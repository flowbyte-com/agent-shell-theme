<?php
/**
 * The interception: what the capture answers when the browser pauses a
 * request.
 *
 * ## The property under test
 *
 * The DevTools Protocol's Fetch domain pauses every request a page makes
 * and waits for an answer. The answer is the whole control: a request the
 * policy permits is continued, and a request it does not permit is failed
 * with `BlockedByClient` — *before the request is sent*. A capture that
 * answers "allowed" for a destination it should not reach has no control
 * at all, however carefully the rest of the code is written.
 *
 * This suite drives the capture against a fake browser that pauses two
 * requests — one to a destination inside the policy and one to a
 * destination outside it — and reads back what the capture answered.
 *
 * It is fast, and it is not a substitute for the live suite: the live
 * suite (`screenshot-request-policy-live-test.php`) proves that a real
 * browser really does not send the request. This suite proves that the
 * answer the capture gives is the right one, which is what makes the live
 * result explicable rather than lucky.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fake-browser.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-cdp.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-screenshot.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-screenshot-cdp.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-browser-request-policy.php';

use AgentShell_MCP\Browser_Request_Policy;
use AgentShell_MCP\Screenshot;
use AgentShell_MCP\Screenshot_CDP;

echo "screenshot request interception tests\n";

/**
 * Run a capture against the fake browser, with two paused requests.
 *
 * @param array $paused The requests to pause: url, resourceType.
 * @return array{result:array,decisions:array}
 */
function sri_capture( array $paused ) {
    $work = sys_get_temp_dir() . '/agentshell-intercept-' . getmypid() . '-' . substr( md5( sri_json( $paused ) ), 0, 8 );
    @mkdir( $work, 0700, true );

    $pause_file = $work . '/pause.json';
    $log_file   = $work . '/decisions.log';
    file_put_contents( $pause_file, sri_json( $paused ) );
    file_put_contents( $log_file, '' );

    putenv( 'AGENTSHELL_FAKE_CDP_PAUSE=' . $pause_file );
    putenv( 'AGENTSHELL_FAKE_CDP_LOG=' . $log_file );

    $browser = agentshell_fake_cdp_browser_path();
    $policy  = new Browser_Request_Policy(
        array(
            array( 'scheme' => 'http', 'host' => 'site.test', 'port' => 80, 'raw' => 'http://site.test:80' ),
        )
    );
    $profile = Screenshot::create_profile_dir();
    $file    = $work . '/shot.png';
    try {
        $result = Screenshot_CDP::capture( $browser, 'http://site.test/page', 800, 600, $file, $profile, 30, false, $policy );
    } finally {
        Screenshot::destroy_profile_dir( $profile );
        putenv( 'AGENTSHELL_FAKE_CDP_PAUSE' );
        putenv( 'AGENTSHELL_FAKE_CDP_LOG' );
    }

    $decisions = array();
    foreach ( explode( "\n", trim( (string) @file_get_contents( $log_file ) ) ) as $line ) {
        if ( '' === trim( $line ) ) {
            continue;
        }
        $decoded = json_decode( $line, true );
        if ( is_array( $decoded ) ) {
            $decisions[] = $decoded;
        }
    }
    @unlink( $pause_file );
    @unlink( $log_file );
    @unlink( $file );
    @rmdir( $work );

    return array( 'result' => $result, 'decisions' => $decisions );
}

/**
 * JSON encoding, without depending on WordPress.
 *
 * @param mixed $value The value.
 * @return string
 */
function sri_json( $value ) {
    return (string) json_encode( $value );
}

Agentshell_Test::run(
    'a request inside the policy is continued',
    function () {
        $out = sri_capture(
            array(
                array( 'url' => 'http://site.test/asset.png', 'resourceType' => 'Image' ),
            )
        );
        $continued = 0;
        foreach ( $out['decisions'] as $decision ) {
            if ( 'Fetch.continueRequest' === $decision['method'] ) {
                $continued++;
            }
        }
        Agentshell_Test::assert_equals(
            1,
            $continued,
            'a same-origin request must be continued: ' . json_encode( $out['decisions'] )
        );
        Agentshell_Test::assert_equals(
            array(),
            (array) ( $out['result']['blocked'] ?? array() ),
            'nothing must be reported as refused'
        );
    }
);

Agentshell_Test::run(
    'a request outside the policy is failed, not continued',
    function () {
        $out = sri_capture(
            array(
                array( 'url' => 'http://evil.test/secret.php', 'resourceType' => 'Document' ),
            )
        );
        $failed = 0;
        $continued = 0;
        foreach ( $out['decisions'] as $decision ) {
            if ( 'Fetch.failRequest' === $decision['method'] ) {
                $failed++;
            }
            if ( 'Fetch.continueRequest' === $decision['method'] ) {
                $continued++;
            }
        }
        Agentshell_Test::assert_equals(
            1,
            $failed,
            'the request must be failed: ' . json_encode( $out['decisions'] )
        );
        Agentshell_Test::assert_equals(
            0,
            $continued,
            'a refused request must never be continued: ' . json_encode( $out['decisions'] )
        );
        foreach ( $out['decisions'] as $decision ) {
            if ( 'Fetch.failRequest' === $decision['method'] ) {
                Agentshell_Test::assert_equals(
                    'BlockedByClient',
                    (string) $decision['reason'],
                    'the refusal must say the client refused it'
                );
            }
        }
    }
);

Agentshell_Test::run(
    'a refused request is reported, by host and by kind',
    function () {
        $out = sri_capture(
            array(
                array( 'url' => 'http://169.254.169.254/latest/meta-data/', 'resourceType' => 'Image' ),
            )
        );
        $blocked = (array) ( $out['result']['blocked'] ?? array() );
        Agentshell_Test::assert_equals( 1, count( $blocked ), 'the refusal must be reported: ' . json_encode( $blocked ) );
        Agentshell_Test::assert_equals( '169.254.169.254', (string) ( $blocked[0]['host'] ?? '' ) );
        Agentshell_Test::assert_equals( 'metadata', (string) ( $blocked[0]['category'] ?? '' ) );
        Agentshell_Test::assert_equals( 'Image', (string) ( $blocked[0]['resource_type'] ?? '' ) );
    }
);

Agentshell_Test::run(
    'a refused request is reported without its path and without its query',
    function () {
        $out = sri_capture(
            array(
                array( 'url' => 'http://site.test/../secret?agentshell_tx_key=SUPERSECRETVALUE', 'resourceType' => 'Image' ),
            )
        );
        $reported = json_encode( (array) ( $out['result']['blocked'] ?? array() ) );
        Agentshell_Test::assert_false(
            false !== strpos( $reported, 'SUPERSECRETVALUE' ),
            'a credential must never be reported: ' . $reported
        );
    }
);

Agentshell_Test::run(
    'the page itself asking to leave is refused, and the capture says so',
    function () {
        $out = sri_capture(
            array(
                array( 'url' => 'http://evil.test/secret.php', 'resourceType' => 'Document', 'frameId' => 'fake-frame-1' ),
            )
        );
        Agentshell_Test::assert_true(
            empty( $out['result']['ok'] ),
            'a page that is not this site must not be captured: ' . json_encode( $out['result'] )
        );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $out['result']['error'] ?? '' ), 'leave this site' ),
            'the refusal must say the page asked to leave: ' . (string) ( $out['result']['error'] ?? '' )
        );
    }
);

Agentshell_Test::run(
    'the same host on a different port is refused',
    function () {
        $out = sri_capture(
            array(
                array( 'url' => 'http://site.test:8080/secret.php', 'resourceType' => 'Document' ),
            )
        );
        $failed = 0;
        foreach ( $out['decisions'] as $decision ) {
            if ( 'Fetch.failRequest' === $decision['method'] ) {
                $failed++;
            }
        }
        Agentshell_Test::assert_equals(
            1,
            $failed,
            'a different port on the same host is a different origin and must be refused: '
                . json_encode( $out['decisions'] )
        );
    }
);

Agentshell_Test::run(
    'a request from inside the page is refused without refusing the page',
    function () {
        $out = sri_capture(
            array(
                array( 'url' => 'http://evil.test/pixel.png', 'resourceType' => 'Image', 'frameId' => 'fake-frame-2' ),
            )
        );
        Agentshell_Test::assert_true(
            ! empty( $out['result']['ok'] ),
            'the capture itself must still succeed: ' . json_encode( $out['result'] )
        );
        $blocked = (array) ( $out['result']['blocked'] ?? array() );
        Agentshell_Test::assert_equals( 1, count( $blocked ), 'the refusal must be reported' );
        Agentshell_Test::assert_false(
            ! empty( $blocked[0]['is_main_frame'] ),
            'a request from inside the page is not the page'
        );
    }
);
