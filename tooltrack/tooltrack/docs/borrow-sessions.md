# Borrow checkout sessions

## Cause and storage

Previously, `transactions` was both the transaction and its tool line: its `txn_id` was unique, and `borrow_batch` called `generateTxnId()` inside the tool loop. The existing `borrow_submissions` table prevented retry duplicates but was not a checkout header.

New checkouts create one `borrow_sessions` header. Each borrowed tool remains a row in `transactions`, linked by `borrow_session_id` and carrying the header's shared public `txn_id`. Its numeric `transactions.id` remains the loan-line identifier used by returns, missing reports, and damage incidents. The tool quantities, due dates/times, returns, missing counters, and status remain on their individual lines.

The header and every detail use one database timestamp. Header creation, submission-token response caching, stock checks/deductions, and borrower counts occur inside the same existing inventory transaction. All tools are locked and validated before creating the header. A failed line rolls back the entire checkout, including its header. The current confirmation form retains its existing submission-token and busy-button protections.

The public TXN number is generated once per checkout using random bytes. The header enforces its uniqueness. `transactions.txn_id` now has a nonunique reference index so multiple borrow lines can carry it. Return events keep their own public IDs and their existing per-line links.

## Migration and historical data

Run this on each deployment before confirming new checkouts:

```powershell
C:\xampp\php\php.exe api/migrate_borrow_sessions.php
```

The migration creates `borrow_sessions`, adds nullable `transactions.borrow_session_id` and its index, moves TXN uniqueness to the header, and registers the new table with existing live synchronization. The database account needs the existing migration permissions, including CREATE/ALTER and TRIGGER for change tracking. Re-running the migration is safe.

**Applied locally.** A before/after hash verified that all **24 existing transaction numeric IDs and public TXN IDs were unchanged**. No historical records were merged or renumbered. Older rows retain a null session link and appear as separate legacy checkouts, even when borrower and timestamp match.

## Views, returns, and reports

- Borrow history shows one checkout with its shared TXN, borrower, date, totals, and status. Expand it to see each tool's borrowed, returned, missing, and returned-damaged quantities, due date, status, and existing incident actions. Search covers the TXN, borrower, tool name, and code. Pagination counts checkouts and clamps an out-of-range page. Expanded groups survive history refreshes.
- The mobile history sheet also shows one expandable checkout, preserving its existing search/range controls and latest-record limit.
- Overall checkout status is derived from all its lines. A partial return leaves the checkout active while any line remains active with outstanding units. Closed/missing/resolved displays retain the existing incident rules; closing stock circulation does not erase missing accountability.
- Return and incident endpoints continue addressing numeric loan-line IDs. Returning or reporting one tool does not alter another line's quantities or status.
- Dashboard active/recent borrowing, weekly/monthly/trend counts, navigation/notification counts, and borrower borrowing totals count each shared borrow TXN once. Tool quantities and inventory conservation continue using the detail rows.
- Reports retain their existing tool-level rows, columns, actions, sorting, filtering, and CSV structure. Borrow lines repeat the shared TXN ID so quantities stay attributable to each tool. Transaction-report `transaction_count` counts distinct checkout/return events; `total` remains the number of tool/event rows used for compatible pagination. The UI explicitly distinguishes transaction count from line count. CSV contains every tool line, rather than dropping tools to deduplicate a checkout.
- Existing line-oriented API consumers still get loan rows by default. Borrow history opts into grouped responses with `type=borrow&grouped=1`. No return form is forced to consume headers instead of lines.

Legacy API clients without a submission token continue to treat each POST as a new checkout. The current Confirm Borrow form sends a stable token; clients retrying requests must also send one. Historical records are never guessed to belong together based on matching names or timestamps.

## Verification performed

- Focused isolated-schema suite: **40 checks passed**, including the browser integration below. Coverage includes two-tool checkout under one shared number; two units per tool; one persisted header; exact stock deductions; borrower/header/report counts; grouped search and pagination; partial returns; per-line damage and missing confirmation; closure with remaining missing accountability; duplicate and changed-payload retries; rollback when a second detail insert fails; successful retry after rollback; concurrent last-unit allocation; concurrent duplicate tokens; migration reruns; unchanged historical IDs; legacy returns.
- The focused suite was run with live-change database triggers installed.
- Real headless Chrome app workflow: **12 assertions passed**. Select two tools through the existing controls, open the existing review, press Confirm Borrow once, inspect the shared number, expand and refresh grouped history, return one line through the real API and inspect partial status, inspect mobile grouped history, check report counts, and fetch the real CSV. Charts/scanners are stubbed only in the offline test fixture; checkout, history, reports, and returns use real PHP APIs and an isolated database. No uncaught browser errors occurred.
- Existing full workflow suite: **559 checks passed** with the new schema and live-change triggers.
- Existing inventory/return/write-off browser checks: **48 passed**. Existing report interface checks: **270 passed**. Existing polling lifecycle checks: **52 passed**.
- PHP syntax checks passed for the modified backend and page files. Browser runners compile the JavaScript, and the integration test executes the actual modified scripts.

Run the focused API and browser workflow with:

```powershell
$env:TOOLTRACK_TEST_LIVE_TRIGGERS='1'
$env:TOOLTRACK_BORROW_SESSION_BROWSER_TEST='1'
C:\xampp\php\php.exe tests/borrow_session_workflow.php
```

The browser fixture expects Chrome at its normal Windows installation path. No production checkout/return was submitted during testing. Large-volume load testing and physical scanner/camera testing were not performed for this change.

## Files changed

- `includes/borrow_session_lib.php` (new)
- `api/borrow_history_lib.php` (new)
- `api/migrate_borrow_sessions.php` (new)
- `api/bootstrap.php`
- `api/transactions.php`
- `includes/missing_lib.php`
- `api/missing_tools.php`
- `includes/circulation_lib.php`
- `api/dashboard.php`
- `api/borrowers.php`
- `api/reports.php`
- `assets/js/reports.js`
- `assets/js/borrow.js`
- `index.php`
- `tests/damage_loss_workflow.php`
- `tests/borrow_session_workflow.php` (new)
- `tests/borrow_session_cases.php` (new)
- `tests/borrow_session_browser_cases.php` (new)
- `tests/tesda_browser_cases.php` (shared browser fixture)
- `docs/borrow-sessions.md` (new)
