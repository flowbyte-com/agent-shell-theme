<?php
/**
 * The notification lifecycle against a real WordPress.
 *
 * `tests/forms-notifier-test.php` pins what a message says. This suite pins
 * what happens to it afterwards, with a transport that can accept, refuse,
 * hang and throw:
 *
 *   - an accepted message is not sent again;
 *   - a failure is retried after a growing delay, and given up on after the
 *     fifth attempt, with the give-up audited;
 *   - a live claim is respected and an abandoned one is taken over;
 *   - the reconciler turns an expired claim into `uncertain` — the one status
 *     that admits not knowing — and sends nothing;
 *   - an attempt that outlives its budget is `uncertain`, not accepted;
 *   - **a notification failure never creates another submission**, and a
 *     failure never rolls the submission back;
 *   - the confirmation is a second, independent message with its own record.
 *
 * It writes submissions and mail and removes every one of them; the parent
 * asserts that it did.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms notifications (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms notification suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-notification-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-notification-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms notifications: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

$p = isset( $f['probes'] ) && is_array( $f['probes'] ) ? $f['probes'] : array();

if ( isset( $p['fatal'] ) ) {
    Agentshell_Test::run(
        'forms notifications: the child reached its subjects',
        function () use ( $p ) {
            Agentshell_Test::assert_true( false, 'the child could not set up: ' . $p['fatal']['where'] . ' — ' . $p['fatal']['code'] . ': ' . $p['fatal']['message'] );
        }
    );
    Agentshell_Test::summary();
    return;
}

/**
 * One probe's field, with an actionable failure when the child did not report.
 *
 * @param array  $probes All probes.
 * @param string $probe  Probe name.
 * @param string $key    Field.
 * @return mixed
 */
function asn3r( array $probes, $probe, $key ) {
    if ( ! isset( $probes[ $probe ] ) ) {
        throw new Exception( "the child did not report the probe \"{$probe}\"" );
    }

    if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
        throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
    }

    return $probes[ $probe ][ $key ];
}

// ══ accepted, once ═══════════════════════════════════════════════════════════

Agentshell_Test::run(
    'an accepted notification is recorded as accepted, with its message id',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'accepted', 'outcome' ), 'the first attempt is accepted' );
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'accepted', 'status' ), 'the record says accepted' );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'accepted', 'attempts' ), 'one attempt is counted' );
        Agentshell_Test::assert_true( '' !== (string) asn3r( $p, 'accepted', 'accepted_at' ), 'the acceptance time is recorded' );
        Agentshell_Test::assert_true( '' !== (string) asn3r( $p, 'accepted', 'message_id' ), 'the Message-ID is recorded' );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'accepted', 'mails' ), 'exactly one message is handed to the transport' );
    }
);

Agentshell_Test::run(
    'an accepted notification is not sent a second time',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'accepted', 'again_outcome' ), 'a retry reports the accepted state' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'accepted', 'again_mails' ), 'a retry of an accepted message sends nothing' );
    }
);

// ══ failure and backoff ══════════════════════════════════════════════════════

Agentshell_Test::run(
    'a refused message is a failed attempt with a recorded reason and a delay',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'failed', asn3r( $p, 'failed', 'outcome' ), 'the attempt failed' );
        Agentshell_Test::assert_equals( 'failed', asn3r( $p, 'failed', 'status' ), 'the record says failed' );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'failed', 'attempts' ), 'the failure is counted as an attempt' );
        Agentshell_Test::assert_true( asn3r( $p, 'failed', 'has_error' ), 'the failure keeps a reason' );

        $wait = (int) asn3r( $p, 'failed', 'wait' );
        Agentshell_Test::assert_true( $wait >= 55 && $wait <= 70, "the first retry is about a minute away (got {$wait}s)" );
    }
);

Agentshell_Test::run(
    'a retry before its time is not attempted',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'not_due', asn3r( $p, 'failed', 'not_due_outcome' ), 'the worker says the record is not due' );
        Agentshell_Test::assert_equals( 'failed', asn3r( $p, 'failed', 'not_due_status' ), 'the record is left as it was' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'failed', 'not_due_mails' ), 'a premature retry sends nothing' );
    }
);

Agentshell_Test::run(
    'a due retry is attempted, and the delay grows',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'failed', asn3r( $p, 'backoff', 'outcome' ), 'the second attempt fails too' );
        Agentshell_Test::assert_equals( 2, asn3r( $p, 'backoff', 'attempts' ), 'the attempt count grows' );

        $wait = (int) asn3r( $p, 'backoff', 'wait' );
        Agentshell_Test::assert_true( $wait > 60, "the second delay is longer than the first (got {$wait}s)" );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'backoff', 'mails' ), 'the retry really hands one message to the transport' );
    }
);

Agentshell_Test::run(
    'the fifth failure gives up, stops retrying and is audited',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'exhausted', asn3r( $p, 'exhausted', 'outcome' ), 'the final attempt reports exhaustion' );
        Agentshell_Test::assert_equals( 'exhausted', asn3r( $p, 'exhausted', 'status' ), 'the record is exhausted' );
        Agentshell_Test::assert_equals( 5, asn3r( $p, 'exhausted', 'attempts' ), 'no more than five attempts are made' );
        Agentshell_Test::assert_equals( null, asn3r( $p, 'exhausted', 'next' ), 'an exhausted record has no next attempt' );
        Agentshell_Test::assert_equals( 'exhausted', asn3r( $p, 'exhausted', 'after_outcome' ), 'a later run does not retry it' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'exhausted', 'after_mails' ), 'a later run sends nothing' );
        Agentshell_Test::assert_true( asn3r( $p, 'exhausted', 'audited' ), 'giving up is recorded in the audit log' );
    }
);

// ══ claims ═══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a live claim is respected by every other worker',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'busy', asn3r( $p, 'claim', 'busy_outcome' ), 'a second worker finds the record claimed' );
        Agentshell_Test::assert_equals( 'claimed', asn3r( $p, 'claim', 'busy_status' ), 'the record is left with its owner' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'claim', 'busy_mails' ), 'a second worker sends nothing' );
    }
);

Agentshell_Test::run(
    'an abandoned claim is taken over and settled',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'claim', 'stale_outcome' ), 'an expired claim does not wedge the record forever' );
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'claim', 'stale_status' ), 'the takeover produces a settled record' );
        Agentshell_Test::assert_equals( 2, asn3r( $p, 'claim', 'stale_attempts' ), 'the takeover counts as the next attempt' );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'claim', 'stale_mails' ), 'the takeover sends exactly one message' );
    }
);

Agentshell_Test::run(
    'the reconciler marks an expired claim uncertain without sending',
    function () use ( $p ) {
        Agentshell_Test::assert_true( (int) asn3r( $p, 'reconcile', 'first' ) >= 1, 'the reconciler finds the abandoned claim' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'reconcile', 'second' ), 'a second pass has nothing to do' );
        Agentshell_Test::assert_equals( 'uncertain', asn3r( $p, 'reconcile', 'status' ), 'the outcome is admitted as unknown' );
        Agentshell_Test::assert_equals( 'claim_expired', asn3r( $p, 'reconcile', 'error' ), 'the reason is recorded' );
        Agentshell_Test::assert_equals( null, asn3r( $p, 'reconcile', 'claimed_at' ), 'the claim is released' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'reconcile', 'mails' ), 'reconciliation sends nothing' );
    }
);

// ══ time and money ═══════════════════════════════════════════════════════════

Agentshell_Test::run(
    'an attempt that outlives its budget is uncertain, not accepted',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'uncertain', asn3r( $p, 'budget', 'outcome' ), 'the outcome is not trusted' );
        Agentshell_Test::assert_equals( 'uncertain', asn3r( $p, 'budget', 'status' ), 'the record says uncertain' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) asn3r( $p, 'budget', 'error' ), 'over_budget' ),
            'the reason names the budget'
        );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'budget', 'mails' ), 'the message was handed over before the budget ran out' );
        Agentshell_Test::assert_true( asn3r( $p, 'budget', 'has_next' ), 'an uncertain record is retried later' );
    }
);

// ══ a broken transport never breaks the submission ══════════════════════════

Agentshell_Test::run(
    'a transport that throws is a failed attempt, and creates nothing',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'failed', asn3r( $p, 'throw', 'outcome' ), 'the throw is caught and recorded as a failure' );
        Agentshell_Test::assert_equals( 'failed', asn3r( $p, 'throw', 'status' ), 'the record says failed' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) asn3r( $p, 'throw', 'error' ), 'transport exploded' ),
            'the transport’s own message is kept for the operator'
        );
        Agentshell_Test::assert_equals(
            (int) asn3r( $p, 'budget', 'entries' ) + 1,
            asn3r( $p, 'throw', 'entries' ),
            'the throwing attempt added no submission beyond its own fixture'
        );
    }
);

Agentshell_Test::run(
    'notifications switched off after the submission are closed, not retried',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'exhausted', asn3r( $p, 'disabled_later', 'outcome' ), 'the record is closed' );
        Agentshell_Test::assert_equals( 'notifications_disabled', asn3r( $p, 'disabled_later', 'error' ), 'the reason is the operator’s decision' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'disabled_later', 'mails' ), 'nothing is sent for a form that no longer asks' );
    }
);

// ══ the confirmation ═════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the confirmation is a second, independent message to the submitter',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'confirmation', 'admin_outcome' ), 'the administrator message is accepted' );
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'confirmation', 'conf_outcome' ), 'the submitter message is accepted' );
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'confirmation', 'admin_status' ), 'the administrator record is settled' );
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'confirmation', 'conf_status' ), 'the confirmation record is settled' );
        Agentshell_Test::assert_equals( 2, asn3r( $p, 'confirmation', 'mails' ), 'two messages are handed over' );

        $to = (string) asn3r( $p, 'confirmation', 'to' );
        Agentshell_Test::assert_true( false !== strpos( $to, (string) asn3r( $p, 'confirmation', 'admin_email' ) ), 'one goes to the site’s administration address' );
        Agentshell_Test::assert_true( false !== strpos( $to, 'test@example.invalid' ), 'one goes to the submitter' );

        $subjects = implode( '|', (array) asn3r( $p, 'confirmation', 'subjects' ) );
        Agentshell_Test::assert_true( false !== strpos( $subjects, 'New submission' ), 'the administrator subject is used for the administrator' );
        Agentshell_Test::assert_true( false !== strpos( $subjects, 'We received your message' ), 'the confirmation subject is used for the submitter' );
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'confirmation', 'second_mails' ), 'dispatching twice sends nothing the second time' );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'confirmation', 'entries' ), 'the dispatch never creates a submission' );
    }
);

Agentshell_Test::run(
    'a confirmation with no email field is skipped and audited, not faked',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 'skipped', asn3r( $p, 'confirmation_skipped', 'outcome' ), 'the confirmation is skipped' );
        Agentshell_Test::assert_equals( 'no_recipient', asn3r( $p, 'confirmation_skipped', 'reason' ), 'the reason is that there is nobody to send to' );
        Agentshell_Test::assert_equals( null, asn3r( $p, 'confirmation_skipped', 'record' ), 'no record pretends a confirmation exists' );
        Agentshell_Test::assert_true( asn3r( $p, 'confirmation_skipped', 'audited' ), 'the skip is in the audit log' );
    }
);

// ══ the retry worker ═════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the retry worker finds due records and sends both kinds',
    function () use ( $p ) {
        Agentshell_Test::assert_true( (int) asn3r( $p, 'run_due', 'admin_attempted' ) >= 1, 'the worker attempts the due administrator message' );
        Agentshell_Test::assert_true( (int) asn3r( $p, 'run_due', 'conf_attempted' ) >= 1, 'the worker attempts the due confirmation' );

        $outcomes = (array) asn3r( $p, 'run_due', 'admin_outcomes' );
        Agentshell_Test::assert_true( isset( $outcomes['accepted'] ), 'the due administrator message is accepted' );

        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'run_due', 'admin_status' ), 'the record is settled' );
        Agentshell_Test::assert_equals( 'accepted', asn3r( $p, 'run_due', 'conf_status' ), 'the confirmation is settled' );
        Agentshell_Test::assert_equals( 2, asn3r( $p, 'run_due', 'mails' ), 'the worker sends exactly the two due messages' );
        Agentshell_Test::assert_equals( 1, asn3r( $p, 'run_due', 'entries' ), 'the worker never creates a submission' );
    }
);

Agentshell_Test::run(
    'a notification is sent under the settings it was accepted with',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asn3r( $p, 'republished', 'sent_to_a' ), 'the recipient configured when the submission was accepted still receives it' );
        Agentshell_Test::assert_false( asn3r( $p, 'republished', 'sent_to_b' ), 'a recipient added by a later publication does not receive an old submission' );
    }
);

Agentshell_Test::run(
    'the retry worker is not starved by settled records',
    function () use ( $p ) {
        $probe = (array) asn3r( $p, 'starvation', 'settled' );
        // The fixture created 2001 settled records ahead of the pending one.
        Agentshell_Test::assert_true( asn3r( $p, 'starvation', 'settled' ) >= 2000, 'the fixture must place thousands of settled records ahead of the pending one' );

        $statuses = (array) asn3r( $p, 'starvation', 'statuses' );

        // The first run must not process the pending record: it is behind the
        // settled ones, and a fixed window would stop there forever.
        Agentshell_Test::assert_equals(
            'pending',
            (string) ( $statuses[0] ?? '' ),
            'the first run does not reach the pending record: the window rotates rather than restarting'
        );
        Agentshell_Test::assert_true(
            asn3r( $p, 'starvation', 'processed' ),
            'the pending record is processed within a bounded number of runs'
        );
    }
);

Agentshell_Test::run(
    'the suite left nothing behind',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, asn3r( $p, 'cleanup', 'remaining' ), 'no submission may outlive the suite' );
    }
);

Agentshell_Test::summary();
