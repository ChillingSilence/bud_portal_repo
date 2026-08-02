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
| `tests/docker_persistence_test.sh` | Full container: DB created on the `/data` volume only, and data survives container destruction/recreation | docker, curl |

Run everything that doesn't need Docker:

```bash
bash tests/lint.sh && bash tests/check_db_storage.sh && \
bash tests/check_schema.sh && bash tests/smoke_test.sh
```

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

1. In HA: **Settings → Add-ons → Add-on Store → ⋮ → Repositories**, add a
   fork/branch of this repo (or use the *Local add-ons* folder: copy
   `bud_addon/` into `/addons/` on the HA host).
2. Install / update **BUD Portal** and open the Web UI via ingress.
3. Persistence spot-check: add a supplier or stock item, then restart the
   add-on (**Settings → Add-ons → BUD Portal → Restart**) and confirm the
   data is still there. For the strongest check, uninstall is the only thing
   that removes `/data` — updates and rebuilds must never lose data.
4. Backups: the HA backup system snapshots `/data` automatically when a
   backup includes the add-on. The Admin page (triple-click the dot in the
   nav logo) also offers `.db` download and JSON export.

## Local development without Docker

```bash
cd bud_addon/files/general/www/public
BUD_DB_PATH=/tmp/bud-dev.db php -S 127.0.0.1:8420
```

Without `BUD_DB_PATH` (and outside the container), the app falls back to
`bud_addon/files/general/www/public/database/bud_inventory.db` — that file is
git-ignored territory; never commit it (CI's storage guard will catch it).
