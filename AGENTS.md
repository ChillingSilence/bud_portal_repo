# AGENTS.md

Rules for AI coding assistants and human contributors working on BUD.

AI tools pick this file up automatically (Claude Code through `CLAUDE.md`,
and Codex, Cursor, GitHub Copilot and others read it directly). People should
read it too before opening a pull request. [TESTING.md](TESTING.md) covers
the test suite and running BUD locally.

BUD is used in live production. Destructive changes and experiments must be
gated and tested.

## 1. Persistent storage (absolute rule)

BUD runs as a Home Assistant app inside a Docker container. Only `/data` is a
persistent volume. Everything else in the container is wiped on every update.

- The database is `/data/bud.db`. This path must never change.
- `config.php` refuses to start if `/data` is missing inside the app, rather
  than writing somewhere that's wiped. `BUD_DB_PATH` points tests and local
  development at a throwaway database.
- Signed shipping manifests (photos and PDFs uploaded for courier
  deliveries) are files in `coc_documents/` beside the database, so
  `/data/coc_documents` in the app. Never under the web folder. Only
  `coc_document.php` hands one out, for its own transfer, by the stored
  name (`includes/documents.php`).
- `tests/check_db_storage.sh` checks all of this in CI.

This rule takes precedence over every other consideration.

## 2. Database changes upgrade in place

The people using BUD can't run an upgrade by hand. `config.php` upgrades the
database on every request.

- Schema changes must upgrade an existing database automatically, with no
  data loss, and be safe to run again on every request. A fresh install gets
  `database/schema.sql`, so a change has to work on both paths
  (`tests/check_schema.sh` and `tests/upgrade_test.sh`).
- Close every cursor (`$stmt = null;`) before an `ALTER TABLE` or
  `DROP TABLE`, or SQLite reports the table as locked.
- A one-off data fix (not a schema change) runs exactly once per database.
  Put it in `includes/migrations.php` and record it in the `data_migrations`
  table, the way `bud_convert_times_to_nz()` does.
- Every stored time is NZ time (Pacific/Auckland), because the Agency's
  months are NZ months. Stamp records with `bud_now()`. Never use SQLite's
  `CURRENT_TIMESTAMP` or `DATETIME('now')`: they're UTC, 12 or 13 hours
  behind (`tests/nz_time_test.sh` checks).
- The audit log (`audit_log`) is how stock history, the Admin undo and
  Materials in work. Log every stock change with `Audit::log()` and keep the
  history intact through migrations.

## 3. Product rules

- Chain of custody records are kept forever. A mistake is cancelled (its
  stock goes back), never deleted.
- A transfer is completed with the receiver's name and either a signature
  drawn on screen or the signed shipping manifest uploaded (a photo or PDF,
  checked by its first bytes, never its name). One of the two, always.
- The shipping manifest follows the security SOPs: the person it's sent to
  and their address, the controlled drug (cannabis), the quantity, how it
  travels (our staff, or the courier company), and lines for the sender,
  the courier and the receiver to sign (`js/print-transfer.js`).
- The destruction register is permanent. No editing, deleting or undoing an
  entry, not even through the Admin undo.
- Verified receivers are retired, never deleted. Past transfers link to
  them, and their address belongs on those packing slips and reports.
- Stock items and bundles are retired, never deleted (`is_active`). A
  retired one is left out of Stock, low stock and every form, and the
  server refuses it on a transfer, the destruction register or a bundle.
  History and reports keep it. Controlled stock can only be retired at
  zero, and an item can't be retired while a bundle that's still offered
  holds it (`includes/stock.php`, `tests/retire_test.sh`).
- Controlled stock only leaves through a chain of custody transfer or the
  destruction register.
- Stock that a cancelled transfer puts back isn't stock received. Tag it
  `'movement' => 'transfer_cancelled'` so Materials in leaves it out
  (`bud_is_cancel_restore()`).
- Section 29 files hold confidential patient details. Never commit a CSV or
  Excel file, and use made-up data in tests and screenshots.
- CSV exports for the Agency keep their columns and values. When a screen
  shows something differently (NZ dates, a minus sign), the export uses the
  stored value.
- Authentication is Home Assistant's job. There's no login.
- BUD is served through Home Assistant's ingress, under a deep path. Always
  use relative URLs for links, assets and requests.
- Nothing loads from the internet. Pico, Inter and Chart.js are served by
  the app (with their licences beside them).
- The app image runs PHP 8.3 with only pdo, pdo_sqlite, session, simplexml
  and zip (see `bud_addon/Dockerfile`). Functions from other extensions,
  such as `mb_strtolower()` or `ctype_digit()`, work on a dev PC but crash in
  production.

## 4. The look

BUD follows the GRACe Portal's layout, in BUD's violet (#6d28d9), with the
Chill Division brand guide's buttons, type and colours.

- Every page uses `includes/header.php` and `includes/footer.php`, with a
  `<main class="container">` and an `hgroup.page-header` in between.
- The menu has four sections: Dashboard, Tracking, Reporting and
  Administration. A page belongs to one of them (`includes/nav.php`). The
  admin tools stay hidden, opened by three clicks on the version number.
- Use the classes in `css/bud.css`: one main (filled) button per area,
  `secondary outline` for the rest, `danger` for anything that can't be
  undone. Pop-ups are `<dialog class="bud-modal">`, tables sit in
  `.table-wrap`, and wide tables get `wide-table` so values stay on one line
  on a phone.
- Line icons from Lucide (`includes/icons.php`). No emoji.
- Charts use the brand's chart colours, chart green #2F7D4F, blue #3B6FB6 and
  orange #C05600, in that order, three series at most (the rest go into
  "Other" in grey #C9C8C2). No pies.

## 5. Writing for people

- On-screen text follows the Chill Division brand guide: sentence case for
  headings, buttons, labels, table headers and cards (names keep their
  capitals: BUD, S29, CSV, the Medicinal Cannabis Agency), New Zealand
  English, no em or en dashes, no semicolons, and no colon at the end of a
  form label.
- Dates show as 18/11/2026 (`bud_date()` in PHP, `formatDate()` in JS).
- A menu item or a card on Tracking, Reporting or Administration has the
  same name as the page it opens, in its heading and browser tab.
- Home Assistant renamed add-ons to apps in 2026.2. Anything people read says
  app, and Settings → Apps for where to find it. Keep add-on for the
  technical side (the add-on image, `config.yaml`, the `bud_addon` folder).
  Never change the `bud_addon` slug: Home Assistant would treat BUD as a
  different app, cut off from every install's data.
- `tests/wording_test.sh` checks all of this.

## 6. Releases and the changelog

Home Assistant installs BUD straight from `main`, so merging is releasing.
`main` is protected: every change goes through a pull request.

- A release bumps the version in `bud_addon/config.yaml`, `BUD_VERSION` in
  `config.php` and the top heading of `bud_addon/CHANGELOG.md`
  (`tests/check_version.sh` checks they agree). Installs only see an update
  when the version changes.
- If a release changes how a page looks, retake the screenshots in
  `docs/screenshots/` (TESTING.md, "Retaking the screenshots"). They're
  public: demo data from `tests/seed_demo_data.php` only, never real
  receivers, patients or cultivar names.
- The changelog is read by the people using BUD, in Home Assistant's update
  dialog. Keep it short and plain: say what changed for them, not how.
  Heading `## [x.y.z] - YYYY-MM-DD`, newest at the top, then a one-line
  summary and these sections, leaving out empty ones: `### What's new`,
  `### Important fixes`, `### Changes`, `### Minor bug fixes and reliability
  improvements`. A bullet starts with a bold sentence where that helps.

## 7. Before you open a pull request

Every test in TESTING.md that doesn't need Docker must pass, and new
behaviour gets a test wired into `.github/workflows/ci.yml`.
