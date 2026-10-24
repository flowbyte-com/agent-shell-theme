#!/usr/bin/env bash
#
# Build a deployment archive of the AgentShell theme and its plugins.
#
# Why this exists
# ---------------
# The repository is a *theme*, and a theme is a directory a web server
# serves. The repository also contains things that must never be served:
# the test suite, a profile that names a database and its password, the
# CI workflow, worktrees, git's own directory and the backup archives.
#
# The independent review of PR #16 found the test-bed profile readable
# over HTTP at its path inside the theme, with the password in cleartext.
# The profile is gitignored, which keeps it out of the *repository* — but
# a gitignored file is still a file on a disk that may be served, and a
# deployment that copies the working tree copies it too.
#
# So the deployment is a *build*: this script produces an archive from an
# explicit list of what ships, and `tools/check-release-archive.sh`
# asserts that the archive contains what it must and none of what it must
# not. The two are checked against each other in CI, so an exclusion that
# is forgotten fails the build rather than a deployment.
#
# Both web servers are covered by construction, not by configuration:
#
#   Apache    The archive carries no `tests/` directory and no profile, so
#             there is nothing for a `.htaccess` to have to protect. The
#             theme's own `.htaccess` rules are inside the archive and are
#             read by Apache where `AllowOverride` permits it.
#
#   nginx     nginx does not read `.htaccess` at all, so a rule that only
#             exists there protects nothing. The archive therefore relies
#             on *what it contains* rather than on rules a server may or
#             may not honour: the served tree is the theme, the two
#             plugins, and the theme's assets — and nothing else.
#
# Usage
# -----
#   tools/package-release.sh [--out DIR] [--ref REF] [--dry-run]
#
#   --out DIR    where to write the archive (default: ./dist)
#   --ref REF    the git revision to package (default: HEAD)
#   --dry-run    list what would be packaged, and write nothing

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

OUT_DIR="$REPO_ROOT/dist"
REF="HEAD"
DRY_RUN=0

while [ $# -gt 0 ]; do
    case "$1" in
        --out)    OUT_DIR="$2"; shift 2 ;;
        --ref)    REF="$2"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

say() { printf '[package] %s\n' "$*"; }
die() { printf '[package] FAILED: %s\n' "$*" >&2; exit 1; }

# ── what ships ─────────────────────────────────────────────────────────────
#
# Everything the site needs to run, and nothing else. Each entry is a path
# relative to the repository root; the list is the contract that
# tools/check-release-archive.sh re-checks against the built archive.
INCLUDE=(
    # The licence travels with the work. The GNU Affero General Public
    # License v3 requires that a copy of the licence accompanies every
    # copy of the work, and the release archive is a copy of the work.
    "LICENSE"
    "style.css"
    "functions.php"
    "index.php"
    "front-page.php"
    "singular.php"
    "header.php"
    "footer.php"
    "default-config.json"
    "template-parts"
    "widgets"
    "assets"
    "agentshell-mcp"
    "agentshell-blocks"
)

# ── what must never ship ──────────────────────────────────────────────────
#
# The categories live in one file, shared with the checker: see
# tools/release-exclusions.sh for why there is one list and not two.
#
# shellcheck source=tools/release-exclusions.sh
. "$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/release-exclusions.sh"

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "not a git checkout: $REPO_ROOT"

# The archive is built from the *tree* of a revision, not from the working
# directory: a deployment must be the code that was reviewed, not whatever
# happens to be on the disk.
REV="$(git rev-parse --verify "$REF" 2>/dev/null)" || die "cannot resolve the revision $REF"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/agentshell"
mkdir -p "$STAGE"

for entry in "${INCLUDE[@]}"; do
    if git cat-file -e "$REV:$entry" 2>/dev/null; then
        mkdir -p "$STAGE/$(dirname "$entry")"
        git archive "$REV" "$entry" | tar -x -C "$STAGE"
    else
        say "not in $REV, skipped: $entry"
    fi
done

# The exclusion pass. It runs over the staged tree rather than over the
# repository, so what is checked is what is about to be shipped.
while IFS= read -r -d '' path; do
    relative="${path#$STAGE/}"
    case "$relative" in
        "$STAGE"*) continue ;;
    esac
    # A root rule matches the repository's own directories only.
    for pattern in "${EXCLUDE_ROOT[@]}"; do
        case "$relative" in
            $pattern|$pattern/*)
                rm -rf "$path"
                continue 2
                ;;
        esac
    done
    # A depth rule matches a category wherever it appears.
    base="$(basename "$relative")"
    for pattern in "${EXCLUDE_ANY[@]}"; do
        case "$base" in
            $pattern)
                rm -rf "$path"
                continue 2
                ;;
        esac
    done
done < <(find "$STAGE" -mindepth 1 -print0)

# Nothing that looks like a credential may ship.
LEAKS=0
while IFS= read -r -d '' path; do
    relative="${path#$STAGE/}"
    case "$(basename "$path")" in
        test-bed.json|*.pem|*.key|id_rsa|id_ed25519|.env|.env.*)
            say "REFUSED: the archive would contain $relative"
            LEAKS=1
            ;;
    esac
done < <(find "$STAGE" -type f -print0)
[ "$LEAKS" -eq 0 ] || die "the archive contains a file that looks like a credential"

# The theme's PHP must still parse: a deployment that cannot boot is not a
# deployment.
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || die "syntax error in $file"
done < <(find "$STAGE" -name '*.php' -print0)

FILE_COUNT="$(find "$STAGE" -type f | wc -l | tr -d ' ')"
BYTES="$(du -sk "$STAGE" | cut -f1)"

if [ "$DRY_RUN" = "1" ]; then
    say "dry run: $FILE_COUNT files, ${BYTES} KiB, from $REV"
    find "$STAGE" -type f | sed "s|$STAGE/|  |" | sort | head -40
    say "… (a full listing is written by a real run)"
    exit 0
fi

mkdir -p "$OUT_DIR"
NAME="agentshell-$(git rev-parse --short "$REV")"
ARCHIVE="$OUT_DIR/$NAME.tar.gz"
tar -czf "$ARCHIVE" -C "$TMP" agentshell
chmod 0644 "$ARCHIVE"

say "built $ARCHIVE ($FILE_COUNT files, ${BYTES} KiB, from $REV)"
say "the archive's contents are checked by tools/check-release-archive.sh"
