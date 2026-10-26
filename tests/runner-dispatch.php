<?php
/**
 * The runner's dispatch contract.
 *
 * The runner used to `require` every suite into its own process, which
 * made the runner's lifecycle the suites' lifecycle: a suite that called
 * `exit()` ended the whole run, silently, with a success exit code, and
 * every suite after it was skipped without a word.
 *
 * This file holds the three pieces that fix that, apart from the
 * process isolation itself:
 *
 *   1. `agentshell_runner_environment()` — what this machine can offer:
 *      a plain unit run, a test-bed profile, a live WordPress, or a
 *      *verified* test bed. The environment is resolved once, by the
 *      runner, and every suite is judged against the same answer.
 *   2. `agentshell_suite_eligibility()` — whether one suite may run
 *      here, and the sentence to print when it may not. A suite that
 *      cannot run is *skipped with a reason*, never silently omitted
 *      and never counted as a pass.
 *   3. `agentshell_dispatch_suites()` — the loop. Every suite runs in
 *      its own process (see `run-suite.php`), every suite produces a
 *      verdict, and the verdicts are aggregated into a report that
 *      distinguishes passed, failed, skipped and incomplete.
 *
 * The requirement vocabulary
 * --------------------------
 *   'unit'        — no external dependency. Always eligible.
 *   'testbed'     — needs the opt-in test-bed *profile* to exist, and
 *                   only reads it. (A suite that asserts something about
 *                   the profile's own contents.)
 *   'live'        — needs a resolvable live WordPress, and only reads
 *                   from it.
 *   'destructive' — needs a *verified* test bed and writes to it: the
 *                   profile, the mysqli extension in the runner's PHP,
 *                   the fail-closed identity check, and the HTTP
 *                   identity handshake must all be in place.
 *
 * The destructive boundary is unchanged by any of this. A suite that
 * says 'destructive' runs only when every one of those four holds, and
 * the reason it did not run is printed.
 */

if ( ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
    define( 'AGENTSHELL_TEST_AGGREGATE', true );
}

/**
 * What this machine can offer the suites.
 *
 * The answers are resolved once, here, so the runner and the suites
 * cannot disagree about whether an environment exists. That disagreement
 * was the second half of the early-exit defect: the runner decided a
 * suite was eligible because a *profile file* existed, while the suite
 * decided it was not because the profile did not *resolve* — and the
 * suite's exit ended the run.
 *
 * @param array $args {
 *     @type array|null $test_bed_cfg    The loaded test-bed profile, or null.
 *     @type array|null $test_bed_check  The fail-closed check's result, or null.
 *     @type bool       $http_ok          Whether the HTTP identity handshake passed.
 *     @type bool       $unverified       Whether the runner's PHP lacks mysqli.
 *     @type array|null $live             The resolvable live configuration, or null.
 *     @type array|null $destructive       The resolvable destructive configuration, or null.
 * }
 * @return array{unit:bool,testbed:bool,live:bool,destructive:bool,reasons:array}
 */
function agentshell_runner_environment( array $args ) {
    $test_bed_cfg   = isset( $args['test_bed_cfg'] ) ? $args['test_bed_cfg'] : null;
    $test_bed_check = isset( $args['test_bed_check'] ) ? $args['test_bed_check'] : null;
    $http_ok        = ! empty( $args['http_ok'] );
    $unverified     = ! empty( $args['unverified'] );
    $live           = isset( $args['live'] ) ? $args['live'] : null;
    $destructive    = isset( $args['destructive'] ) ? $args['destructive'] : null;

    $reasons = array();

    $has_profile = is_array( $test_bed_cfg );
    if ( ! $has_profile ) {
        $reasons['testbed'] = 'no test-bed profile — this suite asserts something about the '
            . 'configured test installation, so it has nothing to assert against without one';
        $reasons['live'] = 'no live WordPress installation could be resolved — there is no '
            . 'test-bed profile and no discoverable installation';
        $reasons['destructive'] = 'no test-bed profile — a destructive integration suite requires '
            . 'a verified test installation (tests/config/test-bed.json)';
    }

    $check_ok = is_array( $test_bed_check ) && ! empty( $test_bed_check['ok'] );
    if ( $has_profile && $unverified ) {
        $reasons['destructive'] = 'the runner\'s PHP has no mysqli, so the fail-closed check cannot '
            . 'run — re-run with the test bed\'s PHP to enable destructive verification';
    } elseif ( $has_profile && ! $check_ok ) {
        $refusal = is_array( $test_bed_check ) && isset( $test_bed_check['refusal'] )
            ? (string) $test_bed_check['refusal']
            : 'unknown';
        $reasons['destructive'] = 'the fail-closed check failed (refusal: ' . $refusal . ')';
    } elseif ( $has_profile && ! $http_ok ) {
        $reasons['destructive'] = 'the HTTP identity handshake failed — the HTTP site is not the '
            . 'same installation as the CLI/DB path the runner verified';
    } elseif ( $has_profile && ! is_array( $destructive ) ) {
        $reasons['destructive'] = 'the test-bed profile does not resolve to a usable WordPress '
            . 'installation and PHP binary';
    }

    if ( ! is_array( $live ) && ! isset( $reasons['live'] ) ) {
        $reasons['live'] = 'no live WordPress installation could be resolved';
    }
    if ( ! is_array( $destructive ) && ! isset( $reasons['destructive'] ) ) {
        $reasons['destructive'] = 'the test-bed profile does not resolve to a usable WordPress '
            . 'installation and PHP binary';
    }

    return array(
        'unit'        => true,
        'testbed'     => $has_profile,
        'live'        => is_array( $live ),
        'destructive' => $has_profile && ! $unverified && $check_ok && $http_ok && is_array( $destructive ),
        'reasons'     => $reasons,
    );
}

/**
 * May this suite run in this environment?
 *
 * @param string $requirement One of unit, testbed, live, destructive.
 * @param array  $env         The environment from agentshell_runner_environment().
 * @return array{eligible:bool,reason:string}
 */
function agentshell_suite_eligibility( $requirement, array $env ) {
    $requirement = (string) $requirement;
    if ( 'unit' === $requirement ) {
        return array( 'eligible' => true, 'reason' => '' );
    }
    if ( ! array_key_exists( $requirement, $env ) || 'reasons' === $requirement ) {
        // An unknown requirement is a registry mistake, and a registry
        // mistake must not be read as "this suite may run".
        return array(
            'eligible' => false,
            'reason'   => 'the registry does not declare what this suite requires ("'
                . $requirement . '"), so it is not run',
        );
    }
    if ( ! empty( $env[ $requirement ] ) ) {
        return array( 'eligible' => true, 'reason' => '' );
    }
    $reasons = isset( $env['reasons'] ) && is_array( $env['reasons'] ) ? $env['reasons'] : array();
    $reason  = isset( $reasons[ $requirement ] )
        ? (string) $reasons[ $requirement ]
        : 'the environment does not provide what this suite requires';
    return array( 'eligible' => false, 'reason' => $reason );
}

/**
 * Run one suite in its own process and read its verdict.
 *
 * @param string $name The suite's registry name.
 * @param string $path The suite file.
 * @return array{status:string,passed:int,failed:int,skipped:bool,reason:string,output:string,exit:int}
 *   `status` is one of: passed, failed, skipped, incomplete.
 */
function agentshell_run_suite_isolated( $name, $path ) {
    $child = __DIR__ . '/run-suite.php';
    $cmd   = escapeshellarg( PHP_BINARY )
        . ' ' . escapeshellarg( $child )
        . ' ' . escapeshellarg( $path )
        . ' ' . escapeshellarg( $name )
        . ' 2>&1';

    $descriptors = array( 1 => array( 'pipe', 'w' ) );
    $pipes       = array();
    $process     = proc_open( $cmd, $descriptors, $pipes );
    if ( ! is_resource( $process ) ) {
        return array(
            'status'  => 'failed',
            'passed'  => 0,
            'failed'  => 1,
            'skipped' => false,
            'reason'  => 'the suite process could not be started',
            'output'  => '',
            'exit'    => -1,
        );
    }

    $output = (string) stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    $exit = proc_close( $process );

    $result = array(
        'status'  => 'incomplete',
        'passed'  => 0,
        'failed'  => 0,
        'skipped' => false,
        'reason'  => '',
        'output'  => $output,
        'exit'    => (int) $exit,
    );

    $sentinel = null;
    if ( preg_match( '/^__AGENTSHELL_SUITE_RESULT__ (.*)$/m', $output, $m ) ) {
        $decoded = json_decode( trim( $m[1] ), true );
        if ( is_array( $decoded ) ) {
            $sentinel = $decoded;
        }
        // The sentinel is the runner's own protocol, not the suite's
        // output: it is read here and not printed back at the reader.
        $result['output'] = str_replace( $m[0] . "\n", '', $result['output'] );
        $result['output'] = str_replace( $m[0], '', $result['output'] );
    }

    if ( null === $sentinel ) {
        // No verdict at all. The suite died before it could report one —
        // a fatal error, a killed child, or a suite that printed nothing
        // at all. That is not a pass.
        $result['status'] = ( 0 === $result['exit'] ) ? 'incomplete' : 'failed';
        $result['failed'] = 1;
        $result['reason'] = ( 0 === $result['exit'] )
            ? 'the suite stopped without reporting a result'
            : 'the suite exited with status ' . $result['exit'] . ' without reporting a result';
        return $result;
    }

    $result['passed']  = (int) ( $sentinel['passed'] ?? 0 );
    $result['failed']  = (int) ( $sentinel['failed'] ?? 0 );
    $result['skipped'] = ! empty( $sentinel['skipped'] );
    $result['reason']  = (string) ( $sentinel['reason'] ?? '' );

    if ( ! empty( $sentinel['completed'] ) ) {
        if ( $result['skipped'] ) {
            $result['status'] = 'skipped';
        } elseif ( $result['failed'] > 0 ) {
            $result['status'] = 'failed';
        } else {
            $result['status'] = 'passed';
        }
    } else {
        // The suite did not run to the end of its file. It may have
        // declared a skip on the way out — that is a skip. Otherwise it
        // stopped for some reason it did not explain, which is a failure
        // of the run: a suite that vanishes is not a suite that passed.
        $result['status'] = $result['skipped'] ? 'skipped' : 'incomplete';
        if ( ! $result['skipped'] ) {
            $result['failed'] = max( 1, $result['failed'] );
            $result['reason'] = 'the suite stopped before the end of its file without reporting a '
                . 'result — it may have exited, or died';
        }
    }

    return $result;
}

/**
 * Run a list of suites, in order, and aggregate their verdicts.
 *
 * @param array  $suites name => array( 'path' => string, 'requires' => string )
 * @param array  $env    The environment from agentshell_runner_environment().
 * @param bool   $echo   Whether to print the suites' output.
 * @return array{passed:int,failed:int,skipped:array,incomplete:array,failed_suites:array,results:array}
 */
function agentshell_dispatch_suites( array $suites, array $env, $echo = true ) {
    $passed   = 0;
    $failed   = 0;
    $skipped  = array();
    $incomplete = array();
    $failed_suites = array();
    $results = array();

    foreach ( $suites as $name => $spec ) {
        $path       = isset( $spec['path'] ) ? $spec['path'] : '';
        $requirement = isset( $spec['requires'] ) ? $spec['requires'] : 'unit';

        if ( $echo ) {
            echo "\n=== $name ===\n";
        }

        $eligibility = agentshell_suite_eligibility( $requirement, $env );
        if ( ! $eligibility['eligible'] ) {
            if ( $echo ) {
                echo "  SKIPPED: " . $eligibility['reason'] . "\n";
            }
            $skipped[ $name ] = $eligibility['reason'];
            $results[ $name ] = array(
                'status'  => 'skipped',
                'passed'  => 0,
                'failed'  => 0,
                'skipped' => true,
                'reason'  => $eligibility['reason'],
            );
            continue;
        }

        if ( ! is_file( $path ) ) {
            if ( $echo ) {
                echo "  FAIL the suite file is missing: $path\n";
            }
            $failed_suites[] = $name;
            $failed++;
            $results[ $name ] = array(
                'status'  => 'failed',
                'passed'  => 0,
                'failed'  => 1,
                'skipped' => false,
                'reason'  => 'the suite file is missing: ' . $path,
            );
            continue;
        }

        $result = agentshell_run_suite_isolated( $name, $path );

        if ( $echo && '' !== $result['output'] ) {
            echo rtrim( $result['output'], "\n" ) . "\n";
        }
        if ( $echo && 'incomplete' === $result['status'] ) {
            echo "  FAIL " . $result['reason'] . "\n";
        }

        $passed += $result['passed'];
        $failed += $result['failed'];

        if ( 'skipped' === $result['status'] ) {
            $skipped[ $name ] = '' !== $result['reason'] ? $result['reason'] : 'the suite declared that it could not run';
            if ( $echo ) {
                echo "\n" . $result['passed'] . " passed, " . $result['failed'] . " failed, skipped\n";
            }
        } elseif ( 'incomplete' === $result['status'] ) {
            $incomplete[ $name ] = $result['reason'];
            $failed_suites[] = $name;
        } elseif ( 'failed' === $result['status'] ) {
            $failed_suites[] = $name;
        }

        $results[ $name ] = array(
            'status'  => $result['status'],
            'passed'  => $result['passed'],
            'failed'  => $result['failed'],
            'skipped' => $result['skipped'],
            'reason'  => $result['reason'],
        );
    }

    return array(
        'passed'        => $passed,
        'failed'        => $failed,
        'skipped'       => $skipped,
        'incomplete'    => $incomplete,
        'failed_suites' => $failed_suites,
        'results'       => $results,
    );
}
