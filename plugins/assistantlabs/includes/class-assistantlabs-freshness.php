<?php
/**
 * Knowledge freshness: remember when published content changed.
 *
 * This NEVER calls the server. The owner decides when the agent re-learns the
 * site, with the "Update the agent from my site" button.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores assistantlabs_site_changed_at when a published page, post or product changes.
 */
class AssistantLabs_Freshness {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	/**
	 * Post types that count.
	 *
	 * @return string[]
	 */
	private static function types() {
		return array( 'page', 'post', 'product' );
	}

	/**
	 * A published post was saved.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' === $post->post_status && in_array( $post->post_type, self::types(), true ) ) {
			self::mark();
		}
	}

	/**
	 * Something left "publish" (unpublished, trashed).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status && in_array( $post->post_type, self::types(), true ) ) {
			self::mark();
		}
	}

	/**
	 * Remember the time.
	 */
	public static function mark() {
		update_option( AssistantLabs_Options::SITE_CHANGED_AT, time(), false );
	}

	/**
	 * Has the site changed since the agent last learned it?
	 *
	 * @param mixed $last_synced lastSyncedAt from /site/status (ISO string or epoch s/ms), if any.
	 * @return bool
	 */
	public static function is_stale( $last_synced = null ) {
		$changed = (int) get_option( AssistantLabs_Options::SITE_CHANGED_AT, 0 );
		if ( ! $changed ) {
			return false;
		}
		$learned = max(
			(int) get_option( AssistantLabs_Options::KNOWLEDGE_SYNCED, 0 ),
			self::to_timestamp( $last_synced )
		);
		return $changed > $learned;
	}

	/**
	 * ISO string / seconds / milliseconds → seconds.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function to_timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			$value = (float) $value;
			return (int) ( $value > 1e12 ? $value / 1000 : $value );
		}
		if ( is_string( $value ) && '' !== $value ) {
			$ts = strtotime( $value );
			return $ts ? $ts : 0;
		}
		return 0;
	}
}
