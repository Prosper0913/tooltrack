# Mobile Borrow / Scan checkout

Phone layout applies below 768px. Existing checkout, role checks, scanner implementations, API payloads, validation and database behavior are retained.

## Staff workflow

1. Search by borrower name or ID and select a result, or choose Scan ID. The selected identity replaces search; Change uses the existing clear-selection handler. External ID scanner input remains available in a disclosure.
2. Search for a tool and select it, or choose Scan QR / Barcode or Upload QR. Set its quantity and choose Add Tool. Repeat for additional tools.
3. Review Selected Tools, edit quantities, or remove a tool. Total requested quantity remains below the list.
4. Set due date/time and notes. Admins retain their conditionally rendered policy override reason.
5. Choose Review checkout, check the existing review sheet, then Complete checkout & issue tools. Existing busy locks and retry tokens remain responsible for preventing duplicate records. Success/error feedback is unchanged.

## Consolidated controls

- Phone welcome banner, repeated Borrow/Scan quick actions, cart jump and overview strip are hidden. Actual scanner buttons remain beside manual lookup; the cart is in the form sequence. Overview information remains on the system Dashboard.
- Existing Borrow / Scan, Active Borrowings, Overdue and History controls are retained inside Borrowing views on phones. Desktop/tablet controls remain expanded. The recent-history shortcut is consolidated into History; Reports remains available through bottom navigation.
- The empty read-only scanned-code field is hidden on phones and appears when populated. It remains the same scanner display, with its clear action.
- External ID input moves into Use external ID scanner; camera Scan ID remains immediately available. QR image upload stays visible beside tool scanning.
- The checkout button is in the document flow. It opens the existing review; only its final confirmation submits the loan.

## Files

- `index.php`: stylesheet/script references, compact heading, views disclosure, phone labels.
- `assets/js/mobile-checkout.js`: moves existing nodes at the phone breakpoint, restores original desktop/tablet positions, and manages secondary disclosures. No cloned inputs or new checkout state.
- `css/mobile-checkout.css`: scoped phone layout, compact nonsticky system header, cards, readable controls, labeled change/remove actions and safe-area spacing.
- `tests/ui/build_mobile_checkout_runner.php`, `tests/ui/mobile_checkout.js`: controlled browser fixtures for ordering, layout and retained controls.
- `tests/ui/run_tool_details_preview.ps1`: accepts optional fixture/artifact names for reuse across browser checks.

Generated test runner HTML, screenshots, metrics and logs are under `tests/ui/`.

## Verification

Chrome fixture checks passed at 320px and 390px (26 assertions each), and 820px and 1440px (15 each). The existing tool-selection/scanner fixture passed 19 assertions, covering camera and image-upload callbacks, manual selection, quantity addition and stale selection prevention. PHP syntax validation passed for `index.php`.

The fixtures use controlled data and scanner stubs. They do not establish physical camera/barcode performance, live Staff/Admin authorization, actual checkout persistence, device keyboard/safe-area behavior, or external browser/app banners. These require manual review using controlled records. The skip link retains its existing focus-only behavior. Only one reference image was available in this request; the current layout was inspected from source.
