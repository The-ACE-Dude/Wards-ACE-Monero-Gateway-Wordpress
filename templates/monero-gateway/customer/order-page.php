<section class="xmr-payment-section">
    <noscript><p style="color:red;font-weight:bold;">You must enable javascript to complete your Monero payment.</p></noscript>

    <!-- Status Tracker -->
    <div class="xmr-status-tracker">
        <div class="xmr-step" data-step="waiting">
            <div class="xmr-step-icon">
                <svg class="xmr-spinner" width="22" height="22" viewBox="0 0 50 50"><circle cx="25" cy="25" r="20" fill="none" stroke-width="4"></circle></svg>
                <svg class="xmr-check" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <svg class="xmr-x" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </div>
            <span class="xmr-step-label">Waiting for Payment</span>
        </div>
        <div class="xmr-step-line"></div>
        <div class="xmr-step" data-step="received">
            <div class="xmr-step-icon">
                <svg class="xmr-spinner" width="22" height="22" viewBox="0 0 50 50"><circle cx="25" cy="25" r="20" fill="none" stroke-width="4"></circle></svg>
                <svg class="xmr-check" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <span class="xmr-step-label">Payment Received</span>
        </div>
        <div class="xmr-step-line"></div>
        <div class="xmr-step" data-step="confirmed">
            <div class="xmr-step-icon">
                <svg class="xmr-spinner" width="22" height="22" viewBox="0 0 50 50"><circle cx="25" cy="25" r="20" fill="none" stroke-width="4"></circle></svg>
                <svg class="xmr-check" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <span class="xmr-step-label">Confirmed</span>
        </div>
    </div>

    <!-- Status Message -->
    <div id="monero_payment_messages" class="xmr-status-message">
        <span class="monero_payment_review" role="alert"><?php echo !empty($details['hold']) ? esc_html($details['warning']) : ''; ?></span>
        <span class="monero_payment_unpaid">Send the exact amount below to complete your order. Expires in <strong class="monero_payment_expire_time"></strong>.</span>
        <span class="monero_payment_partial">Partial payment received. Please send the remaining amount. Expires in <strong class="monero_payment_expire_time"></strong>.</span>
        <span class="monero_payment_paid">Payment received! Waiting for network confirmation (<span class="monero_confirm_time"></span>).<?php if(is_wc_endpoint_url('order-received')): ?> <a href="<?php echo esc_url($details['my_order_url']); ?>">Track in your account</a>.<?php endif; ?></span>
        <span class="monero_payment_confirmed">Payment confirmed. Thank you for paying with Monero!</span>
        <span class="monero_payment_expired">This order has expired. Please place a new order.</span>
        <span class="monero_payment_expired_partial">This order has expired with a partial payment. Please contact us for a refund.</span>
    </div>

    <!-- Discount Banner (shown dynamically via JS if discount > 0) -->
    <div id="xmr_discount_banner" class="xmr-discount-banner" style="display:none;">
        <div class="xmr-savings-row">
            <span class="xmr-original-price" id="xmr_original_price"></span>
            <span class="xmr-arrow-icon">&rarr;</span>
            <span class="xmr-discounted-price" id="xmr_discounted_price"></span>
        </div>
        <div class="xmr-savings-text">You save <strong><span id="xmr_savings_amount"></span></strong> (<span id="xmr_discount_pct"></span>% off) by paying with Monero!</div>
    </div>

    <!-- Payment Card -->
    <div class="xmr-payment-card"<?php if(!empty($details['hold'])) echo ' style="display:none;"'; ?>>
        <!-- Pay To -->
        <div class="xmr-field">
            <label>PAY TO:</label>
            <div class="xmr-field-value">
                <span id="monero_integrated_address" class="xmr-address"></span>
                <span class="xmr-actions">
                    <?php if($show_qr): ?>
                    <button type="button" class="xmr-btn xmr-btn-qr" title="Show QR Code" onclick="monero_showQR()">
                        <svg width="18" height="18" viewBox="0 0 512 512"><path d="M0 512h233V279H0zm47-186h139v139H47zM93 372h47v47H93zm279 93h47v47h-47zm93 0h47v47h-47zM465 326h-46v-47H279v233h47V372h46v47h140V279h-47zM0 233h233V0H0zM47 47h139v139H47zM93 93h47v47H93zM279 0v233h233V0zm186 186H326V47h139zM372 93h47v47h-47z"/></svg>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="xmr-btn clipboard" title="Copy Address" data-clipboard-target="#monero_integrated_address">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    </button>
                </span>
            </div>
        </div>

        <!-- Open in Wallet -->
        <div class="xmr-wallet-action">
            <a id="monero_open_wallet" class="xmr-wallet-btn" href="<?php echo esc_attr(json_decode($details_json)->qrcode_uri ?? ''); ?>" title="Open in Monero wallet app">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 010-4h14v4"/><path d="M3 5v14a2 2 0 002 2h16v-5"/><path d="M18 12a2 2 0 100 4h4v-4h-4z"/></svg>
                <span>Open in Wallet</span>
            </a>
        </div>

        <!-- Amount Row -->
        <div class="xmr-amounts">
            <div class="xmr-field xmr-field-amount">
                <label>TOTAL DUE:</label>
                <div class="xmr-field-value xmr-amount-value">
                    <span id="monero_total_due"></span> <span class="xmr-currency">XMR</span>
                    <button type="button" class="xmr-btn clipboard" title="Copy Amount" data-clipboard-target="#monero_total_due">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    </button>
                </div>
            </div>
            <div class="xmr-field xmr-field-paid">
                <label>TOTAL PAID:</label>
                <div class="xmr-field-value xmr-amount-value">
                    <span id="monero_total_paid"></span> <span class="xmr-currency">XMR</span>
                </div>
            </div>
        </div>

        <!-- Exchange Rate -->
        <div class="xmr-field xmr-rate-field">
            <label>EXCHANGE RATE:</label>
            <div class="xmr-field-value" id="monero_exchange_rate"></div>
        </div>

        <!-- Hidden total amount for reference -->
        <span id="monero_total_amount" style="display:none;"></span>
    </div>

    <!-- Transactions -->
    <div id="monero_tx_section">
        <table id="monero_tx_table" style="display:none;">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Height</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <div id="monero_tx_none" style="display:none;"></div>
    </div>

    <!-- QR Overlay -->
    <div id="monero_qr_code_container" style="display:none;" onclick="monero_showQR(false)">
        <div id="monero_qr_code"></div>
    </div>
</section>

<div id="monero_toast"></div>

<script type="text/javascript">
    var monero_show_qr = <?php echo $show_qr ? 'true' : 'false'; ?>;
    var monero_ajax_url = '<?php echo esc_js($ajax_url); ?>';
    var monero_order_key = '<?php echo esc_js($order_key); ?>';
    var monero_explorer_url = '<?php echo esc_js(MONERO_GATEWAY_EXPLORER_URL); ?>';
    var monero_details = <?php echo $details_json; ?>;
</script>
