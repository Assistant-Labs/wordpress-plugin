<?php
/**
 * Option names and small accessors for the plugin's stored state.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for every option the plugin stores.
 */
class AssistantLabs_Options {

	const SITE_ID          = 'assistantlabs_site_id';
	const SITE_SECRET      = 'assistantlabs_site_secret';
	const ASSISTANT_ID     = 'assistantlabs_assistant_id';
	const ASSISTANT_NAME   = 'assistantlabs_assistant_name';
	const ASSISTANT_URL    = 'assistantlabs_assistant_url';
	const WORKSPACE_NAME   = 'assistantlabs_workspace_name';
	const CONNECT_MODE     = 'assistantlabs_connect_mode';
	const CONNECTED_AT     = 'assistantlabs_connected_at';
	const USER_ID          = 'assistantlabs_user_id';
	const SITE_CHANGED_AT  = 'assistantlabs_site_changed_at';
	const KNOWLEDGE_SYNCED = 'assistantlabs_knowledge_requested_at';
	const CHAT             = 'assistantlabs_chat';
	const FORMS            = 'assistantlabs_forms';

	/**
	 * Every option name, used by uninstall.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::SITE_ID,
			self::SITE_SECRET,
			self::ASSISTANT_ID,
			self::ASSISTANT_NAME,
			self::ASSISTANT_URL,
			self::WORKSPACE_NAME,
			self::CONNECT_MODE,
			self::CONNECTED_AT,
			self::USER_ID,
			self::SITE_CHANGED_AT,
			self::KNOWLEDGE_SYNCED,
			self::CHAT,
			self::FORMS,
		);
	}

	/**
	 * The site's UUID, created on activation (and lazily if missing).
	 *
	 * @return string
	 */
	public static function site_id() {
		$id = get_option( self::SITE_ID );
		if ( ! is_string( $id ) || '' === $id ) {
			$id = wp_generate_uuid4();
			update_option( self::SITE_ID, $id, true );
		}
		return $id;
	}

	/**
	 * The shared secret, or an empty string when not connected.
	 *
	 * @return string
	 */
	public static function site_secret() {
		$secret = get_option( self::SITE_SECRET, '' );
		return is_string( $secret ) ? $secret : '';
	}

	/**
	 * Whether the site holds a connection (a secret and an assistant).
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::site_secret() && '' !== self::assistant_id();
	}

	/**
	 * The connected assistant id.
	 *
	 * @return string
	 */
	public static function assistant_id() {
		return (string) get_option( self::ASSISTANT_ID, '' );
	}

	/**
	 * The connected assistant's name.
	 *
	 * @return string
	 */
	public static function assistant_name() {
		return (string) get_option( self::ASSISTANT_NAME, '' );
	}

	/**
	 * Name of the workspace the site belongs to (last seen in /site/status).
	 *
	 * @return string
	 */
	public static function workspace_name() {
		return (string) get_option( self::WORKSPACE_NAME, '' );
	}

	/**
	 * Chat widget settings, with defaults (off until the owner turns it on).
	 *
	 * @return array{enabled:bool,position:string,lang:string,hide_on:string}
	 */
	public static function chat() {
		$saved = get_option( self::CHAT, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'  => false,
				'position' => 'right',
				'lang'     => 'auto',
				'hide_on'  => '',
			)
		);
	}

	/**
	 * Form-lead settings, with defaults (off until the owner turns it on).
	 *
	 * @return array{enabled:bool}
	 */
	public static function forms() {
		$saved = get_option( self::FORMS, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array( 'enabled' => false )
		);
	}
}
