# Workspace isolation implementation status

This is an incomplete, local refactor. Do not enable WORKSPACE_ISOLATION_ENABLED or deploy these migrations as a completed isolation release.

## Implemented foundation

- Organizations, workspaces, explicit memberships, and stable creator ID/role references.
- No automatic ownership assignment for existing accounts or records.
- Account listing and target updates require an active account-management membership.
- Managers additionally require cashier creator ownership.
- Server-assigned ownership on cashier creation; submitted ownership values are ignored.
- An Admin or Super Admin role alone does not grant scoped account access.
- Isolation tests cover cross-manager reads/updates, forged ownership, unassigned admins, legacy names, and revoked memberships.

## Business read stage completed locally

- Nullable, indexed workspace references added to business tables; migrations do not assign legacy records.
- Shared catalog/bootstrap raw SQL replaced with explicit query-builder queries and WorkspaceRead filters.
- Inventory availability/history, batch calculations, and purchasing list reads are scoped to the active assigned workspace.
- Inventory history joins use scoped sources to avoid disclosing names through mismatched legacy links.
- Cashier bootstrap suppresses management datasets and filters transactions to the cashier identity.
- No role-based administrator bypass; unassigned/revoked membership returns empty business read results.
- Verification: 29 targeted tests, 301 assertions, including existing stock-receiving, sellable-stock, and inventory workflows.

This is read-path coverage only. Writes, direct document/report URLs, approval flows, notification delivery, account provisioning, workspace transfers, and legacy assignment are still incomplete. The rollout switch remains disabled. Do not claim the full system is isolated yet.

## Confirmed legacy ownership decision

The owner selected `lexterevans.1474` as the sole manager workspace owner for existing products, purchase orders, receipts, and generated transaction history on October 31, 2026.

The October 25 backup contains exactly one matching account: manager ID 4, Bhal Pimentel. Match the exact email/username and role against the current database before applying ownership; a backup identity is not proof that the live account is still active or unchanged. Abort on no match, multiple matches, an inactive account, or a role mismatch. Never choose the first available manager as a fallback.

Assign record scope without rewriting historical creator/cashier identities. Other managers receive no membership in this workspace automatically. Existing cashier management assignments and administrative grants must be established separately; assigning business-record scope does not transfer every cashier account or grant every admin access.

This decision has been recorded only. No production ownership has been changed yet.

## Required before rollout

1. Revalidate the selected owner against the live account and review the assignment report before migration. Do not infer access from matching display names.
2. Add workspace ownership and relational checks for products, suppliers, categories, brands, purchase requests/orders/lines, receipts/lots, movements, sales/lines/refunds, approval records, and adjustments.
3. Replace unrestricted data aggregation in KitaDataController, PurchasingController, ProductMasterController, reporting, exports and printable documents with scoped queries. Audit direct SQL separately.
4. Restrict manager PIN selection, approval notifications, supplier settlements, recalls, and evidence downloads to explicit assignments.
5. Scope asynchronous payment processing using the persisted transaction owner, never an untrusted request scope. Authenticate and verify the webhook before resolving its transaction.
6. Introduce authenticated workspace selection for multi-workspace administrators. Assignment and transfers need independent permissions and an audit trail; do not move historical records with a cashier transfer.
7. Restrict cashier transaction history to self while permitting assigned sellable catalog access.
8. Check cache keys, login/logout state, jobs, backup/download permissions, and cross-workspace validation/uniqueness errors.
9. Test two organizations, two manager scopes, assigned/unassigned admins, direct record URLs, exports, spoofed foreign keys, revoked access, and concurrent requests.
10. Enable only after all access paths are covered and existing ownership is approved. Run migration against an isolated restored backup first; preserve a recovery plan.

Existing production data and permissions have not been changed by this local foundation. New nullable ownership columns alone do not provide security.

## Catalog writes and purchasing documents completed locally

- Brand/subcategory create and update paths require active membership and assign workspace ownership on the server.
- Foreign catalog IDs return 404 before validation; foreign category references are rejected.
- Standard catalog imports use the assigned workspace, including their existence checks.
- Brand/subcategory uniqueness is workspace-specific; audit records inherit authenticated ownership.
- Direct PO order/report and receipt report lookups and their related datasets use scoped queries. Carryover OR predicates are grouped within the scope boundary.
- Verification: 31 targeted tests, 314 assertions. Includes forged ownership, foreign edit IDs/category references, revoked write access, and direct foreign document URLs.

Remaining receiving/checkout/approval writes, background processing, account provisioning, and legacy assignment still prevent rollout. No live records have been assigned or changed. The feature switch remains disabled.

## Password setup and recovery implemented locally

New account forms use First Name and Last Name. The server generates a random 24-character temporary password, emails it to the registered address, and requires password setup before business access. Temporary credentials expire in 24 hours; reissues invalidate older credentials and pending login OTPs. Existing accounts default to password setup complete and retain their credentials.

Password recovery uses a separate hashed, single-use 5-minute email OTP with attempt limits and resend throttling. Verification creates a short-lived session-bound reset authorization before showing New Password and Confirm New Password. Password changes advance a credential version checked on every web request, invalidate pending OTPs, rotate remember tokens, log the event, and send an email notification.

Account-management resend/reset actions require explicit active workspace account-management membership, even while the wider isolation flag remains disabled. Direct administrator password edits are prohibited. Public recovery is available from the login page. Mail delivery must be verified on the production mail transport before release; local tests do not demonstrate live inbox delivery.

The combined isolation rollout is still incomplete: account provisioning/assignments and all remaining business access paths must be finished before enabling or deploying the full isolation release.

## Account provisioning stage

With isolation enabled, new Managers get an empty workspace within the creator's assigned organization. Only the creating administrator receives a management membership in that new workspace. Cashiers inherit the creator's workspace and receive a non-management membership. Other administrators receive no automatic grant.

Administrator workspace selection validates an active explicit membership; managers cannot switch workspaces. A read-only `workspaces:assignment-preview` command requires an exact unique active Manager identity and reports unassigned/already assigned business row counts without changing ownership. Legacy cashier and admin mappings are pending user input. No live assignments or deployment have been performed.

## Explicit legacy account assignments provided by owner

The October 25 backup verifies the provided account emails and roles:

- Workspace owner Manager: `lexterevans.1474@gmail.com` (Bhal Pimentel).
- Admin workspace access: `markkmarzo@gmail.com`.
- Super Admin workspace access: `markkiel.marzo@dwcc.edu.ph`.
- Assigned Cashier: `markkiel97@gmail.com`.

The selected Cashier will receive a separate assigned-manager link; historical `createdBy` remains unchanged. The Admin and Super Admin receive explicit workspace membership with account-management permission as supplied by the owner.

Use `php artisan workspaces:assign-legacy lexterevans.1474@gmail.com --admins=markkmarzo@gmail.com --superadmins=markkiel.marzo@dwcc.edu.ph --cashiers=markkiel97@gmail.com` to preview. Review every unassigned business-table count and identity; use the same command with `--apply` only after deploying migrations and taking a verified database backup. This command has not been run against production. Local execution could not connect to the configured MySQL server at 127.0.0.1:3306. The account assignment preview/apply behavior is covered by a feature test.
