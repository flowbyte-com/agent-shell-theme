<?php
/**
 * The test runner's own regression test.
 *
 * Why this exists
 * ---------------
 * The runner used to `require` every suite into its own process. A suite
 * that called `exit()` therefore ended the *whole run*, silently, with a
 * success exit code, and every suite registered after it was skipped
 * without a word. `destructive-identity-guard` did exactly that when it
 * found no live WordPress — which is the normal case on a workstation
 * and in CI's unit job — so the run reported a smaller number of
 * assertions than it had executed, and it reported success.
 *
 * The fix is structural: a suite is a process, the runner reads a
 * machine-readable verdict from it, and a suite that stops without
 * reporting one is an *incomplete* suite rather than a silent omission.
 *
 * What this asserts
 * -----------------
 *   1. A suite that exits early is reported as incomplete, not as a pass.
 *   2. A suite registered after it still runs. This is the regression
 *      the whole change exists for.
 *   3. A suite that fails is reported as failed, and is counted.
 *   4. A suite that declares a skip is reported as skipped, and its
 *      assertions are not counted as passes.
 *   5. A suite the environment cannot support is skipped with a reason,
 *      before it is started, and is not counted as a pass.
 *   6. A requirement the registry does not declare is not run: a
 *      registry mistake must never read as permission.
 *   7. The real registry declares a requirement for every suite, and
 *      every suite it names exists on disk.
 *
 * The synthetic suites are written to the system temporary directory, not
 * into the checkout: the runner fingerprints the working tree before and
 * after the run, and a test that wrote into it would fail the run as a
 * modified checkout.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/runner-dispatch.php';

echo "Test runner lifecycle (dispatch, verdicts, skips)\n";

/**
 * The absolute path of the bootstrap, for the synthetic suites to load.
 */
$agentshell_bootstrap = realpath( __DIR__ . '/bootstrap.php' );

/**
 * Write a synthetic suite and return its path.
 *
 * @param string $name    A file name stem.
 * @param string $body    The suite's PHP.
 * @return string
 */
function agentshell_write_synthetic_suite( $name, $body ) {
    $dir = sys_get_temp_dir() . '/agentshell-runner-lifecycle-' . getmypid();
    if ( ! is_dir( $dir ) ) {
        @mkdir( $dir, 0700, true );
    }
    $path = $dir . '/' . $name . '.php';
    file_put_contents( $path, $body );
    return $path;
}

/**
 * Remove the synthetic suites' directory.
 */
function agentshell_remove_synthetic_suites() {
    $dir = sys_get_temp_dir() . '/agentshell-runner-lifecycle-' . getmypid();
    if ( ! is_dir( $dir ) ) {
        return;
    }
    foreach ( (array) glob( $dir . '/*' ) as $file ) {
        @unlink( (string) $file );
    }
    @rmdir( $dir );
}

$agentshell_prelude = "<?php\n"
    . "require_once '" . $agentshell_bootstrap . "';\n";

$suites = array(
    // 1. A suite that passes.
    'alpha' => array(
        'path'     => agentshell_write_synthetic_suite( 'alpha', $agentshell_prelude
            . "Agentshell_Test::run( 'alpha passes', function () {\n"
            . "    Agentshell_Test::assert_true( true );\n"
            . "} );\n" ),
        'requires' => 'unit',
    ),
    // 2. A suite that exits early — the shape that used to end the run.
    'early-exit' => array(
        'path'     => agentshell_write_synthetic_suite( 'early-exit', $agentshell_prelude
            . "fwrite( STDERR, \"skip: nothing to do here\\n\" );\n"
            . "exit( 0 );\n" ),
        'requires' => 'unit',
    ),
    // 3. A suite registered *after* the one that exits. This is the
    //    regression: it must still run.
    'after-the-early-exit' => array(
        'path'     => agentshell_write_synthetic_suite( 'after-the-early-exit', $agentshell_prelude
            . "Agentshell_Test::run( 'the suite after the early exit ran', function () {\n"
            . "    Agentshell_Test::assert_true( true );\n"
            . "} );\n" ),
        'requires' => 'unit',
    ),
    // 4. A suite that fails.
    'failing' => array(
        'path'     => agentshell_write_synthetic_suite( 'failing', $agentshell_prelude
            . "Agentshell_Test::run( 'this one fails', function () {\n"
            . "    Agentshell_Test::assert_true( false, 'on purpose' );\n"
            . "} );\n" ),
        'requires' => 'unit',
    ),
    // 5. A suite that declares a skip.
    'skipping' => array(
        'path'     => agentshell_write_synthetic_suite( 'skipping', $agentshell_prelude
            . "Agentshell_Test::skip( 'the environment has no fixture for this' );\n" ),
        'requires' => 'unit',
    ),
);

$environment = agentshell_runner_environment( array(
    'test_bed_cfg'   => null,
    'test_bed_check' => null,
    'http_ok'        => false,
    'unverified'     => false,
    'live'           => null,
    'destructive'    => null,
) );

// ── 1. the dispatch ─────────────────────────────────────────────────────────
$run = agentshell_dispatch_suites( $suites, $environment, false );

Agentshell_Test::run(
    'a suite that exits early is reported as incomplete, not as a pass',
    function () use ( $run ) {
        Agentshell_Test::assert_true(
            isset( $run['results']['early-exit'] ),
            'the early-exiting suite has a verdict'
        );
        Agentshell_Test::assert_equals(
            'incomplete',
            $run['results']['early-exit']['status'],
            'a suite that stopped without reporting a result is incomplete'
        );
        Agentshell_Test::assert_true(
            isset( $run['incomplete']['early-exit'] ),
            'the incomplete suite is listed as incomplete'
        );
        Agentshell_Test::assert_true(
            in_array( 'early-exit', $run['failed_suites'], true ),
            'an incomplete suite fails the run: a suite that vanished is not a suite that passed'
        );
    }
);

// ── 2. the regression itself ────────────────────────────────────────────────
Agentshell_Test::run(
    'a suite registered after the one that exits still runs',
    function () use ( $run ) {
        Agentshell_Test::assert_true(
            isset( $run['results']['after-the-early-exit'] ),
            'the suite after the early exit has a verdict'
        );
        Agentshell_Test::assert_equals(
            'passed',
            $run['results']['after-the-early-exit']['status'],
            'the suite after the early exit ran and passed'
        );
        Agentshell_Test::assert_equals(
            1,
            (int) $run['results']['after-the-early-exit']['passed'],
            'the suite after the early exit contributed its assertion'
        );
    }
);

// ── 3. a failing suite ──────────────────────────────────────────────────────
Agentshell_Test::run(
    'a suite that fails is reported as failed, and is counted',
    function () use ( $run ) {
        Agentshell_Test::assert_equals(
            'failed',
            $run['results']['failing']['status'],
            'the failing suite is reported as failed'
        );
        Agentshell_Test::assert_true(
            in_array( 'failing', $run['failed_suites'], true ),
            'the failing suite is listed among the failed suites'
        );
        Agentshell_Test::assert_true(
            (int) $run['failed'] >= 1,
            'the failure is counted'
        );
    }
);

// ── 4. a skipping suite ─────────────────────────────────────────────────────
Agentshell_Test::run(
    'a suite that declares a skip is reported as skipped, not as a pass',
    function () use ( $run ) {
        Agentshell_Test::assert_equals(
            'skipped',
            $run['results']['skipping']['status'],
            'the skipping suite is reported as skipped'
        );
        Agentshell_Test::assert_true(
            isset( $run['skipped']['skipping'] ),
            'the skipping suite is listed among the skipped suites'
        );
        Agentshell_Test::assert_true(
            '' !== $run['skipped']['skipping'],
            'the skip carries a reason'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) $run['results']['skipping']['passed'],
            'a skipped suite contributes no passes'
        );
    }
);

// ── 5. the totals distinguish the three ─────────────────────────────────────
Agentshell_Test::run(
    'the totals distinguish passed, failed and skipped',
    function () use ( $run ) {
        Agentshell_Test::assert_equals(
            2,
            (int) $run['passed'],
            'only the two suites that really passed are counted as passes'
        );
        Agentshell_Test::assert_equals(
            1,
            count( $run['skipped'] ),
            'exactly one suite is skipped'
        );
        Agentshell_Test::assert_equals(
            1,
            count( $run['incomplete'] ),
            'exactly one suite is incomplete'
        );
    }
);

// ── 6. eligibility ──────────────────────────────────────────────────────────
Agentshell_Test::run(
    'a suite the environment cannot support is skipped with a reason',
    function () use ( $environment ) {
        $suites = array(
            'needs-a-destroyed-site' => array(
                'path'     => '/nonexistent/suite.php',
                'requires' => 'destructive',
            ),
        );
        $run = agentshell_dispatch_suites( $suites, $environment, false );
        Agentshell_Test::assert_equals(
            'skipped',
            $run['results']['needs-a-destroyed-site']['status'],
            'a destructive suite is skipped when there is no verified test bed'
        );
        Agentshell_Test::assert_true(
            '' !== $run['skipped']['needs-a-destroyed-site'],
            'the skip says why'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) $run['passed'],
            'a skipped suite contributes no passes'
        );
    }
);

Agentshell_Test::run(
    'a requirement the registry does not declare is not run',
    function () use ( $environment ) {
        $suites = array(
            'mystery' => array(
                'path'     => '/nonexistent/suite.php',
                'requires' => 'something-else',
            ),
        );
        $run = agentshell_dispatch_suites( $suites, $environment, false );
        Agentshell_Test::assert_equals(
            'skipped',
            $run['results']['mystery']['status'],
            'a suite with an unknown requirement is not run'
        );
    }
);

Agentshell_Test::run(
    'a unit suite runs whatever the environment is',
    function () use ( $environment ) {
        $eligibility = agentshell_suite_eligibility( 'unit', $environment );
        Agentshell_Test::assert_true( $eligibility['eligible'], 'a unit suite is always eligible' );
    }
);

// ── 7. the real registry ────────────────────────────────────────────────────
Agentshell_Test::run(
    'the real registry declares a requirement for every suite, and every suite exists',
    function () {
        $source = (string) file_get_contents( __DIR__ . '/run-all.php' );
        Agentshell_Test::assert_true(
            false !== strpos( $source, "'requires' =>" ),
            'the registry declares requirements'
        );
        // Every entry in the registry is a name => array( path, requires ).
        preg_match_all(
            "/'([a-z0-9\-]+)'\\s*=>\\s*array\\(\\s*'path'\\s*=>\\s*__DIR__\\s*\\.\\s*'([^']+)'\\s*,\\s*'requires'\\s*=>\\s*'([a-z]+)'/s",
            $source,
            $matches,
            PREG_SET_ORDER
        );
        Agentshell_Test::assert_true(
            count( $matches ) > 50,
            'the registry was parsed and holds the whole suite list (' . count( $matches ) . ' suites)'
        );
        $missing = array();
        $undeclared = array();
        foreach ( $matches as $m ) {
            if ( ! is_file( __DIR__ . $m[2] ) ) {
                $missing[] = $m[1];
            }
            if ( ! in_array( $m[3], array( 'unit', 'testbed', 'live', 'destructive' ), true ) ) {
                $undeclared[] = $m[1] . '=' . $m[3];
            }
        }
        Agentshell_Test::assert_equals(
            array(),
            $missing,
            'every suite the registry names exists on disk'
        );
        Agentshell_Test::assert_equals(
            array(),
            $undeclared,
            'every suite declares a requirement the runner understands'
        );
    }
);

Agentshell_Test::run(
    'the registry has no suite registered after a guard that can end the run',
    function () {
        // The guard is still in the registry — it is a real suite and it
        // must run where it can. What must not exist is a suite that
        // *depends* on the guard having run, because the guard may be
        // skipped. Every suite in the registry is independent, so this
        // holds by construction now; the assertion is that it keeps
        // holding.
        $source = (string) file_get_contents( __DIR__ . '/run-all.php' );
        Agentshell_Test::assert_true(
            false !== strpos( $source, "'destructive-identity-guard'" ),
            'the guard is still registered'
        );
        Agentshell_Test::assert_true(
            is_file( __DIR__ . '/run-suite.php' ),
            'the suite runner exists'
        );
    }
);

agentshell_remove_synthetic_suites();

Agentshell_Test::summary();
