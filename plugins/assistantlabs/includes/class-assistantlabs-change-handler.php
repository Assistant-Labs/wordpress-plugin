<?php
/**
 * Base class for change kinds (create, update, product_create, …).
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A change kind. Register more through the `assistantlabs_change_handlers` filter.
 *
 * The "record" passed around is an array with:
 * - target_id      (int)    the post/product/coupon the change is about;
 * - target_type    (string) its post type;
 * - target_title   (string) its title when the change was created;
 * - created_target (bool)   the change inserted the target as a draft;
 * - applied        (bool)   the change was already written to a draft target;
 * - payload        (array)  what publish() writes to a live target;
 * - snapshot       (array)  previous values, so discard() can put them back;
 * - summary        (string) one line for people.
 */
abstract class AssistantLabs_Change_Handler {

	/**
	 * Validate the request body and do what is allowed now: insert a draft,
	 * edit a draft in place, or just keep the payload for a live target.
	 *
	 * Never makes anything live.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error Record.
	 */
	abstract public function create( array $body );

	/**
	 * Make the change live.
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	abstract public function publish( array $record );

	/**
	 * Throw the change away: trash a draft it created, or put back a draft it edited.
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	abstract public function discard( array $record );

	/**
	 * Whether the change has a front-end preview.
	 *
	 * @param array $record Record.
	 * @return bool
	 */
	public function has_preview( array $record ) {
		return true;
	}

	/**
	 * Register the filters that show the proposed values on the preview page.
	 * Called only for a valid preview, after the main query is set up.
	 *
	 * @param array $record Record.
	 */
	public function preview( array $record ) {}

	/**
	 * Small helpers shared by handlers.
	 */

	/**
	 * 400 error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	protected function invalid( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	/**
	 * A non-empty string field, or null.
	 *
	 * @param array  $body Body.
	 * @param string $key  Key.
	 * @return string|null
	 */
	protected function str( array $body, $key ) {
		if ( ! isset( $body[ $key ] ) ) {
			return null;
		}
		if ( is_int( $body[ $key ] ) || is_float( $body[ $key ] ) ) {
			return (string) $body[ $key ];
		}
		return is_string( $body[ $key ] ) ? $body[ $key ] : null;
	}

	/**
	 * Is the post a draft-like status (not visible to visitors)?
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	protected function is_draft( $post ) {
		return in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true );
	}

	/**
	 * Move a draft the change created to the trash (never a hard delete).
	 * A target the owner has since published is left alone.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	protected function trash_draft( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return true;
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'future' ), true ) ) {
			return true;
		}
		$trashed = wp_trash_post( $post_id );
		if ( ! $trashed ) {
			return new WP_Error( 'trash_failed', __( 'Could not move the draft to the trash.', 'assistantlabs' ), array( 'status' => 500 ) );
		}
		return true;
	}
}
