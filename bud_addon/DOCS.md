# BUD Portal

BUD (Business Utility Dashboard) provides stock management, chain of custody
tracking, destruction records and regulatory reporting.

## Data storage & persistence

All of your data lives in a single SQLite database at **`/data/bud.db`**.

`/data` is the add-on's **permanent storage**: Home Assistant preserves it
across add-on restarts, updates and rebuilds. Your data is only removed if
you **uninstall** the add-on. The app is hard-wired to refuse to start if
`/data` is ever unavailable, so data can never be silently written to
temporary container storage.

### Backups

- **Home Assistant backups** automatically include `/data` (and therefore the
  entire database) whenever a backup covers this add-on. This is the
  recommended backup method.
- **Manual backups**: the Admin Dashboard offers a `.db` file download and a
  full JSON export, plus a restore-from-file option.

## Pages

- **Dashboard** — stock overview and low-stock warnings.
- **Suppliers / Stock / Bundles** — inventory management.
- **Chain of Custody** — two-phase transfer tracking with digital signatures,
  packing slips, invoicing flags, and cancellation of mistaken transfers
  (cancelling restores the deducted stock and keeps the record).
- **Destruction** — permanent register of destroyed stock (expired, damaged)
  with reason, method, staff, witness and signature — MCA-ready with CSV
  export. Register entries can never be edited or deleted.
- **Reports** — controlled-substance Materials In/Out and MCA (Ministry of
  Health) report with CSV export.
- **Analytics** — materials-out volumes by month with product and buyer
  breakdown charts.
- **Admin Dashboard** — undo last action, backup/restore, JSON export and
  schema upgrades. Reach it by clicking the dot after "BUD" in the
  navigation bar three times.

## Support

Issues and source: [Project Repository](https://github.com/Chill-Division/bud_portal_repo)
