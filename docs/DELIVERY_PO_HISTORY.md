# Delivery PO numbers and receiving history

The first receipt uses the approved purchase PO. When Receive Stock is used again on that PO, the application automatically creates one new delivery PO for all submitted items. The new PO and receipt are saved in the same database transaction. Failed validation leaves neither a new PO nor extra stock behind; retrying the same submission returns the saved receipt.

For example, receive 18 of 20 units under PO A, then submit the remaining 2 through Receive Stock on PO A. The second receipt uses PO B. PO A retains its original 18 received units and records 2 resolved on a later delivery. Both POs become Fully Received when their respective outstanding quantities reach zero. If another product on PO A is still missing, PO A stays Partially Received.

Delivery POs carry forward the original approved obligation without adding another purchase charge. Stock and batches are posted only for accepted quantities. Damaged units remain outstanding. Shortages that pass through multiple deliveries retain links back to the original purchase.

Transaction History displays one row per receipt, with the receipt's delivery status and the current PO status separately. The save confirmation displays the actual delivery PO number.

Existing receipts are not renumbered or reposted. Historical deliveries recorded under the same PO appear as separate receipt rows under that existing number. Do not receive those units again to obtain a new number: doing so would risk duplicating inventory.
