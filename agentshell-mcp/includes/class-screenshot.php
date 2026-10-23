<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A capture could not start because another capture holds the cross-process
 * lock. Distinct from a browser failure so callers can retry rather than
 * treating it as a broken install.
 */
class Screenshot_Busy_Exception extends \RuntimeException {}

/**
 * A capture exceeded its hard wall-clock deadline and its process group was
 * terminated. Distinct from a browser failure: the browser may be perfectly
 * healthy and simply too slow for this budget.
 */
class Screenshot_Timeout_Exception extends \RuntimeException {}

/**
 * Screenshot backend — headless-browser page capture for the agent visual
 * feedback loop (inspect → change → render → screenshot → evaluate).
 *
 * Uses the Chrome/Chromium headless CLI (--screenshot), which is available on
 * most servers. Detection order:
 *   1. AGENTSHELL_CHROME_BIN constant (explicit path)
 *   2. $PATH: google-chrome, google-chrome-stable, chromium, chromium-browser, headless_shell
 *
 * Step 2 needs a caveat. Under php-fpm the worker PATH is whatever the pool
 * declares — LocalWP sets env[PATH] to the Ghostscript bin directory, and
 * php-fpm's clear_env default discards the host environment, so PATH scan and
 * the `command -v` fallback both miss a browser that is present on the host.
 * Define AGENTSHELL_CHROME_BIN with an absolute path when that applies; it is
 * an is_executable() check and does not read PATH at all.
 *
 * Chrome runs here with its sandbox enabled and with certificate verification
 * on. Do not add --no-sandbox or any ignore-certificate flag to make a capture
 * succeed: such a failure means the browser or its trust store is missing, and
 * the fix belongs there rather than in the flags. The one environment that
 * genuinely requires --no-sandbox is a root-owned PHP runtime, since Chrome
 * refuses to sandbox as root — run PHP as a normal user instead.
 *
 * EXECUTION MODEL
 * ---------------
 * capture() blocks a PHP-FPM worker for the whole browser run. That is safe
 * only because the work is bounded and exclusive, which is what the rest of
 * this class is about:
 *
 *   Lock    Exactly one capture may run at a time. Two concurrent captures
 *           occupy every worker in a small pool (LocalWP: pm.max_children=2),
 *           and each browser then blocks fetching this very site through those
 *           same workers — a self-referential deadlock that takes the whole
 *           site down, not just screenshots. The lock is an flock() on a file
 *           in the system temp dir (never in uploads/, which is web-readable)
 *           because flock() is advisory and cross-process: PHP-FPM workers are
 *           separate processes and cannot coordinate through PHP state.
 *
 *   Deadline capture() polls the child and enforces its own wall-clock
 *           deadline rather than trusting a blocking exec() to return. On
 *           expiry it SIGKILLs the browser's entire process group — Chrome
 *           spawns a zygote plus one process per renderer, and killing only
 *           the direct child orphans the rest. setsid() puts the browser in
 *           its own process group so the group kill is complete and cannot
 *           reach PHP (a proc_open child otherwise shares PHP's group, and
 *           killing that group would kill the worker mid-request).
 *
 *   Temp    The browser writes to a private temp file which is validated and
 *           only then moved into uploads/. A failed or timed-out capture can
 *           therefore never leave a truncated PNG behind for a later request
 *           to serve.
 *
 *   Profile Every capture gets its own fresh, unpredictable, 0700 browser
 *           profile directory, passed with --user-data-dir and deleted when the
 *           capture ends. This is a privacy control, not a performance one: a
 *           capture of an unpublished draft renders that draft's content and
 *           the preview credential in its URL, and a browser's default profile
 *           would keep both — cookies, cache, the HTTP cache entries, and the
 *           rendered bytes — in the developer's own account directory, where
 *           the next capture, another tool, or the next person at that machine
 *           could read them. Nothing is left behind on any path: success,
 *           browser failure, or timeout.
 *
 *   Trust   Redirecting HOME also moves the certificate trust store, because
 *           Chromium keeps its roots in `$HOME/.pki/nssdb` and there is
 *           commonly no system NSS database to fall back on. A self-signed
 *           local site then fails with ERR_CERT_AUTHORITY_INVALID — the site
 *           under test is the one thing that cannot be captured.
 *
 *           So when the operator names trusted authorities explicitly, via
 *           `AGENTSHELL_TRUSTED_CA_FILES` in wp-config.php, this class builds
 *           a *fresh* NSS database inside the throwaway profile and adds only
 *           those certificates to it. Certificate validation is never
 *           disabled and `--ignore-certificate-errors` is never passed: a
 *           certificate that is not on that list still fails, which is the
 *           whole point of pinning trust rather than skipping it.
 *
 *           The operator's own NSS database is never read or copied. It is a
 *           browser *session* as much as a trust store — it can hold private
 *           keys and its own history — and this class is under a duty to leave
 *           the developer's account directory alone. Only the public
 *           certificates the operator listed are read.
 *
 *   Temp    TMPDIR points inside the throwaway profile too. Chromium creates
 *           auxiliary directories (`com.google.Chrome.chrome_chrome_url_fetcher_*`)
 *           in the system temp dir while rendering, and they survived the
 *           profile's removal because they were never inside it. Redirecting
 *           TMPDIR is what makes "nothing outlives the capture" true for those
 *           too, on the success, failure and timeout paths alike.
 *
 * Screenshots land in wp-content/uploads/agentshell-screenshots/.
 */
class Screenshot {
    const VIEWPORTS = array(
        'desktop' => array( 1280, 800 ),
        'mobile'  => array( 390, 844 ),
        'tablet'  => array( 768, 1024 ),
    );

    /** Marker file written into every profile this class creates. */
    const PROFILE_MARKER = '.agentshell-profile';

    /** Its contents. Proves a directory is ours before a sweep removes it. */
    const PROFILE_MARKER_VALUE = 'agentshell-browser-profile-v1';

    /**
     * How old a profile must be before the stale sweep will consider removing
     * it. Comfortably above the maximum capture timeout (300s) so a slow but
     * live capture is never mistaken for an abandoned one.
     */
    const PROFILE_STALE_SECONDS = 3600;

    /**
     * Longest TMPDIR this class will hand a browser.
     *
     * Chromium keeps its process-singleton socket inside TMPDIR, in a directory
     * it names for itself (`com.google.Chrome.XXXXXX/SingletonSocket`), and a
     * unix socket path is capped at the 107 bytes of `sockaddr_un`. That turns
     * the profile path from a naming choice into a hard constraint: point
     * TMPDIR inside the profile — which is what makes the browser's own scratch
     * directories die with the profile instead of accumulating in the shared
     * system temp dir — and the length of `<temp>/<profile>/<random>` decides
     * whether the capture can run at all.
     *
     * Measured against Chrome on glibc: a 71-byte TMPDIR works and a 72-byte one
     * does not. Anything longer than this is refused here, where the message can
     * say what is wrong, rather than inside the browser, where it cannot.
     */
    const MAX_TMPDIR_LENGTH = 71;

    /**
     * Hex characters of randomness in a profile directory name.
     *
     * Sixteen hex characters is 64 bits, which is what "unpredictable" needs for
     * a name another local user must not be able to guess or pre-create. It is
     * also half of what this used to spend, because the name is on a socket path
     * (see MAX_TMPDIR_LENGTH) and the saved characters are what leave the
     * capture any headroom at all.
     */
    const PROFILE_NAME_HEX = 16;

    /**
     * Detect an available headless browser.
     *
     * @return array|null { command, label } or null when none available
     */
    public static function backend() {
        if ( ! function_exists( 'exec' ) ) {
            return null;
        }

        if ( defined( 'AGENTSHELL_CHROME_BIN' ) && is_string( AGENTSHELL_CHROME_BIN ) && AGENTSHELL_CHROME_BIN !== '' ) {
            if ( is_executable( AGENTSHELL_CHROME_BIN ) || file_exists( AGENTSHELL_CHROME_BIN ) ) {
                return array( 'command' => AGENTSHELL_CHROME_BIN, 'label' => 'AGENTSHELL_CHROME_BIN' );
            }
        }

        foreach ( array( 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'headless_shell' ) as $binary ) {
            $path = self::which( $binary );
            if ( null !== $path ) {
                return array( 'command' => $path, 'label' => $binary );
            }
        }

        return null;
    }

    /**
     * Capture a screenshot of $url at $width x $height.
     *
     * The caller chooses where the PNG lands. There is deliberately no default
     * under wp-content/uploads/: that path is web-readable, and a capture is a
     * rendering of whatever the agent was looking at — including unpublished
     * drafts — so a public file is a disclosure, not a cache. An inline capture
     * passes a scratch directory it deletes immediately; a stored capture
     * passes Screenshot_Store::screenshot_dir().
     *
     * @param string $url    Fully-qualified URL
     * @param int    $width  Viewport width in px
     * @param int    $height Viewport height in px
     * @param string $dir    Destination directory. Must exist or be creatable,
     *                       and must NOT be a web-served path.
     * @return array { file, url, width, height, backend, timed_out, elapsed_ms }
     * @throws \InvalidArgumentException
     */
    public static function capture( $url, $width, $height, $dir = null ) {
        $backend = self::backend();
        if ( null === $backend ) {
            throw new \InvalidArgumentException(
                'No headless browser found. Install google-chrome/chromium, or define AGENTSHELL_CHROME_BIN in wp-config.php.'
            );
        }

        $width  = max( 320, min( 4096, (int) $width ) );
        $height = max( 240, min( 4096, (int) $height ) );

        if ( null === $dir ) {
            throw new \InvalidArgumentException(
                'Screenshot destination directory is required. Screenshots must not be written to a web-served path; '
                . 'pass Screenshot_Store::screenshot_dir() for a stored capture, or a scratch directory for an inline one.'
            );
        }

        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \InvalidArgumentException( 'Could not create screenshot directory: ' . $dir );
        }

        $file = $dir . '/shot-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.png';

        // Everything below runs under the capture lock. try/finally so the
        // lock is released on success, on browser failure, and on timeout.
        //
        // The browser profile is created and destroyed *inside* the lock, and
        // destroyed in a finally that runs after run_browser() has returned or
        // thrown — which is the point at which the browser's process tree is
        // guaranteed dead. Removing a profile while a surviving renderer still
        // holds files open in it would leave the deletion partial and the
        // leftovers on disk.
        $lock = self::acquire_lock();
        try {
            $profile = self::create_profile_dir();
            try {
                $result = self::run_browser( $backend['command'], $url, $width, $height, $file, $profile );
            } finally {
                self::destroy_profile_dir( $profile );
            }
        } finally {
            self::release_lock( $lock );
        }

        // The dimensions the capture ACTUALLY has.
        //
        // The requested width is what the caller asked for; it is not
        // necessarily what the browser rendered. Headless Chrome enforces a
        // minimum window width (around 485 CSS pixels on the hosts this was
        // measured on), so a request for a 390-pixel mobile viewport is
        // laid out at 485 pixels — and the PNG is a crop of that wider
        // layout, with the right-hand side missing. Reporting the requested
        // width told the caller it had a 390-pixel screenshot, and it did
        // not. Reading the PNG's own header is the only measurement that
        // cannot be wrong.
        $actual = self::png_dimensions( $file );

        $result_row = array(
            'file'    => $file,
            // No public URL. Every route to these bytes is now authenticated;
            // see Screenshot_Store.
            'width'   => $actual['width'] > 0 ? $actual['width'] : $width,
            'height'  => $actual['height'] > 0 ? $actual['height'] : $height,
            'requested_width'  => $width,
            'requested_height' => $height,
            'backend' => $backend['label'],
            'timed_out' => $result['timed_out'],
            'elapsed_ms' => $result['elapsed_ms'],
        );

        if ( $actual['width'] > 0 && ( $actual['width'] !== $width || $actual['height'] !== $height ) ) {
            // Say so. A caller reviewing a "mobile" screenshot has to know
            // that it is not a 390-pixel screenshot.
            $result_row['viewport_adjusted'] = true;
            $result_row['note'] = sprintf(
                'The browser rendered %d×%d, not the requested %d×%d. Headless Chrome enforces a minimum window width, '
                . 'so a narrow viewport is laid out wider than it was asked for and the image is a crop of that layout. '
                . 'The dimensions above are the ones the image actually has.',
                $actual['width'],
                $actual['height'],
                $width,
                $height
            );
        }

        return $result_row;
    }

    /**
     * The pixel dimensions of a PNG, read from its IHDR chunk.
     *
     * A PNG's header carries its width and height in the first bytes of the
     * first chunk, so the measurement needs no image library and cannot be
     * fooled by a truncated file: if the header is not there, the
     * dimensions are reported as unknown (zero) rather than guessed.
     *
     * @param string $path
     * @return array{width:int,height:int}
     */
    public static function png_dimensions( $path ) {
        $out = array( 'width' => 0, 'height' => 0 );
        if ( ! is_string( $path ) || ! is_readable( $path ) ) {
            return $out;
        }
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) {
            return $out;
        }
        $header = fread( $handle, 24 );
        fclose( $handle );
        if ( ! is_string( $header ) || strlen( $header ) < 24 ) {
            return $out;
        }
        // The eight-byte PNG signature, then the IHDR chunk's length and
        // type, then the width and height as two big-endian 32-bit
        // integers. The chunk type is checked as well as the signature: a
        // file that carries the signature but is not a PNG must report
        // unknown dimensions, not a number read out of whatever follows.
        if ( "\x89PNG\r\n\x1a\n" !== substr( $header, 0, 8 ) || 'IHDR' !== substr( $header, 12, 4 ) ) {
            return $out;
        }
        $width  = unpack( 'N', substr( $header, 16, 4 ) );
        $height = unpack( 'N', substr( $header, 20, 4 ) );
        $out['width']  = (int) ( $width[1] ?? 0 );
        $out['height'] = (int) ( $height[1] ?? 0 );
        return $out;
    }

    /**
     * Execute the headless browser under an enforced wall-clock deadline.
     *
     * The browser writes to $file via a private temp path, so a capture that
     * fails or times out leaves nothing behind. The browser itself runs against
     * a throwaway user-data directory that this class owns and removes.
     *
     * @return array { timed_out: bool, elapsed_ms: int }
     * @throws \InvalidArgumentException
     */
    private static function run_browser( $binary, $url, $width, $height, $file, $profile ) {
        $timeout = self::timeout_seconds();

        $dir  = dirname( $file );
        $stem = basename( $file, '.png' );
        $tmp  = $dir . '/.pending-' . $stem . '-' . wp_generate_password( 6, false, false ) . '.png';

        // argv form, not a shell string: the URL and every path are passed as
        // discrete arguments so no part of an untrusted URL can be interpreted
        // as shell syntax.
        $argv = array(
            $binary,
            '--headless',
            '--disable-gpu',
            '--hide-scrollbars',
            '--window-size=' . $width . ',' . $height,
            '--virtual-time-budget=6000',
            // The privacy-critical flag. Without it the browser resolves its
            // default profile location itself, and a rendered draft — plus the
            // preview credential in its URL, and every cookie the site's
            // response set — can persist there across captures and outlive the
            // request that rendered it. See the class docblock.
            '--user-data-dir=' . $profile,
            '--screenshot=' . $tmp,
            $url,
        );

        $wrapped = self::wrap_in_process_group( $argv, $timeout );

        $descriptors = array(
            0 => array( 'file', '/dev/null', 'r' ),
            1 => array( 'file', $tmp . '.log', 'a' ),
            2 => array( 'file', $tmp . '.log', 'a' ),
        );
        $pipes = array();

        $started = microtime( true );
        $handle  = @proc_open( $wrapped, $descriptors, $pipes, null, self::isolated_env( $profile ) );
        if ( ! is_resource( $handle ) ) {
            @unlink( $tmp );
            throw new \InvalidArgumentException( 'Could not start the headless browser process.' );
        }

        $deadline  = $started + $timeout;
        $timed_out = false;
        $exit_code = -1;

        while ( true ) {
            $status = proc_get_status( $handle );
            if ( ! $status['running'] ) {
                $exit_code = (int) $status['exitcode'];
                break;
            }
            if ( microtime( true ) >= $deadline ) {
                $timed_out = true;
                break;
            }
            // Short sleep: the deadline is PHP-enforced, so this loop is what
            // actually guarantees the bound. It must stay responsive.
            usleep( 50000 );
        }

        if ( $timed_out ) {
            // Kill the whole process group, not just the direct child — Chrome
            // spawns a zygote and a process per renderer, and orphans would
            // outlive the request and hold the lock against the next attempt.
            self::kill_process_group( $handle );
        }
        @proc_close( $handle );

        $elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

        // A capture killed by the `timeout` wrapper can leave a perfectly
        // valid-looking PNG behind: the browser may have finished rendering
        // and then hung. Exit 124 is timeout's own marker, 137 is SIGKILL
        // after --kill-after. Either way the capture did NOT complete, so it
        // must not publish — otherwise a half-rendered page is served as if
        // it were the site.
        $killed = in_array( $exit_code, array( 124, 137 ), true );

        if ( $timed_out || $killed ) {
            self::discard_temp( $tmp );
            throw new Screenshot_Timeout_Exception(
                sprintf(
                    'Screenshot timed out after %ds; the browser process group was terminated. '
                    . 'Raise AGENTSHELL_SCREENSHOT_TIMEOUT in wp-config.php (seconds, 1-300) if this page genuinely needs longer.',
                    $timeout
                )
            );
        }

        // Validate before publishing. A truncated or absent file must never
        // be handed back as if it were the site.
        if ( ! file_exists( $tmp ) || filesize( $tmp ) < 100 || ! self::is_png( $tmp ) ) {
            $reason = self::tail( $tmp . '.log' );
            self::discard_temp( $tmp );
            throw new \InvalidArgumentException(
                'Screenshot failed (browser exit ' . $exit_code . ')' . ( '' !== $reason ? ': ' . $reason : '.' )
            );
        }

        if ( ! @rename( $tmp, $file ) ) {
            $reason = self::tail( $tmp . '.log' );
            self::discard_temp( $tmp );
            throw new \InvalidArgumentException( 'Could not publish screenshot to ' . $dir . '.' );
        }
        @unlink( $tmp . '.log' );

        return array( 'timed_out' => false, 'elapsed_ms' => $elapsed_ms );
    }

    /**
     * Environment for the browser process, with every profile location it
     * might reach redirected inside the throwaway directory.
     *
     * `--user-data-dir` alone is not enough to make the capture forgettable.
     * Chromium reads several *other* locations before and alongside the user
     * data dir: `XDG_CONFIG_HOME` and `XDG_CACHE_HOME` for caches and
     * component state, and `$HOME` as the fallback when those are unset —
     * which, under php-fpm with a cleared environment, is frequently empty and
     * resolves via the password-database entry to a real user's home.
     *
     * So HOME is pinned too. That is the belt to `--user-data-dir`'s braces:
     * if the flag were ever dropped, misparsed, or ignored by a future Chrome,
     * the fallback path still lands inside a directory this class deletes.
     *
     * TMPDIR is pinned for the same reason and for a different leak: Chromium
     * creates its own scratch directories (`com.google.Chrome.chrome_chrome_url_fetcher_*`)
     * in the system temp dir while rendering, and because they sit outside the
     * profile they survived the profile's removal — one per capture, on every
     * path including success. Redirecting TMPDIR puts them inside the profile
     * the capture already deletes.
     *
     * PATH is preserved from the parent — `setsid` and `timeout` are resolved
     * by absolute path today, but rebuilding the environment must not quietly
     * strip it from the child's other lookups.
     *
     * `LD_LIBRARY_PATH` and `LD_PRELOAD` are scrubbed. They are the dynamic
     * linker's own configuration, not Chrome's: a parent process that sets
     * them (a build host, a test harness, a PHP-FPM pool configured with a
     * custom shared-libs directory) is configuring PHP's library resolution,
     * not Chrome's, and the values must not leak across the proc_open
     * boundary. Two observed failure modes:
     *
     *  1. A host that bundles a pre-2.34 `libglib-2.0.so.0` in
     *     `LD_LIBRARY_PATH` (e.g. a build-time PHP drop-in) makes Chrome
     *     load an older `libglib` than the system `libgobject-2.0.so.0` was
     *     compiled against. Chrome then fails to resolve `g_dir_unref`
     *     ("symbol lookup error ... undefined symbol: g_dir_unref") and
     *     exits before the first frame. The system libraries are correct;
     *     the inherited path is the wrong resolver for the browser.
     *  2. `LD_PRELOAD` would let the parent force a shared library into
     *     Chrome's address space — a property the capture should never
     *     inherit.
     *
     * Stripping both before proc_open restores Chrome's default library
     * resolution, which is what every other deployment (system Chrome, a
     * docker container, a CI runner) already gives it. PATH stays; the
     * resolved-binary path is independent of the dynamic loader's path.
     *
     * @param string $profile Throwaway user-data directory for this capture.
     * @return array Environment for proc_open().
     */
    private static function isolated_env( $profile ) {
        $env = getenv();
        if ( ! is_array( $env ) ) {
            $env = array();
        }
        $env['HOME']             = self::trust_home_dir( $profile );
        $env['XDG_CONFIG_HOME']  = $profile . '/xdg-config';
        $env['XDG_CACHE_HOME']   = $profile . '/xdg-cache';
        $env['XDG_DATA_HOME']    = $profile . '/xdg-data';
        $env['XDG_STATE_HOME']   = $profile . '/xdg-state';
        $env['TMPDIR']           = self::profile_tmp_dir( $profile );
        unset( $env['LD_LIBRARY_PATH'], $env['LD_PRELOAD'] );
        return $env;
    }

    /**
     * Create a fresh, private, unpredictable browser profile directory.
     *
     * Fresh per capture, because the whole point is that one capture's history
     * cannot be read by the next: a shared profile would carry cookies, cache
     * and form state from a previous render into this one.
     *
     * Unpredictable, because the path is inside a world-writable temp dir. It
     * is created with random_bytes() rather than a counter or a pid, so it
     * cannot be guessed into existence by another local user ahead of us and
     * then symlinked under the capture. assert_private_dir() would refuse such
     * a pre-emptive directory — but refusing means captures stop working, and
     * not guessing is cheaper than defending.
     *
     * The marker file is what makes the stale sweep below safe: it is the
     * difference between "a directory this class created" and "a directory
     * that happens to match a pattern".
     *
     * @return string Absolute path to the new profile directory.
     * @throws \RuntimeException when no private directory can be established.
     */
    public static function create_profile_dir() {
        $base = self::profile_base_dir();
        self::assert_private_dir( $base );

        // A previous run killed before its cleanup handler ran leaves a profile
        // behind. Sweep those first — narrowly, see purge_stale_profiles().
        self::purge_stale_profiles( $base );

        $name = 'profile-' . bin2hex( random_bytes( (int) ( self::PROFILE_NAME_HEX / 2 ) ) );
        $path = $base . '/' . $name;

        // Before the directory exists, so a temp dir too deep for Chromium's
        // singleton socket fails here rather than as a browser FATAL.
        $tmp_dir = self::profile_tmp_dir( $path );
        if ( strlen( $tmp_dir ) > self::MAX_TMPDIR_LENGTH ) {
            throw new \RuntimeException(
                'The system temp directory is too deep for an isolated browser capture. '
                . 'TMPDIR would be ' . strlen( $tmp_dir ) . ' bytes, and Chromium caps a socket '
                . 'path at ' . self::MAX_TMPDIR_LENGTH . '. Set TMPDIR to a shorter path.'
            );
        }

        if ( ! @mkdir( $path, 0700 ) ) {
            throw new \RuntimeException( 'Could not create a private browser profile directory: ' . $path );
        }
        clearstatcache( true, $path );

        // mkdir() with an existing path fails, so a path we just created cannot
        // be a symlink — but verify rather than reason from it, since the
        // failure modes of this code are exactly the ones worth not assuming.
        if ( ! is_dir( $path ) || is_link( $path ) ) {
            throw new \RuntimeException( 'Browser profile path is not a real private directory: ' . $path );
        }
        @chmod( $path, 0700 );
        if ( @file_put_contents( $path . '/' . self::PROFILE_MARKER, self::PROFILE_MARKER_VALUE ) === false ) {
            self::destroy_profile_dir( $path );
            throw new \RuntimeException( 'Could not mark the browser profile directory: ' . $path );
        }

        // HOME and TMPDIR both point inside the profile, so both directories
        // must exist before the browser starts: HOME is where Chromium looks
        // for its trust store, TMPDIR is where it creates scratch directories of
        // its own. Both are created 0700 inside a 0700 parent.
        foreach ( array( self::trust_home_dir( $path ), self::profile_tmp_dir( $path ) ) as $dir ) {
            if ( ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
                self::destroy_profile_dir( $path );
                throw new \RuntimeException( 'Could not create a browser profile subdirectory: ' . $dir );
            }
            @chmod( $dir, 0700 );
        }

        // Last, and inside a try, because by now the profile holds a marker and
        // two subdirectories — a plain rmdir() cannot undo that, so a failure
        // here has to unwind through the same recursive removal every other
        // exit uses or the half-built profile stays for the stale sweep to find
        // an hour later.
        try {
            self::install_trust_store( $path );
        } catch ( \Throwable $e ) {
            self::destroy_profile_dir( $path );
            throw $e;
        }

        // Backstop for the case the brief calls out: PHP killed before its
        // cleanup runs. This cannot help a SIGKILL — nothing does — but it does
        // cover a fatal error, an uncaught exception or a normal exit on a path
        // that skipped the finally. Idempotent, so it is harmless when the
        // finally has already removed the directory.
        register_shutdown_function( array( __CLASS__, 'destroy_profile_dir' ), $path );

        return $path;
    }

    /**
     * HOME for the browser process: the parent of the NSS trust store.
     *
     * @param string $profile
     * @return string
     */
    public static function trust_home_dir( $profile ) {
        return $profile . '/home';
    }

    /**
     * The NSS database Chromium reads its trusted roots from.
     *
     * `$HOME/.pki/nssdb` is NSS's own default for a user, and Chromium follows
     * it. There is no flag to point it elsewhere short of relocating HOME,
     * which this class already does.
     *
     * @param string $profile
     * @return string
     */
    public static function trust_db_dir( $profile ) {
        return self::trust_home_dir( $profile ) . '/.pki/nssdb';
    }

    /**
     * TMPDIR for the browser process.
     *
     * @param string $profile
     * @return string
     */
    public static function profile_tmp_dir( $profile ) {
        return $profile . '/tmp';
    }

    /**
     * Certificate authorities the operator has explicitly trusted for captures.
     *
     * Configured in wp-config.php as AGENTSHELL_TRUSTED_CA_FILES: a string, or
     * an array of strings, each a path to a certificate file or a directory of
     * them. Empty by default, and when empty nothing here runs — a site with a
     * valid certificate chain is unaffected by any of this.
     *
     * A configured path that cannot be read is an error rather than a silent
     * skip. An operator who pinned a CA and then gets ERR_CERT_AUTHORITY_INVALID
     * has no way to tell a typo from a genuinely untrusted certificate, and
     * quietly dropping the entry would present a typo as a trust failure.
     *
     * @return string[] Absolute paths to certificate files.
     * @throws \RuntimeException when a configured certificate is unreadable.
     */
    public static function trusted_ca_files() {
        $configured = array();
        if ( defined( 'AGENTSHELL_TRUSTED_CA_FILES' ) ) {
            $raw = constant( 'AGENTSHELL_TRUSTED_CA_FILES' );
            if ( is_string( $raw ) ) {
                $raw = array( $raw );
            }
            if ( is_array( $raw ) ) {
                $configured = $raw;
            }
        }

        $certs = array();
        foreach ( $configured as $entry ) {
            if ( ! is_string( $entry ) || '' === trim( $entry ) ) {
                continue;
            }
            $path = trim( $entry );
            if ( is_dir( $path ) ) {
                foreach ( (array) glob( rtrim( $path, '/' ) . '/*.{crt,pem,cer,der}', GLOB_BRACE ) as $found ) {
                    $certs[] = $found;
                }
                continue;
            }
            $certs[] = $path;
        }

        foreach ( $certs as $cert ) {
            if ( ! is_file( $cert ) || ! is_readable( $cert ) ) {
                throw new \RuntimeException(
                    'AGENTSHELL_TRUSTED_CA_FILES names a certificate that cannot be read: ' . $cert
                );
            }
        }

        return array_values( array_unique( $certs ) );
    }

    /**
     * Build a fresh, private NSS trust store inside the throwaway profile.
     *
     * Fresh means `certutil -N` on an empty directory: the operator's own
     * database is never opened, copied or merged. Their NSS store is part of
     * their browser session and may contain private keys; this class leaves it
     * alone even when reading it would have been convenient.
     *
     * @param string $profile
     * @return int Number of certificates added, 0 when none are configured.
     * @throws \RuntimeException when certutil is missing or fails.
     */
    private static function install_trust_store( $profile ) {
        $certs = self::trusted_ca_files();
        if ( empty( $certs ) ) {
            return 0;
        }

        $certutil = self::helper_binary( 'certutil', array( '/usr/bin/certutil', '/bin/certutil' ) );
        if ( null === $certutil ) {
            throw new \RuntimeException(
                'AGENTSHELL_TRUSTED_CA_FILES is configured but certutil was not found. Chromium reads its '
                . 'trusted roots from an NSS database and only certutil can create one. Install the NSS '
                . 'tools package, or unset AGENTSHELL_TRUSTED_CA_FILES.'
            );
        }

        $db = self::trust_db_dir( $profile );
        if ( ! @mkdir( $db, 0700, true ) && ! is_dir( $db ) ) {
            throw new \RuntimeException( 'Could not create the browser trust store directory: ' . $db );
        }
        @chmod( $db, 0700 );

        self::run_certutil( $certutil, array( '-N', '--empty-password', '-d', $db ), 'create the trust store' );

        $added = 0;
        foreach ( $certs as $cert ) {
            $added++;
            self::run_certutil(
                $certutil,
                array( '-A', '-n', 'agentshell-ca-' . $added, '-t', 'C,,', '-i', $cert, '-d', $db ),
                'add ' . basename( $cert )
            );
        }

        return $added;
    }

    /**
     * Run certutil with a deadline, so a wedged helper cannot hold the worker.
     *
     * @param string   $bin  Absolute path to certutil.
     * @param string[] $args Arguments after the binary.
     * @param string   $what Human description used in the error message.
     * @throws \RuntimeException on a non-zero exit or a timeout.
     */
    private static function run_certutil( $bin, array $args, $what ) {
        $descriptors = array(
            0 => array( 'file', '/dev/null', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );
        $pipes   = array();
        $process = @proc_open( array_merge( array( $bin ), $args ), $descriptors, $pipes );
        if ( ! is_resource( $process ) ) {
            throw new \RuntimeException( 'Could not run certutil to ' . $what . '.' );
        }

        $deadline = microtime( true ) + 10;
        $exit     = -1;
        $running  = true;
        while ( true ) {
            $status  = proc_get_status( $process );
            $running = (bool) $status['running'];
            if ( ! $running ) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if ( microtime( true ) >= $deadline ) {
                proc_terminate( $process, 9 );
                break;
            }
            usleep( 20000 );
        }

        // Drain before closing so certutil cannot die writing into a full pipe.
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        @proc_close( $process );

        if ( $running ) {
            throw new \RuntimeException( 'certutil timed out trying to ' . $what . '.' );
        }
        if ( 0 !== $exit ) {
            throw new \RuntimeException(
                'certutil failed to ' . $what . ' (exit ' . $exit . '): '
                . trim( (string) $stderr . ' ' . (string) $stdout )
            );
        }
    }

    /**
     * Remove a profile directory and everything the browser wrote into it.
     *
     * Never throws: this runs from a finally and from a shutdown handler, where
     * an exception would mask whatever the capture was actually reporting.
     *
     * @param string $path
     */
    public static function destroy_profile_dir( $path ) {
        if ( ! is_string( $path ) || '' === $path ) {
            return;
        }
        $base = self::profile_base_dir();
        $path = rtrim( $path, '/' );

        // Refuse anything that is not a profile directory we could have
        // created: not the base itself, not outside it, not via a traversal.
        // A rename-based attack that swaps our profile for a symlink must not
        // turn cleanup into an arbitrary recursive delete.
        if ( $path === $base || ! str_starts_with( $path, $base . '/' ) ) {
            return;
        }
        if ( ! preg_match( '/\/profile-[0-9a-f]{16,32}$/', $path ) ) {
            return;
        }
        if ( is_link( $path ) ) {
            @unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }

        self::rm_tree( $path );
    }

    /**
     * Recursive delete that does not follow symlinks.
     *
     * is_link() is tested *before* is_dir(), and that order is the whole
     * safety property: is_dir() resolves a symlink to its target, so testing
     * is_dir() first would recurse into whatever a planted link points at —
     * including a directory far outside the profile being removed. A symlink is
     * unlinked as itself and never descended into.
     *
     * Depth is bounded because Chromium builds a deep profile tree and a
     * pathological one should cost us the profile, not the worker.
     */
    private static function rm_tree( $path, $depth = 0 ) {
        if ( $depth > 32 ) {
            return false;
        }
        if ( is_link( $path ) ) {
            return @unlink( $path );
        }
        if ( ! is_dir( $path ) ) {
            return @unlink( $path );
        }

        $entries = @scandir( $path );
        if ( ! is_array( $entries ) ) {
            return false;
        }
        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            self::rm_tree( $path . '/' . $entry, $depth + 1 );
        }
        return @rmdir( $path );
    }

    /**
     * Remove profile directories abandoned by a killed run.
     *
     * Deliberately narrow, because the alternative — a sweeper that deletes
     * things in the temp dir — is a foot-gun pointed at every other process on
     * the host. A directory is removed only when *all* of these hold:
     *
     *   - it is a direct child of our own per-uid private base;
     *   - its basename matches `profile-<32 hex>`, the exact shape we create;
     *   - it is a real directory, not a symlink;
     *   - it is owned by us;
     *   - it carries our marker file with our marker value;
     *   - it is older than PROFILE_STALE_SECONDS, which is several times the
     *     maximum capture timeout, so a slow live capture is never swept.
     *
     * Anything else is left alone. Failing to clean up is recoverable; deleting
     * a stranger's directory is not.
     *
     * Called under the capture lock, so a concurrent capture's profile cannot
     * be caught by its own sweep.
     */
    private static function purge_stale_profiles( $base, $now = null ) {
        $now  = null === $now ? time() : (int) $now;
        $self = self::euid();

        $entries = @scandir( $base );
        if ( ! is_array( $entries ) ) {
            return 0;
        }

        $removed = 0;
        foreach ( $entries as $entry ) {
            if ( ! preg_match( '/^profile-[0-9a-f]{16,32}$/', $entry ) ) {
                continue;
            }
            $path = $base . '/' . $entry;
            if ( is_link( $path ) || ! is_dir( $path ) ) {
                continue;
            }
            if ( null !== $self && (int) @fileowner( $path ) !== $self ) {
                continue;
            }
            $marker = @file_get_contents( $path . '/' . self::PROFILE_MARKER );
            if ( self::PROFILE_MARKER_VALUE !== $marker ) {
                continue;
            }
            $mtime = @filemtime( $path );
            if ( ! is_int( $mtime ) || ( $now - $mtime ) < self::PROFILE_STALE_SECONDS ) {
                continue;
            }
            if ( self::rm_tree( $path ) ) {
                $removed++;
            }
        }
        return $removed;
    }

    private static function euid() {
        return function_exists( 'posix_geteuid' ) ? (int) posix_geteuid() : null;
    }

    /**
     * Private per-uid directory holding this site's browser profiles.
     *
     * Under sys_get_temp_dir() like the lock, and per-uid for the same reason:
     * the temp dir is world-writable, so a name another user could claim is a
     * name another user could own.
     */
    public static function profile_base_dir() {
        $uid = self::euid();
        return rtrim( sys_get_temp_dir(), '/' ) . '/agentshell-chrome-' . ( null === $uid ? 'na' : $uid );
    }

    /**
     * Prefix argv with setsid so the browser leads its own process group.
     *
     * Without this the browser shares the PHP-FPM worker's process group, and
     * the group kill used on timeout would take PHP down with it. `timeout` is
     * an independent second line of defence: it fires even if this PHP process
     * is itself wedged and never reaches its own deadline check.
     *
     * Each helper is verified with is_executable() rather than trusted by PATH,
     * because a php-fpm pool's PATH is not the shell's.
     *
     * @return array argv
     */
    private static function wrap_in_process_group( array $argv, $timeout ) {
        $setsid  = self::helper_binary( 'setsid', array( '/usr/bin/setsid', '/bin/setsid' ) );
        $timeout_bin = self::helper_binary( 'timeout', array( '/usr/bin/timeout', '/bin/timeout' ) );

        if ( null !== $timeout_bin ) {
            // --kill-after forces SIGKILL if the browser ignores SIGTERM, so a
            // wedged browser cannot outlive its budget by ignoring a signal.
            $argv = array_merge(
                array( $timeout_bin, '--signal=TERM', '--kill-after=5', (string) $timeout ),
                $argv
            );
        }
        if ( null !== $setsid ) {
            $argv = array_merge( array( $setsid ), $argv );
        }

        return $argv;
    }

    private static function helper_binary( $name, array $candidates ) {
        $override = 'AGENTSHELL_SCREENSHOT_' . strtoupper( $name ) . '_BIN';
        if ( defined( $override ) && is_string( constant( $override ) ) && '' !== constant( $override ) ) {
            return is_executable( constant( $override ) ) ? constant( $override ) : null;
        }
        foreach ( $candidates as $candidate ) {
            if ( is_executable( $candidate ) ) {
                return $candidate;
            }
        }
        $found = self::which( $name );
        return null !== $found ? $found : null;
    }

    /**
     * Terminate the browser and everything it spawned.
     *
     * A group kill is only meaningful when the browser leads its own group,
     * i.e. when setsid() actually ran: negating a pid that is not a group
     * leader names a group that does not exist, and posix_kill() discards the
     * signal silently — no error, nothing killed. So the group signal is only
     * sent when the child really is its own group leader, and otherwise the
     * tree is walked through /proc and killed leaf-first.
     *
     * Either way proc_terminate() is the final backstop for the direct child.
     */
    private static function kill_process_group( $handle ) {
        $status = proc_get_status( $handle );
        $pid    = isset( $status['pid'] ) ? (int) $status['pid'] : 0;
        $self   = getmypid();

        // Never signal our own process or our own process group: if the child
        // has already exited and its pid was recycled, a negative pid would
        // name whatever group now owns that number.
        if ( $pid <= 1 || $pid === $self ) {
            @proc_terminate( $handle, 9 );
            return;
        }

        $leads_group = false;
        if ( function_exists( 'posix_getpgid' ) && function_exists( 'posix_kill' ) ) {
            $pgid = @posix_getpgid( $pid );
            // $pgid === $pid is the whole safety argument: only a group leader
            // may be signalled by negated pid, and our own group is led by
            // someone else. Anything else (including false, once the child is
            // gone and the pid recycled) takes the descendant path, which only
            // ever names processes that are provably our descendants.
            $leads_group = ( $pgid === $pid )
                && function_exists( 'posix_getpgrp' )
                && @posix_getpgrp() !== $pid;

            if ( $leads_group ) {
                @posix_kill( -$pid, 9 );
            }
        }

        if ( ! $leads_group ) {
            // The browser shares our group, so we cannot signal the group
            // without signalling PHP itself. Kill its descendants instead.
            foreach ( array_reverse( self::descendants_of( $pid, $self ) ) as $descendant ) {
                if ( $descendant > 1 && $descendant !== $self ) {
                    @posix_kill( $descendant, 9 );
                }
            }
        }

        @proc_terminate( $handle, 9 );
    }

    /**
     * All living descendants of $pid, parents before children.
     *
     * Reads /proc directly rather than shelling out to `ps`, because a
     * php-fpm pool's PATH is not the shell's. Returns an empty array when the
     * process has already exited or /proc is unavailable.
     *
     * @return int[]
     */
    private static function descendants_of( $pid, $self ) {
        if ( ! is_dir( '/proc' ) ) {
            return array();
        }

        // parent_pid => [child_pid, ...]
        $children = array();
        foreach ( glob( '/proc/[0-9]*/stat' ) as $stat_file ) {
            $stat = @file_get_contents( $stat_file );
            if ( ! is_string( $stat ) ) {
                continue;
            }
            // comm can contain spaces and parentheses, so parse from the last ')'.
            $tail = strrchr( $stat, ')' );
            if ( false === $tail ) {
                continue;
            }
            $fields = preg_split( '/\s+/', trim( substr( $tail, 1 ) ) );
            if ( ! is_array( $fields ) || count( $fields ) < 2 ) {
                continue;
            }
            $child_pid  = (int) basename( dirname( $stat_file ) );
            $parent_pid = (int) $fields[1];
            // Never traverse into ourselves: /proc lists us and our parents, and
            // a recycled pid could otherwise make us a descendant of the pid we
            // are walking from.
            if ( $child_pid === $self || $parent_pid === $self ) {
                continue;
            }
            if ( $child_pid > 0 && $parent_pid > 0 ) {
                $children[ $parent_pid ][] = $child_pid;
            }
        }

        $found    = array();
        $frontier = array( $pid );
        while ( $frontier ) {
            $next = array();
            foreach ( $frontier as $parent ) {
                foreach ( $children[ $parent ] ?? array() as $child ) {
                    if ( in_array( $child, $found, true ) ) {
                        continue;
                    }
                    $found[]   = $child;
                    $next[]    = $child;
                }
            }
            $frontier = $next;
        }

        return $found;
    }

    private static function discard_temp( $tmp ) {
        @unlink( $tmp );
        @unlink( $tmp . '.log' );
    }

    /** PNG magic-number check — a size check alone passes on truncated files. */
    private static function is_png( $path ) {
        $fh = @fopen( $path, 'rb' );
        if ( false === $fh ) { return false; }
        $magic = fread( $fh, 8 );
        fclose( $fh );
        return "\x89PNG\r\n\x1a\n" === $magic;
    }

    private static function tail( $path, $lines = 3 ) {
        if ( ! is_readable( $path ) ) { return ''; }
        $raw = @file( $path, FILE_IGNORE_NEW_LINES );
        if ( ! is_array( $raw ) ) { return ''; }
        $raw = array_slice( array_values( array_filter( $raw, 'strlen' ) ), -$lines );
        return trim( implode( ' | ', $raw ) );
    }

    /**
     * Hard wall-clock budget for one capture, in seconds.
     *
     * Clamped so a typo in wp-config.php cannot disable the bound entirely.
     */
    public static function timeout_seconds() {
        $seconds = defined( 'AGENTSHELL_SCREENSHOT_TIMEOUT' ) ? (int) constant( 'AGENTSHELL_SCREENSHOT_TIMEOUT' ) : 30;
        return max( 1, min( 300, $seconds ) );
    }

    /**
     * Cross-process capture lock.
     *
     * flock() is advisory and operates on the open file description, so it
     * excludes other PHP-FPM workers on the same host — which is exactly the
     * set that must not run two captures at once. The file lives in the system
     * temp dir, never under uploads/, so the lock's existence and timing are
     * not web-readable.
     *
     * The system temp dir is world-writable, so the path is not trusted on its
     * own: it lives in a private 0700 directory that we verify we own, and the
     * file is verified after opening rather than before. Opening with 'c'
     * follows symlinks, so a pre-planted symlink would otherwise let another
     * local user aim our lock at a file of their choosing. Comparing the opened
     * handle against an lstat of the path catches that (different inode), as
     * does an ownership check — flock on someone else's file is not a lock we
     * control.
     *
     * @return resource handle
     * @throws \Screenshot_Busy_Exception when another capture holds the lock
     */
    public static function acquire_lock() {
        $path = self::lock_path();
        $dir  = dirname( $path );
        self::assert_private_dir( $dir );

        // 'c' = open for write, create if absent, do not truncate. Nothing is
        // ever written through this handle; it exists only to be locked.
        $handle = @fopen( $path, 'c' );
        if ( false === $handle ) {
            throw new \RuntimeException( 'Could not open the screenshot lock file: ' . $path );
        }

        $problem = self::lock_file_problem( $path, $handle );
        if ( null !== $problem ) {
            fclose( $handle );
            throw new \RuntimeException( $problem );
        }
        @chmod( $path, 0600 );

        // LOCK_NB: fail immediately rather than queue behind a wedged capture.
        // A queued request would hold a PHP-FPM worker for the entire duration
        // of the capture ahead of it, which is the starvation this lock exists
        // to prevent.
        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            throw new Screenshot_Busy_Exception(
                'Another screenshot capture is already running; only one capture may run at a time. '
                . 'Retry shortly, or serialize captures in the calling workflow.'
            );
        }

        return $handle;
    }

    /**
     * Ensure $dir is a real, private directory that belongs to us.
     *
     * mkdir() is not sufficient on its own: it succeeds quietly when the
     * directory already exists, so an attacker who won the race to create
     * agentshell-screenshot-<uid>/ first would otherwise own the lock. The
     * name embeds our uid, the directory is 0700, and we verify ownership
     * before using it. Refusing loudly is the point — a lock we cannot trust is
     * worse than no lock, because it looks like it is working.
     */
    private static function assert_private_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0700, true );
        }
        clearstatcache( true, $dir );

        if ( ! is_dir( $dir ) ) {
            throw new \RuntimeException( 'Could not create the screenshot lock directory: ' . $dir );
        }
        if ( is_link( $dir ) ) {
            throw new \RuntimeException( 'Refusing to use a symlinked screenshot lock directory: ' . $dir );
        }

        $owner = @fileowner( $dir );
        $uid   = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
        if ( null !== $uid && false !== $owner && (int) $owner !== (int) $uid ) {
            throw new \RuntimeException(
                'Screenshot lock directory is owned by uid ' . (int) $owner . ', not ' . (int) $uid
                . ': ' . $dir
            );
        }

        // Tighten permissions if the directory was pre-created wider than 0700.
        @chmod( $dir, 0700 );
    }

    /**
     * Verify the opened lock file is the file we meant to open.
     *
     * Runs after fopen(), because that is the only point at which the resolved
     * target is known. Returns a human-readable problem, or null when the
     * handle is sound.
     *
     * @param resource $handle
     * @return string|null
     */
    private static function lock_file_problem( $path, $handle ) {
        clearstatcache( true, $path );

        if ( is_link( $path ) ) {
            // Deliberately fatal and deliberately not self-healing. Removing a
            // path we cannot prove is safe to remove is exactly the wrong
            // reflex for a symlink. But say what to do: without this the
            // operator sees a lock failure with no route to a fix, and the
            // capture stays broken until they find this file by hand.
            return 'Refusing to lock a symlinked screenshot lock file: ' . $path
                . ' — another process may have placed it, or a previous run was interrupted mid-write. '
                . 'Inspect it, then remove it (or the containing '
                . dirname( $path ) . ' directory) to restore captures.';
        }

        $stat = @fstat( $handle );
        if ( ! is_array( $stat ) ) {
            return 'Could not stat the screenshot lock file: ' . $path;
        }

        // Different inode => the path resolved somewhere else (a symlink, or a
        // file replaced between our lstat and the open).
        $link = @lstat( $path );
        if ( is_array( $link ) && isset( $stat['ino'] ) && (int) $link['ino'] !== (int) $stat['ino'] ) {
            return 'Screenshot lock file resolved to a different inode than its path: ' . $path;
        }

        $uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
        if ( null !== $uid && isset( $stat['uid'] ) && (int) $stat['uid'] !== (int) $uid ) {
            return 'Screenshot lock file is owned by uid ' . (int) $stat['uid'] . ', not ' . (int) $uid . ': ' . $path;
        }

        return null;
    }

    public static function release_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            @flock( $handle, LOCK_UN );
            @fclose( $handle );
        }
    }

    /**
     * Lock file path.
     *
     * Scoped per-site so two WordPress installs sharing a host do not lock each
     * other out of captures entirely, and per-uid so two users on the same host
     * cannot collide on — or gain write access to — each other's lock. The site
     * host is hashed rather than used verbatim, keeping the filename
     * filesystem-safe.
     *
     * The directory name embeds the uid, which is what makes assert_private_dir()
     * meaningful: an unrelated user cannot guess it into existence and own it.
     */
    public static function lock_path() {
        $base = sys_get_temp_dir();
        $site = function_exists( 'home_url' ) ? (string) home_url( '/' ) : 'agentshell';
        $uid  = function_exists( 'posix_geteuid' ) ? (int) posix_geteuid() : 0;

        // The lock lives in a per-uid private directory, NOT directly in the
        // temp dir. sys_get_temp_dir() is typically /tmp, which is
        // world-writable: a file dropped straight into it would be visible to
        // every local user, and its ownership would not be ours to assert.
        return rtrim( $base, '/' ) . '/agentshell-screenshots-' . $uid
            . '/' . substr( md5( $site ), 0, 16 ) . '.lock';
    }

    private static function which( $binary ) {
        $paths = explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) );
        foreach ( $paths as $path ) {
            if ( $path === '' ) {
                continue;
            }
            $candidate = rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $binary;
            if ( is_executable( $candidate ) ) {
                return $candidate;
            }
        }
        // Fall back to shell lookup (covers aliases, snap, flatpak wrappers).
        $found = shell_exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null' );
        $found = is_string( $found ) ? trim( $found ) : '';
        return $found !== '' && is_executable( $found ) ? $found : null;
    }
}