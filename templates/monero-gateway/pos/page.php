<?php defined('ABSPATH') || exit; ?>

<main class="xmr-pos-shell" id="xmr-pos-app">
    <header class="xmr-pos-header">
        <div>
            <p class="xmr-pos-eyebrow">Wards Ace Hardware</p>
            <h1>Monero In-Store Checkout</h1>
            <p class="xmr-pos-subtitle">Enter a subtotal or scan products. Add 6% sales tax and check out directly through the website.</p>
        </div>
        <div class="xmr-pos-live" id="xmr-pos-monitor" role="status"><span></span> Waiting for a payment request</div>
    </header>

    <div class="xmr-pos-alert" id="xmr-pos-alert" role="alert" hidden></div>

    <section class="xmr-pos-entry xmr-pos-access" id="xmr-pos-access"<?php echo $authorized ? ' hidden' : ''; ?>>
        <form id="xmr-pos-access-form" autocomplete="off">
            <div class="xmr-pos-lock-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            </div>
            <h2>Cashier access</h2>
            <p>Enter the shared register PIN to start an in-store Monero payment.</p>
            <label for="xmr-pos-pin">Cashier PIN</label>
            <input id="xmr-pos-pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="12" autocomplete="off" required>
            <?php if($turnstile_enabled): ?>
                <div class="xmr-pos-turnstile-wrap">
                    <?php cfturnstile_field_show('#xmr-pos-unlock', 'xmrPosTurnstileCallback', 'monero-pos', '-monero-pos', 'xmr-pos-turnstile'); ?>
                </div>
            <?php endif; ?>
            <button class="xmr-pos-primary" id="xmr-pos-unlock" type="submit">
                <span>Unlock cashier page</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
    </section>

    <section class="xmr-pos-terminal" id="xmr-pos-terminal" aria-labelledby="xmr-pos-terminal-heading" hidden>
        <div class="xmr-pos-terminal-heading">
            <div>
                <h2 id="xmr-pos-terminal-heading">Ingenico customer screen</h2>
                <p>The payment QR stays on this page whether or not the terminal is connected.</p>
            </div>
            <span class="xmr-pos-terminal-badge" id="xmr-pos-terminal-badge">Not connected</span>
        </div>
        <p id="xmr-pos-terminal-status" role="status" aria-live="polite">Close Eagle before connecting the Ingenico so its USB port is available. Create order &amp; request payment can display the payment QR automatically when JPG display is enabled. Select COM5 if the browser asks.</p>
        <div class="xmr-pos-terminal-actions">
            <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-connect">Connect Ingenico</button>
            <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-disconnect" disabled>Restore screen &amp; disconnect</button>
        </div>
        <details class="xmr-pos-terminal-setup" id="xmr-pos-terminal-setup">
            <summary>Terminal setup and QR test</summary>
            <p id="xmr-pos-terminal-recover-help">If Connect reports 11.01 (waiting for a card), close Eagle and make sure no card payment is in progress, then use this to close the leftover card screen and connect.</p>
            <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-recover" aria-describedby="xmr-pos-terminal-recover-help">Close leftover card screen &amp; connect</button>
            <p>Use Chrome or Edge on the register and select the Ingenico USB serial port (COM5). Eagle must stay closed while connected. Closing this tab automatically releases the port. Use <strong>Restore screen &amp; disconnect</strong> to let the page verify screen restoration and cleanup before reopening Eagle.</p>
            <p>First run the same hello-world display that worked in the register test. It does not require a payment request.</p>
            <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-text-test" disabled>Test hello world for 12 seconds</button>
            <p id="xmr-pos-terminal-test-help">Create a payment request, then test its QR code on the Ingenico for 12 seconds. Scan the terminal with a wallet and compare the address and exact XMR amount with this page, without sending payment. This test does not verify the terminal's visible amount labels.</p>
            <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-test" aria-describedby="xmr-pos-terminal-test-help" disabled>Test terminal QR for 12 seconds</button>
            <div class="xmr-pos-image-setup">
                <h3>Payment JPG screen</h3>
                <p>The JPG combines the payment QR, sale total in USD and exact remaining XMR. Its preview and download work without a terminal connection.</p>
                <p id="xmr-pos-payment-image-status" role="status" aria-live="polite">Create a payment request to preview its JPG.</p>
                <figure class="xmr-pos-image-preview" id="xmr-pos-payment-image-preview" hidden>
                    <img id="xmr-pos-payment-image" alt="Preview of the current payment JPG with its QR code, USD sale total and exact remaining XMR" width="800" height="480">
                    <figcaption>Browser preview · terminal display has not been verified</figcaption>
                </figure>
                <a class="xmr-pos-secondary" id="xmr-pos-payment-image-download" download="wards-ace-payment.jpg" hidden>Download current payment JPG</a>
                <p id="xmr-pos-terminal-image-help">With Eagle closed and the Ingenico connected, prepare the JPEG screen once, then create a payment request and test its JPG for 12 seconds. If preparation requires a restart, follow the result below. Scan the terminal QR with a wallet and compare both amounts without sending payment.</p>
                <div class="xmr-pos-terminal-actions">
                    <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-image-prepare" aria-describedby="xmr-pos-terminal-image-help" disabled>Prepare JPEG screen</button>
                    <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-image-test" aria-describedby="xmr-pos-terminal-image-help" disabled>Test payment JPG for 12 seconds</button>
                </div>
                <p class="xmr-pos-terminal-note" id="xmr-pos-terminal-image-note">The JPEG image form has not been verified on this register. Automatic JPG display is disabled until the physical test succeeds.</p>
            </div>
            <p class="xmr-pos-terminal-result" id="xmr-pos-terminal-test-result" role="status" aria-live="polite">No display test has run.</p>
            <p class="xmr-pos-terminal-note" id="xmr-pos-terminal-profile-note">Automatic display is awaiting verification of the terminal's QR form and amount labels.</p>
            <details class="xmr-pos-terminal-diagnostics">
                <summary>Terminal command log</summary>
                <p>The log distinguishes a missing QR form from a communication timeout. Copy it if the ACE logo stays on screen.</p>
                <div class="xmr-pos-terminal-actions">
                    <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-inspect" disabled>Check installed display forms</button>
                    <button type="button" class="xmr-pos-secondary" id="xmr-pos-terminal-copy-log" disabled>Copy terminal log</button>
                </div>
                <pre id="xmr-pos-terminal-log" tabindex="0">No terminal commands sent.</pre>
            </details>
        </details>
    </section>

    <section class="xmr-pos-entry xmr-pos-checkout" id="xmr-pos-entry"<?php echo $authorized ? '' : ' hidden'; ?>>
        <form id="xmr-pos-form" autocomplete="off">
            <div class="xmr-pos-modes" role="group" aria-label="Sale entry method">
                <button type="button" class="xmr-pos-secondary is-selected" id="xmr-pos-mode-manual" aria-pressed="true">Enter amount</button>
                <button type="button" class="xmr-pos-secondary" id="xmr-pos-mode-products" aria-pressed="false">Scan products</button>
            </div>
            <div id="xmr-pos-manual">
                <label for="xmr-pos-amount">Subtotal before tax</label>
                <div class="xmr-pos-money-input">
                    <span>$</span>
                    <input id="xmr-pos-amount" name="amount" type="text" inputmode="decimal" placeholder="0.00" maxlength="9" aria-describedby="xmr-pos-help" autofocus>
                    <span class="xmr-pos-currency">USD</span>
                </div>
                <p id="xmr-pos-help">Enter the pre-tax amount. A manual sale records one custom line on the website order; it does not update individual product stock.</p>
            </div>
            <div id="xmr-pos-products" hidden>
                <label for="xmr-pos-barcode">Product barcode or SKU</label>
                <div class="xmr-pos-scanner-input">
                    <input id="xmr-pos-barcode" type="text" inputmode="text" maxlength="128" autocomplete="off" spellcheck="false" aria-describedby="xmr-pos-scanner-help">
                    <button type="button" class="xmr-pos-secondary" id="xmr-pos-add-barcode">Add product</button>
                </div>
                <p id="xmr-pos-scanner-help">Plug in the USB keyboard scanner, focus this field, then scan. Each scan ending with Enter adds one item. You can also type a barcode or SKU.</p>
                <button type="button" class="xmr-pos-secondary" id="xmr-pos-scanner-focus">Use barcode scanner</button>
                <p id="xmr-pos-scanner-status" role="status" aria-live="polite">No scanner pairing is needed.</p>
                <div class="xmr-pos-cart" id="xmr-pos-cart" aria-label="Sale items"></div>
            </div>
            <div class="xmr-pos-totals" aria-label="Order totals" aria-live="polite">
                <div><span>Subtotal</span><strong id="xmr-pos-subtotal">—</strong></div>
                <div><span>Sales tax (6%)</span><strong id="xmr-pos-tax">—</strong></div>
                <div class="xmr-pos-total"><span>Total due</span><strong id="xmr-pos-total">—</strong></div>
            </div>
            <p id="xmr-pos-preview-status" role="status">Enter an amount or scan products to calculate the total.</p>
            <p class="xmr-pos-order-note">Creates a website order with 6% sales tax and no additional Monero discount. Payment is tracked here; you do not need to ring up the sale in Epicor.</p>
            <button type="button" class="xmr-pos-secondary" id="xmr-pos-revise" hidden>Review / edit this sale</button>
            <button class="xmr-pos-primary" id="xmr-pos-create" type="button" disabled>
                <span>Create order &amp; request payment</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
    </section>

    <section class="xmr-pos-payment" id="xmr-pos-payment" hidden>
        <div class="xmr-pos-toolbar">
            <button type="button" class="xmr-pos-secondary" id="xmr-pos-new-sale">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                New sale
            </button>
            <div class="xmr-pos-sale-total">Sale total <strong id="xmr-pos-fiat-total">$0.00</strong></div>
        </div>
        <p class="xmr-pos-order-identity" id="xmr-pos-order-identity" aria-live="polite"></p>
        <p class="xmr-pos-order-breakdown" id="xmr-pos-order-breakdown"></p>

        <div class="xmr-pos-status-tracker" id="xmr-pos-status-tracker" aria-label="Payment status">
            <div class="xmr-pos-step" data-step="waiting">
                <div class="xmr-pos-step-icon">
                    <svg class="spinner" viewBox="0 0 50 50" aria-hidden="true"><circle cx="25" cy="25" r="20"/></svg>
                    <svg class="check" viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                    <svg class="cross" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                </div>
                <span>Waiting</span>
            </div>
            <div class="xmr-pos-step-line"></div>
            <div class="xmr-pos-step" data-step="received">
                <div class="xmr-pos-step-icon">
                    <svg class="spinner" viewBox="0 0 50 50" aria-hidden="true"><circle cx="25" cy="25" r="20"/></svg>
                    <svg class="check" viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                </div>
                <span>In mempool</span>
            </div>
            <div class="xmr-pos-step-line"></div>
            <div class="xmr-pos-step" data-step="confirmed">
                <div class="xmr-pos-step-icon">
                    <svg class="spinner" viewBox="0 0 50 50" aria-hidden="true"><circle cx="25" cy="25" r="20"/></svg>
                    <svg class="check" viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                </div>
                <span>Confirmed</span>
            </div>
        </div>

        <div class="xmr-pos-status-card" id="xmr-pos-status-card" aria-live="polite">
            <h2 id="xmr-pos-status-title">Waiting for payment</h2>
            <p id="xmr-pos-status-copy">Ask the customer to scan the QR code and send the exact amount.</p>
        </div>

        <div class="xmr-pos-grid">
            <div class="xmr-pos-qr-panel">
                <div id="xmr-pos-qr" class="xmr-pos-qr" aria-label="Monero payment QR code"></div>
                <p id="xmr-pos-qr-paused" class="xmr-pos-qr-paused" hidden>Payment code paused while the website order is reviewed.</p>
                <a id="xmr-pos-wallet-link" class="xmr-pos-wallet-link" href="#">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 1 0 0 4h4v-4Z"/></svg>
                    Open in wallet
                </a>
            </div>

            <div class="xmr-pos-details">
                <div class="xmr-pos-detail xmr-pos-amount-detail">
                    <span>Monero due</span>
                    <div><strong id="xmr-pos-due">0.000000000000</strong> <b>XMR</b></div>
                    <button type="button" class="xmr-pos-copy" data-copy-target="xmr-pos-due">Copy amount</button>
                </div>
                <div class="xmr-pos-detail">
                    <span>Received</span>
                    <div><strong id="xmr-pos-paid">0.000000000000</strong> XMR</div>
                </div>
                <div class="xmr-pos-detail">
                    <span>Locked exchange rate</span>
                    <div id="xmr-pos-rate">1 XMR = — USD</div>
                </div>
                <div class="xmr-pos-detail">
                    <span>Payment address</span>
                    <code id="xmr-pos-address"></code>
                    <button type="button" class="xmr-pos-copy" data-copy-target="xmr-pos-address">Copy address</button>
                </div>
            </div>
        </div>

        <div class="xmr-pos-transactions" id="xmr-pos-transactions" hidden>
            <h3>Transactions</h3>
            <div id="xmr-pos-transaction-list"></div>
        </div>
    </section>

    <footer class="xmr-pos-footer">
        Staff checkout · website orders · 6% sales tax · payment requests expire automatically
    </footer>
</main>
