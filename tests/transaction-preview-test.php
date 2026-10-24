<?php
/**
 * Transaction-aware preview regressions.
 *
 * The gap this fills: the site-from-brief workflow can stage a design, but
 * there was no way to *see* it. `agentshell_preview_transaction` returns a
 * diff, not a render, and the theme renders from the committed option — so the
 * only way to screenshot a staged design was to publish it first and walk it
 * back. That is backwards: the transaction exists precisely so uncommitted work
 * stays off the live site, and the visual check is the step most likely to be
 * skipped without it.
 *
 * The security line is narrow and deliberate. Staged config is uncommitted
 * design; a visitor seeing it is a disclosure. So the preview is:
 *
 *   - explicitly requested (a `transaction` argument, never automatic),
 *   - scoped to one transaction, its opening actor, and a 15-minute window,
 *   - rendered by substituting the config for one request, never by writing
 *     to the option, a cache, or any visitor's response,
 *   - dead the instant the transaction closes, because authorisation reads
 *     the very option that commit and rollback delete.
 *
 * Most assertions below are about a *refusal* — a request that must render the
 * committed config. A grep proves a guard string exists; only execution shows
 * it runs on the path an unauthorised caller actually takes.
 *
 * Nothing here touches the live site. Chrome is the shared fake browser, which
 * never makes a network request; the render assertions read the CSS the theme
 * would have handed Chrome, which is the same bytes a real capture rasterises.
 */

require_once __DIR__ . '/bootstrap.php';

// The signing secret. bootstrap.php defines AGENTSHELL_REST_TOKEN as an empty
// string, and preview_secret() deliberately treats empty as "not configured"
// so it falls through — which is the behaviour this suite needs to pin.
if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'tx-preview-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    $d = sys_get_temp_dir() . '/agentshell-txpreview-wpcontent-' . getmypid();
    @mkdir( $d, 0700, true );
    define( 'WP_CONTENT_DIR', $d );
}
if ( ! defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
    // Pinned: the production default walks up out of ABSPATH, and this suite's
    // ABSPATH is tests/, so it would otherwise create the store in the repo.
    define( 'AGENTSHELL_SCREENSHOT_DIR', sys_get_temp_dir() . '/agentshell-txpreview-store-' . getmypid() );
}

$GLOBALS['_agentshell_test_uploads'] = array(
    'basedir' => '', 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => '',
);
$GLOBALS['_agentshell_test_login'] = 'agent';

if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); } }
if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir() { return $GLOBALS['_agentshell_test_uploads']; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $url ) { return $url; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $len = 12, $special = true, $extra = false ) {
        return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
    }
}
// add_query_arg is defined in bootstrap.php with both of core's call shapes.

// Declared unguarded by the screenshot-privacy suite, which shares this
// process under run-all.php.
if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        public $ID = 0;
        public $user_login = '';
    }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user() {
        $u = new WP_User();
        // ID is set so Store::actor() resolves the login below rather than
        // returning its own 'agent' default — which is what binds a preview
        // credential to the actor that opened the transaction.
        $u->ID = 1;
        $u->user_login = $GLOBALS['_agentshell_test_login'];
        return $u;
    }
}
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return false; } }
if ( ! function_exists( 'nocache_headers' ) ) {
    // Mirrors WordPress: sets the four headers that make a response
    // uncacheable by intermediaries as well as the browser.
    function nocache_headers() {
        $GLOBALS['_agentshell_test_captured_headers'][] = 'Cache-Control: no-cache, must-revalidate, max-age=0';
        $GLOBALS['_agentshell_test_captured_headers'][] = 'Expires: Wed, 11 Jan 1984 05:00:00 GMT';
        $GLOBALS['_agentshell_test_captured_headers'][] = 'X-Robots-Tag: noindex, nofollow, noarchive';
    }
}

// Doctor reads this global to gate commit on the WP floor. Declaring it here
// (rather than stubbing a function) keeps the commit tests exercising the real
// validation gate instead of a bypass.
$GLOBALS['wp_version'] = '6.5';

require_once __DIR__ . '/fake-browser.php';
require_once dirname( __DIR__ ) . '/functions.php';
// Earlier suites in run-all.php have already loaded most of the plugin; each
// one is a plain require_once, so a shared file would be declared twice.
foreach ( array(
    'class-store.php', 'class-doctor.php', 'class-content-write-guard.php', 'class-content-journal.php',
    'class-recovery-advisory-lock.php', 'class-content-recovery.php',
    'class-transactions.php',
    'class-screenshot.php', 'class-screenshot-store.php', 'class-json-rpc.php',
    'class-server.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-screenshot.php';

use AgentShell_MCP\Transaction_Manager as Tx;

echo "transaction-preview regression tests\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const COMMITTED_BG = '#123456'; // what visitors see
const STAGED_BG    = '#ff00ff'; // what the transaction wants

/**
 * Seed the committed config and clear any preview credentials from the
 * request, so each test starts from an ordinary anonymous page view.
 */
function txp_reset( $config = null ) {
    agentshell_test_reset();
    $_GET = array();
    if ( null === $config ) {
        $config = array( 'design' => array( 'colors' => array( 'primary' => COMMITTED_BG ) ) );
    }
    $GLOBALS['_agentshell_test_options']['agentshell_config'] = $config;
    // Drop the cached transaction payload, or it survives the option reset.
    $ref = new ReflectionProperty( Tx::class, 'instance' );
    $ref->setAccessible( true );
    $ref->setValue( null, null );
    return $config;
}

/**
 * Open a transaction and stage a visibly different colour, exactly as an
 * agentshell_set_css_var call inside a transaction would.
 */
function txp_open_with_staged_change() {
    txp_reset();
    Tx::instance()->begin( 'preview test' );
    $staged = $GLOBALS['_agentshell_test_options']['agentshell_config'];
    $staged['design']['colors']['primary'] = STAGED_BG;
    Tx::instance()->stage( $staged );
    return Tx::instance()->payload();
}

/** The CSS a browser would receive for the current $_GET. */
function txp_rendered_css() {
    ob_start();
    agentshell_inject_saved_styles();
    return (string) ob_get_clean();
}

/**
 * Drive agentshell_screenshot against the shared fake browser.
 *
 * The fake browser records no network request — that is the point of it — so
 * these tests assert on what the tool builds and returns: the URL it asked a
 * browser to fetch, and the summary that follows it back. Whether the render
 * behind that URL shows the staged colour is proven separately, by reading the
 * CSS the theme emits for the same $_GET.
 */
function agentshell_test_capture( array $arguments ) {
    $dir = sys_get_temp_dir() . '/agentshell-txpreview-bin-' . getmypid();
    @mkdir( $dir, 0700, true );
    if ( ! defined( 'AGENTSHELL_CHROME_BIN' ) ) {
        define( 'AGENTSHELL_CHROME_BIN', agentshell_fake_cdp_browser_path( $dir ) );
    }
    putenv( 'AGENTSHELL_FAKE_CHROME_MODE=success' );
    putenv( 'AGENTSHELL_FAKE_PNG_BYTES=4096' );
    $tool = new \AgentShell_MCP\Tools\Screenshot();
    return $tool->execute( $arguments );
}

/** Filenames in the inline scratch directory — must be empty after a capture. */
function agentshell_test_scratch_listing() {
    $dir = \AgentShell_MCP\Screenshot_Store::scratch_dir();
    if ( ! is_dir( $dir ) ) { return array(); }
    $out = array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) );
    sort( $out );
    return $out;
}

/** Present credentials to the renderer, as the preview URL would. */
function txp_pretend_request( array $args ) {
    $_GET = $args;
}

/**
 * Call the private signer. Backdating an expiry requires knowing what a valid
 * signature over a *past* window looks like — which is precisely the artefact
 * an attacker would need and must not be able to produce for free.
 */
function txp_sign( $tx_id, $actor, $expires ) {
    $m = new ReflectionMethod( Tx::class, 'sign_preview' );
    $m->setAccessible( true );
    return $m->invoke( null, $tx_id, $actor, $expires, Tx::preview_secret() );
}

// ---------------------------------------------------------------------------
// 1. The committed baseline is visible without preview authorisation.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'ordinary render shows the committed config with no transaction open',
    function () {
        txp_reset();
        $css = txp_rendered_css();
        Agentshell_Test::assert_true(
            str_contains( $css, COMMITTED_BG ),
            'committed colour missing from an ordinary render'
        );
    }
);

Agentshell_Test::run(
    'ordinary render shows the committed config while a transaction is open',
    function () {
        txp_open_with_staged_change();
        $css = txp_rendered_css();
        Agentshell_Test::assert_true( str_contains( $css, COMMITTED_BG ) );
    }
);

// ---------------------------------------------------------------------------
// 2. A staged, visibly different token appears in an authorised render.
//    (This is the CSS Chrome rasterises; live verification confirms the
//    capture itself. See the design doc's "Live verification".)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'authorised preview renders the staged colour',
    function () {
        $tx = txp_open_with_staged_change();
        txp_pretend_request( Tx::preview_args() );
        $css = txp_rendered_css();
        Agentshell_Test::assert_true(
            str_contains( $css, STAGED_BG ),
            'staged colour missing from an authorised preview render'
        );
    }
);

Agentshell_Test::run(
    'authorised preview replaces the committed value rather than layering over it',
    function () {
        txp_open_with_staged_change();
        txp_pretend_request( Tx::preview_args() );
        $css = txp_rendered_css();
        Agentshell_Test::assert_false(
            str_contains( $css, COMMITTED_BG ),
            'a token removed by the transaction is still rendering from the committed block; '
            . 'an appended override cannot express removal, which is why the config is substituted instead'
        );
    }
);

Agentshell_Test::run(
    'minted credentials carry the transaction id and an expiry, and nothing else',
    function () {
        txp_open_with_staged_change();
        $args = Tx::preview_args();
        Agentshell_Test::assert_equals(
            array( Tx::PREVIEW_TX_ARG, Tx::PREVIEW_EXP_ARG, Tx::PREVIEW_KEY_ARG ),
            array_keys( $args )
        );
        Agentshell_Test::assert_true( (int) $args[ Tx::PREVIEW_EXP_ARG ] > time() );
        Agentshell_Test::assert_true(
            (int) $args[ Tx::PREVIEW_EXP_ARG ] <= time() + Tx::PREVIEW_TTL_SECONDS,
            'expiry exceeds the documented window'
        );
        Agentshell_Test::assert_true( strlen( $args[ Tx::PREVIEW_KEY_ARG ] ) >= 32 );
    }
);

Agentshell_Test::run(
    'the credential is bound to the transaction id',
    function () {
        txp_open_with_staged_change();
        $args = Tx::preview_args();
        $args[ Tx::PREVIEW_TX_ARG ] = 'tx_somebody_else';
        txp_pretend_request( $args );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'the credential is bound to the opening actor',
    function () {
        txp_open_with_staged_change();
        $args  = Tx::preview_args();
        $key   = txp_sign( $args[ Tx::PREVIEW_TX_ARG ], 'someone-else', (int) $args[ Tx::PREVIEW_EXP_ARG ] );
        txp_pretend_request( array(
            Tx::PREVIEW_TX_ARG  => $args[ Tx::PREVIEW_TX_ARG ],
            Tx::PREVIEW_EXP_ARG => $args[ Tx::PREVIEW_EXP_ARG ],
            Tx::PREVIEW_KEY_ARG => $key,
        ) );
        // A signature over the wrong actor must not authorise, even though the
        // transaction id and expiry are genuine.
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

// ---------------------------------------------------------------------------
// 3. The same changes stay invisible to ordinary visitors.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'the staged config never reaches the stored option',
    function () {
        txp_open_with_staged_change();
        txp_pretend_request( Tx::preview_args() );
        txp_rendered_css();
        $stored = $GLOBALS['_agentshell_test_options']['agentshell_config'];
        Agentshell_Test::assert_equals(
            COMMITTED_BG,
            $stored['design']['colors']['primary'],
            'a preview render wrote the staged value into agentshell_config'
        );
    }
);

Agentshell_Test::run(
    'a login session alone does not authorise a transaction preview',
    function () {
        // Deliberate divergence from the profile preview, which accepts any
        // logged-in browser. An editor's session is not authority to render
        // another actor's uncommitted design, so the credential is mandatory.
        txp_open_with_staged_change();
        $_GET = array( Tx::PREVIEW_TX_ARG => Tx::instance()->payload()["id"] );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'normal theme rendering never consults effective_config()',
    function () {
        // The prohibited design: every render automatically showing staged
        // config while a transaction is open. Pinned by asserting the
        // committed-config reader is the only source for an ordinary request,
        // even though effective_config() would return something different.
        txp_open_with_staged_change();
        Agentshell_Test::assert_equals( STAGED_BG, Tx::instance()->effective_config()['design']['colors']['primary'] );
        Agentshell_Test::assert_true(
            str_contains( txp_rendered_css(), COMMITTED_BG ),
            'ordinary render adopted effective_config() — staged config is leaking to visitors'
        );
    }
);

// ---------------------------------------------------------------------------
// No staged value may reach a cache. Found live: WordPress sends no
// Cache-Control at all on front-end pages, so on a host with a shared page
// cache the staged response would be stored and replayed to visitors.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'an authorised preview response is marked uncacheable',
    function () {
        txp_open_with_staged_change();
        txp_pretend_request( Tx::preview_args() );
        agentshell_authorize_transaction_preview();
        $headers = implode( "\n", $GLOBALS['_agentshell_test_captured_headers'] );
        Agentshell_Test::assert_true(
            str_contains( $headers, 'Cache-Control: no-cache, must-revalidate, max-age=0' ),
            "preview response carries no Cache-Control; a shared cache may store it: $headers"
        );
        Agentshell_Test::assert_true( str_contains( $headers, 'X-Robots-Tag: noindex' ) );
    }
);

Agentshell_Test::run(
    'a valid credential is refused once the headers are gone',
    function () {
        // The other end of the same control. nocache_headers() on a response
        // whose headers already went out is a no-op, so the staged render
        // would go out cacheable — the exact leak the header block exists to
        // prevent. Refusing costs the agent one retry; allowing it leaks the
        // design to whoever reads the cache next. So it must fail closed, and
        // it must fail *closed*, not merely quietly render the committed site.
        txp_open_with_staged_change();
        $GLOBALS['_agentshell_test_headers_already_sent'] = true;
        txp_pretend_request( Tx::preview_args() );

        Agentshell_Test::assert_false(
            agentshell_authorize_transaction_preview(),
            'a staged render was authorised after the response headers were already sent; '
            . 'the no-store headers could not be applied, so the staged config would be cached'
        );
        // And the request must now render the committed site, not the staged one.
        Agentshell_Test::assert_true(
            str_contains( txp_rendered_css(), COMMITTED_BG ),
            'staged config reached the render even though the preview was refused'
        );
        Agentshell_Test::assert_true(
            ! str_contains( txp_rendered_css(), STAGED_BG ),
            'staged colour present in an ordinary render'
        );
    }
);

Agentshell_Test::run(
    'an ordinary response is left alone',
    function () {
        txp_reset();
        agentshell_authorize_transaction_preview();
        Agentshell_Test::assert_equals( array(), $GLOBALS['_agentshell_test_captured_headers'] );
    }
);

Agentshell_Test::run(
    'a refused credential does not get cache headers either',
    function () {
        // Not a correctness problem — an unauthorised request renders the
        // committed site, which is cacheable. Asserting it keeps the header
        // block provably tied to authorisation rather than to the query args.
        txp_open_with_staged_change();
        txp_pretend_request( array( Tx::PREVIEW_TX_ARG => Tx::instance()->payload()['id'], Tx::PREVIEW_KEY_ARG => 'wrong', Tx::PREVIEW_EXP_ARG => '1' ) );
        agentshell_authorize_transaction_preview();
        Agentshell_Test::assert_equals( array(), $GLOBALS['_agentshell_test_captured_headers'] );
    }
);

// ---------------------------------------------------------------------------
// 4. Invalid, expired and unauthorised preview requests fail.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'no credential fails closed',
    function () {
        txp_open_with_staged_change();
        txp_pretend_request( array( Tx::PREVIEW_TX_ARG => Tx::instance()->payload()["id"] ) );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'a wrong key fails closed',
    function () {
        txp_open_with_staged_change();
        $args = Tx::preview_args();
        $args[ Tx::PREVIEW_KEY_ARG ] = str_repeat( '0', 64 );
        txp_pretend_request( $args );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'an empty key fails closed',
    function () {
        txp_open_with_staged_change();
        $args = Tx::preview_args();
        $args[ Tx::PREVIEW_KEY_ARG ] = '';
        txp_pretend_request( $args );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'an expired credential fails closed',
    function () {
        $tx   = txp_open_with_staged_change();
        $past = time() - 60;
        txp_pretend_request( array(
            Tx::PREVIEW_TX_ARG  => $tx['id'],
            Tx::PREVIEW_EXP_ARG => (string) $past,
            // Correctly signed — over the expired window. Only the render-time
            // expiry check can refuse this.
            Tx::PREVIEW_KEY_ARG => txp_sign( $tx['id'], $tx['actor'], $past ),
        ) );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'a credential cannot be stretched by editing the expiry parameter',
    function () {
        $tx   = txp_open_with_staged_change();
        $args = Tx::preview_args();
        // Same key, far-future expiry. If the expiry were merely compared
        // rather than signed over, this would authorise forever.
        $args[ Tx::PREVIEW_EXP_ARG ] = (string) ( time() + 86400 );
        txp_pretend_request( $args );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'a non-numeric expiry fails closed',
    function () {
        $tx   = txp_open_with_staged_change();
        txp_pretend_request( array(
            Tx::PREVIEW_TX_ARG  => $tx['id'],
            Tx::PREVIEW_EXP_ARG => 'soon',
            Tx::PREVIEW_KEY_ARG => txp_sign( $tx['id'], $tx['actor'], 9999999999 ),
        ) );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'a zero expiry fails closed (it is simply a window that ended long ago)',
    function () {
        $tx   = txp_open_with_staged_change();
        txp_pretend_request( array(
            Tx::PREVIEW_TX_ARG  => $tx['id'],
            Tx::PREVIEW_EXP_ARG => '0',
            Tx::PREVIEW_KEY_ARG => txp_sign( $tx['id'], $tx['actor'], 0 ),
        ) );
        Agentshell_Test::assert_false( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'preview_args() refuses to mint when no transaction is open',
    function () {
        txp_reset();
        $threw = false;
        try { Tx::preview_args(); } catch ( InvalidArgumentException $e ) { $threw = true; }
        Agentshell_Test::assert_true( $threw, 'minted a preview credential with no transaction open' );
    }
);

Agentshell_Test::run(
    'preview_args() refuses to mint when no signing secret exists',
    function () {
        // Separate process: a constant, once defined, cannot be undefined.
        $script = <<<'PHP'
<?php
namespace {
define( 'ABSPATH', __DIR__ );
define( 'AGENTSHELL_REST_TOKEN', '' );
$GLOBALS['_options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['_options'][$k] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['_options'][$k] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['_options'][$k] ); return true; }
function add_filter( ...$a ) { return true; }
function sanitize_text_field( $t ) { return is_string( $t ) ? trim( $t ) : ''; }
function wp_get_current_user() { return null; }
class WP_User { public $ID = 0; public $user_login = ''; }
function wp_json_encode( $d ) { return json_encode( $d ); }
}
// Transaction_Manager's constructor hooks a filter named after Store::CONFIG_KEY,
// and preview_args() reads CONFIG_KEY — enough of Store to get past both.
namespace AgentShell_MCP {
class Store {
    const CONFIG_KEY = 'agentshell_config';
    const REVISIONS_KEY = 'agentshell_revisions';
    public static $suppress_history = false;
    public static $operation_label = '';
}
}
namespace {
require $argv[1];
$GLOBALS['_options']['agentshell_transaction'] = array(
    'id' => 'tx_1', 'label' => '', 'actor' => 'agent', 'timestamp' => time(),
    'before' => array(), 'staged' => array(),
);
try {
    AgentShell_MCP\Transaction_Manager::preview_args();
    echo 'MINTED';
} catch ( InvalidArgumentException $e ) {
    echo 'REFUSED';
}
}
PHP;
        $file = tempnam( sys_get_temp_dir(), 'txp-nosecret-' );
        file_put_contents( $file, $script );
        $out = shell_exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' '
            . escapeshellarg( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-transactions.php' ) . ' 2>&1' );
        @unlink( $file );
        Agentshell_Test::assert_true(
            str_contains( (string) $out, 'REFUSED' ),
            'minted a credential with neither AGENTSHELL_REST_TOKEN nor AUTH_SALT defined: ' . $out
        );
    }
);

// ---------------------------------------------------------------------------
// 5. Rollback removes access to the staged changes.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'rollback invalidates a previously valid credential',
    function () {
        $tx   = txp_open_with_staged_change();
        $args = Tx::preview_args();
        txp_pretend_request( $args );
        Agentshell_Test::assert_true( str_contains( txp_rendered_css(), STAGED_BG ), 'setup' );

        Tx::instance()->rollback();
        // Same URL, next request: the transaction option is gone, so there is
        // nothing left for authorisation to match.
        Agentshell_Test::assert_false(
            str_contains( txp_rendered_css(), STAGED_BG ),
            'a rolled-back transaction still renders staged config'
        );
        Agentshell_Test::assert_true( str_contains( txp_rendered_css(), COMMITTED_BG ) );
        Agentshell_Test::assert_false( Tx::authorize_preview( $args[ Tx::PREVIEW_TX_ARG ], $args[ Tx::PREVIEW_KEY_ARG ], $args[ Tx::PREVIEW_EXP_ARG ] ) );
        unset( $tx );
    }
);

// ---------------------------------------------------------------------------
// 6. Commit leaves normal rendering consistent with the committed config, and
//    leaves no preview mechanism behind.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'after commit the staged value is the committed value',
    function () {
        txp_open_with_staged_change();
        Tx::instance()->commit();
        Agentshell_Test::assert_equals(
            STAGED_BG,
            $GLOBALS['_agentshell_test_options']['agentshell_config']['design']['colors']['primary']
        );
        $_GET = array();
        Agentshell_Test::assert_true( str_contains( txp_rendered_css(), STAGED_BG ) );
    }
);

Agentshell_Test::run(
    'commit leaves no live preview mechanism',
    function () {
        txp_open_with_staged_change();
        $args = Tx::preview_args();
        Tx::instance()->commit();
        Agentshell_Test::assert_false(
            Tx::authorize_preview( $args[ Tx::PREVIEW_TX_ARG ], $args[ Tx::PREVIEW_KEY_ARG ], $args[ Tx::PREVIEW_EXP_ARG ] ),
            'the credential still authorises after the transaction committed'
        );
        txp_pretend_request( $args );
        $css = txp_rendered_css();
        // It renders the committed config, which now happens to equal the
        // staged one — the point is that it renders as an ordinary request.
        Agentshell_Test::assert_true( str_contains( $css, STAGED_BG ) );
        Agentshell_Test::assert_false( str_contains( $css, 'agentshell-preview' ) );
    }
);

// ---------------------------------------------------------------------------
// 7. Existing profile previews and ordinary screenshots keep working.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'the existing theme-profile preview still renders its overrides',
    function () {
        txp_reset( array( 'design' => array( 'colors' => array( 'primary' => COMMITTED_BG ) ) ) );
        $GLOBALS['_agentshell_test_options']['agentshell_theme_profiles'] = array(
            'midnight' => array( 'design' => array( 'colors' => array( 'primary' => '#00ced1' ) ) ),
        );
        // bootstrap.php defines AGENTSHELL_REST_TOKEN as '', and the existing
        // block accepts the key derived from it — unchanged by this work.
        $_GET = array(
            'agentshell_preview'     => 'midnight',
            'agentshell_preview_key' => hash_hmac( 'sha256', 'midnight', AGENTSHELL_REST_TOKEN ),
        );
        $css = txp_rendered_css();
        Agentshell_Test::assert_true( str_contains( $css, 'agentshell-preview-overrides' ) );
        Agentshell_Test::assert_true( str_contains( $css, '#00ced1' ) );
    }
);

Agentshell_Test::run(
    'an unauthorised profile preview still renders nothing',
    function () {
        txp_reset( array( 'design' => array( 'colors' => array( 'primary' => COMMITTED_BG ) ) ) );
        $GLOBALS['_agentshell_test_options']['agentshell_theme_profiles'] = array(
            'midnight' => array( 'design' => array( 'colors' => array( 'primary' => '#00ced1' ) ) ),
        );
        $_GET = array( 'agentshell_preview' => 'midnight', 'agentshell_preview_key' => 'wrong' );
        $css  = txp_rendered_css();
        Agentshell_Test::assert_false( str_contains( $css, '#00ced1' ) );
        Agentshell_Test::assert_true( str_contains( $css, COMMITTED_BG ) );
    }
);

Agentshell_Test::run(
    'an ordinary screenshot is unchanged and carries no preview credential',
    function () {
        txp_reset();
        $result = agentshell_test_capture( array( 'viewport' => 'desktop' ) );
        Agentshell_Test::assert_true( $result['captured'] );
        Agentshell_Test::assert_equals( 'https://example.test/', $result['captured_url'] );
        Agentshell_Test::assert_false( isset( $result['preview_note'] ) );
    }
);

Agentshell_Test::run(
    'a transaction screenshot names the transaction it rendered',
    function () {
        $tx     = txp_open_with_staged_change();
        $result = agentshell_test_capture( array( 'transaction' => true ) );
        Agentshell_Test::assert_true( $result['captured'] );
        Agentshell_Test::assert_equals( $tx['id'], $result['screenshot']['previewed_transaction'] );
    }
);

Agentshell_Test::run(
    'transaction and profile cannot be combined',
    function () {
        txp_open_with_staged_change();
        $GLOBALS['_agentshell_test_options']['agentshell_theme_profiles'] = array(
            'midnight' => array( 'design' => array( 'colors' => array( 'primary' => '#00ced1' ) ) ),
        );
        $threw = false;
        try {
            agentshell_test_capture( array( 'transaction' => true, 'profile' => 'midnight' ) );
        } catch ( InvalidArgumentException $e ) { $threw = true; }
        Agentshell_Test::assert_true( $threw, 'silently honoured two contradictory preview sources' );
    }
);

Agentshell_Test::run(
    'screenshot(transaction) with no open transaction is an error, not a quiet committed capture',
    function () {
        txp_reset();
        $threw = false;
        try {
            agentshell_test_capture( array( 'transaction' => true ) );
        } catch ( InvalidArgumentException $e ) { $threw = true; }
        Agentshell_Test::assert_true( $threw, 'returned a committed screenshot as though it were a preview' );
    }
);

// ---------------------------------------------------------------------------
// 8. No preview credentials leak into image metadata, logs or public URLs.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'captured_url carries no preview key',
    function () {
        txp_open_with_staged_change();
        $result = agentshell_test_capture( array( 'transaction' => true ) );
        $url    = $result['captured_url'];
        Agentshell_Test::assert_false( str_contains( $url, 'agentshell_tx_key' ), 'preview key in captured_url' );
        // The non-secret parts survive, so the URL is still diagnosable.
        Agentshell_Test::assert_true( str_contains( $url, 'agentshell_tx_exp=' ) );
        Agentshell_Test::assert_true( str_contains( $url, 'agentshell_tx=' ) );
    }
);

Agentshell_Test::run(
    'the profile preview key is scrubbed from captured_url too',
    function () {
        txp_reset();
        $GLOBALS['_agentshell_test_options']['agentshell_theme_profiles'] = array(
            'midnight' => array( 'design' => array( 'colors' => array( 'primary' => '#00ced1' ) ) ),
        );
        $result = agentshell_test_capture( array( 'profile' => 'midnight' ) );
        Agentshell_Test::assert_false(
            str_contains( $result['captured_url'], 'agentshell_preview_key' ),
            'the profile preview key is the same kind of bearer capability and must be scrubbed too'
        );
    }
);

Agentshell_Test::run(
    'no credential appears anywhere in the returned summary, including the image block',
    function () {
        txp_open_with_staged_change();
        $args   = Tx::preview_args();
        $key    = $args[ Tx::PREVIEW_KEY_ARG ];
        $result = agentshell_test_capture( array( 'transaction' => true ) );
        $serialised = wp_json_encode( $result );
        Agentshell_Test::assert_false(
            str_contains( $serialised, $key ),
            'the preview key is present in the tool result'
        );
        unset( $serialised );
    }
);

Agentshell_Test::run(
    'a caller-supplied preview credential is stripped from the url argument',
    function () {
        // Otherwise an agent holding a key can paste it into `url`, and the
        // audit log — which records arguments — would persist the capability.
        txp_reset();
        $result = agentshell_test_capture( array(
            'url' => 'https://example.test/?agentshell_tx=tx_1&agentshell_tx_key=deadbeef&agentshell_tx_exp=9999999999',
        ) );
        Agentshell_Test::assert_false( str_contains( $result['captured_url'], 'deadbeef' ) );
        Agentshell_Test::assert_false( str_contains( $result['captured_url'], 'agentshell_tx_key' ) );
        Agentshell_Test::assert_equals( 'https://example.test/', $result['captured_url'] );
    }
);

Agentshell_Test::run(
    'the tool arguments that reach the audit log cannot contain the key',
    function () {
        txp_open_with_staged_change();
        // The credential is minted inside execute(), after the arguments have
        // been handed to the transport's logger — so the logged arguments are
        // the caller's {"transaction": true} and nothing else.
        $logged = array( 'transaction' => true );
        Agentshell_Test::assert_false(
            str_contains( wp_json_encode( $logged ), Tx::preview_args()[ Tx::PREVIEW_KEY_ARG ] )
        );
    }
);

Agentshell_Test::run(
    'nothing preview-related is written to the error log',
    function () {
        txp_open_with_staged_change();
        txp_pretend_request( Tx::preview_args() );
        txp_rendered_css();
        txp_pretend_request( array( Tx::PREVIEW_TX_ARG => Tx::instance()->payload()["id"], Tx::PREVIEW_KEY_ARG => 'wrong' ) );
        txp_rendered_css();
        Agentshell_Test::assert_equals( array(), $GLOBALS['_agentshell_test_error_log'] );
    }
);

Agentshell_Test::run(
    'an inline preview capture leaves no file behind',
    function () {
        txp_open_with_staged_change();
        $before = agentshell_test_scratch_listing();
        agentshell_test_capture( array( 'transaction' => true ) );
        Agentshell_Test::assert_equals( $before, agentshell_test_scratch_listing() );
    }
);