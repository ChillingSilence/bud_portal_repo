#!/usr/bin/env bash
# Integration test of the Chain of Custody lifecycle over HTTP:
#   - initiate (stock deducted, bundle components expanded)
#   - complete (signature + receiver; audit entry must record OLD values so
#     the Admin "Undo Last Action" can revert a completion — v0.18 fix)
#   - server-side rejection of completions without name/signature
#   - cancel a Completed-but-not-invoiced transfer (v0.18): requires a
#     reason, restores stock, keeps the record as Cancelled
#   - invoiced transfers can NOT be cancelled
#
# Requires: php-cli with pdo_sqlite, curl, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${CUSTODY_PORT:-8099}"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

export BUD_DB_PATH="$TMP/custody.db"

echo "== Custody lifecycle test =="

q() { sqlite3 "$BUD_DB_PATH" "$1"; }

php -r '
    $pdo = new PDO("sqlite:" . getenv("BUD_DB_PATH"));
    $pdo->exec(file_get_contents($argv[1]));
' "$PUBLIC/database/schema.sql"

# Seed: one receiver, two stock items, one bundle (2x jar + 1x box per bundle)
q "INSERT INTO verified_receivers (name, contact_person, address) VALUES ('Test Pharmacy', 'Tess Taker', '1 Test St');"
q "INSERT INTO suppliers (name) VALUES ('Test Supplier');"
q "INSERT INTO stock_items (supplier_id, name, sku, quantity) VALUES (1, 'Test Jar', 'JAR1', 100);"
q "INSERT INTO stock_items (supplier_id, name, sku, quantity) VALUES (1, 'Test Box', 'BOX1', 100);"
q "INSERT INTO product_bundles (name) VALUES ('Test Bundle');"
q "INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (1, 1, 2);"
q "INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (1, 2, 1);"

php -S "127.0.0.1:$PORT" -t "$PUBLIC" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do
    curl -fsS "http://127.0.0.1:$PORT/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

fail=0
SIG='data:image/png;base64,iVBORw0KGgoTESTSIGNATURE'

# ── Initiate: 5 bundles -> jar -10, box -5 ───────────────────────────────────
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=create_coc" -d "date=2026-08-15" -d "origin=Main Facility" \
    -d "receiver_id=1" -d "transported_by=Tester" \
    -d "item_id[]=bundle_1" -d "qty[]=5" -d "batch[]=B1" >/dev/null

[ "$(q 'SELECT CAST(quantity AS INTEGER) FROM stock_items WHERE id=1;')" = "90" ] \
    && [ "$(q 'SELECT CAST(quantity AS INTEGER) FROM stock_items WHERE id=2;')" = "95" ] \
    && echo "  ok: initiation deducts bundle components (jar 100->90, box 100->95)" \
    || { echo "FAIL: initiation stock deduction" >&2; fail=1; }

coc1=$(q "SELECT id FROM chain_of_custody ORDER BY id DESC LIMIT 1;")

# ── Completion without a signature must be rejected server-side ──────────────
resp=$(curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=complete_coc" -d "coc_id=$coc1" -d "received_by=No Signature" \
    -d "signature_data=")
[ "$(q "SELECT status FROM chain_of_custody WHERE id=$coc1;")" = "In Progress" ] \
    && echo "  ok: completion without signature rejected" \
    || { echo "FAIL: completion without signature was accepted" >&2; fail=1; }

# ── Complete properly: audit entry must carry old_values ─────────────────────
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=complete_coc" -d "coc_id=$coc1" -d "received_by=Tess Taker" \
    --data-urlencode "signature_data=$SIG" >/dev/null

[ "$(q "SELECT status FROM chain_of_custody WHERE id=$coc1;")" = "Completed" ] \
    && echo "  ok: transfer completed" || { echo "FAIL: completion" >&2; fail=1; }

old_vals=$(q "SELECT old_values FROM audit_log WHERE table_name='chain_of_custody' AND record_id=$coc1 AND action='UPDATE' ORDER BY id DESC LIMIT 1;")
case "$old_vals" in
    *'"status":"In Progress"'*) echo "  ok: completion audit records old values (undo-able)" ;;
    *) echo "FAIL: completion audit old_values missing/wrong: '$old_vals'" >&2; fail=1 ;;
esac

# ── Cancel a Completed transfer: reason required ─────────────────────────────
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=cancel_coc" -d "coc_id=$coc1" -d "cancel_reason=" >/dev/null
[ "$(q "SELECT status FROM chain_of_custody WHERE id=$coc1;")" = "Completed" ] \
    && echo "  ok: cancelling a completed transfer without a reason is rejected" \
    || { echo "FAIL: completed transfer cancelled without a reason" >&2; fail=1; }

curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=cancel_coc" -d "coc_id=$coc1" \
    --data-urlencode "cancel_reason=Duplicate opened by mistake" >/dev/null

[ "$(q "SELECT status FROM chain_of_custody WHERE id=$coc1;")" = "Cancelled" ] \
    && [ "$(q "SELECT cancel_reason FROM chain_of_custody WHERE id=$coc1;")" = "Duplicate opened by mistake" ] \
    && echo "  ok: completed transfer cancelled with reason recorded" \
    || { echo "FAIL: cancel-completed" >&2; fail=1; }

[ "$(q 'SELECT CAST(quantity AS INTEGER) FROM stock_items WHERE id=1;')" = "100" ] \
    && [ "$(q 'SELECT CAST(quantity AS INTEGER) FROM stock_items WHERE id=2;')" = "100" ] \
    && echo "  ok: cancelling restored bundle component stock (jar/box back to 100)" \
    || { echo "FAIL: stock not restored on cancel" >&2; fail=1; }

# The record must be kept, with the signature intact
sig_len=$(q "SELECT LENGTH(signature_image) FROM chain_of_custody WHERE id=$coc1;")
[ -n "$sig_len" ] && [ "$sig_len" -gt 0 ] \
    && echo "  ok: cancelled record kept with signature" \
    || { echo "FAIL: cancelled record lost its signature" >&2; fail=1; }

# ── Invoiced transfers can not be cancelled ──────────────────────────────────
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=create_coc" -d "date=2026-08-15" -d "origin=Main Facility" \
    -d "receiver_id=1" -d "transported_by=Tester" \
    -d "item_id[]=1" -d "qty[]=10" -d "batch[]=B2" >/dev/null
coc2=$(q "SELECT id FROM chain_of_custody ORDER BY id DESC LIMIT 1;")

curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=complete_coc" -d "coc_id=$coc2" -d "received_by=Tess Taker" \
    --data-urlencode "signature_data=$SIG" >/dev/null
curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=mark_invoiced" -d "coc_id=$coc2" >/dev/null

curl -fsS -X POST "http://127.0.0.1:$PORT/custody.php" \
    -d "action=cancel_coc" -d "coc_id=$coc2" \
    --data-urlencode "cancel_reason=Should not work" >/dev/null

[ "$(q "SELECT status FROM chain_of_custody WHERE id=$coc2;")" = "Completed" ] \
    && [ "$(q 'SELECT CAST(quantity AS INTEGER) FROM stock_items WHERE id=1;')" = "90" ] \
    && echo "  ok: invoiced transfer can not be cancelled" \
    || { echo "FAIL: invoiced transfer was cancelled" >&2; fail=1; }

if [ "$fail" -ne 0 ]; then
    echo "Custody lifecycle test FAILED" >&2
    exit 1
fi
echo "Custody lifecycle test OK"
