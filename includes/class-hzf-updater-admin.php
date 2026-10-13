<?php
/**
 * Admin UI: Tools -> HZF Updater (Settings -> HZF Updater in the network admin).
 *
 * @package hzf-updater
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status page, "Check now" action and admin notices.
 */
final class HZF_Updater_Admin {

	const SLUG = 'hzf-updater';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hzf_updater_check', array( __CLASS__, 'handle_check' ) );
		add_action( is_multisite() ? 'network_admin_notices' : 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * URL of the status page.
	 *
	 * @return string
	 */
	private static function page_url() {
		return is_multisite() ? network_admin_url( 'settings.php?page=' . self::SLUG ) : admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Add the page.
	 */
	public static function menu() {
		$title = __( 'HZF Updater', 'hzf-updater' );
		if ( is_multisite() ) {
			add_submenu_page( 'settings.php', $title, $title, 'update_plugins', self::SLUG, array( __CLASS__, 'render' ) );
		} else {
			add_management_page( $title, $title, 'update_plugins', self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * "Check now": nonce + capability, clear the cache and re-run the update checks.
	 */
	public static function handle_check() {
		check_admin_referer( 'hzf_updater_check' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'hzf-updater' ), 403 );
		}
		HZF_Updater::check_now();
		wp_safe_redirect( add_query_arg( 'checked', '1', self::page_url() ) );
		exit;
	}

	/**
	 * Errors that need the admin's attention.
	 */
	public static function notices() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$msg = '';
		if ( HZF_Updater::token_missing() ) {
			$msg = __( 'HZF Updater: the HZF_UPDATER_GITHUB_TOKEN constant is not defined in wp-config.php, so private GitHub releases cannot be checked.', 'hzf-updater' );
		} else {
			foreach ( HZF_Updater::state() as $entry ) {
				if ( ! empty( $entry['code'] ) && in_array( (int) $entry['code'], array( 401, 403 ), true ) ) {
					$msg = __( 'HZF Updater: GitHub rejected the access token (HTTP 401/403). It may have expired or lack read access to the repository contents.', 'hzf-updater' );
					break;
				}
			}
		}
		if ( $msg ) {
			printf( '<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>', esc_html( $msg ), esc_url( self::page_url() ), esc_html__( 'Details', 'hzf-updater' ) );
		}
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'hzf-updater' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'HZF Updater', 'hzf-updater' ) . '</h1>';
		if ( isset( $_GET['checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Update check finished.', 'hzf-updater' ) . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Package', 'hzf-updater' ), __( 'Repository', 'hzf-updater' ), __( 'Installed', 'hzf-updater' ), __( 'Latest', 'hzf-updater' ), __( 'Last check', 'hzf-updater' ), __( 'Last error', 'hzf-updater' ) ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( HZF_Updater::packages() as $row ) {
			printf(
				'<tr><td>%s (%s)</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['name'] ),
				esc_html( 'plugin' === $row['type'] ? __( 'plugin', 'hzf-updater' ) : __( 'theme', 'hzf-updater' ) ),
				esc_url( 'https://github.com/' . $row['repo'] ),
				esc_html( $row['repo'] ),
				esc_html( $row['installed'] ),
				esc_html( $row['latest'] ? $row['latest'] : '—' ),
				esc_html( $row['checked'] ? wp_date( 'Y-m-d H:i', $row['checked'] ) : '—' ),
				esc_html( $row['error'] ? $row['error'] : '—' )
			);
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="hzf_updater_check" />';
		wp_nonce_field( 'hzf_updater_check' );
		submit_button( __( 'Check now', 'hzf-updater' ), 'primary', 'submit', true, array( 'style' => 'margin-top:1em' ) );
		echo '</form></div>';
	}
}
