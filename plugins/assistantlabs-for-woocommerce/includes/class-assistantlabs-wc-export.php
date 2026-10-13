<?php
/**
 * Products and policies in /export, products in /content.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store content the agent learns from.
 */
class AssistantLabs_WC_Export {

	const EXPORT_PRODUCTS = 100;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'assistantlabs_content_types', array( __CLASS__, 'content_types' ) );
		add_filter( 'assistantlabs_content_item', array( __CLASS__, 'content_item' ), 10, 2 );
		add_filter( 'assistantlabs_export', array( __CLASS__, 'export' ) );
		add_filter( 'assistantlabs_export_exclude_ids', array( __CLASS__, 'exclude_ids' ), 10, 2 );
	}

	/**
	 * Add `product` to /content.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	public static function content_types( $types ) {
		$types[] = 'product';
		return $types;
	}

	/**
	 * Add the `product` block to /content/{id}.
	 *
	 * @param array   $data Item.
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function content_item( $data, $post ) {
		if ( 'product' !== $post->post_type ) {
			return $data;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return $data;
		}
		$data['product'] = array(
			'regularPrice'  => (string) $product->get_regular_price( 'edit' ),
			'salePrice'     => (string) $product->get_sale_price( 'edit' ),
			'price'         => (string) $product->get_price( 'edit' ),
			'stockQuantity' => null === $product->get_stock_quantity( 'edit' ) ? null : (int) $product->get_stock_quantity( 'edit' ),
			'stockStatus'   => $product->get_stock_status( 'edit' ),
			'manageStock'   => (bool) $product->get_manage_stock( 'edit' ),
			'sku'           => (string) $product->get_sku( 'edit' ),
		);
		return $data;
	}

	/**
	 * Leave the store's machinery pages out of the page list: cart, checkout
	 * and account pages say nothing about the business, and the policy pages
	 * already go out as `policies`.
	 *
	 * @param int[]  $ids  Post ids.
	 * @param string $type Post type.
	 * @return int[]
	 */
	public static function exclude_ids( $ids, $type ) {
		if ( 'page' !== $type ) {
			return $ids;
		}
		foreach ( array( 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
			$ids[] = (int) wc_get_page_id( $page );
		}
		$ids[] = (int) get_option( 'woocommerce_refund_returns_page_id' );
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Add `products` and `policies` to /export.
	 *
	 * @param array $data Export.
	 * @return array
	 */
	public static function export( $data ) {
		$data['products'] = self::products();
		$data['policies'] = self::policies();
		return $data;
	}

	/**
	 * Up to 100 published, visible products.
	 *
	 * @return array
	 */
	public static function products() {
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => self::EXPORT_PRODUCTS,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$currency = get_woocommerce_currency();
		$rows     = array();
		foreach ( $products as $product ) {
			if ( 'hidden' === $product->get_catalog_visibility() ) {
				continue;
			}
			$categories = array();
			foreach ( $product->get_category_ids() as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$categories[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
				}
			}
			$rows[] = array(
				'id'               => (int) $product->get_id(),
				'name'             => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
				'url'              => $product->get_permalink(),
				'price'            => (string) $product->get_price(),
				'regularPrice'     => (string) $product->get_regular_price(),
				'salePrice'        => (string) $product->get_sale_price(),
				'currency'         => $currency,
				'sku'              => (string) $product->get_sku(),
				'stockStatus'      => $product->get_stock_status(),
				'shortDescription' => AssistantLabs_Content::to_text( $product->get_short_description(), 1000 ),
				'categories'       => $categories,
			);
		}
		return $rows;
	}

	/**
	 * Shipping, refunds, terms and privacy as plain text.
	 *
	 * @return array{shipping:string,refunds:string,terms:string,privacy:string}
	 */
	public static function policies() {
		$terms_id = (int) get_option( 'woocommerce_terms_page_id', 0 );
		if ( ! $terms_id && function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			$terms_id = (int) wc_terms_and_conditions_page_id();
		}
		if ( ! $terms_id ) {
			$terms_id = (int) wc_get_page_id( 'terms' );
		}
		return array(
			'shipping' => self::shipping_summary(),
			'refunds'  => self::page_text( (int) get_option( 'woocommerce_refund_returns_page_id', 0 ) ),
			'terms'    => self::page_text( $terms_id ),
			'privacy'  => self::page_text( (int) get_option( 'wp_page_for_privacy_policy', 0 ) ),
		);
	}

	/**
	 * A published page's plain text, or ''.
	 *
	 * @param int $page_id Page id.
	 * @return string
	 */
	private static function page_text( $page_id ) {
		if ( $page_id <= 0 ) {
			return '';
		}
		$page = get_post( $page_id );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return '';
		}
		return AssistantLabs_Content::plain_text( $page );
	}

	/**
	 * Shipping zones and their enabled methods as text.
	 *
	 * @return string
	 */
	public static function shipping_summary() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ( function_exists( 'wc_shipping_enabled' ) && ! wc_shipping_enabled() ) ) {
			return '';
		}
		$zones    = WC_Shipping_Zones::get_zones();
		$zones[]  = array( 'zone_id' => 0 );
		$lines    = array();
		$currency = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

		foreach ( $zones as $zone_data ) {
			$zone    = new WC_Shipping_Zone( isset( $zone_data['zone_id'] ) ? (int) $zone_data['zone_id'] : 0 );
			$methods = array();
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				$methods[] = self::describe_method( $method, $currency );
			}
			if ( ! $methods ) {
				continue;
			}
			$name = 0 === $zone->get_id() ? __( 'Everywhere else', 'assistantlabs-for-woocommerce' ) : $zone->get_zone_name();
			$where = 0 === $zone->get_id() ? '' : $zone->get_formatted_location( 20 );
			$label = $where && $where !== $name ? $name . ' (' . $where . ')' : $name;
			$lines[] = wp_strip_all_tags( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ) . ': ' . implode( '; ', $methods ) . '.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * "Flat rate 25 ₪", "Free shipping over 200 ₪", "Local pickup".
	 *
	 * @param WC_Shipping_Method $method   Method.
	 * @param string             $currency Currency symbol.
	 * @return string
	 */
	private static function describe_method( $method, $currency ) {
		$title = wp_strip_all_tags( html_entity_decode( $method->get_title(), ENT_QUOTES, 'UTF-8' ) );
		$cost  = $method->get_option( 'cost', '' );
		if ( 'free_shipping' === $method->id ) {
			$min = $method->get_option( 'min_amount', '' );
			if ( '' !== $min && (float) $min > 0 ) {
				/* translators: 1: method title, 2: minimum amount, 3: currency symbol. */
				return sprintf( __( '%1$s on orders over %2$s %3$s', 'assistantlabs-for-woocommerce' ), $title, $min, $currency );
			}
			return $title;
		}
		if ( '' !== $cost && is_numeric( $cost ) ) {
			return 0.0 === (float) $cost
				? sprintf( '%s (%s)', $title, __( 'free', 'assistantlabs-for-woocommerce' ) )
				: sprintf( '%s %s %s', $title, $cost, $currency );
		}
		if ( '' !== $cost ) {
			/* translators: 1: method title, 2: cost formula. */
			return sprintf( __( '%1$s (cost: %2$s)', 'assistantlabs-for-woocommerce' ), $title, $cost );
		}
		return $title;
	}
}
