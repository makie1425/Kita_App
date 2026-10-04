# Product, purchasing and FIFO revision

This revision implements the product → request → approval → receiving → inventory workflow from the chairperson's supplied checklist. Code changes require the new database migration before they can be served in production.

## What changed

| Concern | Implementation |
| --- | --- |
| Product identity and variants | Product name, brand, category, subcategory, size, measurement unit, inventory unit, barcode and reorder level are separate. Each size/brand variant is a separate product with a unique barcode and ID. Product editing is available from Inventory. Purchase and sale snapshots include brand and size. |
| Category hierarchy | Categories can be added, renamed, edited and activated/deactivated. Renaming updates current products and subcategories while preserving historical purchase snapshots. Subcategories belong to one category; a used subcategory cannot be reassigned to another parent. |
| Brand and subcategory management | Available on Categories / Brands. Supports create, edit, activate and deactivate. Inactive entries are excluded from new registration choices. Existing historical records are retained. |
| Creation versus stock | Registration always creates zero stock. Positive initial stock is rejected at the API. The initial-quantity input is removed. Product edits cannot change stock. |
| Purchase selection | Only registered products can be requested. The inline product-creation flow was removed. Registration is a separate screen, then the user returns to purchasing. Requesting and approving do not add stock. |
| Receiving | Each delivery records actual quantity, actual unit cost, date, supplier, receiving reference, optional batch number and expiry. Approved estimated cost is the default; the receiver can enter the actual cost. Retries remain idempotent. |
| Manual stock increases | Removed from the adjustment form and rejected by the API, including approval of old positive adjustment requests. Damage, loss and other approved stock decreases remain available. Customer returns and confirmed payment releases restore existing inventory; they are not new purchases. |
| Historical prices | Every receipt creates a separate batch and retains its cost. Changing the product's estimated purchase cost never reprices older batches. Existing receipt costs and PO estimates are not overwritten by new receipt costs. |
| FIFO | Sales, wallet reservations, exchange replacements and negative adjustments consume oldest received-date batches, then batch ID. Allocations preserve quantity and original cost. Returns/releases restore their original allocations. Non-restock refunds are marked so subsequent returns cannot restore the wrong cost layer. |
| Stock integrity | Product stock is a transactionally maintained available-quantity cache. Batch totals must equal the resulting stock at every stock movement. Insufficient stock or inconsistent balances roll back the entire transaction. Pending wallet payments reduce available stock until confirmed or released. |
| Reports and low stock | Inventory / Cost History is available from Inventory and Admin Reports. Includes current quantities/reorder status (including zero-stock products), receipt cost history, remaining batch value, batch/expiry details and latest 500 movements. Filters include category, subcategory, brand, product, supplier and date; a low-stock selector filters current products. Printed PO reports show actual receipt costs. |

## Decisions and limits to review

- **Measurement and inventory unit differ:** a 1 Liter drink can be stocked as one Bottle. Receiving/request quantities and unit costs are always expressed per inventory unit, even if a purchase packaging/conversion factor is configured. Quantities remain whole inventory units; fractional weighed checkout is not introduced by this revision.
- Brand can be unbranded/not applicable. Size and measurement unit must be supplied together if used. A new product requires a subcategory when its category has active subcategories. No category-specific rule hides brand/size fields: the supplied notes explicitly left those rules unclear.
- Existing product names are not automatically split into brand/size; a manager must review and update their structured fields. The migration preserves IDs, barcodes, stock and historical records.
- Existing stock becomes a **legacy opening** batch, using the recorded estimated unit cost. It is not represented as a reconstructed historical delivery. Its internal FIFO date is a sentinel so it is consumed before newly received batches; the report displays “Opening balance (date unknown).” Original delivery costs cannot be inferred from a single aggregate balance.
- Returns against pre-migration sales cannot recover unknown original batch allocations. They are explicitly marked **legacy_return** and use the current recorded estimated cost. Have the operator review these transitional cases.
- Existing negative adjustments, returns, reservations and payment integrations remain in place. The purchase states retain the established workflow labels (Pending Approval, Approved / Waiting for Delivery, Partially Received, Fully Received, Declined, Received with Discrepancy); no artificial “Purchased” transition was added.
- Receipt date filters apply to receipt history; movement dates apply to movement history. Current-stock rows are current balances, not historical balances as of a selected date. Supplier filters use receipt supplier for batches and current product supplier for current stock/movements. Movement history is limited to the latest 500 matching entries. CSV exports include all loaded matching rows for the selected dataset (movements still have the 500-row limit). Printing uses the selected table page; choose an appropriate rows-per-page setting. The older demo report generator is outside this revision; use Inventory / Cost History for these real records.
- FIFO is a cost allocation rule. Staff must still pick the correct physical stock. This is not automatic FEFO expiry selection.

## Verification

The automated acceptance example creates Coke / Coca-Cola / Beverages / Soft Drinks / 1 Liter / Bottle with stock zero. A request and approval leave it at zero. Receipts of 20 at ₱55 and 10 at ₱60 produce stock 30. Selling 25 consumes 20 at ₱55 plus 5 at ₱60 (cost ₱1,400), leaving 5 at ₱60.

Additional tests cover invalid quantities/costs/expiry, cross-category subcategories, role restrictions, stock-change rejection, price history, payment release, non-restock returns, category rename, metadata preservation, report filters and receipt retry handling. SQLite and isolated MySQL tests were run; no live database was used. Screen-builder checks cover registration, master lists, purchase selection, inventory history and product editing. Browser visual QA remains required because no browser session was available.

## Production rollout

Do not auto-deploy this code before the database rollout is ready. The catalog endpoint reads the new columns and tables.

1. Rehearse with a staging copy of the live MySQL database and review existing product stock/unit costs. Resolve invalid balances before migration. Review long product/brand names and the structured metadata needed for each product.
2. Preserve a fresh verified production backup and the matching application version/APP_KEY. The existing backup restore tool requires matching schema; restore a pre-migration snapshot with the pre-migration application/schema, not directly over the upgraded schema.
3. Pause checkout, queue workers, scheduler and other writers. Enable maintenance mode on every instance, drain in-flight requests and payment webhooks, and reconcile pending external payment activity before reopening.
4. Deploy the new code during that window, then run `php artisan migrate --force`. The added migration is `2026_10_02_000001_product_master_and_fifo.php`. It adds the master data/FIFO tables, receipt-cost columns and product metadata, and seeds legacy opening batches. MySQL DDL is not transactional: preserve the backup and inspect failures before retrying.
5. Confirm for every existing product that stock equals the sum of remaining batches (products at zero may have no batches). Test the five-step acceptance scenario on staging, then review production metadata and screen layout.
6. Run `php artisan up` and resume workers/scheduler only after verification. Monitor checkout/receiving errors and payment reconciliation.

Rollback drops the new FIFO/master data tables and metadata; it is not appropriate after new business transactions without a recovery plan. Snapshot-name columns are widened to 320 characters and intentionally not shrunk on rollback to avoid truncating captured variant labels.
