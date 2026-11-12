# Shared mobile data tables

All application `.data-table` tables now have either their existing specialized mobile cards or the shared fallback card layout below 768px. Desktop and tablet retain table geometry.

`css/mobile-tables.css` uses the Tools card styling: white cards, theme borders, rounded corners, labeled fields, a full-width identity field and action area, and 44px controls. Fields use two columns, reduced to one on very narrow phones. All original data and editable fields stay present; fallback cards do not collapse required information.

`assets/js/mobile-tables.js` derives missing labels from actual headers and observes dynamically loaded records, report tables and nested details. It decorates the existing nodes, retaining listeners, inputs, values, hidden rows and business handlers. Loading/error/empty rows and spanning detail rows remain full width. Existing Tools, Borrowers, Return, incident, grouped borrow history and grouped transaction summaries retain their specialized layouts. Wide nested transaction tool details receive the fallback layout.

`index.php` loads these two shared files. No endpoint, schema, export handler, permission or record operation changed.

Browser fixtures are in `tests/ui/mobile_tables.js` and `tests/ui/build_mobile_tables_runner.php`. Checks cover actual application table headers, dynamic updates, nested details, preserved listeners/input values, hidden rows, full-width messages, narrow-screen containment, touch sizing and desktop/tablet table geometry. Controlled fixtures are used; visually review real reports, import previews, Admin accounts, actual record actions and assistive technology with representative data.
