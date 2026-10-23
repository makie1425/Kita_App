# Purchase orders and delivery receipts

The current workflow follows the supplied ECOUNT reference: the same PO stays open until its outstanding quantities have been accepted. Each delivery produces a distinct receipt ID and inspection snapshot. This replaces the former one-delivery-per-PO policy.

Example: order 50, accept 40. Stock increases by 40 and PO progress becomes Partially Received. Its receipt records 10 missing. Accept the remaining 10 on the same PO: stock increases by 10 and progress becomes Fully Received. Both receipt rows remain in Transaction History; the first receipt still says 10 missing.

Damaged units are returned, never stocked or charged as accepted goods. Replacement deliveries use the outstanding balance of the same PO. Excess acceptance requires manager notes. Each submission uses an idempotency key and receiving version to prevent duplicate stock.

Older Completed orders with an outstanding quantity can receive on the original PO. Existing receipt identifiers, inspection snapshots and linked carryover allocations are retained. Allocated legacy quantities must be received on their linked order or released before receipt on the source PO; they cannot be received twice. Legacy allocation endpoints remain compatible, but the normal interface no longer creates carryover POs.

Admins may cancel an outstanding remainder with a reason. Cancellation preserves stock already accepted, receipts, and payable balances. Existing unreceived carryover allocations must first be released.
