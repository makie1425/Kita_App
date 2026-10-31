# KITA replacement sample data

The owner authorized replacement of the live product catalog and business history on October 25, 2026, while preserving existing accounts and login settings. These are generated sample records, not real business activity.

## Included examples

- 24 products, seven categories and four suppliers.
- Sales in October 2025 and April–October 2026, with varied days and hours.
- Fully received orders, orders awaiting delivery and pending/declined purchase requests.
- Missing goods and damaged replacement obligations recorded separately.
- One receipt containing 30 new units and five units against an earlier PO.
- An approved equal-or-lower-cost substitution and a receipt awaiting cost approval.
- Multiple physical lots, manufacturer references and expiration dates.
- Expired stock, expiry within seven days, expiry within thirty days, low stock and zero stock.
- A supplier recall, write-off and customer refund.

## Building a new isolated artifact

Run `php scripts/build-sample-dataset.php YYYY-MM-DD` with an explicit presentation date. The script creates a new SQLite file under `storage/app/private/sample-data/`; it does not change the configured live database. It exercises the application's receiving and checkout services in chronological order and checks product-to-batch balances.

Each artifact includes a scenario manifest. Keep exports, database credentials and backups outside the public directory and Git. Never import sample users over existing users. A live replacement requires a full backup, actor-reference mapping, collision-free product IDs, transaction-protected replacement, relationship checks and verification that existing accounts were preserved. Do not put dataset replacement into deployment startup.

Expired stock was accepted on an earlier date when it was still eligible; it is not inserted as a new expired delivery. Expiration examples age naturally after the anchor date.
