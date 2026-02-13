<?php
/**
 * Smart Redirect Manager - Uninstall
 *
 * Fired when the plugin is uninstalled. Removes all plugin data
 * including database tables, options, transients, and cron hooks.
 *
 * @package SmartRedirectManager
 * @author  Sven Gauditz
 * @link    https://gauditz.com
 * @license MIT
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * 1. Drop all plugin database tables.
 */
$tables = array(
	$wpdb->prefix . 'srm_redirects',
	$wpdb->prefix . 'srm_404_log',
	$wpdb->prefix . 'srm_redirect_hits',
	$wpdb->prefix . 'srm_groups',
	$wpdb->prefix . 'srm_conditions',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/*
 * 2. Delete plugin options.
 */
delete_option( 'srm_settings' );
delete_option( 'srm_db_version' );

/*
 * 3. Delete all transients starting with srm_.
 */
$srm_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_srm_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_srm_' ) . '%'
	)
);

foreach ( $srm_transients as $transient ) {
	delete_option( $transient );
}

/*
 * 4. Clear all plugin cron hooks.
 */
$cron_hooks = array(
	'srm_cleanup_logs',
	'srm_auto_cleanup_redirects',
	'srm_send_digest',
	'srm_check_404_spike',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
