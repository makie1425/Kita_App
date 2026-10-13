# Inventory workflows

Deployment: run `php artisan migrate --force` (Render startup uses `RUN_MIGRATIONS=true`).

- Adjustments and damage reports use the existing administrator approval workflow.
- Managers and administrators can record batch write-offs, disposal and transfers out, with a required reason. Transfers require a destination. This single-store inventory does not maintain a receiving-store balance.
- Recall flags the entire batch without reducing physical stock. Release recall clears the flag but never overrides expiry. Returned recalled/expired goods stay in their original quarantined batch. See [Sellable stock management](SELLABLE_STOCK.md).
- Cycle counts can cover one category; Full counts cover all active products; Blind counts hide expected quantities in the count view and count API while open. Count physical inventory, including expired and recalled goods; exclude disposed/transferred goods. Resolve pending payment reservations before counting.
- Complete requires every physical quantity and a reason. Any stock movement after the snapshot blocks completion: cancel and restart the count. Counts do not lock checkout throughout the physical counting session.
- Confirmed shortages consume FIFO batches. Confirmed surplus creates an explicit count-surplus batch at the current estimated unit cost, retaining existing batch costs. All reconciliations create stock movements and audit records.
- Near-expiry monitoring reads remaining batches using the current business date, with a configurable days-ahead filter including expired batches.
- Product/category archive actions persist status, archive date and actor.

Stock actions commit their record, batch balance, product balance, allocation and movement together. Retrying an identical batch action key does not deduct inventory twice.
