<?php
/*
  Plugin Name:  Monetbil
  Plugin URI: https://www.monetbil.com/
  Description: Une passerelle de paiement pour les paiements Mobile Money
  Version: 1.0
  Author: Serge NTONG
  Author URI: https://www.monetbil.com/
  Text Domain: monetbil
 */

add_action('plugins_loaded', 'init_monetbil_woocommerce_gateway', 0);

function init_monetbil_woocommerce_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * Gateway class
     * */
    class WC_Gateway_Monetbil extends WC_Payment_Gateway
    {

        const MONETBIL_WIDGET_URL = 'https://www.monetbil.com/widget/';
        const MONETBIL_MERCHANT_NAME = 'MONETBIL_MERCHANT_NAME';
        const MONETBIL_MERCHANT_EMAIL = 'MONETBIL_MERCHANT_EMAIL';
        const MONETBIL_SERVICE_KEY = 'MONETBIL_SERVICE_KEY';
        const MONETBIL_SERVICE_SECRET = 'MONETBIL_SERVICE_SECRET';
        const MONETBIL_SERVICE_NAME = 'MONETBIL_SERVICE_NAME';
        const MONETBIL_RETURN_URL = 'MONETBIL_RETURN_URL';

        protected $configured = false;

        public function __construct()
        {
            // The global ID for this Payment method
            $this->id = "monetbil";

            // The Title shown on the top of the Payment Gateways Page next to all the other Payment Gateways
            $this->method_title = __("Monetbil", 'monetbil');

            $this->order_button_text = __('Pay with MTN Mobile Money via Monetbil', 'monetbil');

            // The description for this Payment Gateway, shown on the actual Payment options page on the backend
            $this->method_description = __("Payment Gateway for WooCommerce", 'monetbil');

            // The title to be used for the vertical tabs that can be ordered top to bottom
            $this->title = __("Monetbil", 'monetbil');

            // If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
            $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/monetbil-mtnmomo-logo.png';

            // Bool. Can be set to true if you want payment fields to show on the checkout
            // if doing a direct integration, which we are doing in this case
            $this->has_fields = true;

            // Supports the default credit card form
            $this->supports = array('subscriptions', 'products', 'subscription_cancellation', 'subscription_reactivation', 'subscription_suspension', 'subscription_amount_changes', 'subscription_date_changes');

            // This basically defines your settings which are then loaded with init_settings()
            $this->init_form_fields();

            // After init_settings() is called, you can get the settings and load them into variables, e.g:
            // $this->title = $this->get_option( 'title' );
            $this->init_settings();

            // Get setting values
            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->service_key = $this->get_option('service_key');
            $this->service_secret = $this->get_option('service_secret');

            add_action('woocommerce_receipt_' . $this->id, array($this, 'payment_page'));

            // Save settings
            if (is_admin()) {
                // Versions over 2.0
                // Save our administration options. Since we are not going to be doing anything special
                // we have not defined 'process_admin_options' in this class so the method in the parent
                // class will be used instead
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

                $this->createPages();
            }
        }

        public function admin_options()
        {
            ?>

            <h3><?php echo (!empty($this->method_title) ) ? $this->method_title : __('Settings', 'woocommerce'); ?></h3>

            <?php echo (!empty($this->method_description) ) ? wpautop($this->method_description) : ''; ?>
            <style type="text/css">
                .alert {
                    border: 1px solid transparent;
                    border-radius: 0;
                    margin-bottom: 18px;
                    padding: 15px;
                    font-weight: bold;
                    color: #fff;
                }
                .alert-info {
                    background-color: #5192f3;
                }
                .alert-error {
                    background-color: #ff0000;
                }
            </style>
            <?php $this->generateNotice() ?>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><?php
        }

        public function process_admin_options()
        {
            $service_key = $this->getPost('woocommerce_monetbil_service_key');
            $service_secret = $this->getPost('woocommerce_monetbil_service_secret');
            $service = $this->getService($service_key, $service_secret);

            if (array_key_exists('service_key', $service)
                    and array_key_exists('service_secret', $service)
                    and array_key_exists('service_name', $service)
                    and array_key_exists('Merchants', $service)
            ) {
                update_option(self::MONETBIL_MERCHANT_NAME, $service['Merchants']['first_name'] . ' ' . $service['Merchants']['last_name']);
                update_option(self::MONETBIL_MERCHANT_EMAIL, $service['Merchants']['email']);
                update_option(self::MONETBIL_SERVICE_NAME, $service['service_name']);

                parent::process_admin_options();
            }
        }

        // Build the administration fields for this specific Gateway
        public function init_form_fields()
        {
            $merchant_name = get_option(self::MONETBIL_MERCHANT_NAME);
            $merchant_email = get_option(self::MONETBIL_MERCHANT_EMAIL);
            $service_name = get_option(self::MONETBIL_SERVICE_NAME);

            if ($merchant_name
                    and $merchant_email
                    and $service_name
            ) {
                $this->configured = true;
            }

            $this->form_fields = array();

            $this->form_fields['enabled'] = array(
                'title' => __('Enable / Disable', 'monetbil'),
                'label' => __('Enable this payment gateway.', 'monetbil'),
                'type' => 'checkbox',
                'default' => 'yes',
                'desc_tip' => true,
            );
            $this->form_fields['title'] = array(
                'title' => __('Title', 'monetbil'),
                'type' => 'text',
                'desc_tip' => __('Payment title that the customer will see during the ordering process.', 'monetbil'),
                'default' => __('MTN Mobile Money', 'monetbil'),
            );
            $this->form_fields['description'] = array(
                'title' => __('Description', 'monetbil'),
                'type' => 'textarea',
                'desc_tip' => __('Payment description that the customer will see during the ordering process.', 'monetbil'),
                'default' => __('Pay safely using your MTN Mobile Money account.', 'monetbil'),
                'css' => 'max-width:350px;'
            );

            if ($merchant_name) {
                $this->form_fields['merchant_name'] = array(
                    'title' => __('Merchant name', 'monetbil'),
                    'type' => 'text',
                    'disabled' => true,
                    'css' => 'color:#000;font-weight:bold;',
                    'placeholder' => $merchant_name
                );
            }

            if ($merchant_email) {
                $this->form_fields['merchant_email'] = array(
                    'title' => __('Merchant email', 'monetbil'),
                    'type' => 'text',
                    'disabled' => true,
                    'css' => 'color:#000;font-weight:bold;',
                    'placeholder' => $merchant_email
                );
            }

            if ($service_name) {
                $this->form_fields['service_name'] = array(
                    'title' => __('Service name', 'monetbil'),
                    'type' => 'text',
                    'disabled' => true,
                    'css' => 'color:#000;font-weight:bold;',
                    'placeholder' => $service_name
                );
            }

            $this->form_fields['service_key'] = array(
                'title' => __('Service key', 'monetbil'),
                'type' => 'text',
                'desc_tip' => __('This is the service key provided by Monetbil when you created a service.', 'monetbil'),
            );
            $this->form_fields['service_secret'] = array(
                'title' => __('Service secret', 'monetbil'),
                'type' => 'text',
                'desc_tip' => __('This is the service secret Monetbil generated when creating a service.', 'monetbil'),
            );
        }

        // Submit payment and handle response
        public function process_payment($order_id)
        {
            $customer_order = new WC_Order($order_id);

            $customer_order->update_status('pending', __('Awaiting payment MTN Mobile Money', 'monetbil'));

            return array(
                'result' => 'success',
                'redirect' => $customer_order->get_checkout_payment_url(true)
            );
        }

        // Output iframe
        public function payment_page($order_id)
        {
            // Get this Order's information so that we know
            // who to charge and how much
            $customer_order = new WC_Order($order_id);

            $customer_order->add_order_note(__('Awaiting payment MTN Mobile Money', 'monetbil'));

            $total = round($customer_order->order_total, 0, PHP_ROUND_HALF_UP);

            $return_url = get_option('monetbil_return_url');
            $notify_url = '';

            $query = array(
                'amount' => $total,
                'country' => 'CM',
                'user' => get_current_user_id(),
                'currency' => get_option('woocommerce_currency', 'XAF'),
                'email' => $customer_order->billing_email,
                'item_ref' => $order_id,
                'payment_ref' => $customer_order->order_key,
                'locale' => 'fr',
                'last_name' => $customer_order->billing_last_name,
                'first_name' => $customer_order->billing_first_name,
                'return_url' => $return_url,
                'notify_url' => $notify_url
            );

            $monetbil_service_key = $this->service_key;
            $monetbil_service_secret = $this->service_secret;

            $query['sign'] = $this->sign($monetbil_service_secret, $query);

            $monetbil_url = WC_Gateway_Monetbil::MONETBIL_WIDGET_URL . $monetbil_service_key . '?' . http_build_query($query);

            echo '<iframe src="' . $monetbil_url . '" frameborder="0" style="width: 100%;height: 552px;border: 0;"></iframe>';
        }

        public function generateNotice()
        {
            if ($this->configured) {
                ?>
                <p class="alert alert-info">
                    <span class="dashicons dashicons-yes"></span>  <?php echo __("Service perfectly configured", 'monetbil'); ?>
                </p>
                <?php
            } else {
                ?>
                <p class="alert alert-error">
                    <span class="dashicons dashicons-no"></span>  <?php echo __("Service not configured", 'monetbil'); ?>
                </p>
                <?php
            }
        }

        public function createPages()
        {
            $post_id = $this->insertPost('Monetbil Redirect', 'monetbil-redirect', '[monetbil_redirect]');

            if ($post_id > 0) {
                update_option(self::MONETBIL_RETURN_URL, esc_url(get_permalink($post_id)));
            }
        }

        public function insertPost($post_title, $post_slug, $post_content)
        {
            // Initialize the page ID to -1. This indicates no action has been taken.
            $post_id = -1;

            // If the page doesn't already exist, then create it
            if (null == get_page_by_title($post_title)) {
                // Set the post ID so that we know the post was created successfully
                $post_id = wp_insert_post(array(
                    'comment_status' => 'closed',
                    'post_content' => $post_content,
                    'ping_status' => 'closed',
                    'post_author' => get_current_user_id(),
                    'post_name' => $post_slug,
                    'post_title' => $post_title,
                    'post_status' => 'publish',
                    'post_type' => 'page'
                ));
            }

            return $post_id;
        }

        public function getService($service_key, $service_secret)
        {
            $postData = array(
                'service_key' => $service_key,
                'service_secret' => $service_secret,
            );

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, 'https://api.monetbil.com/v1/services/get');
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData, '', '&'));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $json = curl_exec($ch);
            $result = json_decode($json, true);

            if (is_array($result)) {
                return $result;
            }
            return array();
        }

        public function sign($service_secret, $params)
        {
            ksort($params);
            $signature = md5($service_secret . implode('', $params));
            return $signature;
        }

        public function checkSign($service_secret, $params)
        {
            if (!array_key_exists('sign', $params)) {
                return false;
            }

            $sign = $params['sign'];
            unset($params['sign']);

            $signature = $this->sign($service_secret, $params);

            return ($sign == $signature);
        }

        public function checkPayment($paymentId)
        {
            $postData = array(
                'paymentId' => $paymentId
            );

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, 'https://api.monetbil.com/mtnmobilemoney/v1/checkPayment');
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData, '', '&'));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $json = curl_exec($ch);
            $result = json_decode($json, true);

            if (is_array($result) and array_key_exists('transaction', $result)) {
                $transaction = $result['transaction'];
                $status = $transaction['status'];

                if ($status == 1) {
                    return true;
                }

                return false;
            }
        }

        public function getPost($key = null, $default = null)
        {
            return $key == null ? $_POST : (isset($_POST[$key]) ? $_POST[$key] : $default);
        }

        public function getQuery($key = null, $default = null)
        {
            return $key == null ? $_GET : (isset($_GET[$key]) ? $_GET[$key] : $default);
        }

        public function getQueryParams()
        {
            $queryParams = array();
            $parts = explode('?', $this->getUrl());

            if (isset($parts[1])) {
                parse_str($parts[1], $queryParams);
            }

            return $queryParams;
        }

        public function getUrl()
        {
            $server_name = $_SERVER['SERVER_NAME'];
            $port = $_SERVER['SERVER_PORT'];
            $scheme = 'http';

            if ('443' === $port) {
                $scheme = 'https';
            }

            $url = $scheme . '://' . $server_name . $this->getUri();
            return $url;
        }

        public function getUri()
        {
            $requestUri = $_SERVER['REQUEST_URI'];
            $uri = '/' . ltrim($requestUri, '/');

            return $uri;
        }

        public function forceRedirect($customer_order)
        {
            // Redirect to thank you page
            echo ''
            . '<script type="text/javascript">'
            . 'location.href="' . $this->get_return_url($customer_order) . '";'
            . '</script>';
        }

    }

    /**
     * Add the Gateway to WooCommerce
     * */
    function add_monetbil_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Monetbil';
        return $methods;
    }

    function init_monetbil_gateway()
    {
        $plugin_dir = basename(dirname(__FILE__));
        load_plugin_textdomain('woocommerce-gateway-monetbil', false, $plugin_dir . '/languages/');
    }

    add_filter('woocommerce_payment_gateways', 'add_monetbil_gateway');
    add_action('plugins_loaded', 'init_monetbil_gateway');

    function WC_Gateway_Monetbil()
    {
        return new WC_Gateway_Monetbil();
    }

    function monetbil_redirect_page()
    {
        global $woocommerce;

        $woocommerce instanceof WooCommerce;

        $module = new WC_Gateway_Monetbil();

        $item_ref = $module->getQuery('item_ref');
        $transaction_id = $module->getQuery('transaction_id');

        $params = $module->getQueryParams();

        $monetbil_service_secret = $module->service_secret;

        $order_id = $item_ref;

        // Get this Order's information so that we know
        // who to charge and how much
        $customer_order = new WC_Order($order_id);

        if (!$module->checkSign($monetbil_service_secret, $params)) {
            $module->forceRedirect($customer_order);
        }

        $success = $module->checkPayment($transaction_id);

        if ($success) {
            $order_state = 'completed';
            // Payment has been successful
            // Mark order as Paid
            $customer_order->payment_complete($transaction_id);
            $customer_order->update_status($order_state);

            //Empty the cart (Very important step)
            $woocommerce->cart->empty_cart();
        } else {
            $order_state = 'failed';
            $customer_order->update_status($order_state);
        }

        $module->forceRedirect($customer_order);
    }

    // Add Shortcode
    add_shortcode('monetbil_redirect', 'monetbil_redirect_page');

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'addActionLinks', 10, 1);

    function addActionLinks($actions)
    {
        $custom_actions = array(
            'Create An Account' => '<a href="https://www.monetbil.com/try-monetbil" target="_blank">' . __('Create An Account') . '</a>',
            'Create new service' => '<a href="https://www.monetbil.com/services/create" target="_blank">' . __('Create new service') . '</a>'
        );

        return array_merge($custom_actions, $actions);
    }

}
