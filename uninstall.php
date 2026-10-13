<?php
/**
 * Remove everything the plugin stored.
 *
 * @package hzf-updater
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'hzf_updater_state', 'hzf_updater_settings' ) as $hzf_updater_option ) {
	delete_site_option( $hzf_updater_option );
	delete_option( $hzf_updater_option );
}
