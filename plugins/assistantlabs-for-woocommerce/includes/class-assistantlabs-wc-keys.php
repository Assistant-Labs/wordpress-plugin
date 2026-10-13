<?php
/**
 * WooCommerce REST API key for the AssistantLabs user, sent with the connect exchange.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the key the same way WC_Auth::create_keys() does, and revokes it on disconnect.
 */
class AssistantLabs_WC_Keys {

	const OPTION_KEY_ID = 'assistantlabs_wc_key_id';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'assistantlabs_connect_payload', array( __CLASS__, 'add_to_payload' ), 10, 2 );
		add_action( 'assistantlabs_connect_failed', array( __CLASS__, 'on_connect_failed' ) );
		add_action( 'assistantlabs_disconnected', array( __CLASS__, 'revoke' ) );
		add_action( 'admin_init', array( __CLASS__, 'connect_late' ) );
	}

	/**
	 * The add-on was installed after the site connected: no key went out with
	 * the exchange, so send one now instead of making the owner reconnect.
	 * Retried at most hourly when the server cannot be reached.
	 */
	public static function connect_late() {
		if ( ! AssistantLabs_Options::is_connected() || get_option( self::OPTION_KEY_ID, 0 ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || get_transient( 'assistantlabs_wc_connect_late' ) ) {
			return;
		}
		set_transient( 'assistantlabs_wc_connect_late', 1, HOUR_IN_SECONDS );

		$user_id = AssistantLabs_Agent_User::ensure(); // Upgrades the agent user to shop_manager.
		if ( is_wp_error( $user_id ) ) {
			return;
		}
		$key = self::create( (int) $user_id );
		if ( is_wp_error( $key ) ) {
			return;
		}
		$result = AssistantLabs_Api_Client::request(
			'POST',
			'/site/woocommerce/connect',
			array(
				'consumerKey'    => $key['consumer_key'],
				'consumerSecret' => $key['consumer_secret'],
				'keyId'          => $key['key_id'],
				'version'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			self::revoke();
			return;
		}
		delete_transient( 'assistantlabs_wc_connect_late' );
	}

	/**
	 * Add the `woocommerce` block to the exchange body.
	 *
	 * @param array $payload Exchange body.
	 * @param int   $user_id AssistantLabs user.
	 * @return array
	 */
	public static function add_to_payload( $payload, $user_id ) {
		$key = self::create( (int) $user_id );
		if ( is_wp_error( $key ) ) {
			return $payload;
		}
		$payload['woocommerce'] = array(
			'consumerKey'    => $key['consumer_key'],
			'consumerSecret' => $key['consumer_secret'],
			'keyId'          => $key['key_id'],
			'version'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		);
		return $payload;
	}

	/**
	 * The exchange failed: drop the key we just made.
	 *
	 * @param array $payload Exchange body.
	 */
	public static function on_connect_failed( $payload ) {
		if ( ! empty( $payload['woocommerce']['keyId'] ) ) {
			self::delete_key( (int) $payload['woocommerce']['keyId'] );
			delete_option( self::OPTION_KEY_ID );
		}
	}

	/**
	 * Create a read_write key for the user, replacing the previous one.
	 *
	 * Mirrors WC_Auth::create_keys(): the consumer key is stored hashed with
	 * wc_api_hash(), the secret in clear, truncated_key = last 7 chars.
	 *
	 * @param int $user_id User id.
	 * @return array|WP_Error {key_id, consumer_key, consumer_secret}
	 */
	public static function create( $user_id ) {
		global $wpdb;

		if ( ! $user_id || ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
			return new WP_Error( 'assistantlabs_wc_keys', __( 'WooCommerce is not ready.', 'assistantlabs-for-woocommerce' ) );
		}

		self::revoke();

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();
		/* translators: %s: date. */
		$description = sprintf( __( 'AssistantLabs - API (%s)', 'assistantlabs-for-woocommerce' ), gmdate( 'Y-m-d H:i:s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WooCommerce has no API for this; WC_Auth inserts the same way.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => $user_id,
				'description'     => $description,
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'assistantlabs_wc_keys', __( 'Could not create the WooCommerce API key.', 'assistantlabs-for-woocommerce' ) );
		}
		$key_id = (int) $wpdb->insert_id;
		update_option( self::OPTION_KEY_ID, $key_id, false );

		return array(
			'key_id'          => $key_id,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
	}

	/**
	 * Revoke the key this plugin created, if any.
	 */
	public static function revoke() {
		$key_id = (int) get_option( self::OPTION_KEY_ID, 0 );
		if ( $key_id ) {
			self::delete_key( $key_id );
			delete_option( self::OPTION_KEY_ID );
		}
	}

	/**
	 * Delete one key row.
	 *
	 * @param int $key_id Key id.
	 */
	private static function delete_key( $key_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => (int) $key_id ), array( '%d' ) );
	}
}
