#!/usr/bin/env bash
# Syntax-check every PHP file in the add-on with `php -l`.
# Requires: php-cli (any version >= 8.0; the add-on ships PHP 8.3).
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
while IFS= read -r -d '' file; do
    if ! php -l "$file" >/dev/null; then
        fail=1
    fi
done < <(find bud_addon/files -name '*.php' -print0)

if [ "$fail" -ne 0 ]; then
    echo "PHP lint FAILED" >&2
    exit 1
fi
echo "PHP lint OK"
