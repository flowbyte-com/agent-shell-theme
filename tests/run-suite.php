<?php
/**
 * Run one suite in its own process, and report the result machine-readably.
 *
 * Why this exists
 * ---------------
 * The parent runner used to `require` every suite into its own process.
 * That made the runner's own lifecycle the suites' lifecycle: a suite
 * that called `exit()` — as `destructive-identity-guard` did when it
 * found no live WordPress — ended the *entire run*, silently, with a
 * success exit code. Every suite after it was skipped without a word,
 * and the totals the runner printed described a fraction of the work.
 *
 * A suite is now a process. It gets its own address space, so it can
 * exit, die, or fork without touching the run. The parent reads the
 * result from a sentinel line this script prints, and a suite that
 * fails to print one has not reported a result — which is a failure of
 * the run, not a pass.
 *
 * The protocol
 * ------------
 *   php tests/run-suite.php <suite-path> <label>
 *
 * On stdout, last, exactly one line:
 *
 *   __AGENTSHELL_SUITE_RESULT__ {"label":"…","completed":true,…}
 *
 * `completed` is false when the suite did not run to the end of its
 * file — because it exited, or because it died. `skipped` is true when
 * the suite declared that it could not run. The two are different
 * things: a skipped suite is unexecuted on purpose; an incomplete suite
 * is one that stopped without saying why.
 *
 * The sentinel is printed from a shutdown function, so it is printed
 * even when the suite exits early. That is deliberate: the parent needs
 * to see *that* the suite stopped, not to pretend it did not.
 *
 * Usage:
 *   php tests/run-suite.php tests/doctor-test.php doctor
 */

if ( 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "run-suite.php is a command-line script\n" );
    exit( 2 );
}

$suite_path = isset( $argv[1] ) ? (string) $argv[1] : '';
$label      = isset( $argv[2] ) ? (string) $argv[2] : basename( $suite_path, '.php' );

if ( '' === $suite_path || ! is_file( $suite_path ) ) {
    fwrite( STDERR, "run-suite.php: no such suite: " . $suite_path . "\n" );
    exit( 2 );
}

// The aggregate mode is what tells a suite "you are being run by the
// runner: report through the shared counters instead of deciding the
// process's exit status yourself".
define( 'AGENTSHELL_TEST_AGGREGATE', true );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mutation-sandbox.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

/**
 * The result, as it stands right now.
 *
 * The shutdown function reads this by reference, so it always describes
 * the suite's final state — including the state after an early exit.
 */
$agentshell_suite_result = array(
    'label'     => $label,
    'completed' => false,
    'passed'    => 0,
    'failed'    => 0,
    'skipped'   => false,
    'reason'    => '',
);

register_shutdown_function( function () use ( &$agentshell_suite_result ) {
    $agentshell_suite_result['passed']  = Agentshell_Test::$passed;
    $agentshell_suite_result['failed']  = Agentshell_Test::$failed;
    $agentshell_suite_result['skipped'] = Agentshell_Test::$skipped;
    $agentshell_suite_result['reason']  = Agentshell_Test::$skip_reason;
    echo "\n__AGENTSHELL_SUITE_RESULT__ " . json_encode( $agentshell_suite_result ) . "\n";
} );

require $suite_path;

// The suite ran to the end of its file. If it printed its own summary,
// that is the summary; if it did not, print one so every suite's report
// ends with its own totals.
$agentshell_suite_result['completed'] = true;
if ( 0 === Agentshell_Test::$summaries ) {
    Agentshell_Test::summary();
}
