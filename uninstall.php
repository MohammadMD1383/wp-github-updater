<?php
/**
 * Remove everything the plugin stored.
 *
 * @package hzf-updater
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_option( 'hzf_updater_state' );
if ( is_multisite() ) {
	delete_option( 'hzf_updater_state' );
}
