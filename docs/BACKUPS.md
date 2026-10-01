# Database backups

Super Admin → Backup Configuration creates actual encrypted database snapshots, lists completed files, and downloads them. Only active Super Admin users can access these endpoints. Backups contain database records (including confidential account data), not uploaded files, source code, or environment settings. They are encrypted and authenticated with Laravel's APP_KEY. Keep a secure, separate copy of that key and the matching application version; losing the key makes these backups unrecoverable.

## Configuration and scheduling

Set these environment values, then refresh cached configuration if used:

```dotenv
BACKUP_TIME=03:00
BACKUP_TIMEZONE=Asia/Manila
BACKUP_RETENTION_DAYS=30
# BACKUP_PATH=/persistent/private/backups
```

The default directory is `storage/app/private/backups`, never public storage. Put BACKUP_PATH on persistent private storage on production. Ephemeral hosting loses local backups on redeploy/restart. Download encrypted copies to independent storage regularly; local copies alone cannot recover from server loss. Automatic offsite replication is not included.

Run `php artisan backup:run` for a manual command-line backup. Run `php artisan schedule:work` during local development, or configure the production scheduler to execute `php artisan schedule:run` every minute from the project directory. On Windows, use Task Scheduler with the PHP executable, argument `artisan schedule:run`, and this project as the working directory. The Docker entrypoint does not start a scheduler: configure one on the hosting service with access to the same persistent backup directory and database. Use one scheduling instance. Monitor scheduler failures in application logs. A listed schedule is not proof the scheduler is running; verify that new backups actually appear each day.

Scheduled successful backups prune files older than the retention period, always retaining the newest snapshot. Manual web backups do not prune. Backup and restore operations share a filesystem lock, requiring a shared lock-capable filesystem if multiple application instances use the same directory.

## Restore

Restoring is deliberately a server command, not an HTTP request. Never test restoration against production. First rehearse on an isolated database with the same application version and APP_KEY.

1. Download and securely preserve the snapshot. To recover on another server, place it in the configured private backup directory with its original filename.
2. Deploy the matching application version and migrate an empty database if recovering a lost database. The driver and column schema must match the snapshot. Restore is a data replacement, not a schema migration.
3. Stop queue workers, scheduled writers, and other database clients. Allow in-flight requests to finish. Enable maintenance mode on every application instance with `php artisan down`. Do not allow checkout or payment processing during recovery.
4. Run `php artisan backup:restore FILENAME --confirm=FILENAME`. The exact filename is shown in the dashboard's restore instructions. The command validates encryption and schema before writing, creates a safety snapshot, and replaces rows in a transaction. It leaves the app in maintenance mode whether it succeeds or fails.
5. Verify inventory, accounts, orders, and totals. Reconcile payments with the payment provider: restoring a database cannot undo external payments. Database sessions, login OTPs, queued/failed jobs, and database caches are cleared to prevent replaying old work. Clear external session/cache stores separately if used.
6. Run `php artisan up` on each instance and restart workers and scheduling only after verification.

SQLite and MySQL/MariaDB with InnoDB tables are supported. Snapshots preserve column values, including binary values, and omit generated columns when inserting. SQLite sequences/MySQL auto-increment counters may remain above their earlier values; gaps are expected. Schema objects, triggers, and routines are not reconstructed. Avoid schema changes while taking snapshots. MySQL snapshots use a repeatable-read transaction. The encoded row payload is limited to 50 MB and assembled in memory; larger installations should use native database/managed-provider backups. Browser backups are synchronous and subject to hosting request timeouts; use the CLI for slower databases. Backup completion does not establish any guaranteed recovery time or recovery point.

Reference: [Laravel task scheduling](https://laravel.com/docs/12.x/scheduling).
