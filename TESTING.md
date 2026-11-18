# Testing & CI

This repository ships a Home Assistant add-on (`bud_addon/`) — a PHP 8.3 +
nginx + SQLite app. This document describes every testing option available,
how to run each one locally, and what runs automatically in CI.

## The golden rule: the database lives in `/data`

Under the Home Assistant Supervisor, **`/data` is the only directory that is
persisted** across container restarts, rebuilds and add-on updates. Every
other path inside the container is ephemeral — files written there are
silently destroyed the next time the add-on updates.

The database therefore **must** be at `/data/bud.db`, and three layers
enforce it:

1. **Runtime guard** — [config.php](bud_addon/files/general/www/public/config.php)
   defaults to `/data/bud.db`. If the database directory is missing while the
   app is running inside the add-on (detected via `SUPERVISOR_TOKEN` or the
   s6 service tree), it refuses to start rather than silently falling back to
   ephemeral storage. The local-development fallback only activates outside
   the container.
2. **Static guard** — `tests/check_db_storage.sh` fails CI if the default
   path changes, the guard is removed, a `.db` file is committed into the
   image, the Dockerfile copies a database in, or `config.yaml` maps another
   folder over `/data`.
3. **End-to-end proof** — `tests/docker_persistence_test.sh` builds and runs
   the real container, verifies `bud.db` is created on the mounted `/data`
   volume (and nowhere else), then destroys and recreates the container —
   simulating an add-on update — and asserts the data survived.

For tests and local development, set `BUD_DB_PATH` to point the app at a
throwaway database file: `BUD_DB_PATH=/tmp/test.db php -S ...`

## The second rule: schema changes must work on both install paths

Every database change must work as an **in-place upgrade** (existing installs
with live data — auto-migrations in `config.php`, or admin-triggered migrate
scripts) *and* on a **fresh install** (`schema.sql`, bootstrapped by
`index.php` on first run). A change applied to only one path breaks the
other. Migrations must be idempotent and must never lose data or re-prompt
for historical records (e.g. the 0.14 invoicing migration backfills
already-completed transfers as invoiced). `tests/check_schema.sh` covers the
fresh path; `tests/upgrade_test.sh` covers the upgrade path.

One-off data fixes (1.0.0 onwards) live in `includes/migrations.php` and are
recorded in a `data_migrations` table that `config.php` creates on both
paths, so each fix runs exactly once per database, including a restored
older backup. `tests/nz_time_test.sh` covers the first one, which converts
the UTC times BUD stored before 1.0.0 into NZ time.

## Test suite

All scripts live in `tests/` and are plain bash — run them from the repo
root (Git Bash / WSL on Windows). Each prints `... OK` and exits 0 on
success.

| Script | What it proves | Needs |
| --- | --- | --- |
| `tests/lint.sh` | Every PHP file parses (`php -l`) | php-cli |
| `tests/check_db_storage.sh` | The `/data` storage rules (static, see above) | bash only |
| `tests/check_schema.sh` | `schema.sql` loads cleanly and creates all 11 tables (including columns added over time, e.g. `invoiced_at`), foreign keys resolve — the **fresh install** path | sqlite3 |
| `tests/upgrade_test.sh` | config.php auto-migrations upgrade an existing pre-0.14 database with live data: columns added, historical completed transfers backfilled as invoiced, migration idempotent — the **in-place upgrade** path | php-cli (pdo_sqlite) |
| `tests/smoke_test.sh` | Every page renders without PHP errors against a fresh DB; `BUD_DB_PATH` is honoured; the ephemeral-storage guard fires under the Supervisor | php-cli (pdo_sqlite), curl |
| `tests/audit_undo_test.sh` | `Audit::undo()` (Admin "Undo Last Action") reverses INSERT/UPDATE/DELETE, logs each reversal, and refuses unsafe undos | php-cli (pdo_sqlite) |
| `tests/s29_import_test.sh` | The Section 29 importer (synthetic data only — real S29 files are confidential and must never enter this repo): all three pharmacy layouts incl. native .xlsx, day-first dates + Excel serial dates, product matching, name splitting, Institution override, junk-column exclusion, batch delete | php-cli (pdo_sqlite, zip, simplexml), curl, sqlite3 |
| `tests/nz_time_test.sh` | (1.0.0) The one-off conversion of stored UTC times to NZ time: summer and winter offsets, an early-morning entry on the 1st moving into its month, typed dates and S29 file dates left alone, runs once, and new entries stamped in NZ time | php-cli (pdo_sqlite), sqlite3 |
| `tests/materials_in_test.sh` | (1.0.0) Reports' Materials in counts stock really added and leaves out stock a cancelled transfer put back (tagged, and BUD 0.19's note), the 12-month overview agrees, and the Agency CSV keeps its columns and values | php-cli (pdo_sqlite), curl, sqlite3 |
| `tests/receivers_test.sh` | (1.0.0) Verified receivers are retired, never deleted: hidden from new transfers, address kept on past ones, brought back, and BUD 0.x's delete action retires too | php-cli (pdo_sqlite), curl, sqlite3 |
| `tests/retire_test.sh` | (1.1.0) Stock items and bundles are retired, never deleted: upgrading keeps every item in use, controlled stock only at zero, not while a bundle still offered holds it, off Stock, low stock and every form, refused by the server on a transfer, the destruction register, a bundle, an edit or a second copy, kept in history and Reports, and brought back | php-cli (pdo_sqlite), curl, sqlite3 |
| `tests/courier_test.sh` | (1.0.0) Courier transfers need the courier's name before any stock comes off, completing by uploading the signed manifest keeps only a real photo or PDF (by its first bytes), stores it beside the database and never under the web folder, `coc_document.php` serves it back only for its transfer, on-screen signatures still work, and Your company and receiver licence numbers save | php-cli (pdo_sqlite), curl, sqlite3 |
| `tests/check_version.sh` | `config.yaml`, `BUD_VERSION` in config.php and the top CHANGELOG heading agree | bash only |
| `tests/wording_test.sh` | (1.0.0) The brand guide on every page: tab title matches the heading, menu items and section cards match the pages they open, sentence case, no em dashes, semicolons or emoji, and nothing loaded from the internet (`tests/wording_check.php`) | php-cli (pdo_sqlite, dom), curl, sqlite3 |
| `tests/docker_persistence_test.sh` | Full container: DB created on the `/data` volume only, and data survives container destruction/recreation | docker, curl |

Run everything that doesn't need Docker:

```bash
for t in lint check_db_storage check_schema check_version smoke_test upgrade_test \
    audit_undo_test custody_test s29_import_test nz_time_test materials_in_test \
    receivers_test courier_test retire_test wording_test; do bash tests/$t.sh || break; done
```

The HTTP tests each start PHP's built-in server on their own port (8093 to
8099). If one of those is busy, set its `*_PORT` variable (for example
`CUSTODY_PORT=8443`).

Run the full Docker persistence test (a few minutes on first build):

```bash
# Builds like the HA Supervisor: no BUILD_FROM passed, so the Dockerfile's
# default base image must be valid
bash tests/docker_persistence_test.sh
# Different base image or port:
BUILD_FROM=ghcr.io/home-assistant/amd64-base:3.21 PORT=9000 bash tests/docker_persistence_test.sh
```

## Continuous integration (GitHub Actions)

[.github/workflows/ci.yml](.github/workflows/ci.yml) runs on every push to
`main`, every pull request, and manually via *workflow_dispatch*:

- **PHP syntax lint** — `tests/lint.sh` on PHP 8.3 (same version the add-on ships).
- **Persistent storage guard** — `tests/check_db_storage.sh`.
- **SQLite schema validation** — `tests/check_schema.sh`.
- **Page smoke test** — `tests/smoke_test.sh`.
- **NZ time, Materials in, receivers and courier tests** run
  `tests/nz_time_test.sh`, `tests/materials_in_test.sh`,
  `tests/receivers_test.sh` and `tests/courier_test.sh` (1.0.0).
- **Retire test** runs `tests/retire_test.sh` (1.1.0).
- **Wording and version check** runs `tests/check_version.sh` and
  `tests/wording_test.sh`.
- **Home Assistant add-on lint** — [frenck/action-addon-linter](https://github.com/frenck/action-addon-linter)
  validates `bud_addon/config.yaml` against the official add-on spec.
- **Docker /data persistence test** — `tests/docker_persistence_test.sh` on
  the real amd64 image.
- **Docker build (aarch64)** — cross-builds the image under QEMU so ARM
  (Raspberry Pi) breakage is caught before release.

There is no deploy step: Home Assistant installs add-ons straight from this
git repository, so the moment a change reaches `main` it is live for
installed instances. For that reason `main` is protected — **never commit or
push to `main` directly**. Every change goes through a pull request, and
merging the PR (with CI green) is the release. Bump `version` in
`bud_addon/config.yaml` and add a `CHANGELOG.md` entry as part of the PR —
installed instances only see an update when the version changes.

## Manual testing on a real Home Assistant instance

1. In HA: **Settings → Apps → App store → ⋮ → Repositories** (Settings →
   Add-ons before Home Assistant 2026.2), add a fork/branch of this repo (or
   use the local apps folder: copy `bud_addon/` into `/addons/` on the HA
   host).
2. Install / update **BUD Portal** and open the Web UI via ingress.
3. Persistence spot-check: add a supplier or stock item, then restart the
   app (**Settings → Apps → BUD Portal → Restart**) and confirm the
   data is still there. For the strongest check, uninstall is the only thing
   that removes `/data` — updates and rebuilds must never lose data.
4. Backups: the HA backup system snapshots `/data` automatically when a
   backup includes the app. The admin tools (three clicks on the version
   number under "BUD" in the app bar) also offer a `.db` download and JSON
   export.

## Local development without Docker

```bash
cd bud_addon/files/general/www/public
BUD_DB_PATH=/tmp/bud-dev.db php -S 127.0.0.1:8420
```

Without `BUD_DB_PATH` (and outside the container), the app falls back to
`bud_addon/files/general/www/public/database/bud_inventory.db` — that file is
git-ignored territory; never commit it (CI's storage guard will catch it).

For a BUD with something in it, seed a throwaway database with made-up demo
data first: a company, receivers, stock, transfers in every state (one a
courier delivery with its signed manifest), destruction entries and a
Section 29 import. Dates are relative to today. It never touches `/data`,
and `--force` starts it again:

```bash
BUD_DB_PATH=/tmp/bud-demo/bud.db php tests/seed_demo_data.php --force
cd bud_addon/files/general/www/public
BUD_DB_PATH=/tmp/bud-demo/bud.db php -S 127.0.0.1:8420
```

## Retaking the screenshots

The README and the user guide (`bud_addon/DOCS.md`) show the screenshots in
`docs/screenshots/`. When a release changes how a page looks, retake them:

1. Seed and serve the demo data as above (port 8420).
2. On Windows, from the repo root in PowerShell, run
   `powershell -ExecutionPolicy Bypass -File .\tests\capture_screenshots.ps1`.
   It drives headless Microsoft Edge: desktop shots 1280 px wide, phone
   shots a 390 x 844 screen at 2x, and some open a pop-up or print the
   shipping manifest first. Add `-Only name,name` to retake a few.
3. Look through them before committing. They're public, so only ever demo
   data: no real receivers, patients or cultivar names.

The user guide links the images from GitHub's `main` branch, so Home
Assistant's Documentation tab shows new ones once the pull request is
merged.
