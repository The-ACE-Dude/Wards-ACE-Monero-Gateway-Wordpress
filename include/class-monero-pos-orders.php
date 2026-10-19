<?php

defined('ABSPATH') || exit;

/** Website order accounting for the staff Monero register. Never contacts a wallet. */
class Monero_POS_Orders
{
    const TAX_RATE = 6;
    const MAX_CENTS = 99999999;

    private static function error($code, $message, $data = null)
    {
        return new WP_Error('monero_pos_' . $code, __($message, 'monero_gateway'), $data);
    }

    private static function money($cents)
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function cents($value)
    {
        if(!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if(!preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/D', $value)) {
            return null;
        }
        $parts = explode('.', $value, 2);
        return intval($parts[0]) * 100 + intval(str_pad($parts[1] ?? '', 2, '0'));
    }

    private static function tax_cents($cents)
    {
        return intdiv($cents * self::TAX_RATE + 50, 100);
    }

    private static function product($id)
    {
        $product = wc_get_product($id);
        if(!$product || !$product->is_type(array('simple', 'variation')) || !$product->is_purchasable()) {
            return self::error('product_unavailable', 'This item is unavailable for checkout. Select a sellable product or use manual amount entry.');
        }
        return $product;
    }

    private static function product_price($product)
    {
        $price = $product->get_price();
        if($price === '' || !is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) {
            return self::error('product_price', 'This product does not have a valid selling price. Use manual amount entry after checking its price.');
        }
        // The register always displays a pre-tax subtotal and applies the explicit
        // in-store 6% rate, including when catalog prices were stored tax-inclusive.
        if(wc_prices_include_tax()) {
            $price = (float) $price / 1.06;
        }
        $cents = self::cents(number_format((float) $price, 2, '.', ''));
        if($cents === null || $cents < 1 || $cents > self::MAX_CENTS) {
            return self::error('product_price', 'This product price is outside the supported checkout range.');
        }
        return $cents;
    }

    private static function product_matches($codes, $keys)
    {
        global $wpdb;
        $key_placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $code_placeholders = implode(',', array_fill(0, count($codes), '%s'));
        $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
            WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish'
            AND m.meta_key IN ($key_placeholders) AND m.meta_value IN ($code_placeholders)
            ORDER BY p.ID LIMIT 3";
        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, array_merge($keys, $codes))));
    }

    public static function lookup_product($barcode)
    {
        if(!is_string($barcode)) {
            return self::error('barcode_invalid', 'Scan or enter a barcode or SKU.');
        }
        $barcode = trim($barcode);
        if($barcode === '' || strlen($barcode) > 128 || preg_match('/[\x00-\x1f\x7f]/', $barcode)) {
            return self::error('barcode_invalid', 'Scan or enter a valid barcode or SKU.');
        }
        // Exact SKUs take priority; padding is only a fallback for numeric UPCs.
        $ids = self::product_matches(array($barcode), array('_sku'));
        $barcode_keys = array('upc_code', 'upc_2', 'upc_3', 'upc_4', 'upc_5', '_global_unique_id');
        if(!$ids) {
            $ids = self::product_matches(array($barcode), $barcode_keys);
        }
        if(!$ids && ctype_digit($barcode) && strlen($barcode) <= 14) {
            $unpadded = ltrim($barcode, '0');
            $variants = array($unpadded, '0' . $barcode);
            foreach(array(12, 13, 14) as $length) {
                if(strlen($unpadded) <= $length) {
                    $variants[] = str_pad($unpadded, $length, '0', STR_PAD_LEFT);
                }
            }
            $variants = array_values(array_unique(array_filter($variants, function($value) use($barcode) {
                return $value !== '' && $value !== $barcode && strlen($value) <= 14;
            })));
            if($variants) {
                $ids = self::product_matches($variants, $barcode_keys);
            }
        }
        if(count($ids) > 1) {
            return self::error('barcode_ambiguous', 'This barcode matches more than one product. Enter its exact SKU instead.');
        }
        if(!$ids) {
            return self::error('barcode_missing', 'No website product matches this barcode. Try its SKU or use manual amount entry.');
        }
        $product = self::product($ids[0]);
        if(is_wp_error($product)) {
            return $product;
        }
        $price = self::product_price($product);
        if(is_wp_error($price)) {
            return $price;
        }
        return array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => self::money($price),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->managing_stock(),
        );
    }

    public static function build_sale($input)
    {
        if(get_woocommerce_currency() !== 'USD' || wc_get_price_decimals() !== 2) {
            return self::error('currency', 'The in-store register requires USD prices with two decimal places.');
        }
        if(!is_array($input)) {
            return self::error('sale_invalid', 'The sale details are invalid.');
        }
        $mode = $input['mode'] ?? 'manual';
        if(in_array($mode, array('items', 'scan', 'cart'), true)) {
            $mode = 'products';
        }
        if(!in_array($mode, array('manual', 'products'), true)) {
            return self::error('mode_invalid', 'Select manual amount or scanned products.');
        }
        $items = array();
        $subtotal = 0;
        if($mode === 'manual') {
            $subtotal = self::cents($input['amount'] ?? '');
            if($subtotal === null || $subtotal < 1) {
                return self::error('amount_invalid', 'Enter a positive pre-tax USD amount with no more than two decimal places.');
            }
            $items[] = array('product_id' => 0, 'name' => __('In-store purchase (manual amount)', 'monero_gateway'), 'sku' => '', 'quantity' => 1, 'unit_price' => self::money($subtotal), 'line_total' => self::money($subtotal));
        } else {
            $raw_items = $input['items'] ?? array();
            if(is_string($raw_items)) {
                if(strlen($raw_items) > 32000) {
                    return self::error('items_invalid', 'This cart is too large. Split it into separate sales.');
                }
                $raw_items = json_decode($raw_items, true);
            }
            if(!is_array($raw_items) || !$raw_items || count($raw_items) > 100) {
                return self::error('items_invalid', 'Scan at least one product. A sale can contain up to 100 different products.');
            }
            $quantities = array();
            foreach($raw_items as $row) {
                if(!is_array($row)) {
                    return self::error('items_invalid', 'The scanned product list is invalid.');
                }
                $id = $row['product_id'] ?? $row['id'] ?? null;
                $quantity = $row['quantity'] ?? null;
                if((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,10}$/D', (string) $id)
                    || (!is_int($quantity) && !is_string($quantity)) || !preg_match('/^[1-9][0-9]{0,3}$/D', (string) $quantity)) {
                    return self::error('quantity_invalid', 'Each scanned product needs a valid product ID and a whole-number quantity from 1 to 9999.');
                }
                $id = intval($id);
                $quantities[$id] = ($quantities[$id] ?? 0) + intval($quantity);
                if($quantities[$id] > 9999) {
                    return self::error('quantity_invalid', 'A product quantity cannot exceed 9999.');
                }
            }
            ksort($quantities, SORT_NUMERIC);
            $stock_demands = array();
            foreach($quantities as $id => $quantity) {
                $product = self::product($id);
                if(is_wp_error($product)) {
                    return $product;
                }
                if($product->is_sold_individually() && $quantity > 1) {
                    return self::error('sold_individually', 'This product is limited to one per sale.');
                }
                if(!$product->is_in_stock()) {
                    return self::error('stock', 'A scanned product is out of stock. Remove it before checkout.');
                }
                if($product->managing_stock() && !$product->backorders_allowed()) {
                    $stock_id = $product->get_stock_managed_by_id();
                    $stock_demands[$stock_id] = ($stock_demands[$stock_id] ?? 0) + $quantity;
                    $held = function_exists('wc_get_held_stock_quantity') ? wc_get_held_stock_quantity($product) : 0;
                    if($stock_demands[$stock_id] > (float) $product->get_stock_quantity() - (float) $held) {
                        return self::error('stock', 'There is not enough available stock for this quantity. Reduce the quantity before checkout.');
                    }
                }
                $price = self::product_price($product);
                if(is_wp_error($price)) {
                    return $price;
                }
                $line_total = $price * $quantity;
                $subtotal += $line_total;
                if($subtotal > self::MAX_CENTS) {
                    return self::error('amount_maximum', 'The total including sales tax cannot exceed $999,999.99.');
                }
                $items[] = array('product_id' => $id, 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'quantity' => $quantity, 'unit_price' => self::money($price), 'line_total' => self::money($line_total));
            }
        }
        $tax = self::tax_cents($subtotal);
        $total = $subtotal + $tax;
        if($total > self::MAX_CENTS) {
            return self::error('amount_maximum', 'The total including sales tax cannot exceed $999,999.99.');
        }
        // Allocate by cumulative rounding so line taxes always sum to exactly
        // round(subtotal * 6%), including penny-priced items and repeat scans.
        $running_subtotal = 0;
        $running_tax = 0;
        foreach($items as &$item) {
            $running_subtotal += self::cents($item['line_total']);
            $next_tax = self::tax_cents($running_subtotal);
            $item['tax'] = self::money($next_tax - $running_tax);
            $running_tax = $next_tax;
        }
        unset($item);
        return array('mode' => $mode, 'subtotal' => self::money($subtotal), 'tax' => self::money($tax), 'total' => self::money($total), 'tax_rate' => self::TAX_RATE, 'items' => $items);
    }

    public static function create_order($sale, $token, $request_id)
    {
        $order = null;
        try {
            $order = new WC_Order();
            $order->set_status('pending');
            $order->set_currency('USD');
            $order->set_prices_include_tax(false);
            $order->set_created_via('monero-pos');
            $order->set_customer_id(0);
            $order->set_payment_method('monero_gateway');
            $order->set_payment_method_title(__('Monero (in-store)', 'monero_gateway'));
            $order->update_meta_data('_monero_pos', 'yes');
            $order->update_meta_data('_monero_pos_token', $token);
            $order->update_meta_data('_monero_pos_request_id', $request_id);
            $order->update_meta_data('_monero_pos_sale', $sale);
            foreach($sale['items'] as $line) {
                $item = new WC_Order_Item_Product();
                if($line['product_id']) {
                    $product = self::product($line['product_id']);
                    if(is_wp_error($product)) {
                        throw new RuntimeException($product->get_error_message());
                    }
                    $item->set_product($product);
                    $item->add_meta_data('_wardsace_pos_stock_product_id', $product->get_stock_managed_by_id(), true);
                }
                $item->set_name($line['name']);
                $item->set_quantity($line['quantity']);
                $item->set_subtotal($line['line_total']);
                $item->set_total($line['line_total']);
                $item->set_taxes(array('subtotal' => array(0 => $line['tax']), 'total' => array(0 => $line['tax'])));
                $order->add_item($item);
            }
            // A historical order tax line is sufficient: no global tax settings
            // or online-checkout rates are changed by the in-store register.
            $tax = new WC_Order_Item_Tax();
            $tax->set_rate_id(0);
            $tax->set_rate_code('MONERO-POS-SALES-TAX-6');
            $tax->set_label(__('In-store sales tax (6%)', 'monero_gateway'));
            $tax->set_rate_percent(self::TAX_RATE);
            $tax->set_compound(false);
            $tax->set_tax_total($sale['tax']);
            $tax->set_shipping_tax_total('0.00');
            $order->add_item($tax);
            $order->set_cart_tax($sale['tax']);
            $order->set_shipping_tax('0.00');
            $order->calculate_totals(false);
            if(!$order->get_id() || self::cents(number_format((float) $order->get_total(), 2, '.', '')) !== self::cents($sale['total'])) {
                throw new RuntimeException('The website order total could not be verified.');
            }
            // Woo's atomic reservation also coordinates with ordinary online
            // buyers, which do not take the register's inventory-import lock.
            if(function_exists('wc_reserve_stock_for_order')) {
                wc_reserve_stock_for_order($order);
            }
            $order->update_status('on-hold', __('In-store Monero sale: awaiting payment. Prices and 6% sales tax are fixed for this request.', 'monero_gateway'));
            if($order->get_status() !== 'on-hold') {
                throw new RuntimeException('The website order could not be placed on hold.');
            }
            if(function_exists('wc_release_stock_for_order')) {
                wc_release_stock_for_order($order);
            }
            // Stock hooks save through their own order object. Reload before
            // verifying their per-item ledger; Woo can record a stock error
            // without throwing or changing the on-hold status.
            $reloaded_order = wc_get_order($order->get_id());
            if(!$reloaded_order) {
                throw new RuntimeException('The saved website order could not be reloaded.');
            }
            $order = $reloaded_order;
            foreach($order->get_items() as $item) {
                $stock_id = $item->get_meta('_wardsace_pos_stock_product_id', true);
                if(!$stock_id) { continue; }
                $product = $item->get_product();
                if(!$product || ($product->managing_stock() && (float) $item->get_meta('_reduced_stock', true) !== (float) $item->get_quantity())) {
                    throw new RuntimeException('A product stock reduction could not be verified.');
                }
            }
            $order->update_meta_data('_monero_pos_ready', 'yes');
            $order->save();
            return $order;
        } catch(Throwable $exception) {
            if($order && $order->get_id() && function_exists('wc_release_stock_for_order')) {
                try { wc_release_stock_for_order($order); } catch(Throwable $release_error) { /* Preserve the recovery order ID. */ }
            }
            return self::error('order_failed', 'The website order could not be completed. Keep this sale open and retry; do not create a second payment request.', array('order_id' => $order ? $order->get_id() : 0));
        }
    }

    private static function manual_review($order, $reason)
    {
        $old_reason = $order->get_meta('_monero_pos_manual_review', true);
        if($old_reason !== $reason) {
            $order->update_meta_data('_monero_pos_manual_review', $reason);
            $order->save();
            $order->add_order_note($reason);
        }
    }

    public static function sync_order($quote, $snapshot, $expired)
    {
        if(empty($quote->order_id) || ($snapshot['status_fresh'] ?? false) !== true) {
            return;
        }
        $order = wc_get_order(intval($quote->order_id));
        if(!$order || $order->get_meta('_monero_pos', true) !== 'yes') {
            throw new RuntimeException('The linked in-store website order is unavailable.');
        }
        $has_funds = !empty($snapshot['transactions']);
        $confirmed = intval($quote->confirmed) === 1;
        if($order->get_meta('_monero_pos_manual_review', true)) {
            return;
        }
        if(in_array($order->get_status(), array('cancelled', 'failed', 'refunded'), true)) {
            if($has_funds) {
                self::manual_review($order, __('Monero payment detected after this order was cancelled, failed, or refunded. Review the payment and inventory before fulfilling or refunding; this sale was not automatically completed.', 'monero_gateway'));
                // A cancelled order released its stock. Keep its status intact so
                // detecting a late transfer cannot silently reserve stock again.
            }
            return;
        }
        if($order->is_paid()) {
            if(!$confirmed) {
                self::manual_review($order, __('The latest Monero wallet snapshot no longer confirms this paid sale. Review the payment; the completed order and its inventory have been retained.', 'monero_gateway'));
            }
            return;
        }
        if(!empty($quote->first_payment_late)) {
            self::manual_review($order, __('Payment was first detected after this Monero request expired. Review the payment and inventory before fulfilling or refunding; this sale was not automatically completed.', 'monero_gateway'));
            return;
        }
        if($confirmed) {
            $txids = array();
            foreach($snapshot['transactions'] as $transaction) {
                $txids[] = is_object($transaction) ? $transaction->txid : $transaction['txid'];
            }
            $order->update_meta_data('_monero_pos_txids', array_values(array_unique($txids)));
            $order->save();
            $order->payment_complete($txids[0] ?? '');
            if(!$order->is_paid()) {
                throw new RuntimeException('The received Monero payment could not be recorded on the website order.');
            }
            $order->update_status('completed', __('Monero payment confirmed for this in-store purchase. No shipping is required.', 'monero_gateway'));
            return;
        }
        if($expired) {
            if($has_funds) {
                self::manual_review($order, __('This Monero request expired with a partial payment. The order remains on hold with its inventory reserved; review the received funds before cancelling or refunding.', 'monero_gateway'));
            } else {
                $order->update_meta_data('_monero_pos_expired', 'yes');
                $order->save();
                $order->update_status('cancelled', __('The in-store Monero request expired without payment. Reserved inventory was returned.', 'monero_gateway'));
            }
        }
    }

    public static function order_details($quote)
    {
        if(empty($quote->order_id)) {
            return array('order_id' => 0);
        }
        $order = wc_get_order(intval($quote->order_id));
        if(!$order) {
            return array('order_id' => intval($quote->order_id), 'order_warning' => __('The linked website order could not be loaded. Ask a manager to review this sale.', 'monero_gateway'));
        }
        $details = array('order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'order_status' => $order->get_status(), 'sale' => $order->get_meta('_monero_pos_sale', true));
        $warning = $order->get_meta('_monero_pos_manual_review', true);
        if($warning) {
            $details['order_warning'] = $warning;
        }
        return $details;
    }
}
