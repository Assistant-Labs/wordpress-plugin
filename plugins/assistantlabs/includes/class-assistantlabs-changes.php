<?php
/**
 * Change records (CPT `al_change`): create, list, publish, schedule, discard.
 *
 * Nothing the agent writes goes live on its own. Only publish() makes a change
 * live, and it runs only after the owner said yes.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change store and state machine: pending → scheduled → published, or → discarded.
 */
class AssistantLabs_Changes {

	const POST_TYPE  = 'al_change';
	const CRON_HOOK  = 'assistantlabs_publish_change';
	const STORE_KINDS = array( 'product_create', 'product_update', 'coupon_create' );

	const META_KIND      = '_al_kind';
	const META_STATE     = '_al_state';
	const META_RECORD    = '_al_record';
	const META_TARGET    = '_al_target_id';
	const META_SCHEDULED = '_al_scheduled_for';
	const META_PUBLISHED = '_al_published_at';
	const META_ERROR     = '_al_error';

	const PENDING   = 'pending';
	const SCHEDULED = 'scheduled';
	const PUBLISHED = 'published';
	const DISCARDED = 'discarded';

	/**
	 * Handlers, built once per request.
	 *
	 * @var AssistantLabs_Change_Handler[]|null
	 */
	private static $handlers = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * The private change post type. Not public and not in any menu.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'AssistantLabs changes', 'assistantlabs' ),
					'singular_name' => __( 'AssistantLabs change', 'assistantlabs' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * Kind → handler.
	 *
	 * @return AssistantLabs_Change_Handler[]
	 */
	public static function handlers() {
		if ( null === self::$handlers ) {
			/**
			 * Register change kinds. The WooCommerce add-on adds product_create,
			 * product_update and coupon_create.
			 *
			 * @param AssistantLabs_Change_Handler[] $handlers Kind => handler.
			 */
			$handlers       = apply_filters(
				'assistantlabs_change_handlers',
				array(
					'create' => new AssistantLabs_Create_Change_Handler(),
					'update' => new AssistantLabs_Update_Change_Handler(),
				)
			);
			self::$handlers = array();
			foreach ( (array) $handlers as $kind => $handler ) {
				if ( $handler instanceof AssistantLabs_Change_Handler ) {
					self::$handlers[ $kind ] = $handler;
				}
			}
		}
		return self::$handlers;
	}

	/**
	 * Handler for a kind, or null.
	 *
	 * @param string $kind Kind.
	 * @return AssistantLabs_Change_Handler|null
	 */
	public static function handler( $kind ) {
		$handlers = self::handlers();
		return isset( $handlers[ $kind ] ) ? $handlers[ $kind ] : null;
	}

	/**
	 * POST /changes.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error Change.
	 */
	public static function create( array $body ) {
		$kind    = isset( $body['kind'] ) && is_string( $body['kind'] ) ? $body['kind'] : '';
		$handler = self::handler( $kind );
		if ( ! $handler ) {
			if ( in_array( $kind, self::STORE_KINDS, true ) ) {
				return new WP_Error( 'woocommerce_not_active', __( 'WooCommerce and the AssistantLabs for WooCommerce add-on must be active for this change.', 'assistantlabs' ), array( 'status' => 400 ) );
			}
			return new WP_Error( 'invalid_kind', __( 'Unknown change kind.', 'assistantlabs' ), array( 'status' => 400 ) );
		}

		$record = $handler->create( $body );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$record = self::normalize_record( $record );

		$change_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'private',
					'post_title'  => $record['summary'],
					'post_author' => AssistantLabs_Agent_User::id(),
				)
			),
			true
		);
		if ( is_wp_error( $change_id ) ) {
			// Don't leave an orphan draft behind.
			$handler->discard( $record );
			return $change_id;
		}

		update_post_meta( $change_id, self::META_KIND, $kind );
		update_post_meta( $change_id, self::META_STATE, self::PENDING );
		update_post_meta( $change_id, self::META_TARGET, (int) $record['target_id'] );
		self::save_record( $change_id, $record );

		/**
		 * A change was proposed.
		 *
		 * @param int   $change_id Change id.
		 * @param array $record    Record.
		 */
		do_action( 'assistantlabs_change_created', $change_id, $record );

		return self::to_array( get_post( $change_id ) );
	}

	/**
	 * GET /changes.
	 *
	 * @param string $state pending|scheduled|published|discarded|all.
	 * @param int    $limit Max items.
	 * @return array{items:array}
	 */
	public static function list_changes( $state = self::PENDING, $limit = 100 ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( 'all' !== $state ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::META_STATE,
					'value' => is_array( $state ) ? $state : array( $state ),
					'compare' => 'IN',
				),
			);
		}
		$items = array();
		foreach ( get_posts( $args ) as $post ) {
			$items[] = self::to_array( $post );
		}
		return array( 'items' => $items );
	}

	/**
	 * Number of changes waiting for the owner.
	 *
	 * @return int
	 */
	public static function pending_count() {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_key'       => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => self::PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * A change post, or a 404.
	 *
	 * @param int $id Change id.
	 * @return WP_Post|WP_Error
	 */
	public static function get_change_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'change_not_found', __( 'Change not found.', 'assistantlabs' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * GET /changes/{id}.
	 *
	 * @param int $id Change id.
	 * @return array|WP_Error
	 */
	public static function get( $id ) {
		$post = self::get_change_post( $id );
		return is_wp_error( $post ) ? $post : self::to_array( $post );
	}

	/**
	 * POST /changes/{id}/publish. No `at` or a past one: live now. A future `at`: scheduled.
	 *
	 * @param int         $id Change id.
	 * @param string|null $at ISO-8601 time.
	 * @return array|WP_Error Change.
	 */
	public static function publish( $id, $at = null ) {
		$post = self::get_change_post( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$state = self::state( $post->ID );
		if ( ! in_array( $state, array( self::PENDING, self::SCHEDULED ), true ) ) {
			return self::resolved_error( $state );
		}

		if ( null !== $at && '' !== $at ) {
			$timestamp = self::parse_time( $at );
			if ( null === $timestamp ) {
				return new WP_Error( 'invalid_at', __( '`at` must be an ISO-8601 date and time.', 'assistantlabs' ), array( 'status' => 400 ) );
			}
			if ( $timestamp > time() ) {
				return self::schedule( $post->ID, $timestamp );
			}
		}

		$result = self::publish_now( $post->ID );
		return is_wp_error( $result ) ? $result : self::to_array( get_post( $post->ID ) );
	}

	/**
	 * Schedule a change.
	 *
	 * @param int $id        Change id.
	 * @param int $timestamp Unix time.
	 * @return array|WP_Error Change.
	 */
	public static function schedule( $id, $timestamp ) {
		wp_clear_scheduled_hook( self::CRON_HOOK, array( (int) $id ) );
		$scheduled = wp_schedule_single_event( (int) $timestamp, self::CRON_HOOK, array( (int) $id ), true );
		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}
		update_post_meta( $id, self::META_STATE, self::SCHEDULED );
		update_post_meta( $id, self::META_SCHEDULED, (int) $timestamp );
		delete_post_meta( $id, self::META_ERROR );
		return self::to_array( get_post( $id ) );
	}

	/**
	 * Make a change live right now (as the AssistantLabs user, so revisions are attributed to it).
	 *
	 * @param int $id Change id.
	 * @return true|WP_Error
	 */
	public static function publish_now( $id ) {
		$kind    = (string) get_post_meta( $id, self::META_KIND, true );
		$handler = self::handler( $kind );
		if ( ! $handler ) {
			return new WP_Error( 'woocommerce_not_active', __( 'The plugin that handles this change is not active.', 'assistantlabs' ), array( 'status' => 400 ) );
		}
		$record = self::record( $id );
		$result = AssistantLabs_Agent_User::run_as(
			static function () use ( $handler, $record ) {
				return $handler->publish( $record );
			}
		);
		if ( is_wp_error( $result ) ) {
			update_post_meta( $id, self::META_ERROR, $result->get_error_message() );
			return $result;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK, array( (int) $id ) );
		update_post_meta( $id, self::META_STATE, self::PUBLISHED );
		update_post_meta( $id, self::META_PUBLISHED, time() );
		delete_post_meta( $id, self::META_ERROR );

		/**
		 * A change went live.
		 *
		 * @param int   $id     Change id.
		 * @param array $record Record.
		 */
		do_action( 'assistantlabs_change_published', $id, $record );
		return true;
	}

	/**
	 * Cron: publish a scheduled change. On failure it goes back to pending with the error kept.
	 *
	 * @param int $id Change id.
	 */
	public static function run_scheduled( $id ) {
		if ( self::SCHEDULED !== self::state( $id ) ) {
			return;
		}
		$result = self::publish_now( $id );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $id, self::META_STATE, self::PENDING );
			delete_post_meta( $id, self::META_SCHEDULED );
		}
	}

	/**
	 * POST /changes/{id}/discard.
	 *
	 * @param int $id Change id.
	 * @return array|WP_Error Change.
	 */
	public static function discard( $id ) {
		$post = self::get_change_post( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$state = self::state( $post->ID );
		if ( ! in_array( $state, array( self::PENDING, self::SCHEDULED ), true ) ) {
			return self::resolved_error( $state );
		}
		$handler = self::handler( (string) get_post_meta( $post->ID, self::META_KIND, true ) );
		if ( $handler ) {
			$record = self::record( $post->ID );
			$result = AssistantLabs_Agent_User::run_as(
				static function () use ( $handler, $record ) {
					return $handler->discard( $record );
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		wp_clear_scheduled_hook( self::CRON_HOOK, array( (int) $post->ID ) );
		update_post_meta( $post->ID, self::META_STATE, self::DISCARDED );
		delete_post_meta( $post->ID, self::META_SCHEDULED );

		/**
		 * A change was discarded.
		 *
		 * @param int $id Change id.
		 */
		do_action( 'assistantlabs_change_discarded', $post->ID );

		return self::to_array( get_post( $post->ID ) );
	}

	/**
	 * State of a change.
	 *
	 * @param int $id Change id.
	 * @return string
	 */
	public static function state( $id ) {
		$state = (string) get_post_meta( $id, self::META_STATE, true );
		return '' === $state ? self::PENDING : $state;
	}

	/**
	 * Stored record.
	 *
	 * @param int $id Change id.
	 * @return array
	 */
	public static function record( $id ) {
		$raw    = get_post_meta( $id, self::META_RECORD, true );
		$record = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return self::normalize_record( is_array( $record ) ? $record : array() );
	}

	/**
	 * Save a record as JSON (slashed, since post meta is unslashed on write).
	 *
	 * @param int   $id     Change id.
	 * @param array $record Record.
	 */
	private static function save_record( $id, array $record ) {
		update_post_meta( $id, self::META_RECORD, wp_slash( wp_json_encode( $record ) ) );
	}

	/**
	 * Fill record defaults.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	private static function normalize_record( array $record ) {
		return wp_parse_args(
			$record,
			array(
				'target_id'      => 0,
				'target_type'    => '',
				'target_title'   => '',
				'created_target' => false,
				'applied'        => false,
				'payload'        => array(),
				'snapshot'       => array(),
				'summary'        => '',
			)
		);
	}

	/**
	 * 409 for a change that is already published or discarded.
	 *
	 * @param string $state Current state.
	 * @return WP_Error
	 */
	private static function resolved_error( $state ) {
		/* translators: %s: state (published or discarded). */
		return new WP_Error( 'change_resolved', sprintf( __( 'This change is already %s.', 'assistantlabs' ), $state ), array( 'status' => 409 ) );
	}

	/**
	 * Parse ISO-8601 (or anything DateTime understands; no zone = site time zone).
	 *
	 * @param string $value Time.
	 * @return int|null
	 */
	public static function parse_time( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}
		return $date->getTimestamp();
	}

	/**
	 * Preview URL for a change, or null when it has none / is resolved.
	 *
	 * @param int $id Change id.
	 * @return string|null
	 */
	public static function preview_url( $id ) {
		$token = AssistantLabs_Signer::preview_token( $id );
		if ( '' === $token ) {
			return null;
		}
		return add_query_arg(
			array(
				'al_preview' => (int) $id,
				'al_token'   => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * The `Change` JSON shape.
	 *
	 * @param WP_Post $post Change post.
	 * @return array
	 */
	public static function to_array( $post ) {
		$id      = (int) $post->ID;
		$kind    = (string) get_post_meta( $id, self::META_KIND, true );
		$state   = self::state( $id );
		$record  = self::record( $id );
		$handler = self::handler( $kind );
		$target  = $record['target_id'] ? get_post( $record['target_id'] ) : null;

		$open        = in_array( $state, array( self::PENDING, self::SCHEDULED ), true );
		$preview_url = null;
		if ( $open && $handler && $handler->has_preview( $record ) && $target && 'trash' !== $target->post_status ) {
			$preview_url = self::preview_url( $id );
		}

		$live_url = null;
		if ( $target && 'publish' === $target->post_status && is_post_type_viewable( $target->post_type ) ) {
			$live_url = get_permalink( $target );
		}

		$scheduled = (int) get_post_meta( $id, self::META_SCHEDULED, true );
		$published = (int) get_post_meta( $id, self::META_PUBLISHED, true );

		$data = array(
			'id'           => $id,
			'kind'         => $kind,
			'state'        => $state,
			'summary'      => $record['summary'] ? $record['summary'] : $post->post_title,
			'targetId'     => $record['target_id'] ? (int) $record['target_id'] : null,
			'targetType'   => $record['target_type'] ? $record['target_type'] : null,
			'targetTitle'  => $target ? html_entity_decode( get_the_title( $target ), ENT_QUOTES, 'UTF-8' ) : $record['target_title'],
			'previewUrl'   => $preview_url,
			'editUrl'      => $target ? admin_url( 'post.php?post=' . (int) $target->ID . '&action=edit' ) : null,
			'liveUrl'      => $live_url ? $live_url : null,
			'scheduledFor' => self::SCHEDULED === $state && $scheduled ? AssistantLabs_Content::iso_ts( $scheduled ) : null,
			'createdAt'    => AssistantLabs_Content::iso( $post->post_date_gmt ),
			'publishedAt'  => $published ? AssistantLabs_Content::iso_ts( $published ) : null,
		);

		$error = (string) get_post_meta( $id, self::META_ERROR, true );
		if ( '' !== $error ) {
			$data['error'] = $error;
		}
		return $data;
	}
}
