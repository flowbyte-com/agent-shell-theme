<?php
/**
 * The request policy, against a real browser and two disposable sites.
 *
 * ## The defect this pins
 *
 * `agentshell_screenshot` checked that the *initial* URL was on the site's
 * own host and then let the browser do as it liked. Chromium follows
 * redirects, runs scripts, opens frames and loads subresources, and none of
 * those went through the check — so a page on the site's own host could
 * send the browser to another destination, and the *other* destination's
 * content would end up in the image returned to the caller. Reading the
 * page's final URL afterwards does not close that, because the request has
 * already been made by then.
 *
 * ## What is asserted here
 *
 * Two disposable sites are started on this machine: the *site*, which is
 * the origin the capture is pointed at, and the *secret*, which is the
 * destination the site tries to reach. Every request the secret receives is
 * logged.
 *
 * The property under test is the strong one: **the secret receives no
 * request at all**. Not "the secret's content is not in the image" — no
 * request is made, so the secret cannot tell that it was ever visited, and
 * a destination that logs the attempt sees nothing.
 *
 * The scenarios are the ways a page can make the browser go somewhere:
 *
 *   1. an HTTP 302 redirect
 *   2. an HTTP 301 redirect
 *   3. a meta refresh
 *   4. `location.href`
 *   5. `location.replace`
 *   6. `window.open`
 *   7. an `<img>`
 *   8. an `<iframe>`
 *   9. a `<script src>`
 *   10. `fetch()`
 *   11. the IPv6 loopback literal
 *   12. the cloud metadata address
 *   13. a private-network address
 *   14. another host entirely
 *
 * and the two that must keep working:
 *
 *   15. the site's own page
 *   16. the site's own public asset
 *
 * Scenario 10 is the one that separates the two layers. The secret is on
 * the *same host* as the site and on a different port, so the browser's
 * resolver rules — which match hosts, not ports — cannot stop it. Only the
 * origin-level interception can, and it does.
 *
 * The suite needs Chrome. Without one it skips, so it is safe to run
 * anywhere.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-cdp.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-screenshot.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-screenshot-cdp.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-browser-request-policy.php';

use AgentShell_MCP\Browser_Request_Policy;
use AgentShell_MCP\Screenshot;
use AgentShell_MCP\Screenshot_CDP;

echo "screenshot request policy (real browser, disposable sites)\n";

if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $len = 12, $special = true, $extra = false ) {
        return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
    }
}

/**
 * The browser to test with, or null.
 *
 * @return string|null
 */
function srp_browser() {
    if ( defined( 'AGENTSHELL_CHROME_BIN' ) && is_string( AGENTSHELL_CHROME_BIN ) && '' !== AGENTSHELL_CHROME_BIN ) {
        if ( false === strpos( AGENTSHELL_CHROME_BIN, 'fake-browser' )
            && false === strpos( AGENTSHELL_CHROME_BIN, 'fake-cdp-browser' )
            && ( is_executable( AGENTSHELL_CHROME_BIN ) || file_exists( AGENTSHELL_CHROME_BIN ) ) ) {
            return AGENTSHELL_CHROME_BIN;
        }
    }
    foreach ( array( 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser' ) as $binary ) {
        $path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null' ) );
        if ( '' !== $path && is_executable( $path ) ) {
            return $path;
        }
    }
    return null;
}

/**
 * A port nothing is listening on.
 *
 * @return int
 */
function srp_free_port() {
    $socket = @stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
    if ( ! $socket ) {
        return 0;
    }
    $name = (string) stream_socket_get_name( $socket, false );
    fclose( $socket );
    return (int) substr( $name, strrpos( $name, ':' ) + 1 );
}

/**
 * Start a PHP server and wait until it is listening.
 *
 * @param string $bind    The address to bind.
 * @param int    $port    The port.
 * @param string $router  The router script.
 * @param array  $env     Extra environment variables.
 * @return array{process:resource,handle:mixed}
 */
function srp_start_server( $bind, $port, $router, array $env = array() ) {
    $command = escapeshellarg( PHP_BINARY ) . ' -S ' . escapeshellarg( $bind . ':' . $port ) . ' ' . escapeshellarg( $router );
    $descriptors = array(
        0 => array( 'file', '/dev/null', 'r' ),
        1 => array( 'file', '/dev/null', 'a' ),
        2 => array( 'file', '/dev/null', 'a' ),
    );
    $pipes   = array();
    $process = proc_open( $command, $descriptors, $pipes, null, array_merge( getenv(), $env ) );
    if ( ! is_resource( $process ) ) {
        return array( 'process' => null, 'handle' => null );
    }
    for ( $i = 0; $i < 100; $i++ ) {
        $probe = @stream_socket_client( 'tcp://' . $bind . ':' . $port, $e1, $e2, 0.2 );
        if ( $probe ) {
            fclose( $probe );
            return array( 'process' => $process, 'handle' => $process );
        }
        usleep( 100000 );
    }
    return array( 'process' => $process, 'handle' => $process );
}

/**
 * Stop a server.
 *
 * @param mixed $process The process handle.
 */
function srp_stop_server( $process ) {
    if ( is_resource( $process ) ) {
        proc_terminate( $process, 9 );
        proc_close( $process );
    }
}

/**
 * The requests the secret received.
 *
 * @param string $log The log file.
 * @return string[]
 */
function srp_secret_requests( $log ) {
    if ( ! is_readable( $log ) ) {
        return array();
    }
    $raw = trim( (string) file_get_contents( $log ) );
    if ( '' === $raw ) {
        return array();
    }
    return array_values( array_filter( explode( "\n", $raw ) ) );
}

/**
 * The requests the site received.
 *
 * @param string $log The log file.
 * @return string[]
 */
function srp_site_requests( $log ) {
    if ( ! is_readable( $log ) ) {
        return array();
    }
    $raw = trim( (string) file_get_contents( $log ) );
    if ( '' === $raw ) {
        return array();
    }
    return array_values( array_filter( explode( "\n", $raw ) ) );
}

$browser = srp_browser();
if ( null === $browser ) {
    Agentshell_Test::run(
        'the request policy suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no Chrome or Chromium on this host' );
        }
    );
    echo "  (skipped: no Chrome or Chromium on this host)\n";
    return;
}

$work = sys_get_temp_dir() . '/agentshell-policy-' . getmypid();
@mkdir( $work, 0700, true );

// ── the two disposable sites ───────────────────────────────────────────────

$site_port   = srp_free_port();
$secret_port = srp_free_port();
if ( $site_port <= 0 || $secret_port <= 0 ) {
    Agentshell_Test::run( 'the disposable sites', function () {
        Agentshell_Test::assert_true( false, 'no free ports could be found' );
    } );
    return;
}

$secret_url = 'http://127.0.0.1:' . $secret_port . '/secret.php';

$site_router   = $work . '/site-router.php';
$secret_router = $work . '/secret-router.php';
$site_log      = $work . '/site.log';
$secret_log    = $work . '/secret.log';

file_put_contents(
    $site_router,
    '<?php' . "\n"
    . '$name = isset( $_GET["s"] ) ? (string) $_GET["s"] : "";' . "\n"
    . '$secret = getenv( "SRP_SECRET_URL" );' . "\n"
    . '$ipv6 = getenv( "SRP_SECRET_IPV6_URL" );' . "\n"
    . '$localhost = getenv( "SRP_SECRET_LOCALHOST_URL" );' . "\n"
    . 'file_put_contents( getenv( "SRP_SITE_LOG" ), $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND );' . "\n"
    . 'if ( "/asset.png" === $_SERVER["REQUEST_URI"] ) { header( "Content-Type: image/png" ); echo base64_decode( "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" ); return; }' . "\n"
    . 'if ( "redirect302" === $name ) { header( "Location: " . $secret, true, 302 ); echo "redirecting"; return; }' . "\n"
    . 'if ( "redirect301" === $name ) { header( "Location: " . $secret, true, 301 ); echo "redirecting"; return; }' . "\n"
    . 'if ( "meta" === $name ) { echo "<meta http-equiv=\"refresh\" content=\"0;url=" . $secret . "\">"; return; }' . "\n"
    . 'if ( "js-href" === $name ) { echo "<script>location.href=" . json_encode( $secret ) . ";</script>"; return; }' . "\n"
    . 'if ( "js-replace" === $name ) { echo "<script>location.replace(" . json_encode( $secret ) . ");</script>"; return; }' . "\n"
    . 'if ( "js-window-open" === $name ) { echo "<script>window.open(" . json_encode( $secret ) . ");</script>"; return; }' . "\n"
    . 'if ( "img" === $name ) { echo "<img src=\"" . $secret . "\" alt=\"\">"; return; }' . "\n"
    . 'if ( "iframe" === $name ) { echo "<iframe src=\"" . $secret . "\"></iframe>"; return; }' . "\n"
    . 'if ( "script" === $name ) { echo "<script src=\"" . $secret . "\"></script>"; return; }' . "\n"
    . 'if ( "fetch" === $name ) { echo "<script>fetch(" . json_encode( $secret ) . ");</script>"; return; }' . "\n"
    . 'if ( "ipv6" === $name ) { echo "<script>location.href=" . json_encode( $ipv6 ) . ";</script>"; return; }' . "\n"
    . 'if ( "link-local" === $name ) { echo "<img src=\"http://169.254.169.254/latest/meta-data/\" alt=\"\">"; return; }' . "\n"
    . 'if ( "private" === $name ) { echo "<img src=\"http://10.0.0.1/\" alt=\"\">"; return; }' . "\n"
    . 'if ( "public" === $name ) { echo "<iframe src=\"https://example.com/\"></iframe>"; return; }' . "\n"
    . 'if ( "asset" === $name ) { echo "<img src=\"/asset.png\" alt=\"\"><p>the site\'s own asset</p>"; return; }' . "\n"
    . 'if ( "subresources" === $name ) {'
    . '  echo "<img src=\"" . $secret . "\" alt=\"\">";'
    . '  echo "<iframe src=\"" . $secret . "\"></iframe>";'
    . '  echo "<script src=\"" . $secret . "\"></script>";'
    . '  echo "<script>fetch(" . json_encode( $secret ) . ");</script>";'
    . '  echo "<script>navigator.sendBeacon(" . json_encode( $secret ) . ");</script>";'
    . '  echo "<img src=\"" . $localhost . "\" alt=\"\">";'
    . '  return; }' . "\n"
    . 'if ( "offsite" === $name ) {'
    . '  echo "<img src=\"http://169.254.169.254/latest/meta-data/\" alt=\"\">";'
    . '  echo "<img src=\"http://10.0.0.1/\" alt=\"\">";'
    . '  echo "<iframe src=\"https://example.com/\"></iframe>";'
    . '  return; }' . "\n"
    . 'echo "<p>the site itself</p>";' . "\n"
);

file_put_contents(
    $secret_router,
    '<?php' . "\n"
    . 'file_put_contents( getenv( "SRP_SECRET_LOG" ), $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND );' . "\n"
    . 'header( "Content-Type: text/plain" );' . "\n"
    . 'echo "INTERNAL-ONLY-SECRET-VISIBLE";' . "\n"
);

@file_put_contents( $site_log, '' );
@file_put_contents( $secret_log, '' );

$site = srp_start_server(
    '127.0.0.1',
    $site_port,
    $site_router,
    array(
        'SRP_SITE_LOG'      => $site_log,
        'SRP_SECRET_URL'    => $secret_url,
        'SRP_SECRET_IPV6_URL' => 'http://[::1]:' . $secret_port . '/secret.php',
        // The same destination under another name for the same machine.
        'SRP_SECRET_LOCALHOST_URL' => 'http://localhost:' . $secret_port . '/secret.php',
    )
);

// The secret listens on the IPv6 wildcard, so it answers on 127.0.0.1 and
// on [::1] alike. That is what makes the IPv6 scenario meaningful: the
// destination really is reachable, and the policy is what stops it.
$secret = srp_start_server(
    '[::]',
    $secret_port,
    $secret_router,
    array( 'SRP_SECRET_LOG' => $secret_log )
);

$site_origin = 'http://127.0.0.1:' . $site_port;

register_shutdown_function(
    function () use ( $site, $secret, $work ) {
        srp_stop_server( $site['process'] );
        srp_stop_server( $secret['process'] );
        @unlink( $site_log );
        @unlink( $secret_log );
        @unlink( $site_router );
        @unlink( $secret_router );
        @rmdir( $work );
    }
);

/**
 * Capture a page, and report what came back.
 *
 * @param string $browser The browser.
 * @param string $url     The URL.
 * @param array  $policy  The policy's origins.
 * @return array
 */
function srp_capture( $browser, $url, array $origins ) {
    $policy  = new Browser_Request_Policy( $origins );
    $profile = Screenshot::create_profile_dir();
    $file    = sys_get_temp_dir() . '/agentshell-policy-shot-' . getmypid() . '-' . substr( md5( $url ), 0, 8 ) . '.png';
    @unlink( $file );
    try {
        $out = Screenshot_CDP::capture( $browser, $url, 1024, 768, $file, $profile, 40, false, $policy );
    } finally {
        Screenshot::destroy_profile_dir( $profile );
    }
    $out['file'] = $file;
    return $out;
}

$origins = array(
    array(
        'scheme' => 'http',
        'host'   => '127.0.0.1',
        'port'   => $site_port,
        'raw'    => $site_origin,
    ),
);

/**
 * The prohibited scenarios: how the page tries to leave.
 *
 * Each entry names the way it tries, whether the attempt is the *page*
 * itself (a document) or something inside it, and whether the browser is
 * expected to report the refusal. A popup is the odd one out: Chrome
 * blocks a popup that was not opened by a gesture, so the attempt may
 * never reach the policy at all. The destination is not reached either
 * way, which is the property that matters.
 */
$prohibited = array(
    array( 'redirect302', 'document', 'an HTTP 302 redirect', true ),
    array( 'redirect301', 'document', 'an HTTP 301 redirect', true ),
    array( 'meta', 'document', 'a meta refresh', true ),
    array( 'js-href', 'document', 'a JavaScript location.href', true ),
    array( 'js-replace', 'document', 'a JavaScript location.replace', true ),
    array( 'js-window-open', 'document', 'a JavaScript window.open', false ),
    array( 'ipv6', 'document', 'the IPv6 loopback literal', true ),
);

foreach ( $prohibited as $scenario ) {
    list( $name, $kind, $description, $reported ) = $scenario;

    Agentshell_Test::run(
        $description . ' never reaches the destination',
        function () use ( $browser, $site_origin, $origins, $name, $kind, $reported, $secret_log, $site_log ) {
            @file_put_contents( $secret_log, '' );

            $out = srp_capture( $browser, $site_origin . '/?s=' . $name, $origins );

            // The strong property: the destination was never asked.
            $received = srp_secret_requests( $secret_log );
            Agentshell_Test::assert_equals(
                array(),
                $received,
                'the destination must receive no request at all, not merely no content in the image'
            );

            $blocked = (array) ( $out['blocked'] ?? array() );

            if ( $reported ) {
                // And the refusal is reported, so a caller can see it.
                Agentshell_Test::assert_true(
                    ! empty( $blocked ),
                    'the refused destination must be reported: ' . json_encode( $out )
                );
            }

            if ( 'document' === $kind && $reported ) {
                Agentshell_Test::assert_true(
                    empty( $out['ok'] ),
                    'a page that is not this site must not be captured: ' . json_encode( $out )
                );
                Agentshell_Test::assert_true(
                    false !== strpos( (string) ( $out['error'] ?? '' ), 'leave this site' ),
                    'the refusal must say the page asked to leave: ' . (string) ( $out['error'] ?? '' )
                );
            }
        }
    );
}

Agentshell_Test::run(
    'a subresource of every kind never reaches the destination',
    function () use ( $browser, $site_origin, $origins, $secret_log ) {
        // One page that asks for the same destination four ways: an
        // image, a frame, a script and a fetch. One capture covers four
        // mechanisms, and the report must name all four — which is a
        // stronger assertion than four separate captures, because a
        // mechanism that was not refused is visible in the count.
        @file_put_contents( $secret_log, '' );

        $out = srp_capture( $browser, $site_origin . '/?s=subresources', $origins );

        Agentshell_Test::assert_equals(
            array(),
            srp_secret_requests( $secret_log ),
            'the destination must receive no request at all, not merely no content in the image'
        );

        $blocked = (array) ( $out['blocked'] ?? array() );
        Agentshell_Test::assert_equals(
            6,
            count( $blocked ),
            'all six requests must be refused and reported: ' . json_encode( $blocked )
        );
        $hosts = array();
        foreach ( $blocked as $violation ) {
            $hosts[] = (string) ( $violation['host'] ?? '' );
        }
        sort( $hosts );
        // Five of them name the destination by its address; the sixth
        // names it by the other name for the same machine. Both are
        // refused, and the difference between them is exactly the
        // difference between a host rule and an origin rule.
        Agentshell_Test::assert_equals(
            array( '127.0.0.1', '127.0.0.1', '127.0.0.1', '127.0.0.1', '127.0.0.1', 'localhost' ),
            $hosts,
            'every refused destination must be named: ' . json_encode( $blocked )
        );
        // The capture itself still succeeds: the page is the site's own.
        Agentshell_Test::assert_true(
            ! empty( $out['ok'] ),
            'the capture of the site must still succeed: ' . json_encode( $out )
        );
    }
);

Agentshell_Test::run(
    'a destination of every kind is refused, and named as the kind it is',
    function () use ( $browser, $site_origin, $origins ) {
        // Three destinations, three kinds: the cloud metadata address, a
        // private-network address and another host entirely.
        $out = srp_capture( $browser, $site_origin . '/?s=offsite', $origins );

        $blocked = (array) ( $out['blocked'] ?? array() );
        $by_host = array();
        foreach ( $blocked as $violation ) {
            $by_host[ (string) ( $violation['host'] ?? '' ) ] = (string) ( $violation['category'] ?? '' );
        }
        Agentshell_Test::assert_equals(
            'metadata',
            $by_host['169.254.169.254'] ?? '(not refused)',
            'the cloud metadata address must be refused and named as one'
        );
        Agentshell_Test::assert_equals(
            'private',
            $by_host['10.0.0.1'] ?? '(not refused)',
            'a private-network address must be refused and named as one'
        );
        Agentshell_Test::assert_equals(
            'cross_host',
            $by_host['example.com'] ?? '(not refused)',
            'another host must be refused and named as one'
        );
    }
);

Agentshell_Test::run(
    'the site\'s own page is captured',
    function () use ( $browser, $site_origin, $origins, $secret_log ) {
        @file_put_contents( $secret_log, '' );
        $out = srp_capture( $browser, $site_origin . '/?s=plain', $origins );
        Agentshell_Test::assert_true( ! empty( $out['ok'] ), 'the capture must succeed: ' . json_encode( $out ) );
        Agentshell_Test::assert_equals( array(), srp_secret_requests( $secret_log ) );
        Agentshell_Test::assert_equals( array(), (array) ( $out['blocked'] ?? array() ), 'nothing must be refused' );
    }
);

Agentshell_Test::run(
    'the site\'s own public asset is fetched, not refused',
    function () use ( $browser, $site_origin, $origins, $secret_log, $site_log ) {
        @file_put_contents( $secret_log, '' );
        @file_put_contents( $site_log, '' );
        $out = srp_capture( $browser, $site_origin . '/?s=asset', $origins );
        Agentshell_Test::assert_true( ! empty( $out['ok'] ), 'the capture must succeed: ' . json_encode( $out ) );
        Agentshell_Test::assert_equals(
            array(),
            (array) ( $out['blocked'] ?? array() ),
            'a same-origin asset must not be refused'
        );
        // The asset was really requested: the policy must not break the
        // page's own resources.
        $site_requests = srp_site_requests( $site_log );
        Agentshell_Test::assert_true(
            in_array( '/asset.png', $site_requests, true ),
            'the site\'s own asset must still be fetched: ' . implode( ',', $site_requests )
        );
    }
);

Agentshell_Test::run(
    'a preview credential on the site\'s own origin does not stop the capture',
    function () use ( $browser, $site_origin, $origins ) {
        $out = srp_capture(
            $browser,
            $site_origin . '/?s=plain&agentshell_tx_key=SUPERSECRETVALUE',
            $origins
        );
        Agentshell_Test::assert_true( ! empty( $out['ok'] ), 'an authenticated capture must succeed: ' . json_encode( $out ) );
        // Nothing the capture reports may carry the credential.
        $reported = json_encode( $out );
        Agentshell_Test::assert_false(
            false !== strpos( $reported, 'SUPERSECRETVALUE' ),
            'the credential must not appear in anything the capture reports'
        );
    }
);

Agentshell_Test::run(
    'the same host on a different port is refused',
    function () use ( $browser, $site_origin, $origins, $secret_log ) {
        @file_put_contents( $secret_log, '' );
        // The site is on 127.0.0.1:PORT_A and the destination is on
        // 127.0.0.1:PORT_B. A host-only rule would allow this; an origin
        // rule does not.
        $out = srp_capture( $browser, $site_origin . '/?s=fetch', $origins );
        Agentshell_Test::assert_equals(
            array(),
            srp_secret_requests( $secret_log ),
            'a different port on the same host must not be reached'
        );
        $blocked = (array) ( $out['blocked'] ?? array() );
        Agentshell_Test::assert_true( ! empty( $blocked ), 'the refusal must be reported' );
    }
);

Agentshell_Test::run(
    'the capture is scoped to the site, and the site is what the browser may ask for',
    function () use ( $origins, $secret_url ) {
        $policy = new Browser_Request_Policy( $origins );
        $decision = $policy->evaluate( $secret_url );
        Agentshell_Test::assert_false( $decision['allow'], 'the destination must not be permitted' );
        Agentshell_Test::assert_equals( 'loopback', $decision['category'] );
    }
);
