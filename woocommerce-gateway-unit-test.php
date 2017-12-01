<?php

/**
 * Plugin Name: Test Payment Module for Woocommerce
 * Plugin URI:  http://www.tortoise-it.co.uk
 * Description: A payment gateway plugin for Woocommerce to handle test or paymentless transactions. Shows for admin only by default or everyone in WP_DEBUG or using a gateway setting
 * Author:      Sean Barton (Tortoise IT)
 * Author URI:  http://www.tortoise-it.co.uk
 * Version:     1.5
 */
function sb_wc_test_init() {
	if (! class_exists ( 'WC_Payment_Gateway' )) {
		return;
	}
	class WC_Gateway_sb_test extends WC_Payment_Gateway {
		public function __construct() {
			$this->id = 'sb_test';
			$this->method_title = __ ( 'Gateway unit test', 'woocommerce' );
			$this->has_fields = true;
			$this->supports = array (
					'products',
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
					'subscription_payment_method_change',
					'subscription_payment_method_change_customer',
					'subscription_payment_method_change_admin' 
			);
			
			// Load the form fields
			$this->init_form_fields ();
			
			// Load the settings.
			$this->init_settings ();
			
			$this->title = 'Gateway unit test';
			
			add_action ( 'woocommerce_update_options_payment_gateways_' . $this->id, array (
					$this,
					'process_admin_options' 
			) );
			
			if (class_exists ( 'WC_Subscriptions_Order' )) {
				add_action ( 'woocommerce_scheduled_subscription_payment_' . $this->id, array (
						$this,
						'scheduled_subscription_payment' 
				), 10, 2 );
				
				// Allow store managers to manually set Simplify as the payment method on a subscription
				add_filter ( 'woocommerce_subscription_payment_meta', array (
						$this,
						'add_subscription_payment_meta' 
				), 10, 2 );
				add_filter ( 'woocommerce_subscription_validate_payment_meta', array (
						$this,
						'validate_subscription_payment_meta' 
				), 10, 2 );
			}
		}
		function init_form_fields() {
			$this->form_fields = array (
					'enabled' => array (
							'title' => __ ( 'Enable/Disable', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __ ( 'Enable test gateway', 'woocommerce' ),
							'default' => 'yes' 
					),
					'enabled_visitors' => array (
							'title' => __ ( 'Enable for visitors', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __ ( 'Allow non admins to use this gateway (for testing or for paymentless stores)', 'woocommerce' ),
							'default' => 'no' 
					),
					'enabled_ip' => array (
							'title' => __ ( 'Enable for specific IP addresses', 'woocommerce' ),
							'type' => 'textarea',
							'label' => __ ( 'In the following field enter IP addresses (one per line) to enable this gateway for specific IPs', 'woocommerce' ),
							'default' => '' 
					) 
			);
		}
		public function admin_options() {
			echo '	<h3>Gateway unit test</h3>
				<table class="form-table">';
			
			$this->generate_settings_html ();
			
			echo '	</table>';
		}
		
		/**
		 * Include the payment meta data required to process automatic recurring payments so that store managers can
		 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
		 *
		 * @since 2.5
		 * @param array $payment_meta
		 *        	associative array of meta data required for automatic payments
		 * @param WC_Subscription $subscription
		 *        	An instance of a subscription object
		 * @return array
		 */
		public function add_subscription_payment_meta($payment_meta, $subscription) {
			$payment_meta [$this->id] = array (
					'post_meta' => array (
							'_sb_test_customer_id' => array (
									'value' => get_post_meta ( $subscription->id, '_sb_test_customer_id', true ),
									'label' => 'SB Customer ID' 
							),
							'_sb_test_initial_result' => array (
									'value' => get_post_meta ( $subscription->id, '_sb_test_initial_result', true ),
									'label' => 'SB Initial Result' 
							),
							'_sb_test_next_result' => array (
									'value' => get_post_meta ( $subscription->id, '_sb_test_next_result', true ),
									'label' => 'SB Next Result' 
							) 
					) 
			);
			
			return $payment_meta;
		}
		
		/**
		 * Validate the payment meta data required to process automatic recurring payments so that store managers can
		 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
		 *
		 * @since 2.5
		 * @param string $payment_method_id
		 *        	The ID of the payment method to validate
		 * @param array $payment_meta
		 *        	associative array of meta data required for automatic payments
		 * @return array
		 */
		public function validate_subscription_payment_meta($payment_method_id, $payment_meta) {
			if ($this->id === $payment_method_id) {
				if (! isset ( $payment_meta ['post_meta'] ['_sb_test_customer_id'] ['value'] ) || empty ( $payment_meta ['post_meta'] ['_sb_test_customer_id'] ['value'] )) {
					throw new Exception ( 'A "_sb_test_customer_id" value is required.' );
				}
				
				if (! isset ( $payment_meta ['post_meta'] ['_sb_test_initial_result'] ['value'] ) || ! is_bool ( $payment_meta ['post_meta'] ['_sb_test_initial_result'] ['value'] )) {
					throw new Exception ( 'A "_sb_test_initial_result" value is required.' );
				}
			}
		}
		
		/**
		 * Process initial payment
		 *
		 * @param int $order_id        	
		 * @return string[]|NULL[]
		 */
		public function process_payment($order_id) {
			$order = new WC_Order ( $order_id );
			// $success = $order->get_meta ( '_sb_test_initial_result', true );
			$success = get_post_meta ( $order_id, '_sb_test_initial_result', true );
			
			$order->add_order_note ( 'Process initial payment by gateway-unit-test ' . ($success ? 'successfully' : 'unsuccessfully') );
			
			if ($success) {
				$order->payment_complete ();
				$order->update_status ( 'completed' );
				WC_Subscriptions_Manager::process_subscription_payments_on_order ( $order );
				
				return array (
						'result' => 'success',
						'redirect' => '' 
				);
			} else {
				$order->update_status ( 'failed' );
				WC_Subscriptions_Manager::process_subscription_payment_failure_on_order ( $order );
				WC_Subscriptions_Manager::clear_users_subscriptions_from_order ( $order );
				
				return array (
						'result' => 'fail',
						'redirect' => '' 
				);
			}
		}
		
		/**
		 * Process recurring payment
		 *
		 * @param float $amount_to_charge
		 *        	The amount to charge.
		 * @param WC_Order $renewal_order
		 *        	A WC_Order object created to record the renewal payment.
		 */
		public function scheduled_subscription_payment($amount_to_charge, $renewal_order) {
			$success = $renewal_order->get_meta ( '_sb_test_next_result', true );
			// $success = get_post_meta ( $order->get_id(), '_sb_test_next_result', true );
			$renewal_order->add_order_note ( 'Process renewal payment by gateway-unit-test ' . ($success ? 'successfully' : 'unsuccessfully') );
			
			if ($success) {
				$renewal_order->payment_complete ();
				$renewal_order->update_status ( 'completed' );
				WC_Subscriptions_Manager::process_subscription_payments_on_order ( $renewal_order );
			} else {
				WC_Subscriptions_Manager::process_subscription_payment_failure_on_order ( $renewal_order, $product_id );
			}
		}
	}
	
	/**
	 *
	 * @param unknown $methods        	
	 * @return string
	 */
	function add_sb_test_gateway($methods) {
		$show_visitors = $show_ip = false;
		
		if ($settings = get_option ( 'woocommerce_sb_test_settings' )) {
			if (isset ( $settings ['enabled_visitors'] ) && $settings ['enabled_visitors'] == 'yes') {
				$show_visitors = true;
			}
			if (isset ( $settings ['enabled_ip'] )) {
				if ($ips = explode ( "\n", $settings ['enabled_ip'] )) {
					foreach ( $ips as $ip ) {
						$ip = trim ( $ip );
						if ($_SERVER ['REMOTE_ADDR'] == $ip) {
							$show_ip = true;
							break;
						}
					}
				}
			}
		}
		
		if (current_user_can ( 'administrator' ) || WP_DEBUG || $show_visitors || $show_ip) {
			$methods [] = 'WC_Gateway_sb_test';
		}
		
		return $methods;
	}
	
	add_filter ( 'woocommerce_payment_gateways', 'add_sb_test_gateway' );
}

add_filter ( 'plugins_loaded', 'sb_wc_test_init' );

?>
