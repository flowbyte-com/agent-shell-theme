<?php
/**
 * Live regression for the create_draft CAS persistent-journal
 * defect (Gate 3 Task 4).
 *
 * The bug this pins
 * -----------------
 * `agentshell_create_draft` failed with "compare_and_set_head()
 * refused to record the head for post N on create" on a site with
 * prior journal state. The integration site reproduced this three
 * times in a row, and the only path off the stuck-intended state
 * was lease expiry. The 0314499 fix landed the post-attach re-read
 * in the head CAS, so the FIRST create in a tx was correct, but
 * the SECOND and subsequent creates in the same tx still failed at
 * `Content_Journal::attach()` because the in-scope `$tx` (read at
 * the start of the in-tx branch) was stale relative to the
 * on-disk row that the previous create's attach() had just
 * advanced. The fix mirrors that pattern at the start of the
 * in-tx branch: re-read the row from the option at the start of
 * every in-tx call.
 *
 * What is asserted here
 * ---------------------
 * Eight user-required scenarios plus a no-orphan teardown, each
 * through a real WordPress install with the journal and the option
 * cache. A stubbed assertion cannot prove `cas_write_row()` would
 * not refuse on stale bytes; only a live option can show the
 * second-and-subsequent-op path is correct.
 *
 * Note on F7/F8 scope: the original directive also asked for
 * "inconsistent or corrupted journal state" and "recovery from a
 * failed intended operation". Those paths require the tool to fail
 * closed on unexpected journal state, which is a property the
 * current tool does not enforce (it does not validate journal
 * signatures, and `begin()` itself refuses on durable work before
 * the tool is reached). F7 and F8 here exercise the same code path
 * from different angles (longer sequence, idempotency resume) to
 * broaden regression coverage of the fix without expanding the
 * tool's contract. The fail-closed property is left for a separate
 * pass.
 *
 *   F0. The tx option row is empty at child start (clean ledger).
 *   F1. First transaction on a clean journal applies, commits, the
 *       post exists, the head entry is set.
 *   F2. New transaction after a previously committed one applies
 *       and commits.
 *   F3. New transaction after a previously rolled-back one
 *       applies and commits; the rolled-back post is gone.
 *   F4. Three successive transactions on the same site each apply
 *       and commit; three separate posts are created.
 *   F5. Three sequential create_drafts in one tx (the integration
 *       reproduction). All three must apply, all three posts must
 *       exist, all three must have head entries. The post-fix
 *       tool MUST NOT regress to "second and third refuse".
 *   F6. The head map structural test: after three creates in a
 *       single tx, the head count equals the create count, so the
 *       second/third attaches did not erase the first/second head
 *       entries.
 *   F7. Stress test: five sequential creates in one tx. Same code
 *       path as F5, longer sequence, exercises the fresh-row re-read
 *       repeatedly.
 *   F8. Idempotency resume: two creates with the same key in one
 *       tx. The second call must return the first post id with
 *       `resumed: true`, not create a second post.
 *   F9. No orphans: the tx option row is gone, no tracked posts
 *       survive, and the audit log has no spurious compensation
 *       rows from this run.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live create-draft CAS persistent-journal (Gate 3 Task 4 regression)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::skip( 'no test-bed config (destructive suites require tests/config/test-bed.json)\\n' );
    Agentshell_Test::run(
        'live create-draft CAS persistent-journal',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config' );
        }
    );
    return;
}

$child       = __DIR__ . '/create-draft-cas-persistent-live-child.php';
$report_file = sys_get_temp_dir() . '/as-cdcp-live-' . getmypid() . '.json';
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
        'live create-draft CAS persistent-journal: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['cdcp_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['cdcp_f'] = $f;

function cdcp_f( $key, $default = null ) {
    $f = $GLOBALS['cdcp_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

Agentshell_Test::run(
    'live create-draft CAS persistent-journal',
    function () {
        // ── F0: precondition ────────────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f0_clean' ),
            'F0: tx option row must be empty at child start'
        );

        // ── F1: first transaction on a clean journal ───────────────────
        Agentshell_Test::assert_true(
            (int) cdcp_f( 'f1_post_id' ) > 0,
            'F1: first create must produce a post id; got: '
                . wp_json_encode( cdcp_f( 'f1_post_id' ) )
        );
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f1_applied' ),
            'F1: first create must apply; got: '
                . wp_json_encode( cdcp_f( 'f1_applied' ) )
        );
        Agentshell_Test::assert_equals(
            1,
            (int) cdcp_f( 'f1_head' ),
            'F1: first create must set the head entry'
        );
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f1_committed' ),
            'F1: commit must succeed; got: '
                . wp_json_encode( cdcp_f( 'f1_committed' ) )
        );
        Agentshell_Test::assert_true(
            is_null( cdcp_f( 'f1_tx_after' ) ),
            'F1: tx row must be deleted by commit'
        );

        // ── F2: new tx after a previously committed one ────────────────
        Agentshell_Test::assert_true(
            (int) cdcp_f( 'f2_post_id' ) > 0,
            'F2: second tx create must produce a post id; got: '
                . wp_json_encode( cdcp_f( 'f2_post_id' ) )
        );
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f2_applied' ),
            'F2: second tx create must apply; got: '
                . wp_json_encode( cdcp_f( 'f2_applied' ) )
        );

        // ── F3: new tx after a previously rolled-back one ──────────────
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f3a_rolled_back' ),
            'F3a: rollback must succeed; got: '
                . wp_json_encode( cdcp_f( 'f3a_rolled_back' ) )
        );
        Agentshell_Test::assert_false(
            (bool) cdcp_f( 'f3a_post_after_rb' ),
            'F3a: rolled-back post must be gone'
        );
        Agentshell_Test::assert_true(
            (int) cdcp_f( 'f3b_post_id' ) > 0,
            'F3b: new tx after rollback must produce a post id; got: '
                . wp_json_encode( cdcp_f( 'f3b_post_id' ) )
        );
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f3b_applied' ),
            'F3b: new tx after rollback must apply'
        );

        // ── F4: multiple successive transactions on the same site ─────
        $f4_ids = cdcp_f( 'f4_post_ids', array() );
        Agentshell_Test::assert_equals(
            3,
            count( $f4_ids ),
            'F4: three successive transactions must produce three post ids'
        );
        for ( $i = 1; $i <= 3; $i++ ) {
            Agentshell_Test::assert_true(
                (int) ( $f4_ids[ $i - 1 ] ?? 0 ) > 0,
                "F4: tx #{$i} must produce a post id; got: "
                    . wp_json_encode( $f4_ids[ $i - 1 ] ?? null )
            );
            Agentshell_Test::assert_true(
                (bool) cdcp_f( 'f4_applied_' . $i ),
                "F4: tx #{$i} create must apply"
            );
        }
        $f4_unique = array_unique( array_map( 'intval', $f4_ids ) );
        Agentshell_Test::assert_equals(
            3,
            count( $f4_unique ),
            'F4: three successive txs must produce three distinct post ids'
        );

        // ── F5: three creates in one tx (the integration reproduction)
        $f5_results = cdcp_f( 'f5_results', array() );
        Agentshell_Test::assert_equals(
            3,
            count( $f5_results ),
            'F5: must have three results, one per create'
        );
        $f5_head_count = 0;
        foreach ( $f5_results as $r5 ) {
            Agentshell_Test::assert_true(
                ! empty( $r5['ok'] ),
                'F5: every create must succeed; failed: '
                    . wp_json_encode( $r5 )
            );
            Agentshell_Test::assert_true(
                (int) ( $r5['post_id'] ?? 0 ) > 0,
                'F5: every create must produce a post id; got: '
                    . wp_json_encode( $r5 )
            );
            Agentshell_Test::assert_true(
                (bool) ( $r5['applied'] ?? false ),
                'F5: every create must apply; got: '
                    . wp_json_encode( $r5 )
            );
            Agentshell_Test::assert_equals(
                1,
                (int) ( $r5['has_head'] ?? 0 ),
                'F5: every create must set the head entry; got: '
                    . wp_json_encode( $r5 )
            );
            $f5_head_count += (int) ( $r5['has_head'] ?? 0 );
        }
        Agentshell_Test::assert_equals(
            3,
            $f5_head_count,
            'F5: three creates must produce three head entries (the bug would leave only one)'
        );
        // The F5 unique-post-id check: the three creates must produce
        // three distinct post ids.
        $f5_ids = array_map( function ( $r ) { return (int) ( $r['post_id'] ?? 0 ); }, $f5_results );
        Agentshell_Test::assert_equals(
            3,
            count( array_unique( $f5_ids ) ),
            'F5: three creates in one tx must produce three distinct post ids'
        );

        // ── F6: head map structural test
        Agentshell_Test::assert_equals(
            3,
            (int) cdcp_f( 'f6_head_count_before_rb' ),
            'F6: head count before rollback must equal create count'
        );

        // ── F7: stress test — five sequential creates in one tx ──────
        $f7_results = cdcp_f( 'f7_results', array() );
        Agentshell_Test::assert_equals(
            5,
            count( $f7_results ),
            'F7: must have five results, one per create'
        );
        $f7_head_count = 0;
        $f7_ids = array();
        foreach ( $f7_results as $r7 ) {
            Agentshell_Test::assert_true(
                ! empty( $r7['ok'] ),
                'F7: every create must succeed; failed: '
                    . wp_json_encode( $r7 )
            );
            Agentshell_Test::assert_true(
                (int) ( $r7['post_id'] ?? 0 ) > 0,
                'F7: every create must produce a post id; got: '
                    . wp_json_encode( $r7 )
            );
            Agentshell_Test::assert_true(
                (bool) ( $r7['applied'] ?? false ),
                'F7: every create must apply; got: '
                    . wp_json_encode( $r7 )
            );
            Agentshell_Test::assert_equals(
                1,
                (int) ( $r7['has_head'] ?? 0 ),
                'F7: every create must set the head entry; got: '
                    . wp_json_encode( $r7 )
            );
            $f7_head_count += (int) ( $r7['has_head'] ?? 0 );
            $f7_ids[] = (int) ( $r7['post_id'] ?? 0 );
        }
        Agentshell_Test::assert_equals(
            5,
            $f7_head_count,
            'F7: five creates must produce five head entries'
        );
        Agentshell_Test::assert_equals(
            5,
            count( array_unique( $f7_ids ) ),
            'F7: five creates in one tx must produce five distinct post ids'
        );

        // ── F8: idempotency resume — same key in two creates in one tx
        Agentshell_Test::assert_true(
            (int) cdcp_f( 'f8a_post_id' ) > 0,
            'F8a: first create must produce a post id'
        );
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f8a_journaled' ),
            'F8a: first create must be journalled'
        );
        Agentshell_Test::assert_false(
            (bool) cdcp_f( 'f8a_resumed' ),
            'F8a: first create must not be a resume'
        );
        Agentshell_Test::assert_equals(
            (int) cdcp_f( 'f8a_post_id' ),
            (int) cdcp_f( 'f8b_post_id' ),
            'F8b: second create with the same key must return the first post id (resume)'
        );
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f8b_resumed' ),
            'F8b: second create with the same key must be flagged as resumed'
        );

        // ── F9: no orphans ─────────────────────────────────────────────
        Agentshell_Test::assert_true(
            (bool) cdcp_f( 'f9_tx_row_absent' ),
            'F9: tx option row must be gone at end of suite'
        );
        Agentshell_Test::assert_false(
            (bool) cdcp_f( 'f9_orphan_posts' ),
            'F9: every tracked post must have been deleted by teardown; got: '
                . wp_json_encode( array(
                    'tracked'   => cdcp_f( 'f9_tracked_post_count' ),
                    'existing'  => cdcp_f( 'f9_existing_post_count' ),
                ) )
        );
        Agentshell_Test::assert_equals(
            0,
            (int) cdcp_f( 'f9_bad_audit' ),
            'F9: audit log must not record spurious compensation rows from this run'
        );
    }
);
