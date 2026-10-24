#!/usr/bin/env bash
#
# PHP syntax check for every tracked PHP file.
#
# `git ls-files` is the source of the file list, so the check covers exactly
# what is in the commit — not whatever happens to be lying in the working
# directory (build artefacts, editor backups, a stray worktree).
#
# Exits non-zero on the first syntax error, and prints every error rather
# than stopping at the first, so one run reports every broken file.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

status=0
count=0

while IFS= read -r file; do
    [ -n "$file" ] || continue
    count=$((count + 1))
    if ! out=$(php -l "$file" 2>&1); then
        echo "SYNTAX ERROR: $file"
        echo "$out" | sed 's/^/    /'
        status=1
    fi
done < <(git ls-files -z '*.php' | tr '\0' '\n')

echo "php -l: $count files checked"
if [ "$status" -ne 0 ]; then
    echo "php -l: FAILED"
    exit 1
fi
echo "php -l: ok"
exit 0
