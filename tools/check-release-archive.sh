#!/usr/bin/env bash
#
# Assert what a deployment contains, and what it must not.
#
# This is the other half of tools/package-release.sh: the packager decides
# what ships, and this script decides whether what was built is right. The
# two are checked against each other in CI, so a category that is excluded
# in one and forgotten in the other fails the build rather than a
# deployment.
#
# Two modes:
#
#   --archive FILE   check a built archive (.tar.gz)
#   --tree DIR       check a directory that is about to be served
#
# The tree mode is the one that matters for a theme served straight out of
# a checkout — which is exactly how the showcase runs, and exactly how the
# independent review of PR #16 found the test-bed profile readable over
# HTTP at its path inside the theme, with the password in cleartext.
#
# What must never be in a deployment:
#
#   * the test suite and every fixture it carries
#   * a profile that names a database and its password
#   * git's directory, other checkouts, the CI workflow
#   * the CI and release tooling itself
#   * anything that looks like a private key
#
# What must be in a deployment, or the site does not run:
#
#   * the theme's PHP and its stylesheet
#   * both plugins
#
# Usage
# -----
#   tools/check-release-archive.sh --archive dist/agentshell-abc123.tar.gz
#   tools/check-release-archive.sh --tree /var/www/wp-content/themes/agent-shell-theme

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

MODE=""
TARGET=""

while [ $# -gt 0 ]; do
    case "$1" in
        --archive) MODE="archive"; TARGET="$2"; shift 2 ;;
        --tree)    MODE="tree";    TARGET="$2"; shift 2 ;;
        -h|--help) sed -n '2,35p' "$0"; exit 0 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

[ -n "$MODE" ] || { echo "usage: $0 --archive FILE | --tree DIR" >&2; exit 2; }

FAILURES=0
say()  { printf '[check] %s\n' "$*"; }
fail() { printf '[check] FAILED: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }

# ── the forbidden set ──────────────────────────────────────────────────────
#
# The categories live in one file, shared with the packager: see
# tools/release-exclusions.sh for why there is one list and not two.
#
# shellcheck source=tools/release-exclusions.sh
. "$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/release-exclusions.sh"

# ── the required set ───────────────────────────────────────────────────────
REQUIRED=(
    # A deployment that does not carry the licence is not a compliant
    # distribution of an AGPL-licensed work.
    "LICENSE"
    "style.css"
    "functions.php"
    "header.php"
    "footer.php"
    "default-config.json"
    "template-parts/shell-render.php"
    "template-parts/widgets.php"
    "assets/theme.js"
    "widgets/.index.json"
    "widgets/hello-world.php"
    "agentshell-mcp/agentshell-mcp.php"
    "agentshell-mcp/includes/tools/class-inspect.php"
    "agentshell-blocks/agentshell-blocks.php"
    "agentshell-blocks/includes/tools/class-get-widget.php"
)

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
LIST="$WORK/paths.txt"

case "$MODE" in
    archive)
        [ -f "$TARGET" ] || { echo "[check] FAILED: no such archive: $TARGET" >&2; exit 1; }
        say "checking the archive $TARGET"
        tar -xzf "$TARGET" -C "$WORK"
        ROOT="$WORK/agentshell"
        [ -d "$ROOT" ] || { echo "[check] FAILED: the archive has no agentshell/ directory" >&2; exit 1; }
        ;;
    tree)
        [ -d "$TARGET" ] || { echo "[check] FAILED: no such directory: $TARGET" >&2; exit 1; }
        say "checking the tree $TARGET"
        ROOT="$TARGET"
        ;;
esac

# The prune is for *directories* only: a real .git directory would be
# enormous, but a *file* named .git is git internals too, and the rule
# that forbids git internals must see it.
( cd "$ROOT" && find . -mindepth 1 \( -type d \( -name '.git' -o -name '.worktrees' \) -prune \) -o -print ) \
    | sed 's|^\./||' | sort > "$LIST"

TOTAL="$(wc -l < "$LIST" | tr -d ' ')"
say "$TOTAL paths"

# ── forbidden ──────────────────────────────────────────────────────────────
while IFS= read -r path; do
    [ -n "$path" ] || continue
    for forbidden in "${EXCLUDE_ROOT[@]}"; do
        if [ "$path" = "$forbidden" ] || case "$path" in "$forbidden"/*) true ;; *) false ;; esac; then
            fail "the deployment contains $path (a $forbidden directory must not ship)"
            continue 2
        fi
    done
    base="$(basename "$path")"
    for pattern in "${EXCLUDE_ANY[@]}"; do
        case "$base" in
            $pattern)
                fail "the deployment contains $path (a $pattern must not ship)"
                continue 2
                ;;
        esac
    done
    case "$(basename "$path")" in
        test-bed.json|.env|id_rsa|id_ed25519)
            fail "the deployment contains $path (it looks like a credential)"
            continue
            ;;
    esac
done < "$LIST"

# ── required ───────────────────────────────────────────────────────────────
for required in "${REQUIRED[@]}"; do
    if ! grep -qxF "$required" "$LIST"; then
        fail "the deployment is missing $required"
    fi
done

# ── the profile, specifically ──────────────────────────────────────────────
#
# The single most important line in this file: the file the review found
# over HTTP, named and checked by name.
if grep -qxF "tests/config/test-bed.json" "$LIST" || grep -q "test-bed.json" "$LIST"; then
    fail "the deployment contains a test-bed profile"
fi

if [ "$FAILURES" -eq 0 ]; then
    say "the deployment is clean: nothing forbidden is present, nothing required is missing"
    exit 0
fi

say "$FAILURES problem(s) found"
exit 1
