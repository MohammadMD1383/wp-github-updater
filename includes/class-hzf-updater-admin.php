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
		add_action( 'admin_post_hzf_updater_save', array( __CLASS__, 'handle_save' ) );
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
	 * Capability needed to change settings.
	 *
	 * @return string
	 */
	private static function settings_cap() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Save settings: nonce + capability. The token field is write-only; empty keeps the saved one.
	 */
	public static function handle_save() {
		check_admin_referer( 'hzf_updater_save' );
		if ( ! current_user_can( self::settings_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'hzf-updater' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$settings = HZF_Updater::settings();
		$token    = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$notice   = 'saved';
		if ( ! empty( $_POST['remove_token'] ) ) {
			$settings['token'] = '';
		} elseif ( '' !== $token ) {
			$enc = HZF_Updater::encrypt( $token );
			if ( false === $enc ) {
				$notice = 'nosodium';
			} else {
				$settings['token'] = $enc;
			}
		}
		$settings['ttl'] = isset( $_POST['ttl'] ) ? min( 168, max( 1, absint( $_POST['ttl'] ) ) ) : 6;
		$base            = isset( $_POST['api_base'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['api_base'] ) ) ), array( 'https' ) ) : '';
		// phpcs:enable
		$settings['api_base'] = untrailingslashit( HZF_Updater::DEFAULT_BASE === untrailingslashit( $base ) ? '' : $base );
		HZF_Updater::save_settings( $settings );
		HZF_Updater::check_now();
		wp_safe_redirect( add_query_arg( 'saved', $notice, self::page_url() ) );
		exit;
	}

	/**
	 * Settings form.
	 */
	private static function render_settings() {
		$settings = HZF_Updater::settings();
		$source   = HZF_Updater::token_source();
		$disabled = ! current_user_can( self::settings_cap() );
		echo '<h2>' . esc_html__( 'Settings', 'hzf-updater' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="hzf_updater_save" />';
		wp_nonce_field( 'hzf_updater_save' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="hzf-token">' . esc_html__( 'GitHub token', 'hzf-updater' ) . '</label></th><td>';
		if ( 'constant' === $source ) {
			echo '<p>' . esc_html__( 'Set by the HZF_UPDATER_GITHUB_TOKEN constant in wp-config.php, which overrides this field.', 'hzf-updater' ) . '</p>';
		} else {
			printf(
				'<input type="password" id="hzf-token" name="token" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="%s" %s />',
				esc_attr( 'settings' === $source ? __( 'Saved. Leave empty to keep it.', 'hzf-updater' ) : 'github_pat_…' ),
				disabled( $disabled, true, false )
			);
			if ( 'settings' === $source ) {
				echo ' <label><input type="checkbox" name="remove_token" value="1" ' . disabled( $disabled, true, false ) . ' /> ' . esc_html__( 'Remove saved token', 'hzf-updater' ) . '</label>';
			}
			echo '<p class="description">' . esc_html__( 'Fine-grained personal access token with read-only Contents access to your repositories. Stored encrypted and never shown again.', 'hzf-updater' ) . '</p>';
		}
		echo '</td></tr><tr><th scope="row"><label for="hzf-ttl">' . esc_html__( 'Check every', 'hzf-updater' ) . '</label></th><td>';
		printf( '<input type="number" id="hzf-ttl" name="ttl" min="1" max="168" step="1" class="small-text" value="%d" %s /> %s', (int) $settings['ttl'], disabled( $disabled, true, false ), esc_html__( 'hours', 'hzf-updater' ) );
		echo '</td></tr><tr><th scope="row"><label for="hzf-base">' . esc_html__( 'API base URL', 'hzf-updater' ) . '</label></th><td>';
		printf( '<input type="url" id="hzf-base" name="api_base" class="regular-text code" placeholder="%s" value="%s" %s />', esc_attr( HZF_Updater::DEFAULT_BASE ), esc_attr( $settings['api_base'] ), disabled( $disabled, true, false ) );
		echo '<p class="description">' . esc_html__( 'Leave empty for GitHub. Set only to use your own proxy that holds the token.', 'hzf-updater' ) . '</p>';
		echo '</td></tr></table>';
		if ( ! $disabled ) {
			submit_button( __( 'Save and check', 'hzf-updater' ) );
		}
		echo '</form>';
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
			$msg = __( 'HZF Updater: no GitHub token is set, so private GitHub releases cannot be checked.', 'hzf-updater' );
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
		$saved = isset( $_GET['saved'] ) ? sanitize_key( $_GET['saved'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'saved' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved and updates checked.', 'hzf-updater' ) . '</p></div>';
		} elseif ( 'nosodium' === $saved ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The token could not be encrypted on this server (the sodium extension is missing). Define HZF_UPDATER_GITHUB_TOKEN in wp-config.php instead.', 'hzf-updater' ) . '</p></div>';
		}
		self::render_settings();
		echo '<h2>' . esc_html__( 'Packages', 'hzf-updater' ) . '</h2>';
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
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
		echo '</tbody></table></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="hzf_updater_check" />';
		wp_nonce_field( 'hzf_updater_check' );
		submit_button( __( 'Check now', 'hzf-updater' ), 'primary', 'submit', true, array( 'style' => 'margin-top:1em' ) );
		echo '</form></div>';
	}
}
