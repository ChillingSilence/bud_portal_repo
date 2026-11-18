#!/usr/bin/env bash
# Version check (1.0.0): a release bumps the version in three places, and
# they have to agree. Home Assistant offers the update from config.yaml, the
# app bar shows BUD_VERSION (config.php), and the changelog's top heading
# says what's in it.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== Version check =="
yaml=$(sed -n 's/^version: *"\{0,1\}\([^"]*\)"\{0,1\} *$/\1/p' bud_addon/config.yaml)
app=$(sed -n "s/^define('BUD_VERSION', '\([^']*\)');/\1/p" bud_addon/files/general/www/public/config.php)
log=$(sed -n 's/^## \[\([^]]*\)\].*/\1/p' bud_addon/CHANGELOG.md | head -1)

echo "  config.yaml:  $yaml"
echo "  BUD_VERSION:  $app"
echo "  CHANGELOG.md: $log"
if [ -n "$yaml" ] && [ "$yaml" = "$app" ] && [ "$yaml" = "$log" ]; then
    echo "Version check OK"
else
    echo "Version check FAILED: bump all three together" >&2
    exit 1
fi
