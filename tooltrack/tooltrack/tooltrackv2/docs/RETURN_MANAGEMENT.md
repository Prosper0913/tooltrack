# Return Management redesign

> Paths and shell commands in this document are relative to the ToolTrack V2 project root, unless explicitly stated otherwise.

The existing Return module is retained. The page heading is now **Return Management** and its layout shares Borrowing Management's welcome banner, quick-action cards, overview styles, theme and existing card/button components.

- **Welcome:** Ready to return? and Scan Student ID call the existing ID scanner.
- **Quick actions:** Scan ID, Find Borrower, Return Tools and View History reuse existing lookup, scanning, selection and history handlers.
- **Workspace:** borrower search/scanner and borrower details stay on the left on desktop; outstanding loans and condition quantities stay on the right. The same controls stack on smaller screens. No scanner or lookup form is duplicated.
- **Summary:** the existing totals, stock-impact message, validation error and confirmation button appear in one sticky responsive bottom panel. Its expandable review lists selected tools and conditions. Full/partial selection behavior remains unchanged.
- **History:** a dedicated Return History view uses the existing table, filters, pager and transaction records.
- **Overview:** the existing `api/transactions.php` GET endpoint now accepts `type=return_overview`. Units on Loan uses `sqlOutstanding`; Overdue Loans uses `sqlIsOverdue`; Returned Today sums physical return-row quantities on the configured local date. Pending missing units remain outstanding until Admin confirmation. Missing reports are never counted as physically returned units.
- **Refresh:** successful returns update overview, history, notifications, tracking and existing stock views. The existing minute refresh updates the overview while this page is visible.

No database migration, financial workflow or sidebar module was added. The existing return POST implementation, scanner initialization and scanner/ID lookup code were compared before/after and are unchanged. Existing Minor Wear support remains alongside Good, Damaged and Missing.

## Files

- `index.php`: existing controls reorganized, welcome/overview/quick actions and dedicated history view.
- `css/styles.css`: Borrow welcome component styles shared with Return, plus scoped Return workspace/summary styling.
- `assets/js/return.js`: presentation, view switching, summary state and overview refresh. Existing scanner and batch submission functions are reused.
- `assets/js/app.js`: page heading and entry/refresh integration.
- `api/transactions.php`: read-only overview response; no return mutation changes.
- `tests/return_management_cases.php`, `tests/ui/return_management.js`, `tests/damage_loss_workflow.php`: isolated API/markup checks and DOM/scanner-stub UI tests.

## Validation

Focused API checks (disposable test database):

```powershell
$env:TOOLTRACK_RETURN_UI_TEST='1'
& C:\xampp\php\php.exe tests/damage_loss_workflow.php
```

UI checks with DOM and scanner stubs, requiring Node:

```powershell
node tests/ui/return_management.js
```

- 34 focused API/markup checks passed.
- 14 UI checks passed in a JavaScript runtime with DOM/scanner stubs: scan lookup, borrower/loan loading, partial/full quantities, summary, duplicate submit guard, excess-return rejection, reset, overview refresh and view switching.
- Broader regression run: 374 checks passed; separately reports the pre-existing student registration/CSV academic-validation mismatch.
- PHP and JavaScript syntax checks passed; no duplicate scanner, borrower input, confirmation button or history table IDs were introduced.
- Real browser appearance and physical camera/USB scanner operation remain unverified. Stub tests validate integration, not actual hardware scanning.
