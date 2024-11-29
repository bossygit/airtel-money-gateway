<?php
/*
Plugin Name: WooCommerce Airtel Money Payment Gateway
Description: Accept Airtel Money payments through WooCommerce.
Version: 1.0
Author: Bienvenu Kitutu
*/


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Include Gateway Class and Settings
add_action('plugins_loaded', 'init_airtel_money_gateway', 0);


function init_airtel_money_gateway() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Airtel_Money_Gateway extends WC_Payment_Gateway {
        
            public $client_id;
            public $client_secret;
            public $test_mode;
            public $api_base_url;
            public $uuid ;

        public function __construct() {
            $this->id = 'airtel_money_gateway';
            $this->method_title = 'Airtel Money';
            $this->has_fields = true;
            $this->icon = plugin_dir_url(__FILE__) . 'am-logo.jpg';
            $this->method_description = 'Payment Gateway to handle airtel money';
            

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            // Get settings from the admin
            $this->client_id = $this->get_option('client_id');
            $this->client_secret = $this->get_option('client_secret');
            $this->test_mode = $this->get_option('test_mode');
            $this->title = $this->get_option('title');
            

            // Set API URL based on mode
            $this->api_base_url = $this->test_mode === 'yes' ? 'https://openapiuat.airtel.africa' : 'https://openapi.airtel.africa';

            // Hooks
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);

            // Note: These hooks call functions, not methods
            add_action('woocommerce_checkout_process', 'validate_airtel_money_fields'); 
            add_action('init', 'add_airtel_money_intermediary_endpoint');
            add_action('template_redirect', 'handle_airtel_money_intermediary_page');
            add_action('wp_ajax_finalize_airtel_payment', 'finalize_airtel_payment');
            add_action('wp_ajax_nopriv_finalize_airtel_payment', 'finalize_airtel_payment');
            
            add_action('woocommerce_api_' . $this->id, array($this, 'handle_callback'));
            


            error_log('Airtel Money Gateway Initialized');
            error_log('Payment sc : ' . $this->client_secret );

        }

        // Initialize settings fields
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Airtel Money Payment',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Title shown at checkout.',
                    'default' => 'Airtel Money',
                ),
                'client_id' => array(
                    'title' => 'Client ID',
                    'type' => 'text',
                ),
                'client_secret' => array(
                    'title' => 'Client Secret',
                    'type' => 'password',
                ),
                'test_mode' => array(
                    'title' => 'Test Mode',
                    'type' => 'checkbox',
                    'label' => 'Enable Test Mode',
                    'default' => 'yes',
                ),
            );
        }
        
        public function payment_fields() {
                // Display the Airtel Money payment instructions or description if needed
                if ($this->description) {
                    echo wpautop(wp_kses_post($this->description));
                }
            
                // Create the form for the MSISDN (subscriber number) input
                ?>
                <fieldset>
                    <p class="form-row form-row-wide">
                        <label for="airtel_money_msisdn"><?php _e('Numéro Airtel', 'woocommerce'); ?> <span class="required">*</span></label>
                        <input type="text" class="input-text" name="airtel_money_msisdn" id="airtel_money_msisdn" placeholder="Entrez votre numéro Airtel Money" required />
                    </p>
                </fieldset>
                <?php
            }

        // Payment processing

        public function process_payment($order_id) {
            error_log('Process payment function');
            $order = wc_get_order($order_id);
            $uuid = wp_generate_uuid4();
        
            // Fetch Airtel Money access token
            $access_token = $this->get_access_token();
            if (!$access_token) {
                wc_add_notice('Unable to process payment: Access token error', 'error');
                return;
            }
        
            // Call payment API to initiate the payment
            $payment_response = $this->request_payment($order, $access_token,$uuid);
            if ($payment_response && isset($payment_response->status) && isset($payment_response->status->success) && $payment_response->status->success) {
                // Save UUID and payment info to order meta
                update_post_meta($order_id, '_airtel_payment_uuid', $uuid);
        
                // Redirect to intermediary page with order ID as parameter
                return array(
                    'result' => 'success',
                    'redirect' => home_url('/airtel-money-intermediary/?order_id=' . $order_id)
                );
            } else {
                wc_add_notice('L initiation du paiement a échoué: ' . $payment_response->status->message, 'error');
                return;
            }
        }

        // Handle OAuth2 token generation
        public function get_access_token() {
            error_log('Get access token function');
            $url = $this->api_base_url . '/auth/oauth2/token';
        
            $response = wp_remote_post($url, array(
                'body'    => json_encode(array(
                    'client_id'     => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'grant_type'    => 'client_credentials',
                )),
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 60, // Timeout in seconds
            ));
        
            // Check for errors
            if (is_wp_error($response)) {
                error_log('Access token error: ' . $response->get_error_message());
                return false;
            }
        
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);
            error_log('Corps : ' . $body);
            
            // Check for errors
            if (is_wp_error($response)) {
                error_log('Access token error: ' . $response->get_error_message());
                return false;
            }
        
            return $data->access_token ?? false;
        }
        
        // Function to verify transaction status
        public function verify_transaction_status($uuid, $access_token) {
            error_log('Verifying transaction status for UUID: ' . $uuid);
        
            // Construct the URL to check transaction status
            $url = $this->api_base_url . '/standard/v1/payments/' . $uuid;
        
            // Make the GET request to verify transaction status
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Accept'        => '*/*',
                    'X-Country'     => 'CG',
                    'X-Currency'    => 'XAF',
                ),
                'timeout' => 60, // Timeout in seconds
            ));
        
            // Check for errors
            if (is_wp_error($response)) {
                error_log('Erreur de statut de transaction: ' . $response->get_error_message());
                return false;
            }
        
            // Parse the response
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);
        
            // Log the response body for debugging purposes
            error_log('Réponse sur l état de la transaction :' . print_r($data, true));
        
            // Check if $data is an object and contains expected properties
            if (is_object($data) && isset($data->data->transaction->status)) {
                // Return the response data
                return $data;
            } else {
                error_log('Réponse d état de transaction non valide.');
                return false;
            }
        }

        
        // Request payment from Airtel Money API
        public function request_payment($order, $access_token,$uuid) {
            error_log('Request payment function');
            $url = $this->api_base_url . '/merchant/v1/payments/';
           // error_log('POST data: ' . print_r($_POST, true));
            $msisdn = $_POST['airtel_money_msisdn'] ?? '';
         
            $amount = $order->get_total();
            $order_id = $order->get_id();
           
            
            error_log('Numero msisdn : '. $msisdn);
        
            // Prepare the request body
            $body = json_encode(array(
                'reference' => 'Order ' . $order_id,
                'subscriber' => array(
                    'country' => 'CG',
                    'currency' => 'XAF',
                    'msisdn'  => $msisdn,
                ),
                'transaction' => array(
                    'amount'   => $amount,
                    'country'  => 'CG',
                    'currency' => 'XAF',
                    'id'       => $uuid,
                ),
            ));
        
            // Make the API request
            $response = wp_remote_post($url, array(
                'body'    => $body,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'X-Country'     => 'CG',
                    'X-Currency'    => 'XAF',
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 60, // Timeout in seconds
            ));
        
            // Check for errors
            if (is_wp_error($response)) {
                error_log('Payment request error: ' . $response->get_error_message());
                return false;
            }
        
            // Parse the response
            $body = wp_remote_retrieve_body($response);
            error_log('Corps Request : ' . $body);
            return json_decode($body);
        }
        
         public function handle_callback() {
        // Read the POST data
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data);

        // Log the received data for debugging
        error_log('Airtel Money Callback received: ' . print_r($data, true));

        // Process the data
        if (isset($data->transaction)) {
            $transaction_id     = $data->transaction->id;
            $transaction_status = $data->transaction->status_code;
            $transaction_message = $data->transaction->message;
            $airtel_money_id    = $data->transaction->airtel_money_id;

            // Find the order using the transaction_id
            $orders = wc_get_orders(array(
                'limit'        => 1,
                'meta_key'     => '_airtel_payment_uuid',
                'meta_value'   => $transaction_id,
                'meta_compare' => '=',
            ));

            if (!empty($orders)) {
                $order = $orders[0];
                // Update order status based on transaction_status
                if ($transaction_status === 'TS') {
                    // Payment successful
                    $order->payment_complete($airtel_money_id);
                    $order->add_order_note('Payment completed via Airtel Money callback. Transaction ID: ' . $airtel_money_id);
                } elseif ($transaction_status === 'TF') {
                    // Payment failed
                    $order->update_status('failed', 'Payment failed via Airtel Money callback. Message: ' . $transaction_message);
                } else {
                    // Other statuses
                    $order->add_order_note('Received unknown transaction status via Airtel Money callback. Status: ' . $transaction_status . '. Message: ' . $transaction_message);
                }
            } else {
                // Order not found
                error_log('Airtel Money callback: Order not found for transaction ID ' . $transaction_id);
            }
        } else {
            error_log('Airtel Money callback: Invalid data received.');
        }

        // Respond to the callback
        header('Content-Type: application/json');
        echo json_encode(array('status' => 'success'));
        exit;
    }
        
        // Receipt page
        public function receipt_page($order) {
            echo '<p>Thank you for your order. Please wait while we process your payment.</p>';
        }
    }
    // End Class


     
       
}

        function validate_airtel_money_fields() {
            if ($_POST['payment_method'] === 'airtel_money_gateway') {
                if (empty($_POST['airtel_money_msisdn']) OR !preg_match("#^0[45]{1}[0-9]{7}$#", $_POST['airtel_money_msisdn'])) {
                    wc_add_notice(__('Veuillez saisir votre numéro Airtel Money.', 'woocommerce'), 'error');
                }
            }
        }
        
        add_action('woocommerce_checkout_process', 'validate_airtel_money_fields'); 

        

        // Add Airtel Money Gateway to WooCommerce
        function add_airtel_money_gateway($methods) {
            $methods[] = 'WC_Airtel_Money_Gateway';
            return $methods;
        }

        add_filter('woocommerce_payment_gateways', 'add_airtel_money_gateway');
        
        // Register the custom query variable
        function add_airtel_money_query_vars($vars) {
            $vars[] = 'airtel_money_intermediary';
            return $vars;
        }
        add_filter('query_vars', 'add_airtel_money_query_vars');

        // Add custom endpoint for the intermediary page
            
            
        function add_airtel_money_intermediary_endpoint() {
                    add_rewrite_rule('^airtel-money-intermediary/?', 'index.php?airtel_money_intermediary=1', 'top');
                    add_rewrite_tag('%airtel_money_intermediary%', '([^&]+)');
        }
        add_action('init', 'add_airtel_money_intermediary_endpoint');

        // Handle the intermediary page content


        function handle_airtel_money_intermediary_page() {
            global $wp_query;
        
            if (isset($wp_query->query_vars['airtel_money_intermediary'])) {
                // Retrieve the order ID
                $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
                // Fetch the order
                if ($order_id) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        // Start output buffering
                        ob_start();
        
                        // Include the necessary HTML structure
                        ?>
                        <!DOCTYPE html>
                        <html <?php language_attributes(); ?>>
                        <head>
                            <meta charset="<?php bloginfo('charset'); ?>">
                            <meta name="viewport" content="width=device-width, initial-scale=1">
                            <?php wp_head(); ?>
                        </head>
                        <body <?php body_class(); ?>>
                            <div class="container">
                                <h2>Instructions de paiement</h2>
                                <p>Votre paiement est en cours de traitement. Veuillez patienter...</p>
                                <div id="payment-status-message"></div>
        
                                <script type="text/javascript">
                                    // Function to check payment status
                                    function checkPaymentStatus() {
                                        jQuery.ajax({
                                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                            type: 'GET',
                                            dataType: 'json',
                                            data: {
                                                action: 'check_airtel_payment_status',
                                                order_id: '<?php echo $order_id; ?>'
                                            },
                                            success: function(response) {
                                                if (response.success) {
                                                    if (response.data.status === 'TS') {
                                                        // Payment successful, redirect to thank you page
                                                        window.location.href = response.data.redirect_url;
                                                    } else if (response.data.status === 'TF') {
                                                        // Payment failed, redirect to checkout page with error
                                                        alert('Le paiement a échoué. Veuillez réessayer.');
                                                        window.location.href = response.data.redirect_url;
                                                    } else if (response.data.status === 'TIP') {
                                                        // Payment still in progress, continue polling
                                                        document.getElementById('payment-status-message').innerText = 'Le paiement est toujours en cours. Veuillez patienter...';
                                                    } else {
                                                        // Handle unknown status
                                                        document.getElementById('payment-status-message').innerText = 'Statut inconnu du paiement. Veuillez réessayer.';
                                                    }
                                                } else {
                                                    // Handle error
                                                    document.getElementById('payment-status-message').innerText = 'Erreur lors de la vérification du statut du paiement.';
                                                }
                                            },
                                            error: function() {
                                                // Handle request error
                                                document.getElementById('payment-status-message').innerText = 'Erreur de communication avec le serveur.';
                                            }
                                        });
                                    }
        
                                    // Start polling every 5 seconds
                                    setInterval(checkPaymentStatus, 5000);
                                </script>
                            </div>
                            <?php wp_footer(); ?>
                        </body>
                        </html>
                        <?php
        
                        // End output buffering and output content
                        echo ob_get_clean();
        
                        exit; // Exit to stop loading the normal WordPress page
                    }
                }
            }
        }


        add_action('template_redirect', 'handle_airtel_money_intermediary_page');

        

        // AJAX handler for finalizing the payment

        function finalize_airtel_payment() {
            // Verify that order ID is set
            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $gateway = new WC_Airtel_Money_Gateway();
        
                    // Fetch Airtel Money access token
                    $access_token = $gateway->get_access_token();
                    if (!$access_token) {
                        wc_add_notice("Impossible d'effectuer le paiement : erreur de jeton d accès", 'error');
                        wp_redirect(wc_get_checkout_url());
                        exit;
                    }
        
                    // Verify the transaction status using UUID from order meta
                    $uuid = get_post_meta($order_id, '_airtel_payment_uuid', true);
                    error_log('UUID form post meta : ' . $uuid);
            if ($uuid) {
                $verify_response = $gateway->verify_transaction_status($uuid, $access_token);
        
                if ($verify_response && isset($verify_response->status->success) && $verify_response->status->success) {
                    // Access the transaction status
                    if (isset($verify_response->data->transaction->status)) {
                        $transaction_status = $verify_response->data->transaction->status;
        
                        // Handle different statuses
                        switch ($transaction_status) {
                            case 'TS': // Transaction Successful
                                // Mark the order as complete
                                $order->payment_complete();
                                wc_reduce_stock_levels($order_id);
        
                                // Redirect to thank you page
                                wp_redirect($gateway->get_return_url($order));
                                exit;
                                break;
        
                            case 'TF': // Transaction Failed
                                $message = isset($verify_response->data->transaction->message) ? $verify_response->data->transaction->message : '';
                                wc_add_notice('Paiement échoué: ' . $message, 'error');
                                wp_redirect(wc_get_checkout_url());
                                exit;
                                break;
        
                            case 'TIP': // Transaction In Progress
                                wc_add_notice('Le paiement est toujours en cours. Veuillez patienter un instant et réessayer.', 'notice');
                                wp_redirect(home_url('/airtel-money-intermediary/?order_id=' . $order_id));
                                exit;
                                break;
        
                            default:
                                wc_add_notice('Statut de transaction inconnu: ' . $transaction_status, 'error');
                                wp_redirect(wc_get_checkout_url());
                                exit;
                                break;
                        }
                    } else {
                        wc_add_notice('Impossible de récupérer le statut de la transaction.', 'error');
                        wp_redirect(wc_get_checkout_url());
                        exit;
                    }
                } else {
                    $error_message = 'La vérification du paiement a échoué.';
                    if (is_object($verify_response) && isset($verify_response->status->message)) {
                        $error_message .= ' ' . $verify_response->status->message;
                    }
                    wc_add_notice($error_message, 'error');
                    wp_redirect(wc_get_checkout_url());
                    exit;
                }
            } else {
                wc_add_notice("Impossible d'effectuer le paiement: ID de transaction manquant.", 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
                }
            } else {
                wc_add_notice('Impossible de terminer le paiement: ID de commande non valide.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
        }
        
        add_action('wp_ajax_check_airtel_payment_status', 'check_airtel_payment_status');
        add_action('wp_ajax_nopriv_check_airtel_payment_status', 'check_airtel_payment_status');
        
        function check_airtel_payment_status() {
            // Verify that order ID is set
            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $gateway = new WC_Airtel_Money_Gateway();
        
                    // Fetch Airtel Money access token
                    $access_token = $gateway->get_access_token();
                    if (!$access_token) {
                        wp_send_json_error('Unable to retrieve access token.');
                        exit;
                    }
        
                    // Verify the transaction status using UUID from order meta
                    $uuid = get_post_meta($order_id, '_airtel_payment_uuid', true);
                    if ($uuid) {
                        $verify_response = $gateway->verify_transaction_status($uuid, $access_token);
        
                        if ($verify_response && isset($verify_response->status->success) && $verify_response->status->success) {
                            // Access the transaction status
                            if (isset($verify_response->data->transaction->status)) {
                                $transaction_status = $verify_response->data->transaction->status;
        
                                if ($transaction_status == 'TS') {
                                    // Payment successful, complete the order
                                    $order->payment_complete();
                                    wc_reduce_stock_levels($order_id);
        
                                    // Send success response with redirect URL
                                    wp_send_json_success(array(
                                        'status' => 'TS',
                                        'redirect_url' => $gateway->get_return_url($order),
                                    ));
                                    exit;
                                } elseif ($transaction_status == 'TF') {
                                    // Payment failed, send error response
                                    wp_send_json_success(array(
                                        'status' => 'TF',
                                        'redirect_url' => wc_get_checkout_url(),
                                    ));
                                    exit;
                                } elseif ($transaction_status == 'TIP') {
                                    // Payment in progress, send response to continue polling
                                    wp_send_json_success(array(
                                        'status' => 'TIP',
                                    ));
                                    exit;
                                } else {
                                    // Unknown status
                                    wp_send_json_success(array(
                                        'status' => 'UNKNOWN',
                                    ));
                                    exit;
                                }
                            } else {
                                wp_send_json_error('Unable to retrieve transaction status.');
                                exit;
                            }
                        } else {
                            wp_send_json_error('Payment verification failed.');
                            exit;
                        }
                    } else {
                        wp_send_json_error('Unable to complete payment: Missing transaction ID.');
                        exit;
                    }
                }
            }
        
            wp_send_json_error('Invalid order ID.');
            exit;
        }

        function enqueue_intermediary_scripts() {
            if (is_page() && get_query_var('airtel_money_intermediary')) {
                wp_enqueue_script('jquery');
            }
        }
        add_action('wp_enqueue_scripts', 'enqueue_intermediary_scripts');


        
        add_action('wp_ajax_finalize_airtel_payment', 'finalize_airtel_payment');
        add_action('wp_ajax_nopriv_finalize_airtel_payment', 'finalize_airtel_payment');
    
?>