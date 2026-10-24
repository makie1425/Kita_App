# Stock receiving workflow

1. Open **Receive Stock** on the new approved PO. The blue-and-white **Receive Delivery** popup shows the original PO, supplier, date, and status.
2. Enter delivered quantities, including damaged units. Accepted = delivered minus damaged. Only accepted units enter sellable inventory. Leave undelivered lines blank.
3. Record damage reasons and whether goods are returned, rejected, or quarantined. Damage is retained in inspection history and remains a replacement obligation; quarantined quantities are never added to sellable stock.
4. Use **Add Outstanding Item** for earlier deliveries from the same supplier. Select the original PO, product, and missing/replacement type. New orders stay under their new PO; earlier shortages stay under their original PO. Older records without reliable damage classification appear as Previous outstanding item.
5. For 30 new units plus 5 missing units: enter 30 on the current PO and add 5 against the earlier PO. The delivery adds 35 accepted units, with separate PO allocations and one Stock Receipt.
6. Split accepted quantities into lots when supplier/manufacturer lots or expiration dates differ. Each product lot gets an automatic internal LOT identifier. Matching product, supplier lot, manufacturing date and expiration within the same receipt can share a physical lot across PO allocations; purchase-cost layers remain separate. Unknown supplier lots are not merged by date alone.
7. Substitutes must use the same stock unit, have equal-or-lower unit cost, and fit the outstanding quantity. Substitutions, purchase-cost changes and excess deliveries require notes and a separate administrator's approval. The entire exception receipt waits without adding stock. Approval alone does not post it; a manager uses **Post approved receipt** after approval. Stale PO versions block approval/posting and require a new receipt with refreshed quantities.
8. Selling-price editing and payment due dates are not part of this popup. Historic costs are preserved. Remaining receiving allowance is not an unpaid supplier invoice balance.
9. **Review & Confirm** displays PO allocations and accepted totals. Confirm once; retries are idempotent. PO receipts, lots, inventory, quantities and history are posted atomically.
10. View/print the combined Stock Receipt from its history section. It includes original PO allocations, damage dispositions, remaining shortages, product lots, accepted quantities, and costs. Existing PO reports and inventory history retain their batch links.
11. Expiration-dated eligible inventory is issued by earliest expiration, then receipt date and ID. Undated inventory follows receipt order. Returns restore original cost layers. Expired/recalled inventory remains ineligible for sale.

## Historical compatibility and release

Apply migration `2026_10_24_000003_stock_receipt_inspections.php` before serving the updated code. It adds Stock Receipt headers, physical lots, original product references and obligation events. Existing history and batch identifiers are not rewritten. Old unreceived cross-PO shortage allocations must be released before receiving those quantities against their original PO. Existing legacy receipt endpoints remain for compatibility; POs managed through the new inspection workflow must continue through it.

Rollback refuses to discard recorded Stock Receipts. Indexed-column rollback fixes also cover the earlier receiving migrations. No production migration or deployment is included in this implementation step; deployment requires the user's approval.
