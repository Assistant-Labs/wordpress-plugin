<?php
/**
 * Privacy: suggested policy text, and the personal-data exporter and eraser
 * for captured carts (Tools → Export / Erase Personal Data, by email).
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy integration for captured carts.
 */
class AssistantLabs_WC_Privacy {

	const PER_PAGE = 50;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy).
	 */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text. The abandoned-cart part applies only if "Recover abandoned carts" is turned on in AssistantLabs → Store.', 'assistantlabs-for-woocommerce' ) . '</p>';
		$content .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'assistantlabs-for-woocommerce' ) . ' </strong>';
		$content .= esc_html__( 'We use AssistantLabs (https://assistantlabs.io) to answer customer questions and follow up on orders. When you place an order or create an account, your name, email address, phone number, order contents, order totals and shipping tracking details are shared with AssistantLabs.', 'assistantlabs-for-woocommerce' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If you enter your email address or phone number at checkout and leave without ordering, we save your cart (the products, quantities and coupons) together with those contact details for up to 30 days, and share them with AssistantLabs so we can remind you about your cart with a link that restores it. We record your marketing consent only when you tick our opt-in checkbox. Placing the order or emptying your cart removes the saved cart.', 'assistantlabs-for-woocommerce' ) . '</p>';
		$content .= '<p>' . esc_html__( 'AssistantLabs privacy policy: https://assistantlabs.io/privacy', 'assistantlabs-for-woocommerce' ) . '</p>';

		wp_add_privacy_policy_content( __( 'AssistantLabs for WooCommerce', 'assistantlabs-for-woocommerce' ), wp_kses_post( $content ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['assistantlabs-wc-carts'] = array(
			'exporter_friendly_name' => __( 'AssistantLabs saved carts', 'assistantlabs-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['assistantlabs-wc-carts'] = array(
			'eraser_friendly_name' => __( 'AssistantLabs saved carts', 'assistantlabs-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export the carts saved under an email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$page = max( 1, (int) $page );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY created_at LIMIT %d OFFSET %d', AssistantLabs_WC_Install::carts_table(), (string) $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$cart     = json_decode( $row['cart'], true );
			$products = array();
			foreach ( is_array( $cart ) && isset( $cart['lines'] ) ? (array) $cart['lines'] : array() as $line ) {
				$product    = isset( $line['variation_id'], $line['product_id'] ) ? wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] ) : null;
				$name       = $product ? $product->get_name() : '#' . ( isset( $line['product_id'] ) ? (int) $line['product_id'] : 0 );
				$products[] = sprintf( '%s × %d', $name, isset( $line['quantity'] ) ? (int) $line['quantity'] : 0 );
			}
			$items[] = array(
				'group_id'    => 'assistantlabs-wc-carts',
				'group_label' => __( 'Saved carts (AssistantLabs)', 'assistantlabs-for-woocommerce' ),
				'item_id'     => 'assistantlabs-cart-' . $row['token'],
				'data'        => array(
					array(
						'name'  => __( 'Email', 'assistantlabs-for-woocommerce' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Phone', 'assistantlabs-for-woocommerce' ),
						'value' => $row['phone'],
					),
					array(
						'name'  => __( 'Products', 'assistantlabs-for-woocommerce' ),
						'value' => implode( ', ', $products ),
					),
					array(
						'name'  => __( 'Coupons', 'assistantlabs-for-woocommerce' ),
						'value' => is_array( $cart ) && ! empty( $cart['coupons'] ) ? implode( ', ', (array) $cart['coupons'] ) : '',
					),
					array(
						'name'  => __( 'Saved', 'assistantlabs-for-woocommerce' ),
						'value' => $row['updated_at'] . ' UTC',
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( (array) $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase the carts saved under an email, and any of its cart events not sent yet.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (unused: everything goes in one pass).
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$email = (string) $email;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own tables.
		$removed = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', AssistantLabs_WC_Install::carts_table(), $email ) );
		if ( '' !== $email ) {
			$like     = '%' . $wpdb->esc_like( '"email":' . wp_json_encode( $email ) ) . '%';
			$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE topic = %s AND payload LIKE %s', AssistantLabs_WC_Install::events_table(), 'checkout.updated', $like ) );
		}
		// phpcs:enable
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
