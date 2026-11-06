# Automatic tool SKUs

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

New tools use `FPST-CATEGORY-0001`. Four digits are the minimum; larger sequences expand naturally.

| Existing category value | SKU category code |
| --- | --- |
| Utensils | UTL |
| Cookware | CKW |
| Measuring Tools (Measurement in the form) | MEA |
| Accessories | ACC |
| Dinnerware | DIN |
| Cutleries | CUT |
| Glassware | GLS |

The existing `tools.code` field remains the canonical SKU used by QR labels, borrowing, returns and reports. Existing codes are not renamed. Reclassifying or adding stock to a tool preserves its SKU, including its original prefix.

`tool_sku_sequences` stores permanent category mappings and counters. `tool_sku_issued` retains every assigned code independently of the tool record, so deleting a tool cannot free its SKU. Both tables must remain part of database backups.

The Add Tool preview is read-only and does not reserve or consume a number. A final SKU may differ when another user saves first. Creation locks the category counter and saves the issued SKU and tool together in one transaction. Database uniqueness on `tools.code` and the issued register protects against collisions. A failed save rolls back all three changes. PUT rejects attempts to alter an existing SKU.

Deployment on another installation:

```text
C:\xampp\php\php.exe api/migrate_tool_skus.php
```

The CLI-only migration is safe to rerun. It registers existing codes, seeds counters from existing matching SKUs, preserves high-water marks, and verifies codes were not changed. If existing codes are duplicated and no unique index exists, it stops instead of rewriting data.

Run integration tests:

```text
C:\xampp\php\php.exe tests/damage_loss_workflow.php
```

The suite uses an isolated temporary database with schema only, tests existing inventory workflows plus SKU creation, concurrency, deleted records, reclassification, stock additions and failure rollback, then removes the test database.