# KITA App — Updated Capstone Checklist

Reviewed: 29 September 2026

80 tests passed; 692 assertions. Code review status does not replace acceptance testing.

## Cashier — 25 points

| Requirement | Status | Findings |
|---|---|---|
| View Dashboard | Partial | Checkout and My Shift Transactions are available; no separate cashier dashboard. |
| Log in using role-scoped credentials | Implemented | Email/password login resolves the account role; protected routes enforce access. |
| Start and manage a checkout transaction | Implemented | Cart creation, payment and transaction persistence are available. |
| Scan and validate item barcodes | Implemented | Barcode lookup validates catalog items; unknown codes open manual lookup. Physical scanner testing remains. |
| Receive duplicate-scan prevention prompts | Pending | Repeated scans increase quantity and show a toast; no duplicate confirmation/prevention prompt. |
| Search and manually look up a product when a barcode is unreadable | Implemented | Manual product search and lookup are available. |
| Adjust item quantity within a transaction | Implemented | Quantity changes are supported; checkout validates available stock. |
| Remove an item before payment | Implemented | Cart items can be removed before payment. |
| Apply eligible discounts (Employee, Senior/PWD, Promotion) | Partial | Discount calculations and PIN approval exist. Reason choices are PWD/Senior Citizen only, including other discount flows; eligibility and VAT-exemption consistency need review. |
| Request a price override (pending Manager approval) | Partial | A fixed 15% override is prepared locally and requires approval at checkout; no separate pending-request decision workflow. |
| Process cash payment and compute change | Implemented | Server computes totals, validates tender and saves change and stock movements. |
| Process e-wallet payment through PayMongo | Implemented | PayMongo checkout, signed webhooks, cancellation and refund handling exist; automated tests mock the provider. Live payment verification remains. |
| Generate a receipt with UUID and QR code | Partial | Printable receipt and transaction identifier exist; no QR-code generation found. |
| Initiate a refund, exchange, or return request | Implemented | Refund/exchange actions support manager approval, returned line quantities and stock updates. |
| View own transaction history | Partial | My Shift filters the current user and day in the UI; the shared data endpoint returns broader transaction data. |

## Manager — 30 points

| Requirement | Status | Findings |
|---|---|---|
| View Dashboard | Implemented | Manager dashboard is available with inventory and purchasing information. |
| Create Cashier accounts | Implemented | Account API supports manager creation and management of cashier accounts. |
| Approve or decline cashier price override requests | Partial | Manager PIN gates checkout discounts/overrides; a separate approve/decline request queue is absent. |
| Confirm a same-day void transaction via Manager PIN | Implemented | Server enforces same-day voids and validates the selected active manager PIN. |
| Verify and process refunds, itemized refunds, and exchanges | Implemented | Server supports manager-authorized refunds, itemized returns and exchanges with stock tracking. |
| Configure time-bound promotions and discount precedence | Partial | Date-range promotion UI and checkout precedence exist; promotion edits are held in browser state rather than persisted through an API. |
| Register new products, product variants, and unit conversions | Partial | Product registration and unit/conversion fields persist; complete variant creation and conversion workflows are not established. |
| Submit inventory adjustments with reason, comment, and photo evidence | Partial | Reason/comment adjustments persist and support approval; photo is a boolean, with no evidence upload workflow. |
| Record write-off, disposal, supplier return, and stock transfer movements | Partial | Adjustment and stock-movement foundations exist; dedicated disposal, supplier-return and transfer workflows remain incomplete. |
| Locate and flag inventory and past sales tied to a recalled batch/lot | Partial | Recall data is displayed; the flag action is unwired and no complete batch-to-past-sales recall workflow was found. |
| Conduct cycle, blind, and full physical inventory counts | Pending | Cycle/Blind/Full screen contains demonstration text; no persisted count-session or reconciliation workflow. |
| Receive and act on near-expiry monitoring alerts | Partial | Expiry dates are displayed; one monitoring view uses a fixed reference date and automated expiry alerts are not established. |
| Raise purchase requests based on stock review or low-stock alerts | Implemented | Purchase requests support item selection, quantities, submission and review. |
| Record supplier contact upon a receiving shortage | Partial | Receiving records shortages and notifications; supplier callback logging is displayed but a persistent contact-entry workflow is missing. |
| View sales and inventory dashboard and reports | Partial | Dashboard and inventory views work; general report generation/export remains incomplete. |

## Admin — 25 points

| Requirement | Status | Findings |
|---|---|---|
| View Dashboard | Implemented | Dashboard/Analytics screen is available. |
| Create Manager accounts | Implemented | Account API supports admin creation and management of manager accounts. |
| Complete two-factor authentication (email/SMS OTP) on login | Partial | Email OTP is implemented; no SMS OTP integration found. External email delivery needs environment verification. |
| Approve or reject Manager-submitted inventory adjustments | Implemented | Admin adjustment approval/rejection routes persist decisions and stock changes. |
| Approve purchase requests and convert them into purchase orders | Implemented | Request approval produces a purchase order through the server workflow. |
| Track purchase orders through their status lifecycle | Implemented | Purchase requests/orders track approval, waiting, partial and completed delivery states. |
| Review and approve three-way match discrepancies at receiving | Partial | PO-versus-received quantity inspection and shortage/excess records exist; invoice-based three-way matching and admin discrepancy approval are incomplete. |
| Maintain and update supplier records | Partial | Supplier creation persists through the purchasing API; supplier edits in the management screen remain browser-local. |
| View append-only audit logs and field version history | Partial | Audit and field-history data exist with no edit/delete route; a complete admin review screen and storage-level immutability are not established. |
| Generate and export daily, weekly, and monthly reports (PDF/Excel) | Partial | A printable purchase-order report exists; the general PDF/Excel report controls and download links are placeholders. |
| Receive Critical, Standard, and Informational notifications | Partial | Recipient-specific notifications, read state and polling work; generated workflow notifications currently use Standard priority only. |

## Super Admin — 20 points

| Requirement | Status | Findings |
|---|---|---|
| View Dashboard | Implemented | Live dashboard provides user totals, audit activity and application/database measurements. |
| Create Admin accounts | Partial | User-management UI and role rules support this intent, but /api/accounts routes currently exclude super_admin. |
| Complete two-factor authentication (email/SMS OTP) on login | Partial | Email OTP is required; SMS OTP is not implemented. |
| Manage system-wide Role-Based Access Control (RBAC) | Partial | Fixed middleware role checks exist; permission-matrix edits only change browser state. |
| Configure and monitor scheduled database backups | Pending | Backup/restore controls show demonstration messages; no scheduled backup or restore implementation found. |
| Access append-only audit logs across all roles and modules | Partial | Cross-module audit/history data and a read-only screen exist; complete event coverage and tamper-proof storage are not established. |
| Oversee soft-deleted (Inactive/Archived) records | Partial | Inactive account logic and archive data exist; some archive operations are browser-local, and account-management routing blocks super_admin. |
| Monitor overall system security configuration | Partial | Authentication and access protections exist; no dedicated security-configuration monitoring workflow was found. |
| View consolidated sales, inventory, and purchasing analytics | Partial | System dashboard covers users/activity/performance; consolidated sales, inventory and purchasing analytics are not complete for this role. |
