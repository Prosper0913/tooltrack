# Cross-user live synchronization

The cause was missing background refreshes for most views. The previous 60-second timer only refreshed notifications, selected loan tabs, and return overview cards. Inventory, incidents, dashboards, and reports otherwise loaded on navigation or local actions. No cache or query-filter defect was needed to reproduce stale cross-user displays.

## Operation and deployment

Run `C:\xampp\php\php.exe api/migrate_live_sync.php` against each deployment's configured database. This was already run locally, installing change tracking for 26 existing tables. It creates one small `live_sync_versions` table and three transactional triggers per existing table. It does not change business records. The database account needs CREATE TABLE and TRIGGER privileges. Re-run after adding tables through other workflow migrations. No new framework, service, or timezone setting is required.

`api/live_sync.php` authenticates Admin/Staff access and checks the current account role. It returns only an opaque version and the configured `APP_TIMEZONE`. It releases the PHP session lock before querying. Relevant existing read endpoints also release session locks after their authentication/session setup. Authenticated API responses use private, no-store cache headers.

The shared client schedules a check five seconds after completion of the previous check. Hidden tabs pause; focus, visibility, and network restoration request an immediate check. Checks use abortable requests, a timeout, and capped retry backoff. All sibling requests settle before retrying. Unauthorized polling stops. Navigation cancels old requests and view fingerprints reject stale filter/pagination responses.

Ordinary unchanged checks fetch only the small version response. On change, the client fetches the current view's previously used query URLs and shared notification summaries, then checks the version again before rendering. This prevents displaying responses spanning a committed inventory change. A minute bucket also advances time-dependent overdue displays. Data comparisons distinguish a checked version from actual changed response data.

Existing loaders render from that validated response set. Tool and borrower tables reconcile their keyed rows in place and retain expanded rows. Filters, report column settings, pagination variables, checkboxes that still exist, and scroll offsets are retained. Open record dialogs remain untouched and get a newer-data notice. Checkout catalog updates preserve the cart and typed quantity. Successful local API writes request a check immediately.

Tool edit responses carry an edit fingerprint. Saving checks it while holding the tool row lock, rejecting concurrent metadata or stock changes with HTTP 409. Existing checkout, return, recovery, and write-off validation, locking, and retry tokens remain in use; display freshness never determines stock correctness.

## Files changed

- New backend: `api/live_sync.php`, `api/live_sync_lib.php`, `api/migrate_live_sync.php`.
- API cache/session handling and tool conflicts/badges: `api/config.php`, `api/tools.php`, `api/dashboard.php`, `api/reports.php`, `api/transactions.php`, `api/borrowers.php`, `api/users.php`, `api/audit_log.php`, `api/circulation.php`, `api/charge_cases.php`.
- Client integration: `index.php`, `assets/js/live-sync.js`, `assets/js/app.js`, `assets/js/tools.js`, `assets/js/borrowers.js`, `assets/js/borrow.js`, `assets/js/table-redesign.js`, `assets/js/reports.js`, `assets/js/circulation.js`, `assets/js/charge-workflow.js`, `assets/js/users.js`.
- Tests: `tests/live_sync_workflow.php`, `tests/live_sync_cases.php`, `tests/live_sync_browser_cases.php`, `tests/damage_loss_workflow.php`, `tests/ui/live_sync.js`, `tests/ui/build_live_sync_runner.php`, `tests/ui/report_interface.js`.

## Verification

- Existing isolated workflow suite: 513 passing checks, also rerun with the new database triggers installed, including stock concurrency, duplicate retries, borrowing/returns, incident recovery, dashboard and report compatibility. It separately reports an existing student-import validation mismatch; the suite is not entirely clean.
- Focused synchronization API/database and browser integration tests: 22 passed. They cover committed inserts, updates, status changes, deletions, rollback behavior, role permissions and revocation, parameter validation, read-only checks, idempotent trigger installation, and stale tool-edit rejection.
- Headless Chrome lifecycle tests: 52 passed. They cover changed and unchanged checks, preserved search/forms, hidden tabs, duplicate wake events, backoff, failure retention, slow requests, stale filter responses, offline recovery, expired sessions, navigation cleanup, and keyed expanded inventory rows.
- Browser integration uses the real app shell, PHP endpoints, and an isolated database with separate Admin and Staff cookie sessions. A Staff checkout changes Admin inventory from 5 to 3 without reload, while an open edit draft and expanded row survive. Chart rendering and scanner libraries are stubbed in this offline integration fixture.
- Existing inventory/return/write-off browser checks: 48 passed. Report interface checks: 270 passed. The report test's obsolete four-card expectation was updated to preserve the existing fifth write-off-history card.

Run the API tests with `C:\xampp\php\php.exe tests/live_sync_workflow.php`. Set `TOOLTRACK_LIVE_BROWSER_TEST=1` to include the Chrome integration test. `tests/ui/build_live_sync_runner.php` generates a deterministic browser runner; open the generated `tests/ui/live-sync-runner.html` in a browser.

## Limits

Five-second polling is near-real-time, not instantaneous server push. Failed checks use backoff, and continuous writes during response collection can postpone a coherent refresh. Browser integration exercises a real checkout; every workflow has not been exercised end-to-end in two production browser profiles. Existing transaction tests cover the other stock operations. Large-dataset/high-concurrency load testing has not been performed. Direct database TRUNCATE/DDL/restore does not fire row triggers; the existing restore workflow invalidates sessions, and administrators must re-run this migration after schema changes. Existing record-specific conflict checks remain authoritative for other edit/action forms; this change adds a new fingerprint specifically for tool edits.
