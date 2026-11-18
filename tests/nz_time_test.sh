#!/usr/bin/env bash
# NZ time test (1.0.0). Until 1.0.0 BUD stamped times with SQLite's
# CURRENT_TIMESTAMP, which is UTC, so a destruction at 9:30am on 1 November
# was stored as 31 October and landed in October's register and report.
#
# Proves that config.php's one-off conversion:
#   - turns stored UTC times into NZ time, summer (+13) and winter (+12)
#   - moves an early-morning entry on the 1st into the right month
#   - leaves typed dates (form_date) and S29 file dates alone
#   - runs once: a second request changes nothing, and it's recorded
# and that new entries are stamped in NZ time, not UTC.
#
# Requires: php-cli with pdo_sqlite, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
export BUD_DB_PATH="$TMP/nz.db"

echo "== NZ time test =="

q() { sqlite3 "$BUD_DB_PATH" "$1"; }

# A 0.19 database: today's schema, times written the old way (UTC)
sqlite3 "$BUD_DB_PATH" < "$PUBLIC/database/schema.sql"
q "INSERT INTO stock_items (id, name, quantity, created_at, updated_at) VALUES (1, 'Test Jar', 10, '2026-01-15 01:00:00', '2026-07-15 01:00:00');"
q "INSERT INTO audit_log (table_name, record_id, action, new_values, timestamp) VALUES ('stock_items', 1, 'INSERT', '{\"quantity\":10}', '2026-01-15 01:00:00');"
q "INSERT INTO audit_log (table_name, record_id, action, new_values, timestamp) VALUES ('stock_items', 1, 'UPDATE', '{\"quantity\":12}', '2026-07-15 01:00:00');"
q "INSERT INTO destruction_log (item_name, quantity, reason, method, destroyed_by, destroyed_at) VALUES ('Test Jar', 1, 'Damaged', 'Incineration', 'Tester', '2026-10-31 20:30:00');"
q "INSERT INTO chain_of_custody (form_date, destination, transported_by, coc_items, status, created_at, completed_at, invoiced_at) VALUES ('2026-11-01', 'Test Pharmacy', 'Tester', '[]', 'Completed', '2026-10-31 19:00:00', '2026-10-31 23:15:00', NULL);"
q "INSERT INTO s29_imports (id, filename, pharmacy, imported_at) VALUES (1, 'test.csv', 'Test Pharmacy', '2026-03-31 22:00:00');"
q "INSERT INTO s29_supplies (import_id, supplied_at, supply_month, patient, quantity) VALUES (1, '2026-03-01 00:00:00', '2026-03', 'Testpatient, Ann', 1);"

run_config() {
    php -r 'chdir($argv[1]); require "config.php";' "$PUBLIC"
}

fail=0
check() { # label, actual, expected
    if [ "$2" = "$3" ]; then
        echo "  ok: $1 ($2)"
    else
        echo "FAIL: $1: expected '$3', got '$2'" >&2
        fail=1
    fi
}

run_config

check "summer time is UTC+13" "$(q "SELECT timestamp FROM audit_log WHERE action='INSERT';")" "2026-01-15 14:00:00"
check "winter time is UTC+12" "$(q "SELECT timestamp FROM audit_log WHERE action='UPDATE';")" "2026-07-15 13:00:00"
check "early morning on the 1st moves into its month" "$(q "SELECT destroyed_at FROM destruction_log;")" "2026-11-01 09:30:00"
check "transfer created time" "$(q "SELECT created_at FROM chain_of_custody;")" "2026-11-01 08:00:00"
check "transfer completed time" "$(q "SELECT completed_at FROM chain_of_custody;")" "2026-11-01 12:15:00"
check "empty times stay empty" "$(q "SELECT COALESCE(invoiced_at, 'NULL') FROM chain_of_custody;")" "NULL"
check "typed transfer date untouched" "$(q "SELECT form_date FROM chain_of_custody;")" "2026-11-01"
check "S29 import time" "$(q "SELECT imported_at FROM s29_imports;")" "2026-04-01 11:00:00"
check "S29 supply date from the file untouched" "$(q "SELECT supplied_at FROM s29_supplies;")" "2026-03-01 00:00:00"
check "stock item times" "$(q "SELECT created_at || ' / ' || updated_at FROM stock_items;")" "2026-01-15 14:00:00 / 2026-07-15 13:00:00"
check "conversion recorded once" "$(q "SELECT COUNT(*) FROM data_migrations WHERE name='1.0.0-times-to-nz';")" "1"

# A second request must change nothing
run_config
check "second run leaves times alone" "$(q "SELECT destroyed_at FROM destruction_log;")" "2026-11-01 09:30:00"
check "still recorded once" "$(q "SELECT COUNT(*) FROM data_migrations;")" "1"

# New entries are stamped in NZ time
php -r '
    chdir($argv[1]);
    require "config.php";
    require "includes/audit.php";
    Audit::log($pdo, "stock_items", 1, "UPDATE", ["quantity" => 12], ["quantity" => 13]);
' "$PUBLIC"
stamp=$(q "SELECT timestamp FROM audit_log ORDER BY id DESC LIMIT 1;")
nz_now=$(TZ=Pacific/Auckland date '+%Y-%m-%d %H:%M')
utc_now=$(TZ=UTC date '+%Y-%m-%d %H:%M')
case "$stamp" in
    "$nz_now"*) echo "  ok: new audit entries are stamped in NZ time ($stamp)" ;;
    "$utc_now"*) echo "FAIL: new audit entry stamped in UTC ($stamp)" >&2; fail=1 ;;
    *) # the minute may have ticked over between the two clocks
       echo "  ok: new audit entry stamped $stamp (NZ now $nz_now)" ;;
esac

# No page writes SQLite's UTC clock any more
# (config.php's old table definitions and comments that mention it don't count)
if grep -rn --include=*.php -E "= CURRENT_TIMESTAMP|, CURRENT_TIMESTAMP\)|DATETIME\('now'\)|datetime\('now'\)" "$PUBLIC" \
    | grep -v -E "^[^:]+:[0-9]+:\s*(\*|//)" | grep -v "/config.php:" | grep -v "migrate_v0.13.php"; then
    echo "FAIL: a page still stamps times with SQLite's UTC clock (above)" >&2
    fail=1
else
    echo "  ok: no page stamps times with SQLite's UTC clock"
fi

if [ "$fail" -ne 0 ]; then
    echo "NZ time test FAILED" >&2
    exit 1
fi
echo "NZ time test OK"
