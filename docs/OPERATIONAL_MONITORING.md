# Operational monitoring

## Data sources and limits

- Storage: real usage/capacity of the filesystem containing Laravel storage. This is **not** Aiven database usage or app-directory size. Shared/container filesystem capacity can differ from the purchased service quota; the dashboard labels the scope.
- RAM: Linux cgroup v2 or v1 container usage/limit. Missing files or unlimited limits display Unavailable. PHP process memory is not substituted for container RAM.
- RPM: atomic database counters for requests reaching Laravel, including health checks, monitoring polling and denied requests. Static assets served directly by nginx are excluded. The chart contains the last 30 completed UTC minutes; missing buckets remain gaps, not invented zeros.
- Availability: external probe observations. Adjacent samples up to 90 seconds apart describe a sampled interval; larger gaps are unknown. Totals cover the last 24 hours. Latest status expires after 90 seconds. This is sampled availability, not exact second-by-second outage detection.
- Errors: Laravel error/critical/alert/emergency log events are stored with actual severity, class/component and timestamp. Raw messages, SQL, stack arguments and request payloads are deliberately not copied into the dashboard because they may contain credentials or business records. Sanitized categories are shown; detailed diagnostics remain in protected server logs. Database outages can prevent dashboard error persistence; original logging continues.

All monitoring and error-detail/status routes require an active, authenticated Super Admin. Ordinary roles cannot access these routes. Business roles remain Admin, Manager and Cashier; the privileged account is preserved separately. Existing permission planning switches remain browser-only and are explicitly labeled.

## External monitor setup (required before verification/deployment)

The user currently has no external monitor. Uptime, downtime and status therefore remain Unavailable. Do not run the probe only inside the Render web service: it cannot record its own outage while stopped.

On a trusted, separate always-on host, install this application's locked PHP dependencies and configure its database connection to the monitoring tables on Aiven. Configure `MONITORING_PROBE_URL=https://kita-app-ukr8.onrender.com/up`. Keep the credentials in environment secrets, not source control. Schedule `php artisan monitoring:probe` every minute, with one instance and no overlapping jobs. The command records successful health responses or connection/non-2xx failures. Direct database access allows recording a web-service outage; loss of the monitor/database results in unknown gaps.

The probe prunes observations after 7 days, counters after 2 days and errors after 30 days. Verify the external scheduler is operating, retention executes, and timestamps are correct. Health probing may keep a free Render service awake; account for the chosen hosting plan's usage limits before enabling it.

## Migration and verification

The new migration creates only `monitoring_requests`, `monitoring_checks` and `monitoring_errors`; it does not rewrite business tables. Test against a restored production-version MySQL database before rollout.

Run `php vendor/phpunit/phpunit/phpunit --filter OperationalMonitoringTest` and `node --test tests/monitoring-ui.test.cjs`. Also run the ownership suite and the full application regression suite. On staging, verify real container memory/filesystem readings, request counts under known traffic, an outage/recovery from the external host, stale/gap handling, a sanitized logged error, and protected error-status changes. Test responsive layouts and account/business workflows.

Do not deploy while these live-source checks or the existing full-suite failures remain unresolved. Local tests use injected observations for outage cases; they are not proof that an external monitor is installed.
