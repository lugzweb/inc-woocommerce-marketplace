<?php

if( ! class_exists( 'Settings' ) ) {

	class Settings {

		public static function save_settings_vendor( $request ) {

			$user_id = get_current_user_id();

			if ( !User_Actions::is_activate( $user_id ) )
				return new WP_Error( 'not_active', __( 'Please confirm your account', TEXTDOMAIN ) );

			$vendor = get_wcmp_vendor( $user_id );

			$fields = [
				'first_name', 'last_name', 'vendor_page_title', 'vendor_description',
				'vendor_paypal_email', 'vendor_image', 'current_pass', 'new_pass'
			];

			foreach ( $fields as $fieldkey ) {

				if ( isset( $request[ $fieldkey ] ) ) {

					if ( $fieldkey == 'current_pass' && ! empty( $request[ $fieldkey ] ) ) {

						if ( empty( $request['new_pass'] ) ) {
							return new WP_Error( 'new_pass_error', __( 'Insert new password', TEXTDOMAIN ) );
						}

						if ( strlen( trim( $request['new_pass'] ) ) < 6 ) {
							return new WP_Error( 'new_pass_error', __( 'New password must contain min 6 symbols', TEXTDOMAIN ) );
						}

						if ( ! self::check_password( $user_id, $request[ $fieldkey ] ) ) {
							return new WP_Error( 'wrong_pass', __( 'Old password does not match!', TEXTDOMAIN ) );
						}

						wp_update_user( array( 'ID' => $user_id, 'user_pass' => $request['new_pass'] ) );
					}

					if ( in_array( $fieldkey, [ 'first_name', 'last_name' ] ) ) {
						wp_update_user( array( 'ID' => $user_id, $fieldkey => $request[ $fieldkey ] ) );
					}

					if ( $fieldkey === 'vendor_paypal_email' ) {

						if ( !empty( $request[ $fieldkey ] ) && empty( get_user_meta( $user_id, '_' . $fieldkey, true ) ) ) {
							Mailing::get_instance()->vendor_add_paypal( $user_id );
						}

						update_user_meta( $user_id, '_' . $fieldkey, $request[ $fieldkey ] );
						update_user_meta( $user_id, '_vendor_payment_mode', 'paypal_masspay' );
					}

					if ( in_array( $fieldkey, [ 'vendor_description', 'vendor_image' ] ) ) {
						update_user_meta( $user_id, '_' . $fieldkey, $request[ $fieldkey ] );
					}

					if ( $fieldkey == 'vendor_page_title' && empty( $request[ $fieldkey ] ) ) {
						return new WP_Error( 'display_name_error', __( 'Display name can not be empty', TEXTDOMAIN ) );
					}

					if ( $fieldkey == 'vendor_page_title' ) {
						if ( ! $vendor->update_page_title( wc_clean( $request[ $fieldkey ] ) ) ) {
							return new WP_Error( 'display_name_error', __( 'Display name update error', TEXTDOMAIN ) );
						} else {
							wp_update_user( array( 'ID' => $user_id, 'display_name' => $request[ $fieldkey ] ) );
						}
					}
				}
			}

			return ['msg' => 'Your account updated successfully'];
		}

		public static function save_settings_customer( $request ) {

			$user_id = get_current_user_id();

			if ( !User_Actions::is_activate( $user_id ) )
				return new WP_Error( 'not_active', __( 'Please confirm your account', TEXTDOMAIN ) );

			$fields = [
				'first_name', 'last_name', 'display_name', 'current_pass', 'new_pass', 'user_image'
			];

			foreach ( $fields as $fieldkey ) {

				if ( isset( $request[ $fieldkey ] ) ) {

					if ( $fieldkey == 'current_pass' && ! empty( $request[ $fieldkey ] ) ) {

						if ( empty( $request['new_pass'] ) ) {
							return new WP_Error( 'new_pass_error', __( 'Insert new password', TEXTDOMAIN ) );
						}
						else if ( strlen( trim( $request['new_pass'] ) ) < 6 ) {
							return new WP_Error( 'new_pass_error', __( 'New password must contain min 6 symbols', TEXTDOMAIN ) );
						}
						else if( ! self::check_password( $user_id, $request[ $fieldkey ] ) ) {
							return new WP_Error( 'wrong_pass', __( 'Old password does not match!', TEXTDOMAIN ) );
						} else {
							wp_update_user( array( 'ID' => $user_id, 'user_pass' => $request[ 'new_pass' ] ) );
						}
					}

					if ( in_array( $fieldkey, [ 'first_name', 'last_name' ] ) ) {
						wp_update_user( array( 'ID' => $user_id, $fieldkey => $request[ $fieldkey ] ) );
					}

					if ( $fieldkey == 'user_image' ) {
						update_user_meta( $user_id, '_' . $fieldkey, $request[$fieldkey] );
					}

					if ( $fieldkey == 'display_name' ) {

						if ( empty( $request[ $fieldkey ] ) )
							return new WP_Error( 'display_name_error', __( 'Display name can not be empty', TEXTDOMAIN ) );
						else
							wp_update_user( array( 'ID' => $user_id, 'display_name' => $request[ $fieldkey ] ) );
					}
				}
			}

			return ['msg' => 'Your account updated successfully'];
		}

		public static function check_password( $user_id, $pass ) {
			global $current_user;

			if ( (int) $user_id !== $current_user->ID && ! current_user_can( 'remove_users' ) ) {
				return new WP_Error( 'ae_permission_denied', __( 'You cannot change other user password', TEXTDOMAIN ) );
			}

			$old_pass = $pass;
			$user = get_user_by( 'login', $current_user->user_login );

			return ( $user && wp_check_password( $old_pass, $user->data->user_pass, $user->ID ) );
		}
	}
}