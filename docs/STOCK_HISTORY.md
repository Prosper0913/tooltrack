# Add Stock and Stock History

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

## Restock an existing tool

1. Sign in as Admin and open Tools.
2. Click **+ Add Stock** beside the tool's actions menu. Retired tools must be reactivated first.
3. Choose **+1, +5, +10, +20**, or enter a custom positive whole quantity.
4. Review current and updated **total** and **available** stock. Optionally enter a delivery/purchase reference.
5. Press **Add N units** once. The Tools list, Dashboard and Borrow tool availability refresh after saving.

This updates the existing tool; it does not generate another SKU or duplicate the tool. Added units enter good/available stock. Borrowed, minor-wear, damaged and missing buckets stay unchanged. The updated total is capped at 100,000, consistent with existing tool validation.

Each modal opening has a unique persistent request token. During saving, its controls are disabled. An uncertain network failure keeps the same token so a retry cannot apply the addition twice. If a reply was lost, retry the same request or check Stock History before opening a new addition.

## Reports → Stock History

The former **Tool Registration** card is now **Stock History**, in its existing Reports location. It includes:

- New-tool registrations, with their initial quantities.
- Add Stock events and existing stock adjustments/removals.
- Date/time in `MM/DD/YYYY h:mm AM/PM`, event type, tool name, SKU, quantity, previous/updated total, responsible user and remarks.
- **Today**, **All dates** and a custom **From / To** date range.
- Daily totals for units added (initial registrations plus additions), new tool records, restocked units and removals.
- Date-filtered CSV export. Daily totals include all matching records even when the screen preview is capped at 200 rows.

The responsible user is captured from the authenticated server-side account. Add Stock is Admin-only; existing permission rules for other modules are preserved. Initial tool registration logs the actual creator, which may be a staff member under the existing tool-create permissions.

Legacy tools still appear with their existing creation timestamps. Historical initial quantities or creators that were never recorded are shown as **Unknown**, not guessed. Unknown initial quantities are excluded from the units-added total with an explanatory note. Existing records and original quantities are not rewritten or backfilled.

## Database and deployment

The existing `stock_history` table is reused. The migration adds `event_kind`, a tool-code snapshot, a nullable unique `request_token`, and lookup indexes. It does not rewrite tool quantities or historical events.

Before copying the updated code to another installation, run:

```text
C:\xampp\php\php.exe api/migrate_stock_history.php
```

The CLI-only migration is safe to rerun. Existing tool creations now write their registration event inside the same transaction as the tool and SKU. Restocking locks the tool row and saves its quantity change, ledger event and inventory adjustment in one transaction. Editors send the stock quantity they originally loaded so an outdated Edit Tool form cannot overwrite a more recent restock.

Modified runtime files: `api/tools.php`, `api/reports.php`, `includes/stock_lib.php`, `index.php`, `assets/js/tools.js`, `assets/js/table-redesign.js`, `assets/js/reports.js`, `css/styles.css`.

## Tests

```text
C:\xampp\php\php.exe tests/damage_loss_workflow.php
```

The suite includes `tests/stock_workflow_cases.php` and runs against a temporary schema-only database that is removed afterward. It covers concurrent additions and retries, validation, permissions, rollback, SKU preservation, condition quantities, dashboard totals, creation history, timestamp/date filtering, CSV export and daily totals beyond the preview limit. Browser checks use mock records and do not add stock to real tools.