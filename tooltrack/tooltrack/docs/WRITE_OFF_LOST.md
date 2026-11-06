# Admin-approved Write Off as Lost

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

Implemented in the existing PHP/PDO/MariaDB incident workflow. No historical incidents, loans, returns, financial records, tools, or disposal records are deleted or migrated to new outcomes.

## Stock model and behavior

`tools.quantity` is the canonical active owned total and includes active missing units. The invariant is `quantity = available + damaged_qty + missing_qty + outstanding loans`; `minor_qty` is a subset of available. Missing confirmation moves loan units to the missing bucket without reducing total. Approved write-off reduces quantity and missing_qty by exactly the selected number, once. Availability and loan rows are unchanged. Stock additions remain possible for retained zero-stock SKUs.

Damage & Loss Tracking → View Details exposes staff requests, admin direct write-off, approval/rejection, exact stock confirmation, and decision history. Linked missing incidents use their original Missing Report as the authoritative case; manual shelf-loss cases are also supported. Existing Record Recovery handles active missing units. Recovery after Write-off selects an individual approved write-off, requires quantity/condition/inspection notes, restores total once, and increases available only for good/minor condition. Damaged later recoveries create a separate Pending Review damage case, linked by the write-off reference in its notes and audit event.

Only one pending request per incident is permitted, conservatively preventing overlapping unit claims. Approval checks eligibility again after locking current tool and incident rows. Request tokens bind actor and exact payload; replay does not repeat stock movements. The browser retains the exact payload/token after an uncertain response. Transactions include decision, stock, incident counters, stock-history row, and durable loss audit event. A failure rolls everything back. A conflicting concurrent action may require refresh/retry.

Internal missing statuses retain the existing pending/confirmed/resolved/rejected model. Displayed outcomes derive from quantities and evidence: Missing, Partially Closed, Closed — Recovered, Closed — Lost, and Closed — Mixed Outcome. Historical replacement/Resolved records without recovery/write-off evidence retain their historical interpretation. The existing Resolved queue filter includes the new fully closed outcomes for compatibility; individual new-outcome filters remain available.

## Setup

The existing inventory-consistency, stock-history, and incident schema migrations must already be installed. Run before deploying these application files:

```powershell
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\htdocs\tooltrackv2 api/migrate_writeoffs.php
```

The additive, rerunnable migration adds `missing_reports.qty_written_off`, `qty_late_recovered`, `loss_writeoffs`, and `loss_writeoff_events` with incident/status, borrower, timeline and unique token indexes, plus an event-to-write-off foreign key. No historical rows are updated. It has already been applied to this local database and rerun successfully.

The review reminder defaults to 14 calendar days in the application's Asia/Manila timezone. Set the Apache/PHP environment variable `TOOLTRACK_LOSS_REVIEW_DAYS` to a positive number to change it; restart Apache after changing its environment. Age uses missing_date for Missing Reports and created_at for manual cases. The reminder never performs an automatic stock or accountability action.

## History, reports and accountability

Tools actions include Written Off / Lost History. Reports has a paginated, searchable, column-selectable loss-history report and CSV export; tracking and history support Active missing, Needs Review, Pending write-off and final-outcome filters. Existing Missing Tools report/CSV retains its existing columns/preferences and adds gross written-off, later recovered, net loss and approval information. Stock History distinguishes Write Off as Lost and Recovery after Write-off, including their effects in daily added/removed totals. Dashboard and inventory reports continue to read the canonical tool balances.

Loss History has one row per request/decision, including rejected requests; incident totals repeat across those rows and must not be summed repeatedly. Gross written-off quantity remains historical even after later recovery. Net outstanding loss subtracts later recoveries. Original event history is always retained.

The current application has retired its active charge/payment workflow. The smallest added separation is a durable borrower-linked `accountability_status = Review required` on each write-off, visible in loss details/history, plus `loss_accountability_review_count` in the borrower list API. Writing off or recovering stock never updates borrower restrictions, payment/replacement records, loan missing/returned counters or compensation. There is no new settlement/payment UI: settlement and release of accountability require a separate reviewed workflow. Existing historical financial evidence remains untouched.

## Files changed

- New backend: `api/writeoff_lib.php`, `api/writeoffs.php`, `api/migrate_writeoffs.php`, `api/loss_report.php`.
- Existing backend: `api/case_inventory.php`, `includes/missing_lib.php`, `api/missing_tools.php`, `api/tools.php`, `includes/stock_lib.php`, `api/reports.php`, `api/borrowers.php`.
- UI: `index.php`, `assets/js/writeoffs.js` (new), `assets/js/charge-payments.js`, `assets/js/charge-workflow.js`, `assets/js/missing-cases.js`, `assets/js/tools.js`, `assets/js/reports.js`.
- Tests: `tests/damage_loss_workflow.php`, `tests/writeoff_workflow.php` (new), `tests/ui/writeoffs.js` (new), `tests/ui/build_consistency_runner.php`, regenerated `tests/ui/consistency-runner.html`.
- This setup document and verification logs under `tests/`.

## Verification actually run

All API tests clone schema into randomly named disposable `tooltrack_test_*` databases, create isolated fixtures, check stock conservation after API actions, and drop those databases afterward. No production fixtures were added.

```powershell
$env:TOOLTRACK_WRITEOFF_TEST='1'
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\htdocs\tooltrackv2 tests/damage_loss_workflow.php
# 84 checks passed; tests/writeoff-results.log
Remove-Item Env:TOOLTRACK_WRITEOFF_TEST
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\htdocs\tooltrackv2 tests/damage_loss_workflow.php
# 512 checks passed, plus the separately reported pre-existing student-import validation failure
# tests/writeoff-regression.log
```

Focused coverage includes full/partial write-offs, pending/rejected requests, staff denial, CSRF, invalid quantities, exact-stock confirmation, repeated payloads, concurrent identical and distinct approvals, recovery during a pending request, transactional audit failure rollback, later good/damaged recovery, separate damage case creation, multiple cases per SKU, zero stock and future additions, unchanged loan/borrower records, persistent accountability review, reminder/pending/outcome filters, actual CSV output, dashboard and stock-history summary consistency.

The existing full suite covered borrowing, mixed partial returns, normal recovery, damaged case handling, disposal, multi-reservations, report pagination/columns and stock additions. Separately ran inventory consistency (44 checks) and multi-reservation (90 checks). All API PHP files and index.php passed syntax checks. Migration reruns succeeded.

Headless Chrome ran the generated consistency runner: 46 UI/syntax checks passed (`tests/ui/writeoff-browser-results.log`). These include actual write-off JS with DOM/API stubs, staff controls, reminder, escaping, quantity/notes validation, cancelled confirmation, exact impact text, and identical payload/token reuse after an uncertain response. They also retain existing Borrow/Return/disposal UI checks and parse changed scripts including Reports.

Limitations: no full signed-in visual/responsive walkthrough or real scanner/camera test was performed. The full suite continues to report its known manual-registration versus CSV student academic-validation mismatch; this unrelated import behavior was not changed. The loss-history export currently materializes matching rows in memory, appropriate for the current small inventory but worth converting to streaming for very large histories.
