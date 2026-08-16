#!/usr/bin/env bash
# In-place upgrade test: every schema change must work on databases that
# already hold live data, not just fresh installs (which check_schema.sh
# covers). This builds a pre-0.14 database with existing Chain of Custody
# records, loads config.php so its auto-migrations run, and verifies:
#
#   - the invoiced_at column is added,
#   - transfers completed BEFORE the upgrade are backfilled as invoiced
#     (so staff are never prompted to invoice historical entries),
#   - in-progress transfers are left un-invoiced,
#   - the migration is idempotent (safe to run on every page load).
#
# Requires: php-cli with pdo_sqlite, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

export BUD_DB_PATH="$TMP/old.db"

echo "== In-place upgrade test =="

# Pre-0.14 chain_of_custody (no invoiced_at) with historical data
cat > "$TMP/setup.php" <<'PHP'
<?php
$pdo = new PDO('sqlite:' . getenv('BUD_DB_PATH'));
$pdo->exec("CREATE TABLE chain_of_custody (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_date DATE NOT NULL,
    origin TEXT DEFAULT 'Main Facility',
    destination TEXT NOT NULL,
    receiver_id INTEGER,
    transported_by TEXT NOT NULL,
    received_by TEXT,
    coc_items JSON NOT NULL,
    signature_image TEXT,
    status TEXT DEFAULT 'In Progress',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME
)");
$pdo->exec("INSERT INTO chain_of_custody (form_date, destination, transported_by, coc_items, status, completed_at)
    VALUES ('2026-01-15', 'Historic Pharmacy', 'Sam', '[]', 'Completed', '2026-01-15 10:00:00')");
$pdo->exec("INSERT INTO chain_of_custody (form_date, destination, transported_by, coc_items, status)
    VALUES ('2026-06-20', 'Current Pharmacy', 'Sam', '[]', 'In Progress')");

// Pre-0.14 installs also had the (since removed) Time Sheet feature
$pdo->exec("CREATE TABLE time_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_name TEXT NOT NULL,
    action TEXT CHECK(action IN ('IN', 'OUT')) NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT
)");
$pdo->exec("INSERT INTO time_logs (staff_name, action) VALUES ('Sam', 'IN')");

// Pre-0.15 installs also had the (since removed) Scheduling feature
$pdo->exec("CREATE TABLE cleaning_schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    frequency TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT 1
)");
$pdo->exec("CREATE TABLE cleaning_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    schedule_id INTEGER NOT NULL,
    staff_name TEXT NOT NULL,
    completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT
)");
$pdo->exec("INSERT INTO cleaning_schedules (name, frequency) VALUES ('Floors', 'Weekly')");
$pdo->exec("INSERT INTO cleaning_logs (schedule_id, staff_name) VALUES (1, 'Sam')");
$pdo->exec("CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    table_name TEXT NOT NULL,
    record_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    changed_by TEXT DEFAULT 'SYSTEM',
    old_values JSON,
    new_values JSON,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO audit_log (table_name, record_id, action) VALUES ('time_logs', 1, 'INSERT')");
$pdo->exec("INSERT INTO audit_log (table_name, record_id, action) VALUES ('cleaning_schedules', 1, 'INSERT')");
$pdo->exec("INSERT INTO audit_log (table_name, record_id, action) VALUES ('cleaning_logs', 1, 'INSERT')");
$pdo->exec("INSERT INTO audit_log (table_name, record_id, action) VALUES ('stock_items', 1, 'UPDATE')");
echo "setup ok\n";
PHP
php "$TMP/setup.php"

# Load config.php twice — the second pass proves idempotence
php -r 'include $argv[1];' "$PUBLIC/config.php"
php -r 'include $argv[1];' "$PUBLIC/config.php"

cat > "$TMP/assert.php" <<'PHP'
<?php
$pdo = new PDO('sqlite:' . getenv('BUD_DB_PATH'));
$fail = 0;

$col = $pdo->query("SELECT COUNT(*) FROM pragma_table_info('chain_of_custody') WHERE name='invoiced_at'")->fetchColumn();
if ($col != 1) { echo "FAIL: invoiced_at column not added by migration\n"; $fail = 1; }
else { echo "  ok: invoiced_at column added\n"; }

$hist = $pdo->query("SELECT invoiced_at FROM chain_of_custody WHERE destination='Historic Pharmacy'")->fetchColumn();
if ($hist === null || $hist === false) { echo "FAIL: pre-existing completed transfer was NOT backfilled as invoiced\n"; $fail = 1; }
else { echo "  ok: historical completed transfer backfilled as invoiced ($hist)\n"; }

$curr = $pdo->query("SELECT invoiced_at FROM chain_of_custody WHERE destination='Current Pharmacy'")->fetchColumn();
if ($curr !== null) { echo "FAIL: in-progress transfer should not be marked invoiced\n"; $fail = 1; }
else { echo "  ok: in-progress transfer left un-invoiced\n"; }

$count = $pdo->query("SELECT COUNT(*) FROM chain_of_custody")->fetchColumn();
if ($count != 2) { echo "FAIL: expected 2 rows after migration, got $count\n"; $fail = 1; }
else { echo "  ok: no rows lost or duplicated\n"; }

$tl = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='time_logs'")->fetchColumn();
if ($tl != 0) { echo "FAIL: time_logs table was not dropped by migration\n"; $fail = 1; }
else { echo "  ok: time_logs table dropped\n"; }

$tl_audit = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE table_name='time_logs'")->fetchColumn();
if ($tl_audit != 0) { echo "FAIL: time_logs audit entries were not purged\n"; $fail = 1; }
else { echo "  ok: time_logs audit entries purged\n"; }

$other_audit = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE table_name='stock_items'")->fetchColumn();
if ($other_audit != 1) { echo "FAIL: unrelated audit entries were lost\n"; $fail = 1; }
else { echo "  ok: unrelated audit entries preserved\n"; }

// v0.15: cancelled_at column added to chain_of_custody
$cc = $pdo->query("SELECT COUNT(*) FROM pragma_table_info('chain_of_custody') WHERE name='cancelled_at'")->fetchColumn();
if ($cc != 1) { echo "FAIL: cancelled_at column not added by migration\n"; $fail = 1; }
else { echo "  ok: cancelled_at column added\n"; }

// v0.18: cancel_reason column added to chain_of_custody
$cr = $pdo->query("SELECT COUNT(*) FROM pragma_table_info('chain_of_custody') WHERE name='cancel_reason'")->fetchColumn();
if ($cr != 1) { echo "FAIL: cancel_reason column not added by migration\n"; $fail = 1; }
else { echo "  ok: cancel_reason column added\n"; }

// v0.15: destruction_log table created on upgrade
$dl = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='destruction_log'")->fetchColumn();
if ($dl != 1) { echo "FAIL: destruction_log table not created by migration\n"; $fail = 1; }
else { echo "  ok: destruction_log table created\n"; }

// v0.16: products + S29 tables created on upgrade, first product seeded
$s29 = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('products','s29_imports','s29_supplies')")->fetchColumn();
if ($s29 != 3) { echo "FAIL: products/S29 tables not created by migration\n"; $fail = 1; }
else { echo "  ok: products + S29 tables created\n"; }

// v0.16.1: freshly created s29_supplies includes raw_quantity
$rq = $pdo->query("SELECT COUNT(*) FROM pragma_table_info('s29_supplies') WHERE name='raw_quantity'")->fetchColumn();
if ($rq != 1) { echo "FAIL: raw_quantity missing from created s29_supplies\n"; $fail = 1; }
else { echo "  ok: raw_quantity present (create path)\n"; }

$seed = $pdo->query("SELECT COUNT(*) FROM products WHERE name='White Sherb'")->fetchColumn();
if ($seed != 1) { echo "FAIL: White Sherb product not seeded\n"; $fail = 1; }
else { echo "  ok: White Sherb product seeded\n"; }

// v0.15: scheduling tables dropped and their audit entries purged
$cl = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('cleaning_schedules','cleaning_logs')")->fetchColumn();
if ($cl != 0) { echo "FAIL: scheduling tables were not dropped by migration\n"; $fail = 1; }
else { echo "  ok: scheduling tables dropped\n"; }

$cl_audit = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE table_name IN ('cleaning_schedules','cleaning_logs')")->fetchColumn();
if ($cl_audit != 0) { echo "FAIL: scheduling audit entries were not purged\n"; $fail = 1; }
else { echo "  ok: scheduling audit entries purged\n"; }

exit($fail);
PHP

if ! php "$TMP/assert.php"; then
    echo "In-place upgrade test FAILED" >&2
    exit 1
fi

# ── ALTER path: a 0.16.0 database already has the S29 tables but not
#    raw_quantity — the migration must add the column, not recreate ──
export BUD_DB_PATH="$TMP/v0160.db"
cat > "$TMP/setup2.php" <<'PHP'
<?php
$pdo = new PDO('sqlite:' . getenv('BUD_DB_PATH'));
$pdo->exec("CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, inn_generic TEXT,
    dose_form TEXT, pack_size TEXT, strength TEXT, is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO products (name) VALUES ('White Sherb')");
$pdo->exec("CREATE TABLE s29_imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT, pharmacy TEXT NOT NULL,
    default_product_id INTEGER, row_count INTEGER DEFAULT 0,
    total_quantity DECIMAL(10,2) DEFAULT 0,
    imported_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE s29_supplies (
    id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INTEGER NOT NULL,
    supplied_at DATETIME, supply_month TEXT, prescriber TEXT,
    prescriber_facility TEXT, patient TEXT, med_name TEXT, med_plu TEXT,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0, product_id INTEGER, pharmacy TEXT)");
$pdo->exec("INSERT INTO s29_imports (pharmacy) VALUES ('Existing Pharmacy')");
$pdo->exec("INSERT INTO s29_supplies (import_id, quantity, med_name) VALUES (1, 3, 'Existing Row')");
echo "setup2 ok\n";
PHP
php "$TMP/setup2.php"
php -r 'include $argv[1];' "$PUBLIC/config.php"

rq=$(sqlite3 "$BUD_DB_PATH" "SELECT COUNT(*) FROM pragma_table_info('s29_supplies') WHERE name='raw_quantity';")
rows=$(sqlite3 "$BUD_DB_PATH" "SELECT COUNT(*) FROM s29_supplies;")
if [ "$rq" = "1" ] && [ "$rows" = "1" ]; then
    echo "  ok: raw_quantity added to existing 0.16.0 database, data intact (alter path)"
else
    echo "FAIL: alter path — raw_quantity=$rq rows=$rows" >&2
    echo "In-place upgrade test FAILED" >&2
    exit 1
fi

echo "In-place upgrade test OK"
