# Workspace isolation

Isolation is enforced in config/workspaces.php; the old environment toggle no longer disables it. These changes are local and have not been deployed or applied to production data.

## Authorization rules

- Super Admin lists, creates and manages Admins only. Business data is omitted from its bootstrap response; business routes and database-wide backup downloads are denied.
- A new Manager receives a new empty workspace. Only the creating administrator receives an explicit management membership in it.
- Cashiers receive a server-derived assigned manager and membership. A Manager/Cashier workspace must have exactly one active Manager owner; missing or ambiguous ownership fails closed.
- Admins require explicit active memberships. The workspace selector reloads the page to discard previous in-memory data and carts.
- Cashiers share their workspace's sellable inventory but see only their own transactions. The PIN selector exposes only their assigned Manager's minimal identity.
- WorkspaceRead, WorkspaceWrite, WorkspaceAccess and NotificationAccess enforce scope independently of frontend filters. Foreign record IDs return no record or an authorization/validation error.
- Purchasing, receiving/carryovers, supplier balances, inventory/FIFO, sales/refunds, reports/print documents, notifications and expiry settings use scoped queries and server-derived ownership. Payment callbacks use the verified transaction's persisted workspace.

## Database changes

Existing organization, membership, creator, assigned-manager and business workspace columns are reused. New migrations add nullable notification ownership, a workspace expiry-settings table, and workspace-specific catalog uniqueness. Categories receive a surrogate ID; name references remain scoped by workspace. Product barcodes and normalized category names may repeat across workspaces, not within one workspace.

Migrations preserve historical records and do not infer ownership from names or assign old rows to new accounts. Unknown ownership remains hidden pending review. Catalog rollback refuses conflicting duplicate names/barcodes instead of deleting them.

## Existing data and rollout

1. Take a verified database backup and test migrations on an isolated restored copy using the production MySQL version.
2. Run php artisan migrate --force on that copy, then php artisan workspaces:audit-ownership. Review null ownership, ambiguous Manager workspaces, membership conflicts and broken parent/child links.
3. Map each historical record/account using verified business evidence. Preserve creator/cashier identities. Grant administrative memberships explicitly. Do not infer assignments from stale notes, matching names or a new login.
4. Rerun the audit and ownership tests. Verify account creation emails and existing purchasing/checkout workflows before production rollout.
5. Apply reviewed migrations and mappings during maintenance, clear cached configuration, and verify role-specific sign-ins.

workspaces:assign-legacy is preview-only. --apply returns failure without mutation: assigning every historical record to one Manager is unsafe. See WORKSPACE_OWNERSHIP_AUDIT.md. No live data review or ownership mapping has been performed.

## Validation

Focused command: php vendor/phpunit/phpunit/phpunit --filter 'Workspace|InitialSuperAdmin|AccountAuditAndManagerSelector|SharedService'

The focused suite passes 40 tests / 228 assertions. It covers manager/cashier creation, isolated lists and dashboards, forged owner IDs, direct transaction/document URLs, cashier self-only access, valid checkout, foreign inventory rejection, explicit Admin scope, revoked memberships, purchasing/FIFO boundaries, notifications/settings, read-only auditing, catalog migration preservation and Super Admin route denial. EnsureRole no longer gives Super Admin a universal role bypass.

The broader legacy suite still requires ownership-aware fixture updates and review; do not make it green by disabling isolation. MySQL migrations and production delivery are not verified by SQLite tests. This local implementation awaits full regression and staging validation.
