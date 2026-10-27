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
    // then the mode's own arguments. A sequential run has no barrier, so the
    // ready and go positions are empty strings.
    $full = array( (string) $config['wp_root'], $mode, $report, '', '' );
    foreach ( $argv as $arg ) {
        $full[] = (string) $arg;
    }

    $cmd = fc_command( $config, $script, $full ) . ' 2>&1';
    exec( $cmd, $out, $status );

    $decoded = json_decode( (string) @file_get_contents( $report ), true );
    @unlink( $report );

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
