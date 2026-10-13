<?php
/**
 * HMAC signing shared by both directions (server → site and site → server).
 *
 * canonical = timestamp + "\n" + METHOD + "\n" + route + "\n" + hex(SHA256(rawBody))
 * signature = hex(HMAC_SHA256(siteSecret, canonical))
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and verifies the X-AL-* signature headers.
 */
class AssistantLabs_Signer {

	const HEADER_SITE_ID   = 'X-AL-Site-Id';
	const HEADER_TIMESTAMP = 'X-AL-Timestamp';
	const HEADER_SIGNATURE = 'X-AL-Signature';

	/** Maximum allowed clock difference, in seconds. */
	const MAX_SKEW = 300;

	/**
	 * The canonical string that gets signed.
	 *
	 * @param int|string $timestamp Unix seconds.
	 * @param string     $method    HTTP method.
	 * @param string     $route     Route without query string.
	 * @param string     $raw_body  Exact body bytes ('' for GET).
	 * @return string
	 */
	public static function canonical( $timestamp, $method, $route, $raw_body ) {
		return (string) $timestamp . "\n" . strtoupper( (string) $method ) . "\n" . (string) $route . "\n" . hash( 'sha256', (string) $raw_body );
	}

	/**
	 * Hex HMAC-SHA256 of the canonical string.
	 *
	 * @param string     $secret    Shared secret.
	 * @param int|string $timestamp Unix seconds.
	 * @param string     $method    HTTP method.
	 * @param string     $route     Route without query string.
	 * @param string     $raw_body  Exact body bytes.
	 * @return string
	 */
	public static function sign( $secret, $timestamp, $method, $route, $raw_body ) {
		return hash_hmac( 'sha256', self::canonical( $timestamp, $method, $route, $raw_body ), $secret );
	}

	/**
	 * Headers for an outgoing signed request.
	 *
	 * @param string $method   HTTP method.
	 * @param string $route    Path under /vendor/wordpress, e.g. /site/status.
	 * @param string $raw_body Exact body bytes.
	 * @return array<string,string>
	 */
	public static function headers( $method, $route, $raw_body ) {
		$timestamp = time();
		return array(
			self::HEADER_SITE_ID   => AssistantLabs_Options::site_id(),
			self::HEADER_TIMESTAMP => (string) $timestamp,
			self::HEADER_SIGNATURE => self::sign( AssistantLabs_Options::site_secret(), $timestamp, $method, $route, $raw_body ),
		);
	}

	/**
	 * Verify an incoming signed request.
	 *
	 * @param string $site_id   Value of X-AL-Site-Id.
	 * @param string $timestamp Value of X-AL-Timestamp.
	 * @param string $signature Value of X-AL-Signature.
	 * @param string $method    HTTP method.
	 * @param string $route     Route as WordPress reports it.
	 * @param string $raw_body  Exact body bytes.
	 * @return true|WP_Error
	 */
	public static function verify( $site_id, $timestamp, $signature, $method, $route, $raw_body ) {
		$secret = AssistantLabs_Options::site_secret();
		if ( '' === $secret ) {
			return self::error( 'assistantlabs_not_connected', __( 'This site is not connected to AssistantLabs.', 'assistantlabs' ) );
		}
		if ( ! is_string( $site_id ) || ! hash_equals( AssistantLabs_Options::site_id(), $site_id ) ) {
			return self::error( 'assistantlabs_bad_site', __( 'Unknown site.', 'assistantlabs' ) );
		}
		if ( ! is_string( $timestamp ) || ! preg_match( '/^\d{1,12}$/', $timestamp ) ) {
			return self::error( 'assistantlabs_bad_timestamp', __( 'Missing or malformed timestamp.', 'assistantlabs' ) );
		}
		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return self::error( 'assistantlabs_stale_request', __( 'Request timestamp is too far from the site clock.', 'assistantlabs' ) );
		}
		if ( ! is_string( $signature ) || '' === $signature ) {
			return self::error( 'assistantlabs_bad_signature', __( 'Missing signature.', 'assistantlabs' ) );
		}
		$expected = self::sign( $secret, $timestamp, $method, $route, $raw_body );
		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return self::error( 'assistantlabs_bad_signature', __( 'Invalid signature.', 'assistantlabs' ) );
		}
		return true;
	}

	/**
	 * Token that unlocks the public preview of a change.
	 *
	 * @param int $change_id Change post id.
	 * @return string Empty when not connected.
	 */
	public static function preview_token( $change_id ) {
		$secret = AssistantLabs_Options::site_secret();
		if ( '' === $secret ) {
			return '';
		}
		return hash_hmac( 'sha256', 'preview:' . (int) $change_id, $secret );
	}

	/**
	 * A 401 error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function error( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 401 ) );
	}
}
