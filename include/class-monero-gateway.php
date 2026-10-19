<?php
/*
 * Main Gateway of Monero using either a local daemon or the explorer
 * Authors: SerHack, cryptochangements, mosu-forge
 */

defined( 'ABSPATH' ) || exit;

require_once('class-monero-cryptonote.php');

// Where the Monero failover watchdog reports which node each wallet is on
// (see wallet_on_backup()). Tests may point it elsewhere.
defined('MONERO_FAILOVER_STATE_DIR') || define('MONERO_FAILOVER_STATE_DIR', '/var/lib/monero-failover/site-state');

class Monero_Gateway extends WC_Payment_Gateway
{
    private static $_id = 'monero_gateway';
    private static $_title = 'Monero Gateway';
    private static $_method_title = 'Monero Gateway';
    private static $_method_description = 'Monero Gateway Plug-in for WooCommerce.';
    private static $_errors = [];

    private static $discount = false;
    private static $valid_time = null;
    private static $confirms = null;
    private static $confirm_type = null;
    private static $address = null;
    private static $viewkey = null;
    private static $host = null;
    private static $port = null;
    private static $testnet = false;
    private static $onion_service = false;
    private static $show_qr = false;
    private static $use_monero_price = false;
    private static $use_monero_price_decimals = MONERO_GATEWAY_ATOMIC_UNITS;

    private static $cryptonote;
    private static $monero_wallet_rpc;
    private static $monero_explorer_tools;
    private static $log;

    private static $currencies = array('AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTC', 'BTN', 'BWP', 'BYN', 'BYR', 'BZD', 'CAD', 'CDF', 'CHF', 'CLF', 'CLP', 'CNY', 'COP', 'CRC', 'CUC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GGP', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'IMP', 'INR', 'IQD', 'IRR', 'ISK', 'JEP', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LTL', 'LVL', 'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VEF', 'VND', 'VUV', 'WST', 'XAF', 'XAG', 'XAU', 'XCD', 'XDR', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMK', 'ZMW', 'ZWL');
    private static $rates = array();

    private static $payment_details = array();

    // Limits for a wallet synced over the internet and a cron-fed price.
    private static $wallet_stall_seconds = 1200; // wallet height unchanged this long: not following the chain
    private static $rate_max_age = 300;         // an older XMR rate is not used for a new checkout
    private static $kraken_pairs = array('usd' => 'XXMRZUSD', 'eur' => 'XXMRZEUR', 'btc' => 'XXMRXXBT'); // Kraken's XMR markets
    private static $kraken_max_spread = 0.02;   // a wider bid/ask gap than this (of the midpoint) is no price
    private static $tx_time_slack = 7200;        // how far a transfer may predate its quote (block times may lag ~1 h)
    private static $failover_state_max_age = 180; // failover watchdog report older than this: in doubt, hold decisions

    public function get_icon()
    {
        return apply_filters('woocommerce_gateway_icon', '<img src="'.MONERO_GATEWAY_PLUGIN_URL.'assets/images/monero-icon.png" style="max-height:24px;width:auto;vertical-align:middle;" alt="Monero"/>', $this->id);
    }

    function __construct($add_action=true)
    {
        $this->id = self::$_id;
        $this->method_title = __(self::$_method_title, 'monero_gateway');
        $this->method_description = __(self::$_method_description, 'monero_gateway');
        $this->has_fields = false;
        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change'
        );

        $this->enabled = $this->get_option('enabled') == 'yes';

        $this->init_form_fields();
        $this->init_settings();

        self::$_title = $this->settings['title'];
        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];
        if(!empty($this->settings['discount']) && $this->settings['discount'] > 0) {
            $this->description .= ' <strong>Save ' . intval($this->settings['discount']) . '% when you pay with Monero!</strong>';
            if(!is_admin()) {
                $this->title .= ' — Save ' . intval($this->settings['discount']) . '%!';
            }
        }
        self::$discount = $this->settings['discount'];
        self::$valid_time = $this->settings['valid_time'];
        self::$confirms = $this->settings['confirms'];
        self::$confirm_type = $this->settings['confirm_type'];
        self::$address = $this->settings['monero_address'];
        self::$viewkey = $this->settings['viewkey'];
        self::$host = $this->settings['daemon_host'];
        self::$port = $this->settings['daemon_port'];
        self::$testnet = $this->settings['testnet'] == 'yes';
        self::$onion_service = $this->settings['onion_service'] == 'yes';
        self::$show_qr = $this->settings['show_qr'] == 'yes';
        self::$use_monero_price = $this->settings['use_monero_price'] == 'yes';
        self::$use_monero_price_decimals = $this->settings['use_monero_price_decimals'];

        $explorer_url = self::$testnet ? MONERO_GATEWAY_TESTNET_EXPLORER_URL : MONERO_GATEWAY_MAINNET_EXPLORER_URL;
        defined('MONERO_GATEWAY_EXPLORER_URL') || define('MONERO_GATEWAY_EXPLORER_URL', $explorer_url);

        // Add the currency of the shop to $currencies array. Needed for do_update_event() function
        $currency_shop = get_woocommerce_currency();
		array_push(self::$currencies, $currency_shop);
        
        if($add_action)
            add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));

        // Initialize helper classes
        self::$cryptonote = new Monero_Cryptonote();
        if(self::$confirm_type == 'monero-wallet-rpc') {
            require_once('class-monero-wallet-rpc.php');
            self::$monero_wallet_rpc = new Monero_Wallet_Rpc(self::$host, self::$port);
        } else if(self::$confirm_type == 'viewkey') {
            require_once('class-monero-explorer-tools.php');
            self::$monero_explorer_tools = new Monero_Explorer_Tools(self::$testnet);
        }
        // 'manual' mode requires no external services

        self::$log = new WC_Logger();
    }

    public function init_form_fields()
    {
        $this->form_fields = include 'admin/monero-gateway-admin-settings.php';
    }

    public function validate_monero_address_field($key,$address)
    {
        if($this->settings['confirm_type'] == 'viewkey' || $this->settings['confirm_type'] == 'manual') {
            if (strlen($address) == 95 && substr($address, 0, 1) == '4')
                if(self::$cryptonote->verify_checksum($address))
                    return $address;
            self::$_errors[] = 'Monero address is invalid';
        }
        return $address;
    }

    public function validate_viewkey_field($key,$viewkey)
    {
        if($this->settings['confirm_type'] == 'viewkey') {
            if(preg_match('/^[a-z0-9]{64}$/i', $viewkey)) {
                return $viewkey;
            } else {
                self::$_errors[] = 'Viewkey is invalid';
                return '';
            }
        }
        return $viewkey;
    }

    public function validate_confirms_field($key,$confirms)
    {
        if($confirms >= 0 && $confirms <= 60)
            return $confirms;
        self::$_errors[] = 'Number of confirms must be between 0 and 60';
    }

    public function validate_valid_time_field($key,$valid_time)
    {
        if($valid_time >= 600 && $valid_time < 86400*7)
            return $valid_time;
        self::$_errors[] = 'Order valid time must be between 600 (10 minutes) and 604800 (1 week)';
    }

    public function admin_options()
    {
        $confirm_type = self::$confirm_type;
        if($confirm_type === 'monero-wallet-rpc')
            $balance = self::admin_balance_info();

        $settings_html = $this->generate_settings_html(array(), false);
        $errors = array_merge(self::$_errors, $this->admin_php_module_check(), $this->admin_ssl_check());
        include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/admin/settings-page.php';
    }

    public static function admin_balance_info()
    {
        if(!is_admin()) {
            return array(
                'height' => 'Not Available',
                'balance' => 'Not Available',
                'unlocked_balance' => 'Not Available',
            );
        }
        $wallet_amount = self::$monero_wallet_rpc->getbalance();
        $height = self::$monero_wallet_rpc->getheight();
        if (!isset($wallet_amount)) {
            self::$_errors[] = 'Cannot connect to monero-wallet-rpc';
            self::$log->add('Monero_Payments', '[ERROR] Cannot connect to monero-wallet-rpc');
            return array(
                'height' => 'Not Available',
                'balance' => 'Not Available',
                'unlocked_balance' => 'Not Available',
            );
        } else {
            return array(
                'height' => $height,
                'balance' => self::format_monero($wallet_amount['balance']).' Monero',
                'unlocked_balance' => self::format_monero($wallet_amount['unlocked_balance']).' Monero'
            );
        }
    }

    protected function admin_ssl_check()
    {
        $errors = array();
        if ($this->enabled && !self::$onion_service)
            if (get_option('woocommerce_force_ssl_checkout') == 'no')
                $errors[] = sprintf('%s is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href="%s">forcing the checkout pages to be secured.</a>', self::$_method_title, admin_url('admin.php?page=wc-settings&tab=checkout'));
        return $errors;
    }

    protected function admin_php_module_check()
    {
        $errors = array();
        if(!extension_loaded('bcmath'))
            $errors[] = 'PHP extension bcmath must be installed';
        return $errors;
    }

    public function process_payment($order_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix.'monero_gateway_quotes';

        $order = wc_get_order($order_id);

        // A customer retrying from "Pay for order" keeps the quote they were
        // already given instead of receiving a second address for one order.
        $existing = $wpdb->get_row($wpdb->prepare("SELECT *, NOW() AS now FROM $table_name WHERE order_id=%d", $order_id));
        if($existing) {
            if(self::recovery_hold($existing)) {
                wc_add_notice(__('This historical payment request needs manual review. Please contact the shop before paying.', 'monero_gateway'), 'error');
                return array('result' => 'failure');
            }
            if($existing->pending == 1 && !empty($existing->payment_id)) {
                // "Pay for order" also takes failed orders (a declined card).
                // The payment checks only move orders awaiting Monero, so put
                // it back to Pending payment with its stock taken again.
                if(!$order->has_status(array('pending', 'on-hold')))
                    self::await_monero_payment($order);
                WC()->cart->empty_cart();
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }
            wc_add_notice(__('Payment error:', 'woothemes') . ' ' . __('This order already has a Monero payment request. Please contact us to complete it.', 'monero_gateway'), 'error');
            return array('result' => 'failure');
        }

        // Price first, so a price outage never consumes a wallet subaddress.
        $currency = $order->get_currency();
        $rate = self::get_live_rate($currency);
        $fiat_amount = $order->get_total('');

        if($rate != 0)
            $monero_amount = 1e8 * $fiat_amount / $rate;
        else{
            // Critical, the price has not been retrivied.
            $monero_amount = -1;
            $error_message = "The price for Monero could not be retrieved. Please contact the merchant.";
            self::$log->add('Monero_Payments', "[ERROR] Impossible to retrieve price for order: ".$order_id);
            wc_add_notice( __('Payment error:', 'woothemes') . $error_message, 'error' );
            return array('result' => 'failure');
        }

        // The rate is only refreshed by the payment cron. If that stops, the
        // stored rate can be days old and would misprice the order, so take
        // Monero only while it is recent (aged by the DB's clock, not PHP's).
        $rate_age = self::get_live_rate_age($currency);
        if($rate_age === null || $rate_age < 0 || $rate_age > self::$rate_max_age) {
            self::$log->add('Monero_Payments', '[ERROR] Monero rate for ' . $currency . ' is ' . ($rate_age === null ? 'of unknown age' : intval($rate_age / 60) . ' minutes old') . '; refusing checkout for order ' . $order_id . '.');
            wc_add_notice(__('Payment error:', 'woothemes') . ' ' . __('Monero payments are temporarily unavailable. Please choose another payment method or try again shortly.', 'monero_gateway'), 'error');
            return array('result' => 'failure');
        }

        if(self::$confirm_type != 'monero-wallet-rpc') {
          // Generate a unique payment id
          do {
              $payment_id = bin2hex(openssl_random_pseudo_bytes(8));
              $query = $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE payment_id=%s", array($payment_id));
              $payment_id_used = $wpdb->get_var($query);
          } while ($payment_id_used);
        }
        else {
          // Generate subaddress. Without one the customer has nowhere to pay,
          // so checkout must stop here rather than accept an unpayable order.
          $payment_id = self::create_unused_subaddress('Order: ' . $order_id);
          if($payment_id === null) {
            wc_add_notice(__('Payment error:', 'woothemes') . ' ' . __('Monero payments are temporarily unavailable. Please choose another payment method or try again shortly.', 'monero_gateway'), 'error');
            return array('result' => 'failure');
          }
        }

        if(self::$discount)
            $monero_amount = $monero_amount - $monero_amount * self::$discount / 100;

        $monero_amount = intval($monero_amount * MONERO_GATEWAY_ATOMIC_UNITS_POW);

        // Without its quote the order could never be matched to a payment.
        $query = $wpdb->prepare("INSERT INTO $table_name (order_id, payment_id, currency, rate, amount) VALUES (%d, %s, %s, %d, %d)", array($order_id, $payment_id, $currency, $rate, $monero_amount));
        if(!$wpdb->query($query)) {
            self::$log->add('Monero_Gateway', '[ERROR] Couldn\'t save the Monero quote for order ' . $order_id . '; refusing checkout.');
            wc_add_notice(__('Payment error:', 'woothemes') . ' ' . __('Monero payments are temporarily unavailable. Please choose another payment method or try again shortly.', 'monero_gateway'), 'error');
            return array('result' => 'failure');
        }

        self::await_monero_payment($order);
        WC()->cart->empty_cart(); // Remove cart

        // A mail failure must never undo a checkout that already succeeded.
        try {
            self::send_payment_instructions($order);
        } catch(Throwable $e) {
            self::$log->add('Monero_Gateway', '[ERROR] Payment instructions email failed for order ' . $order_id . ': ' . $e->getMessage());
        }

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /*
     * WooCommerce sends no emails for Pending orders, so send the customer
     * the address, amount and deadline ourselves; otherwise closing the
     * payment page would lose them.
     */
    protected static function send_payment_instructions($order)
    {
        $to = $order->get_billing_email();
        if(!$to)
            return;
        $order_id = $order->get_id();
        unset(self::$payment_details[$order_id]);
        $details = self::get_payment_details($order_id);
        if(!is_array($details) || $details['status'] !== 'unpaid')
            return;

        $method_title = self::$_title;
        ob_start();
        echo '<p style="margin: 0 0 16px;">' . sprintf(esc_html__('Thank you for your order #%s. It will stay "Pending payment" until your Monero payment is confirmed on the network, and we will email you again when it is.', 'monero_gateway'), esc_html($order->get_order_number())) . '</p>';
        include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/customer/order-email-block.php';
        $body = ob_get_clean();

        $mailer = WC()->mailer();
        $subject = sprintf(__('Monero payment details for order #%s', 'monero_gateway'), $order->get_order_number());
        if($mailer->send($to, $subject, $mailer->wrap_message($subject, $body)))
            $order->add_order_note(__('Monero payment details emailed to the customer.', 'monero_gateway'));
    }

    /*
     * Stay "Pending payment" until the wallet sees the Monero arrive; the
     * payment checks move it to Processing/Completed, or cancel on expiry.
     */
    protected static function await_monero_payment($order)
    {
        $note = __('Awaiting Monero payment. The order moves to Processing once the payment is confirmed on the Monero network.', 'monero_gateway');
        if($order->has_status('pending'))
            $order->add_order_note($note);
        else
            $order->update_status('pending', $note);
        // Reserve stock during the payment window. The "maybe" variant also
        // flags the order as stock-reduced, which is what lets a cancel on
        // expiry give the stock back.
        wc_maybe_reduce_stock_levels($order->get_id());
        // The stock is taken now; the checkout's own hold on it would count
        // it a second time (the old on-hold step released it the same way).
        wc_release_stock_for_order($order);
    }

    /*
     * True while $order waits on an open Monero payment request, paid or
     * not. WooCommerce must leave such an order alone: no automatic unpaid
     * cancel, and no customer Cancel or Pay from My Account, since the
     * Monero may already be on its way.
     */
    public static function has_open_quote($order)
    {
        global $wpdb;
        if(!($order instanceof WC_Order) || $order->get_payment_method() != self::$_id)
            return false;
        $table_name = $wpdb->prefix.'monero_gateway_quotes';
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE order_id=%d AND pending=1", array($order->get_id())))) > 0;
    }

    /*
     * Only a web order still waiting on this gateway may be moved by a
     * payment check. A customer who switched to another payment method,
     * cancelled, or was already confirmed must be left alone.
     */
    protected static function order_awaits_monero($order)
    {
        return $order
            && $order->get_payment_method() == self::$_id
            && $order->has_status(array('pending', 'on-hold'));
    }

    protected static function mark_order_paid($order, $order_id)
    {
        if(!self::order_awaits_monero($order)) {
            if($order)
                $order->add_order_note(__('A Monero payment was confirmed for this order, but it was no longer awaiting Monero, so its status was not changed. Please review.', 'monero_gateway'));
            return;
        }
        if(self::is_virtual_in_cart($order_id) == true){
            $order->update_status('completed', __('Payment has been received.', 'monero_gateway'));
        } else {
            $order->update_status('processing', __('Payment has been received.', 'monero_gateway'));
        }
    }

    protected static function expire_order($order)
    {
        if(self::order_awaits_monero($order))
            $order->update_status('cancelled', __('Payment has expired.', 'monero_gateway'));
    }

    /*
     * monero-wallet-rpc answers getheight from its own copy of the chain even
     * while it cannot reach its daemon, so a height alone does not prove it
     * can see new payments. It counts as current only if its height moved in
     * the last $wallet_stall_seconds and is not racing ahead (still catching
     * up after an outage or a restore). The reading is kept in an option so
     * the cron and the payment page share it; 'changed' is when the height
     * was last seen to advance, or 0 if it has not been seen to since a
     * reading that proves nothing (first one, a drop, a gap or a jump).
     * $log is false for the payment page, which polls every few seconds.
     */
    protected static function wallet_is_current($height, $log = true)
    {
        $height = intval($height);
        if(defined('WARDSACE_MONERO_REQUIRE_NODE_HEALTH') && WARDSACE_MONERO_REQUIRE_NODE_HEALTH) {
            $health = self::protected_node_health();
            if($health === null || abs($health['height'] - $height) > 2) {
                if($log) self::$log->add('Monero_Payments', '[HOLD] Wallet height does not match a fresh protected node check.');
                return false;
            }
        }
        $now = time();
        $seen = get_option('monero_gateway_wallet_height');
        if(!is_array($seen) || !isset($seen['height'], $seen['changed'], $seen['checked']))
            $seen = null;

        $changed = 0;
        if($seen === null) {
            $problem = 'first height reading, nothing to compare it with yet';
        } else if($height == $seen['height']) {
            $changed = intval($seen['changed']);
            $problem = 'height ' . $height . ' has not advanced ' . ($changed ? 'for ' . intval(($now - $changed) / 60) . ' minutes (daemon unreachable?)' : 'since the last reading that could not be trusted');
        } else if($height < $seen['height'] || $now - $seen['checked'] > self::$wallet_stall_seconds) {
            $problem = 'height moved from ' . $seen['height'] . ' to ' . $height . ' with no recent reading to compare';
        } else if($height - $seen['height'] > 5 + 2 * ($now - $seen['checked']) / MONERO_GATEWAY_DIFFICULTY_TARGET) {
            // The network makes a block about every two minutes; a much larger
            // jump since the last reading means the wallet is still catching up.
            $problem = 'height jumped from ' . $seen['height'] . ' to ' . $height . ', still catching up';
        } else {
            // Only a small step up from a recent reading shows new blocks arriving.
            $changed = $now;
        }
        $current = $changed > 0 && $now - $changed <= self::$wallet_stall_seconds;

        // Write when the height moves, else at most once a minute (the
        // payment page polls far more often than that).
        if($seen === null || $height != $seen['height'] || $now - $seen['checked'] >= 60) {
            update_option('monero_gateway_wallet_height', array(
                'height' => $height,
                'changed' => $changed,
                'checked' => $now,
            ), false);
        }

        if(!$current && $log)
            self::$log->add('Monero_Payments', ($seen === null ? '[INFO]' : '[ERROR]') . ' Wallet RPC not confirmed in sync (' . $problem . '); skipping payment checks and expiry this run.');
        return $current;
    }

    /*
     * Why the wallet may not make a final payment decision now, or null.
     * When the Nodo fails, the failover watchdog moves the wallet to a public
     * backup node, which could show it transfers that are not real (or hide
     * some). The watchdog writes wallet-<rpc port>.json in
     * MONERO_FAILOVER_STATE_DIR on every run. No file: no watchdog, so the
     * wallet is on its own daemon. A file that cannot be read, is more than
     * $failover_state_max_age old (watchdog stopped), or does not say
     * on_primary: true holds decisions (fail closed). So does a file PHP
     * cannot even see (open_basedir, a folder it may not read): only a
     * provably missing one means "no watchdog".
     */
    protected static function wallet_hold_reason()
    {
        if(self::$confirm_type != 'monero-wallet-rpc')
            return null;
        if(defined('WARDSACE_MONERO_REQUIRE_NODE_HEALTH') && WARDSACE_MONERO_REQUIRE_NODE_HEALTH && self::protected_node_health() === null)
            return 'protected node health unavailable or stale';
        $file = rtrim(MONERO_FAILOVER_STATE_DIR, '/') . '/wallet-' . intval(self::$port) . '.json';
        clearstatcache(true, $file);
        // Only a regular file is read (a fifo there would block the page).
        $raw = @is_file($file) ? @file_get_contents($file) : false;
        if($raw === false)
            return self::failover_state_absent($file) ? null : 'state unreadable';
        $state = json_decode($raw, true);
        if(!is_array($state) || !isset($state['wallet'], $state['rpc_port'], $state['on_primary'], $state['daemon'], $state['updated']) ||
            intval($state['rpc_port']) !== intval(self::$port) || !(is_int($state['updated']) || is_float($state['updated'])))
            return 'state unreadable';
        // A time ahead of the clock does not prove a live watchdog either.
        $age = time() - intval($state['updated']);
        if(abs($age) > self::$failover_state_max_age)
            return 'state stale (written ' . $age . ' s ago)';
        if($state['on_primary'] !== true)
            return 'on backup' . (is_string($state['daemon']) && preg_match('/^[\w.:\/\[\]-]{1,255}$/D', $state['daemon']) ? ' node ' . $state['daemon'] : '');
        return null;
    }

    /*
     * True while the wallet (wallet RPC mode) is on a backup node, or the
     * watchdog's report on it is in doubt: nothing may then be confirmed or
     * expired. The other modes do not use the wallet and are never held.
     */
    public static function wallet_on_backup()
    {
        return self::wallet_hold_reason() !== null;
    }

    /*
     * True only when $file provably does not exist: its name is missing from
     * the listing of the nearest folder above it that PHP can read. Not
     * being able to open or list it proves nothing.
     */
    private static function failover_state_absent($file)
    {
        $name = basename($file);
        for($dir = dirname($file); ; $dir = dirname($dir)) {
            $list = @scandir($dir);
            if($list !== false)
                return !in_array($name, $list, true);
            if(dirname($dir) === $dir)
                return false;
            $name = basename($dir);
        }
    }

    /*
     * True once an unpaid quote may be expired: past valid_time (aged by the
     * DB's clock) and, with wallet RPC, the wallet has taken a new block a
     * block time or more after the deadline. A wallet cut off just before
     * the deadline cannot have seen a payment sent in its last minutes.
     * In-store requests use it too (their rows also carry created/now).
     */
    public static function quote_has_expired($quote)
    {
        $timestamp_created = new DateTime($quote->created);
        $timestamp_now = new DateTime($quote->now);
        $order_age_seconds = $timestamp_now->getTimestamp() - $timestamp_created->getTimestamp();
        if($order_age_seconds <= self::$valid_time)
            return false;
        if(self::$confirm_type != 'monero-wallet-rpc')
            return true;
        $seen = get_option('monero_gateway_wallet_height');
        $changed = is_array($seen) && isset($seen['changed']) ? intval($seen['changed']) : 0;
        return $changed >= time() - $order_age_seconds + self::$valid_time + MONERO_GATEWAY_DIFFICULTY_TARGET;
    }

    /*
     * True if this site already gave out $address: on a web order's quote
     * (any status), in a recorded transfer, or on an in-store request.
     */
    protected static function subaddress_already_used($address)
    {
        global $wpdb;
        foreach(array('monero_gateway_quotes', 'monero_gateway_quotes_txids', 'monero_gateway_pos_quotes') as $table) {
            $table_name = $wpdb->prefix.$table;
            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE payment_id=%s", array($address)));
            // A missing table/read failure is not proof an address is unused.
            if($count === null || !is_numeric($count)) return null;
            if(intval($count) > 0) return true;
        }
        return false;
    }

    /*
     * Claim an unused, pre-generated account-0 subaddress. Restricted wallet
     * RPC cannot create addresses; it only returns the pool with get_address.
     * An insert-only claim using the unique option_name index arbitrates Woo/POS
     * claims before either caller can insert its quote. Claims never expire or
     * get deleted, including when the later quote insert/email/checkout fails.
     */
    protected static function create_unused_subaddress($label)
    {
        global $wpdb;
        $minimum = defined('WARDSACE_MONERO_MIN_SUBADDRESS_INDEX') ? WARDSACE_MONERO_MIN_SUBADDRESS_INDEX : 1;
        $maximum = defined('WARDSACE_MONERO_MAX_SUBADDRESS_INDEX') ? WARDSACE_MONERO_MAX_SUBADDRESS_INDEX : PHP_INT_MAX;
        if(!is_int($minimum) || !is_int($maximum) || $minimum < 1 || $maximum < $minimum) return null;
        $pool = self::$monero_wallet_rpc->get_address(0);
        if(!is_array($pool) || !isset($pool['addresses']) || !is_array($pool['addresses'])) {
            self::$log->add('Monero_Gateway', '[ERROR] Wallet subaddress pool unavailable; refusing a new payment request.');
            return null;
        }
        $candidates = $pool['addresses'];
        usort($candidates, function($a, $b) {
            return intval($a['address_index'] ?? 0) <=> intval($b['address_index'] ?? 0);
        });
        foreach($candidates as $candidate) {
            if(!is_array($candidate) || !isset($candidate['address'], $candidate['address_index'], $candidate['used']) ||
                !is_string($candidate['address']) || $candidate['address'] === '' ||
                !is_int($candidate['address_index']) || $candidate['address_index'] < $minimum || $candidate['address_index'] > $maximum ||
                $candidate['used'] !== false) continue;
            $address = $candidate['address'];
            $used = self::subaddress_already_used($address);
            if($used === null) {
                self::$log->add('Monero_Gateway', '[ERROR] Could not verify subaddress history; refusing a new payment request.');
                return null;
            }
            if($used) {
                self::$log->add('Monero_Gateway', '[INFO] Skipped an already used or reserved subaddress.');
                continue;
            }
            $claim_key = 'wardsace_monero_reserved_' . hash('sha256', $address);
            $claim = array('address' => $address, 'account_index' => 0,
                'address_index' => $candidate['address_index'], 'claimed_at' => time());
            // add_option() is an upsert after its cache precheck, so it is not
            // sufficient for concurrent claims. INSERT IGNORE cannot replace a
            // prior claim, even if another worker inserted it a moment ago.
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, %s)",
                array($claim_key, maybe_serialize($claim), 'no')
            ));
            if($inserted === false) {
                self::$log->add('Monero_Gateway', '[ERROR] Could not persist subaddress reservation; refusing a new payment request.');
                return null;
            }
            if($inserted !== 1) continue;
            wp_cache_delete($claim_key, 'options');
            wp_cache_delete('notoptions', 'options');
            // Also protect against a legacy quote inserted during allocation.
            $used = self::subaddress_already_used($address);
            if($used === null) return null;
            if($used) continue; // Keep the claim permanently; never recycle it.
            return $address;
        }
        self::$log->add('Monero_Gateway', '[ERROR] No unused subaddresses remain in the reserved wallet pool; refusing a new payment request.');
        return null;
    }

    /*
     * Earliest unix time a transfer may carry and still pay this quote. The
     * quote's age comes from the DB's own created/NOW(), so a MySQL time zone
     * that differs from PHP's cannot shift it.
     */
    protected static function quote_not_before($quote)
    {
        $timestamp_created = new DateTime($quote->created);
        $timestamp_now = new DateTime($quote->now);
        $quote_age_seconds = $timestamp_now->getTimestamp() - $timestamp_created->getTimestamp();
        return time() - max(0, $quote_age_seconds) - self::$tx_time_slack;
    }

    /*
     * quote_has_expired(), but a quote on which the wallet once showed a transfer
     * that it no longer lists waits one more day: a transfer that is only briefly invisible (daemon
     * switch, pool eviction) must still be credited when it is mined. After that the order is
     * cancelled like any unpaid one, and the log says so for a manual review.
     */
    protected static function quote_expired_after_sighting($quote, $vanished)
    {
        if(!self::quote_has_expired($quote))
            return false;
        if(!$vanished)
            return true;
        $timestamp_created = new DateTime($quote->created);
        $timestamp_now = new DateTime($quote->now);
        return $timestamp_now->getTimestamp() - $timestamp_created->getTimestamp() > self::$valid_time + 86400;
    }

    /*
     * Refresh only the fiat cache; never inspect, fulfill or expire orders.
     * Prices come from Kraken's public order book: the midpoint of the best
     * bid and ask on each XMR market that is open for trading. USD is
     * required; EUR and BTC are refreshed only when their market is good, and
     * any other currency keeps its old rate, which then ages out. Only when
     * Kraken has no good XMR/USD price does CoinGecko stand in.
     */
    public static function refresh_live_rates()
    {
        global $wpdb;
        $currencies = array_values(array_unique(array_map('strtolower', self::$currencies)));
        $age = 0; // Kraken's order book is live, so its price is as old as this fetch.
        $valid = self::kraken_rates($currencies);
        if($valid === null) {
            $valid = self::coingecko_rates($currencies, $age);
            if($valid === null) {
                self::$log->add('Monero_Payments', '[ERROR] Price refresh failed: Kraken and CoinGecko prices are both unavailable; existing rates were not refreshed.');
                return false;
            }
            self::$log->add('Monero_Payments', '[WARNING] Kraken XMR/USD price unavailable; rates refreshed from CoinGecko.');
        }
        $table = $wpdb->prefix . 'monero_gateway_live_rates';
        foreach($valid as $currency => $rate) {
            // Store source time (adjusted to DB time), not fetch time: an old
            // upstream quote must not gain a new full freshness window.
            $saved = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (currency, rate, updated) VALUES (%s, %d, DATE_SUB(NOW(), INTERVAL %d SECOND)) ON DUPLICATE KEY UPDATE rate=%d, updated=DATE_SUB(NOW(), INTERVAL %d SECOND)",
                array($currency, $rate, $age, $rate, $age)
            ));
            if($saved === false) {
                self::$log->add('Monero_Payments', '[ERROR] Price refresh could not be saved.');
                return false;
            }
            unset(self::$rates[$currency], self::$rates[strtoupper($currency)]);
        }
        return isset($valid['usd']);
    }

    /** Kraken rates (×1e8) for the currencies it has an open XMR market in; null without a good USD price. */
    private static function kraken_rates($currencies)
    {
        $pairs = array_intersect_key(self::$kraken_pairs, array_flip($currencies));
        $markets = self::kraken_public('AssetPairs', $pairs);
        $tickers = $markets === null ? null : self::kraken_public('Ticker', $pairs);
        if($tickers === null)
            return null;
        $valid = array();
        foreach($pairs as $currency => $pair) {
            $ask = self::kraken_price($tickers[$pair]['a'][0] ?? null);
            $bid = self::kraken_price($tickers[$pair]['b'][0] ?? null);
            $open = ($markets[$pair]['status'] ?? null) === 'online';
            $rate = $ask !== null && $bid !== null ? ($ask + $bid) / 2 : 0;
            // A halted market or a thin book is not a price.
            if(!$open || $rate <= 0 || $bid <= 0 || $ask < $bid || $ask - $bid > self::$kraken_max_spread * $rate ||
                $rate > PHP_INT_MAX / 1e8 || $rate * 1e8 < 1) {
                if($currency === 'usd')
                    return null;
                continue;
            }
            $valid[$currency] = intval(round($rate * 1e8));
        }
        return isset($valid['usd']) ? $valid : null;
    }

    /*
     * The fallback: CoinGecko rates (×1e8) for every currency it prices, with
     * $age set to the age of its quote; null when stale or invalid.
     */
    private static function coingecko_rates($currencies, &$age)
    {
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies=' .
            implode(',', $currencies) . '&include_last_updated_at=true';
        $response = wp_remote_get($url, array('timeout' => 10, 'redirection' => 0,
            'sslverify' => true, 'limit_response_size' => 262144,
            'headers' => array('Accept' => 'application/json')));
        if(is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200)
            return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $price = is_array($body) && isset($body['monero']) && is_array($body['monero']) ? $body['monero'] : null;
        $source_time = $price['last_updated_at'] ?? null;
        $source_age = is_int($source_time) ? time() - $source_time : null;
        if(!is_array($price) || $source_age === null || $source_age < 0 || $source_age > 300 ||
            !isset($price['usd']) || !(is_int($price['usd']) || is_float($price['usd'])) ||
            !is_finite($price['usd']) || $price['usd'] <= 0)
            return null;
        $valid = array();
        foreach($currencies as $currency) {
            if(!isset($price[$currency])) continue;
            $rate = $price[$currency];
            if(!(is_int($rate) || is_float($rate)) || !is_finite($rate) || $rate <= 0 ||
                $rate > PHP_INT_MAX / 1e8 || $rate * 1e8 < 1)
                return null;
            $valid[$currency] = intval(round($rate * 1e8));
        }
        $age = $source_age;
        return $valid;
    }

    /** One Kraken public endpoint for these pairs: its result, or null on any error. */
    private static function kraken_public($endpoint, $pairs)
    {
        $response = wp_remote_get('https://api.kraken.com/0/public/' . $endpoint . '?pair=' . implode(',', $pairs),
            array('timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 262144,
            'headers' => array('Accept' => 'application/json')));
        if(is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200)
            return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if(!is_array($body) || !isset($body['error'], $body['result']) || $body['error'] !== array() || !is_array($body['result']))
            return null;
        return $body['result'];
    }

    /** Kraken sends prices as decimal strings; anything else is not a price. */
    private static function kraken_price($value)
    {
        if(!is_string($value) || !preg_match('/^\d{1,12}(\.\d{1,12})?$/', $value))
            return null;
        $price = (float) $value;
        return is_finite($price) && $price > 0 ? $price : null;
    }

    /** Historical quotes stay unchanged during a supervised reactivation. */
    public static function recovery_hold($quote)
    {
        if(!defined('WARDSACE_MONERO_RECOVERY_CUTOFF')) return false;
        $cutoff = WARDSACE_MONERO_RECOVERY_CUTOFF;
        if(!is_int($cutoff) || $cutoff <= 0) return true;
        if(!isset($quote->created, $quote->now)) return true;
        try {
            // Both columns use DB time; their age is independent of DB timezone.
            $age = (new DateTime($quote->now))->getTimestamp() - (new DateTime($quote->created))->getTimestamp();
            return $age < 0 || time() - $age < $cutoff;
        } catch(Throwable $error) { return true; }
    }

    /** Filter before a bounded batch so held history cannot starve new requests. */
    public static function recovery_sql_condition()
    {
        if(!defined('WARDSACE_MONERO_RECOVERY_CUTOFF')) return '1=1';
        if(!is_int(WARDSACE_MONERO_RECOVERY_CUTOFF) || WARDSACE_MONERO_RECOVERY_CUTOFF <= 0 ||
            WARDSACE_MONERO_RECOVERY_CUTOFF > time()) return '1=0';
        $age = time() - WARDSACE_MONERO_RECOVERY_CUTOFF;
        return 'created <= NOW() AND created >= DATE_SUB(NOW(), INTERVAL ' . intval($age) . ' SECOND)';
    }

    public static function log_recovery_holds($kind, $count)
    {
        if($count > 0) self::$log->add('Monero_Payments', '[HOLD] Recovery cutoff: ' . intval($count) .
            ' historical ' . ($kind === 'woo' ? 'online' : 'in-store') . ' requests left unchanged for manual review.');
    }

    private static function protected_node_health()
    {
        $health = get_transient('wardsace_monero_node_health');
        $now = time();
        if(!is_array($health) || !isset($health['height'], $health['checked_at'], $health['top_timestamp']) ||
            !is_int($health['height']) || $health['height'] < 1 || !is_int($health['checked_at']) ||
            !is_int($health['top_timestamp']) || $health['checked_at'] > $now || $now - $health['checked_at'] > 300 ||
            $health['top_timestamp'] > $now + 60 || $now - $health['top_timestamp'] > 1200) return null;
        return $health;
    }

    /*
     * function for verifying payments
     * This cron runs every 30 seconds
     */
    public static function do_update_event()
    {
        global $wpdb;

        self::refresh_live_rates();

        // On a backup node (see wallet_hold_reason()) the transfers the wallet
        // lists are still recorded and a full payment still shows as received,
        // but no quote is confirmed, expired or closed until the hold ends. Read
        // before any wallet data, and again once each quote's transfers are in
        // hand (below): a switch to a backup during this run holds the rest of it.
        $hold = self::wallet_hold_reason();

        // Get current network/wallet height
        if(self::$confirm_type == 'monero-wallet-rpc')
            $height = self::$monero_wallet_rpc->getheight();
        else if(self::$confirm_type == 'viewkey')
            $height = self::$monero_explorer_tools->getheight();
        else
            $height = 0; // manual mode - no height needed

        // If the wallet can't be reached we can't see payments either, so
        // nothing may be expired or confirmed until it answers again.
        if(self::$confirm_type == 'monero-wallet-rpc' && (!$height || !is_numeric($height))) {
            self::$log->add('Monero_Payments', '[ERROR] Wallet RPC unreachable; skipping payment checks and expiry this run.');
            return;
        }
        // Answering is not following the chain: a wallet cut off from its
        // daemon, or still catching up, cannot see recent payments either.
        if(self::$confirm_type == 'monero-wallet-rpc' && !self::wallet_is_current($height))
            return;
        set_transient('monero_gateway_network_height', $height);

        if($hold !== null)
            self::$log->add('Monero_Payments', '[HOLD] Failover: ' . $hold . '; recording payments, but confirming and expiring nothing this run.');

        // Get pending payments
        $table_name_1 = $wpdb->prefix.'monero_gateway_quotes';
        $table_name_2 = $wpdb->prefix.'monero_gateway_quotes_txids';

        $query = $wpdb->prepare("SELECT *, $table_name_1.payment_id AS payment_id, $table_name_1.amount AS amount_total, $table_name_2.amount AS amount_paid, NOW() as now FROM $table_name_1 LEFT JOIN $table_name_2 ON $table_name_1.payment_id = $table_name_2.payment_id WHERE pending=1", array());
        $rows = $wpdb->get_results($query);

        $pending_payments = array();

        // Group the query into distinct orders by payment_id
        foreach($rows as $row) {
            if(!isset($pending_payments[$row->payment_id]))
                $pending_payments[$row->payment_id] = array(
                    'quote' => null,
                    'txs' => array()
                );
            $pending_payments[$row->payment_id]['quote'] = $row;
            if($row->txid)
                $pending_payments[$row->payment_id]['txs'][] = $row;
        }

        $recovery_count = 0;
        foreach($pending_payments as $pending) if(self::recovery_hold($pending['quote'])) $recovery_count++;
        self::log_recovery_holds('woo', $recovery_count);
        // Loop through each pending payment and check status
        foreach($pending_payments as $pending) {
            $quote = $pending['quote'];
            if(self::recovery_hold($quote)) continue;
            $old_txs = $pending['txs'];
            $order_id = $quote->order_id;
            $order = wc_get_order($order_id);
            $payment_id = self::sanatize_id($quote->payment_id);
            $amount_monero = $quote->amount_total;

            // Deleted orders, and quotes saved without an address by the old
            // checkout bug, can never be paid; close them without guessing.
            if(!$order || $payment_id === '') {
                // Not during a hold: nothing is closed then.
                if($hold !== null)
                    continue;
                $wpdb->query($wpdb->prepare("UPDATE $table_name_1 SET pending=0 WHERE payment_id=%s AND order_id=%d", array($quote->payment_id, $order_id)));
                if($order && $payment_id === '') {
                    self::$log->add('Monero_Payments', "[FAILED] Order id $order_id has no Monero payment address; closing its quote.");
                    self::expire_order($order);
                }
                continue;
            }

            if(self::$confirm_type == 'monero-wallet-rpc')
                $new_txs = self::check_payment_rpc($payment_id, self::quote_not_before($quote));
            else if(self::$confirm_type == 'viewkey')
                $new_txs = self::check_payment_explorer($payment_id);
            else
                $new_txs = array(); // manual mode - no automatic checking

            // The lookup failed (already logged); that is not "no payment".
            // Leave this quote alone until a later run can see its transfers.
            if($new_txs === null)
                continue;
            if($hold === null && ($hold = self::wallet_hold_reason()) !== null)
                self::$log->add('Monero_Payments', '[HOLD] Failover: ' . $hold . '; recording payments, but confirming and expiring nothing for the rest of this run.');

            foreach($new_txs as $new_tx) {
                $is_new_tx = true;
                foreach($old_txs as $old_tx) {
                    if($new_tx['txid'] == $old_tx->txid && $new_tx['amount'] == $old_tx->amount_paid) {
                        $is_new_tx = false;
                        break;
                    }
                }
                if($is_new_tx) {
                    $old_txs[] = (object) $new_tx;
                }

                $query = $wpdb->prepare("INSERT INTO $table_name_2 (payment_id, txid, amount, height) VALUES (%s, %s, %d, %d) ON DUPLICATE KEY UPDATE height=%d", array($payment_id, $new_tx['txid'], $new_tx['amount'], $new_tx['height'], $new_tx['height']));
                $wpdb->query($query);
            }

            $txs = $old_txs;
            // With wallet RPC only the transfers the wallet lists NOW count. A
            // transfer recorded in an earlier run that the wallet no longer shows (reorganised out,
            // double-spent, dropped from the pool, or shown by a bad daemon) stays in the table as
            // history but no longer counts toward paid or confirmed.
            $vanished = 0;
            if(self::$confirm_type == 'monero-wallet-rpc') {
                $txs = array();
                $listed = array();
                foreach($new_txs as $new_tx) {
                    $txs[] = (object) $new_tx;
                    $listed[strtolower($new_tx['txid']) . ':' . $new_tx['amount']] = true;
                }
                foreach($pending['txs'] as $db_tx)
                    if(!isset($listed[strtolower($db_tx->txid) . ':' . $db_tx->amount_paid]))
                        $vanished++;
                if($vanished)
                    self::$log->add('Monero_Payments', "[WARNING] Order id $order_id: $vanished transfer(s) recorded earlier are no longer in the wallet; they do not count.");
            }
            $heights = array();
            $amount_paid = 0;
            foreach($txs as $tx) {
                $amount_paid += $tx->amount;
                $heights[] = $tx->height;
            }
            if(intval($quote->paid) === 1 && !($amount_paid > $amount_monero - MONERO_GATEWAY_ATOMIC_UNIT_THRESHOLD)) {
                $wpdb->query($wpdb->prepare("UPDATE $table_name_1 SET paid=0 WHERE payment_id=%s AND pending=1", array($payment_id)));
                unset(self::$payment_details[$order_id]);
            }

            // Safety: no transactions means not paid
            if(empty($txs)) {
                // Check expiry only
                if($hold === null && self::quote_expired_after_sighting($quote, $vanished)) {
                    if($vanished)
                        self::$log->add('Monero_Payments', "[WARNING] Order id $order_id: a payment seen earlier never came back within a day of the deadline; cancelling. Review by hand.");
                    self::$log->add('Monero_Payments', "[FAILED] Payment has expired for order id $order_id and payment id $payment_id");
                    $query = $wpdb->prepare("UPDATE $table_name_1 SET pending=0 WHERE payment_id=%s", array($payment_id));
                    $wpdb->query($query);
                    unset(self::$payment_details[$order_id]);
                    self::expire_order($order);
                }
                continue;
            }

            $paid = $amount_paid > $amount_monero - MONERO_GATEWAY_ATOMIC_UNIT_THRESHOLD;

            if($paid) {
                if(self::$confirms == 0) {
                    $confirmed = true;
                } else {
                    $highest_block = max($heights);
                    if($height - $highest_block >= self::$confirms && !in_array(0, $heights)) {
                        $confirmed = true;
                    } else {
                        $confirmed = false;
                    }
                }
            } else {
                $confirmed = false;
            }
            // Held: a confirmed payment counts only as received, so neither
            // the quote (closed first, below) nor the order is changed.
            if($hold !== null)
                $confirmed = false;

            if($paid && $confirmed) {
                self::$log->add('Monero_Payments', "[SUCCESS] Payment has been confirmed for order id $order_id and payment id $payment_id");
                $query = $wpdb->prepare("UPDATE $table_name_1 SET confirmed=1,paid=1,pending=0 WHERE payment_id=%s", array($payment_id));
                $wpdb->query($query);

                unset(self::$payment_details[$order_id]);

                self::mark_order_paid($order, $order_id);

            } else if($paid) {
                self::$log->add('Monero_Payments', "[SUCCESS] Payment has been received for order id $order_id and payment id $payment_id");
                $query = $wpdb->prepare("UPDATE $table_name_1 SET paid=1 WHERE payment_id=%s", array($payment_id));
                $wpdb->query($query);

                unset(self::$payment_details[$order_id]);

            } else {
                if($hold === null && self::quote_expired_after_sighting($quote, $vanished)) {
                    self::$log->add('Monero_Payments', "[FAILED] Payment has expired for order id $order_id and payment id $payment_id");
                    $query = $wpdb->prepare("UPDATE $table_name_1 SET pending=0 WHERE payment_id=%s", array($payment_id));
                    $wpdb->query($query);

                    unset(self::$payment_details[$order_id]);

                    self::expire_order($order);
                }
            }
        }

        // The cron wrapper reconciles POS orders in its finally block.
    }

    /*
     * Incoming transfers to $subaddress that can pay an order, or null if the
     * wallet could not answer; callers must never read null as "unpaid". As in
     * get_payment_reference_snapshot (POS), time-locked, double-spent and
     * zero-amount transfers do not count. With $not_before (unix time), older
     * transfers are ignored too: they predate the quote, so they were meant
     * for whoever held a reissued address before.
     */
    protected static function check_payment_rpc($subaddress, $not_before = null)
    {
        $address_index = self::$monero_wallet_rpc->get_address_index($subaddress);
        if(!is_array($address_index) || !isset($address_index['index']['major'], $address_index['index']['minor']) ||
            !is_int($address_index['index']['major']) || $address_index['index']['major'] < 0 ||
            !is_int($address_index['index']['minor']) || $address_index['index']['minor'] < 0) {
          self::$log->add('Monero_Gateway', '[ERROR] Couldn\'t get address index of subaddress: ' . $subaddress . '; payment status unknown, leaving it for the next check.');
          return null;
        }
        $payments = self::$monero_wallet_rpc->get_transfers(array(
            'in' => true,
            'pool' => true,
            'account_index' => $address_index['index']['major'],
            'subaddr_indices' => array($address_index['index']['minor'])
        ));
        // An empty result means no transfers; null means the call failed.
        if(!is_array($payments)) {
          self::$log->add('Monero_Gateway', '[ERROR] Couldn\'t get transfers for subaddress: ' . $subaddress . '; payment status unknown, leaving it for the next check.');
          return null;
        }

        $txs = array();
        foreach(array('in', 'pool') as $kind) {
          if(!array_key_exists($kind, $payments))
            continue;
          if(!is_array($payments[$kind])) {
            self::$log->add('Monero_Gateway', '[ERROR] Wallet returned malformed transfers for subaddress: ' . $subaddress . '; payment status unknown, leaving it for the next check.');
            return null;
          }
          foreach($payments[$kind] as $payment) {
            if(!is_array($payment) || !isset($payment['txid'], $payment['amount'], $payment['height'], $payment['timestamp'], $payment['double_spend_seen'], $payment['unlock_time']) ||
                !is_string($payment['txid']) || !preg_match('/^[a-f0-9]{64}$/i', $payment['txid']) ||
                !is_int($payment['amount']) || $payment['amount'] < 0 ||
                !is_int($payment['height']) || $payment['height'] < 0 ||
                !is_int($payment['timestamp']) || $payment['timestamp'] < 0 ||
                !is_bool($payment['double_spend_seen']) ||
                !is_int($payment['unlock_time']) || $payment['unlock_time'] < 0) {
              self::$log->add('Monero_Gateway', '[ERROR] Wallet returned a malformed transfer for subaddress: ' . $subaddress . '; payment status unknown, leaving it for the next check.');
              return null;
            }
            if($payment['double_spend_seen'] || $payment['unlock_time'] !== 0 || $payment['amount'] === 0)
              continue;
            $txid = strtolower($payment['txid']);
            if($not_before !== null && $payment['timestamp'] < $not_before) {
              self::$log->add('Monero_Gateway', '[WARNING] Ignoring transfer ' . $txid . ' to subaddress ' . $subaddress . ': it is older than the payment request, so the wallet probably reissued this address.');
              continue;
            }
            // Prefer the mined entry if the transaction is also still listed
            // in the pool, so it is never counted twice.
            if(isset($txs[$txid]))
              continue;
            $txs[$txid] = array(
                'amount' => $payment['amount'],
                'txid' => $txid,
                'height' => $kind === 'pool' ? 0 : $payment['height']
            );
          }
        }
        return array_values($txs);
    }

    public static function check_payment_explorer($payment_id)
    {
        $txs = array();
        $outputs = self::$monero_explorer_tools->get_outputs(self::$address, self::$viewkey);
        foreach($outputs as $payment) {
            if($payment['payment_id'] == $payment_id) {
                $txs[] = array(
                    'amount' => $payment['amount'],
                    'txid' => $payment['tx_hash'],
                    'height' => $payment['block_no']
                );
            }
        }
        return $txs;
    }

    protected static function get_payment_details($order_id)
    {
        if(!is_integer($order_id))
            $order_id = $order_id->get_id();

        if(isset(self::$payment_details[$order_id]))
            return self::$payment_details[$order_id];

        global $wpdb;
        $table_name_1 = $wpdb->prefix.'monero_gateway_quotes';
        $table_name_2 = $wpdb->prefix.'monero_gateway_quotes_txids';
        $query = $wpdb->prepare("SELECT *, $table_name_1.payment_id AS payment_id, $table_name_1.amount AS amount_total, $table_name_2.amount AS amount_paid, NOW() as now FROM $table_name_1 LEFT JOIN $table_name_2 ON $table_name_1.payment_id = $table_name_2.payment_id WHERE order_id=%d", array($order_id));
        $details = $wpdb->get_results($query);
        if (count($details)) {
            $txs = array();
            $heights = array();
            $amount_paid = 0;
            foreach($details as $tx) {
                if(!isset($tx->txid))
                    continue;
                $txs[] = array(
                    'txid' => $tx->txid,
                    'height' => $tx->height,
                    'amount' => $tx->amount_paid,
                    'amount_formatted' => self::format_monero($tx->amount_paid)
                );
                $amount_paid += $tx->amount_paid;
                $heights[] = $tx->height;
            }

            usort($txs, function($a, $b) {
                if($a['height'] == 0) return -1;
                return $b['height'] - $a['height'];
            });

            // Old cron runs stored '' here when the wallet was down; treat
            // anything but a real height as unknown instead of doing maths on it.
            $height = intval(get_transient('monero_gateway_network_height'));
            if(count($heights) && !in_array(0, $heights) && $height > 0) {
                $highest_block = max($heights);
                $confirms = $height - $highest_block;
                $blocks_to_confirm = self::$confirms - $confirms;
            } else {
                $blocks_to_confirm = self::$confirms;
            }
            $time_to_confirm = self::format_seconds_to_time($blocks_to_confirm * MONERO_GATEWAY_DIFFICULTY_TARGET);

            $amount_total = $details[0]->amount_total;
            $amount_due = max(0, $amount_total - $amount_paid);

            $timestamp_created = new DateTime($details[0]->created);
            $timestamp_now = new DateTime($details[0]->now);

            $order_age_seconds = $timestamp_now->getTimestamp() - $timestamp_created->getTimestamp();
            $order_expires_seconds = self::$valid_time - $order_age_seconds;

            $address = self::$address;
            $payment_id = self::sanatize_id($details[0]->payment_id);

            if(self::$confirm_type == 'monero-wallet-rpc') {
                $integrated_addr = $payment_id;
            } else {
                if ($address) {
                    $decoded_address = self::$cryptonote->decode_address($address);
                    $pub_spendkey = $decoded_address['spendkey'];
                    $pub_viewkey = $decoded_address['viewkey'];
                    $integrated_addr = self::$cryptonote->integrated_addr_from_keys($pub_spendkey, $pub_viewkey, $payment_id);
                } else {
                    self::$log->add('Monero_Gateway', '[ERROR] Merchant has not set Monero address');
                    return '[ERROR] Merchant has not set Monero address';
                }
            }

            $status = '';
            $paid = $details[0]->paid == 1;
            $confirmed = $details[0]->confirmed == 1;
            $pending = $details[0]->pending == 1;

            if($confirmed) {
                $status = 'confirmed';
            } else if($paid) {
                $status = 'paid';
            } else if($pending && ($order_expires_seconds > 0 || self::recovery_hold($details[0]))) {
                if(count($txs)) {
                    $status = 'partial';
                } else {
                    $status = 'unpaid';
                }
            } else {
                if(count($txs)) {
                    $status = 'expired_partial';
                } else {
                    $status = 'expired';
                }
            }

            $amount_formatted = self::format_monero($amount_due);
            $qrcode_uri = 'monero:'.$integrated_addr.'?tx_amount='.$amount_formatted.'&tx_payment_id='.$payment_id;
            $my_order_url = wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount'));

            $payment_details = array(
                'order_id' => $order_id,
                'payment_id' => $payment_id,
                'integrated_address' => $integrated_addr,
                'qrcode_uri' => $qrcode_uri,
                'my_order_url' => $my_order_url,
                'rate' => $details[0]->rate,
                'rate_formatted' => sprintf('%.8f', $details[0]->rate / 1e8),
                'currency' => $details[0]->currency,
                'amount_total' => $amount_total,
                'amount_paid' => $amount_paid,
                'amount_due' => $amount_due,
                'amount_total_formatted' => self::format_monero($amount_total),
                'amount_paid_formatted' => self::format_monero($amount_paid),
                'amount_due_formatted' => self::format_monero($amount_due),
                'status' => $status,
                'hold' => $pending && self::recovery_hold($details[0]),
                'warning' => $pending && self::recovery_hold($details[0]) ? 'This historical payment request is held for manual review. Contact the shop before sending payment.' : '',
                'created' => $details[0]->created,
                'order_age' => $order_age_seconds,
                'order_expires' => self::format_seconds_to_time($order_expires_seconds),
                'blocks_to_confirm' => $blocks_to_confirm,
                'time_to_confirm' => $time_to_confirm,
                'txs' => $txs,
                'discount' => self::$discount ? self::$discount : 0,
                'fiat_total' => 0,
                'fiat_discounted' => 0,
                'fiat_savings' => 0,
                'fiat_currency_symbol' => '',
            );

            // Add fiat pricing for discount display
            $order = wc_get_order($order_id);
            if($order && self::$discount) {
                $fiat_total = floatval($order->get_total(''));
                $fiat_discounted = $fiat_total * (1 - self::$discount / 100);
                $fiat_savings = $fiat_total - $fiat_discounted;
                $payment_details['fiat_total'] = round($fiat_total, 2);
                $payment_details['fiat_discounted'] = round($fiat_discounted, 2);
                $payment_details['fiat_savings'] = round($fiat_savings, 2);
                $payment_details['fiat_currency_symbol'] = get_woocommerce_currency_symbol($order->get_currency());
            }

            self::$payment_details[$order_id] = $payment_details;
            return $payment_details;
        } else {
            return '[ERROR] Quote not found';
        }

    }

    public static function get_payment_details_ajax() {

        $user = wp_get_current_user();
        if($user === 0)
            self::ajax_output(array('error' => '[ERROR] User not logged in'));
        
        if(isset($_GET['order_id'])){
            $order_id = preg_replace("/[^0-9]+/", "", $_GET['order_id']);
            $order = wc_get_order($order_id);
            if(!$order)
                self::ajax_output(array('error' => '[ERROR] Order not found'));

            // A guest order's customer id is 0, the same as any logged-out
            // visitor's, so guests must also show the order key from their
            // payment page link.
            $key = isset($_GET['key']) ? (string) wp_unslash($_GET['key']) : '';
            $owner = $order->get_customer_id() && $order->get_customer_id() == $user->ID;
            if(!$owner && ($key === '' || !hash_equals($order->get_order_key(), $key)))
                self::ajax_output(array('error' => '[ERROR] Order does not belong to this user'));

            if($order->get_payment_method() != self::$_id)
                self::ajax_output(array('error' => '[ERROR] Order not paid for with Monero'));

            // Real-time payment check: if order is still pending, check wallet-rpc now
            self::check_order_payment($order_id);
    
            $details = self::get_payment_details($order);
            if(!is_array($details))
                self::ajax_output(array('error' => $details));
    
            self::ajax_output($details);
        }
    }

    /**
     * Check payment status for a single order via wallet-rpc.
     * Called during AJAX polling so payments are detected in real-time
     * without depending on WP-cron.
     */
    protected static function check_order_payment($order_id) {
        if(self::$confirm_type != 'monero-wallet-rpc')
            return;

        global $wpdb;
        $table_name_1 = $wpdb->prefix.'monero_gateway_quotes';
        $table_name_2 = $wpdb->prefix.'monero_gateway_quotes_txids';

        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT *, NOW() as now FROM $table_name_1 WHERE order_id=%d AND pending=1", $order_id
        ));
        if(!$quote || self::recovery_hold($quote)) return;

        $payment_id = self::sanatize_id($quote->payment_id);
        if($payment_id === '') return;
        // SELECT * has no amount_total alias; reading it gave null, which let
        // any payment at all count as paid in full.
        $amount_monero = intval($quote->amount);
        if($amount_monero <= 0) return;

        // Nothing is decided on a backup node either (the cron logs it). Read
        // before the wallet data and again after it: a switch in between holds.
        $held = self::wallet_on_backup();

        // Get current height
        $height = self::$monero_wallet_rpc->getheight();
        if(!$height || !is_numeric($height)) return;
        // The cron logs why the wallet is not current; the page stays quiet.
        if(!self::wallet_is_current($height, false)) return;
        if($held) return;

        // Check for transfers to this subaddress. Null means the wallet could
        // not say (already logged), so decide nothing this time.
        $new_txs = self::check_payment_rpc($payment_id, self::quote_not_before($quote));
        if($new_txs === null || self::wallet_on_backup()) return;

        // Insert new txs
        foreach($new_txs as $new_tx) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_name_2 (payment_id, txid, amount, height) VALUES (%s, %s, %d, %d) ON DUPLICATE KEY UPDATE height=%d",
                $payment_id, $new_tx['txid'], $new_tx['amount'], $new_tx['height'], $new_tx['height']
            ));
        }

        // Only the transfers the wallet lists now count (not every transfer ever
        // recorded for this quote): one reorganised out or double-spent must not pay the order.
        $all_txs = array();
        foreach($new_txs as $new_tx)
            $all_txs[] = (object) $new_tx;

        // Safety: no transactions means not paid, period
        if(empty($all_txs)) return;

        $amount_paid = 0;
        $heights = array();
        foreach($all_txs as $tx) {
            $amount_paid += $tx->amount;
            $heights[] = $tx->height;
        }

        $paid = $amount_paid > $amount_monero - MONERO_GATEWAY_ATOMIC_UNIT_THRESHOLD;

        if($paid) {
            if(self::$confirms == 0) {
                $confirmed = true;
            } else {
                $highest_block = max($heights);
                $confirmed = ($height - $highest_block >= self::$confirms && !in_array(0, $heights));
            }
        } else {
            $confirmed = false;
        }

        $order = wc_get_order($order_id);
        if($paid && $confirmed) {
            self::$log->add('Monero_Payments', "[SUCCESS] Real-time check: payment confirmed for order $order_id. Amount paid: $amount_paid, required: $amount_monero");
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_name_1 SET confirmed=1,paid=1,pending=0 WHERE payment_id=%s", $payment_id
            ));
            unset(self::$payment_details[$order_id]);
            self::mark_order_paid($order, $order_id);
        } else if($paid) {
            self::$log->add('Monero_Payments', "[INFO] Real-time check: payment received for order $order_id, awaiting confirmations. Amount paid: $amount_paid");
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_name_1 SET paid=1 WHERE payment_id=%s", $payment_id
            ));
            unset(self::$payment_details[$order_id]);
        }

        // Update network height transient
        set_transient('monero_gateway_network_height', $height);
    }
    public static function ajax_output($response) {
        header('Content-type: application/json');
        if (ob_get_length() > 0){
            ob_clean();
        }
        echo json_encode($response);
        wp_die();
    }

    public static function admin_confirm_payment_ajax() {
        if(!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        check_ajax_referer('monero_confirm_payment', 'nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $txid = isset($_POST['txid']) ? sanitize_text_field($_POST['txid']) : '';

        if(!$order_id) {
            wp_send_json_error('Invalid order ID');
            return;
        }

        $order = wc_get_order($order_id);
        if(!$order || $order->get_payment_method() != self::$_id) {
            wp_send_json_error('Invalid order');
            return;
        }

        global $wpdb;
        $table_name_1 = $wpdb->prefix.'monero_gateway_quotes';
        $table_name_2 = $wpdb->prefix.'monero_gateway_quotes_txids';

        // Get the payment_id for this order
        $quote = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name_1 WHERE order_id=%d", $order_id));
        if(!$quote) {
            wp_send_json_error('Quote not found');
            return;
        }

        $payment_id = $quote->payment_id;
        $amount = $quote->amount;

        // Record the txid if provided
        if($txid) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_name_2 (payment_id, txid, amount, height) VALUES (%s, %s, %d, %d) ON DUPLICATE KEY UPDATE height=%d",
                $payment_id, $txid, $amount, 1, 1
            ));
        }

        // Mark quote as confirmed and paid
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name_1 SET confirmed=1, paid=1, pending=0 WHERE payment_id=%s",
            $payment_id
        ));

        // Update order status
        $note = 'Monero payment manually confirmed by admin.';
        if($txid) {
            $note .= ' TX: ' . $txid;
        }

        if(self::is_virtual_in_cart($order_id)) {
            $order->update_status('completed', $note);
        } else {
            $order->update_status('processing', $note);
        }

        self::$log->add('Monero_Payments', "[SUCCESS] Payment manually confirmed for order $order_id by admin");

        wp_send_json_success('Payment confirmed');
    }

    public static function admin_order_page($post)
    {
        $order = $post instanceof WC_Order ? $post : wc_get_order($post->ID);
        if(!$order || $order->get_meta('_monero_pos') === 'yes') { return; }
        if($order->get_payment_method() != self::$_id)
            return;

        $method_title = self::$_title;
        $details = self::get_payment_details($order);
        if(!is_array($details)) {
            $error = $details;
            include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/admin/order-history-error-page.php';
            return;
        }
        include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/admin/order-history-page.php';
    }

    public static function customer_order_page($order)
    {
        if(is_integer($order)) {
            $order_id = $order;
            $order = wc_get_order($order_id);
        } else {
            $order_id = $order->get_id();
        }

        if(!$order || $order->get_meta('_monero_pos') === 'yes') { return; }
        if($order->get_payment_method() != self::$_id)
            return;

        $method_title = self::$_title;
        $details = self::get_payment_details($order_id);
        if(!is_array($details)) {
            $error = $details;
            include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/customer/order-error-page.php';
            return;
        }
        $show_qr = self::$show_qr;
        $details_json = json_encode($details);
        $ajax_url = WC_AJAX::get_endpoint('monero_gateway_payment_details');
        $order_key = $order->get_order_key(); // lets a guest's page poll its own order
        include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/customer/order-page.php';
    }

    public static function customer_order_email($order)
    {
        if(is_integer($order)) {
            $order_id = $order;
            $order = wc_get_order($order_id);
        } else {
            $order_id = $order->get_id();
        }

        if(!$order || $order->get_meta('_monero_pos') === 'yes') { return; }
        if($order->get_payment_method() != self::$_id)
            return;

        $method_title = self::$_title;
        $details = self::get_payment_details($order_id);
        if(!is_array($details)) {
            include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/customer/order-email-error-block.php';
            return;
        }
        include MONERO_GATEWAY_PLUGIN_DIR . '/templates/monero-gateway/customer/order-email-block.php';
    }

    public static function get_id()
    {
        return self::$_id;
    }

    public static function get_discount()
    {
        return self::$discount ? self::$discount : 0;
    }

    public static function get_confirm_type()
    {
        return self::$confirm_type;
    }

    /**
     * Create the unique destination used by a standalone payment request.
     * Wallet RPC mode uses a subaddress; view-key mode uses an integrated address.
     */
    public static function create_payment_destination($label = '')
    {
        if(self::$confirm_type == 'manual') {
            return new WP_Error('monero_pos_manual_mode', __('In-store payments require wallet RPC or view-key verification.', 'monero_gateway'));
        }

        if(self::$confirm_type == 'monero-wallet-rpc') {
            // Skips addresses a web order or earlier request already used.
            $address = self::create_unused_subaddress($label);
            if($address === null) {
                self::$log->add('Monero_Gateway', '[ERROR] Could not create an in-store payment subaddress.');
                return new WP_Error('monero_pos_address', __('The Monero wallet could not create a payment address.', 'monero_gateway'));
            }

            return array(
                'payment_id' => self::sanatize_id($address),
                'address' => self::sanatize_id($address),
            );
        }

        try {
            $payment_id = bin2hex(random_bytes(8));
        } catch(Exception $exception) {
            $payment_id = bin2hex(openssl_random_pseudo_bytes(8));
        }

        if(!self::$address) {
            return new WP_Error('monero_pos_address', __('The merchant Monero address is not configured.', 'monero_gateway'));
        }

        $decoded_address = self::$cryptonote->decode_address(self::$address);
        $integrated_address = self::$cryptonote->integrated_addr_from_keys(
            $decoded_address['spendkey'],
            $decoded_address['viewkey'],
            $payment_id
        );

        return array(
            'payment_id' => $payment_id,
            'address' => $integrated_address,
        );
    }

    public static function get_payment_address($payment_id)
    {
        $payment_id = self::sanatize_id($payment_id);
        if(self::$confirm_type == 'monero-wallet-rpc') {
            return $payment_id;
        }

        if(!self::$address) {
            return '';
        }

        $decoded_address = self::$cryptonote->decode_address(self::$address);
        return self::$cryptonote->integrated_addr_from_keys(
            $decoded_address['spendkey'],
            $decoded_address['viewkey'],
            $payment_id
        );
    }

    public static function check_payment_reference($payment_id)
    {
        $payment_id = self::sanatize_id($payment_id);
        if(self::$confirm_type == 'monero-wallet-rpc') {
            return self::check_payment_rpc($payment_id);
        }
        if(self::$confirm_type == 'viewkey') {
            return self::check_payment_explorer($payment_id);
        }
        return array();
    }

    /**
     * POS-only current incoming snapshot. The order-payment path above is unchanged.
     * https://docs.getmonero.org/rpc-library/wallet-rpc/#get_transfers
     */
    public static function get_payment_reference_snapshot($payment_id)
    {
        if(self::$confirm_type !== 'monero-wallet-rpc') {
            return array(
                'transactions' => self::check_payment_reference($payment_id),
                'status_fresh' => false,
                'status_source' => self::$confirm_type === 'viewkey' ? 'viewkey' : 'unavailable',
            );
        }

        $payment_id = self::sanatize_id($payment_id);
        $address = self::$monero_wallet_rpc->get_address_index($payment_id);
        if(!is_array($address) || !isset($address['index']['major'], $address['index']['minor']) ||
            !is_int($address['index']['major']) || $address['index']['major'] < 0 ||
            !is_int($address['index']['minor']) || $address['index']['minor'] < 0) {
            throw new RuntimeException('The wallet did not return a valid payment address index.');
        }
        $payments = self::$monero_wallet_rpc->get_transfers(array(
            'in' => true,
            'pool' => true,
            'account_index' => $address['index']['major'],
            'subaddr_indices' => array($address['index']['minor']),
        ));
        // The RPC wrapper can return null on transport/JSON-RPC errors. An empty
        // result object is valid; a missing or malformed result is not "unpaid".
        if(!is_array($payments)) {
            throw new RuntimeException('The wallet did not return a payment snapshot.');
        }

        $transactions = array();
        foreach(array('in', 'pool') as $kind) {
            if(!array_key_exists($kind, $payments)) {
                continue;
            }
            if(!is_array($payments[$kind])) {
                throw new RuntimeException('The wallet returned malformed incoming transfers.');
            }
            foreach($payments[$kind] as $payment) {
                if(!is_array($payment) || !isset($payment['txid'], $payment['amount'], $payment['height'], $payment['double_spend_seen'], $payment['unlock_time']) ||
                    !is_string($payment['txid']) || !preg_match('/^[a-f0-9]{64}$/i', $payment['txid']) ||
                    !is_int($payment['amount']) || $payment['amount'] < 0 ||
                    !is_int($payment['height']) || $payment['height'] < 0 ||
                    !is_bool($payment['double_spend_seen']) ||
                    !is_int($payment['unlock_time']) || $payment['unlock_time'] < 0) {
                    throw new RuntimeException('The wallet returned an invalid incoming transfer.');
                }
                if($payment['double_spend_seen'] || $payment['unlock_time'] !== 0 || $payment['amount'] === 0) {
                    continue;
                }
                $txid = strtolower($payment['txid']);
                // Prefer the mined entry if an RPC result includes the same
                // transaction in both sections during a pool-to-block transition.
                if(isset($transactions[$txid])) {
                    continue;
                }
                $transactions[$txid] = array(
                    'txid' => $txid,
                    'amount' => $payment['amount'],
                    'height' => $kind === 'pool' ? 0 : $payment['height'],
                );
            }
        }
        return array('transactions' => array_values($transactions), 'status_fresh' => true, 'status_source' => 'wallet-rpc');
    }

    public static function get_network_height()
    {
        if(self::$confirm_type == 'monero-wallet-rpc') {
            return self::$monero_wallet_rpc->getheight();
        }
        if(self::$confirm_type == 'viewkey') {
            return self::$monero_explorer_tools->getheight();
        }
        return 0;
    }

    public static function get_required_confirmations()
    {
        return max(0, intval(self::$confirms));
    }

    public static function get_valid_time()
    {
        return max(60, intval(self::$valid_time));
    }

    public static function use_qr_code()
    {
        return self::$show_qr;
    }

    public static function use_monero_price()
    {
        return self::$use_monero_price;
    }


    public static function convert_wc_price($price, $currency)
    {
        $rate = self::get_live_rate($currency);
        $monero_amount = intval(MONERO_GATEWAY_ATOMIC_UNITS_POW * 1e8 * $price / $rate) / MONERO_GATEWAY_ATOMIC_UNITS_POW;
        $monero_amount_formatted = sprintf('%.'.self::$use_monero_price_decimals.'f', $monero_amount);

        return <<<HTML
            <span class="woocommerce-Price-amount amount" data-price="$price" data-currency="$currency"
        data-rate="$rate" data-rate-type="live">
            $monero_amount_formatted
            <span class="woocommerce-Price-currencySymbol">XMR</span>
        </span>

HTML;
    }

    public static function convert_wc_price_order($price_html, $order)
    {
        if($order->get_payment_method() != self::$_id)
            return $price_html;

        $order_id = $order->get_id();
        $payment_details = self::get_payment_details($order_id);
        if(!is_array($payment_details))
            return $price_html;

        // Experimental regex, may fail with other custom price formatters
        $match_ok = preg_match('/data-price="([^"]*)"/', $price_html, $matches);
        if($match_ok !== 1) // regex failed
            return $price_html;

        $price = array_pop($matches);
        $currency = $payment_details['currency'];
        $rate = $payment_details['rate'];
        $monero_amount = intval(MONERO_GATEWAY_ATOMIC_UNITS_POW * 1e8 * $price / $rate) / MONERO_GATEWAY_ATOMIC_UNITS_POW;
        $monero_amount_formatted = sprintf('%.'.MONERO_GATEWAY_ATOMIC_UNITS.'f', $monero_amount);

        return <<<HTML
            <span class="woocommerce-Price-amount amount" data-price="$price" data-currency="$currency"
        data-rate="$rate" data-rate-type="fixed">
            $monero_amount_formatted
            <span class="woocommerce-Price-currencySymbol">XMR</span>
        </span>

HTML;
    }

    public static function get_live_rate($currency)
    {
        if(isset(self::$rates[$currency]))
            return self::$rates[$currency];

        global $wpdb;
        $table_name = $wpdb->prefix.'monero_gateway_live_rates';
        $query = $wpdb->prepare("SELECT rate FROM $table_name WHERE currency=%s", array($currency));

        $row = $wpdb->get_row($query);
        $rate = $row ? intval($row->rate) : 0;
        self::$rates[$currency] = $rate;

        return $rate;
    }

    /*
     * Seconds since the stored rate for $currency was refreshed, measured
     * with the DB's own NOW() like quote expiry is; null if unknown.
     */
    protected static function get_live_rate_age($currency)
    {
        global $wpdb;
        $table_name = $wpdb->prefix.'monero_gateway_live_rates';
        $row = $wpdb->get_row($wpdb->prepare("SELECT updated, NOW() as now FROM $table_name WHERE currency=%s", array($currency)));
        if(!$row || empty($row->updated) || empty($row->now))
            return null;

        $timestamp_updated = new DateTime($row->updated);
        $timestamp_now = new DateTime($row->now);
        return $timestamp_now->getTimestamp() - $timestamp_updated->getTimestamp();
    }

    protected static function sanatize_id($payment_id)
    {
        // Limit payment id to alphanumeric characters
        $sanatized_id = preg_replace("/[^a-zA-Z0-9]+/", "", $payment_id);
        return $sanatized_id;
    }

    protected static function is_virtual_in_cart($order_id)
    {
        $order = wc_get_order($order_id);
        $items = $order->get_items();
        $cart_size = count($items);
        $virtual_items = 0;

        foreach ( $items as $item ) {
            // wc_get_product gives false for a deleted product, where new
            // WC_Product throws and would abort a paid order's status change.
            $product = wc_get_product( $item['product_id'] );
            if ($product && $product->is_virtual()) {
                $virtual_items += 1;
            }
        }
        return $virtual_items == $cart_size;
    }

    public static function format_monero($atomic_units) {
        return sprintf(MONERO_GATEWAY_ATOMIC_UNITS_SPRINTF, $atomic_units / MONERO_GATEWAY_ATOMIC_UNITS_POW);
    }

    public static function format_seconds_to_time($seconds)
    {
        $units = array();

        $dtF = new \DateTime('@0');
        $dtT = new \DateTime("@$seconds");
        $diff = $dtF->diff($dtT);

        $d = $diff->format('%a');
        $h = $diff->format('%h');
        $m = $diff->format('%i');

        if($d == 1)
            $units[] = "$d day";
        else if($d > 1)
            $units[] = "$d days";

        if($h == 0 && $d != 0)
            $units[] = "$h hours";
        else if($h == 1)
            $units[] = "$h hour";
        else if($h > 0)
            $units[] = "$h hours";

        if($m == 1)
            $units[] = "$m minute";
        else
            $units[] = "$m minutes";

        return implode(', ', $units) . ($seconds < 0 ? ' ago' : '');
    }

}
