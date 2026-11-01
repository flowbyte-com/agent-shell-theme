#!/usr/bin/env bash
#
# Install AgentShell's WordPress components into a site by symlink.
#
# The repository keeps the theme and the plugins under `wordpress/`; a site
# wants them where WordPress looks. This script makes that mapping without
# copying anything, so the site runs the checkout — edit a file, reload the
# page — and without ever writing a development file into the site.
#
# What it links
# -------------
#   wordpress/themes/agentshell/    -> <wp-content>/themes/agentshell
#   wordpress/plugins/agentshell-mcp    -> <wp-content>/plugins/agentshell-mcp
#   wordpress/plugins/agentshell-blocks -> <wp-content>/plugins/agentshell-blocks
#   wordpress/plugins/agentshell-forms  -> <wp-content>/plugins/agentshell-forms
#
# The forms plugin is linked but **not activated**: whether a site runs a form
# engine is the operator's decision, not the installer's.
#
# What it refuses to do
# ---------------------
#   * It never deletes anything — not a file, not a directory, not a link.
#   * It never overwrites a destination that is not already the link it would
#     create. An existing theme directory, an existing unrelated plugin, or a
#     link pointing somewhere else is reported as a conflict and left alone.
#   * It touches nothing outside the four destinations above. `wp-content`
#     itself, the database, uploads, other themes and other plugins are never
#     modified.
#   * It does not copy the repository, so no tests, docs, tooling or Git
#     metadata reach the site.
#
# Usage
# -----
#   tools/install-localwp.sh --wp-content "/path/to/site/app/public/wp-content"
#   tools/install-localwp.sh --wp-content "..." --dry-run
#
# Exit status: 0 when every component is linked or already correct,
#              1 when a conflict was found (nothing else is changed),
#              2 on a usage or validation error.

set -uo pipefail

REPO_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

THEME_SRC="$REPO_ROOT/wordpress/themes/agentshell"
PLUGIN_SRC="$REPO_ROOT/wordpress/plugins"
PLUGINS=( "agentshell-mcp" "agentshell-blocks" "agentshell-forms" )

WP_CONTENT=""
DRY_RUN=0

usage() {
    sed -n '2,45p' "$0" | sed 's/^# \{0,1\}//'
}

say()  { printf '%s\n' "$*"; }
note() { printf '  %s\n' "$*"; }
fail() { printf 'CONFLICT: %s\n' "$*" >&2; CONFLICTS=$((CONFLICTS + 1)); }
die()  { printf 'error: %s\n' "$*" >&2; exit 2; }

while [ $# -gt 0 ]; do
    case "$1" in
        --wp-content) WP_CONTENT="${2:-}"; shift 2 ;;
        --wp-content=*) WP_CONTENT="${1#*=}"; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown argument: $1 (try --help)" ;;
    esac
done

CONFLICTS=0

# ── validate the destination ───────────────────────────────────────────────
#
# The argument is a wp-content directory, and the checks below are what make
# "it is a WordPress site" a fact rather than an assumption. A wrong path is
# the one mistake that could put links in a random directory, so it is
# refused before anything is created.

[ -n "$WP_CONTENT" ] || die "--wp-content is required (try --help)"

if [ ! -d "$WP_CONTENT" ]; then
    die "not a directory: $WP_CONTENT"
fi

WP_CONTENT="$( cd "$WP_CONTENT" 2>/dev/null && pwd )" || die "cannot resolve $WP_CONTENT"

for required in themes plugins; do
    if [ ! -d "$WP_CONTENT/$required" ]; then
        die "$WP_CONTENT does not look like a wp-content directory (no $required/)"
    fi
done

# A wp-content that contains a wp-config.php two levels up is the normal
# layout; one that does not is reported, not refused, because some hosts
# split the directories.
if [ -f "$WP_CONTENT/../wp-config.php" ] || [ -f "$WP_CONTENT/../../wp-config.php" ]; then
    say "WordPress install:   $( cd "$WP_CONTENT/.." && pwd )"
else
    say "WordPress install:   (no wp-config.php found near $WP_CONTENT — continuing)"
fi
say "wp-content:          $WP_CONTENT"
say "checkout:            $REPO_ROOT"
if [ "$DRY_RUN" -eq 1 ]; then
    say "mode:                DRY RUN — nothing will be created"
fi
say ""

# ── validate the sources ───────────────────────────────────────────────────

[ -f "$THEME_SRC/style.css" ] || die "the theme is missing from the checkout: $THEME_SRC/style.css"

for plugin in "${PLUGINS[@]}"; do
    [ -f "$PLUGIN_SRC/$plugin/$plugin.php" ] || die "the plugin is missing from the checkout: $PLUGIN_SRC/$plugin/$plugin.php"
done

# ── link ───────────────────────────────────────────────────────────────────

LINKED=0
UNCHANGED=0

# link_one <source> <destination> <label>
link_one() {
    local source="$1" destination="$2" label="$3"

    if [ ! -e "$source" ]; then
        fail "$label: the source does not exist: $source"
        return
    fi

    if [ -L "$destination" ]; then
        local current
        current="$( readlink "$destination" )"
        if [ "$current" = "$source" ]; then
            note "$label: already linked to $source — unchanged"
            UNCHANGED=$((UNCHANGED + 1))
            return
        fi
        fail "$label: $destination is a link to $current, not to $source — left alone"
        return
    fi

    if [ -e "$destination" ]; then
        fail "$label: $destination already exists and is not a link — left alone (remove it yourself if it should be replaced)"
        return
    fi

    if [ "$DRY_RUN" -eq 1 ]; then
        note "$label: would link $destination -> $source"
        LINKED=$((LINKED + 1))
        return
    fi

    if ln -s "$source" "$destination" 2>/dev/null; then
        note "$label: linked $destination -> $source"
        LINKED=$((LINKED + 1))
    else
        fail "$label: could not create the link at $destination"
    fi
}

say "Theme"
link_one "$THEME_SRC" "$WP_CONTENT/themes/agentshell" "theme agentshell"

say ""
say "Plugins"
for plugin in "${PLUGINS[@]}"; do
    link_one "$PLUGIN_SRC/$plugin" "$WP_CONTENT/plugins/$plugin" "plugin $plugin"
done

# ── report ─────────────────────────────────────────────────────────────────

say ""
if [ "$CONFLICTS" -gt 0 ]; then
    say "$CONFLICTS conflict(s): nothing was overwritten, and nothing outside the list above was touched."
    say "Resolve each conflict, then run this script again — it is idempotent."
    exit 1
fi

if [ "$DRY_RUN" -eq 1 ]; then
    say "$LINKED link(s) would be created, $UNCHANGED already correct. Nothing was changed."
    exit 0
fi

say "$LINKED link(s) created, $UNCHANGED already correct."
say ""
say "Next:"
say "  1. Activate the theme (Appearance -> Themes -> AgentShell)."
say "  2. Activate agentshell-mcp and agentshell-blocks (Plugins)."
say "  3. Activate agentshell-forms only if this site should serve forms."
say ""
say "The plugins are linked but not activated: activation is your decision."
