<?php

class Checkout {

	protected $mail;

	public function __construct() {

		$this->mail = Mailing::get_instance();

		add_action( 'wp_ajax_nopriv_process-payment', [ $this, 'process_payment' ] );
		add_action( 'wp_ajax_process-payment', [ $this, 'process_payment' ] );

		add_filter( 'woocommerce_checkout_fields' , [ $this, 'custom_override_checkout_fields' ] );
		add_filter( 'woocommerce_paypal_args', [ $this, 'change_email_recipient' ], 1, 2 );
	}

	public function process_payment() {

		$request = $_REQUEST;

		try {

			$_POST = $this->setup_post_data( $request );

			$checkout = new WC_Checkout();
			$checkout->process_checkout();

		} catch ( Exception $e ) {

			wp_send_json_error( array(
				'msg' => $e->getMessage()
			) );
			exit;
		}
	}

	protected function setup_post_data( $request ) {
		$type_payment = $request['method'];

		if ( empty( $request['method'] ) )
			return new WP_Error( 'empty_type_payment', __( 'Empty type of payment.', TEXTDOMAIN ) );

		if ( empty( $request['full_name'] ) )
			return new WP_Error( 'full_name_invalid', __( 'Full name is invalid.', TEXTDOMAIN ) );

		if ( empty( $request['user_email'] ) || ! is_email( $request['user_email'] ) )
			return new WP_Error( 'email_invalid', __( 'Email field is invalid.', TEXTDOMAIN ) );

		if ( $type_payment === 'paypal' && ( empty( $request['paypal_email'] ) || ! is_email( $request['paypal_email'] ) ) )
			return new WP_Error( 'email_invalid', __( 'PayPal email is invalid.', TEXTDOMAIN ) );

		if ( $type_payment === 'stripe' && empty( $request['stripe_source'] ) )
			return new WP_Error( 'empty_source', __( 'Empty credit card information.', TEXTDOMAIN ) );

		$full_name = explode( ' ', $request['full_name'], 2 );

		return array(
			'billing_first_name' => $full_name[0],
			'billing_last_name' =>  $full_name[1],
			'billing_email' => $request['user_email'],
			'account_password' => '',
			'payment_method' => $request['method'],
			'stripe_source' => $request['stripe_source'],
			'_wpnonce' =>  $request['_wpnonce']
		);
	}

	function custom_override_checkout_fields( $fields ) {
		$fields['billing']['billing_last_name']['required'] = false;

		unset($fields['billing']['billing_company']);
		unset($fields['billing']['billing_address_1']);
		unset($fields['billing']['billing_address_2']);
		unset($fields['billing']['billing_city']);
		unset($fields['billing']['billing_postcode']);
		unset($fields['billing']['billing_country']);
		unset($fields['billing']['billing_state']);
		unset($fields['billing']['billing_phone']);
		unset($fields['order']['order_comments']);

		return $fields;
	}

	function change_email_recipient( $paypal_args ) {
		$request = $_REQUEST;
		$type_payment = $request['method'];

		if ( $type_payment === 'paypal' && !empty( $request['paypal_email'] ) && is_email( $request['paypal_email'] ) ) {
			$paypal_args['email'] = $request['paypal_email'];
		}

		return $paypal_args;
	}
}