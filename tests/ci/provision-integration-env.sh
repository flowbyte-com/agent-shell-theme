#!/usr/bin/env bash
#
# Provision a disposable integration environment for the AgentShell test
# suites, and write the test-bed profile the suites read.
#
# What it creates
# ---------------
#   * a MySQL database and a *restricted* user whose only grant is that
#     database (the harness refuses to run when the user can reach anything
#     else — this is the check that keeps a CI run away from a developer's
#     own database);
#   * a WordPress installation in a scratch directory, with the AgentShell
#     theme and both plugins linked from the checkout under test;
#   * the `agentshell_site_mode = disposable_test_bed` sentinel, so the
#     harness can prove it is talking to a disposable site;
#   * an HTTP server for the site, and a hosts entry for it;
#   * a mysqldump baseline the runner can restore from;
#   * a test-bed profile (JSON) naming all of the above.
#
# Nothing here touches a LocalWP installation, a developer database, or any
# credential that is not generated during this run. Every secret it writes is
# generated at run time and lives only in the profile file it produces.
#
# Usage
# -----
#   tests/ci/provision-integration-env.sh [--config-out PATH] [--wp-dir PATH]
#
# Environment (all optional)
# --------------------------
#   CI_REPO             checkout root                     (default: git root)
#   CI_WP_DIR           WordPress root to create          (default: /tmp/agentshell-ci/wp)
#   CI_WP_VERSION       WordPress version to pin          (default: 6.8.2)
#   CI_DB_NAME          database name                     (default: agentshell_ci_bed)
#   CI_DB_USER          database user                     (default: agentshell_ci)
#   CI_DB_PASSWORD      database password                 (default: generated)
#   CI_MYSQL_SOCKET     mysqld socket                     (default: /var/run/mysqld/mysqld.sock)
#   CI_MYSQL_ROOT_CMD   how to reach MySQL as root        (default: autodetected)
#   CI_PHP_BIN          PHP binary to use                 (default: command -v php)
#   CI_SITE_HOST        site hostname                     (default: agentshell-test.local)
#   CI_SITE_PORT        port in the transport URL          (default: 80)
#   CI_HTTP_LISTEN_PORT port the HTTP server binds        (default: CI_SITE_PORT)
#   CI_WP_CLI           wp-cli entry point                (default: wp)
#   CI_SKIP_HTTP=1      provision everything except the HTTP server
#
# The site is served on port 80 by default and its WordPress `siteurl` is
# *portless* (`http://agentshell-test.local`). That is not a detail: the
# harness's siteurl gate refuses an observed siteurl that carries an explicit
# port, because a port there means the transport URL was copied into the
# configuration. Serving on a high port would force exactly that, so the
# site is served where a canonical URL is possible.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

CONFIG_OUT=""
WP_DIR=""
while [ $# -gt 0 ]; do
    case "$1" in
        --config-out) CONFIG_OUT="$2"; shift 2 ;;
        --wp-dir)     WP_DIR="$2";     shift 2 ;;
        -h|--help)    sed -n '2,50p' "$0"; exit 0 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

CI_REPO="${CI_REPO:-$REPO_ROOT}"
CI_WP_DIR="${CI_WP_DIR:-${WP_DIR:-/tmp/agentshell-ci/wp}}"
CI_WP_VERSION="${CI_WP_VERSION:-6.8.2}"
CI_DB_NAME="${CI_DB_NAME:-agentshell_ci_bed}"
CI_DB_USER="${CI_DB_USER:-agentshell_ci}"
CI_MYSQL_SOCKET="${CI_MYSQL_SOCKET:-/var/run/mysqld/mysqld.sock}"
CI_PHP_BIN="${CI_PHP_BIN:-$(command -v php || true)}"
CI_SITE_HOST="${CI_SITE_HOST:-agentshell-test.local}"
CI_SITE_PORT="${CI_SITE_PORT:-80}"
CI_HTTP_LISTEN_PORT="${CI_HTTP_LISTEN_PORT:-$CI_SITE_PORT}"
CI_SKIP_HTTP="${CI_SKIP_HTTP:-0}"
CI_WP_CLI="${CI_WP_CLI:-wp}"

# How WordPress itself reaches MySQL. PHP's default socket is the one in
# /var/run/mysqld on a Debian-family host; anywhere else the socket path has
# to travel with the host name, exactly as the local test bed spells it
# ("localhost:/path/to/mysqld.sock").
if [ -z "${CI_DB_HOST:-}" ]; then
    if [ "$CI_MYSQL_SOCKET" = "/var/run/mysqld/mysqld.sock" ]; then
        CI_DB_HOST="localhost"
    else
        CI_DB_HOST="localhost:$CI_MYSQL_SOCKET"
    fi
fi

if [ -z "$CONFIG_OUT" ]; then
    CONFIG_OUT="$CI_REPO/tests/config/test-bed-ci.json"
fi

say() { printf '\n[provision] %s\n' "$*"; }
die() { printf '\n[provision] FAILED: %s\n' "$*" >&2; exit 1; }

# ── 0. preconditions ───────────────────────────────────────────────────────
[ -n "$CI_PHP_BIN" ] && [ -x "$CI_PHP_BIN" ] || die "no PHP binary (set CI_PHP_BIN)"
command -v "$CI_WP_CLI" >/dev/null 2>&1 || die "wp-cli is not available ($CI_WP_CLI)"

# wp-cli must run on the *same* PHP as the test site: the CLI needs the
# mysqli extension to install WordPress, and the profile's `php.binary` is
# the PHP the child scripts run on. When the entry point is a phar it is
# executed through the configured PHP binary; otherwise it is run directly.
WP_ENTRY="$(command -v "$CI_WP_CLI")"
WP_SHEBANG="$(head -1 "$WP_ENTRY" 2>/dev/null || true)"
case "$WP_SHEBANG" in
    *php*) wp_cli() { "$CI_PHP_BIN" -d memory_limit=512M "$WP_ENTRY" "$@"; } ;;
    *)     wp_cli() { "$WP_ENTRY" "$@"; } ;;
esac

# The credentials are generated per *environment*, not per invocation: the
# script must be re-runnable against the same scratch directory, and a
# rotated password would leave wp-config.php pointing at a credential the
# MySQL user no longer has. The state file lives beside the WordPress
# directory, in the scratch area, and is never committed.
STATE_FILE="$(dirname "$CI_WP_DIR")/ci-credentials.env"
if [ -f "$STATE_FILE" ]; then
    # shellcheck disable=SC1090
    . "$STATE_FILE"
fi

# The restricted user needs a password. It is generated here and never
# committed: the profile file it lands in is gitignored, and CI writes it
# into a scratch directory.
if [ -z "${CI_DB_PASSWORD:-}" ]; then
    CI_DB_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
fi

# The MCP transport's static token. It is generated per environment, written
# into the disposable site's wp-config.php, and never committed or shared: it
# authenticates nothing but a database that this script created seconds
# earlier and that CI throws away at the end of the job.
if [ -z "${CI_REST_TOKEN:-}" ]; then
    CI_REST_TOKEN="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
fi

# How to reach MySQL as root. A socket connection is the only shape the
# harness supports (it requires `db.socket` to be a real socket), so the
# root command is built around the socket too.
if [ -z "${CI_MYSQL_ROOT_CMD:-}" ]; then
    if mysql --socket="$CI_MYSQL_SOCKET" -uroot -e "SELECT 1" >/dev/null 2>&1; then
        CI_MYSQL_ROOT_CMD="mysql --socket=$CI_MYSQL_SOCKET -uroot"
    elif sudo mysql --socket="$CI_MYSQL_SOCKET" -e "SELECT 1" >/dev/null 2>&1; then
        CI_MYSQL_ROOT_CMD="sudo mysql --socket=$CI_MYSQL_SOCKET"
    else
        die "cannot reach MySQL as root over $CI_MYSQL_SOCKET (set CI_MYSQL_ROOT_CMD)"
    fi
fi

say "repository:  $CI_REPO"
say "wordpress:   $CI_WP_DIR (WordPress $CI_WP_VERSION)"
say "database:    $CI_DB_NAME (user $CI_DB_USER, restricted to that database)"
say "site:        http://$CI_SITE_HOST (transport port $CI_SITE_PORT, server binds $CI_HTTP_LISTEN_PORT)"

# ── 1. database ────────────────────────────────────────────────────────────
say "creating the disposable database and its restricted user"
# The user is granted *only* the test database. The harness's grants gate
# refuses to run when the configured user can reach anything else, so this
# is the mechanism that makes "CI never touches a LocalWP database" a
# property of the environment rather than a promise.
$CI_MYSQL_ROOT_CMD <<SQL
CREATE DATABASE IF NOT EXISTS \`$CI_DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$CI_DB_USER'@'localhost' IDENTIFIED BY '$CI_DB_PASSWORD';
ALTER USER '$CI_DB_USER'@'localhost' IDENTIFIED BY '$CI_DB_PASSWORD';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$CI_DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$CI_DB_NAME\`.* TO '$CI_DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

mkdir -p "$(dirname "$CI_WP_DIR")"
umask 077
cat > "$STATE_FILE" <<STATE
CI_DB_PASSWORD='$CI_DB_PASSWORD'
CI_REST_TOKEN='$CI_REST_TOKEN'
STATE

# ── 2. WordPress ───────────────────────────────────────────────────────────
mkdir -p "$CI_WP_DIR"
if [ ! -f "$CI_WP_DIR/wp-load.php" ]; then
    say "downloading WordPress $CI_WP_VERSION"
    wp_cli core download --path="$CI_WP_DIR" --version="$CI_WP_VERSION" --allow-root --force
else
    say "WordPress core already present at $CI_WP_DIR"
fi

if [ ! -f "$CI_WP_DIR/wp-config.php" ]; then
    say "writing wp-config.php"
    wp_cli config create \
        --path="$CI_WP_DIR" \
        --dbname="$CI_DB_NAME" \
        --dbuser="$CI_DB_USER" \
        --dbpass="$CI_DB_PASSWORD" \
        --dbhost="$CI_DB_HOST" \
        --skip-check \
        --allow-root \
        --extra-php <<PHP
// AgentShell integration test environment.
// The static token is generated per run by the provisioning script and
// never committed; it binds the MCP transport to an explicit actor.
define( 'AGENTSHELL_REST_TOKEN', '$CI_REST_TOKEN' );
define( 'AGENTSHELL_REST_TOKEN_USER_ID', 1 );
PHP
fi

if ! wp_cli core is-installed --path="$CI_WP_DIR" --allow-root >/dev/null 2>&1; then
    say "installing WordPress"
    wp_cli core install \
        --path="$CI_WP_DIR" \
        --url="http://$CI_SITE_HOST" \
        --title="AgentShell integration bed" \
        --admin_user="agentshell_ci_admin" \
        --admin_password="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 18)" \
        --admin_email="ci@example.invalid" \
        --skip-email \
        --allow-root
fi

# ── 3. the AgentShell theme and plugins ────────────────────────────────────
say "linking the theme and plugins from the checkout under test"
ln -sfn "$CI_REPO" "$CI_WP_DIR/wp-content/themes/agent-shell-theme"
ln -sfn "$CI_REPO/agentshell-mcp"   "$CI_WP_DIR/wp-content/plugins/agentshell-mcp"
ln -sfn "$CI_REPO/agentshell-blocks" "$CI_WP_DIR/wp-content/plugins/agentshell-blocks"

wp_cli plugin activate agentshell-mcp agentshell-blocks --path="$CI_WP_DIR" --allow-root >/dev/null
wp_cli theme activate agent-shell-theme --path="$CI_WP_DIR" --allow-root >/dev/null

# Pretty permalinks are not cosmetic here: the harness's HTTP identity
# handshake fetches /wp-json/agentshell-test/v1/identity, and WordPress only
# serves that path when the permalink structure is pretty. With the default
# plain structure the same endpoint is only reachable as
# /?rest_route=/agentshell-test/v1/identity.
wp_cli rewrite structure '/%postname%/' --hard --path="$CI_WP_DIR" --allow-root >/dev/null 2>&1 \
    || wp_cli option update permalink_structure '/%postname%/' --path="$CI_WP_DIR" --allow-root >/dev/null
wp_cli rewrite flush --hard --path="$CI_WP_DIR" --allow-root >/dev/null 2>&1 || true

# ── 4. the sentinel ────────────────────────────────────────────────────────
# This row is what proves, to the harness, that the database belongs to a
# disposable site rather than to somebody's real one.
wp_cli option update agentshell_site_mode disposable_test_bed --path="$CI_WP_DIR" --allow-root >/dev/null

# ── 5. the HTTP server ─────────────────────────────────────────────────────
if [ "$CI_SKIP_HTTP" != "1" ]; then
    say "resolving $CI_SITE_HOST"
    if ! grep -q "[[:space:]]$CI_SITE_HOST" /etc/hosts 2>/dev/null; then
        printf '127.0.0.1 %s\n' "$CI_SITE_HOST" | sudo tee -a /etc/hosts >/dev/null
    fi

    say "starting the HTTP server on port $CI_HTTP_LISTEN_PORT"
    ROUTER="$CI_REPO/tests/ci/router.php"
    export AGENTSHELL_CI_WP_ROOT="$CI_WP_DIR"
    export PHP_CLI_SERVER_WORKERS=4
    if [ "$CI_HTTP_LISTEN_PORT" = "80" ]; then
        sudo -E env AGENTSHELL_CI_WP_ROOT="$CI_WP_DIR" PHP_CLI_SERVER_WORKERS=4 \
            nohup "$CI_PHP_BIN" -S "0.0.0.0:$CI_HTTP_LISTEN_PORT" -t "$CI_WP_DIR" "$ROUTER" \
            >/tmp/agentshell-ci-http.log 2>&1 &
    else
        nohup env AGENTSHELL_CI_WP_ROOT="$CI_WP_DIR" PHP_CLI_SERVER_WORKERS=4 \
            "$CI_PHP_BIN" -S "0.0.0.0:$CI_HTTP_LISTEN_PORT" -t "$CI_WP_DIR" "$ROUTER" \
            >/tmp/agentshell-ci-http.log 2>&1 &
    fi
    sleep 2
    # The health check uses the configured PHP rather than curl: the PHP
    # binary is already pinned for this environment, and one less external
    # tool is one less thing that can differ between the CI runner and a
    # workstation.
    if ! "$CI_PHP_BIN" -r '
        $ctx = stream_context_create( array( "http" => array( "timeout" => 15, "ignore_errors" => true ) ) );
        $body = @file_get_contents( $argv[1], false, $ctx );
        exit( ( false === $body ) ? 1 : 0 );
    ' "http://$CI_SITE_HOST:$CI_SITE_PORT/"; then
        echo "--- http server log ---" >&2
        tail -20 /tmp/agentshell-ci-http.log >&2 || true
        die "the HTTP server did not answer on http://$CI_SITE_HOST:$CI_SITE_PORT/"
    fi
fi

# ── 6. the baseline snapshot ───────────────────────────────────────────────
# The snapshot is taken with the *restricted* user: it holds every
# privilege on this one database, so it can dump it, and using it here
# keeps the root account out of the picture entirely.
say "writing the baseline snapshot"
BASELINE="${CI_BASELINE_PATH:-$CI_WP_DIR/../test-bed-baseline.sql}"
mkdir -p "$(dirname "$BASELINE")"
if ! mysqldump --socket="$CI_MYSQL_SOCKET" -u"$CI_DB_USER" -p"$CI_DB_PASSWORD" \
        --single-transaction --skip-lock-tables --no-tablespaces \
        "$CI_DB_NAME" > "$BASELINE" 2>/tmp/agentshell-ci-dump.err; then
    echo "--- mysqldump stderr ---" >&2
    cat /tmp/agentshell-ci-dump.err >&2 || true
    die "could not write the baseline snapshot"
fi

# ── 7. the profile ─────────────────────────────────────────────────────────
say "writing the test-bed profile: $CONFIG_OUT"
SERVER_UUID="$($CI_MYSQL_ROOT_CMD -N -B -e 'SELECT @@server_uuid' 2>/dev/null | tr -d '\r')"
DATADIR="$($CI_MYSQL_ROOT_CMD -N -B -e 'SELECT @@datadir' 2>/dev/null | tr -d '\r')"
DB_PORT="$($CI_MYSQL_ROOT_CMD -N -B -e 'SELECT @@port' 2>/dev/null | tr -d '\r')"

[ -n "$SERVER_UUID" ] || die "could not read @@server_uuid"
[ -n "$DATADIR" ]     || die "could not read @@datadir"

# The transport URL is the canonical spelling of the site: portless on 80,
# with the port otherwise. The harness's siteurl gate refuses a *siteurl*
# that carries an explicit port, and the transport URL is what that gate
# compares against, so the two are spelled the same way.
if [ "$CI_SITE_PORT" = "80" ]; then
    CI_TRANSPORT_URL="http://$CI_SITE_HOST"
else
    CI_TRANSPORT_URL="http://$CI_SITE_HOST:$CI_SITE_PORT"
fi

mkdir -p "$(dirname "$CONFIG_OUT")"
cat > "$CONFIG_OUT" <<JSON
{
    "kind": "agentshell-test-bed/v1",
    "label": "ci-disposable-integration-bed",

    "wp_root": "$CI_WP_DIR",
    "domain":  "$CI_SITE_HOST",
    "url_http":  "$CI_TRANSPORT_URL",

    "db": {
        "host":   "localhost",
        "name":   "$CI_DB_NAME",
        "user":   "$CI_DB_USER",
        "password": "$CI_DB_PASSWORD",
        "socket": "$CI_MYSQL_SOCKET",
        "port":   0
    },

    "php": {
        "binary": "$CI_PHP_BIN"
    },

    "auth": {
        "admin_user": "agentshell_ci_admin"
    },

    "expect": {
        "site_mode_sentinel": "disposable_test_bed",
        "server_uuid":        "$SERVER_UUID",
        "expected_datadir":   "${DATADIR%/}",
        "expected_port":      $DB_PORT,
        "wp_table_prefix": "wp_",
        "db_user_grants_only": "$CI_DB_NAME",
        "baseline_snapshot_path": "$BASELINE"
    },

    "fixture": {
        "post_title_prefix":  "ASTB",
        "post_meta_prefix":   "_astb_",
        "option_prefix":      "astb_",
        "menu_prefix":        "ASTB ",
        "post_status_for_created": "draft"
    }
}
JSON

say "provisioning complete"
echo "$CONFIG_OUT"
