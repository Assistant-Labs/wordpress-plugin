<?php
/**
 * Abandoned-cart capture and the storefront cart links (CONTRACT.md,
 * "Cart capture" and "Plugin public endpoints").
 *
 * - Capture is off until the owner turns on "Recover abandoned carts", and
 *   never runs while the site is not connected.
 * - Classic checkout: assets/checkout.js posts the contact fields to the
 *   `wc-ajax=assistantlabs_capture` endpoint when they change.
 * - Block checkout: the checkout block sends the contact fields to the Store
 *   API (`/wc/store/v1/cart/update-customer`); we capture in
 *   `woocommerce_store_api_cart_update_customer_from_request`.
 * - A known shopper (logged in, or a guest whose session already has an
 *   email) is captured when the checkout page loads.
 * - Each browser cart gets a `cartToken` in the WC session. The cart (lines +
 *   coupons) is saved in the carts table under that token for 30 days, so
 *   `/?al_cart={token}` can rebuild it.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart capture, checkout.updated / checkout.cleared, al_cart and al_add.
 */
class AssistantLabs_WC_Carts {

	const OPTION          = 'assistantlabs_wc_settings';
	const SESSION_TOKEN   = 'assistantlabs_cart_token';
	const SESSION_HASH    = 'assistantlabs_cart_hash';
	const SESSION_REF     = 'assistantlabs_ref';
	const SESSION_CONSENT = 'assistantlabs_consent';
	const TTL             = 30 * DAY_IN_SECONDS;
	const THROTTLE        = MINUTE_IN_SECONDS;
	const CLEANUP_HOOK    = 'assistantlabs_wc_cleanup';
	const AJAX_ACTION     = 'assistantlabs_capture';
	const NONCE           = 'assistantlabs_wc_capture';
	const MAX_LINES       = 50;
	const MAX_QTY         = 999;

	/**
	 * True while we rebuild the cart ourselves: no capture, no "cleared".
	 *
	 * @var bool
	 */
	private static $quiet = false;

	/**
	 * True while a capture runs (recalculating totals fires cart hooks).
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule_cleanup' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );

		// Storefront links work whether or not capture is on.
		add_action( 'wp_loaded', array( __CLASS__, 'storefront_links' ), 30 );

		// The order a cart became carries its token, the shopper's al_ref and opt-in.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'tag_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'tag_order' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_placed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_placed' ), 10, 1 );

		// checkout.cleared / re-capture when the cart changes.
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'on_cart_emptied' ) );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'on_cart_updated' ) );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ), 1 );

		// Capture.
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_capture' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'on_store_api_customer' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'on_checkout_page' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Settings, with defaults (capture off).
	 *
	 * @return array{recover_carts:bool}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array( 'recover_carts' => false ) );
	}

	/**
	 * Whether carts may be captured now.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = self::settings();
		return ! empty( $settings['recover_carts'] ) && AssistantLabs_Options::is_connected();
	}

	/**
	 * Checkbox names treated as an explicit marketing opt-in. Only these are
	 * read; without one, `marketingConsent` is left out.
	 *
	 * @return string[]
	 */
	public static function consent_fields() {
		$fields = apply_filters( 'assistantlabs_wc_marketing_consent_fields', array( 'mailpoet_woocommerce_checkout_optin', 'marketing_consent', 'newsletter_optin' ) );
		return array_values( array_filter( array_map( 'strval', (array) $fields ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Capture
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Classic checkout: assets/checkout.js on billing email/phone/name change.
	 */
	public static function ajax_capture() {
		if ( ! check_ajax_referer( self::NONCE, 'security', false ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( self::enabled() ) {
			$consent = null;
			if ( isset( $_POST['consent'] ) ) {
				$consent = '1' === sanitize_text_field( wp_unslash( $_POST['consent'] ) );
			}
			self::capture(
				array(
					'email'     => isset( $_POST['billing_email'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_email'] ) ) : '',
					'phone'     => isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '',
					'firstName' => isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
					'lastName'  => isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
					'consent'   => $consent,
				)
			);
		}
		wp_send_json_success();
	}

	/**
	 * Load the classic-checkout listener (the checkout block needs none).
	 */
	public static function enqueue_script() {
		if ( ! self::enabled() || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		if ( has_block( 'woocommerce/checkout' ) ) {
			return; // This page is the checkout block.
		}
		wp_enqueue_script( 'assistantlabs-wc-checkout', plugins_url( 'assets/checkout.js', ASSISTANTLABS_WC_FILE ), array(), ASSISTANTLABS_WC_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'assistantlabs-wc-checkout',
			'assistantlabsWcCheckout',
			array(
				'url'           => WC_AJAX::get_endpoint( self::AJAX_ACTION ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'consentFields' => self::consent_fields(),
			)
		);
	}

	/**
	 * Block checkout: the Store API saved the shopper's address/contact.
	 *
	 * @param WC_Customer     $customer Customer (session or account).
	 * @param WP_REST_Request $request  Request.
	 */
	public static function on_store_api_customer( $customer, $request ) {
		if ( ! self::enabled() || ! $customer instanceof WC_Customer ) {
			return;
		}
		$consent = null;
		$sources = array();
		if ( $request instanceof WP_REST_Request ) {
			$sources = array( $request->get_param( 'billing_address' ), $request->get_param( 'additional_fields' ) );
		}
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			foreach ( self::consent_fields() as $field ) {
				if ( array_key_exists( $field, $source ) ) {
					$consent = rest_sanitize_boolean( $source[ $field ] );
				}
			}
		}
		self::capture(
			array(
				'email'     => $customer->get_billing_email(),
				'phone'     => $customer->get_billing_phone(),
				'firstName' => $customer->get_billing_first_name(),
				'lastName'  => $customer->get_billing_last_name(),
				'consent'   => $consent,
			)
		);
	}

	/**
	 * Checkout page load: capture a shopper whose contact details we already
	 * have (logged in, or entered earlier in this session).
	 */
	public static function on_checkout_page() {
		if ( ! self::enabled() || ! is_checkout() || is_wc_endpoint_url() || ! WC()->customer ) {
			return;
		}
		$customer = WC()->customer;
		if ( '' === $customer->get_billing_email() && '' === $customer->get_billing_phone() ) {
			return;
		}
		self::capture(
			array(
				'email'     => $customer->get_billing_email(),
				'phone'     => $customer->get_billing_phone(),
				'firstName' => $customer->get_billing_first_name(),
				'lastName'  => $customer->get_billing_last_name(),
				'consent'   => null,
			)
		);
	}

	/**
	 * Record the cart and queue checkout.updated (throttled).
	 *
	 * @param array|null $contact {email, phone, firstName, lastName, consent}; null re-uses the saved contact.
	 * @return bool Whether an event was queued.
	 */
	public static function capture( $contact = null ) {
		if ( self::$quiet || self::$busy || ! self::enabled() || ! self::has_cart() ) {
			return false;
		}
		self::$busy = true;
		try {
			return self::do_capture( $contact );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * The work of capture().
	 *
	 * @param array|null $contact Contact.
	 * @return bool
	 */
	private static function do_capture( $contact ) {
		$session = WC()->session;
		$token   = self::token();
		$row     = $token ? self::get_row( $token ) : null;

		if ( null === $contact ) {
			if ( ! $row ) {
				return false;
			}
			$stored  = json_decode( $row['cart'], true );
			$contact = is_array( $stored ) && isset( $stored['contact'] ) && is_array( $stored['contact'] ) ? $stored['contact'] : array();
		}
		$contact = self::clean_contact( $contact );
		if ( '' === $contact['email'] && '' === $contact['phone'] ) {
			return false;
		}

		if ( null === $contact['consent'] ) {
			$saved              = $session->get( self::SESSION_CONSENT );
			$contact['consent'] = 'yes' === $saved ? true : ( 'no' === $saved ? false : null );
		} else {
			$session->set( self::SESSION_CONSENT, $contact['consent'] ? 'yes' : 'no' );
		}

		$snapshot = self::cart_snapshot();
		if ( empty( $snapshot['lines'] ) ) {
			return false;
		}
		if ( ! $token ) {
			$token = self::new_token();
			$session->set( self::SESSION_TOKEN, $token );
			self::ensure_cookie();
		}
		$session->set( self::SESSION_HASH, md5( (string) wp_json_encode( $snapshot ) ) );

		$hash = md5( (string) wp_json_encode( array( $snapshot, $contact ) ) );
		$now  = time();
		$sent = $row && $row['sent_at'] ? strtotime( $row['sent_at'] . ' UTC' ) : 0;
		$send = ! $row || $row['hash'] !== $hash || ( $now - $sent ) >= self::THROTTLE;

		self::save_row(
			$token,
			$contact,
			array(
				'lines'    => $snapshot['lines'],
				'coupons'  => $snapshot['coupons'],
				'contact'  => $contact,
				'currency' => get_woocommerce_currency(),
			),
			$hash,
			$send ? $now : $sent,
			null !== $row
		);

		if ( ! $send ) {
			return false;
		}
		return AssistantLabs_WC_Events::enqueue( 'checkout.updated', self::event_data( $token, $contact ), array(), 'cart:' . $token );
	}

	/**
	 * Validate and normalise contact details.
	 *
	 * @param array $contact Raw contact.
	 * @return array{email:string,phone:string,firstName:string,lastName:string,consent:bool|null}
	 */
	private static function clean_contact( array $contact ) {
		$email = isset( $contact['email'] ) ? sanitize_email( (string) $contact['email'] ) : '';
		$phone = isset( $contact['phone'] ) ? wc_sanitize_phone_number( (string) $contact['phone'] ) : '';
		$len   = strlen( (string) preg_replace( '/\D/', '', $phone ) );
		return array(
			'email'     => is_email( $email ) ? substr( $email, 0, 100 ) : '',
			'phone'     => $len >= 7 && $len <= 15 ? substr( $phone, 0, 32 ) : '',
			'firstName' => isset( $contact['firstName'] ) ? substr( sanitize_text_field( (string) $contact['firstName'] ), 0, 100 ) : '',
			'lastName'  => isset( $contact['lastName'] ) ? substr( sanitize_text_field( (string) $contact['lastName'] ), 0, 100 ) : '',
			'consent'   => isset( $contact['consent'] ) && is_bool( $contact['consent'] ) ? $contact['consent'] : null,
		);
	}

	/**
	 * The checkout.updated `data`.
	 *
	 * @param string $token   Cart token.
	 * @param array  $contact Clean contact.
	 * @return array
	 */
	private static function event_data( $token, array $contact ) {
		$cart = WC()->cart;
		if ( (float) $cart->get_total( 'edit' ) <= 0 ) {
			$cart->calculate_totals();
		}
		$data = array( 'cartToken' => $token );
		foreach ( array( 'email', 'phone', 'firstName', 'lastName' ) as $key ) {
			if ( '' !== $contact[ $key ] ) {
				$data[ $key ] = $contact[ $key ];
			}
		}
		$data['currency']    = get_woocommerce_currency();
		$data['total']       = AssistantLabs_WC_Events::money( $cart->get_total( 'edit' ) );
		$data['lines']       = self::event_lines();
		$data['recoveryUrl'] = self::recovery_url( $token );
		if ( null !== $contact['consent'] ) {
			$data['marketingConsent'] = $contact['consent'];
		}
		return $data;
	}

	/**
	 * `Line`s of the current cart (price per unit, as the cart shows it).
	 *
	 * @return array
	 */
	private static function event_lines() {
		$cart  = WC()->cart;
		$incl  = $cart->display_prices_including_tax();
		$lines = array();
		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$price = $incl ? wc_get_price_including_tax( $product ) : wc_get_price_excluding_tax( $product );
			$line  = array(
				'productId' => (int) $item['product_id'],
				'name'      => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
				'quantity'  => (int) $item['quantity'],
				'price'     => AssistantLabs_WC_Events::money( $price ),
			);
			if ( ! empty( $item['variation_id'] ) ) {
				$line['variationId'] = (int) $item['variation_id'];
			}
			$lines[] = array_merge( $line, AssistantLabs_WC_Events::product_links( $product ) );
		}
		return $lines;
	}

	/**
	 * What we need to rebuild the cart: lines and coupons.
	 *
	 * @return array{lines:array,coupons:string[]}
	 */
	private static function cart_snapshot() {
		$lines = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['product_id'] ) || empty( $item['quantity'] ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'quantity'     => (int) $item['quantity'],
				'variation'    => isset( $item['variation'] ) && is_array( $item['variation'] ) ? array_map( 'strval', $item['variation'] ) : array(),
			);
		}
		return array(
			'lines'   => array_slice( $lines, 0, self::MAX_LINES ),
			'coupons' => array_values( array_map( 'strval', WC()->cart->get_applied_coupons() ) ),
		);
	}

	/**
	 * The recovery link for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function recovery_url( $token ) {
		return add_query_arg( 'al_cart', rawurlencode( $token ), home_url( '/' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Cleared
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Cart emptied (by the shopper, a plugin, or after an order).
	 */
	public static function on_cart_emptied() {
		if ( ! self::$quiet ) {
			self::clear();
		}
	}

	/**
	 * The cart changed: empty → cleared; different contents → capture again.
	 */
	public static function on_cart_updated() {
		if ( self::$quiet || self::$busy || ! self::token() ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			self::clear();
			return;
		}
		$hash = md5( (string) wp_json_encode( self::cart_snapshot() ) );
		if ( WC()->session->get( self::SESSION_HASH ) === $hash ) {
			return;
		}
		WC()->session->set( self::SESSION_HASH, $hash );
		self::capture( null );
	}

	/**
	 * Logging out empties the session's cart, but the shopper did not abandon
	 * or clear anything: keep the captured cart.
	 */
	public static function on_logout() {
		self::$quiet = true;
	}

	/**
	 * Put the cart token, al_ref and opt-in on the order the cart becomes.
	 * Runs before the order is saved (classic and Store API).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function tag_order( $order ) {
		if ( ! $order instanceof WC_Order || ! self::session() ) {
			return;
		}
		$session = WC()->session;
		$token   = self::token();
		if ( $token ) {
			$order->update_meta_data( AssistantLabs_WC_Events::META_CART_TOKEN, $token );
		}
		$ref = (string) $session->get( self::SESSION_REF );
		if ( '' !== $ref ) {
			$order->update_meta_data( AssistantLabs_WC_Events::META_REF, $ref );
		}
		$consent = $session->get( self::SESSION_CONSENT );
		if ( doing_action( 'woocommerce_checkout_create_order' ) ) {
			// Classic checkout: WooCommerce verified its checkout nonce before creating the order.
			foreach ( self::consent_fields() as $field ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
				if ( isset( $_POST[ $field ] ) ) {
					$consent = 'yes';
				}
			}
		}
		if ( 'yes' === $consent || 'no' === $consent ) {
			$order->update_meta_data( AssistantLabs_WC_Events::META_CONSENT, $consent );
		}
	}

	/**
	 * The cart became an order: checkout.cleared, and forget the saved cart.
	 *
	 * @param int|WC_Order  $order_or_id Order id (classic) or order (Store API).
	 * @param mixed         $posted      Posted data (classic).
	 * @param WC_Order|null $order       Order (classic).
	 */
	public static function on_order_placed( $order_or_id, $posted = null, $order = null ) {
		if ( ! self::session() ) {
			return;
		}
		if ( $order_or_id instanceof WC_Order ) {
			$order = $order_or_id;
		} elseif ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_or_id );
		}
		$token = self::token();
		if ( $token && $order instanceof WC_Order && ! $order->get_meta( AssistantLabs_WC_Events::META_CART_TOKEN ) ) {
			$order->update_meta_data( AssistantLabs_WC_Events::META_CART_TOKEN, $token );
			$order->save_meta_data();
		}
		self::clear( true );
		WC()->session->set( self::SESSION_REF, null );
	}

	/**
	 * Forget the session's cart token; send checkout.cleared if we had
	 * reported that cart.
	 *
	 * @param bool $after_order Queue it at shutdown, after the order's own events.
	 */
	private static function clear( $after_order = false ) {
		if ( ! self::session() ) {
			return;
		}
		$token = self::token();
		if ( ! $token ) {
			return;
		}
		WC()->session->set( self::SESSION_TOKEN, null );
		WC()->session->set( self::SESSION_HASH, null );

		$row = self::get_row( $token );
		if ( ! $row ) {
			return;
		}
		self::delete_row( $token );
		if ( ! $row['sent_at'] ) {
			return;
		}
		$send = static function () use ( $token ) {
			AssistantLabs_WC_Events::enqueue( 'checkout.cleared', array( 'cartToken' => $token ) );
		};
		if ( $after_order ) {
			add_action( 'shutdown', $send, 5 ); // AssistantLabs_WC_Events::flush_orders runs at 1.
		} else {
			$send();
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Storefront links: ?al_cart= and ?al_add=
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Handle the links on a storefront GET. Both act only on the visitor's own
	 * cart and always redirect to this site's cart or checkout page.
	 */
	public static function storefront_links() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public links that only rebuild the visitor's own cart.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( ! isset( $_GET['al_cart'] ) && ! isset( $_GET['al_add'] ) ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method || ! function_exists( 'WC' ) || ! WC()->cart || ! self::session() ) {
			return;
		}
		$ref = isset( $_GET['al_ref'] ) ? self::clean_ref( sanitize_text_field( wp_unslash( $_GET['al_ref'] ) ) ) : '';

		if ( isset( $_GET['al_cart'] ) ) {
			self::restore_link( strtolower( sanitize_text_field( wp_unslash( $_GET['al_cart'] ) ) ), $ref );
		} elseif ( isset( $_GET['al_add'] ) ) {
			$coupon = isset( $_GET['coupon'] ) ? sanitize_text_field( wp_unslash( $_GET['coupon'] ) ) : '';
			self::add_link( sanitize_text_field( wp_unslash( $_GET['al_add'] ) ), $coupon, $ref );
		}
		// phpcs:enable
	}

	/**
	 * `?al_cart={token}`: replace the cart with the captured one, go to checkout.
	 *
	 * @param string $token Token.
	 * @param string $ref   Clean al_ref, or ''.
	 */
	private static function restore_link( $token, $ref ) {
		$row = preg_match( '/^[a-f0-9]{32}$/', $token ) ? self::get_row( $token ) : null;
		if ( ! $row || strtotime( $row['updated_at'] . ' UTC' ) < time() - self::TTL ) {
			wc_add_notice( __( 'This cart link has expired.', 'assistantlabs-for-woocommerce' ), 'notice' );
			self::redirect( wc_get_cart_url() );
		}
		$stored = json_decode( $row['cart'], true );
		$lines  = array();
		foreach ( is_array( $stored ) && isset( $stored['lines'] ) ? (array) $stored['lines'] : array() as $line ) {
			if ( is_array( $line ) && ! empty( $line['product_id'] ) && ! empty( $line['quantity'] ) ) {
				$lines[] = array(
					'product_id'   => absint( $line['product_id'] ),
					'variation_id' => isset( $line['variation_id'] ) ? absint( $line['variation_id'] ) : 0,
					'quantity'     => min( self::MAX_QTY, absint( $line['quantity'] ) ),
					'variation'    => isset( $line['variation'] ) && is_array( $line['variation'] ) ? array_map( 'sanitize_text_field', $line['variation'] ) : array(),
				);
			}
		}
		$coupons = is_array( $stored ) && isset( $stored['coupons'] ) ? (array) $stored['coupons'] : array();
		if ( '' !== $ref ) {
			WC()->session->set( self::SESSION_REF, $ref );
		}
		self::replace_cart( $lines, $coupons, $token );
		self::redirect( wc_get_checkout_url() );
	}

	/**
	 * `?al_add={productId[:variationId]}:{qty}[,…]&coupon=&al_ref=`.
	 *
	 * @param string $spec   Lines.
	 * @param string $coupon Coupon code, or ''.
	 * @param string $ref    Clean al_ref, or ''.
	 */
	private static function add_link( $spec, $coupon, $ref ) {
		$lines = self::parse_add( $spec );
		if ( '' !== $ref ) {
			WC()->session->set( self::SESSION_REF, $ref );
		}
		if ( ! $lines ) {
			self::redirect( wc_get_cart_url() );
		}
		self::replace_cart( $lines, '' !== $coupon ? array( $coupon ) : array(), null );
		self::redirect( wc_get_checkout_url() );
	}

	/**
	 * Parse and validate al_add: published, purchasable, in-stock products only.
	 *
	 * @param string $spec e.g. "12:2,15:31:1".
	 * @return array Lines {product_id, variation_id, quantity, variation}.
	 */
	public static function parse_add( $spec ) {
		$lines = array();
		foreach ( array_slice( explode( ',', (string) $spec ), 0, self::MAX_LINES ) as $part ) {
			$bits = explode( ':', trim( $part ) );
			if ( count( $bits ) < 2 || count( $bits ) > 3 ) {
				continue;
			}
			foreach ( $bits as $bit ) {
				if ( ! ctype_digit( $bit ) ) {
					continue 2;
				}
			}
			$product_id   = (int) $bits[0];
			$variation_id = 3 === count( $bits ) ? (int) $bits[1] : 0;
			$quantity     = (int) end( $bits );
			if ( $product_id < 1 || $quantity < 1 ) {
				continue;
			}
			$product = wc_get_product( $variation_id ? $variation_id : $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( $product->is_type( 'variation' ) ) {
				if ( $variation_id && $product->get_parent_id() !== $product_id ) {
					continue;
				}
				$variation_id = $product->get_id();
				$product_id   = $product->get_parent_id();
			} elseif ( $variation_id || ! $product->is_type( array( 'simple', 'subscription' ) ) ) {
				continue; // Variable, grouped and external products need a choice we don't have.
			}
			if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => min( self::MAX_QTY, $quantity ),
				'variation'    => $variation_id ? $product->get_variation_attributes() : array(),
			);
		}
		return $lines;
	}

	/**
	 * Empty the cart and fill it with these lines and coupons.
	 *
	 * @param array       $lines   Lines.
	 * @param string[]    $coupons Coupon codes (invalid ones are skipped silently).
	 * @param string|null $token   The captured cart being restored, or null for a new cart.
	 */
	private static function replace_cart( array $lines, array $coupons, $token ) {
		$current = self::token();
		if ( $current && $current !== $token ) {
			self::clear(); // That cart is gone from this browser now.
		}
		$session = WC()->session;
		self::ensure_cookie();
		self::$quiet = true;
		try {
			WC()->cart->empty_cart();
			foreach ( $lines as $line ) {
				WC()->cart->add_to_cart( $line['product_id'], $line['quantity'], $line['variation_id'], $line['variation'] );
			}
			foreach ( $coupons as $code ) {
				self::apply_coupon_quietly( (string) $code );
			}
			if ( $token ) {
				$session->set( self::SESSION_TOKEN, $token );
				$session->set( self::SESSION_HASH, md5( (string) wp_json_encode( self::cart_snapshot() ) ) );
			}
			WC()->cart->calculate_totals();
		} finally {
			self::$quiet = false;
		}
	}

	/**
	 * Apply a coupon only when it is valid for this cart; otherwise do nothing
	 * (no error notice for the shopper).
	 *
	 * @param string $code Code.
	 */
	private static function apply_coupon_quietly( $code ) {
		$code = wc_format_coupon_code( $code );
		if ( '' === $code || WC()->cart->is_empty() || WC()->cart->has_discount( $code ) ) {
			return;
		}
		$coupon = new WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			return;
		}
		$discounts = new WC_Discounts( WC()->cart );
		if ( true !== $discounts->is_coupon_valid( $coupon ) ) {
			return;
		}
		WC()->cart->apply_coupon( $code );
	}

	/**
	 * al_ref: letters, digits and ._-: only, at most 64 characters.
	 *
	 * @param string $ref Raw.
	 * @return string
	 */
	private static function clean_ref( $ref ) {
		return substr( (string) preg_replace( '/[^A-Za-z0-9._:\-]/', '', $ref ), 0, 64 );
	}

	/**
	 * Redirect to one of this site's own pages and stop.
	 *
	 * @param string $url URL (cart or checkout page).
	 */
	private static function redirect( $url ) {
		nocache_headers();
		wp_safe_redirect( $url );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Storage
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether a WC session (and cart) is available in this request.
	 *
	 * @return bool
	 */
	private static function session() {
		return function_exists( 'WC' ) && WC()->session instanceof WC_Session;
	}

	/**
	 * Make sure a guest's session survives this request (sets the WC session cookie).
	 */
	private static function ensure_cookie() {
		$session = WC()->session;
		if ( method_exists( $session, 'has_session' ) && method_exists( $session, 'set_customer_session_cookie' ) && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}
	}

	/**
	 * A non-empty cart in a session.
	 *
	 * @return bool
	 */
	private static function has_cart() {
		return self::session() && WC()->cart && ! WC()->cart->is_empty();
	}

	/**
	 * The session's cart token, or ''.
	 *
	 * @return string
	 */
	public static function token() {
		if ( ! self::session() ) {
			return '';
		}
		$token = (string) WC()->session->get( self::SESSION_TOKEN );
		return preg_match( '/^[a-f0-9]{32}$/', $token ) ? $token : '';
	}

	/**
	 * A new random token (32 hex characters).
	 *
	 * @return string
	 */
	private static function new_token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return md5( wp_generate_password( 64, true, true ) );
		}
	}

	/**
	 * A saved cart row.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	private static function get_row( $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', AssistantLabs_WC_Install::carts_table(), $token ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update a saved cart.
	 *
	 * @param string $token   Token.
	 * @param array  $contact Clean contact.
	 * @param array  $cart    Stored cart.
	 * @param string $hash    Content + contact hash.
	 * @param int    $sent    Last send (unix), 0 for never.
	 * @param bool   $exists  Whether the row exists.
	 */
	private static function save_row( $token, array $contact, array $cart, $hash, $sent, $exists ) {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'email'      => $contact['email'],
			'phone'      => $contact['phone'],
			'cart'       => (string) wp_json_encode( $cart ),
			'hash'       => $hash,
			'sent_at'    => $sent ? gmdate( 'Y-m-d H:i:s', $sent ) : null,
			'updated_at' => $now,
		);
		$table = AssistantLabs_WC_Install::carts_table();
		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $data, array( 'token' => $token ) );
			return;
		}
		$data['token']      = $token;
		$data['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $data );
	}

	/**
	 * Delete a saved cart.
	 *
	 * @param string $token Token.
	 */
	private static function delete_row( $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( AssistantLabs_WC_Install::carts_table(), array( 'token' => $token ), array( '%s' ) );
	}

	/**
	 * Saved carts (for the admin screen).
	 *
	 * @return int
	 */
	public static function saved_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', AssistantLabs_WC_Install::carts_table() ) );
	}

	/**
	 * Daily cleanup schedule.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Daily: delete carts older than 30 days, and outbox rows nobody sent in 7 days.
	 */
	public static function cleanup() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s', AssistantLabs_WC_Install::carts_table(), gmdate( 'Y-m-d H:i:s', time() - self::TTL ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', AssistantLabs_WC_Install::events_table(), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}
}
