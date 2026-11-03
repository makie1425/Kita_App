# Regression repairs

The ownership and monitoring changes exposed outdated fixtures and three application defects. Isolation remains enabled; no tests were skipped or marked incomplete to suppress failures.

## Application fixes

- Transaction approvals validate the assigned Manager's active workspace instead of requiring that Manager to appear in the Cashier's self-only user list. This restores valid refunds/exchanges without permitting cross-workspace approvals.
- Product updates retain omitted catalog metadata from the scoped existing product before validation. Foreign IDs are rejected before metadata is read.
- Purchase substitutions enforce both the submitted replacement cost and its catalog unit cost against the original approved unit-cost ceiling.

## Test corrections

`tests/Concerns/UsesWorkspaceFixtures.php` explicitly provisions one Manager workspace and grants selected test members access. Tests explicitly stamp their own fixture rows; there is no global auto-assignment hook or disabled security mode.

Workflow fixtures now include required brand, subcategory, measurement and supplier fields. Direct FIFO return fixtures include their original transaction. Cashier history fixtures identify the actual Cashier. Notifications use legitimate shared membership rather than unrelated Managers.

Obsolete expectations were updated to the intended rules: Super Admin manages Admin accounts only, database-wide backups remain server-operator actions, foreign IDs return 404, additional receiving budgets remain disabled, and login still requires OTP. Backup encryption/restore/concurrency tests remain covered through the server backup service. Receipt assertions preserve the full catalog display label.

Passing local tests do not replace production MySQL migration checks, live monitoring-source verification, or staging browser workflows. See MONITORING_VERIFICATION.md for the release checklist.

Final verification: full PHPUnit suite **260 tests / 2,804 assertions, all passing**; frontend monitoring component suite **3 tests, all passing**. PHP syntax checks passed for 35 changed/new PHP files; JavaScript syntax and whitespace checks passed. No monitoring/regression changes have been committed, pushed or deployed.
