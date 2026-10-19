/* Read-only, bounded form discovery. No serial access, writes, aliases or templates.
 * Ingenico RBA Developer's Guide Rev 17.6, sections 6.2.49 and 6.2.51, pp.395-400.
 * The 65 request table says type=start 1/next 0, format=plain 0/base64 1.
 * Its 65.01 worked example instead returns plain text: that contradiction is NOT
 * treated as permission to silently switch wire formats. Replies must say base64.
 * The guide does not identify the scope/algorithm of the 65 CRC field. This helper
 * matches command 63's documented complete-file CRC32 and size when available.
 * Without 63 it requires a consistent 65 CRC32 matching complete decoded content.
 * An incompatible 65 implementation
 * fails explicitly; these helpers alone do not prove UPP compatibility.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.MoneroPosDiscovery = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';
    var FS = String.fromCharCode(28), MAX_BYTES = 64 * 1024, MAX_BLOCKS = 512, MAX_BODY = 8192;
    var ALLOWED_PATHS = Object.freeze(['manifestfrm.txt', '/HOST/MSG.K3Z', '/HOST/OFFLINE.K3Z']);
    function problem(code, message) { var error = new Error(message); error.code = code; return error; }
    function allowed(path) {
        if (ALLOWED_PATHS.indexOf(path) === -1) throw problem('PATH_NOT_ALLOWED', 'Read-only discovery accepts only manifestfrm.txt, /HOST/MSG.K3Z and /HOST/OFFLINE.K3Z.');
        return path;
    }
    function buildReadRequest(path, first) {
        allowed(path);
        if (typeof first !== 'boolean') throw problem('INVALID_REQUEST', 'Specify whether this is the first read block.');
        return '65.' + (first ? '11' : '01') + path;
    }
    function crc32(bytes) {
        if (!(bytes instanceof Uint8Array)) throw new TypeError('CRC input must be bytes.');
        var crc = 0xffffffff;
        for (var i = 0; i < bytes.length; i++) {
            crc ^= bytes[i];
            for (var b = 0; b < 8; b++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
        }
        return ((crc ^ 0xffffffff) >>> 0).toString(16).padStart(8, '0');
    }
    function decodeBase64(text) {
        if (typeof text !== 'string' || text.length > MAX_BODY || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(text)) {
            throw problem('INVALID_BASE64', 'The terminal returned invalid or incomplete base64 data.');
        }
        var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', bytes = [];
        for (var i = 0; i < text.length; i += 4) {
            var a = alphabet.indexOf(text[i]), b = alphabet.indexOf(text[i + 1]);
            var c = text[i + 2] === '=' ? 0 : alphabet.indexOf(text[i + 2]);
            var d = text[i + 3] === '=' ? 0 : alphabet.indexOf(text[i + 3]);
            if ((text[i + 2] === '=' && (b & 15)) || (text[i + 3] === '=' && text[i + 2] !== '=' && (c & 3))) {
                throw problem('INVALID_BASE64', 'The terminal returned noncanonical base64 padding.');
            }
            bytes.push((a << 2) | (b >>> 4));
            if (text[i + 2] !== '=') bytes.push(((b & 15) << 4) | (c >>> 2));
            if (text[i + 3] !== '=') bytes.push(((c & 3) << 6) | d);
        }
        return new Uint8Array(bytes);
    }
    // Accept only the application body after the serial layer validates its LRC.
    function parseReadReply(body) {
        if (typeof body !== 'string' || body.length > MAX_BODY) throw problem('INVALID_RESPONSE', 'Missing or oversized file-read response.');
        var failure = /^65\.([123])(?:0*\x1c0*\x1c[0-9a-fA-F]{1,8}\x1c[01]\x1c)?$/.exec(body);
        if (failure) {
            if (failure[1] === '1') return { found: false, status: 'not_found' };
            if (failure[1] === '2') throw problem('BASE64_UNSUPPORTED', 'Terminal could not convert this file to base64 (65.2); this does not mean the file is missing.');
            throw problem('FORMAT_CHANGE', 'Terminal rejected a data-format change (65.3); this does not mean the file is missing.');
        }
        var match = /^65\.0([0-9]{1,6})\x1c([0-9]{1,6})\x1c([0-9a-fA-F]{1,8})\x1c([01])\x1c([\s\S]*)$/.exec(body);
        if (!match) throw problem('UNSUPPORTED_RESPONSE', 'Unrecognized 65 file-read response; cannot determine whether the file exists.');
        var total = Number(match[1]), number = Number(match[2]);
        if (total < 1 || total > MAX_BLOCKS || number < 1 || number > total) throw problem('INVALID_METADATA', 'Terminal returned an invalid or excessive file block count.');
        if (match[4] !== '1') throw problem('UNSUPPORTED_FORMAT', 'Terminal returned plain text after a base64 request. The guide has contradictory examples; no format fallback was attempted.');
        return { found: true, totalBlocks: total, blockNumber: number, crc32: match[3].toLowerCase().padStart(8, '0'), format: 1, data: decodeBase64(match[5]) };
    }
    function asciiText(bytes) {
        var text = '';
        for (var i = 0; i < bytes.length; i++) {
            var byte = bytes[i];
            if (!(byte === 9 || byte === 10 || byte === 13 || (byte >= 32 && byte <= 126))) {
                throw problem('NON_TEXT_FILE', 'The retrieved file is not the documented ASCII form text; no interpretation was attempted.');
            }
            text += String.fromCharCode(byte);
        }
        return text;
    }
    function createTransfer(path, options) {
        allowed(path); options = options || {};
        var expectedSize = options.expectedSize, expectedCrc = options.expectedCrc32;
        if (expectedSize !== undefined && (!Number.isSafeInteger(expectedSize) || expectedSize < 0 || expectedSize > MAX_BYTES)) throw problem('INVALID_EXPECTATION', 'Invalid independent file-size expectation.');
        if (expectedCrc !== undefined && (typeof expectedCrc !== 'string' || !/^[0-9a-fA-F]{1,8}$/.test(expectedCrc))) throw problem('INVALID_EXPECTATION', 'Invalid independent CRC32 expectation.');
        if (expectedCrc !== undefined) expectedCrc = expectedCrc.toLowerCase().padStart(8, '0');
        if ((expectedSize === undefined) !== (expectedCrc === undefined)) throw problem('INVALID_EXPECTATION', 'Independent verification requires both file size and CRC32.');
        var parts = [], responseCrcs = [], length = 0, total = null, advertisedCrc = null, finished = null, fault = null, outstanding = false;
        function ensure() { if (fault) throw fault; if (finished) throw problem('TRANSFER_FINISHED', 'This file-read transfer is already complete.'); }
        function request() { ensure(); if (outstanding) throw problem('REQUEST_PENDING', 'Await the previous file-read response before requesting another block.'); outstanding = true; return buildReadRequest(path, parts.length === 0); }
        function accept(body) {
            ensure();
            try {
                if (!outstanding) throw problem('UNSOLICITED_BLOCK', 'No file-read request is awaiting this response.');
                outstanding = false;
                var parsed = parseReadReply(body);
                if (!parsed.found) {
                    if (parts.length) throw problem('FILE_DISAPPEARED', 'Terminal reported file not found after starting its transfer.');
                    finished = Object.freeze({ path: path, found: false, status: 'not_found', verified: false });
                    return finished;
                }
                if (parsed.blockNumber !== parts.length + 1) throw problem('BLOCK_ORDER', 'File block is missing, repeated or out of order.');
                if (total !== null && (total !== parsed.totalBlocks || (expectedCrc === undefined && advertisedCrc !== parsed.crc32))) throw problem('METADATA_CHANGED', 'Terminal file-read metadata changed during transfer; CRC scope may be incompatible.');
                total = parsed.totalBlocks; advertisedCrc = parsed.crc32;
                responseCrcs.push(parsed.crc32);
                length += parsed.data.length;
                if (length > MAX_BYTES || (expectedSize !== undefined && length > expectedSize)) throw problem('FILE_TOO_LARGE', 'Retrieved file exceeds its size bound.');
                if (!parsed.data.length && (parsed.totalBlocks !== 1 || expectedSize > 0)) throw problem('EMPTY_BLOCK', 'Unexpected empty block in terminal file transfer.');
                parts.push(parsed.data);
                if (parts.length !== total) return { found: true, complete: false, blocksReceived: parts.length, totalBlocks: total, size: length };
                var bytes = new Uint8Array(length), offset = 0;
                parts.forEach(function (part) { bytes.set(part, offset); offset += part.length; });
                var actualCrc = crc32(bytes);
                if (expectedCrc === undefined && actualCrc !== advertisedCrc) throw problem('CRC_MISMATCH', 'Complete file CRC32 does not match the 65 response. Data is corrupt or this terminal uses an incompatible CRC convention.');
                if (expectedSize !== undefined && (length !== expectedSize || actualCrc !== expectedCrc)) throw problem('INDEPENDENT_MISMATCH', 'Retrieved file does not match the independent 63 size and CRC32.');
                finished = Object.freeze({ path: path, found: true, complete: true, verified: true, verification: expectedSize === undefined ? '65-complete-crc32' : '63-size-crc32', size: length, crc32: actualCrc, responseCrc32: Object.freeze(responseCrcs.slice()), blocks: total, bytes: bytes, text: asciiText(bytes) });
                parts = [];
                return finished;
            } catch (error) { fault = error; parts = []; throw error; }
        }
        function result() { if (fault) throw fault; if (!finished) throw problem('INCOMPLETE_TRANSFER', 'File read is incomplete; no content is available.'); return finished; }
        return Object.freeze({ request: request, accept: accept, result: result });
    }
    // Inert diagnostics only: never insert returned terminal text into HTML or eval.
    // This intentionally records exact quoted attributes; it is not an XML validator.
    function summarizeForm(text) {
        if (typeof text !== 'string' || text.length > MAX_BYTES) throw problem('INVALID_FORM_TEXT', 'Form summary requires bounded text.');
        var tags = [], match, tagPattern = /<(Form|Image|QRImage)\b([^<>]*)\/?\s*>/g;
        while ((match = tagPattern.exec(text))) {
            if (tags.length >= 32) throw problem('TOO_MANY_ELEMENTS', 'Form contains too many display elements for a bounded summary.');
            var attrs = {}, attr, pattern = /([A-Za-z][A-Za-z0-9_-]*)\s*=\s*(['"])(.*?)\2/g;
            while ((attr = pattern.exec(match[2]))) {
                if (['x','y','width','height','template','image','hostimage','id','timeout'].indexOf(attr[1]) !== -1) {
                    if (Object.prototype.hasOwnProperty.call(attrs, attr[1])) throw problem('DUPLICATE_ATTRIBUTE', 'Form contains duplicate diagnostic attributes.');
                    attrs[attr[1]] = attr[3];
                }
            }
            tags.push({ tag: match[1], attributes: attrs });
        }
        return Object.freeze({ elements: tags, hasForm: tags.some(function (tag) { return tag.tag === 'Form'; }), interpretation: 'Exact quoted diagnostic attributes only; no compatibility inference.' });
    }
    return Object.freeze({ ALLOWED_PATHS: ALLOWED_PATHS, MAX_BYTES: MAX_BYTES, MAX_BLOCKS: MAX_BLOCKS, buildReadRequest: buildReadRequest, parseReadReply: parseReadReply, createTransfer: createTransfer, summarizeForm: summarizeForm, crc32: crc32 });
});
