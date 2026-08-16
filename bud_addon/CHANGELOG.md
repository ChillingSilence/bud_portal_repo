# Changelog
## [0.18.0] - 2026-08-15
### Added
- **Cancel completed transfers**: Chain of Custody transfers that are Completed but not yet invoiced can now be cancelled (for duplicates and mistakes). A reason is required, deducted stock is restored, and the record — including the signature — is kept permanently, marked Cancelled with the reason shown in the View modal. Invoiced transfers still cannot be cancelled.

### Fixed
- **Blank signatures blocked**: The "Mark as Received" button now stays disabled until a receiver name is entered AND an actual signature is drawn on the pad. Previously an untouched signature pad still produced a valid (blank) image, so a transfer could be completed with no real autograph.
- **Undo on completions**: Completing a transfer now records the previous values in the audit log, so the Admin "Undo Last Action" can revert a completion back to In Progress. Previously it failed with "No previous values were recorded".

## [0.17.1] - 2026-08-03
### Fixed
- **Test suite**: The S29 and smoke test scripts could report spurious failures (or, in the smoke test's case, mask a real PHP error) due to a `set -o pipefail` interaction with `echo "$var" | grep -q` — `grep -q` exits as soon as it matches, which can SIGPIPE the still-writing `echo` and make the pipeline report a false failure. No functional/runtime change.

## [0.17.0] - 2026-08-02
### Added
- **S29 Top Orders**: New hidden-by-default "🏆 Top Orders" panel on the S29 page with Patients / Prescribers / Places tabs. Shows the top 10 by total quantity for the current filters, combining separate fills by the same person into one row with order count and first/last order dates. Clicking a name filters the supply records to just theirs.

## [0.16.1] - 2026-08-02
### Fixed
- **S29 quantity units**: Some pharmacies report gram totals rather than unit counts (e.g. 20 = 2 × 10 g jars). The upload form now has a "Quantities in File" mode — auto-detect (default, treats the file as grams only when every value is a multiple of the grams-per-unit), units as-is, or grams — with a configurable grams-per-unit (default 10). Converted records keep the original gram value, shown alongside the unit count in the register.
- **S29 name artifacts**: Stray leading/trailing characters on names (e.g. the trailing "-" some exports append to prescriber names) are now stripped on import, without touching hyphens inside names like Jones-Young.

## [0.16.0] - 2026-08-02
### Added
- **Section 29 Register**: New "S29" page to upload the monthly pharmacy reporting files — CSV and Excel (.xlsx, parsed natively; legacy .xls should be saved as .xlsx/CSV first). The importer auto-detects all three known export layouts (including Excel serial dates and combined "Dr X (Clinic)" name splitting), keeps only the Section 29 record fields (practitioner, patient, product, quantity, date, place supplied to) and discards everything else — NHI numbers, DOBs, addresses and the uploaded file itself are never stored. Includes register browsing with month/place/product filters and patient/prescriber search, monthly summary totals, full-format CSV export, and per-import batch deletion for fixing mistakes.
- **Products**: New products table holding each verified product's Section 29 constants (INN/generic name, trade name, dose form, pack size, strength), managed from the S29 page and pre-seeded with White Sherb. Import rows auto-match products by med name with a selectable fallback.
- **Data-file guard**: `.gitignore` and CI now block any CSV/Excel file from ever being committed to the repository (S29 files contain confidential patient data).

## [0.15.0] - 2026-08-02
### Added
- **Cancel Transfer**: In Progress Chain of Custody transfers can now be cancelled (with a confirmation modal). Cancelling restores the stock that was deducted at initiation — bundle components included — and keeps the record permanently with a "Cancelled" badge and timestamp. Cancelled transfers are excluded from reports and analytics.
- **Destruction Register**: New "Destruction" page for recording destroyed stock (expired, damaged, contaminated). Records quantity, batch, reason, method, staff, witness name and witness signature; deducts stock with a full audit trail. The register is permanent (cannot be edited, deleted or undone), filterable by month, and exports to CSV for the Medicinal Cannabis Agency.

### Removed
- **Scheduling**: The cleaning schedules / task scheduling feature has been removed entirely — pages, navigation, dashboard tile, and the `cleaning_schedules` / `cleaning_logs` database tables (dropped automatically on upgrade, including their audit log entries).

## [0.14.4] - 2026-08-02
### Changed
- **Sidebar icon**: Changed the Home Assistant panel icon from `mdi:cannabis` to `mdi:truck-delivery` to avoid clashing with another add-on using the same icon.

## [0.14.3] - 2026-07-12
### Fixed
- **Reports**: Export CSV button text was invisible in light mode.

## [0.14.2] - 2026-07-12
### Changed
- **Dashboard**: Added an Analytics tile to the home dashboard (previously only reachable from the navigation bar).

## [0.14.1] - 2026-07-12
### Fixed
- **Add-on build failure**: 0.14.0 failed to build on Home Assistant instances ("base name ($BUILD_FROM) should not be blank") because the Supervisor no longer supplies a default `BUILD_FROM`. The Dockerfile now defaults to the official multi-arch base image (`ghcr.io/home-assistant/base:latest`). CI now builds Supervisor-style (without passing `BUILD_FROM`) so a broken default fails CI instead of shipping.

## [0.14.0] - 2026-07-02
### Added
- **Analytics Page**: New "Analytics" page showing materials-out by month (stacked bar chart per product) and a pie chart of who's been buying which product, with buyer breakdown and monthly totals tables.
- **Invoicing Flag**: Completed Chain of Custody transfers now show a "Needs Invoice" badge and an "Invoiced" action button (with a confirmation modal) until they are marked as invoiced. Transfers completed before this update are automatically treated as already invoiced, so only new entries prompt for invoicing. The invoiced date appears in the View record.
- **CI Pipeline**: GitHub Actions workflow running PHP lint, SQLite schema validation, page smoke tests, the official Home Assistant add-on linter, an aarch64 cross-build, and a Docker-based `/data` persistence test. See `TESTING.md`.
- **Persistent Storage Guard**: The app now refuses to start with ephemeral storage if `/data` is unavailable inside the add-on — the database can never be silently written to storage that is wiped on update. A `BUD_DB_PATH` environment override supports tests and local development.

### Changed
- **Documentation**: DOCS.md now covers data storage, persistence and backup guidance; README lists the Analytics page.
- **Add-on config**: Removed deprecated `codenotary` and `startup` fields and the `armhf`/`armv7`/`i386` architectures (unsupported since Home Assistant 2025.12) per the official add-on linter.

### Removed
- **Time Sheet**: The staff clock-in/out feature has been removed entirely — the page, its navigation and dashboard links, and the `time_logs` database table (dropped automatically on upgrade, including its audit log entries).

### Fixed
- **Admin Undo**: The "Undo Last Action" button previously crashed with a fatal error (`Audit::undo` was never implemented). It now reverses the last change (deletes an insert, restores an update, re-inserts a deletion) inside a transaction, logs the reversal to the audit trail, and refuses undos that cannot be applied safely.

## [0.13.6]
- **Reports**: Switched to single-column stacked layout (Materials In → Materials Out → 12-Month Overview).
- **Reports**: Moved Export CSV button above the Materials Out heading so copy-selecting the table doesn't capture the button.
- **Reports**: Removed sub-header from Materials Out panel.

## [0.13.5]
- **Reports**: Merged duplicate Materials Out and MCA Report into a single panel with Destination, Address, Product, and Qty columns plus CSV export.
- **Reports**: Simplified product names — now shows item name only (no SKU or bundle reference).
- **Admin**: Replaced schema upgrade panel with Stock Integrity Check tool — verifies stock quantities against audit history and COC deductions.
- **Docs**: Added versioning instructions to README for Home Assistant addon releases.

## [0.13.4]
- **Admin**: Added JSON database export for debugging — exports all tables as a downloadable `.json` file from the Admin Dashboard.

## [0.13.3]
- **Reporting fix**: Corrected reports to include ControlledComponents()

## [0.13.2]
- **Edit fix**: Another fix

## [0.13.1] - 2026-03-04
### Fixed
- **Registered Receivers**: Fixed a syntax error in the Edit functionality caused by unescaped receiver data.

### Changed
- **Reports**: Enhanced Materials Out and MCA reports to expand product bundles into their constituent controlled substances.


## [0.13.0] - 2026-03-04
### Added
- **Two-Phase Chain of Custody**: Split the CoC process into "Initiate Transfer" (shipment) and "Complete Transfer" (receipt).
- **Verified Receivers**: New management page to pre-register destinations and contact persons.
- **Packing Slips**: Ability to print professional packing slips for "In Progress" transfers, including receiver addresses and resolved item names.
- **MCA Report**: New regulatory report section in the MoH/MCA format with CSV export capability.
- **Interim Schema Upgrade**: Added an "Upgrade to v0.13 Schema" button in the Admin Dashboard to backfill receiver data and update the database structure.

### Changed
- **CoC Form**: Replaced destination text input with a dropdown from verified receivers.
- **CoC Signature**: Moved signature capture from the initiation phase to the completion phase.
- **Reports Filtering**: Materials In, Materials Out, and Yearly Overview now show **controlled substances only**.
- **Item Resolution**: Chain of Custody views now resolve bundle IDs into human-readable product name and SKU.

### Fixed
- **Bundle Display**: Fixed an issue where "bundle_1" was displayed instead of the actual bundle name in CoC records.

## [0.12.3] - 2026-02-04
### Fixed
- **Database Lock**: Fixed remaining `SQLITE_LOCKED` error by ensuring *all* schema check cursors (`tables_check`) are closed before migration runs.

## [0.12.2] - 2026-02-04
### Fixed
- **Database Lock**: Added `busy_timeout` and explicitly closed database cursors before running migrations to prevent `SQLITE_LOCKED` errors.

## [0.12.1] - 2026-02-04
### Fixed
- **Migration v0.12**: Fixed Database Locked / Foreign Key violation error during migration by temporarily disabling foreign key checks.

## [0.12.0] - 2026-01-10
### Added
- **Ad-hoc Tasks**: Added support for "Once-off" tasks in Scheduling. These tasks appear in the "Due" list until completed once, then disappear (are not rescheduled).
- **Global History Viewer**: Added a new "View Task History" button on the Scheduling page. This opens a modal where you can view the last 5, 25, or 100 completed tasks across all schedules.
- **Auto-Migration (v0.12)**: System automatically updates the database schema to support the new 'Once-off' frequency option.



## [0.11.3] - 2026-01-10
### Fixed
- **UI Cleanup**: Completely removed obsolete "Supplier" and "Category" fields from the Add Stock and Edit Stock forms, ensuring a cleaner interface consistent with the table view.
- **Documentation**: Added comprehensive `README.md` to the addon root directory.



## [0.11.2] - 2026-01-10
### Fixed
- **Audit Logging**: Enhanced audit logs to include specific context when items are deducted. Stock history will now explicitly state if an item was removed as part of a Bundle shipment via COC, or as a direct single-item COC transfer.
- **Documentation**: Updated project README to correctly reflect the SQLite architecture and remove obsolete MariaDB references.



## [0.11.1] - 2026-01-03
### Fixed
- **Dropdown Styling**: Fixed readability of category labels (`optgroup`) in dark mode by ensuring they have the correct background and contrast.



## [0.11.0] - 2026-01-03
### Added
- **COC Bundle Integration**: Product bundles can now be selected in Chain of Custody forms. Sending a bundle automatically deducts all its component items from stock.
- **Stock Action Enhancements**:
    - **Quick Adjustments**: New "Add" and "Remove" action buttons on the Stock page for rapid inventory updates.
    - **Validation**: System prevents removing more stock than available and enforces that Controlled Substances must only be removed via Chain of Custody forms.
    - **Audit History**: New "📜 History" button for each stock item showing a detailed log of all quantity changes, including notes and context (e.g., if it was part of a bundle shipment).
- **Filtered COC selection**: Chain of Custody item selection now only shows Controlled Substances by default to prevent accidental shipment of non-controlled items (like stickers) via COC forms.

### Changed
- **Stock UI Refinement**: Removed "Supplier" and "Category" columns from the main Stock Inventory table to improve readability and provide space for new action buttons.
- **Dashboard**: Replaced placeholder references with proper scheduling links.

### Technical
- Added `get_stock_history.php` endpoint for fetching audit logs.
- Updated `custody.php` backend to resolve bundle component IDs and handle automatic multi-item deductions.
- Enforced `is_controlled` validation flag across adjustment pathways.



## [0.10.3] - 2026-01-03
### Changed
- **Dashboard**: Fixed invalid references, removed old html file

## [0.10.2] - 2026-01-03
### Changed
- **Navigation**: Moved Bundles link from main navigation to Stock page for better organization
- **Dashboard**: Updated references from "Cleaning" to "Scheduling" to reflect current feature naming

## [0.10.1] - 2026-01-03
### Fixed
- **Auto-Migration System**: Database schema now updates automatically on any page load. No manual migration required when upgrading to v0.10.
- Removed manual migration script in favor of automatic migration in `config.php`

## [0.10] - 2026-01-03
### Added
- **Bundle Management System**: New "Bundles" page allows creating product bundles (e.g., "Finished Box" containing multiple stock items). When bundles are shipped via Chain of Custody, all component items are automatically deducted from stock.
- **COC Stock Deduction**: Chain of Custody submissions now automatically reduce stock quantities for shipped items, with full audit trail logging.
- **Scheduling - Upcoming Tasks**: New section displays tasks that will be due within 24 hours.
- **Scheduling - Edit Capability**: Edit button for each schedule with modal form to modify name, frequency, and description.
- **Scheduling - Completion History**: History button shows last 7 completions for each schedule with staff names, dates, and notes.
- **Timesheet - 7-Day Reporting**: New comprehensive weekly summary with:
  - Daily breakdown showing total hours and staff count per day
  - Staff breakdown showing total hours per person with daily details
- **Navigation**: Added "Bundles" link to main navigation.

### Technical
- Added `product_bundles` and `bundle_items` database tables
- New files: `bundles.php`, `get_bundle_items.php`, `get_schedule_history.php`
- Enhanced `custody.php` with automatic stock deduction logic
- Enhanced `scheduling.php` with upcoming tasks classification and edit/history modals
- Enhanced `timesheet.php` with 7-day historical analysis and aggregation

### Notes
- Bundle system supports controlled substance tracking - controlled items in bundles are properly logged for regulatory compliance
- Stock deductions are audited in `audit_log` for full traceability
- Scheduling upcoming threshold is configurable (currently 24 hours before due date)



## [0.9.10] - 2024-05-21
### Added
- **Global Timezone**: Enforced 'Pacific/Auckland' timezone across the application to ensure correct timestamps in logs and reports.
- **Scheduling**: Renamed "Cleaning" module to "Scheduling" to better reflect its versatility for general recurring tasks.
- **Documentation**: Completely rewrote README for better user clarity.

### Fixed
- **Responsive Tables**: Added horizontal scrolling to data tables (`.table-responsive`) to prevent layout breakage on mobile devices.
- **Timesheet**: Now explicitly records local time for Sign In/Out actions.

## [0.9.9] - 2024-05-21
### Fixed
- Fixed signature canvas alignment issue where the drawn line was offset from the cursor/finger. It now correctly calculates coordinates based on the canvas display size vs internal resolution.

## 0.9.8
- Bug Fix: Fixed Regression causing unbroken Navigation and Theme Toggle
