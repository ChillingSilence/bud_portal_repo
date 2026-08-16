#!/usr/bin/env bash
# End-to-end test of the Section 29 CSV importer using SYNTHETIC data only
# (real S29 files contain confidential patient information and must never
# appear in this repository or its tests).
#
# Covers all three known pharmacy export layouts:
#   - the full ~55-column CSV dispensing export (extra columns discarded)
#   - the compact 9-column CSV export
#   - the .xlsx clinic export (dispensing_date / patient_name / prescriber_name)
# and verifies: day-first date parsing (2- and 4-digit years), Excel serial
# dates, product auto-matching with default-product fallback, per-row
# Institution override, combined-name splitting ("Dr X (Clinic)"),
# junk-column exclusion, and import batch deletion.
#
# Requires: php-cli with pdo_sqlite + zip + simplexml, curl, sqlite3.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${S29_PORT:-8098}"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

export BUD_DB_PATH="$TMP/s29.db"

echo "== S29 import test =="

php -r '
    $pdo = new PDO("sqlite:" . getenv("BUD_DB_PATH"));
    $pdo->exec(file_get_contents($argv[1]));
' "$PUBLIC/database/schema.sql"

php -S "127.0.0.1:$PORT" -t "$PUBLIC" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 20); do
    curl -fsS "http://127.0.0.1:$PORT/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

# Synthetic layout A: full export with junk columns (subset, same headers)
cat > "$TMP/full_layout.csv" <<'EOF'
"Rx number","Date time","Prescriber last name","Prescriber first names","Prescriber facility name","Patient ID","Patient last name","Patient first names","NHI number","DOB","Med cost","Quantity","Med name","Med plu","Directions","Institution","Staff"
"100001","03/04/26 09:15","Testdoc","Alice","Test Clinic A","P1","Testpatient","Bob","ZZZ0001","01/01/90","100.00","10","WHITE SHERB THC20+CBD1% 20%+1% 10","10000880","Take as directed","","TS"
"100002","15/04/26 14:30","Testdoc","Alice","Test Clinic A","P2","Testpatient","Carol","ZZZ0002","02/02/91","100.00","20","WHITE SHERB THC20+CBD1% 20%+1% 10","10000880","Take as directed","Synthetic Clinic","TS"
"100003","28/04/26 16:45","Otherdoc -","Dan","Test Clinic B","P3","Testpatient","Eve","ZZZ0003","03/03/92","100.00","15","Thc 20+CBD 1 20%+1% 10","10000880","Take as directed","","TS"
EOF

# Synthetic layout B: compact 9-column export, 4-digit years
cat > "$TMP/compact_layout.csv" <<'EOF'
Date time,Prescriber last name,Prescriber first names,Prescriber facility name,Patient last name,Patient first names,Quantity,Med name,Med plu
5/05/2026 8:05,Testdoc,Alice,Test Clinic A,Testpatient,Frank,10,WHITE SHERB THC 20% + CBD 1% 200mg+10mg/g Dried Flower 10,1000042
19/05/2026 13:40,Otherdoc,Dan,Test Clinic B,Testpatient,Grace,30,WHITE SHERB THC 20% + CBD 1% 200mg+10mg/g Dried Flower 10,1000042
EOF

# Synthetic layout C: .xlsx clinic export, built with the same ZIP+XML
# structure the real files use (inline strings, one numeric serial date)
php -r '
$path = $argv[1];
$zip = new ZipArchive();
$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString("[Content_Types].xml", "<?xml version=\"1.0\"?><Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\"><Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/><Default Extension=\"xml\" ContentType=\"application/xml\"/><Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/><Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/></Types>");
$zip->addFromString("_rels/.rels", "<?xml version=\"1.0\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>");
$zip->addFromString("xl/workbook.xml", "<?xml version=\"1.0\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Sheet1\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>");
$zip->addFromString("xl/_rels/workbook.xml.rels", "<?xml version=\"1.0\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/></Relationships>");
function is_cell($ref, $text) { return "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars($text, ENT_XML1) . "</t></is></c>"; }
function n_cell($ref, $num) { return "<c r=\"$ref\"><v>$num</v></c>"; }
$sheet = "<?xml version=\"1.0\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>";
$sheet .= "<row r=\"1\">" . is_cell("A1","dispensing_date") . is_cell("B1","script_number") . is_cell("C1","medicine_id") . is_cell("D1","quantity") . is_cell("E1","product_name") . is_cell("F1","supplier") . is_cell("G1","patient_name") . is_cell("H1","nhi") . is_cell("I1","prescriber_name") . "</row>";
$sheet .= "<row r=\"2\">" . is_cell("A2","12/03/2026") . is_cell("B2","S1") . is_cell("C2","M1") . n_cell("D2","5") . is_cell("E2","WHITE SHERB THC20+CBD1% 20%+1% 10") . is_cell("F2","Synthetic Supplier") . is_cell("G2","Testpatient, Harry") . is_cell("H2","ZZZ7777") . is_cell("I2","Dr Synthetic Doctor (Synthetic Clinic)") . "</row>";
$sheet .= "<row r=\"3\">" . n_cell("A3","46096") . is_cell("B3","S2") . is_cell("C3","M2") . n_cell("D3","7") . is_cell("E3","WHITE SHERB THC20+CBD1% 20%+1% 10") . is_cell("F3","Synthetic Supplier") . is_cell("G3","Testpatient, Iris") . is_cell("H3","ZZZ8888") . is_cell("I3","Dr Synthetic Doctor (Synthetic Clinic)") . "</row>";
$sheet .= "</sheetData></worksheet>";
$zip->addFromString("xl/worksheets/sheet1.xml", $sheet);
$zip->close();
echo "xlsx built\n";
' "$TMP/clinic_layout.xlsx"

fail=0
q() { sqlite3 "$BUD_DB_PATH" "$1"; }

# White Sherb product is seeded by config.php on first page load
product_id=$(q "SELECT id FROM products WHERE name='White Sherb';")
if [ -z "$product_id" ]; then
    echo "FAIL: White Sherb product was not seeded" >&2
    exit 1
fi
echo "  ok: White Sherb product seeded (id $product_id)"

# Import layout A with explicit units mode (quantities are already jars)
resp=$(curl -fsS -X POST "http://127.0.0.1:$PORT/s29.php" \
    -F "action=upload_s29" \
    -F "pharmacy=Synthetic Test Pharmacy" \
    -F "default_product_id=$product_id" \
    -F "qty_mode=units" \
    -F "csv_file=@$TMP/full_layout.csv")
grep -q "Imported 3 records" <<< "$resp" || { echo "FAIL: layout A import (response did not confirm 3 records)" >&2; fail=1; }
grep -q "divided by" <<< "$resp" && { echo "FAIL: units mode must not convert quantities" >&2; fail=1; }
echo "  ok: layout A imported (units mode, no conversion)"

# Import layout B in auto mode: 10 and 30 are all multiples of 10 g,
# so auto-detect must treat them as grams (1 and 3 units)
resp=$(curl -fsS -X POST "http://127.0.0.1:$PORT/s29.php" \
    -F "action=upload_s29" \
    -F "pharmacy=Synthetic Takapuna" \
    -F "default_product_id=$product_id" \
    -F "csv_file=@$TMP/compact_layout.csv")
grep -q "Imported 2 records" <<< "$resp" || { echo "FAIL: layout B import" >&2; fail=1; }
grep -q "detected as grams" <<< "$resp" || { echo "FAIL: auto-detect did not flag gram quantities" >&2; fail=1; }
echo "  ok: layout B imported (auto-detected grams)"

# Gram conversion: quantities divided by 10, raw gram values preserved
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE raw_quantity IS NOT NULL;")" = "2" ] \
    && echo "  ok: raw gram values preserved" || { echo "FAIL: raw_quantity not stored" >&2; fail=1; }
[ "$(q "SELECT CAST(SUM(quantity) AS INTEGER) FROM s29_supplies WHERE pharmacy='Synthetic Takapuna';")" = "4" ] \
    && echo "  ok: gram quantities converted to units (10g+30g -> 4 units)" || { echo "FAIL: gram conversion" >&2; fail=1; }

# Name cleaning: trailing "- " export artifacts stripped from names
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE prescriber='Otherdoc, Dan';")" = "2" ] \
    && echo "  ok: trailing dash stripped from prescriber name" || { echo "FAIL: name cleaning" >&2; fail=1; }
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE prescriber LIKE '% -' OR patient LIKE '% -';")" = "0" ] \
    && echo "  ok: no name artifacts remain" || { echo "FAIL: name artifacts remain" >&2; fail=1; }

# Import layout C (.xlsx clinic export)
resp=$(curl -fsS -X POST "http://127.0.0.1:$PORT/s29.php" \
    -F "action=upload_s29" \
    -F "pharmacy=Synthetic Clinic Pharmacy" \
    -F "default_product_id=$product_id" \
    -F "csv_file=@$TMP/clinic_layout.xlsx;type=application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
grep -q "Imported 2 records" <<< "$resp" || { echo "FAIL: layout C (.xlsx) import" >&2; fail=1; }
echo "  ok: layout C (.xlsx) imported"

# Combined "Dr X (Clinic)" names split into prescriber + facility
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE prescriber='Dr Synthetic Doctor' AND prescriber_facility='Synthetic Clinic';")" = "2" ] \
    && echo "  ok: combined prescriber name split into name + facility" || { echo "FAIL: prescriber name splitting" >&2; fail=1; }

# Excel serial date converted (46096 = 2026-03-15) alongside the text date
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE supply_month='2026-03';")" = "2" ] \
    && echo "  ok: xlsx text + serial dates parsed to 2026-03" || { echo "FAIL: xlsx date parsing" >&2; fail=1; }

# Date parsing: 2-digit and 4-digit years both land in the right months
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE supply_month='2026-04';")" = "3" ] \
    && echo "  ok: April rows parsed (dd/mm/yy)" || { echo "FAIL: April month derivation" >&2; fail=1; }
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE supply_month='2026-05';")" = "2" ] \
    && echo "  ok: May rows parsed (d/mm/yyyy)" || { echo "FAIL: May month derivation" >&2; fail=1; }

# Product matching: all rows resolve to White Sherb (name match + default)
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE product_id=$product_id;")" = "7" ] \
    && echo "  ok: product matching (name match + default fallback)" || { echo "FAIL: product matching" >&2; fail=1; }

# Institution override: one row supplied to Synthetic Clinic, rest to the pharmacy
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE pharmacy='Synthetic Clinic';")" = "1" ] \
    && echo "  ok: per-row Institution override" || { echo "FAIL: Institution override" >&2; fail=1; }
[ "$(q "SELECT COUNT(*) FROM s29_supplies WHERE pharmacy='Synthetic Test Pharmacy';")" = "2" ] \
    && echo "  ok: pharmacy from upload form" || { echo "FAIL: pharmacy assignment" >&2; fail=1; }

# Confidential excess columns are discarded: NHI/DOB must not be stored anywhere
nhi_hits=$(q "SELECT COUNT(*) FROM s29_supplies WHERE prescriber LIKE '%ZZZ%' OR patient LIKE '%ZZZ%' OR med_name LIKE '%ZZZ%' OR pharmacy LIKE '%ZZZ%' OR prescriber_facility LIKE '%ZZZ%' OR med_plu LIKE '%ZZZ%';")
[ "$nhi_hits" = "0" ] && echo "  ok: junk/confidential columns discarded" || { echo "FAIL: NHI-like values leaked into stored fields" >&2; fail=1; }

# Quantities aggregate correctly (45 units + 4 converted units + 12 units)
[ "$(q "SELECT CAST(SUM(quantity) AS INTEGER) FROM s29_supplies;")" = "61" ] \
    && echo "  ok: quantities stored (total 61)" || { echo "FAIL: quantity total" >&2; fail=1; }

# Top Orders panel: renders for the all-months view, ranked by total quantity
# (top patient across the synthetic data is Carol with 20 units in one order)
page=$(curl -fsS "http://127.0.0.1:$PORT/s29.php?month=")
grep -q "Top Orders" <<< "$page" || { echo "FAIL: Top Orders panel missing" >&2; fail=1; }
first_patient=$(grep -o 'Testpatient, [A-Za-z]*' <<< "$page" | head -1)
[ "$first_patient" = "Testpatient, Carol" ] \
    && echo "  ok: Top Orders ranks by total quantity (Carol first)" \
    || { echo "FAIL: expected Testpatient, Carol ranked first, got '$first_patient'" >&2; fail=1; }
tabs_ok=1
grep -q 'id="top-prescribers"' <<< "$page" || { echo "FAIL: prescriber tab missing" >&2; fail=1; tabs_ok=0; }
grep -q 'id="top-places"' <<< "$page" || { echo "FAIL: places tab missing" >&2; fail=1; tabs_ok=0; }
[ "$tabs_ok" -eq 1 ] && echo "  ok: Top Orders tabs present"

# Delete import batch 1 — its 3 rows cascade away, other batches untouched
import1=$(q "SELECT MIN(id) FROM s29_imports;")
curl -fsS -X POST "http://127.0.0.1:$PORT/s29.php" \
    -F "action=delete_import" -F "import_id=$import1" >/dev/null
[ "$(q "SELECT COUNT(*) FROM s29_supplies;")" = "4" ] \
    && echo "  ok: import batch delete cascades" || { echo "FAIL: batch delete" >&2; fail=1; }

if [ "$fail" -ne 0 ]; then
    echo "S29 import test FAILED" >&2
    exit 1
fi
echo "S29 import test OK"
