<?php

if ( ! class_exists( 'Compatible' ) ) {

	class Compatible {

		public static function convert( $compatible ) {

			$result = new stdClass();

			$result->id = $compatible->term_id;
			$result->title = $compatible->name;
			$result->slug = $compatible->slug;
			$result->description = $compatible->description;
			$result->count = $compatible->count;

			$thumbnail_id  = get_term_meta( $compatible->term_id, 'compatible_thumbnail_id', true );

			$result->thumbnail_url = ! empty( $thumbnail_id ) ? wp_get_attachment_url( $thumbnail_id ) : '';

			return $result;
		}

		public static function get_compatibles_by_category( $slug, $search = '' ) {

			$products = Product::get_products_by_terms( $slug, '', '', $search );

			$products_ids = wp_list_pluck( $products, 'ID' );

			$filter_compatibles = wp_get_object_terms( $products_ids, 'compatible' );

			$results = array();

			foreach ( $filter_compatibles as $compatible )
				$results[] = Compatible::convert( $compatible );

			return $results;
		}

		public static function get_current_compatibles() {

			if ( !empty( $_GET['compatibles'] ) )
				return explode( ',', $_GET['compatibles'] );

			return array();
		}
	}
}