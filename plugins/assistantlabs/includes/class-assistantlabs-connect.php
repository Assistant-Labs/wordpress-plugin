<?php
/**
 * Connect / disconnect: PKCE redirect to the app, the callback and the exchange.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the connection lifecycle.
 */
class AssistantLabs_Connect {

	const TRANSIENT_PREFIX = 'assistantlabs_pkce_';
	const STATUS_CACHE     = 'assistantlabs_status_cache';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_assistantlabs_connect', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_assistantlabs_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_assistantlabs_sync_knowledge', array( __CLASS__, 'handle_sync_knowledge' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_callback' ) );
	}

	/**
	 * Admin page URL that the app sends the owner back to.
	 *
	 * @return string
	 */
	public static function return_url() {
		return admin_url( 'admin.php?page=assistantlabs&al_connect=1' );
	}

	/**
	 * Step 1: create state + verifier and send the browser to the connect page.
	 */
	public static function handle_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'assistantlabs' ), 403 );
		}
		check_admin_referer( 'assistantlabs_connect' );

		$intent = isset( $_POST['intent'] ) && 'build' === $_POST['intent'] ? 'build' : 'existing';

		$state    = bin2hex( random_bytes( 16 ) );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // 64 chars.

		set_transient(
			self::TRANSIENT_PREFIX . $state,
			array(
				'verifier' => $verifier,
				'intent'   => $intent,
				'user_id'  => get_current_user_id(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$query = array(
			'site'      => home_url(),
			'siteId'    => AssistantLabs_Options::site_id(),
			'name'      => get_bloginfo( 'name' ),
			'return'    => self::return_url(),
			'state'     => $state,
			'challenge' => hash( 'sha256', $verifier ),
			'intent'    => $intent,
			'woo'       => class_exists( 'WooCommerce' ) ? '1' : '0',
		);
		$url   = untrailingslashit( ASSISTANTLABS_APP_URL ) . '/connect/wordpress?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		// The app lives on another host, so wp_safe_redirect() would refuse it.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Step 3: the app sent the owner back with ?code=&state=.
	 */
	public static function maybe_handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the PKCE state is the CSRF check here.
		if ( ! isset( $_GET['page'], $_GET['al_connect'] ) || 'assistantlabs' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable

		$home = admin_url( 'admin.php?page=assistantlabs' );

		if ( '' === $state || '' === $code || ! preg_match( '/^[a-f0-9]{32}$/', $state ) ) {
			AssistantLabs_Plugin::flash( 'error', __( 'The connection was cancelled or the link was incomplete. Please try again.', 'assistantlabs' ) );
			wp_safe_redirect( $home );
			exit;
		}

		$pending = get_transient( self::TRANSIENT_PREFIX . $state );
		delete_transient( self::TRANSIENT_PREFIX . $state );

		if ( ! is_array( $pending ) || (int) $pending['user_id'] !== get_current_user_id() ) {
			AssistantLabs_Plugin::flash( 'error', __( 'This connection link expired. Please start again.', 'assistantlabs' ) );
			wp_safe_redirect( $home );
			exit;
		}

		$result = self::exchange( $code, $pending['verifier'] );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message from AssistantLabs. */
			AssistantLabs_Plugin::flash( 'error', sprintf( __( 'Could not connect: %s', 'assistantlabs' ), $result->get_error_message() ) );
		} elseif ( 'build' === $result['mode'] ) {
			AssistantLabs_Plugin::flash( 'success', __( 'Connected. Your agent is reading your site now — this takes a few minutes.', 'assistantlabs' ) );
		} else {
			AssistantLabs_Plugin::flash( 'success', __( 'Connected. Your agent now works with this site.', 'assistantlabs' ) );
		}
		wp_safe_redirect( $home );
		exit;
	}

	/**
	 * Swap the one-time code for a connection.
	 *
	 * The secret and the agent user exist before the exchange call, because in
	 * build mode the server may call /export while the exchange is in flight.
	 *
	 * @param string $code     One-time code.
	 * @param string $verifier PKCE verifier.
	 * @return array|WP_Error Server response.
	 */
	public static function exchange( $code, $verifier ) {
		$user_id = AssistantLabs_Agent_User::ensure();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$previous_secret = AssistantLabs_Options::site_secret();
		$secret          = bin2hex( random_bytes( 32 ) );
		update_option( AssistantLabs_Options::SITE_SECRET, $secret, false );

		$payload = array(
			'code'            => $code,
			'verifier'        => $verifier,
			'siteId'          => AssistantLabs_Options::site_id(),
			'siteSecret'      => $secret,
			'siteUrl'         => home_url(),
			'restUrl'         => rest_url(),
			'siteName'        => get_bloginfo( 'name' ),
			'siteDescription' => get_bloginfo( 'description' ),
			'language'        => get_locale(),
			'wpVersion'       => get_bloginfo( 'version' ),
			'pluginVersion'   => ASSISTANTLABS_VERSION,
		);

		/**
		 * Filter the body sent to /connect/exchange. The WooCommerce add-on adds
		 * its `woocommerce` block here.
		 *
		 * @param array $payload Exchange body.
		 * @param int   $user_id The AssistantLabs WordPress user.
		 */
		$payload = apply_filters( 'assistantlabs_connect_payload', $payload, $user_id );

		$response = AssistantLabs_Api_Client::post_unsigned( '/connect/exchange', $payload );

		if ( is_wp_error( $response ) || empty( $response['assistantId'] ) ) {
			if ( '' !== $previous_secret ) {
				update_option( AssistantLabs_Options::SITE_SECRET, $previous_secret, false );
			} else {
				delete_option( AssistantLabs_Options::SITE_SECRET );
			}
			/**
			 * The exchange failed; undo anything added to the payload.
			 *
			 * @param array $payload Exchange body that was sent.
			 */
			do_action( 'assistantlabs_connect_failed', $payload );
			return is_wp_error( $response ) ? $response : new WP_Error( 'assistantlabs_bad_exchange', __( 'AssistantLabs sent an unexpected answer.', 'assistantlabs' ) );
		}

		update_option( AssistantLabs_Options::ASSISTANT_ID, sanitize_text_field( $response['assistantId'] ), true );
		update_option( AssistantLabs_Options::ASSISTANT_NAME, sanitize_text_field( isset( $response['assistantName'] ) ? $response['assistantName'] : '' ), false );
		update_option( AssistantLabs_Options::ASSISTANT_URL, esc_url_raw( isset( $response['appUrl'] ) ? $response['appUrl'] : '' ), false );
		update_option( AssistantLabs_Options::CONNECT_MODE, isset( $response['mode'] ) && 'build' === $response['mode'] ? 'build' : 'existing', false );
		update_option( AssistantLabs_Options::CONNECTED_AT, time(), false );
		if ( isset( $response['knowledge']['status'] ) && 'running' === $response['knowledge']['status'] ) {
			update_option( AssistantLabs_Options::KNOWLEDGE_SYNCED, time(), false );
		}
		self::remember_names( $response );
		delete_transient( self::STATUS_CACHE );

		$response['mode'] = isset( $response['mode'] ) ? $response['mode'] : 'existing';

		/**
		 * The site is connected.
		 *
		 * @param array $response Exchange response.
		 * @param array $payload  Exchange body.
		 */
		do_action( 'assistantlabs_connected', $response, $payload );

		return $response;
	}

	/**
	 * Disconnect: tell the server (best effort), then forget the connection.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'assistantlabs' ), 403 );
		}
		check_admin_referer( 'assistantlabs_disconnect' );

		$result = self::disconnect();
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message. */
			AssistantLabs_Plugin::flash( 'warning', sprintf( __( 'Disconnected on this site. AssistantLabs could not be reached (%s); remove the WordPress integration from your dashboard too.', 'assistantlabs' ), $result->get_error_message() ) );
		} else {
			AssistantLabs_Plugin::flash( 'success', __( 'Disconnected. Your agent no longer has access to this site.', 'assistantlabs' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=assistantlabs' ) );
		exit;
	}

	/**
	 * Forget the connection.
	 *
	 * @return true|WP_Error Error from the server call (the local disconnect still happens).
	 */
	public static function disconnect() {
		$remote = AssistantLabs_Api_Client::request( 'POST', '/site/disconnect', new stdClass() );

		/**
		 * The site is being disconnected (fires before the secret is removed).
		 */
		do_action( 'assistantlabs_disconnected' );

		foreach ( array(
			AssistantLabs_Options::SITE_SECRET,
			AssistantLabs_Options::ASSISTANT_ID,
			AssistantLabs_Options::ASSISTANT_NAME,
			AssistantLabs_Options::ASSISTANT_URL,
			AssistantLabs_Options::WORKSPACE_NAME,
			AssistantLabs_Options::CONNECT_MODE,
			AssistantLabs_Options::CONNECTED_AT,
			AssistantLabs_Options::KNOWLEDGE_SYNCED,
		) as $option ) {
			delete_option( $option );
		}
		delete_transient( self::STATUS_CACHE );

		return is_wp_error( $remote ) ? $remote : true;
	}

	/**
	 * "Update the agent from my site" — the only place the sync is requested.
	 */
	public static function handle_sync_knowledge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'assistantlabs' ), 403 );
		}
		check_admin_referer( 'assistantlabs_sync_knowledge' );

		$result = AssistantLabs_Api_Client::request( 'POST', '/site/knowledge/sync', new stdClass() );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message. */
			AssistantLabs_Plugin::flash( 'error', sprintf( __( 'Could not start the update: %s', 'assistantlabs' ), $result->get_error_message() ) );
		} else {
			update_option( AssistantLabs_Options::KNOWLEDGE_SYNCED, time(), false );
			delete_transient( self::STATUS_CACHE );
			AssistantLabs_Plugin::flash( 'success', __( 'Your agent is learning your site again. This takes a few minutes.', 'assistantlabs' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=assistantlabs' ) );
		exit;
	}

	/**
	 * GET /site/status, cached for a minute so admin page loads stay fast.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array|WP_Error
	 */
	public static function status( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::STATUS_CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$status = AssistantLabs_Api_Client::request( 'GET', '/site/status', null, array( 'timeout' => 8 ) );
		if ( ! is_wp_error( $status ) ) {
			set_transient( self::STATUS_CACHE, $status, MINUTE_IN_SECONDS );
			self::remember_names( $status );
		}
		return $status;
	}

	/**
	 * Keep the workspace and agent names, so Home can show them while the
	 * server is unreachable.
	 *
	 * @param array $data /site/status (or exchange) response.
	 */
	public static function remember_names( $data ) {
		if ( isset( $data['workspace']['name'] ) && is_string( $data['workspace']['name'] ) ) {
			update_option( AssistantLabs_Options::WORKSPACE_NAME, sanitize_text_field( $data['workspace']['name'] ), false );
		}
		if ( isset( $data['assistant']['name'] ) && is_string( $data['assistant']['name'] ) && '' !== $data['assistant']['name'] ) {
			update_option( AssistantLabs_Options::ASSISTANT_NAME, sanitize_text_field( $data['assistant']['name'] ), false );
		}
	}
}
