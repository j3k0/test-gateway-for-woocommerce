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
			$this->has_fields = false;
			$this->method_title = __ ( 'Gateway unit test', 'woocommerce' );
			$this->init_form_fields ();
			$this->init_settings ();
			$this->title = 'Gateway unit test';
			$this->supports = array (
					'products',
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
					'subscription_payment_method_change' 
			);
			
			add_action ( 'woocommerce_update_options_payment_gateways_' . $this->id, array (
					$this,
					'process_admin_options' 
			) );
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
									'label' => 'SB Result' 
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
				
				if (! isset ( $payment_meta ['post_meta'] ['_sb_test_initial_result'] ['value'] ) || empty ( $payment_meta ['post_meta'] ['_sb_test_initial_result'] ['value'] )) {
					throw new Exception ( 'A "_sb_test_initial_result" value is required.' );
				} elseif (! is_bool ( $payment_meta ['post_meta'] ['_sb_test_initial_result'] ['value'] )) {
					throw new Exception ( 'Invalid result. A valid "_sb_test_initial_result" must be a boolean".' );
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
			global $woocommerce;
			
			// if (get_post_meta ( $order_id, '_sb_test_initial_result', true )) {
			$order = new WC_Order ( $order_id );
			$order->payment_complete ();
			$order->reduce_order_stock ();
			$woocommerce->cart->empty_cart ();
			
			return array (
					'result' => 'success',
					'redirect' => $order->get_checkout_order_received_url () 
			);
			// }
			
			// return array (
			// 'result' => 'fail',
			// 'redirect' => ''
			// );
		}
		
		/**
		 * Process recurring payment
		 *
		 * @param double $amount_to_charge        	
		 * @param WC_Order $order        	
		 * @param int $product_id        	
		 */
		public function scheduled_subscription_payment($amount_to_charge, $order, $product_id) {
			$result = true;
			
			if (is_wp_error ( $result )) {
				WC_Subscriptions_Manager::process_subscription_payment_failure_on_order ( $order, $product_id );
			} else {
				WC_Subscriptions_Manager::process_subscription_payments_on_order ( $order );
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
