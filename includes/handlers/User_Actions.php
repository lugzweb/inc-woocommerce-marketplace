<?php

if ( ! class_exists( 'Users_Actions' ) ) {

	class User_Actions {

	    protected $mail;

		public function __construct() {

			$this->mail = Mailing::get_instance();

            add_action( 'wp_ajax_nopriv_sync-user', [ $this, 'ajax_handle' ] );
			add_action( 'wp_ajax_sync-user', [ $this, 'ajax_handle' ] );
		}

        public function ajax_handle() {
			$request = $_REQUEST;

			switch ( $request['method'] ) {
				case 'login':
					$result = self::login( $request );
					break;
				case 'register':
					$result = $this->register( $request );
					break;
				case 'forgot':
					$result = $this->forgot( $request );
					break;
				case 'reset_pass':
					$result = $this->reset_pass( $request );
					break;
                case 'save-settings-vendor':
                    $result = Settings::save_settings_vendor( $request );
                    break;
				case 'save-settings-customer':
					$result = Settings::save_settings_customer( $request );
					break;
				default:
					$result = new WP_Error('some-bug', 'Bad request!');
					break;
			}

	        if ( $result && ! is_wp_error( $result ) ) {
		        $response = array(
			        'success' => true,
			        'msg'     => is_object( $result ) && isset( $result->msg ) ? $result->msg : $result['msg']
		        );
	        } else {
		        $response = array(
			        'success' => false,
			        'msg'     => $result->get_error_message()
		        );
	        }

	        if ( ! empty( $result->redirect ) )
	            $response['redirect'] = $result->redirect;

			wp_send_json($response);
	        exit;
        }

		public static function login( $request ) {

			$user = get_user_by( 'login', $request['user_login'] );

			if ( is_wp_error( $user ) || ! $user ) {
				$user = get_user_by( 'email', $request['user_login'] );
			}

			if ( ! $user ) {
				return new WP_Error( 'login_failed', __( 'Invalid email or password. Please try again!', TEXTDOMAIN ) );
			}

			$user_login = $user->user_login;

			$creds                  = array();
			$creds['user_login']    = $user_login;
			$creds['user_password'] = $request['user_pass'];

			if ( isset($request['remember']) && $request['remember'] == 1 ) {
				$creds['remember'] = true;
			}

            $result = wp_signon( $creds, true );

			if ( $result && ! is_wp_error( $result ) ) {

				wp_set_current_user( $result->ID );

			} else {

				if ( $result->get_error_code() == 'incorrect_password' ) {
					return new WP_Error( 'incorrect_password', __( 'Invalid email or password. Please try again!', TEXTDOMAIN ) );
				}
			}

			if ( ! isset( $result->msg ) ) {
				$result->msg = __( 'You have signed in successfully!', TEXTDOMAIN );

				if( in_array('dc_vendor', $result->roles) )
				    $result->redirect = site_url('dashboard/');
			}

			return $result;
		}

		protected function register( $request ) {
		    global $WCMp;

			if ( empty( $request['first_name'] ) ) {
				return new WP_Error( 'first_name_invalid', __( 'First name is invalid.', TEXTDOMAIN ) );
			}

			if ( empty( $request['last_name'] ) ) {
				return new WP_Error( 'last_name_invalid', __( 'Last name is invalid.', TEXTDOMAIN ) );
			}

			if ( empty( $request['user_email'] ) || !is_email( $request['user_email'] ) ) {
				return new WP_Error( 'email_invalid', __( 'Email field is invalid.', TEXTDOMAIN ) );
			}
			else {
				$arr_login = explode('@', $request['user_email'] );

				$request['user_login'] = $arr_login[0];
				$request['user_login'] .= '_' . explode('.', $arr_login[1])[0];
			}

			if ( empty( $request['user_pass'] ) ) {
				return new WP_Error( 'pass_invalid', __( 'Password field is required.', TEXTDOMAIN ) );
			}

			if ( empty( $request['repeat_pass'] )  && $request['user_pass'] != $request['repeat_pass'] ) {
				return new WP_Error( 'pass_invalid', __( 'Repeat Passwords mismatch.', TEXTDOMAIN ) );
			}

			if ( isset( $request['role'] ) ) {
				if ( strtolower( $request['role'] ) == 'administrator' || strtolower( $request['role'] ) == 'editor' ) {
					return new WP_Error( 'user_role_error', __( 'You can\'t create an administrator account.', TEXTDOMAIN ) );
				}
			}

			$is_approve_manually = $WCMp->vendor_caps->vendor_general_settings('approve_vendor_manually');

			if ( $request['role'] === 'seller' ) {
				$request['role'] = $is_approve_manually ? 'dc_pending_vendor' : 'dc_vendor';
			}
			else {
				$request['role'] = 'customer';
			}

			$request['display_name'] = $request['first_name'] . ' ' . $request['last_name'];

			$result = wp_insert_user( $request );

			if ( $result != false && ! is_wp_error( $result ) ) {

				$result = self::login( $request );

				if ( $request['role'] === 'dc_vendor' || $request['role'] === 'dc_pending_vendor' ) {
					$vendor = get_wcmp_vendor( $result->ID );
					$vendor->update_page_title( $request['display_name'] );
                }

				if( ! empty( $result->ID ) ) {
					$this->mail->customer_new_account( $result->ID );
				}
			}

			$result->msg = __( 'You have registered successfully. Please check your mailbox to activate your account.', TEXTDOMAIN );

			return $result;
		}

		protected function forgot( $request ) {
			global $wpdb;

			$errors = new WP_Error();

			if ( empty( $request['user_email'] ) ) {
				$errors->add( 'empty_username', __( 'ERROR: Enter email address.', TEXTDOMAIN ) );
			} else if ( strpos( $request['user_email'], '@' ) ) {
				$user_data = get_user_by( 'email', trim( $request['user_email'] ) );

				if ( empty( $user_data ) ) {
					$errors->add( 'invalid_email', __( 'Please provide your correct email address.', TEXTDOMAIN ) );
				}
			}

			if ( $errors->get_error_code() ) {
				return $errors;
			}

			if ( empty( $user_data ) ) {
				$errors->add( 'invalidcombo', __( 'ERROR: Invalid email address.', TEXTDOMAIN ) );

				return $errors;
			}

			$user_login = $user_data->user_login;

			$key = $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM $wpdb->users WHERE user_login = %s", $user_login ) );

			if ( empty( $key ) ) {

				$key = wp_generate_password( 20, false );

				$wpdb->update( $wpdb->users, array(
					'user_activation_key' => $key
				), array(
					'user_login' => $user_login
				) );
			}

            $this->mail->customer_reset_password( $user_data->ID, $key );

			return array(
				'success' => true,
				'msg'     => __( 'Please check your mailbox to get password reset link.', TEXTDOMAIN )
			);
		}

		protected function reset_pass( $request ) {
			try {
				if ( empty( $request['user_login'] ) ) {
					throw new Exception( __( 'Email is empty.', TEXTDOMAIN ) );
				}
				if ( empty( $request['user_key'] ) ) {
					throw new Exception( __( 'Invalid Activation Key', TEXTDOMAIN ) );
				}
				if ( empty( $request['new_password'] ) ) {
					throw new Exception( __( 'Please enter your new password', TEXTDOMAIN ) );
				}

				$request['user_pass'] = $request['new_password'];

				$validate_result = $this->check_activation_key( $request['user_key'], $request['user_login'] );

				if ( is_wp_error( $validate_result ) ) {
					return $validate_result;
				}

				$user = get_user_by( 'login', $request['user_login'] );

				wp_set_password( $request['user_pass'], $user->ID );

				wp_password_change_notification( $user );

				return array(
					'success' => true,
					'msg'     => __( 'Your password have updated successfull. You can login with your new password now.', TEXTDOMAIN )
				);

			} catch ( Exception $e ) {
				return new WP_Error( 'reset_error', $e->getMessage() );
			}
		}

		public static function is_activate( $user_id ) {
			return get_user_meta( $user_id, 'register_status', true ) == "unconfirm" ? false : true;
		}

		public static function confirm( $key ) {
			global $de_confirm;

			$user = get_users( array(
				'meta_key'   => 'key_confirm',
				'meta_value' => $key
			) );

			if ( self::is_activate( $user[0]->ID ) )
				return false;

			$de_confirm = update_user_meta( $user[0]->ID, 'register_status', '' );

			if ( $de_confirm ) {
				wp_clear_auth_cookie();
				wp_set_current_user( $user[0]->ID );
				wp_set_auth_cookie( $user[0]->ID );
			}

			return $user[0]->ID;
		}

		public static function check_confirmation_link() {

			if ( ! is_admin() ) {

				if ( isset( $_GET['act'] ) && $_GET['act'] == "confirm" && $_GET['key'] ) {

					$user_id = self::confirm( $_GET['key'] );

					if ( $user_id ) {

						$mail = Mailing::get_instance();

						if ( Helpers::is_vendor( $user_id ) )
							$mail->vendor_success_confirmed( $user_id );
						else
						    $mail->customer_success_confirmed( $user_id ); ?>

						<script type="text/javascript">
                            if ( typeof showFlash === 'function' ) {
                                showFlash('<?= __( 'Your account has been confirmed successfully!', TEXTDOMAIN ); ?>', true);
                                jQuery('.flash-container.not-active').addClass('hide');
                            }
						</script>
					<?php }
				}
			}
		}

		public function check_activation_key( $key, $login ) {
			global $wpdb;

			$key = preg_replace( '/[^a-z0-9]/i', '', $key );

			if ( empty( $key ) || ! is_string( $key ) ) {
				return new WP_Error( 'invalid_key', __( 'Invalid Activation Key.', TEXTDOMAIN ) );
			}

			if ( empty( $login ) || ! is_string( $login ) ) {
				return new WP_Error( 'invalid_key', __( 'Invalid User Name', TEXTDOMAIN ) );
			}

			$user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE user_activation_key = %s AND user_login = %s", esc_sql( $key ), esc_sql( $login ) ) );

			if ( empty( $user ) ) {
				return new WP_Error( 'invalid_key', __( 'Invalid Activation Key.', TEXTDOMAIN ) );
			}

			return $user;
		}

		static function check_reset_pass( $key, $login ) {
		    $user_action = new User_Actions();

		    return !is_wp_error( $user_action->check_activation_key( $key, $login ) );
        }
	}
}