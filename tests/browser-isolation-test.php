<?php
/**
 * Browser profile isolation regressions.
 *
 * A capture renders whatever the agent is looking at, which after the draft
 * preview work can be one person's unpublished content — reached through a
 * preview credential that travels in the URL. A headless browser given its
 * default profile keeps all of it: the HTTP cache entry for the rendered page,
 * the credential-bearing URL in cache metadata, any cookies the response set,
 * and the site's assets. None of it belongs in the developer's personal Chrome
 * profile, and none of it should outlive the request that produced it.
 *
 * These tests are executable rather than source-text pins, because the property
 * under test is what the process was *handed* — an argv flag and an environment
 * — plus what is left on disk afterwards. Only running a capture and looking at
 * the filesystem shows all three.
 *
 * Each capture runs in its own child PHP process, matching the existing
 * screenshot suite: the capture lock is enforced by flock() across processes,
 * and profile sweeping must be observed from outside the process that would
 * otherwise be holding its own profile.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fake-browser.php';

require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php';

use AgentShell_MCP\Screenshot;

// The suite names its own fake-home and fake-profile roots so that "the real
// profile was not touched" is a statement about a directory this test created
// and can therefore verify exhaustively, rather than about the developer's
// actual home directory.
// Short by necessity, not by taste. Each case runs with a directory of its own
// as TMPDIR, so the profile the browser ends up with sits at
// <case>/agentshell-chrome-<uid>/profile-<16 hex>/tmp — and that is a Chromium
// socket path, capped by Screenshot::MAX_TMPDIR_LENGTH. A self-describing root
// would put every case over the cap and fail for a reason unrelated to what the
// case is testing.
define( 'AGENTSHELL_BROWSER_TEST_ROOT', '/tmp/asi' . getmypid() );

if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

/**
 * Child script: one capture, reporting the outcome *and* everything the browser
 * was handed.
 *
 * argv: 1 class-file  2 browser  3 url  4 mode  5 workdir  6 timeout
 *       7 argv-dump  8 env-dump  9 cache-text  10 home-dir 11 record-profile-mode
 */
function agentshell_isolation_child_source() {
    return <<<'PHP'
<?php
$class_file = $argv[1];
$browser    = $argv[2];
$url        = $argv[3];
$mode       = $argv[4];
$workdir    = $argv[5];
$timeout    = (int) $argv[6];
$argv_dump  = (string) ( $argv[7] ?? '' );
$env_dump   = (string) ( $argv[8] ?? '' );
$cache_text = (string) ( $argv[9] ?? '' );
$home       = (string) ( $argv[10] ?? '' );
$record_mode = (string) ( $argv[11] ?? '' );

// Isolate this child's temp dir: the lock file, the browser profiles, and the
// logs all land under $workdir rather than the shared system temp dir.
putenv( 'TMPDIR=' . $workdir );
if ( '' !== $home ) { putenv( 'HOME=' . $home ); }

putenv( 'AGENTSHELL_FAKE_CHROME_MODE=' . $mode );
if ( '' !== $argv_dump )  { putenv( 'AGENTSHELL_FAKE_CHROME_ARGV=' . $argv_dump ); }
if ( '' !== $env_dump )   { putenv( 'AGENTSHELL_FAKE_CHROME_ENV=' . $env_dump ); }
if ( '' !== $cache_text ) { putenv( 'AGENTSHELL_FAKE_CHROME_CACHE=' . $cache_text ); }
if ( '' !== $record_mode ){ putenv( 'AGENTSHELL_FAKE_CHROME_PROFILE_MODE=1' ); }

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
define( 'AGENTSHELL_SCREENSHOT_TIMEOUT', $timeout );

$out = array( 'ok' => false );
try {
    $shot = Screenshot::capture( $url, 800, 600, $workdir . '/shots' );
    $out = array(
        'ok'     => true,
        'file'   => $shot['file'],
        'exists' => file_exists( $shot['file'] ),
        'size'   => file_exists( $shot['file'] ) ? filesize( $shot['file'] ) : 0,
        'is_png' => file_exists( $shot['file'] )
            && substr( (string) file_get_contents( $shot['file'] ), 0, 8 ) === "\x89PNG\r\n\x1a\n",
        'width'  => $shot['width'],
        'height' => $shot['height'],
    );
} catch ( \AgentShell_MCP\Screenshot_Busy_Exception $e ) {
    $out = array( 'ok' => false, 'kind' => 'busy', 'message' => $e->getMessage() );
} catch ( \AgentShell_MCP\Screenshot_Timeout_Exception $e ) {
    $out = array( 'ok' => false, 'kind' => 'timeout', 'message' => $e->getMessage() );
} catch ( \Throwable $e ) {
    $out = array( 'ok' => false, 'kind' => 'error', 'message' => $e->getMessage() );
}

// What is still under the profile base *right now*, while this process is
// still alive. create_profile_dir() also registers a shutdown handler, so
// checking only after the child exits cannot distinguish "the finally removed
// it" from "the shutdown handler removed it on the way out" — a capture whose
// finally-block cleanup was deleted would still look clean.
$out['leftover'] = array();
$base = Screenshot::profile_base_dir();
if ( is_dir( $base ) ) {
    foreach ( (array) scandir( $base ) as $entry ) {
        if ( '.' !== $entry && '..' !== $entry ) {
            $out['leftover'][] = $entry;
        }
    }
    sort( $out['leftover'] );
}
echo json_encode( $out );
PHP;
}

/**
 * Child script: exercise the cleanup helpers and report what survived.
 *
 * argv: 1 class-file  2 workdir  3 mode(destroy|create)  4 json-arg
 *
 * This must be a separate process. `Screenshot::profile_base_dir()` is derived
 * from `sys_get_temp_dir()`, and PHP resolves that once per process and caches
 * the answer — so `putenv('TMPDIR=...')` in the test process changes nothing,
 * and every guard in destroy_profile_dir() would then be evaluated against the
 * real /tmp rather than the fixture. Called here, in a process that sets TMPDIR
 * before anything asks for it, the base and the paths agree.
 *
 * mode `create` reports on a freshly created profile without destroying it, so
 * a test can inspect what create_profile_dir() actually produced.
 */
function agentshell_cleanup_child_source() {
    return <<<'PHP'
<?php
$class_file = $argv[1];
$workdir    = $argv[2];
$mode       = $argv[3];
$arg        = json_decode( $argv[4], true );

putenv( 'TMPDIR=' . $workdir );
define( 'ABSPATH', __DIR__ );
require $class_file;

use AgentShell_MCP\Screenshot;

$out = array( 'base' => Screenshot::profile_base_dir() );

if ( 'create' === $mode ) {
    $path = Screenshot::create_profile_dir();
    clearstatcache( true, $path );
    $out['created'] = array(
        'path'          => $path,
        'is_dir'        => is_dir( $path ),
        'is_link'       => is_link( $path ),
        'mode'          => substr( sprintf( '%o', fileperms( $path ) ), -4 ),
        'marker_exists' => is_file( $path . '/' . Screenshot::PROFILE_MARKER ),
        'marker_value'  => (string) @file_get_contents( $path . '/' . Screenshot::PROFILE_MARKER ),
    );
} else {
    $results = array();
    foreach ( (array) $arg as $path ) {
        Screenshot::destroy_profile_dir( $path );
        $results[] = array(
            'path'    => $path,
            'exists'  => file_exists( $path ),
            'is_link' => is_link( $path ),
        );
    }
    $out['results'] = $results;
}

echo json_encode( $out );
PHP;
}

class Browser_Isolation_Test_Case {
    public $dir;
    public $browser;
    public $class_file;
    public $child;
    public $cleanup_child;
    public $home;

    public function set_up() {
        static $case = 0;
        $this->dir    = AGENTSHELL_BROWSER_TEST_ROOT . '-' . ( ++$case );
        mkdir( $this->dir, 0700, true );
        mkdir( $this->dir . '/shots', 0700, true );
        // Stands in for the developer's real home. Nothing may be written
        // into it by a capture.
        $this->home = $this->dir . '/home';
        mkdir( $this->home, 0755, true );
        file_put_contents( $this->home . '/sentinel.txt', 'pre-existing browser profile' );

        $this->browser       = agentshell_fake_browser_path( $this->dir );
        $this->class_file    = dirname( __DIR__ ) . '/agentshell-mcp/includes/class-screenshot.php';
        $this->child         = $this->dir . '/child.php';
        $this->cleanup_child = $this->dir . '/cleanup-child.php';
        file_put_contents( $this->child, agentshell_isolation_child_source() );
        file_put_contents( $this->cleanup_child, agentshell_cleanup_child_source() );
    }

    public function tear_down() {
        self::rmtree( $this->dir );
    }

    /** Recursive delete, symlink-safe — the test's own cleanup, held to the same rule. */
    public static function rmtree( $path ) {
        if ( is_link( $path ) ) { @unlink( $path ); return; }
        if ( ! is_dir( $path ) ) { @unlink( $path ); return; }
        foreach ( scandir( $path ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) { continue; }
            self::rmtree( $path . '/' . $entry );
        }
        @rmdir( $path );
    }

    /**
     * Run one capture in a child process.
     *
     * @param string $mode Browser behaviour: success|hang|fail|garbage|truncate.
     * @param array  $opts argv_dump, env_dump, cache_text, record_mode, timeout
     * @return array { result: array, argv: string[], env: array, files: array }
     */
    public function capture( $mode = 'success', array $opts = array() ) {
        $argv_dump  = $this->dir . '/argv-' . uniqid() . '.txt';
        $env_dump   = $this->dir . '/env-' . uniqid() . '.txt';
        $timeout    = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 10;

        $cmd = array(
            PHP_BINARY,
            $this->child,
            $this->class_file,
            $this->browser,
            'https://example.test/draft-preview?agentshell_post_key=SECRETCREDENTIAL',
            $mode,
            $this->dir,
            (string) $timeout,
            $argv_dump,
            $env_dump,
            isset( $opts['cache_text'] ) ? $opts['cache_text'] : '',
            $this->home,
            ! empty( $opts['record_mode'] ) ? '1' : '',
        );

        $pipes = array();
        $proc = proc_open( $cmd, array(
            0 => array( 'file', '/dev/null', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        ), $pipes );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $proc );

        $result = json_decode( $stdout, true );
        if ( ! is_array( $result ) ) {
            throw new RuntimeException( 'child produced no result. stdout=' . $stdout . ' stderr=' . $stderr );
        }
        $result['_stderr'] = $stderr;

        return array(
            'result' => $result,
            'argv'   => self::parse_argv_dump( $argv_dump ),
            'env'    => self::parse_env_dump( $env_dump ),
            'profile_mode' => is_file( $argv_dump . '.profilemode' )
                ? trim( (string) file_get_contents( $argv_dump . '.profilemode' ) )
                : null,
        );
    }

    /** The argv dump is "ARGV-COUNT n" followed by n argument lines. */
    public static function parse_argv_dump( $path ) {
        if ( ! is_readable( $path ) ) { return array(); }
        $lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $path ) ), 'strlen' ) );
        if ( empty( $lines ) || 0 !== strpos( $lines[0], 'ARGV-COUNT' ) ) {
            return array();
        }
        $count = (int) substr( $lines[0], strlen( 'ARGV-COUNT' ) + 1 );
        return array_slice( $lines, 1, $count );
    }

    public static function parse_env_dump( $path ) {
        if ( ! is_readable( $path ) ) { return array(); }
        $out = array();
        foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $line ) {
            if ( '' === trim( $line ) ) { continue; }
            list( $k, $v ) = array_pad( explode( '=', $line, 2 ), 2, '' );
            $out[ $k ] = $v;
        }
        return $out;
    }

    /** The value of --user-data-dir= in a captured argv, or null. */
    public static function profile_from_argv( array $argv ) {
        foreach ( $argv as $arg ) {
            if ( str_starts_with( $arg, '--user-data-dir=' ) ) {
                return substr( $arg, strlen( '--user-data-dir=' ) );
            }
        }
        return null;
    }

    /** The profile base the child used, derived from its own temp dir. */
    public function profile_base() {
        return $this->dir . '/agentshell-chrome-' . ( function_exists( 'posix_geteuid' ) ? (int) posix_geteuid() : 'na' );
    }

    /**
     * Apply destroy_profile_dir() to each path, from a child process.
     *
     * This has to be a child. `Screenshot::profile_base_dir()` is derived from
     * `sys_get_temp_dir()`, and PHP resolves that once per process and caches
     * it — so `putenv('TMPDIR=...')` here would change nothing, and every guard
     * would be evaluated against the real /tmp rather than the fixture. Which
     * means the refusal guards would "pass" by refusing paths that were never in
     * scope, and the positive control would fail against /tmp's base. In a
     * process that sets TMPDIR before anything asks, base and paths agree.
     *
     * @return array One { path, exists, is_link } record per path, in order.
     */
    public function destroy_in_child( array $paths ) {
        return $this->in_cleanup_child( 'destroy', array_values( $paths ) )['results'];
    }

    /** Create a profile in a child and report what create_profile_dir() made. */
    public function create_in_child() {
        $out = $this->in_cleanup_child( 'create', null );
        return $out['created'];
    }

    private function in_cleanup_child( $mode, $arg ) {
        $pipes = array();
        $proc = proc_open( array(
            PHP_BINARY,
            $this->cleanup_child,
            $this->class_file,
            $this->dir,
            $mode,
            json_encode( $arg ),
        ), array(
            0 => array( 'file', '/dev/null', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        ), $pipes );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $proc );

        $decoded = json_decode( $stdout, true );
        if ( ! is_array( $decoded ) ) {
            throw new RuntimeException( 'cleanup child produced nothing. stdout=' . $stdout . ' stderr=' . $stderr );
        }
        // The child resolved the same base the tests build their paths from; if
        // it did not, the whole fixture is aiming at the wrong tree.
        Agentshell_Test::assert_equals(
            $this->profile_base(),
            $decoded['base'],
            'the cleanup child must resolve the same profile base as the fixture'
        );
        return $decoded;
    }

    /** Every path under the profile base that still exists. */
    public function surviving_paths( $needle = '' ) {
        $base = $this->profile_base();
        $found = array();
        if ( ! is_dir( $base ) ) { return $found; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $it as $path => $info ) {
            if ( '' === $needle || false !== strpos( $path, $needle ) ) {
                $found[] = $path;
            }
        }
        sort( $found );
        return $found;
    }

    /** Every file in the fake home, to prove nothing was written there. */
    public function home_contents() {
        $out = array();
        if ( ! is_dir( $this->home ) ) { return $out; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $this->home, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $it as $path => $info ) {
            $out[] = substr( $path, strlen( $this->home ) );
        }
        sort( $out );
        return $out;
    }
}

// ── the flag is passed ──────────────────────────────────────────────────────

Agentshell_Test::run( 'isolation: Chrome is launched with a temporary user-data-dir', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success' );
        Agentshell_Test::assert_true( $run['result']['ok'], 'capture must succeed: ' . json_encode( $run['result'] ) );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );
        Agentshell_Test::assert_true( null !== $profile, 'argv must carry --user-data-dir' );
        // And it is a real directory that existed while the browser ran.
        Agentshell_Test::assert_true( '' !== $profile );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: the profile is private (0700)', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success', array( 'record_mode' => true ) );
        Agentshell_Test::assert_true( null !== $run['profile_mode'], 'the browser must be able to stat its profile' );
        Agentshell_Test::assert_equals( '700', $run['profile_mode'], 'profile directory mode' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: the profile lives in a private per-uid directory', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success' );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );
        Agentshell_Test::assert_true(
            str_starts_with( $profile, $t->profile_base() . '/profile-' ),
            "profile must be a profile-* child of the private base, got: {$profile}"
        );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: different captures use different profile directories', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $a = Browser_Isolation_Test_Case::profile_from_argv( $t->capture( 'success' )['argv'] );
        $b = Browser_Isolation_Test_Case::profile_from_argv( $t->capture( 'success' )['argv'] );
        Agentshell_Test::assert_true( null !== $a && null !== $b );
        Agentshell_Test::assert_not_equals( $a, $b, 'a second capture must not reuse the first profile' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: the profile name is unpredictable', function () {
    // 16 hex characters of random_bytes, not a counter or a pid: the temp dir is
    // world-writable, so a guessable name is one another local user could
    // create ahead of us. Sixteen rather than thirty-two because the name is on
    // a Chromium socket path — see Screenshot::MAX_TMPDIR_LENGTH.
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $names = array();
        for ( $i = 0; $i < 3; $i++ ) {
            $profile = Browser_Isolation_Test_Case::profile_from_argv( $t->capture( 'success' )['argv'] );
            Agentshell_Test::assert_true(
                (bool) preg_match( '/profile-[0-9a-f]{16}$/', $profile ),
                "unexpected profile name: {$profile}"
            );
            $names[] = basename( $profile );
        }
        Agentshell_Test::assert_equals( 3, count( array_unique( $names ) ), 'every profile name must be unique' );
    } finally {
        $t->tear_down();
    }
} );

// ── no path back to a real profile ──────────────────────────────────────────

Agentshell_Test::run( 'isolation: HOME and the XDG paths point inside the profile', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success' );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );
        foreach ( array( 'HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_DATA_HOME' ) as $var ) {
            Agentshell_Test::assert_true(
                isset( $run['env'][ $var ] ) && str_starts_with( $run['env'][ $var ], $profile ),
                "{$var} must resolve inside the profile, got: " . ( $run['env'][ $var ] ?? '<unset>' )
            );
        }
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: the pre-existing browser profile is left untouched', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $before = $t->home_contents();
        Agentshell_Test::assert_equals( array( '/sentinel.txt' ), $before );
        $t->capture( 'success', array( 'cache_text' => 'CACHED-DRAFT-CONTENT' ) );
        Agentshell_Test::assert_equals(
            $before,
            $t->home_contents(),
            'a capture must not write into the profile directory it inherited'
        );
        Agentshell_Test::assert_equals(
            'pre-existing browser profile',
            file_get_contents( $t->home . '/sentinel.txt' ),
            'existing profile contents must survive untouched'
        );
    } finally {
        $t->tear_down();
    }
} );

// ── lifecycle: removed on every path ────────────────────────────────────────

Agentshell_Test::run( 'isolation: a successful capture removes the profile', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success' );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );
        Agentshell_Test::assert_true( ! file_exists( $profile ), "profile must be gone after success: {$profile}" );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: a browser failure removes the profile', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'fail' );
        Agentshell_Test::assert_false( $run['result']['ok'], 'a failing browser must not report success' );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );
        Agentshell_Test::assert_true( null !== $profile );
        Agentshell_Test::assert_true( ! file_exists( $profile ), "profile must be gone after failure: {$profile}" );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: a garbage render removes the profile', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'garbage' );
        Agentshell_Test::assert_false( $run['result']['ok'] );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );
        Agentshell_Test::assert_true( ! file_exists( $profile ), 'profile must be gone after an invalid render' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: a timeout removes the profile', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'hang', array( 'timeout' => 2 ) );
        Agentshell_Test::assert_equals( 'timeout', $run['result']['kind'] ?? '', json_encode( $run['result'] ) );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );
        Agentshell_Test::assert_true( null !== $profile );
        Agentshell_Test::assert_true( ! file_exists( $profile ), "profile must be gone after timeout: {$profile}" );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: browser descendants are dead before cleanup runs', function () {
    // The ordering property, not just the end state. The hang mode spawns a
    // grandchild that would otherwise live for 300s. If cleanup ran while it
    // was alive, it would still be alive when this assertion is made; since it
    // is dead *and* the profile is gone from the same capture, cleanup can only
    // have followed termination.
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'hang', array( 'timeout' => 2 ) );
        $profile = Browser_Isolation_Test_Case::profile_from_argv( $run['argv'] );

        // The grandchild pid is recorded next to the browser's output file.
        $gc_files = glob( $t->dir . '/shots/.pending-*.png.gc' );
        Agentshell_Test::assert_true( ! empty( $gc_files ), 'the hang mode must have recorded a grandchild pid' );
        $gc = (int) trim( (string) file_get_contents( $gc_files[0] ) );
        Agentshell_Test::assert_true( $gc > 1 );

        // Give the kernel a moment to reap what was just signalled.
        for ( $i = 0; $i < 40 && file_exists( "/proc/{$gc}" ); $i++ ) {
            usleep( 50000 );
        }
        Agentshell_Test::assert_false(
            file_exists( "/proc/{$gc}" ),
            "browser grandchild {$gc} must be terminated, not merely orphaned"
        );
        Agentshell_Test::assert_true( ! file_exists( $profile ), 'profile must be gone' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: rendered content cannot persist after a capture', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $marker = 'UNPUBLISHED-DRAFT-BODY-' . uniqid();
        $run = $t->capture( 'success', array( 'cache_text' => $marker ) );
        Agentshell_Test::assert_true( $run['result']['ok'] );

        // Nothing anywhere under the profile base carries the marker.
        $left = $t->surviving_paths( $marker );
        Agentshell_Test::assert_equals( array(), $left, 'no cached draft content may survive' );

        // And no profile directory at all is left behind.
        $survivors = $t->surviving_paths();
        Agentshell_Test::assert_equals( array(), $survivors, 'the profile tree must be empty after a capture' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: the credential never reaches the profile base after a capture', function () {
    // The preview credential travels in the URL the browser is given, so the
    // browser's cache of that request would contain it. Nothing may be left.
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success', array( 'cache_text' => 'agentshell_post_key=SECRETCREDENTIAL' ) );
        Agentshell_Test::assert_true( $run['result']['ok'] );
        Agentshell_Test::assert_equals(
            array(),
            $t->surviving_paths( 'SECRETCREDENTIAL' ),
            'the credential must not persist anywhere under the profile base'
        );
    } finally {
        $t->tear_down();
    }
} );

// ── cleanup is narrow ────────────────────────────────────────────────────────

Agentshell_Test::run( 'cleanup: removes a profile it owns, and nothing else', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        mkdir( $t->profile_base(), 0700, true );

        // The positive control. Without it, every refusal below would also pass
        // if destroy_profile_dir() simply did nothing at all.
        $owned = $t->profile_base() . '/profile-' . str_repeat( '0', 16 );
        mkdir( $owned . '/Default/Cache', 0700, true );
        file_put_contents( $owned . '/.agentshell-profile', 'agentshell-browser-profile-v1' );
        file_put_contents( $owned . '/Default/Cache/entry.bin', 'cached' );

        $results = $t->destroy_in_child( array( $owned ) );

        Agentshell_Test::assert_false(
            $results[0]['exists'],
            'a well-formed profile directly under the base must be removed'
        );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: refuses paths outside the profile base', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        mkdir( $t->profile_base(), 0700, true );

        $victim_dir = $t->dir . '/victim';
        mkdir( $victim_dir, 0700, true );
        file_put_contents( $victim_dir . '/keep.txt', 'important' );

        // Three shapes of path outside the base. Each is refused by a different
        // check, so between them they cover every guard rather than relying on
        // one of them happening to catch everything.
        //
        //  - a traversal that escapes the base while keeping a legitimate name
        //  - a directory outside the base whose name is a *perfect* profile
        //    name, which is what separates the prefix check from the name
        //    check: only the prefix check refuses this one
        //  - the base itself, and the temp root
        $traversal = $t->profile_base() . '/profile-' . str_repeat( 'a', 32 ) . '/../victim';
        $lookalike = $t->dir . '/elsewhere/profile-' . str_repeat( 'e', 32 );
        mkdir( $lookalike, 0700, true );
        file_put_contents( $lookalike . '/keep.txt', 'not under the base' );

        $t->destroy_in_child( array( $traversal, $lookalike, $t->profile_base(), $t->dir ) );

        Agentshell_Test::assert_true(
            file_exists( $victim_dir . '/keep.txt' ),
            'a path outside the base must survive even when its shape looks legitimate'
        );
        Agentshell_Test::assert_true(
            file_exists( $lookalike . '/keep.txt' ),
            'a correctly named profile outside the base must survive: the name check is not enough'
        );
        Agentshell_Test::assert_true( is_dir( $t->profile_base() ), 'the base directory itself must survive' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: refuses a path whose name is not a profile we create', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        mkdir( $t->profile_base(), 0700, true );
        $impostor = $t->profile_base() . '/someone-elses-directory';
        mkdir( $impostor, 0700, true );
        file_put_contents( $impostor . '/keep.txt', 'not ours' );

        // Right shape, wrong length.
        $short = $t->profile_base() . '/profile-' . str_repeat( 'b', 15 );
        mkdir( $short, 0700, true );
        file_put_contents( $short . '/keep.txt', 'not ours' );

        $t->destroy_in_child( array( $impostor, $short ) );

        Agentshell_Test::assert_true( file_exists( $impostor . '/keep.txt' ), 'only a profile-<16 hex> name may be removed' );
        Agentshell_Test::assert_true( file_exists( $short . '/keep.txt' ), 'a short hex suffix is not our name' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: a symlinked profile is unlinked, not traversed', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        mkdir( $t->profile_base(), 0700, true );

        $secret = $t->dir . '/secret-target';
        mkdir( $secret, 0700, true );
        file_put_contents( $secret . '/valuable.txt', 'must survive' );

        $name = 'profile-' . str_repeat( 'c', 32 );
        $link = $t->profile_base() . '/' . $name;
        if ( ! @symlink( $secret, $link ) ) {
            throw new RuntimeException( 'could not create the symlink this test needs' );
        }

        $results = $t->destroy_in_child( array( $link ) );

        Agentshell_Test::assert_false(
            $results[0]['exists'] || $results[0]['is_link'],
            'the symlink itself must be removed'
        );
        Agentshell_Test::assert_true(
            file_exists( $secret . '/valuable.txt' ),
            'cleanup must never recurse through a symlink into a target outside the profile'
        );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: a symlink inside a profile is unlinked without following it', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        mkdir( $t->profile_base(), 0700, true );

        $outside = $t->dir . '/outside';
        mkdir( $outside, 0700, true );
        file_put_contents( $outside . '/precious.txt', 'must survive' );

        $profile = $t->profile_base() . '/profile-' . str_repeat( 'd', 16 );
        mkdir( $profile, 0700, true );
        file_put_contents( $profile . '/real.txt', 'cache' );
        // The browser writes symlinks of its own accord (SingletonLock and
        // friends); cleanup must treat them as files, not as directories.
        @symlink( $outside, $profile . '/Default' );

        $results = $t->destroy_in_child( array( $profile ) );

        Agentshell_Test::assert_false( $results[0]['exists'], 'the profile must be gone' );
        Agentshell_Test::assert_true( file_exists( $outside . '/precious.txt' ), 'the symlink target must survive' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: the stale sweep only removes marked, owned, old profiles', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $base = $t->profile_base();
        mkdir( $base, 0700, true );

        // What the sweep should remove: our name, our marker, old.
        $stale = $base . '/profile-' . str_repeat( 'c', 16 );
        mkdir( $stale, 0700, true );
        file_put_contents( $stale . '/.agentshell-profile', 'agentshell-browser-profile-v1' );
        touch( $stale, time() - 7200 );

        // What it must leave alone, each for a different reason.
        $fresh = $base . '/profile-' . str_repeat( 'd', 16 );
        mkdir( $fresh, 0700, true );
        file_put_contents( $fresh . '/.agentshell-profile', 'agentshell-browser-profile-v1' );

        $unmarked = $base . '/profile-' . str_repeat( 'e', 16 );
        mkdir( $unmarked, 0700, true );
        touch( $unmarked, time() - 7200 );

        $wrong_marker = $base . '/profile-' . str_repeat( 'f', 16 );
        mkdir( $wrong_marker, 0700, true );
        file_put_contents( $wrong_marker . '/.agentshell-profile', 'something-else' );
        touch( $wrong_marker, time() - 7200 );

        $foreign_name = $base . '/not-ours';
        mkdir( $foreign_name, 0700, true );
        touch( $foreign_name, time() - 7200 );

        // A capture sweeps as its first step.
        $t->capture( 'success' );

        Agentshell_Test::assert_false( file_exists( $stale ), 'an old, marked profile of ours is swept' );
        Agentshell_Test::assert_true( file_exists( $fresh ), 'a fresh profile is never swept' );
        Agentshell_Test::assert_true( file_exists( $unmarked ), 'an unmarked directory is never swept' );
        Agentshell_Test::assert_true( file_exists( $wrong_marker ), 'another marker value is never swept' );
        Agentshell_Test::assert_true( file_exists( $foreign_name ), 'a foreign directory name is never swept' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: a symlink named like a profile is never swept or followed', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $base = $t->profile_base();
        mkdir( $base, 0700, true );

        $target = $t->dir . '/do-not-delete';
        mkdir( $target, 0700, true );
        file_put_contents( $target . '/valuable.txt', 'must survive' );

        $name = 'profile-' . str_repeat( '9', 32 );
        @symlink( $target, $base . '/' . $name );

        $t->capture( 'success' );

        Agentshell_Test::assert_true( file_exists( $target . '/valuable.txt' ), 'the sweep must not follow a symlink' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: an abandoned profile is recovered on the next capture', function () {
    // The PHP-killed-before-cleanup case. A SIGKILL leaves the directory; the
    // sweep reclaims it once it is old enough that no live capture can own it.
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $base = $t->profile_base();
        mkdir( $base, 0700, true );
        $abandoned = $base . '/profile-' . str_repeat( '1', 32 );
        mkdir( $abandoned . '/Default/Cache', 0700, true );
        file_put_contents( $abandoned . '/.agentshell-profile', 'agentshell-browser-profile-v1' );
        file_put_contents( $abandoned . '/Default/Cache/leak.bin', 'draft body' );
        touch( $abandoned, time() - 7200 );

        $t->capture( 'success' );
        Agentshell_Test::assert_false( file_exists( $abandoned ), 'the abandoned profile must be reclaimed' );
    } finally {
        $t->tear_down();
    }
} );

// ── nothing else moved ──────────────────────────────────────────────────────

Agentshell_Test::run( 'isolation: capture output is unchanged', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success' );
        Agentshell_Test::assert_true( $run['result']['ok'], json_encode( $run['result'] ) );
        Agentshell_Test::assert_true( $run['result']['is_png'], 'a valid PNG must still be produced' );
        Agentshell_Test::assert_true( $run['result']['exists'], 'the capture file must exist' );
        Agentshell_Test::assert_equals( 800, $run['result']['width'] );
        Agentshell_Test::assert_equals( 600, $run['result']['height'] );
        // Dimensions still reach the browser as a window-size argument.
        $found = false;
        foreach ( $run['argv'] as $arg ) {
            if ( '--window-size=800,600' === $arg ) { $found = true; }
        }
        Agentshell_Test::assert_true( $found, 'the viewport must still be passed through unchanged' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: the sandbox and TLS posture is unchanged', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success' );
        foreach ( $run['argv'] as $arg ) {
            Agentshell_Test::assert_false(
                (bool) preg_match( '/^--(no-sandbox|disable-web-security|ignore-certificate-errors)/', $arg ),
                "isolation must not weaken the browser: {$arg}"
            );
        }
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'cleanup: a created profile carries the marker the sweep requires', function () {
    // The marker is the sweep's proof of ownership. A profile that
    // create_profile_dir() did not mark would be one the sweep must refuse to
    // reclaim — the safe failure, but it would mean a SIGKILLed capture's
    // profile is never recovered. So the marker is written, with the exact
    // value the sweep compares.
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $created = $t->create_in_child();
        Agentshell_Test::assert_true( $created['is_dir'], 'a profile directory must exist' );
        Agentshell_Test::assert_false( $created['is_link'], 'a profile must not be a symlink' );
        Agentshell_Test::assert_true( $created['marker_exists'], 'the profile must carry its marker file' );
        Agentshell_Test::assert_equals(
            'agentshell-browser-profile-v1',
            $created['marker_value'],
            'the marker value must match PROFILE_MARKER_VALUE exactly'
        );
        Agentshell_Test::assert_equals( '0700', $created['mode'], 'a created profile must be 0700' );
    } finally {
        $t->tear_down();
    }
} );

Agentshell_Test::run( 'isolation: cleanup runs in the capture, not only at process exit', function () {
    // The distinguishing test. create_profile_dir() registers a shutdown
    // handler as a backstop, so a profile is gone either way by the time the
    // process exits — and a test that only looked afterwards could not tell a
    // working finally-block from a deleted one. The child reports what is left
    // while it is still running, which is after the finally and before the
    // shutdown.
    foreach ( array( 'success', 'fail', 'hang' ) as $mode ) {
        $t = new Browser_Isolation_Test_Case();
        $t->set_up();
        try {
            $run = $t->capture( $mode, 'hang' === $mode ? array( 'timeout' => 2 ) : array() );
            Agentshell_Test::assert_equals(
                array(),
                $run['result']['leftover'] ?? null,
                "after a {$mode} capture the profile must already be gone while the process is still alive"
            );
        } finally {
            $t->tear_down();
        }
    }
} );

Agentshell_Test::run( 'isolation: the URL is still passed as one argv element', function () {
    $t = new Browser_Isolation_Test_Case();
    $t->set_up();
    try {
        $run = $t->capture( 'success' );
        Agentshell_Test::assert_true( in_array(
            'https://example.test/draft-preview?agentshell_post_key=SECRETCREDENTIAL',
            $run['argv'],
            true
        ), 'the URL must remain a single argument, credential and all' );
    } finally {
        $t->tear_down();
    }
} );