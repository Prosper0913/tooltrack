# ToolTrack circulation upgrade

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

The existing Borrow/Return APIs, scanner, borrower profile, notification bell, transaction table, Dashboard and Damage & Loss Tracking are retained. No financial features or new sidebar modules were added.

## Reuse and behavior

- **Monitoring:** `includes/missing_lib.php` owns shared due-time/outstanding rules. Loans without a due time are due at the end of the date. PHP and database connections use `APP_TIMEZONE` (Asia/Manila). Active quantities are borrowed minus returned minus confirmed missing. Fully returned/resolved loans leave active overdue lists.
- **Notifications:** the existing `overdue_summary` report supplies overdue and due-today records to the existing bell, without a notification table. Each borrowing event appears in one due-state group. The bell refreshes after checkout/return and every minute while the application is visible. Existing low-stock and pending-missing notices remain.
- **Borrowing views:** Checkout / Scan, Active Borrowings, Overdue and History live inside Borrowing Management. Active/Overdue/History move and reuse the existing transaction table; its Reports card still works. Existing scanner code and camera handlers are preserved.
- **Borrower history:** the existing modal now searches, filters and paginates transactions while keeping full-record accountability totals. It shows transaction IDs, quantities, borrowed/due/returned dates, current loan status and the existing deduplicated damage/loss history. No history table was added.
- **Policies:** Admin configures Settings → Borrowing Policies. Blank maximum quantity/duration means unlimited. Types without a specific saved rule inherit the default. Limits count outstanding units. Loan duration is measured from actual checkout time. Defaults preserve existing unlimited borrowing and allow additional borrowing with overdue loans until configured.
- **Overrides:** Admin can provide a reason in the existing checkout form for quantity, duration or overdue-policy exceptions. The existing audit log records actor, timestamp, violated policy and reason. Inactive accounts, past due times, physical stock cannot be overridden.
- **Concurrency:** tool rows are locked before borrower checks. Borrower locks serialize limits across simultaneous checkouts of different tools. Database transactions preserve inventory and checkout together.

## Migration

Run on other installations:

```powershell
& C:\xampp\php\php.exe api/migrate_circulation.php
```

The rerunnable migration creates borrowing policies only. Existing borrowers, tools, transactions, audit history, and legacy database relationships remain intact.

## Changed files

- New: `includes/circulation_lib.php`, `api/circulation.php`, `api/migrate_circulation.php`, `assets/js/circulation.js`, `tests/lms_workflow.php`.
- Extended: `api/bootstrap.php`, `includes/missing_lib.php`, `api/transactions.php`, `api/borrowers.php`, `api/reports.php`, `api/missing_tools.php`.
- UI integration: `index.php`, `css/styles.css`, `assets/js/app.js`, `assets/js/borrow.js`, `assets/js/return.js`, `assets/js/borrowers.js`, `assets/js/reports.js`, `assets/js/charge-payments.js` (the existing shared details modal title).
- Test harness: `tests/damage_loss_workflow.php` installs its required schemas in its isolated database and includes circulation tests.

## Validation

```powershell
# Focused circulation suite, in a disposable test database
$env:TOOLTRACK_LMS_TEST='1'
& C:\xampp\php\php.exe tests/damage_loss_workflow.php

# Full regression harness
Remove-Item Env:TOOLTRACK_LMS_TEST
Remove-Item Env:TOOLTRACK_AUTO_CASE_TEST -ErrorAction SilentlyContinue
& C:\xampp\php\php.exe tests/damage_loss_workflow.php
```

- 69 focused circulation checks passed.
- 81 existing incident checks passed.
- Broader run: 339 checks passed, with the pre-existing student registration/CSV academic-validation mismatch reported separately.
- Coverage includes overdue transitions, partial/full returns, borrower history, permissions, policy overrides and simultaneous borrower-limit enforcement.
- PHP syntax and changed JavaScript syntax/shared-scope checks passed. DOM-stub integration checks confirmed reuse of the loan table and checkout/cart/picker helpers.
- Actual browser appearance and camera/USB scanning hardware were not tested. The scanner implementation remains unchanged; checkout only adds override metadata to its existing request.

Existing disposal installations still use their existing disposal migration; the circulation migration does not create a second disposal workflow. Historical charge/payment features remain retired.

