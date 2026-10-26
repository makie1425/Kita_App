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
