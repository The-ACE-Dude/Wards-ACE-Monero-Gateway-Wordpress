# Wards ACE Monero Gateway for WordPress

A Monero (XMR) payment gateway plugin for WooCommerce. Accept XMR payments on your WordPress store with integrated address support, QR codes, "Open in Wallet" functionality, live XMR pricing, and checkout discounts.

**Forked from [monero-integrations/monerowp](https://github.com/monero-integrations/monerowp)** with significant enhancements.

## What's New in This Fork

- **"Open in Wallet" button** — One-click `monero:` URI link on the payment page. Works with Monero GUI, Cake Wallet (mobile), Monerujo, and any wallet that registers the `monero:` protocol handler.
- **Checkout discount banner** — Dynamically shows how much customers save when selecting Monero at checkout (e.g., "You save $15.00 (3% off)!"). Works with both classic and block-based checkout.
- **Admin payments list hardened** — Gracefully handles deleted orders instead of fatal errors.
- **WordPress 6.9+ / WooCommerce 10.x compatible** — Tested with the latest versions.
- **PHP 8.3 compatible** — No deprecation warnings.

## Features

- Accept Monero (XMR) payments directly — no third-party payment processor
- Two verification modes: **monero-wallet-rpc** or **viewkey** (explorer-based)
- Automatic exchange rate conversion (USD, EUR, GBP, BTC, and more)
- Integrated addresses for each order (no payment ID confusion)
- QR code generation on the payment page
- Configurable payment expiration time
- Order confirmation after N block confirmations
- Optional: display all store prices in XMR
- Optional: percentage discount for Monero payments
- Cron-based payment monitoring (checks every minute)
- Staff-only website checkout at `/monero-in-store/` with manual amounts, USB barcode entry, 6% sales tax and WooCommerce orders
- Shortcodes: `[monero-price]` and `[monero-accepted-here]`

## In-Store Checkout

Cashiers can open `/monero-in-store/` (also linked under **WooCommerce → Monero In-Store**), pass the site's Cloudflare Turnstile check, and enter the shared cashier PIN. A signed, HTTP-only cookie remembers that register for 12 hours; no WordPress user account is required. Enter a pre-tax amount, or select **Scan products** and use a USB keyboard scanner with an Enter suffix. Website prices, subtotal, 6% sales tax and total appear before **Create order & request payment**. Repeat scans increase quantities. The screen tracks payment and confirmation, supports partial payments, and recovers the same checkout after a timeout or reload.

The page is rate-limited by IP: page loads, PIN attempts, payment creation, and wallet-status polling each have separate limits. Administrators and shop managers may continue to access it through their normal WordPress login.

Every new sale creates a WooCommerce order without requiring an Epicor sale. No additional Monero discount is applied. Product orders reserve stock and complete after fresh wallet confirmation; unpaid expiry returns stock, while partial and late payments require review. Manual entry creates a custom line and cannot reduce individual product stock. Website in-store checkout requires wallet RPC verification. Historical payment-only requests remain readable.

The accompanying inventory reconciliation update preserves these product deductions during Epicor imports. Keep completed POS orders as inventory records. See [inventory accounting and physical-count checkpoints](docs/pos-inventory-accounting.md).

### Optional Ingenico customer screen (4.2.8)

The operator confirmed JPG display works and requested automatic operation.
With the JPEG profile enabled, Create payment request connects the reader,
verifies its identity/form and displays the current QR and amounts. It may ask
for COM5 the first time in a tab; subsequent sales reuse that verified port.
The same narrowly guarded card-ready recovery is enabled for this explicit
cashier action. Eagle must already be closed. Full authoritative paid status
restores, removes the payment image and disconnects.

JPG export quality is 0.8; pixel decoding tests still recover the exact URI.
JPG transfers use documented fast mode: ACK each packet, final application
response with exact original length, then CRC verification before display.
Form transfers remain standard speed. The existing form bytes are unchanged;
no installation or restart is required for this update.

Explicit “Close leftover card screen & connect” verifies the device and fresh
card-ready status before sending Offline, then verifies lane closure. Normal
Connect never closes the lane. Create payment request may close a freshly
verified leftover card-ready lane as described above. Status 99 (Idle) is accepted.

Close Eagle in every register session if it is holding the Ingenico port, then connect an Ingenico Lane/7000 directly from desktop Chrome or Edge using Web Serial. The cashier-page QR remains available. The website owns the sale; it does not require an Eagle transaction. Use **Restore screen & disconnect** before reopening software that needs the port. See [setup, limitations, and primary documentation](docs/ingenico-web-serial.md).

The page includes the original hello-world test, read-only form checks, and a copyable terminal command log. A fresh full-payment result automatically restores the screen and closes the serial port; partial payments keep it connected. Terminal ACK/response deadlines follow the documented three-second/four-second allowances.

The payment JPG path generates a fresh 800×480 image from the current payment URI, USD total and exact remaining XMR. The cashier can preview/download it, prepare a dedicated terminal image screen once, and run a bounded JPEG display test. The adapter checks the form and uploaded image by size/CRC, restores the previous screen, deletes only its dedicated payment JPG and releases the port on fresh full payment. A new form installation requires a manual terminal restart. Actual JPEG display and refresh on this UPP build must pass the register test before automatic JPG display can be enabled; no ACE logo, firmware or tender configuration is changed.

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+ (8.x recommended)
- One of:
  - **monero-wallet-rpc** running on your server (recommended)
  - OR a Monero **secret viewkey** for explorer-based verification

## Installation

1. Download the latest release or clone this repo into `wp-content/plugins/`
2. Activate the plugin in WordPress admin → Plugins
3. Go to WooCommerce → Settings → Payments → Monero Gateway
4. Configure your Monero address and choose a verification method:
   - **wallet-rpc**: Enter your daemon host/port (default: `127.0.0.1:18080`)
   - **viewkey**: Enter your secret viewkey

### Running monero-wallet-rpc

```bash
# Start monerod (full node)
monerod --detach

# Start wallet-rpc (replace with your wallet file)
monero-wallet-rpc --wallet-file /path/to/wallet \
  --password "your-wallet-password" \
  --rpc-bind-port 18082 \
  --disable-rpc-login \
  --trusted-daemon
```

Systemd unit files are included in `assets/systemd-unit-files/` for Linux servers.

## Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| **Monero Address** | Your primary Monero address (starts with `4`) | — |
| **Verification** | `monero-wallet-rpc` or `viewkey` | `monero-wallet-rpc` |
| **Secret Viewkey** | Only needed for viewkey mode | — |
| **Daemon Host** | monero-wallet-rpc host | `127.0.0.1` |
| **Daemon Port** | monero-wallet-rpc port | `18080` |
| **Discount (%)** | Percentage discount for Monero payments | `0` |
| **Order Valid Time** | Seconds before payment expires | `3600` (1 hour) |
| **Confirmations** | Block confirmations required | `1` |
| **Show QR Code** | Display QR code on payment page | Yes |
| **Testnet** | Use testnet instead of mainnet | No |

## Shortcodes

- `[monero-price]` — Shows the current XMR exchange rate (e.g., "1 XMR = 187.50 USD")
- `[monero-price currency="BTC"]` — Show rate in a specific currency
- `[monero-accepted-here]` — Displays the "Monero Accepted Here" badge

## Credits

- Original plugin: [monero-integrations/monerowp](https://github.com/monero-integrations/monerowp) by Monero Integrations & Ryo Currency Project
- Cryptographic libraries: Ed25519, SHA3, Base58 implementations included

## License

MIT License — see [LICENSE](LICENSE) for details.
