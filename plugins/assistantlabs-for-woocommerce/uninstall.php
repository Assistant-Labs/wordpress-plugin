<?php
/**
 * Uninstall: revoke the WooCommerce API key this add-on created, and remove
 * the saved carts, the event outbox and the add-on's settings.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$assistantlabs_wc_key_id = (int) get_option( 'assistantlabs_wc_key_id', 0 );
if ( $assistantlabs_wc_key_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $assistantlabs_wc_key_id ), array( '%d' ) );
}
delete_option( 'assistantlabs_wc_key_id' );

// Saved carts and the event outbox.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own tables on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'assistantlabs_carts' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'assistantlabs_events' ) );
// phpcs:enable
delete_option( 'assistantlabs_wc_settings' );
delete_option( 'assistantlabs_wc_db_version' );
delete_metadata( 'user', 0, '_assistantlabs_customer_created', '', true );
delete_metadata( 'user', 0, '_assistantlabs_customer_hash', '', true );
wp_clear_scheduled_hook( 'assistantlabs_wc_cleanup' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'assistantlabs_wc_send_event', array(), 'assistantlabs' );
}
