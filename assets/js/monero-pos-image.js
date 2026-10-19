/* Browser-only 800x480 payment artwork. This module does not upload, store, or display
 * anything on a terminal. Load the bundled qrcode.min.js and monero-pos-terminal.js first.
 * QR margins: https://www.qrcode.com/en/howto/code.html
 * Encoder: the existing bundled https://github.com/davidshimjs/qrcodejs
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) module.exports = factory(root, require('./monero-pos-terminal.js'));
    else root.MoneroPosImage = factory(root, root.MoneroPosTerminal);
})(typeof globalThis !== 'undefined' ? globalThis : this, function (root, Terminal) {
    'use strict';
    var WIDTH = 800, HEIGHT = 480, QUIET_MODULES = 4, QR_BOX = { x: 24, y: 88, size: 368 };

    function validate(details, now) {
        if (!Terminal || !Terminal._test || typeof Terminal._test.snapshot !== 'function') throw new Error('The payment snapshot validator is unavailable.');
        // Reuse the exact freshness, sale binding, URI and decimal checks used by the display adapter.
        var snapshot = Terminal._test.snapshot(details, now);
        if (!snapshot) throw new Error('A fresh unpaid or partial payment with matching URI and XMR amount is required.');
        return Object.freeze(snapshot);
    }
    function clockNow(options) { return options && typeof options.now === 'function' ? options.now() : Date.now(); }
    function fitText(context, text, x, y, maxWidth, maxSize, minSize, weight, color) {
        var size = maxSize;
        do { context.font = weight + ' ' + size + 'px Arial, sans-serif'; if (context.measureText(text).width <= maxWidth) break; size--; } while (size >= minSize);
        if (size < minSize) throw new Error('The exact payment amount does not fit the image at a readable size.');
        context.fillStyle = color;
        context.fillText(text, x, y);
        return size;
    }
    function matrix(uri, document, Encoder) {
        if (!Encoder || !Encoder.CorrectLevel) throw new Error('The bundled QR encoder is unavailable.');
        var holder = document.createElement('div'), encoder;
        try {
            encoder = new Encoder(holder, { text: uri, width: 256, height: 256,
                colorDark: '#000000', colorLight: '#ffffff', correctLevel: Encoder.CorrectLevel.M });
            // The checked-in QRCode.js exposes its generated module matrix here. Its own
            // canvas drawing can use fractional pixels, so redraw the matrix at integer scale.
            var model = encoder._oQRCode;
            if (!model || typeof model.getModuleCount !== 'function' || typeof model.isDark !== 'function') throw new Error('The bundled QR encoder matrix API changed.');
            var count = model.getModuleCount();
            if (!Number.isInteger(count) || count < 21 || count > 177 || (count - 21) % 4 !== 0) throw new Error('Invalid QR matrix size.');
            var rows = [];
            for (var row = 0; row < count; row++) {
                rows[row] = [];
                for (var column = 0; column < count; column++) rows[row][column] = model.isDark(row, column) === true;
            }
            return rows;
        } finally { if (encoder && typeof encoder.clear === 'function') encoder.clear(); holder.textContent = ''; }
    }
    function exportImage(canvas, mimeType) {
        return new Promise(function (resolve, reject) {
            if (typeof canvas.toBlob !== 'function') return reject(new Error('This browser cannot export the payment image.'));
            canvas.toBlob(function (blob) {
                if (!blob || blob.type !== mimeType || !blob.size) reject(new Error('Payment image export failed.'));
                else resolve(blob);
            }, mimeType, mimeType === 'image/jpeg' ? 0.8 : undefined);
        });
    }
    function assetId() {
        if (!root.crypto || typeof root.crypto.getRandomValues !== 'function') throw new Error('Secure image identifiers are unavailable.');
        return Array.from(root.crypto.getRandomValues(new Uint8Array(16)), function (n) { return n.toString(16).padStart(2, '0'); }).join('');
    }
    async function render(details, options) {
        options = options || {};
        var mimeType = options.format || 'image/png';
        if (mimeType !== 'image/png' && mimeType !== 'image/jpeg') throw new Error('Choose image/png or image/jpeg for the payment image.');
        var document = options.document || root.document, Encoder = options.QRCode || root.QRCode;
        if (!document || typeof document.createElement !== 'function') throw new Error('A browser document is required.');
        var snapshot = validate(details, clockNow(options)), partial = details.status === 'partial', rows = matrix(snapshot.uri, document, Encoder);
        var modulePixels = Math.floor(QR_BOX.size / (rows.length + QUIET_MODULES * 2));
        if (modulePixels < 4) throw new Error('The QR code is too dense for a readable terminal image.');
        var qrSize = (rows.length + QUIET_MODULES * 2) * modulePixels;
        var qrX = QR_BOX.x + Math.floor((QR_BOX.size - qrSize) / 2), qrY = QR_BOX.y + Math.floor((QR_BOX.size - qrSize) / 2);
        var canvas = document.createElement('canvas'); canvas.width = WIDTH; canvas.height = HEIGHT;
        var context = canvas.getContext('2d', { alpha: false });
        if (!context) throw new Error('Canvas rendering is unavailable.');
        context.imageSmoothingEnabled = false;
        context.fillStyle = '#ffffff'; context.fillRect(0, 0, WIDTH, HEIGHT);
        context.textAlign = 'left'; context.textBaseline = 'alphabetic';
        fitText(context, 'WARDS ACE', 32, 45, 340, 28, 28, '700', '#b92320');
        fitText(context, 'PAY WITH MONERO', 462, 42, 306, 18, 18, '700', '#242c30');
        context.fillStyle = '#e6e8e9'; context.fillRect(32, 65, 736, 1);
        context.fillStyle = '#000000';
        for (var row = 0; row < rows.length; row++) for (var column = 0; column < rows.length; column++) {
            if (rows[row][column]) context.fillRect(qrX + (column + QUIET_MODULES) * modulePixels, qrY + (row + QUIET_MODULES) * modulePixels, modulePixels, modulePixels);
        }
        var fiatText = '$' + snapshot.fiat.replace(/\B(?=(\d{3})+(?!\d))/g, ','), amountLabel = partial ? 'REMAINING TO PAY' : 'SEND EXACTLY';
        fitText(context, 'Sale total', 440, 122, 328, 20, 20, '400', '#545c60');
        var fiatFont = fitText(context, fiatText, 440, 180, 328, 46, 26, '700', '#172126');
        context.fillStyle = '#e6e8e9'; context.fillRect(440, 207, 328, 1);
        fitText(context, amountLabel, 440, 245, 328, 17, 17, '700', '#545c60');
        var dueFont = fitText(context, snapshot.due, 440, 293, 328, 36, 20, '700', '#172126');
        fitText(context, 'XMR', 440, 329, 328, 25, 25, '700', '#b92320');
        if (partial) fitText(context, 'Partial payment received', 440, 368, 328, 18, 18, '400', '#545c60');
        fitText(context, 'Scan with your Monero wallet', 440, 421, 328, 19, 16, '400', '#242c30');
        var id = assetId(), blob = await exportImage(canvas, mimeType);
        // Encoding is asynchronous. Do not return an image whose source aged out while exporting.
        var finishedAt = clockNow(options);
        if (finishedAt < snapshot.checkedAt || finishedAt - snapshot.checkedAt > 15000 || finishedAt >= snapshot.expiresAt) throw new Error('The payment snapshot expired while rendering. Refresh it before generating another image.');
        return Object.freeze({
            assetId: id, canvas: canvas, blob: blob, mimeType: mimeType, extension: mimeType === 'image/jpeg' ? 'jpg' : 'png', width: WIDTH, height: HEIGHT,
            snapshot: snapshot, partial: partial,
            text: Object.freeze({ fiat: fiatText, xmr: snapshot.due, amountLabel: amountLabel }),
            qr: Object.freeze({ x: qrX, y: qrY, size: qrSize, moduleCount: rows.length, modulePixels: modulePixels, quietModules: QUIET_MODULES, errorCorrection: 'M' }),
            typography: Object.freeze({ fiatPixels: fiatFont, xmrPixels: dueFont })
        });
    }
    function matchesCurrent(image, details, options) {
        try {
            var current = validate(details, clockNow(options)), saved = image && image.snapshot;
            return !!(saved && current.token === saved.token && current.uri === saved.uri && current.due === saved.due && current.fiat === saved.fiat && (details.status === 'partial') === image.partial);
        } catch (_) { return false; }
    }
    return { render: render, matchesCurrent: matchesCurrent };
});
