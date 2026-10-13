<?php
/**
 * WP-CLI commands.
 *
 * @package hzf-updater
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and refresh GitHub-managed plugins and themes.
 */
final class HZF_Updater_CLI {

	/**
	 * Show managed packages with installed/latest version and last error.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Default: table.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function status( $args, $assoc_args ) {
		$rows = array();
		foreach ( HZF_Updater::packages() as $row ) {
			$row['checked'] = $row['checked'] ? gmdate( 'Y-m-d H:i:s', $row['checked'] ) : '';
			$rows[]         = $row;
		}
		if ( HZF_Updater::token_missing() ) {
			WP_CLI::warning( 'No GitHub token is set (Tools -> HZF Updater).' );
		}
		WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'type', 'slug', 'repo', 'installed', 'latest', 'checked', 'error' ) );
	}

	/**
	 * Clear the cache and check GitHub for updates now.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function check( $args, $assoc_args ) {
		HZF_Updater::check_now();
		WP_CLI::success( 'Update check finished.' );
		$this->status( $args, $assoc_args );
	}
}
