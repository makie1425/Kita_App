# Purchasing and receiving flow

1. Approve the purchase order before receiving. Preserve the original approved quantities and prices.
2. Record accepted and damaged quantities separately. Only accepted products enter saleable inventory. Record omitted items as shortages; keep the PO Partially Received while quantities remain outstanding.
3. Show outstanding quantities and the remaining approved receiving value. Receiving value equals original ordered value plus approved additional amounts minus accepted delivery value. It is not the unpaid invoice balance.
4. Leave outstanding items open for later delivery, or choose an active substitute with the same stock unit and an equal-or-lower unit cost. Substitutions cannot exceed the outstanding quantity or available approved value. Record the replacement reason and actual product received.
5. Open a delivery for one supplier and enter quantities under each included PO. Missing and damaged replacements stay under the original PO; new orders stay under their new PO. All included items share one automatic receiving Batch / Lot, and each PO uses its own allowance. Record follow-up notes referencing previous inspections. Earlier shortage-transfer records remain available for history and releasing unreceived allocations; the new UI does not create transfers.
6. If receiving would exceed the approved value, the manager requests an additional amount with a reason. An admin approves or declines it in Purchase Request Review. The requester cannot approve their own increase. A changed PO requires a fresh request. Approval adds an allowance; it does not post stock, record payment, or rewrite original PO prices.
7. After approval, reopen receiving to load the current PO version and confirm the delivery. Posting is atomic and repeat submissions do not duplicate stock or allowances.
8. Each delivery receives an automatic Batch / Lot identifier, shared by its PO receipts and inventory entries. A later delivery receives a different identifier, even on the same date. Keep separate actual purchase costs and expiration dates per product entry. A new selling price applies to all remaining units of the product, while historical batch costs and completed sales remain unchanged. Use one consistent selling price when the same product appears on several included POs.
9. Mark quantities Fully Received when all ordered units are accepted or resolved through a linked PO. A cheaper substitution may leave unused approved value. Display it separately; do not automatically turn it into a refund, supplier credit, or extra ordered quantity.
10. The printable PO report includes original value, approved increases, delivery value, remaining value, increase-request history, shared delivery batches, and follow-up notes. The receiving screen includes a collapsible delivery batch history showing products and PO references.

## Release

Apply migrations `2026_10_24_000001_purchase_receiving_budgets.php` and `2026_10_24_000002_shared_receiving_deliveries.php` before serving the updated application. These add approval and shared-delivery history without modifying historical PO prices, inventory batch costs, or earlier batch identifiers. Reverting the shared-batch migration is blocked once shared batches exist, because the earlier schema required a unique batch per receipt line.

Deployment requires the user's explicit approval.
