<?php
/**
 * Regression test for the test-harness bootstrap contract.
 *
 * Why this exists
 * ---------------
 * Prior to commit 7990ee3, tests/agentshell-test-bed.php defined
 * `ABSPATH = __DIR__ . '/'` at file-include time. The file is a
 * test-bed identity guard, not a WordPress bootstrap. When a live
 * child (any `tests/*-live-child.php`) required it, ABSPATH was
 * permanently set to the tests directory. The child's own
 * `define('ABSPATH', rtrim($wp_root, '/') . '/')` had no effect
 * (PHP 8.x warns that the constant is already defined and refuses
 * to replace it), so the subsequent `require_once ABSPATH .
 * 'wp-load.php'` failed with a fatal
 * "Failed opening required .../tests/wp-load.php" — exit 255
 * instead of the structured guard refusal (exit 7) the
 * test infrastructure expects.
 *
 * Every destructive live child (10 scripts) was affected. The
 * previous fix (7990ee3) removed the spurious define; this test
 * is the regression that prevents the same shape from being
 * reintroduced. If a future commit puts a `define('ABSPATH', ...)`
 * back into agentshell-test-bed.php, this test fails immediately
 * and the live children start exiting 255 again.
 *
 * What this test asserts
 * ----------------------
 * 1. After require_once agentshell-test-bed.php, ABSPATH is
 *    unchanged (whatever value the caller set, or undefined if
 *    the caller did not set one).
 * 2. agentshell-test-bed.php must not contain a literal
 *    `define(`ABSPATH` (or any case-variant) outside of comments
 *    and string literals — the contract is "no ABSPATH define,
 *    ever, in this file". This is the static-source guarantee;
 *    test 1 is the runtime guarantee.
 * 3. The in-process guard still works (sanity check that the
 *    removal of the ABSPATH define did not break the guard's
 *    own bootstrap).
 *
 * No DB. No HTTP. No test-bed install required. Pure unit test.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';

echo "Test-harness bootstrap contract\n";

// ── 1. Including agentshell-test-bed.php must not touch ABSPATH.
//      The contract: agentshell-test-bed.php is a test-bed identity
//      guard, not a WordPress bootstrap. The constant `ABSPATH` is
//      a WordPress convention; a test-harness file must not
//      declare it. If a future commit reintroduces the define,
//      this test fires immediately on a live child that needs
//      ABSPATH to point at the real WordPress root.
Agentshell_Test::run(
    'include agentshell-test-bed.php does not change ABSPATH',
    function () {
        // Set ABSPATH to a known sentinel value. The bootstrap
        // shim at tests/bootstrap.php may already have set it
        // (to the tests dir) for its in-memory stubs; that is
        // fine — what we are asserting is that the
        // agentshell-test-bed.php include does not *change* it.
        $sentinel = '/sentinel/known/value-' . getmypid();
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', $sentinel );
        } else {
            // If ABSPATH is already defined (e.g. by the parent
            // bootstrap), we still need a fresh subprocess for
            // the include to be observable in isolation. Run a
            // subprocess.
            $stub = '<?php'
                . ' error_reporting( 0 );'
                . ' ini_set( "display_errors", "stderr" );'
                . ' define( "ABSPATH", "/sentinel/known/value-original" );'
                . ' require_once ' . var_export( __DIR__ . '/agentshell-test-bed.php', true ) . ';'
                . ' echo ABSPATH;';
            $tmp = tempnam( sys_get_temp_dir(), 'as_bs_bootstrap_' );
            file_put_contents( $tmp, $stub );
            $out = array();
            $code = -1;
            exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tmp ) . ' 2>/dev/null', $out, $code );
            @unlink( $tmp );
            Agentshell_Test::assert_equals(
                '/sentinel/known/value-original',
                implode( '', $out ),
                'agentshell-test-bed.php must not change ABSPATH (subprocess: ' . $code . ')'
            );
            return;
        }
        require_once __DIR__ . '/agentshell-test-bed.php';
        Agentshell_Test::assert_equals(
            $sentinel,
            ABSPATH,
            'agentshell-test-bed.php must not change the value of ABSPATH; got: ' . ABSPATH
        );
    }
);

// ── 2. The static source must not contain a literal ABSPATH define.
//      This is the contract enforcement: a future commit that
//      reintroduces the bug fails this test even if the runtime
//      shape happens to be harmless in some other context.
Agentshell_Test::run(
    'agentshell-test-bed.php source does not declare ABSPATH',
    function () {
        $path = __DIR__ . '/agentshell-test-bed.php';
        Agentshell_Test::assert_true(
            is_file( $path ),
            "agentshell-test-bed.php must exist at $path"
        );
        $src = file_get_contents( $path );
        Agentshell_Test::assert_true(
            is_string( $src ) && '' !== $src,
            'agentshell-test-bed.php must be readable and non-empty'
        );
        // Strip line and block comments so the test does not flag
        // `define('ABSPATH')` in documentation.
        $stripped = preg_replace(
            array( '#//.*$#m', '#/\*.*?\*/#s' ),
            '',
            $src
        );
        Agentshell_Test::assert_true(
            is_string( $stripped ),
            'stripped source must remain a string'
        );
        Agentshell_Test::assert_false(
            (bool) preg_match( "/define\s*\(\s*['\"]ABSPATH['\"]/i", $stripped ),
            'agentshell-test-bed.php must not call define() on ABSPATH; the file is a test-bed guard, not a WordPress bootstrap'
        );
    }
);

// ── 3. Sanity check: the in-process guard still runs end-to-end
//      after the include. This proves the ABSPATH removal did not
//      silently break the guard's own dependencies.
Agentshell_Test::run(
    'in-process destructive identity guard still runs after the include',
    function () {
        $cfg = agentshell_test_bed_load();
        $result = is_array( $cfg )
            ? agentshell_destructive_identity_guard( '/no/such/path' )
            : agentshell_destructive_identity_guard( '/no/such/path' );
        Agentshell_Test::assert_true(
            is_array( $result ),
            'guard must return an array'
        );
        Agentshell_Test::assert_true(
            array_key_exists( 'ok', $result ) && false === $result['ok'],
            'guard must refuse a non-existent wp_root'
        );
        Agentshell_Test::assert_true(
            array_key_exists( 'refusal', $result ) && is_string( $result['refusal'] ) && '' !== $result['refusal'],
            'refusal must be a non-empty string'
        );
    }
);

Agentshell_Test::summary();
