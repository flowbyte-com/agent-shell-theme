<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A minimal Chrome DevTools Protocol client.
 *
 * Why this exists
 * ---------------
 * `--window-size` is not a viewport. Headless Chrome will not make a window
 * narrower than about 500 CSS pixels: a request for a 375-pixel viewport is
 * laid out at 500 pixels, the media queries evaluate at 500 pixels, and the
 * PNG that comes back is a *crop* of that wider layout — a picture of the
 * left-hand 375 pixels of a 500-pixel page. The screenshot looked narrow;
 * the page was not. (Measured on Chrome 156: `--window-size=375` gives
 * `window.innerWidth === 500`.)
 *
 * The only supported way to ask a browser for a viewport narrower than its
 * minimum window size is the DevTools Protocol's
 * `Emulation.setDeviceMetricsOverride`, which sets the *viewport* — the
 * width the layout and the media queries see — independently of the window.
 * That needs a WebSocket, and PHP has no WebSocket client, so this is one:
 * the handshake, the framing, and the CDP conversation, and nothing else.
 *
 * What it deliberately does not do
 * --------------------------------
 * - It does not log the URL. A preview credential rides in that URL, and the
 *   URL must not reach a log, an exception message or the audit trail.
 * - It does not reuse a profile. Every capture gets the throwaway profile
 *   the screenshot tool already uses, and that profile is removed
 *   afterwards.
 * - It does not print anything. stdout belongs to the MCP transport.
 *
 * The framing functions are pure, so the wire format can be tested without
 * a browser: `tests/cdp-test.php` does exactly that.
 */
class CDP {

    /**
     * The largest frame this parser will accept.
     *
     * A screenshot's base64 payload is the largest thing the browser
     * sends; 32 MiB covers a full-page capture of a very tall page with
     * room to spare, and it matches the connection's buffer cap. A frame
     * that claims to be larger is refused: the browser does not send one,
     * and accepting the claim would mean allocating whatever it asked
     * for.
     */
    const MAX_FRAME_BYTES = 33554432;
    /** The GUID the RFC requires in the handshake's accept computation. */
    const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * The HTTP request that opens a WebSocket.
     *
     * @param string $host The host to connect to.
     * @param int    $port The port.
     * @param string $path The request path.
     * @param string $key  The Sec-WebSocket-Key (base64 of 16 random bytes).
     * @return string The request bytes.
     */
    public static function handshake_request( $host, $port, $path, $key ) {
        return "GET " . $path . " HTTP/1.1\r\n"
            . "Host: " . $host . ':' . (int) $port . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: " . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
    }

    /**
     * The value the server must answer with, given the key we sent.
     *
     * @param string $key The Sec-WebSocket-Key we sent.
     * @return string The expected Sec-WebSocket-Accept.
     */
    public static function expected_accept( $key ) {
        return base64_encode( sha1( $key . self::WS_GUID, true ) );
    }

    /**
     * Encode a client→server frame.
     *
     * A client MUST mask every frame it sends (RFC 6455 §5.3). The mask is
     * four random bytes; the payload is XORed with them.
     *
     * @param string $payload The payload.
     * @param int    $opcode  1 for text, 8 for close, 9 for pong.
     * @param string $mask    Four bytes of mask.
     * @return string The frame.
     */
    public static function frame( $payload, $opcode = 1, $mask = "\x00\x00\x00\x00", $masked = true ) {
        $length = strlen( $payload );
        $out    = chr( 0x80 | ( $opcode & 0x0F ) ); // FIN + opcode

        if ( ! $masked ) {
            // A server must not mask the frames it sends (RFC 6455 §5.1),
            // so the mask bit has to be clear — and no mask key follows.
            if ( $length < 126 ) {
                $out .= chr( $length );
            } elseif ( $length < 65536 ) {
                $out .= chr( 126 ) . pack( 'n', $length );
            } else {
                $out .= chr( 127 ) . pack( 'J', $length );
            }
            return $out . $payload;
        }

        if ( $length < 126 ) {
            $out .= chr( 0x80 | $length );
        } elseif ( $length < 65536 ) {
            $out .= chr( 0x80 | 126 ) . pack( 'n', $length );
        } else {
            $out .= chr( 0x80 | 127 ) . pack( 'J', $length );
        }

        $out .= $mask;

        if ( '' === $mask ) {
            return $out . $payload;
        }
        $masked = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $masked .= $payload[ $i ] ^ $mask[ $i % 4 ];
        }
        return $out . $masked;
    }

    /**
     * Parse as many complete frames as the buffer holds.
     *
     * Returns the messages and the remainder, so the caller can read more
     * bytes and try again. A frame that is not complete is left in the
     * remainder untouched.
     *
     * ## Frame lengths are validated before they are used
     *
     * A frame's length is read from the wire. The 64-bit form is eight
     * bytes that may name any value up to 2^64-1, and PHP's integer is
     * *signed* — so a length of 2^63 or more cannot be held in one at
     * all. Casting it produced a negative number, and a negative length
     * then passed the "is the frame complete?" test (a positive number
     * is never less than a negative one), moved the cursor backwards, and
     * left the parser reading before the start of the buffer. PHP warned
     * about the negative offsets, and those warnings are printed into the
     * response body — which, for the MCP transport, is the JSON-RPC
     * stream.
     *
     * So the length is now read as two unsigned 32-bit halves, checked
     * against the safe-integer range and against MAX_FRAME_BYTES *before*
     * it becomes an offset, and a length that fails either test ends the
     * parse with a controlled error instead of a warning.
     *
     * @param string $buffer The bytes read so far.
     * @return array{messages:array<int,array{opcode:int,payload:string,fin:bool}>,rest:string,error:string}
     */
    public static function parse_frames( $buffer ) {
        $messages = array();
        $offset   = 0;
        $length   = strlen( $buffer );
        $error    = '';

        while ( true ) {
            if ( $length - $offset < 2 ) {
                break;
            }
            $byte0       = ord( $buffer[ $offset ] );
            $byte1       = ord( $buffer[ $offset + 1 ] );
            $fin         = (bool) ( $byte0 & 0x80 );
            $opcode      = $byte0 & 0x0F;
            $masked      = (bool) ( $byte1 & 0x80 );
            $length_bits = $byte1 & 0x7F;
            $cursor      = $offset + 2;

            if ( 126 === $length_bits ) {
                if ( $length - $cursor < 2 ) {
                    break;
                }
                $payload_length = unpack( 'n', substr( $buffer, $cursor, 2 ) )[1];
                $cursor        += 2;
            } elseif ( 127 === $length_bits ) {
                if ( $length - $cursor < 8 ) {
                    // The extended length is not here yet. This is an
                    // incomplete frame, not an invalid one: the caller
                    // reads more and tries again.
                    break;
                }
                // Two unsigned 32-bit halves, big endian, read apart so
                // that no cast through a signed 64-bit integer can lose
                // the high bit.
                $halves = unpack( 'N2', substr( $buffer, $cursor, 8 ) );
                $high   = (int) $halves[1];
                $low    = (int) $halves[2];
                $cursor += 8;

                if ( $high > 0x1FFFFF ) {
                    // 0x1FFFFF * 2^32 + 0xFFFFFFFF is 2^53-1, the largest
                    // integer that survives a round trip through a double
                    // and is therefore the largest this parser will
                    // consider. Anything above it is refused rather than
                    // truncated.
                    $error    = 'the frame declares a length above the safe integer range';
                    $messages = array();
                    $offset   = $length;
                    break;
                }
                $payload_length = ( $high * 4294967296 ) + $low;

                if ( $payload_length > self::MAX_FRAME_BYTES ) {
                    $error    = 'the frame declares a length of ' . $payload_length
                        . ' bytes, which is more than the ' . self::MAX_FRAME_BYTES . ' byte maximum';
                    $messages = array();
                    $offset   = $length;
                    break;
                }
            } else {
                $payload_length = $length_bits;
            }

            $mask_key = '';
            if ( $masked ) {
                if ( $length - $cursor < 4 ) {
                    break;
                }
                $mask_key = substr( $buffer, $cursor, 4 );
                $cursor  += 4;
            }

            if ( $length - $cursor < $payload_length ) {
                break;
            }
            $payload = substr( $buffer, $cursor, $payload_length );
            $cursor += $payload_length;

            if ( '' !== $mask_key ) {
                $unmasked = '';
                for ( $i = 0; $i < $payload_length; $i++ ) {
                    $unmasked .= $payload[ $i ] ^ $mask_key[ $i % 4 ];
                }
                $payload = $unmasked;
            }

            $messages[] = array(
                'opcode'  => $opcode,
                'payload' => $payload,
                'fin'     => $fin,
            );
            $offset = $cursor;
        }

        return array(
            'messages' => $messages,
            'rest'     => substr( $buffer, $offset ),
            'error'    => $error,
        );
    }

    /**
     * Random bytes, from the best source available.
     *
     * @param int $count How many bytes.
     * @return string
     */
    public static function random_bytes( $count ) {
        try {
            return random_bytes( $count );
        } catch ( \Throwable $e ) {
            $out = '';
            for ( $i = 0; $i < $count; $i++ ) {
                $out .= chr( mt_rand( 0, 255 ) );
            }
            return $out;
        }
    }

    /**
     * Send a text frame.
     *
     * @param resource $socket  The socket.
     * @param string   $payload The payload.
     * @return bool True when the bytes were written.
     */
    public static function send_text( $socket, $payload ) {
        $mask  = self::random_bytes( 4 );
        $frame = self::frame( $payload, 1, $mask );
        $written = @fwrite( $socket, $frame );
        return ( false !== $written && strlen( $frame ) === $written );
    }
}

/**
 * One WebSocket connection to a DevTools endpoint, speaking CDP.
 *
 * The conversation is request/response with interleaved events: a command
 * is answered by the message that carries its id, and everything else that
 * arrives in the meantime is an event (a page load, a console message) and
 * is queued for the caller.
 */
class CDP_Connection {

    /**
     * The largest amount of unparsed input that is kept.
     *
     * A frame that has not arrived in full sits in the buffer until the
     * rest of it does. 32 MiB is far larger than any message the
     * browser sends for a page capture, and small enough that a runaway
     * conversation cannot exhaust the process.
     */
    const MAX_BUFFER_BYTES = 33554432;
    /** @var resource|null */
    private $socket;
    /** @var string */
    private $buffer = '';
    /** @var array<int,array> Messages read from the socket, not yet examined. */
    private $pending = array();
    /** @var array<int,array> Events, kept for whoever asks for them. */
    private $events = array();
    /** @var array<string,callable> Handlers for events, keyed by method name. */
    private $handlers = array();
    /** @var array<int,bool> Command ids whose answer nobody is waiting for. */
    private $discarded = array();
    /** @var int */
    private $next_id = 1;

    /**
     * @param resource|null $socket The socket.
     * @param string        $buffer Bytes already read from the socket.
     */
    public function __construct( $socket, $buffer = '' ) {
        $this->socket = $socket;
        $this->buffer = (string) $buffer;
    }

    /**
     * Open a connection to a DevTools endpoint.
     *
     * @param string $host    The host (127.0.0.1).
     * @param int    $port    The port.
     * @param string $path    The path from the DevTools endpoint.
     * @param float  $timeout The connect/read timeout in seconds.
     * @return CDP_Connection|null
     */
    public static function open( $host, $port, $path, $timeout = 10.0, &$diagnostic = null ) {
        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . (int) $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if ( ! $socket ) {
            $diagnostic = 'the connection was refused or timed out';
            return null;
        }
        $seconds      = (int) floor( $timeout );
        $microseconds = (int) round( ( $timeout - $seconds ) * 1000000 );
        stream_set_timeout( $socket, $seconds, $microseconds );

        $key = base64_encode( CDP::random_bytes( 16 ) );
        fwrite( $socket, CDP::handshake_request( $host, $port, $path, $key ) );

        $response = '';
        $deadline = microtime( true ) + $timeout;
        while ( false === strpos( $response, "\r\n\r\n" ) ) {
            if ( microtime( true ) > $deadline ) {
                fclose( $socket );
                $diagnostic = 'the handshake response did not arrive';
                return null;
            }
            $chunk = @fread( $socket, 4096 );
            if ( false === $chunk || '' === $chunk ) {
                $meta = stream_get_meta_data( $socket );
                if ( ! empty( $meta['timed_out'] ) || feof( $socket ) ) {
                    fclose( $socket );
                    $diagnostic = 'the connection was closed before the handshake completed';
                    return null;
                }
                continue;
            }
            $response .= $chunk;
            if ( strlen( $response ) > 65536 ) {
                fclose( $socket );
                $diagnostic = 'the handshake response was implausibly large';
                return null;
            }
        }

        list( $head, $rest ) = explode( "\r\n\r\n", $response, 2 );

        // The status line, and nothing else: it says why the server said
        // no, and it carries no URL, so it is safe to report.
        $status = trim( strtok( $head, "\r\n" ) );
        if ( false === stripos( $head, ' 101 ' ) ) {
            fclose( $socket );
            $diagnostic = 'the server answered: ' . ( '' !== $status ? $status : 'an unreadable status line' );
            return null;
        }
        if ( false === stripos( $head, CDP::expected_accept( $key ) ) ) {
            // The accept hash did not match: this is not the endpoint we
            // think it is. Refuse rather than talk to it.
            fclose( $socket );
            $diagnostic = 'the server accepted the connection with the wrong accept hash';
            return null;
        }

        return new self( $socket, $rest );
    }

    /**
     * The reason the last read failed, when it failed for a reason of its
     * own rather than by timing out.
     *
     * @var string
     */
    private $error = '';

    /**
     * The reason the last read failed.
     *
     * @return string The reason, or '' when the last read merely timed out.
     */
    public function error() {
        return $this->error;
    }

    /**
     * Close the connection.
     */
    public function close() {
        if ( is_resource( $this->socket ) ) {
            @fwrite( $this->socket, CDP::frame( '', 8, CDP::random_bytes( 4 ) ) );
            @fclose( $this->socket );
        }
        $this->socket = null;
    }

    /**
     * Handle an event as it arrives.
     *
     * A handler is called with the message and with this connection. It
     * must not *read* from the connection: reading inside a handler would
     * consume the answer to whatever command the reader is waiting for.
     * A handler that must answer the browser — which the Fetch
     * interception does, for every paused request — uses `notify()`, which
     * writes without reading.
     *
     * @param string   $method  The event method.
     * @param callable $handler The handler.
     */
    public function set_event_handler( $method, callable $handler ) {
        $this->handlers[ (string) $method ] = $handler;
    }

    /**
     * Send a command whose answer nobody needs.
     *
     * The command is written and its id remembered, so that the answer
     * the browser sends back can be discarded instead of being queued as an
     * unread message.
     *
     * @param string      $method     The CDP method.
     * @param array       $params     The parameters.
     * @param string|null $session_id The session, when talking to a target.
     * @return int The command id.
     */
    public function notify( $method, array $params = array(), $session_id = null ) {
        $id = $this->send( $method, $params, $session_id );
        if ( $id ) {
            $this->discarded[ $id ] = true;
        }
        return $id;
    }

    /**
     * Send a command.
     *
     * @param string      $method     The CDP method.
     * @param array       $params     The parameters.
     * @param string|null $session_id The session, when talking to a target.
     * @return int The command id.
     */
    public function send( $method, array $params = array(), $session_id = null ) {
        $id = $this->next_id++;
        $message = array(
            'id'     => $id,
            'method' => $method,
            'params' => (object) $params,
        );
        if ( null !== $session_id && '' !== $session_id ) {
            $message['sessionId'] = $session_id;
        }
        $payload = json_encode( $message );
        if ( ! is_string( $payload ) ) {
            return 0;
        }
        CDP::send_text( $this->socket, $payload );
        return $id;
    }

    /**
     * Read the next message, queueing the events that arrive first.
     *
     * The timeout is this call's own deadline, and it is honoured: the
     * socket's timeout is a backstop, not the deadline. A read that
     * blocked for the socket's timeout would ignore the deadline it was
     * given, and a caller waiting for an event that was never going to
     * arrive would wait ten times as long as it asked to.
     *
     * @param float $timeout The deadline in seconds.
     * @return array|null The decoded message, or null on timeout.
     */
    public function read( $timeout = 10.0 ) {
        $deadline = microtime( true ) + $timeout;

        while ( true ) {
            if ( ! empty( $this->pending ) ) {
                return array_shift( $this->pending );
            }
            $remaining = $deadline - microtime( true );
            if ( $remaining <= 0 ) {
                return null;
            }

            // Wait for the socket to become readable, for no longer than
            // the deadline. Without this the read below would block for
            // the socket's own timeout, which is longer than the
            // deadline this call was given.
            $read    = array( $this->socket );
            $write   = null;
            $except  = null;
            $seconds = (int) floor( $remaining );
            $micro   = (int) round( ( $remaining - $seconds ) * 1000000 );
            if ( $micro >= 1000000 ) {
                // stream_select() wants the microseconds below a million.
                $seconds++;
                $micro = 0;
            }
            $ready   = @stream_select( $read, $write, $except, $seconds, $micro );
            if ( false === $ready ) {
                return null;
            }
            if ( 0 === $ready ) {
                return null; // the deadline passed with nothing to read
            }

            $chunk = @fread( $this->socket, 65536 );
            if ( false === $chunk || '' === $chunk ) {
                $meta = stream_get_meta_data( $this->socket );
                if ( ! empty( $meta['timed_out'] ) || feof( $this->socket ) ) {
                    return null;
                }
                continue;
            }
            $this->buffer .= $chunk;

            // A cap on the buffer. The peer is the browser's own DevTools
            // endpoint, so this is not a hostile-peer measure: it is a
            // measure against a page that makes the browser talk a lot
            // (a console dump, a large screenshot, a chatty extension).
            // Without a cap, a large frame that has not arrived in full
            // grows the buffer until the process runs out of memory.
            if ( strlen( $this->buffer ) > self::MAX_BUFFER_BYTES ) {
                $this->buffer = '';
                return null;
            }

            $parsed = CDP::parse_frames( $this->buffer );
            $this->buffer = $parsed['rest'];
            if ( ! empty( $parsed['error'] ) ) {
                // A frame the parser refused. The buffer is dropped, the
                // reason is remembered, and the caller gets a controlled
                // failure instead of a warning on the wire.
                $this->buffer = '';
                $this->error  = 'the browser sent a frame this client cannot read: ' . $parsed['error'];
                return null;
            }
            foreach ( $parsed['messages'] as $frame ) {
                if ( 8 === $frame['opcode'] ) {
                    return null; // the peer closed
                }
                if ( 9 === $frame['opcode'] ) {
                    // A ping: answer it, or the peer may drop the connection.
                    @fwrite( $this->socket, CDP::frame( $frame['payload'], 10, CDP::random_bytes( 4 ) ) );
                    continue;
                }
                if ( 1 !== $frame['opcode'] && 2 !== $frame['opcode'] && 0 !== $frame['opcode'] ) {
                    continue;
                }
                $decoded = json_decode( $frame['payload'], true );
                if ( is_array( $decoded ) ) {
                    $this->accept( $decoded );
                }
            }
        }
    }

    /**
     * Take one message off the wire.
     *
     * An event is handed to its handler *here*, at the moment it arrives,
     * rather than when somebody goes looking for it. That is what makes
     * the Fetch interception possible: the browser is waiting for an
     * answer about a paused request, and the answer has to be given while
     * the message is being read, not later.
     *
     * @param array $message The decoded message.
     */
    private function accept( array $message ) {
        if ( isset( $message['method'] ) ) {
            $this->fire( $message );
            $this->pending[] = $message;
            return;
        }
        if ( isset( $message['id'] ) ) {
            $id = (int) $message['id'];
            if ( isset( $this->discarded[ $id ] ) ) {
                unset( $this->discarded[ $id ] );
                return;
            }
        }
        $this->pending[] = $message;
    }

    /**
     * Hand an event to its handler.
     *
     * A handler that throws must not break the connection: the browser is
     * in the middle of a conversation, and a failed handler is a bug in
     * this program, not a reason to drop every later message.
     *
     * @param array $message The event.
     */
    private function fire( array $message ) {
        $method = (string) ( $message['method'] ?? '' );
        if ( '' === $method ) {
            return;
        }
        $handler = $this->handlers[ $method ] ?? ( $this->handlers['*'] ?? null );
        if ( null === $handler ) {
            return;
        }
        try {
            call_user_func( $handler, $message, $this );
        } catch ( \Throwable $e ) {
            // Deliberately swallowed: see above.
        }
    }

    /**
     * Send a command and wait for its answer.
     *
     * Events that arrive while waiting are *kept*: they are put back on the
     * queue, so a page-load event that fires while a command is in flight is
     * still there when the caller waits for it.
     *
     * @param string      $method     The CDP method.
     * @param array       $params     The parameters.
     * @param string|null $session_id The session, when talking to a target.
     * @param float       $timeout    The deadline in seconds.
     * @return array{ok:bool,result:array,error:string}
     */
    public function call( $method, array $params = array(), $session_id = null, $timeout = 10.0 ) {
        $id = $this->send( $method, $params, $session_id );
        if ( ! $id ) {
            return array( 'ok' => false, 'result' => array(), 'error' => 'the command could not be sent' );
        }
        $deadline = microtime( true ) + $timeout;
        while ( microtime( true ) < $deadline ) {
            $message = $this->take( $id );
            if ( null === $message ) {
                $message = $this->read( max( 0.1, $deadline - microtime( true ) ) );
                if ( null === $message ) {
                    // A refusal by the parser is not a timeout: it has a
                    // reason, and saying "timed out" would send the caller
                    // looking in the wrong place.
                    $reason = $this->error();
                    return array(
                        'ok'     => false,
                        'result' => array(),
                        'error'  => '' !== $reason ? $reason : 'the command timed out',
                    );
                }
                if ( ! isset( $message['id'] ) || (int) $message['id'] !== $id ) {
                    // An event, not our answer. It goes to the event queue,
                    // not back onto the read queue: a message put back on
                    // the read queue is handed out again by the next read,
                    // and the command would never see its own answer.
                    $this->events[] = $message;
                    continue;
                }
            }
            if ( isset( $message['error'] ) ) {
                return array(
                    'ok'    => false,
                    'result' => array(),
                    'error' => (string) ( $message['error']['message'] ?? 'the command failed' ),
                );
            }
            return array(
                'ok'     => true,
                'result' => isset( $message['result'] ) && is_array( $message['result'] ) ? $message['result'] : array(),
                'error'  => '',
            );
        }
        return array( 'ok' => false, 'result' => array(), 'error' => 'the command timed out' );
    }

    /**
     * Take a queued message with a given command id, if there is one.
     *
     * @param int $id The command id.
     * @return array|null
     */
    private function take( $id ) {
        foreach ( $this->pending as $index => $message ) {
            if ( isset( $message['id'] ) && (int) $message['id'] === (int) $id ) {
                unset( $this->pending[ $index ] );
                $this->pending = array_values( $this->pending );
                return $message;
            }
        }
        return null;
    }

    /**
     * Wait for an event with a given method.
     *
     * @param string $method  The event method.
     * @param float  $timeout The deadline in seconds.
     * @return array|null The event, or null on timeout.
     */
    public function wait_for_event( $method, $timeout = 10.0 ) {
        $deadline = microtime( true ) + $timeout;
        while ( microtime( true ) < $deadline ) {
            foreach ( $this->events as $index => $message ) {
                if ( isset( $message['method'] ) && $method === $message['method'] ) {
                    unset( $this->events[ $index ] );
                    $this->events = array_values( $this->events );
                    return $message;
                }
            }
            $message = $this->read( max( 0.1, $deadline - microtime( true ) ) );
            if ( null === $message ) {
                return null;
            }
            if ( isset( $message['method'] ) && $method === $message['method'] ) {
                return $message;
            }
            $this->events[] = $message;
        }
        return null;
    }
}
