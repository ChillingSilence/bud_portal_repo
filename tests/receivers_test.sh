#!/usr/bin/env bash
# Verified receivers test (1.0.0). Until 1.0.0, Delete removed a receiver for
# good, and past transfers lost its address on packing slips and the report.
#
# Proves over HTTP that:
#   - Retire hides a receiver from new transfers but keeps the row
#   - a past transfer still shows the retired receiver's address in Reports
#   - Bring back offers it again
#   - BUD 0.x's delete_receiver action retires too, never deletes
#
# Requires: php-cli with pdo_sqlite, curl, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${RECEIVERS_PORT:-8096}"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

export BUD_DB_PATH="$TMP/receivers.db"
q() { sqlite3 "$BUD_DB_PATH" "$1"; }

echo "== Verified receivers test =="

sqlite3 "$BUD_DB_PATH" < "$PUBLIC/database/schema.sql"
q "INSERT INTO verified_receivers (id, name, address) VALUES (1, 'Joe''s Pharmacy', '10 Example Street, Hamilton');"
q "INSERT INTO stock_items (id, name, sku, quantity, unit, is_controlled) VALUES (1, 'Test Flower', 'TF1', 100, 'jars', 1);"
today=$(TZ=Pacific/Auckland date +%F)
q "INSERT INTO chain_of_custody (form_date, destination, receiver_id, transported_by, coc_items, status, received_by, signature_image, completed_at, invoiced_at) VALUES ('$today', 'Joe''s Pharmacy', 1, 'Tester', '[{\"item_id\":\"1\",\"qty\":\"2\",\"batch\":\"B1\"}]', 'Completed', 'Jo', 'data:image/png;base64,X', '$today 10:00:00', '$today 11:00:00');"

php -S "127.0.0.1:$PORT" -t "$PUBLIC" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do
    curl -fsS "http://127.0.0.1:$PORT/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

fail=0

# Retire
curl -fsS -X POST "http://127.0.0.1:$PORT/receivers.php" -d "action=retire_receiver" -d "receiver_id=1" >/dev/null
[ "$(q 'SELECT COUNT(*) || "/" || MAX(is_active) FROM verified_receivers;')" = "1/0" ] \
    && echo "  ok: retire keeps the receiver, marked retired" \
    || { echo "FAIL: retire" >&2; fail=1; }
custody=$(curl -fsS "http://127.0.0.1:$PORT/custody.php")
grep -q '<option value="1">Joe&#039;s Pharmacy' <<< "$custody" \
    && { echo "FAIL: a retired receiver is still offered for new transfers" >&2; fail=1; } \
    || echo "  ok: a retired receiver isn't offered for new transfers"
reports=$(curl -fsS "http://127.0.0.1:$PORT/reports.php?month=${today:0:7}")
grep -q '10 Example Street, Hamilton' <<< "$reports" \
    && echo "  ok: the past transfer keeps the retired receiver's address in Reports" \
    || { echo "FAIL: address lost from Reports" >&2; fail=1; }
list=$(curl -fsS "http://127.0.0.1:$PORT/receivers.php")
grep -q 'Retired' <<< "$list" && echo "  ok: the list shows it as retired" \
    || { echo "FAIL: list doesn't show Retired" >&2; fail=1; }

# Bring back
curl -fsS -X POST "http://127.0.0.1:$PORT/receivers.php" -d "action=restore_receiver" -d "receiver_id=1" >/dev/null
[ "$(q 'SELECT is_active FROM verified_receivers WHERE id=1;')" = "1" ] \
    && grep -q '<option value="1">Joe&#039;s Pharmacy' <<< "$(curl -fsS "http://127.0.0.1:$PORT/custody.php")" \
    && echo "  ok: bring back offers it for new transfers again" \
    || { echo "FAIL: restore" >&2; fail=1; }

# BUD 0.x pages posted delete_receiver: it retires now
curl -fsS -X POST "http://127.0.0.1:$PORT/receivers.php" -d "action=delete_receiver" -d "receiver_id=1" >/dev/null
[ "$(q 'SELECT COUNT(*) || "/" || MAX(is_active) FROM verified_receivers;')" = "1/0" ] \
    && echo "  ok: the old delete action retires, it never deletes" \
    || { echo "FAIL: delete_receiver removed the row" >&2; fail=1; }
grep -q "DELETE FROM verified_receivers" "$PUBLIC/receivers.php" \
    && { echo "FAIL: receivers.php can still delete a receiver" >&2; fail=1; } \
    || echo "  ok: receivers.php has no DELETE"

if [ "$fail" -ne 0 ]; then
    echo "Verified receivers test FAILED" >&2
    exit 1
fi
echo "Verified receivers test OK"
