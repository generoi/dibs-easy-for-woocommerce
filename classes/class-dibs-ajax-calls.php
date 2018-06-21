<?php

class DIBS_Ajax_Calls {
	public $private_key;

	function __construct() {
		add_action( 'wp_ajax_create_paymentID', array( $this, 'create_payment_id' ) );
		add_action( 'wp_ajax_nopriv_create_paymentID', array( $this, 'create_payment_id' ) );
		add_action( 'wp_ajax_dibs_update_checkout', array( $this, 'update_checkout' ) );
		add_action( 'wp_ajax_nopriv_dibs_update_checkout', array( $this, 'update_checkout' ) );
		add_action( 'wp_ajax_payment_success', array( $this, 'get_order_data' ) );
		add_action( 'wp_ajax_nopriv_payment_success', array( $this, 'get_order_data' ) );
		add_action( 'wp_ajax_get_options', array( $this, 'get_options' ) );
		add_action( 'wp_ajax_nopriv_get_options', array( $this, 'get_options' ) );

		// Ajax to add order notes as a session for the customer
		add_action( 'wp_ajax_dibs_customer_order_note', array( $this, 'dibs_add_customer_order_note' ) );
		add_action( 'wp_ajax_nopriv_dibs_customer_order_note', array( $this, 'dibs_add_customer_order_note' ) );

		// Ajax to change payment method
		add_action( 'wp_ajax_dibs_change_payment_method', array( $this, 'change_payment_method' ) );
		add_action( 'wp_ajax_nopriv_dibs_change_payment_method', array( $this, 'change_payment_method' ) );

		// On checkout form submission error
		add_action( 'wp_ajax_dibs_on_checkout_error', array( $this, 'ajax_on_checkout_error' ) );
		add_action( 'wp_ajax_nopriv_dibs_on_checkout_error', array( $this, 'ajax_on_checkout_error' ) );

		$dibs_settings = get_option( 'woocommerce_dibs_easy_settings' );
		$this->testmode = 'yes' === $dibs_settings['test_mode'];
		$this->private_key = $this->testmode ? $dibs_settings['dibs_test_checkout_key'] : $dibs_settings['dibs_checkout_key'];
	}

	public function update_checkout() {
		WC()->cart->calculate_shipping();
		WC()->cart->calculate_fees();
		WC()->cart->calculate_totals();
		
		$request = new DIBS_Requests();
		$dibs_payment_id = $request->get_payment_id();
		$return = array();
		
		if( is_wp_error( $dibs_payment_id ) ) {
			wc_dibs_unset_sessions();
			$return['redirect_url'] = wc_get_checkout_url();
			wp_send_json_error( $return );
			wp_die();
		} else {
			
			$return['paymentId'] = $dibs_payment_id;
			$return['language'] = wc_dibs_get_locale();
			$return['checkoutKey'] = $this->private_key;
			wp_send_json_success( $return );
			wp_die();
		}

	}

	public function create_payment_id() {
		
		// Check if we should create a new payment ID or use an existing one
		if( isset( $_POST['dibs_payment_id'] ) && !empty( $_POST['dibs_payment_id'] ) ) {

			// This is a return from 3DSecure. Use the current payment ID
			$payment_id = $_POST['dibs_payment_id'];
			$request = new stdClass;
			$request->paymentId = $payment_id;

		} else {

			// This is a new order
			// Set DIBS Easy as the chosen payment method
			WC()->session->set( 'chosen_payment_method', 'dibs_easy' );
			
			// Create an empty WooCommerce order and get order id if one is not made already
			if ( WC()->session->get( 'dibs_incomplete_order' ) === null ) {
				$order    = wc_create_order();
				$order_id = $order->get_id();
				// Set the order id as a session variable
				WC()->session->set( 'dibs_incomplete_order', $order_id );
				$order->update_status( 'dibs-incomplete' );
				$order->save();
			} else {
				$order_id = WC()->session->get( 'dibs_incomplete_order' );
				$order = wc_get_order( $order_id );
				$order->update_status( 'dibs-incomplete' );
				$order->save();
			}

			$get_cart = new DIBS_Get_WC_Cart();

			// Get the datastring
			$datastring = $get_cart->create_cart( $order_id );
			// Make the request
			$request = new DIBS_Requests();
			$endpoint_sufix = 'payments/';
			$request = $request->make_request( 'POST', $datastring, $endpoint_sufix );

		}

		

		if ( null != $request ) { // If array has a return
			if ( array_key_exists( 'paymentId', $request ) ) {
				// Create the return array
				$return               = array();
				$return['privateKey'] = $this->private_key;
				
				switch ( get_locale() ) {
					case 'sv_SE' :
						$language = 'sv-SE';
						break;
					case 'nb_NO' :
					case 'nn_NO' :
						$language = 'nb-NO';
						break;
					case 'da_DK' :
						$language = 'da-DK';
						break;
					default :
						$language = 'en-GB';
				}
				
				$return['language']  = $language;
				$return['paymentId'] = $request;
				wp_send_json_success( $return );
				wp_die();
				
			} elseif ( array_key_exists( 'errors', $request ) ) {
				
				if ( array_key_exists( 'amount', $request->errors ) && 'Amount dosent match sum of orderitems' === $request->errors->amount[0] ) {
					$message = 'DIBS failed to create a Payment ID : ' . $request->errors->amount[0];
					wp_send_json_error( $this->fail_ajax_call( $order, $message ) );
					wp_die();
				} else {
					$message = 'DIBS request error: ' . print_r($request->errors, true);
					wp_send_json_error( $message );
					wp_die();
				}
			}
		} else { // If return array equals null
			wp_send_json_error( $this->fail_ajax_call( $order ) );
			wp_die();
		}
	}

	public function get_order_data() {
		
		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}
		
		$payment_id = $_POST['paymentId'];
		// Set the endpoint sufix
		$endpoint_sufix = 'payments/' . $payment_id;

		// Make the request
		$request 	= new DIBS_Requests();
		$response 	= $request->make_request( 'GET', '', $endpoint_sufix );
		$order_id 	= WC()->session->get( 'dibs_incomplete_order' );

		$this->prepare_local_order_before_form_processing( $order_id, $payment_id );
		
		if ( is_wp_error( $response ) || empty( $response ) ) {
			// Something went wrong
			if( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
			} else {
				$message = 'Empty response from DIBS.';
			}
			$order = wc_get_order( $order_id );
			$order->add_order_note( sprintf( __( 'Something went wrong when connecting to DIBS during checkout completion. Error message: %s. Please check your DIBS backoffice to control the order.', 'dibs-easy-for-woocommerce' ), $message ) );
			wp_send_json_error( $message );
			wp_die();
		} else {
			// All good with the request
			// Convert country code from 3 to 2 letters 
			if( $response->payment->consumer->shippingAddress->country ) {
				$response->payment->consumer->shippingAddress->country = dibs_get_iso_2_country( $response->payment->consumer->shippingAddress->country );
			}
			
			// Store the order data in a sesstion. We might need it if form processing in Woo fails
			WC()->session->set( 'dibs_order_data', $response );

			$this->prepare_cart_before_form_processing( $response->payment->consumer->shippingAddress->country );
			
			wp_send_json_success( $response );
			wp_die();
		}
		
	}

	// Change payment method
	public function change_payment_method() {
		WC()->cart->calculate_shipping();
		WC()->cart->calculate_fees();
		WC()->cart->calculate_totals();
		
		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		if ( 'false' === $_POST['dibs_easy'] ) {
			// Set chosen payment method to first gateway that is not DIBS Easy.
			$first_gateway = reset( $available_gateways );
			if ( 'dibs_easy' !== $first_gateway->id ) {
				WC()->session->set( 'chosen_payment_method', $first_gateway->id );
			} else {
				$second_gateway = next( $available_gateways );
				WC()->session->set( 'chosen_payment_method', $second_gateway->id );
			}
		} else {
			WC()->session->set( 'chosen_payment_method', 'dibs_easy' );
		}
		WC()->payment_gateways()->set_current_gateway( $available_gateways );
		
		$redirect = wc_get_checkout_url();
		$data = array(
			'redirect' => $redirect,
		);
		wp_send_json_success( $data );
		wp_die();
	}
	
	// Helper function to prepare the cart session before processing the order form
	public function prepare_cart_before_form_processing( $country = false ) {
		if( $country ) {
			WC()->customer->set_billing_country( $country );
			WC()->customer->set_shipping_country( $country );
			WC()->customer->save();
			WC()->cart->calculate_totals();
		}
	}
	
	// Helper function to prepare the local order before processing the order form
	public function prepare_local_order_before_form_processing( $order_id, $payment_id ) {
		// Update cart hash
		update_post_meta( $order_id, '_cart_hash', md5( json_encode( wc_clean( WC()->cart->get_cart_for_session() ) ) . WC()->cart->total ) );
		// Set the paymentID as a meta value to be used later for reference
		update_post_meta( $order_id, '_dibs_payment_id', $payment_id );
		// Order ready for processing
		WC()->session->set( 'order_awaiting_payment', $order_id );
		$order = wc_get_order( $order_id );
		$order->update_status( 'pending' );
	}
	
	// Function called if a ajax call does not receive the expected result
	public function fail_ajax_call( $order, $message = 'Failed to create an order with DIBS' ) {
		$order->add_order_note( sprintf( __( '%s', 'dibs-easy-for-woocommerce' ), $message ) );
		return $message;
	}

	public function get_options() {
		$return['privateKey'] = $this->private_key;
		if ( 'sv_SE' === get_locale() ) {
			$language = 'sv-SE';
		} else {
			$language = 'en-GB';
		}
		$return['language']  = $language;
		wp_send_json_success( $return );
		wp_die();
	}

	public function dibs_add_customer_order_note() {
		WC()->session->set( 'dibs_customer_order_note', $_POST['order_note'] );
		wp_send_json_success();
		wp_die();
	}

	/**
	 * Handles WooCommerce checkout error (if checkout submission fails), after DIBS order has already been created.
	 */
	public function ajax_on_checkout_error() {
		
		$order_id 			= WC()->session->get( 'dibs_incomplete_order' );
		$order 				= wc_get_order( $order_id );
		$dibs_order_data 	= WC()->session->get( 'dibs_order_data' );

		// Error message
		if ( ! empty( $_POST['error_message'] ) ) { // Input var okay.
			$error_message = 'Error message: ' . sanitize_text_field( trim( $_POST['error_message'] ) );
		} else {
			$error_message = 'Error message could not be retreived';
		}

		// Add customer data to order
		$this->helper_add_customer_data_to_local_order( $order, $dibs_order_data );

		// Add payment method to order
		$this->add_order_payment_method( $order );

		// Add order items
		$this->helper_add_items_to_local_order( $order_id );

		// Add order fees.
		$this->helper_add_order_fees( $order );
		
		// Add order shipping.
		$this->helper_add_order_shipping( $order );
		
		// Add order taxes.
		$this->helper_add_order_tax_rows( $order );
		
		// Store coupons.
		$this->helper_add_order_coupons( $order );

		// Add an order note to inform merchant that the order has been finalized via a fallback routine.
		$note = sprintf( __( 'This order was made as a fallback due to an error in the checkout (%s). Please verify the order with DIBS.', 'dibs-easy-for-woocommerce' ), $error_message );
			$order->add_order_note( $note );
		
		// Save order totals
		$order->calculate_totals();
		$order->save();

		$redirect_url 	= wc_get_endpoint_url( 'order-received', '', wc_get_page_permalink( 'checkout' ) );
		$redirect_url = add_query_arg( array(
						    'dibs-osf' => 'true',
						    'order-id' => $order_id,
						), $redirect_url );
		
		wp_send_json_success( array( 'redirect' => $redirect_url ) );
		wp_die();
	}

	/**
	 * Adds order items to ongoing order.
	 *
	 * @param  integer $local_order_id WooCommerce order ID.
	 * @throws Exception PHP Exception.
	 */
	function helper_add_items_to_local_order( $order_id ) {
		$local_order = wc_get_order( $order_id );
		// Remove items as to stop the item lines from being duplicated.
		$local_order->remove_order_items();
		foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) { // Store the line items to the new/resumed order.
			$item_id = $local_order->add_product( $values['data'], $values['quantity'], array(
				'variation' => $values['variation'],
				'totals'    => array(
					'subtotal'     => $values['line_subtotal'],
					'subtotal_tax' => $values['line_subtotal_tax'],
					'total'        => $values['line_total'],
					'tax'          => $values['line_tax'],
					'tax_data'     => $values['line_tax_data'],
				),
			) );
			if ( ! $item_id ) {
				throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 525 ) );
			}
			do_action( 'woocommerce_add_order_item_meta', $item_id, $values, $cart_item_key ); // Allow plugins to add order item meta.
		}
	}

	/**
	 * Adds order fees to local order.
	 *
	 * @since  1.1.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception PHP Exception.
	 */
	public function helper_add_order_fees( $order ) {
		$order_id = $order->get_id();
		foreach ( WC()->cart->get_fees() as $fee_key => $fee ) {
			$item_id = $order->add_fee( $fee );
			if ( ! $item_id ) {
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}
			// Allow plugins to add order item meta to fees.
			do_action( 'woocommerce_add_order_fee_meta', $order_id, $item_id, $fee, $fee_key );
		}
	}

	/**
	 * Adds order shipping to local order.
	 *
	 * @since  1.1.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception PHP Exception.
	 * @internal param object $klarna_order Klarna order.
	 */
	public function helper_add_order_shipping( $order ) {
		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}
		$order_id 				= $order->get_id();
		$this_shipping_methods 	= WC()->session->get( 'chosen_shipping_methods' );
		WC()->cart->calculate_shipping();
		// Store shipping for all packages.
		foreach ( WC()->shipping->get_packages() as $package_key => $package ) {
			if ( isset( $package['rates'][ $this_shipping_methods[ $package_key ] ] ) ) {
				$item_id = $order->add_shipping( $package['rates'][ $this_shipping_methods[ $package_key ] ] );
				if ( ! $item_id ) {
					throw new Exception( __( 'Error: Unable to add shipping item. Please try again.', 'woocommerce' ) );
				}
				// Allows plugins to add order item meta to shipping.
				do_action( 'woocommerce_add_shipping_order_item', $order_id, $item_id, $package_key );
			}
		}
	}

	/**
	 * Adds order tax rows to local order.
	 * 
	 * @since  1.1.0
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception PHP Exception.
	 */
	public function helper_add_order_tax_rows( $order ) {
		// Store tax rows.
		foreach ( array_keys( WC()->cart->taxes + WC()->cart->shipping_taxes ) as $tax_rate_id ) {
			if ( $tax_rate_id && ! $order->add_tax( $tax_rate_id, WC()->cart->get_tax_amount( $tax_rate_id ), WC()->cart->get_shipping_tax_amount( $tax_rate_id ) ) && apply_filters( 'woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated' ) !== $tax_rate_id ) {
				throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 405 ) );
			}
		}
	}

	/**
	 * Adds order coupons to local order.
	 *
	 * @since  1.1.0
	 * @access public
	 *
	 * @param  object $order Local WC order.
	 *
	 * @throws Exception PHP Exception.
	 */
	public function helper_add_order_coupons( $order ) {
		foreach ( WC()->cart->get_coupons() as $code => $coupon ) {
			if ( ! $order->add_coupon( $code, WC()->cart->get_coupon_discount_amount( $code ) ) ) {
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}
		}
	}
	
	/**
	 * Adds payment method to local order.
	 *
	 * @since  1.1.0
	 * @access public
	 *
	 */
	public function add_order_payment_method( $order ) {
		$available_gateways = WC()->payment_gateways->payment_gateways();
		$payment_method     = $available_gateways['dibs_easy'];
		$order->set_payment_method( $payment_method );
	}

	/**
	 * Adds customer data to WooCommerce order.
	 *
	 * @since  1.1.0
	 * @param integer $order_id 		WooCommerce order ID.
	 * @param array   $dibs_order_data  Customer data returned by DIBS.
	 */
	public function helper_add_customer_data_to_local_order( $order, $dibs_order_data ) {
		$order_id 		= $order->get_id();
		$first_name 	= (string) $dibs_order_data->payment->consumer->privatePerson->firstName;
		$last_name  	= (string) $dibs_order_data->payment->consumer->privatePerson->lastName;
		$email      	= (string) $dibs_order_data->payment->consumer->privatePerson->email;
		$country    	= (string) $dibs_order_data->payment->consumer->shippingAddress->country;
		$address  		= (string) $dibs_order_data->payment->consumer->shippingAddress->addressLine1;
		$city     		= (string) $dibs_order_data->payment->consumer->shippingAddress->city;
		$postcode 		= (string) $dibs_order_data->payment->consumer->shippingAddress->postalCode;
		$phone    		= (string) $dibs_order_data->payment->consumer->privatePerson->phoneNumber->number;
		
		update_post_meta( $order_id, '_billing_first_name', $first_name );
		update_post_meta( $order_id, '_billing_last_name', $last_name );
		update_post_meta( $order_id, '_billing_address_1', $address  );
		update_post_meta( $order_id, '_billing_city', $city );
		update_post_meta( $order_id, '_billing_postcode', $postcode );
		update_post_meta( $order_id, '_billing_country', $country );
		update_post_meta( $order_id, '_billing_phone', $phone );
		update_post_meta( $order_id, '_billing_email', $email );
	
		update_post_meta( $order_id, '_shipping_first_name', $first_name );
		update_post_meta( $order_id, '_shipping_last_name', $last_name );
		update_post_meta( $order_id, '_shipping_address_1', $address  );
		update_post_meta( $order_id, '_shipping_city', $city );
		update_post_meta( $order_id, '_shipping_postcode', $postcode );
		update_post_meta( $order_id, '_shipping_country', $country );
	}


}
$dibs_ajax_calls = new DIBS_Ajax_Calls();
