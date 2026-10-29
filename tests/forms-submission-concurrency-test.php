<?php
/**
 * The submission pipeline's concurrency claims, in separate processes.
 *
 * Three races, each one a claim the design makes and none of which a
 * single-process test can honestly demonstrate:
 *
 *   A. **A double-click, a retry or a lost response cannot create two
 *      submissions.** Four processes present the *same* challenge token at the
 *      same instant. Exactly one submission exists afterwards, and every
 *      contender is answered as success — because the losers are replays of the
 *      winner's success, not refusals.
 *   B. **The per-form quota is never exceeded.** Six processes each present
 *      their own valid token against a form limited to three. Exactly three
 *      are admitted and three are refused, and exactly three submissions exist.
 *   C. **Two mail workers cannot both send one notification.** Two processes
 *      attempt the same record at the same instant, with a transport slow
 *      enough to overlap. Exactly one message is handed over and exactly one
 *      worker owns the record.
 *
 * Every contender is its own operating-system process with its own MySQL
 * connection, released together by a file barrier.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms submission concurrency (separate processes, real MySQL)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms submission concurrency suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$child  = __DIR__ . '/forms-submission-concurrency-child.php';
$tmp    = rtrim( sys_get_temp_dir(), '/' );
$run_id = getmypid() . '-' . substr( md5( (string) microtime( true ) ), 0, 6 );

/**
 * Build the shell command for one child, with the test bed's PHP, ini and env.
 *
 * @param array  $config Test-bed config.
 * @param array  $argv   Fixed arguments followed by the mode's own.
 * @return string
 */
function fsx_command( array $config, array $argv ) {
    global $child;

    $parts = array();

    foreach ( $config['env'] as $k => $v ) {
        $parts[] = $k . '=' . escapeshellarg( (string) $v );
    }

    $parts[] = 'AGENTSHELL_TEST_WP_ROOT=' . escapeshellarg( (string) $config['wp_root'] );

    if ( is_array( $config['cfg'] ) && isset( $config['cfg']['_config_path'] ) ) {
        $parts[] = 'AGENTSHELL_TEST_BED_CONFIG=' . escapeshellarg( (string) $config['cfg']['_config_path'] );
    }

    $parts[] = escapeshellarg( (string) $config['php'] );

    foreach ( $config['ini'] as $arg ) {
        $parts[] = '-d ' . escapeshellarg( (string) $arg );
    }

    $parts[] = escapeshellarg( $child );

    foreach ( $argv as $arg ) {
        $parts[] = escapeshellarg( (string) $arg );
    }

    return implode( ' ', $parts );
}

/**
 * Run one child to completion.
 *
 * @param array  $config Test-bed config.
 * @param string $mode   Mode.
 * @param array  $args   Mode arguments.
 * @return array
 */
function fsx_run_one( array $config, $mode, array $args ) {
    $base = rtrim( sys_get_temp_dir(), '/' ) . '/as-fsx-one-' . getmypid() . '-' . mt_rand( 1000, 999999 );

    @unlink( $base . '.report' );
    file_put_contents( $base . '.go', '1' );

    $argv = array( (string) $config['wp_root'], $mode, $base . '.report', $base . '.ready', $base . '.go' );

    foreach ( $args as $arg ) {
        $argv[] = (string) $arg;
    }

    exec( fsx_command( $config, $argv ) . ' 2>&1', $out, $status );

    $decoded = json_decode( (string) @file_get_contents( $base . '.report' ), true );

    @unlink( $base . '.report' );
    @unlink( $base . '.ready' );
    @unlink( $base . '.go' );

    if ( ! is_array( $decoded ) ) {
        return array( 'error' => 'no_report', 'output' => implode( "\n", $out ), 'status' => $status );
    }

    return $decoded;
}

/**
 * Start one contender, waiting at the barrier.
 *
 * @param array  $config Test-bed config.
 * @param string $label  Race label.
 * @param int    $index  Contender index.
 * @param string $mode   Mode.
 * @param array  $args   Mode arguments.
 * @return array
 */
function fsx_spawn( array $config, $label, $index, $mode, array $args ) {
    global $tmp, $run_id;

    $base = $tmp . '/as-fsx-' . $run_id . '-' . $label . '-' . $index;

    @unlink( $base . '.report' );
    @unlink( $base . '.ready' );
    @unlink( $base . '.go' );

    $argv = array( (string) $config['wp_root'], $mode, $base . '.report', $base . '.ready', $base . '.go' );

    foreach ( $args as $arg ) {
        $argv[] = (string) $arg;
    }

    $desc = array(
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
    );

    $proc = proc_open( fsx_command( $config, $argv ), $desc, $pipes );

    return array(
        'index'  => (int) $index,
        'label'  => (string) $label,
        'base'   => $base,
        'proc'   => $proc,
        'stdout' => $pipes[1] ?? null,
        'stderr' => $pipes[2] ?? null,
    );
}

/**
 * Open one contender's barrier, without waiting for it.
 *
 * @param array $handle The handle.
 * @return void
 */
function fsx_open( array $handle ) {
    file_put_contents( $handle['base'] . '.go', '1' );
}

/**
 * Open the barrier and collect every report.
 *
 * @param array $handles Handles.
 * @param int   $timeout Seconds to wait for readiness.
 * @return array<int,array>
 */
function fsx_release( array $handles, $timeout = 180 ) {
    $deadline = microtime( true ) + (int) $timeout;

    while ( microtime( true ) < $deadline ) {
        $all = true;

        foreach ( $handles as $handle ) {
            clearstatcache( true, $handle['base'] . '.ready' );

            if ( ! is_file( $handle['base'] . '.ready' ) ) {
                $all = false;
                break;
            }
        }

        if ( $all ) {
            break;
        }

        usleep( 5000 );
    }

    $barrier = true;

    foreach ( $handles as $handle ) {
        clearstatcache( true, $handle['base'] . '.ready' );

        if ( ! is_file( $handle['base'] . '.ready' ) ) {
            $barrier = false;
        }
    }

    if ( $barrier ) {
        foreach ( $handles as $handle ) {
            file_put_contents( $handle['base'] . '.go', '1' );
        }
    }

    $results = array();

    foreach ( $handles as $handle ) {
        $stdout = is_resource( $handle['stdout'] ) ? (string) stream_get_contents( $handle['stdout'] ) : '';
        $stderr = is_resource( $handle['stderr'] ) ? (string) stream_get_contents( $handle['stderr'] ) : '';

        if ( is_resource( $handle['stdout'] ) ) {
            fclose( $handle['stdout'] );
        }

        if ( is_resource( $handle['stderr'] ) ) {
            fclose( $handle['stderr'] );
        }

        $deadline = microtime( true ) + 120;

        while ( microtime( true ) < $deadline ) {
            $status = @proc_get_status( $handle['proc'] );

            if ( is_array( $status ) && empty( $status['running'] ) ) {
                break;
            }

            usleep( 5000 );
        }

        @proc_close( $handle['proc'] );

        $decoded = json_decode( (string) @file_get_contents( $handle['base'] . '.report' ), true );

        @unlink( $handle['base'] . '.report' );
        @unlink( $handle['base'] . '.ready' );
        @unlink( $handle['base'] . '.go' );

        $results[] = array(
            'index'   => (int) $handle['index'],
            'label'   => (string) $handle['label'],
            'barrier' => $barrier,
            'report'  => is_array( $decoded ) ? $decoded : null,
            'stdout'  => $stdout,
            'stderr'  => $stderr,
        );
    }

    return $results;
}

/**
 * A readable summary of a race, for failure messages.
 *
 * @param array $results Results.
 * @return string
 */
function fsx_summary( array $results ) {
    $lines = array();

    foreach ( $results as $result ) {
        $lines[] = '  #' . $result['index'] . ' ' . wp_json_encode( $result['report'] )
            . ( '' !== trim( (string) $result['stderr'] ) ? ' stderr=' . trim( (string) $result['stderr'] ) : '' );
    }

    return implode( "\n", $lines );
}

/**
 * The reports that were produced.
 *
 * @param array $results Results.
 * @return array<int,array>
 */
function fsx_reports( array $results ) {
    $out = array();

    foreach ( $results as $result ) {
        if ( is_array( $result['report'] ) ) {
            $out[] = $result['report'];
        }
    }

    return $out;
}

// ══ preparation ══════════════════════════════════════════════════════════════

$token = 'ASfx' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$prep  = fsx_run_one( $config, 'prepare', array( $token ) );

if ( isset( $prep['error'] ) || empty( $prep['same']['form_id'] ) ) {
    Agentshell_Test::run(
        'forms submission concurrency: fixtures were prepared',
        function () use ( $prep ) {
            Agentshell_Test::assert_true( false, 'preparation failed: ' . wp_json_encode( $prep ) );
        }
    );
    return;
}

$same_form    = (string) $prep['same']['form_id'];
$same_version = (int) $prep['same']['version'];
$same_token   = (string) $prep['duplicate_token'];

$quota_form    = (string) $prep['limited']['form_id'];
$quota_version = (int) $prep['limited']['version'];
$quota_tokens  = (array) $prep['quota_tokens'];

$notify_form  = (string) $prep['notify']['form_id'];
$notify_entry = (int) $prep['notify_entry'];
$notify_two   = (int) ( $prep['notify_entry_two'] ?? 0 );

$mail_log = $tmp . '/as-fsx-mail-' . $run_id . '.log';
@unlink( $mail_log );
touch( $mail_log );

$cleanup_ids = array( $same_form, $quota_form, $notify_form );

// ══ A — one token, four contenders ═══════════════════════════════════════════

$handles = array();

for ( $i = 0; $i < 4; $i++ ) {
    $handles[] = fsx_spawn( $config, 'same', $i, 'submit_same', array( $same_form, $same_version, $same_token ) );
}

$race_a = fsx_release( $handles );

// ══ B — six tokens, one quota of three ══════════════════════════════════════

$handles = array();

for ( $i = 0; $i < 6; $i++ ) {
    $handles[] = fsx_spawn(
        $config,
        'quota',
        $i,
        'submit_quota',
        array( $quota_form, $quota_version, (string) $quota_tokens[ $i ], $i )
    );
}

$race_b = fsx_release( $handles );

// ══ C — two workers, one notification ═══════════════════════════════════════

$handles = array();

for ( $i = 0; $i < 2; $i++ ) {
    $handles[] = fsx_spawn( $config, 'notify', $i, 'notify', array( $notify_entry, $mail_log ) );
}

$race_c = fsx_release( $handles );

$mail_lines = is_file( $mail_log ) ? file( $mail_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();

// ══ D — an older worker must not overwrite a newer worker's settlement ═════
//
// Worker A claims the notification and is slow in the transport. Its claim
// expires, worker B takes the record over, sends and settles it. A then
// finishes and tries to settle a record it no longer owns.
//
// A's settlement must not land. With a settlement that re-read the row and
// used *that* as its expectation, A's write would be guarded on B's bytes,
// would succeed, and would replace B's settlement with A's.
//
// The two workers are separate processes: the claim, the takeover and the
// late settlement all happen in different operating-system processes with
// their own MySQL connections.

$handles = array();
$handles[] = fsx_spawn( $config, 'slow', 0, 'notify_slow', array( $notify_two, $mail_log, '15000' ) );

// A starts now: it must claim the record and then be slow inside the
// transport, so that its claim can expire while it is still working.
fsx_open( $handles[0] );

// Wait until A really holds the claim, then let it expire.
$deadline = microtime( true ) + 60;
$claimed  = false;

while ( microtime( true ) < $deadline ) {
    $state = fsx_run_one( $config, 'verify_notification', array( $notify_two ) );

    if ( isset( $state['notification']['status'] ) && 'claimed' === (string) $state['notification']['status'] ) {
        $claimed = true;
        break;
    }

    usleep( 100000 );
}

// The claim lives for two seconds (the child's filter), so two and a half
// seconds later it is expired and B may take it over.
sleep( 3 );

$handles_b = array();
$handles_b[] = fsx_spawn( $config, 'fast', 0, 'notify', array( $notify_two, $mail_log ) );

$race_d = fsx_release( $handles_b );

// A is still inside its transport; wait for it to finish and settle.
$race_d_slow = fsx_release( $handles );

$final = fsx_run_one( $config, 'verify_notification', array( $notify_two ) );

// ══ verification ═════════════════════════════════════════════════════════════

$verify = fsx_run_one( $config, 'verify', $cleanup_ids );
$counts = isset( $verify['counts'] ) ? (array) $verify['counts'] : array();

$cleanup = fsx_run_one( $config, 'cleanup', $cleanup_ids );

$after       = fsx_run_one( $config, 'verify', $cleanup_ids );
$after_counts = isset( $after['counts'] ) ? (array) $after['counts'] : array();

@unlink( $mail_log );

// ══ assertions ═══════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'A: four contenders presenting one challenge create exactly one submission',
    function () use ( $race_a, $counts, $same_form ) {
        $reports = fsx_reports( $race_a );

        Agentshell_Test::assert_equals( 4, count( $reports ), 'all four contenders must report: ' . fsx_summary( $race_a ) );
        Agentshell_Test::assert_equals(
            1,
            (int) ( $counts[ $same_form ] ?? -1 ),
            'one challenge may produce one submission, however many requests carry it: ' . fsx_summary( $race_a )
        );
    }
);

Agentshell_Test::run(
    'A: a duplicate is answered as success or told to retry — never refused outright',
    function () use ( $race_a ) {
        $successes = 0;

        foreach ( fsx_reports( $race_a ) as $report ) {
            $status = (int) $report['status'];
            $code   = (string) ( $report['code'] ?? '' );

            // Two honest answers exist. A duplicate that arrives after the
            // winner has linked its challenge **replays the success**. One that
            // arrives while the winner is still writing cannot know the outcome
            // yet, and is told to wait (`form_pending`) — the browser then
            // retries the same token and gets the replay.
            Agentshell_Test::assert_true(
                in_array( $status, array( 200, 409 ), true ),
                "a duplicate must not be refused as invalid or rejected (status {$status}): " . fsx_summary( $race_a )
            );

            if ( 200 === $status ) {
                $successes++;
            } else {
                Agentshell_Test::assert_equals(
                    'form_pending',
                    $code,
                    'the only refusal a duplicate may receive is the retryable one: ' . fsx_summary( $race_a )
                );
            }
        }

        Agentshell_Test::assert_true( $successes >= 1, 'at least one contender is answered as success: ' . fsx_summary( $race_a ) );
    }
);

Agentshell_Test::run(
    'B: six contenders against a quota of three admit exactly three',
    function () use ( $race_b ) {
        $reports = fsx_reports( $race_b );

        Agentshell_Test::assert_equals( 6, count( $reports ), 'all six contenders must report: ' . fsx_summary( $race_b ) );

        $admitted = array_filter( $reports, function ( $report ) { return 200 === (int) $report['status']; } );
        $refused  = array_filter( $reports, function ( $report ) { return 429 === (int) $report['status']; } );

        Agentshell_Test::assert_equals( 3, count( $admitted ), 'the quota may admit exactly three: ' . fsx_summary( $race_b ) );
        Agentshell_Test::assert_equals( 3, count( $refused ), 'the rest must be refused with 429: ' . fsx_summary( $race_b ) );
    }
);

Agentshell_Test::run(
    'B: a refused submission leaves no row behind',
    function () use ( $counts, $quota_form ) {
        Agentshell_Test::assert_equals(
            3,
            (int) ( $counts[ $quota_form ] ?? -1 ),
            'the stored submissions must match the admissions exactly'
        );
    }
);

Agentshell_Test::run(
    'C: two workers attempting one notification hand over exactly one message',
    function () use ( $race_c, $mail_lines ) {
        $reports = fsx_reports( $race_c );

        Agentshell_Test::assert_equals( 2, count( $reports ), 'both workers must report: ' . fsx_summary( $race_c ) );
        Agentshell_Test::assert_equals( 1, count( $mail_lines ), 'exactly one message may be handed to the transport: ' . fsx_summary( $race_c ) );
    }
);

Agentshell_Test::run(
    'C: exactly one worker owns the record, and it settles it',
    function () use ( $race_c ) {
        $reports = fsx_reports( $race_c );

        $accepted = array_filter( $reports, function ( $report ) { return 'accepted' === (string) ( $report['outcome'] ?? '' ); } );
        $busy     = array_filter( $reports, function ( $report ) { return 'busy' === (string) ( $report['outcome'] ?? '' ); } );

        Agentshell_Test::assert_equals( 1, count( $accepted ), 'one worker sends: ' . fsx_summary( $race_c ) );
        Agentshell_Test::assert_equals( 1, count( $busy ), 'the other finds the record claimed: ' . fsx_summary( $race_c ) );

        $settled = array_values( $accepted );

        Agentshell_Test::assert_equals( 'accepted', (string) $settled[0]['entry']['status'], 'the record is settled as accepted' );
        Agentshell_Test::assert_equals( 1, (int) $settled[0]['entry']['attempts'], 'one attempt is counted, not two' );
    }
);

Agentshell_Test::run(
    'D: the older worker does not overwrite the newer worker\'s settlement',
    function () use ( $race_d, $race_d_slow, $final ) {
        $slow = fsx_reports( $race_d_slow );
        $fast = fsx_reports( $race_d );



        Agentshell_Test::assert_equals( 1, count( $slow ), 'the slow worker must report: ' . fsx_summary( $race_d_slow ) );
        Agentshell_Test::assert_equals( 1, count( $fast ), 'the taking worker must report: ' . fsx_summary( $race_d ) );

        $fast_report = $fast[0];

        Agentshell_Test::assert_equals(
            'accepted',
            (string) ( $fast_report['outcome'] ?? '' ),
            'the worker that took the record over sends and settles it: ' . fsx_summary( $race_d )
        );

        $slow_report = $slow[0];

        Agentshell_Test::assert_equals(
            'contended',
            (string) ( $slow_report['outcome'] ?? '' ),
            'the older worker finds the record it claimed has moved on, and its write does not land: ' . fsx_summary( $race_d_slow )
        );

        $record = (array) ( $final['notification'] ?? array() );

        Agentshell_Test::assert_equals(
            'accepted',
            (string) ( $record['status'] ?? '' ),
            'the record still holds the newer worker\'s settlement'
        );
        Agentshell_Test::assert_equals(
            2,
            (int) ( $record['attempts'] ?? 0 ),
            'the attempts counted are the newer worker\'s: the older worker\'s settlement did not replace them'
        );
        Agentshell_Test::assert_equals(
            (string) ( $fast_report['entry']['message_id'] ?? '' ),
            (string) ( $record['message_id'] ?? '' ),
            'the message id is the newer worker\'s'
        );
    }
);

Agentshell_Test::run(
    'the suite left nothing behind',
    function () use ( $cleanup, $after_counts ) {
        Agentshell_Test::assert_true( ! empty( $cleanup['ok'] ), 'the cleanup step must succeed: ' . wp_json_encode( $cleanup ) );

        foreach ( $after_counts as $form_id => $count ) {
            Agentshell_Test::assert_equals( 0, (int) $count, "the form {$form_id} must hold no submission after cleanup" );
        }
    }
);

Agentshell_Test::summary();
