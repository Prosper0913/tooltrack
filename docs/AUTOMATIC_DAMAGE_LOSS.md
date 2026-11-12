# Damage & Loss Tracking — current implementation

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

This supersedes the previous charge/payment workflow. The application uses the existing sidebar entry, tables, authentication, incident modals, stock buckets and audit records.

## Reused and improved

- Main Dashboard: its existing `api/dashboard.php` calculations and `assets/js/dashboard.js` cards remain unchanged. Available, borrowed, damaged and missing quantities come from the same tool inventory fields as before. No tracking summary cards were added.
- Tracking page: one searchable, paginated incident queue replaces the financial queue and separate missing-case table. It combines existing physical case rows, authoritative missing reports and unlinked historical damaged returns. Linked loss case rows and their source returns are excluded as duplicate display entries. Historical returns stay readable without inventing a current resolution status.
- Borrower History: uses the same incident read model as the tracking queue. The existing transaction history and statistics remain available.
- Returns: retain automatic incident linking, mixed quantities, partial returns and retry protection. Good/minor-wear returns retain normal behavior. Staff missing reports retain the existing Admin confirmation rule; pending units remain borrowed and unavailable until confirmed.
- Inventory: retains case requests, repair, inspection, recovery, disposal and physical replacement receipt. Case disposal now calls `disposeDamagedTool` inside the existing case transaction; replacement receipt calls `addToolStock`. Stock views refresh through the existing `refreshToolStockViews` utility.
- Historical tables and filenames: retained to avoid data loss and broken inventory relationships. New incident rows use `workflow_mode='tracking'`. The existing version and event fields are reused; no replacement tracking tables were created.

## Removed active features

Charge assessments, waivers, payment entry, verification, reversals, financial completion controls, financial summary cards, financial reports and the multi-tool charge form are no longer available. Their backend actions and old financial read routes return a clear retired-feature response. The financial frontend bundle is no longer loaded.

Existing assessed amounts, receipts, historical financial audit entries and borrower records are preserved in the database. Inventory resolution no longer reads or changes balances, amounts or financial statuses. Audit Trail can still describe historical financial events.

## Main files

- `index.php`: existing navigation, queue, incident modal and borrower-history integration; removes the financial report and duplicate missing table.
- `api/case_inventory.php`: shared incident read model for queue/history, existing physical workflows and normalized query collations for existing tables.
- `api/charge_cases.php`: existing queue endpoint, now physical incidents only.
- `api/replacement_requests.php`, `api/replacement_payments.php`, `api/charge_lib.php`: existing endpoint/helpers, narrowed to incident and physical actions.
- `api/bulk_charge_lib.php`: retains historical item reading/schema compatibility, removes active bulk financial mutations.
- `api/borrowers.php`, `api/missing_tools.php`, `api/tool_disposal_lib.php`, `api/tools.php`, `api/reports.php`: integration with existing history, missing cases, disposal, inventory reservations and retirement of financial reports.
- `assets/js/charge-workflow.js`, `assets/js/charge-payments.js`, `assets/js/missing-cases.js`, `assets/js/borrowers.js`, `assets/js/app.js`, `assets/js/reports.js`, `assets/js/return.js`, `assets/js/tools.js`: existing modules updated without adding a second module or navigation entry.

## Database changes

None for this simplification. No tables, columns or historical records were dropped, and no migration is required on the current installation. The previous incident-schema migration remains available for installations that have not yet installed incident tracking.

## Validation

The existing schema-cloning API harness creates an isolated temporary database and removes it afterward. It checks inventory conservation after each API request.

```powershell
# Focused tracking checks
$env:TOOLTRACK_AUTO_CASE_TEST='1'
& C:\xampp\php\php.exe tests/damage_loss_workflow.php

# Existing regression suites plus tracking
Remove-Item Env:TOOLTRACK_AUTO_CASE_TEST
& C:\xampp\php\php.exe tests/damage_loss_workflow.php
```

- Focused suite: 81 checks passed.
- Broader suite: 270 checks passed; separately reports the existing student registration / CSV academic-validation mismatch.
- Coverage includes Dashboard accuracy, single/batch return retries, one visible row per linked incident, borrower incidents, authorization, retirement of financial actions, inspection, recovery, shared disposal/restock ledgers and archived receipt preservation.
- PHP syntax checks passed. Browser interaction and visual verification have not been performed in this environment.

Historical financial test fixtures describe the superseded workflow and are not invoked by the current harness. Stored financial evidence remains preserved; it is not an active payment-management feature.