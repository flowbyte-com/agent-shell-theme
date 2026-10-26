<?php
/**
 * Stage 2c Milestone 6 — parent test for the new
 * `agentshell_edit_published` MCP tool (Gate 3 Task 5).
 *
 * Spawns the live child against the disposable test bed and
 * asserts the structured results. Skips cleanly when no test
 * bed is configured.
 *
 * The scenarios are documented in the child file's header.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live edit-published (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::skip( 'no test-bed config (destructive suites require tests/config/test-bed.json)\\n' );
    Agentshell_Test::run(
        'live edit-published suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child       = __DIR__ . '/edit-published-live-child.php';
$report_file = sys_get_temp_dir() . '/as-ep-live-' . getmypid() . '.json';
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
        'live edit-published: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['ep_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['ep_f'] = $f;

function ep_f( $key, $default = null ) {
    $f = $GLOBALS['ep_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live edit-published',
    function () {
        // ── F0: precondition ────────────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f0_clean' ),
            'F0: tx option row must be empty at child start'
        );

        // ── F1: fresh staging on a clean ledger ────────────────────────
        Agentshell_Test::assert_equals(
            'staged',
            (string) ep_f( 'f1_state' ),
            'F1.1 fresh staging must return state=staged'
        );
        Agentshell_Test::assert_false(
            (bool) ep_f( 'f1_resumed' ),
            'F1.2 fresh staging must not be a resume'
        );
        Agentshell_Test::assert_false(
            (bool) ep_f( 'f1_advanced' ),
            'F1.3 fresh staging must not be an advance'
        );
        Agentshell_Test::assert_true(
            '' !== (string) ep_f( 'f1_op_id' ),
            'F1.4 fresh staging must return an op id'
        );
        Agentshell_Test::assert_true(
            (int) ep_f( 'f1_shadow_id' ) > 0,
            'F1.5 fresh staging must return a shadow id'
        );
        Agentshell_Test::assert_true(
            (int) ep_f( 'f1_journal_target_shadow_id' ) === (int) ep_f( 'f1_shadow_id' ),
            'F1.6 journal op must carry target.shadow_id matching the shadow'
        );
        Agentshell_Test::assert_true(
            (int) ep_f( 'f1_journal_post_id' ) === (int) ep_f( 'f1_shadow_id' ),
            'F1.7 journal op post_id must be the shadow id'
        );
        Agentshell_Test::assert_equals(
            'staged',
            (string) ep_f( 'f1_journal_state' ),
            'F1.8 journal op must be in staged state on disk'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f1_shadow_exists' ),
            'F1.9 shadow post must exist'
        );
        Agentshell_Test::assert_equals(
            (string) ep_f( 'f1_op_id' ),
            (string) ep_f( 'f1_shadow_op_id' ),
            'F1.10 shadow META_OP_ID must equal the op id'
        );
        Agentshell_Test::assert_equals(
            (string) ( $GLOBALS['ep_f']['f1_op_idem'] ?? '' ),
            (string) ep_f( 'f1_op_idem' ),
            'F1.11 result idempotent.key must round-trip'
        );
        Agentshell_Test::assert_true(
            (string) ep_f( 'f1_claim_holder' ) === (string) ep_f( 'f1_claim_tx' ),
            'F1.12 claim holder must equal the staging tx id'
        );
        Agentshell_Test::assert_equals(
            'Orig Title f1',
            (string) ep_f( 'f1_orig_unchanged_title' ),
            'F1.13 original row must be unchanged after fresh staging'
        );

        // ── F2: refusal — not in tx ────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f2_threw' ),
            'F2.1 must throw when not in a transaction'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f2_refused' ),
            'F2.2 refusal must name not_in_tx'
        );
        Agentshell_Test::assert_equals(
            'Orig Title f2',
            (string) ep_f( 'f2_orig_state' ),
            'F2.3 original must be unchanged after refused staging'
        );

        // ── F3: refusal — bad idempotency key ──────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f3_threw' ),
            'F3.1 must throw on empty idempotency_key'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f3_refused' ),
            'F3.2 refusal must name bad_idempotency_key'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) ep_f( 'f3_journal_op_count' ),
            'F3.3 no journal op must be written on bad-key refusal'
        );

        // ── F4a: refusal — original missing ────────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f4a_threw' ),
            'F4a.1 must throw on missing original'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f4a_refused' ),
            'F4a.2 refusal must name original_gone'
        );

        // ── F4b: refusal — original wrong type ─────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f4b_threw' ),
            'F4b.1 must throw on wrong post_type'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f4b_refused' ),
            'F4b.2 refusal must name original_wrong_type'
        );

        // ── F4c: refusal — original unpublished ────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f4c_threw' ),
            'F4c.1 must throw on non-publish status'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f4c_refused' ),
            'F4c.2 refusal must name original_unpublished'
        );

        // ── F5: refusal — no fields ────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f5_threw' ),
            'F5.1 must throw on no-field call'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f5_refused' ),
            'F5.2 refusal must name no_op'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) ep_f( 'f5_journal_op_count' ),
            'F5.3 no journal op must be written on no-op refusal'
        );
        Agentshell_Test::assert_equals(
            'Orig Title f5',
            (string) ep_f( 'f5_orig_unchanged_title' ),
            'F5.4 original must be unchanged after no-op refusal'
        );

        // ── F6: refusal — different tx holds claim ─────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f6_threw' ),
            'F6.1 must throw when other tx holds the claim'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f6_refused' ),
            'F6.2 refusal must name claim_other_tx'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) ep_f( 'f6_journal_op_count' ),
            'F6.3 no journal op must be written on competing-claim refusal'
        );
        Agentshell_Test::assert_equals(
            'Orig Title f6',
            (string) ep_f( 'f6_orig_unchanged_title' ),
            'F6.4 original must be unchanged after competing-claim refusal'
        );

        // ── F7: retry-resume (same key, same payload) ──────────────────
        Agentshell_Test::assert_false(
            (bool) ep_f( 'f7a_resumed' ),
            'F7.1 first call must not be a resume'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f7b_resumed' ),
            'F7.2 second call with same key + same payload must be flagged as resumed'
        );
        Agentshell_Test::assert_false(
            (bool) ep_f( 'f7b_advanced' ),
            'F7.3 retry-resume must not be an advance'
        );
        Agentshell_Test::assert_equals(
            (string) ep_f( 'f7a_op_id' ),
            (string) ep_f( 'f7b_op_id' ),
            'F7.4 retry-resume must return the same op id'
        );
        Agentshell_Test::assert_equals(
            (int) ep_f( 'f7a_shadow_id' ),
            (int) ep_f( 'f7b_shadow_id' ),
            'F7.5 retry-resume must return the same shadow id (no second shadow)'
        );

        // ── F8: idempotency conflict (same key, different payload) ────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f8_threw' ),
            'F8.1 must throw on same-key-different-payload'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f8_refused' ),
            'F8.2 refusal must name idempotency_conflict'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f8_shadow_unchanged' ),
            'F8.3 shadow post_content must be unchanged on conflict'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f8_op_state_unchanged' ),
            'F8.4 op must remain in staged state on conflict'
        );

        // ── F9: head advancement (different key) ───────────────────────
        Agentshell_Test::assert_false(
            (bool) ep_f( 'f9a_advanced' ),
            'F9.1 first call must not be an advance'
        );
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f9b_advanced' ),
            'F9.2 second call with different key must be flagged as advanced'
        );
        Agentshell_Test::assert_equals(
            (string) ep_f( 'f9a_op_id' ),
            (string) ep_f( 'f9b_op_id' ),
            'F9.3 head advancement must keep the same op id'
        );
        Agentshell_Test::assert_equals(
            (int) ep_f( 'f9a_shadow_id' ),
            (int) ep_f( 'f9b_shadow_id' ),
            'F9.4 head advancement must keep the same shadow id'
        );
        Agentshell_Test::assert_equals(
            'F9 second body',
            (string) ep_f( 'f9_shadow_content' ),
            'F9.5 shadow post_content must reflect the new staged body'
        );
        Agentshell_Test::assert_equals(
            (string) ep_f( 'f9a_op_id' ),
            (string) ep_f( 'f9_shadow_op_id' ),
            'F9.6 shadow META_OP_ID must still equal the (unchanged) op id'
        );
        Agentshell_Test::assert_equals(
            'staged',
            (string) ep_f( 'f9_op_state' ),
            'F9.7 op must remain in staged state after advancement'
        );
        Agentshell_Test::assert_equals(
            1,
            (int) ep_f( 'f9_head_for_original' ),
            'F9.8 head map must have an entry keyed by original_id'
        );
        Agentshell_Test::assert_equals(
            (string) ep_f( 'f9a_op_id' ),
            (string) ep_f( 'f9_head_op_meta' ),
            'F9.9 head map op_meta_value must equal the op id'
        );

        // ── F10: no orphans ────────────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) ep_f( 'f10_tx_row_absent' ),
            'F10.1 tx option row must be gone at end of suite'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) ep_f( 'f10_claim_count' ),
            'F10.2 no per-original claim rows must survive'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) ep_f( 'f10_shadow_meta_count' ),
            'F10.3 no shadow meta rows must survive'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) ep_f( 'f10_orphan_count' ),
            'F10.4 every tracked post must have been deleted by teardown'
        );
    }
);
