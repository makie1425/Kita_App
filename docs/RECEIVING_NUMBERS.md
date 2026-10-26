# Receiving numbers

New records use `PREFIX-MM-DD-YY-NNNNN`, for example:

| Record | Example |
| --- | --- |
| Internal batch / lot | `BATCH-10-26-26-00001` |
| Stock receipt inspection | `SR-10-26-26-00001` |
| Receiving record for one PO | `RCV-10-26-26-00001` |

The date is the server's issue date in the configured application timezone, not
the entered delivery date. Each prefix has its own daily sequence, padded to at
least five digits. Allocation uses a database lock and participates in the
receiving transaction. Existing matching identifiers are skipped. Retrying an
already saved transaction returns its original receipt number.

Both direct receiving and inspected physical lots use the BATCH sequence.
Manufacturer/supplier lot references remain separate supplier-provided values.
A stock receipt can cover several POs and physical lots; those records keep
their own identifiers and existing links. Historic identifiers are not rewritten.

No additional migration or data reset is required; counters use the existing
inventory_sequences table.

## Shared delivery batches

One confirmed receipt gets one BATCH number and one posting timestamp across all
products and PO allocations. Separate confirmations remain separate batches even
if their timestamps happen to match. Product lots use the shared batch plus an
L001 suffix; supplier lots, expiry dates, costs, and original PO links remain
separate. Inventory batch records and recall selection are grouped by batch.
Recall actions remain specific to the selected product stock entry.

Managers must complete delivered and damaged quantities for every outstanding
primary-PO item (explicit 0 for none), cost, delivery date, supplier document
reference, and notes. Accepted lots require a supplier lot, manufacturing date,
and expiration date or an explicit No expiration date selection for a
non-perishable product. Damage and substitution details are required when those
conditions apply. Automatic identifiers and calculated quantities are read-only.
Historic receipts are preserved; existing pending approvals retain their saved
payload and are still revalidated against current quantities before posting.

## Standardizing older records and printing

Migration `2026_10_26_000001_standardize_legacy_batch_numbers` assigns standard
BATCH-MM-DD-YY-NNNNN labels to older inventory entries, using the recorded receipt
date. Receipt/physical-entry keys, supplier references, quantities, and costs stay
unchanged. Entries with the same saved stock receipt or delivery link are grouped;
matching timestamps alone are not sufficient. The previous number is retained in
batch_number_history. The migration is idempotent and keeps its history on rollback.
Run the normal `php artisan migrate --force` deployment step to update old labels.

Every batch group has Print batch. The authenticated print route reads all current
entries for that batch from the database, independently of UI filters/pagination.
Managers and administrators can print or save the report as PDF.
