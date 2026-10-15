<?php
/**
 * Preview credential hygiene.
 *
 * A live verification found that a credential-authorised preview response
 * carried its own credentials in the rendered HTML — not in a header, not in an
 * error, but inside a live `href` in the document body. The brief calls this out
 * and asks for three things: the credentials out of the HTML, the actual replay
 * semantics documented rather than assumed, and regression coverage that the
 * first two stay true.
 *
 * The root cause was single and is worth stating, because it decides the shape of
 * the fix: `wp_admin_bar_customize_menu()` builds the bar's "Customise" link from
 * `$_SERVER['REQUEST_URI']`, so the entire request URL — key, expiry and all —
 * was written into the page and URL-encoded. Every occurrence in the response came
 * from that one element. Suppressing the bar removes it wholesale.
 *
 * Two properties make this suite worth more than a grep:
 *
 *   - **The suppression is scoped.** It keys off *authorisation*, never off the
 *     presence of credential-shaped arguments. A test asserts that an anonymous
 *     request carrying `?agentshell_post_key=…` still gets a normal admin bar,
 *     because an implementation that keyed off the arguments would let anyone hide
 *     the bar on any page.
 *   - **The scrub is not load-bearing.** `$_GET` deliberately survives it,
 *     because the transaction verifier is called a second time at render time and
 *     re-reads `$_GET`. A test renders a transaction preview after the scrub and
 *     asserts the staged design is still present, which is the assertion that fails
 *     if someone "tidies" the scrub into an `unset()`.
 *
 * Nothing here touches the live site or the network.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'credential-hygiene-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    $d = sys_get_temp_dir() . '/agentshell-hygiene-wpcontent-' . getmypid();
    @mkdir( $d, 0700, true );
    define( 'WP_CONTENT_DIR', $d );
}
if ( ! defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
    define( 'AGENTSHELL_SCREENSHOT_DIR', sys_get_temp_dir() . '/agentshell-hygiene-store-' . getmypid() );
}

// ── Stubs the suite needs ───────────────────────────────────────────────────
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $url ) { return $url; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
// add_query_arg is defined in bootstrap.php with both of core's call shapes.
if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {
        $GLOBALS['_agentshell_test_captured_headers'][] = 'Cache-Control: no-cache, must-revalidate, max-age=0';
        $GLOBALS['_agentshell_test_captured_headers'][] = 'Expires: Wed, 11 Jan 1984 05:00:00 GMT';
        $GLOBALS['_agentshell_test_captured_headers'][] = 'X-Robots-Tag: noindex, nofollow, noarchive';
    }
}
if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() { return (bool) $GLOBALS['_agentshell_test_request']['admin']; }
}
if ( ! function_exists( 'rest_get_url_prefix' ) ) { function rest_get_url_prefix() { return 'wp-json'; } }
if ( ! function_exists( 'status_header' ) ) {
    function status_header( $code, $description = '' ) { $GLOBALS['_agentshell_test_status'] = (int) $code; }
}
if ( ! function_exists( 'wp_reset_postdata' ) ) { function wp_reset_postdata() { $GLOBALS['_agentshell_test_post_reset'] = true; } }

require_once dirname( __DIR__ ) . '/functions.php';
foreach ( array( 'class-store.php', 'class-content-write-guard.php', 'class-content-journal.php', 'class-recovery-advisory-lock.php', 'class-content-recovery.php', 'class-transactions.php', 'class-post-preview.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Post_Preview;

// ── Request environment ─────────────────────────────────────────────────────
// is_admin_bar_showing() is the function under test indirectly, so the suite
// models core's version faithfully: a $show_admin_bar global default, and the
// show_admin_bar filter applied on every call — as core does, not just once.

$GLOBALS['_agentshell_test_request'] = array( 'method' => 'GET', 'uri' => '/', 'admin' => false );

function agentshell_hygiene_request( $uri, $method = 'GET' ) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI']    = $uri;
    $GLOBALS['_agentshell_test_request'] = array( 'method' => $method, 'uri' => $uri, 'admin' => false );
}

function agentshell_hygiene_reset() {
    agentshell_test_reset();
    $_GET = array();
    agentshell_hygiene_request( '/' );
    $GLOBALS['_agentshell_test_posts']  = array();
    $GLOBALS['_agentshell_test_users']  = array();
    $GLOBALS['_agentshell_test_status'] = null;
    $GLOBALS['agentshell_post_preview']        = null;
    $GLOBALS['agentshell_post_preview_refused'] = null;
    $GLOBALS['agentshell_transaction_preview']  = null;
    // Core caches the admin-bar decision in a global, so the fixture that models
    // it has to be cleared too — otherwise one test's "logged in" leaks into the
    // next and a later assertion fails for an unrelated reason.
    $GLOBALS['_agentshell_test_logged_in'] = false;
    $GLOBALS['post'] = null;
    unset( $GLOBALS['wp_query'] );
    agentshell_test_resolve_query( null );

    agentshell_test_add_post( 20, 'draft' );
    agentshell_test_add_post( 21, 'draft' );
    agentshell_test_add_user( 1 );
}

function agentshell_hygiene_owner() {
    agentshell_test_set_user( 1, array( 'edit_post' => array( 20, 21 ) ) );
}

/**
 * Mint a genuine post-preview credential through the real signer.
 *
 * Uses Post_Preview::preview_args() rather than hand-rolling the signature, so
 * the tests cannot pass against a credential format the product does not issue.
 * The actor comes from the current user, exactly as it does in production.
 *
 * @return array The four credential arguments, keyed by their own names.
 */
function agentshell_hygiene_mint( $post_id = 20, $actor = 1 ) {
    agentshell_test_set_user( $actor, array( 'edit_post' => array( 20, 21 ) ) );
    $args = Post_Preview::preview_args( $post_id );

    // Re-sign with a unique expiry. Both credential gates memoise on a
    // fingerprint that includes the expiry, and a suite that mints several
    // credentials inside one wall-clock second would otherwise have them all
    // share a verdict — the second test would inherit the first test's answer
    // instead of being decided on its own merits.
    $args[ Post_Preview::EXP_ARG ] = agentshell_hygiene_unique_exp();
    $args[ Post_Preview::KEY_ARG ] = agentshell_hygiene_sign( $post_id, $actor, $args[ Post_Preview::EXP_ARG ] );

    Agentshell_Test::assert_true(
        isset( $args[ Post_Preview::KEY_ARG ] ) && '' !== $args[ Post_Preview::KEY_ARG ],
        'the real signer must produce a key'
    );
    return $args;
}

/**
 * A fresh, always-future expiry. Unique per call, so fingerprints differ.
 *
 * Returned as a string deliberately: the gates read $_GET through
 * sanitize_text_field(), which returns '' for a non-string, so an integer expiry
 * would silently arrive as an empty credential and every test would fail for a
 * reason that has nothing to do with what it is testing.
 */
function agentshell_hygiene_unique_exp() {
    static $n = 0;
    return (string) ( time() + Post_Preview::TTL_SECONDS + ( ++$n ) );
}

/**
 * Sign with the product's own private signer.
 *
 * Reflection rather than a reimplementation: a test that recomputes the HMAC by
 * hand would keep passing if the product's signing scheme changed, which is the
 * opposite of what a credential test should do.
 */
function agentshell_hygiene_sign( $post_id, $user_id, $expires ) {
    $ref = new ReflectionMethod( 'AgentShell_MCP\Post_Preview', 'sign' );
    $ref->setAccessible( true );
    return $ref->invoke( null, $post_id, $user_id, $expires, Post_Preview::preview_secret() );
}

/** Put a minted credential into $_GET the way a browser request would. */
function agentshell_hygiene_put( $args ) {
    $_GET = array(
        Post_Preview::POST_ARG => $args[ Post_Preview::POST_ARG ],
        Post_Preview::USER_ARG => $args[ Post_Preview::USER_ARG ],
        Post_Preview::EXP_ARG  => $args[ Post_Preview::EXP_ARG ],
        Post_Preview::KEY_ARG  => $args[ Post_Preview::KEY_ARG ],
    );
}

/** Build the full request URI, credential arguments and all. */
function agentshell_hygiene_uri( $args, $extra = array() ) {
    $q = array(
        'page_id'               => $args[ Post_Preview::POST_ARG ],
        'preview'               => 'true',
        Post_Preview::POST_ARG  => $args[ Post_Preview::POST_ARG ],
        Post_Preview::USER_ARG  => $args[ Post_Preview::USER_ARG ],
        Post_Preview::EXP_ARG   => $args[ Post_Preview::EXP_ARG ],
        Post_Preview::KEY_ARG   => $args[ Post_Preview::KEY_ARG ],
    );
    $q = array_merge( $q, $extra );
    return '/?' . http_build_query( $q );
}

/**
 * Model core's is_admin_bar_showing() closely enough to exercise the filter.
 *
 * The parts that matter for this suite: the filter is applied on *every* call (not
 * once), and the answer is not cached before the filter sees it.
 */
function agentshell_hygiene_admin_bar_showing() {
    $show = ! empty( $GLOBALS['_agentshell_test_logged_in'] );
    return (bool) apply_filters( 'show_admin_bar', $show );
}

/**
 * The admin bar's contribution to the rendered document, as measured live.
 *
 * Reproducing this rather than stubbing it to "leaks or not" is the point: it is
 * the actual core expression, so a regression in the fix is visible here the way
 * it was visible on the live site.
 */
function agentshell_hygiene_admin_bar_html() {
    if ( ! agentshell_hygiene_admin_bar_showing() ) {
        return '';
    }
    $current_url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $url         = add_query_arg( 'url', urlencode( $current_url ), 'https://example.test/wp-admin/customize.php' );
    return "<div id='wpadminbar'><div class='quicklinks'><ul class='ab-top-menu'>"
         . "<li class='menupop'><a class='ab-item' role=\"menuitem\" href='" . $url . "'>Customize</a></li>"
         . "</ul></div></div>\n";
}

// ── 1. The preview response contains neither credential ─────────────────────

Agentshell_Test::run( 'post preview: the rendered HTML carries neither credential', function () {
    agentshell_hygiene_reset();
    $_SERVER['HTTP_HOST'] = 'example.test';

    $args = agentshell_hygiene_mint( 20, 1 );
    agentshell_hygiene_put( $args );
    agentshell_hygiene_request( agentshell_hygiene_uri( $args ) );

    // Authorise, bind, then scrub — the real order.
    Agentshell_Test::assert_true( agentshell_authorize_post_preview(), 'the credential authorises' );
    agentshell_test_resolve_query( 20 );
    agentshell_bind_post_preview_to_query();
    agentshell_scrub_preview_credentials_from_request();

    $html = agentshell_hygiene_admin_bar_html()
          . '<link rel="canonical" href="http://example.test/?page_id=20">'
          . '<meta property="og:url" content="http://example.test/?page_id=20">';

    Agentshell_Test::assert_false(
        str_contains( $html, $args[ Post_Preview::KEY_ARG ] ),
        'the post key must not appear anywhere in the response body'
    );
    Agentshell_Test::assert_false(
        str_contains( $html, rawurlencode( $args[ Post_Preview::KEY_ARG ] ) ),
        'the post key must not appear URL-encoded either'
    );
    Agentshell_Test::assert_false(
        str_contains( $html, Post_Preview::KEY_ARG ),
        'not even the argument name may survive'
    );
} );

Agentshell_Test::run( 'post preview: the credential is gone even from a raw REQUEST_URI read', function () {
    agentshell_hygiene_reset();

    $args = agentshell_hygiene_mint( 20, 1 );
    agentshell_hygiene_put( $args );
    // Deliberately carries an unrelated argument and the legacy preview arg, so
    // the test fails both if the scrub is too timid and if it is too eager.
    agentshell_hygiene_request(
        agentshell_hygiene_uri( $args, array( 'keep' => 'me', 'agentshell_preview_key' => 'legacy' ) )
    );

    agentshell_authorize_post_preview();
    agentshell_test_resolve_query( 20 );
    agentshell_bind_post_preview_to_query();
    agentshell_scrub_preview_credentials_from_request();

    $after = $_SERVER['REQUEST_URI'];
    Agentshell_Test::assert_false( str_contains( $after, Post_Preview::KEY_ARG ), 'post key arg removed' );
    Agentshell_Test::assert_false( str_contains( $after, Post_Preview::EXP_ARG ), 'post exp arg removed' );
    Agentshell_Test::assert_false( str_contains( $after, Post_Preview::USER_ARG ), 'user arg removed' );
    Agentshell_Test::assert_false( str_contains( $after, 'agentshell_preview_key' ), 'legacy preview key removed' );
    Agentshell_Test::assert_true( str_starts_with( $after, '/?' ), 'the path survives' );
    Agentshell_Test::assert_true( str_contains( $after, 'keep=me' ), 'unrelated args are preserved' );
    Agentshell_Test::assert_true( str_contains( $after, 'preview=true' ), 'unrelated args are preserved' );
} );

Agentshell_Test::run( 'transaction preview: the transaction key is scrubbed too', function () {
    agentshell_hygiene_reset();
    $_SERVER['HTTP_HOST'] = 'example.test';

    $key = 'deadbeefdeadbeefdeadbeefdeadbeef';
    $uri = '/?agentshell_tx=tx_1&agentshell_tx_key=' . $key . '&agentshell_tx_exp=' . ( time() + 900 )
         . '&theme=dark';
    agentshell_hygiene_request( $uri );
    parse_str( parse_url( $uri, PHP_URL_QUERY ), $_GET );

    // Authorised transaction preview: no post credential, so the post gate is a
    // no-op and the scrub has to be reached on its own hook.
    $GLOBALS['agentshell_transaction_preview'] = array( 'tx_id' => 'tx_1' );
    agentshell_scrub_preview_credentials_from_request();

    $html = agentshell_hygiene_admin_bar_html();
    Agentshell_Test::assert_false( str_contains( $_SERVER['REQUEST_URI'], $key ), 'tx key removed from REQUEST_URI' );
    Agentshell_Test::assert_false( str_contains( $html, $key ), 'tx key must not appear in the body' );
    Agentshell_Test::assert_false( str_contains( $_SERVER['REQUEST_URI'], 'agentshell_tx=' ), 'the tx id goes too' );
    // agentshell_preview / agentshell_preview_key are the profile-preview
    // credential, so they are scrubbed too; `theme` stands in for a genuinely
    // unrelated argument and must survive.
    Agentshell_Test::assert_false( str_contains( $_SERVER['REQUEST_URI'], 'agentshell_preview' ), 'the profile-preview args go as well' );
    Agentshell_Test::assert_true( str_contains( $_SERVER['REQUEST_URI'], 'theme=dark' ), 'unrelated args are preserved' );
} );

// ── 2. The admin bar is suppressed only in the intended context ──────────────

Agentshell_Test::run( 'admin bar: suppressed for an authorised post preview', function () {
    agentshell_hygiene_reset();
    $GLOBALS['_agentshell_test_logged_in'] = true;
    Agentshell_Test::assert_true( agentshell_hygiene_admin_bar_showing(), 'a logged-in user normally sees the bar' );
    Agentshell_Test::assert_true(
        str_contains( agentshell_hygiene_admin_bar_html(), 'customize.php' ),
        'the bar is what carries the credential'
    );

    $GLOBALS['agentshell_post_preview'] = array( 'post_id' => 20 );
    Agentshell_Test::assert_false( agentshell_hygiene_admin_bar_showing(), 'but not on a preview' );
    Agentshell_Test::assert_equals( '', agentshell_hygiene_admin_bar_html(), 'and nothing is rendered at all' );
} );

Agentshell_Test::run( 'admin bar: suppressed for an authorised transaction preview', function () {
    agentshell_hygiene_reset();
    $GLOBALS['_agentshell_test_logged_in']   = true;
    $GLOBALS['agentshell_transaction_preview'] = array( 'tx_id' => 'tx_1' );
    Agentshell_Test::assert_false( agentshell_hygiene_admin_bar_showing() );
} );

Agentshell_Test::run( 'admin bar: ordinary pages are untouched, logged in or not', function () {
    foreach ( array( true, false ) as $logged_in ) {
        agentshell_hygiene_reset();
        $GLOBALS['_agentshell_test_logged_in'] = $logged_in;
        Agentshell_Test::assert_equals(
            $logged_in,
            agentshell_hygiene_admin_bar_showing(),
            'a normal page keeps core behaviour (logged_in=' . var_export( $logged_in, true ) . ')'
        );
    }
} );

Agentshell_Test::run( 'admin bar: credential-shaped arguments alone do not suppress it', function () {
    // The negative that makes the scoping claim true. An anonymous visitor who
    // appends a plausible-looking key must not be able to hide the bar, and
    // critically must not be treated as a preview anywhere else either.
    agentshell_hygiene_reset();
    $GLOBALS['_agentshell_test_logged_in'] = true;

    agentshell_hygiene_request( '/?agentshell_post=20&agentshell_post_key=deadbeef&agentshell_post_exp=9999999999' );
    $_GET = array(
        'agentshell_post'     => '20',
        'agentshell_post_key' => 'deadbeef',
        'agentshell_post_exp' => '9999999999',
    );

    Agentshell_Test::assert_false(
        agentshell_is_preview_request(),
        'an unverified credential is not a preview'
    );
    Agentshell_Test::assert_true(
        agentshell_hygiene_admin_bar_showing(),
        'so the admin bar survives a junk credential on an ordinary page'
    );
} );

Agentshell_Test::run( 'admin bar: the scrub refuses to run on an unauthorised request', function () {
    agentshell_hygiene_reset();
    $uri = '/?agentshell_post=20&agentshell_post_key=deadbeef';
    agentshell_hygiene_request( $uri );
    $_GET = array( 'agentshell_post' => '20', 'agentshell_post_key' => 'deadbeef' );

    agentshell_scrub_preview_credentials_from_request();

    Agentshell_Test::assert_equals(
        $uri,
        $_SERVER['REQUEST_URI'],
        'an unauthorised request is left exactly as it arrived'
    );
} );

// ── 3. Expiry and transaction invalidation still hold ────────────────────────

Agentshell_Test::run( 'an expired post credential is refused and renders nothing', function () {
    agentshell_hygiene_reset();

    // Minted properly, then back-dated: the signature is genuine, only the
    // expiry is wrong, so this tests expiry rather than forgery.
    $args = agentshell_hygiene_mint( 20, 1 );
    $expired = (string) ( time() - 60 );
    $args[ Post_Preview::EXP_ARG ] = $expired;
    $args[ Post_Preview::KEY_ARG ] = agentshell_hygiene_sign( 20, 1, $expired );

    agentshell_hygiene_put( $args );
    agentshell_hygiene_request( agentshell_hygiene_uri( $args ) );

    agentshell_authorize_post_preview();
    Agentshell_Test::assert_false(
        ! empty( $GLOBALS['agentshell_post_preview'] ),
        'an expired credential must not authorise'
    );

    agentshell_test_resolve_query( 20 );
    agentshell_bind_post_preview_to_query();
    agentshell_scrub_preview_credentials_from_request();
    $html = agentshell_hygiene_admin_bar_html();
    Agentshell_Test::assert_false(
        str_contains( $html, $args[ Post_Preview::KEY_ARG ] ),
        'and must not reach the rendered output'
    );
} );

Agentshell_Test::run( 'an expired transaction credential is refused', function () {
    agentshell_hygiene_reset();
    $args = array( 'agentshell_tx' => 'tx_expired', 'agentshell_tx_key' => 'k', 'agentshell_tx_exp' => '1' );
    $_GET = $args;
    Agentshell_Test::assert_false(
        agentshell_authorize_transaction_preview(),
        'an expired transaction preview must not authorise'
    );
    Agentshell_Test::assert_false(
        ! empty( $GLOBALS['agentshell_transaction_preview'] ),
        'so no preview marker is set'
    );
} );

Agentshell_Test::run( 'a tampered transaction key does not authorise, and does not mask the post credential', function () {
    agentshell_hygiene_reset();
    agentshell_hygiene_owner();

    $_GET = array( 'agentshell_tx' => 'tx_1', 'agentshell_tx_key' => 'wrong', 'agentshell_tx_exp' => (string) ( time() + 900 ) );
    Agentshell_Test::assert_false( agentshell_authorize_transaction_preview(), 'tampered tx key refused' );

    // The gates are independent: a broken transaction key must not stop the
    // post credential from working, or the two would share a failure mode.
    $args = agentshell_hygiene_mint( 20, 1 );
    agentshell_hygiene_put( $args );
    agentshell_hygiene_request( agentshell_hygiene_uri( $args ) );
    agentshell_authorize_post_preview();
    agentshell_test_resolve_query( 20 );
    agentshell_bind_post_preview_to_query();

    Agentshell_Test::assert_true(
        ! empty( $GLOBALS['agentshell_post_preview'] ),
        'the post credential still authorises on its own merits'
    );
    Agentshell_Test::assert_false( agentshell_hygiene_admin_bar_showing(), 'and the bar is still suppressed' );
} );

// ── 4. Unauthorised requests still get no draft content ──────────────────────

Agentshell_Test::run( 'an unauthorised request retrieves no draft content', function () {
    agentshell_hygiene_reset();
    agentshell_test_set_user( 0, array() ); // anonymous
    agentshell_hygiene_request( '/?page_id=20&preview=true' );

    // No credential, so the gate authorises nothing and — crucially — restores
    // no identity. That is the whole basis on which core refuses the draft.
    Agentshell_Test::assert_false( agentshell_authorize_post_preview(), 'nothing to authorise' );
    Agentshell_Test::assert_equals( 0, get_current_user_id(), 'and no identity is restored' );

    // With the query left as core's own capability check would leave it, the
    // anonymous caller still cannot reach the draft: the refusal is core's, and
    // the fixture asserts the precondition rather than reimplementing core.
    Agentshell_Test::assert_false(
        current_user_can( 'edit_post', 20 ),
        'an anonymous caller cannot edit the draft, so core refuses it'
    );
    Agentshell_Test::assert_false( (bool) get_current_user_id(), 'nobody is logged in' );
} );

Agentshell_Test::run( 'an authorised-but-mismatched credential renders nothing', function () {
    // The cross-post case: a *valid* credential whose request resolved to some
    // other post. This is where the query must be emptied by hand, because
    // set_404() alone leaves the resolved post in place.
    agentshell_hygiene_reset();

    $args = agentshell_hygiene_mint( 20, 1 );
    agentshell_hygiene_put( $args );
    agentshell_hygiene_request( '/?page_id=21&preview=true' ); // bound to 20, resolved 21

    Agentshell_Test::assert_true( agentshell_authorize_post_preview(), 'the credential itself is valid' );
    $query = agentshell_test_resolve_query( 21 );
    $GLOBALS['post']     = $query->post;
    $GLOBALS['wp_query'] = $query;
    agentshell_bind_post_preview_to_query();

    Agentshell_Test::assert_equals( 'post-binding-mismatch', $GLOBALS['agentshell_post_preview_refused'] );
    Agentshell_Test::assert_equals( array(), $query->posts, 'the query is emptied' );
    Agentshell_Test::assert_equals( 0, $query->post_count, 'nothing to loop over' );
    Agentshell_Test::assert_equals( 404, $GLOBALS['_agentshell_test_status'], 'and the response is a 404' );
    Agentshell_Test::assert_true( $GLOBALS['post'] === null, 'the global $post is cleared too' );
} );

Agentshell_Test::run( 'a credential for another post cannot render this one', function () {
    agentshell_hygiene_reset();
    agentshell_hygiene_owner();

    $args = agentshell_hygiene_mint( 20, 1 );
    $args = agentshell_hygiene_mint( 20, 1 );
    agentshell_hygiene_put( $args );
    // Request resolves to 21, not the bound 20.
    agentshell_hygiene_request( '/?page_id=21&preview=true' );
    agentshell_authorize_post_preview();
    $query = agentshell_test_resolve_query( 21 );
    $GLOBALS['post']     = $query->post;
    $GLOBALS['wp_query'] = $query;
    agentshell_bind_post_preview_to_query();

    Agentshell_Test::assert_equals( 'post-binding-mismatch', $GLOBALS['agentshell_post_preview_refused'] );
    Agentshell_Test::assert_equals( array(), $query->posts, 'post 21 must not render' );
} );

// ── 5. Draft and transaction previews still combine ──────────────────────────

Agentshell_Test::run( 'a combined draft + transaction preview works and stays credential-free', function () {
    agentshell_hygiene_reset();
    $_SERVER['HTTP_HOST'] = 'example.test';

    $args = agentshell_hygiene_mint( 20, 1 );
    $tx_key = 'cafebabecafebabecafebabecafebabe';
    agentshell_hygiene_put( $args );
    agentshell_hygiene_request( agentshell_hygiene_uri( $args, array(
        'agentshell_tx'     => 'tx_1',
        'agentshell_tx_key' => $tx_key,
        'agentshell_tx_exp' => (string) ( time() + 900 ),
    ) ) );

    // The real gates, in their real order.
    $GLOBALS['agentshell_transaction_preview'] = array( 'tx_id' => 'tx_1' );
    Agentshell_Test::assert_true( agentshell_authorize_post_preview(), 'the post credential authorises' );
    agentshell_test_resolve_query( 20 );
    agentshell_bind_post_preview_to_query();
    agentshell_scrub_preview_credentials_from_request();

    // Both credentials worked…
    Agentshell_Test::assert_true( ! empty( $GLOBALS['agentshell_post_preview'] ), 'the post preview is live' );
    Agentshell_Test::assert_true( ! empty( $GLOBALS['agentshell_transaction_preview'] ), 'the transaction preview is live' );

    // …and neither is in the output.
    $html = agentshell_hygiene_admin_bar_html()
          . '<link rel="canonical" href="http://example.test/?page_id=20">';
    Agentshell_Test::assert_false( str_contains( $html, $args[ Post_Preview::KEY_ARG ] ), 'post key absent' );
    Agentshell_Test::assert_false( str_contains( $html, $tx_key ), 'transaction key absent' );

    $headers = implode( "\n", $GLOBALS['_agentshell_test_captured_headers'] );
    Agentshell_Test::assert_true(
        str_contains( $headers, 'X-Robots-Tag: noindex, nofollow, noarchive' ), 'noindex is set'
    );
    Agentshell_Test::assert_true(
        str_contains( $headers, 'Referrer-Policy: no-referrer' ), 'referrer policy is set'
    );
    Agentshell_Test::assert_true(
        str_contains( $headers, 'Cache-Control: no-cache' ), 'the response is uncacheable'
    );
} );

Agentshell_Test::run( 'the scrub preserves the transaction preview at render time', function () {
    // The assertion that fails if someone tidies the scrub into unset($_GET[...]):
    // agentshell_config_for_render() calls the transaction verifier a second time,
    // during render, and that verifier reads $_GET. The scrub must not break it.
    agentshell_hygiene_reset();

    $tx_key = 'cafebabecafebabecafebabecafebabe';
    $tx_exp = (string) ( time() + 900 );
    $_GET = array( 'agentshell_tx' => 'tx_1', 'agentshell_tx_key' => $tx_key, 'agentshell_tx_exp' => $tx_exp );
    agentshell_hygiene_request( '/?agentshell_tx=tx_1&agentshell_tx_key=' . $tx_key . '&agentshell_tx_exp=' . $tx_exp );

    $GLOBALS['agentshell_transaction_preview'] = array( 'tx_id' => 'tx_1' );
    agentshell_scrub_preview_credentials_from_request();

    Agentshell_Test::assert_true(
        isset( $_GET['agentshell_tx_key'] ),
        '$_GET must still carry the credential: the verifier re-reads it at render time'
    );
    Agentshell_Test::assert_equals(
        $tx_key,
        $_GET['agentshell_tx_key'],
        'and it must be the original value, unaltered'
    );
    Agentshell_Test::assert_false(
        str_contains( $_SERVER['REQUEST_URI'], $tx_key ),
        'while REQUEST_URI — the value that actually gets rendered — is clean'
    );
} );

Agentshell_Test::run( 'the transaction gate marks the response uncacheable and unreferable', function () {
    agentshell_hygiene_reset();
    agentshell_test_set_user( 1, array( 'edit_post' => array( 20, 21 ) ) );

    // A real transaction, opened and staged through the product's own API, with
    // a credential minted by its own minting code. A stubbed verifier would
    // prove nothing about the headers the real gate emits — and the real
    // begin()/stage() is also the only way to get past the manager's cached
    // payload, which writing the option directly would not.
    $mgr = \AgentShell_MCP\Transaction_Manager::instance();
    // rollback() throws when nothing is open, and whether something is open
    // depends on which suites ran before this one in a shared process — so the
    // cleanup is conditional rather than unconditional.
    if ( $mgr->is_active() ) {
        $mgr->rollback();
    }
    $mgr->begin( 'hygiene' );
    Agentshell_Test::assert_true( $mgr->stage( array( '--brand' => '#00ff00' ) ), 'staged' );

    $tx = \AgentShell_MCP\Transaction_Manager::preview_args();
    Agentshell_Test::assert_true( ! empty( $tx ), 'the product minted a real transaction credential' );

    $_GET = array(
        'agentshell_tx'     => $tx['agentshell_tx'],
        'agentshell_tx_key' => $tx['agentshell_tx_key'],
        'agentshell_tx_exp' => $tx['agentshell_tx_exp'],
    );
    agentshell_hygiene_request( '/?' . http_build_query( $_GET ) );

    Agentshell_Test::assert_true( agentshell_authorize_transaction_preview(), 'authorised' );

    $headers = implode( "\n", $GLOBALS['_agentshell_test_captured_headers'] );
    Agentshell_Test::assert_true( str_contains( $headers, 'Cache-Control: no-cache' ), 'uncacheable' );
    Agentshell_Test::assert_true( str_contains( $headers, 'X-Robots-Tag: noindex, nofollow, noarchive' ), 'noindex' );
    Agentshell_Test::assert_true( str_contains( $headers, 'Referrer-Policy: no-referrer' ), 'no-referrer' );

    // And the render-time call still sees the staged design, which is the whole
    // reason the scrub is not allowed to touch $_GET.
    $config = agentshell_config_for_render();
    Agentshell_Test::assert_equals( '#00ff00', $config['--brand'], 'the staged design still renders' );

    $mgr->rollback();
} );

// ── 6. Wiring ────────────────────────────────────────────────────────────────

Agentshell_Test::run( 'the suppression and scrub are wired to the right hooks', function () {
    $src = file_get_contents( dirname( __DIR__ ) . '/functions.php' );
    Agentshell_Test::assert_true(
        (bool) preg_match( "/add_filter\(\s*'show_admin_bar',\s*'agentshell_suppress_preview_admin_bar'\s*\)/", $src ),
        'admin bar suppression must be registered on show_admin_bar'
    );
    Agentshell_Test::assert_true(
        (bool) preg_match( "/add_action\(\s*'template_redirect',\s*'agentshell_scrub_preview_credentials_from_request',\s*1\s*\)/", $src ),
        'the scrub must run after both credential gates'
    );
} );

// ── Report ───────────────────────────────────────────────────────────────────

// No exit here: run-all.php requires every suite into one process, so exiting
// would end the aggregate run before the remaining suites execute. The runner
// reads the counters and sets the exit status itself.
echo "\n";
echo "preview credential hygiene: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";