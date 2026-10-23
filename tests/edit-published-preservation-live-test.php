<?php
/**
 * Parent test for the published-content field-preservation regression
 * (HAL 9001 remediation, defect D2).
 *
 * Spawns the live child against the disposable test bed and asserts the
 * structured results. Skips cleanly when no test bed is configured.
 *
 * The scenarios are documented in the child file's header.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live edit-published field preservation (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    echo "  SKIPPED: no test-bed config (destructive suites require tests/config/test-bed.json)\n";
    Agentshell_Test::run(
        'live edit-published preservation suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child       = __DIR__ . '/edit-published-preservation-live-child.php';
$report_file = sys_get_temp_dir() . '/as-epp-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    $child,
    array( $config['wp_root'], $report_file )
);

echo $run['output'];

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );
if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live edit-published preservation: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['epp_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['epp_f'] = $f;

function epp_f( $key, $default = null ) {
    $f = $GLOBALS['epp_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

/**
 * The refusal a scenario recorded, or an empty string when it did not
 * refuse. Included in the failure messages so a regression says *why*
 * the scenario refused instead of only that it did.
 */
function epp_err( $key ) {
    return ' [refusal: ' . (string) epp_f( $key, '' ) . ']';
}

Agentshell_Test::run(
    'live edit-published field preservation',
    function () {
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p0_clean' ),
            'P0: the transaction row must be empty at child start'
        );

        // ── P1: a content-only edit preserves everything else ────────
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_after_title_is_original' ),
            'P1.1 the staged after-image must carry the published title, not an empty string'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_after_excerpt_is_original' ),
            'P1.2 the staged after-image must carry the published excerpt'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_after_name_is_original' ),
            'P1.3 the staged after-image must carry the published slug'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_after_content_is_new' ),
            'P1.4 the staged after-image must carry the new body'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_staged_fields_is_content' ),
            'P1.5 the authorised change set must name exactly post_content'
        );
        Agentshell_Test::assert_true(
            (int) epp_f( 'p1_result_inherited_count' ) === 3,
            'P1.6 the result must report the three inherited fields'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_shadow_title_is_original' ),
            'P1.7 the shadow must carry the inherited title'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_shadow_excerpt_is_original' ),
            'P1.8 the shadow must carry the inherited excerpt'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_shadow_staged_name' ),
            'P1.9 the shadow must carry the inherited slug'
        );
        Agentshell_Test::assert_equals(
            'publication_settled',
            (string) epp_f( 'p1_publish_state' ),
            'P1.10 the publish must settle'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_live_title_preserved' ),
            'P1.11 a content-only publish must not erase the published title'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_live_excerpt_preserved' ),
            'P1.12 a content-only publish must not erase the published excerpt'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_live_slug_preserved' ),
            'P1.13 a content-only publish must not erase the published slug'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p1_live_content_changed' ),
            'P1.14 a content-only publish must apply the new body'
        );

        // ── P2: an excerpt-only edit preserves the rest ──────────────
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p2_live_title_preserved' ),
            'P2.1 an excerpt-only publish must not change the title'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p2_live_slug_preserved' ),
            'P2.2 an excerpt-only publish must not change the slug'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p2_live_content_preserved' ),
            'P2.3 an excerpt-only publish must not change the body'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p2_live_excerpt_changed' ),
            'P2.4 an excerpt-only publish must change the excerpt'
        );

        // ── P3: an explicit empty excerpt is honoured ─────────────────
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p3_live_excerpt_cleared' ),
            'P3.1 an explicitly empty excerpt must clear the excerpt'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p3_live_title_preserved' ),
            'P3.2 clearing the excerpt must not change the title'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p3_live_slug_preserved' ),
            'P3.3 clearing the excerpt must not change the slug'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p3_live_content_preserved' ),
            'P3.4 clearing the excerpt must not change the body'
        );

        // ── P4/P5/P6: refusals ───────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p4_threw' ),
            'P4.1 an explicitly empty title must be refused'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p4_refused' ),
            'P4.2 the refusal must name the empty title'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p5_threw' ),
            'P5.1 an explicitly empty body must be refused'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p5_refused' ),
            'P5.2 the refusal must name the empty body'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p6_threw' ),
            'P6.1 a call that names no field must be refused'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p6_refused' ),
            'P6.2 the refusal must name the no-op'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) epp_f( 'p4_journal_ops' ),
            'P6.3 a refused call must not stage anything'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p4_orig_title_untouched' ),
            'P6.4 a refused call must not touch the original'
        );

        // ── P7: head advancement inherits the shadow's values ────────
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p7_advanced' ),
            'P7.1 the second edit must advance the head'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p7_shadow_content_kept' ),
            'P7.2 the second edit must not drop the first edit\'s staged body'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p7_shadow_title_new' ),
            'P7.3 the second edit must apply its own title'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p7_union_has_content' ),
            'P7.4 the authorised change set must accumulate across edits'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p7_union_has_title' ),
            'P7.5 the authorised change set must name the new field too'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p7_live_title_new' ),
            'P7.6 the publish must apply the second edit\'s title'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p7_live_content_new' ),
            'P7.7 the publish must apply the first edit\'s body'
        );

        // ── P8: the publish step refuses an unstaged-field drift ────
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p8_threw' ),
            'P8.1 a publish that would change an unauthorised field must be refused'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p8_refused' ),
            'P8.2 the refusal must name the unstaged field or the shadow mismatch' . epp_err( 'p8_publish_error' )
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p8_orig_title_unchanged' ),
            'P8.3 a refused publish must leave the original alone'
        );

        // ── no scenario may refuse unexpectedly ──────────────────────
        foreach ( array(
            'p1_staging_error'  => 'P1 staging',
            'p1_publish_error'  => 'P1 publish',
            'p2_staging_error'  => 'P2 staging',
            'p2_publish_error'  => 'P2 publish',
            'p3_staging_error'  => 'P3 staging',
            'p3_publish_error'  => 'P3 publish',
            'p7a_staging_error' => 'P7 first staging',
            'p7b_staging_error' => 'P7 second staging',
            'p7_publish_error'  => 'P7 publish',
            'p8_staging_error'  => 'P8 staging',
            'p9_staging_error'  => 'P9 staging',
        ) as $key => $label ) {
            Agentshell_Test::assert_equals(
                '',
                (string) epp_f( $key, '' ),
                $label . ' must not be refused'
            );
        }

        // ── P9: rollback ─────────────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p9_live_title_unchanged' ),
            'P9.1 a rollback must leave the published title alone'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p9_live_content_unchanged' ),
            'P9.2 a rollback must leave the published body alone'
        );
        Agentshell_Test::assert_true(
            (bool) epp_f( 'p9_shadow_gone' ),
            'P9.3 a rollback must remove the shadow'
        );
    }
);
