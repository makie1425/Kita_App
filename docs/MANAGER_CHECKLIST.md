# Manager checklist completion

Implemented October 13, 2026:

- Promotions are saved on the server, validate dates and overlapping product/category offers, reject below-cost offers, and refresh at checkout. Existing discount precedence remains: override price first, then employee/senior discount or promotion.
- Product registration supports a parent product and named variants, with separate barcodes, stock and conversion factors. Duplicate, nested and circular variant relationships are rejected.
- Adjustment photo evidence accepts verified PNG/JPEG/WebP images up to 2 MB. Evidence is stored in the database so Render's ephemeral disk does not lose it, and is served only to authorized inventory users.
- Supplier returns remove quantities from a selected batch and product balance, record its original supplier, and preserve audit/movement history. Retried submissions do not deduct twice.
- Recall tracing searches exact batch or lot numbers and lists physical/sellable quantities and linked sales/payment reservations, including returned quantities. Receipt entry now records optional lot numbers. Sales predating batch allocations cannot be reconstructed automatically.
- Receiving discrepancy history supports dated supplier contact notes, channel, contact person, outcome and recorder. Contacts also appear in the purchase report. This records a conversation; it does not send messages.
- The expiry notification window is saved globally (default 7 days, allowed 0–365). Recipient/batch/date/stage deduplication and Critical expired notices remain in place.
- Managers can decline price overrides with a PIN and reason at checkout. Declines are recorded in audit history and restore original cart prices. Approval remains part of checkout; a declined override cannot be sold without a fresh approval.
- Expired/recalled physical returns remain quarantined and excluded from sellable quantity.

Activation requires normal migrations, including `2026_10_13_000001_complete_manager_checklist`. Local XAMPP MySQL migrations were applied successfully. Do not use database resets or demo seeding to deploy this update.

Validation: 161 backend tests (1468 assertions), seven frontend regression scripts, JavaScript syntax check, migration application on local MySQL, and read-only MySQL catalog/recall checks. Authenticated production UI verification is separate from these local checks.
