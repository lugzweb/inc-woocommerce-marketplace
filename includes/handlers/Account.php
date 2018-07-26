<?php

if ( ! class_exists( 'Account' ) ) {

	class Account {

		protected $parse;

		public function __construct() {
			add_action( 'wp_ajax_get-account-content', [ $this, 'account_content' ] );

			add_action( 'wp_ajax_nopriv_wishlist-sync', [ $this, 'wishlist_sync' ] );
			add_action( 'wp_ajax_wishlist-sync', [ $this, 'wishlist_sync' ] );

			add_action( 'init', [ $this, 'add_my_account_wishlist' ] );
			add_filter( 'woocommerce_account_menu_items', [ $this, 'account_menu_items' ] );
			add_action( 'woocommerce_account_wishlist_endpoint', [ $this, 'wishlist_endpoint_content' ] );
		}

		public function account_content() {
			$request = $_REQUEST;

			$this->parse = parse_url( $request['uri'] );
			$title       = '';

			ob_start();

			if ( $this->check_account_page( 'settings' ) ) {
				get_template_part( 'woocommerce/myaccount/form-edit-account' );
				$title = __( 'Settings', TEXTDOMAIN ) . ' - ' . get_bloginfo( 'name' );
			} elseif ( $this->check_account_page( 'wishlist' ) ) {
				get_template_part( 'woocommerce/myaccount/wishlist' );
				$title = __( 'Wishlist', TEXTDOMAIN ) . ' - ' . get_bloginfo( 'name' );
			} elseif ( $this->check_account_page( 'purchases' ) ) {
				get_template_part( 'woocommerce/myaccount/downloads' );
				$title = __( 'Purchases', TEXTDOMAIN ) . ' - ' . get_bloginfo( 'name' );
			}

			$response['content'] = ob_get_clean();
			$response['title'] = $title;
			$response['success'] = true;

			wp_send_json( $response );
			exit;
		}

		protected function check_account_page( $path ) {
			return $this->parse['path'] === '/my-account/' . $path . '/';
		}

		public function wishlist_sync() {
			$product_id = $_REQUEST[ 'product' ];
			$method     = $_REQUEST[ 'method' ];

			$user_id = get_current_user_id();

			try {

				$result = array();

				if ( !User_Actions::is_activate( $user_id ) )
					throw new Exception( __( 'Please confirm your account', TEXTDOMAIN ) );

				if ( empty( $product_id ) )
					throw new Exception( __( 'Empty product id', TEXTDOMAIN ) );

				if ( empty( $user_id ) )
					throw new Exception( __( 'Please login for add product to wishlist', TEXTDOMAIN ) );

				$wishlist = get_user_meta( $user_id, 'wishlist', true );

				if ( $method === 'add' ) {
					if ( empty( $wishlist ) ) {
						$wishlist = array( $product_id );
					} elseif ( ! in_array( $product_id, $wishlist ) ) {
						$wishlist[] = $product_id;
					}

					$likes = (int) get_post_meta( $product_id, '_total_likes', true );
					update_post_meta( $product_id, '_total_likes', ++$likes );

					Statistic::set_product_point( $product_id, 1 );

				} elseif ( $method === 'remove' && in_array( $product_id, $wishlist ) ) {

					$key = array_search( $product_id, $wishlist );
					unset( $wishlist[ $key ] );
				}

				update_user_meta( $user_id, 'wishlist', $wishlist );

				if ( $method === 'add' ) {

					$result['msg'] = __( 'Successfully added product to wishlist', TEXTDOMAIN );

				} elseif ( $method === 'remove' ) {

					$result['msg'] = __( 'Successfully remove product from wishlist', TEXTDOMAIN );
				}

				wp_send_json_success( $result );
				exit;

			} catch ( Exception $e ) {

				wp_send_json_error( array(
					'msg' => $e->getMessage()
				) );
				exit;
			}
		}

		public function add_my_account_wishlist() {
			add_rewrite_endpoint( 'wishlist', EP_PAGES );
		}

		public function account_menu_items( $items ) {

			$items['wishlist'] = __( 'Wishlist', TEXTDOMAIN );

			return $items;
		}

		public function wishlist_endpoint_content() {
			get_template_part( 'woocommerce/myaccount/wishlist' );
		}
	}
}