# Inventory workspace

Reference: https://www.ecount.com/ph/ecount/product/inventory_inventory-management-system
ECOUNT informed the stock balance, item history, batch traceability, configurable columns and export organization. KITA remains a single-store system.

## Flow
Purchase request ? approval ? receive and inspect ? posted delivery with inspection remarks ? stock balance ? sales / stock issues ? inventory ledger and stock count.
Only accepted goods enter inventory. Missing quantities and damaged goods returned at delivery remain in that receipt?s remarks. Outstanding quantities are received later against the same PO; see DELIVERY_PO_HISTORY.md.

## Quantities and value
- Qty on Hand includes reservations and blocked batches still physically held.
- Available Qty excludes reservations, expiry, recalls and inactive or inconsistent items.
- Reorder alerts compare Available Qty with Reorder Level; inconsistent balances must be reconciled first.
- Inventory Value sums physical batch quantities times their individual costs, including reserved units. Opening costs may be estimated. It is not sales value or an accounting impairment calculation.
- Ledger increases/decreases reconcile the unreserved book balance. Payment holds/releases affect it without physical movement; recall events can have zero change.
- Stock units remain item-specific; the summary counts items rather than adding incompatible units.

## Views and exports
Stock Balance is the default. Item links open a filtered Inventory Ledger; Batches opens the Batch / Lot Register. Request Stock prepares a draft for manager review and normal approval.
Choose columns controls screen visibility. CSV and print include all report columns and all loaded rows matching search. Ledger responses are limited to the latest 500 matching records, with total count shown; narrow filters for larger histories. Date filters select posted movements or batch receipt dates, not historical inventory snapshots.
No database migration is required.
