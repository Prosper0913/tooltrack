# Reservation removal — November 6, 2026

## Findings recorded before implementation

Reservation was embedded in shared circulation code, not a standalone page. The affected application files were `index.php`, `css/styles.css`, `assets/js/circulation.js`, `assets/js/borrow.js`, `assets/js/borrowers.js`, `assets/js/live-sync.js`, `api/circulation.php`, `api/circulation_lib.php`, `api/transactions.php`, and `api/borrowers.php`. The live-sync API also had an explanatory comment.

Dedicated components were `api/migrate_multi_reservations.php`, `tests/multi_reservation_cases.php`, `tests/ui/multi_reservation.js`, `tests/writeoff-reservations.log`, and six root `.reservation-*.php` editing/testing helpers. Shared migration, test harness, UI fixture builder, and circulation documentation needed targeted edits.

Database inspection confirmed these relationships:

- `tool_reservations.borrower_id` → `borrowers.id`
- `tool_reservations.tool_id` → `tools.id`
- `tool_reservations.fulfilled_transaction_id` → `transactions.id`
- `reservation_items.reservation_id` → `tool_reservations.id`
- `reservation_items.tool_id` → `tools.id`
- `transactions.reservation_item_id` → `reservation_items.id`

The migration also defined transaction checkout token/hash columns, unique/index constraints, item quantity constraints, and cancellation metadata. `circulation_policies` is shared with normal borrowing; only its pickup-window setting belonged to Reservation. Existing database tables, columns, constraints, records, generic live-sync triggers, and SQL backups have all been retained. No database cleanup migration was executed or supplied for automatic execution.

Main risks were removing shared policy checks or borrower locks, orphaning inline UI handlers, damaging historical transaction links, and leaving reservation stock holds active after removing the UI. Deleting root PHP session files can sign out sessions using those files. A pre-edit source archive was saved at `%TEMP%/tooltrack-before-reservation-removal.zip` (entries use basenames; restore to their original directories manually).

## Implemented changes

Removed the Reservation tab, selected-borrower button, checkout notice/linking, list/filter/pagination, borrower-history panel, creation modal, pickup-window setting, reservation-only styles, draft state, timers, handlers, and live-refresh branches. Removed reservation API listing, availability, creation, and updates; the shared circulation endpoint remains for borrowing policies and CSRF metadata.

Deleted backend functions: `installReservationItems`, `reservationRemainingSql`, `reservationItems`, `reservationRefresh`, `circulationExpire`, `circulationHeld`, `reservationCheckoutHash`, `reservationCheckoutReplay`, `circulationFulfilled`, `reservationList`, `reservationQuantity`, `reservationSchedule`, `reservationAvailability`, `reservationCreate`, and `reservationChange`.

Removed all reservation-only frontend functions, including list/load, draft editing and validation, availability, save/cancel/ready/details, fulfillment, checkout unlinking, and borrower reservation navigation. Kept `circulationFetch`, `circulationTab`, and borrowing policy load/save functions.

Normal checkout now uses physical availability without reservation holds. Existing borrower locking, active-unit limits, loan-duration limits, overdue policy, Admin override reasons/auditing, stock locking, borrow-session retry protection, and transaction rollback remain in place. Readiness now checks the shared policy table independently of reservation tables. The shared migration installs borrowing policies only.

Deleted the dedicated files listed above and all root `sess_*` files. Added `/sess_*` to `.gitignore`. Authentication and session configuration remain unchanged.

Modified application files: the ten application files listed in Findings, plus `api/live_sync.php` and `api/migrate_circulation.php`.

Modified support files: `.gitignore`, `tests/damage_loss_workflow.php`, `tests/lms_workflow.php`, `tests/ui/build_incident_runner.php`, `LMS_UPGRADE.md`, `docs/borrow-sessions.md`, and `docs/live-synchronization.md`. This report is new.

Dashboard, Tools, Borrowers, Borrow, Return, Reports, Replacements, Missing Cases, authentication, and Admin/Staff permission code were retained. No shared modal, database relationship, stock helper, scanner implementation, or unrelated module was deleted.

## Verification

- PHP syntax: 84 application and test files passed.
- JavaScript syntax: all 22 files in `assets/js` parsed successfully in Chrome.
- Isolated borrowing-policy suite: 47 checks passed, including rejected reservation list/availability/create/update requests, no reservation data in borrower history, Staff restrictions, Admin overrides, borrower limits, concurrent checkout, overdue handling, history pagination, returns, and Dashboard compatibility.
- Broader isolated API workflow: 441 checks passed before the five additional removal assertions were added; those five subsequently passed in the focused suite. Coverage included inventory consistency, missing/damage tracking, returns, SKU operations, student validation, and stock history.
- Real-browser borrow-session suite: 39 API checks passed before browser execution; the browser progressed through checkout, partial return, grouped history, report search/sorting, and expansion to a report detail width assertion. That assertion failed with `Tool details have a responsive contained scroll area`. A separate temporary copy using the archived pre-edit application files reproduced the identical failure. This existing report responsiveness issue was left outside the requested scope; the complete browser suite is not green.
- Source scan: no Reservation references remain in application PHP, JavaScript, CSS, or `index.php`. Historical SQL backups, retained database data, this audit report, test assertions, and verification logs intentionally describe the retired feature.
- Root `sess_*` cleanup confirmed. Production data was inspected read-only; test writes occurred in disposable schema clones.

Logs: `tests/reservation-removal-policy.log`, `tests/reservation-removal-regression.log`, `tests/reservation-removal-browser.log`, and `tests/reservation-removal-baseline-browser.log`.

Physical camera/scanner hardware and every manual Admin/Staff screen were not exhaustively tested. Passing automated checks reduce regression risk but cannot establish zero regression across all usage.
