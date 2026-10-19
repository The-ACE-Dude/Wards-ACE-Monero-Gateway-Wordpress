<?php
/*
Plugin Name: Wards ACE Monero Gateway
Plugin URI: https://github.com/eward3/Wards-ACE-Monero-Gateway-Wordpress
Description: Monero payment gateway for WooCommerce (forked from monero-integrations/monerowp v3.0.5). Features: wallet-rpc & viewkey verification, QR codes, Open-in-Wallet button, live XMR pricing from Kraken (CoinGecko fallback), checkout discount, integrated address payments.
Version: 4.3.2
Tested up to: 6.9
Author: eward3
Author URI: https://github.com/eward3
License: MIT
*/
// This code isn't for Dark Net Markets, please report them to Authority!

defined( 'ABSPATH' ) || exit;

// Constants, you can edit these if you fork this repo
define('MONERO_GATEWAY_MAINNET_EXPLORER_URL', 'https://xmrchain.net/');
define('MONERO_GATEWAY_TESTNET_EXPLORER_URL', 'https://testnet.xmrchain.com/');
define('MONERO_GATEWAY_ADDRESS_PREFIX', 0x12);
define('MONERO_GATEWAY_ADDRESS_PREFIX_INTEGRATED', 0x13);
define('MONERO_GATEWAY_ATOMIC_UNITS', 12);
define('MONERO_GATEWAY_ATOMIC_UNIT_THRESHOLD', 10); // Amount under in atomic units payment is valid
define('MONERO_GATEWAY_DIFFICULTY_TARGET', 120);

// Do not edit these constants
define('MONERO_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MONERO_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MONERO_GATEWAY_ATOMIC_UNITS_POW', pow(10, MONERO_GATEWAY_ATOMIC_UNITS));
define('MONERO_GATEWAY_ATOMIC_UNITS_SPRINTF', '%.'.MONERO_GATEWAY_ATOMIC_UNITS.'f');
define('MONERO_GATEWAY_DB_VERSION', '4.3.0');

// Optionally reorder Monero to appear first in the payment methods list
add_filter('woocommerce_payment_gateways', 'monero_reorder_gateways', 999);
function monero_reorder_gateways($gateways) {
    $priority = array('monero_gateway');
    $prioritized = array();
    $rest = array();
    foreach ($gateways as $gateway) {
        $id = is_string($gateway) ? $gateway : $gateway->id;
        if (in_array($id, $priority)) {
            $prioritized[$id] = $gateway;
        } else {
            $rest[] = $gateway;
        }
    }
    // Monero first, then everything else
    $result = array();
    foreach ($priority as $pid) {
        if (isset($prioritized[$pid])) {
            $result[] = $prioritized[$pid];
        }
    }
    return array_merge($result, $rest);
}

// Include our Gateway Class and register Payment Gateway with WooCommerce
add_action('plugins_loaded', 'monero_init', 1);
function monero_init() {

    // If the class doesn't exist (== WooCommerce isn't installed), return NULL
    if (!class_exists('WC_Payment_Gateway')) return;

    // If we made it this far, then include our Gateway Class
    require_once('include/class-monero-gateway.php');

    // Create a new instance of the gateway so we have static variables set up
    new Monero_Gateway($add_action=false);

    // Website orders from the cashier checkout.
    require_once('include/class-monero-pos-orders.php');
    require_once('include/class-monero-pos-inventory.php');
    Monero_POS_Inventory::init();
    require_once('include/class-monero-pos.php');
    Monero_POS::init();

    // Include our Admin interface class
    require_once('include/admin/class-monero-admin-interface.php');

    add_filter('woocommerce_payment_gateways', 'monero_gateway');
    function monero_gateway($methods) {
        $methods[] = 'Monero_Gateway';
        return $methods;
    }

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'monero_payment');
    function monero_payment($links) {
        $plugin_links = array(
            '<a href="'.admin_url('admin.php?page=monero_gateway_settings').'">'.__('Settings', 'monero_gateway').'</a>',
            '<a href="'.esc_url(home_url('/monero-in-store/')).'">'.__('In-store checkout', 'monero_gateway').'</a>'
        );
        return array_merge($plugin_links, $links);
    }

    add_filter('cron_schedules', 'monero_cron_add_one_minute');
    function monero_cron_add_one_minute($schedules) {
        $schedules['one_minute'] = array(
            'interval' => 60,
            'display' => __('Once every minute', 'monero_gateway')
        );
        return $schedules;
    }

    add_action('wp', 'monero_activate_cron');
    function monero_activate_cron() {
        if(!wp_next_scheduled('monero_update_event')) {
            wp_schedule_event(time(), 'one_minute', 'monero_update_event');
        }
    }

    add_action('monero_update_event', 'monero_update_event');
    function monero_update_event() {
        // A deleted legacy online order or an unrelated legacy RPC failure must
        // not prevent website POS orders from completing or releasing stock.
        try {
            Monero_Gateway::do_update_event();
        } finally {
            Monero_POS::update_pending_quotes();
        }
    }

    // Monero orders now wait as "Pending payment". WooCommerce's hold-stock
    // timer cancels pending orders on its own clock, which could cancel one
    // that is paid but still gathering confirmations; while its payment
    // request is open, the gateway's own expiry (valid_time) is the only
    // thing that should cancel it. A checkout the gateway refused has no
    // request and is left to WooCommerce to clean up.
    add_filter('woocommerce_cancel_unpaid_order', 'monero_keep_unpaid_order', 10, 2);
    function monero_keep_unpaid_order($cancel, $order = null) {
        if(Monero_Gateway::has_open_quote($order))
            return false;
        return $cancel;
    }

    // Pending also offers the customer Cancel and Pay in My Account. With
    // the Monero perhaps already sent, neither is allowed until the payment
    // request closes (paid, or expired by the gateway).
    add_filter('woocommerce_valid_order_statuses_for_cancel', 'monero_lock_quoted_order', 10, 2);
    add_filter('woocommerce_valid_order_statuses_for_payment', 'monero_lock_quoted_order', 10, 2);
    function monero_lock_quoted_order($statuses, $order = null) {
        if(Monero_Gateway::has_open_quote($order))
            return array();
        return $statuses;
    }

    add_action('woocommerce_thankyou_'.Monero_Gateway::get_id(), 'monero_order_confirm_page');
    add_action('woocommerce_order_details_after_order_table', 'monero_order_page');
    add_action('woocommerce_email_after_order_table', 'monero_order_email');

    function monero_order_confirm_page($order_id) {
        Monero_Gateway::customer_order_page($order_id);
    }
    function monero_order_page($order) {
        if(!is_wc_endpoint_url('order-received'))
            Monero_Gateway::customer_order_page($order);
    }
    function monero_order_email($order) {
        Monero_Gateway::customer_order_email($order);
    }

    add_action('wc_ajax_monero_gateway_payment_details', 'monero_get_payment_details_ajax');
    function monero_get_payment_details_ajax() {
        Monero_Gateway::get_payment_details_ajax();
    }

    add_action('wp_ajax_monero_confirm_payment', 'monero_admin_confirm_payment');
    function monero_admin_confirm_payment() {
        Monero_Gateway::admin_confirm_payment_ajax();
    }

    add_filter('woocommerce_currencies', 'monero_add_currency');
    function monero_add_currency($currencies) {
        $currencies['Monero'] = __('Monero', 'monero_gateway');
        return $currencies;
    }

    add_filter('woocommerce_currency_symbol', 'monero_add_currency_symbol', 10, 2);
    function monero_add_currency_symbol($currency_symbol, $currency) {
        switch ($currency) {
        case 'Monero':
            $currency_symbol = 'XMR';
            break;
        }
        return $currency_symbol;
    }

    if(Monero_Gateway::use_monero_price()) {

        // This filter will replace all prices with amount in Monero (live rates)
        add_filter('wc_price', 'monero_live_price_format', 10, 3);
        function monero_live_price_format($price_html, $price_float, $args) {
            if(!isset($args['currency']) || !$args['currency']) {
                global $woocommerce;
                $currency = strtoupper(get_woocommerce_currency());
            } else {
                $currency = strtoupper($args['currency']);
            }
            return Monero_Gateway::convert_wc_price($price_float, $currency);
        }

        // These filters will replace the live rate with the exchange rate locked in for the order
        // We must be careful to hit all the hooks for price displays associated with an order,
        // else the exchange rate can change dynamically (which it should for an order)
        add_filter('woocommerce_order_formatted_line_subtotal', 'monero_order_item_price_format', 10, 3);
        function monero_order_item_price_format($price_html, $item, $order) {
            return Monero_Gateway::convert_wc_price_order($price_html, $order);
        }

        add_filter('woocommerce_get_formatted_order_total', 'monero_order_total_price_format', 10, 2);
        function monero_order_total_price_format($price_html, $order) {
            return Monero_Gateway::convert_wc_price_order($price_html, $order);
        }

        add_filter('woocommerce_get_order_item_totals', 'monero_order_totals_price_format', 10, 3);
        function monero_order_totals_price_format($total_rows, $order, $tax_display) {
            foreach($total_rows as &$row) {
                $price_html = $row['value'];
                $row['value'] = Monero_Gateway::convert_wc_price_order($price_html, $order);
            }
            return $total_rows;
        }

    }

    add_action('wp_enqueue_scripts', 'monero_enqueue_scripts');
    function monero_enqueue_scripts() {
        if(Monero_Gateway::use_monero_price())
            wp_dequeue_script('wc-cart-fragments');
        if(Monero_Gateway::use_qr_code())
            wp_enqueue_script('monero-qr-code', MONERO_GATEWAY_PLUGIN_URL.'assets/js/qrcode.min.js');

        wp_enqueue_script('monero-clipboard-js', MONERO_GATEWAY_PLUGIN_URL.'assets/js/clipboard.min.js');
        wp_enqueue_script('monero-gateway', MONERO_GATEWAY_PLUGIN_URL.'assets/js/monero-gateway-order-page.js', array('jquery'), '1.0.8');
        wp_enqueue_style('monero-gateway', MONERO_GATEWAY_PLUGIN_URL.'assets/css/monero-gateway-order-page.css', array(), '1.0.6');
    }

    // Checkout page: show dynamic discount inside Monero description area
    add_action('wp_footer', 'monero_checkout_discount_script', 999);
    function monero_checkout_discount_script() {
        $discount = intval(Monero_Gateway::get_discount());
        if($discount <= 0) return;
        ?>
        <script type="text/javascript">
        (function(){
            var discount = <?php echo $discount; ?>;
            var totalRow = null;
            var watching = false;

            // Find the "Total" row by locating the label that says exactly "Total"
            // then return the dollar amount from its parent container.
            function findTotalRow() {
                if(totalRow && document.contains(totalRow)) return totalRow;
                // Search all leaf text nodes for exactly "Total"
                var walker = document.createTreeWalker(
                    document.body, NodeFilter.SHOW_TEXT, null, false
                );
                while(walker.nextNode()) {
                    var node = walker.currentNode;
                    if(node.textContent.trim() === 'Total') {
                        // Go up to the row container (the element holding both label + price)
                        var el = node.parentElement;
                        // Walk up max 3 levels to find an element containing a $ amount
                        for(var up = 0; up < 4 && el; up++) {
                            if(/\$\d/.test(el.textContent) && el.textContent.indexOf('Total') > -1) {
                                totalRow = el;
                                return el;
                            }
                            el = el.parentElement;
                        }
                    }
                }
                return null;
            }

            function readTotal() {
                var row = findTotalRow();
                if(!row) return null;
                // Extract the last dollar amount in the row (the price, not any sub-label)
                var matches = row.textContent.match(/\$([0-9,]+\.\d{2})/g);
                if(matches && matches.length) {
                    var last = matches[matches.length - 1];
                    return parseFloat(last.replace(/[$,]/g, ''));
                }
                return null;
            }

            function watchTotalRow() {
                if(watching) return;
                var row = findTotalRow();
                if(!row) return;
                watching = true;
                // Observe the row itself
                new MutationObserver(function(){
                    setTimeout(update, 50);
                }).observe(row, {childList:true, subtree:true, characterData:true});
                // Observe parent in case React replaces the entire row
                var parent = row.parentElement;
                if(parent) {
                    new MutationObserver(function(){
                        totalRow = null;
                        watching = false;
                        setTimeout(function(){
                            watchTotalRow();
                            update();
                        }, 100);
                    }).observe(parent, {childList:true});
                }
            }

            function findMoneroContent() {
                var radio = document.querySelector('input[value="monero_gateway"]');
                if(!radio) return null;
                var option = radio.closest('.wc-block-components-radio-control-accordion-option');
                if(option) {
                    var content = option.querySelector('.wc-block-components-radio-control-accordion-content')
                              || option.querySelector('[class*="accordion-content"]');
                    if(content) return content;
                }
                var scope = option || radio.closest('.wc-block-components-radio-control__option')
                                   || radio.parentElement;
                if(scope && scope.parentElement) scope = scope.parentElement;
                if(scope) {
                    var elems = scope.querySelectorAll('div, p');
                    for(var i = 0; i < elems.length; i++) {
                        if(elems[i].textContent.indexOf('Pay securely') > -1 || elems[i].textContent.indexOf('payment details after checkout') > -1) {
                            return elems[i].parentElement || elems[i];
                        }
                    }
                }
                var paymentBox = radio.closest('li');
                if(paymentBox) return paymentBox.querySelector('.payment_box');
                return null;
            }

            function isMoneroSelected() {
                var radio = document.querySelector('input[value="monero_gateway"]');
                return radio && radio.checked;
            }

            function update() {
                var banner = document.getElementById('xmr-checkout-discount');

                if(!isMoneroSelected()) {
                    if(banner) banner.style.display = 'none';
                    return;
                }

                var total = readTotal();
                if(!total) return;

                var discounted = (total * (1 - discount / 100)).toFixed(2);
                var savings = (total - parseFloat(discounted)).toFixed(2);

                if(!banner) {
                    var content = findMoneroContent();
                    if(!content) return;
                    banner = document.createElement('div');
                    banner.id = 'xmr-checkout-discount';
                    banner.className = 'xmr-checkout-discount';
                    content.appendChild(banner);
                }

                banner.style.display = '';
                banner.innerHTML = '<div class="xmr-checkout-savings-inner">'
                    + '<span class="xmr-original-price">$' + total.toFixed(2) + '</span>'
                    + ' <span class="xmr-arrow-icon">&rarr;</span> '
                    + '<span class="xmr-discounted-price">$' + discounted + '</span>'
                    + ' &mdash; <span class="xmr-savings-text">You save <strong>$' + savings + '</strong> (' + discount + '% off)!</span>'
                    + '</div>';
            }

            function init() {
                setInterval(function(){
                    watchTotalRow();
                    update();
                }, 1000);
                document.body.addEventListener('change', function(){ setTimeout(update, 300); });
                document.body.addEventListener('click', function(){ setTimeout(update, 300); });
            }

            if(document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    }

    // [monero-price currency="USD"]
    // currency: BTC, GBP, etc
    // if no none, then default store currency
    function monero_price_func( $atts ) {
        global  $woocommerce;
        $a = shortcode_atts( array(
            'currency' => get_woocommerce_currency()
        ), $atts );

        $currency = strtoupper($a['currency']);
        $rate = Monero_Gateway::get_live_rate($currency);
        if($currency == 'BTC')
            $rate_formatted = sprintf('%.8f', $rate / 1e8);
        else
            $rate_formatted = sprintf('%.5f', $rate / 1e8);

        return "<span class=\"monero-price\">1 XMR = $rate_formatted $currency</span>";
    }
    add_shortcode('monero-price', 'monero_price_func');


    // [monero-accepted-here]
    function monero_accepted_func() {
        return '<img src="'.MONERO_GATEWAY_PLUGIN_URL.'assets/images/monero-accepted-here.png" />';
    }
    add_shortcode('monero-accepted-here', 'monero_accepted_func');

}

register_deactivation_hook(__FILE__, 'monero_deactivate');
function monero_deactivate() {
    $timestamp = wp_next_scheduled('monero_update_event');
    wp_unschedule_event($timestamp, 'monero_update_event');
}

register_activation_hook(__FILE__, 'monero_install');
function monero_install() {
    global $wpdb;
    require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . "monero_gateway_quotes";
    if($wpdb->get_var("show tables like '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
               order_id BIGINT(20) UNSIGNED NOT NULL,
               payment_id VARCHAR(95) DEFAULT '' NOT NULL,
               currency VARCHAR(6) DEFAULT '' NOT NULL,
               rate BIGINT UNSIGNED DEFAULT 0 NOT NULL,
               amount BIGINT UNSIGNED DEFAULT 0 NOT NULL,
               paid TINYINT NOT NULL DEFAULT 0,
               confirmed TINYINT NOT NULL DEFAULT 0,
               pending TINYINT NOT NULL DEFAULT 1,
               created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (order_id)
               ) $charset_collate;";
        dbDelta($sql);
    }

    $table_name = $wpdb->prefix . "monero_gateway_quotes_txids";
    if($wpdb->get_var("show tables like '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
               id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
               payment_id VARCHAR(95) DEFAULT '' NOT NULL,
               txid VARCHAR(64) DEFAULT '' NOT NULL,
               amount BIGINT UNSIGNED DEFAULT 0 NOT NULL,
               height MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
               PRIMARY KEY (id),
               UNIQUE KEY (payment_id, txid, amount)
               ) $charset_collate;";
        dbDelta($sql);
    }

    $table_name = $wpdb->prefix . "monero_gateway_live_rates";
    if($wpdb->get_var("show tables like '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
               currency VARCHAR(6) DEFAULT '' NOT NULL,
               rate BIGINT UNSIGNED DEFAULT 0 NOT NULL,
               updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (currency)
               ) $charset_collate;";
        dbDelta($sql);
    }

    $table_name = $wpdb->prefix . "monero_gateway_pos_quotes";
        $sql = "CREATE TABLE $table_name (
               id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
               token CHAR(64) NOT NULL,
               payment_id VARCHAR(95) DEFAULT '' NOT NULL,
               order_id BIGINT(20) UNSIGNED NULL,
               request_id CHAR(32) NULL,
               request_hash CHAR(64) NULL,
               request_state VARCHAR(16) DEFAULT 'legacy' NOT NULL,
               sale_json LONGTEXT NULL,
               currency VARCHAR(6) DEFAULT 'USD' NOT NULL,
               rate BIGINT UNSIGNED DEFAULT 0 NOT NULL,
               fiat_amount DECIMAL(12,2) DEFAULT 0.00 NOT NULL,
               amount BIGINT UNSIGNED DEFAULT 0 NOT NULL,
               paid TINYINT NOT NULL DEFAULT 0,
               confirmed TINYINT NOT NULL DEFAULT 0,
               pending TINYINT NOT NULL DEFAULT 1,
               created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY token (token),
               UNIQUE KEY payment_id (payment_id),
               UNIQUE KEY request_id (request_id),
               UNIQUE KEY order_id (order_id),
               KEY pending (pending)
               ) $charset_collate;";
        dbDelta($sql);

    update_option('monero_gateway_db_version', MONERO_GATEWAY_DB_VERSION);
}

// Activation hooks do not run when updated plugin files are deployed in place.
// Keep the small custom schema current on the next normal WordPress request.
add_action('plugins_loaded', 'monero_maybe_upgrade', 2);
function monero_maybe_upgrade() {
    if(get_option('monero_gateway_db_version') !== MONERO_GATEWAY_DB_VERSION) {
        monero_install();
    }
}

// Purge only the generated cashier-page cache once per release so its HTML
// always references the matching access/session JavaScript.
add_action('plugins_loaded', 'monero_maybe_purge_pos_page', 20);
function monero_maybe_purge_pos_page() {
    $release = '4.3.0';
    if(get_option('monero_gateway_pos_release') === $release) {
        return;
    }
    if(function_exists('wardsace_pagecache_purge_url')) {
        wardsace_pagecache_purge_url(home_url('/monero-in-store/'));
    }
    update_option('monero_gateway_pos_release', $release);
}
