# Ownership change summary

## Main files and responsibilities

- `app/Http/Middleware/EnsureRole.php`: removes the unconditional Super Admin role bypass.
- `app/Services/WorkspaceRead.php`, `WorkspaceAccess.php`, `WorkspaceProvisioning.php`, `config/workspaces.php`: enforce active memberships, independent Manager scopes, server-assigned Cashier ownership and Admin-only Super Admin account visibility.
- `app/Http/Controllers/AccountController.php`, `KitaDataController.php`, `SuperAdminDashboardController.php`: scoped account lists/actions, OTP summaries, audit ownership, minimal assigned-manager selector and restricted Super Admin bootstrap.
- `TransactionController.php`, `PayMongoController.php`, `SaleCheckout.php`, `FifoInventory.php`, `StockMovement.php`, `DiscountApproval.php`, `ManagerApproval.php`: sale/return/payment ownership, foreign-ID rejection, manager approval and persisted webhook scope.
- `PurchasingController.php`, `PurchasingWorkflowController.php`, `OperationsController.php`, `PurchaseBudget.php`, `PurchaseWorkflow.php`, `PurchasingReports.php`, `ReceivingCarryover.php`, `ReceivingDelivery.php`, `StockReceiptWorkflow.php`, `SupplierAccounts.php`: scope purchasing reads/writes, child references, receiving, carryovers, supplier settlements and reports.
- `ProductController.php`, `CategoryController.php`, `InventoryWorkflowController.php`, `ManagerWorkflowController.php`, `ReportController.php`, `BatchReportController.php`, `ArchiveReferences.php`, `BatchPrintReport.php`, `BatchTrace.php`, `InventoryAvailability.php`, `InventoryRules.php`: catalog, inventory and reporting boundaries.
- `NotificationController.php`, `NotificationAccess.php`, `WorkflowNotifications.php`, `ExpiryMonitoring.php`: recipient/workspace visibility and workspace expiry settings.
- `BackupController.php`: disallows database-wide backups through scoped business accounts; server-operator backup commands remain available.
- `SeedInitialSuperAdmin.php`, `config/initial_super_admin.php`: creates an explicit initial workspace only for a fresh single-account installation; does not adopt historical records.
- `routes/web.php`, `routes/console.php`: explicit route roles; historical bulk assignment disabled while read-only previews remain.
- `public/app.js`: Super Admin creates Admins only; Admin workspace selector reloads scoped state.

## Database changes

Three migrations add nullable notification ownership, workspace expiry settings, and workspace catalog uniqueness. Existing ownership columns and relationships are reused. Categories gain an ID while name references retain their workspace constraints. Existing records are preserved; unknown ownership is not automatically assigned. Rollback refuses duplicate catalog identifiers rather than deleting data.

## Review and tests

`WorkspaceOwnershipAudit.php` provides a read-only ownership review command. No live database has been inspected, migrated, reassigned or deleted during this work. Audit findings require verified ownership mapping before rollout.

Focused PHPUnit coverage: 40 passing tests / 228 assertions. Includes the requested Manager/Cashier isolation scenarios, Admin membership, Super Admin route denial, valid checkout, foreign URLs and owner IDs, catalog preservation, receiving/FIFO, reports and settings. PHP syntax checks passed for 60 changed/new PHP files; JavaScript syntax and git whitespace checks passed.

The complete suite ran 257 tests / 625 assertions, with 143 failures and 30 errors. Unassigned fixtures and old global-access expectations account for many failures, but each remaining failure still needs review; focused passing tests do not prove all existing workflows are preserved. Do not disable isolation to make those tests pass. Production MySQL migration validation and a browser workflow check also remain outstanding. Nothing has been pushed or deployed.

See `WORKSPACE_ISOLATION.md` for authorization and rollout instructions, and `WORKSPACE_OWNERSHIP_AUDIT.md` for ownership audit usage.
