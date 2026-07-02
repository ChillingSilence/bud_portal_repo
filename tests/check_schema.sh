#!/usr/bin/env bash
# Validate that schema.sql loads cleanly into a fresh SQLite database and
# creates every table the application expects.
# Requires: sqlite3 CLI.
set -euo pipefail
cd "$(dirname "$0")/.."

SCHEMA=bud_addon/files/general/www/public/database/schema.sql
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "== Schema validation =="

sqlite3 "$TMP/schema_test.db" < "$SCHEMA"

expected="suppliers stock_items audit_log time_logs cleaning_schedules cleaning_logs \
chain_of_custody materials_out_reports product_bundles bundle_items verified_receivers"

fail=0
for table in $expected; do
    found=$(sqlite3 "$TMP/schema_test.db" \
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table';")
    if [ "$found" != "1" ]; then
        echo "FAIL: table '$table' missing after loading schema.sql" >&2
        fail=1
    else
        echo "  ok: $table"
    fi
done

# Foreign keys must resolve (catches typos in REFERENCES clauses)
fk_errors=$(sqlite3 "$TMP/schema_test.db" "PRAGMA foreign_key_check;")
if [ -n "$fk_errors" ]; then
    echo "FAIL: foreign key check reported errors:" >&2
    echo "$fk_errors" >&2
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    echo "Schema validation FAILED" >&2
    exit 1
fi
echo "Schema validation OK"
