<?php
/**
 * Published-preview credential hygiene.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * AgentShell has three preview credentials, and the third one was not
 * treated like the other two.
 *
 *   1. the post-preview key       (agentshell_post_key)
 *   2. the transaction-preview key (agentshell_tx_key)
 *   3. the published-preview credential (agentshell_pub_cred)
 *
 * The first two have been scrubbed out of REQUEST_URI and redacted out of
 * any URL the tools hand back, since the measurement recorded in
 * preview-credential-hygiene-test.php: a credential left in REQUEST_URI is
 * written into the rendered page body by core's admin bar, whose
 * "Customise" link is built from the request URL.
 *
 * The third one — the credential that renders a *published* page with its
 * staged, unpublished edit overlaid — was not in either list. The
 * consequence, measured live against the disposable test bed:
 *
 *   - the credential appeared in `captured_url`, the URL the screenshot
 *     tool returns to its caller, in the clear;
 *   - a caller could paste a credential into a `url` argument and have it
 *     survive into the capture and into the audit log, which records tool
 *     arguments verbatim;
 *   - and, for a logged-in user, the credential appeared in the rendered
 *     HTML body of the previewed page, URL-encoded inside the admin bar's
 *     "Customise" link.
 *
 * That last one is the serious half. A preview credential is a bearer
 * capability: it authorises one request to see content that is not
 * published. Writing it into a page body puts it somewhere that outlives
 * its 300-second window — a saved page, a DOM snapshot, a support ticket,
 * a transcript.
 *
 * What is asserted here
 * ---------------------
 *   1. The outbound direction: `Post_Preview::redact()` removes the
 *      published-preview credential, so the URL the screenshot tool reports
 *      does not carry it.
 *   2. The inbound direction: `Post_Preview::strip_args()` removes it from
 *      a caller-supplied URL, so a pasted credential cannot ride along into
 *      the capture or into the audit log.
 *   3. The theme's scrub removes it from REQUEST_URI.
 *   4. The theme's canonical-redirect guard refuses to redirect a request
 *      that carries it, so the credential cannot be relayed to a path the
 *      theme never vetted.
 *   5. The scoping property: a request that merely *looks* like a preview
 *      (the arguments are present but nothing verified them) does not
 *      suppress the admin bar, does not scrub REQUEST_URI and does not
 *      block the canonical redirect. Keying off the arguments rather than
 *      off a verified credential would be a griefing primitive: anyone
 *      could hide the bar on any page by appending a query argument.
 *   6. The published preview IS recognised as a preview request once its
 *      credential has verified — otherwise the two protections that keep
 *      the credential out of the page body would never run for it.
 *
 * Nothing here touches the live site or the network.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'published-preview-hygiene-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }
if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) { define( 'AGENTSHELL_REST_TOKEN', 'published-preview-hygiene-token' ); }

require_once dirname( __DIR__ ) . '/functions.php';
foreach ( array( 'class-store.php', 'class-content-write-guard.php', 'class-content-journal.php', 'class-recovery-advisory-lock.php', 'class-content-recovery.php', 'class-transactions.php', 'class-post-preview.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

// The theme's canonical-redirect guard parses the query with core's
// wp_parse_url. The unit bootstrap does not stub it (the other suite
// that exercises this guard stubs it locally), so it is stubbed here —
// faithfully: core's wp_parse_url is parse_url with a component
// constant of -1 meaning "all components".
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

use AgentShell_MCP\Post_Preview;

echo "published-preview credential hygiene tests\n";

// ── Fixtures ────────────────────────────────────────────────────────────────

/**
 * A published-preview credential, minted by the product's own signer.
 *
 * The real signer, so a test cannot pass against a credential format the
 * product does not issue.
 *
 * @return string The base64-encoded credential blob.
 */
function agentshell_pph_mint() {
    $args = Post_Preview::published_preview_url_args( 20, 21, 'tx_1', 'op_1', 1 );
    return (string) $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
}

/**
 * Put the credential into $_GET and REQUEST_URI the way a browser would.
 *
 * @param string $cred
 */
function agentshell_pph_put( $cred ) {
    $_GET = array(
        Post_Preview::PUB_PREVIEW_FLAG_ARG => '1',
        Post_Preview::PUB_PREVIEW_CRED_ARG => $cred,
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/page/?' . http_build_query( $_GET );
    $_SERVER['HTTP_HOST']      = 'example.test';
}

/**
 * Model the published overlay's verified global.
 *
 * The overlay installs `agentshell_pub_preview_overlay` only after the
 * credential has verified. Setting it here models that verified state
 * without standing up the whole overlay.
 */
function agentshell_pph_mark_verified() {
    $GLOBALS['agentshell_pub_preview_overlay'] = array(
        'oid' => 20, 'sid' => 21, 'tx' => 'tx_1', 'op' => 'op_1', 'actor' => 1,
    );
}

function agentshell_pph_reset() {
    agentshell_test_reset();
    $_GET = array();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/';
    $_SERVER['HTTP_HOST']      = 'example.test';
    unset( $GLOBALS['agentshell_post_preview'], $GLOBALS['agentshell_transaction_preview'] );
    unset( $GLOBALS['agentshell_pub_preview_overlay'] );
    $GLOBALS['_agentshell_test_logged_in'] = false;
}

// ── 1. The outbound direction: the reported URL carries no credential ───────

Agentshell_Test::run( 'redact: the published-preview credential is removed from a reported URL', function () {
    agentshell_pph_reset();
    $cred = agentshell_pph_mint();
    $url  = 'https://example.test/page/?agentshell_preview=1&agentshell_pub_cred=' . rawurlencode( $cred );

    $redacted = Post_Preview::redact( $url );

    Agentshell_Test::assert_false(
        str_contains( $redacted, $cred ),
        'the credential must not survive redaction'
    );
    Agentshell_Test::assert_false(
        str_contains( $redacted, rawurlencode( $cred ) ),
        'nor URL-encoded'
    );
    Agentshell_Test::assert_false(
        str_contains( $redacted, Post_Preview::PUB_PREVIEW_CRED_ARG ),
        'not even the argument name may survive'
    );
    // The page identity survives, so the URL stays diagnosable.
    Agentshell_Test::assert_true(
        str_contains( $redacted, '/page/' ),
        'the path survives redaction'
    );
} );

Agentshell_Test::run( 'redact: the post-preview key is still redacted (the change did not regress the first credential)', function () {
    agentshell_pph_reset();
    $url = 'https://example.test/?agentshell_post=20&agentshell_post_key=SECRETPOSTKEY&agentshell_post_exp=123';
    $redacted = Post_Preview::redact( $url );
    Agentshell_Test::assert_false(
        str_contains( $redacted, 'SECRETPOSTKEY' ),
        'the post key must still be redacted'
    );
    Agentshell_Test::assert_true(
        str_contains( $redacted, 'agentshell_post=20' ),
        'the post id survives, so the URL stays diagnosable'
    );
} );

// ── 2. The inbound direction: a pasted credential does not ride along ───────

Agentshell_Test::run( 'strip_args: a caller-supplied published-preview credential is removed', function () {
    agentshell_pph_reset();
    $cred = agentshell_pph_mint();
    $url  = 'https://example.test/?agentshell_pub_cred=' . rawurlencode( $cred )
          . '&agentshell_preview=1&keep=me';

    $stripped = Post_Preview::strip_args( $url );

    Agentshell_Test::assert_false(
        str_contains( $stripped, $cred ),
        'the credential must not survive stripping'
    );
    Agentshell_Test::assert_false(
        str_contains( $stripped, rawurlencode( $cred ) ),
        'nor URL-encoded'
    );
    Agentshell_Test::assert_true(
        str_contains( $stripped, 'keep=me' ),
        'an unrelated argument survives'
    );
} );

Agentshell_Test::run( 'strip_args: the post-preview arguments are still stripped', function () {
    agentshell_pph_reset();
    $url = 'https://example.test/?agentshell_post=20&agentshell_post_user=1&agentshell_post_key=K&agentshell_post_exp=9';
    $stripped = Post_Preview::strip_args( $url );
    foreach ( array( 'agentshell_post', 'agentshell_post_user', 'agentshell_post_key', 'agentshell_post_exp' ) as $arg ) {
        Agentshell_Test::assert_false(
            str_contains( $stripped, $arg ),
            $arg . ' must be stripped'
        );
    }
} );

// ── 3. The theme's scrub removes it from REQUEST_URI ───────────────────────

Agentshell_Test::run( 'the scrub removes the published-preview credential from REQUEST_URI', function () {
    agentshell_pph_reset();
    $cred = agentshell_pph_mint();
    agentshell_pph_put( $cred );
    // The overlay has verified the credential, so this IS a preview request.
    agentshell_pph_mark_verified();

    Agentshell_Test::assert_true(
        agentshell_is_preview_request(),
        'a verified published preview is a preview request'
    );

    agentshell_scrub_preview_credentials_from_request();

    Agentshell_Test::assert_false(
        str_contains( $_SERVER['REQUEST_URI'], $cred ),
        'the credential must not survive the scrub'
    );
    Agentshell_Test::assert_false(
        str_contains( $_SERVER['REQUEST_URI'], Post_Preview::PUB_PREVIEW_CRED_ARG ),
        'nor the argument name'
    );
    Agentshell_Test::assert_true(
        str_starts_with( $_SERVER['REQUEST_URI'], '/page/' ),
        'the path survives'
    );
} );

// ── 4. The canonical redirect guard ─────────────────────────────────────────

Agentshell_Test::run( 'the canonical-redirect guard refuses a request carrying the credential', function () {
    agentshell_pph_reset();
    $cred = agentshell_pph_mint();
    $requested = 'https://example.test/?agentshell_pub_cred=' . rawurlencode( $cred );

    $blocked = agentshell_block_preview_redirects( 'https://example.test/other/', $requested );

    Agentshell_Test::assert_equals(
        false,
        $blocked,
        'the redirect must be suppressed, or the credential rides along in Location'
    );
} );

Agentshell_Test::run( 'the canonical-redirect guard still refuses the other two credentials', function () {
    agentshell_pph_reset();
    foreach ( array(
        'https://example.test/?agentshell_tx_key=k',
        'https://example.test/?agentshell_post_key=k',
        'https://example.test/?agentshell_post_exp=1',
        'https://example.test/?agentshell_tx_exp=1',
    ) as $requested ) {
        Agentshell_Test::assert_equals(
            false,
            agentshell_block_preview_redirects( 'https://example.test/other/', $requested ),
            'must still block: ' . $requested
        );
    }
} );

// ── 5. The scoping property: shape alone grants nothing ────────────────────

Agentshell_Test::run( 'an unverified published preview does not suppress the admin bar', function () {
    agentshell_pph_reset();
    $cred = agentshell_pph_mint();
    agentshell_pph_put( $cred );
    // Deliberately NOT marked verified: the arguments are present but
    // nothing has checked them.

    Agentshell_Test::assert_false(
        agentshell_is_preview_request(),
        'the arguments alone are not authorisation'
    );
} );

Agentshell_Test::run( 'an unverified published preview does not scrub REQUEST_URI', function () {
    agentshell_pph_reset();
    $cred = agentshell_pph_mint();
    agentshell_pph_put( $cred );
    $before = $_SERVER['REQUEST_URI'];

    agentshell_scrub_preview_credentials_from_request();

    Agentshell_Test::assert_equals(
        $before,
        $_SERVER['REQUEST_URI'],
        'an unauthorised request is left exactly as it arrived'
    );
} );

Agentshell_Test::run( 'an ordinary page with a credential-shaped argument is untouched', function () {
    agentshell_pph_reset();
    $uri = '/?agentshell_pub_cred=whatever&agentshell_preview=1&theme=dark';
    $_SERVER['REQUEST_URI'] = $uri;
    parse_str( parse_url( $uri, PHP_URL_QUERY ), $_GET );

    Agentshell_Test::assert_false(
        agentshell_is_preview_request(),
        'not a preview'
    );
    agentshell_scrub_preview_credentials_from_request();
    Agentshell_Test::assert_equals(
        $uri,
        $_SERVER['REQUEST_URI'],
        'the request is left alone'
    );
} );

Agentshell_Test::run( 'the canonical-redirect guard blocks by argument NAME, fail-closed', function () {
    // The guard matches on the parsed argument names, not on whether a
    // credential verified — the same rule the other two credentials
    // follow. It has to: redirect_canonical() preserves unknown query
    // arguments, so a credential relayed in a Location header would reach a
    // path the theme never vetted, and the guard cannot know at that point
    // whether the credential was valid. Failing closed costs an
    // un-canonicalised URL; failing open costs the credential.
    agentshell_pph_reset();
    $uri = 'https://example.test/?agentshell_pub_cred=whatever';
    Agentshell_Test::assert_equals(
        false,
        agentshell_block_preview_redirects( 'https://example.test/other/', $uri ),
        'a request carrying the credential argument is not redirected'
    );

    // An ordinary request is unaffected.
    Agentshell_Test::assert_equals(
        'https://example.test/canonical/',
        agentshell_block_preview_redirects( 'https://example.test/canonical/', 'https://example.test/?p=10' ),
        'an ordinary request keeps its canonical redirect'
    );
} );

// ── 6. The published preview IS recognised once verified ───────────────────

Agentshell_Test::run( 'a verified published preview is recognised as a preview request', function () {
    agentshell_pph_reset();
    agentshell_pph_mark_verified();

    Agentshell_Test::assert_true(
        agentshell_is_preview_request(),
        'the verified overlay must make this a preview request, or neither the admin bar nor the scrub protects it'
    );
} );

Agentshell_Test::run( 'the post and transaction previews are still recognised', function () {
    agentshell_pph_reset();
    $GLOBALS['agentshell_post_preview'] = array( 'post_id' => 20 );
    Agentshell_Test::assert_true( agentshell_is_preview_request(), 'post preview still counts' );

    agentshell_pph_reset();
    $GLOBALS['agentshell_transaction_preview'] = array( 'tx_id' => 'tx_1' );
    Agentshell_Test::assert_true( agentshell_is_preview_request(), 'transaction preview still counts' );
} );

// ── 7. The wiring: the scrub must actually run for this request ────────────

Agentshell_Test::run( 'the scrub is wired to template_redirect after the credential gates', function () {
    $src = file_get_contents( dirname( __DIR__ ) . '/functions.php' );
    Agentshell_Test::assert_true(
        (bool) preg_match( "/add_action\(\s*'template_redirect',\s*'agentshell_scrub_preview_credentials_from_request',\s*1\s*\)/", $src ),
        'the scrub must run on template_redirect at priority 1'
    );
} );

// ── Report ──────────────────────────────────────────────────────────────────

// No exit: run-all.php requires every suite into one process, and exiting
// would end the aggregate run before the remaining suites execute.
echo "\n";
echo "published-preview credential hygiene: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
