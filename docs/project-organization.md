# Project organization — November 6, 2026

The approved implementation groups documentation without relocating executable files, changing public routes, modifying UI/business rules, or altering the database. Existing filenames are retained.

## Original and final structure

Originally ten feature/planning documents were in the project root, SKU documentation was in `api/`, and four documents were already in `docs/`. The root guide remains at the root.

After organization, the eleven moved documents are in `docs/` beside the existing documents. `README.md` provides the project map and document index. Application directories (`api/`, `assets/`, `css/`, `backups/`, `pwa/`, `tests/`) and executable entry points retain their original paths.

```text
Before                              After
tooltrackv2/                        tooltrackv2/
  GUIDE.md                            README.md (new index)
  10 feature/planning *.md             GUIDE.md (corrected references)
  api/SKU_GENERATION.md                api/ (same executable files)
  docs/ (4 documents)                  docs/ (4 existing + 11 moved + this report)
  application files/directories       application files/directories (same paths)
```

| Original | Final |
| --- | --- |
| `AUTOMATIC_DAMAGE_LOSS.md` | `docs/AUTOMATIC_DAMAGE_LOSS.md` |
| `BULK_DAMAGE_LOSS.md` | `docs/BULK_DAMAGE_LOSS.md` |
| `DAMAGE_LOSS_PAYMENTS.md` | `docs/DAMAGE_LOSS_PAYMENTS.md` |
| `INVENTORY_CONSISTENCY.md` | `docs/INVENTORY_CONSISTENCY.md` |
| `LMS_UPGRADE.md` | `docs/LMS_UPGRADE.md` |
| `PWA_SETUP.md` | `docs/PWA_SETUP.md` |
| `RETURN_MANAGEMENT.md` | `docs/RETURN_MANAGEMENT.md` |
| `STOCK_HISTORY.md` | `docs/STOCK_HISTORY.md` |
| `ToDoList.md` | `docs/ToDoList.md` |
| `WRITE_OFF_LOST.md` | `docs/WRITE_OFF_LOST.md` |
| `api/SKU_GENERATION.md` | `docs/SKU_GENERATION.md` |

Each moved document has a short note identifying the project root as the base for commands and paths. Existing historical content is retained.

`GUIDE.md` has targeted corrections for `Login.html`, `index.php`, database configuration ownership in `api/bootstrap.php`, and the API helper/base location in `assets/js/app.js`. It links to the new project index and identifies its historical setup content. `api/config.php` remains the correct API include and CORS-header location.

`.gitignore` now excludes the six existing generated browser-profile directory names explicitly. It does not delete profiles, logs, screenshots, uploads, sessions, database files, or other runtime data.

No source-code references to the moved document paths were discovered during Phase 1. Existing document hyperlinks were external URLs or backup-local links; these remain valid after the moves. README links point to the new locations. API routes, include paths, scripts, stylesheets, scheduled-task paths and database connection code are unchanged.

## Reversal

Move each document back according to the table and remove its added command-base note. The verified temporary rollback archive stores the moved documents under their original paths. Its `after-initial-edits/` directory contains snapshots of the guide and ignore rules after the initial edits; those two snapshots are not pre-edit originals. The initial archive attempt failed, and no complete pre-edit archive was produced.

The guide's targeted edits and the appended ignore block are also described above so they remain reviewable. No live data was included in the rollback archive.

## Scope retained

Dashboard, Tools, Borrowers, Borrow, Return, Reports, Replacements, Missing Cases, authentication, Admin/Staff authorization, inventory arithmetic, transaction history and scanner implementations were not edited. Neither database records/schema nor session files were changed by organization.

Older code variants, generated fixtures, existing test results, browser profiles, database dumps and operational utilities remain in place pending investigation. No files were removed as cleanup.

## Verification

Checks performed after the moves:

- All eleven destination documents exist; local Markdown links in the root guide/index, `docs/` and backup documentation resolve.
- All matched local static asset links in `index.php` resolve.
- Ninety-one literal `__DIR__` include references in application/support PHP resolve. Computed/dynamic includes are not fully covered by this check.
- PHP syntax: 59 application/support files passed.
- JavaScript syntax: all 22 `assets/js/` files parsed in headless Chrome. This is syntax verification, not complete browser runtime coverage.
- Borrow-session workflow: 39 checks passed, exit code 0. Includes grouped checkouts, stock deductions, partial returns, missing cases, retry protection, rollback and concurrent checkout.
- Live-synchronization workflow: 21 checks passed, exit code 0.
- Read-only live database metadata query succeeded: 28 base tables and 31 foreign-key column references. No live schema modifications were performed.
- No root `sess_*` files were present; no existing sessions were removed by organization.

The broader `tests/damage_loss_workflow.php` regression suite passed 446 checks with exit code 0. Coverage included inventory consistency, incident workflows, borrowing policies and role checks, returns, Dashboard/report compatibility, SKU operations, student validation and stock history. API role checks use test sessions; they do not establish that real-account login was exercised.

Test outputs are stored under the Windows temporary directory as `tooltrack-organization-regression.log`, `tooltrack-organization-borrow.log`, and `tooltrack-organization-live-sync.log`; existing project logs were retained. The JavaScript parse result is in `tooltrack-organization-js-result.html`.

Not run in this organization pass: real-account Admin/Staff login/logout, camera/physical student-ID scanning, and visual desktop/mobile inspection. No application execution paths or assets were moved, but static checks cannot establish all of those behaviors. Practical manual verification: sign in as each role and verify allowed/denied screens; borrow and return a test tool; compare Dashboard and Reports quantities; scan a QR code and student ID on the deployed devices; inspect Borrow/Return/Reports at desktop width and at a narrow mobile width. Use controlled test data or an isolated installation for transactions.

Backup/restore suites were not run because no backup files or behavior changed and those suites require separate private-storage setup. The existing missing `sw.js`, old code variants and web-root database dumps remain investigation items. The previously reproduced report responsiveness failure is not claimed fixed or retested by this documentation-only change.
