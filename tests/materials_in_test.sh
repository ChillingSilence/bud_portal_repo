#!/usr/bin/env bash
# Materials in test (1.0.0). Until 1.0.0, cancelling a chain of custody
# transfer put its stock back, and the monthly report counted that as
# controlled stock received ("+7 jars" for a cancelled 7-jar transfer).
#
# Proves over HTTP that Reports' Materials in:
#   - counts stock that's really added (Add stock)
#   - leaves out stock a cancelled transfer put back, both the 1.0.0 tagged
#     kind and the note BUD 0.15 to 0.19 left ("Restored via Cancelled COC #")
#   - and that the 12-month overview's Total in agrees
# and that the Agency CSV columns are unchanged.
#
# Requires: php-cli with pdo_sqlite, curl, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${MATERIALS_PORT:-8097}"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

export BUD_DB_PATH="$TMP/materials.db"
q() { sqlite3 "$BUD_DB_PATH" "$1"; }

echo "== Materials in test =="

sqlite3 "$BUD_DB_PATH" < "$PUBLIC/database/schema.sql"
q "INSERT INTO verified_receivers (name, address) VALUES ('Test Pharmacy', '1 Test St');"
q "INSERT INTO stock_items (id, name, sku, quantity, unit, is_controlled) VALUES (1, 'Test Flower', 'TF1', 100, 'jars', 1);"

php -S "127.0.0.1:$PORT" -t "$PUBLIC" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do
    curl -fsS "http://127.0.0.1:$PORT/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

fail=0
month=$(TZ=Pacific/Auckland date +%Y-%m)

# Stock really received: +10
curl -fsS -X POST "http://127.0.0.1:$PORT/stock.php" \
    -d "action=adjust_stock" -d "item_id=1" -d "operation=add" -d "quantity=10" -d "notes=Restock" >/dev/null

# A transfer of 7 that's cancelled: 7 off, then 7 back
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=create_coc" -d "date=$(TZ=Pacific/Auckland date +%F)" -d "origin=Main Facility" \
    -d "receiver_id=1" -d "transported_by=Tester" \
    -d "item_id[]=1" -d "qty[]=7" -d "batch[]=B1" >/dev/null
coc=$(q "SELECT MAX(id) FROM chain_of_custody;")
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=cancel_coc" -d "coc_id=$coc" -d "cancel_reason=" >/dev/null
[ "$(q 'SELECT CAST(quantity AS INTEGER) FROM stock_items WHERE id=1;')" = "110" ] \
    && echo "  ok: cancelling put the 7 back (110 on hand)" \
    || { echo "FAIL: stock after cancel" >&2; fail=1; }
tagged=$(q "SELECT COUNT(*) FROM audit_log WHERE table_name='stock_items' AND new_values LIKE '%transfer_cancelled%';")
[ "$tagged" = "1" ] && echo "  ok: the put-back is tagged as a cancelled transfer" \
    || { echo "FAIL: put-back not tagged ($tagged)" >&2; fail=1; }

# A put-back from BUD 0.19, which only left a note
q "INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, timestamp) VALUES ('stock_items', 1, 'UPDATE', '{\"quantity\":110}', '{\"quantity\":115,\"adjustment_notes\":\"Restored via Cancelled COC #99\"}', '$(TZ=Pacific/Auckland date '+%Y-%m-%d %H:%M:%S')');"

# A completed transfer of 3, so Materials out has a row for the CSV check
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=create_coc" -d "date=$(TZ=Pacific/Auckland date +%F)" -d "origin=Main Facility" \
    -d "receiver_id=1" -d "transported_by=Tester" \
    -d "item_id[]=1" -d "qty[]=3" -d "batch[]=B2" >/dev/null
coc2=$(q "SELECT MAX(id) FROM chain_of_custody;")
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=complete_coc" -d "coc_id=$coc2" -d "received_by=Tess Taker" \
    --data-urlencode "signature_data=data:image/png;base64,iVBORw0KGgoTEST" >/dev/null

page=$(curl -fsS "http://127.0.0.1:$PORT/reports.php?month=$month")
in_rows=$(grep -c -E 'num num--in">\+[0-9.,]+ jars' <<< "$page" || true)
grep -q '+10 jars' <<< "$page" \
    && echo "  ok: stock added shows in Materials in (+10 jars)" \
    || { echo "FAIL: +10 jars missing from Materials in" >&2; fail=1; }
if grep -q -E '\+7 jars|\+5 jars' <<< "$page"; then
    echo "FAIL: stock put back by a cancelled transfer counted as received" >&2
    fail=1
else
    echo "  ok: put-backs from cancelled transfers aren't counted (new and 0.19 style)"
fi
[ "$in_rows" = "1" ] && echo "  ok: one Materials in row" \
    || { echo "FAIL: expected 1 Materials in row, got $in_rows" >&2; fail=1; }

# The 12-month overview agrees: this month's Total in is +10
this_month=$(TZ=Pacific/Auckland date +%B)
grep -A1 "<td>$this_month</td>" <<< "$page" | grep -q '+10<' \
    && echo "  ok: 12-month overview counts +10 for $this_month" \
    || { echo "FAIL: 12-month overview Total in for $this_month isn't +10" >&2; fail=1; }

# The Agency CSV keeps BUD 0.19's columns and values ("-3", not "−3")
csv_heads=$( (grep -o '<th[^>]*data-csv="[^"]*"' <<< "$page" || true) | sed 's/.*data-csv="//; s/"$//' | paste -sd, -)
[ "$csv_heads" = "Date,Destination,Address,Product,Qty" ] \
    && echo "  ok: Agency CSV columns unchanged ($csv_heads)" \
    || { echo "FAIL: Agency CSV columns changed: '$csv_heads'" >&2; fail=1; }
grep -q 'data-csv="-3"' <<< "$page" \
    && echo "  ok: Agency CSV quantity exported as -3" \
    || { echo "FAIL: Agency CSV quantity isn't -3" >&2; fail=1; }
grep -q "data-csv=\"$(TZ=Pacific/Auckland date +%F)\"" <<< "$page" \
    && echo "  ok: Agency CSV date exported as stored (YYYY-MM-DD)" \
    || { echo "FAIL: Agency CSV date isn't YYYY-MM-DD" >&2; fail=1; }

if [ "$fail" -ne 0 ]; then
    echo "Materials in test FAILED" >&2
    exit 1
fi
echo "Materials in test OK"
