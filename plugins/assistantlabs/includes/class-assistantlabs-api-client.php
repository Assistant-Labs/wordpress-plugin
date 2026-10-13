<?php
/**
 * Signed calls from the site to the AssistantLabs server.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around wp_remote_request for {API_URL}/vendor/wordpress.
 */
class AssistantLabs_Api_Client {

	const BASE_PATH = '/vendor/wordpress';

	/**
	 * Full URL for a path under /vendor/wordpress.
	 *
	 * @param string $path e.g. /site/status.
	 * @return string
	 */
	public static function url( $path ) {
		return untrailingslashit( ASSISTANTLABS_API_URL ) . self::BASE_PATH . $path;
	}

	/**
	 * Send a signed request and decode the JSON answer.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under /vendor/wordpress (signed as-is).
	 * @param array|null $body   JSON body (null for GET).
	 * @param array      $args   Extra wp_remote_request args (timeout, blocking).
	 * @return array|WP_Error Decoded JSON (empty array for non-blocking calls).
	 */
	public static function request( $method, $path, $body = null, $args = array() ) {
		if ( '' === AssistantLabs_Options::site_secret() ) {
			return new WP_Error( 'assistantlabs_not_connected', __( 'This site is not connected to AssistantLabs.', 'assistantlabs' ) );
		}
		$method   = strtoupper( $method );
		$raw_body = null === $body ? '' : wp_json_encode( $body );
		$headers  = AssistantLabs_Signer::headers( $method, $path, $raw_body );

		$headers['Accept'] = 'application/json';
		if ( '' !== $raw_body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$request_args = array_merge(
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => $headers,
			),
			$args
		);
		if ( '' !== $raw_body ) {
			$request_args['body'] = $raw_body;
		}

		$response = wp_remote_request( self::url( $path ), $request_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( isset( $request_args['blocking'] ) && false === $request_args['blocking'] ) {
			return array();
		}
		return self::decode( $response );
	}

	/**
	 * Unsigned JSON POST (only the connect exchange uses this).
	 *
	 * @param string $path Path under /vendor/wordpress.
	 * @param array  $body JSON body.
	 * @return array|WP_Error
	 */
	public static function post_unsigned( $path, $body ) {
		$response = wp_remote_post(
			self::url( $path ),
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return self::decode( $response );
	}

	/**
	 * Turn a response into an array or a WP_Error carrying the server's message.
	 *
	 * @param array $response wp_remote_* response.
	 * @return array|WP_Error
	 */
	private static function decode( $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = '';
			if ( is_array( $data ) ) {
				foreach ( array( 'message', 'error', 'code' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
						$message = $data[ $key ];
						break;
					}
				}
			}
			if ( '' === $message ) {
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'AssistantLabs answered with HTTP %d.', 'assistantlabs' ), $code );
			}
			return new WP_Error( 'assistantlabs_http_' . $code, $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
