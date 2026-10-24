<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-cdp.php';
// The screenshot class owns the browser's environment, its process-group
// handling and its profile lifecycle, and this class uses all three. The
// two require each other; neither touches the other at load time, so the
// cycle is harmless whichever one is loaded first.
require_once __DIR__ . '/class-screenshot.php';

/**
 * A capture driven by the Chrome DevTools Protocol.
 *
 * The screenshot tool's other path asks Chrome for a *window* of a given
 * size and lets Chrome take the picture itself. Chrome will not make a
 * window narrower than about 500 CSS pixels, so a narrow request is laid
 * out at 500 pixels and the PNG is a crop of that wider layout — the media
 * queries evaluated at 500 pixels, not the width the caller asked for.
 *
 * This path asks for a *viewport* instead: the browser is launched with a
 * remote debugging port, the DevTools Protocol's
 * `Emulation.setDeviceMetricsOverride` sets the viewport to the requested
 * size, and the page is captured through `Page.captureScreenshot`. The
 * effective viewport is then read back from the page itself
 * (`window.innerWidth`), so the number the tool reports is the width the
 * layout actually used — measured, not assumed.
 *
 * Every capture gets its own throwaway profile, exactly as the other path
 * does, and the profile is removed by the caller afterwards. The URL is
 * never logged: a preview credential rides in it.
 */
class Screenshot_CDP {
    /**
     * Capture a page at a true viewport width.
     *
     * @param string $binary   The browser binary.
     * @param string $url      The URL to capture.
     * @param int    $width    The viewport width.
     * @param int    $height   The viewport height.
     * @param string $file     Where to write the PNG.
     * @param string $profile  The throwaway user-data directory.
     * @param int    $timeout  The wall-clock budget in seconds.
     * @param bool   $full_page Whether to capture the whole page.
     * @return array{ok:bool,error:string,viewport:array{width:int,height:int},elapsed_ms:int,timed_out:bool}
     */
    public static function capture( $binary, $url, $width, $height, $file, $profile, $timeout, $full_page = false ) {
        $started = microtime( true );
        $result  = array(
            'ok'        => false,
            'error'     => '',
            // A fatal failure is one the fallback cannot fix: the page
            // itself did not load. Falling back would only spend the
            // budget again to reach the same answer.
            'fatal'    => false,
            'viewport'  => array( 'width' => 0, 'height' => 0 ),
            'elapsed_ms' => 0,
            'timed_out' => false,
        );

        $log = $file . '.cdp.log';
        $argv = array(
            $binary,
            '--headless',
            '--disable-gpu',
            '--hide-scrollbars',
            '--no-first-run',
            '--no-default-browser-check',
            // The browser's shared memory. In a container /dev/shm is
            // usually 64 MiB, and Chromium's renderer will crash when it
            // runs out — which looks exactly like a browser that started,
            // published a port and then vanished. This flag moves the
            // shared memory into a temporary directory instead.
            '--disable-dev-shm-usage',
            // Port 0: the browser picks a free port and writes it into
            // DevToolsActivePort in the user-data directory. A fixed port
            // would collide with whatever else is running.
            '--remote-debugging-port=0',
            '--user-data-dir=' . $profile,
            'about:blank',
        );

        // Chromium's sandbox needs user namespaces, which a container
        // running as root does not have. Without this the browser exits
        // immediately and the capture fails with a message about a
        // connection that was refused.
        if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
            $argv[] = '--no-sandbox';
            $argv[] = '--disable-setuid-sandbox';
        }

        // The profile's own subdirectories — the browser's HOME (where its
        // trust store lives) and its TMPDIR (where it creates the socket
        // directory its process singleton needs) — must exist before the
        // browser starts. Chrome aborts when it cannot create them, and
        // the abort message says nothing about the missing directory.
        foreach ( array(
            \AgentShell_MCP\Screenshot::trust_home_dir( $profile ),
            \AgentShell_MCP\Screenshot::profile_tmp_dir( $profile ),
        ) as $dir ) {
            if ( ! is_dir( $dir ) ) {
                @mkdir( $dir, 0700, true );
            }
        }

        $descriptors = array(
            0 => array( 'file', '/dev/null', 'r' ),
            1 => array( 'file', $log, 'a' ),
            2 => array( 'file', $log, 'a' ),
        );
        $pipes = array();

        // The browser leads its own process group, so the whole tree —
        // the zygote and the renderers — can be killed when the capture
        // ends. A browser that outlives its capture holds files open in
        // the profile, and the profile is then not removable.
        //
        // The wrapper's budget is the capture's budget plus a grace
        // period. With the same budget the wrapper would kill the browser
        // at the very moment the capture gives up, and the failure would
        // look like a refused connection rather than a timeout.
        $argv = \AgentShell_MCP\Screenshot::wrap_browser_argv( $argv, $timeout + 30 );

        $handle = @proc_open( $argv, $descriptors, $pipes, null, self::isolated_env( $profile ) );
        if ( ! is_resource( $handle ) ) {
            $result['error'] = 'the browser could not be started';
            return $result;
        }

        $connection = null;
        try {
            $port = self::wait_for_devtools_port( $profile, $handle, $timeout );
            if ( ! $port ) {
                $result['error'] = 'the browser did not report a DevTools port';
                $result['timed_out'] = true;
                return $result;
            }

            $path = self::browser_websocket_path( $port, $timeout );
            if ( '' === $path ) {
                $result['error'] = 'the DevTools endpoint could not be read';
                return $result;
            }

            // The browser's DevTools server can refuse the first
            // connection while it is still initialising: the HTTP
            // endpoint answers before the WebSocket endpoint is ready.
            // A few attempts, a moment apart, turn a race into a
            // non-event.
            $connection = null;
            $diagnostic = '';
            $attempts   = 0;
            $deadline   = microtime( true ) + max( 5.0, min( 20.0, $timeout ) );
            while ( ! $connection && microtime( true ) < $deadline && $attempts < 6 ) {
                $attempts++;
                $connection = CDP_Connection::open( '127.0.0.1', $port, $path, min( 10.0, $timeout ), $diagnostic );
                if ( ! $connection ) {
                    usleep( 500000 );
                }
            }
            if ( ! $connection ) {
                $result['error'] = 'the DevTools connection could not be opened'
                    . ( '' !== $diagnostic ? ' (' . $diagnostic . ')' : '' );
                return $result;
            }

            $outcome = self::drive( $connection, $url, $width, $height, $file, $timeout, $full_page );
            $result = array_merge( $result, $outcome );
        } catch ( \Throwable $e ) {
            // The message may contain the URL, and the URL may carry a
            // preview credential. Report the class of the failure, never
            // the message.
            $result['error'] = 'the capture failed (' . get_class( $e ) . ')';
        } finally {
            if ( $connection instanceof CDP_Connection ) {
                $connection->close();
            }
            // The browser is a process tree: kill the group, not just the
            // direct child, or the renderer outlives the request.
            if ( is_resource( $handle ) ) {
                \AgentShell_MCP\Screenshot::kill_browser_process_group( $handle );
                @proc_close( $handle );
            }
            // When the capture failed, the browser's own words about why
            // are the most useful thing there is. They are reported with
            // every URL removed: the browser's log may name the URL it
            // was asked to open, and that URL may carry a preview
            // credential.
            if ( ! empty( $result['error'] ) ) {
                $excerpt = self::log_excerpt( $log );
                if ( '' !== $excerpt ) {
                    $result['error'] .= ': ' . $excerpt;
                }
            }
            @unlink( $log );
        }

        $result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
        return $result;
    }

    /**
     * The browser's own explanation, with every URL removed.
     *
     * @param string $log The browser's log file.
     * @return string A short excerpt, or '' when there is nothing to say.
     */
    private static function log_excerpt( $log ) {
        if ( ! is_file( $log ) ) { return ''; }
        $raw = (string) @file_get_contents( $log );
        if ( '' === trim( $raw ) ) { return ''; }
        // Any URL is replaced: the browser was asked to open one, and it
        // may carry a credential.
        $raw = preg_replace( '#https?://\S+#i', '<url>', $raw );
        $raw = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
        if ( '' === $raw ) { return ''; }
        return substr( $raw, 0, 300 );
    }

    /**
     * The conversation with the browser.
     *
     * @param CDP_Connection $connection The connection.
     * @param string         $url        The URL.
     * @param int            $width      The viewport width.
     * @param int            $height     The viewport height.
     * @param string         $file       Where to write the PNG.
     * @param int            $timeout    The budget in seconds.
     * @param bool           $full_page  Whether to capture the whole page.
     * @return array
     */
    private static function drive( CDP_Connection $connection, $url, $width, $height, $file, $timeout, $full_page ) {
        $out = array( 'ok' => false, 'error' => '', 'viewport' => array( 'width' => 0, 'height' => 0 ) );

        $created = $connection->call( 'Target.createTarget', array( 'url' => 'about:blank' ), null, min( 10.0, $timeout ) );
        if ( ! $created['ok'] || empty( $created['result']['targetId'] ) ) {
            $out['error'] = 'the browser refused to create a target';
            return $out;
        }
        $target_id = (string) $created['result']['targetId'];

        $attached = $connection->call(
            'Target.attachToTarget',
            array( 'targetId' => $target_id, 'flatten' => true ),
            null,
            min( 10.0, $timeout )
        );
        if ( ! $attached['ok'] || empty( $attached['result']['sessionId'] ) ) {
            $out['error'] = 'the browser refused to attach to the target';
            return $out;
        }
        $session = (string) $attached['result']['sessionId'];

        // The viewport. This is the whole point: the device metrics
        // override sets the width the layout and the media queries see,
        // independently of the window the browser would have used.
        $metrics = $connection->call(
            'Emulation.setDeviceMetricsOverride',
            array(
                'width'             => (int) $width,
                'height'            => (int) $height,
                'deviceScaleFactor' => 1,
                'mobile'            => false,
            ),
            $session,
            min( 10.0, $timeout )
        );
        if ( ! $metrics['ok'] ) {
            $out['error'] = 'the browser refused the viewport override';
            return $out;
        }

        $connection->call( 'Page.enable', array(), $session, min( 10.0, $timeout ) );

        $navigate = $connection->call(
            'Page.navigate',
            array( 'url' => $url ),
            $session,
            min( 15.0, $timeout )
        );
        if ( ! $navigate['ok'] ) {
            $out['error'] = 'the browser refused to navigate';
            return $out;
        }

        // A navigation that failed carries the reason: an untrusted
        // certificate is net::ERR_CERT_AUTHORITY_INVALID, a refused
        // connection is net::ERR_CONNECTION_REFUSED. The page did not
        // load, so the fallback has nothing to add — and saying so is
        // what tells the caller *why* the capture failed instead of
        // "the browser failed".
        $error_text = (string) ( $navigate['result']['errorText'] ?? '' );
        if ( '' !== $error_text ) {
            $out['error']  = 'the page could not be loaded: ' . $error_text;
            $out['fatal']  = true;
            return $out;
        }

        $connection->wait_for_event( 'Page.loadEventFired', min( 15.0, $timeout ) );

        // The effective viewport, read from the page itself. A number
        // measured here cannot be wrong the way an assumed one can.
        $evaluated = $connection->call(
            'Runtime.evaluate',
            array(
                'expression'    => 'JSON.stringify({w: window.innerWidth, h: window.innerHeight})',
                'returnByValue' => true,
            ),
            $session,
            min( 10.0, $timeout )
        );
        if ( $evaluated['ok'] ) {
            $value = $evaluated['result']['result']['value'] ?? '';
            $decoded = is_string( $value ) ? json_decode( $value, true ) : null;
            if ( is_array( $decoded ) ) {
                $out['viewport'] = array(
                    'width'  => (int) ( $decoded['w'] ?? 0 ),
                    'height' => (int) ( $decoded['h'] ?? 0 ),
                );
            }
        }

        $clip = null;
        if ( $full_page ) {
            $layout = $connection->call( 'Page.getLayoutMetrics', array(), $session, min( 10.0, $timeout ) );
            if ( $layout['ok'] ) {
                $size = $layout['result']['cssContentSize'] ?? ( $layout['result']['contentSize'] ?? null );
                if ( is_array( $size ) && (int) ( $size['width'] ?? 0 ) > 0 ) {
                    $clip = array(
                        'x'      => 0,
                        'y'      => 0,
                        'width'  => (float) $size['width'],
                        'height' => (float) $size['height'],
                        'scale'  => 1,
                    );
                }
            }
        }

        $params = array( 'format' => 'png' );
        if ( null !== $clip ) {
            $params['captureBeyondViewport'] = true;
            $params['clip']                 = $clip;
        }

        $shot = $connection->call( 'Page.captureScreenshot', $params, $session, min( 20.0, $timeout ) );
        if ( ! $shot['ok'] || empty( $shot['result']['data'] ) ) {
            $out['error'] = 'the browser returned no image';
            return $out;
        }

        $bytes = base64_decode( (string) $shot['result']['data'], true );
        if ( ! is_string( $bytes ) || '' === $bytes ) {
            $out['error'] = 'the browser returned an unreadable image';
            return $out;
        }

        // Atomic: a reader must never see a half-written PNG.
        $tmp = $file . '.part';
        if ( false === @file_put_contents( $tmp, $bytes ) ) {
            $out['error'] = 'the image could not be written';
            return $out;
        }
        if ( ! @rename( $tmp, $file ) ) {
            @unlink( $tmp );
            $out['error'] = 'the image could not be moved into place';
            return $out;
        }

        $out['ok'] = true;
        return $out;
    }

    /**
     * Wait for the browser to write its DevTools port.
     *
     * @param string   $profile The user-data directory.
     * @param resource $handle  The process handle.
     * @param int      $timeout The budget in seconds.
     * @return int The port, or 0.
     */
    private static function wait_for_devtools_port( $profile, $handle, $timeout ) {
        $file    = rtrim( $profile, '/' ) . '/DevToolsActivePort';
        $deadline = microtime( true ) + $timeout;
        while ( microtime( true ) < $deadline ) {
            if ( is_readable( $file ) ) {
                $contents = (string) @file_get_contents( $file );
                $port     = (int) strtok( $contents, "\n" );
                if ( $port > 0 ) {
                    return $port;
                }
            }
            $status = @proc_get_status( $handle );
            if ( is_array( $status ) && empty( $status['running'] ) ) {
                return 0;
            }
            usleep( 50000 );
        }
        return 0;
    }

    /**
     * The WebSocket path of the browser-level DevTools endpoint.
     *
     * @param int $port    The DevTools port.
     * @param int $timeout The budget in seconds.
     * @return string The path, or ''.
     */
    private static function browser_websocket_path( $port, $timeout ) {
        $context = stream_context_create( array(
            'http' => array( 'timeout' => max( 1, (int) $timeout ), 'ignore_errors' => true ),
        ) );
        // The response is read with fopen/fread rather than
        // file_get_contents: Chrome's DevTools HTTP handler answers
        // without a Content-Length header and keeps the connection open,
        // so file_get_contents waits for a close that never comes and
        // burns the whole timeout before it returns what it already has.
        $handle = @fopen( 'http://127.0.0.1:' . (int) $port . '/json/version', 'rb', false, $context );
        if ( ! $handle ) {
            return '';
        }
        $body = (string) @fread( $handle, 8192 );
        @fclose( $handle );

        $decoded = json_decode( $body, true );
        $url     = is_array( $decoded ) ? (string) ( $decoded['webSocketDebuggerUrl'] ?? '' ) : '';
        if ( '' === $url ) {
            return '';
        }
        $path = (string) parse_url( $url, PHP_URL_PATH );
        return $path;
    }

    /**
     * The environment the browser runs in.
     *
     * This is the screenshot class's own environment, not a second one:
     * HOME points at the profile's trust store (so the certificates the
     * operator named are the only roots the browser has), TMPDIR points
     * inside the profile (so the browser's scratch directories do not
     * survive its removal), and the server's library paths are not
     * inherited. A capture that used a different environment would trust
     * the wrong roots and leave files behind — the two failures the
     * browser-trust and browser-isolation suites exist to catch.
     *
     * @param string $profile The user-data directory.
     * @return array
     */
    private static function isolated_env( $profile ) {
        return \AgentShell_MCP\Screenshot::browser_env( $profile );
    }
}
