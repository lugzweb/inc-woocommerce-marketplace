<?php

class Payouts {

	public function __construct() {
		add_action( 'wp_ajax_nopriv_send-payout', [ $this, 'send_payout' ] );
		add_action( 'wp_ajax_send-payout', [ $this, 'send_payout' ] );
	}

	public function send_payout() {
		global $WCMp;

		$request = $_REQUEST;

		try {

			if ( isset( $request['vendor_get_paid'] ) )
				throw new Exception( __( 'Wrong request', TEXTDOMAIN ) );

			$vendor = get_wcmp_vendor( get_current_vendor_id() );

			$commissions = isset($request['commissions']) ? $request['commissions'] : array();

			$payment_method = get_user_meta($vendor->id, '_vendor_payment_mode', true);

			if ( empty( $commissions ) )
				throw new Exception( __( 'Not find commissions to paid', TEXTDOMAIN ) );

			if ( empty( $payment_method ) )
				throw new Exception( __( 'No payment method has been selected for commission withdrawal', TEXTDOMAIN ) );

			if ( !array_key_exists( $payment_method, $WCMp->payment_gateway->payment_gateways ) )
				throw new Exception( __( 'Invalid payment method', TEXTDOMAIN ) );

			$response = $WCMp->payment_gateway->payment_gateways[$payment_method]->process_payment($vendor, $commissions, 'manual');

			if ( $response && $response['transaction_id'] ) {

				$redirect = wcmp_get_vendor_dashboard_endpoint_url( get_wcmp_vendor_settings('wcmp_transaction_details_endpoint', 'vendor', 'general', 'transaction-details'), $response['transaction_id'] );

				$result = array(
					'success' => true,
					'message' => __( 'Successfully send your withdrawal amount', TEXTDOMAIN ),
					'redirect' => $redirect
				);

			} else {
				throw new Exception( __( 'Oops! Something went wrong please try again later', TEXTDOMAIN ) );
			}

		} catch (Exception $e) {

			$result = array(
				'error' => true,
				'message' => $e->getMessage()
			);
		}

		wp_send_json( $result );
		exit;
	}

	public static function get_payouts_list() {
		global $WCMp;

		if ( is_user_logged_in() && is_user_wcmp_vendor( get_current_vendor_id() ) ) {

			$vendor = get_wcmp_vendor( get_current_vendor_id() );

			$vendor = apply_filters('wcmp_transaction_vendor', $vendor);

			$transaction_details = $WCMp->transaction->get_transactions( $vendor->term_id, false, false, array( 'wcmp_processing', 'wcmp_completed' ) );

			$data = array();

			if ( ! empty( $transaction_details ) ) {

				foreach ( $transaction_details as $transaction_id => $detail ) {

					$amount = get_post_meta( $transaction_id, 'amount', true );
					$gateway_charge = get_post_meta( $transaction_id, 'gateway_charge', true );
					$transfer_charge = get_post_meta( $transaction_id, 'transfer_charge', true );
					$transaction_amt = $amount - $transfer_charge - $gateway_charge;

					$row = array();
					$row['date'] = get_the_date( wc_date_format(), $transaction_id );
					$row['transaction'] = wc_price( $transaction_amt );
					$data[] = $row;
				}
			}

			return $data;
		}
	}

	public static function get_unpaid_payouts() {

		$vendor = get_wcmp_vendor( get_current_vendor_id() );

		if ($vendor) {

			$meta_query['meta_query'] = array(
				array(
					'key'     => '_paid_status',
					'value'   => 'unpaid',
					'compare' => '='
				),
				array(
					'key'     => '_commission_vendor',
					'value'   => absint( $vendor->term_id ),
					'compare' => '='
				)
			);

			return $vendor->get_orders( false, false, $meta_query );
		}

		return array();
	}

	public static function get_total_payout_sum() {
		global $WCMp;

		$unpaid_payouts = self::get_unpaid_payouts();

		$vendor = get_wcmp_vendor( get_current_vendor_id() );

		$total_vendor_due = 0;

		if ( count( $unpaid_payouts ) > 0 ) {
			if ( isset( $WCMp->vendor_caps->payment_cap['wcmp_disbursal_mode_vendor'] ) &&
			     $WCMp->vendor_caps->payment_cap['wcmp_disbursal_mode_vendor'] == 'Enable' ) {
				$total_vendor_due = $vendor->wcmp_vendor_get_total_amount_due();
			}
		}

		return $total_vendor_due;
	}

	public static function get_unpaid_commissions() {

		$vendor = get_wcmp_vendor( get_current_vendor_id() );

		$meta_query['meta_query'] = array(
			array(
				'key' => '_paid_status',
				'value' => 'unpaid',
				'compare' => '='
			),
			array(
				'key' => '_commission_vendor',
				'value' => absint( $vendor->term_id ),
				'compare' => '='
			)
		);

		$vendor_unpaid_orders = $vendor->get_orders( false, false, $meta_query );

		$data = array();

		if ( $vendor_unpaid_orders ) {

			foreach ( $vendor_unpaid_orders as $commission_id => $order_id ) {

				$order = wc_get_order( $order_id );

				$vendor_share = get_wcmp_vendor_order_amount( array( 'vendor_id' => $vendor->id, 'order_id' => $order->get_id() ) );

				if ( !isset( $vendor_share['total'] ) )
					$vendor_share['total'] = 0;

				if( is_commission_requested_for_withdrawals( $commission_id ) )
					continue;

				$row = array();
				$row ['commission_id'] = $commission_id;
				$row ['order_id'] = $order->get_id();
				$row ['total'] = $vendor_share['total'];
				$data[] = $row;
			}
		}

		return $data;
	}
}