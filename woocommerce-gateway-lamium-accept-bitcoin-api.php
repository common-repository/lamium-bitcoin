<?php
/**
 * Plugin Name: Lamium Bitcoin Plugin to receive btc directly or convert to fiat
 * Plugin URI: https://www.lamium.io/coinnexus
 * Description: Accept Bitcoin payments from customers and get Bitcoins directly to your lamium wallet or convert them automatically via the decentralized invoice service into EUR, USD, CHF.
 * Author: Lamium Oy
 * Author URI: https://www.lamium.io/
 * Version: 1.1.0
 * Text Domain: woocommerce-gateway-lamium-accept-bitcoin-api
 * Domain Path: /languages/
 *
 * Copyright: (c) 2018 Lamium Oy (support@lamium.io) and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   woocommerce-gateway-lamium-accept-bitcoin-api
 * @author    Lamium Oy
 * @category  Admin
 * @copyright Copyright (c) 2018, Lamium Oy and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * This offline gateway forks the WooCommerce core "Cheque" payment gateway to create a bitcoin to fiat conversion payment plugin.
 */
 
defined( 'ABSPATH' ) or exit;


// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

register_activation_hook(__FILE__,'lamiumActivationForBitcoinPay');
add_action( 'wp', 'lamiumActivationForBitcoinPay' );

function lamiumActivationForBitcoinPay() {
   if (! wp_next_scheduled ( 'lamium_hourly_event_for_bitcoin_pay' )) {
	 wp_schedule_event(time(), 'hourly', 'lamium_hourly_event_for_bitcoin_pay');
    }
}
//updates order status of pending orders by connecting to the coinnexus api
add_action('lamium_hourly_event_for_bitcoin_pay','lamium_do_this_hourly_for_bitcoin_pay');
register_deactivation_hook(__FILE__, 'LamiumDeactivationForBitcoinPay');
function lamium_do_this_hourly_for_bitcoin_pay()
{	                 
	$lamiumPaymentObj = new WC_Gateway_Lamium_Accept_Bitcoin_Api;
	$lamiumPaymentObj->do_this_hourly_for_bitcoin_pay();
}
function LamiumDeactivationForBitcoinPay() {
	wp_clear_scheduled_hook('lamium_hourly_event_for_bitcoin_pay');
}


/**
 * Add the gateway to WC Available Gateways
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + lamium accept bitcoin api gateway
 */
function wc_lamium_accept_bitcoin_api_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Lamium_Accept_Bitcoin_Api';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_lamium_accept_bitcoin_api_add_to_gateways' );


/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */ 
function wc_lamium_accept_bitcoin_api_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=lamium_accept_bitcoin_api_gateway' ) . '">' . __( 'Configure', 'wc-gateway-fiat-to-bitcoin-coinnexus-api' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_lamium_accept_bitcoin_api_gateway_plugin_links' );


/**
 * Bitcoin To Fiat or Bitcoin Lamium Api 
 *
 * Lamium Bitcoin payment gateway that allows you to accept EUR, USD or CHF payments without an own bank account and coverts them directly into bitcoin.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Lamium_Accept_Bitcoin_Api
 * @extends		WC_Payment_Gateway
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		Lamium Oy
 */
add_action( 'plugins_loaded', 'wc_lamium_accept_bitcoin_api_gateway_init', 11 );

function wc_lamium_accept_bitcoin_api_gateway_init() {

	class WC_Gateway_Lamium_Accept_Bitcoin_Api extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			global $wp_session;
	  
			$this->id                 = 'lamium_accept_bitcoin_api_gateway';
			$this->icon               = apply_filters('woocommerce_lamium_accept_bitcoin_api_gateway_icon', '');
			$this->has_fields         = false;
			$this->method_title       = __( 'Lamium accept bitcoin api', 'wc-gateway-lamium-accept-bitcoin-api' );
			$this->method_description = __( 'Allows to accept payments in bitcoin. Very handy if you use your cheque gateway for another payment method, and can help with testing. Orders are marked as "payment-pending" when received.', 'wc-gateway-lamium-accept-bitcoin-api' );
		  
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
		  
			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->username  = $this->get_option( 'username' );
			$this->password  = $this->get_option( 'password' );
			$this->iban  = $this->get_option( 'iban' );
			$this->bic = $this->get_option( 'bic' );
			$this->fiat_pay_or_bitcoin_pay = $this->get_option( 'fiat_bitcoin' );
			$this->full_name = $this->get_option( 'full_name' );
			$this->email_address = $this->get_option( 'email_address' );
			$this->instructions = $this->get_option( 'instructions', $this->description );
		  
		 
			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		  
		  // Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
			
		}
	
	
		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
	  
			$this->form_fields = apply_filters( 'wc_lamium_accept_bitcoin_api_form_fields', array(
		  
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Lamium accept Bitcoin api', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default' => 'yes'
				),
				
				'title' => array(
					'title'       => __( 'Title', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'text',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( 'Bitcoin Payment', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),
				'username' => array(
					'title'       => __( 'Lamium username', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'text',
					'description' => __( 'Lamium api username', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( '', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),
				'password' => array(
					'title'       => __( 'Lamium api password', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'text',
					'description' => __( 'Lamium api password', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( '', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),
				'fiat_bitcoin'=> array(
					'title'       => __( 'Please select here if you want to receive the bitcoins directly and without fees to your Lamium bitcoin wallet or if you want it automatically converted into fiat currency via the Lamium Invoice Service and sent the money to your bank account *', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'select',
					'description' => __( 'Please select here if you want to receive the bitcoins directly and without fees to your Lamium bitcoin wallet or if you want it automatically converted into fiat currency via the Lamium Invoice Service and sent the money to your bank account', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'options'     => array(
						                'fiat'   => __( 'fiat' ),
						                'bitcoins'  => __( 'bitcoins' )
            						),
					'default'     => __( '', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
					),
				'full_name' =>array(
					'title'       => __( 'Your full name', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'text',
					'description' => __( 'Your full name as in the bank account', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( '', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),
				'email_address' =>array(
					'title'       => __( 'Your email address', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'text',
					'description' => __( 'Your email address(compulsory)', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( '', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),

				'iban' => array(
					'title'       => __( 'Your IBAN', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'text',
					'description' => __( 'Bank details where coinnexus will deposit your fiat', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( '', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),
				'bic' => array(
					'title'       => __( 'Your BIC', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'text',
					'description' => __( 'Bank details where Lamium will deposit your fiat', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( '', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),
				
				'description' => array(
					'title'       => __( 'Description', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => __( 'Please remit payment to Store Name upon pickup or delivery.', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'desc_tip'    => true,
				),
				
				'instructions' => array(
					'title'       => __( 'Instructions', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-gateway-lamium-accept-bitcoin-api' ),
					'default'     => '',
					'desc_tip'    => true,
				),
			) );
		}
	
	
		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				print_r(WC()->session->get('lamiumData'));
			}
		}
		
		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			$orderData = $order->get_data();
			if ( $this->instructions && ! $sent_to_admin && $this->id === $orderData['payment_method'] && $order->has_status( 'payment-pending' ) ) {
				echo wpautop( wptexturize( $this->instructions )) . PHP_EOL;
			}
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) 
		{
			 try { 
				$order = wc_get_order( $order_id );

		 		$orderData = $order->get_data();
				$data = array(
					'username' =>$this->username,
	                'password'  => $this->password,
	               );
				$data = json_encode($data);
				$url = 'http://api.lamium.fi/api/users/token';
	            $tokenRemoteCall = null;
	            $i = 0;
	            do {
	            	$tokenRemoteCall = $this->_getCoinnexusToken();
	            	$tokenRemoteCall =json_decode($tokenRemoteCall['body']);
	            	$i = $i +1;
	            }while (($i<3) && (@$tokenRemoteCall->success!=true));
	            if(empty($tokenRemoteCall->success)){
	            	$this->_fail($tokenRemoteCall,'Api login failed',$orderData);
	            }
	            $lamiumApiData['fiat_pay_or_bitcoin_pay'] = $this->fiat_pay_or_bitcoin_pay;
	            $lamiumApiData['iban'] = $this->iban;
	            $lamiumApiData['bic_code'] = $this->bic;
	            $lamiumApiData['payer_name'] = $this->full_name;
	            $lamiumApiData['payer_email_address'] = $this->email_address;
	            $lamiumApiData['amount']= $orderData['total'];
	            $lamiumApiData['currency']= $orderData['currency'];
	            $lamiumApiData['purchase_bitcoin_agreement']= 1;
	            $lamiumApiData['customer_name']= $orderData['billing']['first_name'].'--'.$orderData['billing']['last_name'];
	            $lamiumApiData['customer_phone']= $orderData['billing']['phone'];
	            $lamiumApiData['customer_address']= $orderData['billing']['address_1'].'--'.$orderData['billing']['address_2'].'--'.$orderData['billing']['city'].'--'.
					$orderData['billing']['state'].'--'.$orderData['billing']['postcode'].'--'.$orderData['billing']['country'];	
	            $lamiumApiData['item']='url - '.get_home_url().'-pay with bitcoin request- Woocommerce Order id -'.$orderData['id'];
	            $lamiumApiData['vat_rate']=$orderData['total_tax'];
	            $lamiumApiData = json_encode($lamiumApiData);
	            $url = 'http://api.lamium.fi/api/payments/paybitcoins';
	            $apiDataRemoteCall = null;
	            $i = 0;
	            do {
	            	$apiDataRemoteCall = $this->_wpRemoteCall($url,$lamiumApiData,$tokenRemoteCall->data->token);
	            	//print_r($apiDataRemoteCall);exit;
	            	$apiDataRemoteCall =json_decode($apiDataRemoteCall['body']);
	            	$i = $i +1;
	            }while (($i<3) && (@$apiDataRemoteCall->success!=true));

	            if(empty($apiDataRemoteCall->success)){
	            	$this->_fail($apiDataRemoteCall,'/payments/paybitcoins call failed',$orderData);
	            }
				// Mark as payment-pending (we're awaiting the payment)
			    $order->update_status( 'payment-pending', __( 'Awaiting fiat payment', 'wc-gateway-lamium-accept-bitcoin-api' ) );
				if('fiat'== $this->fiat_pay_or_bitcoin_pay)
				{
					update_post_meta( $order_id , '_lamium_merchant_id',$apiDataRemoteCall->data[0]->merchant_id);
					update_post_meta( $order_id , '_lamium_transaction_id',$apiDataRemoteCall->data[0]->transaction_id);
				}
				update_post_meta( $order_id , '_fiat_pay_or_bitcoin_pay',$this->fiat_pay_or_bitcoin_pay);
	 			update_post_meta( $order_id , '_lamium_customer_reference',$apiDataRemoteCall->data[0]->customer_reference);
				update_post_meta( $order_id , '_lamium_btc_address',$apiDataRemoteCall->data[0]->btc_address);
				update_post_meta( $order_id , '_lamium_btc_amount',$apiDataRemoteCall->data[0]->btc_amount);
				 // Reduce stock levels
				$order->reduce_order_stock();
			 	// Remove cart
			    WC()->cart->empty_cart();
			    //send new order and payment details email to customer
			   // load the mailer class
				 $mailer = WC()->mailer();
				//format the email
				$recipient = $orderData['billing']['email'];
				$subject = get_bloginfo()." payment details for order #".$order_id;
				$content = '<div>Dear '.$orderData['billing']['first_name'].' '.$orderData['billing']['last_name'].',<br/>
					Thank you for your order at '.get_bloginfo().'.</div>
					<div>In order to complete the order please send bitcoins to the following address:</div>
					<table class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
					<tr><td>BTC Address : <strong>'.$apiDataRemoteCall->data[0]->btc_address.'</strong><td><tr>
					<tr><td>BTC Amount :  <strong>'.$apiDataRemoteCall->data[0]->btc_amount.'</strong><td></tr>
					<tr><td>Message/Reference : <strong>'.$apiDataRemoteCall->data[0]->customer_reference.'</strong></td></tr>
					<tr><td><img src="http://api.qrserver.com/v1/create-qr-code/?size=200x200&amp;data=bitcoin://'.$apiDataRemoteCall->data[0]->btc_address.'?amount='.$apiDataRemoteCall->data[0]->btc_amount.'"></td></tr>';
				if(!empty($apiDataRemoteCall->data[0]->pay_with_bitalo)){
					$content .='<tr><td><a style="background:#2778b2;padding:10px 10px 10px 10px;color:#fff" href ="'.$apiDataRemoteCall->data[0]->pay_with_bitalo.'" class="button" target="_blank">Pay with Lamium</a><td></tr>';
				}
				$content .='</table>';
				$content .= $this->_get_custom_email_html( $order, $subject, $mailer );
				$headers = "Content-Type: text/html\r\n";
				//send the email through wordpress
				$mailer->send( $recipient, $subject, $content, $headers );
			    $paymentDetailsBlock ='<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
					<li class="woocommerce-order-overview__order order"><p>Please bitcoins to the following address:</p>
					<p>BTC Address : <strong>'.$apiDataRemoteCall->data[0]->btc_address.'</strong></p>
					<p>BTC Amount :  <strong>'.$apiDataRemoteCall->data[0]->btc_amount.'</strong></p>
					<p>Message/Reference : <strong>'.$apiDataRemoteCall->data[0]->customer_reference.'</strong></p>
					<p><img src="http://api.qrserver.com/v1/create-qr-code/?size=200x200&amp;data=bitcoin://'.$apiDataRemoteCall->data[0]->btc_address.'?amount='.$apiDataRemoteCall->data[0]->btc_amount.'"></p>';
				if(!empty($apiDataRemoteCall->data[0]->pay_with_bitalo)){
					$paymentDetailsBlock .='<p><a style="background:#2778b2;padding:10px 10px 10px 10px;color:#fff" href ="'.$apiDataRemoteCall->data[0]->pay_with_bitalo.'" class="button" target="_blank">Pay with Lamium</a></p>';
				}
			     
				$lamiumData = '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
					<li class="woocommerce-order-overview__order order"><p>Please send bitcoins to the following address :</p>
					<p style="font-size:125%"><strong>Within <span id="pay-time"></span></strong></p>
					<p>BTC Address : <strong>'.$apiDataRemoteCall->data[0]->btc_address.'</strong></p>
					<p>BTC Amount:  <strong>'.$apiDataRemoteCall->data[0]->btc_amount.'</strong></p>
					<p>Message/Reference : <strong>'.$apiDataRemoteCall->data[0]->customer_reference.'</strong></p>
					<p><img src="http://api.qrserver.com/v1/create-qr-code/?size=200x200&amp;data=bitcoin://'.$apiDataRemoteCall->data[0]->btc_address.'?amount='.$apiDataRemoteCall->data[0]->btc_amount.'"></p>';
			    if(!empty($apiDataRemoteCall->data[0]->pay_with_bitalo)){
					$lamiumData  .='<p><a style="background:#2778b2;padding:10px 10px 10px 10px;color:#fff" href ="'.$apiDataRemoteCall->data[0]->pay_with_bitalo.'" class="button" target="_blank">Pay with Lamium</a></p>';
				}
				$lamiumData .= "
					<script>jQuery(document).ready(function () {
		        
		        var countdown = 600 * 1000;
				var timerId = setInterval(function(){
				  countdown -= 1000;
				  var min = Math.floor(countdown / (60 * 1000));
				  if(min<10){
				  	min = '0'+min;
				  }
				  //var sec = Math.floor(countdown - (min * 60 * 1000));  // wrong
				  var sec = Math.floor((countdown - (min * 60 * 1000)) / 1000);  //correct
				  if(sec<10){
				  	sec = '0'+sec;
				  }
				  if (countdown <= 0) {
				  	jQuery('#pay-time').html( '00:00');
				  	checkForUpdate(1);
				  } else {
				     jQuery('#pay-time').html(min + ':' + sec);
				  }

				}, 1000);
		    });</script>";
				// Return thankyou redirect
			    WC()->session->set( 'lamiumData', $lamiumData);
				return array(
					'result' 	=> 'success',
					'redirect'	=> $this->get_return_url($order)
				);
			}catch(Exception $e) {
   		 			$this->_tryCatchError($e->getMessage());
			}
		}
	public function do_this_hourly_for_bitcoin_pay() {
	try {
		$customer_orders = get_posts( array(
			        'numberposts' => 100,
			        'order' => 'ASC',
			        'meta_key'    => '_customer_user',
			        'post_type'   => array( 'shop_order' ),
			        'post_status' => array( 'wc-pending' )
	    		));
		if(empty($customer_orders)){return true;}
		if('bitcoins' == $this->fiat_pay_or_bitcoin_pay)
		{
			$orders = array();
			$orderIdTransactionIdMap = array();	
			foreach ( $customer_orders as $key =>$customer_order ) 
			{
				$metaData = get_post_meta($customer_order->ID);
		    	if(!isset($metaData["_lamium_customer_reference"])|| !isset($metaData["_lamium_btc_amount"]))
		    		{continue;}
		    	$btc_amount = $metaData["_lamium_btc_amount"][0];
		    	$orders[$customer_order->ID]['reference_number'] = $metaData["_lamium_customer_reference"][0];
		    	$orders[$customer_order->ID]['btc_amount'] = $metaData["_lamium_btc_amount"][0];
		    	$orders[$customer_order->ID]['btc_address'] = $metaData["_lamium_btc_address"][0];
		    	$orderIdTransactionIdMap[$metaData["_lamium_customer_reference"][0]] = $customer_order->ID;
			}
			if(empty($orders)){return true;}
			$lamiumApiData = $orders;
			$url = 'http://api.lamium.fi/api/payments/paybitcoinsallorderpaymentstatusforbitcoin';
		}else{
			$transaction_ids = array();	
			$orderIdTransactionIdMap = array();		
			foreach ( $customer_orders as $customer_order ) 
			{
			    $metaData = get_post_meta($customer_order->ID);
			    if(!isset($metaData["_lamium_customer_reference"])|| !isset($metaData["_lamium_transaction_id"][0]))
			    	{continue;}
			    $transaction_id = $metaData["_lamium_transaction_id"][0];
			    $transaction_ids[] = $transaction_id;
			    $merchantId = $metaData["_lamium_merchant_id"][0];
			    $orderIdTransactionIdMap[$transaction_id] = $customer_order->ID;     
			}
			if(empty($transaction_ids)){return true;}
			$lamiumApiData['merchant_id'] = $merchantId;
		    $lamiumApiData['transaction_ids'] = $transaction_ids;
		    $url = 'http://api.lamium.fi/api/payments/paybitcoinsallorderpaymentstatus';
		}
		$lamiumApiData = json_encode($lamiumApiData);
	    $tokenRemoteCall = $this->_getCoinnexusToken();
	    $tokenRemoteCall =json_decode($tokenRemoteCall['body']);
	    if(empty($tokenRemoteCall->success))
	    {
	        $this->_fail($tokenRemoteCall,$url.'-- call failed',false,true);
	        return true;
	    }
	    $apiDataRemoteCall = $this->_wpRemoteCall($url,$lamiumApiData,$tokenRemoteCall->data->token);
        $apiDataRemoteCall =json_decode($apiDataRemoteCall['body']);
        if(empty($apiDataRemoteCall->success)){return true;}
        if('bitcoins' == $this->fiat_pay_or_bitcoin_pay)
        {
        	foreach($apiDataRemoteCall->data as $key => $apiData)
        	{
        		switch ($apiData->status) {
        			case 'n/a':
            			$order = wc_get_order($key);
            			$orderUpdate = $order->update_status('cancelled', __( 'Customer did not pay bitcoins', 'wc-gateway-lamium-accept-bitcoin-api'));
        				$order->add_order_note('Customer did not pay bitcoins',
						__( 'Customer did not pay bitcoins', 'wc-gateway-lamium-accept-bitcoin-api' ));
        				break;
        			case 'paid':
            			$order = wc_get_order($key);
            			$orderUpdate = $order->update_status('processing', __( 'Bitcoins paid by customer', 'wc-gateway-lamium-accept-bitcoin-api'));
        				$order->add_order_note('Customer paid bitcoins,here is the transaction link <a href="https://blockchain.info/tx/'.$apiData->tx_id.'">View</a>',
						__( 'Customer paid bitcoins', 'wc-gateway-lamium-accept-bitcoin-api' ));
        				break;
        			default:
        			break;	
        		}
        	}
        }else{
        	foreach($apiDataRemoteCall->data[0]->records as $apiData)
		        {	  
		            if($apiData->status =='paid')
		            { 
		            	$orderId = $orderIdTransactionIdMap[$apiData->transaction_id];
		            	$order = wc_get_order($orderId);
		            	$orderUpdate = $order->update_status('processing', __( 'Bitcoins paid by customer', 'wc-gateway-lamium-accept-bitcoin-api'));
		            }
		        }
        }
        
    }
	    catch(Exception $e) {
	    	$this->_tryCatchError($e->getMessage());
		}
	}

	protected function _get_custom_email_html( $order, $heading = false, $mailer ) {
		$template = 'emails/customer-invoice.php';
		return wc_get_template_html( $template, array(
			'order'         => $order,
			'email_heading' => $heading,
			'sent_to_admin' => false,
			'plain_text'    => false,
			'email'         => $mailer
		) );
	}

	protected function _wpRemoteCall($url,$bodyData,$token=null)
	{	
		return wp_remote_post( $url, array(
			'method' => 'POST',
			'timeout' => 45,
			'redirection' => 5,
			'headers' => array("Content-type" =>'application/json','Accept'=>'application/json','Authorization'=>'Bearer '.$token),
			'body' => $bodyData
		    )
		);
	}

	protected function _getCoinnexusToken()
	{
		$data = array(
				'username' =>$this->username,
                'password'  => $this->password,
               );
			$data = json_encode($data);
			$url = 'http://api.lamium.fi/api/users/token';
            return $this->_wpRemoteCall($url,$data);
	}

    protected function _fail($cornCallObj,$sub,$orderData=false,$automatedCall=false)
    {
    	$to = 'support@lamium.io,debanjan@lamium.io';
		$subject = 'WC_Gateway_Lamium_Accept_Bitcoin_Api ---'.$sub;
		$message = get_home_url().'---'.@$cornCallObj->message;
		if($orderData)
		{
			$message .= '--------'.$cornCallObj->url.'-------'.$orderData['currency'].'--'.$orderData['total'].
			'--'.$orderData['billing']['first_name'].$orderData['billing']['last_name'].'--'.$orderData['billing']['phone'].'--'.
			$orderData['billing']['address_1'].'--'.$orderData['billing']['address_2'].'--'.$orderData['billing']['city'].'--'.
			$orderData['billing']['state'].'--'.$orderData['billing']['postcode'].'--'.$orderData['billing']['country'].'--'.$orderData['total_tax'];
		}
		wp_mail( $to, $subject, $message);
		if(!$automatedCall)
		{
			throw new Exception( __( 'order processing failed, please try again later', 'woo' ) );
		}	
    }

    protected function _tryCatchError($error)
    {
    	$to = 'support@lamium.io,debanjan@lamium.io';
		$subject = 'WC_Gateway_Lamium_Accept_Bitcoin_Api plugin run failed';
		$message = get_home_url().'-----'.$error;
    }
	
  } // end \WC_Gateway_Lamium_Accept_Bitcoin_Api class
}