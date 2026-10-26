<?php
/**
 * Live rollback-evidence regressions, run against a real WordPress install.
 *
 * The question: after a transaction has been rolled back — and its row
 * deleted, which is what a rollback is — can an operator still find out
 * what happened?
 *
 * The answer is yes, through the audit log, and this suite proves it
 * rather than asserting it: it rolls a transaction back, shows that the
 * transaction is gone, and then finds the outcome in the log — by
 * operation, and by transaction id.
 *
 * It also proves the two things the log must *not* do: keep the content
 * body that was compensated, and keep anything that looks like a
 * credential.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is
 * safe to run anywhere.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live rollback evidence (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    // A skip, declared as one. It used to be an assertion that
    // passed with 'skipped:' in its message, which is a skip
    // counted as a pass.
    Agentshell_Test::skip( 'no test-bed config (destructive suites require tests/config/test-bed.json)' );
    return;
}

$report_file = sys_get_temp_dir() . '/as-re-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/rollback-evidence-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live rollback-evidence child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['re_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['re_f'] = $f;

function re_f( $key, $default = null ) {
    $f = $GLOBALS['re_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'the rollback happened',
    function () {
        Agentshell_Test::assert_true( (bool) re_f( 'staged' ), 'the transaction must have staged something' );
        Agentshell_Test::assert_true( (bool) re_f( 'rolled_back' ), 'the rollback must have succeeded' );
    }
);

Agentshell_Test::run(
    'the transaction row is gone afterwards',
    function () {
        Agentshell_Test::assert_equals(
            'absent',
            re_f( 'transaction_after_rollback' ),
            'a rolled-back transaction must leave no row behind'
        );
    }
);

Agentshell_Test::run(
    'the outcome is still inspectable in the audit log',
    function () {
        Agentshell_Test::assert_true(
            (bool) re_f( 'rollback_entry_found' ),
            'the audit log must still hold the rollback'
        );
        $entry = (array) re_f( 'rollback_entry', array() );
        $changed = isset( $entry['changed'] ) && is_array( $entry['changed'] ) ? $entry['changed'] : array();
        Agentshell_Test::assert_equals( re_f( 'tx_id' ), (string) ( $changed['transaction_id'] ?? '' ) );
        Agentshell_Test::assert_true(
            ! empty( $changed['discarded_staged_changes'] ),
            'the entry must say that staged changes were discarded'
        );
    }
);

Agentshell_Test::run(
    'the entry can be found by transaction id as well as by operation',
    function () {
        Agentshell_Test::assert_true(
            (int) re_f( 'entries_by_transaction_id' ) >= 1,
            'the transaction id filter must find the entry'
        );
        Agentshell_Test::assert_equals(
            (int) re_f( 'entries_by_transaction_id' ),
            (int) re_f( 'total_by_transaction_id' )
        );
    }
);

Agentshell_Test::run(
    'a rollback after compensation is recorded as such',
    function () {
        Agentshell_Test::assert_true( (bool) re_f( 'rolled_back_2' ), 'the compensated rollback must have succeeded' );
        Agentshell_Test::assert_true( (bool) re_f( 'draft_gone' ), 'the compensated draft must be gone' );
        Agentshell_Test::assert_true(
            (bool) re_f( 'compensation_entry_found' ),
            'the audit log must record the compensated rollback'
        );
    }
);

Agentshell_Test::run(
    'a draft with no slug is still compensatable',
    function () {
        // WordPress leaves a draft's slug empty, so the journal's
        // after-image recorded an empty post_name. The validator used to
        // refuse such a journal outright, which meant the recovery engine
        // could not interpret it, the rollback could not compensate the
        // draft, and the draft survived the rollback with the record of the
        // debt discarded. This is the regression that proves it is fixed.
        Agentshell_Test::assert_equals(
            '',
            (string) re_f( 'draft_slug' ),
            'the draft must be one without a slug, or this proves nothing'
        );
        Agentshell_Test::assert_true( (bool) re_f( 'draft_gone' ), 'the draft must be gone after the rollback' );
    }
);

Agentshell_Test::run(
    'the evidence carries no content body',
    function () {
        $json = (string) re_f( 'entry_has_no_body' );
        Agentshell_Test::assert_true(
            '1' === $json || true === $json,
            'the check must have run'
        );
        $entry_json = (string) re_f( 'compensation_entry_json', '' );
        Agentshell_Test::assert_true(
            false === strpos( $entry_json, 'ASre body' ),
            'the audit entry must not carry the content body: ' . substr( $entry_json, 0, 200 )
        );
    }
);

Agentshell_Test::run(
    'the evidence carries no credential',
    function () {
        foreach ( array( 'rollback_entry_json', 'compensation_entry_json' ) as $key ) {
            $json = strtolower( (string) re_f( $key, '' ) );
            foreach ( array( 'password', 'secret', 'authorization', 'app_password', 'token' ) as $needle ) {
                Agentshell_Test::assert_true(
                    false === strpos( $json, $needle ),
                    'the audit entry must not carry a credential (' . $needle . '): ' . substr( $json, 0, 200 )
                );
            }
        }
    }
);
