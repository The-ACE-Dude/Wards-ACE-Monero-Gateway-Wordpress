/* Pure, bounded file-protocol helpers; no serial access or automatic installation.
 * Candidate for Lane/7000 UPP 5.08.05-0010, NOT physically verified.
 * Ingenico RBA Developer's Guide Rev 17.6: pp.27,240,388-398,798,885.
 * The p.390-391 Binary label is misleading: Ingenico FormBuilder's own
 * FileWriteCommand appends ASCII digits for these flags. Standard HOST writes
 * use ASCII '1'/'0' (0x31/0x30), never raw 0x01/0x00.
 * Only dedicated WARDXMR files are supported. No ACE assets, packages, reboot,
 * payment configuration, filesystem paths, or firmware interfaces are exposed.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.MoneroPosFile = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';
    var IMAGE_NAME = 'WARDXMR.JPG', FORM_NAME = 'WARDXMR.K3Z', FRAME_LIMIT = 247;
    var IMAGE_LIMIT = 256 * 1024, FORM_LIMIT = 8 * 1024;
    function requireBytes(bytes) {
        if (!(bytes instanceof Uint8Array)) throw new TypeError('File data must be a Uint8Array.');
    }
    function ascii(text) {
        var bytes = [];
        for (var i = 0; i < text.length; i++) bytes.push(text.charCodeAt(i));
        return bytes;
    }
    function frame(payload) {
        if (payload.length + 3 > FRAME_LIMIT) throw new Error('File frame exceeds 247 bytes.');
        var bytes = new Uint8Array(payload.length + 3), check = 3;
        bytes[0] = 2;
        for (var i = 0; i < payload.length; i++) { bytes[i + 1] = payload[i]; check ^= payload[i]; }
        bytes[bytes.length - 2] = 3;
        bytes[bytes.length - 1] = check;
        return bytes;
    }
    function fileLimit(name) {
        if (name === IMAGE_NAME) return IMAGE_LIMIT;
        if (name === FORM_NAME) return FORM_LIMIT;
        throw new Error('Only WARDXMR.JPG and WARDXMR.K3Z may be written.');
    }

    function generateFileFrames(name, data, options) {
        var fast = !!(options && options.fast === true && name === IMAGE_NAME);
        var limit = fileLimit(name);
        requireBytes(data);
        if (!data.length || data.length > limit) throw new RangeError('Invalid file size for ' + name + '.');
        var result = [], offset = 0, segment = 1;
        while (offset < data.length) {
            var first = result.length === 0;
            var segmentText = String(segment).padStart(2, '0');
            var payload = ascii('62.' + (first ? '1' : '2') + '8' + segmentText + '0000');
            payload.push(0x31, fast ? 0x31 : 0x30); // Vendor ASCII flags; fast JPG mode ACKs intermediate packets.
            if (first) payload = payload.concat(ascii(name), [28]);
            var start = offset;
            while (offset < data.length) {
                var value = data[offset], escaped = value < 32 || value === 255;
                if (payload.length + (escaped ? 2 : 1) + 3 > FRAME_LIMIT) break;
                if (value < 32) payload.push(255, value + 32);
                else if (value === 255) payload.push(255, 255);
                else payload.push(value);
                offset++;
            }
            var last = offset === data.length;
            if (last) payload[3] = first ? 48 : 51; // Single record 0, or final record 3.
            result.push({
                bytes: frame(payload),
                label: '62. ' + name + ' segment ' + segmentText + ' (' + (offset - start) + ' bytes' + (last ? ', final' : '') + ')',
                offset: start,
                length: offset - start,
                last: last
            });
            segment = segment === 99 ? 1 : segment + 1;
        }
        return result;
    }

    function crc32(bytes) {
        requireBytes(bytes);
        var value = 0xffffffff;
        for (var i = 0; i < bytes.length; i++) {
            value ^= bytes[i];
            for (var bit = 0; bit < 8; bit++) value = (value >>> 1) ^ ((value & 1) ? 0xedb88320 : 0);
        }
        return ((value ^ 0xffffffff) >>> 0).toString(16).padStart(8, '0');
    }
    function parseSize(text) {
        if (!/^[0-9]{1,16}$/.test(text)) throw new Error('Invalid terminal file size.');
        var size = Number(text);
        if (!Number.isSafeInteger(size)) throw new Error('Invalid terminal file size.');
        return size;
    }

    // Input is an LRC-validated application body, never a transport ACK.
    // Pass the original byte length for the final record; omit it otherwise.
    function parseFileWriteReply(body, expectedFinalSize) {
        if (typeof body !== 'string' || !/^62\.[012389][0-9]*$/.test(body)) throw new Error('Invalid file-write response; a transport ACK is not completion.');
        var status = body.charAt(3);
        if (status !== '0') {
            var errors = { '1': 'record out of order', '2': 'file input/output error', '3': 'file data error', '8': 'unzip error', '9': 'file transfer aborted' };
            throw new Error('Terminal file write failed: ' + errors[status] + ' (62.' + status + ').');
        }
        var final = expectedFinalSize !== undefined && expectedFinalSize !== null;
        if (final && (!Number.isSafeInteger(expectedFinalSize) || expectedFinalSize < 1 || expectedFinalSize > IMAGE_LIMIT)) throw new RangeError('Invalid expected file size.');
        var digits = body.slice(4);
        if (!final) {
            if (digits) throw new Error('Unexpected final size on an intermediate file-write response.');
            return { ok: true, final: false, size: null };
        }
        var size = parseSize(digits);
        if (size !== expectedFinalSize) throw new Error('Terminal file size does not match the uploaded file.');
        return { ok: true, final: true, size: size };
    }
    function parseFindFileReply(body) {
        if (typeof body !== 'string') throw new Error('Invalid find-file response.');
        if (/^63\.1(?:0)?(?:\x1c0)?$/.test(body)) return { found: false, size: null, crc32: null };
        var match = /^63\.0([0-9]{1,16})(?:\x1c([0-9a-fA-F]{1,8}))?$/.exec(body);
        if (!match) throw new Error('Invalid find-file response.');
        return { found: true, size: parseSize(match[1]), crc32: match[2] ? match[2].toLowerCase().padStart(8, '0') : null };
    }
    function buildDeleteImageFrame() {
        if (arguments.length) throw new Error('The image deletion target is fixed.');
        var body = '64./HOST/' + IMAGE_NAME;
        return { bytes: frame(ascii(body)), label: body };
    }

    function candidateFormBytes() {
        // A separate, fixed-name form; the handoff's ACE form references this
        // template. FormBuilder documents built-in runtime templates, so a
        // standalone HTM file lookup is not a valid prerequisite.
        // This candidate has not run on hardware.
        // K3Z loading/reboot and image replacement/cache behavior need validation.
        var form = "<Form x='0' y='0' width='800' height='480' template='TEMPLLIMG.HTM' backgroundcolor='FFFFFF' timeout='12' enterenabled='false' entertone='0' clearenabled='false' cleartone='0' cancelenabled='false' canceltone='0' tonetype='0' f1enabled='false' f2enabled='false' f3enabled='false' f4enabled='false' />\n" +
            "<Image image='WARDXMR.JPG' hostimage='.\\WARDXMR.JPG' id='image1' x='0' y='0' width='800' height='480' />\n";
        return new Uint8Array(ascii(form));
    }

    return Object.freeze({
        PROTOCOL_LABEL: 'RBA62 encoding=8 unpack=ASCII1 frameMax=247',
        IMAGE_NAME: IMAGE_NAME, FORM_NAME: FORM_NAME,
        IMAGE_LIMIT: IMAGE_LIMIT, FORM_LIMIT: FORM_LIMIT,
        generateFileFrames: generateFileFrames,
        crc32: crc32,
        parseFileWriteReply: parseFileWriteReply,
        parseFindFileReply: parseFindFileReply,
        buildDeleteImageFrame: buildDeleteImageFrame,
        candidateFormBytes: candidateFormBytes
    });
});
