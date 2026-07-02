#!/usr/bin/env bash
# Smoke test: boot the portal with PHP's built-in web server against a
# throwaway database and check that every page renders without PHP errors.
#
# Also verifies the storage rules at runtime:
#   - the BUD_DB_PATH override is honoured (pages write to the test DB,
#     never to the in-repo fallback), and
#   - config.php refuses to start with ephemeral storage when it looks
#     like it is running inside the add-on but /data is unavailable.
#
# Requires: php-cli with pdo_sqlite, curl.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC="$PWD/bud_addon/files/general/www/public"
PORT="${SMOKE_PORT:-8099}"
TMP=$(mktemp -d)
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

export BUD_DB_PATH="$TMP/bud.db"

echo "== Smoke test (DB at $BUD_DB_PATH) =="

# Fresh DB from schema.sql (index.php would also do this on first run;
# seeding it here keeps the test independent of page order).
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

PAGES="index.php suppliers.php stock.php custody.php bundles.php receivers.php scheduling.php timesheet.php reports.php analytics.php admin.php"
fail=0
for page in $PAGES; do
    if ! body=$(curl -fsS "http://127.0.0.1:$PORT/$page"); then
        echo "FAIL: $page returned an HTTP error" >&2
        fail=1
        continue
    fi
    if echo "$body" | grep -qiE 'Fatal error|Parse error|Database connection failed|Refusing to start'; then
        echo "FAIL: $page rendered a PHP error" >&2
        fail=1
    else
        echo "  ok: $page"
    fi
done

# Storage rule 1: everything went to the test DB, nothing to the fallback.
if [ -f "$PUBLIC/database/bud_inventory.db" ]; then
    echo "FAIL: pages wrote to the in-repo fallback DB instead of BUD_DB_PATH" >&2
    rm -f "$PUBLIC/database/bud_inventory.db"
    fail=1
else
    echo "  ok: no writes to the fallback database"
fi

# Storage rule 2: inside the add-on (SUPERVISOR_TOKEN set), a missing DB
# directory must be fatal — never a silent fallback to ephemeral storage.
guard_out=$(BUD_DB_PATH=/nonexistent-bud-test/bud.db SUPERVISOR_TOKEN=smoke-test \
    php -r 'include $argv[1];' "$PUBLIC/config.php" 2>&1 || true)
if echo "$guard_out" | grep -q "Refusing to start with ephemeral storage"; then
    echo "  ok: ephemeral-storage guard triggers under the Supervisor"
else
    echo "FAIL: ephemeral-storage guard did not trigger; got: $guard_out" >&2
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    echo "Smoke test FAILED" >&2
    exit 1
fi
echo "Smoke test OK"
