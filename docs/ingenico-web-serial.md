# Ingenico display on the in-store cashier page

## Current scope

Version 4.2.8 provides an optional direct Web Serial connection to `/monero-in-store/`.

### Automatic payment display and faster JPEG uploads (4.2.8)

The operator confirmed that JPG display works and requested automatic operation.
Enable the existing JPEG profile option for this register. Create payment request
starts connection synchronously so the browser can show its port chooser. A port
successfully verified earlier in this tab is reused, with device/form checks on
every connection. The explicit cashier request permits the same guarded
card-ready recovery below; Eagle must already be closed. No connection is
initiated merely by typing, page load or polling.

JPEG quality is 0.8. Independent decoder tests check the exported QR's exact URI;
the representative raster shrank from 118900 to 61049 bytes. JPG transfers alone
use ASCII fast flag 1: every packet requires ACK, intermediate application replies
are omitted, errors stop transfer, and final success must include the exact
original byte count. File CRC verification still precedes display. The 247-byte
frame bound, upload deadline, cancellation, cleanup and paid lifecycle remain.
Source: Ingenico RBA Developer's Guide Rev 17.6, pp.391–392, and the vendor
implementation recorded in reference/file-write-vendor-evidence.md. New speed
has not yet been timed on the physical reader. Form content and its installation
protocol are unchanged, so this release requires no reinstall or restart.

### Explicit leftover card-screen recovery

A successful port open followed by `11.01` means the terminal is waiting for a
card; it does not mean another process owns COM5. With Eagle closed and no card
payment in progress, use **Close leftover card screen & connect** under setup.
The action verifies L7000 / UPP 5.08.05-0010, requests fresh status, and only if it
is still 01 sends the documented `00.0000` Offline command. It requires transport
ACK and verifies offline through at most three status requests. A failure closes
the port and leaves an explicit error. PIN, processing, EMV and other active states
are refused. Normal Connect never sends Offline. This is not a hard reset, reboot,
or forced release of another application's serial handle. It clears the card-ready
lane state, so only use it after finishing the Eagle workflow. Status 99 (Idle)
is also accepted by connection, display, setup, inspection and restoration checks.
Source: Ingenico RBA Developer's Guide Rev 17.6, Offline pp.244–247 and Status
pp.273–276. Protocol checks are simulated; recovery on this UPP register
still requires an operator-observed test.
The cashier QR and amounts remain available when the terminal is absent or fails.
Eagle must be closed in **every Windows session** before the browser opens the port.
The website neither completes nor changes an Eagle tender.

The default configuration exposes connection, the original **12-second hello-world
test**, a **12-second QR-only probe**, and read-only display-form checks.
Automatic customer display is disabled until the installed terminal's QR form and
both visible amount labels have been physically verified. A transport ACK, valid
unit-information response, or successful screen restore is not QR-scan evidence.
The separate **Prepare JPEG screen** button can install only the dedicated image
form described below. No firmware or ACE logo is installed or replaced.

## Payment JPEG path

The site draws an 800×480 JPEG in the cashier browser. The QR contains the exact
current payment URI; the image also contains the USD sale total and remaining XMR
as exact text. Partial payments replace both the URI and remaining amount. The QR
uses integer pixel modules and a four-module white margin. Images are generated
locally without public image uploads, and stale/finished-sale preview URLs are revoked.
The bundled PNG renderer remains available, but the terminal test uses JPEG to match
the existing raster-image route. Independent decoding of actual browser JPEG output
has passed offline; that does not prove terminal display support.

1. Close Eagle in every session, refresh the cashier page, and connect COM5.
2. Open **Terminal setup and QR test → Payment JPG screen**.
3. Click **Prepare JPEG screen**. It checks whether `/HOST/WARDXMR.K3Z`
   already matches this site's candidate. It writes a missing form only;
   an existing different form is never overwritten. The new form is checked by
   length/CRC, then the port closes for a **manual terminal restart**. No `97.` command
   or automatic reboot is sent. The page displays the next step.
4. After a new installation, restart the Ingenico normally and reconnect COM5.
5. Create a payment request, wait for a fresh wallet check, and click
   **Test payment JPG for 12 seconds**. Upload can take longer than the display;
   progress is shown, with a two-minute upload limit plus bounded response cleanup.
6. Scan the physical QR without paying. Compare its address and exact `tx_amount`,
   visible USD/XMR amounts, and the return to ACE. Repeat with a different request
   or remaining amount to prove that a previous image is not cached on screen.
7. Copy the terminal log if any step fails or the pixels do not match. Automatic
   JPEG display remains disabled until the physical installation/display/refresh
   test passes. The existing hello-world path is independently verified by the user.

The form is a passive `Image` element referencing dedicated `WARDXMR.JPG`, using
`TEMPLLIMG.HTM` from the register handoff. Each sale has fresh image content; the
terminal scratch filename is reused because the form points at a fixed filename.
This avoids installing/rebooting for every payment. It does not reuse `lglogo.jpg`,
`ace.jpg`, `OFFLINE.K3Z`, or change Eagle settings.

The earlier `TEMPLLIMG.HTM was not found` error was raised by an invalid website
preflight check before any upload. Ingenico FormBuilder's own help documents that
templates can be built into the payment application. The mandatory Form `template`
attribute remains, but a separate `.HTM` file is not required to pass `63.` lookup.
This release removes that gate; it does not substitute another guessed template.

`62.` uploads use eight-bit escaping and frames no larger than 247 bytes, require
ACK and application replies, verify final original-byte length, then compare `63.`
CRC at the explicit `/HOST/` path before display. Version 4.2.5 fixes the unpack/fast
header flags to ASCII `1`/`0` (`0x31`/`0x30`). The guide's **Binary** type label led
the previous version to send raw `0x01`/`0x00`; the terminal reported `62.3` data error.
Static inspection of Ingenico FormBuilder's own `IngenicoTerminalFileWriteCommand`
confirms it appends ASCII digit strings for these fields. We retain standard mode
and require each application response. There is no automatic fallback or format retry.
The local log includes adapter version, header-only hex and the failed file/segment;
image data and payment URIs are omitted. The required form restart and dynamic JPEG
cache behavior still need physical validation on this terminal.

The original upload failure is preserved if abort cleanup also fails. A negative
`62.x` abort reply is reported as a rejection immediately; actual ACK/response
timeouts remain separate. No undocumented assumption is made that a data error
has already ended the transfer.

Only the verified dedicated form grants ownership of the `WARDXMR.JPG` scratch
name. An old dedicated image is removed before upload. After the bounded display,
`34.R` plus final `11.` verifies restoration, then `64./HOST/WARDXMR.JPG` deletes
the image and `63./HOST/WARDXMR.JPG` verifies absence. Failed initial form uploads
also remove `/HOST/WARDXMR.K3Z` only if it was proven absent before this operation
created it. An unknown pre-existing form is never deleted or overwritten. A payment or
cancel during upload aborts our file transfer with `62.9`, removes the scratch image
and prevents display. A payment during display waits for its bounded interval,
restores, deletes, and closes the port. Partial updates stop an obsolete upload;
the revised remaining balance can then be tested. Ordinary fresh same-sale checks
keep a lengthy upload current; its separate overall time bound still applies.

Restoration/deletion failures remain visible and never announce successful cleanup.
Browser/USB/power failure may prevent acknowledged cleanup; a finite display timeout
and retry inspection are still required. No successful transport reply is interpreted
as a visually verified QR or as payment proof.

## First register test

1. Finish any register work and close Eagle normally in both sessions.
2. Open the HTTPS cashier page in current desktop Chrome or Edge and unlock it.
3. Click **Connect Ingenico** and select the Ingenico USB serial port (COM5 on POS-1).
   The browser chooser is required; the site cannot silently select `COM5` by name.
4. Expand **Terminal setup and QR test**. First click **Test hello world for 12 seconds**.
   This reproduces the original `MSG.K3Z` text request without needing a payment.
5. Create an unpaid test request and wait for a successful wallet check, then click
   **Test terminal QR for 12 seconds**.
6. Scan the physical terminal without paying. Compare the decoded address and
   exact `tx_amount` with the cashier page. Record whether the prior ACE screen
   returns, and record any error text. This probe does not test visible amount labels.
7. Click **Restore screen & disconnect**, verify the terminal, then reopen Eagle.

If the screen does not change, open **Terminal command log**, use **Check installed
display forms**, and **Copy terminal log**. The read-only `63.` checks use explicit
`/HOST/` paths. Optional bounded `65.` readback of the installed message and ACE
forms reports only form metadata, with complete-file integrity verification. These
diagnostics do not gate JPEG preparation. The checks distinguish
an explicit missing-file reply from a communication failure; neither a timeout nor
the unchanged ACE logo proves the form is absent. Logs are bounded and stay in the
browser. They omit cashier nonces, sale tokens, full wallet URIs, and terminal serial
numbers. Test results stay visible independently of the automatic-display setup note.

The transport now allows three seconds for ACK and a separate four seconds for a
normal expected response, matching the RBA guide's timing guidance. The previous
combined two-second deadline was too short. A matching application reply is still
distinguished from the protocol ACK; QR rendering requires physical observation.

The candidate probe uses `70.TQRDATA,<URI>` and `24.QRCODE.K3Z[FS]t,12`.
QRCODE.K3Z is documented in another provider's terminal package; its presence and
behavior on Epicor UPP 5.08.05-0010 remain unverified. If missing, obtain a compatible
approved form and its amount-label identifiers. Do not install another provider's
firmware or overwrite the ACE logo to work around it.

Connection validates idle state and `07.` against Ingenico L7000 / application
field `5.08.05-0010`. Field offsets come from the RBA guide; the browser path still
needs a live check against UPP. A mismatch stops before display commands. Capture
the model/application diagnostic for investigation.

## Enabling the tested form

For the dedicated JPEG route, the tested profile is
`{verified: true, mode: 'jpeg', form: 'WARDXMR.K3Z'}`. It is enabled only after the
physical checks above. The site option `wardsace_monero_pos_jpeg_verified_v1` must be
the exact string `yes` to supply this profile; its default is `no`. The existing
`monero_pos_terminal_profile` filter can override it. Never treat hello-world,
successful form upload, or an application ACK as JPEG visual verification.

Automatic JPEG mode checks the dedicated form on connection. It keeps the same
sale's verified image during successive bounded display intervals, checking the
image CRC before reuse. A changed payment URI/remaining amount requires new image
pixels. Ended or stale payment updates stop renewal and remove the dedicated image;
a fresh sufficient paid/confirmed result also releases the serial port. The default
profile remains null, preserving manual preparation and tests during setup.

A site-side `monero_pos_terminal_profile` filter can return these fields. There is
deliberately no guessed default for the two amount identifiers.

| Field | Required value |
| --- | --- |
| `verified` | Boolean `true`, only after the complete physical test |
| `form` | Tested installed `.K3Z` filename |
| `fiatVariable` | Tested USD text-variable identifier, without the command's leading `T` |
| `dueVariable` | Tested remaining-XMR text-variable identifier, without the leading `T` |

Before setting `verified`, test the full URI, both labels, a partial-payment update,
timeout, fresh paid status, cancellation, and restoration on this terminal. Use a
separate approved test harness/profile to establish those identifiers; the default
QR-only probe cannot certify them. A form's existence alone does not qualify it.

With a verified profile, each finite display interval uses the same server-issued
URI as the cashier QR. Fresh wallet checks refresh the remaining balance. Current
paid/confirmed results stop renewal, restore the saved screen after the active bounded
interval, then automatically close the serial port. Partial payments keep it open.
Expired requests and New sale stop display renewal. Cleanup may take approximately
12 seconds plus protocol response time. Continuous cycles and visual transitions require physical validation. No
`15.6` is sent because a late cancel can affect an unrelated terminal operation.

## Payment and lifecycle rules

- Existing cashier authorization and WordPress nonce checks remain. Wallet RPC and
  credentials stay on the server. No unauthenticated bridge endpoint or Windows HTTP
  service is added.
- `status_fresh=true` means a complete successful wallet RPC snapshot. Creation,
  errors, and cached results are not fresh. `status_cached` distinguishes a saved
  result; `status_source` records the monitoring mode.
- The legacy view-key cashier flow remains available. Its limited block-window
  scan cannot drive the terminal because it is not a complete wallet snapshot.
- Current incoming/pool transfers determine current RPC totals. Dropped pool
  transfers, flagged double spends, and nonzero-unlock-time transfers are excluded.
  Historical rows remain available for reconciliation.
- Response freshness starts when its browser request started. Snapshots older than
  15 seconds cannot start another cycle. XMR decimals are passed as exact strings.
- Only one status poll runs at a time. Reset aborts pending requests and advances
  the sale generation; late replies cannot repaint another sale.
- Closing or navigating away from the cashier tab starts an immediate port-release
  path on `pagehide`; `freeze` does the same when the browser suspends the page.
  This path cancels the serial reader, aborts the writer, releases stream locks and
  starts closing the port without waiting for a 12-second form. It also blocks
  queued commands, late wallet replies and pending connection attempts. No exit
  confirmation dialog, firmware command or late form cancel is added.
- Merely hiding the page stops display renewal and permits normal restoration.
  Fresh sufficient paid/confirmed responses still trigger normal acknowledged
  restoration, image deletion and disconnect while hidden.
- Returning through browser history or resuming refreshes payment status but does
  not reopen the port. The cashier must explicitly connect again. Stale previews
  and responses from before departure cannot restart display.
- Actual Chrome/Edge context destruction also releases the native serial handle.
  Webpage JavaScript cannot guarantee acknowledged ACE restoration or JPEG deletion
  after termination, freezing, crashes, sleep or power loss. Immediate port release
  and a successfully restored screen are different outcomes; the exit path never
  claims the latter. The finite form timeout remains required and needs physical
  verification. The normal **Restore screen & disconnect** action remains available
  when an acknowledged cleanup result is needed.

## Primary documentation consulted

- [Chrome Web Serial](https://developer.chrome.com/docs/capabilities/serial): HTTPS,
  user gesture, raw streams, serial options, reader cancellation and stream-lock cleanup.
- [Web Serial specification](https://wicg.github.io/serial/): origin permission,
  `requestPort`, device information, and the `serial` Permissions Policy.
- [Edge serial permission policy](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-policies/DefaultSerialGuardSetting):
  allowing or blocking serial-port permission requests in Windows Edge.
- [Chrome page lifecycle](https://developer.chrome.com/docs/web-platform/page-lifecycle-api):
  cleanup cannot be relied upon after page termination.
- [Chromium SerialPort context destruction](https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/modules/serial/serial_port.cc)
  and [native serial implementation](https://chromium.googlesource.com/chromium/src/+/main/services/device/serial/serial_port_impl.cc):
  context/receiver destruction closes the underlying serial connection.
- [Monero Wallet RPC](https://docs.getmonero.org/rpc-library/wallet-rpc/#get_transfers):
  incoming/pool snapshots; `make_uri` and `parse_uri` document payment URIs.
- [WordPress nonces](https://developer.wordpress.org/apis/security/nonces/): nonces
  complement access checks; they do not replace authentication or authorization.
- [Ingenico RBA Developer's Guide 17.6](https://developer.ingenico.us/wp-content/uploads/2020/02/DIV350779-RBA-Developers-Guide-Rev-17.6.pdf):
  QRImage p.48; unit information pp.258–259; on-demand forms pp.317–321;
  save/restore pp.357–358; text-variable updates pp.401–403.
- [Shift4 TETRA guide](https://s4-myportal.s3.amazonaws.com/downloads/documentation/utg/using%20ingenico%20tetra%20external%20devices.pdf):
  public links to Ingenico FormBuilder and Lane7000 example packages. FormBuilder
  help topics `fb_k3zfileformat_introduction.htm`, `fb_screencomponents_form.htm`, and
  `fb_screencomponents_image.htm` document built-in templates and mandatory attributes.
  Example packages are supporting format evidence, not proof of Epicor installation support.

## Offline checks

From the staged development plugin directory run `node --test --test-isolation=none tests/monero-pos.test.js tests/monero-pos-terminal.test.js`
and `php tests/pos-snapshot.test.php`, plus PHP lint on changed PHP files.
They use fake clocks/transports/wallet results and never create payments or open COM5.
`tests/render-pos-fixture.php` renders an isolated template without WordPress or wallet access.
