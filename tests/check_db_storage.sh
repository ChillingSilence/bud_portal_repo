#!/usr/bin/env bash
# Static guard: the add-on database MUST live on persistent storage (/data).
#
# Under the Home Assistant Supervisor, /data is the ONLY directory that is
# persisted across container restarts, rebuilds and add-on updates. A database
# anywhere else inside the container is ephemeral and will be silently
# destroyed on the next update. These checks make sure nobody accidentally
# moves the database off /data.
#
# Requires: bash, grep, find (no PHP or Docker needed — safe to run anywhere).
set -uo pipefail
cd "$(dirname "$0")/.."

CONFIG=bud_addon/files/general/www/public/config.php
DOCKERFILE=bud_addon/Dockerfile
ADDON_CONFIG=bud_addon/config.yaml

fail=0
err() { echo "FAIL: $*" >&2; fail=1; }
ok()  { echo "  ok: $*"; }

echo "== Database persistent-storage checks =="

# 1. The default database path must be /data/bud.db
if grep -q "'/data/bud.db'" "$CONFIG"; then
    ok "default DB path is /data/bud.db"
else
    err "config.php no longer defaults the database to /data/bud.db"
fi

# 2. The ephemeral-storage guard must be present: when running inside the
#    add-on and /data is unavailable, the app must refuse to start rather
#    than silently write to storage that is wiped on update.
if grep -q "Refusing to start with ephemeral storage" "$CONFIG"; then
    ok "ephemeral-storage guard present in config.php"
else
    err "config.php is missing the ephemeral-storage guard (fallback could run inside the add-on)"
fi

# 3. No SQLite database files may be committed into the image contents
strays=$(find bud_addon/files \( -name '*.db' -o -name '*.sqlite' -o -name '*.sqlite3' \) -type f)
if [ -n "$strays" ]; then
    err "SQLite database file(s) committed into the image contents:"$'\n'"$strays"
else
    ok "no database files baked into the image"
fi

# 4. The Dockerfile must not copy database files into the image
if grep -Eiq 'COPY[^#]*\.(db|sqlite)' "$DOCKERFILE"; then
    err "Dockerfile copies a database file into the image"
else
    ok "Dockerfile does not copy database files"
fi

# 5. Nothing in config.yaml may map another folder over /data
#    (/data is mounted automatically by the Supervisor; mapping over it
#    would redirect the database to a different location).
if grep -Eq '^\s*path:\s*/data\s*$' "$ADDON_CONFIG"; then
    err "config.yaml maps a folder over /data"
else
    ok "config.yaml leaves /data to the Supervisor's persistent mount"
fi

if [ "$fail" -ne 0 ]; then
    echo "Database storage checks FAILED" >&2
    exit 1
fi
echo "Database storage checks OK"
