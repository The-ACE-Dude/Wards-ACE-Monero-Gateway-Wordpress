/* Optional Web Serial display. No payment, reset, firmware or ACE-logo commands.
 * Manual JPEG setup writes only the dedicated WARDXMR form/image, with CRC checks.
 * RBA guide 17.6 pp.48,239-240,258-259,317-321,357-358,395-397,745; UPP QR compatibility needs physical verification.
 * https://developer.chrome.com/docs/capabilities/serial
 */
(function (root, factory) {
    'use strict';
    var api = factory(root);
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.MoneroPosTerminal = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function (root) {
    'use strict';
    var FS = String.fromCharCode(28), DISPLAY_MS = 12000, FRESH_MS = 15000, ACK_MS = 3000, RESPONSE_MS = 4000;
    var DIAGNOSTIC_VERSION = '4.2.8';
    function idleStatus(body) { return /^11\.(00|15|99)/.test(body); }
    function stateError(body) {
        var code = body.slice(3, 5), names = {'01':'waiting for a card','02':'transaction selection','03':'PIN entry','04':'amount entry','05':'processing','06':'approval/decline','10':'signature','12':'input capture','13':'input available','16':'menu','17':'textbox','18':'EMV'};
        return new Error('COM port opened, but terminal reports 11.' + code + ' (' + (names[code] || 'non-idle state') + '). ' +
            (code === '01' ? 'With Eagle closed and no card payment in progress, use Close leftover card screen & connect.' : 'Finish the terminal activity before connecting.'));
    }

    function frame(body) {
        if (typeof body !== 'string' || /[^\x1c\x20-\x7e]/.test(body)) throw new Error('Invalid terminal command characters.');
        if (body.length + 3 > 247) throw new Error('Terminal command exceeds 247 bytes.');
        var out = new Uint8Array(body.length + 3), check = 3;
        out[0] = 2;
        for (var i = 0; i < body.length; i++) { out[i + 1] = body.charCodeAt(i); check ^= out[i + 1]; }
        out[out.length - 2] = 3; out[out.length - 1] = check;
        return out;
    }
    function decoder(emit) {
        var body = [], active = false, checksum = false, check = 0;
        return function (bytes) {
            for (var i = 0; i < bytes.length; i++) {
                var b = bytes[i];
                if (checksum) {
                    active = checksum = false;
                    if (b !== check) { body = []; emit('fault', new Error('Terminal response checksum failed.')); return; }
                    emit('frame', String.fromCharCode.apply(null, body)); body = [];
                } else if (active) {
                    check ^= b;
                    if (b === 3) checksum = true;
                    else body.push(b);
                    if (body.length > 8192) { active = false; body = []; emit('fault', new Error('Oversized terminal response.')); return; }
                } else if (b === 2) { active = true; check = 0; body = []; }
                else if (b === 6) emit('ack');
                else if (b === 21) emit('fault', new Error('Terminal rejected the command (NAK).'));
            }
        };
    }
    function profileValid(p) {
        if (p && p.verified === true && p.mode === 'jpeg') return p.form === 'WARDXMR.K3Z';
        if (p && p.mode && p.mode !== 'native') return false;
        return !!(p && p.verified === true && /^[A-Za-z0-9_-]{1,24}\.K3Z$/.test(p.form) &&
            /^[A-Za-z][A-Za-z0-9_]{0,31}$/.test(p.fiatVariable) && /^[A-Za-z][A-Za-z0-9_]{0,31}$/.test(p.dueVariable) && p.fiatVariable !== p.dueVariable);
    }
    function snapshot(d, now) {
        if (!freshProof(d, now) || !/^[a-f0-9]{64}$/.test(d.token || '') ||
            !Number.isFinite(Number(d.expires_in)) || Number(d.expires_in) <= 0 || !/^(unpaid|partial)$/.test(d.status)) return null;
        var match = /^monero:([123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{95}|[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{106})\?tx_amount=((?:0|[1-9][0-9]*)\.[0-9]{1,12})$/.exec(d.qrcode_uri || '');
        if (!match || !/[1-9]/.test(match[2]) || match[2] !== d.amount_due_formatted) return null;
        var usd = Number(d.fiat_total);
        if (!Number.isFinite(usd) || usd <= 0 || usd > 999999.99) return null;
        frame('70.TQRDATA,' + d.qrcode_uri);
        return { token: d.token, status: d.status, uri: d.qrcode_uri, due: d.amount_due_formatted, fiat: usd.toFixed(2),
            checkedAt: d.status_checked_at_ms, expiresAt: d.status_checked_at_ms + Number(d.expires_in) * 1000 };
    }
    function freshProof(d, now) {
        return !!(d && d.status_fresh === true && !d.warning && Number.isFinite(d.status_checked_at_ms) && now >= d.status_checked_at_ms && now - d.status_checked_at_ms <= FRESH_MS);
    }
    function unitInfo(body) {
        var fields = body.slice(3).split(FS);
        // 07 field 8 is application version; never accept an unrelated version-like field.
        if (fields[0] !== 'INGNAR' || !/^L7000(?:-|$)/.test(fields[1] || '') || fields[8] !== '5.08.05-0010') {
            throw new Error('Expected Lane/7000 UPP 5.08.05-0010. Received model ' + String(fields[1] || '(missing)').slice(0,40) + ', application field ' + String(fields[8] || '(missing)').slice(0,40) + '. Confirm the unit-information layout before using display commands.');
        }
        return { model: fields[1], version: fields[8] };
    }
    function create(options) {
        options = options || {};
        var serial = options.serial || (root.navigator && root.navigator.serial);
        var clock = options.clock || { now: function () { return Date.now(); }, setTimeout: root.setTimeout.bind(root), clearTimeout: root.clearTimeout.bind(root) };
        var profile = profileValid(options.profile) ? Object.assign({}, options.profile) : null;
        var files = options.files || root.MoneroPosFile;
        var discovery = options.discovery || root.MoneroPosDiscovery;
        function imageRenderer() { return options.images || root.MoneroPosImage; }
        var link = null, connected = false, connecting = null, closing = null, active = null, runner = null, verifiedPort = null;
        var connectingAttempt = null, pageExitRequested = false, pageExitPromise = null, pageExitReleasing = false;
        var retiringLinks = new Set(), pendingOpenAttempts = new Set();
        var latest = null, token = null, retired = new Set(), generation = 0, stopEpoch = 0, connectEpoch = 0, failed = false, lastUsed = -1, lastSeenAt = -1;
        var recoveryError = null, idleCleanup = null, stopping = null;
        var cachedImage = null, imageExpiryTimer = null;
        var diagnostics = [], diagnosticStartedAt = clock.now(), latchedError = null, lastGate = '';
        var lastState = 'disconnected', lastMessage = 'Terminal disconnected.';
        function diagnostic(message) {
            diagnostics.push('+' + Math.max(0, clock.now() - diagnosticStartedAt) + 'ms ' + message);
            if (diagnostics.length > 200) diagnostics.shift();
            if (options.onDiagnostic) { try { options.onDiagnostic(diagnostics.join('\n')); } catch (_) {} }
        }
        function commandLabel(body) {
            if (body.indexOf('70.TQRDATA,') === 0) return '70.TQRDATA,<redacted URI; ' + (body.length - 10) + ' bytes>';
            if (body === '24.MSG.K3Z' + FS + 'T1,hello world' + FS + 'TPROMPTLINE1,hello world' + FS + 't,12') return body.split(FS).join('[FS]');
            if (body.indexOf('24.') === 0) return body.split(FS)[0] + ' [bounded 12-second display]';
            if (/^63\.\/HOST\/(?:WARDXMR\.(?:JPG|K3Z)|(?:MSG|QRCODE|OFFLINE)\.K3Z)(?:\x1c1)?$/.test(body)) return body.split(FS).join('[FS]');
            if (/^65\.(?:11|01)(?:manifestfrm\.txt|\/HOST\/(?:MSG|OFFLINE)\.K3Z)$/.test(body)) return body;
            if (body === '62.9' || /^64\.\/HOST\/WARDXMR\.(?:JPG|K3Z)$/.test(body)) return body;
            return /^(11\.|07\.|00\.0000|34\.[SR])$/.test(body) ? body : 'Other display command (' + body.length + ' bytes)';
        }
        function responseLabel(body) {
            if (body.indexOf('07.') === 0) {
                var fields = body.slice(3).split(FS);
                function field(value) { return /^[A-Za-z0-9_.-]{1,40}$/.test(value || '') ? value : '(missing or unexpected)'; }
                return '07. model=' + field(fields[1]) + ', application=' + field(fields[8]);
            }
            if (/^11\.\d{2}/.test(body)) return body.slice(0, 5);
            if (/^00\.[0-9]{4}$/.test(body)) return body;
            if (/^34\.[019]$/.test(body)) return body;
            if (/^24\.[01689]/.test(body)) return body.slice(0, 4) + ' (' + body.length + ' bytes)';
            if (/^63\.[01][0-9]{0,12}(?:\x1c[0-9a-fA-F]{1,8})?$/.test(body)) return body.split(FS).join('[FS]');
            if (body.indexOf('65.') === 0) {
                var read = /^65\.([0-3])([0-9]{0,6})(?:\x1c([0-9]{1,6})\x1c([0-9a-fA-F]{1,8})\x1c([01])\x1c)/.exec(body);
                return read ? '65. result=' + read[1] + ', block=' + read[3] + '/' + read[2] + ', CRC=' + read[4] + ', format=' + read[5] + ' [content omitted]' : '65. file-read response (' + body.length + ' bytes; content omitted)';
            }
            if (/^62\.[012389][0-9]{0,12}$/.test(body) || /^64\.[012]$/.test(body)) return body;
            return 'Other response (' + body.length + ' bytes)';
        }
        function gated(message) { if (lastGate !== message) { diagnostic('DISPLAY GATE: ' + message); lastGate = message; } }
        function notify(state, message) {
            if (pageExitRequested && state !== 'error') {
                state = 'disconnected';
                message = 'Page exit released or is releasing the serial port. Screen restoration and JPG cleanup are unverified.';
            }
            if (state === 'error') latchedError = message;
            else if (latchedError) { state = 'error'; message = latchedError; }
            lastState = state; lastMessage = message;
            if (options.onState) { try { options.onState({ state: state, message: message, connected: connected, ready: isReady(), busy: !!(active || connecting || closing || idleCleanup || stopping || pageExitReleasing || pendingOpenAttempts.size) }); } catch (_) {} }
        }
        function bounded(promise, ms, message) {
            return new Promise(function (resolve, reject) {
                var timer = clock.setTimeout(function () { reject(new Error(message)); }, Math.max(0, ms));
                Promise.resolve(promise).then(function (v) { clock.clearTimeout(timer); resolve(v); }, function (e) { clock.clearTimeout(timer); reject(e); });
            });
        }
        function delay(ms) { return new Promise(function (resolve) { clock.setTimeout(resolve, Math.max(0, ms)); }); }
        function fresh(s) { return s && clock.now() >= s.checkedAt && clock.now() - s.checkedAt <= FRESH_MS && clock.now() < s.expiresAt; }
        function isReady() { return connected && !!profile && !!link && !link.fault() && !failed && !recoveryError && !closing && !idleCleanup && !stopping; }
        function jpegRuntime() { return !!(profile && profile.mode === 'jpeg'); }
        function sameImage(a, b) { return !!(a && b && a.token === b.token && a.uri === b.uri && a.due === b.due && a.fiat === b.fiat && a.status === b.status); }
        function clearImageExpiry() { if (imageExpiryTimer !== null) clock.clearTimeout(imageExpiryTimer); imageExpiryTimer = null; }
        function scheduleImageExpiry() {
            clearImageExpiry();
            if (!cachedImage || !sameImage(cachedImage.source, latest)) return;
            var owned = cachedImage, source = latest, deadline = Math.min(source.checkedAt + FRESH_MS + 1, source.expiresAt);
            imageExpiryTimer = clock.setTimeout(function () {
                imageExpiryTimer = null;
                if (cachedImage !== owned || !sameImage(owned.source, latest)) return;
                if (fresh(latest)) { scheduleImageExpiry(); return; }
                diagnostic('PAYMENT IMAGE EXPIRED: restoring and deleting the cached JPEG');
                stop('Payment status is no longer fresh. Restoring the screen and deleting the payment image.').catch(function () {});
            }, Math.max(0, deadline - clock.now()));
        }

        function makeLink(port) {
            var reader = port.readable.getReader(), writer = port.writable.getWriter(), writes = Promise.resolve();
            var frames = [], sequence = 0, acks = 0, fault = null, faultSequence = 0, listeners = new Set(), shutting = false, closePromise = null;
            var exitClosing = false, fastClosePromise = null, readerCancelPromise = null, writerAbortPromise = null, portClosePromise = null;
            function wake() { listeners.forEach(function (fn) { fn(); }); }
            function closedError() { var error = new Error('The serial connection is closing.'); error.cancelled = true; error.pageExit = exitClosing; return error; }
            function fail(error) {
                if (shutting) return;
                diagnostic('ERROR ' + error.message);
                fault = error; faultSequence++; failed = true; latest = null; generation++; wake();
                if (!shutting) notify('error', 'Terminal connection error: ' + error.message);
                Promise.resolve().then(cleanupIdleFault);
            }
            function write(bytes) {
                if (shutting) return Promise.reject(closedError());
                var next = writes.then(function () {
                    // Normal close drains writes already queued, including reply ACKs.
                    // Page exit invalidates queued writes before releasing the lock.
                    if (exitClosing) throw closedError();
                    return bounded(writer.write(bytes), 1000, 'Terminal write timed out.');
                });
                writes = next.catch(function () {});
                return next;
            }
            var parse = decoder(function (kind, value) {
                if (shutting) return;
                if (kind === 'ack') { acks++; diagnostic('RX ACK'); }
                else if (kind === 'fault') fail(value);
                else {
                    diagnostic('RX ' + responseLabel(value));
                    diagnostic('TX ACK');
                    write(new Uint8Array([6])).catch(fail);
                    frames.push({ sequence: ++sequence, body: value });
                    if (frames.length > 256) frames.shift();
                }
                wake();
            });
            var reading = (async function () {
                try {
                    while (!shutting) {
                        var chunk = await reader.read();
                        if (chunk.done) { if (!shutting) fail(new Error('Terminal disconnected.')); break; }
                        if (chunk.value && !shutting) parse(chunk.value);
                    }
                } catch (e) { if (!shutting) fail(e); }
                finally { try { reader.releaseLock(); } catch (_) {} }
            })();
            function find(after, pattern) { return frames.find(function (f) { return f.sequence > after && pattern.test(f.body); }); }
            function wait(check, ms, faultFloor) {
                return new Promise(function (resolve, reject) {
                    var timer;
                    function finish(error, value) { listeners.delete(tick); clock.clearTimeout(timer); if (error) reject(error); else resolve(value); }
                    function tick() {
                        if (shutting) return finish(closedError());
                        if (fault && (faultFloor === undefined || faultSequence > faultFloor)) return finish(fault);
                        var value = check(); if (value) finish(null, value);
                    }
                    timer = clock.setTimeout(function () { finish(new Error('Terminal response timed out.')); }, Math.max(0, ms));
                    listeners.add(tick); tick();
                });
            }
            async function exchangeBytes(bytes, label, pattern, recovery) {
                if (shutting) throw closedError();
                if (fault && !recovery) throw fault;
                var start = sequence, ackStart = acks, floor = recovery ? faultSequence : undefined;
                diagnostic('TX ' + label);
                try { await write(bytes); }
                catch (e) { throw new Error(label + ': write failed. ' + e.message); }
                // RBA pp.239-240 and 745: separate link and application deadlines.
                try { await wait(function () { return acks > ackStart; }, ACK_MS, floor); }
                catch (e) { diagnostic('ERROR ' + label + ': ACK wait failed.'); throw new Error(label + ': waiting for ACK failed. ' + e.message); }
                if (!pattern) return true;
                try { return await wait(function () { var found = find(start, pattern); return found && found.body; }, RESPONSE_MS, floor); }
                catch (e) { diagnostic('ERROR ' + label + ': application-response wait failed.'); throw new Error(label + ': waiting for application response failed. ' + e.message); }
            }
            function pause(ms) {
                return new Promise(function (resolve) {
                    var timer;
                    function done() { listeners.delete(interrupted); clock.clearTimeout(timer); resolve(); }
                    function interrupted() { if (exitClosing) done(); }
                    timer = clock.setTimeout(done, Math.max(0, ms));
                    listeners.add(interrupted); interrupted();
                });
            }
            function cancelReader() {
                if (!readerCancelPromise) {
                    try { readerCancelPromise = bounded(reader.cancel(), 1000, 'Reader cancellation timed out.'); }
                    catch (error) { readerCancelPromise = Promise.reject(error); }
                    readerCancelPromise.catch(function () {});
                }
                return readerCancelPromise;
            }
            function abortWriter() {
                if (!writerAbortPromise) {
                    try { writerAbortPromise = bounded(writer.abort(), 1000, 'Writer abort timed out.'); }
                    catch (error) { writerAbortPromise = Promise.reject(error); }
                    writerAbortPromise.catch(function () {});
                }
                return writerAbortPromise;
            }
            function closePort() {
                if (!portClosePromise) {
                    try { portClosePromise = bounded(port.close(), 1500, 'Port close timed out.'); }
                    catch (error) { portClosePromise = Promise.reject(error); }
                    portClosePromise.catch(function () {});
                }
                return portClosePromise;
            }
            function close(fast) {
                if (fast) {
                    if (fastClosePromise) return fastClosePromise;
                    exitClosing = shutting = true; wake();
                    // Initiate every native teardown step in this lifecycle task.
                    // No page-exit timer or terminal application reply is required.
                    var cancellations = [cancelReader(), abortWriter()];
                    try { reader.releaseLock(); } catch (_) {}
                    try { writer.releaseLock(); } catch (_) {}
                    var nativeClose = closePort();
                    fastClosePromise = nativeClose.then(function () { diagnostic('PORT CLOSED AFTER PAGE EXIT; terminal cleanup unverified'); });
                    Promise.allSettled(cancellations).catch(function () {});
                    fastClosePromise.catch(function () {});
                    return fastClosePromise;
                }
                if (fastClosePromise) return fastClosePromise;
                if (closePromise) return closePromise;
                shutting = true; wake();
                closePromise = (async function () {
                    var errors = [];
                    try { await cancelReader(); } catch (e) { errors.push(e); }
                    try { await bounded(reading, 1000, 'Reader shutdown timed out.'); } catch (e) { errors.push(e); }
                    try { reader.releaseLock(); } catch (_) {}
                    try { await bounded(writes, 1000, 'Writer shutdown timed out.'); } catch (e) { errors.push(e); }
                    try { await abortWriter(); } catch (e) { errors.push(e); }
                    try { writer.releaseLock(); } catch (e) { errors.push(e); }
                    try { await closePort(); } catch (e) { errors.push(e); }
                    if (exitClosing) return fastClosePromise;
                    if (errors.length) throw errors[0];
                    diagnostic('PORT CLOSED');
                })();
                return closePromise;
            }
            return {
                mark: function () { return sequence; },
                find: find,
                fault: function () { return fault; },
                exited: function () { return exitClosing; },
                pause: pause,
                drain: async function () {
                    // Let the continuous reader consume already-queued replies before setting a fresh command marker.
                    await pause(50);
                    if (shutting) throw closedError();
                    if (fault) throw fault;
                },
                exchange: function (body, pattern, recovery) { return exchangeBytes(frame(body), commandLabel(body), pattern, recovery); },
                fileExchange: function (packet, pattern, recovery) { return exchangeBytes(packet.bytes, packet.label, pattern, recovery); },
                completion: function (mark, deadline) {
                    return wait(function () { var f = find(mark, /^24\.[01689]/); return f && f.body; }, deadline - clock.now()).catch(function (e) {
                        throw new Error('24. display completion response failed. ' + e.message);
                    });
                },
                close: close
            };
        }
        async function drop() {
            clearImageExpiry(); cachedImage = null;
            var old = link; link = null; connected = false;
            if (old) {
                retiringLinks.add(old);
                try { await old.close(); } finally { retiringLinks.delete(old); }
            }
        }
        function cleanupIdleFault() {
            if (!link || active || connecting || closing || idleCleanup || stopping || !link.fault()) return;
            var error = link.fault(); connected = false;
            idleCleanup = handleFailure(error).finally(function () { idleCleanup = null; notify(lastState, lastMessage); });
            return idleCleanup;
        }
        function connect(connectionOptions) {
            var recoverCardReady = !!(connectionOptions && connectionOptions.recoverCardReady === true);
            if (connecting) return connecting;
            if (closing || idleCleanup || stopping || pageExitReleasing || pendingOpenAttempts.size || (pageExitRequested && (active || runner))) return Promise.reject(new Error('Wait for the terminal operation to finish.'));
            if (connected) return Promise.resolve();
            if (!serial || !serial.requestPort) { notify('unsupported', 'Use Chrome or Edge on the register to connect the terminal.'); return Promise.reject(new Error('Web Serial is unavailable.')); }
            latchedError = null; pageExitRequested = false; pageExitPromise = null;
            var chosen, epoch = ++connectEpoch;
            // Invoke synchronously from the click handler: no await precedes the browser chooser.
            try { chosen = connectionOptions && connectionOptions.reusePort && verifiedPort ? Promise.resolve(verifiedPort) : serial.requestPort(); } catch (e) { return Promise.reject(e); }
            var attempt = { cancelled: false, candidate: null, opened: false, session: null, closePromise: null, openingCleanup: null };
            connectingAttempt = attempt;
            function closeCandidate(fast) {
                if (attempt.session) return attempt.session.close(!!fast);
                if (!attempt.opened) return Promise.resolve();
                if (!attempt.closePromise) {
                    try { attempt.closePromise = bounded(attempt.candidate.close(), 1500, 'Port close timed out.'); }
                    catch (error) { attempt.closePromise = Promise.reject(error); }
                    attempt.closePromise.catch(function () {});
                }
                return attempt.closePromise;
            }
            attempt.cancel = function () {
                attempt.cancelled = true;
                if (!attempt.opened && attempt.openingCleanup) return bounded(attempt.openingCleanup, 5000, 'The pending port open has not finished closing.');
                return closeCandidate(true);
            };
            function ensureAttempt() {
                if (attempt.cancelled || epoch !== connectEpoch) { var error = new Error('Connection request was cancelled.'); error.cancelled = true; throw error; }
            }
            notify('connecting', 'Choose the Ingenico port after closing Eagle in both sessions.');
            connecting = (async function () {
                try {
                    attempt.candidate = await chosen;
                    ensureAttempt();
                    var opening = attempt.candidate.open({ baudRate: 115200, dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' });
                    pendingOpenAttempts.add(attempt);
                    attempt.openingCleanup = Promise.resolve(opening).then(function () {
                        attempt.opened = true;
                        if (attempt.cancelled || epoch !== connectEpoch) return closeCandidate(attempt.cancelled);
                    }, function () {}).finally(function () { pendingOpenAttempts.delete(attempt); notify(lastState, lastMessage); });
                    attempt.openingCleanup.catch(function () {});
                    await bounded(opening, 5000, 'Opening the terminal timed out.');
                    ensureAttempt();
                    attempt.session = makeLink(attempt.candidate); link = attempt.session;
                    diagnostic('TERMINAL ADAPTER ' + DIAGNOSTIC_VERSION);
                    diagnostic('PORT OPEN 115200 baud, 8N1, no flow control');
                    var initial = await attempt.session.exchange('11.', /^11\.\d{2}/);
                    ensureAttempt();
                    var unitChecked = false;
                    if (!idleStatus(initial) && recoverCardReady && /^11\.01/.test(initial)) {
                        unitInfo(await attempt.session.exchange('07.', /^07\./)); unitChecked = true;
                        ensureAttempt();
                        initial = await attempt.session.exchange('11.', /^11\.\d{2}/);
                        ensureAttempt();
                        if (/^11\.01/.test(initial)) {
                            diagnostic('EXPLICIT RECOVERY: closing the leftover card-ready lane with 00.0000');
                            // The guide permits no application reply when transitioning
                            // online to offline. Require ACK, then verify with status.
                            await attempt.session.exchange('00.0000');
                            ensureAttempt();
                            for (var retry = 0; retry < 3; retry++) {
                                var offline = await attempt.session.exchange('11.', /^(11\.[0-9]{2}|00\.[0-9]{4})/);
                                ensureAttempt();
                                if (offline === '00.0000' || /^11\.00/.test(offline)) { initial = '11.00'; break; }
                                if (!/^11\.01/.test(offline) || retry === 2) throw new Error('Lane close was sent, but offline state was not confirmed (' + responseLabel(offline) + '). Port will close; inspect the terminal.');
                                await attempt.session.pause(250); ensureAttempt();
                            }
                            diagnostic('RECOVERY VERIFIED: terminal is offline');
                        }
                    }
                    if (!idleStatus(initial)) throw stateError(initial);
                    if (!unitChecked) unitInfo(await attempt.session.exchange('07.', /^07\./));
                    ensureAttempt();
                    if (jpegRuntime()) {
                        requireImageTools();
                        if (!imageRenderer() || !imageRenderer().render) throw new Error('Reload the page to load the payment-image renderer.');
                        await verifyImageForm(attempt.session);
                    }
                    ensureAttempt();
                    failed = false; recoveryError = null; connected = true; verifiedPort = attempt.candidate;
                    notify(profile ? 'ready' : 'needs_setup', profile ? 'Terminal connected. Verified QR and amount display is ready.' : 'Terminal connected. Test the QR; amount display still needs a verified form.');
                } catch (e) {
                    var stale = attempt.cancelled || epoch !== connectEpoch;
                    if (!stale) connectEpoch++;
                    if (link === attempt.session) { link = null; connected = false; }
                    try { await closeCandidate(attempt.cancelled); } catch (_) {}
                    if (!stale) { connected = false; notify('error', e.message); throw e; }
                } finally {
                    if (connectingAttempt === attempt) { connectingAttempt = null; connecting = null; notify(lastState, lastMessage); }
                }
            })();
            return connecting;
        }
        async function cycle(s, probe, gen) {
            var session = link, saved = false, saveMark = -1, formMark = -1, deadline = 0, result = null, error = null, initial;
            if (!session) return { stopped: true, pageExit: pageExitRequested, qrVerified: false, amountsVerified: false, restored: false, displaySent: false };
            var textProbe = probe === 'text', imageProbe = probe === 'image', p = probe ? { form: textProbe ? 'MSG.K3Z' : (imageProbe ? 'WARDXMR.K3Z' : 'QRCODE.K3Z') } : profile;
            function allowed() { if (gen !== (textProbe ? stopEpoch : generation) || (!textProbe && !(imageProbe ? freshImageSource(s) : fresh(s))) || link !== session || closing) { var stopped = new Error('Display request was stopped or expired.'); stopped.cancelled = true; throw stopped; } }
            try {
                allowed();
                initial = await session.exchange('11.', /^11\.\d{2}/);
                if (!idleStatus(initial)) throw stateError(initial);
                allowed(); await session.drain(); allowed(); saveMark = session.mark();
                try { var save = await session.exchange('34.S', /^34\.[019]$/); }
                finally { saved = !!session.find(saveMark, /^34\.0$/); }
                if (save !== '34.0') throw new Error('Terminal did not confirm saving its screen.');
                allowed(); if (!textProbe && !imageProbe) await session.exchange('70.TQRDATA,' + s.uri);
                allowed();
                var command = '24.' + p.form;
                if (textProbe) command += FS + 'T1,hello world' + FS + 'TPROMPTLINE1,hello world';
                else if (!probe) command += FS + 'T' + p.fiatVariable + ',USD $' + s.fiat + FS + 'T' + p.dueVariable + ',XMR ' + s.due;
                command += FS + 't,12';
                frame(command); formMark = session.mark(); deadline = clock.now() + DISPLAY_MS + RESPONSE_MS;
                await session.exchange(command);
                notify(probe ? 'testing' : 'displaying', textProbe ? 'Text command accepted. Look for hello world for 12 seconds.' : (probe ? 'QR command accepted. Look for a QR code for 12 seconds; display and amounts still need visual verification.' : 'QR and amount display command accepted.'));
                var finished = await session.completion(formMark, deadline), exit = finished.charAt(3);
                if (exit === '1') throw new Error(p.form + ' is missing or invalid on this terminal.');
                if (exit === '6') throw new Error('The terminal rejected a form label.');
                if (exit === '9') throw new Error('The terminal declined the display request.');
                diagnostic('FORM FINISHED exit=' + exit + '; visual result not verified');
                result = { exit: exit, qrVerified: false, amountsVerified: !probe, restored: false, displaySent: true };
            } catch (e) { error = e; }
            finally {
                // No 15.6: a late cancel can affect another process. The form always has a finite timeout.
                if (!session.exited() && formMark >= 0 && !session.find(formMark, /^24\.[01689]/) && clock.now() < deadline) await session.pause(deadline - clock.now());
                if (saved && !session.exited()) {
                    try {
                        // Reader remains active; consume old buffered replies before the fresh restore request.
                        await session.pause(50);
                        if (session.exited()) throw new Error('Page exit interrupted screen restoration.');
                        var restore = await session.exchange('34.R', /^34\.[019]$/, true);
                        if (restore !== '34.0') throw new Error('Screen restoration failed: ' + restore + '.');
                        var finalState = await session.exchange('11.', /^11\.\d{2}/, true);
                        if (!idleStatus(finalState) || finalState.slice(3,5) !== initial.slice(3,5)) throw new Error('The terminal did not return to its original idle state.');
                        if (result) result.restored = true;
                    } catch (e) { if (!session.exited()) { error = new Error('Inspect the terminal before reopening Eagle: ' + e.message); recoveryError = error; } }
                }
            }
            if (session.exited()) return { stopped: true, pageExit: true, qrVerified: false, amountsVerified: false, restored: false, displaySent: formMark >= 0 };
            if (error && error.cancelled) { diagnostic('DISPLAY STOPPED before the form command'); return { stopped: true, qrVerified: false, amountsVerified: false, restored: saved, displaySent: false }; }
            if (error) throw error;
            return result;
        }
        function freshImageSource(s) {
            if (!s || s.token !== token || retired.has(s.token)) return null;
            var current = sameImage(latest, s) ? latest : s;
            return fresh(current) ? current : null;
        }
        function stoppedImage() {
            var error = new Error('JPEG operation stopped because the payment or connection changed.');
            error.cancelled = true;
            return error;
        }
        function requireImageTools() {
            if (!files || !files.generateFileFrames || !files.candidateFormBytes) throw new Error('Reload the page to load the JPEG file adapter.');
        }
        async function findFile(session, name, crc, recovery) {
            if (!/^WARDXMR\.(JPG|K3Z)$/.test(name)) throw new Error('Unexpected image setup filename.');
            return files.parseFindFileReply(await session.exchange('63./HOST/' + name + (crc ? FS + '1' : ''), /^63\./, recovery));
        }
        function matchesFile(found, data) { return found.found && found.size === data.length && found.crc32 === files.crc32(data); }
        async function verifyImageForm(session) {
            var expected = files.candidateFormBytes(), actual = await findFile(session, 'WARDXMR.K3Z', true);
            if (!matchesFile(actual, expected)) throw new Error(actual.found ? 'WARDXMR.K3Z does not match this site\'s image screen. It will not be overwritten.' : 'Prepare the JPEG screen, restart the terminal, then reconnect before testing.');
        }
        async function writeDedicatedFile(session, name, data, allowed) {
            var fast = name === 'WARDXMR.JPG';
            var packets = files.generateFileFrames(name, data, { fast: fast }), started = false, finished = false, deadline = clock.now() + 120000;
            var uploadMark = session.mark(), uploadStartedAt = clock.now();
            function checkTransferError() {
                var errorFrame = session.find(uploadMark, /^62\.[12389]/);
                if (errorFrame) throw new Error('File transfer rejected: ' + responseLabel(errorFrame.body));
            }
            var protocol = typeof files.PROTOCOL_LABEL === 'string' && /^[A-Za-z0-9 ._:()=+\/-]{1,100}$/.test(files.PROTOCOL_LABEL) ? files.PROTOCOL_LABEL : 'legacy file adapter';
            diagnostic('FILE UPLOAD ' + name + ': ' + data.length + ' data bytes in ' + packets.length + ' packets; ' + protocol + '; fast=ASCII' + (fast ? '1' : '0'));
            try {
                for (var i = 0; i < packets.length; i++) {
                    allowed();
                    if (fast) checkTransferError();
                    if (clock.now() >= deadline) throw new Error('JPEG file upload exceeded its two-minute limit.');
                    // STX through the two flags only. Filename and file content
                    // start after these 14 bytes and must never enter diagnostics.
                    var header = Array.from(packets[i].bytes.subarray(0, 14), function (byte) { return byte.toString(16).padStart(2, '0').toUpperCase(); }).join(' ');
                    if (i === 0) diagnostic('FILE HEADER ' + packets[i].label + ': ' + header);
                    started = true;
                    var reply = await session.fileExchange(packets[i], fast && !packets[i].last ? undefined : /^62\./);
                    if (fast) {
                        var rejected = session.find(uploadMark, /^62\.[12389]/);
                        if (rejected) reply = rejected.body;
                    }
                    try { if (reply !== true) files.parseFileWriteReply(reply, packets[i].last ? data.length : undefined); }
                    catch (parseError) {
                        if (i > 0) diagnostic('REJECTED FILE HEADER ' + packets[i].label + ': ' + header);
                        var responseCode = /^62\.[0-9]/.exec(reply);
                        var applicationError = new Error(packets[i].label + ': response ' + (responseCode ? responseCode[0] : '(unrecognized 62 response)') + '. ' + parseError.message);
                        applicationError.fileWriteResponseCode = responseCode ? responseCode[0] : null;
                        throw applicationError;
                    }
                    if (packets[i].last) finished = true;
                    if (i === 0 || i % 20 === 0 || packets[i].last) notify('testing', 'Uploading ' + name + ': ' + Math.round((packets[i].offset + packets[i].length) * 100 / data.length) + '%. Keep this page open.');
                }
                diagnostic('FILE TRANSFER FINISHED ' + name + ': ' + data.length + ' bytes in ' + (clock.now() - uploadStartedAt) + 'ms; final length acknowledged');
                allowed();
                var found = await findFile(session, name, true);
                if (!matchesFile(found, data)) throw new Error('The uploaded ' + name + ' failed the terminal size/CRC check. It will not be displayed.');
                diagnostic('FILE VERIFIED ' + name + ': ' + data.length + ' bytes, CRC matched');
            } catch (error) {
                if (started) error.ownedFileWriteStarted = name;
                if (started && !finished && !session.exited()) {
                    try {
                        // Abort only the file transfer initiated by this operation, never a tender/form.
                        var aborted = await session.exchange('62.9', /^62\./, true);
                        if (!/^62\.[09]$/.test(aborted)) {
                            var abortCode = /^62\.[0-9]/.exec(aborted);
                            throw new Error('62.9 abort returned ' + (abortCode ? abortCode[0] : 'an unsupported 62 response') + '.');
                        }
                    } catch (abortError) {
                        var combined = new Error(error.message + ' File transfer cleanup: ' + abortError.message);
                        combined.ownedFileWriteStarted = name;
                        throw combined;
                    }
                }
                throw error;
            }
        }
        async function deletePaymentImage(session) {
            var before = await findFile(session, 'WARDXMR.JPG', false, true);
            if (!before.found) return;
            var reply = await session.fileExchange(files.buildDeleteImageFrame(), /^64\.[012]$/, true);
            if (reply === '64.2' || (await findFile(session, 'WARDXMR.JPG', false, true)).found) throw new Error('The temporary payment JPG could not be deleted.');
            diagnostic('PAYMENT JPG DELETED');
        }
        async function clearCachedImage(session) {
            if (!cachedImage || cachedImage.session !== session) return;
            clearImageExpiry(); cachedImage = null;
            try { await deletePaymentImage(session); }
            catch (error) { recoveryError = new Error('Cached payment JPEG cleanup failed: ' + error.message); throw recoveryError; }
        }
        function installImageForm() {
            try { requireImageTools(); } catch (error) { return Promise.reject(error); }
            if (!connected || active || runner || closing || idleCleanup || stopping) return Promise.reject(new Error('Connect an idle terminal before preparing its JPEG screen.'));
            latchedError = null; latest = null; generation++;
            var session = link, epoch = stopEpoch;
            function allowed() { if (link !== session || closing || stopEpoch !== epoch) throw stoppedImage(); }
            var operation = (async function () {
                allowed();
                await clearCachedImage(session); allowed();
                var initial = await session.exchange('11.', /^11\.\d{2}/);
                if (!idleStatus(initial)) throw stateError(initial);
                // TEMPLLIMG.HTM selects the image template at runtime. A standalone
                // 63 lookup does not prove whether UPP can resolve that selector.
                allowed();
                var expected = files.candidateFormBytes(), found = await findFile(session, 'WARDXMR.K3Z', true);
                if (found.found) {
                    if (!matchesFile(found, expected)) throw new Error('An existing WARDXMR.K3Z differs from this site\'s screen. It will not be overwritten.');
                    return { requiresRestart: false, message: 'The dedicated JPEG screen is already installed. Test the payment JPG. If this is a new installation, restart the terminal first.' };
                }
                allowed();
                try { await writeDedicatedFile(session, 'WARDXMR.K3Z', expected, allowed); }
                catch (error) {
                    // The immediately preceding 63 proved absence. Only this upload
                    // may have created the form; never remove a pre-existing form.
                    if (error.ownedFileWriteStarted === 'WARDXMR.K3Z' && !session.exited()) {
                        try {
                            var removed = await session.exchange('64./HOST/WARDXMR.K3Z', /^64\.[012]$/, true);
                            var remains = await findFile(session, 'WARDXMR.K3Z', false, true);
                            if (removed === '64.2' || remains.found) throw new Error('The partial WARDXMR.K3Z could not be removed.');
                            diagnostic('INCOMPLETE OWNED JPEG FORM DELETED; prepare may be retried');
                        } catch (cleanupError) { throw new Error(error.message + ' Form rollback failed: ' + cleanupError.message); }
                    }
                    throw error;
                }
                return { requiresRestart: true, message: 'JPEG screen installed and checked. Restart the Ingenico manually, then reconnect and test the payment JPG. Eagle must remain closed.' };
            })();
            active = operation.then(function (result) { diagnostic(result.message); return result; }, async function (error) { await handleFailure(error); throw error; }).finally(function () { active = null; notify(lastState, lastMessage); cleanupIdleFault(); });
            notify('testing', 'Preparing the dedicated JPEG screen. ACE files will not be changed.');
            return active.then(async function (result) {
                if (result.requiresRestart) await disconnect('JPEG screen installed. Closing the port before the required manual restart.');
                notify(connected ? 'needs_setup' : 'disconnected', result.message);
                return result;
            });
        }
        async function imageCycle(s, gen, retain) {
            requireImageTools();
            var renderer = imageRenderer(), session = link;
            if (!renderer || !renderer.render) throw new Error('Reload the page to load the payment-image renderer.');
            function allowed() { if (gen !== generation || link !== session || closing || !freshImageSource(s)) throw stoppedImage(); }
            var imageTouched = false, result = null, problem = null;
            try {
                allowed();
                var initial = await session.exchange('11.', /^11\.\d{2}/);
                if (!idleStatus(initial)) throw stateError(initial);
                await verifyImageForm(session); allowed();
                if (cachedImage && cachedImage.session === session && sameImage(cachedImage.source, s)) {
                    imageTouched = true;
                    var existing = await findFile(session, 'WARDXMR.JPG', true);
                    if (!matchesFile(existing, cachedImage.data)) throw new Error('The cached payment JPG changed or disappeared. It will not be displayed.');
                    diagnostic('REUSING VERIFIED PAYMENT JPG: same sale and exact amounts');
                    allowed();
                } else {
                    await clearCachedImage(session); allowed();
                    var source = freshImageSource(s);
                    var picture = await renderer.render({ token: source.token, status: source.status, status_fresh: true,
                        status_checked_at_ms: source.checkedAt, expires_in: (source.expiresAt - source.checkedAt) / 1000,
                        qrcode_uri: source.uri, amount_due_formatted: source.due, fiat_total: source.fiat }, { format: 'image/jpeg', now: clock.now });
                    allowed();
                    if (!picture || picture.mimeType !== 'image/jpeg' || !picture.blob || !sameImage(picture.snapshot, s)) throw new Error('The rendered JPG does not match the current payment.');
                    var data = new Uint8Array(await picture.blob.arrayBuffer());
                    if (data.length < 4 || data.length > files.IMAGE_LIMIT || data[0] !== 255 || data[1] !== 216 || data[2] !== 255 || data[data.length - 2] !== 255 || data[data.length - 1] !== 217) throw new Error('The payment image is not a valid bounded JPEG file.');
                    allowed();
                    imageTouched = true;
                    await deletePaymentImage(session);
                    await writeDedicatedFile(session, 'WARDXMR.JPG', data, allowed);
                    allowed();
                    if (retain) cachedImage = { session: session, source: s, data: data };
                }
                if (retain) scheduleImageExpiry();
                result = await cycle(s, 'image', gen);
            } catch (error) { problem = error; }
            finally {
                var keep = retain && !problem && result && !result.stopped && gen === generation && link === session && !closing && freshImageSource(s);
                if (session.exited()) {
                    if (cachedImage && cachedImage.session === session) { cachedImage = null; clearImageExpiry(); }
                } else if (imageTouched && !keep) {
                    if (cachedImage && cachedImage.session === session) { cachedImage = null; clearImageExpiry(); }
                    try { await deletePaymentImage(session); if (result) result.imageDeleted = true; }
                    catch (cleanupError) { problem = new Error((problem ? problem.message + ' ' : '') + 'JPEG cleanup failed: ' + cleanupError.message); recoveryError = problem; }
                } else if (keep) {
                    result.imageDeleted = false;
                    scheduleImageExpiry();
                }
            }
            if (session.exited()) return { stopped: true, pageExit: true, displaySent: !!(result && result.displaySent), restored: false, imageDeleted: false };
            if (problem && problem.cancelled) return { stopped: true, displaySent: false, imageDeleted: imageTouched };
            if (problem) throw problem;
            return result;
        }
        function probeImage(details) {
            var s;
            try { requireImageTools(); s = snapshot(details, clock.now()); } catch (error) { return Promise.reject(error); }
            if (!connected || active || runner || closing || idleCleanup || stopping) return Promise.reject(new Error('Connect an idle terminal before testing the payment JPG.'));
            if (!s || retired.has(s.token)) return Promise.reject(new Error('Refresh an unpaid or partial payment before testing its JPG.'));
            var renderer = imageRenderer();
            if (!renderer || !renderer.render) return Promise.reject(new Error('Reload the page to load the payment-image renderer.'));
            latchedError = null;
            if (token !== s.token) { if (token) retired.add(token); lastSeenAt = -1; }
            token = s.token; latest = s; lastSeenAt = Math.max(lastSeenAt, s.checkedAt);
            var gen = ++generation, operation = imageCycle(s, gen, false);
            active = operation.then(function (result) {
                notify('needs_setup', result.stopped ? 'Payment JPG stopped before display; temporary image cleanup finished.' : 'JPG test finished. The screen was restored and the image deleted. Confirm that the QR and amounts appeared correctly.');
                return result;
            }, async function (error) { await handleFailure(error); throw error; }).finally(function () { active = null; notify(lastState, lastMessage); cleanupIdleFault(); });
            notify('testing', 'Preparing the payment JPG. Upload finishes before the 12-second display starts.');
            return active;
        }
        async function handleFailure(error) {
            if (pageExitRequested) return;
            diagnostic('ERROR ' + error.message);
            failed = true; latest = null; generation++;
            try { await clearCachedImage(link); } catch (cleanupError) { error = new Error(error.message + ' ' + cleanupError.message); recoveryError = error; }
            try { await drop(); } catch (closeError) { error = new Error(error.message + ' ' + closeError.message); recoveryError = error; }
            notify('error', error.message);
        }
        function probe(details) {
            var s;
            try { s = snapshot(details, clock.now()); } catch (e) { return Promise.reject(e); }
            if (!connected || active || runner || closing || idleCleanup || stopping) return Promise.reject(new Error('Connect an idle terminal before testing.'));
            if (!s || retired.has(s.token)) { diagnostic('QR TEST BLOCKED: request is not a fresh, current unpaid/partial snapshot with an exact-amount URI.'); return Promise.reject(new Error('Create or refresh an unpaid request before testing.')); }
            latchedError = null;
            if (token !== s.token) { if (token) retired.add(token); lastSeenAt = -1; }
            latest = null; token = s.token; lastSeenAt = Math.max(lastSeenAt, s.checkedAt); var gen = ++generation;
            var operation = (async function () { await clearCachedImage(link); return cycle(s, 'qr', gen); })();
            active = operation.then(function (r) { notify('needs_setup', r.stopped ? 'QR test stopped before the display command. Refresh the payment status and try again.' : 'Terminal reported the QR form finished and its state was restored. Confirm whether the QR actually appeared and scanned correctly.'); return r; }, async function (e) { await handleFailure(e); throw e; }).finally(function () { active = null; notify(lastState, lastMessage); cleanupIdleFault(); });
            notify('testing', 'Testing the existing QR form.');
            return active;
        }
        function probeText() {
            if (!connected || active || runner || closing || idleCleanup || stopping) return Promise.reject(new Error('Connect an idle terminal before testing.'));
            latchedError = null;
            latest = null;
            generation++;
            var gen = stopEpoch, operation = (async function () { await clearCachedImage(link); return cycle(null, 'text', gen); })();
            active = operation.then(function (r) {
                notify(profile ? 'ready' : 'needs_setup', r.stopped ? 'Text test stopped before the display command.' : 'Terminal reported the text form finished and its state was restored. Confirm whether hello world appeared.');
                return r;
            }, async function (e) { await handleFailure(e); throw e; }).finally(function () { active = null; notify(lastState, lastMessage); cleanupIdleFault(); });
            notify('testing', 'Testing the same hello world form used on this register.');
            return active;
        }
        function inspectForms() {
            if (!connected || active || runner || closing || idleCleanup || stopping) return Promise.reject(new Error('Connect an idle terminal before inspecting forms.'));
            if (cachedImage) return Promise.reject(new Error('Stop the payment display before inspecting forms.'));
            latchedError = null; latest = null;
            generation++;
            var session = link, gen = stopEpoch, inspectionDeadline = clock.now() + 30000;
            function allowed() { return stopEpoch === gen && link === session && !closing; }
            function stopped() { return { stopped: true, message: 'Form inspection stopped before completion.' }; }
            function readAllowed() {
                if (!allowed()) { var cancelled = new Error('Form inspection stopped before completion.'); cancelled.cancelled = true; throw cancelled; }
                // Do not begin another exchange unless its full ACK/response deadlines
                // fit. This avoids abandoning an in-flight read at the overall limit.
                if (clock.now() + ACK_MS + RESPONSE_MS > inspectionDeadline) throw new Error('Form readback reached its 30-second inspection limit.');
            }
            function lookupReply(reply) {
                var match = /^63\.0([0-9]{1,16})(?:\x1c([0-9a-fA-F]{1,8}))?$/.exec(reply);
                if (/^63\.1(?:0)?(?:\x1c0)?$/.test(reply)) return { found: false, size: null, crc32: null };
                if (!match || !Number.isSafeInteger(Number(match[1]))) throw new Error('63. Find File returned an unsupported response; file availability is unknown.');
                return { found: true, size: Number(match[1]), crc32: match[2] ? match[2].toLowerCase().padStart(8, '0') : null };
            }
            function safeElements(text) {
                return discovery.summarizeForm(text).elements.map(function (element) {
                    var attrs = {};
                    Object.keys(element.attributes).forEach(function (name) {
                        var value = element.attributes[name], numeric = /^(width|height|x|y|timeout)$/.test(name);
                        attrs[name] = (numeric ? /^[0-9]{1,5}$/ : /^[A-Za-z0-9_.\\/:-]{1,80}$/).test(value) ? value : '(omitted)';
                    });
                    return { tag: element.tag, attributes: attrs };
                });
            }
            async function readForm(path) {
                readAllowed();
                var found = lookupReply(await session.exchange('63.' + path + FS + '1', /^63\./));
                readAllowed();
                // 63 uppercases its filename. A negative lookup can miss a file
                // whose stored case differs; 65 still gets its exact allowed path.
                if (found.found && found.size > discovery.MAX_BYTES) throw new Error('Form readback exceeds the 64 KiB file limit.');
                var expectation = found.found && found.crc32 ? { expectedSize: found.size, expectedCrc32: found.crc32 } : {};
                var transfer = discovery.createTransfer(path, expectation), progress;
                do {
                    readAllowed();
                    progress = transfer.accept(await session.exchange(transfer.request(), /^65\./));
                    if (!allowed()) { var cancelled = new Error('Form inspection stopped before completion.'); cancelled.cancelled = true; throw cancelled; }
                } while (progress.found && !progress.complete);
                if (!progress.found) return { found: false, status: 'not_found_by_65', lookup: found };
                return { found: true, verified: true, size: progress.size, crc32: progress.crc32, blocks: progress.blocks,
                    verification: progress.verification, lookup: found, elements: safeElements(progress.text) };
            }
            var operation = (async function () {
                if (!allowed()) return stopped();
                readAllowed();
                var initial = await session.exchange('11.', /^11\.\d{2}/), result = {};
                if (!idleStatus(initial)) throw stateError(initial);
                for (var name of ['MSG.K3Z', 'QRCODE.K3Z']) {
                    if (!allowed()) return stopped();
                    readAllowed();
                    var found = lookupReply(await session.exchange('63./HOST/' + name, /^63\./));
                    result[name] = { found: found.found, size: found.size };
                    diagnostic('FILE ' + name + ': ' + (result[name].found ? 'found, ' + result[name].size + ' bytes' : 'not found by 63. Find File'));
                }
                result.message = 'Form lookup: MSG.K3Z ' + (result['MSG.K3Z'].found ? 'found' : 'not found by lookup') + '; QRCODE.K3Z ' + (result['QRCODE.K3Z'].found ? 'found. QR rendering still needs a visual test.' : 'not found by lookup; see the diagnostic log.');
                if (discovery && discovery.createTransfer && discovery.summarizeForm) {
                    result.readback = {};
                    for (var path of ['/HOST/OFFLINE.K3Z', '/HOST/MSG.K3Z']) {
                        if (!allowed()) return stopped();
                        try {
                            result.readback[path] = await readForm(path);
                            diagnostic('FORM READBACK ' + path + ': ' + JSON.stringify(result.readback[path]));
                        } catch (error) {
                            if (error.cancelled) return stopped();
                            result.readback[path] = { status: 'readback_error', message: error.message };
                            diagnostic('FORM READBACK ' + path + ': ' + error.message);
                            // An unanswered exchange can leave a late reply in flight.
                            // Preserve basic lookup results, and do not start a new read.
                            if (session.fault() || /wait|timed out|30-second/i.test(error.message)) break;
                        }
                    }
                    var errors = Object.keys(result.readback).filter(function (path) { return result.readback[path].status === 'readback_error'; });
                    result.message += errors.length ? ' Optional form readback was incomplete; see the terminal log.' : ' Optional form readback finished; see the terminal log.';
                }
                return result;
            })();
            active = operation.then(function (result) {
                notify(profile ? 'ready' : 'needs_setup', result.message);
                return result;
            }, async function (e) { await handleFailure(e); throw e; }).finally(function () { active = null; notify(lastState, lastMessage); cleanupIdleFault(); });
            notify('testing', 'Checking whether MSG.K3Z and QRCODE.K3Z are present.');
            return active;
        }
        function startRunner() {
            if (runner || active || !isReady() || !latest || !fresh(latest) || latest.checkedAt <= lastUsed) return;
            runner = (async function () {
                while (isReady() && latest && fresh(latest) && latest.checkedAt > lastUsed) {
                    var s = latest, gen = generation; lastUsed = s.checkedAt;
                    active = jpegRuntime() ? imageCycle(s, gen, true) : cycle(s, false, gen);
                    try { await active; } catch (e) { await handleFailure(e); break; } finally { active = null; cleanupIdleFault(); }
                }
                if (connected) notify('ready', 'Screen restored. Waiting for a fresh payment update.');
            })().finally(function () { runner = null; });
        }
        function update(details) {
            if (pageExitRequested) return;
            var incoming = details && details.token;
            if (incoming && retired.has(incoming)) return;
            if (incoming === token && Number.isFinite(details.status_checked_at_ms) && details.status_checked_at_ms < lastSeenAt) return;
            if (freshProof(details, clock.now()) && /^[a-f0-9]{64}$/.test(incoming || '') && /^(paid|confirmed|expired|expired_partial)$/.test(details.status)) {
                if (incoming) retired.add(incoming);
                if (token === null && /^(paid|confirmed)$/.test(details.status)) token = incoming;
                if (incoming === token) {
                    if (/^(paid|confirmed)$/.test(details.status)) {
                        diagnostic('FRESH PAYMENT RECEIVED: restore and automatically close the port');
                        return disconnect('Payment received. Restoring the screen and disconnecting automatically.');
                    }
                    return stop('Payment request ended.');
                }
                return;
            }
            var s;
            try { s = snapshot(details, clock.now()); } catch (_) { s = null; }
            if (!s) {
                gated(!freshProof(details, clock.now()) ? 'payment status is stale, missing, or has a warning' : 'request is not unpaid/partial, is expired, or lacks a valid exact-amount URI');
                if (!incoming || incoming === token) {
                    if (jpegRuntime() || cachedImage) return stop('Waiting for a fresh payment check. Restoring the screen and deleting the payment image.');
                    latest = null; generation++; notify('waiting', 'Waiting for a fresh payment check; the current screen will restore after its bounded display interval.');
                }
                return;
            }
            if (token !== s.token) { if (token) retired.add(token); token = s.token; latest = null; generation++; lastUsed = -1; lastSeenAt = -1; }
            if (latest && s.checkedAt <= latest.checkedAt) return;
            if (latest && (latest.uri !== s.uri || latest.fiat !== s.fiat || (jpegRuntime() && latest.status !== s.status))) generation++;
            lastSeenAt = s.checkedAt;
            latest = s;
            scheduleImageExpiry();
            if (!profile) { gated('automatic display has no verified QR and amount form; manual tests are available'); if (connected && !active) notify('needs_setup', 'QR test available. Automatic QR and amount display needs a verified form.'); return; }
            lastGate = '';
            startRunner();
        }
        function stop(reason) {
            if (pageExitRequested) return pageExitPromise || Promise.resolve();
            generation++; stopEpoch++; connectEpoch++; latest = null;
            clearImageExpiry();
            if (token && /^new[ _-]sale$/i.test(reason || '')) retired.add(token);
            notify('stopping', reason || 'Restoring the terminal after its bounded display interval.');
            if (stopping) return stopping;
            stopping = (async function () {
                await Promise.resolve(runner || active).catch(function () {});
                try {
                    await clearCachedImage(link);
                    if (recoveryError) throw recoveryError;
                    if (connected) notify('ready', 'Screen restored. You can disconnect and reopen Eagle.');
                } catch (error) { await handleFailure(error); throw recoveryError || error; }
            })().finally(function () {
                stopping = null; notify(lastState, lastMessage);
                if (!closing && !failed && !recoveryError) startRunner();
            });
            return stopping;
        }
        function disconnect(reason) {
            if (pageExitRequested) return pageExitPromise || Promise.resolve();
            if (closing) return closing;
            connectEpoch++;
            closing = (async function () {
                var problem = null;
                try { await stop(reason || 'Restoring the screen before disconnecting.'); } catch (e) { problem = e; }
                if (idleCleanup) await idleCleanup;
                try {
                    await drop();
                    if (recoveryError || problem) throw recoveryError || problem;
                    notify('disconnected', 'Terminal disconnected. Eagle can be reopened.');
                }
                catch (e) { recoveryError = e; notify('error', 'Check the terminal before reopening Eagle: ' + e.message); throw e; }
                finally { closing = null; notify(lastState, lastMessage); }
            })();
            return closing;
        }
        function releaseForPageExit() {
            if (pageExitRequested) return pageExitPromise;
            pageExitRequested = pageExitReleasing = true;
            generation++; stopEpoch++; connectEpoch++; latest = null;
            clearImageExpiry(); cachedImage = null;
            var session = link, attempt = connectingAttempt, resolveExit, rejectExit;
            link = null; connected = false; connectingAttempt = null; connecting = null;
            pageExitPromise = new Promise(function (resolve, reject) { resolveExit = resolve; rejectExit = reject; });
            // Callers may be leaving before promises settle. No rejection is left
            // unobserved, and the browser also tears down ports with its context.
            pageExitPromise.catch(function () {});
            diagnostic('PAGE EXIT: stop new commands and release COM ownership; screen restoration and JPG cleanup unverified');
            var releases = [];
            try { if (session) releases.push(session.close(true)); } catch (error) { releases.push(Promise.reject(error)); }
            retiringLinks.forEach(function (retiring) {
                try { releases.push(retiring.close(true)); } catch (error) { releases.push(Promise.reject(error)); }
            });
            try { if (attempt) releases.push(attempt.cancel()); } catch (error) { releases.push(Promise.reject(error)); }
            pendingOpenAttempts.forEach(function (pending) {
                if (pending === attempt) return;
                try { releases.push(pending.cancel()); } catch (error) { releases.push(Promise.reject(error)); }
            });
            Promise.all(releases).then(function () {
                pageExitReleasing = false;
                notify('disconnected', 'Serial port released after page exit. Screen restoration and JPG cleanup are unverified.');
                resolveExit({ released: true, restored: false, imageDeleted: false });
            }, function (error) {
                pageExitReleasing = false;
                notify('error', 'Page-exit port release could not be verified: ' + error.message);
                rejectExit(error);
            });
            notify('disconnected', 'Releasing the serial port after page exit.');
            return pageExitPromise;
        }
        return { connect: connect, probe: probe, probeText: probeText, inspectForms: inspectForms, installImageForm: installImageForm, probeImage: probeImage, update: update, stop: stop, disconnect: disconnect,
            releaseForPageExit: releaseForPageExit,
            getDiagnostics: function () { return diagnostics.join('\n'); },
            isConnected: function () { return connected; }, isReady: isReady };
    }
    return { create: create, _test: { frame: frame, decoder: decoder, snapshot: snapshot, unitInfo: unitInfo, profileValid: profileValid } };
});
