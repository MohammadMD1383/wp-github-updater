<?php
/**
 * Plugin Name:       HZF Updater
 * Plugin URI:        https://github.com/MohammadMD1383/wp-github-updater
 * Description:       Updates our own plugins and themes from private GitHub Releases. Opt in with an "Update URI: https://github.com/<org>/<repo>" header.
 * Version:           1.1.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            HZF
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/MohammadMD1383/wp-github-updater
 * Text Domain:       hzf-updater
 * Domain Path:       /languages
 *
 * @package hzf-updater
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-hzf-updater.php';
HZF_Updater::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-hzf-updater-admin.php';
	HZF_Updater_Admin::init();
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-hzf-updater-cli.php';
	WP_CLI::add_command( 'hzf-updater', 'HZF_Updater_CLI' );
}
