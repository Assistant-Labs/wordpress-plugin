<?php
/**
 * The chat widget on the public site (off until the owner turns it on).
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues {APP_URL}/assistantlabs-loader.js with the data-* attributes it reads.
 */
class AssistantLabs_Widget {

	const HANDLE = 'assistantlabs-loader';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
	}

	/**
	 * Whether to show the chat on this request.
	 *
	 * @return bool
	 */
	public static function should_show() {
		if ( is_admin() || ! AssistantLabs_Options::is_connected() ) {
			return false;
		}
		$settings = AssistantLabs_Options::chat();
		$show     = ! empty( $settings['enabled'] ) && ! self::is_hidden_here( $settings['hide_on'] );
		/**
		 * Filter whether the chat widget prints on this request.
		 *
		 * @param bool $show Show it.
		 */
		return (bool) apply_filters( 'assistantlabs_show_chat', $show );
	}

	/**
	 * Is the current path in the "hide on these pages" list?
	 *
	 * One path or URL per line. A trailing * matches everything below it.
	 *
	 * @param string $list Lines.
	 * @return bool
	 */
	public static function is_hidden_here( $list ) {
		$list = trim( (string) $list );
		if ( '' === $list ) {
			return false;
		}
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$current = self::normalize_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

		foreach ( preg_split( '/\r\n|\r|\n/', $list ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$path     = (string) wp_parse_url( $line, PHP_URL_PATH );
			$wildcard = '*' === substr( $line, -1 );
			$path     = self::normalize_path( rtrim( $path, '*' ) );
			if ( $wildcard ? 0 === strpos( $current, $path ) : $current === $path ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lowercase, decoded, no trailing slash (except root).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$path = strtolower( rawurldecode( $path ) );
		$path = '/' . trim( $path, '/' );
		return $path;
	}

	/**
	 * Language for data-lang.
	 *
	 * @return string
	 */
	public static function lang() {
		$settings = AssistantLabs_Options::chat();
		if ( ! empty( $settings['lang'] ) && 'auto' !== $settings['lang'] ) {
			return $settings['lang'];
		}
		return substr( get_locale(), 0, 2 );
	}

	/**
	 * Enqueue the loader in the footer, async.
	 */
	public static function enqueue() {
		if ( ! self::should_show() ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE,
			untrailingslashit( ASSISTANTLABS_APP_URL ) . '/assistantlabs-loader.js',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the loader is versioned by AssistantLabs.
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
	}

	/**
	 * Add the data-* attributes the loader reads.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function script_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$settings = AssistantLabs_Options::chat();
		$attrs    = sprintf(
			' data-assistant-id="%s" data-position="%s" data-lang="%s"',
			esc_attr( AssistantLabs_Options::assistant_id() ),
			esc_attr( 'left' === $settings['position'] ? 'left' : 'right' ),
			esc_attr( self::lang() )
		);
		if ( false === strpos( $tag, ' async' ) ) {
			$attrs .= ' async';
		}
		return preg_replace( '/<script\b/', '<script' . $attrs, $tag, 1 );
	}
}
