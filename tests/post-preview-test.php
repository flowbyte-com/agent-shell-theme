<?php
/**
 * Secure draft-capture regressions.
 *
 * The gap this fills: `agentshell_screenshot` drives headless Chrome at a
 * front-end URL, and Chrome carries no credentials, so an unpublished post was
 * unreachable. The implemented answer is a request-scoped bearer that restores
 * the *MCP caller's own* WordPress identity for one request and then defers to
 * core's existing per-post preview check.
 *
 * That answer is safe only because of four independent gates, and a gate that
 * is not independently exercised is a gate that does not exist. The gates:
 *
 *   1. mint      — the caller is authorised against this specific post
 *   2. signature — HMAC over actor + post + expiry, short-lived, fails closed
 *   3. route     — front-end GET only; never REST, admin, XML-RPC, login, cron
 *   4. binding   — the request must actually have resolved to the bound post,
 *                  checked after the main query and before any output
 *
 * plus core's own capability check, which this suite must never bypass — the
 * tests assert that an actor who cannot edit a post still gets nothing, even
 * holding a perfectly valid credential for a post they can.
 *
 * Most assertions below are about a *refusal*. Two of the vulnerabilities these
 * tests pin were found by negative testing during implementation, not by
 * reading the code: a cross-post render leak (`set_404()` raises a flag and
 * leaves the resolved post in place) and credential propagation through
 * `redirect_canonical`'s Location header. Both are asserted directly.
 *
 * Nothing here touches the live site or the network.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'post-preview-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    $d = sys_get_temp_dir() . '/agentshell-postpreview-wpcontent-' . getmypid();
    @mkdir( $d, 0700, true );
    define( 'WP_CONTENT_DIR', $d );
}
if ( ! defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
    define( 'AGENTSHELL_SCREENSHOT_DIR', sys_get_temp_dir() . '/agentshell-postpreview-store-' . getmypid() );
}

$GLOBALS['_agentshell_test_login'] = 'agent';

// Stubs the suite needs that earlier suites in the same process do not define.
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $url ) { return $url; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url ) {
        $sep = str_contains( $url, '?' ) ? '&' : '?';
        return $url . $sep . http_build_query( $args );
    }
}
if ( ! function_exists( 'nocache_headers' ) ) {
    // Mirrors WordPress: the headers that make a response uncacheable by
    // intermediaries as well as by the browser.
    function nocache_headers() {
        $GLOBALS['_agentshell_test_captured_headers'][] = 'Cache-Control: no-cache, must-revalidate, max-age=0';
        $GLOBALS['_agentshell_test_captured_headers'][] = 'Expires: Wed, 11 Jan 1984 05:00:00 GMT';
        $GLOBALS['_agentshell_test_captured_headers'][] = 'X-Robots-Tag: noindex, nofollow, noarchive';
    }
}

// ── Route gate environment ──────────────────────────────────────────────────
// REQUEST_METHOD / REQUEST_URI are read straight from $_SERVER by
// agentshell_post_preview_route_allowed(); is_admin() and rest_get_url_prefix()
// are the other two inputs. All four are per-request state, so each test sets
// them and agentshell_test_reset() puts them back.

$GLOBALS['_agentshell_test_request'] = array( 'method' => 'GET', 'uri' => '/', 'admin' => false );

function is_admin() { return (bool) $GLOBALS['_agentshell_test_request']['admin']; }
function rest_get_url_prefix() { return 'wp-json'; }

function status_header( $code, $description = '' ) {
    $GLOBALS['_agentshell_test_status'] = (int) $code;
}
function wp_reset_postdata() { $GLOBALS['_agentshell_test_post_reset'] = true; }

// ── Post and user registries ────────────────────────────────────────────────
// Post_Preview::authorize() reads post_status through get_post();
// authenticate() requires get_userdata() to still resolve the actor, so a
// deleted account is representable.

$GLOBALS['_agentshell_test_posts'] = array();
$GLOBALS['_agentshell_test_users'] = array();

function agentshell_test_add_post( $id, $status ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = (object) array(
        'ID'          => (int) $id,
        'post_status' => $status,
        'post_type'   => 'page',
        'post_title'  => "Post {$id}",
    );
}
function agentshell_test_add_user( $id ) {
    $GLOBALS['_agentshell_test_users'][ (int) $id ] = (object) array(
        'ID'           => (int) $id,
        'user_login'   => "user{$id}",
    );
}
function get_post( $id = null ) {
    if ( null === $id ) { return $GLOBALS['post'] ?? null; }
    return $GLOBALS['_agentshell_test_posts'][ (int) $id ] ?? null;
}
function get_userdata( $id ) {
    return $GLOBALS['_agentshell_test_users'][ (int) $id ] ?? false;
}

// ── Query surface the binding gate reads ─────────────────────────────────────
// A fake WP_Query whose resolved object the test controls, so "the credential
// was valid but the request resolved somewhere else" is expressible.

class Agentshell_Test_Query {
    public $posts = array();
    public $post = null;
    public $post_count = 0;
    public $found_posts = 0;
    public $is_singular = true;
    public $is_page = true;
    public $is_single = false;
    public $is_404 = false;
    public function set_404() { $this->is_404 = true; }
}

$GLOBALS['_agentshell_test_query'] = new Agentshell_Test_Query();

function get_queried_object_id() {
    $q = $GLOBALS['_agentshell_test_query'];
    return $q->post ? (int) $q->post->ID : 0;
}
function is_singular() { return (bool) $GLOBALS['_agentshell_test_query']->is_singular; }

/**
 * Put the fake main query into the state it would hold after wp() resolved a
 * request to $resolved_id (or to nothing, for an archive / 404).
 */
function agentshell_test_resolve_query( $resolved_id ) {
    $q = new Agentshell_Test_Query();
    if ( null !== $resolved_id ) {
        $post = get_post( $resolved_id );
        if ( $post ) {
            $q->post = $post;
            $q->posts = array( $post );
            $q->post_count = 1;
            $q->found_posts = 1;
        }
    }
    $GLOBALS['_agentshell_test_query'] = $q;
    return $q;
}

require_once dirname( __DIR__ ) . '/functions.php';
foreach ( array(
    'class-store.php', 'class-transactions.php', 'class-post-preview.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Post_Preview;

/**
 * Per-test fixture reset.
 *
 * $_GET and the request environment are real superglobals the gates read, so
 * they are restored explicitly — a leaked credential from one test would
 * otherwise authorise the next.
 */
function agentshell_post_preview_reset() {
    agentshell_test_reset();
    $_GET = array();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/';
    $GLOBALS['_agentshell_test_request'] = array( 'method' => 'GET', 'uri' => '/', 'admin' => false );
    $GLOBALS['_agentshell_test_posts'] = array();
    $GLOBALS['_agentshell_test_users'] = array();
    $GLOBALS['_agentshell_test_status'] = null;
    $GLOBALS['_agentshell_test_post_reset'] = false;
    $GLOBALS['agentshell_post_preview'] = null;
    $GLOBALS['agentshell_post_preview_refused'] = null;
    $GLOBALS['post'] = null;
    unset( $GLOBALS['wp_query'] );
    agentshell_test_resolve_query( null );

    // One owner of two drafts they may edit, one draft they may not, plus a
    // private post they may read and a published one.
    agentshell_test_add_post( 10, 'draft' );
    agentshell_test_add_post( 11, 'private' );
    agentshell_test_add_post( 12, 'pending' );
    agentshell_test_add_post( 13, 'publish' );
    agentshell_test_add_user( 1 );
    agentshell_test_add_user( 2 );
}

/** The actor used by most tests: may edit 10 and 12, read 11, nothing else. */
function agentshell_post_preview_owner() {
    agentshell_test_set_user( 1, array( 'edit_post' => array( 10, 12 ), 'read_post' => array( 11 ) ) );
}

/**
 * Write arguments into $_GET, as a minted URL would.
 */
function agentshell_post_preview_set_args( array $args ) {
    foreach ( $args as $k => $v ) {
        $_GET[ $k ] = $v;
    }
}

/**
 * Put a valid credential into $_GET, as a minted URL would.
 *
 * Each call mints a distinct expiry on purpose.
 * agentshell_authorize_post_preview() memoises its verdict per credential —
 * correctly, and matching the transaction preview beside it — so reusing one
 * credential across tests would let the first verdict answer every later test
 * and silently disable the gates under test. Distinct expiries mean distinct
 * fingerprints, so each test is decided on its own merits.
 */
function agentshell_post_preview_put_valid_credential( $post_id = 10, $user_id = 1 ) {
    $exp = agentshell_post_preview_unique_exp();
    agentshell_post_preview_set_args( array(
        Post_Preview::POST_ARG => (string) $post_id,
        Post_Preview::USER_ARG => (string) $user_id,
        Post_Preview::EXP_ARG  => (string) $exp,
        Post_Preview::KEY_ARG  => agentshell_post_preview_sign( $post_id, $user_id, $exp ),
    ) );
    return $exp;
}

/** A fresh, always-future expiry. Unique per call, so fingerprints differ. */
function agentshell_post_preview_unique_exp() {
    static $n = 0;
    return time() + Post_Preview::TTL_SECONDS + ( ++$n );
}

/** Capture the status code last passed to status_header(). */
function agentshell_post_preview_status() {
    return $GLOBALS['_agentshell_test_status'];
}

function agentshell_post_preview_headers() {
    return implode( "\n", $GLOBALS['_agentshell_test_captured_headers'] );
}

// ═════════════════════════════════════════════════════════════════════════════
// Gate 1 — minting. Who may obtain a credential, and for what.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'authorize: owner of a draft is authorised', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    Agentshell_Test::assert_equals( 'ok', Post_Preview::authorize( 10 ) );
} );

Agentshell_Test::run( 'authorize: anonymous caller is refused', function () {
    agentshell_post_preview_reset();
    agentshell_test_set_user( 0, array() );
    Agentshell_Test::assert_equals( 'no-user', Post_Preview::authorize( 10 ) );
} );

Agentshell_Test::run( 'authorize: a user without the post\'s capability is refused', function () {
    agentshell_post_preview_reset();
    // Actor 2 exists but may edit nothing.
    agentshell_test_set_user( 2, array() );
    Agentshell_Test::assert_equals( 'forbidden', Post_Preview::authorize( 10 ) );
} );

Agentshell_Test::run( 'authorize: a missing post is refused', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    Agentshell_Test::assert_equals( 'no-post', Post_Preview::authorize( 9999 ) );
} );

Agentshell_Test::run( 'authorize: an already-published post needs no credential', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    Agentshell_Test::assert_equals( 'published', Post_Preview::authorize( 13 ) );
    // And asking for one throws rather than silently minting.
    $threw = false;
    try { Post_Preview::preview_args( 13 ); } catch ( \InvalidArgumentException $e ) { $threw = true; }
    Agentshell_Test::assert_true( $threw, 'preview_args() must throw for a published post' );
} );

Agentshell_Test::run( 'authorize: private posts use read_post, protected ones edit_post', function () {
    agentshell_post_preview_reset();
    // Read-only on 11 (private), no edit rights anywhere.
    agentshell_test_set_user( 2, array( 'read_post' => array( 11 ) ) );
    Agentshell_Test::assert_equals( 'ok', Post_Preview::authorize( 11 ), 'private needs read_post' );
    Agentshell_Test::assert_equals( 'forbidden', Post_Preview::authorize( 10 ), 'draft needs edit_post' );
} );

Agentshell_Test::run( 'authorize: pending and future are covered', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    Agentshell_Test::assert_equals( 'ok', Post_Preview::authorize( 12 ) );
    foreach ( Post_Preview::UNPUBLISHED_STATUSES as $status ) {
        Agentshell_Test::assert_true(
            in_array( $status, array( 'draft', 'pending', 'private', 'future' ), true ),
            "unexpected status {$status}"
        );
    }
} );

Agentshell_Test::run( 'mint: an authorised caller gets a signed, expiring credential', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $args = Post_Preview::preview_args( 10 );
    Agentshell_Test::assert_equals( '10', $args[ Post_Preview::POST_ARG ] );
    Agentshell_Test::assert_equals( '1', $args[ Post_Preview::USER_ARG ] );
    Agentshell_Test::assert_equals( time() + Post_Preview::TTL_SECONDS, (int) $args[ Post_Preview::EXP_ARG ] );
    Agentshell_Test::assert_equals( 64, strlen( $args[ Post_Preview::KEY_ARG ] ), 'HMAC-SHA256 hex is 64 chars' );
    Agentshell_Test::assert_equals( 1, Post_Preview::authenticate(
        $args[ Post_Preview::POST_ARG ], $args[ Post_Preview::USER_ARG ],
        $args[ Post_Preview::KEY_ARG ], $args[ Post_Preview::EXP_ARG ]
    ) );
} );

Agentshell_Test::run( 'mint: an unauthorised caller gets an explanatory throw', function () {
    agentshell_post_preview_reset();
    agentshell_test_set_user( 2, array() );
    $message = '';
    try { Post_Preview::preview_args( 10 ); } catch ( \InvalidArgumentException $e ) { $message = $e->getMessage(); }
    Agentshell_Test::assert_true( '' !== $message, 'must throw' );
    Agentshell_Test::assert_true(
        str_contains( $message, 'permission' ),
        "message should name the reason, got: {$message}"
    );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Gate 2 — the signature. Everything that must fail closed.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'authenticate: a valid credential resolves to its actor', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $a = Post_Preview::preview_args( 10 );
    Agentshell_Test::assert_equals( 1, Post_Preview::authenticate( '10', '1', $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ] ) );
} );

Agentshell_Test::run( 'authenticate: a tampered post id fails closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $a = Post_Preview::preview_args( 10 );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate(
        '11', '1', $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ]
    ), 'a credential for post 10 must not authenticate post 11' );
} );

Agentshell_Test::run( 'authenticate: a tampered actor id fails closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $a = Post_Preview::preview_args( 10 );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate(
        '10', '2', $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ]
    ), 'must not be replayable as another user' );
} );

Agentshell_Test::run( 'authenticate: an extended expiry fails closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $a = Post_Preview::preview_args( 10 );
    $stretched = (string) ( time() + 86400 );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', '1', $a[ Post_Preview::KEY_ARG ], $stretched ) );
} );

Agentshell_Test::run( 'authenticate: an expired credential fails closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    // Signed correctly for the same actor and post, but minted in the past.
    $exp   = time() - 1;
    $key   = agentshell_post_preview_sign( 10, 1, $exp );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', '1', $key, (string) $exp ) );
} );

Agentshell_Test::run( 'authenticate: expiry 0 and non-numeric expiry both fail closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    // A key signed over these exact values, to prove the refusal is the
    // expiry check and not a signature mismatch in disguise.
    foreach ( array( 0, 'not-a-number', -5 ) as $exp ) {
        $key = agentshell_post_preview_sign( 10, 1, (int) $exp );
        Agentshell_Test::assert_equals(
            0,
            Post_Preview::authenticate( '10', '1', $key, (string) $exp ),
            "expiry '{$exp}' must be refused"
        );
    }
} );

Agentshell_Test::run( 'authenticate: an empty or absent key fails closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $exp = (string) ( time() + 60 );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', '1', '', $exp ) );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', '1', null, $exp ) );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', '1', array(), $exp ) );
} );

Agentshell_Test::run( 'authenticate: non-string post/user ids fail closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $a = Post_Preview::preview_args( 10 );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '', '1', $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ] ) );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', '', $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ] ) );
    // Array arguments must not reach hash_hmac() as a string.
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( array( '10' ), '1', $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ] ) );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', array( '1' ), $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ] ) );
} );

Agentshell_Test::run( 'authenticate: a credential naming a deleted user fails closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $a = Post_Preview::preview_args( 10 );
    unset( $GLOBALS['_agentshell_test_users'][1] );
    Agentshell_Test::assert_equals(
        0,
        Post_Preview::authenticate( '10', '1', $a[ Post_Preview::KEY_ARG ], $a[ Post_Preview::EXP_ARG ] ),
        'a deleted account must not be resurrected by an old credential'
    );
} );

Agentshell_Test::run( 'authenticate: user 0 and post 0 are refused outright', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    foreach ( array( array( '10', '0' ), array( '0', '1' ) ) as $pair ) {
        list( $post, $user ) = $pair;
        $key = agentshell_post_preview_sign( (int) $post, (int) $user, time() + 60 );
        Agentshell_Test::assert_equals(
            0,
            Post_Preview::authenticate( $post, $user, $key, (string) ( time() + 60 ) ),
            "post={$post} user={$user} must be refused"
        );
    }
} );

Agentshell_Test::run( 'authenticate: a key signed with the wrong secret fails closed', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $exp = time() + 60;
    $key = hash_hmac( 'sha256', 'agentshell-post-preview|10|1|' . $exp, 'attacker-secret' );
    Agentshell_Test::assert_equals( 0, Post_Preview::authenticate( '10', '1', $key, (string) $exp ) );
} );

Agentshell_Test::run( 'authenticate: with no signing secret every credential is refused', function () {
    // preview_secret() is a static with a fixed fallback chain, so "nothing
    // configured" can only be expressed in a process that defines neither
    // AGENTSHELL_REST_TOKEN nor AUTH_SALT. Child process, not a source grep.
    $this_suite_has_a_secret = '' !== Post_Preview::preview_secret();
    Agentshell_Test::assert_true(
        $this_suite_has_a_secret,
        'precondition: the main suite is configured, so the child is what proves the unconfigured path'
    );

    $out = agentshell_post_preview_run_child( 'no-secret' );
    Agentshell_Test::assert_false(
        str_contains( $out, 'FAIL' ),
        "the unconfigured secret path must fail closed everywhere:\n{$out}"
    );
    foreach ( array( 'secret-is-empty', 'authenticate-refused', 'preview-args-throws', 'message-explains' ) as $check ) {
        Agentshell_Test::assert_true(
            str_contains( $out, "ok {$check}" ),
            "missing child check: {$check}\n{$out}"
        );
    }
} );

/** Sign directly, bypassing preview_args()'s authorisation check. */
function agentshell_post_preview_sign( $post_id, $user_id, $expires ) {
    $ref = new ReflectionMethod( 'AgentShell_MCP\Post_Preview', 'sign' );
    $ref->setAccessible( true );
    return $ref->invoke( null, $post_id, $user_id, $expires, Post_Preview::preview_secret() );
}

// ═════════════════════════════════════════════════════════════════════════════
// Gate 3 — the route. A valid credential on the wrong endpoint is still refused.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'route: a plain front-end GET is allowed', function () {
    agentshell_post_preview_reset();
    Agentshell_Test::assert_true( agentshell_post_preview_route_allowed() );
} );

Agentshell_Test::run( 'route: non-GET methods are refused', function () {
    agentshell_post_preview_reset();
    foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD' ) as $method ) {
        $_SERVER['REQUEST_METHOD'] = $method;
        Agentshell_Test::assert_false(
            agentshell_post_preview_route_allowed(),
            "{$method} must be refused"
        );
    }
} );

Agentshell_Test::run( 'route: wp-admin, admin-ajax and admin-post are refused', function () {
    agentshell_post_preview_reset();
    $GLOBALS['_agentshell_test_request']['admin'] = true;
    Agentshell_Test::assert_false( agentshell_post_preview_route_allowed() );
} );

Agentshell_Test::run( 'route: REST is refused, by constant and by both spellings', function () {
    agentshell_post_preview_reset();
    // rest_route= is visible in $_GET before REST_REQUEST is defined.
    $_GET['rest_route'] = '/wp/v2/users';
    Agentshell_Test::assert_false( agentshell_post_preview_route_allowed(), 'rest_route= must be refused' );
    unset( $_GET['rest_route'] );

    // The permalink-prefix form: REST_REQUEST is defined by rest_api_loaded,
    // which runs on parse_request — after init, where this gate is consulted.
    $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users';
    Agentshell_Test::assert_false( agentshell_post_preview_route_allowed(), '/wp-json/ must be refused' );

    $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/wp/v2/users';
    // A real request carries the query string in both places; the gate reads
    // $_GET, which is populated from the same query.
    $_GET['rest_route'] = '/wp/v2/users';
    Agentshell_Test::assert_false( agentshell_post_preview_route_allowed(), 'index.php?rest_route= must be refused' );
} );

Agentshell_Test::run( 'route: REST_REQUEST and XMLRPC_REQUEST constants are refused', function () {
    // Run in a child process: a constant, once defined, cannot be undefined,
    // so defining either here would disable the gate for every later test.
    foreach ( array( '', 'REST_REQUEST', 'XMLRPC_REQUEST' ) as $constant ) {
        $out = agentshell_post_preview_run_child( 'route', array( $constant ) );
        Agentshell_Test::assert_equals(
            '' === $constant ? 'allowed' : 'refused',
            $out,
            $constant
                ? "the gate must refuse when {$constant} is defined"
                : 'the same request must be allowed with neither constant defined'
        );
    }
} );

/**
 * Run a child-process mode of post-preview-child.php and return its verdict.
 *
 * @param string $mode Mode to run.
 * @param array  $args Extra arguments.
 * @return string Trimmed stdout.
 */
function agentshell_post_preview_run_child( $mode, array $args = array() ) {
    $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/post-preview-child.php' )
        . ' ' . escapeshellarg( $mode );
    foreach ( $args as $arg ) {
        $cmd .= ' ' . escapeshellarg( $arg );
    }
    $out = shell_exec( $cmd . ' 2>&1' );
    Agentshell_Test::assert_true(
        null !== $out && '' !== trim( $out ),
        "the child process must report a verdict, got: " . var_export( $out, true )
    );
    return trim( $out );
}

Agentshell_Test::run( 'route: wp-login.php and wp-cron.php are refused', function () {
    agentshell_post_preview_reset();
    $_SERVER['REQUEST_URI'] = '/wp-login.php';
    Agentshell_Test::assert_false( agentshell_post_preview_route_allowed() );
    $_SERVER['REQUEST_URI'] = '/wp-cron.php';
    Agentshell_Test::assert_false( agentshell_post_preview_route_allowed() );
    // A query string does not disguise them.
    $_SERVER['REQUEST_URI'] = '/wp-login.php?redirect_to=/';
    Agentshell_Test::assert_false( agentshell_post_preview_route_allowed() );
} );

Agentshell_Test::run( 'route: a path merely containing "wp-json" is not a REST route', function () {
    agentshell_post_preview_reset();
    $_SERVER['REQUEST_URI'] = '/my-wp-json-guide/';
    Agentshell_Test::assert_true(
        agentshell_post_preview_route_allowed(),
        'the prefix match must respect path segment boundaries'
    );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Gate 3 applied — a valid credential on a refused route restores nobody.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'authorise: a valid credential restores the caller identity', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    $exp = agentshell_post_preview_put_valid_credential();
    Agentshell_Test::assert_true( agentshell_authorize_post_preview(), 'must authorise' );
    Agentshell_Test::assert_equals( 1, get_current_user_id(), 'identity must be restored' );
    Agentshell_Test::assert_equals(
        array( 'post_id' => 10, 'user_id' => 1, 'exp' => $exp ),
        $GLOBALS['agentshell_post_preview']
    );
} );

Agentshell_Test::run( 'authorise: an ordinary request has no side effects at all', function () {
    agentshell_post_preview_reset();
    agentshell_test_set_user( 0, array() );
    Agentshell_Test::assert_false( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 0, get_current_user_id(), 'nobody logged in' );
    Agentshell_Test::assert_equals( array(), $_GET, 'no credential was present' );
    Agentshell_Test::assert_equals( array(), $GLOBALS['_agentshell_test_captured_headers'] );
} );

Agentshell_Test::run( 'authorise: a partial credential is not treated as a credential', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_put_valid_credential();
    foreach ( array( Post_Preview::POST_ARG, Post_Preview::USER_ARG, Post_Preview::KEY_ARG, Post_Preview::EXP_ARG ) as $drop ) {
        agentshell_post_preview_reset();
        agentshell_post_preview_put_valid_credential();
        unset( $_GET[ $drop ] );
        Agentshell_Test::assert_false(
            agentshell_authorize_post_preview(),
            "a credential missing {$drop} must not authorise"
        );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
} );

Agentshell_Test::run( 'authorise: a valid credential on a REST path restores nobody', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_put_valid_credential();
    $_GET['rest_route'] = '/wp/v2/users';
    Agentshell_Test::assert_false( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 0, get_current_user_id(), 'no identity for the REST API' );
    Agentshell_Test::assert_equals( null, $GLOBALS['agentshell_post_preview'], 'no preview marker either' );
} );

Agentshell_Test::run( 'authorise: a valid credential on /wp-json/ restores nobody', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_put_valid_credential();
    $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?agentshell_post=10';
    Agentshell_Test::assert_false( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 0, get_current_user_id() );
} );

Agentshell_Test::run( 'authorise: a valid credential under wp-admin restores nobody', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_put_valid_credential();
    $GLOBALS['_agentshell_test_request']['admin'] = true;
    Agentshell_Test::assert_false( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 0, get_current_user_id() );
} );

Agentshell_Test::run( 'authorise: a valid credential on wp-login.php restores nobody', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_put_valid_credential();
    $_SERVER['REQUEST_URI'] = '/wp-login.php';
    Agentshell_Test::assert_false( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 0, get_current_user_id() );
} );

Agentshell_Test::run( 'authorise: a valid credential sent as POST restores nobody', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_put_valid_credential();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Agentshell_Test::assert_false( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 0, get_current_user_id() );
} );

Agentshell_Test::run( 'authorise: each credential is decided on its own merits', function () {
    // The memo is keyed per credential, so a good verdict cannot leak into a
    // later request that presents a different one.
    agentshell_post_preview_reset();
    agentshell_test_set_user( 0, array() );
    agentshell_post_preview_put_valid_credential();
    Agentshell_Test::assert_true( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 1, get_current_user_id() );

    // A fresh request: same actor and post, wrong key. A different fingerprint,
    // so it must be decided fresh rather than inheriting the verdict above.
    $GLOBALS['agentshell_post_preview'] = null;
    agentshell_post_preview_set_args( array(
        Post_Preview::POST_ARG => '10',
        Post_Preview::USER_ARG => '1',
        Post_Preview::EXP_ARG  => (string) agentshell_post_preview_unique_exp(),
        Post_Preview::KEY_ARG  => str_repeat( '0', 64 ),
    ) );
    agentshell_test_set_user( 0, array() );
    Agentshell_Test::assert_false( agentshell_authorize_post_preview(), 'a forged key must not inherit the earlier verdict' );
    Agentshell_Test::assert_equals( 0, get_current_user_id(), 'no identity may be restored' );
    Agentshell_Test::assert_equals( null, $GLOBALS['agentshell_post_preview'] );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Gate 4 — post binding. Valid credential, wrong resolution.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'bind: a request that resolved to the bound post proceeds', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential();
    agentshell_authorize_post_preview();
    agentshell_test_resolve_query( 10 );
    agentshell_bind_post_preview_to_query();
    Agentshell_Test::assert_equals( null, $GLOBALS['agentshell_post_preview_refused'] );
    Agentshell_Test::assert_equals( 1, $GLOBALS['_agentshell_test_query']->post_count, 'query intact' );
    Agentshell_Test::assert_true( $GLOBALS['_agentshell_test_query']->post !== null, 'the post must still be renderable' );
    Agentshell_Test::assert_false( $GLOBALS['_agentshell_test_query']->is_404 );
} );

Agentshell_Test::run( 'bind: the response is marked uncacheable, noindex and no-referrer', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential();
    agentshell_authorize_post_preview();
    agentshell_test_resolve_query( 10 );
    agentshell_bind_post_preview_to_query();
    $headers = agentshell_post_preview_headers();
    Agentshell_Test::assert_true( str_contains( $headers, 'X-Robots-Tag: noindex, nofollow, noarchive' ), $headers );
    Agentshell_Test::assert_true( str_contains( $headers, 'Referrer-Policy: no-referrer' ), $headers );
    Agentshell_Test::assert_true( str_contains( $headers, 'Cache-Control: no-cache' ), $headers );
} );

Agentshell_Test::run( 'bind: a credential for post 10 pointed at post 11 leaks nothing', function () {
    // The vulnerability found during implementation. set_404() raises a flag
    // and leaves $wp_query->posts and global $post populated, so the content of
    // the refused post still rendered with a 404 status behind it.
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential( 10 );
    Agentshell_Test::assert_true( agentshell_authorize_post_preview() );
    agentshell_test_resolve_query( 11 );
    $GLOBALS['post'] = $GLOBALS['_agentshell_test_query']->post;
    $GLOBALS['wp_query'] = $GLOBALS['_agentshell_test_query'];

    agentshell_bind_post_preview_to_query();

    Agentshell_Test::assert_equals( 'post-binding-mismatch', $GLOBALS['agentshell_post_preview_refused'] );
    Agentshell_Test::assert_equals( array(), $GLOBALS['wp_query']->posts, 'the query must be emptied' );
    Agentshell_Test::assert_equals( 0, $GLOBALS['wp_query']->post_count );
    Agentshell_Test::assert_equals( 0, $GLOBALS['wp_query']->found_posts );
    Agentshell_Test::assert_equals( null, $GLOBALS['wp_query']->post );
    Agentshell_Test::assert_equals( null, $GLOBALS['post'], 'the global must be cleared too' );
    Agentshell_Test::assert_false( $GLOBALS['wp_query']->is_singular );
    Agentshell_Test::assert_false( $GLOBALS['wp_query']->is_page );
    Agentshell_Test::assert_false( $GLOBALS['wp_query']->is_single );
    Agentshell_Test::assert_true( $GLOBALS['wp_query']->is_404 );
    Agentshell_Test::assert_equals( 404, agentshell_post_preview_status() );
} );

Agentshell_Test::run( 'bind: a credential pointed at a non-singular page is refused', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential();
    agentshell_authorize_post_preview();
    // The query resolved to nothing singular (an archive, the home page).
    $q = agentshell_test_resolve_query( null );
    $q->is_singular = false;
    agentshell_bind_post_preview_to_query();
    Agentshell_Test::assert_equals( 'post-binding-mismatch', $GLOBALS['agentshell_post_preview_refused'] );
    Agentshell_Test::assert_equals( 404, agentshell_post_preview_status() );
} );

Agentshell_Test::run( 'bind: a refusal is indistinguishable from the ordinary 404', function () {
    // So the refusal cannot be used to probe which posts an actor may read.
    //
    // What must match is what an attacker can observe about *content*: the
    // status, and the absence of anything to render. The response headers
    // deliberately differ — an authorised request adds noindex/no-referrer,
    // because it is the one path that was carrying one person's unpublished
    // content — so they are asserted separately, below.
    $capture = function ( $with_credential ) {
        agentshell_post_preview_reset();
        agentshell_post_preview_owner();
        if ( $with_credential ) {
            agentshell_post_preview_put_valid_credential( 10 );
            agentshell_authorize_post_preview();
            agentshell_test_resolve_query( 11 );
            $GLOBALS['wp_query'] = $GLOBALS['_agentshell_test_query'];
            $GLOBALS['post'] = $GLOBALS['_agentshell_test_query']->post;
            agentshell_bind_post_preview_to_query();
        } else {
            // An anonymous visitor reaching a private post: core's own preview
            // check empties the query and raises 404 (class-wp-query.php:3494).
            agentshell_test_set_user( 0, array() );
            $q = agentshell_test_resolve_query( 11 );
            $q->set_404();
            $q->posts = array();
            $q->post = null;
            $q->post_count = 0;
            $GLOBALS['wp_query'] = $q;
            $GLOBALS['post'] = null;
            status_header( 404 );
        }
        return array(
            'status' => agentshell_post_preview_status(),
            'is_404' => $GLOBALS['wp_query']->is_404,
            'posts'  => $GLOBALS['wp_query']->posts,
            'global' => $GLOBALS['post'],
        );
    };

    $authorised = $capture( true );
    $anonymous  = $capture( false );
    Agentshell_Test::assert_equals( 404, $authorised['status'] );
    Agentshell_Test::assert_equals( $anonymous['status'], $authorised['status'], 'same status' );
    Agentshell_Test::assert_equals( $anonymous['is_404'], $authorised['is_404'], 'same 404 flag' );
    Agentshell_Test::assert_equals( array(), $authorised['posts'], 'nothing to render' );
    Agentshell_Test::assert_equals( $anonymous['posts'], $authorised['posts'], 'same empty query' );
    Agentshell_Test::assert_equals( null, $authorised['global'], 'no global post to render' );
    Agentshell_Test::assert_equals( $anonymous['global'], $authorised['global'], 'same global state' );
} );

Agentshell_Test::run( 'bind: the refusal reason is recorded but never rendered', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential( 10 );
    agentshell_authorize_post_preview();
    agentshell_test_resolve_query( 11 );
    $GLOBALS['wp_query'] = $GLOBALS['_agentshell_test_query'];
    agentshell_bind_post_preview_to_query();
    Agentshell_Test::assert_equals( 'post-binding-mismatch', $GLOBALS['agentshell_post_preview_refused'] );
    // Nothing emitted on this path may name the reason, the post or the user.
    $emitted = agentshell_post_preview_headers();
    foreach ( array( 'post-binding-mismatch', 'agentshell_post_key', 'agentshell_post_user' ) as $secret ) {
        Agentshell_Test::assert_false( str_contains( $emitted, $secret ), "{$secret} leaked into the response" );
    }
} );

Agentshell_Test::run( 'bind: the identity is dropped as soon as the request is refused', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential();
    agentshell_authorize_post_preview();
    Agentshell_Test::assert_equals( 1, get_current_user_id() );
    agentshell_test_resolve_query( 11 );
    $GLOBALS['wp_query'] = $GLOBALS['_agentshell_test_query'];
    agentshell_bind_post_preview_to_query();
    Agentshell_Test::assert_equals( null, $GLOBALS['agentshell_post_preview'], 'the preview marker must be cleared' );
} );

Agentshell_Test::run( 'bind: no credential means no gate runs at all', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_test_resolve_query( 11 );
    agentshell_bind_post_preview_to_query();
    Agentshell_Test::assert_equals( null, $GLOBALS['agentshell_post_preview_refused'] );
    Agentshell_Test::assert_equals( 1, $GLOBALS['_agentshell_test_query']->post_count, 'ordinary requests are untouched' );
    Agentshell_Test::assert_equals( array(), $GLOBALS['_agentshell_test_captured_headers'] );
} );

Agentshell_Test::run( 'bind: a refusal after headers are sent still clears the query', function () {
    // The status line is best-effort once output has begun, but the content
    // refusal is not: leaking the post body is worse than a missing header.
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential();
    agentshell_authorize_post_preview();
    agentshell_test_resolve_query( 11 );
    $GLOBALS['wp_query'] = $GLOBALS['_agentshell_test_query'];
    $GLOBALS['post'] = $GLOBALS['_agentshell_test_query']->post;
    $GLOBALS['_agentshell_test_headers_already_sent'] = true;
    agentshell_bind_post_preview_to_query();
    Agentshell_Test::assert_equals( array(), $GLOBALS['wp_query']->posts, 'query must still be emptied' );
    Agentshell_Test::assert_equals( null, $GLOBALS['post'] );
    Agentshell_Test::assert_equals( null, agentshell_post_preview_status(), 'no status line after output' );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Privilege escalation — core's capability check must still be authoritative.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'escalation: a valid credential cannot preview a post the actor may not edit', function () {
    // The mint-side check refuses outright...
    agentshell_post_preview_reset();
    agentshell_test_set_user( 2, array( 'read_post' => array( 11 ) ) );
    $threw = false;
    try { Post_Preview::preview_args( 10 ); } catch ( \InvalidArgumentException $e ) { $threw = true; }
    Agentshell_Test::assert_true( $threw, 'actor 2 must not mint a credential for post 10' );

    // ...and if a credential for it were somehow obtained, core refuses it,
    // because the restored identity genuinely lacks edit_post on that post.
    $exp = agentshell_post_preview_unique_exp();
    agentshell_post_preview_reset();
    agentshell_test_set_user( 2, array( 'read_post' => array( 11 ) ) );
    Agentshell_Test::assert_true(
        agentshell_post_preview_authorize_with(
            array( '10', '2', agentshell_post_preview_sign( 10, 2, $exp ), (string) $exp )
        ),
        'the identity is restored...'
    );
    Agentshell_Test::assert_false(
        current_user_can( 'edit_post', 10 ),
        '...but core still refuses the post, and the theme must not override that'
    );
} );

Agentshell_Test::run( 'escalation: a restored identity gains no capability it lacked', function () {
    // The credential restores an identity; it does not grant one. Measured on a
    // naive implementation, an unrestricted wp_set_current_user() also opened
    // unrelated private posts and the whole REST API.
    agentshell_post_preview_reset();
    agentshell_test_set_user( 2, array() );
    $before = current_user_can( 'edit_post', 10 );
    agentshell_post_preview_put_valid_credential( 10, 2 );
    agentshell_authorize_post_preview();
    Agentshell_Test::assert_equals( 2, get_current_user_id(), 'identity restored' );
    Agentshell_Test::assert_equals( $before, current_user_can( 'edit_post', 10 ), 'capabilities unchanged' );
    Agentshell_Test::assert_false( current_user_can( 'edit_theme_options' ) );
    Agentshell_Test::assert_false( current_user_can( 'manage_options' ) );
} );

/** Authorise against an explicit credential tuple; returns the boolean verdict. */
function agentshell_post_preview_authorize_with( array $tuple ) {
    list( $post, $user, $key, $exp ) = $tuple;
    agentshell_post_preview_set_args( array(
        Post_Preview::POST_ARG => $post,
        Post_Preview::USER_ARG => $user,
        Post_Preview::KEY_ARG  => $key,
        Post_Preview::EXP_ARG  => $exp,
    ) );
    return agentshell_authorize_post_preview();
}

// ═════════════════════════════════════════════════════════════════════════════
// Redirect suppression — the credential must not ride a Location header.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'redirect: a post-preview credential suppresses the canonical redirect', function () {
    // redirect_canonical() preserves unknown query args, so without this the
    // credential was relayed to a path the theme never vetted — measured as a
    // 301 whose Location header carried the full key.
    $url = 'https://example.test/canonical-page/?agentshell_post=10&agentshell_post_key=abc';
    Agentshell_Test::assert_false( agentshell_block_preview_redirects( 'https://example.test/canonical-page/', $url ) );
    // Including the transaction and profile credentials, which share the risk.
    Agentshell_Test::assert_false( agentshell_block_preview_redirects( 'https://example.test/x/', 'https://example.test/x/?agentshell_tx=tx_1&agentshell_tx_key=k' ) );
    Agentshell_Test::assert_false( agentshell_block_preview_redirects( 'https://example.test/x/', 'https://example.test/x/?agentshell_preview=profile' ) );
} );

Agentshell_Test::run( 'redirect: an ordinary request still redirects', function () {
    $target = 'https://example.test/canonical/';
    Agentshell_Test::assert_equals(
        $target,
        agentshell_block_preview_redirects( $target, 'https://example.test/?p=10' ),
        'the gate must not disable canonical redirects generally'
    );
} );

Agentshell_Test::run( 'redirect: a falsy redirect target passes through untouched', function () {
    Agentshell_Test::assert_false( agentshell_block_preview_redirects( false, 'https://example.test/?agentshell_post=10' ) );
    Agentshell_Test::assert_equals( '', agentshell_block_preview_redirects( '', 'https://example.test/?agentshell_post=10' ) );
} );

Agentshell_Test::run( 'redirect: a value that merely contains an arg name is not a match', function () {
    $target = 'https://example.test/canonical/';
    Agentshell_Test::assert_equals(
        $target,
        agentshell_block_preview_redirects( $target, 'https://example.test/?my_agentshell_post=1' ),
        'matching is on "name=", not on a substring of the name'
    );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Credential hygiene — the key must not escape into logs or caller output.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'hygiene: strip_args removes every post-preview argument', function () {
    $url = 'https://example.test/?page_id=10&preview=true&agentshell_post=10&agentshell_post_user=1'
        . '&agentshell_post_exp=123&agentshell_post_key=deadbeef';
    $stripped = Post_Preview::strip_args( $url );
    foreach ( array( 'agentshell_post=', 'agentshell_post_user=', 'agentshell_post_exp=', 'agentshell_post_key=' ) as $arg ) {
        Agentshell_Test::assert_false( str_contains( $stripped, $arg ), "{$arg} survived stripping" );
    }
    // The caller's own arguments are left alone.
    Agentshell_Test::assert_true( str_contains( $stripped, 'page_id=10' ) );
    Agentshell_Test::assert_true( str_contains( $stripped, 'preview=true' ) );
} );

Agentshell_Test::run( 'hygiene: redact keeps the post and expiry but drops the key', function () {
    $url = 'https://example.test/?agentshell_post=10&agentshell_post_exp=123&agentshell_post_key=deadbeef';
    $redacted = Post_Preview::redact( $url );
    Agentshell_Test::assert_false( str_contains( $redacted, 'deadbeef' ), 'the key must not survive' );
    Agentshell_Test::assert_false( str_contains( $redacted, 'agentshell_post_key' ) );
    Agentshell_Test::assert_true( str_contains( $redacted, 'agentshell_post=10' ), 'the URL stays diagnosable' );
    Agentshell_Test::assert_true( str_contains( $redacted, 'agentshell_post_exp=123' ) );
} );

Agentshell_Test::run( 'hygiene: strip and redact tolerate a URL with no query string', function () {
    Agentshell_Test::assert_equals( 'https://example.test/', Post_Preview::strip_args( 'https://example.test/' ) );
    Agentshell_Test::assert_equals( 'https://example.test/', Post_Preview::redact( 'https://example.test/' ) );
} );

Agentshell_Test::run( 'hygiene: a pasted credential cannot reach the audit log via a url argument', function () {
    // The screenshot tool strips caller-supplied credentials from `url` before
    // it is recorded. This asserts the scrubber on that path directly.
    $supplied = 'https://example.test/?agentshell_post=10&agentshell_post_user=1'
        . '&agentshell_post_exp=1&agentshell_post_key=' . str_repeat( 'a', 64 )
        . '&agentshell_tx=tx_1&agentshell_tx_key=' . str_repeat( 'b', 64 );
    $stripped = Post_Preview::strip_args( $supplied );
    Agentshell_Test::assert_false( str_contains( $stripped, str_repeat( 'a', 64 ) ) );
    Agentshell_Test::assert_false( str_contains( $stripped, 'agentshell_post_key' ) );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Interaction with the transaction preview — the combined acceptance case.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'combined: a draft preview and a transaction preview are independent', function () {
    agentshell_post_preview_reset();
    agentshell_post_preview_owner();
    agentshell_post_preview_put_valid_credential();
    agentshell_authorize_post_preview();
    agentshell_test_resolve_query( 10 );
    agentshell_bind_post_preview_to_query();
    // Both markers can be live on one request: the staged design and the
    // draft's content are separate questions, gated separately.
    Agentshell_Test::assert_equals( 10, $GLOBALS['agentshell_post_preview']['post_id'] );
    Agentshell_Test::assert_equals( null, $GLOBALS['agentshell_post_preview_refused'] );
} );

Agentshell_Test::run( 'combined: the transaction credential does not substitute for a post credential', function () {
    // A request carrying only the transaction preview key must restore no
    // identity, or the two mechanisms would be one mechanism with one gate.
    agentshell_post_preview_reset();
    $exp = time() + 60;
    agentshell_post_preview_set_args( array(
        'agentshell_tx'      => 'tx_1',
        'agentshell_tx_key'  => str_repeat( 'c', 64 ),
        'agentshell_tx_exp'  => (string) $exp,
    ) );
    Agentshell_Test::assert_false( agentshell_authorize_post_preview() );
    Agentshell_Test::assert_equals( 0, get_current_user_id() );
    Agentshell_test_resolve_query( 10 );
    agentshell_bind_post_preview_to_query();
    Agentshell_Test::assert_equals( null, $GLOBALS['agentshell_post_preview_refused'], 'nothing was authorised, so nothing to refuse' );
} );

// ═════════════════════════════════════════════════════════════════════════════
// The tool surface.
// ═════════════════════════════════════════════════════════════════════════════

Agentshell_Test::run( 'tool: the screenshot schema advertises post_id as an integer', function () {
    $file = dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-screenshot.php';
    Agentshell_Test::assert_true( file_exists( $file ) );
    $src = file_get_contents( $file );
    Agentshell_Test::assert_true(
        (bool) preg_match( "/'post_id'\s*=>\s*array\(\s*'type'\s*=>\s*'integer'/", $src ),
        'post_id must be a declared integer property'
    );
    Agentshell_Test::assert_true( str_contains( $src, 'Post_Preview::preview_args' ), 'the tool must mint through the credential authority' );
    Agentshell_Test::assert_true( str_contains( $src, 'Post_Preview::redact' ), 'the tool must scrub the key it minted' );
    Agentshell_Test::assert_true( str_contains( $src, 'Post_Preview::strip_args' ), 'the tool must scrub caller-supplied credentials' );
    // The captured_url must be run through both scrubbers.
    Agentshell_Test::assert_true(
        (bool) preg_match( '/redact_post_preview_credentials\(\s*self::redact_preview_credentials\(\s*\$url/', $src ),
        'captured_url must pass through both credential scrubbers'
    );
} );

Agentshell_Test::run( 'tool: the post-preview block runs before the SSRF host check', function () {
    // Ordering matters: the tool replaces `url` with the minted preview link,
    // so a host check reading the caller's original url would validate the
    // wrong thing. This pins the order in the source.
    $src = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-screenshot.php' );
    $mint = strpos( $src, 'Post_Preview::preview_args' );
    $guard = strpos( $src, 'does not match this site' );
    Agentshell_Test::assert_true( false !== $mint && false !== $guard, 'both blocks must exist' );
    Agentshell_Test::assert_true( $mint < $guard, 'the credential must be minted before the URL is checked' );
} );

Agentshell_Test::run( 'tool: the plugin loads the credential authority', function () {
    $src = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/agentshell-mcp.php' );
    Agentshell_Test::assert_true(
        str_contains( $src, "includes/class-post-preview.php" ),
        'class-post-preview.php must be required by the plugin entrypoint'
    );
} );

Agentshell_Test::run( 'tool: the theme gates are wired to the right hooks and priorities', function () {
    $src = file_get_contents( dirname( __DIR__ ) . '/functions.php' );
    // init priority 0: the main query runs after init, so a later check is too late.
    Agentshell_Test::assert_true(
        (bool) preg_match( "/add_action\(\s*'init',\s*'agentshell_authorize_post_preview',\s*0\s*\)/", $src ),
        'identity restoration must run on init at priority 0'
    );
    // template_redirect priority 0: after the main query, before any output.
    Agentshell_Test::assert_true(
        (bool) preg_match( "/add_action\(\s*'template_redirect',\s*'agentshell_bind_post_preview_to_query',\s*0\s*\)/", $src ),
        'post binding must run on template_redirect at priority 0'
    );
    Agentshell_Test::assert_true(
        (bool) preg_match( "/add_filter\(\s*'redirect_canonical',\s*'agentshell_block_preview_redirects',\s*0,\s*2\s*\)/", $src ),
        'redirect suppression must be registered on redirect_canonical'
    );
} );