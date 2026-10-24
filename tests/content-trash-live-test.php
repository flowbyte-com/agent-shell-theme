<?php
/**
 * Live content-trash regressions, run against a real WordPress install.
 *
 * Deletion is the one content operation an agent can lose data with, so
 * this suite is mostly about the edges: a caller who may not delete the
 * post, a call inside a transaction, a call for something that is not
 * there, and the same call twice.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is
 * safe to run anywhere.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live content trash (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live content-trash suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-ct-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/content-trash-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live content-trash child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['ct_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['ct_f'] = $f;

function ct_f( $key, $default = null ) {
    $f = $GLOBALS['ct_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'trashing moves the content to the Trash without deleting it',
    function () {
        Agentshell_Test::assert_equals( 'trash', ct_f( 'status_after_trash' ) );
        Agentshell_Test::assert_true( (bool) ct_f( 'row_survived' ), 'the row must survive the trash' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ct_f( 'body_after_trash' ), 'AStr body' ),
            'the body must survive the trash: ' . (string) ct_f( 'body_after_trash' )
        );
        Agentshell_Test::assert_equals( 'publish', ct_f( 'previous_status_reported' ) );
    }
);

Agentshell_Test::run(
    'restoring with status=previous puts it back as it was',
    function () {
        Agentshell_Test::assert_equals( 'publish', ct_f( 'status_after_restore' ) );
        Agentshell_Test::assert_equals( 'publish', ct_f( 'restored_as_reported' ) );
    }
);

Agentshell_Test::run(
    'a default restore comes back as a draft, as WordPress does',
    function () {
        Agentshell_Test::assert_equals( 'draft', ct_f( 'status_after_default_restore' ) );
    }
);

Agentshell_Test::run(
    'restoring brings the content back',
    function () {
        Agentshell_Test::assert_true(
            false !== strpos( (string) ct_f( 'body_after_restore' ), 'AStr body' ),
            'the body must survive the restore'
        );
    }
);

Agentshell_Test::run(
    'the operations are idempotent',
    function () {
        Agentshell_Test::assert_true( (bool) ct_f( 'second_trash_already' ), 'a second trash must report that it was already trashed' );
        Agentshell_Test::assert_true( (bool) ct_f( 'third_trash_already' ) );
        Agentshell_Test::assert_equals( 'trash', ct_f( 'status_after_repeat' ) );
        Agentshell_Test::assert_true( (bool) ct_f( 'restore_already' ), 'restoring something that is not trashed must report it' );
        Agentshell_Test::assert_equals( 'draft', ct_f( 'status_after_restore_again' ) );
    }
);

Agentshell_Test::run(
    'a caller without the delete_post capability is refused, and the content is unchanged',
    function () {
        $refusal = (string) ct_f( 'subscriber_refusal' );
        Agentshell_Test::assert_true(
            'ACCEPTED' !== $refusal,
            'a subscriber must not be able to trash a page: ' . $refusal
        );
        Agentshell_Test::assert_true(
            false !== stripos( $refusal, 'delete_' ),
            'the refusal must name the capability: ' . $refusal
        );
        Agentshell_Test::assert_true( (bool) ct_f( 'unchanged_after_subscriber' ) );
    }
);

Agentshell_Test::run(
    'a call inside an open transaction is refused, and the content is unchanged',
    function () {
        Agentshell_Test::assert_true( (bool) ct_f( 'tx_opened' ) );
        $refusal = (string) ct_f( 'tx_refusal' );
        Agentshell_Test::assert_true(
            'ACCEPTED' !== $refusal,
            'the call inside a transaction must be refused: ' . $refusal
        );
        Agentshell_Test::assert_true(
            false !== stripos( $refusal, 'transaction' ),
            'the refusal must say why: ' . $refusal
        );
        Agentshell_Test::assert_true( (bool) ct_f( 'unchanged_after_tx_refusal' ) );
    }
);

Agentshell_Test::run(
    'content that does not exist is refused',
    function () {
        $refusal = (string) ct_f( 'missing_refusal' );
        Agentshell_Test::assert_true( 'ACCEPTED' !== $refusal, 'a missing post must be refused' );
        Agentshell_Test::assert_true(
            false !== stripos( $refusal, 'not found' ),
            'the refusal must say the content is not there: ' . $refusal
        );
    }
);

Agentshell_Test::run(
    'the audit log records the trash and the restore',
    function () {
        Agentshell_Test::assert_true( (bool) ct_f( 'audit_has_trashed' ), 'the trash must be audited' );
        Agentshell_Test::assert_true( (bool) ct_f( 'audit_has_restored' ), 'the restore must be audited' );
    }
);

Agentshell_Test::run(
    'the suite cleans up after itself',
    function () {
        Agentshell_Test::assert_true( (bool) ct_f( 'cleanup_done' ), 'the disposable page must be gone' );
    }
);
