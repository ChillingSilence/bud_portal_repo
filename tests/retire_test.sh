#!/usr/bin/env bash
# Retire test (1.1.0). Stock items and bundles you no longer use can be
# retired: they drop off Stock, low stock and every new entry, and are never
# deleted, so history and reports keep them.
#
# Proves that:
#   - upgrading adds the column with every existing item still in use
#   - controlled stock can only be retired at zero, other stock any time
#   - an item in a bundle that's still offered can't be retired
#   - retired items move to the Retired list, off low stock and the forms
#   - the server refuses a retired item on a transfer, the destruction
#     register, a bundle, an edit or an add, and won't add it a second time
#   - history names it, and Reports still show its past transfers
#   - bundles retire and come back, but not while an item in them is retired
#   - BUD 0.x's bundle delete action retires too, never deletes
#
# Requires: php-cli with pdo_sqlite, curl, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${RETIRE_PORT:-8093}"
B="http://127.0.0.1:$PORT"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

fail=0
ok() { echo "  ok: $1"; }
bad() { echo "FAIL: $1" >&2; fail=1; }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (got '$2', expected '$3')"; fi; }
has() { grep -qF -- "$2" <<< "$1"; }
check_has() { if has "$2" "$3"; then ok "$1"; else bad "$1"; fi; }
check_not() { if has "$2" "$3"; then bad "$1"; else ok "$1"; fi; }

echo "== Retire test =="

# 1. Upgrading from 1.0.1: stock_items had no is_active column
export BUD_DB_PATH="$TMP/upgrade.db"
sed '/CREATE TABLE IF NOT EXISTS stock_items/,/);/{/is_active/d}' "$PUBLIC/database/schema.sql" > "$TMP/old_schema.sql"
sqlite3 "$BUD_DB_PATH" < "$TMP/old_schema.sql"
sqlite3 "$BUD_DB_PATH" "INSERT INTO stock_items (name, sku, quantity, unit) VALUES ('Old Item', 'OI1', 4, 'units');"
expect "a 1.0.1 database has no is_active on stock items" \
    "$(sqlite3 "$BUD_DB_PATH" "SELECT COUNT(*) FROM pragma_table_info('stock_items') WHERE name='is_active';")" "0"
php -r 'include $argv[1];' "$PUBLIC/config.php"
expect "upgrading adds it, with existing items in use" \
    "$(sqlite3 "$BUD_DB_PATH" "SELECT COUNT(*) FROM pragma_table_info('stock_items') WHERE name='is_active';")/$(sqlite3 "$BUD_DB_PATH" "SELECT is_active FROM stock_items;")" "1/1"

# 2. The pages
export BUD_DB_PATH="$TMP/retire.db"
q() { sqlite3 "$BUD_DB_PATH" "$1"; }
today=$(TZ=Pacific/Auckland date +%F)

sqlite3 "$BUD_DB_PATH" < "$PUBLIC/database/schema.sql"
q "INSERT INTO verified_receivers (id, name, address) VALUES (1, 'Joe''s Pharmacy', '10 Example Street, Hamilton');"
q "INSERT INTO stock_items (id, name, sku, quantity, unit, reorder_level, is_controlled) VALUES
    (1, 'Test Flower', 'TF1', 100, 'jars', 10, 1),
    (2, 'Old Flower', 'OF1', 0, 'jars', 0, 1),
    (3, 'Spare Labels', 'SL1', 13, 'units', 50, 0),
    (4, 'Old Jar', 'OJ1', 0, 'units', 10, 0),
    (5, 'Bundle Part', 'BP1', 20, 'units', 5, 0);"
q "INSERT INTO product_bundles (id, name, sku, is_active) VALUES (1, 'Test Pack', 'TP1', 1);"
q "INSERT INTO bundle_items (bundle_id, stock_item_id, quantity) VALUES (1, 5, 1);"
q "INSERT INTO chain_of_custody (form_date, destination, receiver_id, transported_by, coc_items, status, received_by, signature_image, completed_at) VALUES ('$today', 'Joe''s Pharmacy', 1, 'Tester', '[{\"item_id\":\"2\",\"qty\":\"5\",\"batch\":\"B1\"}]', 'Completed', 'Jo', 'data:image/png;base64,X', '$today 10:00:00');"

php -S "127.0.0.1:$PORT" -t "$PUBLIC" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do
    curl -fsS "$B/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

post() { local page=$1; shift; curl -fsS -X POST "$B/$page" "$@"; }
active() { q "SELECT is_active FROM stock_items WHERE id = $1;"; }

# Who can be retired
page=$(post stock.php -d action=retire_item -d item_id=1)
expect "controlled stock still on hand can't be retired" "$(active 1)" "1"
check_has "...and the page says what's left" "$page" "still has 100 jars on hand"
post stock.php -d action=retire_item -d item_id=2 >/dev/null
expect "controlled stock at zero can be retired" "$(active 2)" "0"
post stock.php -d action=retire_item -d item_id=3 >/dev/null
expect "other stock can be retired with some left, and keeps it" "$(active 3)/$(q 'SELECT quantity FROM stock_items WHERE id = 3;')" "0/13"
page=$(post stock.php -d action=retire_item -d item_id=5)
expect "an item in a bundle that's still offered can't be retired" "$(active 5)" "1"
check_has "...and the page names the bundle" "$page" "is in the bundle Test Pack"
expect "nothing was deleted" "$(q 'SELECT COUNT(*) FROM stock_items;')" "5"

# Where retired items show, and where they don't
page=$(curl -fsS "$B/stock.php")
main=${page%%id=\"retired-heading\"*}
retired=${page#*id=\"retired-heading\"}
check_has "Stock lists items in use" "$main" "Test Flower"
check_not "...but not retired ones" "$main" "Spare Labels"
check_has "retired ones are under Retired" "$retired" "Spare Labels"
check_has "...with Bring back" "$retired" "Bring back"
check_has "Test Flower's Retire explains why it can't go yet" "$main" "data-notice-title=\"Test Flower can't be retired yet\""
dash=$(curl -fsS "$B/index.php")
check_has "low stock still lists items in use" "$dash" "Old Jar"
check_not "...but not retired ones" "$dash" "Spare Labels"
destruction=$(curl -fsS "$B/destruction.php")
check_has "the destruction register offers items in use" "$destruction" "Bundle Part"
check_not "...but not retired ones" "$destruction" "Spare Labels"
check_not "a retired item can't be put in a bundle" "$(curl -fsS "$B/bundles.php")" "Spare Labels"

# The server refuses a retired item, even from a form opened earlier
transfers=$(q 'SELECT COUNT(*) FROM chain_of_custody;')
page=$(post custody.php -d action=create_coc -d "date=$today" -d "origin=Main Facility" -d receiver_id=1 \
    -d transported_by=Tester -d delivery_method=staff -d 'item_id[]=2' -d 'qty[]=1' -d 'batch[]=B2')
expect "a transfer with a retired item is refused" "$(q 'SELECT COUNT(*) FROM chain_of_custody;')/$(q 'SELECT quantity FROM stock_items WHERE id = 2;')" "$transfers/0"
check_has "...saying why" "$page" "Old Flower is retired"
page=$(post destruction.php -d action=destroy -d item_id=3 -d qty=1 -d reason=Damaged -d method=Shredded -d destroyed_by=Tester)
expect "the destruction register refuses a retired item" "$(q 'SELECT COUNT(*) FROM destruction_log;')/$(q 'SELECT quantity FROM stock_items WHERE id = 3;')" "0/13"
post stock.php -d action=adjust_stock -d item_id=3 -d operation=add -d quantity=5 >/dev/null
expect "stock can't be added to a retired item" "$(q 'SELECT quantity FROM stock_items WHERE id = 3;')" "13"
post stock.php -d action=edit -d id=3 -d name=Renamed -d sku=SL1 -d quantity=13 -d unit=units -d reorder_level=50 -d description= >/dev/null
expect "a retired item can't be edited" "$(q 'SELECT name FROM stock_items WHERE id = 3;')" "Spare Labels"
page=$(post bundles.php -d action=create -d "name=New Pack" -d 'bundle_item_id[]=3' -d 'bundle_item_qty[]=1')
expect "a bundle with a retired item is refused" "$(q 'SELECT COUNT(*) FROM product_bundles;')" "1"
check_has "...saying why" "$page" "Spare Labels is retired"
page=$(post stock.php -d action=create -d "name=New Labels" -d sku=sl1 -d quantity=1 -d unit=units -d reorder_level= -d description=)
check_has "adding a retired item's SKU again points to Bring back" "$page" "Spare Labels (SL1) is retired"
post stock.php -d action=create -d "name=old flower" -d sku= -d quantity=0 -d unit=jars -d reorder_level= -d description= >/dev/null
expect "...and so does its name, so there's never a second copy" "$(q 'SELECT COUNT(*) FROM stock_items;')" "5"

# History and reports keep retired items
check_has "the item's history says it was retired" "$(curl -fsS "$B/get_stock_history.php?id=3")" '"type":"Retired"'
check_has "Reports still show a retired item's past transfer" "$(curl -fsS "$B/reports.php?month=${today:0:7}")" "Old Flower"

# Bundles
post bundles.php -d action=retire_bundle -d id=1 >/dev/null
expect "a bundle can be retired" "$(q 'SELECT is_active FROM product_bundles WHERE id = 1;')" "0"
check_not "...and isn't offered on new transfers" "$(curl -fsS "$B/custody.php")" 'value="bundle_1"'
page=$(post custody.php -d action=create_coc -d "date=$today" -d "origin=Main Facility" -d receiver_id=1 \
    -d transported_by=Tester -d delivery_method=staff -d 'item_id[]=bundle_1' -d 'qty[]=1' -d 'batch[]=B3')
expect "a transfer with a retired bundle is refused" "$(q 'SELECT COUNT(*) FROM chain_of_custody;')/$(q 'SELECT quantity FROM stock_items WHERE id = 5;')" "$transfers/20"
post stock.php -d action=retire_item -d item_id=5 >/dev/null
expect "once its bundle is retired, the item can be" "$(active 5)" "0"
page=$(post bundles.php -d action=restore_bundle -d id=1)
expect "a bundle can't come back while an item in it is retired" "$(q 'SELECT is_active FROM product_bundles WHERE id = 1;')" "0"
check_has "...saying which" "$page" "Bundle Part"
post stock.php -d action=restore_item -d item_id=5 >/dev/null
post bundles.php -d action=restore_bundle -d id=1 >/dev/null
expect "bringing the item back, then the bundle, works" "$(active 5)/$(q 'SELECT is_active FROM product_bundles WHERE id = 1;')" "1/1"
check_has "...and the bundle is offered again" "$(curl -fsS "$B/custody.php")" 'value="bundle_1"'
post bundles.php -d action=delete -d id=1 >/dev/null
expect "the old delete action retires a bundle, never deletes it" "$(q 'SELECT COUNT(*) || "/" || is_active FROM product_bundles WHERE id = 1;')" "1/0"

# Bring back
post stock.php -d action=restore_item -d item_id=3 >/dev/null
expect "Bring back puts an item back in use" "$(active 3)" "1"
check_has "...and it's offered again" "$(curl -fsS "$B/destruction.php")" "Spare Labels"
check_has "its history says it was brought back" "$(curl -fsS "$B/get_stock_history.php?id=3")" '"type":"Brought back"'

# PHP warnings go to the server's log, not the page. The PHP install's own
# startup messages ("in Unknown on line 0") aren't from BUD's code.
if grep -E "PHP (Warning|Notice|Deprecated|Fatal)" "$TMP/server.log" | grep -v "in Unknown on line 0"; then
    bad "PHP reported problems (above)"
fi

if [ "$fail" -ne 0 ]; then
    echo "Retire test FAILED" >&2
    exit 1
fi
echo "Retire test OK"
