<?php
/**
 * Screenshot capture regressions.
 *
 * These are EXECUTABLE tests, not source-text pins. The availability defect
 * these cover (two concurrent captures deadlocking a PHP-FPM pool) cannot be
 * detected by grepping source — it only exists in the interaction between
 * processes, so every test here drives the real capture() code path through
 * isolated child PHP processes.
 *
 * WHY THE CHILD-PROCESS HARNESS
 * -----------------------------
 * The deadlock is the bug under test. Reproducing it inside a single PHP
 * process would prove nothing: the lock is enforced by flock(), which is
 * cross-process by definition. Two handles in one process are not two
 * contenders. So each "request" is a genuinely separate `php` invocation.
 *
 * These children run against a temporary upload directory with a fake browser
 * script. They never touch the live LocalWP site, and no test here points a
 * capture at a real URL — the fake browser never makes a network request.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fake-browser.php';

// The class under test, loaded directly. bootstrap.php does not pull in the
// plugin, and the parent process needs the real Screenshot for lock_path() and
// the source-contract assertions.
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php';

use AgentShell_MCP\Screenshot;

/** Strip comments so a flag named in prose is not mistaken for a flag in code. */
class Screenshot_Test_Helpers {
    public static function code_without_comments( $php ) {
        $php = preg_replace( '#/\*.*?\*/#s', '', $php );
        $php = preg_replace( '#//[^\n]*#', '', $php );
        return $php;
    }
}


// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

/**
 * Child script: performs exactly one capture and reports the outcome as JSON.
 *
 * This is the "one PHP-FPM worker handling one screenshot request" stand-in.
 *
 * argv: 1 class-file  2 browser  3 url  4 mode  5 workdir  6 timeout
 *       7 linger  8 helpers (comma list of helpers to make unavailable)
 */
function agentshell_capture_child_source() {
    return <<<'PHP'
<?php
$class_file = $argv[1];
$browser    = $argv[2];
$url        = $argv[3];
$mode       = $argv[4];
$workdir    = $argv[5];
$timeout    = (int) $argv[6];
// Stay alive after the capture so a parent can probe the lock while this
// process still holds whatever it held. Process exit releases an flock()
// implicitly, so a test that only checks the lock AFTER the child exits
// proves nothing about the release path.
$linger     = (int) ( $argv[7] ?? 0 );

// Helpers the caller wants to simulate as unavailable. A php-fpm pool may run
// in an environment where /usr/bin/timeout is not present or not executable,
// and the deadline must still hold without it.
$helpers    = array_filter( explode( ',', (string) ( $argv[8] ?? '' ) ) );
if ( in_array( 'timeout', $helpers, true ) ) {
    define( 'AGENTSHELL_SCREENSHOT_TIMEOUT_BIN', $workdir . '/no-such-timeout-binary' );
}
if ( in_array( 'setsid', $helpers, true ) ) {
    define( 'AGENTSHELL_SCREENSHOT_SETSID_BIN', $workdir . '/no-such-setsid-binary' );
}

// Isolate this child's temp dir (lock file + logs) and upload dir.
putenv( 'TMPDIR=' . $workdir );

define( 'ABSPATH', __DIR__ );
$GLOBALS['agentshell_test_uploads'] = array(
    'basedir' => $workdir . '/uploads',
    'baseurl' => 'https://example.test/wp-content/uploads',
);

function wp_upload_dir() {
    return array(
        'basedir' => $GLOBALS['agentshell_test_uploads']['basedir'],
        'baseurl' => $GLOBALS['agentshell_test_uploads']['baseurl'],
        'error'   => '',
    );
}
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function wp_generate_password( $len = 12, $special = true, $extra = false ) {
    return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
}
function home_url( $p = '/' ) { return 'https://example.test' . $p; }

require $class_file;

use AgentShell_MCP\Screenshot;

define( 'AGENTSHELL_CHROME_BIN', $browser );
putenv( 'AGENTSHELL_FAKE_CHROME_MODE=' . $mode );
define( 'AGENTSHELL_SCREENSHOT_TIMEOUT', $timeout );

$out = array( 'ok' => false );
$t0 = microtime( true );
try {
    $shot = Screenshot::capture( $url, (int) ( $argv[9] ?? 800 ), (int) ( $argv[10] ?? 600 ), $workdir . '/shots' );
    $out = array(
        'ok'      => true,
        'file'    => $shot['file'],
        'exists'  => file_exists( $shot['file'] ),
        'size'    => file_exists( $shot['file'] ) ? filesize( $shot['file'] ) : 0,
        'is_png'  => file_exists( $shot['file'] )
            && substr( (string) file_get_contents( $shot['file'] ), 0, 8 ) === "\x89PNG\r\n\x1a\n",
        'elapsed' => $shot['elapsed_ms'],
        // What the capture code reports about the image it produced.
        'width'   => $shot['width'],
        'height'  => $shot['height'],
        'requested_width'  => $shot['requested_width'] ?? $shot['width'],
        'requested_height' => $shot['requested_height'] ?? $shot['height'],
        'viewport_adjusted' => ! empty( $shot['viewport_adjusted'] ),
        'png_width'  => \AgentShell_MCP\Screenshot::png_dimensions( $shot['file'] )['width'],
        'png_height' => \AgentShell_MCP\Screenshot::png_dimensions( $shot['file'] )['height'],
    );
} catch ( \AgentShell_MCP\Screenshot_Busy_Exception $e ) {
    $out = array( 'ok' => false, 'kind' => 'busy', 'message' => $e->getMessage() );
} catch ( \AgentShell_MCP\Screenshot_Timeout_Exception $e ) {
    $out = array( 'ok' => false, 'kind' => 'timeout', 'message' => $e->getMessage() );
} catch ( \Throwable $e ) {
    $out = array( 'ok' => false, 'kind' => 'error', 'message' => $e->getMessage() );
}
$out['elapsed'] = $out['elapsed'] ?? (int) round( ( microtime( true ) - $t0 ) * 1000 );

// Linger: the capture is over, but this worker is still alive. A parent can
// now probe the lock and tell an explicit release apart from the implicit
// release that process exit would have given us anyway.
file_put_contents( $workdir . '/done.marker', '1' );
echo json_encode( $out );
if ( $linger > 0 ) { sleep( $linger ); }
PHP;
}

class Screenshot_Test_Case {
    public $dir;
    public $uploads;
    /** Destination the child passes to capture(). Stands in for private storage. */
    public $shots;
    public $browser;
    public $class_file;
    public $child;

    public function set_up() {
        // Short by necessity. This directory is each case's TMPDIR, so the
        // profile the browser ends up with sits at
        // <dir>/agentshell-chrome-<uid>/profile-<16 hex>/tmp — a Chromium socket
        // path, capped by Screenshot::MAX_TMPDIR_LENGTH. A self-describing name
        // would put every case over the cap and fail for a reason unrelated to
        // what the case is testing.
        static $case = 0;
        $this->dir   = '/tmp/ast' . getmypid() . '-' . ( ++$case );
        mkdir( $this->dir, 0700, true );
        $this->uploads = $this->dir . '/uploads';
        mkdir( $this->uploads, 0755, true );
        // capture() no longer chooses its own destination: the caller passes a
        // directory outside every web-served path. Tests assert against it
        // directly rather than against a hardcoded uploads subdirectory.
        $this->shots = $this->dir . '/shots';
        mkdir( $this->shots, 0700, true );

        $this->browser    = agentshell_fake_browser_path( $this->dir );
        $this->class_file = dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php';
        $this->child      = $this->dir . '/child.php';
        file_put_contents( $this->child, agentshell_capture_child_source() );

        // Every child must use its own lock file, or suites would contend
        // with each other rather than with the scenario under test.
        putenv( 'TMPDIR=' . $this->dir );
    }

    public function tear_down() {
        self::rmtree( $this->dir );
        putenv( 'TMPDIR' );
    }

    public static function rmtree( $path ) {
        if ( ! is_dir( $path ) ) { @unlink( $path ); return; }
        foreach ( scandir( $path ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) { continue; }
            self::rmtree( $path . '/' . $entry );
        }
        @rmdir( $path );
    }

    /**
     * Is the capture lock currently held by another live process?
     *
     * Uses the same flock() primitive production uses. Returns false (i.e.
     * "not held") when the lock can be taken.
     */
    public function lock_is_held( $mode ) {
        putenv( 'TMPDIR=' . $this->dir );
        $handle = @fopen( $this->child_lock_path(), 'c' );
        if ( false === $handle ) { return false; }
        $free = flock( $handle, LOCK_EX | LOCK_NB );
        if ( $free ) { flock( $handle, LOCK_UN ); }
        fclose( $handle );
        return ! $free;
    }

    /** The lock path a child will use, computed the same way the child does. */
    public function child_lock_path() {
        // sys_get_temp_dir() honours TMPDIR when evaluated in a fresh process;
        // compute it here by shelling out so caching cannot mislead us.
        return trim( (string) shell_exec(
            sprintf(
                'TMPDIR=%s %s -r %s',
                escapeshellarg( $this->dir ),
                escapeshellarg( PHP_BINARY ),
                escapeshellarg(
                    'putenv("TMPDIR=' . addslashes( $this->dir ) . '");' .
                    'echo sys_get_temp_dir();'
                )
            )
        ) ) . '/agentshell-screenshot-' . substr( md5( 'https://example.test/' ), 0, 16 ) . '.lock';
    }

    /** argv for one capture child. */
    public function argv_for( $mode, $timeout, $url, $linger = 0, $helpers = '', $width = 800, $height = 600 ) {
        return sprintf(
            '%s %s %s %s %s %s %s %d %d %s %d %d',
            escapeshellarg( PHP_BINARY ),
            escapeshellarg( $this->child ),
            escapeshellarg( $this->class_file ),
            escapeshellarg( $this->browser ),
            escapeshellarg( $url ),
            escapeshellarg( $mode ),
            escapeshellarg( $this->dir ),
            $timeout,
            $linger,
            escapeshellarg( $helpers ),
            (int) $width,
            (int) $height
        );
    }

    /** Run one capture in a separate PHP process. */
    public function run_capture( $mode = 'success', $timeout = 5, $url = 'https://example.test/', $linger = 0, $helpers = '', $width = 800, $height = 600 ) {
        $raw = shell_exec( $this->argv_for( $mode, $timeout, $url, $linger, $helpers, $width, $height ) . ' 2>/dev/null' );
        $out = json_decode( (string) $raw, true );
        if ( ! is_array( $out ) ) {
            return array( 'ok' => false, 'kind' => 'harness', 'message' => 'child produced no JSON: ' . var_export( $raw, true ) );
        }
        return $out;
    }

    /** Start a capture asynchronously; returns the child pid. */
    public function start_capture( $mode = 'success', $timeout = 5, $url = 'https://example.test/', $linger = 0, $helpers = '' ) {
        return (int) trim( (string) shell_exec(
            $this->argv_for( $mode, $timeout, $url, $linger, $helpers ) . ' > /dev/null 2>&1 & echo $!'
        ) );
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

echo "-- screenshot capture regressions --\n";

/** 1. One successful capture. */
Agentshell_Test::run( 'capture: a successful run produces a valid PNG in the caller-chosen directory', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        $out = $t->run_capture( 'success', 5 );
        Agentshell_Test::assert_true( $out['ok'], 'capture should succeed: ' . json_encode( $out ) );
        Agentshell_Test::assert_true( $out['exists'], 'the published file must exist' );
        Agentshell_Test::assert_true( $out['is_png'], 'the published file must carry PNG magic' );
        Agentshell_Test::assert_true( $out['size'] > 100, 'the published file must be non-trivial' );
        Agentshell_Test::assert_true( false === strpos( $out['file'], '.pending-' ), 'no temp path may leak into the result' );
    } finally { $t->tear_down(); }
});

/** 2. Two simultaneous requests: the second fails immediately. */
Agentshell_Test::run( 'lock: a concurrent capture is refused immediately, not queued', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // The first capture hangs, holding the lock.
        $pid = $t->start_capture( 'hang', 8 );
        Agentshell_Test::assert_true( $pid > 0, 'the first capture must start' );

        // Wait for a signal that the first capture is genuinely inside
        // capture() — the fake browser records its grandchild pid once it is
        // running. Probing the parent's own lock path would not work:
        // sys_get_temp_dir() caches on first call, so the parent cannot see
        // the child's TMPDIR.
        $marker = $t->shots . '/.pending-*.png.gc';
        $in_flight = false;
        for ( $i = 0; $i < 100; $i++ ) {
            if ( glob( $marker ) ) { $in_flight = true; break; }
            usleep( 50000 );
        }
        Agentshell_Test::assert_true( $in_flight, 'the first capture must reach the browser before the second is issued' );

        $start = microtime( true );
        $second = $t->run_capture( 'success', 5 );
        $elapsed = microtime( true ) - $start;

        Agentshell_Test::assert_false( $second['ok'], 'the second capture must be refused' );
        Agentshell_Test::assert_equals( 'busy', $second['kind'] ?? '', 'the refusal must be reported as busy' );
        // "Immediately" is the load-bearing word: this is what stops a queued
        // request from holding a PHP-FPM worker for the whole first capture.
        Agentshell_Test::assert_true( $elapsed < 3, sprintf( 'the refusal must be immediate (took %.1fs)', $elapsed ) );

        // And the first must be cleaned up.
        exec( 'kill -9 ' . (int) $pid . ' 2>/dev/null' );
        usleep( 300000 );
    } finally { $t->tear_down(); }
});

/** 3. A hung capture reaches the hard deadline. */
Agentshell_Test::run( 'timeout: a hung browser is cut off at the deadline', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        $start = microtime( true );
        $out   = $t->run_capture( 'hang', 2 ); // 2s budget against an infinite hang
        $elapsed = microtime( true ) - $start;

        Agentshell_Test::assert_false( $out['ok'], 'a hung capture must not report success' );
        Agentshell_Test::assert_equals( 'timeout', $out['kind'] ?? '', 'a hang must be distinguishable from a browser failure' );
        Agentshell_Test::assert_true( $elapsed < 12, sprintf( 'the deadline must actually fire (took %.1fs)', $elapsed ) );
    } finally { $t->tear_down(); }
});

/** 4. Browser process-tree termination: no orphaned Chrome. */
Agentshell_Test::run( 'timeout: the browser process tree is terminated, no orphans', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        $out = $t->run_capture( 'hang', 2 );
        Agentshell_Test::assert_equals( 'timeout', $out['kind'] ?? '', 'precondition: the capture must time out' );

        // The fake browser wrote its grandchild's pid next to its output file.
        $markers = glob( $t->shots . '/.pending-*.png.gc' );
        Agentshell_Test::assert_true( is_array( $markers ) && count( $markers ) > 0, 'the fake browser must have recorded a grandchild pid' );

        $gc = (int) trim( (string) file_get_contents( $markers[0] ) );
        Agentshell_Test::assert_true( $gc > 0, 'the grandchild pid must be readable' );

        // Give the kill a moment to land.
        usleep( 400000 );
        $alive = posix_kill( $gc, 0 );
        Agentshell_Test::assert_false( $alive, sprintf( 'grandchild %d must be dead after timeout (orphans would outlive the request)', $gc ) );
    } finally { $t->tear_down(); }
});

/** 5. Lock release after failure: a browser failure must not wedge the lock. */
Agentshell_Test::run( 'lock: released after a browser failure', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        $out = $t->run_capture( 'fail', 5 );
        Agentshell_Test::assert_false( $out['ok'], 'the failing capture must not report success' );
        Agentshell_Test::assert_equals( 'error', $out['kind'] ?? '', 'a browser failure must be distinct from busy/timeout' );

        // Proof by re-acquisition, not just by inspection. The parent cannot
        // probe the child's lock directly (sys_get_temp_dir() caches on first
        // call), so a successful later capture IS the release evidence.
        $after = $t->run_capture( 'success', 5 );
        Agentshell_Test::assert_true( $after['ok'], 'a later capture must succeed after an earlier failure: ' . json_encode( $after ) );
    } finally { $t->tear_down(); }
});

/** 6. Successful capture after a previous timeout. */
Agentshell_Test::run( 'lock: released after a timeout, later capture succeeds', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        $out = $t->run_capture( 'hang', 2 );
        Agentshell_Test::assert_equals( 'timeout', $out['kind'] ?? '', 'precondition: the first capture must time out' );
        $after = $t->run_capture( 'success', 5 );
        Agentshell_Test::assert_true( $after['ok'], 'a capture after a timeout must succeed: ' . json_encode( $after ) );
        Agentshell_Test::assert_true( $after['is_png'], 'the post-timeout capture must be a valid PNG' );
    } finally { $t->tear_down(); }
});

/** 7. Failed captures never leave a stale or truncated PNG behind. */
Agentshell_Test::run( 'temp: a failed capture publishes nothing and leaves no temp file', function () {
    foreach ( array( 'fail', 'garbage', 'truncate' ) as $mode ) {
        $t = new Screenshot_Test_Case();
        $t->set_up();
        try {
            $out = $t->run_capture( $mode, 5 );
            Agentshell_Test::assert_false( $out['ok'], "$mode must not report success" );

            $shot_dir = $t->shots;
            $published = glob( $shot_dir . '/shot-*.png' );
            Agentshell_Test::assert_true( empty( $published ), "$mode must publish no PNG (found: " . implode( ',', (array) $published ) . ')' );

            $pending = glob( $shot_dir . '/.pending-*' );
            Agentshell_Test::assert_true( empty( $pending ), "$mode must clean up its temp file (found: " . implode( ',', (array) $pending ) . ')' );
        } finally { $t->tear_down(); }
    }
});

/** 8. A timed-out capture must not publish the PNG it had already written. */
Agentshell_Test::run( 'temp: a timed-out capture publishes nothing', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // The hang mode writes a valid PNG and *then* blocks — the exact case
        // where a naive implementation would publish a half-rendered page.
        $out = $t->run_capture( 'hang', 2 );
        Agentshell_Test::assert_equals( 'timeout', $out['kind'] ?? '', 'precondition: the capture must time out' );

        $published = glob( $t->shots . '/shot-*.png' );
        Agentshell_Test::assert_true( empty( $published ), 'a timed-out capture must publish nothing' );

        $pending = glob( $t->shots . '/.pending-*.png' );
        Agentshell_Test::assert_true( empty( $pending ), 'a timed-out capture must remove its temp PNG' );
    } finally { $t->tear_down(); }
});

/** 9. The lock is released on the failure path while the worker is still alive. */
Agentshell_Test::run( 'lock: released before the worker exits, on the failure path', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // The child performs a failing capture, then LINGERS. Without an
        // explicit release the lock would still be held while this worker
        // lives; process exit would have masked that, which is why this test
        // probes from a second live process rather than after the child ends.
        $pid = $t->start_capture( 'fail', 5, 'https://example.test/', 10 );
        Agentshell_Test::assert_true( $pid > 0, 'the child must start' );

        $marker = $t->dir . '/done.marker';
        $done = false;
        for ( $i = 0; $i < 100; $i++ ) {
            if ( file_exists( $marker ) ) { $done = true; break; }
            usleep( 50000 );
        }
        Agentshell_Test::assert_true( $done, 'the failing capture must complete' );

        // The child is still running (it is sleeping). If the lock were still
        // held, this probe must fail.
        Agentshell_Test::assert_false( $t->lock_is_held( 'fail' ),
            'the lock must be free once capture() returns, even while the worker lives' );

        // And a concurrent capture must succeed rather than be refused.
        $after = $t->run_capture( 'success', 5 );
        Agentshell_Test::assert_true( $after['ok'], 'a capture must succeed while another worker is alive: ' . json_encode( $after ) );

        exec( 'kill -9 ' . (int) $pid . ' 2>/dev/null' );
        usleep( 200000 );
    } finally { $t->tear_down(); }
});

/** 10. The lock must not live in a web-readable directory. */
Agentshell_Test::run( 'lock: the lock file is not web-readable', function () {
    // The security property is that the lock sits outside the webroot, not
    // that it matches this process's TMPDIR: sys_get_temp_dir() caches on
    // first call, so the parent process cannot relocate the child's lock.
    $path = Screenshot::lock_path();

    Agentshell_Test::assert_true( false === strpos( $path, '/uploads/' ), 'the lock must never live under uploads/: ' . $path );
    Agentshell_Test::assert_true( false === strpos( $path, 'wp-content' ), 'the lock must never live under wp-content/: ' . $path );
    Agentshell_Test::assert_true( false === strpos( $path, 'wp-includes' ), 'the lock must never live under wp-includes/: ' . $path );
    Agentshell_Test::assert_true(
        0 === strpos( $path, rtrim( sys_get_temp_dir(), '/' ) ),
        'the lock must live under the system temp dir: ' . $path
    );

    // Per-site scoping, so two installs on one host do not lock each other out.
    Agentshell_Test::assert_equals( 16, strlen( basename( $path, '.lock' ) ), 'the lock filename is a per-site hash: ' . basename( $path ) );
    Agentshell_Test::assert_true( '.lock' === substr( $path, -5 ), 'the lock must use a .lock suffix: ' . $path );

    // Per-uid scoping via a private directory. Two users sharing a host must
    // not land on the same path: the loser would chmod and flock a file the
    // other owns, and the flock would exclude the wrong user's captures.
    // Critically the lock must not sit directly in a world-writable /tmp.
    $uid = function_exists( 'posix_geteuid' ) ? (int) posix_geteuid() : 0;
    Agentshell_Test::assert_equals(
        'agentshell-screenshots-' . $uid,
        basename( dirname( $path ) ),
        'the lock must sit in a private per-uid directory: ' . $path
    );
    Agentshell_Test::assert_equals( sys_get_temp_dir(), dirname( dirname( $path ) ), 'that directory must be under the system temp dir' );
});

/** 9b. The lock file must be private, and a symlink there must be refused. */
Agentshell_Test::run( 'lock: the lock file is created private and is not a symlink', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        putenv( 'TMPDIR=' . $t->dir );
        $lock = Screenshot::acquire_lock();
        Screenshot::release_lock( $lock );

        $path = Screenshot::lock_path();
        Agentshell_Test::assert_true( file_exists( $path ), 'the lock file must exist after a capture' );

        clearstatcache( true, $path );
        $mode = fileperms( $path ) & 0777;
        Agentshell_Test::assert_equals( 0600, $mode, 'the lock file must be 0600, got 0' . dechex( $mode ) );
        Agentshell_Test::assert_false( is_link( $path ), 'the lock must not be a symlink' );

        if ( function_exists( 'posix_geteuid' ) ) {
            $owner = fileowner( $path );
            Agentshell_Test::assert_equals( posix_geteuid(), $owner, 'the lock must be owned by this uid' );
        }
    } finally { $t->tear_down(); }
});

/** 9c. A pre-planted symlink at the lock path must be refused, not followed. */
Agentshell_Test::run( 'lock: a symlink at the lock path is refused', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        putenv( 'TMPDIR=' . $t->dir );
        $path = Screenshot::lock_path();

        // Plant a symlink where the lock will go, aimed at a file the attacker
        // controls. flock() follows the link, so without the check we would
        // lock their file and chmod it.
        $canary = $t->dir . '/canary.txt';
        file_put_contents( $canary, 'untouched' );
        @mkdir( dirname( $path ), 0700, true );
        // An earlier test may have created a real lock file here; the
        // interesting case is the link, so clear whatever is in the way.
        if ( is_link( $path ) || file_exists( $path ) ) {
            @unlink( $path );
        }
        symlink( $canary, $path );

        $refused = false;
        try {
            $lock = Screenshot::acquire_lock();
            Screenshot::release_lock( $lock );
        } catch ( \RuntimeException $e ) {
            $refused = true;
        } catch ( \Throwable $e ) {
            // A Screenshot_Busy_Exception would mean we locked the target.
            $refused = false;
        }

        Agentshell_Test::assert_true( $refused, 'a symlinked lock path must be refused' );
        Agentshell_Test::assert_true( is_link( $path ), 'the planted symlink must be left in place, not silently replaced' );
        Agentshell_Test::assert_equals( 'untouched', file_get_contents( $canary ), 'the symlink target must not be modified' );
        Agentshell_Test::assert_equals(
            'untouched', file_get_contents( $canary ),
            'the symlink target must not be chmod-ed through the link'
        );
    } finally {
        // Remove the planted link. sys_get_temp_dir() caches its value per
        // process, so putenv() cannot relocate it: this link lands in the real
        // /tmp lock directory and would outlive the test. Left behind, it makes
        // every later capture fail — fopen() cannot create a file through a
        // dangling symlink.
        if ( isset( $path ) ) { @unlink( $path ); }
        $t->tear_down();
    }
});

/** 9d. The process kill must never signal PHP's own process or group. */
Agentshell_Test::run( 'kill: the process tree walk never targets PHP itself', function () {
    $src = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php' );
    $code = Screenshot_Test_Helpers::code_without_comments( $src );

    // A negated pid is only safe when the target leads its own group. The
    // implementation must therefore compare pgid to pid AND rule out our own
    // group; a bare posix_kill(-$pid) with no guard would SIGKILL the worker.
    Agentshell_Test::assert_true(
        false !== strpos( $code, '( $pgid === $pid )' ),
        'a group kill must be gated on the child leading its own group'
    );
    Agentshell_Test::assert_true(
        false !== strpos( $code, 'posix_getpgrp' ),
        'the guard must also rule out signalling our own process group'
    );
    Agentshell_Test::assert_true(
        false !== strpos( $code, '$pid === $self' ),
        'the pid must be checked against our own pid before any signal'
    );
    Agentshell_Test::assert_true(
        false !== strpos( $code, '$descendant !== $self' ),
        'descendants must be checked against our own pid before being signalled'
    );
    Agentshell_Test::assert_true(
        false !== strpos( $code, 'descendants_of( $pid, $self )' ),
        'the descendant walk must receive our pid so it can exclude us'
    );
});

/** 9e. Behavioural: a capture must not kill the worker that runs it. */
Agentshell_Test::run( 'kill: the worker survives killing its own capture tree', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // If any of the guards regressed, the group or descendant kill would
        // take this PHP process down and the child would emit no JSON at all.
        $out = $t->run_capture( 'hang', 2, 'https://example.test/', 0, 'timeout,setsid' );
        Agentshell_Test::assert_equals( 'timeout', $out['kind'] ?? '', 'the capture must time out: ' . json_encode( $out ) );

        // Still alive and still able to work afterwards.
        $after = $t->run_capture( 'success', 5 );
        Agentshell_Test::assert_true( $after['ok'], 'the worker must survive and keep working: ' . json_encode( $after ) );
    } finally { $t->tear_down(); }
});

/** 10. The lock must be cross-process, not PHP process-local state. */
Agentshell_Test::run( 'lock: uses flock (cross-process), not a PHP static', function () {
    $src = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php' );

    Agentshell_Test::assert_true( false !== strpos( $src, 'flock(' ), 'capture locking must use flock()' );
    Agentshell_Test::assert_true( false !== strpos( $src, 'LOCK_NB' ), 'the lock must be non-blocking' );

    // A static/global flag would look correct in one process and do nothing
    // across two — the exact bug being fixed. Pin its absence.
    Agentshell_Test::assert_true(
        false === strpos( $src, 'static $lock_held' ) && false === strpos( $src, '$GLOBALS[\'agentshell_lock' ),
        'locking must not use PHP process-local state'
    );
});

/** 11. The timeout must be bounded and cannot be disabled by configuration. */
Agentshell_Test::run( 'timeout: the deadline is clamped and always enforced', function () {
    $src = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php' );
    Agentshell_Test::assert_true(
        false !== strpos( $src, 'max( 1, min( 300, $seconds ) )' ),
        'the configured timeout must be clamped to 1-300s'
    );

    // Loop, not a bare blocking exec(): the deadline is only real if PHP
    // checks it while the child runs.
    Agentshell_Test::assert_true( false !== strpos( $src, 'proc_get_status' ), 'the deadline must be enforced by polling the child' );
    Agentshell_Test::assert_true( false !== strpos( $src, 'microtime( true ) >= $deadline' ), 'the poll loop must compare against the deadline' );

    // Group kill, or Chrome's zygote and renderers survive.
    Agentshell_Test::assert_true( false !== strpos( $src, 'posix_kill( -$pid, 9 )' ), 'the timeout must kill the whole process group' );
    Agentshell_Test::assert_true( false !== strpos( $src, 'setsid' ), 'the browser must be put in its own process group' );
});

/** 12. Unsafe browser flags must not be reintroduced. */
Agentshell_Test::run( 'security: no --no-sandbox and no certificate bypass', function () {
    foreach ( array(
        dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php',
        dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-screenshot.php',
    ) as $file ) {
        $src = file_get_contents( $file );
        $code = Screenshot_Test_Helpers::code_without_comments( $src );

        foreach ( array( '--no-sandbox', '--disable-web-security', '--ignore-certificate-errors',
                         '--allow-running-insecure-content', '--user-data-dir=/tmp' ) as $flag ) {
            Agentshell_Test::assert_true(
                false === strpos( $code, $flag ),
                basename( $file ) . " must not pass $flag to the browser"
            );
        }
    }
});

/** 13. Arguments must reach the browser as argv, not via a shell string. */
Agentshell_Test::run( 'security: the URL is passed as an argv element, never shell-concatenated', function () {
    $src  = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php' );
    $code = Screenshot_Test_Helpers::code_without_comments( $src );

    Agentshell_Test::assert_true(
        false !== strpos( $src, 'proc_open( $wrapped' ),
        'the browser must be started with proc_open so argv is passed discretely'
    );
    Agentshell_Test::assert_true(
        false === strpos( $code, 'escapeshellarg( $url )' ),
        'the URL must not be escaped into a shell string; it belongs in argv'
    );
    // Pin the argv shape itself. Quoting the URL back into the array would
    // still "not use escapeshellarg" while reintroducing shell parsing, so the
    // bare trailing $url element is the load-bearing assertion.
    Agentshell_Test::assert_true(
        preg_match( '/^\s*\$url,\s*\n\s*\);/m', $code ) === 1,
        'the URL must be its own argv element immediately before the argv is closed'
    );
    // A shell string anywhere in the browser invocation re-opens injection.
    Agentshell_Test::assert_true(
        false === strpos( $code, "' . '" ),
        'the browser argv must not be assembled by string concatenation'
    );
});

/** 13b. A URL containing shell metacharacters must reach the browser literally. */
Agentshell_Test::run( 'security: a URL with shell metacharacters is not interpreted by a shell', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // If any shell parsed this string, the capture would not run at all —
        // the URL is what the fake browser receives, and it must be intact.
        $nasty = 'https://example.test/?a=1;id>/tmp/pwned&b=$(touch /tmp/pwned2)';
        $out   = $t->run_capture( 'success', 5, $nasty );

        Agentshell_Test::assert_true( $out['ok'], 'a URL with shell metacharacters must still capture: ' . json_encode( $out ) );
        Agentshell_Test::assert_false( file_exists( '/tmp/pwned' ), 'no command substitution may run' );
        Agentshell_Test::assert_false( file_exists( '/tmp/pwned2' ), 'no command substitution may run' );
    } finally { $t->tear_down(); }
});

/** 13c. The browser must lead its own process group. */
Agentshell_Test::run( 'security: the browser leads its own process group', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // `timeout` carries the same budget as PHP, so when it is available it
        // wins the race and PHP's own group kill never runs — setsid looks
        // inert. Simulate a php-fpm pool where /usr/bin/timeout is not
        // executable, which is the only environment where PHP's own deadline
        // and its own group kill are the sole enforcement.
        $out = $t->run_capture( 'hang', 2, 'https://example.test/', 0, 'timeout' );
        Agentshell_Test::assert_equals( 'timeout', $out['kind'] ?? '', 'the deadline must hold without the timeout helper: ' . json_encode( $out ) );

        // The worker survived its own group kill and still works. Without
        // setsid the browser shares PHP's process group, so this group kill
        // would SIGKILL the request handler mid-request.
        $after = $t->run_capture( 'success', 5 );
        Agentshell_Test::assert_true( $after['ok'], 'the worker must survive its own group kill: ' . json_encode( $after ) );
    } finally { $t->tear_down(); }
});

/** 13d. The deadline holds with no external helper available at all. */
Agentshell_Test::run( 'timeout: enforced with neither setsid nor timeout available', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // A restricted php-fpm pool may expose neither helper. PHP's own
        // polling loop and its own kill must still bound the capture.
        $out = $t->run_capture( 'hang', 2, 'https://example.test/', 0, 'timeout,setsid' );
        Agentshell_Test::assert_equals( 'timeout', $out['kind'] ?? '', 'PHP must enforce the deadline unaided: ' . json_encode( $out ) );
        Agentshell_Test::assert_true( ( $out['elapsed'] ?? 0 ) < 8000, 'it must stop near the deadline, not run to completion' );

        // The grandchild must be dead too. Without setsid the browser shares
        // PHP's process group, so posix_kill(-$pid) names a group that does
        // not exist and the signal is silently discarded — only proc_terminate
        // on the direct child runs. Chrome's zygote and renderers would then
        // outlive the request and hold the lock against the next attempt.
        $markers = glob( $t->shots . '/.pending-*.png.gc' );
        Agentshell_Test::assert_true( is_array( $markers ) && count( $markers ) > 0, 'the fake browser must have recorded a grandchild pid' );
        $gc = (int) trim( (string) file_get_contents( $markers[0] ) );
        usleep( 400000 );
        Agentshell_Test::assert_false( posix_kill( $gc, 0 ), sprintf( 'grandchild %d must be dead without either helper', $gc ) );

        $after = $t->run_capture( 'success', 5, 'https://example.test/', 0, 'timeout,setsid' );
        Agentshell_Test::assert_true( $after['ok'], 'a later capture must still succeed: ' . json_encode( $after ) );
    } finally { $t->tear_down(); }
});

/**
 * 15. The capture must report the dimensions the image has, not the ones
 * it asked for.
 *
 * Headless Chrome enforces a minimum window width — around 485 CSS pixels
 * on the host the defect was measured on — so a request for a 390-pixel
 * mobile viewport is laid out at 485 pixels and the PNG is a crop of that
 * wider layout. The tool reported the requested width, so an agent
 * reviewing a "mobile" screenshot was reviewing a cropped, wider layout
 * and had no way to know.
 *
 * The capture now reads the PNG's own header and reports the dimensions
 * the image has, with the requested dimensions beside them and a note
 * when they differ.
 */
Agentshell_Test::run( 'capture: the reported width is the width the image has', function () {
    $t = new Screenshot_Test_Case();
    $t->set_up();
    try {
        // The fake browser writes an image that is wider than the one the
        // capture asked for — which is what a real browser does with a
        // narrow window.
        $out = $t->run_capture( 'success', 5, 'https://example.test/', 0, '', 390, 844 );

        Agentshell_Test::assert_true( $out['ok'], 'the capture must succeed: ' . json_encode( $out ) );
        Agentshell_Test::assert_equals(
            485,
            (int) $out['png_width'],
            'the fake browser must have written a 485-pixel image, as a real browser does with a narrow window'
        );
        Agentshell_Test::assert_equals(
            485,
            (int) $out['width'],
            'the capture must report the width the image has'
        );
        Agentshell_Test::assert_equals(
            390,
            (int) $out['requested_width'],
            'the capture must also report the width it asked for'
        );
        Agentshell_Test::assert_true(
            ! empty( $out['viewport_adjusted'] ),
            'the capture must say that the viewport was not what was asked for'
        );
    } finally { $t->tear_down(); }
} );

Agentshell_Test::run( 'capture: the PNG dimensions are read from the header, not guessed', function () {
    $png = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'N', 390 ) . pack( 'N', 844 );
    $path = tempnam( sys_get_temp_dir(), 'as-png' );
    file_put_contents( $path, $png . str_repeat( 'X', 100 ) );

    Agentshell_Test::assert_equals(
        390,
        (int) Screenshot::png_dimensions( $path )['width'],
        'the width must come from the IHDR chunk'
    );
    Agentshell_Test::assert_equals(
        844,
        (int) Screenshot::png_dimensions( $path )['height'],
        'the height must come from the IHDR chunk'
    );

    // A file that carries the PNG signature but is not a PNG must report
    // unknown dimensions rather than a number read out of whatever follows.
    file_put_contents( $path, "\x89PNG\r\n\x1a\n" . str_repeat( 'X', 100 ) );
    Agentshell_Test::assert_equals(
        0,
        (int) Screenshot::png_dimensions( $path )['width'],
        'a file without an IHDR chunk must report unknown dimensions'
    );
    @unlink( $path );
} );

/** 14. SSRF protection must remain effective. */
Agentshell_Test::run( 'security: SSRF host check is still enforced', function () {
    $src  = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-screenshot.php' );
    $code = Screenshot_Test_Helpers::code_without_comments( $src );

    Agentshell_Test::assert_true( false !== strpos( $code, 'strcasecmp' ), 'the host comparison must remain' );
    Agentshell_Test::assert_true( false !== strpos( $code, 'PHP_URL_HOST' ), 'the host must still be parsed out of the URL' );
});