<?php
/**
 * Store change kinds: product_create, product_update, coupon_create.
 *
 * Same rule as pages: new things are drafts, a change to something live is
 * stored and applied only on publish.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers shared by the store handlers.
 */
abstract class AssistantLabs_WC_Change_Handler extends AssistantLabs_Change_Handler {

	/**
	 * A price string ("49", "49.90"), '' to clear, or null when absent.
	 *
	 * @param array  $body Body.
	 * @param string $key  Key.
	 * @return string|null|WP_Error
	 */
	protected function price( array $body, $key ) {
		if ( ! array_key_exists( $key, $body ) ) {
			return null;
		}
		$value = $body[ $key ];
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			/* translators: %s: field name. */
			return $this->invalid( 'invalid_price', sprintf( __( '%s must be a number of zero or more.', 'assistantlabs-for-woocommerce' ), $key ) );
		}
		return wc_format_decimal( $value );
	}

	/**
	 * Price for summaries: "49 ₪".
	 *
	 * @param string $amount Amount.
	 * @return string
	 */
	protected function money( $amount ) {
		return trim( $amount . ' ' . html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Publish a draft product/coupon through WooCommerce CRUD.
	 *
	 * @param int $id Post id.
	 * @return true|WP_Error
	 */
	protected function publish_object( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'target_missing', __( 'The draft no longer exists.', 'assistantlabs-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$object = 'shop_coupon' === $post->post_type ? new WC_Coupon( $id ) : wc_get_product( $id );
		if ( ! $object ) {
			return new WP_Error( 'target_missing', __( 'The draft no longer exists.', 'assistantlabs-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$object->set_status( 'publish' );
		$object->save();
		return true;
	}
}

/**
 * Applies product fields, used by create, update and discard.
 */
trait AssistantLabs_WC_Product_Fields {

	/**
	 * Validate and clean product fields from a body.
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	protected function product_fields( array $body ) {
		$fields = array();
		$name   = $this->str( $body, 'name' );
		if ( null !== $name ) {
			$fields['name'] = sanitize_text_field( $name );
		}
		foreach ( array( 'regularPrice', 'salePrice' ) as $key ) {
			$price = $this->price( $body, $key );
			if ( is_wp_error( $price ) ) {
				return $price;
			}
			if ( null !== $price ) {
				$fields[ $key ] = $price;
			}
		}
		if ( isset( $fields['salePrice'], $fields['regularPrice'] ) && '' !== $fields['salePrice'] && '' !== $fields['regularPrice'] && (float) $fields['salePrice'] >= (float) $fields['regularPrice'] ) {
			return $this->invalid( 'invalid_price', __( 'salePrice must be lower than regularPrice.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( array_key_exists( 'stockQuantity', $body ) && null !== $body['stockQuantity'] ) {
			if ( ! is_numeric( $body['stockQuantity'] ) ) {
				return $this->invalid( 'invalid_stock', __( 'stockQuantity must be a whole number.', 'assistantlabs-for-woocommerce' ) );
			}
			$fields['stockQuantity'] = (int) $body['stockQuantity'];
		}
		if ( isset( $body['stockStatus'] ) ) {
			if ( ! in_array( $body['stockStatus'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				return $this->invalid( 'invalid_stock_status', __( 'stockStatus must be instock, outofstock or onbackorder.', 'assistantlabs-for-woocommerce' ) );
			}
			$fields['stockStatus'] = $body['stockStatus'];
		}
		foreach ( array( 'description', 'shortDescription' ) as $key ) {
			$value = $this->str( $body, $key );
			if ( null !== $value ) {
				$fields[ $key ] = wp_kses_post( $value );
			}
		}
		return $fields;
	}

	/**
	 * Write fields onto a product object (not saved).
	 *
	 * @param WC_Product $product Product.
	 * @param array      $fields  Fields.
	 */
	protected function apply_product_fields( $product, array $fields ) {
		if ( isset( $fields['name'] ) ) {
			$product->set_name( $fields['name'] );
		}
		if ( array_key_exists( 'regularPrice', $fields ) ) {
			$product->set_regular_price( $fields['regularPrice'] );
		}
		if ( array_key_exists( 'salePrice', $fields ) ) {
			$product->set_sale_price( $fields['salePrice'] );
		}
		if ( array_key_exists( 'stockQuantity', $fields ) ) {
			if ( null === $fields['stockQuantity'] ) {
				$product->set_manage_stock( false );
			} else {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $fields['stockQuantity'] );
			}
		}
		if ( isset( $fields['stockStatus'] ) ) {
			$product->set_stock_status( $fields['stockStatus'] );
		}
		if ( isset( $fields['description'] ) ) {
			$product->set_description( $fields['description'] );
		}
		if ( isset( $fields['shortDescription'] ) ) {
			$product->set_short_description( $fields['shortDescription'] );
		}
	}

	/**
	 * Current values of the given fields, for discard.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $fields  Fields about to change.
	 * @return array
	 */
	protected function product_snapshot( $product, array $fields ) {
		$all = array(
			'name'             => $product->get_name( 'edit' ),
			'regularPrice'     => (string) $product->get_regular_price( 'edit' ),
			'salePrice'        => (string) $product->get_sale_price( 'edit' ),
			'stockQuantity'    => $product->get_manage_stock( 'edit' ) ? (int) $product->get_stock_quantity( 'edit' ) : null,
			'stockStatus'      => $product->get_stock_status( 'edit' ),
			'description'      => $product->get_description( 'edit' ),
			'shortDescription' => $product->get_short_description( 'edit' ),
		);
		return array_intersect_key( $all, $fields );
	}

	/**
	 * Names of changed fields, for summaries.
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	protected function product_field_names( array $fields ) {
		$labels = array(
			'name'             => __( 'name', 'assistantlabs-for-woocommerce' ),
			'regularPrice'     => __( 'price', 'assistantlabs-for-woocommerce' ),
			'salePrice'        => __( 'sale price', 'assistantlabs-for-woocommerce' ),
			'stockQuantity'    => __( 'stock', 'assistantlabs-for-woocommerce' ),
			'stockStatus'      => __( 'stock status', 'assistantlabs-for-woocommerce' ),
			'description'      => __( 'description', 'assistantlabs-for-woocommerce' ),
			'shortDescription' => __( 'short description', 'assistantlabs-for-woocommerce' ),
		);
		$out    = array();
		foreach ( array_keys( $fields ) as $key ) {
			$out[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
		return implode( ', ', array_unique( $out ) );
	}
}

/**
 * `product_create`: a new simple product, saved as a draft.
 */
class AssistantLabs_WC_Product_Create_Handler extends AssistantLabs_WC_Change_Handler {
	use AssistantLabs_WC_Product_Fields;

	/**
	 * {@inheritDoc}
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	public function create( array $body ) {
		$fields = $this->product_fields( $body );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( empty( $fields['name'] ) ) {
			return $this->invalid( 'missing_name', __( 'name is required.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( ! isset( $fields['regularPrice'] ) || '' === $fields['regularPrice'] ) {
			return $this->invalid( 'missing_price', __( 'regularPrice is required.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( ! current_user_can( 'edit_products' ) ) {
			return new WP_Error( 'forbidden', __( 'The AssistantLabs user cannot create products.', 'assistantlabs-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$sku = $this->str( $body, 'sku' );
		$sku = null === $sku ? '' : wc_clean( $sku );
		if ( '' !== $sku && wc_get_product_id_by_sku( $sku ) ) {
			return new WP_Error( 'sku_exists', __( 'Another product already uses this SKU.', 'assistantlabs-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$category_ids = array();
		$missing      = array();
		if ( isset( $body['categories'] ) && is_array( $body['categories'] ) ) {
			foreach ( $body['categories'] as $name ) {
				if ( ! is_string( $name ) || '' === trim( $name ) ) {
					continue;
				}
				$term = get_term_by( 'name', $name, 'product_cat' );
				if ( ! $term ) {
					$term = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' );
				}
				if ( $term ) {
					$category_ids[] = (int) $term->term_id;
				} else {
					// Creating a category would change the live store, so only existing ones are used.
					$missing[] = sanitize_text_field( $name );
				}
			}
		}

		$product = new WC_Product_Simple();
		try {
			$this->apply_product_fields( $product, $fields );
			if ( '' !== $sku ) {
				$product->set_sku( $sku );
			}
			if ( $category_ids ) {
				$product->set_category_ids( $category_ids );
			}
			$product->set_status( 'draft' );
			$id = $product->save();
		} catch ( WC_Data_Exception $e ) {
			return $this->invalid( $e->getErrorCode(), $e->getMessage() );
		}
		if ( ! $id ) {
			return new WP_Error( 'save_failed', __( 'Could not save the product.', 'assistantlabs-for-woocommerce' ), array( 'status' => 500 ) );
		}

		/* translators: 1: product name, 2: price. */
		$summary = sprintf( __( 'New draft product “%1$s” at %2$s', 'assistantlabs-for-woocommerce' ), $fields['name'], $this->money( isset( $fields['salePrice'] ) && '' !== $fields['salePrice'] ? $fields['salePrice'] : $fields['regularPrice'] ) );
		if ( $missing ) {
			/* translators: %s: category names. */
			$summary .= ' ' . sprintf( __( '(categories not found: %s)', 'assistantlabs-for-woocommerce' ), implode( ', ', $missing ) );
		}

		return array(
			'target_id'      => (int) $id,
			'target_type'    => 'product',
			'target_title'   => $fields['name'],
			'created_target' => true,
			'applied'        => true,
			'payload'        => array(),
			'snapshot'       => array(),
			'summary'        => $summary,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function publish( array $record ) {
		return $this->publish_object( $record['target_id'] );
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
 * `product_update`: edit a draft product in place, or keep the edit for a live one.
 */
class AssistantLabs_WC_Product_Update_Handler extends AssistantLabs_WC_Change_Handler {
	use AssistantLabs_WC_Product_Fields;

	/**
	 * {@inheritDoc}
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	public function create( array $body ) {
		$target_id = isset( $body['targetId'] ) ? (int) $body['targetId'] : 0;
		$product   = $target_id ? wc_get_product( $target_id ) : null;
		$post      = $target_id ? get_post( $target_id ) : null;
		if ( ! $product || ! $post || 'product' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'assistantlabs-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$fields = $this->product_fields( $body );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( ! $fields ) {
			return $this->invalid( 'nothing_to_change', __( 'Send at least one field to change.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( isset( $fields['name'] ) && '' === $fields['name'] ) {
			return $this->invalid( 'missing_name', __( 'name cannot be empty.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( $product->is_type( 'variable' ) && ( isset( $fields['regularPrice'] ) || isset( $fields['salePrice'] ) ) ) {
			return $this->invalid( 'unsupported_product_type', __( 'Prices of a variable product are set on its variations, which this change cannot edit yet.', 'assistantlabs-for-woocommerce' ) );
		}
		// Check the sale price against the price it will end up with.
		$regular = isset( $fields['regularPrice'] ) ? $fields['regularPrice'] : (string) $product->get_regular_price( 'edit' );
		if ( isset( $fields['salePrice'] ) && '' !== $fields['salePrice'] && '' !== $regular && (float) $fields['salePrice'] >= (float) $regular ) {
			return $this->invalid( 'invalid_price', __( 'salePrice must be lower than regularPrice.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			return new WP_Error( 'forbidden', __( 'The AssistantLabs user cannot edit this product.', 'assistantlabs-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$record = array(
			'target_id'      => $target_id,
			'target_type'    => 'product',
			'target_title'   => $product->get_name( 'edit' ),
			'created_target' => false,
			'applied'        => false,
			'payload'        => $fields,
			'snapshot'       => array(),
			/* translators: 1: product name, 2: changed fields. */
			'summary'        => sprintf( __( 'Edit product “%1$s”: %2$s', 'assistantlabs-for-woocommerce' ), $product->get_name( 'edit' ), $this->describe( $fields ) ),
		);

		if ( $this->is_draft( $post ) ) {
			$record['snapshot'] = $this->product_snapshot( $product, $fields );
			try {
				$this->apply_product_fields( $product, $fields );
				$product->save();
			} catch ( WC_Data_Exception $e ) {
				return $this->invalid( $e->getErrorCode(), $e->getMessage() );
			}
			$record['applied'] = true;
		}
		return $record;
	}

	/**
	 * "price 59 ₪ → 49 ₪, stock 12".
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	private function describe( array $fields ) {
		$parts = array();
		if ( isset( $fields['regularPrice'] ) ) {
			/* translators: %s: price. */
			$parts[] = sprintf( __( 'price %s', 'assistantlabs-for-woocommerce' ), '' === $fields['regularPrice'] ? '—' : $this->money( $fields['regularPrice'] ) );
		}
		if ( array_key_exists( 'salePrice', $fields ) ) {
			/* translators: %s: price, or "none". */
			$parts[] = sprintf( __( 'sale price %s', 'assistantlabs-for-woocommerce' ), '' === $fields['salePrice'] ? __( 'none', 'assistantlabs-for-woocommerce' ) : $this->money( $fields['salePrice'] ) );
		}
		if ( array_key_exists( 'stockQuantity', $fields ) ) {
			/* translators: %s: quantity. */
			$parts[] = sprintf( __( 'stock %s', 'assistantlabs-for-woocommerce' ), (string) $fields['stockQuantity'] );
		}
		$rest = array_diff_key( $fields, array_flip( array( 'regularPrice', 'salePrice', 'stockQuantity' ) ) );
		if ( $rest ) {
			$parts[] = $this->product_field_names( $rest );
		}
		return implode( ', ', $parts );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function publish( array $record ) {
		if ( ! empty( $record['applied'] ) ) {
			return $this->publish_object( $record['target_id'] );
		}
		$product = wc_get_product( $record['target_id'] );
		$post    = get_post( $record['target_id'] );
		if ( ! $product || ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'target_missing', __( 'The product this change edits no longer exists.', 'assistantlabs-for-woocommerce' ), array( 'status' => 409 ) );
		}
		try {
			$this->apply_product_fields( $product, (array) $record['payload'] );
			$product->save();
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function discard( array $record ) {
		if ( empty( $record['applied'] ) || empty( $record['snapshot'] ) ) {
			return true;
		}
		$product = wc_get_product( $record['target_id'] );
		$post    = get_post( $record['target_id'] );
		if ( ! $product || ! $post || ! $this->is_draft( $post ) ) {
			return true;
		}
		try {
			$this->apply_product_fields( $product, (array) $record['snapshot'] );
			$product->save();
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Show the proposed values on the live product page.
	 *
	 * @param array $record Record.
	 */
	public function preview( array $record ) {
		if ( ! empty( $record['applied'] ) ) {
			return;
		}
		$target = (int) $record['target_id'];
		$fields = (array) $record['payload'];

		$map = array(
			'name'             => 'woocommerce_product_get_name',
			'regularPrice'     => 'woocommerce_product_get_regular_price',
			'salePrice'        => 'woocommerce_product_get_sale_price',
			'stockQuantity'    => 'woocommerce_product_get_stock_quantity',
			'stockStatus'      => 'woocommerce_product_get_stock_status',
			'description'      => 'woocommerce_product_get_description',
			'shortDescription' => 'woocommerce_product_get_short_description',
		);
		foreach ( $map as $key => $hook ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}
			$value = $fields[ $key ];
			add_filter(
				$hook,
				static function ( $current, $product ) use ( $target, $value ) {
					return $product && (int) $product->get_id() === $target ? $value : $current;
				},
				99,
				2
			);
		}

		if ( isset( $fields['regularPrice'] ) || array_key_exists( 'salePrice', $fields ) ) {
			add_filter(
				'woocommerce_product_get_price',
				static function ( $current, $product ) use ( $target ) {
					if ( ! $product || (int) $product->get_id() !== $target ) {
						return $current;
					}
					$sale = $product->get_sale_price();
					return '' !== (string) $sale ? $sale : $product->get_regular_price();
				},
				99,
				2
			);
		}
		if ( isset( $fields['name'] ) ) {
			$name = $fields['name'];
			add_filter(
				'the_title',
				static function ( $title, $id = 0 ) use ( $target, $name ) {
					return (int) $id === $target ? esc_html( $name ) : $title;
				},
				0,
				2
			);
		}
		if ( isset( $fields['description'] ) ) {
			$description = $fields['description'];
			add_filter(
				'the_content',
				static function ( $content ) use ( $target, $description ) {
					return (int) get_the_ID() === $target ? $description : $content;
				},
				0
			);
		}
	}
}

/**
 * `coupon_create`: a new coupon, saved as a draft (drafts can't be used at checkout).
 */
class AssistantLabs_WC_Coupon_Create_Handler extends AssistantLabs_WC_Change_Handler {

	/**
	 * {@inheritDoc}
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	public function create( array $body ) {
		$code = $this->str( $body, 'code' );
		$code = null === $code ? '' : wc_format_coupon_code( $code );
		if ( '' === $code ) {
			return $this->invalid( 'missing_code', __( 'code is required.', 'assistantlabs-for-woocommerce' ) );
		}
		$type = isset( $body['discountType'] ) ? $body['discountType'] : '';
		if ( ! in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
			return $this->invalid( 'invalid_discount_type', __( 'discountType must be percent, fixed_cart or fixed_product.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( ! isset( $body['amount'] ) || ! is_numeric( $body['amount'] ) || (float) $body['amount'] <= 0 ) {
			return $this->invalid( 'invalid_amount', __( 'amount must be a number above zero.', 'assistantlabs-for-woocommerce' ) );
		}
		$amount = wc_format_decimal( $body['amount'] );
		if ( 'percent' === $type && (float) $amount > 100 ) {
			return $this->invalid( 'invalid_amount', __( 'A percent discount cannot be above 100.', 'assistantlabs-for-woocommerce' ) );
		}
		$expires = $this->str( $body, 'expiresAt' );
		if ( null !== $expires && '' !== $expires && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ) {
			return $this->invalid( 'invalid_expires_at', __( 'expiresAt must be YYYY-MM-DD.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( isset( $body['minimumAmount'] ) && ( ! is_numeric( $body['minimumAmount'] ) || (float) $body['minimumAmount'] < 0 ) ) {
			return $this->invalid( 'invalid_minimum', __( 'minimumAmount must be a number of zero or more.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( isset( $body['usageLimit'] ) && ( ! is_numeric( $body['usageLimit'] ) || (int) $body['usageLimit'] < 0 ) ) {
			return $this->invalid( 'invalid_usage_limit', __( 'usageLimit must be a whole number.', 'assistantlabs-for-woocommerce' ) );
		}
		if ( ! current_user_can( 'edit_shop_coupons' ) ) {
			return new WP_Error( 'forbidden', __( 'The AssistantLabs user cannot create coupons.', 'assistantlabs-for-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( $this->code_exists( $code ) ) {
			return new WP_Error( 'coupon_exists', __( 'A coupon with this code already exists.', 'assistantlabs-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$coupon = new WC_Coupon();
		try {
			$coupon->set_code( $code );
			$coupon->set_discount_type( $type );
			$coupon->set_amount( $amount );
			if ( $expires ) {
				$coupon->set_date_expires( $expires );
			}
			if ( isset( $body['usageLimit'] ) ) {
				$coupon->set_usage_limit( (int) $body['usageLimit'] );
			}
			if ( isset( $body['minimumAmount'] ) ) {
				$coupon->set_minimum_amount( wc_format_decimal( $body['minimumAmount'] ) );
			}
			if ( isset( $body['freeShipping'] ) ) {
				$coupon->set_free_shipping( (bool) $body['freeShipping'] );
			}
			$coupon->set_status( 'draft' );
			$id = $coupon->save();
		} catch ( WC_Data_Exception $e ) {
			return $this->invalid( $e->getErrorCode(), $e->getMessage() );
		}
		if ( ! $id ) {
			return new WP_Error( 'save_failed', __( 'Could not save the coupon.', 'assistantlabs-for-woocommerce' ), array( 'status' => 500 ) );
		}

		if ( 'percent' === $type ) {
			/* translators: %s: percentage. */
			$what = sprintf( __( '%s%% off', 'assistantlabs-for-woocommerce' ), $amount );
		} elseif ( 'fixed_cart' === $type ) {
			/* translators: %s: amount. */
			$what = sprintf( __( '%s off the cart', 'assistantlabs-for-woocommerce' ), $this->money( $amount ) );
		} else {
			/* translators: %s: amount. */
			$what = sprintf( __( '%s off each product', 'assistantlabs-for-woocommerce' ), $this->money( $amount ) );
		}
		if ( $expires ) {
			/* translators: %s: date. */
			$what .= ', ' . sprintf( __( 'until %s', 'assistantlabs-for-woocommerce' ), $expires );
		}

		return array(
			'target_id'      => (int) $id,
			'target_type'    => 'shop_coupon',
			'target_title'   => $code,
			'created_target' => true,
			'applied'        => true,
			'payload'        => array(),
			'snapshot'       => array(),
			/* translators: 1: coupon code, 2: discount description. */
			'summary'        => sprintf( __( 'New coupon “%1$s”: %2$s', 'assistantlabs-for-woocommerce' ), $code, $what ),
		);
	}

	/**
	 * Is the code taken by any coupon that isn't in the trash (drafts included)?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	private function code_exists( $code ) {
		$found = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'title'          => $code,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return ! empty( $found );
	}

	/**
	 * Coupons have no front-end page to preview.
	 *
	 * @param array $record Record.
	 * @return bool
	 */
	public function has_preview( array $record ) {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $record Record.
	 * @return true|WP_Error
	 */
	public function publish( array $record ) {
		return $this->publish_object( $record['target_id'] );
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
