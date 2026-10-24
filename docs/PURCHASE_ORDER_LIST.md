# Purchase order creation and listing

Use **Create Purchase Order** to choose one supplier and add items. The purchase-unit selector includes the item's Stock Unit, its configured purchase unit, and Box, Case, and Pack. A purchase unit different from the Stock Unit requires a positive whole number of stock units per package. For example, 3 Cases with 24 Bottles per Case orders 72 Bottles. Costs remain per Stock Unit.

The PO workspace lists pending, approved, and declined records in a table with Purchase ID, PO Date, supplier, item count, expected delivery, status, and actions. An approved request and its posted PO appear once. The **All PO Statuses** filter can narrow the list.

View opens details. Admins can Edit, Approve, or Decline a pending PO from its row. Review dialogs retain revision checks and require reasons for edits and declines. Managers retain their existing permissions; the change does not grant approval access. Approved POs keep their delivery-plan and printing actions.

New numbers use `PO-YYYY-MM-NNNNNN`, for example `PO-2026-10-000043`. The sequence continues across months and years. Existing IDs are not changed. Both purchase creation and shortage delivery creation use this format, with the existing database sequence lock, collision checks, and retry handling.

The API accepts `stockUnitsPerOrder` for package quantities. Existing `piecesPerBox` requests remain supported. The existing database column stores the package quantity for compatibility; no schema migration is required.

Validation: `php artisan test --compact --filter=PurchaseWorkflowTest` and `node tests/frontend/purchase-order-list.cjs`.
