<?php
/**
 * GET /store/summary — sales, recent orders and low stock for the owner.
 *
 * Orders are read only through wc_get_orders(), so this works with HPOS.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The store summary route.
 */
class AssistantLabs_WC_Store {

	const MAX_ORDERS_COUNTED = 2000;
	const RECENT_ORDERS      = 10;
	const LOW_STOCK          = 20;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'assistantlabs_register_routes', array( __CLASS__, 'register_routes' ), 10, 2 );
	}

	/**
	 * Register /store/summary with core's signed permission callback.
	 *
	 * @param string   $namespace REST namespace.
	 * @param callable $auth      Permission callback.
	 */
	public static function register_routes( $namespace, $auth ) {
		register_rest_route(
			$namespace,
			'/store/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'summary' ),
				'permission_callback' => $auth,
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'enum'    => array( 'today', '7d', '30d' ),
						'default' => 'today',
					),
				),
			)
		);
	}

	/**
	 * Start of the period, as a unix timestamp in the site's time zone.
	 *
	 * @param string $period today|7d|30d.
	 * @return int
	 */
	public static function period_start( $period ) {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		if ( '7d' === $period ) {
			return $today->modify( '-6 days' )->getTimestamp();
		}
		if ( '30d' === $period ) {
			return $today->modify( '-29 days' )->getTimestamp();
		}
		return $today->getTimestamp();
	}

	/**
	 * GET /store/summary?period=today|7d|30d.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function summary( WP_REST_Request $request ) {
		$period = (string) $request->get_param( 'period' );
		if ( ! in_array( $period, array( 'today', '7d', '30d' ), true ) ) {
			$period = 'today';
		}
		$since = self::period_start( $period );

		// Orders that count as sales: paid statuses (processing, completed by default).
		$paid_statuses = array_map(
			static function ( $status ) {
				return 'wc-' . $status;
			},
			wc_get_is_paid_statuses()
		);
		$order_ids     = wc_get_orders(
			array(
				'status'       => $paid_statuses,
				'date_created' => '>=' . $since,
				'limit'        => self::MAX_ORDERS_COUNTED,
				'return'       => 'ids',
				'type'         => 'shop_order',
			)
		);
		$revenue       = 0.0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$revenue += (float) $order->get_total() - (float) $order->get_total_refunded();
			}
		}

		$recent = array();
		$orders = wc_get_orders(
			array(
				'status'  => array_keys( wc_get_order_statuses() ),
				'limit'   => self::RECENT_ORDERS,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
			)
		);
		foreach ( $orders as $order ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( '' === $name ) {
				$name = $order->get_billing_email();
			}
			$created  = $order->get_date_created();
			$recent[] = array(
				'id'       => (int) $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'status'   => $order->get_status(),
				'total'    => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'customer' => $name,
				'date'     => $created ? gmdate( 'Y-m-d\TH:i:s\Z', $created->getTimestamp() ) : null,
			);
		}

		return rest_ensure_response(
			array(
				'currency'     => get_woocommerce_currency(),
				'period'       => $period,
				'orders'       => array(
					'count'   => count( $order_ids ),
					'revenue' => wc_format_decimal( $revenue, wc_get_price_decimals() ),
				),
				'recentOrders' => $recent,
				'lowStock'     => self::low_stock(),
			)
		);
	}

	/**
	 * Products (and variations) at or below the store's low-stock threshold.
	 *
	 * @return array
	 */
	public static function low_stock() {
		$threshold = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		$query     = new WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => self::LOW_STOCK,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_stock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_manage_stock',
						'value' => 'yes',
					),
					array(
						'key'     => '_stock',
						'value'   => $threshold,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$rows      = array();
		foreach ( $query->posts as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$rows[] = array(
				'id'            => (int) $product->get_id(),
				'name'          => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
				'stockQuantity' => (int) $product->get_stock_quantity(),
			);
		}
		return $rows;
	}
}
