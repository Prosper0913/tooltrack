# Mobile navigation and layout

Phone changes apply below 768px. The PHP sidebar remains the source for available destinations; desktop navigation and existing navigation handlers are retained.

## Files

- `index.php`: replaces the bottom Profile button with More and adds its native dialog.
- `assets/js/mobile-inventory.js`: builds More from the current role's rendered sidebar, delegates clicks to the existing handlers, tracks active sections, and handles dialog dismissal and resizing.
- `css/mobile-inventory.css`: phone header, More menu, compact inventory cards, readable controls, focus indicators and safe-area clearance. Existing theme variables are reused.
- `tests/ui/mobile_inventory.js`: extends the existing browser fixture with More routing and absent-admin-entry checks. Its existing PHP builder regenerates `tests/ui/mobile-inventory-runner.html`.

## Navigation and button changes

Inventory, Scan and Reports remain in the bottom bar. More contains Dashboard, Borrowers, Borrow, Return, Damage & Loss Tracking and any other rendered secondary destination, including User Accounts when rendered for Admin. Inventory and Reports are excluded from More to avoid duplicate destinations. Borrow and Return highlight Scan; other secondary sections highlight More.

The phone hamburger is hidden. Its destinations remain available through More, including Borrow and Return without starting a camera. Profile moves to More > My account; settings remain in that account dialog. Logout reuses the original confirmation handler. The phone header settings shortcut moves into account access; the full-width search field and date are hidden on phones, while the existing inventory search button remains available. No inventory actions or business workflows were removed.

Quick Scan appears before New Tool on the mobile inventory home. Existing category shortcuts, real activity, filters, stock controls and expandable detail cards are retained. The Return confirmation summary sits above the bottom bar; active application modal overlays hide the bar. Native dialogs use the browser top layer.

## Verification and limits

`index.php` passed PHP syntax validation. The existing Chrome browser fixture passed 62 checks at 320px, 62 at 390px and 48 at 1440px. Checks cover role-rendered navigation, existing click delegation, scanner handoffs, category filtering, expandable tool data, touch targets and horizontal containment. Fixtures use controlled data and scanner stubs, not live transactions or physical cameras.

Manually verify real Staff/Admin accounts, camera permissions and physical scans, iOS/Android safe areas and keyboard resizing, notification interactions, and Borrow/Return confirmations using controlled records. Review Reports, management screens and tablet layouts with actual account data; this fixture does not establish exhaustive visual coverage of every screen. No database, API, authentication or transaction changes were made.
