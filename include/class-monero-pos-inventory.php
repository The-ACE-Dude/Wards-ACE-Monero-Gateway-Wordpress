<?php
/** Keep website-only POS sales deducted from subsequent Epicor inventory snapshots. */
defined('ABSPATH') || exit;

class Monero_POS_Inventory {
    const ITEM_STOCK_ID = '_wardsace_pos_stock_product_id';
    const LOCK_NAME = 'wardsace_monero_pos_inventory';
    private static $lock_depth = 0;
    private static $initialized = false;
    private static $operations = array();

    public static function init() {
        if (self::$initialized) { return; }
        self::$initialized = true;
        add_filter('woocommerce_can_reduce_order_stock', array(__CLASS__, 'before_reduce'), PHP_INT_MAX, 2);
        add_filter('woocommerce_can_restore_order_stock', array(__CLASS__, 'before_restore'), PHP_INT_MAX, 2);
        add_filter('woocommerce_can_restock_refunded_items', array(__CLASS__, 'before_refund'), PHP_INT_MAX, 3);
        add_action('woocommerce_reduce_order_stock', array(__CLASS__, 'after_reduce'), PHP_INT_MAX);
        add_action('woocommerce_restore_order_stock', array(__CLASS__, 'after_restore'), PHP_INT_MAX);
        add_action('woocommerce_order_refunded', array(__CLASS__, 'after_refund'), PHP_INT_MAX);
        add_action('woocommerce_before_save_order_items', array(__CLASS__, 'before_edit'), 1);
        add_action('woocommerce_saved_order_items', array(__CLASS__, 'after_edit'), PHP_INT_MAX);
        add_action('woocommerce_reduce_order_item_stock', array(__CLASS__, 'sync_item_qoh'), 20);
        add_action('woocommerce_restore_order_item_stock', array(__CLASS__, 'sync_item_qoh'), 20);
        add_action('woocommerce_restock_refunded_item', array(__CLASS__, 'after_refund_item'), 20, 5);
        add_action('woocommerce_before_delete_order', array(__CLASS__, 'protect_order'), 1, 2);
        add_filter('pre_delete_post', array(__CLASS__, 'protect_post'), 1, 3);
        add_action('woocommerce_before_delete_order_item', array(__CLASS__, 'protect_item'), 1);
        // Some stock APIs are also called directly, without a matching outer action.
        register_shutdown_function(array(__CLASS__, 'release_all_locks'));
    }

    public static function acquire_lock($timeout = 10) {
        global $wpdb;
        if (self::$lock_depth > 0) { self::$lock_depth++; return true; }
        $acquired = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, max(0, (int) $timeout))) === '1';
        if ($acquired) { self::$lock_depth = 1; }
        return $acquired;
    }

    public static function release_lock() {
        global $wpdb;
        if (self::$lock_depth < 1) { return; }
        self::$lock_depth--;
        if (self::$lock_depth === 0) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
        }
    }

    public static function release_all_locks() {
        if (self::$lock_depth > 0) { self::$lock_depth = 1; self::release_lock(); }
        self::$operations = array();
    }

    /**
     * Woo owns this ledger: cancellation removes _reduced_stock and a restocking
     * refund reduces it. The tag is written only on new _monero_pos=yes product
     * lines. Both HPOS and legacy orders use these same two order-item tables.
     * Do not cache: payment/refund activity can change it during this request.
     */
    public static function load_offsets() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ledger.product_id, SUM(GREATEST(ledger.quantity, 0)) AS quantity
             FROM (
                 SELECT order_item_id,
                    MAX(CASE WHEN meta_key = %s THEN CAST(meta_value AS UNSIGNED) ELSE 0 END) AS product_id,
                    MAX(CASE WHEN meta_key = '_reduced_stock' THEN CAST(meta_value AS DECIMAL(20,6)) ELSE 0 END) AS quantity,
                    MAX(CASE WHEN meta_key = '_refunded_item_id' THEN 1 ELSE 0 END) AS is_refund
                 FROM {$wpdb->prefix}woocommerce_order_itemmeta
                 WHERE meta_key IN (%s, '_reduced_stock', '_refunded_item_id')
                 GROUP BY order_item_id
             ) ledger
             INNER JOIN {$wpdb->prefix}woocommerce_order_items items ON items.order_item_id = ledger.order_item_id
             WHERE items.order_item_type = 'line_item' AND ledger.product_id > 0 AND ledger.is_refund = 0
             GROUP BY ledger.product_id", self::ITEM_STOCK_ID, self::ITEM_STOCK_ID
        ));
        if ($wpdb->last_error) {
            throw new RuntimeException('Unable to read website POS inventory adjustments. Inventory sync was stopped.');
        }
        $offsets = array();
        foreach ((array) $rows as $row) {
            if ((float) $row->quantity > 0) { $offsets[(int) $row->product_id] = (float) $row->quantity; }
        }
        return $offsets;
    }

    public static function effective_quantity($raw_quantity, $product_id, array $offsets) {
        return (float) $raw_quantity - (float) ($offsets[(int) $product_id] ?? 0);
    }

    private static function is_pos_order($order) {
        return is_object($order) && method_exists($order, 'get_meta') && $order->get_meta('_monero_pos', true) === 'yes';
    }

    private static function begin_operation($type, $order) {
        if (!self::is_pos_order($order)) { return; }
        $key = $type . ':' . $order->get_id();
        if (isset(self::$operations[$key])) { return; }
        if (!self::acquire_lock()) {
            throw new RuntimeException('Inventory is currently syncing. Please retry this POS order operation.');
        }
        self::$operations[$key] = true;
    }

    private static function end_operation($type, $order) {
        $id = is_object($order) ? $order->get_id() : (int) $order;
        $key = $type . ':' . $id;
        if (!isset(self::$operations[$key])) { return; }
        unset(self::$operations[$key]);
        self::release_lock();
    }

    public static function before_reduce($allowed, $order) {
        if ($allowed) { self::begin_operation('reduce', $order); }
        return $allowed;
    }
    public static function before_restore($allowed, $order) {
        if ($allowed) { self::begin_operation('restore', $order); }
        return $allowed;
    }
    public static function before_refund($allowed, $order, $items) {
        if ($allowed) { self::begin_operation('refund', $order); }
        return $allowed;
    }
    public static function after_reduce($order) { self::end_operation('reduce', $order); }
    public static function after_restore($order) { self::end_operation('restore', $order); }
    public static function after_refund($order_id) { self::end_operation('refund', $order_id); }
    public static function before_edit($order_id) { self::begin_operation('edit', wc_get_order($order_id)); }
    public static function after_edit($order_id) {
        $order = wc_get_order($order_id);
        if (self::is_pos_order($order)) {
            foreach ($order->get_items() as $item) { self::sync_item_qoh($item); }
        }
        self::end_operation('edit', $order_id);
    }

    private static function sync_qoh($product_id) {
        if ($product_id > 0) {
            update_post_meta($product_id, 'qoh', (float) get_post_meta($product_id, '_stock', true));
        }
    }
    public static function sync_item_qoh($item) {
        self::sync_qoh((int) $item->get_meta(self::ITEM_STOCK_ID, true));
    }
    public static function after_refund_item($product_id, $old_stock, $new_stock, $order, $product) {
        if (self::is_pos_order($order)) { self::sync_qoh((int) $product->get_stock_managed_by_id()); }
    }

    private static function deletion_error() {
        throw new RuntimeException('This POS sale still records removed inventory. Keep the order for inventory accounting, or restock its items before permanently deleting it.');
    }
    public static function protect_item($item_id) {
        if (wc_get_order_item_meta($item_id, '_refunded_item_id', true)) { return; }
        if ((int) wc_get_order_item_meta($item_id, self::ITEM_STOCK_ID, true) > 0
            && (float) wc_get_order_item_meta($item_id, '_reduced_stock', true) > 0) {
            self::deletion_error();
        }
    }
    public static function protect_order($order_id, $order = null) {
        $order = $order ?: wc_get_order($order_id);
        if (!$order) { return; }
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_refunded_item_id', true)) { continue; }
            if ((int) $item->get_meta(self::ITEM_STOCK_ID, true) > 0 && (float) $item->get_meta('_reduced_stock', true) > 0) {
                self::deletion_error();
            }
        }
    }
    public static function protect_post($delete, $post, $force_delete) {
        if ($post && in_array($post->post_type, array('shop_order', 'shop_order_placehold'), true)) {
            self::protect_order($post->ID);
        }
        return $delete;
    }
}
