(function () {
    'use strict';

    if (typeof moneroPosConfig === 'undefined') return;

    var config = moneroPosConfig;
    var pollTimer = null;
    var countdownTimer = null;
    var currentToken = '';
    var currentStatus = '';
    var expiresAt = 0;
    var currentDetails = null;
    var generation = 0;
    var pollInFlight = null;
    var creating = false;
    var paymentRequests = new Set();
    var terminal = null;
    var terminalState = {connected: false, busy: false, ready: false};
    var terminalOperation = false;
    var pageLeaving = false;
    var pageExitPromise = null;
    var imageGeneration = 0;
    var imageUrl = '';
    var paymentImage = null;
    var imagePending = false;
    var imagePageActive = true;
    var websiteCheckout = !!config.previewAction;
    var saleMode = 'manual';
    var cart = [];
    var preview = null;
    var draftRevision = 0;
    var previewTimer = null;
    var previewPending = false;
    var scanQueue = [];
    var scanGeneration = 0;
    var scanFlight = null;
    var pendingCheckout = null;
    var storageKey = 'wards-monero-checkout:' + config.pageUrl;

    window.xmrPosTurnstileCallback = function () {
        showAlert('');
    };

    function el(id) { return document.getElementById(id); }

    function showAlert(message) {
        var alert = el('xmr-pos-alert');
        alert.textContent = message;
        alert.hidden = !message;
    }

    function setBusy(busy) {
        var button = el('xmr-pos-create');
        button.disabled = busy || pageLeaving || !!pageExitPromise || (websiteCheckout && (!!currentToken ||
            (pendingCheckout ? pendingCheckout.state === 'rejected' : (!preview || previewPending || !!scanFlight || scanQueue.length > 0))));
        button.classList.toggle('is-busy', busy);
        button.querySelector('span').textContent = busy ? 'Creating request…' :
            (websiteCheckout ? (pendingCheckout && pendingCheckout.state !== 'rejected' ? 'Retry / recover this checkout' : 'Create order & request payment') : 'Create payment request');
        if (websiteCheckout) renderEditorLock();
    }

    function dollars(value) { return '$' + Number(value).toFixed(2); }

    function draftPayload() {
        return saleMode === 'manual' ? {mode: 'manual', amount: el('xmr-pos-amount').value.trim()} :
            {mode: 'products', items: JSON.stringify(cart.map(function (item) { return {product_id: item.id, quantity: item.quantity}; }))};
    }

    function renderEditorLock() {
        var locked = creating || !!pendingCheckout || !!currentToken || pageLeaving || !!pageExitPromise;
        ['xmr-pos-mode-manual', 'xmr-pos-mode-products', 'xmr-pos-amount', 'xmr-pos-barcode',
            'xmr-pos-add-barcode', 'xmr-pos-scanner-focus'].forEach(function (id) {
            if (el(id)) el(id).disabled = locked;
        });
        el('xmr-pos-cart').querySelectorAll('input, button').forEach(function (control) { control.disabled = locked; });
        el('xmr-pos-revise').hidden = !pendingCheckout || pendingCheckout.state !== 'rejected';
        el('xmr-pos-revise').disabled = creating || pageLeaving || !!pageExitPromise;
    }

    function drawTotals() {
        ['subtotal', 'tax', 'total'].forEach(function (key) {
            el('xmr-pos-' + key).textContent = preview ? dollars(preview[key]) : '—';
        });
        setBusy(creating);
    }

    function renderCart() {
        var target = el('xmr-pos-cart');
        target.replaceChildren();
        if (!cart.length) {
            var empty = document.createElement('p');
            empty.className = 'xmr-pos-cart-empty';
            empty.textContent = 'No products yet. Scan the first item to start.';
            target.appendChild(empty);
        }
        cart.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'xmr-pos-cart-row';
            var description = document.createElement('div');
            var name = document.createElement('strong');
            name.textContent = item.name;
            var meta = document.createElement('small');
            meta.textContent = (item.sku ? 'SKU ' + item.sku + ' · ' : '') + dollars(item.price) + ' each';
            description.appendChild(name);
            description.appendChild(meta);
            var quantity = document.createElement('input');
            quantity.type = 'number';
            quantity.min = '1';
            quantity.max = '9999';
            quantity.step = '1';
            quantity.value = item.quantity;
            quantity.setAttribute('aria-label', 'Quantity for ' + item.name);
            quantity.addEventListener('change', function () {
                if (creating || pendingCheckout) return;
                var value = Number(quantity.value);
                if (!Number.isInteger(value) || value < 1 || value > 9999) {
                    quantity.value = item.quantity;
                    showAlert('Use a whole-number quantity from 1 to 9999.');
                    return;
                }
                item.quantity = value;
                refreshPreview();
            });
            var total = document.createElement('strong');
            var authoritative = preview && (preview.items || []).find(function (line) { return Number(line.product_id) === Number(item.id); });
            total.textContent = authoritative ? dollars(authoritative.line_total) : '—';
            total.className = 'xmr-pos-line-total';
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'xmr-pos-remove';
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label', 'Remove ' + item.name);
            remove.addEventListener('click', function () {
                if (creating || pendingCheckout) return;
                cart = cart.filter(function (line) { return line !== item; });
                refreshPreview();
            });
            row.appendChild(description);
            row.appendChild(quantity);
            row.appendChild(total);
            row.appendChild(remove);
            target.appendChild(row);
        });
        renderEditorLock();
    }

    function refreshPreview(delay) {
        if (!websiteCheckout || pendingCheckout) return;
        var revision = ++draftRevision;
        preview = null;
        previewPending = false;
        window.clearTimeout(previewTimer);
        var payload = draftPayload();
        var valid = saleMode === 'manual' ? /^\d+(?:\.\d{1,2})?$/.test(payload.amount) && Number(payload.amount) > 0 : cart.length > 0;
        el('xmr-pos-preview-status').textContent = valid ? 'Calculating website totals…' :
            (saleMode === 'manual' ? 'Enter a pre-tax USD amount, with up to two decimal places.' : 'Scan products to calculate the total.');
        renderCart();
        drawTotals();
        if (!valid) return;
        previewPending = true;
        setBusy(creating);
        function fetchPreview() {
            request(config.previewAction, payload).then(function (sale) {
                if (revision !== draftRevision || pendingCheckout || pageLeaving) return;
                preview = sale;
                (sale.items || []).forEach(function (line) {
                    var item = cart.find(function (candidate) { return Number(candidate.id) === Number(line.product_id); });
                    if (item) { item.price = line.unit_price; item.name = line.name; item.sku = line.sku; }
                });
                el('xmr-pos-preview-status').textContent = 'Website total ready. Review the sale, then request payment.';
                renderCart();
            }).catch(function (error) {
                if (revision !== draftRevision || pendingCheckout || pageLeaving) return;
                el('xmr-pos-preview-status').textContent = error.name === 'AbortError' ? 'Totals timed out. Edit the sale to recalculate.' : error.message;
            }).finally(function () {
                if (revision !== draftRevision) return;
                previewPending = false;
                drawTotals();
            });
        }
        if (delay) previewTimer = window.setTimeout(fetchPreview, delay);
        else fetchPreview();
    }

    function focusScanner() {
        if (saleMode !== 'products' || creating || pendingCheckout) return;
        el('xmr-pos-barcode').focus();
        el('xmr-pos-scanner-status').textContent = 'Barcode field focused. Scan an item; Enter adds it to the sale.';
    }

    function processScanQueue() {
        if (scanFlight || !scanQueue.length || saleMode !== 'products' || pendingCheckout || pageLeaving) return;
        var flight = {barcode: scanQueue.shift(), generation: scanGeneration};
        scanFlight = flight;
        el('xmr-pos-scanner-status').textContent = 'Looking up ' + flight.barcode + '…';
        setBusy(creating);
        request(config.lookupAction, {barcode: flight.barcode}).then(function (result) {
            if (flight.generation !== scanGeneration || pendingCheckout || saleMode !== 'products' || pageLeaving) return;
            var product = result.product || result;
            var existing = cart.find(function (item) { return Number(item.id) === Number(product.id); });
            if (existing) existing.quantity++;
            else cart.push({id: product.id, name: product.name, sku: product.sku, price: product.price, quantity: 1});
            el('xmr-pos-scanner-status').textContent = 'Added ' + product.name + (existing ? ' · quantity ' + existing.quantity : '');
            refreshPreview();
        }).catch(function (error) {
            if (flight.generation !== scanGeneration || pageLeaving) return;
            var message = 'Barcode ' + flight.barcode + ': ' + (error.name === 'AbortError' ? 'lookup timed out.' : error.message) +
                ' Try its SKU, or use Enter amount to enter the whole sale subtotal manually.';
            showAlert(message);
            el('xmr-pos-scanner-status').textContent = message;
            // A failed lookup must not strand an otherwise valid cart.
            refreshPreview();
        }).finally(function () {
            if (scanFlight !== flight) return;
            scanFlight = null;
            processScanQueue();
            setBusy(creating);
        });
    }

    function addBarcode() {
        if (saleMode !== 'products' || creating || pendingCheckout || pageLeaving) return;
        var input = el('xmr-pos-barcode');
        var barcode = input.value.trim();
        input.value = '';
        if (!barcode) { focusScanner(); return; }
        showAlert('');
        scanQueue.push(barcode);
        // Disable checkout immediately, including while an earlier scan resolves.
        ++draftRevision;
        preview = null;
        previewPending = false;
        window.clearTimeout(previewTimer);
        drawTotals();
        processScanQueue();
        input.focus();
    }

    function setSaleMode(mode, skipPreview) {
        if (creating || pendingCheckout || pageLeaving) return;
        saleMode = mode;
        ++scanGeneration;
        scanQueue = [];
        scanFlight = null;
        el('xmr-pos-manual').hidden = mode !== 'manual';
        el('xmr-pos-products').hidden = mode !== 'products';
        ['manual', 'products'].forEach(function (value) {
            var button = el('xmr-pos-mode-' + value);
            button.classList.toggle('is-selected', value === mode);
            button.setAttribute('aria-pressed', String(value === mode));
        });
        showAlert('');
        if (!skipPreview) refreshPreview();
        if (mode === 'products') focusScanner();
        else el('xmr-pos-amount').focus();
    }

    function saveCheckout(value) {
        if (value) window.sessionStorage.setItem(storageKey, JSON.stringify(value));
        else window.sessionStorage.removeItem(storageKey);
    }

    function clearCheckout() {
        pendingCheckout = null;
        try { saveCheckout(null); } catch (error) { /* Already linked to the order in the page URL. */ }
    }

    function prepareCheckout() {
        if (pendingCheckout) return pendingCheckout.payload;
        if (!preview || previewPending || scanFlight || scanQueue.length) return null;
        var random = new Uint8Array(16);
        if (!window.crypto || !window.crypto.getRandomValues) throw new Error('Secure checkout is unavailable in this browser. Use the HTTPS page in Chrome or Edge.');
        window.crypto.getRandomValues(random);
        var id = Array.from(random).map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
        var payload = draftPayload();
        payload.expected_total = preview.total;
        payload.request_id = id;
        var attempt = {payload: payload, preview: preview, cart: cart, state: 'pending'};
        try { saveCheckout(attempt); }
        catch (error) { throw new Error('Enable session storage for this page before checkout so an interrupted order can be recovered safely.'); }
        pendingCheckout = attempt;
        return payload;
    }

    function restoreCheckout() {
        if (!websiteCheckout) return;
        var saved;
        try { saved = JSON.parse(window.sessionStorage.getItem(storageKey) || 'null'); } catch (error) { return; }
        if (!saved || !saved.payload || !/^[a-f0-9]{32}$/.test(saved.payload.request_id || '') ||
            !['manual', 'products'].includes(saved.payload.mode) || !saved.preview) return;
        // An older payment link in this tab does not identify the uncertain
        // request. Recover the saved checkout before opening another payment;
        // otherwise following a bookmark could discard its idempotency key.
        if (config.initialToken) config.initialToken = '';
        cart = Array.isArray(saved.cart) ? saved.cart : [];
        el('xmr-pos-amount').value = saved.payload.amount || '';
        setSaleMode(saved.payload.mode, true);
        ++draftRevision;
        previewPending = false;
        preview = saved.preview;
        pendingCheckout = saved;
        renderCart();
        drawTotals();
        el('xmr-pos-preview-status').textContent = saved.state === 'rejected' ?
            'The previous checkout was rejected before an order was created. Review / edit the sale to continue.' :
            'An interrupted checkout is saved. Retry to recover the same order before starting another sale.';
    }

    function initializeSaleEntry() {
        if (!websiteCheckout) return;
        el('xmr-pos-mode-manual').addEventListener('click', function () { setSaleMode('manual'); });
        el('xmr-pos-mode-products').addEventListener('click', function () { setSaleMode('products'); });
        el('xmr-pos-scanner-focus').addEventListener('click', focusScanner);
        el('xmr-pos-add-barcode').addEventListener('click', addBarcode);
        el('xmr-pos-barcode').addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            event.stopPropagation();
            addBarcode();
        });
        el('xmr-pos-form').addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target !== el('xmr-pos-create') && event.target.tagName !== 'BUTTON') event.preventDefault();
        });
        el('xmr-pos-amount').addEventListener('input', function () {
            if (!creating && !pendingCheckout) refreshPreview(250);
        });
        el('xmr-pos-revise').addEventListener('click', function () {
            if (!pendingCheckout || pendingCheckout.state !== 'rejected' || creating || pageLeaving) return;
            clearCheckout();
            refreshPreview();
            showAlert('Review the refreshed total before creating a new checkout.');
        });
        renderCart();
        drawTotals();
        restoreCheckout();
    }

    function setUnlockBusy(busy) {
        var button = el('xmr-pos-unlock');
        if (!button) return;
        button.disabled = busy;
        button.querySelector('span').textContent = busy ? 'Checking…' : 'Unlock cashier page';
    }

    function request(action, values) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', config.nonce);
        Object.keys(values || {}).forEach(function (key) { body.append(key, values[key]); });

        var controller = new AbortController();
        var isPaymentRequest = action === config.createAction || action === config.statusAction;
        if (isPaymentRequest) paymentRequests.add(controller);
        var timeout = window.setTimeout(function () { controller.abort(); }, 20000);

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (json) {
                if (!response.ok || !json || !json.success) {
                    var message = json && json.data && json.data.message ? json.data.message : 'The payment service did not respond. Please try again.';
                    var error = new Error(message);
                    error.status = response.status;
                    error.code = json && json.data && json.data.code;
                    error.definitive = !!(json && json.data && json.data.definitive === true);
                    throw error;
                }
                return json.data;
            });
        }).finally(function () {
            window.clearTimeout(timeout);
            paymentRequests.delete(controller);
        });
    }

    function monitorState(fresh, text) {
        var target = el('xmr-pos-monitor');
        if (!target) return;
        target.classList.toggle('is-stale', !fresh);
        target.replaceChildren(document.createElement('span'), document.createTextNode(' ' + text));
    }

    function terminalError(error) {
        var target = el('xmr-pos-terminal-status');
        if (target) target.textContent = error && error.message ? error.message : 'The terminal did not respond. Use the QR code on this page.';
        terminalTestResult('Terminal error: ' + (error && error.message ? error.message : 'No response. Copy the terminal log.'), true);
        renderTerminalControls();
    }

    function terminalTestResult(message, failed) {
        var target = el('xmr-pos-terminal-test-result');
        if (!target) return;
        target.textContent = message;
        target.classList.toggle('is-error', !!failed);
    }

    function renderTerminalDiagnostics(text) {
        var target = el('xmr-pos-terminal-log');
        if (target) target.textContent = text || 'No terminal commands sent.';
        var button = el('xmr-pos-terminal-copy-log');
        if (button) button.disabled = !text;
    }

    function stopTerminal(reason) {
        if (!terminal) return Promise.resolve();
        try { return Promise.resolve(terminal.stop(reason)).catch(terminalError); }
        catch (error) { terminalError(error); return Promise.resolve(); }
    }

    function performTerminalAction(action) {
        if (pageLeaving || pageExitPromise || terminalOperation || !terminal) return;
        terminalOperation = true;
        var result;
        try { result = action(); }
        catch (error) { result = Promise.reject(error); }
        renderTerminalControls();
        Promise.resolve(result).catch(terminalError).finally(function () {
            terminalOperation = false;
            renderTerminalControls();
        });
    }

    function freshPayment() {
        return !pageLeaving && !pageExitPromise && currentDetails && currentDetails.status_fresh === true && !currentDetails.warning &&
            currentDetails.status_cached !== true &&
            (currentStatus === 'unpaid' || currentStatus === 'partial') && expiresAt > Date.now() &&
            Date.now() - currentDetails.status_checked_at_ms >= 0 &&
            Date.now() - currentDetails.status_checked_at_ms < 15000;
    }

    function clearPaymentImage(message) {
        imageGeneration++;
        imagePending = false;
        paymentImage = null;
        if (imageUrl) window.URL.revokeObjectURL(imageUrl);
        imageUrl = '';
        var preview = el('xmr-pos-payment-image-preview');
        var image = el('xmr-pos-payment-image');
        var download = el('xmr-pos-payment-image-download');
        var status = el('xmr-pos-payment-image-status');
        if (preview) preview.hidden = true;
        if (image) image.removeAttribute('src');
        if (download) { download.hidden = true; download.removeAttribute('href'); }
        if (status) status.textContent = message || 'Create a payment request to preview its JPG.';
    }

    function renderPaymentImage() {
        if (!el('xmr-pos-payment-image-preview')) return;
        clearPaymentImage('A fresh unpaid or partial payment is required for the JPG preview.');
        if (!imagePageActive || !freshPayment()) return;
        if (!window.MoneroPosImage || typeof window.MoneroPosImage.render !== 'function' ||
            !window.URL || typeof window.URL.createObjectURL !== 'function') {
            el('xmr-pos-payment-image-status').textContent = 'The JPG preview is unavailable in this browser. The cashier QR remains available.';
            return;
        }
        var details = currentDetails, saleGeneration = generation, renderGeneration = imageGeneration;
        imagePending = true;
        el('xmr-pos-payment-image-status').textContent = 'Preparing the current payment JPG…';
        Promise.resolve().then(function () {
            return window.MoneroPosImage.render(details, {document: document, QRCode: QRCode, now: Date.now, format: 'image/jpeg'});
        }).then(function (result) {
            if (renderGeneration !== imageGeneration || saleGeneration !== generation ||
                details !== currentDetails || details.token !== currentToken) return;
            imagePending = false;
            if (!freshPayment() || !result || result.mimeType !== 'image/jpeg' || !result.blob ||
                (window.MoneroPosImage.matchesCurrent && !window.MoneroPosImage.matchesCurrent(result, currentDetails, {now: Date.now}))) {
                clearPaymentImage('Waiting for a fresh payment before preparing another JPG.');
                return;
            }
            imageUrl = window.URL.createObjectURL(result.blob);
            paymentImage = result;
            el('xmr-pos-payment-image').src = imageUrl;
            el('xmr-pos-payment-image-preview').hidden = false;
            var download = el('xmr-pos-payment-image-download');
            download.href = imageUrl;
            download.download = 'wards-ace-payment.jpg';
            download.hidden = false;
            el('xmr-pos-payment-image-status').textContent = 'Current payment JPG preview. It has not been displayed or verified on the Ingenico.';
        }).catch(function (error) {
            if (renderGeneration !== imageGeneration || saleGeneration !== generation || details !== currentDetails) return;
            clearPaymentImage('JPG preview unavailable: ' + (error && error.message ? error.message : 'Image rendering failed.'));
        });
    }

    function initializePaymentImage() {
        var download = el('xmr-pos-payment-image-download');
        if (download) download.addEventListener('click', function (event) {
            if (!imageUrl || !paymentImage || !freshPayment() ||
                (window.MoneroPosImage.matchesCurrent && !window.MoneroPosImage.matchesCurrent(paymentImage, currentDetails, {now: Date.now}))) {
                event.preventDefault();
                clearPaymentImage('Waiting for a fresh payment before preparing another JPG.');
            }
        });
    }

    function initializePageLifecycle() {
        function leavePage() {
            if (pageLeaving) return;
            pageLeaving = true;
            generation++;
            ++draftRevision;
            ++scanGeneration;
            scanFlight = null;
            scanQueue = [];
            window.clearTimeout(previewTimer);
            previewPending = false;
            imagePageActive = false;
            clearPaymentImage('Cashier page closed or suspended. Reconnect after returning.');
            if (currentDetails) currentDetails.status_fresh = false;
            paymentRequests.forEach(function (controller) { controller.abort(); });
            paymentRequests.clear();
            pollInFlight = null;
            creating = false;
            clearInterval(pollTimer);
            clearInterval(countdownTimer);
            pollTimer = countdownTimer = null;
            var operation;
            try {
                // Invoke immediately: page termination may prevent further tasks.
                // This is separate from the normal, acknowledged restore flow.
                operation = terminal ? (terminal.releaseForPageExit || terminal.disconnect).call(terminal, 'Cashier page closed or suspended.') : Promise.resolve();
            } catch (error) { operation = Promise.reject(error); }
            var closing = Promise.resolve(operation).catch(terminalError);
            pageExitPromise = closing;
            closing.finally(function () {
                if (pageExitPromise === closing) pageExitPromise = null;
                setBusy(creating);
                renderTerminalControls();
            });
            setBusy(false);
            renderTerminalControls();
        }
        function resumePage() {
            if (!pageLeaving) return;
            pageLeaving = false;
            imagePageActive = true;
            setBusy(false);
            renderTerminalControls();
            // BFCache/resume retains old JS objects. Require a new wallet check
            // and an explicit cashier connection before using the terminal again.
            if (config.authorized && currentToken) { beginPolling(); poll(); }
            else if (config.authorized && websiteCheckout && !pendingCheckout) refreshPreview();
        }
        window.addEventListener('pagehide', leavePage, {capture: true});
        document.addEventListener('freeze', leavePage, {capture: true});
        window.addEventListener('pageshow', resumePage, {capture: true});
        document.addEventListener('resume', resumePage, {capture: true});
    }

    function renderTerminalControls() {
        if ((imageUrl || imagePending) && !freshPayment()) clearPaymentImage('Waiting for a fresh unpaid or partial payment before preparing another JPG.');
        if (!el('xmr-pos-terminal-connect')) return;
        var supported = !!(window.isSecureContext && navigator.serial && terminal);
        var connected = terminal ? terminal.isConnected() : false;
        var busy = pageLeaving || !!pageExitPromise || terminalOperation || terminalState.busy;
        el('xmr-pos-terminal-connect').disabled = !supported || connected || busy;
        if (el('xmr-pos-terminal-recover')) el('xmr-pos-terminal-recover').disabled = !supported || connected || busy;
        el('xmr-pos-terminal-disconnect').disabled = !connected || terminalOperation;
        el('xmr-pos-terminal-test').disabled = !connected || busy || !freshPayment();
        if (el('xmr-pos-terminal-text-test')) el('xmr-pos-terminal-text-test').disabled = !connected || busy || !terminal.probeText;
        if (el('xmr-pos-terminal-inspect')) el('xmr-pos-terminal-inspect').disabled = !connected || busy || !terminal.inspectForms;
        if (el('xmr-pos-terminal-image-prepare')) el('xmr-pos-terminal-image-prepare').disabled = !connected || busy || !terminal.installImageForm;
        if (el('xmr-pos-terminal-image-test')) el('xmr-pos-terminal-image-test').disabled = !connected || busy || !freshPayment() || !terminal.probeImage;
        var badge = el('xmr-pos-terminal-badge');
        badge.textContent = connected ? (terminalState.ready ? 'Connected' : 'Connected · setup needed') : 'Not connected';
        badge.classList.toggle('is-connected', connected);
        el('xmr-pos-terminal-profile-note').textContent = terminalState.ready ?
            'Automatic display uses the verified QR and amount layout. Keep this page open while the customer pays.' :
            (config.terminalProfile && config.terminalProfile.verified === true && config.terminalProfile.mode === 'jpeg' ?
                'Checkout automatically connects and displays its JPG. Close Eagle to make the USB port available; select COM5 if prompted.' :
                'Automatic display is awaiting verification of the terminal\'s QR form and amount labels.');
        if (el('xmr-pos-terminal-image-note') && config.terminalProfile && config.terminalProfile.verified === true && config.terminalProfile.mode === 'jpeg') {
            el('xmr-pos-terminal-image-note').textContent = terminalState.ready ?
                'Automatic JPG display is ready. Keep this page open while the customer pays.' :
                'Connect the Ingenico to check and use the verified JPG screen.';
        }
    }

    function initializeTerminal() {
        if (!el('xmr-pos-terminal')) return;
        if (!window.isSecureContext || !navigator.serial || !window.MoneroPosTerminal) {
            el('xmr-pos-terminal-status').textContent = 'To connect the Ingenico, open this HTTPS page in Chrome or Edge on the register. The cashier QR works without a terminal connection.';
            renderTerminalControls();
            return;
        }
        try { terminal = window.MoneroPosTerminal.create({
            profile: config.terminalProfile || null,
            onDiagnostic: renderTerminalDiagnostics,
            onState: function (state) {
                terminalState = state;
                el('xmr-pos-terminal-status').textContent = state.message;
                if (state.state === 'error') terminalTestResult('Terminal error: ' + state.message, true);
                renderTerminalControls();
            }
        }); } catch (error) { terminalError(error); return; }
        el('xmr-pos-terminal-connect').addEventListener('click', function () {
            // Call synchronously from the click; the browser's port chooser needs user activation.
            performTerminalAction(function () {
                return Promise.resolve(terminal.connect()).then(function () {
                    if (!pageLeaving && !pageExitPromise && currentDetails && !document.hidden) return terminal.update(currentDetails);
                });
            });
        });
        el('xmr-pos-terminal-disconnect').addEventListener('click', function () {
            performTerminalAction(function () { return terminal.disconnect(); });
        });
        if (el('xmr-pos-terminal-recover')) el('xmr-pos-terminal-recover').addEventListener('click', function () {
            performTerminalAction(function () {
                return Promise.resolve(terminal.connect({ recoverCardReady: true })).then(function () {
                    if (!pageLeaving && !pageExitPromise && currentDetails && !document.hidden) return terminal.update(currentDetails);
                });
            });
        });
        el('xmr-pos-terminal-test').addEventListener('click', function () {
            if (!freshPayment()) return;
            performTerminalAction(function () {
                terminalTestResult('Sending the QR test. Watch the Ingenico for up to 12 seconds.', false);
                return terminal.probe(currentDetails).then(function (result) {
                    terminalTestResult(result && result.stopped ? 'The QR test stopped before completion. See the terminal log.' : 'The QR command finished and restoration was acknowledged. If the QR did not appear, copy the terminal log.', false);
                });
            });
        });
        if (el('xmr-pos-terminal-text-test')) el('xmr-pos-terminal-text-test').addEventListener('click', function () {
            performTerminalAction(function () {
                terminalTestResult('Sending the original hello-world test. Watch the Ingenico for up to 12 seconds.', false);
                return terminal.probeText().then(function (result) {
                    terminalTestResult(result && result.stopped ? 'The hello-world test stopped before completion. See the terminal log.' : 'The hello-world command finished and restoration was acknowledged. Check that the text appeared on the Ingenico.', false);
                });
            });
        });
        if (el('xmr-pos-terminal-inspect')) el('xmr-pos-terminal-inspect').addEventListener('click', function () {
            performTerminalAction(function () {
                terminalTestResult('Checking whether the message and QR forms are installed.', false);
                return terminal.inspectForms().then(function (result) {
                    terminalTestResult(result.message, false);
                });
            });
        });
        if (el('xmr-pos-terminal-image-prepare')) el('xmr-pos-terminal-image-prepare').addEventListener('click', function () {
            if (!terminal.isConnected() || terminalState.busy || typeof terminal.installImageForm !== 'function') return;
            performTerminalAction(function () {
                terminalTestResult('Preparing the JPEG screen form. Keep Eagle closed and wait for the result.', false);
                return Promise.resolve(terminal.installImageForm()).then(function (result) {
                    var message = result && result.message ? result.message : 'JPEG screen preparation finished. Physical display verification is still required.';
                    if (result && result.requiresRestart) message += ' Restart required: restore and disconnect, then restart the terminal using the normal register procedure before testing. This page will not restart it automatically.';
                    terminalTestResult(message, false);
                });
            });
        });
        if (el('xmr-pos-terminal-image-test')) el('xmr-pos-terminal-image-test').addEventListener('click', function () {
            if (!freshPayment() || !terminal.isConnected() || terminalState.busy || typeof terminal.probeImage !== 'function') return;
            performTerminalAction(function () {
                terminalTestResult('Preparing and sending the current payment JPG. Watch for a 12-second display, then restoration.', false);
                return Promise.resolve(terminal.probeImage(currentDetails)).then(function (result) {
                    terminalTestResult(result && result.stopped ? 'The payment JPG test stopped before completion. See the terminal log.' :
                        ((result && result.message) || 'The payment JPG command finished and restoration was acknowledged.') + ' Check the Ingenico: compare both amounts and scan the QR with a wallet without sending payment.' +
                        (config.terminalProfile && config.terminalProfile.verified === true && config.terminalProfile.mode === 'jpeg' ? '' : ' Automatic JPG display remains disabled.'), false);
                });
            });
        });
        if (el('xmr-pos-terminal-copy-log')) el('xmr-pos-terminal-copy-log').addEventListener('click', function () {
            if (!terminal || !terminal.getDiagnostics) return;
            navigator.clipboard.writeText(terminal.getDiagnostics()).then(function () {
                el('xmr-pos-terminal-copy-log').textContent = 'Terminal log copied';
            }).catch(function () {
                terminalTestResult('Could not copy automatically. Select the terminal command log and copy it manually.', true);
            });
        });
        document.addEventListener('visibilitychange', function () {
            if (pageLeaving) return;
            if (document.hidden) stopTerminal('Cashier page hidden');
            else if (currentToken) poll();
        });
        renderTerminalControls();
    }

    function statusText(details) {
        switch (details.status) {
            case 'partial':
                return ['Partial payment received', 'Ask the customer to send the remaining ' + details.amount_due_formatted + ' XMR.'];
            case 'paid':
                return ['Payment detected', 'The full amount was seen by the wallet. The terminal will restore and disconnect automatically. Waiting about ' + details.time_to_confirm + ' for confirmation.'];
            case 'confirmed':
                return ['Payment confirmed', details.order_id ?
                    'The Monero payment is complete. The sale is recorded on the website; no Epicor checkout is needed.' :
                    'The Monero payment is complete. Wait for the terminal to disconnect before reopening Eagle.'];
            case 'expired_partial':
                return ['Expired with a partial payment', 'Do not create another request until the partial payment is reconciled.'];
            case 'expired':
                return ['Payment request expired', 'No payment was found. Select New sale to create another request.'];
            default:
                return ['Waiting for payment', 'Ask the customer to scan the QR code and send the exact amount.'];
        }
    }

    function updateSteps(status) {
        var steps = document.querySelectorAll('.xmr-pos-step');
        var lines = document.querySelectorAll('.xmr-pos-step-line');
        steps.forEach(function (step) { step.classList.remove('active', 'done', 'error'); });
        lines.forEach(function (line) { line.classList.remove('active', 'done'); });

        var waiting = document.querySelector('[data-step="waiting"]');
        var received = document.querySelector('[data-step="received"]');
        var confirmed = document.querySelector('[data-step="confirmed"]');

        if (status === 'confirmed') {
            waiting.classList.add('done');
            received.classList.add('done');
            confirmed.classList.add('done');
            lines.forEach(function (line) { line.classList.add('done'); });
        } else if (status === 'paid') {
            waiting.classList.add('done');
            received.classList.add('done');
            confirmed.classList.add('active');
            lines[0].classList.add('done');
            lines[1].classList.add('active');
        } else if (status === 'expired' || status === 'expired_partial') {
            waiting.classList.add('error');
        } else {
            waiting.classList.add('active');
        }
    }

    function renderQr(uri) {
        var target = el('xmr-pos-qr');
        if (target.dataset.uri === uri) return;
        target.innerHTML = '';
        target.dataset.uri = uri;
        new QRCode(target, {
            text: uri,
            width: 280,
            height: 280,
            colorDark: '#111827',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    function renderTransactions(transactions) {
        var section = el('xmr-pos-transactions');
        var list = el('xmr-pos-transaction-list');
        list.innerHTML = '';
        section.hidden = !transactions.length;

        transactions.forEach(function (transaction) {
            var row = document.createElement('div');
            row.className = 'xmr-pos-transaction';

            var link = document.createElement('a');
            link.href = config.explorerUrl.replace(/\/$/, '') + '/tx/' + encodeURIComponent(transaction.txid);
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = transaction.txid;

            var meta = document.createElement('span');
            meta.textContent = transaction.amount_formatted + ' XMR · ' + (transaction.height === 0 ? 'mempool' : 'block ' + transaction.height);

            row.appendChild(link);
            row.appendChild(meta);
            list.appendChild(row);
        });
    }

    function formatCountdown(seconds) {
        seconds = Math.max(0, seconds);
        var minutes = Math.floor(seconds / 60);
        var remainder = seconds % 60;
        return minutes + ':' + String(remainder).padStart(2, '0');
    }

    function startCountdown(seconds, checkedAt) {
        expiresAt = checkedAt + (seconds * 1000);
        clearInterval(countdownTimer);
        countdownTimer = setInterval(function () {
            renderTerminalControls();
            renderPaymentCode();
            if (currentStatus !== 'unpaid' && currentStatus !== 'partial') return;
            if (currentDetails && (currentDetails.order_warning || currentDetails.hold)) return;
            var remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
            var copy = statusText({status: currentStatus, amount_due_formatted: el('xmr-pos-due').textContent})[1];
            el('xmr-pos-status-copy').textContent = copy + ' Request expires in ' + formatCountdown(remaining) + '.';
        }, 1000);
    }

    function renderPaymentCode() {
        if (!currentDetails) return;
        var review = !!currentDetails.order_warning;
        var complete = !!currentDetails.order_id && ['paid', 'confirmed'].includes(currentStatus);
        var expired = !!currentDetails.order_id && !complete &&
            (['expired', 'expired_partial'].includes(currentStatus) || expiresAt <= Date.now());
        var paused = review || complete || expired;
        el('xmr-pos-qr').hidden = paused;
        el('xmr-pos-wallet-link').hidden = paused;
        if (el('xmr-pos-qr-paused')) {
            el('xmr-pos-qr-paused').hidden = !paused;
            el('xmr-pos-qr-paused').textContent = review ? 'Payment code paused while the website order is reviewed.' :
                (complete ? 'Payment has been received. No further payment is needed.' : 'This payment request has expired. Do not send funds to it.');
        }
    }

    function update(details, isInitial, checkedAt) {
        if (pageLeaving) return;
        var previousStatus = currentStatus;
        currentStatus = details.status;
        currentToken = details.token;
        if (details.order_id && !details.order_warning &&
            ['cancelled', 'failed', 'refunded'].includes(details.order_status) &&
            ['unpaid', 'partial', 'paid', 'confirmed'].includes(details.status)) {
            details.order_warning = 'This website order is ' + details.order_status +
                '. Do not take another payment for it. Ask a manager to review the order and any received funds.';
        }
        // Wallet payment and website order completion are separate facts. A paid
        // wallet snapshot must not clear an order reconciliation warning.
        if (details.order_warning) details.warning = [details.warning, details.order_warning].filter(Boolean).join(' ');
        // Anchor freshness to request START, so a slow or replayed response cannot extend its lifetime.
        details.status_checked_at_ms = checkedAt;
        currentDetails = details;

        el('xmr-pos-entry').hidden = true;
        el('xmr-pos-payment').hidden = false;
        el('xmr-pos-fiat-total').textContent = '$' + Number(details.fiat_total).toFixed(2);
        if (el('xmr-pos-order-identity')) {
            el('xmr-pos-order-identity').textContent = details.order_id ?
                'Website order #' + (details.order_number || details.order_id) + ' · ' + String(details.order_status || 'pending').replace(/-/g, ' ') :
                (websiteCheckout ? 'Legacy payment request · no website order was created' : '');
        }
        if (el('xmr-pos-order-breakdown')) {
            el('xmr-pos-order-breakdown').textContent = details.sale ?
                'Subtotal ' + dollars(details.sale.subtotal) + ' + sales tax (6%) ' + dollars(details.sale.tax) + ' = ' + dollars(details.sale.total) :
                (websiteCheckout && !details.order_id ? 'This older request tracks the Monero payment only.' : '');
        }
        el('xmr-pos-due').textContent = details.amount_due_formatted;
        el('xmr-pos-paid').textContent = details.amount_paid_formatted;
        el('xmr-pos-rate').textContent = '1 XMR = $' + details.rate_formatted + ' USD';
        el('xmr-pos-address').textContent = details.integrated_address;
        el('xmr-pos-wallet-link').href = details.qrcode_uri;

        var cached = details.status_cached === true || (details.status_cached === undefined && details.status_fresh === false);
        // The shop wallet is on a backup node: nothing it shows counts as paid or expired yet.
        var held = details.hold === true && !details.order_warning;
        var message = held ? ['Payment on hold', 'The shop wallet is using a backup node. Any payment is recorded, but it is ' +
            'not accepted until the wallet is back on its own node. Do not hand over goods yet; this updates by itself.'] :
            details.order_warning ?
            [!cached && details.status === 'confirmed' ? 'Payment confirmed · order needs review' :
                (!cached && details.status === 'paid' ? 'Payment detected · order needs review' : 'Website order needs review'), details.order_warning] :
            cached && (details.status === 'paid' || details.status === 'confirmed') ?
            ['Checking payment status', 'The last saved result is shown below. Waiting for a fresh wallet check.'] : statusText(details);
        el('xmr-pos-status-title').textContent = message[0];
        el('xmr-pos-status-copy').textContent = message[1];
        el('xmr-pos-status-card').className = 'xmr-pos-status-card status-' + (details.order_warning ? 'order-review' : held ? 'unpaid' : details.status);

        updateSteps(cached || held || details.order_warning ? 'unpaid' : details.status);
        if (el('xmr-pos-status-tracker')) el('xmr-pos-status-tracker').hidden = !!details.order_warning;
        renderQr(details.qrcode_uri);
        renderTransactions(details.txs || []);
        startCountdown(Number(details.expires_in || 0), checkedAt);
        renderPaymentCode();
        showAlert(details.warning || '');
        renderPaymentImage();
        monitorState(!cached && !details.order_warning, details.order_warning ? 'Website order needs review' :
            (cached ? 'Waiting for a fresh wallet check' : (details.status_source === 'viewkey' ? 'Explorer status checked' : 'Wallet status checked')));
        var checkAge = Date.now() - checkedAt;
        var timelyCheck = Number.isFinite(checkAge) && checkAge >= 0 && checkAge <= 15000;
        var freshPaymentComplete = !cached && details.status_fresh === true && !details.warning &&
            timelyCheck &&
            (details.status === 'paid' || details.status === 'confirmed');
        // Hidden pages must still release the port on fresh payment; they must not start or renew a display.
        if (terminal && !pageExitPromise && (!document.hidden || freshPaymentComplete)) Promise.resolve(terminal.update(details)).catch(terminalError);
        renderTerminalControls();

        if (previousStatus && previousStatus !== details.status) {
            el('xmr-pos-status-card').classList.add('status-changed');
            window.setTimeout(function () { el('xmr-pos-status-card').classList.remove('status-changed'); }, 900);
        }

        var url = config.pageUrl + '?payment=' + encodeURIComponent(details.token);
        if (!isInitial || window.location.href !== url) window.history.replaceState({}, '', url);

        // A slow confirmed reply cannot release the terminal; keep polling until a timely check arrives.
        // View-key confirmation can still finish cashier polling without being eligible for terminal control.
        // An expired website order can still receive a late transfer. Keep the
        // open cashier page watching so its manual-review warning is visible.
        if (!cached && !held && !details.order_warning && ((details.status === 'confirmed' && timelyCheck) ||
            (!details.order_id && (details.status === 'expired' || details.status === 'expired_partial')))) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function poll() {
        if (pageLeaving || !currentToken || pollInFlight) return Promise.resolve();
        var flight = {token: currentToken, generation: generation, startedAt: Date.now()};
        pollInFlight = flight;
        return request(config.statusAction, {token: flight.token}).then(function (details) {
            if (generation !== flight.generation || currentToken !== flight.token || details.token !== flight.token) return;
            update(details, false, flight.startedAt);
        }).catch(function (error) {
            if (generation !== flight.generation || currentToken !== flight.token) return;
            showAlert(error.name === 'AbortError' ? 'The wallet check timed out. Trying again shortly.' : error.message);
            monitorState(false, 'Wallet check interrupted');
            if (currentDetails) currentDetails.status_fresh = false;
            clearPaymentImage('Wallet check interrupted. Waiting for a fresh payment before preparing another JPG.');
            stopTerminal('Wallet check interrupted');
            renderTerminalControls();
        }).finally(function () {
            if (pollInFlight === flight) pollInFlight = null;
        });
    }

    function beginPolling() {
        if (pageLeaving) return;
        clearInterval(pollTimer);
        pollTimer = window.setInterval(poll, 5000);
    }

    function activateCashier() {
        config.authorized = true;
        var access = el('xmr-pos-access');
        if (access) access.hidden = true;
        el('xmr-pos-entry').hidden = false;
        if (el('xmr-pos-terminal')) el('xmr-pos-terminal').hidden = false;

        if (config.initialToken) {
            currentToken = config.initialToken;
            config.initialToken = '';
            if (websiteCheckout) setBusy(false);
            beginPolling();
            poll();
        } else {
            if (websiteCheckout && saleMode === 'products' && !pendingCheckout) focusScanner();
            else if (!pendingCheckout) el('xmr-pos-amount').focus();
        }
    }

    function reset() {
        if (pageLeaving) return;
        if (websiteCheckout && pendingCheckout) {
            showAlert('Recover the existing checkout before starting another sale.');
            return;
        }
        stopTerminal('New sale');
        generation++;
        clearPaymentImage();
        paymentRequests.forEach(function (controller) { controller.abort(); });
        paymentRequests.clear();
        pollInFlight = null;
        creating = false;
        setBusy(false);
        clearInterval(pollTimer);
        clearInterval(countdownTimer);
        pollTimer = null;
        currentToken = '';
        currentStatus = '';
        currentDetails = null;
        expiresAt = 0;
        showAlert('');
        el('xmr-pos-payment').hidden = true;
        el('xmr-pos-entry').hidden = false;
        el('xmr-pos-form').reset();
        if (websiteCheckout) {
            clearCheckout();
            cart = [];
            el('xmr-pos-amount').value = '';
            el('xmr-pos-barcode').value = '';
            setSaleMode(saleMode);
        }
        window.history.replaceState({}, '', config.pageUrl);
        if (websiteCheckout && saleMode === 'products') focusScanner();
        else el('xmr-pos-amount').focus();
        monitorState(false, 'Waiting for a payment request');
        renderTerminalControls();
    }

    function copyTarget(button) {
        var target = el(button.dataset.copyTarget);
        if (!target) return;
        var value = target.textContent.trim();
        navigator.clipboard.writeText(value).then(function () {
            var original = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = original; }, 1500);
        }).catch(function () {
            showAlert('Could not copy automatically. Select the value and copy it manually.');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializePaymentImage();
        initializeTerminal();
        initializePageLifecycle();
        initializeSaleEntry();
        var accessForm = el('xmr-pos-access-form');
        if (accessForm) {
            accessForm.addEventListener('submit', function (event) {
                event.preventDefault();
                showAlert('');
                var responseField = accessForm.querySelector('[name="cf-turnstile-response"]');
                var turnstileToken = responseField ? responseField.value : '';
                if (config.turnstileEnabled && !turnstileToken) {
                    showAlert('Complete the Cloudflare verification first.');
                    return;
                }

                setUnlockBusy(true);
                request(config.unlockAction, {
                    pin: el('xmr-pos-pin').value,
                    turnstile: turnstileToken
                }).then(function () {
                    activateCashier();
                }).catch(function (error) {
                    showAlert(error.message);
                    el('xmr-pos-pin').value = '';
                    el('xmr-pos-pin').focus();
                    if (window.turnstile && typeof window.turnstile.reset === 'function') {
                        window.turnstile.reset();
                    }
                }).finally(function () {
                    setUnlockBusy(false);
                });
            });
        }

        function createPayment(event) {
            event.preventDefault();
            if (creating || pageLeaving || pageExitPromise) return;
            if (websiteCheckout && currentToken) return;
            if (websiteCheckout && pendingCheckout && pendingCheckout.state === 'rejected') return;
            var payload;
            try { payload = websiteCheckout ? prepareCheckout() : {amount: el('xmr-pos-amount').value}; }
            catch (error) { showAlert(error.message); return; }
            if (!payload) return;
            creating = true;
            var saleGeneration = generation;
            var startedAt = Date.now();
            if (terminal && config.terminalProfile && config.terminalProfile.verified === true && config.terminalProfile.mode === 'jpeg' && !terminal.isConnected()) {
                // The payment click supplies browser activation for the first port
                // chooser; later sales can reuse this tab's verified device.
                performTerminalAction(function () {
                    return Promise.resolve(terminal.connect({ reusePort: true, recoverCardReady: true })).then(function () {
                        if (saleGeneration === generation && !pageLeaving && !pageExitPromise && currentDetails && !document.hidden) return terminal.update(currentDetails);
                    });
                });
            }
            showAlert('');
            setBusy(true);
            request(config.createAction, payload).then(function (details) {
                if (saleGeneration !== generation) return;
                update(details, false, startedAt);
                if (websiteCheckout) clearCheckout();
                beginPolling();
                poll();
            }).catch(function (error) {
                if (saleGeneration !== generation) return;
                if (websiteCheckout) {
                    if (error.definitive && pendingCheckout) {
                        pendingCheckout.state = 'rejected';
                        try { saveCheckout(pendingCheckout); } catch (ignored) { /* Keep the same key if storage becomes unavailable. */ }
                        el('xmr-pos-preview-status').textContent = 'No order was created. Review / edit the sale to refresh the total before trying again.';
                        showAlert(error.message);
                    } else {
                        el('xmr-pos-preview-status').textContent = 'The outcome is not yet known. Retry / recover this checkout uses the same order request.';
                        showAlert((error.name === 'AbortError' ? 'Checkout timed out.' : error.message) + ' Keep this sale open and use Retry / recover this checkout.');
                    }
                } else {
                    showAlert(error.name === 'AbortError' ? 'The request timed out. Check whether a request was created before trying again.' : error.message);
                    el('xmr-pos-amount').focus();
                }
            }).finally(function () {
                if (saleGeneration === generation) {
                    creating = false;
                    setBusy(false);
                }
            });
        }

        el('xmr-pos-form').addEventListener('submit', function (event) {
            event.preventDefault();
            // A keyboard scanner's Enter must never submit an order.
            if (!websiteCheckout) createPayment(event);
        });
        if (websiteCheckout) el('xmr-pos-create').addEventListener('click', createPayment);

        el('xmr-pos-new-sale').addEventListener('click', reset);
        document.querySelectorAll('.xmr-pos-copy').forEach(function (button) {
            button.addEventListener('click', function () { copyTarget(button); });
        });

        if (!config.authorized) {
            request(config.accessAction, {}).then(function () {
                activateCashier();
            }).catch(function (error) {
                if (error.message !== 'Cashier PIN required.') showAlert(error.message);
                el('xmr-pos-pin').focus();
            });
        } else {
            activateCashier();
        }
    });
}());
