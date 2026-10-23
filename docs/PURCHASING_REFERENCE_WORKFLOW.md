# Purchasing reference workflow

Source: user-supplied ECOUNT_Purchasing_Stock_Receiving_Reports_Workflow.pdf, five pages. Its recommended statuses and inspection controls are design suggestions, not verified ECOUNT screen names. The user selected the same-PO partial receiving rule.

1. Inventory: review available stock and reorder alerts; Request Stock prepares a replenishment draft.
2. Request: choose one supplier, quantities, stock-unit costs and optional expected delivery date. Admin reviews and approves. Neither step increases stock or payable.
3. Purchase Orders: monitor fulfillment and expected delivery, print a supplier-facing PO, then record when/how it was sent. KITA does not send supplier messages automatically.
4. Stock Receiving: enter accepted/damaged quantities, actual costs, optional expiry, supplier document and payment due date. Posting adds only accepted stock. Each receipt retains its own inspection and batch links.
5. Outstanding Deliveries: the PO stays Partially Received until remaining goods arrive. Receive them on that PO. Fully Received orders cannot accept another ordinary receipt. Admin cancellation closes only the undelivered remainder.
6. Supplier Accounts: received quantity times actual receipt cost creates the operational payable. Admins record payments already made or supplier-issued credit notes. These entries do not move money or inventory. Duplicate references/retries and overpayments are rejected; voiding requires a reason and retains audit history.
7. Reports: Purchase Order Progress, Outstanding Deliveries, Purchase Receipt Detail, Inventory, Inventory Ledger, Supplier Book, Accounts Payable, and existing receiving/expiry reports support CSV, print and PDF where offered.

## Definitions and limits
- PO ordered value is a commitment, not an unpaid supplier balance.
- Receipt value includes every accepted receipt once, including legacy linked deliveries; original PO obligation totals are not added again.
- Unpaid receipt balance = accepted value minus recorded non-void payments and credit notes.
- Returns after acceptance affect stock through Stock Issues / Returns. A payable reduction requires a supplier credit note; a stock return alone does not prove credit was granted.
- Supplier Book dates filter entries; period activity is not a closing balance. Accounts Payable dates select receipt cohorts and use all settlements to show current balances.
- Due dates are explicit and optional, never guessed from supplier text. Unknown legacy costs/payments are flagged for reconciliation, blocked from settlement, and excluded from unpaid totals.
- This is a single-store operational inventory/payables workflow, not a warehouse transfer network, bank integration, or tax/general-ledger accounting module.

## Deployment and validation
Migration: 2026_10_23_230000_complete_purchasing_reports.php. Adds nullable scheduling/receipt fields and an auditable supplier_settlements table; it does not rewrite old orders or stock. The user confirmed Render RUN_MIGRATIONS=true; startup runs migrate --force. Until migration completes, new account/report features return a clear pending-update message and ordinary receiving remains compatible.

Feature tests cover 100 ordered, 60 then 40 accepted, damage exclusion, unchanged original inspection, same-PO receipts, supplier payments/credits, retries, overpayments, voids, cancellation, historical Completed shortages, report exports and negotiated request prices. Browser checks live in tests/frontend/purchasing-reference.js.
