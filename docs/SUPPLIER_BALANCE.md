# Supplier Balance

Supplier Balance means goods owed by a supplier following a posted delivery, valued at original PO unit cost. Accepted stock is treated as paid on receipt under this business workflow; this does not transfer money or create a bank payment.

- New orders without receipts do not create supplier balances.
- Original remaining quantities exclude incoming carryover copies. Accepted replacements and resolved carryovers reduce the quantity once.
- Missing and damaged quantities share one outstanding total and cannot be added twice.
- Unknown historical classification or cost is Needs Review with a null amount, excluded from known totals.
- Post replacements using Stock Receiving. Existing authorization, stock unit compatibility, quantity and approved purchase-value checks still apply. Substitutes require review; unaccepted goods never increase sellable stock.
- Received POs with outstanding goods cannot be cancelled through Cancel Remainder. Older cancelled records retain their quantities for review; do not erase historical obligations.
- Old payments/credits remain readable. New manual settlement creation is disabled. Payments never reduce goods owed.
- Reports retain the `payables` type identifier for saved links, but return Supplier Balance columns and semantics. Date filters select initial receipt date, with current outstanding quantities.
- No migration or historical data rewrite is required. All queries use the authenticated workspace scope.
