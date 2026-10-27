<?php
/**
 * The forms concurrency acceptance suite: separate processes, real MySQL.
 *
 * Six races, and each one is a claim the design makes about what the storage
 * layer will *not* do:
 *
 *   A. two creators cannot establish conflicting authoritative records for
 *      the same slug;
 *   B. two writers cannot create two authoritative rows for one meta key;
 *   C. two editors cannot silently overwrite one another;
 *   D. two publishers cannot both succeed against the same stale revision;
 *   E. a challenge can be consumed at most once;
 *   F. rate-limit admissions never exceed the configured bound.
 *
 * Concurrency has to be real to be worth testing. Every contender here is a
 * separate operating-system process with its own MySQL connection, and all of
 * them are released at the same instant by a file barrier — so the operations
 * genuinely overlap in the server, rather than being simulated inside one
 * process where `$wpdb` is a single connection and `GET_LOCK` is re-entrant.
 *
 * The single-process invariants these races rest on (the `meta_id` guard, the
 * duplicate detection, the publication transaction) are
 * `tests/forms-storage-test.php`.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms concurrency (separate processes, real MySQL)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'forms concurrency suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$child  = __DIR__ . '/forms-concurrency-child.php';
$tmp    = rtrim( sys_get_temp_dir(), '/' );
$run_id = getmypid() . '-' . substr( md5( (string) microtime( true ) ), 0, 6 );

/**
 * Build the shell command for one child, with the test bed's PHP, ini and env.
 *
 * @param array  $config Test-bed config.
 * @param string $script Child script.
 * @param array  $argv   Arguments after the five fixed ones.
 * @return string
 */
function fc_command( array $config, $script, array $argv ) {
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
    $parts[] = escapeshellarg( $script );
    foreach ( $argv as $arg ) {
        $parts[] = escapeshellarg( (string) $arg );
    }
    return implode( ' ', $parts );
}

/**
 * Run one child to completion, sequentially.
 *
 * @param array  $config Test-bed config.
 * @param string $script Child script.
 * @param array  $argv   Arguments.
 * @return array The child's report, or an array with `error`.
 */
function fc_run_one( array $config, $script, array $argv ) {
    $report = rtrim( sys_get_temp_dir(), '/' ) . '/as-fcx-one-' . getmypid() . '-' . mt_rand( 1000, 999999 ) . '.json';
    @unlink( $report );

    $mode = (string) array_shift( $argv );

    // The child's argument layout is fixed: wp_root, mode, report, ready, go,
    // then the mode's own arguments. A sequential run has no barrier to wait
    // for, so its gate is opened before the process starts — a mode that does
    // call `wait_for_go()` then proceeds immediately rather than timing out.
    $go = $report . '.go';
    file_put_contents( $go, '1' );

    $full = array( (string) $config['wp_root'], $mode, $report, $report . '.ready', $go );
    foreach ( $argv as $arg ) {
        $full[] = (string) $arg;
    }

    $cmd = fc_command( $config, $script, $full ) . ' 2>&1';
    exec( $cmd, $out, $status );

    $decoded = json_decode( (string) @file_get_contents( $report ), true );
    @unlink( $report );
    @unlink( $go );
    @unlink( $report . '.ready' );

    if ( ! is_array( $decoded ) ) {
        return array( 'error' => 'no_report', 'output' => implode( "\n", $out ), 'status' => $status );
    }
    return $decoded;
}

/**
 * Start one contender and leave it waiting at the barrier.
 *
 * @param array  $config Test-bed config.
 * @param string $script Child script.
 * @param string $label  Race label, for unique paths.
 * @param int    $index  Contender index.
 * @param string $mode   Child mode.
 * @param array  $args   Mode arguments.
 * @return array Handle.
 */
function fc_spawn( array $config, $script, $label, $index, $mode, array $args ) {
    global $tmp, $run_id;

    $base   = $tmp . '/as-fcx-' . $run_id . '-' . $label . '-' . $index;
    $report = $base . '.report.json';
    $ready  = $base . '.ready';
    $go     = $base . '.go';

    @unlink( $report );
    @unlink( $ready );
    @unlink( $go );

    $argv = array( (string) $config['wp_root'], $mode, $report, $ready, $go );
    foreach ( $args as $arg ) {
        $argv[] = (string) $arg;
    }

    $cmd  = fc_command( $config, $script, $argv );
    $desc = array(
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
    );
    $proc   = proc_open( $cmd, $desc, $pipes );
    $handle = array(
        'label'  => $label,
        'index'  => $index,
        'report' => $report,
        'ready'  => $ready,
        'go'     => $go,
        'proc'   => $proc,
        'stdout' => isset( $pipes[1] ) ? $pipes[1] : null,
        'stderr' => isset( $pipes[2] ) ? $pipes[2] : null,
    );

    return $handle;
}

/**
 * Wait until every contender has announced readiness.
 *
 * @param array $handles Handles.
 * @param int   $timeout Seconds.
 * @return bool
 */
function fc_wait_ready( array $handles, $timeout = 180 ) {
    $deadline = microtime( true ) + (int) $timeout;

    while ( microtime( true ) < $deadline ) {
        $all = true;
        foreach ( $handles as $h ) {
            clearstatcache( true, $h['ready'] );
            if ( ! is_file( $h['ready'] ) ) {
                $all = false;
                break;
            }
        }
        if ( $all ) {
            return true;
        }

        // A contender that died before announcing readiness will never do so.
        foreach ( $handles as $h ) {
            $status = @proc_get_status( $h['proc'] );
            if ( is_array( $status ) && empty( $status['running'] ) ) {
                clearstatcache( true, $h['ready'] );
                if ( ! is_file( $h['ready'] ) ) {
                    return false;
                }
            }
        }

        usleep( 5000 );
    }

    return false;
}

/**
 * Open the barrier for every contender and collect their reports.
 *
 * @param array $handles Handles from `fc_spawn()`.
 * @return array<int,array{report:?array,stdout:string,stderr:string,index:int,label:string,barrier:bool}>
 */
function fc_release( array $handles ) {
    $barrier = fc_wait_ready( $handles );

    if ( $barrier ) {
        foreach ( $handles as $h ) {
            file_put_contents( $h['go'], '1' );
        }
    }

    $results = array();
    foreach ( $handles as $h ) {
        $stdout = is_resource( $h['stdout'] ) ? (string) stream_get_contents( $h['stdout'] ) : '';
        $stderr = is_resource( $h['stderr'] ) ? (string) stream_get_contents( $h['stderr'] ) : '';
        if ( is_resource( $h['stdout'] ) ) { fclose( $h['stdout'] ); }
        if ( is_resource( $h['stderr'] ) ) { fclose( $h['stderr'] ); }

        $deadline = microtime( true ) + 120;
        while ( microtime( true ) < $deadline ) {
            $status = @proc_get_status( $h['proc'] );
            if ( is_array( $status ) && empty( $status['running'] ) ) {
                break;
            }
            usleep( 5000 );
        }
        @proc_close( $h['proc'] );

        $decoded = json_decode( (string) @file_get_contents( $h['report'] ), true );

        $results[] = array(
            'index'   => (int) $h['index'],
            'label'   => (string) $h['label'],
            'barrier' => $barrier,
            'report'  => is_array( $decoded ) ? $decoded : null,
            'stdout'  => $stdout,
            'stderr'  => $stderr,
        );
    }

    return $results;
}

/**
 * The reports that were produced at all.
 *
 * @param array $results Results.
 * @return array<int,array>
 */
function fc_reports( array $results ) {
    $out = array();
    foreach ( $results as $r ) {
        if ( is_array( $r['report'] ) ) {
            $out[] = $r['report'];
        }
    }
    return $out;
}

/**
 * A human-readable summary of a race, for a failure message.
 *
 * @param array $results Results.
 * @return string
 */
function fc_summary( array $results ) {
    $lines = array();
    foreach ( $results as $r ) {
        $lines[] = '  #' . $r['index'] . ' ' . wp_json_encode( $r['report'] ) . ( '' !== trim( (string) $r['stderr'] ) ? ' stderr=' . trim( (string) $r['stderr'] ) : '' );
    }
    return implode( "\n", $lines );
}

/* ---------------------------------------------------------------------------
 * Preparation
 * ------------------------------------------------------------------------ */

$token = 'AScx' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$prep  = fc_run_one( $config, $child, array( 'prepare', $token ) );

if ( isset( $prep['error'] ) ) {
    Agentshell_Test::run(
        'forms concurrency: fixtures were prepared',
        function () use ( $prep ) {
            Agentshell_Test::assert_true( false, 'preparation failed: ' . wp_json_encode( $prep ) );
        }
    );
    return;
}

$update_form   = (string) $prep['update']['form_id'];
$publish_form  = (string) $prep['publish']['form_id'];
$bare_post     = (int) $prep['bare']['post_id'];
$challenge     = (string) $prep['challenge']['token'];
$publish_fid   = $publish_form;
$race_slug     = 'cx-' . strtolower( $token ) . '-same';

$GLOBALS['race_token'] = $token;

/* ---------------------------------------------------------------------------
 * A — two creators, one slug
 * ------------------------------------------------------------------------ */

$handles = array();
for ( $i = 0; $i < 2; $i++ ) {
    $handles[] = fc_spawn( $config, $child, 'slug', $i, 'create_same_slug', array( $race_slug, $token ) );
}
$race_a = fc_release( $handles );
$slug_verify = fc_run_one( $config, $child, array( 'verify_slug', $race_slug ) );

Agentshell_Test::run(
    'forms concurrency A: two creators cannot both claim one slug',
    function () use ( $race_a, $slug_verify ) {
        $reports = fc_reports( $race_a );
        Agentshell_Test::assert_equals( 2, count( $reports ), 'both contenders must have run: ' . fc_summary( $race_a ) );

        $created = array_filter( $reports, function ( $r ) { return ! empty( $r['created'] ); } );
        $refused = array_filter( $reports, function ( $r ) { return empty( $r['created'] ); } );

        Agentshell_Test::assert_equals( 1, count( $created ), 'exactly one creator may establish the form: ' . fc_summary( $race_a ) );
        Agentshell_Test::assert_equals( 1, count( $refused ), 'the other creator must be refused' );

        $loser = array_values( $refused )[0];
        Agentshell_Test::assert_equals(
            'form_slug_duplicate',
            $loser['code'] ?? null,
            'the loser must be refused by name, not by a generic failure: ' . fc_summary( $race_a )
        );

        $winner = array_values( $created )[0];
        Agentshell_Test::assert_equals(
            array( (int) $winner['post_id'] ),
            array_map( 'intval', (array) ( $winner['posts_with_slug'] ?? array() ) ),
            'exactly one post may carry the slug'
        );

        // The winner is not merely the only one with the slug: its own
        // definition rows must still be singular.
        $counts = isset( $winner['row_counts'] ) && is_array( $winner['row_counts'] ) ? $winner['row_counts'] : array();
        foreach ( $counts as $key => $count ) {
            Agentshell_Test::assert_true( (int) $count <= 1, 'the winner must not carry a duplicate row for ' . $key );
        }
        Agentshell_Test::assert_equals(
            1,
            (int) ( $counts['_agentshell_form_working'] ?? -1 ),
            'the winner must have exactly one authoritative working row'
        );

        // And a later reader sees the same single record.
        Agentshell_Test::assert_equals(
            1,
            count( (array) ( $slug_verify['posts_with_slug'] ?? array() ) ),
            'after the race exactly one form may answer for the slug'
        );
    }
);

/* ---------------------------------------------------------------------------
 * B — two writers, one authoritative row
 * ------------------------------------------------------------------------ */

$value_a = 'value-A-' . $token;
$value_b = 'value-B-' . $token;

$handles = array();
$handles[] = fc_spawn( $config, $child, 'row', 0, 'ensure_row', array( $bare_post, $value_a ) );
$handles[] = fc_spawn( $config, $child, 'row', 1, 'ensure_row', array( $bare_post, $value_b ) );
$race_b = fc_release( $handles );

Agentshell_Test::run(
    'forms concurrency B: two writers cannot create two authoritative rows',
    function () use ( $race_b, $value_a, $value_b ) {
        $reports = fc_reports( $race_b );
        Agentshell_Test::assert_equals( 2, count( $reports ), 'both writers must have run: ' . fc_summary( $race_b ) );

        foreach ( $reports as $r ) {
            Agentshell_Test::assert_false( ! empty( $r['is_error'] ), 'neither writer may fail on a create-or-use operation: ' . ( $r['code'] ?? '' ) );
        }

        $counts = array_map( function ( $r ) { return (int) ( $r['row_count'] ?? -1 ); }, $reports );
        Agentshell_Test::assert_equals( array( 1, 1 ), $counts, 'each writer must observe exactly one authoritative row afterwards' );

        $meta_ids = array();
        foreach ( $reports as $r ) {
            foreach ( (array) ( $r['meta_ids'] ?? array() ) as $id ) {
                $meta_ids[ (int) $id ] = true;
            }
        }
        Agentshell_Test::assert_equals( 1, count( $meta_ids ), 'both writers must be addressing the same single row' );

        // The row holds exactly one of the two submitted values. What must not
        // happen is a mixture, or the loser's value overwriting the winner's.
        $stored = (array) ( $reports[0]['stored_values'] ?? array() );
        Agentshell_Test::assert_equals( 1, count( $stored ), 'exactly one value may be stored' );
        $value = (string) ( $stored[0] ?? '' );
        Agentshell_Test::assert_true(
            $value === $value_a || $value === $value_b,
            'the stored value must be one of the two submitted values, not a mixture'
        );

        // At least one writer really did see the key absent, which is the
        // check-then-insert race the advisory lock exists to close. If none
        // saw it absent the race was not actually reproduced.
        $saw_absent = array_filter( $reports, function ( $r ) { return ! empty( $r['saw_absent'] ); } );
        Agentshell_Test::assert_equals( 2, count( $saw_absent ), 'both writers must have seen the key absent before writing: ' . fc_summary( $race_b ) );
    }
);

/* ---------------------------------------------------------------------------
 * C — two editors, one revision
 * ------------------------------------------------------------------------ */

$marker_one = 'edition-one-' . $token;
$marker_two = 'edition-two-' . $token;

$handles = array();
$handles[] = fc_spawn( $config, $child, 'edit', 0, 'update', array( $update_form, 0, $marker_one ) );
$handles[] = fc_spawn( $config, $child, 'edit', 1, 'update', array( $update_form, 0, $marker_two ) );
$race_c = fc_release( $handles );
$edit_verify = fc_run_one( $config, $child, array( 'verify_update', $update_form ) );

Agentshell_Test::run(
    'forms concurrency C: two editors cannot silently overwrite each other',
    function () use ( $race_c, $edit_verify, $marker_one, $marker_two ) {
        $reports = fc_reports( $race_c );
        Agentshell_Test::assert_equals( 2, count( $reports ), 'both editors must have run: ' . fc_summary( $race_c ) );

        $ok      = array_values( array_filter( $reports, function ( $r ) { return ! empty( $r['ok'] ); } ) );
        $refused = array_values( array_filter( $reports, function ( $r ) { return empty( $r['ok'] ); } ) );

        Agentshell_Test::assert_equals( 1, count( $ok ), 'exactly one editor may win: ' . fc_summary( $race_c ) );
        Agentshell_Test::assert_equals( 1, count( $refused ), 'the other editor must be refused' );

        // Two layers can catch this, and which one fires depends on how the
        // race interleaves. The revision check refuses a caller who read a
        // stale counter *before* the other write landed; the row verification
        // refuses a caller whose two reads straddled the other commit. Both
        // are named refusals that write nothing, and either is correct — what
        // must never happen is an unguarded overwrite, which the assertions
        // below pin.
        Agentshell_Test::assert_true(
            in_array( (string) ( $refused[0]['code'] ?? '' ), array( 'form_revision_mismatch', 'form_state_changed' ), true ),
            'the loser must be told the form changed by name, not given a generic failure: ' . ( $refused[0]['code'] ?? '' )
        );

        $winner_marker = (string) ( $ok[0]['marker'] ?? '' );
        $loser_marker  = (string) ( $refused[0]['marker'] ?? '' );

        Agentshell_Test::assert_equals( 1, (int) ( $edit_verify['revision'] ?? -1 ), 'the revision must advance exactly once' );
        Agentshell_Test::assert_equals(
            $winner_marker,
            $edit_verify['working_name'] ?? null,
            'the stored definition must be the winner’s'
        );
        Agentshell_Test::assert_true(
            false === strpos( (string) ( $edit_verify['working_bytes'] ?? '' ), $loser_marker ),
            'the loser’s edit must appear nowhere in the stored bytes'
        );

        $counts = (array) ( $edit_verify['row_counts'] ?? array() );
        foreach ( $counts as $key => $count ) {
            Agentshell_Test::assert_true( (int) $count <= 1, 'a race must not duplicate the row for ' . $key );
        }
    }
);

/* ---------------------------------------------------------------------------
 * D — two publishers, one revision
 * ------------------------------------------------------------------------ */

$handles = array();
$handles[] = fc_spawn( $config, $child, 'pub', 0, 'publish', array( $publish_form, 0 ) );
$handles[] = fc_spawn( $config, $child, 'pub', 1, 'publish', array( $publish_form, 0 ) );
$race_d = fc_release( $handles );
$publish_verify = fc_run_one( $config, $child, array( 'verify_publish', $publish_form ) );

Agentshell_Test::run(
    'forms concurrency D: two publishers cannot both publish a stale revision',
    function () use ( $race_d, $publish_verify ) {
        $reports = fc_reports( $race_d );
        Agentshell_Test::assert_equals( 2, count( $reports ), 'both publishers must have run: ' . fc_summary( $race_d ) );

        $ok      = array_values( array_filter( $reports, function ( $r ) { return ! empty( $r['ok'] ); } ) );
        $refused = array_values( array_filter( $reports, function ( $r ) { return empty( $r['ok'] ); } ) );

        Agentshell_Test::assert_equals( 1, count( $ok ), 'exactly one publisher may succeed: ' . fc_summary( $race_d ) );
        Agentshell_Test::assert_equals( 1, count( $refused ), 'the other publisher must be refused' );
        Agentshell_Test::assert_equals( 1, (int) ( $ok[0]['version'] ?? -1 ), 'the winning publication must be version 1' );
        Agentshell_Test::assert_true(
            in_array( (string) ( $refused[0]['code'] ?? '' ), array( 'form_revision_mismatch', 'form_state_changed' ), true ),
            'the losing publication must be refused by name: ' . ( $refused[0]['code'] ?? '' )
        );

        // One publication means one version, not two.
        Agentshell_Test::assert_equals( 1, (int) ( $publish_verify['version'] ?? -1 ), 'the form must be at version 1, not 2' );
        Agentshell_Test::assert_true( (bool) ( $publish_verify['has_been_published'] ?? false ), 'the form must be published' );
        Agentshell_Test::assert_equals( 1, (int) ( $publish_verify['published_rows'] ?? -1 ), 'exactly one published row may exist' );
        Agentshell_Test::assert_equals( 1, (int) ( $publish_verify['version_rows'] ?? -1 ), 'exactly one version row may exist' );
        Agentshell_Test::assert_equals( 0, (int) ( $publish_verify['previous_rows'] ?? -1 ), 'a first publication writes no previous definition' );
        Agentshell_Test::assert_equals( 1, (int) ( $publish_verify['published_version'] ?? -1 ), 'the published definition must say version 1' );

        // Internally consistent: what is published is what was being edited.
        Agentshell_Test::assert_equals(
            $publish_verify['working_name'] ?? null,
            $publish_verify['published_name'] ?? null,
            'the published definition must be the working one, not a mixture'
        );
        Agentshell_Test::assert_equals(
            (int) ( $publish_verify['working_fields'] ?? -1 ),
            (int) ( $publish_verify['published_fields'] ?? -2 ),
            'the published definition must carry the working field list'
        );
        Agentshell_Test::assert_equals( 'publish', $publish_verify['post_status'] ?? null, 'the post must be published' );
        Agentshell_Test::assert_true( (int) ( $publish_verify['revision_count'] ?? 0 ) >= 1, 'the publication must leave a native revision' );
    }
);

/* ---------------------------------------------------------------------------
 * E — one challenge, many claims
 * ------------------------------------------------------------------------ */

$handles = array();
for ( $i = 0; $i < 6; $i++ ) {
    $handles[] = fc_spawn( $config, $child, 'chal', $i, 'challenge_claim', array( $challenge ) );
}
$race_e = fc_release( $handles );
$challenge_verify = fc_run_one( $config, $child, array( 'verify_challenge', $challenge ) );

Agentshell_Test::run(
    'forms concurrency E: a challenge is consumed at most once',
    function () use ( $race_e, $challenge_verify ) {
        $reports = fc_reports( $race_e );
        Agentshell_Test::assert_equals( 6, count( $reports ), 'all six claimants must have run: ' . fc_summary( $race_e ) );

        $claimed = array_values( array_filter( $reports, function ( $r ) { return 'claimed' === ( $r['status'] ?? '' ); } ) );
        $pending = array_values( array_filter( $reports, function ( $r ) { return 'pending' === ( $r['status'] ?? '' ); } ) );

        Agentshell_Test::assert_equals( 1, count( $claimed ), 'exactly one claimant may consume the token: ' . fc_summary( $race_e ) );
        Agentshell_Test::assert_equals( 5, count( $pending ), 'every other claimant must be told the challenge is already claimed' );
        Agentshell_Test::assert_true(
            '' !== (string) ( $claimed[0]['form_id'] ?? '' ),
            'the winner must receive the form the challenge was minted for'
        );

        Agentshell_Test::assert_true( true === ( $challenge_verify['exists'] ?? false ), 'the challenge row must still exist (a retry must be recognisable)' );
        Agentshell_Test::assert_true(
            '' !== (string) ( $challenge_verify['consumed_at'] ?? '' ),
            'the row must record when it was consumed'
        );
        Agentshell_Test::assert_equals(
            'pending',
            $challenge_verify['second_claim'] ?? null,
            'a later claim of the same token must never be admitted again'
        );
        Agentshell_Test::assert_equals(
            $challenge_verify['consumed_at'] ?? null,
            $challenge_verify['consumed_at_after'] ?? 'x',
            'a refused claim must not move the consumption time'
        );
    }
);

/* ---------------------------------------------------------------------------
 * F — one bucket, many reservations
 * ------------------------------------------------------------------------ */

$limit   = 5;
$window  = 300;
$when    = time();
$subject = 'cx-subject-' . $token;
$scope   = 'form';
$contenders = 12;

$handles = array();
for ( $i = 0; $i < $contenders; $i++ ) {
    $handles[] = fc_spawn( $config, $child, 'rate', $i, 'rate_reserve', array( $scope, $subject, $publish_fid, $limit, $window, $when ) );
}
$race_f = fc_release( $handles );
$rate_verify = fc_run_one( $config, $child, array( 'verify_rate_limit', $scope, $subject, $publish_fid, $window, $when ) );

Agentshell_Test::run(
    'forms concurrency F: rate-limit admissions never exceed the bound',
    function () use ( $race_f, $rate_verify, $limit, $contenders ) {
        $reports = fc_reports( $race_f );
        Agentshell_Test::assert_equals( $contenders, count( $reports ), 'every contender must have run: ' . fc_summary( $race_f ) );

        foreach ( $reports as $r ) {
            Agentshell_Test::assert_equals( '', (string) ( $r['error'] ?? 'x' ), 'a reservation must not fail: ' . ( $r['error'] ?? '' ) );
        }

        $admitted = array_values( array_filter( $reports, function ( $r ) { return ! empty( $r['admitted'] ); } ) );

        Agentshell_Test::assert_true(
            count( $admitted ) <= $limit,
            'at most ' . $limit . ' requests may be admitted, got ' . count( $admitted ) . ': ' . fc_summary( $race_f )
        );
        Agentshell_Test::assert_true(
            count( $admitted ) >= 1,
            'at least one request must be admitted, or the limiter is simply refusing everything'
        );

        // The counter itself may sit at most one past the limit — the
        // statement increments only while `hits <= limit`, so the ceiling is
        // `limit + 1` and it can never be driven higher by a flood.
        $hits = (int) ( $rate_verify['hits'] ?? -1 );
        Agentshell_Test::assert_true(
            $hits <= $limit + 1,
            'the stored counter may never exceed limit + 1 (' . ( $limit + 1 ) . '), got ' . $hits
        );
        Agentshell_Test::assert_true(
            $hits >= count( $admitted ),
            'the counter must be at least the number of admissions (' . count( $admitted ) . '), got ' . $hits
        );

        // One bucket, not one row per request.
        $rows = is_array( $rate_verify['rows'] ?? null ) ? $rate_verify['rows'] : array();
        Agentshell_Test::assert_equals( 1, count( $rows ), 'all contenders must share one bucket row' );
        Agentshell_Test::assert_equals(
            (int) ( $rate_verify['window_start'] ?? -1 ),
            (int) ( $rows[0]['window_start'] ?? -2 ),
            'the bucket must be the window the contenders were released in'
        );
    }
);

/* ---------------------------------------------------------------------------
 * G — a publisher paused between its read and its lock
 *
 * This is the race the pre-lock revision check cannot catch. The publisher has
 * already read the working definition and passed its `expected_revision`
 * check; another process then commits an edit — same actor, same wall-clock
 * second — and only the verification inside the transaction stands between the
 * stale definition and a new published version.
 * ------------------------------------------------------------------------ */

/**
 * Run one paused publication against a concurrent editor.
 *
 * The publisher announces what it read and waits; the editor runs to
 * completion in its own process; then the publisher is released.
 *
 * @param array  $config      Test-bed config.
 * @param string $child       Child script.
 * @param string $label       Unique label.
 * @param string $form_id     Form to publish.
 * @param int    $revision    Revision to publish against.
 * @param array  $editor_argv Editor mode and arguments.
 * @return array{read:?array,editor:?array,publisher:?array,results:array}
 */
function fc_paused_publish( array $config, $child, $label, $form_id, $revision, array $editor_argv ) {
    global $tmp, $run_id;

    $base   = $tmp . '/as-fcx-' . $run_id . '-' . $label;
    $paused = $base . '.paused.json';
    $resume = $base . '.resume';
    @unlink( $paused );
    @unlink( $resume );

    $handles = array(
        fc_spawn( $config, $child, $label, 0, 'publish_paused', array( $form_id, $revision, $paused, $resume ) ),
    );

    // This contender is alone at its own gate, so it is released at once; the
    // pause it takes is the thing under test, not the starting barrier.
    file_put_contents( $handles[0]['go'], '1' );

    $deadline = microtime( true ) + 90;
    while ( microtime( true ) < $deadline ) {
        clearstatcache( true, $paused );
        if ( is_file( $paused ) ) {
            break;
        }
        usleep( 5000 );
    }

    $read = is_file( $paused ) ? json_decode( (string) @file_get_contents( $paused ), true ) : null;
    $read = is_array( $read ) ? $read : null;

    $editor = is_array( $read ) ? fc_run_one( $config, $child, $editor_argv ) : null;

    file_put_contents( $resume, '1' );

    $results = fc_release( $handles );

    return array(
        'read'      => $read,
        'editor'    => $editor,
        'publisher' => isset( $results[0]['report'] ) ? $results[0]['report'] : null,
        'results'   => $results,
    );
}

$pause_forms = isset( $prep['pause'] ) && is_array( $prep['pause'] ) ? $prep['pause'] : array();

$race_g1 = fc_paused_publish(
    $config,
    $child,
    'pause-different',
    (string) ( $pause_forms[0]['form_id'] ?? '' ),
    (int) ( $pause_forms[0]['revision'] ?? 0 ),
    array( 'update', (string) ( $pause_forms[0]['form_id'] ?? '' ), (int) ( $pause_forms[0]['revision'] ?? 0 ), 'Race G editor ' . $token )
);

$pause_verify_1 = fc_run_one( $config, $child, array( 'verify_publish', (string) ( $pause_forms[0]['form_id'] ?? '' ) ) );

$race_g2 = fc_paused_publish(
    $config,
    $child,
    'pause-identical',
    (string) ( $pause_forms[1]['form_id'] ?? '' ),
    (int) ( $pause_forms[1]['revision'] ?? 0 ),
    array( 'update_identical', (string) ( $pause_forms[1]['form_id'] ?? '' ), (int) ( $pause_forms[1]['revision'] ?? 0 ) )
);

$pause_verify_2 = fc_run_one( $config, $child, array( 'verify_publish', (string) ( $pause_forms[1]['form_id'] ?? '' ) ) );

Agentshell_Test::run(
    'forms concurrency G1: a publisher that read a stale definition cannot publish it',
    function () use ( $race_g1, $pause_verify_1 ) {
        $read      = $race_g1['read'];
        $editor    = $race_g1['editor'];
        $publisher = $race_g1['publisher'];

        Agentshell_Test::assert_true( is_array( $read ), 'the publisher must have announced what it read: ' . fc_summary( $race_g1['results'] ) );
        Agentshell_Test::assert_true( is_array( $editor ), 'the editor must have run' );
        Agentshell_Test::assert_true( is_array( $publisher ), 'the publisher must have reported: ' . fc_summary( $race_g1['results'] ) );

        // The scenario is only the scenario if the publisher really did read
        // first and the editor really did commit after it.
        Agentshell_Test::assert_equals( 0, (int) ( $read['revision'] ?? -1 ), 'the publisher must have read revision 0' );
        Agentshell_Test::assert_true( true === ( $editor['ok'] ?? false ), 'the editor must have committed: ' . ( $editor['code'] ?? '' ) );
        Agentshell_Test::assert_equals( 1, (int) ( $editor['revision'] ?? -1 ), 'the editor must have advanced the revision to 1' );

        // Same actor, same second: the publisher read, the editor committed and
        // the publisher was refused inside one wall-clock second, so nothing
        // about the ordering can be attributed to a timestamp.
        Agentshell_Test::assert_equals(
            (int) ( $publisher['started_at'] ?? 0 ),
            (int) ( $publisher['finished_at'] ?? 0 ),
            'the whole read-pause-refuse sequence must have happened inside one second: '
                . wp_json_encode( $publisher )
        );
        Agentshell_Test::assert_equals(
            (int) ( $publisher['started_at'] ?? 0 ),
            (int) ( $read['at'] ?? 0 ),
            'the publisher must have read the definition in the same second it finished in'
        );

        Agentshell_Test::assert_false( (bool) ( $publisher['ok'] ?? true ), 'a stale publication must be refused' );
        Agentshell_Test::assert_equals(
            'form_state_changed',
            $publisher['code'] ?? null,
            'the refusal must say the state changed under the publisher, not that the caller passed a stale number'
        );

        // And nothing from the stale definition reached the live one.
        Agentshell_Test::assert_equals( 0, (int) ( $pause_verify_1['version'] ?? -1 ), 'no version may have been created: ' . fc_summary( $race_g1['results'] ) );
        Agentshell_Test::assert_equals( 0, (int) ( $pause_verify_1['published_rows'] ?? -1 ), 'no published row may exist' );
        Agentshell_Test::assert_false( (bool) ( $pause_verify_1['has_been_published'] ?? true ), 'the form must not claim to have been published' );
        Agentshell_Test::assert_false(
            false !== strpos( (string) ( $pause_verify_1['published_bytes'] ?? '' ), 'Your name' ),
            'no definition at all may have been published'
        );
        Agentshell_Test::assert_equals( 'draft', $pause_verify_1['post_status'] ?? null, 'a refused first publication must leave the post a draft' );
        Agentshell_Test::assert_true(
            0 === strpos( (string) ( $pause_verify_1['working_name'] ?? '' ), 'Race G editor' ),
            'the editor’s write must have survived intact: ' . ( $pause_verify_1['working_name'] ?? '' )
        );
    }
);

Agentshell_Test::run(
    'forms concurrency G2: the revision row alone is enough to refuse a stale publication',
    function () use ( $race_g2, $pause_verify_2 ) {
        $read      = $race_g2['read'];
        $editor    = $race_g2['editor'];
        $publisher = $race_g2['publisher'];

        Agentshell_Test::assert_true( is_array( $read ), 'the publisher must have announced what it read: ' . fc_summary( $race_g2['results'] ) );
        Agentshell_Test::assert_true( is_array( $editor ), 'the identical editor must have run' );
        Agentshell_Test::assert_true( is_array( $publisher ), 'the publisher must have reported' );

        Agentshell_Test::assert_true( true === ( $editor['ok'] ?? false ), 'the identical edit must still commit: ' . ( $editor['code'] ?? '' ) );
        Agentshell_Test::assert_equals( 1, (int) ( $editor['revision'] ?? -1 ), 'the identical edit must advance the revision' );
        Agentshell_Test::assert_false(
            (bool) ( $editor['bytes_changed'] ?? true ),
            'the identical edit must not change the working bytes — otherwise this race is the G1 race again'
        );

        Agentshell_Test::assert_false( (bool) ( $publisher['ok'] ?? true ), 'a publication whose revision moved must be refused even when the bytes did not' );
        Agentshell_Test::assert_equals( 'form_state_changed', $publisher['code'] ?? null, 'the refusal must name the state change' );

        Agentshell_Test::assert_equals( 0, (int) ( $pause_verify_2['version'] ?? -1 ), 'no version may have been created' );
        Agentshell_Test::assert_equals( 0, (int) ( $pause_verify_2['published_rows'] ?? -1 ), 'no published row may exist' );
        Agentshell_Test::assert_equals( 'draft', $pause_verify_2['post_status'] ?? null, 'the post must still be a draft' );
    }
);

/* ---------------------------------------------------------------------------
 * H — a slug change racing an editor that read the same revision
 * ------------------------------------------------------------------------ */

$slug_race = isset( $prep['slug_race'] ) && is_array( $prep['slug_race'] ) ? $prep['slug_race'] : array();
$slug_form = (string) ( $slug_race['form_id'] ?? '' );
$slug_old  = (string) ( $slug_race['slug'] ?? '' );
$slug_new  = $slug_old . '-moved';

$handles   = array();
$handles[] = fc_spawn( $config, $child, 'slugrace-edit', 0, 'update', array( $slug_form, 0, 'Race H editor ' . $token ) );
$handles[] = fc_spawn( $config, $child, 'slugrace-slug', 1, 'set_slug', array( $slug_form, $slug_new ) );
$race_h    = fc_release( $handles );

$slug_state_h = fc_run_one( $config, $child, array( 'verify_slug_state', $slug_form ) );

Agentshell_Test::run(
    'forms concurrency H: a slug change and an edit cannot leave the two slug representations disagreeing',
    function () use ( $race_h, $slug_state_h, $slug_old, $slug_new ) {
        $reports = fc_reports( $race_h );
        Agentshell_Test::assert_equals( 2, count( $reports ), 'both contenders must have run: ' . fc_summary( $race_h ) );

        $ok      = array_values( array_filter( $reports, function ( $r ) { return ! empty( $r['ok'] ); } ) );
        $refused = array_values( array_filter( $reports, function ( $r ) { return empty( $r['ok'] ); } ) );

        Agentshell_Test::assert_true( count( $ok ) >= 1, 'at least one contender must succeed: ' . fc_summary( $race_h ) );

        foreach ( $refused as $r ) {
            Agentshell_Test::assert_true(
                in_array(
                    (string) ( $r['code'] ?? '' ),
                    array( 'form_state_changed', 'form_revision_mismatch', 'form_slug_duplicate' ),
                    true
                ),
                'a losing contender must be refused by name: ' . ( $r['code'] ?? '' )
            );
        }

        // The invariant, whatever the order of the two writes.
        Agentshell_Test::assert_true(
            true === ( $slug_state_h['slug_consistent'] ?? false ),
            'the dedicated row and the working definition must agree afterwards: row='
                . ( $slug_state_h['slug_row'] ?? '' ) . ' working=' . ( $slug_state_h['working_slug'] ?? '' )
        );
        Agentshell_Test::assert_equals(
            $slug_state_h['slug_row'] ?? null,
            $slug_state_h['working_slug'] ?? null,
            'the two representations must be the same string'
        );
        Agentshell_Test::assert_true(
            in_array( (string) ( $slug_state_h['slug_row'] ?? '' ), array( $slug_old, $slug_new ), true ),
            'the slug must be one of the two contenders’ values, never a mixture: ' . ( $slug_state_h['slug_row'] ?? '' )
        );

        // Never a second row for any key — a racing slug change must not add
        // or lose one — and the six keys a stored form always has must all be
        // present with exactly one row.
        $counts  = is_array( $slug_state_h['row_counts'] ?? null ) ? $slug_state_h['row_counts'] : array();
        $present = 0;
        foreach ( $counts as $key => $count ) {
            Agentshell_Test::assert_true(
                (int) $count <= 1,
                'key ' . $key . ' must never have more than one row: ' . wp_json_encode( $counts )
            );
            if ( 1 === (int) $count ) {
                $present++;
            }
        }
        Agentshell_Test::assert_true(
            $present >= 6,
            'the six keys a form always has must all still exist: ' . wp_json_encode( $counts )
        );
    }
);

/* ---------------------------------------------------------------------------
 * I — concurrent audited mutations
 *
 * The audit ring is one option row, appended by read-modify-write. Under
 * concurrency the failure modes are a lost entry (a change with no record) and
 * a corrupted ring (a change that can no longer be read). Neither may happen.
 * ------------------------------------------------------------------------ */

$audit_forms = isset( $prep['audit'] ) && is_array( $prep['audit'] ) ? $prep['audit'] : array();
$distinct    = array_slice( $audit_forms, 0, 4 );
$shared      = isset( $audit_forms[4] ) ? $audit_forms[4] : array();

$handles = array();
foreach ( $distinct as $i => $row ) {
    $handles[] = fc_spawn( $config, $child, 'audit-distinct', $i, 'update', array( (string) $row['form_id'], 0, 'Audit distinct ' . $token . ' #' . $i ) );
}
$race_i1 = fc_release( $handles );

$distinct_ids = array_map( function ( $row ) { return (string) $row['form_id']; }, $distinct );
$audit_verify_1 = fc_run_one( $config, $child, array_merge( array( 'verify_audit', $token ), $distinct_ids ) );

$handles = array();
for ( $i = 0; $i < 4; $i++ ) {
    $handles[] = fc_spawn( $config, $child, 'audit-shared', $i, 'update', array( (string) ( $shared['form_id'] ?? '' ), 0, 'Audit shared ' . $token . ' #' . $i ) );
}
$race_i2 = fc_release( $handles );

$audit_verify_2 = fc_run_one( $config, $child, array( 'verify_audit', $token, (string) ( $shared['form_id'] ?? '' ) ) );

Agentshell_Test::run(
    'forms concurrency I: concurrent audited mutations lose no audit entries',
    function () use ( $race_i1, $audit_verify_1, $distinct_ids ) {
        $reports = fc_reports( $race_i1 );
        Agentshell_Test::assert_equals( count( $distinct_ids ), count( $reports ), 'every contender must have run: ' . fc_summary( $race_i1 ) );

        $ok = array_values( array_filter( $reports, function ( $r ) { return ! empty( $r['ok'] ); } ) );
        Agentshell_Test::assert_equals(
            count( $distinct_ids ),
            count( $ok ),
            'every contender is editing a different form, so every one must succeed: ' . fc_summary( $race_i1 )
        );

        Agentshell_Test::assert_true( true === ( $audit_verify_1['ring_is_array'] ?? false ), 'the audit ring must still be a readable array' );
        Agentshell_Test::assert_equals(
            (int) ( $audit_verify_1['ring_size'] ?? -1 ),
            (int) ( $audit_verify_1['well_formed'] ?? -2 ),
            'every retained entry must be well formed, not a partial write'
        );

        $per_event = is_array( $audit_verify_1['per_event'] ?? null ) ? $audit_verify_1['per_event'] : array();
        foreach ( $distinct_ids as $form_id ) {
            Agentshell_Test::assert_equals(
                1,
                (int) ( $per_event[ $form_id ]['form_created'] ?? 0 ),
                'form ' . $form_id . ' must record exactly one creation: ' . wp_json_encode( $per_event )
            );
            Agentshell_Test::assert_equals(
                1,
                (int) ( $per_event[ $form_id ]['form_updated'] ?? 0 ),
                'form ' . $form_id . ' must record exactly one update, with no entry lost to a concurrent append: '
                    . wp_json_encode( $per_event )
            );
        }
    }
);

Agentshell_Test::run(
    'forms concurrency I2: concurrent edits of one form record exactly one change',
    function () use ( $race_i2, $audit_verify_2 ) {
        $reports = fc_reports( $race_i2 );
        Agentshell_Test::assert_equals( 4, count( $reports ), 'every contender must have run: ' . fc_summary( $race_i2 ) );

        $ok = array_values( array_filter( $reports, function ( $r ) { return ! empty( $r['ok'] ); } ) );
        Agentshell_Test::assert_equals( 1, count( $ok ), 'exactly one editor may win: ' . fc_summary( $race_i2 ) );

        $per_event = is_array( $audit_verify_2['per_event'] ?? null ) ? $audit_verify_2['per_event'] : array();
        $form_id   = (string) ( $audit_verify_2['form_ids'][0] ?? '' );

        Agentshell_Test::assert_equals(
            1,
            (int) ( $per_event[ $form_id ]['form_updated'] ?? 0 ),
            'one committed change means exactly one update entry, not one per contender: ' . wp_json_encode( $per_event )
        );
        Agentshell_Test::assert_equals(
            (int) ( $audit_verify_2['ring_size'] ?? -1 ),
            (int) ( $audit_verify_2['well_formed'] ?? -2 ),
            'the ring must still be well formed after the failed appends'
        );
    }
);

/* ---------------------------------------------------------------------------
 * J — concurrent creations, all racing the audit ring's first insert
 *
 * The ring is one option row. When several transactions append to it for the
 * first time, exactly one inserts it and the rest must find it rather than
 * lose their entry (or fail). `INSERT` + duplicate-key retry is only safe if
 * that is true, so it is raced rather than reasoned about.
 * ------------------------------------------------------------------------ */

// An empty ring, so every contender really is racing the *first* insert.
$audit_cleared = fc_run_one( $config, $child, array( 'clear_audit' ) );

$handles = array();
for ( $i = 0; $i < 4; $i++ ) {
    $handles[] = fc_spawn( $config, $child, 'create-audit', $i, 'create_same_slug', array( 'cx-' . strtolower( $token ) . '-create-' . $i, $token ) );
}
$race_j = fc_release( $handles );

$created_reports = fc_reports( $race_j );
$created_ids     = array();
foreach ( $created_reports as $r ) {
    if ( ! empty( $r['form_id'] ) ) {
        $created_ids[] = (string) $r['form_id'];
    }
}
$audit_verify_3 = fc_run_one( $config, $child, array_merge( array( 'verify_audit', $token ), $created_ids ) );

Agentshell_Test::run(
    'forms concurrency J: concurrent creations all record their own audit entry',
    function () use ( $race_j, $created_reports, $created_ids, $audit_verify_3, $audit_cleared ) {
        Agentshell_Test::assert_equals( 4, count( $created_reports ), 'every creator must have run: ' . fc_summary( $race_j ) );
        Agentshell_Test::assert_equals( 4, count( $created_ids ), 'every creator must have created its form: ' . fc_summary( $race_j ) );

        foreach ( $created_reports as $r ) {
            Agentshell_Test::assert_true( true === ( $r['created'] ?? false ), 'a creation with its own slug must succeed: ' . fc_summary( $race_j ) );
            Agentshell_Test::assert_equals( 1, count( (array) ( $r['posts_with_slug'] ?? array() ) ), 'each slug must resolve to exactly one form' );
        }

        Agentshell_Test::assert_true( true === ( $audit_cleared['cleared'] ?? false ), 'the probe must have emptied the ring first' );
        Agentshell_Test::assert_true( true === ( $audit_verify_3['ring_is_array'] ?? false ), 'the ring must still be readable' );
        Agentshell_Test::assert_true(
            (int) ( $audit_verify_3['ring_size'] ?? -1 ) >= 4,
            'every creation must have appended an entry: ' . wp_json_encode( $audit_verify_3 )
        );
        Agentshell_Test::assert_equals(
            (int) ( $audit_verify_3['ring_size'] ?? -1 ),
            (int) ( $audit_verify_3['well_formed'] ?? -2 ),
            'no concurrent first append may leave a partial entry'
        );

        $per_event = is_array( $audit_verify_3['per_event'] ?? null ) ? $audit_verify_3['per_event'] : array();
        foreach ( $created_ids as $form_id ) {
            Agentshell_Test::assert_equals(
                1,
                (int) ( $per_event[ $form_id ]['form_created'] ?? 0 ),
                'form ' . $form_id . ' must have exactly one creation entry, and none may be lost to the ring insert race: '
                    . wp_json_encode( $per_event )
            );
        }
    }
);

/* ---------------------------------------------------------------------------
 * Cleanup
 * ------------------------------------------------------------------------ */

$cleanup = fc_run_one( $config, $child, array( 'cleanup', $token ) );

Agentshell_Test::run(
    'forms concurrency: the site is left exactly as it was found',
    function () use ( $cleanup ) {
        Agentshell_Test::assert_false( isset( $cleanup['error'] ), 'cleanup must run: ' . wp_json_encode( $cleanup ) );
        Agentshell_Test::assert_equals( array(), $cleanup['leftovers'] ?? null, 'every disposable form must be gone' );
        Agentshell_Test::assert_equals( 0, (int) ( $cleanup['stray_meta'] ?? -1 ), 'no orphaned definition metadata may remain' );
    }
);
