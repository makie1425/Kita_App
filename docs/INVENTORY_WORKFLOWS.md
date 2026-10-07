# Inventory workflows

Deployment: run `php artisan migrate --force` (Render startup uses `RUN_MIGRATIONS=true`).

- Adjustments and damage reports use the existing administrator approval workflow.
- Managers and administrators can record batch write-offs, disposal and transfers out, with a required reason. Transfers require a destination. This single-store inventory does not maintain a receiving-store balance.
- Recall removes the entire remaining selected batch from sellable stock and flags the batch. Refunds cannot restock that recalled batch; use a refund without restocking.
- Cycle counts can cover one category; Full counts cover all active products; Blind counts hide expected quantities in the count view and count API while open. Count sellable inventory only, excluding recalled/disposed/transferred items.
- Complete requires every physical quantity and a reason. Any stock movement after the snapshot blocks completion: cancel and restart the count. Counts do not lock checkout throughout the physical counting session.
- Confirmed shortages consume FEFO/FIFO batches. Confirmed surplus creates an explicit count-surplus batch at the current estimated unit cost, retaining existing batch costs. All reconciliations create stock movements and audit records.
- Near-expiry monitoring reads remaining batches using the current business date, with a configurable days-ahead filter including expired batches.
- Product/category archive actions persist status, archive date and actor.

Stock actions commit their record, batch balance, product balance, allocation and movement together. Retrying an identical batch action key does not deduct inventory twice.
