# Website POS inventory accounting

Barcode checkout uses WooCommerce product prices and stock. Each product line on a new `_monero_pos=yes` order records `_wardsace_pos_stock_product_id`, the product or parent variation that manages its stock. Manual amount sales contain no product quantities and therefore cannot reduce item inventory.

WooCommerce remains responsible for reducing and restoring stock. Its `_reduced_stock` item metadata is the inventory ledger: on-hold orders reserve the items through the normal stock reduction, cancellation restores them, and refunds restore only the quantities for which the operator selects restocking. Confirmation must not reduce them again. Refund item copies are excluded from the ledger because WooCommerce copies the original item's metadata into refund lines.

The existing hourly Epicor reconciliation needs the accompanying `monero-pos-inventory-review.patch`. It calculates:

    website stock = incoming Epicor quantity - outstanding website POS reductions

The adjustment is applied to both `_stock` and the site's `qoh` display. Unadjusted Epicor quantity remains available as `_monero_pos_epicor_qoh` for affected products after a reconciliation. Negative adjusted inventory is retained so discrepancies are visible. Products without these POS lines retain the existing reconciliation behavior. Ordinary online sales are outside the scope of this new adjustment.

Checkout, payment updates, the hourly reconciliation, standard WooCommerce stock reduction/restoration, restocking refunds, and normal order-item editing share the `wardsace_monero_pos_inventory` database advisory lock. The lock covers reading the adjustment and writing stock. Ledger query or lock failures stop reconciliation rather than silently replacing stock with unadjusted source quantities. Other extensions that directly write inventory must coordinate with this lock as well.

Keep completed POS orders as inventory records. Permanent deletion of a POS order or product line with outstanding stock reductions is rejected; restocked lines can be deleted. Trashing an order does not erase its adjustment. The helper is also loaded by the reconciliation MU plugin, so deactivating the payment gateway alone does not remove stock protection.

The adjustment deliberately has no expiry and never guesses that an Epicor stock change represents a POS sale. If an administrator later enters these same sales into Epicor, or resets Epicor to a physical count that already includes them, the corresponding historical POS adjustments must be reconciled against that accounting checkpoint before the next import. Otherwise the same sale would be deducted twice. This is an inventory accounting task; it is not a required checkout step. Product prices and future incoming stock continue to come from the existing Epicor feed.

## Validation

- `tests/pos-inventory.test.php`: nested locks, lock failure, existing online-order isolation, native ledger refresh, effective quantity, qoh, and permanent-deletion guards.
- `tests/pos-inventory-sql.test.php`: actual MySQL aggregation with connection-scoped temporary tables; excludes ordinary sales, fees and cloned refund lines, and verifies partial refunds, full restocks, and cancellation.
- `tests/pos-native-isolated.test.php`: native WooCommerce HPOS lifecycle using 160 empty temporary shadow tables, a database session with READ ONLY defaults, denied reconnects/schema changes, request-local WordPress cache, and blocked HTTP/email. Verifies saved scanned/manual totals and tax, stock reservation, confirmed payment, partial restocking refunds, cancellation, and expiry. A second temporary address mirror supports HPOS's billing/shipping join because MySQL cannot self-join a temporary table.
- `monero-pos-inventory-review.patch` records the narrow reconciliation change. It passed `git apply --check` against the existing site; the approved change is included in the staged MU plugin and its original SHA256 is recorded for the publisher's baseline check.
