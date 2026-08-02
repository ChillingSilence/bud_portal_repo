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

expected="suppliers stock_items audit_log destruction_log products s29_imports s29_supplies \
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

# Removed features must not come back on fresh installs
for table in time_logs cleaning_schedules cleaning_logs; do
    found=$(sqlite3 "$TMP/schema_test.db" \
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table';")
    if [ "$found" != "0" ]; then
        echo "FAIL: removed table '$table' is still created by schema.sql" >&2
        fail=1
    else
        echo "  ok: $table stays removed"
    fi
done

# Columns added over time must also exist on FRESH installs (schema.sql must
# always match what in-place migrations produce — see tests/upgrade_test.sh)
for col in "chain_of_custody:invoiced_at" "chain_of_custody:cancelled_at" "chain_of_custody:receiver_id" "chain_of_custody:received_by" "s29_supplies:raw_quantity"; do
    table="${col%%:*}"; column="${col##*:}"
    found=$(sqlite3 "$TMP/schema_test.db" \
        "SELECT COUNT(*) FROM pragma_table_info('$table') WHERE name='$column';")
    if [ "$found" != "1" ]; then
        echo "FAIL: column '$column' missing from '$table' on a fresh install" >&2
        fail=1
    else
        echo "  ok: $table.$column"
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
