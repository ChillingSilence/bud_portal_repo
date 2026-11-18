#!/usr/bin/env bash
# Courier transfers and signed manifests (1.0.0), over HTTP:
#   - a courier transfer needs the courier's name, checked before any stock
#     comes off, and keeps the courier and tracking number
#   - completing it by uploading the signed manifest: only a photo (JPG, PNG,
#     WebP) or a PDF is kept, by its first bytes, never by its name
#   - the file is kept beside the database (/data in the app), never under
#     the web folder, and coc_document.php hands it back for that transfer
#   - on-screen signatures still complete a transfer as before
#   - Your company's details and a receiver's licence number are saved
#
# Requires: php-cli with pdo_sqlite, curl, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${COURIER_PORT:-8094}"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

export BUD_DB_PATH="$TMP/db/courier.db"
mkdir -p "$TMP/db"
q() { sqlite3 "$BUD_DB_PATH" "$1"; }
B="http://127.0.0.1:$PORT"

echo "== Courier and signed manifest test =="

sqlite3 "$BUD_DB_PATH" < "$PUBLIC/database/schema.sql"
q "INSERT INTO verified_receivers (id, name, contact_person, address) VALUES (1, 'Test Pharmacy', 'Tess Taker', '1 Test St');"
q "INSERT INTO stock_items (id, name, sku, quantity, unit, is_controlled) VALUES (1, 'Test Flower', 'TF1', 100, 'jars', 1);"

php -S "127.0.0.1:$PORT" -t "$PUBLIC" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do
    curl -fsS "$B/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

fail=0
check() { # label, actual, expected
    if [ "$2" = "$3" ]; then echo "  ok: $1"; else echo "FAIL: $1: expected '$3', got '$2'" >&2; fail=1; fi
}
today=$(TZ=Pacific/Auckland date +%F)
create() { # extra curl args...
    curl -fsS -o /dev/null -X POST "$B/custody.php" -d "action=create_coc" -d "date=$today" -d "origin=Main Facility" \
        -d "receiver_id=1" -d "transported_by=Tester" -d "item_id[]=1" -d "qty[]=5" -d "batch[]=B1" "$@"
}

# A courier with no name: refused before any stock comes off
create -d "delivery_method=courier" -d "courier_company="
check "a courier transfer without the courier's name is refused" "$(q 'SELECT COUNT(*) FROM chain_of_custody;')" "0"
check "and no stock came off" "$(q 'SELECT CAST(quantity AS INTEGER) FROM stock_items WHERE id=1;')" "100"

# A proper courier transfer
create -d "delivery_method=courier" -d "courier_company=Test Couriers" -d "tracking_number=TC123"
coc=$(q "SELECT MAX(id) FROM chain_of_custody;")
check "courier, company and tracking kept" "$(q "SELECT delivery_method || '|' || courier_company || '|' || tracking_number FROM chain_of_custody WHERE id=$coc;")" "courier|Test Couriers|TC123"

complete_doc() { # file path, then optional curl args
    local file="$1"; shift
    curl -fsS -o /dev/null -X POST "$B/custody.php" -F "action=complete_coc" -F "coc_id=$coc" \
        -F "received_by=Tess Taker" -F "sign_method=document" -F "signed_document=@$file" "$@"
}

# No file: refused
curl -fsS -o /dev/null -X POST "$B/custody.php" -F "action=complete_coc" -F "coc_id=$coc" \
    -F "received_by=Tess Taker" -F "sign_method=document"
check "completing by upload without a file is refused" "$(q "SELECT status FROM chain_of_custody WHERE id=$coc;")" "In Progress"

# A text file called .pdf: refused, nothing kept
printf 'not really a pdf\n' > "$TMP/fake.pdf"
complete_doc "$TMP/fake.pdf"
check "a file that isn't a photo or PDF is refused" "$(q "SELECT status FROM chain_of_custody WHERE id=$coc;")" "In Progress"
check "and nothing was kept" "$(ls "$TMP/db/coc_documents" 2>/dev/null | wc -l | tr -d ' ')" "0"

# A real PDF: completes, kept beside the database
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$TMP/signed.pdf"
complete_doc "$TMP/signed.pdf" -F "tracking_number=TC999"
check "completing with the signed manifest" "$(q "SELECT status || '|' || received_by FROM chain_of_custody WHERE id=$coc;")" "Completed|Tess Taker"
check "no on-screen signature stored" "$(q "SELECT COALESCE(signature_image, 'NULL') FROM chain_of_custody WHERE id=$coc;")" "NULL"
check "the tracking number given at completion is kept" "$(q "SELECT tracking_number FROM chain_of_custody WHERE id=$coc;")" "TC999"
stored=$(q "SELECT signed_document FROM chain_of_custody WHERE id=$coc;")
[ -f "$TMP/db/coc_documents/$stored" ] && cmp -s "$TMP/db/coc_documents/$stored" "$TMP/signed.pdf" \
    && echo "  ok: the signed manifest is kept beside the database ($stored)" \
    || { echo "FAIL: signed manifest not kept beside the database" >&2; fail=1; }
check "its original name is kept to show" "$(q "SELECT signed_document_name FROM chain_of_custody WHERE id=$coc;")" "signed.pdf"
strays=$(find "$PUBLIC" -name 'transfer-*' -type f | wc -l | tr -d ' ')
check "nothing was written under the web folder" "$strays" "0"

# coc_document.php hands it back, and only for its own transfer
headers=$(curl -fsS -D - -o "$TMP/back.pdf" "$B/coc_document.php?id=$coc")
grep -qi '^Content-Type: application/pdf' <<< "$headers" && cmp -s "$TMP/back.pdf" "$TMP/signed.pdf" \
    && echo "  ok: coc_document.php serves it back, as a PDF" \
    || { echo "FAIL: coc_document.php didn't serve the PDF" >&2; fail=1; }
check "a transfer with no signed manifest gets a 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/coc_document.php?id=9999")" "404"
check "the address bar can't name a file" "$(curl -s -o /dev/null -w '%{http_code}' "$B/coc_document.php?id=../$stored")" "404"

# A JPEG photo works too, for a second courier transfer
create -d "delivery_method=courier" -d "courier_company=Test Couriers"
coc=$(q "SELECT MAX(id) FROM chain_of_custody;")
printf '\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9' > "$TMP/photo.jpg"
complete_doc "$TMP/photo.jpg"
check "a JPEG photo of the signed manifest completes it" "$(q "SELECT status || '|' || substr(signed_document, -4) FROM chain_of_custody WHERE id=$coc;")" "Completed|.jpg"

# Our staff, signed on screen: as before
create
coc=$(q "SELECT MAX(id) FROM chain_of_custody;")
check "a staff transfer is recorded as our staff" "$(q "SELECT delivery_method FROM chain_of_custody WHERE id=$coc;")" "staff"
curl -fsS -o /dev/null -X POST "$B/custody.php" -d "action=complete_coc" -d "coc_id=$coc" \
    -d "received_by=Tess Taker" --data-urlencode "signature_data=data:image/png;base64,iVBORw0KGgoTEST"
check "an on-screen signature still completes it" "$(q "SELECT status || '|' || COALESCE(signed_document, 'none') FROM chain_of_custody WHERE id=$coc;")" "Completed|none"

# Your company
curl -fsS -o /dev/null -X POST "$B/company.php" -d "action=save_company" -d "company_name=Test Growers Ltd" \
    -d "company_licence=MCL-1" -d "company_address=1 Grow Rd" -d "company_email=dispatch@example.com"
check "your company's details are saved" "$(q "SELECT value FROM settings WHERE name='company_name';")" "Test Growers Ltd"
curl -fsS -o /dev/null -X POST "$B/company.php" -d "action=save_company" -d "company_name=Changed" -d "company_email=not an email"
check "a bad email doesn't save over them" "$(q "SELECT value FROM settings WHERE name='company_name';")" "Test Growers Ltd"
grep -q 'Test Growers Ltd' <<< "$(curl -fsS "$B/custody.php")" \
    && echo "  ok: the details reach the shipping manifest" \
    || { echo "FAIL: company details missing from the custody page" >&2; fail=1; }

# A receiver's licence number
curl -fsS -o /dev/null -X POST "$B/receivers.php" -d "action=add_receiver" -d "name=Licensed Pharmacy" -d "licence_number=PH-42"
check "a receiver's licence number is saved" "$(q "SELECT licence_number FROM verified_receivers WHERE name='Licensed Pharmacy';")" "PH-42"

# PHP warnings go to the server's log, not the page. The PHP install's own
# startup messages ("in Unknown on line 0") aren't from BUD's code.
if grep -E "PHP (Warning|Notice|Deprecated|Fatal)" "$TMP/server.log" | grep -v "in Unknown on line 0"; then
    echo "FAIL: PHP reported problems (above)" >&2
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    echo "Courier test FAILED" >&2
    exit 1
fi
echo "Courier test OK"
