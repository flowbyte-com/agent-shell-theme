#!/usr/bin/env bash
#
# The categories a deployment must not carry.
#
# One list, two scripts.
#
# tools/package-release.sh excludes them from the archive it builds;
# tools/check-release-archive.sh refuses an archive that contains them.
# They used to keep two copies of these lists, which is precisely the
# arrangement that lets a category be excluded in one and forgotten in the
# other — the failure the release tooling claims cannot happen.
#
# Two kinds of rule, and the difference matters.
#
# A *root* rule names a directory of the repository itself: the working
# tree's own directories, which exist at the top level and nowhere else.
# It must not match a directory that merely has the same name further
# down — `agentshell-mcp/includes/tools/` is the plugin's tool classes,
# and excluding it would produce an archive with no tools at all.
#
# A *depth* rule names a category of file wherever it appears: a log, a
# SQL dump, a private key, a test directory.
#
# This file is sourced, not executed. It defines arrays and nothing else.

# Root rules: directories of the repository itself.
#
# The configurator is NOT one of them. It is a production feature: the theme
# enqueues it for administrators on every front-end page, so a distribution
# without it ships a configurator trigger whose assets 404 and a panel that
# cannot open. The size audit classified it as development material, which
# was right about its *origin* and wrong about its *destination*.
EXCLUDE_ROOT=(
    ".git"                 # git's own directory
    ".github"              # the CI workflow and its scripts
    ".worktrees"           # other checkouts
    "tests"                # the test suite and every fixture it carries
    "tools"                # the release tooling itself
    "skills"               # agent skill documents
    "dist"                 # previous archives
    "outputs"              # generated output
    "docs"                 # design records; the product does not need them
    "agentshell-mcp-daemon"  # the client-side daemon; it runs on the operator's machine
)

# Depth rules: categories of file wherever they appear.
EXCLUDE_ANY=(
    "tests"                # a test directory, at any depth
    "*.log"
    "*.sql"
    "*.tar.gz"
    "*.tgz"
    "*.zip"
    "*.key"
    "*.pem"
    "*.swp"
    "*~"
    ".DS_Store"
    "pi-session-*.html"
    "screenshot.png"
)
