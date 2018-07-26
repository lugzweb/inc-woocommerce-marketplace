<?php

if ( ! class_exists( 'Product' ) ) {

	class Product {

		public function __construct() {
			add_action( 'wp_ajax_nopriv_sync-product', [ $this, 'sync_product' ] );
			add_action( 'wp_ajax_sync-product', [ $this, 'sync_product' ] );

			add_action( 'wp_ajax_nopriv_delete-product', [ $this, 'delete_product' ] );
			add_action( 'wp_ajax_delete-product', [ $this, 'delete_product' ] );

			add_action( 'wp_ajax_nopriv_get-list-products', [ $this, 'get_list_products' ] );
			add_action( 'wp_ajax_get-list-products', [ $this, 'get_list_products' ] );

			add_action( 'wp_ajax_nopriv_get-single-product', [ $this, 'get_single_product' ] );
			add_action( 'wp_ajax_get-single-product', [ $this, 'get_single_product' ] );

			add_action( 'wp_ajax_nopriv_popular-product', [ $this, 'get_popular_product' ] );
			add_action( 'wp_ajax_popular-product', [ $this, 'get_popular_product' ] );

			add_action( 'wp_ajax_nopriv_count-shares', [ $this, 'increase_shares_product' ] );
			add_action( 'wp_ajax_count-shares', [ $this, 'increase_shares_product' ] );

			add_action( 'product_cat_add_form_fields', [ $this, 'add_custom_fields' ], null, 2 );
			add_action( 'product_cat_edit_form_fields', [ $this, 'edit_custom_fields' ], null, 2 );

			add_action( 'edited_product_cat', [ $this, 'save_custom_meta' ], 10, 2 );
			add_action( 'create_product_cat', [ $this, 'save_custom_meta' ], 10, 2 );

			add_filter( 'loop_shop_per_page', create_function( '$cols', 'return 20;' ), 20 );
		}

		function sync_product() {

			$request = $_REQUEST;

			$user_id = get_current_user_id();

			if ( !User_Actions::is_activate( $user_id ) ) {

				$result = array(
					'error' => true,
					'msg'   => __( 'Please confirm your account', TEXTDOMAIN ),
				);

			} else {

				$has_error = false;

				$required_fields = array(
					'title',
					'description',
					'product_cats',
					'featured_img',
					'downloadable_files'
				);

				foreach ( $required_fields as $field ) {
					if ( empty( $request[ $field ] ) ) {
						$has_error = true;
						break;
					}
				}

				if ( ! $has_error ) {

					if ( $request['preview'] )
						$result = $this->preview_handler( $request );
					else
						$result = $this->update_handler( $request );

				} else {

					$result = array(
						'error' => true,
						'msg'   => __( 'Please fill all required fields', TEXTDOMAIN ),
					);
				}
			}

			wp_send_json( $result );
			exit;
		}

		public function preview_handler( $request ) {

			$_SESSION['preview_data'] = $request;

			return array(
				'success' => true,
				'preview' => true,
				'redirect' => site_url( 'preview/' )
			);
		}

		public function update_handler( $request ) {
			global $WCMp;

			$is_update  = false;
			$is_publish = false;
			$is_vendor  = false;
			$has_error = false;

			$current_user_id = $vendor_id = apply_filters( 'wcmp_current_loggedin_vendor_id', get_current_user_id() );

			if ( is_user_wcmp_vendor( $current_user_id ) )
				$is_vendor = true;

			if ( $is_vendor ) {

				if ( ! current_user_can( 'publish_products' ) )
					$product_status = 'pending';
				else
					$product_status = 'publish';

			} else {
				$product_status = 'publish';
			}

			$new_product = array(
				'post_title'   => wc_clean( $request['title'] ),
				'post_status'  => $product_status,
				'post_type'    => 'product',
				'post_content' => $request['description'],
				'post_author'  => $vendor_id
			);

			if ( isset( $request['pro_id'] ) && $request['pro_id'] == 0 ) {

				$is_publish     = true;
				$new_product_id = wp_insert_post( $new_product, true );

			} else {

				$is_update         = true;
				$new_product['ID'] = $request['pro_id'];

				if ( ! $is_vendor )
					unset( $new_product['post_author'] );

				$new_product_id = wp_update_post( $new_product, true );
			}

			if ( ! is_wp_error( $new_product_id ) ) {

				if ( $is_update )
					$new_product_id = $request['pro_id'];

				update_post_meta( $new_product_id, '_sku', '' );

				$downloadables = array();

				if ( isset( $request['is_downloadable'] ) && isset( $request['downloadable_files'] ) ) {
					foreach ( $request['downloadable_files'] as $downloadable_files ) {
						if ( ! empty( $downloadable_files['file'] ) ) {
							$name              = wc_clean( $downloadable_files['name'] );
							$downloadables[]   = array(
								'name'          => $name,
								'file'          => wp_unslash( trim( $downloadable_files['file'] ) ),
								'previous_hash' => md5( $downloadable_files['file'] )
							);
						}
					}
				}

				$product_type = empty( $request['product_type'] ) ? WC_Product_Factory::get_product_type( $new_product_id ) : sanitize_title( stripslashes( $request['product_type'] ) );
				$classname    = WC_Product_Factory::get_product_classname( $new_product_id, $product_type ? $product_type : 'simple' );

				$product = new $classname( $new_product_id );

				$errors = $product->set_props( array(
					'downloadable'  => isset( $request['is_downloadable'] ),
					'regular_price' => wc_clean( $request['regular_price'] ),
					'downloads'     => $downloadables,
				) );

				if ( is_wp_error( $errors ) )
					$has_error = true;

				$product->save();

				if ( ! empty( $request['free_product'] ) )
					update_post_meta( $new_product_id, '_free_product', $request['free_product'] );
				else
					update_post_meta( $new_product_id, '_free_product', '' );

				if ( ! empty( $request['product_cats'] ) ) {

					$is_first = true;

					foreach ( $request['product_cats'] as $product_cat ) {
						if ( $is_first ) {
							$is_first = false;
							wp_set_object_terms( $new_product_id, (int) $product_cat, 'product_cat' );
						} else {
							wp_set_object_terms( $new_product_id, (int) $product_cat, 'product_cat', true );
						}
					}
				} else {

					wp_set_object_terms( $new_product_id, array(), 'product_cat' );
				}

				if ( ! empty( $request['compatibles'] ) ) {

					$is_first = true;

					foreach ( $request['compatibles'] as $compatible ) {
						if ( $is_first ) {
							$is_first = false;
							wp_set_object_terms( $new_product_id, (int) $compatible, 'compatible' );
						} else {
							wp_set_object_terms( $new_product_id, (int) $compatible, 'compatible', true );
						}
					}
				}

				if ( ! empty( $request['featured_img'] ) ) {
					$featured_img_id = get_attachment_id_by_url( $request['featured_img'] );
					set_post_thumbnail( $new_product_id, $featured_img_id );
				} else {
					delete_post_thumbnail( $new_product_id );
				}

				if ( ! empty( $request['gallery_img'] ) ) {

					$gallery = array();

					foreach ( $request['gallery_img'] as $gallery_img ) {
						if ( ! empty( $gallery_img['image'] ) ) {
							$gallery_img_id = get_attachment_id_by_url( $gallery_img['image'] );
							$gallery[]      = $gallery_img_id;
						}
					}

					update_post_meta( $new_product_id, '_product_image_gallery', implode( ',', $gallery ) );
				}

				if ( ! empty( $request['features'] ) )
					update_post_meta( $new_product_id, '_features', $request['features'] );

				if ( $is_vendor && ! $is_update ) {
					$vendor_term = get_user_meta( $current_user_id, '_vendor_term_id', true );
					$term        = get_term( $vendor_term, $WCMp->taxonomy->taxonomy_name );
					wp_delete_object_term_relationships( $new_product_id, $WCMp->taxonomy->taxonomy_name );
					wp_set_post_terms( $new_product_id, $term->name, $WCMp->taxonomy->taxonomy_name, true );
				}

				if ( $is_publish )
					$WCMp->product->on_all_status_transitions( $product_status, '', get_post( $new_product_id ) );

				if ( ! $has_error ) {

					if ( get_post_status( $new_product_id ) == 'publish' ) {

						$result = array(
							'success'  => true,
							'msg'      => __( 'Product updated successfully!', TEXTDOMAIN ),
							'redirect' => wcmp_get_vendor_dashboard_endpoint_url( get_wcmp_vendor_settings( 'wcmp_products_endpoint', 'vendor', 'general', 'products' ) )
						);

					} else {

						Mailing::get_instance()->vendor_add_product( get_current_user_id() );

						$result = array(
							'success'  => true,
							'msg'      => __( 'Product saved successfully!', TEXTDOMAIN ),
							'redirect' => wcmp_get_vendor_dashboard_endpoint_url( get_wcmp_vendor_settings( 'wcmp_products_endpoint', 'vendor', 'general', 'products' ) )
						);
					}

				} else {

					$result = array(
						'error' => true,
						'msg'   => $errors->get_error_message(),
					);
				}

			} else {

				$result = array(
					'error' => true,
					'msg'   => $new_product_id->get_error_message(),
				);
			}

			return $result;
		}

		function delete_product() {

			$product_id = $_REQUEST['proid'];

			if ( $product_id ) {

				if ( wp_trash_post( $product_id ) ) {

					$result = array(
						'success'  => true,
						'msg'      => __( 'Product delete successfully!', TEXTDOMAIN )
					);

					wp_send_json( $result );
					exit;
				}
			}
		}

		function get_list_products() {

			$request = $_REQUEST;
			$result  = array( 'success' => true );

			$query = array();
			$parts = parse_url( $request['uri'] );
			parse_str( $parts['query'], $query );

			$category_slug  = Category::get_current_category_slug( $request['uri'] );
			$compatible_ids = ! empty( $query['compatibles'] ) ? explode( ',', $query['compatibles'] ) : array();

			$page   = $query['paged'];
			$order  = empty( $query['orderby'] ) ? 'date' : $query['orderby'];

			$search = empty( $query['s'] ) ? '' :  $query['s'];

			$products       = self::get_products_by_terms( $category_slug, $compatible_ids, $order, $search );
			$total_products = count( $products );

			$page          = empty( $page ) ? 1 : $page;
			$products_page = Product::get_paged_products( $products, $page );
			$total_pages   = $total_products > PRODUCTS_PER_PAGE ? ceil( $total_products / PRODUCTS_PER_PAGE ) : 1;

			if ( ! empty( $request['category'] ) ) {

				$title       = Category::get_category_title( $category_slug, $search );
				$description = Category::get_category_desc( $category_slug, $search );

				$compatibles = Compatible::get_compatibles_by_category( $category_slug, $search );

				ob_start();
				set_query_var( 'search', $search );
				set_query_var( 'order', $order );

				set_query_var( 'compatibles', $compatibles );
				set_query_var( 'compatible_ids', $compatible_ids );

				set_query_var( 'title', $title );
				set_query_var( 'description', $description );

				set_query_var( 'products_page', $products_page );
				set_query_var( 'total_pages', $total_pages );
				set_query_var( 'page', $page );
				get_template_part( 'template-parts/home/category-content' );
				$result['category_content'] = ob_get_clean();

				$result['meta_title'] = $title . ' - ' . get_bloginfo( 'name' );

			} else {

				ob_start();
				set_query_var( 'page', $page );
				set_query_var( 'products_page', $products_page );
				set_query_var( 'total_pages', $total_pages );
				get_template_part( 'template-parts/home/products-list' );
				$result['products_list'] = ob_get_clean();
			}

			wp_send_json( $result );
			exit;
		}

		function get_single_product() {

			$request = $_REQUEST;
			$result  = array( 'success' => true );

			$parts = parse_url( $request['uri'] );
			$arr = explode('/', $parts['path']);
			$slug = $arr[count( $arr ) - 2];

			$product = get_page_by_path( $slug, OBJECT, 'product' );

			ob_start();
			setup_postdata( $product );
			wc_get_template_part( 'content', 'single-product' );
			$result['content'] = ob_get_clean();

			$result['title'] = $product->post_title . ' - ' . get_bloginfo( 'name' );

			wp_send_json( $result );
			exit;
		}

		function get_popular_product() {
			$request = $_REQUEST;

			if ( empty( $request['time'] ) || empty( $request['type'] ) ) {
				return;
			}

			$product = Statistic::get_popular_product( $request['time'], $request['type'] );

			$response = array(
				'success' => true,
				'product' => ! empty( $product ) ? Helpers::truncate( $product->post_title, 15 ) : __( 'None', TEXTDOMAIN )
			);

			wp_send_json( $response );
			exit;
		}

		function increase_shares_product() {
			$request = $_REQUEST;
			$result_shares = false;
			$result_likes = false;

			if ( ! empty( $request['product'] ) ) {

				$shares = (int) get_post_meta( $request['product'], '_total_shares', true );
				$result_shares = update_post_meta( $request['product'], '_total_shares', ++$shares );
			}

			wp_send_json( array(
				'share' => $result_shares,
				'like' => $result_likes
			) );
			exit;
		}

		function add_custom_fields() { ?>

            <div class="form-field">
                <label for="fontawesome"><?= __( 'FontAwesome', TEXTDOMAIN ); ?></label>
                <input type="text" id="fontawesome" name="fontawesome">
                <p class="description"><?= __( 'Enter font-awesome class', TEXTDOMAIN ); ?></p>
            </div>
		<?php }

		function edit_custom_fields( $term ) {

			$fontawesome = get_term_meta( $term->term_id, 'fontawesome', true ); ?>

            <tr class="form-field">
                <th scope="row" valign="top">
                    <label for="fontawesome"><?= __( 'FontAwesome', TEXTDOMAIN ); ?></label>
                </th>
                <td>
                    <input type="text" id="fontawesome" name="fontawesome" value="<?= $fontawesome; ?>">
                    <p class="description"><?= __( 'Enter font-awesome class', TEXTDOMAIN ); ?></p>
                </td>
            </tr>
		<?php }

		function save_custom_meta( $term_id ) {
			if ( isset( $_POST['fontawesome'] ) ) {
				$class = $_POST['fontawesome'];
				update_term_meta( $term_id, 'fontawesome', $class );
			}
		}

		public static function get_products_by_terms( $category_slug, $compatible_ids = '', $order = '', $search = '' ) {

			$args = array(
				'numberposts' => - 1,
				'post_type'   => 'product'
			);

			$tax_query = array();

			$types = array( 'home', 'best-sellers', 'freebies', 'all', 'search' );

			if ( ! empty( $category_slug ) ) {

				if ( ! in_array( $category_slug, $types ) ) {
					$tax_query[] = array(
						'taxonomy' => 'product_cat',
						'field'    => 'slug',
						'terms'    => $category_slug
					);
				} else if ( $category_slug === 'best-sellers' ) {
					$args['meta_key'] = 'total_sales';
					$args['orderby']  = 'meta_value_num';
					$args['meta_query'] = array(
						array(
							'key'     => '_regular_price',
							'value'   => 0,
							'type'    => 'numeric',
                            'compare' => '>'
						)
					);
				} else if ( $category_slug === 'freebies' ) {
					$args['meta_query'] = array(
						array(
							'key'     => '_regular_price',
							'value'   => 0,
							'type'    => 'numeric'
						)
					);
				}
			}

			if ( ! empty( $search ) ) {
				$args['s'] = $search;
			}

			if ( $order === 'price' ) {
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
				$args['meta_key'] = '_regular_price';
				$args['meta_query'] = array(
					array(
						'key'     => '_regular_price',
						'value'   => 0,
						'type'    => 'numeric',
						'compare' => '>'
					)
				);
			} elseif ( $order === 'price-desc' ) {
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				$args['meta_key'] = '_regular_price';
				$args['meta_query'] = array(
					array(
						'key'     => '_regular_price',
						'value'   => 0,
						'type'    => 'numeric',
						'compare' => '>'
					)
				);
			}

			if ( ! empty( $compatible_ids ) ) {
				$tax_query[]           = array(
					'taxonomy' => 'compatible',
					'field'    => 'id',
					'terms'    => $compatible_ids
				);
				$tax_query['relation'] = 'AND';
			}

			if ( ! empty( $tax_query ) ) {
				$args['tax_query'] = $tax_query;
			}

			return get_posts( $args );
		}

		public static function get_paged_products( $products, $page ) {

			$paged  = ! empty( $page ) ? $page - 1 : 0;
			$offset = $paged * PRODUCTS_PER_PAGE;

			return array_slice( $products, $offset, PRODUCTS_PER_PAGE );
		}

		public static function convert( $product ) {

			$result                     = new stdClass();
			$result->pro_id             = 0;
			$result->title              = '';
			$result->description        = '';
			$result->permalink          = '';
			$result->regular_price      = 0;
			$result->free_product       = '';
			$result->features           = array();
			$result->downloadable_files = array();
			$result->featured_img       = '';
			$result->gallery_img        = array();
			$result->categories         = array();
			$result->compatibles        = array();

			if ( ! is_object( $product ) || empty( $product ) || empty( $product->get_id() ) )
				return $result;

			$product_id = $product->get_id();

			$result->pro_id        = $product_id;
			$result->title         = $product->get_title();
			$result->description   = $product->get_description();
			$result->permalink     = $product->get_permalink();
			$result->regular_price = $product->get_regular_price();
			$result->display_price = '$' . ceil( $result->regular_price );
			$result->features      = get_post_meta( $product_id, '_features', true );

			$result->downloadable_files = get_post_meta( $product_id, '_downloadable_files', true );

			if ( ! $result->downloadable_files )
				$result->downloadable_files = array();

			if ( empty(	$result->regular_price ) ) {
				$items = $product->get_downloads();
				$files = array();

				foreach ( $items as $id => $file ) {
					$files[] = $file->get_file();
				}

				$result->files = $files;
            }

			$result->file_ids = self::get_product_file_ids( $result->downloadable_files, $product_id );

			$result->file_size = self::get_product_file_size( $result->file_ids );

			$result->free_product = get_post_meta( $product_id, '_free_product', true );

			$featured_img = $product->get_image_id() ? $product->get_image_id() : '';

			if ( $featured_img )
				$result->featured_img = wp_get_attachment_url( $featured_img );

			$gallery_img_ids = $product->get_gallery_image_ids();

			if ( ! empty( $gallery_img_ids ) ) {
				foreach ( $gallery_img_ids as $gallery_img_id ) {
					$result->gallery_img[]['image'] = wp_get_attachment_url( $gallery_img_id );
				}
			}

			$pcategories = get_the_terms( $product_id, 'product_cat' );

			if ( ! empty( $pcategories ) ) {
				foreach ( $pcategories as $pkey => $pcategory ) {
					$result->categories_ids[] = $pcategory->term_id;
					$result->categories[]     = $pcategory;
				}
			}

			$pcompatibles = get_the_terms( $product_id, 'compatible' );

			if ( ! empty( $pcompatibles ) ) {

				foreach ( $pcompatibles as $pkey => $pcompatible ) {
					$pcompatible->thumbnail_id  = get_term_meta( $pcompatible->term_id, 'compatible_thumbnail_id', true );
					$pcompatible->thumbnail_url = ! empty( $pcompatibles[ $pkey ]->thumbnail_id ) ?
						wp_get_attachment_url( $pcompatibles[ $pkey ]->thumbnail_id ) : '';

					$result->compatibles_ids[] = $pcompatible->term_id;
					$result->compatibles[]     = $pcompatible;
				}
			}

			$result->vendor_id   = get_post_field( 'post_author', $product_id );
			$result->vendor_name = get_the_author_meta( 'display_name', $result->vendor_id );
			$result->vendor_slug = get_user_meta( $result->vendor_id, 'nickname', true );

			return $result;
		}

		public static function formate_preview_data( $data ) {

			parse_str( $data, $data );

			$result                     = new stdClass();
			$result->pro_id             = 0;
			$result->title              = sanitize_text_field( $data['title'] );
			$result->description        = $data['description'];
			$result->regular_price      = ceil( $data['regular_price'] );
			$result->display_price      = '$' . $result->regular_price;
			$result->free_product       = empty( $data['regular_price'] );

			$result->downloadable_files = array();
			$result->featured_img       = $data['featured_img'];
			$result->gallery_img        = array();
			$result->categories         = array();
			$result->compatibles        = array();

			$result->features           = $data['features'];
			$result->file_ids           = array();

			if ( ! empty( $data['downloadable_files'] ) ) {

				foreach ( $data['downloadable_files'] as $file )
					$result->file_ids[] = $file['file_id'];
			}

			$result->file_size = self::get_product_file_size( $result->file_ids );

			$result->gallery_img = $data['gallery_img'];

			$pcategories = get_terms( array(
				'taxonomy' => 'product_cat',
				'include' => $data['product_cats'],
				'hide_empty' => false
			) );

			if ( ! empty( $pcategories ) ) {
				foreach ( $pcategories as $pkey => $pcategory ) {
					$result->categories_ids[] = $pcategory->term_id;
					$result->categories[]     = $pcategory;
				}
			}

			if ( ! empty( $data['compatibles'] ) ) {

				$pcompatibles = get_terms( array(
					'taxonomy' => 'compatible',
					'include' => $data['compatibles'],
					'hide_empty' => false
				) );

				if ( ! empty( $pcompatibles ) ) {

					foreach ( $pcompatibles as $pkey => $pcompatible ) {
						$pcompatible->thumbnail_id  = get_term_meta( $pcompatible->term_id, 'compatible_thumbnail_id', true );
						$pcompatible->thumbnail_url = ! empty( $pcompatibles[ $pkey ]->thumbnail_id ) ?
							wp_get_attachment_url( $pcompatibles[ $pkey ]->thumbnail_id ) : '';

						$result->compatibles_ids[] = $pcompatible->term_id;
						$result->compatibles[]     = $pcompatible;
					}
				}
			}

			$result->vendor_id   = get_current_user_id();
			$result->vendor_name = get_the_author_meta( 'display_name', $result->vendor_id );
			$result->vendor_slug = get_user_meta( $result->vendor_id, 'nickname', true );

			return $result;
		}

		public static function get_recent_products( $count = false ) {
			$products = get_posts( array(
				'post_type'   => 'product',
				'numberposts' => - 1
			) );

			if ( $count ) {
				return count( $products );
			}

			return $products;
		}

		public static function get_best_seller_products( $count = false ) {
			$products = get_posts( array(
				'post_type'   => 'product',
				'meta_key'    => 'total_sales',
				'orderby'     => 'meta_value_num',
				'numberposts' => - 1,
				'meta_query' => array(
					array(
						'key'     => '_regular_price',
						'value'   => 0,
						'type'    => 'numeric',
						'compare' => '>'
					)
				)
			) );

			if ( $count ) {
				return count( $products );
			}

			return $products;
		}

		public static function get_sales_products( $count = false ) {
			$products = get_posts( array(
				'post_type'   => 'product',
				'numberposts' => - 1,
				'meta_query'  => array(
					array(
						'key'     => '_regular_price',
						'value'   => 0,
						'type'    => 'numeric'
					)
				)
			) );

			if ( $count ) {
				return count( $products );
			}

			return $products;
		}

		public static function get_popular_products( $count = 4 ) {
			$products = get_posts( array(
				'post_type'   => 'product',
				'numberposts' => $count
			) );

			return $products;
		}

		public static function get_product_categories() {
			$categories = get_terms( array(
				'taxonomy'   => 'product_cat',
				'pad_counts' => 1
			) );

			foreach ( $categories as $key => $category ) {
				$fontawesome                     = get_term_meta( $category->term_id, 'fontawesome', true );
				$categories[ $key ]->fontawesome = $fontawesome;
			}

			return $categories;
		}

		public static function get_product_price( $product_id ) {
			return floor( get_post_meta( $product_id, '_regular_price', true ) );
		}

		public static function format_bytes( $bytes, $precision = 2 ) {
			$base     = log( $bytes, 1024 );
			$suffixes = array( '', 'KB', 'MB' );

			return round( pow( 1024, $base - floor( $base ) ), $precision ) . $suffixes[ floor( $base ) ];
		}

		public static function get_product_file_ids( $downloadable_files, $product_id ) {

			$ids = array();

			foreach ( $downloadable_files as $file )
				$ids[] = self::get_attachment_id_from_url( $file['file'], $product_id );

			return $ids;
		}

		public static function get_product_file_size( $file_ids ) {

			$size = 0;

			if ( is_array( $file_ids ) ) {

				foreach ( $file_ids as $file_id )
					$size += filesize( get_attached_file( $file_id ) );
			}

			return self::format_bytes( $size, 0 );
		}

		public static function get_attachment_id_from_url( $url, $product_id ) {

			if ( empty( $url ) ) return 0;

			$id         = 0;
			$upload_dir = wp_upload_dir( null, false );
			$base_url   = $upload_dir['baseurl'] . '/';

			if ( false !== strpos( $url, $base_url ) || false === strpos( $url, '://' ) ) {

				$file = str_replace( $base_url, '', $url );
				$args = array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'fields'      => 'ids',
					'meta_query'  => array(
						'relation' => 'OR',
						array(
							'key'     => '_wp_attached_file',
							'value'   => '^' . $file,
							'compare' => 'REGEXP',
						),
						array(
							'key'     => '_wp_attached_file',
							'value'   => '/' . $file,
							'compare' => 'LIKE',
						),
						array(
							'key'     => '_wc_attachment_source',
							'value'   => '/' . $file,
							'compare' => 'LIKE',
						),
					),
				);
			} else {

				$args = array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'fields'      => 'ids',
					'meta_query'  => array(
						array(
							'value' => $url,
							'key'   => '_wc_attachment_source',
						),
					),
				);
			}

			$ids = get_posts( $args );

			if ( $ids )
				$id = current( $ids );

			if ( ! $id && stristr( $url, '://' ) ) {
				$upload = wc_rest_upload_image_from_url( $url );

				if ( is_wp_error( $upload ) ) {
					throw new Exception( $upload->get_error_message(), 400 );
				}

				$id = wc_rest_set_uploaded_image_as_attachment( $upload, $product_id );

				if ( ! wp_attachment_is_image( $id ) )
					throw new Exception( sprintf( __( 'Not able to attach "%s".', 'woocommerce' ), $url ), 400 );

				update_post_meta( $id, '_wc_attachment_source', $url );
			}

			if ( ! $id )
				throw new Exception( sprintf( __( 'Unable to use image "%s".', 'woocommerce' ), $url ), 400 );

			return $id;
		}
	}
}