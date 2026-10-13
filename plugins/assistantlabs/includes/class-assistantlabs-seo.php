<?php
/**
 * SEO title/description through Yoast SEO or Rank Math.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the SEO meta of whichever SEO plugin is active.
 */
class AssistantLabs_Seo {

	const YOAST    = 'yoast';
	const RANKMATH = 'rankmath';

	/**
	 * Meta keys per plugin.
	 *
	 * @var array<string,array{title:string,description:string}>
	 */
	private static $keys = array(
		self::YOAST    => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
		),
		self::RANKMATH => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
		),
	);

	/**
	 * The active SEO plugin, or null.
	 *
	 * @return string|null
	 */
	public static function active_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return self::YOAST;
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return self::RANKMATH;
		}
		return null;
	}

	/**
	 * SEO block for /content/{id}.
	 *
	 * Without an active plugin, falls back to whichever meta the post carries
	 * (a plugin may have been switched off), and reports plugin: null.
	 *
	 * @param int $post_id Post id.
	 * @return array{title:string,description:string,plugin:?string}
	 */
	public static function read( $post_id ) {
		$plugin = self::active_plugin();
		$order  = $plugin ? array( $plugin ) : array( self::YOAST, self::RANKMATH );
		foreach ( $order as $candidate ) {
			$title       = (string) get_post_meta( $post_id, self::$keys[ $candidate ]['title'], true );
			$description = (string) get_post_meta( $post_id, self::$keys[ $candidate ]['description'], true );
			if ( $plugin || '' !== $title || '' !== $description ) {
				return array(
					'title'       => $title,
					'description' => $description,
					'plugin'      => $plugin,
				);
			}
		}
		return array(
			'title'       => '',
			'description' => '',
			'plugin'      => null,
		);
	}

	/**
	 * Write SEO fields. Does nothing without an active SEO plugin.
	 *
	 * @param int   $post_id Post id.
	 * @param array $seo     {title?, description?}.
	 * @return bool Whether anything was written.
	 */
	public static function write( $post_id, $seo ) {
		$plugin = self::active_plugin();
		if ( ! $plugin || ! is_array( $seo ) ) {
			return false;
		}
		$wrote = false;
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( isset( $seo[ $field ] ) && is_string( $seo[ $field ] ) ) {
				update_post_meta( $post_id, self::$keys[ $plugin ][ $field ], sanitize_text_field( $seo[ $field ] ) );
				$wrote = true;
			}
		}
		return $wrote;
	}

	/**
	 * Current values, so a discarded edit can be put back.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function snapshot( $post_id ) {
		$plugin = self::active_plugin();
		if ( ! $plugin ) {
			return array();
		}
		return array(
			'title'       => (string) get_post_meta( $post_id, self::$keys[ $plugin ]['title'], true ),
			'description' => (string) get_post_meta( $post_id, self::$keys[ $plugin ]['description'], true ),
		);
	}
}
