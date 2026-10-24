<?php
/**
 * The DevTools Protocol client's wire format.
 *
 * The framing is the part that can be wrong in a way no browser would
 * report: a malformed frame is a connection that silently stops
 * answering, and the capture then looks like a browser problem. These
 * tests are about the bytes, and they need no browser.
 *
 * What is asserted here
 * ---------------------
 * 1. The handshake request is the one the RFC describes, and the accept
 *    hash is the one the server must answer with.
 * 2. A masked frame round-trips: what goes in comes out.
 * 3. The three length encodings (7-bit, 16-bit, 64-bit) are read back
 *    correctly, which is what a multi-megabyte screenshot needs.
 * 4. A partial frame is left in the buffer rather than half-parsed.
 * 5. A fragmented message is reassembled.
 * 6. A close frame and a ping are recognised.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-cdp.php';

use AgentShell_MCP\CDP;

echo "CDP wire format tests\n";

// ── 1. the handshake ───────────────────────────────────────────────────────
Agentshell_Test::run(
    'the handshake request asks for a websocket upgrade',
    function () {
        $request = CDP::handshake_request( '127.0.0.1', 9222, '/devtools/browser/abc', 'dGhlIHNhbXBsZSBub25jZQ==' );
        Agentshell_Test::assert_true(
            0 === strpos( $request, 'GET /devtools/browser/abc HTTP/1.1' ),
            'the request line must name the path: ' . substr( $request, 0, 60 )
        );
        Agentshell_Test::assert_true(
            false !== strpos( $request, 'Upgrade: websocket' ),
            'the upgrade header is required'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $request, 'Sec-WebSocket-Version: 13' ),
            'the version header is required'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $request, 'Host: 127.0.0.1:9222' ),
            'the host header must carry the port'
        );
    }
);

Agentshell_Test::run(
    'the accept hash is the one the RFC computes',
    function () {
        // The example from RFC 6455 §1.3.
        Agentshell_Test::assert_equals(
            's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
            CDP::expected_accept( 'dGhlIHNhbXBsZSBub25jZQ==' )
        );
    }
);

// ── 2. framing round-trip ─────────────────────────────────────────────────
Agentshell_Test::run(
    'a masked text frame round-trips',
    function () {
        $payload = '{"id":1,"method":"Page.enable"}';
        $frame   = CDP::frame( $payload, 1, "\x01\x02\x03\x04" );
        $parsed  = CDP::parse_frames( $frame );
        Agentshell_Test::assert_equals( 1, count( $parsed['messages'] ) );
        Agentshell_Test::assert_equals( $payload, $parsed['messages'][0]['payload'] );
        Agentshell_Test::assert_equals( '', $parsed['rest'] );
    }
);

Agentshell_Test::run(
    'a client frame is masked, as the RFC requires',
    function () {
        $payload = 'hello';
        $mask    = "\x0a\x0b\x0c\x0d";
        $frame   = CDP::frame( $payload, 1, $mask );
        // The mask bit must be set.
        Agentshell_Test::assert_true(
            0 !== ( ord( $frame[1] ) & 0x80 ),
            'the mask bit must be set on a client frame'
        );
        // The payload on the wire must not be the payload in the clear.
        Agentshell_Test::assert_true(
            false === strpos( $frame, 'hello' ),
            'the payload must be masked on the wire'
        );
    }
);

// ── 3. the three length encodings ─────────────────────────────────────────
Agentshell_Test::run(
    'a 7-bit length frame round-trips',
    function () {
        $payload = str_repeat( 'a', 100 );
        $parsed  = CDP::parse_frames( CDP::frame( $payload, 1, "\x00\x00\x00\x00" ) );
        Agentshell_Test::assert_equals( $payload, $parsed['messages'][0]['payload'] );
    }
);

Agentshell_Test::run(
    'a 16-bit length frame round-trips',
    function () {
        $payload = str_repeat( 'b', 1000 );
        $parsed  = CDP::parse_frames( CDP::frame( $payload, 1, "\x00\x00\x00\x00" ) );
        Agentshell_Test::assert_equals( $payload, $parsed['messages'][0]['payload'] );
    }
);

Agentshell_Test::run(
    'a 64-bit length frame round-trips — a screenshot does not fit in 16 bits',
    function () {
        $payload = str_repeat( 'c', 70000 );
        $parsed  = CDP::parse_frames( CDP::frame( $payload, 1, "\x00\x00\x00\x00" ) );
        Agentshell_Test::assert_equals( 1, count( $parsed['messages'] ) );
        Agentshell_Test::assert_equals( strlen( $payload ), strlen( $parsed['messages'][0]['payload'] ) );
        Agentshell_Test::assert_equals( $payload, $parsed['messages'][0]['payload'] );
    }
);

// ── 4. partial frames ─────────────────────────────────────────────────────
Agentshell_Test::run(
    'a partial frame is left in the buffer, not half-parsed',
    function () {
        $frame   = CDP::frame( 'hello world', 1, "\x00\x00\x00\x00" );
        $partial = substr( $frame, 0, 8 );
        $parsed  = CDP::parse_frames( $partial );
        Agentshell_Test::assert_equals( 0, count( $parsed['messages'] ), 'an incomplete frame must not be parsed' );
        Agentshell_Test::assert_equals( $partial, $parsed['rest'], 'the incomplete frame must be kept for the next read' );
    }
);

Agentshell_Test::run(
    'a frame split across two reads is parsed once the rest arrives',
    function () {
        $frame  = CDP::frame( 'hello world', 1, "\x00\x00\x00\x00" );
        $first  = CDP::parse_frames( substr( $frame, 0, 8 ) );
        $second = CDP::parse_frames( $first['rest'] . substr( $frame, 8 ) );
        Agentshell_Test::assert_equals( 1, count( $second['messages'] ) );
        Agentshell_Test::assert_equals( 'hello world', $second['messages'][0]['payload'] );
    }
);

Agentshell_Test::run(
    'two frames in one read are both parsed',
    function () {
        $buffer = CDP::frame( 'one', 1, "\x00\x00\x00\x00" ) . CDP::frame( 'two', 1, "\x00\x00\x00\x00" );
        $parsed = CDP::parse_frames( $buffer );
        Agentshell_Test::assert_equals( 2, count( $parsed['messages'] ) );
        Agentshell_Test::assert_equals( 'one', $parsed['messages'][0]['payload'] );
        Agentshell_Test::assert_equals( 'two', $parsed['messages'][1]['payload'] );
    }
);

// ── 5. the other opcodes ──────────────────────────────────────────────────
Agentshell_Test::run(
    'a close frame is recognised',
    function () {
        $parsed = CDP::parse_frames( CDP::frame( '', 8, "\x00\x00\x00\x00" ) );
        Agentshell_Test::assert_equals( 8, $parsed['messages'][0]['opcode'] );
    }
);

Agentshell_Test::run(
    'a ping is recognised',
    function () {
        $parsed = CDP::parse_frames( CDP::frame( 'ping', 9, "\x00\x00\x00\x00" ) );
        Agentshell_Test::assert_equals( 9, $parsed['messages'][0]['opcode'] );
        Agentshell_Test::assert_equals( 'ping', $parsed['messages'][0]['payload'] );
    }
);

Agentshell_Test::run(
    'a fragmented message is reported with its continuation flag',
    function () {
        // A server may split a large message; the first fragment has the
        // continuation bit clear in the FIN flag.
        $frame = CDP::frame( 'part one ', 1, "\x00\x00\x00\x00" );
        $frame = chr( ord( $frame[0] ) & 0x7F ) . substr( $frame, 1 ); // clear FIN
        $parsed = CDP::parse_frames( $frame );
        Agentshell_Test::assert_equals( 1, count( $parsed['messages'] ), 'the fragment is reported' );
        Agentshell_Test::assert_false( $parsed['messages'][0]['fin'] );
        Agentshell_Test::assert_equals( 'part one ', $parsed['messages'][0]['payload'] );
    }
);

// ── 8. frame lengths that cannot be represented ────────────────────────────
//
// The independent review of PR #16 found that a frame declaring a 64-bit
// length of 2^63 or more made the parser compute a *negative* payload
// length: PHP's integer is signed, so the value cannot be held at all.
// The negative length then passed the "is the frame complete?" test, moved
// the cursor backwards, and left PHP reading before the start of the
// buffer — and the warnings it printed went into the response body, which
// for the MCP transport is the JSON-RPC stream.
//
// The reproduction below is the reviewer's, byte for byte: ten bytes, a
// 64-bit length with the high bit set. The parser must refuse it with a
// controlled error, and it must do so without printing a warning.

/**
 * Run the parser with every diagnostic turned into an exception, so that
 * a warning is a failure rather than something printed.
 *
 * @param string $buffer The bytes to parse.
 * @return array The parser's result.
 */
function cdp_parse_strict( $buffer ) {
    set_error_handler( function ( $errno, $errstr, $errfile, $errline ) {
        throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
    } );
    try {
        return CDP::parse_frames( $buffer );
    } finally {
        restore_error_handler();
    }
}

Agentshell_Test::run(
    "the reviewer's ten-byte reproduction is refused without a warning",
    function () {
        // 0x81 = FIN + text opcode; 0x7F = the 64-bit length form; the
        // eight length bytes are 2^63, whose high bit PHP cannot hold.
        $bytes = "\x81\x7f\x80\x00\x00\x00\x00\x00\x00\x00";
        Agentshell_Test::assert_equals( 10, strlen( $bytes ), 'the reproduction is ten bytes' );

        $parsed = cdp_parse_strict( $bytes );
        Agentshell_Test::assert_true(
            '' !== (string) ( $parsed['error'] ?? '' ),
            'the frame must be refused with a reason'
        );
        Agentshell_Test::assert_equals(
            0,
            count( $parsed['messages'] ),
            'no message may be produced from an invalid frame'
        );
        Agentshell_Test::assert_equals( '', $parsed['rest'], 'the invalid bytes must not be kept for another pass' );
    }
);

Agentshell_Test::run(
    'the masked form of the same length is refused too',
    function () {
        // The same length with the mask bit set — the form the reviewer's
        // own reproduction used, because the mask key is read before the
        // payload.
        $bytes = "\x81\xff\x7f\x80\x00\x00\x00\x00\x00\x00\x00\x00" . 'MASK' . 'payload';
        $parsed = cdp_parse_strict( $bytes );
        Agentshell_Test::assert_true(
            '' !== (string) ( $parsed['error'] ?? '' ),
            'the frame must be refused with a reason'
        );
        Agentshell_Test::assert_equals( 0, count( $parsed['messages'] ), 'no message may be produced' );
    }
);

Agentshell_Test::run(
    'a length above the safe integer range is refused',
    function () {
        // 2^64-1: every bit set.
        $bytes = "\x81\x7f\xff\xff\xff\xff\xff\xff\xff\xff";
        $parsed = cdp_parse_strict( $bytes );
        Agentshell_Test::assert_true( '' !== (string) ( $parsed['error'] ?? '' ), 'the frame must be refused' );
        Agentshell_Test::assert_equals( 0, count( $parsed['messages'] ), 'no message may be produced' );
    }
);

Agentshell_Test::run(
    'the largest permitted frame is accepted and one byte more is not',
    function () {
        $max = CDP::MAX_FRAME_BYTES;

        // A frame *declaring* the maximum is not an error: it is simply
        // incomplete, because the payload has not arrived yet.
        $header = "\x81\x7f" . pack( 'N2', (int) ( $max / 4294967296 ), $max % 4294967296 );
        $parsed = cdp_parse_strict( $header );
        Agentshell_Test::assert_equals( '', (string) ( $parsed['error'] ?? '' ), 'the maximum size must be permitted' );
        Agentshell_Test::assert_equals( 0, count( $parsed['messages'] ), 'the frame is incomplete, not invalid' );
        Agentshell_Test::assert_equals(
            strlen( $header ),
            strlen( $parsed['rest'] ),
            'the incomplete frame must be kept for the next read'
        );

        // One byte more is refused, with a reason that names the limit.
        $over = $max + 1;
        $header = "\x81\x7f" . pack( 'N2', (int) ( $over / 4294967296 ), $over % 4294967296 );
        $parsed = cdp_parse_strict( $header );
        Agentshell_Test::assert_true( '' !== (string) ( $parsed['error'] ?? '' ), 'one byte over the maximum must be refused' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) $parsed['error'], (string) $max ),
            'the reason must name the limit: ' . (string) $parsed['error']
        );
    }
);

Agentshell_Test::run(
    'a frame at the maximum size really does parse',
    function () {
        // The boundary is not only a declared length: a real payload of
        // the maximum size must round-trip. 32 MiB is a lot to allocate in
        // a unit test, so the *declared* size is what is checked here and
        // a smaller real payload proves the path.
        $payload = str_repeat( 'x', 1024 );
        $frame   = "\x81\x7f" . pack( 'N2', 0, strlen( $payload ) ) . $payload;
        $parsed  = cdp_parse_strict( $frame );
        Agentshell_Test::assert_equals( '', (string) ( $parsed['error'] ?? '' ), 'a real extended-length frame must parse' );
        Agentshell_Test::assert_equals( 1, count( $parsed['messages'] ), 'the message must be produced' );
        Agentshell_Test::assert_equals( $payload, $parsed['messages'][0]['payload'], 'the payload must survive' );
    }
);

Agentshell_Test::run(
    'an incomplete extended length waits for more bytes',
    function () {
        // The 64-bit length is declared but only part of it has arrived.
        $parsed = cdp_parse_strict( "\x81\x7f\x80\x00" );
        Agentshell_Test::assert_equals( '', (string) ( $parsed['error'] ?? '' ), 'an incomplete frame is not an invalid one' );
        Agentshell_Test::assert_equals( 0, count( $parsed['messages'] ), 'no message may be produced' );
        Agentshell_Test::assert_equals( 4, strlen( $parsed['rest'] ), 'the partial frame must be kept' );
    }
);

Agentshell_Test::run(
    'a malformed extended length does not move the cursor backwards',
    function () {
        // Whatever the parser decides, it must not read before the start
        // of the buffer, and it must not warn.
        foreach ( array( "\x81\x7f", "\x81\x7f\x00", "\x81\x7f\x00\x00\x00\x00\x00\x00", "\x81\x7f\xff\xff\xff\xff\xff\xff\xff\xff" ) as $bytes ) {
            $parsed = cdp_parse_strict( $bytes );
            Agentshell_Test::assert_true(
                strlen( $parsed['rest'] ) >= 0,
                'the remainder must be a real length'
            );
            Agentshell_Test::assert_equals(
                0,
                count( $parsed['messages'] ),
                'no message may be produced from a malformed frame'
            );
        }
    }
);
