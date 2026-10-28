<?php
/**
 * The WordPress privacy exporter and eraser, against a real WordPress.
 *
 * Registered through WordPress's own registry, so a subject access request
 * handled in **Settings → Privacy** answers correctly without the operator
 * remembering that this plugin holds data too.
 *
 * The claims the assertions pin:
 *
 *   - every submission that holds the address is exported, across forms;
 *   - the match is the whole address, so one person's request does not
 *     disclose another person's data (a note that merely *mentions* the
 *     address is not matched — the safe direction, and stated as a limit);
 *   - erasure is a hard delete: the rows are absent, not in the trash;
 *   - everything not asked about survives;
 *   - running the eraser again is safe and reports that there was nothing to do;
 *   - both operations are audited.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms privacy (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms privacy suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-privacy-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-privacy-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms privacy: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

$p = isset( $f['probes'] ) && is_array( $f['probes'] ) ? $f['probes'] : array();

/**
 * One probe's field, with an actionable failure when the child did not report.
 *
 * @param array  $probes All probes.
 * @param string $probe  Probe name.
 * @param string $key    Field.
 * @return mixed
 */
function asp3r( array $probes, $probe, $key ) {
    if ( ! isset( $probes[ $probe ] ) ) {
        throw new Exception( "the child did not report the probe \"{$probe}\"" );
    }

    if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
        throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
    }

    return $probes[ $probe ][ $key ];
}

// ══ export ═══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a subject-access request finds the address across every form',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 2, asp3r( $p, 'export', 'count' ), 'both submissions holding the address are exported' );
        Agentshell_Test::assert_true( asp3r( $p, 'export', 'has_matching_email' ), 'the submission naming the address in its email field is found' );
        Agentshell_Test::assert_true( asp3r( $p, 'export', 'has_matching_form_b' ), 'a submission of another form is found too' );
        Agentshell_Test::assert_true( asp3r( $p, 'export', 'done' ), 'the export reports it is complete' );
    }
);

Agentshell_Test::run(
    'the exported record carries the form, its version and the submitted values',
    function () use ( $p ) {
        $values = implode( "\n", (array) asp3r( $p, 'export', 'values' ) );

        foreach ( array( 'Form=', 'Form identifier=', 'Form version=', 'Received at (UTC)=', 'name=Subject', 'email=subject@example.invalid' ) as $needle ) {
            Agentshell_Test::assert_true( false !== strpos( $values, $needle ), "the export must carry \"{$needle}\"" );
        }

        Agentshell_Test::assert_equals(
            array( 'agentshell-forms' ),
            (array) asp3r( $p, 'export', 'group_ids' ),
            'every item belongs to one group WordPress can present together'
        );
    }
);

Agentshell_Test::run(
    'one person’s request does not disclose another person’s data',
    function () use ( $p ) {
        Agentshell_Test::assert_false( asp3r( $p, 'export', 'has_other' ), 'a different address is not exported' );
        Agentshell_Test::assert_false(
            asp3r( $p, 'export', 'has_longer' ),
            'an address that merely begins with the requested one is not exported'
        );
        Agentshell_Test::assert_false( asp3r( $p, 'export', 'has_none' ), 'a submission with no address is not exported' );

        // The safe direction of the limitation, stated rather than hidden: a
        // free-text value that merely *mentions* the address is not matched,
        // because over-inclusion discloses a third party's words.
        Agentshell_Test::assert_false(
            asp3r( $p, 'export', 'has_note_mention' ),
            'matching is whole-value; a note that mentions the address is not disclosed'
        );
    }
);

Agentshell_Test::run(
    'an address the site holds nothing about exports nothing',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, asp3r( $p, 'export', 'unknown_count' ), 'an unknown address yields no items' );
        Agentshell_Test::assert_true( asp3r( $p, 'export', 'unknown_done' ), 'and the request still completes' );
    }
);

// ══ erasure ══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'an erasure request hard-deletes exactly the matching submissions',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asp3r( $p, 'erase', 'removed' ), 'the erasure reports that it removed something' );
        Agentshell_Test::assert_false( asp3r( $p, 'erase', 'retained' ), 'nothing was retained' );
        Agentshell_Test::assert_true( asp3r( $p, 'erase', 'done' ), 'the request is complete' );
        Agentshell_Test::assert_equals( array(), (array) asp3r( $p, 'erase', 'messages' ), 'no failure message is reported' );

        $gone = (array) asp3r( $p, 'erase', 'gone' );

        Agentshell_Test::assert_true( $gone['matching_email'], 'the matching submission is gone' );
        Agentshell_Test::assert_true( $gone['matching_form_b'], 'the matching submission of the other form is gone' );
    }
);

Agentshell_Test::run(
    'erasure is a delete, never a move to the trash',
    function () use ( $p ) {
        Agentshell_Test::assert_equals(
            0,
            asp3r( $p, 'erase', 'trashed' ),
            'a trashed submission would still hold the personal data, which is not erasure'
        );
    }
);

Agentshell_Test::run(
    'an erasure does not touch anybody else’s submission',
    function () use ( $p ) {
        $gone = (array) asp3r( $p, 'erase', 'gone' );

        Agentshell_Test::assert_false( $gone['other'], 'a different address survives' );
        Agentshell_Test::assert_false( $gone['longer'], 'a longer address survives' );
        Agentshell_Test::assert_false( $gone['none'], 'a submission with no address survives' );
        Agentshell_Test::assert_false( $gone['note_mention'], 'a note that mentions the address survives' );
    }
);

Agentshell_Test::run(
    'a repeated erasure is safe and reports nothing to do',
    function () use ( $p ) {
        Agentshell_Test::assert_false( asp3r( $p, 'erase', 'again_removed' ), 'the second request removes nothing' );
        Agentshell_Test::assert_true( asp3r( $p, 'erase', 'again_done' ), 'and still completes' );
    }
);

Agentshell_Test::run(
    'both privacy operations are audited',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asp3r( $p, 'erase', 'audited_export' ), 'the export is in the audit log' );
        Agentshell_Test::assert_true( asp3r( $p, 'erase', 'audited_erase' ), 'the erasure is in the audit log' );
    }
);

Agentshell_Test::run(
    'the suite left nothing behind',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, asp3r( $p, 'cleanup', 'remaining' ), 'no submission may outlive the suite' );
    }
);

Agentshell_Test::summary();
