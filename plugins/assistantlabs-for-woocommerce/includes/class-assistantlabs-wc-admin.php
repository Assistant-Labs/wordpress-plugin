<?php
/**
 * WP admin: the "Store" screen in the AssistantLabs menu.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store settings: "Recover abandoned carts" (off by default).
 */
class AssistantLabs_WC_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'assistantlabs-store';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_assistantlabs_wc_save_store', array( __CLASS__, 'save' ) );
	}

	/**
	 * Add "Store" under the AssistantLabs menu (the core plugin's menu and styles).
	 */
	public static function menu() {
		add_submenu_page(
			'assistantlabs',
			__( 'Store', 'assistantlabs-for-woocommerce' ),
			__( 'Store', 'assistantlabs-for-woocommerce' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'assistantlabs-for-woocommerce' ), 403 );
		}
		$connected = AssistantLabs_Options::is_connected();
		$settings  = AssistantLabs_WC_Carts::settings();
		$saved     = AssistantLabs_WC_Carts::saved_count();
		$waiting   = AssistantLabs_WC_Events::pending_count();
		include ASSISTANTLABS_WC_DIR . 'admin/views/store.php';
	}

	/**
	 * Save the store settings.
	 */
	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'assistantlabs-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'assistantlabs_wc_save_store' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$settings = array( 'recover_carts' => ! empty( $_POST['recover_carts'] ) );
		update_option( AssistantLabs_WC_Carts::OPTION, $settings, true );
		AssistantLabs_Plugin::flash( 'success', __( 'Store settings saved.', 'assistantlabs-for-woocommerce' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
