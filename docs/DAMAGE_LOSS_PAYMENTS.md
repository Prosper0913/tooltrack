# Damage & Loss Charges — FPST policy

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

Students pay for damaged or lost borrowed tools through the school. **Replacement is not part of this system:** there is no replacement request, ordering, receiving or resolution workflow. Tool recovery, repair, purchasing and disposal use the existing Tools Management and stock controls and never change a charge.

## Dashboard (Damage & Loss Charges page)

Every card is computed from database records on each load (`api/charge_cases.php?summary=1`). Nothing is hardcoded.

| Card | Meaning |
|---|---|
| **Unpaid Cases** | Cases with a charge and a remaining balance (includes partially paid). |
| **Payments to Verify** | Cases that have at least one pending payment entry. |
| **Paid Cases** | Cases whose confirmed, non-voided payments cover the charge. |
| **Disposed Tools** | Units in the disposal ledger (`tool_disposals`) that link to a matching `stock_history` disposal row. Shown only when the ledger exists. Admin can open the disposal history from the card; Staff sees the count only. |

A bulk (multi-tool) case counts once, never once per item. Clicking a card filters the queue; the list also supports status filter and search by student, tool, case or receipt.

## Daily workflow

1. Borrow and return as usual. A damaged return, or a loss confirmed by an Admin through Missing Tool Cases, becomes evidence. Reporting alone does not deduct stock.
2. **Report Damage / Loss** → pick the evidence and quantity (one tool, or **Add Multiple Tools** for a bulk case) → **Record Incident**. No inventory change.
3. Admin or Staff opens the case → **Set charge** (positive total, assessment notes).
4. **Record payment**: installment amount, school receipt/reference (optional — a blank receipt gets an internal `SYS-PAY-…` reference), payment date. The entry is *pending*.
5. Admin or Staff verifies against school records, ticks **actually received**, adds notes and **Confirm payment**. Pending or rejected entries never count as collected.
6. Repeat until the balance is zero: **Unpaid → Partially Paid → Paid**.

Voiding a confirmed payment requires **Admin** and a reason; the case returns to Unpaid and the original receipt, confirmer and void reason stay in history. Staff cannot void or change a charge that has confirmed payments. A charge cannot be reduced below confirmed collections. Paying never changes inventory.

## Reports

**Reports → Damage & Loss Charges** (Payment History) is unchanged: summary cards, installment history, filters (dates, student/tool/case/receipt search, incident type, case status, entry status) and CSV / Excel XML / Print exports share one endpoint (`api/reports.php`, `api/damage_loss_reports.php`). Charge/paid/balance columns are case totals repeated per row; do not sum them. Borrowers History continues to show the borrow, return and damaged/missing outcome.

## Removed in this release

- Replacement requested / ordered / received states, the **Receive** modal and its stock posting.
- Inventory-resolution endpoints, library and migration (`api/resolution_lib.php`, `api/inventory_resolution.php`, `api/migrate_resolution_receiving.php`, `api/migrate_inventory_resolution.sql`, `migrate_inventory_resolution.sql`).
- The "Damage & Loss History" completed-case report section and `case_completed_at` use.
- Replacement columns/filters in Missing Tool Cases and Reports; `replacement_requests.php` now serves only the charge actions.
- Docs `INVENTORY_RESOLUTION_SETUP.md`, `PHYSICAL_REPLACEMENTS.md`; test `receiving_workflow_cases.php`; stale `tests/charges_ui_check.ps1`.

Kept on purpose: historical audit labels (old log rows still read correctly), the historical `qty_replaced` display on old missing cases, and legacy table/file names (`replacement_requests`, `replacement_payment_claims`, `replacement_payments.php`) so existing data and links keep working.

Renamed: `assets/js/replacement-payments.js` → `charge-payments.js`, `assets/js/replacements.js` → `missing-cases.js`, `tests/replacement_workflow.php` → `tests/damage_loss_workflow.php`.

## Storage

- `replacement_requests`: case and charge data (`workflow_mode` `payment` = case or bulk parent, `payment_item` = bulk child).
- `replacement_payment_claims`: installments (pending / confirmed / rejected / voided).
- `replacement_request_events`: case audit events. `replacement_incident_sources`: exact evidence quantities. `audit_log`: department audit trail.
- `tool_disposals` + `stock_history`: disposal ledger behind the Disposed card.

Obsolete `inventory_resolution*` tables and `case_completed_at` are no longer read or written. They are **retained, not dropped**; drop them only after a verified backup and your own review.

## Migration

No schema change is required for this release on a database that already ran the earlier payment/bulk/disposal migrations. Otherwise:

~~~powershell
& 'C:\xampp\php\php.exe' backup.php backup
& 'C:\xampp\php\php.exe' api/migrate_replacement_payments.php
& 'C:\xampp\php\php.exe' api/migrate_bulk_charges.php '--database=tooltrack v2_db'
& 'C:\xampp\php\php.exe' api/migrate_tool_disposals.php
~~~

All are additive and repeatable. If `tool_disposals` does not exist the Disposed card is hidden instead of showing zero.

## Testing

~~~powershell
& 'C:\xampp\php\php.exe' tests/damage_loss_workflow.php
~~~

Creates an isolated `tooltrack_test_*` database, runs the payment, bulk-charge, disposal, SKU, student-import and stock/borrow/return suites (backup tests are separate: `tests/backup_workflow.php`) and removes it. Browser regression: `tests/ui/damage_loss_ui.js` (Playwright; set `TT_BASE`, `TT_DB`, `TT_DUMP`).

Latest results (sandbox, PHP 8.3 / MariaDB 10.11, cloned from the live database dump of Oct 31, 2026):

- **366 database/API checks passed.** One unrelated suite, student CSV import, is stale against `student_import.php` (it now requires Course/Section); it is guarded and not part of this module.
- **Browser regression, 110 checks (55 at 1440px, 55 at 390px), 0 failures:** borrow → damaged/missing return → report incident → set charge → record payment → confirm (refused without the received tick) → Partially Paid → Paid → Admin void → Unpaid; cards re-derived from SQL after each step; inventory invariant after each step; Payment History search by receipt; Borrowers History; Add Stock +6 with stock-history entry; no replacement text/controls on any page; removed endpoints return 404/405; no horizontal overflow; modal footer visible; no JavaScript errors; no PHP warnings.
- **Integrity on the live clone:** 0 orphan payments, 0 confirmed payments without verifier, 0 status mismatches, 0 inventory inconsistencies, 0 duplicate active receipts, 0 disposals without stock history. Migrations added/dropped no tables.
