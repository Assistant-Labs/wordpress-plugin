<?php
/**
 * REST routes the AssistantLabs server calls (namespace assistantlabs/v1).
 *
 * Every route is signed (X-AL-Site-Id / X-AL-Timestamp / X-AL-Signature) and,
 * once verified, runs as the dedicated AssistantLabs WordPress user.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route registration and callbacks.
 */
class AssistantLabs_Rest {

	const NAMESPACE_V1 = 'assistantlabs/v1';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Permission callback for every route: verify the signature, then act as the agent user.
	 *
	 * Public so the WooCommerce add-on can reuse it for its own routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ) {
		$verified = AssistantLabs_Signer::verify(
			(string) $request->get_header( 'X-AL-Site-Id' ),
			(string) $request->get_header( 'X-AL-Timestamp' ),
			(string) $request->get_header( 'X-AL-Signature' ),
			$request->get_method(),
			$request->get_route(),
			(string) $request->get_body()
		);
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$user_id = AssistantLabs_Agent_User::ensure();
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'assistantlabs_user_missing', $user_id->get_error_message(), array( 'status' => 500 ) );
		}
		wp_set_current_user( $user_id );
		return true;
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$auth = array( __CLASS__, 'authorize' );
		$id   = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'content_list' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/content/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'content_read' ),
				'permission_callback' => $auth,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/changes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'changes_list' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'changes_create' ),
					'permission_callback' => $auth,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/changes/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'changes_get' ),
				'permission_callback' => $auth,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/changes/(?P<id>\d+)/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'changes_publish' ),
				'permission_callback' => $auth,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/changes/(?P<id>\d+)/discard',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'changes_discard' ),
				'permission_callback' => $auth,
				'args'                => $id,
			)
		);

		/**
		 * Register more signed routes (the WooCommerce add-on adds /store/summary).
		 *
		 * @param string   $namespace REST namespace.
		 * @param callable $auth      Permission callback to reuse.
		 */
		do_action( 'assistantlabs_register_routes', self::NAMESPACE_V1, $auth );
	}

	/**
	 * The {id} from the signed route itself.
	 *
	 * $request['id'] would let an unsigned ?id= query parameter (or a body
	 * field) override the id the signature covers, so read the URL params only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	private static function route_id( WP_REST_Request $request ) {
		$params = $request->get_url_params();
		return isset( $params['id'] ) ? (int) $params['id'] : 0;
	}

	/**
	 * GET /ping.
	 *
	 * @return WP_REST_Response
	 */
	public static function ping() {
		return rest_ensure_response(
			array(
				'ok'            => true,
				'siteId'        => AssistantLabs_Options::site_id(),
				'wpVersion'     => get_bloginfo( 'version' ),
				'pluginVersion' => ASSISTANTLABS_VERSION,
				'woocommerce'   => class_exists( 'WooCommerce' ),
			)
		);
	}

	/**
	 * GET /export.
	 *
	 * @return WP_REST_Response
	 */
	public static function export() {
		return rest_ensure_response( AssistantLabs_Content::export() );
	}

	/**
	 * GET /content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function content_list( WP_REST_Request $request ) {
		$result = AssistantLabs_Content::list_items(
			array(
				'type'    => (string) $request->get_param( 'type' ),
				'search'  => (string) $request->get_param( 'search' ),
				'status'  => (string) $request->get_param( 'status' ),
				'page'    => (int) $request->get_param( 'page' ),
				'perPage' => (int) $request->get_param( 'perPage' ),
			)
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /content/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function content_read( WP_REST_Request $request ) {
		$result = AssistantLabs_Content::read( self::route_id( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /changes?state=pending|all.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function changes_list( WP_REST_Request $request ) {
		$state   = (string) $request->get_param( 'state' );
		$allowed = array( 'all', AssistantLabs_Changes::PENDING, AssistantLabs_Changes::SCHEDULED, AssistantLabs_Changes::PUBLISHED, AssistantLabs_Changes::DISCARDED );
		if ( '' === $state ) {
			$state = AssistantLabs_Changes::PENDING;
		}
		if ( ! in_array( $state, $allowed, true ) ) {
			return new WP_Error( 'invalid_state', __( 'Unsupported state.', 'assistantlabs' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( AssistantLabs_Changes::list_changes( $state ) );
	}

	/**
	 * POST /changes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function changes_create( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_body', __( 'Send a JSON object.', 'assistantlabs' ), array( 'status' => 400 ) );
		}
		$change = AssistantLabs_Changes::create( $body );
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		$response = rest_ensure_response( $change );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * GET /changes/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function changes_get( WP_REST_Request $request ) {
		$change = AssistantLabs_Changes::get( self::route_id( $request ) );
		return is_wp_error( $change ) ? $change : rest_ensure_response( $change );
	}

	/**
	 * POST /changes/{id}/publish { at? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function changes_publish( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$at   = is_array( $body ) && isset( $body['at'] ) && is_string( $body['at'] ) ? $body['at'] : null;
		$change = AssistantLabs_Changes::publish( self::route_id( $request ), $at );
		return is_wp_error( $change ) ? $change : rest_ensure_response( $change );
	}

	/**
	 * POST /changes/{id}/discard.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function changes_discard( WP_REST_Request $request ) {
		$change = AssistantLabs_Changes::discard( self::route_id( $request ) );
		return is_wp_error( $change ) ? $change : rest_ensure_response( $change );
	}
}
