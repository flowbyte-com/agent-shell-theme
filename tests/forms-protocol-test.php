<?php
/**
 * The challenge and rate-limit protocol state, against a real MySQL.
 *
 * These are the two stores the design gives a table to, and for one reason:
 * the options table and transients give **no atomicity** for a consume-once
 * operation and **no atomicity** for a counter increment. A table with a unique
 * key does. So the properties worth pinning are the ones that depend on that
 * key, and they can only be pinned against a real database:
 *
 *   - a challenge is stored as a hash, claimed once, and keeps its claim so a
 *     genuine retry is recognisable rather than indistinguishable from a replay;
 *   - a challenge is refused once expired, and the claim statement re-checks
 *     expiry rather than trusting the row's existence;
 *   - the rate-limit counter never passes its bound;
 *   - both are prunable without touching live rows.
 *
 * The *concurrent* halves — six claimants at once, twelve reservations at once
 * — are `tests/forms-concurrency-test.php`, because they need separate
 * processes.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms protocol state (real MySQL)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'forms protocol suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-protocol-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-protocol-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms protocol: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

/**
 * One probe's facts.
 *
 * @param string $name Probe name.
 * @return array
 */
function fpr( $name ) {
    $f = $GLOBALS['f'];
    return isset( $f[ $name ] ) && is_array( $f[ $name ] ) ? $f[ $name ] : array();
}

/* ---------------------------------------------------------------------------
 * Challenges
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms protocol: a challenge is stored as a hash, never as the token itself',
    function () {
        $p = fpr( 'c1_mint' );

        Agentshell_Test::assert_false( (bool) ( $p['is_error'] ?? true ), 'minting must succeed: ' . ( $p['code'] ?? '' ) );
        Agentshell_Test::assert_true( true === ( $p['token_is_hex'] ?? false ), 'the token must be 256 bits of hex' );
        Agentshell_Test::assert_equals( 64, (int) ( $p['token_length'] ?? 0 ), 'the token must be 64 characters' );
        Agentshell_Test::assert_true( true === ( $p['row_exists'] ?? false ), 'the challenge must be stored' );
        Agentshell_Test::assert_true( true === ( $p['stored_is_hash'] ?? false ), 'the stored value must be the SHA-256 of the token' );
        Agentshell_Test::assert_true(
            true === ( $p['plaintext_absent'] ?? false ),
            'a database disclosure must not yield a usable token'
        );
        Agentshell_Test::assert_equals( 'abababababababababababababababab', $p['form_id'] ?? null, 'the challenge must record its form' );
        Agentshell_Test::assert_equals( 7, (int) ( $p['form_version'] ?? 0 ), 'the challenge must record the published version it was minted for' );
        Agentshell_Test::assert_equals( 'subject-hash', $p['subject_hash'] ?? null, 'the challenge must record its subject hash' );
        Agentshell_Test::assert_true( true === ( $p['unconsumed'] ?? false ), 'a new challenge must be unconsumed' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['submission_id'] ?? -1 ), 'a new challenge has produced no submission yet' );
        Agentshell_Test::assert_true(
            (int) ( $p['seconds_to_expiry'] ?? 0 ) > 3500 && (int) ( $p['seconds_to_expiry'] ?? 0 ) <= 3600,
            'the expiry must be the requested TTL'
        );
    }
);

Agentshell_Test::run(
    'forms protocol: a challenge is claimed once and keeps the claim',
    function () {
        $p = fpr( 'c2_claim' );

        Agentshell_Test::assert_equals( 'claimed', $p['first_status'] ?? null, 'the first claim must own the challenge' );
        Agentshell_Test::assert_equals( 'abababababababababababababababab', $p['first_form_id'] ?? null, 'the claimant must be told which form the challenge is for' );
        Agentshell_Test::assert_equals( 7, (int) ( $p['first_form_version'] ?? 0 ), 'the claimant must be told which version it is for' );

        // A second claim is not "claimed". It is `pending`, because an earlier
        // attempt owns it and has not linked a submission yet — which is what
        // makes a genuine retry distinguishable from a replay.
        Agentshell_Test::assert_equals( 'pending', $p['second_status'] ?? null, 'a second claim must not own the challenge' );

        Agentshell_Test::assert_true( true === ( $p['linked'] ?? false ), 'linking the submission must succeed' );
        Agentshell_Test::assert_equals( 'linked', $p['linked_status'] ?? null, 'a first link must report itself as linked' );
        Agentshell_Test::assert_true( true === ( $p['linked_changed'] ?? false ), 'a first link must report that it wrote' );
        Agentshell_Test::assert_equals( 4242, (int) ( $p['linked_submission'] ?? -1 ), 'the link must name the submission it linked' );
        Agentshell_Test::assert_equals( 'replay', $p['after_link_status'] ?? null, 'after linking, a claim is a replay of a known submission' );
        Agentshell_Test::assert_equals( 4242, (int) ( $p['after_link_submission'] ?? -1 ), 'the replay must name the submission that was already made' );
        Agentshell_Test::assert_true( true === ( $p['consumed_at_set'] ?? false ), 'the claim time must be recorded' );
        Agentshell_Test::assert_equals( 4242, (int) ( $p['submission_stored'] ?? -1 ), 'the submission id must be stored on the challenge' );
    }
);

Agentshell_Test::run(
    'forms protocol: linking refuses an unclaimed, expired, absent or malformed challenge, and never replaces a submission',
    function () {
        $p = fpr( 'c2b_link_rules' );

        // An unclaimed challenge has produced nothing to link to.
        Agentshell_Test::assert_equals( 'unclaimed', $p['unclaimed_status'] ?? null, 'an unclaimed challenge must not be linkable' );
        Agentshell_Test::assert_false( (bool) ( $p['unclaimed_linked'] ?? true ), 'an unclaimed challenge must not report itself linked' );
        Agentshell_Test::assert_false( (bool) ( $p['unclaimed_changed'] ?? true ), 'an unclaimed challenge must not be written' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['unclaimed_submission'] ?? -1 ), 'an unclaimed challenge must still hold no submission' );

        // An expired challenge is not a valid one, claimed or not.
        Agentshell_Test::assert_equals( 'expired', $p['expired_status'] ?? null, 'an expired challenge must not be linkable' );
        Agentshell_Test::assert_false( (bool) ( $p['expired_linked'] ?? true ), 'an expired challenge must not report itself linked' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['expired_submission'] ?? -1 ), 'an expired challenge must still hold no submission' );

        // The first link wins, and the second is refused rather than replacing.
        Agentshell_Test::assert_equals( 'linked', $p['first_status'] ?? null, 'the first link of a claimed challenge must succeed' );
        Agentshell_Test::assert_true( true === ( $p['first_changed'] ?? false ), 'the first link must have written' );
        Agentshell_Test::assert_equals( 'conflict', $p['conflict_status'] ?? null, 'linking a different submission must be refused as a conflict' );
        Agentshell_Test::assert_false( (bool) ( $p['conflict_linked'] ?? true ), 'a conflicting link must not report the other submission as linked' );
        Agentshell_Test::assert_false( (bool) ( $p['conflict_changed'] ?? true ), 'a conflicting link must not write' );
        Agentshell_Test::assert_equals( 333, (int) ( $p['conflict_reports_existing'] ?? -1 ), 'the conflict must name the submission that is already linked' );

        // Retrying the *same* link is not an error, and writes nothing.
        Agentshell_Test::assert_equals( 'already_linked', $p['idempotent_status'] ?? null, 'relinking the same submission must be idempotent' );
        Agentshell_Test::assert_true( true === ( $p['idempotent_linked'] ?? false ), 'a repeated link must report the challenge as linked' );
        Agentshell_Test::assert_false( (bool) ( $p['idempotent_changed'] ?? true ), 'a repeated link must not write again' );
        Agentshell_Test::assert_equals( 333, (int) ( $p['stored_submission'] ?? -1 ), 'the stored submission must be the first one, not the last attempt' );
        Agentshell_Test::assert_equals( 333, (int) ( $p['stored_after_invalid'] ?? -1 ), 'no refused attempt may change the stored submission' );

        // Malformed and unknown inputs.
        Agentshell_Test::assert_equals( 'invalid', $p['invalid_token_status'] ?? null, 'a token that is not a token must be refused as invalid' );
        Agentshell_Test::assert_equals( 'invalid', $p['short_token_status'] ?? null, 'a short token must be refused as invalid' );
        Agentshell_Test::assert_equals( 'absent', $p['absent_status'] ?? null, 'an unknown token must be reported absent' );
        Agentshell_Test::assert_equals( 'invalid', $p['zero_submission_status'] ?? null, 'a zero submission id must be refused as invalid' );
    }
);

Agentshell_Test::run(
    'forms protocol: an unknown token is absent, and an expired one is refused',
    function () {
        Agentshell_Test::assert_equals( 'absent', fpr( 'c3_absent' )['status'] ?? null, 'an unknown token must be reported absent' );

        $p = fpr( 'c4_expired' );
        Agentshell_Test::assert_true( true === ( $p['mint_ok'] ?? false ), 'the probe must have minted the challenge it expires' );
        Agentshell_Test::assert_equals(
            'expired',
            $p['status'] ?? null,
            'an expired challenge must be refused by the claim statement itself, not accepted because the row exists'
        );
        Agentshell_Test::assert_equals( 'abababababababababababababababab', $p['form_id'] ?? null, 'the refusal must still identify the form' );
    }
);

Agentshell_Test::run(
    'forms protocol: pruning removes expired challenges and keeps live ones',
    function () {
        $p = fpr( 'c5_prune' );

        Agentshell_Test::assert_true( true === ( $p['kept_survives'] ?? false ), 'a live challenge must survive a prune' );
        Agentshell_Test::assert_true( true === ( $p['expired_removed'] ?? false ), 'an expired challenge must be pruned' );
    }
);

/* ---------------------------------------------------------------------------
 * Rate limits
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms protocol: the rate-limit counter stops at its bound',
    function () {
        $p     = fpr( 'r1_sequence' );
        $seq   = isset( $p['sequence'] ) && is_array( $p['sequence'] ) ? $p['sequence'] : array();

        Agentshell_Test::assert_equals( 4, count( $seq ), 'the probe must have made four reservations' );

        foreach ( $seq as $index => $step ) {
            Agentshell_Test::assert_equals( '', (string) ( $step['error'] ?? 'x' ), 'reservation ' . $index . ' must not fail' );
            Agentshell_Test::assert_equals( $index + 1, (int) ( $step['hits'] ?? -1 ), 'reservation ' . $index . ' must read the counter it produced' );
        }

        Agentshell_Test::assert_equals( array( true, true, true, false ), array_map( function ( $s ) { return (bool) $s['admitted']; }, $seq ), 'with a limit of 3, the fourth request must be refused' );
        Agentshell_Test::assert_equals( 4, (int) ( $p['hits_read'] ?? -1 ), 'the counter must be readable without reserving' );

        // A new window is a new bucket: the subject is admitted again.
        $next = isset( $p['next_window'] ) && is_array( $p['next_window'] ) ? $p['next_window'] : array();
        Agentshell_Test::assert_true( true === ( $next['admitted'] ?? false ), 'a new window must admit the same subject again' );
        Agentshell_Test::assert_equals( 1, (int) ( $next['hits'] ?? -1 ), 'a new window starts its own counter' );
        Agentshell_Test::assert_equals( (int) ( $p['expected_next_start'] ?? -1 ), (int) ( $next['start'] ?? -2 ), 'the new bucket must start at the next window boundary' );

        // The site scope is a different bucket, shared by everybody.
        Agentshell_Test::assert_true( true === ( $p['site_scope_admitted'] ?? false ), 'the site scope must be its own bucket' );
        Agentshell_Test::assert_equals( 1, (int) ( $p['site_scope_hits'] ?? -1 ), 'the site scope must start at zero' );

        Agentshell_Test::assert_equals(
            array_map( 'intval', (array) ( $p['window_start_expected'] ?? array() ) ),
            array_map( 'intval', (array) ( $p['window_start_floors'] ?? array() ) ),
            'the window start must floor to the boundary, not round'
        );
    }
);

Agentshell_Test::run(
    'forms protocol: pruning removes expired counters and keeps live ones',
    function () {
        $p = fpr( 'r2_prune' );

        Agentshell_Test::assert_true( true === ( $p['live_reserve_ok'] ?? false ), 'the live bucket must be reservable' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['stale_rows'] ?? -1 ), 'an expired counter must be pruned' );
        Agentshell_Test::assert_equals( 1, (int) ( $p['live_rows'] ?? -1 ), 'a live counter must survive the prune' );
    }
);

Agentshell_Test::run(
    'forms protocol: the protocol tables are left empty',
    function () {
        $p = fpr( 'cleanup' );

        Agentshell_Test::assert_equals( 0, (int) ( $p['challenge_rows'] ?? -1 ), 'no challenge may be left behind' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['rate_rows'] ?? -1 ), 'no rate-limit counter may be left behind' );
    }
);
