<?php
/*
Plugin Name: Ameriabank Credit Card Gateway
Plugin URI: https://github.com/greghub/ameria-woocommerce-gateway
Description: Add Credit Card Payment Gateway for WooCommerce
Version: 1.0.0
Author: Greg Hovanesyan
Author URI: https://github.com/greghub/
License: MIT License
*/

session_start();
//Additional links on the plugin page
add_filter( 'plugin_row_meta', 'ameria_register_plugin_links', 10, 2 );
function ameria_register_plugin_links($links, $file) {
	$base = plugin_basename(__FILE__);
	if ($file == $base) {
		$links[] = '';
	}
	return $links;
}



/* WooCommerce fallback notice. */
function woocommerce_cpg_fallback_notice() {
    echo '<div class="error"><p>' . sprintf( __( 'WooCommerce Ameriabank Credit Card Gateway depends on the last version of %s to work!', 'ameria' ), '<a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a>' ) . '</p></div>';
}

/* Load functions. */
function custom_payment_gateway_load() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'woocommerce_cpg_fallback_notice' );
        return;
    }

       
    class WC_Ameria extends WC_Payment_Gateway {


        /**
         * Constructor for the gateway.
         *
         * @return void
         */    
        public function __construct() {

            global $woocommerce;

            // Ameria vars
            $this->clientID = '';
            $this->username = '';
            $this->password = ''; 

            // plugin params
            $this->id             = 'Ameria';
            $this->has_fields     = false;
            $this->method_title     = __( 'Ameria', 'woocommerce' );
            $this->order_button_text = __( 'Proceed to Ameria', 'woocommerce' );
            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables.

            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->instructions = $this->get_option( 'instructions' );

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }


        /* Admin Panel Options.*/
        function admin_options() {
            ?>
            <h3><?php _e('Ameria Payment Gateway','woocommerce'); ?></h3>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table> <?php
        }

        /* Initialise Gateway Settings Form Fields. */
        public function init_form_fields() {
            global $woocommerce;

            $shipping_methods = array();

            if ( is_admin() )
                foreach ( $woocommerce->shipping->load_shipping_methods() as $method ) {
                    $shipping_methods[ $method->id ] = $method->get_title();
                }
                
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'woocommerce' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable Credit Card', 'woocommerce' ),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __( 'Title', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                    'desc_tip' => true,
                    'default' => __( 'Credit Card', 'woocommerce' )
                ),
                'description' => array(
                    'title' => __( 'Description', 'woocommerce' ),
                    'type' => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
                    'default' => __( 'Desctiption for Credit Card', 'woocommerce' )
                ),
                'instructions' => array(
                    'title' => __( 'Instructions', 'woocommerce' ),
                    'type' => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
                    'default' => __( 'Instructions for Credit Card', 'woocommerce' )
                ),
            );

        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
            global $woocommerce;

      
            $order = new WC_Order( $order_id );
            // Ameria bank params

            $this->description = "[description]"; 
            $this->orderID = $order_id; 
            $this->paymentAmount = $order->get_total();
            $_SESSION['eli_cart_total'] = $this->paymentAmount;
            $this->backURL = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))));


            $options = array(
                    'soap_version'    => SOAP_1_1,
                    'exceptions'      => true,
                    'trace'           => 1,
                    'wdsl_local_copy' => true
                    );

            $client = new SoapClient("https://online.ameriabank.am/payments/webservice/PaymentService.svc?wsdl", $options);

            $args['paymentfields'] = array(
                    'ClientID' => $this->clientID,
                    'Username' => $this->username,
                    'Password' => $this->password,                
                    'Description' => $this->description,
                    'OrderID' => $this->orderID,
                    'PaymentAmount' => $this->paymentAmount,
                    'backURL' => $this->backURL
                );

            $webService = $client->GetPaymentID($args); 
            $_SESSION['pid'] = $webService->GetPaymentIDResult->PaymentID;
            $this->liveurl = 'https://online.ameriabank.am/payments/forms/frm_paymentstype.aspx?clientid='.$this->clientID.'&clienturl='.$this->backURL.'&lang=am&paymentid='.$webService->GetPaymentIDResult->PaymentID;

            // Return thankyou redirect
            return array(
                'result'    => 'success',
                'redirect'  => $this->liveurl
            );

        }

        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function thankyou_page($order_id) {
            global $woocommerce;
            $options = array(
                    'soap_version'    => SOAP_1_1,
                    'exceptions'      => true,
                    'trace'           => 1,
                    'wdsl_local_copy' => true
                    );

            $client = new SoapClient("https://online.ameriabank.am/payments/webservice/PaymentService.svc?wsdl", $options);
            $total = $_SESSION['eli_cart_total'];
            $args['paymentfields'] = array(
                    'ClientID' => $this->clientID,
                    'Username' => $this->username,
                    'Password' => $this->password,                     
                    'PaymentAmount' => $total,    
                    'OrderID' => $order_id
                );
            $webService = $client->GetPaymentFields($args); 

            if($webService->GetPaymentFieldsResult->respcode == "00") {
                $order = new WC_Order( $order_id );
                    $type = $webService->GetPaymentFieldsResult->paymenttype;
                    if( $type == "1" ) {
                        $client->Confirmation($args);
                    }

                    $order->update_status('on-hold', __( 'Awaiting credit card payment', 'woocommerce' ));
                    // Reduce stock levels
                    $order->reduce_order_stock();

                    // Remove cart
                    $woocommerce->cart->empty_cart();

            } else {
                //echo '<meta http-equiv="refresh" content="0; url=http://example.com/" />';
            }
        }


    }

    function wc_Custom_add_gateway( $methods ) {
        $methods[] = 'WC_Ameria';
        return $methods;
    }
	add_filter( 'woocommerce_payment_gateways', 'wc_Custom_add_gateway' );

}
add_action( 'plugins_loaded', 'custom_payment_gateway_load', 0 );

// use [success] shortcode after [woocommerce_thankyou] on Thankyou page
function get_thank_you() {  
    if(!isset($_GET['order'])) return false;
    $success = new WC_Custom_Payment_Gateway_1();
    return $success->thankyou_page($_GET['order']);
}
add_shortcode( 'success', 'get_thank_you' );


/* Adds custom settings url in plugins page. */
function ameria_action_links( $links ) {
    $settings = array(
		'settings' => sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'admin.php?page=woocommerce_settings&tab=payment_gateways' ),
		__( 'Payment Gateways', 'Ameria' )
		)
    );

    return array_merge( $settings, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ameria_action_links' );


?>
