<?php
/**
 * The `create` and `update` change kinds for pages and posts.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for post-based handlers.
 */
abstract class AssistantLabs_Post_Change_Handler extends AssistantLabs_Change_Handler {

	/**
	 * Human label for a post type ("page", "post", "product").
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	protected function type_label( $type ) {
		$object = get_post_type_object( $type );
		return $object ? strtolower( $object->labels->singular_name ) : $type;
	}

	/**
	 * Publish a draft target.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	protected function publish_draft( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'target_missing', __( 'The draft no longer exists.', 'assistantlabs' ), array( 'status' => 409 ) );
		}
		if ( 'publish' === $post->post_status ) {
			return true;
		}
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Write title/content/excerpt/seo fields to a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  {title?, content?, excerpt?, seo?}.
	 * @return true|WP_Error
	 */
	protected function write_fields( $post_id, array $fields ) {
		$update = array( 'ID' => $post_id );
		if ( isset( $fields['title'] ) ) {
			$update['post_title'] = $fields['title'];
		}
		if ( isset( $fields['content'] ) ) {
			$update['post_content'] = $fields['content'];
		}
		if ( isset( $fields['excerpt'] ) ) {
			$update['post_excerpt'] = $fields['excerpt'];
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( ! empty( $fields['seo'] ) ) {
			AssistantLabs_Seo::write( $post_id, $fields['seo'] );
		}
		return true;
	}

	/**
	 * Clean the editable fields of a body. Content goes through wp_kses_post
	 * even though the agent user may hold unfiltered_html.
	 *
	 * @param array $body Body.
	 * @return array
	 */
	protected function clean_fields( array $body ) {
		$fields = array();
		$title  = $this->str( $body, 'title' );
		if ( null !== $title ) {
			$fields['title'] = sanitize_text_field( $title );
		}
		$content = $this->str( $body, 'content' );
		if ( null !== $content ) {
			$fields['content'] = wp_kses_post( $content );
		}
		$excerpt = $this->str( $body, 'excerpt' );
		if ( null !== $excerpt ) {
			$fields['excerpt'] = sanitize_textarea_field( $excerpt );
		}
		if ( isset( $body['seo'] ) && is_array( $body['seo'] ) ) {
			$seo = array();
			foreach ( array( 'title', 'description' ) as $key ) {
				if ( isset( $body['seo'][ $key ] ) && is_string( $body['seo'][ $key ] ) ) {
					$seo[ $key ] = sanitize_text_field( $body['seo'][ $key ] );
				}
			}
			if ( $seo ) {
				$fields['seo'] = $seo;
			}
		}
		return $fields;
	}

	/**
	 * Translatable names of changed fields, for summaries.
	 *
	 * @param array $fields Cleaned fields.
	 * @return string
	 */
	protected function field_names( array $fields ) {
		$names = array(
			'title'   => __( 'title', 'assistantlabs' ),
			'content' => __( 'content', 'assistantlabs' ),
			'excerpt' => __( 'excerpt', 'assistantlabs' ),
			'seo'     => __( 'SEO', 'assistantlabs' ),
		);
		$out   = array();
		foreach ( array_keys( $fields ) as $key ) {
			$out[] = isset( $names[ $key ] ) ? $names[ $key ] : $key;
		}
		return implode( ', ', $out );
	}
}

/**
 * `create`: insert a new page or post as a draft.
 */
class AssistantLabs_Create_Change_Handler extends AssistantLabs_Post_Change_Handler {

	/**
	 * {@inheritDoc}
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	public function create( array $body ) {
		$type = isset( $body['type'] ) ? $body['type'] : '';
		if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
			return $this->invalid( 'invalid_type', __( 'type must be "page" or "post".', 'assistantlabs' ) );
		}
		$fields = $this->clean_fields( $body );
		if ( empty( $fields['title'] ) ) {
			return $this->invalid( 'missing_title', __( 'title is required.', 'assistantlabs' ) );
		}
		if ( ! isset( $fields['content'] ) ) {
			return $this->invalid( 'missing_content', __( 'content is required.', 'assistantlabs' ) );
		}
		if ( ! current_user_can( 'page' === $type ? 'edit_pages' : 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'The AssistantLabs user cannot create this content.', 'assistantlabs' ), array( 'status' => 403 ) );
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $type,
					'post_status'  => 'draft',
					'post_title'   => $fields['title'],
					'post_content' => $fields['content'],
					'post_excerpt' => isset( $fields['excerpt'] ) ? $fields['excerpt'] : '',
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( ! empty( $fields['seo'] ) ) {
			AssistantLabs_Seo::write( $post_id, $fields['seo'] );
		}

		return array(
			'target_id'      => (int) $post_id,
			'target_type'    => $type,
			'target_title'   => $fields['title'],
			'created_target' => true,
			'applied'        => true,
			'payload'        => array(),
			'snapshot'       => array(),
			/* translators: 1: post type label (page/post), 2: title. */
			'summary'        => sprintf( __( 'New draft %1$s “%2$s”', 'assistantlabs' ), $this->type_label( $type ), $fields['title'] ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function publish( array $record ) {
		return $this->publish_draft( $record['target_id'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function discard( array $record ) {
		return $this->trash_draft( $record['target_id'] );
	}
}

/**
 * `update`: edit a draft in place, or keep a proposed edit for a live post.
 */
class AssistantLabs_Update_Change_Handler extends AssistantLabs_Post_Change_Handler {

	/**
	 * {@inheritDoc}
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	public function create( array $body ) {
		$target_id = isset( $body['targetId'] ) ? (int) $body['targetId'] : 0;
		$post      = AssistantLabs_Content::get_post( $target_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$fields = $this->clean_fields( $body );
		if ( ! $fields ) {
			return $this->invalid( 'nothing_to_change', __( 'Send at least one of title, content, excerpt or seo.', 'assistantlabs' ) );
		}
		if ( isset( $fields['title'] ) && '' === $fields['title'] ) {
			return $this->invalid( 'missing_title', __( 'title cannot be empty.', 'assistantlabs' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', __( 'The AssistantLabs user cannot edit this content.', 'assistantlabs' ), array( 'status' => 403 ) );
		}

		$record = array(
			'target_id'      => (int) $post->ID,
			'target_type'    => $post->post_type,
			'target_title'   => $post->post_title,
			'created_target' => false,
			'applied'        => false,
			'payload'        => $fields,
			'snapshot'       => array(),
			/* translators: 1: post type label, 2: title, 3: list of changed fields. */
			'summary'        => sprintf( __( 'Edit %1$s “%2$s”: %3$s', 'assistantlabs' ), $this->type_label( $post->post_type ), $post->post_title, $this->field_names( $fields ) ),
		);

		if ( $this->is_draft( $post ) ) {
			// A draft is not live: edit it in place, keep the old values for discard.
			$record['snapshot'] = array(
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
				'seo'     => AssistantLabs_Seo::snapshot( $post->ID ),
			);
			$written            = $this->write_fields( $post->ID, $fields );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$record['applied'] = true;
		}

		return $record;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function publish( array $record ) {
		if ( ! empty( $record['applied'] ) ) {
			return $this->publish_draft( $record['target_id'] );
		}
		$post = get_post( $record['target_id'] );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'target_missing', __( 'The page this change edits no longer exists.', 'assistantlabs' ), array( 'status' => 409 ) );
		}
		return $this->write_fields( $post->ID, (array) $record['payload'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function discard( array $record ) {
		if ( empty( $record['applied'] ) || empty( $record['snapshot'] ) ) {
			return true; // Nothing was written.
		}
		$post = get_post( $record['target_id'] );
		if ( ! $post || ! $this->is_draft( $post ) ) {
			return true; // Gone, or the owner published it meanwhile: leave it.
		}
		$snapshot = $record['snapshot'];
		$restore  = array_intersect_key( $snapshot, (array) $record['payload'] );
		return $this->write_fields( $post->ID, $restore );
	}

	/**
	 * Show the proposed title/content/excerpt on the live post.
	 *
	 * @param array $record Record.
	 */
	public function preview( array $record ) {
		if ( ! empty( $record['applied'] ) ) {
			return; // The draft itself already holds the change.
		}
		$target  = (int) $record['target_id'];
		$payload = (array) $record['payload'];

		if ( isset( $payload['title'] ) ) {
			$title = $payload['title'];
			add_filter(
				'the_title',
				static function ( $value, $id = 0 ) use ( $target, $title ) {
					return (int) $id === $target ? esc_html( $title ) : $value;
				},
				0,
				2
			);
			add_filter(
				'single_post_title',
				static function ( $value, $post = null ) use ( $target, $title ) {
					return $post && (int) $post->ID === $target ? $title : $value;
				},
				0,
				2
			);
			add_filter(
				'document_title_parts',
				static function ( $parts ) use ( $title ) {
					$parts['title'] = $title;
					return $parts;
				},
				0
			);
		}
		if ( isset( $payload['content'] ) ) {
			$content = $payload['content'];
			add_filter(
				'the_content',
				static function ( $value ) use ( $target, $content ) {
					return (int) get_the_ID() === $target ? $content : $value;
				},
				0
			);
		}
		if ( isset( $payload['excerpt'] ) ) {
			$excerpt = $payload['excerpt'];
			add_filter(
				'get_the_excerpt',
				static function ( $value, $post = null ) use ( $target, $excerpt ) {
					return $post && (int) $post->ID === $target ? $excerpt : $value;
				},
				0,
				2
			);
		}
	}
}
