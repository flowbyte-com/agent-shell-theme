<?php
/**
 * Retention and export against a real WordPress.
 *
 * Two things a forms system must get right and cannot prove from a stub:
 *
 *   1. **A retention period deletes.** Not trashes, not hides — deletes. The
 *      suite backdates real entries, runs the purge, and checks which rows are
 *      still there. It also checks the two cases that are easy to miss: a form
 *      that declares no period inherits the site default, and an entry whose
 *      form is gone is still purged (nothing else will ever apply a period to
 *      it).
 *
 *   2. **An export cannot attack the operator.** The suite stores values that
 *      begin `=`, `+`, `-`, `@` and a tab, then reads the produced CSV and
 *      asserts every one of them carries the text prefix and none of them is a
 *      bare spreadsheet formula; it checks RFC 4180 quoting for commas, quotes
 *      and newlines; and it checks the JSON export is unprefixed data.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms retention and export (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();

if ( null === $config ) {
    Agentshell_Test::run(
        'forms retention suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-retention-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-retention-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms retention: the child reported',
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
function asr3p( array $probes, $probe, $key ) {
    if ( ! isset( $probes[ $probe ] ) ) {
        throw new Exception( "the child did not report the probe \"{$probe}\"" );
    }

    if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
        throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
    }

    return $probes[ $probe ][ $key ];
}

// ══ retention ════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'a form’s own retention period is the one that applies',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 30, asr3p( $p, 'retention', 'site_default' ), 'the fixture sets a site default of thirty days' );
        Agentshell_Test::assert_false(
            asr3p( $p, 'retention', 'strict_old_alive' ),
            'an entry past the form’s own period must be gone'
        );
        Agentshell_Test::assert_true(
            asr3p( $p, 'retention', 'strict_recent_alive' ),
            'an entry inside the form’s own period must survive'
        );
    }
);

Agentshell_Test::run(
    'a form with no period of its own inherits the site default',
    function () use ( $p ) {
        Agentshell_Test::assert_false(
            asr3p( $p, 'retention', 'inherit_old_alive' ),
            'an entry past the site default must be gone when the form declares none'
        );
        Agentshell_Test::assert_true(
            asr3p( $p, 'retention', 'inherit_recent_alive' ),
            'an entry inside the site default must survive when the form declares none'
        );
    }
);

Agentshell_Test::run(
    'retention is a hard delete, and the trash is not an exception',
    function () use ( $p ) {
        Agentshell_Test::assert_true(
            asr3p( $p, 'retention', 'trashed_purged' ),
            'a trashed entry past its period is deleted, not kept recoverable'
        );
        // At least three: a bed shared with an interrupted run may hold an
        // orphan of its own, and the purge removes that too. Which entries were
        // deleted is asserted by the aliveness checks above and the per-form
        // details below, not by the total.
        Agentshell_Test::assert_true( asr3p( $p, 'retention', 'deleted' ) >= 3, 'the three past-period entries were deleted' );
        Agentshell_Test::assert_equals( 0, asr3p( $p, 'retention', 'failed' ), 'every deletion succeeded' );
    }
);

Agentshell_Test::run(
    'the purge reports what it did, per form, and audits it',
    function () use ( $p ) {
        $details = (array) asr3p( $p, 'retention', 'details' );

        $by_days = array();

        foreach ( $details as $detail ) {
            // The last detail for a period wins; a bed with residue may report
            // more than one row per period, and the assertions below are about
            // the two forms this fixture created.
            $by_days[ (int) $detail['days'] ] = $detail;
        }

        Agentshell_Test::assert_true( isset( $by_days[1] ), 'the one-day form is reported' );
        Agentshell_Test::assert_equals( 2, (int) $by_days[1]['deleted'], 'the one-day form lost two entries' );
        Agentshell_Test::assert_true( isset( $by_days[30] ), 'the inherited thirty-day period is reported' );
        Agentshell_Test::assert_equals( 1, (int) $by_days[30]['deleted'], 'the inherited period lost one entry' );

        Agentshell_Test::assert_true( asr3p( $p, 'retention', 'audited' ) >= 2, 'one audit row per form is written' );
    }
);

Agentshell_Test::run(
    'a second purge finds nothing to do',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, asr3p( $p, 'retention', 'second_deleted' ), 'the purge is idempotent' );
        Agentshell_Test::assert_equals( 0, asr3p( $p, 'retention', 'second_failed' ), 'a second run fails nothing' );
    }
);

Agentshell_Test::run(
    'an entry whose form is gone is still purged, on the site default',
    function () use ( $p ) {
        Agentshell_Test::assert_false(
            asr3p( $p, 'orphan', 'alive' ),
            'nothing else will ever apply a retention period to an entry with no form'
        );
        Agentshell_Test::assert_true( asr3p( $p, 'orphan', 'run_deleted' ) >= 1, 'the orphan is deleted by the site default' );

        $detail = asr3p( $p, 'orphan', 'detail' );

        Agentshell_Test::assert_true( is_array( $detail ), 'the orphan is reported under its own form identifier' );
        Agentshell_Test::assert_equals( 1, (int) $detail['deleted'], 'the orphan is the entry that was deleted' );
        Agentshell_Test::assert_equals( 'unknown_form', (string) $detail['reason'], 'the report says why it was purged' );
        Agentshell_Test::assert_equals( 30, (int) $detail['days'], 'it is purged on the site default, not a form period' );
        Agentshell_Test::assert_true( asr3p( $p, 'orphan', 'audited' ), 'the orphan purge is audited' );
    }
);

// ══ export ═══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the export is produced only for an authorised actor',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr3p( $p, 'export', 'granted' ), 'the fixture must hold submission access to export' );
        Agentshell_Test::assert_true( asr3p( $p, 'export', 'csv_is_array' ), 'the CSV must be produced' );
        Agentshell_Test::assert_equals( 'text/csv', asr3p( $p, 'export', 'csv_type' ), 'the CSV is served as CSV' );
        Agentshell_Test::assert_equals( 8, asr3p( $p, 'export', 'csv_rows' ), 'every stored submission is exported' );
    }
);

Agentshell_Test::run(
    'a spreadsheet formula in a submitted value cannot execute',
    function () use ( $p ) {
        $detail = (array) asr3p( $p, 'export', 'prefix_detail' );

        Agentshell_Test::assert_equals( 5, count( $detail ), 'five submitted values begin with a formula character' );

        foreach ( $detail as $row ) {
            Agentshell_Test::assert_true(
                (bool) $row['prefixed'],
                'the value ' . wp_json_encode( (string) $row['value'] ) . ' must carry the text prefix'
            );
            Agentshell_Test::assert_false(
                (bool) $row['raw_cell'],
                'the value ' . wp_json_encode( (string) $row['value'] ) . ' must never appear as a bare formula cell'
            );
        }

        Agentshell_Test::assert_equals( 0, asr3p( $p, 'export', 'unsafe_cells' ), 'no exported cell is an executable formula' );
    }
);

Agentshell_Test::run(
    'commas, quotes and newlines stay inside their own cell',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr3p( $p, 'export', 'comma_quoted' ), 'a comma is quoted, not treated as a separator' );
        Agentshell_Test::assert_true( asr3p( $p, 'export', 'quote_doubled' ), 'a quote is doubled, as RFC 4180 requires' );
        Agentshell_Test::assert_true( asr3p( $p, 'export', 'newline_quoted' ), 'a newline is kept inside one quoted cell' );
    }
);

Agentshell_Test::run(
    'the CSV header is the metadata columns followed by the form’s field labels',
    function () use ( $p ) {
        $header = (string) asr3p( $p, 'export', 'header' );

        foreach ( array( 'ID', 'Received (UTC)', 'Form version', 'Spam status', 'Notification', 'Name', 'Message' ) as $column ) {
            Agentshell_Test::assert_true(
                false !== strpos( $header, '"' . $column . '"' ),
                "the header must name the \"{$column}\" column"
            );
        }
    }
);

Agentshell_Test::run(
    'the JSON export is data, not a program, and is not prefixed',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr3p( $p, 'export', 'json_decoded' ), 'the JSON export must decode' );
        Agentshell_Test::assert_equals( 8, asr3p( $p, 'export', 'json_count' ), 'the JSON export carries every submission' );

        $values = (array) asr3p( $p, 'export', 'json_values' );

        Agentshell_Test::assert_true(
            in_array( '=HYPERLINK("http://evil.example","click")', $values, true ),
            'the JSON keeps the value exactly as submitted'
        );

        foreach ( $values as $value ) {
            Agentshell_Test::assert_false(
                0 === strpos( (string) $value, "'=" ),
                'JSON is not executed, so it is not prefixed'
            );
        }

        $keys = (array) asr3p( $p, 'export', 'json_keys' );

        foreach ( array( 'form_id', 'columns', 'records', 'count', 'exported_at' ) as $key ) {
            Agentshell_Test::assert_true( in_array( $key, $keys, true ), "the JSON export carries \"{$key}\"" );
        }
    }
);

// ══ cleanup ══════════════════════════════════════════════════════════════════

Agentshell_Test::run(
    'the suite left nothing behind',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, asr3p( $p, 'cleanup', 'remaining' ), 'no submission may outlive the suite' );
    }
);

Agentshell_Test::summary();
