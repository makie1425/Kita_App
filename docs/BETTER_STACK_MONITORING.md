# Better Stack dashboard integration

Set these private Render environment variables:

- `BETTER_STACK_API_TOKEN`: read-only token with access to the monitor's team.
- `BETTER_STACK_MONITOR_ID`: `5173244` for the current KITA monitor.

Deploy the integration after setting these values. The token is used only by Laravel;
it must never be included in frontend variables, source control, or screenshots.
Existing Super Admin authorization protects the dashboard endpoint.

The backend reads the official monitor and monitor SLA endpoints:
https://betterstack.com/docs/uptime/api/get-a-single-monitor/
https://betterstack.com/docs/uptime/api/get-a-monitors-availability-summary/

Current status uses the provider's last check and effective check frequency. Checks
older than twice that frequency plus 60 seconds are unavailable. Pending, paused,
maintenance, and unknown states are not presented as operational. Recovery validation
is labeled Recovering. Failures never default to operational or zero downtime.

Uptime is the provider's percentage, and downtime is its reported seconds, since
monitor creation. These are explicitly labeled rather than represented as a full
24-hour observation. Historical monitoring is not reconstructed from app uptime.
The provider checks `/up`; this is not an end-to-end business transaction test.

Sanitized results and failures are cached in the application's file cache for 60
seconds. No credentials or raw monitor response are cached or returned. Dashboard
refresh remains every 30 seconds, while the external monitor checks every 3 minutes.
No laptop or local scheduled process is required. The CLI probe remains the fallback
only when no Better Stack configuration is supplied.

No database migration is introduced by this integration. RPM and recent errors
still require the existing operational_monitoring migration; Better Stack does not
populate those tables. Storage and RAM measurement behavior is unchanged.

Validation: run the BetterStackMonitoringTest and OperationalMonitoringTest suites,
the Node monitoring UI suite, PHP/JavaScript syntax checks and git diff --check.
After deployment verify status matches Better Stack, uptime shows a percentage with
the correct period, and downtime matches the provider. Live token permissions and
production connectivity cannot be verified without the deployed environment.

Local verification completed: full PHPUnit suite 265 tests / 2,821 assertions
passed; four Node monitoring UI tests passed. Changed PHP and JavaScript syntax
checks and whitespace checks passed. No live provider token was used in tests.
