# Grocery presentation records

This dataset is synthetic presentation history, not evidence of actual purchases or sales.
Displayed names use ordinary grocery labels. Supplier addresses use reserved invalid
email domains; the loader does not send messages or initiate payment requests.

Run `php artisan presentation:load-grocery ADMIN_EMAIL MANAGER_EMAIL` for a read-only
preview, then add `--apply` to load. Roles, active accounts, unique manager ownership,
and the administrator's workspace management membership are checked first.

The manager's existing workspace must have no products, transactions, purchase orders,
suppliers, categories, brands, or subcategories. Existing records are never deleted or
reassigned. The import is transactional; a failed import rolls back entirely. A workspace
row lock and deterministic transaction IDs protect against concurrent/repeated loads.

Contents: 50 products, 10 categories, 10 subcategories, 10 suppliers, 150 received purchase
orders, 150 receipt-linked batches, one cancelled purchase order, and 150 cash sales
across January–October of 2024, 2025 and 2026. Sales are attributed to the verified
Manager, who can use checkout, rather than inventing cashier accounts. Historical batches
sell out; current-year batches retain stock. Actual FIFO, stock movement, checkout,
transaction numbering and sales logging services are used. No fictitious payment-provider
confirmation is created. These records affect workspace reports and supplier balances.

For Render deployments without shell access, set:

```
LOAD_GROCERY_PRESENTATION=true
PRESENTATION_ADMIN_EMAIL=markkiel97@gmail.com
PRESENTATION_MANAGER_EMAIL=lexterevans.1474@gmail.com
```

Deploy the new code and confirm the success message in startup logs. Then disable
`LOAD_GROCERY_PRESENTATION`. If validation refuses the workspace, do not clear data to
bypass it: arrange an empty workspace/account relationship instead. A rejected import
prints diagnostics and does not prevent the web application from starting. A running
application is not evidence that the import succeeded; check the explicit import result.

Local tests cover role/membership checks, preview without writes, product/batch/sales
counts, workspace ownership, stock reconciliation, and repeat safety. Production account
roles and live loading remain unverified until the command executes in Render.
