# Bulk Damage & Loss Charges

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

Bulk entry is part of the existing Damage & Loss Charges creation modal. It creates one financial case for one student and separate linked physical items. Existing single-tool incidents, receipts, payment verification, Tools Management actions and the purple interface remain available with their existing permissions.

## Staff workflow

1. Open **Report Damage / Loss → Add Multiple Tools**. The modal title is **Create Damage & Loss Case**.
2. Search/select a student with eligible damaged-return or confirmed-loss evidence. Student ID and available course/year/section are displayed.
3. Add affected tools. Each row selects real evidence for that student and shows the tool/SKU, borrowing reference, damage/loss type, eligible quantity, unit charge and calculated subtotal. Use one row for multiple units of the same incident; different incidents for the same tool can use separate rows.
4. Review affected tool count, total units and combined charge. Choose **Review Charges**, check the student and every line, then **Confirm Charges**. Review alone does not save anything.
5. Record and verify installments against the parent case's combined balance. Payment never changes stock.
6. **View Details** shows each tool's evidence, quantity, unit charge and subtotal, plus the combined payment history. Paid cases appear once in **Reports → Damage & Loss Charges**.

The desktop editor uses compact rows in the existing scrollable modal with a fixed footer. Mobile uses separate item cards and touch-sized controls. Existing summary cards, filters and contextual menus remain in place. Parent counts are not multiplied by their item count.

## Architecture and database changes

The existing `replacement_requests` table is reused for both the parent and items:

| Field | Definition | Purpose |
|---|---|---|
| `parent_case_id` | nullable INT | Links each item to its financial parent; indexed with `id` |
| `assessed_unit_charge` | nullable DECIMAL(12,2) | Exact assessed unit price for an item |
| `line_subtotal` | nullable DECIMAL(12,2) | Backend-calculated quantity × unit price |
| `tool_code_snapshot` | nullable VARCHAR(50) | Preserves the item's SKU |

Parents use the existing `workflow_mode='payment'`, financial amount/status/version fields, borrower snapshots and completion metadata. Their `tool_id` is null and their tool-name snapshot summarizes the items. Mixed incidents use reason `mixed`. Items use `workflow_mode='payment_item'`, retain their own tool, reason, quantity and incident source, and have no independent payment ledger. Existing single-tool rows remain unchanged with a null parent link.

No new payment or history table is introduced (older `inventory_resolution*` tables are no longer used). `replacement_incident_sources` reserves each item's exact evidence quantities, including reservations against existing single-tool cases.

The server derives tools and student responsibility from the evidence, calculates all money using integer cents, locks tools in a consistent order and creates the parent, items, sources, requirements and audit records in one transaction. A failure rolls back the entire case. Retry tokens identify the whole reviewed submission. Reusing a token with changed contents is refused.

Verified payments remain on the parent. A recovered original flags its parent for financial review. Charge corrections update unit prices and subtotals together, recalculate the combined balance, retain before/after audit snapshots, and cannot reduce the charge below verified collections. Existing Admin restrictions on confirmed-history corrections remain enforced.

## Exact files

New:

- `api/bulk_charge_lib.php`: schema installer, parent/item creation, evidence validation, exact totals, item assessment corrections, item retrieval and aggregate physical status.
- `api/migrate_bulk_charges.php`: CLI-only additive migration with database confirmation and preservation fingerprints.
- `assets/js/bulk-charges.js`: creation editor/review/confirmation, student selection, item charge details and per-item progress/actions.
- `tests/bulk_charge_workflow_cases.php`: isolated bulk financial/inventory regressions.

Updated:

- `api/replacement_payments.php`: bulk creation action, item-bearing details and parent recovery review.
- `api/charge_lib.php`: activate item requirements from combined verified settlement; apply atomic item-charge corrections.
- `api/charge_cases.php`: eligible student/evidence metadata, parent-only queue/counts, grouped tool requirements, item search and receipt details.
- `includes/missing_lib.php`, `api/missing_tools.php`: distinguish financial item rows from legacy physical reservations; lock parent cases before recovery.
- `api/damage_loss_reports.php`: include mixed cases when filtering either applicable incident type while counting each financial case once.
- `assets/js/charge-workflow.js`: dashboard cards, queue and mixed-case labels.
- `index.php`, `css/styles.css`: integrate the bulk editor in the existing modal and responsive design.
- `tests/damage_loss_workflow.php`: loads the bulk helpers. Test fixture dates now explicitly use Asia/Manila so midnight does not invalidate loan/missing dates.

## Checkpoint and backup

The folder had no Git repository. A local repository was initialized and checkpoint **`8d672bd` — Checkpoint before bulk damage and loss cases** was committed before implementation. It includes the previously requested removal of Resolve Damage / Missing from Tools Management. No remote was configured or pushed. Database dumps and protected backup material remain excluded by the existing ignore rules.

The pre-change database backup was created and verified:

`tooltrack_2026-10-29_00-19-20_87946c5568b573569253d849a6674f56.ttbackup`

It is stored in the application's configured protected backup destination. Source rollback uses the Git checkpoint; database recovery uses the existing protected backup workflow.

## Deployment

This installation already has the payment and physical-receiving schema. On another installation, apply those prerequisite upgrades first. During a maintenance window, preserve the current source release and back up the database, then run:

```powershell
& 'C:\xampp\php\php.exe' backup.php backup
& 'C:\xampp\php\php.exe' api/migrate_bulk_charges.php '--database=tooltrack v2_db'
```

The configured development database **`tooltrack v2_db`** was explicitly verified and this migration was applied successfully. It adds only nullable fields and an index, with no historical conversion. The installer can be rerun. Preservation fingerprints verify existing tools, borrowing/return transactions, missing cases, cases, incident allocations, payments, physical requirements, receipts and stock history using their original columns.

Refresh the browser after deploying the PHP, JavaScript and CSS together. Existing single-tool cases do not require backfilling. No synthetic bulk cases were created in the development database; authenticated endpoint smoke checks were read-only.

## Executed validation

Bulk checks run inside the main suite (**366 checks pass** in the current suite; the earlier 510-check total also covered the receiving tests, which were removed with the feature). They cover: mixed damage/loss, multiple quantities, one-item bulk entry, combined installments, full settlement, duplicate evidence prevention across old/new cases, concurrent creation retries, atomic creation rollback, stock conservation, history search and charge corrections.

Browser regression for the charge module: see `DAMAGE_LOSS_PAYMENTS.md` (110 checks, 0 failures). The bulk review/confirm editor was covered by the API suite; it was not re-driven through the browser in this release.

```powershell
& 'C:\xampp\php\php.exe' tests/damage_loss_workflow.php
```

Database tests use an isolated random test database and remove their own fixtures afterward. Browser checks do not submit production records. These results do not claim a fresh manual login/logout or end-to-end acceptance test using real school charges.

## Limits and rollback

Bulk creation supports up to 30 incident rows in one reviewed submission. Source identity and affected quantities are fixed after creation; authorized corrections edit charges, not historical evidence. Existing exports remain combined case/payment reports; item breakdowns are available in case details and physical history.

For code rollback, restore the affected tracked files from checkpoint `8d672bd` after preserving any later user edits. Retain new additive columns, item records, receipts and audit history. The previous application version does not understand bulk items, so do not resume processing existing bulk cases with old code; keep the updated implementation available to review and resolve them.

Do not drop columns, delete child rows, reverse inventory with ad hoc SQL or restore an old database over subsequent payments/receipts. If database restoration is necessary, use controlled maintenance and the existing authenticated restore/safety workflow after accounting for all activity since the backup.
