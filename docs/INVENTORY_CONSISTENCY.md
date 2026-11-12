# Inventory consistency handoff

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

The supplied request was reviewed against the code; no screen recording was attached. Existing PHP/PDO/MariaDB endpoints, permissions, scanners, components and missing-report policy are retained.

## Confirmed defects and changes

- Borrow silently added pending form values during confirmation, allowed an empty enabled button, and submitted immediately on desktop. It now requires Add Tool, disables empty/pending confirmation, and opens the existing review sheet on all screen sizes. Review shows borrower, each committed tool quantity and total units. Whole quantities are validated on both sides.
- Ordinary cart checkout had no durable retry protection. `borrow_submissions` now stores actor, payload hash and the exact response inside the same transaction as loan rows, borrower counters and stock updates. A token replay returns the original lines. The UI retains a token for an unchanged failed request and does not clamp the cart against stock that its own successful but unacknowledged request may have consumed. Existing sorted tool locks, borrower locks, eligibility rules and reservation checks remain authoritative.
- Returns already targeted `transactions.id` through `borrow_txn_id`, supported mixed partial conditions, and created incidents atomically. Those mechanisms were retained. Confirmation now explicitly describes each original reference, whether it closes, and other outstanding loans for the same tool.
- Case actions previously consumed the whole original incident. Additive `resolved_quantity` and `repair_quantity` counters now support partial damaged-case actions. Original `incident_quantity`, return rows and evidence links remain intact. Repair marking leaves stock unavailable; approval releases exactly the selected quantity. Events record affected quantity, staff, time and before/after balances.
- Tools disposal omitted tracking cases and could not reconcile case resolutions. Both entry points now use the same versioned case action and disposal ledger. The picker shows case eligibility and carries the originating case ID. Case-owned stock cannot be disposed through the historical-stock option or repaired through generic Tools repair. Tool-first lock order and current-read retry lookups protect concurrent actions.
- Returns by Condition now leads with tool, borrower, date and condition quantities. Full references remain later in the table and CSV. “Damaged at Return” is historical evidence, independent of current damaged stock.
- Return badges used elapsed overdue days, incorrectly labeling earlier-today loans as Due. They now use the server's actual due timestamp and timezone-formatted label.

## Transaction trace and definitions

`index.php` → `assets/js/borrow.js` → POST `api/transactions.php` (`borrow_batch`) locks tools in ID order and borrower capacity, checks `includes/circulation_lib.php`, creates one borrow row per cart line and deducts available stock atomically. Reservations in `tool_reservations` / `reservation_items` allocate capacity over the requested loan period without changing physical stock. Peak overlapping holds and borrower policies are checked during checkout.

`assets/js/return.js` loads `type=student_loans` and submits original numeric loan-line IDs through `return_batch` (single-return API remains supported). Good and Minor Wear go to available; Minor Wear is a subset of available. Damaged goes to `damaged_qty` and automatically creates one linked `replacement_requests` case using `replacement_incident_sources`. Unprocessed units remain on the same loan. Staff Missing reports remain pending and outstanding until Admin confirms through the existing missing workflow; they are not physical returns.

`assets/js/charge-payments.js` / `tools.js` → versioned `rpAction` → `caseInventory` / `disposeDamagedTool` updates stock, case balances, events and disposal/stock ledgers in one transaction. Case details and queue retain original quantities and show unresolved quantities separately. Disposal records tool snapshots, quantity, reason, actor, timestamp and case ID.

- Total owned: `tools.quantity`; disposed units have been removed from this balance and remain in `tool_disposals` / `stock_history`.
- Available: `tools.available`, including minor wear; good = available − minor.
- On loan: sum of `qty − qty_returned − qty_missing` on original borrow rows. Inventory conservation: owned = available + on loan + damaged + confirmed missing.
- Currently damaged: `tools.damaged_qty`, including units under repair. Damaged-case unresolved = original incident − resolved quantity.
- Missing: confirmed `tools.missing_qty`; pending reports do not move stock or close the loan.
- Historical return conditions: immutable return transaction rows, shared by report preview and CSV query definitions in `api/reports.php`.

## Migration

From the project directory, with the database configured in `api/bootstrap.php`:

```powershell
& C:\xampp\php\php.exe api/migrate_inventory_consistency.php
```

The migration is additive and rerunnable: it creates the checkout submission and disposal-ledger tables, adds two case counters, and initializes already-resolved historical cases to their original resolved quantity. It does not delete loans, change historical return quantities or alter physical inventory. Existing loans without tokens remain returnable. Legacy API callers may omit cart checkout tokens for compatibility; durable checkout replay requires a token, supplied by the current UI. Run the migration before using the changed application.

For historical damaged stock with no attributable case, Tools → Dispose → **Historical damaged stock without a case** remains available. Eligibility excludes stock reserved by unresolved current cases. Record the historical evidence in the mandatory reason. A legacy case reference may be selected when its identity and remaining stock are known; the same case-resolution rules apply.

## Files

- UI: `index.php`, `assets/js/{app,borrow,return,tools,charge-payments,charge-workflow}.js`.
- Transactions/read models: `api/{transactions,case_inventory,charge_lib,replacement_payments,tool_disposal_lib,tools,reports}.php`.
- Schema: `api/replacement_payment_schema.php`, `api/migrate_inventory_consistency.php`.
- Regression coverage: `tests/inventory_consistency_cases.php`, `tests/damage_loss_workflow.php`, `tests/ui/inventory_consistency.js`, `tests/ui/build_consistency_runner.php`.

## Verification

Tests clone schema only into randomly named `tooltrack_test_*` databases, use the application's real API handlers with isolated sessions, check inventory conservation after each call, and drop their databases afterward. No live borrower/tool fixtures are created.

```powershell
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\htdocs\tooltrackv2 tests/damage_loss_workflow.php
# Optional focused suite, in a fresh shell:
$env:TOOLTRACK_CONSISTENCY_TEST='1'
& C:\xampp\php\php.exe -d session.save_path=C:\xampp\htdocs\tooltrackv2 tests/damage_loss_workflow.php
```

Observed Baso checkpoints, starting with 30 units: after 6+9 checkout, owned 30 / available 15 / outstanding 15; after nine damaged return, 30 / 15 / outstanding 6 / damaged 9; after three approved repairs, 30 / 18 / outstanding 6 / damaged 6; after one case disposal, 29 / 18 / outstanding 6 / damaged 5. Original case and return still record nine. API sources for Tools, Borrow, Return, case details, report and actual CSV were checked.

Also covered: empty/excessive checkout, changed payload under a reused token, concurrent last-unit checkout, concurrent identical checkout retry, duplicate return and disposal, mixed partial return, excess return/disposal, forced checkout database failure followed by same-token retry, legacy loan return, same-day overdue timestamp and existing reservation regressions.

Headless Chrome executes real selected Borrow/case/disposal functions against DOM stubs plus the existing Return/scanner-stub suite; all changed JavaScript is parsed. Generate its local runner with `php tests/ui/build_consistency_runner.php` and open `tests/ui/consistency-runner.html`, or use Chrome headless `--dump-dom`. Node is unavailable on this machine. This checks behavior and syntax, not the complete rendered application, real camera/USB hardware, or a human clicking through production data. Those remain unverified. No recording-specific observations are claimed.

Observed: 508 full-suite checks passed; 33 headless UI/syntax checks passed. PHP syntax checks passed for changed PHP files. The local migration was applied successfully twice to verify rerun safety. The full suite separately reports the pre-existing student-import academic-validation mismatch; it is outside these inventory changes. See `tests/consistency-regression.log` and `tests/ui/inventory-browser-results.log` for detailed results.
