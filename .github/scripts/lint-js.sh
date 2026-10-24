#!/usr/bin/env bash
#
# JavaScript syntax check for every tracked JavaScript file.
#
# `node --check` parses the file without executing it, so this catches a
# syntax error (a stray brace, an unterminated string) in the theme's
# runtime, the configurator, or the widget registry scripts — the files an
# agent's browser actually runs and that no PHP test would ever parse.
#
# The Node test suites are run separately; this script is only about
# "does it parse".

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

if ! command -v node >/dev/null 2>&1; then
    echo "node is not installed — cannot check JavaScript syntax" >&2
    exit 1
fi

status=0
count=0

while IFS= read -r file; do
    [ -n "$file" ] || continue
    count=$((count + 1))
    if ! out=$(node --check "$file" 2>&1); then
        echo "SYNTAX ERROR: $file"
        echo "$out" | sed 's/^/    /'
        status=1
    fi
done < <(git ls-files -z '*.js' | tr '\0' '\n')

echo "node --check: $count files checked"
if [ "$status" -ne 0 ]; then
    echo "node --check: FAILED"
    exit 1
fi
echo "node --check: ok"
exit 0
