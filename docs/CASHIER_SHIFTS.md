# Cash drawer shifts

Each cashier or manager opens a cash drawer shift with counted starting cash before checkout. One open shift per account is enforced on the server; logging out does not close it. Cash sales record the charged total, excluding change. Cash refunds and voids reduce the expected balance. Exchanges add only the cash price difference. Wallet sales and refunds do not change drawer cash.

Cash added or removed requires an amount, reason, and the assigned manager's approval PIN. The close form requires a counted cash amount and an explanation for a difference. Open and closed shifts remain in the database; managers can review up to 100 recent shifts and print a detailed report. Reports are scoped to the user's workspace, and cashiers can see only their own shifts.

The additive migration `2026_11_10_000002_create_cashier_shifts.php` creates the shift and entry tables. Existing transactions are not assigned to new shifts. The Docker startup applies this migration before serving requests even when `RUN_MIGRATIONS=false`, so the new checkout requirement is available only after the tables exist.
