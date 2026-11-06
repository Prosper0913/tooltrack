# ToolTrack V2 / FPST Tool Inventory Management System

Existing PHP/MySQL application with plain JavaScript and CSS. This project does not require a framework, Composer, or npm build step.

## Application map

| Path | Purpose |
| --- | --- |
| `index.php` | Authenticated application: Dashboard, Tools, Borrowers, Borrow, Return, Reports, Replacements and Missing Cases |
| `Login.html`, `logout.php` | Existing sign-in and sign-out entry points |
| `api/` | JSON endpoints, shared PHP libraries and existing maintenance/migration scripts |
| `includes/` | Shared student validation, stock, missing/overdue, borrow-session and circulation libraries |
| `api/bootstrap.php` | Database configuration, sessions, roles and shared helpers; do not publish configuration values |
| `api/config.php` | Shared bootstrap plus API response headers |
| `assets/js/` | Shared application utilities and feature scripts; preserve the script order in `index.php` |
| `assets/`, `css/` | Branding, PWA icons and stylesheets |
| `backups/` | Backup setup scripts, Admin settings partial and backup documentation |
| `pwa/`, `manifest.webmanifest`, `offline.html` | Existing HTTPS/install support and PWA assets |
| `tests/`, `tests/ui/` | Existing API/workflow suites, browser checks, generated fixtures and test results |
| `docs/` | Feature documentation, historical implementation notes and audit reports |
| `backup.php`, `run_backup.bat` | Existing backup entry point and scheduled-task launcher |
| `genhash.php`, `apply_update.sh` | Existing operational utilities; review before use |

Keep the deployed `/tooltrackv2/` application path and existing filenames unless a separate migration has been reviewed. Relative API URLs, session cookie scope, test fixtures and backup setup depend on the current layout. Older root-level code variants remain pending investigation; their presence does not make them safe to remove or substitute for active files.

## Documentation index

Paths and commands in these documents are relative to the project root unless stated otherwise. Historical documents may describe retired behavior; they are not instructions to run migrations against an existing database.

| Document | Topic |
| --- | --- |
| [GUIDE.md](GUIDE.md) | Historical backend connection guide, with corrected entry-point/configuration references |
| [Automatic damage and loss](docs/AUTOMATIC_DAMAGE_LOSS.md) | Current incident workflow implementation notes |
| [Bulk damage and loss](docs/BULK_DAMAGE_LOSS.md) | Historical bulk workflow notes |
| [Damage and loss payments](docs/DAMAGE_LOSS_PAYMENTS.md) | Historical payment workflow notes |
| [Inventory consistency](docs/INVENTORY_CONSISTENCY.md) | Inventory rules and regression coverage |
| [Circulation upgrade](docs/LMS_UPGRADE.md) | Borrowing policies, overdue monitoring and history |
| [PWA setup](docs/PWA_SETUP.md) | Existing HTTPS and installation setup |
| [Return management](docs/RETURN_MANAGEMENT.md) | Return workflows |
| [SKU generation](docs/SKU_GENERATION.md) | SKU implementation and maintenance notes |
| [Stock history](docs/STOCK_HISTORY.md) | Stock history implementation |
| [Planning notes](docs/ToDoList.md) | Existing task list |
| [Lost-tool write-offs](docs/WRITE_OFF_LOST.md) | Write-off implementation and checks |
| [Borrow sessions](docs/borrow-sessions.md) | Grouped checkouts and transaction identities |
| [Live synchronization](docs/live-synchronization.md) | Background refreshes and conflict handling |
| [TESDA students](docs/tesda-students.md) | Student import and program handling |
| [Reservation removal](docs/reservation-removal.md) | Previous removal scope, retained database relationships and results |
| [Backup setup](backups/README.md) | Backup installation and operating instructions |
| [Organization report](docs/project-organization.md) | Approved documentation moves and verification |
| [Shared PHP includes](docs/shared-includes.md) | Approved library moves, updated callers and verification |

## Runtime data and configuration

- Never treat `sess_*` files as source clutter. File sessions use the configured PHP session directory; CLI/test overrides can choose another directory. Clearing active session files can log users out.
- Preserve uploads, database dumps, logs, test browser profiles and backup data. Ignore rules do not delete existing files.
- Backup private configuration is outside the web root. Do not copy private configuration, recovery keys or database credentials into documentation or public assets.
- Database dump files in this folder are retained user data. Review access protection separately before publishing this directory to a network.

## Existing checks

Run commands from the project root. Workflow suites create and drop disposable schema clones and write temporary fixtures/results; inspect the harness and database configuration before running them. Backup tests have additional private-storage requirements. No migration runs automatically as part of this documentation organization.

```powershell
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\tmp tests/damage_loss_workflow.php
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\tmp tests/borrow_session_workflow.php
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\tmp tests/live_sync_workflow.php
```

Real camera QR scanning, physical student-ID scanners, and every desktop/mobile layout require separate device/browser verification. `assets/js/pwa.js` currently references a missing `sw.js`; this pre-existing issue was not repaired by the documentation-only organization.
