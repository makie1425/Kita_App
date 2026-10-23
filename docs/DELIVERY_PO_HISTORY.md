# Delivery PO numbers and receiving history

Recording a receipt completes that delivery, even when some items are missing or damaged. The receipt uses the approved PO. Receive Stock cannot post a second delivery against that completed PO; retrying the same submission returns the saved receipt without adding stock again.

For example, order 50 and receive 40 under PO A. The transaction is completed and its remarks say "10 missing." If 3 of the remaining 10 arrived damaged, the remarks instead say "7 missing, 3 damaged — returned, not received." Only the 40 accepted units enter inventory.

The manager may choose Carry to next delivery in the optional follow-up panel. Select an existing approved delivery PO from the same supplier, or create a new one automatically when confirming that choice. Nothing is carried or created merely because a receipt has missing or damaged items. These carried quantities do not add another purchase charge. Damaged units must be returned and are never accepted into inventory; optional replacements are received separately on the selected future PO.

Stock Receiving, Transaction History, and receiving reports display Remarks instead of receiving status. Remarks use the saved inspection and never change when a later delivery fulfills the shortage. Purchase approval statuses remain separate from delivery remarks. Transaction History displays one row per receipt.

Existing receipts are not renumbered or reposted. Historical deliveries recorded under the same PO appear as separate receipt rows under that existing number. Do not receive those units again to obtain a new number: doing so would risk duplicating inventory.
