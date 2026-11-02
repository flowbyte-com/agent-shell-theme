<?php
/**
 * A suite that proves nothing must not read as a suite that passed.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The runner had one rule about emptiness: a suite that reports no
 * verdict at all is a failure of the run, not a silent omission. That
 * rule covered the *missing* verdict and the *truncated* verdict. It did
 * not cover the **well-formed empty** verdict.
 *
 * The nested Node/browser suites report their result as a line of text —
 * `N passed, M failed` — and the PHP shim that drives each of them
 * parsed that line and added the two numbers to the aggregate. A child
 * that died before its first assertion could still print the line, and
 * `0 passed, 0 failed` is a well-formed line. The two zeroes were
 * added to the aggregate as zeroes, the shim finished with nothing to
 * report, and the suite was read as a **pass**.
 *
 * So the one place a nested suite could crash without anybody noticing
 * was the one place the runner could not see. A green run said "every
 * nested suite ran and passed" when it could only say "no nested suite
 * reported a failure".
 *
 * What is asserted here
 * ---------------------
 * 1. The verdict logic is callable on its own, so it can be tested
 *    without running the whole suite registry.
 * 2. The verdict table, case by case: an empty completed suite fails; a
 *    skipped suite stays skipped; a suite with assertions passes; a
 *    suite with a failure fails; a truncated suite is incomplete; a
 *    suite with no verdict at all is not a pass.
 * 3. The same verdicts, end to end, through the real dispatch path and
 *    with real suite processes — so the rule is proven where it lives,
 *    not only in the function that implements it.
 * 4. The aggregate refuses to call an empty suite a pass.
 * 5. The helper that shims use records an empty child as a failure of
 *    the shim, and does not add the child's zeroes to the counters.
 * 6. Every PHP file that parses a child's count line routes it through
 *    that helper — so the hole cannot be reopened by adding a twelfth
 *    shim.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/runner-dispatch.php';

echo "runner verdict regression tests\n";

// ── the fixtures ─────────────────────────────────────────────────────────────

/**
 * A directory of synthetic suites, written once and removed at the end.
 *
 * They are written to a temporary directory rather than committed,
 * because they are not tests: they are the *subjects* the runner is
 * asked to judge. A committed empty suite would be a real suite in the
 * registry, and the registry is for real suites.
 *
 * @return string The directory path.
 */
function runner_verdict_fixture_dir() {
    static $dir = null;
    if ( null !== $dir ) {
        return $dir;
    }
    $dir = sys_get_temp_dir() . '/agentshell-runner-verdict-' . getmypid();
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0700, true );
    }

    // A suite that runs to the end of its file and asserts nothing.
    // This is the defect: a well-formed, empty verdict.
    file_put_contents(
        $dir . '/empty-suite.php',
        "<?php\n"
        . "// A suite that completes without asserting anything.\n"
        . "echo \"this suite ran and proved nothing\\n\";\n"
    );

    // A suite that asserts something. The control: the fix must not
    // turn a real suite into a failure.
    file_put_contents(
        $dir . '/passing-suite.php',
        "<?php\n"
        . "Agentshell_Test::run( 'the synthetic suite asserts something', function () {\n"
        . "    Agentshell_Test::assert_true( true, 'a synthetic assertion' );\n"
        . "} );\n"
    );

    // A suite that declares it cannot run. A skip is not a failure and
    // must not become one.
    file_put_contents(
        $dir . '/skipping-suite.php',
        "<?php\n"
        . "Agentshell_Test::skip( 'the synthetic suite cannot run here' );\n"
    );

    // A suite that dies. The shutdown function still prints the
    // sentinel, but the suite did not run to the end of its file.
    file_put_contents(
        $dir . '/crashing-suite.php',
        "<?php\n"
        . "echo \"about to die\\n\";\n"
        . "this_function_does_not_exist();\n"
    );

    // A suite that exits early, without saying why.
    file_put_contents(
        $dir . '/truncating-suite.php',
        "<?php\n"
        . "Agentshell_Test::run( 'the synthetic suite asserted before it stopped', function () {\n"
        . "    Agentshell_Test::assert_true( true, 'a synthetic assertion' );\n"
        . "} );\n"
        . "exit( 0 );\n"
    );

    return $dir;
}

/** Remove the synthetic suites. */
function runner_verdict_cleanup() {
    $dir = runner_verdict_fixture_dir();
    foreach ( glob( $dir . '/*.php' ) as $file ) {
        @unlink( $file );
    }
    @rmdir( $dir );
}

// ── 1. the verdict logic is reachable ───────────────────────────────────────

Agentshell_Test::run(
    'the runner exposes its verdict logic as a callable function',
    function () {
        Agentshell_Test::assert_true(
            function_exists( 'agentshell_suite_verdict' ),
            'agentshell_suite_verdict() must exist, so the rule can be asserted rather than inferred'
        );
        Agentshell_Test::assert_true(
            function_exists( 'agentshell_run_suite_isolated' ),
            'the isolated runner must exist, so the rule can be proved end to end'
        );
    }
);

// ── 2. the verdict table, case by case ──────────────────────────────────────

Agentshell_Test::run(
    'a completed suite that asserted nothing is a failure, not a pass',
    function () {
        $verdict = agentshell_suite_verdict(
            array( 'completed' => true, 'passed' => 0, 'failed' => 0, 'skipped' => false ),
            0
        );
        Agentshell_Test::assert_equals( 'failed', $verdict['status'], 'the status must be failed' );
        Agentshell_Test::assert_equals( 1, $verdict['failed'], 'the failure must be counted' );
        Agentshell_Test::assert_true(
            false !== stripos( $verdict['reason'], 'assert' ),
            'the reason must say what was wrong: ' . $verdict['reason']
        );
    }
);

Agentshell_Test::run(
    'a skipped suite that asserted nothing stays skipped',
    function () {
        $verdict = agentshell_suite_verdict(
            array( 'completed' => true, 'passed' => 0, 'failed' => 0, 'skipped' => true, 'reason' => 'no browser' ),
            0
        );
        Agentshell_Test::assert_equals(
            'skipped',
            $verdict['status'],
            'a suite that declared it could not run is unexecuted on purpose — not a failure'
        );
        Agentshell_Test::assert_equals( 0, $verdict['failed'], 'a skip must not be counted as a failure' );
    }
);

Agentshell_Test::run(
    'a completed suite with assertions passes',
    function () {
        $verdict = agentshell_suite_verdict(
            array( 'completed' => true, 'passed' => 3, 'failed' => 0, 'skipped' => false ),
            0
        );
        Agentshell_Test::assert_equals( 'passed', $verdict['status'], 'the status must be passed' );
        Agentshell_Test::assert_equals( 3, $verdict['passed'], 'the passing count must survive' );
        Agentshell_Test::assert_equals( 0, $verdict['failed'], 'nothing may be counted as a failure' );
    }
);

Agentshell_Test::run(
    'a completed suite with a failure fails',
    function () {
        $verdict = agentshell_suite_verdict(
            array( 'completed' => true, 'passed' => 3, 'failed' => 2, 'skipped' => false ),
            0
        );
        Agentshell_Test::assert_equals( 'failed', $verdict['status'], 'the status must be failed' );
        Agentshell_Test::assert_equals( 2, $verdict['failed'], 'the failure count must survive' );
    }
);

Agentshell_Test::run(
    'a suite that stopped before the end of its file is incomplete, not a pass',
    function () {
        $verdict = agentshell_suite_verdict(
            array( 'completed' => false, 'passed' => 1, 'failed' => 0, 'skipped' => false ),
            0
        );
        Agentshell_Test::assert_equals( 'incomplete', $verdict['status'], 'the status must be incomplete' );
        Agentshell_Test::assert_true( $verdict['failed'] >= 1, 'an incomplete suite must fail the run' );
    }
);

Agentshell_Test::run(
    'a suite that stopped after declaring a skip is skipped',
    function () {
        $verdict = agentshell_suite_verdict(
            array( 'completed' => false, 'passed' => 0, 'failed' => 0, 'skipped' => true, 'reason' => 'no test bed' ),
            0
        );
        Agentshell_Test::assert_equals( 'skipped', $verdict['status'], 'a declared skip is a skip' );
    }
);

Agentshell_Test::run(
    'a suite that reported no verdict at all is not a pass',
    function () {
        $clean_exit = agentshell_suite_verdict( null, 0 );
        Agentshell_Test::assert_equals( 'incomplete', $clean_exit['status'], 'a silent stop is incomplete' );
        Agentshell_Test::assert_equals( 1, $clean_exit['failed'], 'a silent stop must fail the run' );

        $bad_exit = agentshell_suite_verdict( null, 3 );
        Agentshell_Test::assert_equals( 'failed', $bad_exit['status'], 'a non-zero exit is a failure' );
        Agentshell_Test::assert_true(
            false !== strpos( $bad_exit['reason'], '3' ),
            'the reason must name the exit status: ' . $bad_exit['reason']
        );
    }
);

// ── 3. the same verdicts, end to end, through real suite processes ──────────

Agentshell_Test::run(
    'end to end: an empty suite is reported as failed by the dispatch path',
    function () {
        $dir = runner_verdict_fixture_dir();

        $empty = agentshell_run_suite_isolated( 'synthetic-empty', $dir . '/empty-suite.php' );
        Agentshell_Test::assert_equals(
            'failed',
            $empty['status'],
            'a suite that completed with no assertions must not be reported as passed: '
            . $empty['reason']
        );
        Agentshell_Test::assert_true(
            $empty['failed'] >= 1,
            'the empty suite must contribute a failure to the aggregate'
        );
    }
);

Agentshell_Test::run(
    'end to end: a suite with assertions still passes',
    function () {
        $dir = runner_verdict_fixture_dir();

        $passing = agentshell_run_suite_isolated( 'synthetic-passing', $dir . '/passing-suite.php' );
        Agentshell_Test::assert_equals(
            'passed',
            $passing['status'],
            'the fix must not fail a suite that asserted something: ' . $passing['reason']
        );
        Agentshell_Test::assert_equals( 1, $passing['passed'], 'the passing count must survive' );
    }
);

Agentshell_Test::run(
    'end to end: a declared skip is still a skip',
    function () {
        $dir = runner_verdict_fixture_dir();

        $skipping = agentshell_run_suite_isolated( 'synthetic-skipping', $dir . '/skipping-suite.php' );
        Agentshell_Test::assert_equals(
            'skipped',
            $skipping['status'],
            'the fix must not turn a skip into a failure: ' . $skipping['reason']
        );
    }
);

Agentshell_Test::run(
    'end to end: a suite that dies is not a pass',
    function () {
        $dir = runner_verdict_fixture_dir();

        $crashing = agentshell_run_suite_isolated( 'synthetic-crashing', $dir . '/crashing-suite.php' );
        Agentshell_Test::assert_true(
            in_array( $crashing['status'], array( 'failed', 'incomplete' ), true ),
            'a crashed suite must be reported as failed or incomplete, not passed'
        );
        Agentshell_Test::assert_true(
            $crashing['failed'] >= 1,
            'a crashed suite must contribute a failure to the aggregate'
        );
    }
);

Agentshell_Test::run(
    'end to end: a suite that exits early is not a pass',
    function () {
        $dir = runner_verdict_fixture_dir();

        $truncating = agentshell_run_suite_isolated( 'synthetic-truncating', $dir . '/truncating-suite.php' );
        Agentshell_Test::assert_equals(
            'incomplete',
            $truncating['status'],
            'a suite that stopped before the end of its file is incomplete'
        );
    }
);

// ── 4. the aggregate refuses to call an empty suite a pass ──────────────────

Agentshell_Test::run(
    'the aggregate reports an empty suite as a failed suite',
    function () {
        $dir = runner_verdict_fixture_dir();

        $suites = array(
            'synthetic-empty' => array( 'path' => $dir . '/empty-suite.php', 'requires' => 'unit' ),
        );
        $env = array( 'unit' => true, 'testbed' => false, 'live' => false, 'destructive' => false, 'reasons' => array() );

        $run = agentshell_dispatch_suites( $suites, $env, false );

        Agentshell_Test::assert_true(
            in_array( 'synthetic-empty', $run['failed_suites'], true ),
            'the empty suite must be listed among the failed suites: ' . implode( ', ', $run['failed_suites'] )
        );
        Agentshell_Test::assert_true(
            $run['failed'] >= 1,
            'the aggregate must count the empty suite as a failure, not as zero'
        );
        Agentshell_Test::assert_equals(
            0,
            $run['passed'],
            'the aggregate must not report a pass for a suite that asserted nothing'
        );
    }
);

Agentshell_Test::run(
    'the aggregate still reports a suite with assertions as a pass',
    function () {
        $dir = runner_verdict_fixture_dir();

        $suites = array(
            'synthetic-passing' => array( 'path' => $dir . '/passing-suite.php', 'requires' => 'unit' ),
        );
        $env = array( 'unit' => true, 'testbed' => false, 'live' => false, 'destructive' => false, 'reasons' => array() );

        $run = agentshell_dispatch_suites( $suites, $env, false );

        Agentshell_Test::assert_equals( array(), $run['failed_suites'], 'no suite may be listed as failed' );
        Agentshell_Test::assert_equals( 1, $run['passed'], 'the passing suite must be counted' );
        Agentshell_Test::assert_equals( 0, $run['failed'], 'nothing may be counted as a failure' );
    }
);

// ── 5. the helper the shims use ─────────────────────────────────────────────

Agentshell_Test::run(
    'the child-count helper records an empty child as a failure of the shim',
    function () {
        Agentshell_Test::assert_true(
            function_exists( 'agentshell_absorb_child_counts' ),
            'agentshell_absorb_child_counts() must exist, so every shim shares one rule'
        );

        $failed_before = Agentshell_Test::$failed;
        $passed_before = Agentshell_Test::$passed;

        // The helper reports the emptiness as a failure of this suite,
        // which prints a FAIL line. That line is the evidence, and it
        // is expected here, so it is captured rather than printed.
        ob_start();
        $returned = agentshell_absorb_child_counts( 0, 0, 'the child printed nothing useful' );
        $reported = ob_get_clean();

        Agentshell_Test::assert_true(
            false !== stripos( (string) $reported, 'asserted nothing' ),
            'the helper must say what was wrong: ' . trim( (string) $reported )
        );
        Agentshell_Test::assert_equals(
            1,
            $returned,
            'the helper must report a failure to the shim that called it'
        );
        Agentshell_Test::assert_equals(
            $failed_before + 1,
            Agentshell_Test::$failed,
            'the empty child must be recorded as a failure of this suite'
        );
        Agentshell_Test::assert_equals(
            $passed_before,
            Agentshell_Test::$passed,
            'the empty child must not be counted as a pass'
        );

        // Undo the counts this assertion consumed, so the suite's own
        // totals describe its own assertions. The inner failure the
        // helper printed is part of the evidence.
        Agentshell_Test::$failed = $failed_before;
        Agentshell_Test::$passed = $passed_before;
    }
);

Agentshell_Test::run(
    'the child-count helper adds a real child count to the aggregate',
    function () {
        $passed_before = Agentshell_Test::$passed;
        $failed_before = Agentshell_Test::$failed;

        $returned = agentshell_absorb_child_counts( 4, 0, '4 passed, 0 failed' );

        Agentshell_Test::assert_equals( 0, $returned, 'a child with no failures reports none' );
        Agentshell_Test::assert_equals(
            $passed_before + 4,
            Agentshell_Test::$passed,
            "a child's passing assertions must reach the aggregate"
        );
        Agentshell_Test::assert_equals(
            $failed_before,
            Agentshell_Test::$failed,
            "a child's passing assertions must not add failures"
        );

        Agentshell_Test::$passed = $passed_before;
        Agentshell_Test::$failed = $failed_before;
    }
);

Agentshell_Test::run(
    'the child-count helper can judge a child without counting it',
    function () {
        $passed_before = Agentshell_Test::$passed;
        $failed_before = Agentshell_Test::$failed;

        // A shim that asserts *about* a child rather than counting it
        // passes $add = false, so the child's numbers do not reach the
        // counters twice.
        $returned = agentshell_absorb_child_counts( 7, 0, '7 passed, 0 failed', false );

        Agentshell_Test::assert_equals( 0, $returned, 'a child with no failures reports none' );
        Agentshell_Test::assert_equals(
            $passed_before,
            Agentshell_Test::$passed,
            'the child count must not be added twice'
        );
        Agentshell_Test::assert_equals(
            $failed_before,
            Agentshell_Test::$failed,
            'the child count must not be added twice'
        );
    }
);

Agentshell_Test::run(
    'the child-count helper reports a child that failed',
    function () {
        $passed_before = Agentshell_Test::$passed;
        $failed_before = Agentshell_Test::$failed;

        $returned = agentshell_absorb_child_counts( 3, 2, '3 passed, 2 failed' );

        Agentshell_Test::assert_equals( 2, $returned, "the child's failures must be reported" );
        Agentshell_Test::assert_equals(
            $passed_before + 3,
            Agentshell_Test::$passed,
            "the child's passing assertions must reach the aggregate"
        );
        Agentshell_Test::assert_equals(
            $failed_before + 2,
            Agentshell_Test::$failed,
            "the child's failures must reach the aggregate"
        );

        Agentshell_Test::$passed = $passed_before;
        Agentshell_Test::$failed = $failed_before;
    }
);

// ── 6. no shim may parse a child's count without the guard ─────────────────

Agentshell_Test::run(
    'every file that parses a child count routes it through the guard',
    function () {
        // The needle is the regex text a shim uses to read a child's
        // verdict. This test file is excluded: it names the pattern in
        // order to look for it, and it calls the helper anyway.
        //
        // Both quantifiers are accepted (`\s+` and `\s*`), because the
        // shims were not written uniformly and a scan that matched only
        // one of them would miss half of them.
        $bs      = chr(92);
        $pattern = '/passed,' . preg_quote( $bs, '/' ) . 's[+*]\('
            . preg_quote( $bs, '/' ) . 'd\+\)'
            . preg_quote( $bs, '/' ) . 's[+*]failed/';

        $files = glob( __DIR__ . '/*.php' );
        Agentshell_Test::assert_true( is_array( $files ) && count( $files ) > 0, 'the tests directory must be readable' );

        $unguarded = array();
        $checked   = 0;
        foreach ( $files as $file ) {
            if ( realpath( $file ) === realpath( __FILE__ ) ) {
                continue;
            }
            $source = file_get_contents( $file );
            if ( ! is_string( $source ) || ! preg_match( $pattern, $source ) ) {
                continue;
            }
            $checked++;
            if ( false === strpos( $source, 'agentshell_absorb_child_counts' ) ) {
                $unguarded[] = basename( $file );
            }
        }

        Agentshell_Test::assert_true(
            $checked > 0,
            'the scan must find the shims it is meant to check — if it finds none, the needle is wrong'
        );
        Agentshell_Test::assert_equals(
            array(),
            $unguarded,
            'these files parse a nested child\'s count without the guard, so an empty child '
            . 'would read as a pass in them: ' . implode( ', ', $unguarded )
        );
    }
);

runner_verdict_cleanup();

if ( ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
    // Standalone, this file decides its own exit status. Under the
    // aggregate runner it must not: a suite must not end the run that
    // asked for it, and the runner reads the counters instead.
    echo "\n";
    echo "runner verdict: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
    exit( Agentshell_Test::$failed === 0 ? 0 : 1 );
}
