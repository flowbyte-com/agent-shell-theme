#!/usr/bin/env php
<?php
/**
 * A fake headless browser that speaks the DevTools Protocol.
 *
 * ## Why there are two fakes
 *
 * The original fake (`tests/fake-browser.php`) is a shell script: it
 * accepts `--screenshot=<path>`, writes a PNG there and exits. That is
 * enough for the *window* capture path, and the suites that exercise the
 * capture lock, the wall-clock deadline and the profile isolation use it.
 *
 * It is not enough for the *DevTools* capture path, which is now the only
 * path the screenshot tool uses — a capture that cannot be driven through
 * the DevTools Protocol is refused rather than silently downgraded to a
 * path that cannot enforce the request policy. So this fake speaks the
 * protocol: it publishes a DevTools port, accepts the WebSocket, and
 * answers the commands a capture makes.
 *
 * It is also, deliberately, a *test double for the interception*: it can be
 * told to pause a request, which is how the interception is tested without
 * a browser at all.
 *
 * ## What it answers
 *
 *   Target.createTarget             a target id
 *   Target.attachToTarget           a session id
 *   Target.setAutoAttach            nothing
 *   Page.enable                     nothing
 *   Page.getFrameTree               the main frame's id
 *   Emulation.setDeviceMetricsOverride  the viewport it was given
 *   Page.navigate                   a frame id, then Page.loadEventFired
 *   Runtime.evaluate                the viewport, as JSON
 *   Page.getLayoutMetrics           the content size
 *   Page.captureScreenshot          a PNG, as base64
 *   Fetch.enable                    nothing
 *   Fetch.continueRequest           nothing
 *   Fetch.failRequest               nothing
 *
 * ## The environment it reads
 *
 *   AGENTSHELL_FAKE_PNG_BYTES     the size of the PNG body (default 200)
 *   AGENTSHELL_FAKE_PNG_SIZE      WxH — the dimensions the PNG reports
 *   AGENTSHELL_FAKE_CDP_PAUSE     a JSON file naming requests to pause,
 *                                 as [{"url": "...", "resourceType": "..."}]
 *   AGENTSHELL_FAKE_CDP_LOG       a file to append the conversation to
 */

// The protocol client is loaded for its framing functions. The plugin's
// files guard themselves with ABSPATH, which is WordPress's way of saying
// "do not load me directly"; this is a test double, so the guard is
// satisfied rather than tripped.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

require_once __DIR__ . '/../agentshell-mcp/includes/class-cdp.php';

use AgentShell_MCP\CDP;

/**
 * The arguments this process was started with.
 *
 * @param array $argv The raw argv.
 * @return array<string,string>
 */
function fake_cdp_args( array $argv ) {
    $out = array();
    foreach ( $argv as $arg ) {
        if ( 0 === strpos( $arg, '--' ) && false !== strpos( $arg, '=' ) ) {
            list( $key, $value ) = explode( '=', substr( $arg, 2 ), 2 );
            $out[ $key ] = $value;
        }
    }
    return $out;
}

/**
 * A PNG of a chosen size, with a real IHDR chunk.
 *
 * @param int $width  The width the PNG reports.
 * @param int $height The height the PNG reports.
 * @param int $bytes  The total size of the file.
 * @return string
 */
function fake_cdp_png( $width, $height, $bytes ) {
    // The same bytes the window fake writes, so a suite that checks
    // "the bytes the browser produced" sees one answer, not two.
    $body  = "\x89PNG\r\n\x1a\n";
    $body .= pack( 'N', 13 ) . 'IHDR';
    $body .= pack( 'N', $width ) . pack( 'N', $height );
    $padding = $bytes - 16;
    if ( $padding > 0 ) {
        $body .= str_repeat( 'X', $padding );
    }
    return $body;
}

/**
 * The PNG this fake will hand back.
 *
 * @param int $width  The viewport width the capture asked for.
 * @param int $height The viewport height the capture asked for.
 * @return string
 */
function fake_cdp_image( $width, $height ) {
    $size = (int) ( getenv( 'AGENTSHELL_FAKE_PNG_BYTES' ) ?: 200 );
    $dims = (string) getenv( 'AGENTSHELL_FAKE_PNG_SIZE' );
    if ( '' !== $dims && false !== strpos( $dims, 'x' ) ) {
        list( $w, $h ) = explode( 'x', $dims, 2 );
        $width  = (int) $w;
        $height = (int) $h;
    }
    if ( $width <= 0 ) {
        $width = 800;
    }
    if ( $height <= 0 ) {
        $height = 600;
    }
    return fake_cdp_png( $width, $height, $size );
}

/**
 * Send a JSON message to the client.
 *
 * @param resource $socket The socket.
 * @param array    $message The message.
 */
function fake_cdp_send( $socket, array $message ) {
    $payload = json_encode( $message );
    if ( ! is_string( $payload ) ) {
        return;
    }
    @fwrite( $socket, CDP::frame( $payload, 1, '', false ) );
}

/**
 * The main loop of one connection.
 *
 * @param resource $socket The socket.
 * @param array    $state  The fake's state.
 */
function fake_cdp_serve( $socket, array &$state, $buffer = '' ) {
    while ( true ) {
        $chunk = @fread( $socket, 65536 );
        if ( false === $chunk || '' === $chunk ) {
            $meta = stream_get_meta_data( $socket );
            if ( ! empty( $meta['timed_out'] ) ) {
                continue;
            }
            return;
        }
        $buffer .= $chunk;
        $parsed = CDP::parse_frames( $buffer );
        $buffer = $parsed['rest'];
        foreach ( $parsed['messages'] as $frame ) {
            if ( 8 === $frame['opcode'] ) {
                return;
            }
            if ( 1 !== $frame['opcode'] ) {
                continue;
            }
            $message = json_decode( $frame['payload'], true );
            if ( ! is_array( $message ) ) {
                continue;
            }
            fake_cdp_handle( $socket, $message, $state );
        }
    }
}

/**
 * Answer one command.
 *
 * @param resource $socket  The socket.
 * @param array    $message The command.
 * @param array    $state   The fake's state.
 */
function fake_cdp_handle( $socket, array $message, array &$state ) {
    $id     = isset( $message['id'] ) ? (int) $message['id'] : 0;
    $method = (string) ( $message['method'] ?? '' );
    $params = (array) ( $message['params'] ?? array() );
    $session = (string) ( $message['sessionId'] ?? '' );

    // What the capture decided about a paused request. This is the record
    // a suite reads to find out whether a request was allowed or refused.
    if ( 'Fetch.enable' === $method ) {
        $state['fetch_enabled'] = true;
    }
    if ( 'Fetch.continueRequest' === $method || 'Fetch.failRequest' === $method ) {
        $log = (string) getenv( 'AGENTSHELL_FAKE_CDP_LOG' );
        if ( '' !== $log ) {
            @file_put_contents(
                $log,
                json_encode(
                    array(
                        'method'    => $method,
                        'requestId' => (string) ( $params['requestId'] ?? '' ),
                        'reason'    => (string) ( $params['errorReason'] ?? '' ),
                    )
                ) . "\n",
                FILE_APPEND
            );
        }
    }

    $result = array();
    switch ( $method ) {
        case 'Target.createTarget':
            $result = array( 'targetId' => 'fake-target-1' );
            break;
        case 'Target.attachToTarget':
            $result = array( 'sessionId' => 'fake-session-1' );
            break;
        case 'Page.getFrameTree':
            $result = array( 'frameTree' => array( 'frame' => array( 'id' => 'fake-frame-1' ) ) );
            break;
        case 'Emulation.setDeviceMetricsOverride':
            $state['width']  = (int) ( $params['width'] ?? 0 );
            $state['height'] = (int) ( $params['height'] ?? 0 );
            $result = array();
            break;
        case 'Page.navigate':
            $result = array( 'frameId' => 'fake-frame-1', 'loaderId' => 'fake-loader-1' );
            break;
        case 'Runtime.evaluate':
            $result = array(
                'result' => array(
                    'value' => json_encode(
                        array(
                            'w' => $state['width'],
                            'h' => $state['height'],
                        )
                    ),
                ),
            );
            break;
        case 'Page.getLayoutMetrics':
            $result = array(
                'cssContentSize' => array(
                    'width'  => (float) max( 1, $state['width'] ),
                    'height' => (float) max( 1, $state['height'] ),
                ),
            );
            break;
        case 'Page.captureScreenshot':
            $result = array( 'data' => base64_encode( fake_cdp_image( $state['width'], $state['height'] ) ) );
            break;
        default:
            $result = array();
            break;
    }

    if ( $id > 0 ) {
        $answer = array( 'id' => $id, 'result' => (object) $result );
        if ( '' !== $session ) {
            $answer['sessionId'] = $session;
        }
        fake_cdp_send( $socket, $answer );
    }

    // The load event, after the navigation has been answered.
    if ( 'Page.navigate' === $method ) {
        fake_cdp_send(
            $socket,
            array(
                'method'    => 'Page.loadEventFired',
                'params'    => (object) array( 'timestamp' => 1.0 ),
                'sessionId' => $session,
            )
        );
    }

    // The requests the test wants paused. They are emitted after the
    // navigation, which is when a real browser would make them — and only
    // when the interception has been installed, because a browser that was
    // never asked to intercept anything does not pause anything.
    if ( 'Page.navigate' === $method && ! empty( $state['pause'] ) && ! empty( $state['fetch_enabled'] ) ) {
        foreach ( $state['pause'] as $index => $paused ) {
            fake_cdp_send(
                $socket,
                array(
                    'method'    => 'Fetch.requestPaused',
                    'params'    => (object) array(
                        'requestId'    => 'fake-request-' . $index,
                        'request'      => (object) array(
                            'url'      => (string) ( $paused['url'] ?? '' ),
                            'method'  => 'GET',
                            'headers' => (object) array(),
                        ),
                        'frameId'      => (string) ( $paused['frameId'] ?? 'fake-frame-1' ),
                        'resourceType' => (string) ( $paused['resourceType'] ?? 'Document' ),
                    ),
                    'sessionId' => $session,
                )
            );
        }
    }
}

/**
 * The browser's HTTP endpoint: the version document.
 *
 * @param resource $socket The socket.
 */
function fake_cdp_http( $socket ) {
    $request = '';
    while ( false === strpos( $request, "\r\n\r\n" ) ) {
        $chunk = @fread( $socket, 4096 );
        if ( false === $chunk || '' === $chunk ) {
            $meta = stream_get_meta_data( $socket );
            if ( ! empty( $meta['timed_out'] ) ) {
                continue;
            }
            return;
        }
        $request .= $chunk;
        if ( strlen( $request ) > 65536 ) {
            return;
        }
    }
    if ( false !== strpos( $request, 'GET /json/version' ) ) {
        $body = json_encode(
            array(
                'Browser'              => 'FakeChrome/1.0',
                'Protocol-Version'     => '1.3',
                'webSocketDebuggerUrl' => 'ws://127.0.0.1:' . $GLOBALS['fake_cdp_port'] . '/devtools/browser/fake',
            )
        );
        @fwrite(
            $socket,
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                . strlen( $body ) . "\r\nConnection: close\r\n\r\n" . $body
        );
        return;
    }
    // The WebSocket upgrade. Whatever came after the handshake in the same
    // read is the first frame, and it must not be lost.
    if ( preg_match( '/Sec-WebSocket-Key:\s*(\S+)/i', $request, $matches ) ) {
        $accept = CDP::expected_accept( $matches[1] );
        @fwrite(
            $socket,
            "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . 'Sec-WebSocket-Accept: ' . $accept . "\r\n\r\n"
        );
        $rest = substr( $request, strpos( $request, "\r\n\r\n" ) + 4 );
        fake_cdp_serve( $socket, $GLOBALS['fake_cdp_state'], $rest );
    }
}

$args    = fake_cdp_args( $argv );
$profile = (string) ( $args['user-data-dir'] ?? '' );
if ( '' === $profile ) {
    fwrite( STDERR, "the fake CDP browser needs a --user-data-dir\n" );
    exit( 2 );
}
if ( ! is_dir( $profile ) ) {
    @mkdir( $profile, 0700, true );
}

$state = array(
    'width'  => 800,
    'height' => 600,
    'pause'  => array(),
);
$pause_file = (string) getenv( 'AGENTSHELL_FAKE_CDP_PAUSE' );
if ( '' !== $pause_file && is_readable( $pause_file ) ) {
    $decoded = json_decode( (string) file_get_contents( $pause_file ), true );
    if ( is_array( $decoded ) ) {
        $state['pause'] = $decoded;
    }
}
$GLOBALS['fake_cdp_state'] = &$state;

$errno  = 0;
$errstr = '';
$server = @stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
if ( ! $server ) {
    fwrite( STDERR, "the fake CDP browser could not listen: " . $errstr . "\n" );
    exit( 3 );
}
$name = (string) stream_socket_get_name( $server, false );
$port = (int) substr( $name, strrpos( $name, ':' ) + 1 );
$GLOBALS['fake_cdp_port'] = $port;

// The browser publishes its port in the profile, exactly as Chromium does.
@file_put_contents( $profile . '/DevToolsActivePort', $port . "\n/devtools/browser/fake" );

while ( true ) {
    $client = @stream_socket_accept( $server, 30 );
    if ( ! $client ) {
        continue;
    }
    stream_set_timeout( $client, 30 );
    fake_cdp_http( $client );
    @fclose( $client );
}
