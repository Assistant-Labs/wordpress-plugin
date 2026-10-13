<?php
/**
 * Reading site content: /content, /content/{id} and /export.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content helpers shared by the REST routes and the change handlers.
 */
class AssistantLabs_Content {

	const TEXT_LIMIT    = 4000;
	const EXPORT_PAGES  = 50;
	const EXPORT_POSTS  = 20;
	const LIST_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * Post types the agent may read and edit.
	 *
	 * @return string[]
	 */
	public static function types() {
		/**
		 * Filter the post types exposed through /content. The WooCommerce add-on adds `product`.
		 *
		 * @param string[] $types Post types.
		 */
		$types = apply_filters( 'assistantlabs_content_types', array( 'page', 'post' ) );
		return array_values( array_unique( array_filter( (array) $types, 'post_type_exists' ) ) );
	}

	/**
	 * ISO-8601 UTC from a GMT MySQL date.
	 *
	 * @param string $gmt MySQL datetime in GMT.
	 * @return string|null
	 */
	public static function iso( $gmt ) {
		if ( ! $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return null;
		}
		$ts = strtotime( $gmt . ' UTC' );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null;
	}

	/**
	 * ISO-8601 UTC from a unix timestamp.
	 *
	 * @param int|null $ts Unix seconds.
	 * @return string|null
	 */
	public static function iso_ts( $ts ) {
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts ) : null;
	}

	/**
	 * GET /content.
	 *
	 * @param array $args {type, search, status, page, perPage}.
	 * @return array|WP_Error
	 */
	public static function list_items( $args ) {
		$types = self::types();
		$type  = isset( $args['type'] ) && '' !== $args['type'] ? $args['type'] : 'any';
		if ( 'any' !== $type && ! in_array( $type, $types, true ) ) {
			return new WP_Error( 'invalid_type', __( 'Unsupported content type.', 'assistantlabs' ), array( 'status' => 400 ) );
		}
		$status = isset( $args['status'] ) && '' !== $args['status'] ? $args['status'] : 'any';
		if ( 'any' === $status ) {
			$status = self::LIST_STATUSES;
		} elseif ( ! in_array( $status, self::LIST_STATUSES, true ) ) {
			return new WP_Error( 'invalid_status', __( 'Unsupported status.', 'assistantlabs' ), array( 'status' => 400 ) );
		}

		$per_page = max( 1, min( 100, isset( $args['perPage'] ) ? (int) $args['perPage'] : 20 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );

		$query_args = array(
			'post_type'           => 'any' === $type ? $types : $type,
			'post_status'         => $status,
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}
		$query = new WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * One list row.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function summary( $post ) {
		return array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'status'   => $post->post_status,
			'url'      => self::url( $post ),
			'modified' => self::iso( $post->post_modified_gmt ),
			'excerpt'  => self::excerpt( $post ),
		);
	}

	/**
	 * GET /content/{id}.
	 *
	 * @param int $id Post id.
	 * @return array|WP_Error
	 */
	public static function read( $id ) {
		$post = self::get_post( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$data = array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'url'      => self::url( $post ),
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'modified' => self::iso( $post->post_modified_gmt ),
			'seo'      => AssistantLabs_Seo::read( $post->ID ),
		);
		/**
		 * Filter one /content/{id} item. The WooCommerce add-on adds `product`.
		 *
		 * @param array   $data Item.
		 * @param WP_Post $post Post.
		 */
		return apply_filters( 'assistantlabs_content_item', $data, $post );
	}

	/**
	 * A post the agent may see, or a 404.
	 *
	 * @param int $id Post id.
	 * @return WP_Post|WP_Error
	 */
	public static function get_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Content not found.', 'assistantlabs' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * Public URL of a post (the permalink, or the plain preview-less link for drafts).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function url( $post ) {
		$url = get_permalink( $post );
		return $url ? $url : '';
	}

	/**
	 * Short excerpt: the manual one, or the first words of the content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function excerpt( $post ) {
		if ( '' !== trim( $post->post_excerpt ) ) {
			return self::collapse( wp_strip_all_tags( $post->post_excerpt ) );
		}
		$text = excerpt_remove_blocks( strip_shortcodes( $post->post_content ) );
		return self::collapse( wp_trim_words( $text, 30, '…' ) );
	}

	/**
	 * Rendered content as plain text, at most 4,000 characters.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function plain_text( $post ) {
		global $wp_query;
		$saved_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		// the_content expects to run inside the loop.
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		$in_the_loop = $wp_query ? $wp_query->in_the_loop : false;
		if ( $wp_query ) {
			$wp_query->in_the_loop = true;
		}

		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( $wp_query ) {
			$wp_query->in_the_loop = $in_the_loop;
		}
		$GLOBALS['post'] = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $saved_post ) {
			setup_postdata( $saved_post );
		}

		return self::to_text( $html );
	}

	/**
	 * HTML → collapsed plain text, capped.
	 *
	 * @param string $html  HTML.
	 * @param int    $limit Character cap.
	 * @return string
	 */
	public static function to_text( $html, $limit = self::TEXT_LIMIT ) {
		// Keep block boundaries as spaces so words don't run together.
		$html = preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th)[^>]*>#i', '$0 ', (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = self::collapse( $text );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $limit );
		}
		return substr( $text, 0, $limit );
	}

	/**
	 * Collapse whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function collapse( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Export rows for published posts of a type.
	 *
	 * @param string $type  Post type.
	 * @param int    $limit Max rows.
	 * @param string $order 'menu' for pages, 'date' for posts.
	 * @return array
	 */
	private static function export_rows( $type, $limit, $order ) {
		/**
		 * Filter the ids left out of /export — pages with nothing for the agent
		 * to learn (the WooCommerce add-on drops cart and checkout).
		 *
		 * @param int[]  $ids  Post ids.
		 * @param string $type Post type.
		 */
		$excluded = array_map( 'intval', (array) apply_filters( 'assistantlabs_export_exclude_ids', array(), $type ) );
		$posts    = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'posts_per_page'   => $limit + count( $excluded ),
				'orderby'          => 'menu' === $order ? array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				) : 'date',
				'order'            => 'DESC',
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		$rows  = array();
		foreach ( $posts as $post ) {
			if ( in_array( (int) $post->ID, $excluded, true ) || count( $rows ) >= $limit ) {
				continue;
			}
			$rows[] = array(
				'id'       => (int) $post->ID,
				'type'     => $post->post_type,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'      => self::url( $post ),
				'text'     => self::plain_text( $post ),
				'modified' => self::iso( $post->post_modified_gmt ),
			);
		}
		return $rows;
	}

	/**
	 * GET /export.
	 *
	 * @return array
	 */
	public static function export() {
		$data = array(
			'site'  => array(
				'name'        => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'description' => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
				'url'         => home_url(),
				'language'    => get_locale(),
				'timezone'    => wp_timezone_string(),
			),
			'pages' => self::export_rows( 'page', self::EXPORT_PAGES, 'menu' ),
			'posts' => self::export_rows( 'post', self::EXPORT_POSTS, 'date' ),
		);
		/**
		 * Filter the /export payload. The WooCommerce add-on adds `products` and `policies`.
		 *
		 * @param array $data Export.
		 */
		return apply_filters( 'assistantlabs_export', $data );
	}
}
