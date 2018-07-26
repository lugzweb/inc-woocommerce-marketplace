<?php

if ( ! class_exists( 'Cart' ) ) {

	class Cart {

		public function __construct() {
			add_action( 'wp_ajax_nopriv_add-cart', [ $this, 'add_product_cart' ] );
			add_action( 'wp_ajax_add-cart', [ $this, 'add_product_cart' ] );

			add_action( 'wp_ajax_nopriv_remove-cart', [ $this, 'remove_product_cart' ] );
			add_action( 'wp_ajax_remove-cart', [ $this, 'remove_product_cart' ] );
		}

		public function add_product_cart() {
			wp_verify_nonce( $_REQUEST['security'], 'cart-nonce' );

			try {

				ob_start();

				$product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_REQUEST['product'] ) );

				$quantity = 1;

				$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity );

				$product_cart_id = WC()->cart->generate_cart_id( $product_id );
				$in_cart = WC()->cart->find_product_in_cart( $product_cart_id );

				if ( $in_cart )
					throw new Exception( __( 'Product already in cart', TEXTDOMAIN ) );

				if ( $passed_validation && WC()->cart->add_to_cart( $product_id, $quantity ) ) {

					do_action( 'woocommerce_ajax_added_to_cart', $product_id );

					ob_start();

					woocommerce_mini_cart();

					$mini_cart = ob_get_clean();

					Statistic::set_product_point( $product_id, 2 );

					$data = array(
						'msg' => __( 'Successfully added to cart', TEXTDOMAIN ),
						'content' => $mini_cart,
						'total' => '$' . ceil(WC()->cart->get_cart_contents_total()),
						'count' => WC()->cart->get_cart_contents_count(),
						'cart_hash' => apply_filters( 'woocommerce_add_to_cart_hash', WC()->cart->get_cart_for_session() ? md5( json_encode( WC()->cart->get_cart_for_session() ) ) : '', WC()->cart->get_cart_for_session() ),
					);
				} else {

					throw new Exception( __( 'Technical error: unable to add the product', TEXTDOMAIN ) );
				}

				wp_send_json_success( $data );
				exit;

			} catch ( Exception $e ) {

				wp_send_json_error( array( 'msg' => $e->getMessage() ) );
				exit;
			}
		}

		public function remove_product_cart() {
			wp_verify_nonce( $_REQUEST['security'], 'cart-nonce' );

			try {

				ob_start();

				$cart_item_key = sanitize_text_field( $_REQUEST['product_key'] );

				if ( WC()->cart->get_cart_item( $cart_item_key ) ) {

					WC()->cart->remove_cart_item( $cart_item_key );

					ob_start();

					woocommerce_mini_cart();

					$mini_cart = ob_get_clean();

					$cart_total = ceil(WC()->cart->get_cart_contents_total());
					$cart_total = $cart_total > 0 ? '$' . $cart_total : 0;

					$data = array(
						'msg' => __( 'Successfully remove product from cart', TEXTDOMAIN ),
						'content' => $mini_cart,
						'total' => $cart_total,
						'count' => WC()->cart->get_cart_contents_count()
					);

				} else {

					throw new Exception( __( 'Product not in cart', TEXTDOMAIN ) );
				}

				wp_send_json_success( $data );
				exit;

			} catch ( Exception $e ) {

				wp_send_json_error( array( 'msg' => $e->getMessage() ) );
				exit;
			}
		}
	}
}