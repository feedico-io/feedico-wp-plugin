<?php
/**
 * Uninstall Feedico Sync.
 *
 * @package Feedico_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'FEEDICO_SYNC_DELETE_DATA' ) && FEEDICO_SYNC_DELETE_DATA ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'feedico_merchants' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'feedico_coupons' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'feedico_sync_log' );

	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s)",
			'feedico_store',
			'feedico_coupon'
		)
	);
	if ( is_array( $post_ids ) ) {
		foreach ( $post_ids as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
	}
}

$keys = array(
	'feedico_sync_email',
	'feedico_sync_password_enc',
	'feedico_sync_token_enc',
	'feedico_sync_last_dashboard',
	'feedico_sync_network_catalog',
	'feedico_sync_selected_networks',
	'feedico_sync_cron_interval',
	'feedico_sync_cron_custom_minutes',
	'feedico_sync_connection_ok',
	'feedico_sync_last_run',
);
foreach ( $keys as $k ) {
	delete_option( $k );
}

wp_clear_scheduled_hook( 'feedico_sync_cron' );
wp_unschedule_hook( 'feedico_sync_background' );
