# Sellable stock management

- **Stock on Hand** is the sum of remaining physical batch quantities, including expired and recalled batches and units held for pending wallet payments.
- **Sellable Quantity** includes only unreserved quantities in active products/categories, with no recall and no expired batch. Missing expiry dates are permitted. The existing business rule blocks a batch on its expiry date, using the application's business timezone (default Asia/Manila).
- Expired and recalled counts can overlap. The unavailable total counts each unit once. The displayed reasons explicitly identify overlap and payment reservations.
- A product/batch balance mismatch fails closed: no sales until inventory is reconciled.

## Recalls and physical removals

Recall and Release recall operate on the entire batch and require a reason. They preserve physical quantities and costs, record an event/audit entry and a zero-quantity stock movement, and are idempotent by request key. Release does not override expiry or product/category restrictions. A fully reserved batch can be recalled even with no unreserved units remaining.

Only disposal, write-off, transfer, completed sales and count reconciliation physically remove stock. Returns retain the original batch's cost, expiry and recall status, so goods received into quarantine remain unavailable. A refund with no recoverable original batch cannot automatically restock; reconcile it explicitly. Damaged returns can still be refunded without restocking.

Historical recall events recorded by the former workflow already deducted stock. This update does not invent physical quantities by restoring those old removals. Count and reconcile any goods still physically present before using the new workflow.

## Validation and updates

`GET /api/inventory/availability` provides current quantities and reasons. Product selection sends `productId` and the requested total `quantity`; the server validates them against current batches. Barcode scans and manual lookup use the same serialized check. Network failure blocks selection instead of falling back to an old quantity.

The UI refreshes totals every five seconds, refreshes before checkout, and marks cart lines that exceed current availability. Cash checkout, wallet reservation and exchange replacement independently validate on the server while holding the product lock. FIFO consumption filters out ineligible batches and preserves cost allocations. Direct API calls cannot override those safeguards.

Pending wallet allocations remain physically on hand but are unavailable to another sale. Payment confirmation rechecks their batch flags/dates and product/category status. If goods become ineligible after the provider charges the customer, confirmation is rejected for reconciliation; do not release goods. Cancel/release still returns quantities to their original batch without clearing expiry or recall flags. Resolve pending payments before starting a physical count.

## Activation and verification

This change adds no new schema beyond the pending evaluation migration already in this workspace. Apply outstanding migrations before serving the app: `php artisan migrate` (use the deployment's normal backup/migration process in production).

Regression coverage includes mixed and overlapping exclusions, recall/release/disposal, expiry rollover, quarantined returns, stale selection, batch FIFO costs, excessive/manual quantities, inactive categories, balance mismatches, pending reservations and late payment confirmation. Frontend tests cover serialized scans, selection/checkout refresh, network failure, stale responses, both inventory totals and recall release.
