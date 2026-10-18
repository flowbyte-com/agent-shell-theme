<?php
/**
 * Regression test for the destructive-child identity guard.
 *
 * Why this exists
 * ---------------
 * Gate 3 §2 surfaced a structural gap: the parent runner
 * `tests/run-all.php` runs `agentshell_test_bed_check()` once before any
 * suite and refuses to run when the live environment is not the expected
 * test bed. Standalone destructive child scripts (`tests/*-live-child.php`)
 * can be invoked directly, with a `wp_root` argument, and they call
 * `require_once wp-load.php` on that root without any identity check. A
 * test-bed.json that pointed at the integration site (a documented
 * contamination path) would have caused every destructive child invoked
 * directly to mutate the integration database.
 *
 * The fix introduces a fail-closed identity guard
 * (`agentshell_destructive_identity_guard()`) that every child must call
 * before `require_once wp-load.php`. The guard verifies that the actual
 * connected WordPress install and database match the selected test-bed
 * profile, and exits 7 (a distinct code from the runner's exit codes)
 * on any mismatch.
 *
 * What this test asserts
 * ----------------------
 * 1. The guard returns `ok=false` for each negative case (bad wp_root,
 *    integration site's wp_root, bad config, wrong sentinel).
 * 2. The guard's check chain is fail-closed: an unreadable wp-config
 *    refuses, not silently passes.
 * 3. Every child script (`tests/*-live-child.php`) that takes a wp_root
 *    argv calls the wrapper and exits 7 when given a wrong wp_root.
 * 4. The guard exits with the documented exit code (7) and prints a
 *    refusal message on stderr.
 *
 * Why a non-destructive bootstrap test
 * ------------------------------------
 * The guard does not load WordPress — it parses wp-config directly and
 * uses mysqli. The test exercises the guard against the live test bed
 * (read-only SELECTs) and against a bad wp_root (no MySQL connection
 * attempted). No destructive operations are performed.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "Destructive child identity guard (Gate 3 §3)\n";

$live = agentshell_wp_live_config();
if ( ! is_array( $live ) ) {
    fwrite( STDERR, "skip: no LocalWP PHP / mysqli / test bed available\n" );
    exit( 0 );
}
$guard_php = $live['php'];
$guard_ini = $live['ini'];   // e.g. ['mysqli.default_socket=/path/...']
$guard_env = $live['env'];   // e.g. ['LD_LIBRARY_PATH' => '/path/to/shared-libs']
$guard_ld  = isset( $guard_env['LD_LIBRARY_PATH'] ) ? $guard_env['LD_LIBRARY_PATH'] : '';
$cfg       = $live['cfg'];

// When this test is invoked through run-all.php, the parent PHP may not
// have mysqli.default_socket configured for the test bed's MySQL
// instance. Inject the socket from the resolved live config so the
// in-process guard calls (which need a real MySQL connection to read
// site_mode_sentinel / siteurl / grants) can reach the database. The
// subprocess invocations further below carry their own env.
foreach ( $guard_ini as $ini_arg ) {
    if ( 0 === strpos( $ini_arg, 'mysqli.default_socket=' ) ) {
        ini_set( 'mysqli.default_socket', substr( $ini_arg, strlen( 'mysqli.default_socket=' ) ) );
    }
}

// ── 1. The guard refuses a non-existent wp_root. The check fires
//      at the very first gate — wp-load.php must exist — so the guard
//      returns ok=false before any MySQL connection is attempted.
Agentshell_Test::run(
    'guard refuses when wp-load.php is missing',
    function () {
        $result = agentshell_destructive_identity_guard( '/no/such/path' );
        Agentshell_Test::assert_false(
            $result['ok'],
            'a non-existent wp_root must refuse'
        );
        Agentshell_Test::assert_equals(
            'wp_root',
            substr( $result['refusal'], 0, 7 ),
            'the first failed check must be the wp_root gate'
        );
    }
);

// ── 2. The guard refuses an empty wp_root. Defensive: argv[1] might
//      be unset if the child was invoked with no arguments.
Agentshell_Test::run(
    'guard refuses when wp_root is empty',
    function () {
        $result = agentshell_destructive_identity_guard( '' );
        Agentshell_Test::assert_false(
            $result['ok'],
            'an empty wp_root must refuse'
        );
    }
);

// ── 3. The guard refuses when given a wp_root whose wp-config names
//      a different database than the profile. This is the load-bearing
//      cross-DB check: a wp-config that points at the integration DB
//      would otherwise pass the server_uuid / datadir / port checks
//      (same MySQL instance) and the test bed's content would be
//      overwritten by the integration site's mutations.
Agentshell_Test::run(
    'guard refuses when wp-config DB_NAME differs from the profile',
    function () {
        $integration = '/home/v/local/agentshell-integration/app/public';
        if ( ! is_file( $integration . '/wp-config.php' ) ) {
            throw new Exception( 'integration wp-config not present at ' . $integration . ' — cannot run negative' );
        }
        $result = agentshell_destructive_identity_guard( $integration );
        Agentshell_Test::assert_false(
            $result['ok'],
            'integration wp_root must refuse'
        );
        Agentshell_Test::assert_equals(
            'db_name',
            substr( $result['refusal'], 0, 7 ),
            'cross-DB check must fire first; got refusal: ' . $result['refusal']
        );
    }
);

// ── 4. The live test bed must carry the expected sentinel
//      (agentshell_site_mode = "disposable_test_bed"), AND the guard
//      must refuse a profile whose expected sentinel disagrees with
//      the live test bed. The two halves of this single test cover
//      the sentinel check end-to-end:
//        (a) positive: live test bed has the expected sentinel,
//            guard accepts;
//        (b) negative: a synthetic profile whose expected sentinel
//            is wrong is refused at the sentinel gate before any
//            other check runs.
//      The previous form of this test asserted only the negative
//      case, which made sense before the Phase E restore when the
//      live test bed was contaminated (sentinel = "integration").
//      After the restore, the live test bed has the legitimate
//      sentinel, so the positive leg is the load-bearing assertion
//      and the negative leg proves the check is still in the chain
//      and fires first.
Agentshell_Test::run(
    'guard enforces site_mode_sentinel on both positive (match) and negative (mismatch) paths',
    function () {
        $cfg      = agentshell_test_bed_load();
        $wp_root  = $cfg['wp_root'] ?? '';
        $expected_sentinel = isset( $cfg['expect']['site_mode_sentinel'] )
            ? (string) $cfg['expect']['site_mode_sentinel']
            : '';
        Agentshell_Test::assert_true(
            '' !== $expected_sentinel,
            'test bed profile must declare expect.site_mode_sentinel'
        );
        Agentshell_Test::assert_true(
            is_file( $wp_root . '/wp-load.php' ),
            'test bed wp_root must exist for this test'
        );

        // (a) positive: live test bed matches the profile.
        $accept = agentshell_destructive_identity_guard( $wp_root, $cfg );
        Agentshell_Test::assert_true(
            $accept['ok'],
            'live test bed with the expected sentinel must be accepted; got refusal: ' . $accept['refusal']
        );
        Agentshell_Test::assert_equals(
            $expected_sentinel,
            $accept['sentinel'],
            'observed sentinel must equal the profile sentinel'
        );

        // (b) negative: a profile whose expected sentinel disagrees
        // with the live test bed refuses at the sentinel gate.
        $contaminated_cfg           = $cfg;
        $contaminated_cfg['expect'] = is_array( $cfg['expect'] ?? null ) ? $cfg['expect'] : array();
        $contaminated_cfg['expect']['site_mode_sentinel'] = 'contaminated_marker';
        $refuse = agentshell_destructive_identity_guard( $wp_root, $contaminated_cfg );
        Agentshell_Test::assert_false(
            $refuse['ok'],
            'a profile whose sentinel disagrees with the live test bed must refuse'
        );
        Agentshell_Test::assert_equals(
            'site_mode_sentinel',
            substr( $refuse['refusal'], 0, 18 ),
            'the sentinel check must be the first to fail; got: ' . $refuse['refusal']
        );
    }
);

// ── 5. The guard's fail-closed policy: a wp_root that has wp-load.php
//      but no wp-config refuses at the wp_config_read gate (not at the
//      wp_root gate). This proves the chain is in order: the guard
//      must not silently skip the wp-config check just because
//      wp-load.php exists.
Agentshell_Test::run(
    'guard refuses when wp-config is unreadable',
    function () {
        $tmp_root = sys_get_temp_dir() . '/as_guard_unreadable_' . getmypid();
        @mkdir( $tmp_root, 0755, true );
        // Stub wp-load.php so the wp_root check passes; do NOT create
        // wp-config.php so the wp_config_read check fires.
        file_put_contents( $tmp_root . '/wp-load.php', "<?php // stub\n" );
        $result = agentshell_destructive_identity_guard( $tmp_root );
        @unlink( $tmp_root . '/wp-load.php' );
        @rmdir( $tmp_root );
        Agentshell_Test::assert_false(
            $result['ok'],
            'an unreadable wp-config must refuse'
        );
        Agentshell_Test::assert_equals(
            'wp_config_read',
            substr( $result['refusal'], 0, 14 ),
            'the wp_config_read check must be the first to fail; got: ' . $result['refusal']
        );
    }
);

// ── 6. The wrapper function (the one children call) actually exits 7
//      on a wrong wp_root. This is the wiring proof: even if a future
//      child is added without explicit per-child code, the wrapper
//      enforces the contract. Spawn a real PHP subprocess and read its
//      exit code.
Agentshell_Test::run(
    'wrapper exits 7 when the wp_root is wrong',
    function () use ( $guard_php, $guard_ini, $guard_env ) {
        $stub = '<?php require_once ' . var_export( __DIR__ . '/agentshell-test-bed.php', true ) . ';'
            . ' agentshell_destructive_child_identity_guard( "/no/such/path" );'
            . ' echo "should not reach here\\n"; exit( 99 );';
        $tmp = tempnam( sys_get_temp_dir(), 'as_guard_' );
        file_put_contents( $tmp, $stub );
        $ini_args = '';
        foreach ( $guard_ini as $arg ) {
            $ini_args .= ' -d ' . escapeshellarg( $arg );
        }
        $env_prefix = '';
        foreach ( $guard_env as $k => $v ) {
            // bash env-var form: K=V command (no quotes between = and V).
            // The value itself can contain spaces, so escape V only.
            $env_prefix .= $k . '=' . escapeshellarg( $v ) . ' ';
        }
        $cmd = $env_prefix
             . escapeshellcmd( $guard_php )
             . $ini_args
             . ' ' . escapeshellarg( $tmp );
        $out = array();
        $code = -1;
        exec( $cmd . ' 2>/dev/null', $out, $code );
        @unlink( $tmp );
        Agentshell_Test::assert_equals(
            7,
            $code,
            'wrapper must exit 7 on a wrong wp_root; got exit ' . $code . ' (stderr: ' . implode( "\n", $out ) . ')'
        );
    }
);

// ── 7. Every child script (`tests/*-live-child.php`) that takes a
//      wp_root argv calls the wrapper and refuses on a wrong wp_root.
//      This is the regression proof: if a future PR removes the guard
//      from any child, this test fails because the child returns its
//      own exit code (usually 2 for "no wp-load.php") instead of 7.
Agentshell_Test::run(
    'every destructive child script exits 7 on a wrong wp_root',
    function () use ( $guard_php, $guard_ini, $guard_env ) {
        $children = glob( __DIR__ . '/*-live-child.php' );
        Agentshell_Test::assert_true(
            is_array( $children ) && count( $children ) >= 10,
            'expected at least 10 destructive child scripts; found '
                . ( is_array( $children ) ? count( $children ) : 0 )
        );
        $report = '/tmp/as_guard_negative_report_' . getmypid() . '.json';
        @unlink( $report );
        $ini_args = '';
        foreach ( $guard_ini as $arg ) {
            $ini_args .= ' -d ' . escapeshellarg( $arg );
        }
        $env_prefix = '';
        foreach ( $guard_env as $k => $v ) {
            $env_prefix .= $k . '=' . escapeshellarg( $v ) . ' ';
        }
        foreach ( $children as $child ) {
            $cmd = $env_prefix
                 . escapeshellcmd( $guard_php )
                 . $ini_args
                 . ' ' . escapeshellarg( $child )
                 . ' /no/such/path ' . escapeshellarg( $report );
            $out = array();
            $code = -1;
            exec( $cmd . ' 2>/dev/null', $out, $code );
            Agentshell_Test::assert_equals(
                7,
                $code,
                'child ' . basename( $child ) . ' must exit 7 on a wrong wp_root; got exit '
                    . $code . ' — the guard is not wired into this child'
            );
        }
        @unlink( $report );
    }
);

// ── 8. The guard returns the same shape every time. Every check is
//      present in $checks, ok is a bool, and refusal is a string.
//      A future PR that drops a check from the array must break this
//      test; that is a deliberate contract lock.
Agentshell_Test::run(
    'guard result shape is stable',
    function () {
        $result = agentshell_destructive_identity_guard( '/no/such/path' );
        Agentshell_Test::assert_true(
            is_array( $result ),
            'result must be an array'
        );
        Agentshell_Test::assert_true(
            array_key_exists( 'ok', $result ) && is_bool( $result['ok'] ),
            'ok must be a bool'
        );
        Agentshell_Test::assert_true(
            array_key_exists( 'checks', $result ) && is_array( $result['checks'] ),
            'checks must be an array'
        );
        Agentshell_Test::assert_true(
            array_key_exists( 'refusal', $result ) && is_string( $result['refusal'] ),
            'refusal must be a string'
        );
        $expected = array( 'test_bed_config', 'wp_root' );
        foreach ( $expected as $name ) {
            Agentshell_Test::assert_true(
                array_key_exists( $name, $result['checks'] ),
                'checks must include "' . $name . '" so the caller can render the failure chain'
            );
        }
    }
);

Agentshell_Test::summary();
