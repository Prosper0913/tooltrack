# Shared PHP includes — November 6, 2026

Only the five approved reusable libraries moved. Their filenames and contents are unchanged:

| Original path | Current path | Purpose |
| --- | --- | --- |
| `api/student_validation.php` | `includes/student_validation.php` | Shared student normalization and validation |
| `api/stock_lib.php` | `includes/stock_lib.php` | Stock additions, registration and history queries |
| `api/missing_lib.php` | `includes/missing_lib.php` | Outstanding quantities, overdue rules and missing cases |
| `api/borrow_session_lib.php` | `includes/borrow_session_lib.php` | Checkout headers and borrower count updates |
| `api/circulation_lib.php` | `includes/circulation_lib.php` | Borrowing policies, eligibility and checkout validation |

```text
includes/
  student_validation.php
  stock_lib.php
  missing_lib.php
  borrow_session_lib.php
  circulation_lib.php
```

The folder already existed and was empty when implementation began. No other files were moved or renamed. There are no forwarding PHP copies at the old library locations; application consumers now reference the shared files explicitly. HTTP API endpoint URLs remain unchanged.

## References updated

API callers now use `dirname(__DIR__) . '/includes/filename.php'` (with existing compact spacing retained where applicable). Dependencies between moved libraries still use `__DIR__` and the sibling filename. No include depends on the shell working directory.

Updated API files: `borrowers.php`, `charge_cases.php`, `circulation.php`, `dashboard.php`, `migrate_borrow_sessions.php`, `migrate_circulation.php`, `migrate_stock_history.php`, `missing_tools.php`, `replacement_payments.php`, `replacement_requests.php`, `reports.php`, `student_import.php`, `student_importv2.php`, `tools.php`, `transactions.php`, and `writeoffs.php`.

Updated tests: `borrow_session_cases.php`, `damage_loss_workflow.php`, `dashboard_summary_cases.php`, `report_detail_pagination.php`, `stock_workflow_cases.php`, and `transaction_report_cases.php`.

The workflow harness creates sibling `api/` and `includes/` directories under its randomly named temporary fixture root. It copies the five shared files into that fixture's `includes/` directory and keeps its existing database-configuration substitution in the fixture bootstrap. Copied endpoints and HTTP import fixtures therefore resolve the same relative include paths without loading the production bootstrap. Cleanup removes only files/directories created in that disposable fixture.

Explicit paths were updated in seven feature documents: borrow sessions, bulk damage/loss, inventory consistency, circulation upgrade, stock history, TESDA students, and write-offs. README documents the new folder. Earlier audit reports retain historical file paths; two source comments also retain their original descriptive paths. These are not executable references.

## Preserved scope

`api/bootstrap.php`, database credentials, session initialization, role/permission helpers, `api/config.php`, backup code, API endpoints, page entry points, migrations, scanner scripts and UI assets retain their existing locations. The five libraries retain their function bodies, installer functions and transaction behavior. No schema migration was executed against the live database. No session files, uploads, logs, database dumps or user data were moved or deleted as cleanup.

The root and API `charge_lib.php` variants, older Tools/import variants, incident dependency group, report-specific helpers and backup libraries remain unchanged. Historical names were not renamed.

## Rollback

The verified archive is `%TEMP%/tooltrack-before-includes-6a579947bc304b938879cbc652927f40.zip`. It contains the prior callers, tests and documentation under original relative paths, plus the five unmodified library files under their original `api/` paths. Three unmodified library copies were added immediately after their pure moves because the initial archive selection found references in file contents rather than selecting those files themselves; all five current library hashes match their archived copies.

Restore archived files to their recorded paths, then remove only the five relocated library copies and the newly created report after reviewing the rollback. Do not overwrite unrelated changes or remove the pre-existing `includes/` directory blindly.

## Verification

Results are recorded after execution; no previous task's test results count as a new run.

- PHP syntax: all 94 PHP files passed.
- All 91 checked literal application include paths resolve, including both `__DIR__` and `dirname(__DIR__)` forms. Dynamic/generated includes are additionally exercised by the workflow suites.
- Borrow-session workflow: 39 checks passed, exit code 0.
- Live-synchronization workflow: 21 checks passed, exit code 0.
- TESDA/student workflow: 28 checks passed, exit code 0. The browser-enabled run passed 29 cumulative workflow checks, including 18 real-browser UI assertions and no uncaught browser errors. XLSX fixture skipped because this PHP installation lacks ZipArchive.
- Grouped transaction reports: 75 checks passed, exit code 0, including the real CSV endpoint.
- Five moved libraries match archived copies byte-for-byte.
- All sixteen modified API callers match their archived versions after applying only the approved include-path substitutions.
- Full regression: 446 checks passed, exit code 0.

The initial runs using `C:/xampp/tmp` for the parent CLI session emitted session-file permission warnings, although workflow assertions passed. Full regression, borrow-session, synchronization, report and student/browser suites were rerun with fresh user-owned temporary session directories. All reruns passed with exit code 0 and no PHP warning/fatal matches in their logs. Application session configuration was not changed, and existing session files were not deleted. Logs are under `%TEMP%/tooltrack-includes-*-verified.log`; existing project logs are retained.

Not verified here: real-account Admin/Staff login/logout, physical QR camera or student-ID scanner hardware, exhaustive desktop/mobile layouts, backup/restore operations, or integrations outside this workspace. The browser suite uses test sessions and stubs camera/chart libraries; its UI assertions do not constitute hardware verification. API permission checks use disposable test actors. For manual verification, sign in as each role, exercise allowed/denied actions, scan tools and IDs on deployed devices, and inspect Borrow/Return/Reports at desktop and mobile widths using controlled test data.

The pre-existing missing PWA service worker and previously reproduced report-width issue are outside this relocation and are not claimed fixed. No JavaScript or CSS was changed.
