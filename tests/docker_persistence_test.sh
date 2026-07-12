#!/usr/bin/env bash
# End-to-end persistence test: proves the database lives on the /data volume
# and survives the container being destroyed and recreated — which is exactly
# what happens when the Home Assistant add-on is updated or rebuilt.
#
#   1. Build the add-on image the same way the Supervisor does.
#   2. Run it with a host directory mounted at /data (as the Supervisor does).
#   3. Hit index.php so the app bootstraps its database.
#   4. Assert bud.db was created on the mounted volume and that NO SQLite
#      databases exist anywhere else in the container.
#   5. Write a canary row, destroy the container, start a brand-new one on
#      the same volume, and assert the canary survived.
#
# Requires: docker, curl. Override BUILD_FROM / PORT via environment if needed.
set -euo pipefail
cd "$(dirname "$0")/.."

# By default BUILD_FROM is NOT passed — exactly how the HA Supervisor builds
# the add-on. The Dockerfile's default base image must therefore be valid
# (0.14.0 shipped without one and every real install failed to build, while
# CI stayed green because it always supplied its own BUILD_FROM).
BUILD_FROM="${BUILD_FROM:-}"
IMAGE=bud-addon-test
NAME=bud-addon-test-run
PORT="${PORT:-8420}"
DATA_DIR=$(mktemp -d)

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    docker run --rm -v "$DATA_DIR":/data "$IMAGE" sh -c 'rm -rf /data/*' 2>/dev/null || true
    rm -rf "$DATA_DIR" 2>/dev/null || true
}
trap cleanup EXIT

if [ -n "$BUILD_FROM" ]; then
    echo "== Docker persistence test (base override: $BUILD_FROM) =="
    docker build --build-arg BUILD_FROM="$BUILD_FROM" -t "$IMAGE" bud_addon
else
    echo "== Docker persistence test (Supervisor-style build, Dockerfile default base) =="
    docker build -t "$IMAGE" bud_addon
fi

start_container() {
    docker run -d --name "$NAME" -v "$DATA_DIR":/data -p "$PORT":8420 "$IMAGE" >/dev/null
}

wait_ready() {
    for i in $(seq 1 30); do
        curl -fsS "http://127.0.0.1:$PORT/index.php" >/dev/null 2>&1 && return 0
        sleep 2
    done
    echo "Container never became ready; logs:" >&2
    docker logs "$NAME" >&2
    return 1
}

start_container
wait_ready

# 1. The database must have been created on the mounted /data volume
docker exec "$NAME" test -f /data/bud.db
echo "  ok: bud.db created on the /data volume"

# 2. No SQLite databases anywhere else in the container (ephemeral storage)
strays=$(docker exec "$NAME" sh -c \
    "find / \( -path /proc -o -path /sys -o -path /dev -o -path /data \) -prune \
     -o \( -name '*.db' -o -name '*.sqlite' -o -name '*.sqlite3' \) -type f -print 2>/dev/null" || true)
if [ -n "$strays" ]; then
    echo "FAIL: database file(s) found on ephemeral container storage:" >&2
    echo "$strays" >&2
    exit 1
fi
echo "  ok: no database files on ephemeral storage"

# 3. Write a canary row, then destroy the container entirely
docker exec "$NAME" sqlite3 /data/bud.db \
    "INSERT INTO suppliers (name, notes) VALUES ('persistence-canary', 'written by docker_persistence_test.sh');"
docker rm -f "$NAME" >/dev/null
echo "  ok: canary written, container destroyed"

# 4. Brand-new container on the same volume — simulates an add-on update
start_container
wait_ready
count=$(docker exec "$NAME" sqlite3 /data/bud.db \
    "SELECT COUNT(*) FROM suppliers WHERE name = 'persistence-canary';")
if [ "$count" != "1" ]; then
    echo "FAIL: canary row did not survive container recreation (count=$count)" >&2
    exit 1
fi
echo "  ok: data survived container destruction and recreation"

echo "Docker persistence test OK"
