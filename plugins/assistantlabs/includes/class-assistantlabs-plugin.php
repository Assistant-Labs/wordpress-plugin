<?php
/**
 * Bootstrap: wires every component together.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
final class AssistantLabs_Plugin {

	/**
	 * Singleton.
	 *
	 * @var AssistantLabs_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and on first call, boot) the plugin.
	 *
	 * @return AssistantLabs_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot.
	 */
	private function __construct() {
		AssistantLabs_Agent_User::init();
		AssistantLabs_Connect::init();
		AssistantLabs_Changes::init();
		AssistantLabs_Preview::init();
		AssistantLabs_Rest::init();
		AssistantLabs_Widget::init();
		AssistantLabs_Freshness::init();
		add_action( 'plugins_loaded', array( 'AssistantLabs_Forms', 'init' ), 20 );

		if ( is_admin() ) {
			AssistantLabs_Admin::init();
		}
	}

	/**
	 * Activation: create the site id.
	 */
	public static function activate() {
		AssistantLabs_Options::site_id();
	}

	/**
	 * Deactivation: nothing is removed (scheduled changes stay scheduled).
	 */
	public static function deactivate() {
		delete_transient( AssistantLabs_Connect::STATUS_CACHE );
	}

	/**
	 * Queue an admin notice for the current user's next page load.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 */
	public static function flash( $type, $message ) {
		set_transient(
			'assistantlabs_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Take the queued notice, if any.
	 *
	 * @return array|null {type, message}
	 */
	public static function take_flash() {
		$key   = 'assistantlabs_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( $flash ) {
			delete_transient( $key );
			return $flash;
		}
		return null;
	}
}
