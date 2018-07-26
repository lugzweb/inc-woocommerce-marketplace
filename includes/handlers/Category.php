<?php

if ( ! class_exists( 'Category' ) ) {

	class Category {

		public static function get_current_category_slug( $url = '' ) {

			if ( empty( $url ) )
				$current_url = Helpers::get_current_url();
			else
				$current_url = $url;

			$slug = '';
			$parse = parse_url( $current_url );
			$query = array();

			if ( !empty( $parse['query'] ) )
				parse_str($parse['query'], $query);

			if ( array_key_exists( 's', $query ) ) {

				$slug = 'search';
			} else if ( is_front_page() || site_url('/') === $current_url ) {

				$slug = 'home';
			} else if ( strpos( $current_url, site_url('best-sellers/') ) !== false ) {

				$slug = 'best-sellers';
			} else if ( strpos( $current_url, site_url('freebies/') ) !== false ) {

				$slug = 'freebies';
			} else if ( strpos( $current_url, site_url('category/all/') ) !== false ) {

				$slug = 'all';
			} else if ( is_tax( 'product_cat' ) ) {

				$slug = get_query_var( 'product_cat' );
			} else if ( strpos( $current_url, site_url('category/') ) !== false ) {

				$arr = explode( '/', $parse['path'] );
				return $arr[count($arr) - 2];
			}

			return $slug;
		}

		public static function get_category_title( $slug = '', $search = '' ) {

			if ( empty( $slug ) )
				$slug = Category::get_current_category_slug();

			$search = empty( get_query_var( 's' ) ) ? $search : get_query_var( 's' );
			$custom_title = '';

			if ( $slug === 'home' || $slug === 'freebies' )
				$custom_title = get_option( $slug . '_title' );
			else if ( $slug === 'best-sellers' )
				$custom_title = get_option( 'best_sellers_title' );
			else if ( $slug === 'all' )
				$custom_title = get_option( 'category_all_title' );
			else if ( $slug === 'search' )
				$custom_title = str_replace('%value%', $search, get_option( 'search_title' ) );
			else if ( ! empty( $slug ) ) {
				$category = get_term_by( 'slug', $slug, 'product_cat' );
				$custom_title = $category->name;
			}

			if ( !empty( $custom_title ) )
				return $custom_title;

			return '';
		}

		public static function get_category_desc( $slug = '', $search = '' ) {

			if ( empty( $slug ) )
				$slug = self::get_current_category_slug();

			$desc = '';
			$search = empty( get_query_var( 's' ) ) ? $search : get_query_var( 's' );

			if ( $slug === 'home' || $slug === 'freebies' )
				$desc = get_option( $slug . '_desc' );
			else if ( $slug === 'best-sellers' )
				$desc = get_option( 'best_sellers_desc' );
			else if ( $slug === 'all' )
				$desc = get_option( 'category_all_desc' );
			else if ( $slug === 'search' )
				$desc = str_replace('%value%', $search, get_option( 'search_desc' ) );
			else if ( ! empty( $slug ) ) {
				$category = get_term_by( 'slug', $slug, 'product_cat' );
				$desc = $category->description;
			}

			return $desc;
		}
	}
}