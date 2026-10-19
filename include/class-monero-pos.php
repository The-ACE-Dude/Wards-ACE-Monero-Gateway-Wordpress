<?php

defined('ABSPATH') || exit;

/**
 * Staff checkout backed by WooCommerce orders. Historical payment-only
 * requests remain readable; every new request belongs to a website sale.
 */
class Monero_POS
{
    const PAGE_SLUG = 'monero-in-store';
    const UNLOCK_ACTION = 'monero_pos_unlock';
    const ACCESS_ACTION = 'monero_pos_access';
    const CREATE_ACTION = 'monero_pos_create';
    const STATUS_ACTION = 'monero_pos_status';
    const LOOKUP_ACTION = 'monero_pos_lookup';
    const PREVIEW_ACTION = 'monero_pos_preview';
    const INVENTORY_LOCK = 'wardsace_monero_pos_inventory';
    const ACCESS_COOKIE = 'wardsace_monero_pos_access';
    const ACCESS_TTL = 43200;
    // bcrypt hash for the cashier PIN, from password_hash($pin, PASSWORD_BCRYPT). The PIN itself
    // is never stored in a cookie or database. Set your own: an empty hash accepts no PIN.
    const PIN_HASH = '';

    public static function init()
    {
        add_action('template_redirect', array(__CLASS__, 'maybe_render_page'), 1);
        add_action('wp_ajax_' . self::UNLOCK_ACTION, array(__CLASS__, 'unlock_ajax'));
        add_action('wp_ajax_' . self::ACCESS_ACTION, array(__CLASS__, 'access_status_ajax'));
        add_action('wp_ajax_' . self::CREATE_ACTION, array(__CLASS__, 'create_quote_ajax'));
        add_action('wp_ajax_' . self::STATUS_ACTION, array(__CLASS__, 'quote_status_ajax'));
        add_action('wp_ajax_nopriv_' . self::UNLOCK_ACTION, array(__CLASS__, 'unlock_ajax'));
        add_action('wp_ajax_nopriv_' . self::ACCESS_ACTION, array(__CLASS__, 'access_status_ajax'));
        add_action('wp_ajax_nopriv_' . self::CREATE_ACTION, array(__CLASS__, 'create_quote_ajax'));
        add_action('wp_ajax_nopriv_' . self::STATUS_ACTION, array(__CLASS__, 'quote_status_ajax'));
        foreach(array(self::LOOKUP_ACTION => 'lookup_ajax', self::PREVIEW_ACTION => 'preview_ajax') as $action => $method) {
            add_action('wp_ajax_' . $action, array(__CLASS__, $method));
            add_action('wp_ajax_nopriv_' . $action, array(__CLASS__, $method));
        }
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
    }

    public static function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Monero In-Store', 'monero_gateway'),
            __('Monero In-Store', 'monero_gateway'),
            'edit_shop_orders',
            'monero-in-store',
            array(__CLASS__, 'redirect_admin_menu')
        );
    }

    public static function redirect_admin_menu()
    {
        wp_safe_redirect(home_url('/' . self::PAGE_SLUG . '/'));
        exit;
    }

    private static function is_pos_request()
    {
        $request_path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        $page_path = wp_parse_url(home_url('/' . self::PAGE_SLUG . '/'), PHP_URL_PATH);
        return untrailingslashit($request_path) === untrailingslashit($page_path);
    }

    public static function maybe_render_page()
    {
        if(!self::is_pos_request()) {
            return;
        }

        if(self::rate_limited('page', 60, 300)) {
            wp_die(
                esc_html__('Too many cashier-page requests. Wait a few minutes and try again.', 'monero_gateway'),
                esc_html__('Rate limit exceeded', 'monero_gateway'),
                array('response' => 429)
            );
        }

        global $wp_query;
        if($wp_query) {
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
            $wp_query->is_singular = true;
            $wp_query->is_home = false;
            $wp_query->is_archive = false;
        }
        status_header(200);
        nocache_headers();

        add_filter('pre_get_document_title', array(__CLASS__, 'page_title'), PHP_INT_MAX);
        add_filter('wp_title', array(__CLASS__, 'page_title'), PHP_INT_MAX);
        add_filter('body_class', array(__CLASS__, 'body_class'));

        $asset_version = '4.3.0.2'; // monero-pos.js changed (failover hold): browsers fetch it again
        wp_enqueue_script('monero-qr-code', MONERO_GATEWAY_PLUGIN_URL . 'assets/js/qrcode.min.js', array(), $asset_version, true);
        wp_enqueue_script('monero-clipboard-js', MONERO_GATEWAY_PLUGIN_URL . 'assets/js/clipboard.min.js', array(), $asset_version, true);
        wp_enqueue_script('monero-pos-file', MONERO_GATEWAY_PLUGIN_URL . 'assets/js/monero-pos-file.js', array(), $asset_version, true);
        wp_enqueue_script('monero-pos-discovery', MONERO_GATEWAY_PLUGIN_URL . 'assets/js/monero-pos-discovery.js', array(), $asset_version, true);
        wp_enqueue_script('monero-pos-terminal', MONERO_GATEWAY_PLUGIN_URL . 'assets/js/monero-pos-terminal.js', array('monero-pos-file', 'monero-pos-discovery'), $asset_version, true);
        wp_enqueue_script('monero-pos-image', MONERO_GATEWAY_PLUGIN_URL . 'assets/js/monero-pos-image.js', array('monero-qr-code', 'monero-pos-terminal'), $asset_version, true);
        wp_enqueue_script('monero-pos', MONERO_GATEWAY_PLUGIN_URL . 'assets/js/monero-pos.js', array('monero-qr-code', 'monero-clipboard-js', 'monero-pos-terminal', 'monero-pos-image'), $asset_version, true);
        wp_enqueue_style('monero-pos', MONERO_GATEWAY_PLUGIN_URL . 'assets/css/monero-pos.css', array(), $asset_version);

        $authorized = self::has_access();
        $turnstile_enabled = function_exists('cfturnstile_field_show') && get_option('cfturnstile_key') && get_option('cfturnstile_secret');
        if(!$authorized && $turnstile_enabled) {
            do_action('cfturnstile_enqueue_scripts');
        }

        $initial_token = isset($_GET['payment']) ? self::sanitize_token(wp_unslash($_GET['payment'])) : '';
        // Operator confirmed the JPG displays and requested automatic operation.
        // Each connection still verifies the installed form's exact size and CRC.
        $terminal_profile = get_option('wardsace_monero_pos_jpeg_verified_v1', 'no') === 'yes'
            ? array('verified' => true, 'mode' => 'jpeg', 'form' => 'WARDXMR.K3Z')
            : null;
        wp_localize_script('monero-pos', 'moneroPosConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('monero_pos'),
            'unlockAction' => self::UNLOCK_ACTION,
            'accessAction' => self::ACCESS_ACTION,
            'createAction' => self::CREATE_ACTION,
            'statusAction' => self::STATUS_ACTION,
            'lookupAction' => self::LOOKUP_ACTION,
            'previewAction' => self::PREVIEW_ACTION,
            'authorized' => $authorized,
            'turnstileEnabled' => (bool) $turnstile_enabled,
            'initialToken' => $initial_token,
            'pageUrl' => home_url('/' . self::PAGE_SLUG . '/'),
            'explorerUrl' => MONERO_GATEWAY_EXPLORER_URL,
            'terminalProfile' => apply_filters('monero_pos_terminal_profile', $terminal_profile),
        ));

        get_header();
        include MONERO_GATEWAY_PLUGIN_DIR . 'templates/monero-gateway/pos/page.php';
        get_footer();
        exit;
    }

    public static function page_title()
    {
        return __('Monero In-Store Checkout', 'monero_gateway');
    }

    public static function body_class($classes)
    {
        $classes[] = 'monero-pos-page';
        return $classes;
    }

    private static function authorize_ajax()
    {
        if(!check_ajax_referer('monero_pos', 'nonce', false)) {
            wp_send_json_error(array('message' => __('This page has expired. Reload it to continue.', 'monero_gateway')), 403);
        }
        if(!self::has_access()) {
            wp_send_json_error(array('message' => __('Cashier access has expired. Reload the page and enter the PIN again.', 'monero_gateway')), 403);
        }
    }

    private static function has_access()
    {
        if(current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce')) {
            return true;
        }

        if(empty($_COOKIE[self::ACCESS_COOKIE])) {
            return false;
        }

        $parts = explode('|', sanitize_text_field(wp_unslash($_COOKIE[self::ACCESS_COOKIE])), 2);
        if(count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }

        $expires = intval($parts[0]);
        if($expires < time() || $expires > time() + self::ACCESS_TTL + 60) {
            return false;
        }

        $expected = hash_hmac('sha256', 'monero-pos|' . $expires, wp_salt('auth'));
        return hash_equals($expected, $parts[1]);
    }

    private static function grant_access()
    {
        $expires = time() + self::ACCESS_TTL;
        $signature = hash_hmac('sha256', 'monero-pos|' . $expires, wp_salt('auth'));
        setcookie(self::ACCESS_COOKIE, $expires . '|' . $signature, array(
            'expires' => $expires,
            'path' => '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ));
    }

    private static function client_key()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        return substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 24);
    }

    private static function rate_limited($scope, $maximum, $window)
    {
        $key = 'monero_pos_' . sanitize_key($scope) . '_' . self::client_key();
        $count = intval(get_transient($key));
        if($count >= $maximum) {
            return true;
        }
        set_transient($key, $count + 1, $window);
        return false;
    }

    public static function unlock_ajax()
    {
        if(!check_ajax_referer('monero_pos', 'nonce', false)) {
            wp_send_json_error(array('message' => __('This page has expired. Reload it to continue.', 'monero_gateway')), 403);
        }

        if(self::rate_limited('pin', 8, 900)) {
            wp_send_json_error(array('message' => __('Too many access attempts. Wait 15 minutes and try again.', 'monero_gateway')), 429);
        }

        $turnstile_enabled = function_exists('cfturnstile_check') && get_option('cfturnstile_key') && get_option('cfturnstile_secret');
        if($turnstile_enabled) {
            $turnstile_token = isset($_POST['turnstile']) ? sanitize_text_field(wp_unslash($_POST['turnstile'])) : '';
            $turnstile_result = cfturnstile_check($turnstile_token, 'monero-pos');
            if(empty($turnstile_result['success'])) {
                wp_send_json_error(array('message' => __('Cloudflare could not verify this request. Complete the check and try again.', 'monero_gateway')), 403);
            }
        }

        $pin = isset($_POST['pin']) ? (string) wp_unslash($_POST['pin']) : '';
        if(!password_verify($pin, self::PIN_HASH)) {
            wp_send_json_error(array('message' => __('Incorrect cashier PIN.', 'monero_gateway')), 403);
        }

        self::grant_access();
        wp_send_json_success(array('message' => __('Cashier access granted.', 'monero_gateway')));
    }

    public static function access_status_ajax()
    {
        if(!check_ajax_referer('monero_pos', 'nonce', false)) {
            wp_send_json_error(array('message' => __('This page has expired. Reload it to continue.', 'monero_gateway')), 403);
        }
        if(self::rate_limited('access', 60, 300)) {
            wp_send_json_error(array('message' => __('Too many access checks. Wait a few minutes and try again.', 'monero_gateway')), 429);
        }
        if(!self::has_access()) {
            wp_send_json_error(array('message' => __('Cashier PIN required.', 'monero_gateway')), 403);
        }
        wp_send_json_success(array('authorized' => true));
    }

    private static function sanitize_token($token)
    {
        $token = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $token));
        return strlen($token) === 64 ? $token : '';
    }

    private static function input()
    {
        $input = array();
        foreach(array('mode' => '', 'amount' => '', 'items' => '[]', 'expected_total' => '') as $key => $default) {
            $input[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? trim(wp_unslash($_POST[$key])) : $default;
        }
        return $input;
    }

    private static function send_error($error, $status = 400, $definitive = true)
    {
        wp_send_json_error(array('message' => $error->get_error_message(), 'code' => $error->get_error_code(), 'definitive' => $definitive), $status);
    }

    public static function lookup_ajax()
    {
        self::authorize_ajax();
        if(self::rate_limited('lookup', 900, 300)) {
            self::send_error(new WP_Error('rate_limit', 'Too many barcode lookups. Wait a moment and try again.'), 429);
        }
        $barcode = isset($_POST['barcode']) && is_string($_POST['barcode']) ? trim(wp_unslash($_POST['barcode'])) : '';
        $product = Monero_POS_Orders::lookup_product($barcode);
        if(is_wp_error($product)) { self::send_error($product); }
        wp_send_json_success($product);
    }

    public static function preview_ajax()
    {
        self::authorize_ajax();
        if(self::rate_limited('preview', 900, 300)) {
            self::send_error(new WP_Error('rate_limit', 'Too many total updates. Wait a moment and try again.'), 429);
        }
        $sale = Monero_POS_Orders::build_sale(self::input());
        if(is_wp_error($sale)) { self::send_error($sale); }
        wp_send_json_success($sale);
    }

    public static function create_quote_ajax()
    {
        self::authorize_ajax();
        $request_id = isset($_POST['request_id']) && is_string($_POST['request_id']) ? wp_unslash($_POST['request_id']) : '';
        if(!preg_match('/^[a-f0-9]{32}$/D', $request_id)) {
            self::send_error(new WP_Error('invalid_request', 'Reload the cashier page before starting this sale.'));
        }
        if(self::rate_limited('create', 40, 300)) {
            self::send_error(new WP_Error('rate_limit', 'Too many checkout attempts. Wait a few minutes and retry the same sale.'), 429, false);
        }
        // Serialize with inventory imports. The persisted unique request key
        // also protects retries after a worker crash or lost HTTP response.
        if(!Monero_POS_Inventory::acquire_lock(10)) {
            self::send_error(new WP_Error('request_busy', 'Inventory is updating. Retry this same checkout in a moment.'), 409, false);
        }
        try {
            $result = self::create_checkout($request_id, self::input());
        } catch(Throwable $error) {
            $result = new WP_Error('checkout_incomplete', 'Checkout could not finish. Retry this same sale to recover its order; do not start it again in another tab.', array('definitive' => false, 'status' => 503));
            if(function_exists('wc_get_logger')) { wc_get_logger()->error('POS checkout recovery needed: ' . $request_id . ' (' . get_class($error) . ')', array('source' => 'monero-pos')); }
        } finally {
            Monero_POS_Inventory::release_lock();
        }
        if(is_wp_error($result)) {
            $data = $result->get_error_data();
            $data = is_array($data) ? $data : array();
            wp_send_json_error(array_merge($data, array('message' => $result->get_error_message(), 'code' => $result->get_error_code())), isset($data['status']) ? $data['status'] : 400);
        }
        wp_send_json_success($result);
    }

    private static function create_checkout($request_id, $input)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'monero_gateway_pos_quotes';
        $hash = hash('sha256', wp_json_encode($input));
        $quote = $wpdb->get_row($wpdb->prepare("SELECT *, NOW() AS now FROM $table WHERE request_id=%s", $request_id));
        if($quote && !hash_equals((string) $quote->request_hash, $hash)) {
            return new WP_Error('request_conflict', 'This checkout key belongs to a different sale. Recover the original sale before starting another.', array('status' => 409, 'definitive' => false));
        }
        if($quote && Monero_Gateway::recovery_hold($quote)) {
            return new WP_Error('recovery_hold', 'This historical checkout needs manager review before it can continue. Do not take another payment.', array('status' => 409, 'definitive' => false));
        }
        if($quote && isset($quote->request_state) && $quote->request_state === 'rejected') {
            return new WP_Error('request_retired', 'This checkout was retired before an order was created. Review the sale and create a fresh request.', array('status' => 409, 'definitive' => true));
        }
        if($quote && !empty($quote->order_id)) {
            if(!wc_get_order($quote->order_id)) {
                return new WP_Error('missing_order', 'The saved website order could not be loaded. Ask a manager to review this checkout.', array('status' => 409, 'definitive' => false));
            }
            return self::format_quote($quote);
        }
        $new_claim = !$quote;
        if(!$quote) {
            if(Monero_Gateway::get_confirm_type() !== 'monero-wallet-rpc') {
                return new WP_Error('wallet_mode', 'Website in-store orders require live wallet RPC verification. Ask a manager to check the Monero gateway settings.', array('status' => 503, 'definitive' => true));
            }
            $sale = Monero_POS_Orders::build_sale($input);
            if(is_wp_error($sale)) {
                $sale->add_data(array('status' => 400, 'definitive' => true));
                return $sale;
            }
            if($input['expected_total'] !== $sale['total']) {
                return new WP_Error('price_changed', 'Review the updated sale total before checking out.', array('status' => 409, 'definitive' => true, 'sale' => $sale));
            }
            $rate_table = $wpdb->prefix . 'monero_gateway_live_rates';
            $rate_row = $wpdb->get_row("SELECT rate, TIMESTAMPDIFF(SECOND, updated, NOW()) AS age_seconds FROM $rate_table WHERE currency='USD'");
            $rate = $rate_row ? intval($rate_row->rate) : 0;
            if($rate <= 0 || intval($rate_row->age_seconds) > 300 || intval($rate_row->age_seconds) < 0) {
                return new WP_Error('rate_unavailable', 'A recent XMR/USD rate is unavailable. Wait a moment and try again; no order was created.', array('status' => 503, 'definitive' => true));
            }
            $destination = Monero_Gateway::create_payment_destination('Website in-store sale ' . gmdate('Y-m-d H:i:s') . ' UTC');
            if(is_wp_error($destination)) {
                $destination->add_data(array('status' => 503, 'definitive' => true));
                return $destination;
            }
            $atomic_value = round(MONERO_GATEWAY_ATOMIC_UNITS_POW * 1e8 * (float) $sale['total'] / $rate);
            // A corrupt near-zero fiat rate must not wrap a float outside the
            // signed integer range into a smaller, positive payment amount.
            if(!is_finite($atomic_value) || $atomic_value < 1 || $atomic_value >= PHP_INT_MAX) {
                return new WP_Error('invalid_amount', 'The converted Monero amount is invalid.', array('status' => 503, 'definitive' => true));
            }
            $atomic_amount = (int) $atomic_value;
            $token = bin2hex(random_bytes(32));
            $saved = $wpdb->insert($table, array(
                'token' => $token, 'payment_id' => $destination['payment_id'],
                'currency' => 'USD', 'rate' => $rate, 'fiat_amount' => $sale['total'],
                'amount' => $atomic_amount, 'paid' => 0, 'confirmed' => 0, 'pending' => 0,
                'request_id' => $request_id, 'request_hash' => $hash, 'request_state' => 'building', 'sale_json' => wp_json_encode($sale),
            ));
            if(!$saved) { throw new RuntimeException('Could not persist checkout claim.'); }
            $quote = self::get_quote($token);
        }
        $sale = json_decode($quote->sale_json, true);
        if(!is_array($sale)) { throw new RuntimeException('Invalid persisted sale.'); }
        // Recover an order saved immediately before a worker/browser timeout.
        $orders = self::get_request_orders($request_id);
        if(count($orders) > 1) { throw new RuntimeException('Multiple orders for checkout request.'); }
        if(!$orders && !$new_claim) {
            $age = (new DateTime($quote->now))->getTimestamp() - (new DateTime($quote->created))->getTimestamp();
            $reviewed = Monero_POS_Orders::build_sale($input);
            if($age >= Monero_Gateway::get_valid_time() || is_wp_error($reviewed) || wp_json_encode($reviewed) !== wp_json_encode($sale)) {
                if($wpdb->update($table, array('request_state' => 'rejected'), array('id' => $quote->id)) === false) { throw new RuntimeException('Could not retire checkout claim.'); }
                return new WP_Error('sale_changed', 'The interrupted sale expired or its products changed. Review the current prices and stock before making a fresh request.', array('status' => 409, 'definitive' => true));
            }
        }
        $order = $orders ? reset($orders) : Monero_POS_Orders::create_order($sale, $quote->token, $request_id);
        if(is_wp_error($order)) {
            $error_data = $order->get_error_data();
            $order_id = is_array($error_data) && isset($error_data['order_id']) ? intval($error_data['order_id']) : 0;
            return new WP_Error('order_incomplete', $order->get_error_message(), array('status' => 503, 'definitive' => false, 'order_id' => $order_id));
        }
        if($order->get_meta('_monero_pos_ready') !== 'yes') {
            return new WP_Error('order_incomplete', 'An interrupted website order needs manager review before this sale can continue.', array('status' => 409, 'definitive' => false, 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number()));
        }
        if(!$order->has_status('on-hold')) {
            return new WP_Error('order_changed', 'The saved order has changed. Ask a manager to review it before taking payment.', array('status' => 409, 'definitive' => false, 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number()));
        }
        $saved = $wpdb->update($table, array('order_id' => $order->get_id(), 'pending' => 1, 'request_state' => 'ready'), array('id' => $quote->id), array('%d', '%d', '%s'), array('%d'));
        if($saved === false) { throw new RuntimeException('Could not link checkout order.'); }
        return self::format_quote(self::get_quote($quote->token));
    }

    public static function quote_status_ajax()
    {
        self::authorize_ajax();
        if(self::rate_limited('status', 400, 300)) {
            wp_send_json_error(array('message' => __('Payment status is being checked too frequently. Wait a moment and try again.', 'monero_gateway')), 429);
        }
        $token = isset($_REQUEST['token']) ? self::sanitize_token(wp_unslash($_REQUEST['token'])) : '';
        if(!$token) {
            wp_send_json_error(array('message' => __('That payment request is invalid.', 'monero_gateway')), 400);
        }

        $quote = self::get_quote($token);
        if(!$quote) {
            wp_send_json_error(array('message' => __('That payment request was not found.', 'monero_gateway')), 404);
        }

        $warning = '';
        $snapshot = null;
        try {
            // A reopened paid/confirmed request also needs a current wallet result.
            $snapshot = self::sync_quote($quote);
        } catch(Throwable $throwable) {
            $warning = __('Payment monitoring is temporarily unavailable; the page will keep trying.', 'monero_gateway');
        }

        $details = self::format_quote($quote, $snapshot);
        if($warning) {
            $details['warning'] = trim((isset($details['warning']) ? $details['warning'] . ' ' : '') . $warning);
        }
        wp_send_json_success($details);
    }

    private static function get_quote($token)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'monero_gateway_pos_quotes';
        return $wpdb->get_row($wpdb->prepare("SELECT *, NOW() AS now FROM $table WHERE token=%s", $token));
    }

    private static function get_request_orders($request_id)
    {
        global $wpdb;
        $orders = wc_get_orders(array('limit' => 2, 'type' => 'shop_order', 'meta_key' => '_monero_pos_request_id', 'meta_value' => $request_id));
        if(!is_array($orders) || !empty($wpdb->last_error)) {
            throw new RuntimeException('Could not verify the orders belonging to this checkout request.');
        }
        return $orders;
    }

    /** Recover orders saved before a worker or browser disappeared. Never creates orders. */
    private static function recover_incomplete_quotes()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'monero_gateway_pos_quotes';
        $eligible = Monero_Gateway::recovery_sql_condition();
        $quotes = $wpdb->get_results("SELECT *, NOW() AS now FROM $table WHERE request_state='building' AND order_id IS NULL AND ($eligible) ORDER BY updated ASC, id ASC LIMIT 50");
        $held_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table WHERE request_state='building' AND order_id IS NULL AND NOT ($eligible)"));
        foreach((array) $quotes as $quote) {
            if(Monero_Gateway::recovery_hold($quote)) { $held_count++; continue; }
            $locked = false;
            $current = null;
            try {
                $locked = Monero_POS_Inventory::acquire_lock(5);
                if(!$locked) { throw new RuntimeException('Inventory is busy.'); }
                $current = self::get_quote($quote->token);
                if(!$current || !empty($current->order_id) || $current->request_state !== 'building') { continue; }
                $orders = self::get_request_orders($current->request_id);
                if(count($orders) === 1) {
                    $order = reset($orders);
                    if($order->get_meta('_monero_pos') === 'yes' && $order->get_meta('_monero_pos_ready') === 'yes' && $order->has_status('on-hold')) {
                        $saved = $wpdb->update($table, array('order_id' => $order->get_id(), 'pending' => 1, 'request_state' => 'ready'), array('id' => $current->id), array('%d', '%d', '%s'), array('%d'));
                        if($saved === false) { throw new RuntimeException('Could not recover the checkout order link.'); }
                    }
                    // An incomplete or manually changed order needs manager
                    // review. Do not rebuild it, release stock, or take payment.
                } elseif(!$orders) {
                    $age = (new DateTime($current->now))->getTimestamp() - (new DateTime($current->created))->getTimestamp();
                    if($age >= Monero_Gateway::get_valid_time()) {
                        if($wpdb->update($table, array('request_state' => 'rejected'), array('id' => $current->id)) === false) {
                            throw new RuntimeException('Could not retire the abandoned checkout request.');
                        }
                    }
                }
            } catch(Throwable $error) {
                // Retain the request for retry or manager review. One failed
                // recovery must not prevent other payment requests being polled.
            } finally {
                try {
                    $wpdb->update($table, array('updated' => $current ? $current->now : $quote->now), array('id' => $quote->id));
                } catch(Throwable $scheduling_error) {
                    // Leave recovery retryable if its scheduling write fails.
                } finally {
                    if($locked) { Monero_POS_Inventory::release_lock(); }
                }
            }
        }
        Monero_Gateway::log_recovery_holds('pos', $held_count);
    }

    public static function update_pending_quotes($network_height = null)
    {
        global $wpdb;
        self::recover_incomplete_quotes();
        $table = $wpdb->prefix . 'monero_gateway_pos_quotes';
        $eligible = Monero_Gateway::recovery_sql_condition();
        $quotes = $wpdb->get_results("SELECT *, NOW() AS now FROM $table WHERE pending=1 AND ($eligible) ORDER BY updated ASC, id ASC LIMIT 100");
        $held_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table WHERE pending=1 AND NOT ($eligible)"));
        foreach($quotes as $quote) {
            if(Monero_Gateway::recovery_hold($quote)) { $held_count++; continue; }
            try {
                self::sync_quote($quote, $network_height);
            } catch(Throwable $throwable) {
                // Advance scheduling even if one wallet request keeps failing.
                $wpdb->update($table, array('updated' => $quote->now), array('id' => $quote->id));
                continue;
            }
        }
        Monero_Gateway::log_recovery_holds('pos', $held_count);
    }

    private static function sync_quote($quote, $network_height = null)
    {
        if(Monero_Gateway::recovery_hold($quote)) return null;
        if(empty($quote->order_id)) { return self::sync_quote_locked($quote, $network_height); }
        if(!Monero_POS_Inventory::acquire_lock(5)) { throw new RuntimeException('Inventory is busy.'); }
        try {
            $current = self::get_quote($quote->token);
            if(!$current) { throw new RuntimeException('Payment request is unavailable.'); }
            foreach(get_object_vars($current) as $key => $value) { $quote->$key = $value; }
            return self::sync_quote_locked($quote, $network_height);
        }
        finally { Monero_POS_Inventory::release_lock(); }
    }

    private static function sync_quote_locked($quote, $network_height = null)
    {
        global $wpdb;
        if(Monero_Gateway::recovery_hold($quote)) return null;
        $quote_table = $wpdb->prefix . 'monero_gateway_pos_quotes';
        $tx_table = $wpdb->prefix . 'monero_gateway_quotes_txids';

        // Read before the wallet data and again after it (below): a switch to a
        // backup node in between holds this request too.
        $held = Monero_Gateway::wallet_on_backup();
        $snapshot = Monero_Gateway::get_payment_reference_snapshot($quote->payment_id);
        $new_transactions = $snapshot['transactions'];
        foreach($new_transactions as $transaction) {
            $saved = $wpdb->query($wpdb->prepare(
                "INSERT INTO $tx_table (payment_id, txid, amount, height) VALUES (%s, %s, %d, %d) ON DUPLICATE KEY UPDATE height=%d",
                $quote->payment_id,
                $transaction['txid'],
                $transaction['amount'],
                $transaction['height'],
                $transaction['height']
            ));
            if($saved === false) {
                throw new RuntimeException('The in-store payment observation could not be saved.');
            }
        }

        // The shared table is an audit history, not the current mempool. Keep its
        // rows for reconciliation without counting transfers the wallet dropped.
        if($snapshot['status_fresh']) {
            $transactions = array_map(function($transaction) { return (object) $transaction; }, $new_transactions);
        } else {
            // Legacy view-key monitoring scans a limited block window; it cannot
            // prove the absence of an earlier transfer or drive a terminal result.
            $transactions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tx_table WHERE payment_id=%s", $quote->payment_id));
        }
        $amount_paid = 0;
        $heights = array();
        foreach($transactions as $transaction) {
            if(intval($transaction->amount) > PHP_INT_MAX - $amount_paid) {
                throw new RuntimeException('The in-store payment amount exceeds the supported range.');
            }
            $amount_paid += intval($transaction->amount);
            $heights[] = intval($transaction->height);
        }

        $paid = !empty($transactions) && $amount_paid > intval($quote->amount) - MONERO_GATEWAY_ATOMIC_UNIT_THRESHOLD;
        $confirmed = false;
        if($paid) {
            $required = Monero_Gateway::get_required_confirmations();
            if($required === 0) {
                $confirmed = true;
            } elseif(!in_array(0, $heights, true)) {
                if($network_height === null) {
                    $network_height = Monero_Gateway::get_network_height();
                }
                if(is_numeric($network_height)) {
                    set_transient('monero_gateway_network_height', intval($network_height));
                }
                $confirmed = is_numeric($network_height) && intval($network_height) - max($heights) >= $required;
            }
        }

        $created = new DateTime($quote->created);
        $now = new DateTime($quote->now);
        // Same rule as web orders: a wallet cut off from the network can't
        // expire a sale whose payment it had no chance to see.
        $expired = !$paid && Monero_Gateway::quote_has_expired($quote);
        $pending = (!$confirmed && !$expired) ? 1 : 0;

        $state = array(
            'paid' => $paid ? 1 : 0,
            'confirmed' => $confirmed ? 1 : 0,
            'pending' => $pending,
            'updated' => $quote->now,
        );
        if($held || Monero_Gateway::wallet_on_backup()) {
            // On a backup node the transfers seen are recorded (above), but the
            // request keeps its paid, confirmed and pending flags: what a backup
            // shows is not a received payment at the counter (the page says the
            // payment is on hold), nothing expires, and the order is left alone
            // until the hold ends.
            $state['paid'] = intval($quote->paid);
            $state['confirmed'] = intval($quote->confirmed);
            $state['pending'] = intval($quote->pending);
        } elseif(!empty($quote->order_id)) {
            $order_quote = clone $quote;
            foreach($state as $key => $value) { $order_quote->$key = $value; }
            $order_quote->first_payment_late = $paid && !intval($quote->paid) && ($now->getTimestamp() - $created->getTimestamp()) >= Monero_Gateway::get_valid_time();
            Monero_POS_Orders::sync_order($order_quote, $snapshot, $expired);
            // Watch expired website requests for 30 days for late-payment review.
            if($expired && $created->getTimestamp() + 30 * DAY_IN_SECONDS > $now->getTimestamp()) { $state['pending'] = 1; }
        }
        $saved = $wpdb->update($quote_table, $state, array('id' => intval($quote->id)), array('%d', '%d', '%d', '%s'), array('%d'));
        if($saved === false) {
            throw new RuntimeException('The in-store payment status could not be saved.');
        }
        // Format this request's result from the same snapshot, even if another
        // cashier poll or cron request subsequently updates the database row.
        foreach($state as $key => $value) {
            $quote->$key = $value;
        }
        $snapshot['transactions'] = $transactions;
        return $snapshot;
    }

    private static function format_quote($quote, $snapshot = null)
    {
        global $wpdb;
        $tx_table = $wpdb->prefix . 'monero_gateway_quotes_txids';
        $rows = $snapshot !== null ? $snapshot['transactions'] : $wpdb->get_results($wpdb->prepare("SELECT * FROM $tx_table WHERE payment_id=%s ORDER BY height ASC", $quote->payment_id));

        $transactions = array();
        $amount_paid = 0;
        $heights = array();
        foreach($rows as $row) {
            $amount = intval($row->amount);
            $height = intval($row->height);
            $amount_paid += $amount;
            $heights[] = $height;
            $transactions[] = array(
                'txid' => $row->txid,
                'height' => $height,
                'amount' => $amount,
                'amount_formatted' => self::format_atomic_amount($amount),
            );
        }

        $created = new DateTime($quote->created);
        $now = new DateTime($quote->now);
        $age = max(0, $now->getTimestamp() - $created->getTimestamp());
        $expires_in = Monero_Gateway::get_valid_time() - $age;
        $amount_total = intval($quote->amount);
        $amount_due = max(0, $amount_total - $amount_paid);

        // On a backup node nothing is decided: a request still pending past its
        // window is not shown as expired, and the till says the payment is on hold.
        $recovery_hold = intval($quote->confirmed) !== 1 && Monero_Gateway::recovery_hold($quote);
        $hold = intval($quote->confirmed) !== 1 && intval($quote->pending) === 1 && (Monero_Gateway::wallet_on_backup() || $recovery_hold);
        if(intval($quote->confirmed) === 1) {
            $status = 'confirmed';
        } elseif(intval($quote->paid) === 1) {
            $status = 'paid';
        } elseif(intval($quote->pending) === 1 && ($expires_in > 0 || $hold)) {
            $status = empty($transactions) ? 'unpaid' : 'partial';
        } else {
            $status = empty($transactions) ? 'expired' : 'expired_partial';
        }

        $required = Monero_Gateway::get_required_confirmations();
        $blocks_to_confirm = $required;
        if(!empty($heights) && !in_array(0, $heights, true)) {
            $network_height = get_transient('monero_gateway_network_height');
            if(is_numeric($network_height)) {
                $blocks_to_confirm = max(0, $required - (intval($network_height) - max($heights)));
            }
        }

        $address = Monero_Gateway::get_payment_address($quote->payment_id);
        $amount_formatted = self::format_atomic_amount($amount_due);
        $uri = 'monero:' . $address . '?tx_amount=' . rawurlencode($amount_formatted);

        return array_merge(array(
            'token' => $quote->token,
            'payment_id' => $quote->payment_id,
            'integrated_address' => $address,
            'qrcode_uri' => $uri,
            'currency' => $quote->currency,
            'rate' => intval($quote->rate),
            'rate_formatted' => sprintf('%.2f', intval($quote->rate) / 1e8),
            'fiat_total' => (float) $quote->fiat_amount,
            'amount_total' => $amount_total,
            'amount_paid' => $amount_paid,
            'amount_due' => $amount_due,
            'amount_total_formatted' => self::format_atomic_amount($amount_total),
            'amount_paid_formatted' => self::format_atomic_amount($amount_paid),
            'amount_due_formatted' => $amount_formatted,
            'status' => $status,
            // Only a successful, complete wallet snapshot is fresh. Newly
            // created requests and cached/error responses deliberately are not.
            'status_fresh' => $snapshot !== null && $snapshot['status_fresh'] === true,
            'status_cached' => $snapshot === null,
            'status_source' => $snapshot !== null ? $snapshot['status_source'] : 'cached',
            'created' => $quote->created,
            'expires_in' => max(0, $expires_in),
            'order_expires' => Monero_Gateway::format_seconds_to_time(max(0, $expires_in)),
            'blocks_to_confirm' => $blocks_to_confirm,
            'time_to_confirm' => Monero_Gateway::format_seconds_to_time($blocks_to_confirm * MONERO_GATEWAY_DIFFICULTY_TARGET),
            'txs' => $transactions,
            'hold' => $hold,
        ), $recovery_hold ? array('warning' => __('This historical payment request is held for manual review. Contact the shop before sending payment or releasing goods.', 'monero_gateway')) : ($hold ? array('warning' => __('Monero payment verification is on hold until the shop wallet and trusted node checks are healthy. Do not send another payment, hand over goods or release the terminal yet.', 'monero_gateway')) : array()),
        !empty($quote->order_id) ? Monero_POS_Orders::order_details($quote) : array());
    }

    private static function format_atomic_amount($amount)
    {
        $scale = (int) MONERO_GATEWAY_ATOMIC_UNITS_POW;
        return intdiv($amount, $scale) . '.' . str_pad((string) ($amount % $scale), MONERO_GATEWAY_ATOMIC_UNITS, '0', STR_PAD_LEFT);
    }
}
