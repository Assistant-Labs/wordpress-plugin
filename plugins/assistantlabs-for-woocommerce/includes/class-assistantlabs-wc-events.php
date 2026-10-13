<?php
/**
 * Store events → POST /site/woocommerce/events (CONTRACT.md, "WooCommerce store events").
 *
 * Nothing is sent inside a shopper's or an admin's request. An event is written
 * to the outbox table and an Action Scheduler action delivers it in the
 * background, signed by the core client. A 5xx, 429 or network error is retried
 * with exponential backoff, at most 5 attempts in all; any other 4xx (the
 * server will never accept it) drops the event, logged once.
 *
 * Order events are snapshotted at the end of the request that changed the
 * order (so `order.created` shows the order as it was created, even when it is
 * delivered later); only the customer's order count and total spent, which
 * need a query, are filled in when sending. Customer events are built when
 * sending.
 *
 * Orders are read only through WC_Order / wc_get_orders(), so this works with HPOS.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbox, delivery, and the order/customer hooks.
 */
class AssistantLabs_WC_Events {

	const HOOK         = 'assistantlabs_wc_send_event';
	const GROUP        = 'assistantlabs';
	const PATH         = '/site/woocommerce/events';
	const MAX_ATTEMPTS = 5;

	const META_CREATED     = '_assistantlabs_created_sent';
	const META_PAID        = '_assistantlabs_paid_sent';
	const META_CART_TOKEN  = '_assistantlabs_cart_token';
	const META_REF         = '_assistantlabs_ref';
	const META_CONSENT     = '_assistantlabs_marketing_consent';
	const USER_META_NEW    = '_assistantlabs_customer_created';
	const USER_META_HASH   = '_assistantlabs_customer_hash';
	const GUEST_ORDERS_MAX = 500;

	/** Order statuses that are not orders yet. */
	const SKIP_STATUSES = array( 'checkout-draft', 'auto-draft', 'draft', 'trash' );

	/** Statuses reported as order.cancelled. */
	const CANCELLED_STATUSES = array( 'cancelled', 'refunded', 'failed' );

	/**
	 * Events already queued in this request ("order.created:12" => true).
	 *
	 * @var array<string,bool>
	 */
	private static $queued = array();

	/**
	 * Order events to snapshot at shutdown: [topic, order id, occurredAt].
	 *
	 * @var array
	 */
	private static $deferred = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'deliver' ) );

		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order_saved' ), 20, 2 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_order_saved' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );

		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_customer_created' ), 20, 1 );
		add_action( 'woocommerce_new_customer', array( __CLASS__, 'on_customer_created' ), 20, 1 );
		add_action( 'woocommerce_update_customer', array( __CLASS__, 'on_customer_updated' ), 20, 2 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Outbox
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Queue an event for background delivery.
	 *
	 * @param string $topic  Contract topic.
	 * @param array  $data   The event's `data` (a snapshot), or empty when $source is set.
	 * @param array  $source {order: id} or {customer: id}: `data` is built when sending.
	 * @param string $dedupe Optional key: a still-unsent event with the same key is replaced instead.
	 * @param string $at     occurredAt (ISO-8601), default now.
	 * @return bool Whether it was queued.
	 */
	public static function enqueue( $topic, array $data, array $source = array(), $dedupe = '', $at = '' ) {
		global $wpdb;

		if ( ! AssistantLabs_Options::is_connected() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$payload = wp_json_encode(
			array(
				'topic'      => $topic,
				'occurredAt' => '' !== $at ? $at : gmdate( 'Y-m-d\TH:i:s\Z' ),
				'data'       => $data,
				'source'     => $source,
			)
		);
		if ( false === $payload ) {
			return false;
		}
		$table = AssistantLabs_WC_Install::events_table();

		if ( '' !== $dedupe ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own outbox table.
			$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE dedupe = %s AND attempts = 0 ORDER BY id DESC LIMIT 1', $table, $dedupe ) );
			if ( $pending ) {
				// The queued action has not run yet: it will send this newer payload.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $table, array( 'payload' => $payload ), array( 'id' => (int) $pending ), array( '%s' ), array( '%d' ) );
				return true;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'topic'      => $topic,
				'dedupe'     => $dedupe,
				'payload'    => $payload,
				'attempts'   => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $inserted ) {
			return false;
		}
		as_enqueue_async_action( self::HOOK, array( (int) $wpdb->insert_id ), self::GROUP );
		return true;
	}

	/**
	 * Action Scheduler callback: send one event.
	 *
	 * @param int $event_id Outbox row id.
	 */
	public static function deliver( $event_id ) {
		global $wpdb;
		$table = AssistantLabs_WC_Install::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $event_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		if ( ! AssistantLabs_Options::is_connected() ) {
			self::forget( (int) $row['id'] );
			return;
		}
		$event = self::resolve( json_decode( $row['payload'], true ) );
		if ( null === $event ) {
			self::forget( (int) $row['id'] ); // The order or customer is gone.
			return;
		}

		$result = AssistantLabs_Api_Client::request( 'POST', self::PATH, $event, array( 'timeout' => 10 ) );
		if ( ! is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND payload = %s', $table, (int) $row['id'], $row['payload'] ) );
			if ( ! $deleted ) {
				// A newer snapshot replaced the payload while this one was in flight: send that too.
				as_enqueue_async_action( self::HOOK, array( (int) $row['id'] ), self::GROUP );
			}
			return;
		}

		$data     = $result->get_error_data();
		$status   = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$attempts = (int) $row['attempts'] + 1;

		if ( self::retryable( $status ) && $attempts < self::MAX_ATTEMPTS ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'attempts' => $attempts ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
			as_schedule_single_action( time() + self::backoff( $attempts ), self::HOOK, array( (int) $row['id'] ), self::GROUP );
			return;
		}

		self::forget( (int) $row['id'] );
		self::log(
			sprintf(
				'Store event %1$s dropped after %2$d attempt(s): %3$s (HTTP %4$d).',
				$row['topic'],
				$attempts,
				$result->get_error_code(),
				$status
			)
		);
	}

	/**
	 * Whether a failed send may succeed later: network errors, 5xx (the
	 * server answers 502 `sales_unavailable` when Sales is down) and 429.
	 * Any other 4xx (400 `invalid_topic` / `event_rejected` /
	 * `woocommerce_not_connected`, 401 bad signature) never will.
	 *
	 * @param int $status HTTP status, 0 for a network error.
	 * @return bool
	 */
	public static function retryable( $status ) {
		return 0 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * Delay before the next attempt: 1, 4, 16, 64 minutes.
	 *
	 * @param int $attempts Attempts made so far.
	 * @return int Seconds.
	 */
	public static function backoff( $attempts ) {
		return (int) ( MINUTE_IN_SECONDS * pow( 4, max( 0, $attempts - 1 ) ) );
	}

	/**
	 * Remove an outbox row.
	 *
	 * @param int $id Row id.
	 */
	private static function forget( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( AssistantLabs_WC_Install::events_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Outbox rows still waiting to be sent.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', AssistantLabs_WC_Install::events_table() ) );
	}

	/**
	 * Turn a stored payload into the body we send.
	 *
	 * @param mixed $payload Decoded outbox payload.
	 * @return array|null {topic, occurredAt, data}, or null when it can no longer be built.
	 */
	private static function resolve( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['topic'] ) ) {
			return null;
		}
		$data   = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$source = isset( $payload['source'] ) && is_array( $payload['source'] ) ? $payload['source'] : array();

		if ( ! empty( $source['totals'] ) && isset( $data['customer'] ) && is_array( $data['customer'] ) ) {
			$data['customer'] = self::with_totals( $data['customer'] );
		} elseif ( ! empty( $source['customer'] ) ) {
			$customer = self::customer_data( (int) $source['customer'] );
			if ( null === $customer ) {
				return null;
			}
			$data = array( 'customer' => $customer );
		}

		return array(
			'topic'      => (string) $payload['topic'],
			'occurredAt' => (string) $payload['occurredAt'],
			'data'       => (object) $data,
		);
	}

	/**
	 * Log to WooCommerce's logger (WooCommerce → Status → Logs, source "assistantlabs").
	 *
	 * @param string $message Message (never shopper data).
	 */
	private static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'assistantlabs' ) );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Orders
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Order created or saved (also admin, REST and Store API orders).
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_order_saved( $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			self::sync_order( $order );
		}
	}

	/**
	 * Status transitions: order.cancelled (and created/paid via sync_order).
	 *
	 * @param int           $order_id Order id.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order || ! self::is_store_order( $order ) ) {
			return;
		}
		self::sync_order( $order );
		if ( in_array( $to, self::CANCELLED_STATUSES, true ) && ! in_array( $from, self::CANCELLED_STATUSES, true ) ) {
			self::queue_order( 'order.cancelled', $order, $to );
		}
	}

	/**
	 * Send order.created once the order is real (not a checkout draft), and
	 * order.paid the first time it reaches a paid status.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function sync_order( WC_Order $order ) {
		if ( ! AssistantLabs_Options::is_connected() || ! self::is_store_order( $order ) ) {
			return;
		}
		if ( in_array( $order->get_status(), self::SKIP_STATUSES, true ) ) {
			return;
		}
		$dirty = false;
		if ( ! $order->get_meta( self::META_CREATED ) && self::queue_order( 'order.created', $order ) ) {
			$order->update_meta_data( self::META_CREATED, time() );
			$dirty = true;
		}
		if ( $order->has_status( wc_get_is_paid_statuses() ) && ! $order->get_meta( self::META_PAID ) && self::queue_order( 'order.paid', $order ) ) {
			$order->update_meta_data( self::META_PAID, time() );
			$dirty = true;
		}
		if ( $dirty && $order->get_id() ) {
			$order->save_meta_data();
		}
	}

	/**
	 * A shop order (not a refund, subscription or other order type).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function is_store_order( WC_Order $order ) {
		return 'shop_order' === $order->get_type();
	}

	/**
	 * Queue an order event once per request. The snapshot is taken at
	 * shutdown, when WooCommerce has finished saving the order.
	 *
	 * @param string   $topic Topic.
	 * @param WC_Order $order Order.
	 * @param string   $tag   Extra key part (for repeatable topics).
	 * @return bool
	 */
	private static function queue_order( $topic, WC_Order $order, $tag = '' ) {
		$key = $topic . ':' . $order->get_id() . ':' . $tag;
		if ( isset( self::$queued[ $key ] ) ) {
			return true;
		}
		if ( ! $order->get_id() || ! AssistantLabs_Options::is_connected() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		if ( ! self::$deferred ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_orders' ), 1 );
		}
		self::$deferred[]     = array( $topic, (int) $order->get_id(), gmdate( 'Y-m-d\TH:i:s\Z' ) );
		self::$queued[ $key ] = true;
		return true;
	}

	/**
	 * Shutdown: snapshot the orders changed in this request and queue their events.
	 */
	public static function flush_orders() {
		$deferred       = self::$deferred;
		self::$deferred = array();
		foreach ( $deferred as $item ) {
			$order = wc_get_order( $item[1] );
			if ( $order instanceof WC_Order ) {
				self::enqueue( $item[0], self::order_data( $order, false ), array( 'totals' => true ), '', $item[2] );
			}
		}
	}

	/**
	 * The contract's `Order`.
	 *
	 * @param WC_Order $order       Order.
	 * @param bool     $with_totals Compute the customer's orderCount/totalSpent now
	 *                              (false: filled in when sending).
	 * @return array
	 */
	public static function order_data( WC_Order $order, $with_totals = true ) {
		$data = array(
			'orderId'       => (int) $order->get_id(),
			'number'        => (string) $order->get_order_number(),
			'status'        => $order->get_status(),
			'currency'      => $order->get_currency(),
			'total'         => self::money( $order->get_total() ),
			'subtotal'      => self::money( $order->get_subtotal() ),
			'discountCodes' => array_values( array_map( 'strval', $order->get_coupon_codes() ) ),
			'createdAt'     => self::iso( $order->get_date_created() ),
			'customer'      => self::order_customer( $order, $with_totals ),
			'lines'         => self::order_lines( $order ),
		);

		$token = (string) $order->get_meta( self::META_CART_TOKEN );
		if ( '' !== $token ) {
			$data['cartToken'] = $token;
		}
		$paid = $order->get_date_paid();
		if ( $paid ) {
			$data['paidAt'] = self::iso( $paid );
		}
		$ref = (string) $order->get_meta( self::META_REF );
		if ( '' !== $ref ) {
			$data['ref'] = $ref;
		}
		$shipping = self::order_shipping( $order );
		if ( $shipping ) {
			$data['shipping'] = $shipping;
		}
		return $data;
	}

	/**
	 * `Customer` for an order.
	 *
	 * @param WC_Order $order       Order.
	 * @param bool     $with_totals Compute orderCount/totalSpent now.
	 * @return array
	 */
	private static function order_customer( WC_Order $order, $with_totals ) {
		$customer = array();
		$user_id  = (int) $order->get_customer_id();
		if ( $user_id && get_userdata( $user_id ) ) {
			$customer['id'] = $user_id;
		}

		$fields = array(
			'email'     => $order->get_billing_email(),
			'phone'     => $order->get_billing_phone(),
			'firstName' => $order->get_billing_first_name(),
			'lastName'  => $order->get_billing_last_name(),
		);
		foreach ( $fields as $key => $value ) {
			if ( '' !== (string) $value ) {
				$customer[ $key ] = (string) $value;
			}
		}
		$customer['orderCount'] = 0;
		$customer['totalSpent'] = self::money( 0 );
		if ( $with_totals ) {
			$customer = self::with_totals( $customer );
		}

		$consent = (string) $order->get_meta( self::META_CONSENT );
		if ( 'yes' === $consent || 'no' === $consent ) {
			$customer['marketingConsent'] = 'yes' === $consent;
		}
		return $customer;
	}

	/**
	 * Fill in orderCount and totalSpent: WooCommerce's own totals for an
	 * account, or for a guest, totals computed from the email.
	 *
	 * @param array $customer Customer (with id and/or email).
	 * @return array
	 */
	private static function with_totals( array $customer ) {
		$totals = null;
		if ( ! empty( $customer['id'] ) ) {
			try {
				$account = new WC_Customer( (int) $customer['id'] );
				if ( $account->get_id() ) {
					$totals = array( (int) $account->get_order_count(), (float) $account->get_total_spent() );
				}
			} catch ( Exception $e ) {
				$totals = null; // The account was deleted: count as a guest.
			}
		}
		if ( null === $totals ) {
			$totals = self::guest_totals( isset( $customer['email'] ) ? (string) $customer['email'] : '' );
		}
		$customer['orderCount'] = $totals[0];
		$customer['totalSpent'] = self::money( $totals[1] );
		return $customer;
	}

	/**
	 * Order count and paid total for a guest, by billing email.
	 *
	 * @param string $email Billing email.
	 * @return array{0:int,1:float}
	 */
	private static function guest_totals( $email ) {
		if ( ! is_email( $email ) ) {
			return array( 0, 0.0 );
		}
		$ids   = wc_get_orders(
			array(
				'billing_email' => $email,
				'type'          => 'shop_order',
				'status'        => array_keys( wc_get_order_statuses() ),
				'limit'         => self::GUEST_ORDERS_MAX,
				'return'        => 'ids',
			)
		);
		$spent = 0.0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order && $order->is_paid() ) {
				$spent += (float) $order->get_total() - (float) $order->get_total_refunded();
			}
		}
		return array( count( $ids ), $spent );
	}

	/**
	 * `Line`s of an order. `price` is per unit, before coupons, with or
	 * without tax as the store shows cart prices.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function order_lines( WC_Order $order ) {
		$incl  = 'incl' === get_option( 'woocommerce_tax_display_cart' );
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$line = array(
				'productId' => (int) $item->get_product_id(),
				'name'      => html_entity_decode( $item->get_name(), ENT_QUOTES, 'UTF-8' ),
				'quantity'  => (int) $item->get_quantity(),
				'price'     => self::money( $order->get_item_subtotal( $item, $incl, false ) ),
			);
			if ( $item->get_variation_id() ) {
				$line['variationId'] = (int) $item->get_variation_id();
			}
			$product = $item->get_product();
			if ( $product instanceof WC_Product ) {
				$line = array_merge( $line, self::product_links( $product ) );
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Shipping method and, with the Shipment Tracking extension, the latest tracking number.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function order_shipping( WC_Order $order ) {
		$shipping = array();
		$method   = (string) $order->get_shipping_method();
		if ( '' !== $method ) {
			$shipping['method'] = html_entity_decode( $method, ENT_QUOTES, 'UTF-8' );
		}
		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $items ) && $items ) {
			$item = end( $items );
			if ( is_array( $item ) && ! empty( $item['tracking_number'] ) ) {
				$shipping['trackingNumber'] = (string) $item['tracking_number'];
				$url                        = '';
				if ( ! empty( $item['custom_tracking_link'] ) ) {
					$url = (string) $item['custom_tracking_link'];
				} elseif ( function_exists( 'wc_shipment_tracking' ) ) {
					$tracking = wc_shipment_tracking();
					if ( isset( $tracking->actions ) && is_object( $tracking->actions ) && method_exists( $tracking->actions, 'get_formatted_tracking_item' ) ) {
						$formatted = $tracking->actions->get_formatted_tracking_item( $order->get_id(), $item );
						$url       = isset( $formatted['formatted_tracking_link'] ) ? (string) $formatted['formatted_tracking_link'] : '';
					}
				}
				$url = esc_url_raw( $url, array( 'http', 'https' ) );
				if ( '' !== $url ) {
					$shipping['trackingUrl'] = $url;
				}
			}
		}
		return $shipping;
	}

	/**
	 * `imageUrl` and `url` for a product (a variation falls back to its parent's image).
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function product_links( WC_Product $product ) {
		$links    = array();
		$image_id = (int) $product->get_image_id();
		if ( ! $image_id && $product->get_parent_id() ) {
			$parent   = wc_get_product( $product->get_parent_id() );
			$image_id = $parent ? (int) $parent->get_image_id() : 0;
		}
		if ( $image_id ) {
			$image = wp_get_attachment_image_url( $image_id, 'woocommerce_single' );
			if ( $image ) {
				$links['imageUrl'] = $image;
			}
		}
		$url = $product->get_permalink();
		if ( $url ) {
			$links['url'] = $url;
		}
		return $links;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Customers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A customer account was created (checkout, My Account, admin or REST).
	 *
	 * @param int $customer_id User id.
	 */
	public static function on_customer_created( $customer_id ) {
		$customer_id = (int) $customer_id;
		if ( ! self::is_shopper( $customer_id ) || get_user_meta( $customer_id, self::USER_META_NEW, true ) ) {
			return;
		}
		if ( self::queue_customer( 'customer.created', $customer_id ) ) {
			update_user_meta( $customer_id, self::USER_META_NEW, time() );
			// Remember the details as saved once this request is done (WooCommerce
			// may still be writing billing fields), so an unchanged save later
			// does not count as an update.
			add_action(
				'shutdown',
				static function () use ( $customer_id ) {
					try {
						update_user_meta( $customer_id, AssistantLabs_WC_Events::USER_META_HASH, AssistantLabs_WC_Events::identity_hash( new WC_Customer( $customer_id ) ) );
					} catch ( Exception $e ) {
						return;
					}
				},
				1
			);
		}
	}

	/**
	 * A customer account was saved. Sent only when the contact details
	 * changed, and not in the request that created the account.
	 *
	 * @param int              $customer_id User id.
	 * @param WC_Customer|null $customer    Customer.
	 */
	public static function on_customer_updated( $customer_id, $customer = null ) {
		$customer_id = (int) $customer_id;
		if ( ! self::is_shopper( $customer_id ) || isset( self::$queued[ 'customer.created:' . $customer_id ] ) ) {
			return;
		}
		if ( ! $customer instanceof WC_Customer ) {
			try {
				$customer = new WC_Customer( $customer_id );
			} catch ( Exception $e ) {
				return;
			}
		}
		$hash = self::identity_hash( $customer );
		if ( get_user_meta( $customer_id, self::USER_META_HASH, true ) === $hash ) {
			return;
		}
		if ( self::queue_customer( 'customer.updated', $customer_id ) ) {
			update_user_meta( $customer_id, self::USER_META_HASH, $hash );
		}
	}

	/**
	 * A shopper account, not staff (staff can edit posts; that includes the AssistantLabs user).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private static function is_shopper( $user_id ) {
		return $user_id > 0 && AssistantLabs_Options::is_connected() && ! user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Queue a customer event once per request.
	 *
	 * @param string $topic       Topic.
	 * @param int    $customer_id User id.
	 * @return bool
	 */
	private static function queue_customer( $topic, $customer_id ) {
		$key = $topic . ':' . $customer_id;
		if ( isset( self::$queued[ $key ] ) ) {
			return false;
		}
		$queued = self::enqueue( $topic, array(), array( 'customer' => $customer_id ) );
		if ( $queued ) {
			self::$queued[ $key ] = true;
		}
		return $queued;
	}

	/**
	 * Hash of the contact details that make a customer.updated worth sending.
	 *
	 * @param WC_Customer $customer Customer.
	 * @return string
	 */
	public static function identity_hash( WC_Customer $customer ) {
		return md5(
			(string) wp_json_encode(
				array(
					$customer->get_email(),
					$customer->get_billing_email(),
					$customer->get_billing_phone(),
					$customer->get_first_name(),
					$customer->get_last_name(),
					$customer->get_billing_first_name(),
					$customer->get_billing_last_name(),
				)
			)
		);
	}

	/**
	 * The contract's `Customer` for an account.
	 *
	 * @param int $customer_id User id.
	 * @return array|null
	 */
	public static function customer_data( $customer_id ) {
		try {
			$customer = new WC_Customer( $customer_id );
		} catch ( Exception $e ) {
			return null;
		}
		if ( ! $customer->get_id() ) {
			return null;
		}
		$data   = array( 'id' => (int) $customer->get_id() );
		$fields = array(
			'email'     => $customer->get_email() ? $customer->get_email() : $customer->get_billing_email(),
			'phone'     => $customer->get_billing_phone(),
			'firstName' => $customer->get_first_name() ? $customer->get_first_name() : $customer->get_billing_first_name(),
			'lastName'  => $customer->get_last_name() ? $customer->get_last_name() : $customer->get_billing_last_name(),
		);
		foreach ( $fields as $key => $value ) {
			if ( '' !== (string) $value ) {
				$data[ $key ] = (string) $value;
			}
		}
		$data['orderCount'] = (int) $customer->get_order_count();
		$data['totalSpent'] = self::money( $customer->get_total_spent() );
		return $data;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Formatting
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Money as a decimal string with the store's decimals.
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		return (string) wc_format_decimal( (float) $amount, wc_get_price_decimals() );
	}

	/**
	 * ISO-8601 (UTC) for a WC date.
	 *
	 * @param WC_DateTime|null $date Date.
	 * @return string|null
	 */
	private static function iso( $date ) {
		return $date ? gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() ) : null;
	}
}
