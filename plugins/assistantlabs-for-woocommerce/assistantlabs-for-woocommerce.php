<?php
/**
 * Plugin Name:          AssistantLabs for WooCommerce
 * Plugin URI:           https://assistantlabs.io/woocommerce
 * Description:          Adds your WooCommerce store to AssistantLabs: your agent answers product and order questions, drafts product, price, stock and coupon changes that you approve, and (when you turn it on) recovers abandoned carts.
 * Version:              2.1.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     assistantlabs, woocommerce
 * Author:               AssistantLabs
 * Author URI:           https://assistantlabs.io
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          assistantlabs-for-woocommerce
 * WC requires at least: 8.2
 * WC tested up to:      11.2
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASSISTANTLABS_WC_VERSION', '2.1.0' );
define( 'ASSISTANTLABS_WC_FILE', __FILE__ );
define( 'ASSISTANTLABS_WC_DIR', plugin_dir_path( __FILE__ ) );

// Declare HPOS (custom order tables) compatibility: orders are read only through wc_get_orders().
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ASSISTANTLABS_WC_FILE, true );
		}
	}
);

/**
 * Boot once both AssistantLabs and WooCommerce are loaded.
 */
function assistantlabs_wc_boot() {
	if ( ! class_exists( 'AssistantLabs_Plugin' ) || ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'assistantlabs_wc_missing_notice' );
		return;
	}
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-keys.php';
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-export.php';
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-change-handlers.php';
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-store.php';
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-install.php';
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-events.php';
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-carts.php';
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-privacy.php';

	AssistantLabs_WC_Install::maybe_install();
	AssistantLabs_WC_Keys::init();
	AssistantLabs_WC_Export::init();
	AssistantLabs_WC_Store::init();
	AssistantLabs_WC_Events::init();
	AssistantLabs_WC_Carts::init();
	AssistantLabs_WC_Privacy::init();

	if ( is_admin() && class_exists( 'AssistantLabs_Admin' ) ) {
		require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-admin.php';
		AssistantLabs_WC_Admin::init();
	}

	add_filter( 'assistantlabs_change_handlers', 'assistantlabs_wc_register_handlers' );
}
add_action( 'plugins_loaded', 'assistantlabs_wc_boot', 15 );

/**
 * Activation: create the carts and outbox tables.
 */
function assistantlabs_wc_activate() {
	require_once ASSISTANTLABS_WC_DIR . 'includes/class-assistantlabs-wc-install.php';
	AssistantLabs_WC_Install::install();
}
register_activation_hook( __FILE__, 'assistantlabs_wc_activate' );

/**
 * Deactivation: revoke the store key, so turning the add-on off really ends
 * store access, and stop background work (a disconnect while it is off could not revoke it). On
 * reactivation the key is created and sent again (AssistantLabs_WC_Keys::connect_late).
 */
function assistantlabs_wc_deactivate() {
	global $wpdb;
	$key_id = (int) get_option( 'assistantlabs_wc_key_id', 0 );
	if ( $key_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) );
	}
	delete_option( 'assistantlabs_wc_key_id' );
	delete_transient( 'assistantlabs_wc_connect_late' );

	// Stop the daily cart cleanup and any store event still queued (nothing would handle it).
	wp_clear_scheduled_hook( 'assistantlabs_wc_cleanup' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'assistantlabs_wc_send_event', array(), 'assistantlabs' );
	}
}
register_deactivation_hook( __FILE__, 'assistantlabs_wc_deactivate' );

/**
 * Register the store change kinds.
 *
 * @param AssistantLabs_Change_Handler[] $handlers Kind => handler.
 * @return AssistantLabs_Change_Handler[]
 */
function assistantlabs_wc_register_handlers( $handlers ) {
	$handlers['product_create'] = new AssistantLabs_WC_Product_Create_Handler();
	$handlers['product_update'] = new AssistantLabs_WC_Product_Update_Handler();
	$handlers['coupon_create']  = new AssistantLabs_WC_Coupon_Create_Handler();
	return $handlers;
}

/**
 * Shown when a required plugin is missing (older WordPress ignores Requires Plugins).
 */
function assistantlabs_wc_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'AssistantLabs for WooCommerce needs both the AssistantLabs plugin and WooCommerce to be active.', 'assistantlabs-for-woocommerce' ) . '</p></div>';
}
