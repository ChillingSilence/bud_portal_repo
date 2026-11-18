#!/usr/bin/env bash
# Wording and consistency test (1.0.0): boots BUD with some sample records,
# so the tables and buttons render, and runs tests/wording_check.php over
# every page. See that file for what it checks.
#
# Requires: php-cli with pdo_sqlite and dom, curl, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${WORDING_PORT:-8095}"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

export BUD_DB_PATH="$TMP/wording.db"
q() { sqlite3 "$BUD_DB_PATH" "$1"; }

echo "== Wording test =="

sqlite3 "$BUD_DB_PATH" < "$PUBLIC/database/schema.sql"
today=$(TZ=Pacific/Auckland date +%F)
q "INSERT INTO suppliers (name, contact_person, email) VALUES ('Test Supplies', 'Sam', 'sam@example.com');"
q "INSERT INTO verified_receivers (name, contact_person, address) VALUES ('Joe''s Pharmacy', 'Jo', '1 Test St');"
q "INSERT INTO verified_receivers (name, is_active) VALUES ('Old Pharmacy', 0);"
q "INSERT INTO stock_items (id, name, sku, quantity, unit, reorder_level, is_controlled) VALUES (1, 'Test Flower', 'TF1', 5, 'jars', 10, 1);"
q "INSERT INTO stock_items (id, name, sku, quantity, unit, reorder_level, is_controlled) VALUES (2, 'Test Jar', 'TJ1', 50, 'units', '', 0);"
q "INSERT INTO product_bundles (id, name, sku) VALUES (1, 'Test Pack', 'TP1');"
q "INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (1, 1, 1), (1, 2, 1);"
q "INSERT INTO chain_of_custody (form_date, destination, receiver_id, transported_by, coc_items, status) VALUES ('$today', 'Joe''s Pharmacy', 1, 'Tester', '[{\"item_id\":\"1\",\"qty\":\"1\",\"batch\":\"B1\"}]', 'In Progress');"
q "INSERT INTO chain_of_custody (form_date, destination, receiver_id, transported_by, coc_items, status, received_by, completed_at) VALUES ('$today', 'Joe''s Pharmacy', 1, 'Tester', '[{\"item_id\":\"bundle_1\",\"qty\":\"2\",\"batch\":\"\"}]', 'Completed', 'Jo', '$today 10:00:00');"
q "INSERT INTO destruction_log (item_name, quantity, unit, reason, method, destroyed_by, witness, destroyed_at) VALUES ('Test Flower (TF1)', 1, 'jars', 'Damaged', 'Incineration', 'Tester', 'Witness', '$today 09:00:00');"
q "INSERT INTO s29_imports (id, filename, pharmacy, row_count, total_quantity, imported_at) VALUES (1, 'test.csv', 'Joe''s Pharmacy', 1, 2, '$today 08:00:00');"
q "INSERT INTO s29_supplies (import_id, supplied_at, supply_month, prescriber, patient, med_name, quantity, pharmacy) VALUES (1, '$today 00:00:00', '${today:0:7}', 'Dr Test', 'Testpatient, Ann', 'Test Flower', 2, 'Joe''s Pharmacy');"
q "INSERT INTO audit_log (table_name, record_id, action, old_values, new_values) VALUES ('stock_items', 1, 'UPDATE', '{\"quantity\":4}', '{\"quantity\":5}');"

php -S "127.0.0.1:$PORT" -t "$PUBLIC" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do
    curl -fsS "http://127.0.0.1:$PORT/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

if ! php tests/wording_check.php "http://127.0.0.1:$PORT"; then
    echo "Wording test FAILED" >&2
    exit 1
fi
# PHP warnings and notices go to the server's log, not the page, so a page
# can look fine while a variable is missing. Treat any as a failure, except
# the PHP install's own startup messages ("in Unknown on line 0", like the
# JIT notice on GitHub's runners), which aren't from BUD's code.
if grep -E "PHP (Warning|Notice|Deprecated|Fatal)" "$TMP/server.log" | grep -v "in Unknown on line 0"; then
    echo "FAIL: PHP reported problems while serving the pages (above)" >&2
    exit 1
else
    echo "  ok: no PHP warnings or notices"
fi

echo "Wording test OK"
