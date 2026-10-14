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
 * Screenshots land in wp-content/uploads/agentshell-screenshots/.
 */
class Screenshot {
    const VIEWPORTS = array(
        'desktop' => array( 1280, 800 ),
        'mobile'  => array( 390, 844 ),
        'tablet'  => array( 768, 1024 ),
    );

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
     * @param string $url    Fully-qualified URL
     * @param int    $width  Viewport width in px
     * @param int    $height Viewport height in px
     * @return array { file, url, width, height, backend, label }
     * @throws \InvalidArgumentException
     */
    public static function capture( $url, $width, $height ) {
        $backend = self::backend();
        if ( null === $backend ) {
            throw new \InvalidArgumentException(
                'No headless browser found. Install google-chrome/chromium, or define AGENTSHELL_CHROME_BIN in wp-config.php.'
            );
        }

        $width  = max( 320, min( 4096, (int) $width ) );
        $height = max( 240, min( 4096, (int) $height ) );

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            throw new \InvalidArgumentException( 'Upload directory is not writable: ' . $uploads['error'] );
        }
        $dir = $uploads['basedir'] . '/agentshell-screenshots';
        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \InvalidArgumentException( 'Could not create screenshot directory: ' . $dir );
        }

        $file = $dir . '/shot-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.png';

        // Everything below runs under the capture lock. try/finally so the
        // lock is released on success, on browser failure, and on timeout.
        $lock = self::acquire_lock();
        try {
            $result = self::run_browser( $backend['command'], $url, $width, $height, $file );
        } finally {
            self::release_lock( $lock );
        }

        return array(
            'file'    => $file,
            'url'     => $uploads['baseurl'] . '/agentshell-screenshots/' . basename( $file ),
            'width'   => $width,
            'height'  => $height,
            'backend' => $backend['label'],
            'timed_out' => $result['timed_out'],
            'elapsed_ms' => $result['elapsed_ms'],
        );
    }

    /**
     * Execute the headless browser under an enforced wall-clock deadline.
     *
     * The browser writes to $file via a private temp path, so a capture that
     * fails or times out leaves nothing behind.
     *
     * @return array { timed_out: bool, elapsed_ms: int }
     * @throws \InvalidArgumentException
     */
    private static function run_browser( $binary, $url, $width, $height, $file ) {
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
        $handle  = @proc_open( $wrapped, $descriptors, $pipes );
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
        // reach uploads/ where a later request could serve it.
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
            return 'Refusing to lock a symlinked screenshot lock file: ' . $path;
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