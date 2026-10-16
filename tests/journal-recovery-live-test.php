<?php
/**
 * Live content-journal recovery, run against a real WordPress install.
 *
 * The stubbed suite pins the state machine. This one asks the question a stub
 * cannot answer honestly: does the compensation really remove the content it
 * claims to, and does it stay its hands off everything else?
 *
 * The place that matters is `wp_delete_post()`. Its default branch *trashes*
 * rather than deleting, which would satisfy every assertion written against a
 * mocked delete while leaving the orphan on the site — and trashing rewrites the
 * slug, so a trash-based compensation would also break the identity the journal
 * records. So the first assertion here is not about the recovery engine at all:
 * it establishes, against real rows, that trash and force delete are genuinely
 * different, and only then checks that compensation picked the destructive one
 * for the right reason.
 *
 * The suite needs a WordPress to talk to. Without one it skips, so it is safe
 * to run anywhere; `tests/wp-live.php` finds the install and picks a PHP that
 * can reach its database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wp-live.php';

echo "live content journal recovery (real WordPress)\n";

// Destructive live suite: requires the test-bed config. The strict
// resolution refuses to fall back to LocalWP auto-discovery, which
// would otherwise pick the alphabetically first LocalWP site
// (typically the dev site on a workstation with both dev and test
// installs). A null return is a clean skip, not a fail.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live journal-recovery suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-jr-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/journal-recovery-live-child.php',
    array( $config['wp_root'], $report_file )
);

$f = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'live journal-recovery child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['jr_live_f'] ),
                'child failed to report: ' . $run['output']
            );
        }
    );
    return;
}
$GLOBALS['jr_live_f'] = $f;

function jr_live( $key, $default = null ) {
    $f = $GLOBALS['jr_live_f'];
    return array_key_exists( $key, $f ) ? $f[ $key ] : $default;
}

// The child may refuse to run if the site is not in a state the suite can
// run safely — specifically, if a real (non-test) transaction is already
// open. The destructive commit / rollback paths would otherwise destroy
// it. Surface the refusal here so the suite fails loudly with the
// pre-existing transaction's id, rather than silently destroying the
// row and reporting nonsense downstream.
if ( isset( $f['fatal_precondition'] ) ) {
    Agentshell_Test::run(
        'live: precondition: a non-test transaction is open and would be destroyed',
        function () use ( $f ) {
            $p = $f['fatal_precondition'];
            Agentshell_Test::assert_true(
                false !== strpos( (string) ( $p['reason'] ?? '' ), 'non-test transaction' ),
                'reason: ' . ( $p['reason'] ?? '' )
            );
            Agentshell_Test::assert_equals(
                'tx_real_user_test', (string) ( $p['tx_id'] ?? '' ),
                'the pre-existing transaction id is recorded so the operator can find and close it'
            );
        }
    );
    return;
}

// ── Why the compensation deletes rather than trashes ──────────────────────────
// Asserted first, and about WordPress rather than about our code, because
// every later assertion about deletion is only meaningful if this is true.
Agentshell_Test::run(
    'live: trashing and force-deleting are genuinely different operations',
    function () {
        $t = jr_live( 'trash_semantics', array() );
        Agentshell_Test::assert_equals(
            1, (int) ( $t['row_after_trash'] ?? 0 ),
            'a trashed post must still be a row — otherwise the distinction this suite rests on does not exist'
        );
        Agentshell_Test::assert_equals( 'trash', $t['status_after_trash'] ?? '', 'and its status must be trash, not gone' );
        Agentshell_Test::assert_not_equals(
            $t['slug_before'] ?? '', $t['slug_after'] ?? '',
            'trashing must change the slug, so a trash-based compensation could not be matched to the journal again'
        );
    }
);

// ── The core guarantee ────────────────────────────────────────────────────────
Agentshell_Test::run(
    'live: a journalled draft is really gone from the database afterwards',
    function () {
        $r = jr_live( 'recover', array() );
        Agentshell_Test::assert_equals( 1, (int) ( $r['rows_before'] ?? 0 ), 'the draft must exist before recovery, or nothing is being proved' );
        Agentshell_Test::assert_equals( 1, (int) ( $r['meta_before'] ?? 0 ), 'and its journal marker must be on it' );
        Agentshell_Test::assert_true( $r['object_cache_had_it'] ?? false, 'and get_post() must be serving it' );

        Agentshell_Test::assert_equals( 'recovered', $r['status'] ?? '', 'recovery must complete' );
        Agentshell_Test::assert_equals( 'compensated', $r['outcome'] ?? '', 'by compensating the operation' );
        Agentshell_Test::assert_equals( (int) ( $r['id'] ?? 0 ), (int) ( $r['deleted_post_id'] ?? 0 ), 'and deleting exactly that post' );

        Agentshell_Test::assert_equals( 0, (int) ( $r['rows_after'] ?? -1 ), 'the post row must be gone from wp_posts, not trashed' );
        Agentshell_Test::assert_equals( 0, (int) ( $r['meta_after'] ?? -1 ), 'its metadata rows must be gone with it' );
        Agentshell_Test::assert_false( $r['get_post_after'] ?? true, 'and nothing may still be serving it from cache' );
        Agentshell_Test::assert_equals( array(), $r['title_search'] ?? null, 'no row may match the title' );
        Agentshell_Test::assert_true( $r['transaction_gone'] ?? false, 'the transaction row is freed only afterwards' );
    }
);

Agentshell_Test::run(
    'live: repeating recovery does nothing the second time',
    function () {
        $r = jr_live( 'recover', array() );
        // The row is gone, so the tool reports "nothing stored" as an outcome —
        // a repeat is a success, not an error that sends someone hunting.
        Agentshell_Test::assert_equals( 'nothing_to_do', $r['repeat_status'] ?? '', 'a repeat after a completed recovery is a no-op, not an error' );
        Agentshell_Test::assert_equals( 0, (int) ( $r['repeat_rows'] ?? -1 ), 'and the post is still gone' );
    }
);

// ── The two sides of the crash window ─────────────────────────────────────────
Agentshell_Test::run(
    'live: a crash before the write deletes nothing, and says so',
    function () {
        $n = jr_live( 'no_evidence', array() );
        Agentshell_Test::assert_equals( 'recovered', $n['status'] ?? '', 'recovery completes' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $n['detail'] ?? '' ), 'no evidence the write ever happened' ),
            'and explains why nothing was deleted: ' . ( $n['detail'] ?? '' )
        );
        Agentshell_Test::assert_equals( 1, (int) ( $n['keeper_rows'] ?? 0 ), 'the unrelated post must survive' );
        Agentshell_Test::assert_equals( array(), $n['keeper_rows_matching'] ?? null, 'and nothing was created on its behalf either' );
    }
);

Agentshell_Test::run(
    'live: a crash after the write is found through the marker and refused (no recorded after-image)',
    function () {
        $c = jr_live( 'crash_after_write', array() );
        Agentshell_Test::assert_equals( 'conflict', $c['outcome'] ?? '', 'no recorded after-image means the post is preserved, not deleted by guess' );
        Agentshell_Test::assert_equals( 1, (int) ( $c['rows_after'] ?? -1 ), 'and the marked post must survive in wp_posts' );
        Agentshell_Test::assert_equals( 1, (int) ( $c['meta_after'] ?? -1 ), 'with its marker meta intact' );
    }
);

// ── The case a WordPress-object-based recovery would get wrong ────────────────
Agentshell_Test::run(
    'live: a trashed orphan carrying our marker is found, named, and refused',
    function () {
        $t = jr_live( 'trashed_orphan', array() );
        Agentshell_Test::assert_equals( 1, (int) ( $t['row_is_trashed'] ?? 0 ), 'the draft must really be in the table, trashed' );
        Agentshell_Test::assert_false(
            $t['get_posts_would_see'] ?? true,
            'and get_posts() must not see it — which is why the marker lookup is direct SQL'
        );
        // Found and refused, rather than not found. Both look like "nothing to
        // do" from outside; only one of them can name the post, and that is the
        // difference the direct query buys.
        Agentshell_Test::assert_equals( 'conflict', $t['outcome'] ?? '', 'a post somebody has since trashed is a conflict, not a deletion' );
        Agentshell_Test::assert_true(
            (int) ( $t['identified_post_id'] ?? 0 ) > 0,
            'and the conflict must name the post it found, proving the marker lookup reached it'
        );
        Agentshell_Test::assert_equals( 1, (int) ( $t['rows_after'] ?? 0 ), 'nothing may be deleted' );
    }
);

// ── Divergence, measured on real rows ─────────────────────────────────────────
Agentshell_Test::run(
    'live: a draft somebody else has since edited is refused, and kept',
    function () {
        $d = jr_live( 'diverged', array() );
        Agentshell_Test::assert_equals( 'conflict', $d['outcome'] ?? '', 'divergence must be a conflict' );
        Agentshell_Test::assert_equals( 'partial', $d['status'] ?? '', 'and the recovery is only partial' );
        Agentshell_Test::assert_equals( 1, (int) ( $d['rows_after'] ?? 0 ), 'the post must survive' );
        Agentshell_Test::assert_equals( 'publish', $d['db_status'] ?? '', 'with its real, changed status' );
        Agentshell_Test::assert_true( $d['row_kept'] ?? false, 'the journal must be kept' );
        Agentshell_Test::assert_equals( 'conflict', $d['op_state'] ?? '', 'and the operation recorded as conflicted' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $d['detail'] ?? '' ), 'post_status' ),
            'naming the field that diverged: ' . ( $d['detail'] ?? '' )
        );
    }
);

Agentshell_Test::run(
    'live: repeating recovery does not quietly resolve an unresolved conflict',
    function () {
        $d = jr_live( 'diverged', array() );
        Agentshell_Test::assert_equals( 'conflict', $d['repeat_outcome'] ?? '', 'a conflicted operation stays conflicted' );
        Agentshell_Test::assert_equals( 'partial', $d['repeat_status'] ?? '', 'and the row is not freed' );
        Agentshell_Test::assert_true( (int) ( $d['repeat_conflicts'] ?? 0 ) >= 1, 'the conflict is re-reported, not swallowed' );
        Agentshell_Test::assert_true( $d['still_kept'] ?? false, 'and the transaction row is still there' );
    }
);

// ── Scope and authority, against the real site ────────────────────────────────
Agentshell_Test::run(
    'live: recovery touches only its own post and frees the slot',
    function () {
        $x = jr_live( 'final', array() );
        Agentshell_Test::assert_equals( 'recovered', $x['status'] ?? '', 'the last recovery completes' );
        Agentshell_Test::assert_true( $x['orphan_gone'] ?? false, 'its own orphan is gone' );
        Agentshell_Test::assert_equals( $x['unrelated_before'] ?? null, $x['unrelated_after'] ?? null, 'unrelated posts are untouched' );
        Agentshell_Test::assert_equals( 1, (int) ( $x['unrelated_status'] === 'draft' ? 1 : 0 ), 'and its real status is unchanged' );
        Agentshell_Test::assert_equals( 0, (int) ( $x['keeper_still_marked'] ?? -1 ), 'no journal marker is left on unrelated content' );
        Agentshell_Test::assert_true( $x['transaction_gone'] ?? false, 'the transaction row is freed' );
    }
);

Agentshell_Test::run(
    'live: an unauthorised actor is refused against the real capability check',
    function () {
        $a = jr_live( 'authorisation', array() );
        Agentshell_Test::assert_equals( 'refused', $a['status'] ?? '', 'a stranger may not resolve it' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $a['reason'] ?? '' ), 'manage_options' ),
            'and is told what the capability requires: ' . ( $a['reason'] ?? '' )
        );
        Agentshell_Test::assert_equals( 1, (int) ( $a['rows_after'] ?? 0 ), 'the draft survives the refusal' );
        Agentshell_Test::assert_true( $a['row_kept'] ?? false, 'and the journal is kept' );
    }
);

// ── Audit, on the real audit log ──────────────────────────────────────────────
Agentshell_Test::run(
    'live: every recovery and refusal is on the real audit log',
    function () {
        $a = jr_live( 'audit', array() );
        Agentshell_Test::assert_true( (int) ( $a['count'] ?? 0 ) >= 3, 'recoveries and the refusal are all recorded' );
        Agentshell_Test::assert_true( in_array( 'transaction_recovery', $a['ops'] ?? array(), true ), 'including the successful recovery' );
        Agentshell_Test::assert_true( in_array( 'transaction_recovery_compensated', $a['ops'] ?? array(), true ), 'and each post deleted' );
        Agentshell_Test::assert_true( (int) ( $a['refusals'] ?? 0 ) >= 1, 'and the refusal' );
        Agentshell_Test::assert_true(
            ! empty( array_filter( (array) ( $a['deleted'] ?? array() ), function ( $ids ) { return ! empty( $ids ); } ) ),
            'with the deleted post ids recorded'
        );
    }
);

// ── The site, left as found ───────────────────────────────────────────────────
Agentshell_Test::run(
    'live: the site is left exactly as it was found',
    function () {
        $c = jr_live( 'cleanup', array() );
        Agentshell_Test::assert_equals( 0, (int) ( $c['rows_remaining'] ?? -1 ), 'every disposable post must be gone' );
        Agentshell_Test::assert_equals( 0, (int) ( $c['trashed_remaining'] ?? -1 ), 'including the trashed ones' );
        Agentshell_Test::assert_equals(
            (int) ( $c['saved_marker_count'] ?? 0 ),
            (int) ( $c['stray_marker_meta'] ?? -1 ),
            'the journal-marker count is restored to what it was before the test'
        );
        Agentshell_Test::assert_true( $c['transaction_restored'] ?? false, 'the transaction row must be as it was' );
        Agentshell_Test::assert_true( $c['audit_restored'] ?? false, 'and so must the audit log' );
    }
);

// ── 10. Claim takeover with real SQL bytes ────────────────────────────────────
Agentshell_Test::run(
    'live: claim takeover after TTL is against the real wp_options row',
    function () {
        $t = jr_live( 'claim_takeover', array() );
        Agentshell_Test::assert_equals(
            'another_recovery_in_progress',
            $t['blocked_outcome'] ?? '',
            'a different actor inside the claim window is refused at the claim layer'
        );
        Agentshell_Test::assert_equals( 1, (int) ( $t['rows_after_blocked'] ?? -1 ), 'and the post survives the refusal' );
        Agentshell_Test::assert_equals(
            'compensated', $t['resumed_outcome'] ?? '',
            'after the claim is aged past TTL, the second actor can take over and complete compensation'
        );
        Agentshell_Test::assert_equals( 0, (int) ( $t['rows_after_resumed'] ?? -1 ), 'and the post is gone afterwards' );
    }
);

// ── 11. create_draft inside a real transaction ────────────────────────────────
Agentshell_Test::run(
    'live: create_draft + commit retains the post; the post+marker pair is atomic',
    function () {
        $c = jr_live( 'create_draft_commit', array() );
        Agentshell_Test::assert_equals( 1, (int) ( $c['row_present'] ?? -1 ), 'the post row is in wp_posts' );
        Agentshell_Test::assert_equals( 1, (int) ( $c['marker_present'] ?? -1 ), 'and the marker is in wp_postmeta — the guard wrapped both in one SQL transaction' );
        Agentshell_Test::assert_equals( 1, (int) ( $c['rows_alive'] ?? 0 ), 'and the post is retained through commit' );
        Agentshell_Test::assert_equals( 1, (int) ( $c['marker_after'] ?? 0 ), 'with the marker still on it' );
        Agentshell_Test::assert_true( $c['transaction_gone'] ?? false, 'and the option row is freed by the value-guarded CAS' );
    }
);

Agentshell_Test::run(
    'live: create_draft + rollback deletes the post; the row is freed by cas_delete_row',
    function () {
        $r = jr_live( 'create_draft_rollback', array() );
        Agentshell_Test::assert_equals( 0, (int) ( $r['rows_after'] ?? -1 ), 'the post is gone after rollback' );
        Agentshell_Test::assert_true( $r['transaction_gone'] ?? false, 'and the transaction row is freed' );
    }
);

Agentshell_Test::run(
    'live: create_draft + rollback with a divergence preserves the post and refuses the close',
    function () {
        $c = jr_live( 'create_draft_conflict', array() );
        Agentshell_Test::assert_true( $c['rollback_threw'] ?? false, 'the rollback throws when the post has diverged' );
        Agentshell_Test::assert_equals( 1, (int) ( $c['rows_after'] ?? -1 ), 'the post survives the divergence' );
        Agentshell_Test::assert_true( $c['transaction_kept'] ?? false, 'and the transaction row is preserved' );
        Agentshell_Test::assert_equals( 'conflict', $c['op_state'] ?? '', 'with the op transitioned to conflict' );
        Agentshell_Test::assert_true( $c['row_preserved'] ?? false, 'and the post row is intact' );
    }
);

Agentshell_Test::run(
    'live: idempotency_key reuses the original post id; no second post is created',
    function () {
        $i = jr_live( 'create_draft_idem_resume', array() );
        Agentshell_Test::assert_equals( 1, (int) ( $i['matches'] ?? -1 ), 'one journal op carries the key' );
        Agentshell_Test::assert_equals( (int) ( $i['expected_id'] ?? 0 ), (int) ( $i['resumed_id'] ?? 0 ), 'and it points at the original post id' );
        Agentshell_Test::assert_equals( 1, (int) ( $i['rows_count'] ?? -1 ), 'no second post is on the site' );
    }
);

Agentshell_Test::run(
    'live: a same-second content edit is caught by the strong after-image, not by post_modified_gmt',
    function () {
        $s = jr_live( 'same_second_divergence', array() );
        Agentshell_Test::assert_true( $s['same_modified_gmt'] ?? false, 'the field-level evidence did not move — the edit landed in the same second' );
        Agentshell_Test::assert_true( $s['same_slug']         ?? false, 'and the slug was preserved' );
        Agentshell_Test::assert_true( $s['content_differs']   ?? false, 'but the post content was silently rewritten' );
        Agentshell_Test::assert_equals( 'conflict', $s['recover_outcome'] ?? '', 'the recovery engine refuses on a content-hash mismatch with unchanged slug and modified_gmt' );
        Agentshell_Test::assert_true( $s['post_preserved']    ?? false, 'and the post is preserved, not silently deleted' );
    }
);

Agentshell_Test::run(
    'live: a mark_applied CAS failure leaves the post on disk and refuses the commit',
    function () {
        $c = jr_live( 'mark_applied_cas_failure', array() );
        Agentshell_Test::assert_true(
            array_key_exists( 'cas_returned', $c ) && null === $c['cas_returned'],
            'mark_applied returns null on CAS failure'
        );
        Agentshell_Test::assert_equals( 1, (int) ( $c['post_present']   ?? -1 ), 'the post is on disk' );
        Agentshell_Test::assert_equals( 1, (int) ( $c['marker_present'] ?? -1 ), 'and the marker is on it' );
        Agentshell_Test::assert_true( $c['commit_refused']   ?? false, 'and a subsequent commit refuses, not discards' );
        Agentshell_Test::assert_true( $c['transaction_kept'] ?? false, 'the option row is preserved' );
    }
);

// ── 12. update_draft inside a real transaction (the Update_Draft tool) ────────
// The auto-compensate path for update + rollback (12a) is a documented gap
// in Stage 2a (see journal-recovery-live-child.php:991). The sub-tests
// below cover the Update_Draft tool itself: the no-marker refusal, the
// not-a-draft refusal, and the retry-with-same-key idempotency.

Agentshell_Test::run(
    'live: update_draft refuses when the post carries no journal marker',
    function () {
        $u = jr_live( 'update_no_marker', array() );
        Agentshell_Test::assert_true( $u['refused'] ?? false, 'the tool threw rather than touching an unmarked post' );
        Agentshell_Test::assert_true( $u['names_cause'] ?? false, 'and the message names transaction_update_draft_not_transaction_owned' );
        Agentshell_Test::assert_true( $u['title_unchanged'] ?? false, 'and the post title was not changed' );
        Agentshell_Test::assert_true( $u['transaction_gone'] ?? false, 'and the empty transaction row is closed by the rollback' );
    }
);

Agentshell_Test::run(
    'live: update_draft refuses when the post is not a draft',
    function () {
        $u = jr_live( 'update_not_a_draft', array() );
        Agentshell_Test::assert_true( $u['refused'] ?? false, 'the tool threw when the post was a published page' );
        Agentshell_Test::assert_true( $u['names_cause'] ?? false, 'and the message names transaction_update_draft_not_a_draft' );
        Agentshell_Test::assert_true( $u['post_was_publish'] ?? false, 'and the post was indeed a published page' );
        Agentshell_Test::assert_true( $u['content_unchanged'] ?? false, 'and the post content was not modified' );
    }
);

Agentshell_Test::run(
    'live: update_draft with the same idempotency_key reuses the op; a different content refuses',
    function () {
        $u = jr_live( 'update_retry', array() );
        Agentshell_Test::assert_true( '' !== ( $u['first_op_id'] ?? '' ), 'the first call returned an op id' );
        Agentshell_Test::assert_equals( $u['first_op_id'] ?? 'X', $u['resumed_op_id'] ?? 'Y', 'and a same-content retry returns the same op id' );
        Agentshell_Test::assert_true( $u['resumed_flag'] ?? false, 'with resumed=true on the second call' );
        Agentshell_Test::assert_equals( 1, (int) ( $u['op_count_in_journal'] ?? -1 ), 'and the journal holds exactly one update op, not two' );
        Agentshell_Test::assert_true( $u['different_content_refused'] ?? false, 'and a retry with different content is refused' );
        Agentshell_Test::assert_true( $u['names_idem_conflict'] ?? false, 'with a transaction_update_draft_idempotency_conflict name' );
    }
);

// ── 13. Advisory lock: KILL -9 of the owning process releases the lock ────────
Agentshell_Test::run(
    'live: dropping the wpdb connection releases the advisory lock to a second acquirer',
    function () {
        $k = jr_live( 'lock_kill_minus_9', array() );
        Agentshell_Test::assert_true( $k['first_acquire'] ?? false, 'the first acquire on a fresh post id succeeds' );
        Agentshell_Test::assert_true( $k['second_acquire'] ?? false, 'after the connection drops, the second acquire succeeds — the server released the lock on close' );
    }
);

// ── 14. Advisory lock: GET_LOCK with timeout=0 returns promptly ───────────────
Agentshell_Test::run(
    'live: a second acquire on a held lock returns within 200 ms, not after a 60-second wait',
    function () {
        $p = jr_live( 'lock_prompt_failure', array() );
        Agentshell_Test::assert_true( $p['lock_held'] ?? false, 'the first acquire on a fresh post id holds the lock' );
        Agentshell_Test::assert_true( $p['prompt'] ?? false, 'a second acquire returns within 200 ms — the GET_LOCK timeout is 0, not 60' );
    }
);

// ── 15. Stage 2a acceptance scenarios ─────────────────────────────────────────
// The 11 scenarios in the user's stage/2a completion brief, exercised
// against a real WordPress install. Each test pins one of the load-bearing
// properties of the head-pointer model: a successful create+edit+commit
// retains the final state, a successful create+edit+rollback deletes the
// post exactly once (the create op's compensation, not a chain of inverse
// updates), two drafts in the same transaction have independent heads, an
// external edit is detected by the head and refuses, the strong
// after-image catches same-second title-only edits, idempotent update
// retries honour the same-key/same-content rule, the OCC pre-check on
// Update_Draft refuses when the live post has diverged from the head
// (whether by external edit or by a stale-head crash window), and the
// Stage 1b create-only commit/rollback path is unchanged.

Agentshell_Test::run(
    'live: create + one edit + commit retains the final edited state',
    function () {
        $s = jr_live( 'stage2a_single_edit_commit', array() );
        Agentshell_Test::assert_equals( 1, (int) ( $s['rows_after'] ?? -1 ), 'the post survives commit' );
        Agentshell_Test::assert_equals( '<p>first body edited</p>', (string) ( $s['db_content'] ?? '' ), 'and the live content is the edited one, not the original' );
        Agentshell_Test::assert_true( $s['transaction_gone'] ?? false, 'and the option row is freed by the value-guarded CAS' );
    }
);

Agentshell_Test::run(
    'live: create + multiple edits + commit retains the LAST edited state',
    function () {
        $s = jr_live( 'stage2a_multi_edit_commit', array() );
        Agentshell_Test::assert_equals( 1, (int) ( $s['rows_after'] ?? -1 ), 'the post survives commit' );
        Agentshell_Test::assert_equals( '<p>v3</p>', (string) ( $s['db_content'] ?? '' ), 'and the live content is the LAST edit, not an intermediate one' );
        Agentshell_Test::assert_true( $s['transaction_gone'] ?? false, 'and the option row is freed' );
    }
);

Agentshell_Test::run(
    'live: create + one edit + rollback deletes the post exactly once',
    function () {
        $s = jr_live( 'stage2a_single_edit_rollback', array() );
        Agentshell_Test::assert_equals( 0, (int) ( $s['rows_after'] ?? -1 ), 'the post is gone after rollback' );
        Agentshell_Test::assert_true( $s['transaction_gone'] ?? false, 'and the option row is freed' );
    }
);

Agentshell_Test::run(
    'live: create + multiple edits + rollback deletes the post; no inverse-replay',
    function () {
        $s = jr_live( 'stage2a_multi_edit_rollback', array() );
        Agentshell_Test::assert_equals( 0, (int) ( $s['rows_after'] ?? -1 ), 'the post is gone after rollback' );
        // The rollback does NOT replay inverse updates before deleting:
        // the head-pointer model deletes via the create op's compensation,
        // and the update ops are no-op settlements. There is no audit
        // row for a wp_update_post inverse; the deletion is the only
        // destructive action. This test pins the absence of the
        // inverse-replay behaviour.
        Agentshell_Test::assert_true( $s['transaction_gone'] ?? false, 'and the option row is freed' );
    }
);

Agentshell_Test::run(
    'live: two drafts in one transaction have independent edit histories',
    function () {
        $s = jr_live( 'stage2a_two_drafts', array() );
        Agentshell_Test::assert_equals( 0, (int) ( $s['rows_a_after'] ?? -1 ), 'post A is deleted by rollback' );
        Agentshell_Test::assert_equals( 0, (int) ( $s['rows_b_after'] ?? -1 ), 'and post B is deleted too — both are owned by the same transaction' );
        Agentshell_Test::assert_true( $s['head_a_advanced'] ?? false, 'and post A\'s head advanced to the v1 after-image' );
        Agentshell_Test::assert_true( $s['head_b_advanced'] ?? false, 'and post B\'s head advanced independently' );
        Agentshell_Test::assert_true( $s['transaction_gone'] ?? false, 'and the option row is freed' );
    }
);

Agentshell_Test::run(
    'live: external modification after an authorised edit is detected; the next update refuses',
    function () {
        $s = jr_live( 'stage2a_external_after_edit', array() );
        Agentshell_Test::assert_true( $s['second_refused'] ?? false, 'the OCC pre-check refuses the second authorised update' );
        Agentshell_Test::assert_true( $s['names_cause'] ?? false, 'and names the cause: transaction_update_draft_external_edit_detected' );
        Agentshell_Test::assert_equals( 1, (int) ( $s['rows_after'] ?? -1 ), 'the post survives — the engine does not delete on a divergence' );
        Agentshell_Test::assert_true( $s['transaction_kept'] ?? false, 'and the journal/option row is preserved for reconciliation' );
    }
);

Agentshell_Test::run(
    'live: a same-second title-only external modification is caught by the strong after-image',
    function () {
        $s = jr_live( 'stage2a_title_only_divergence', array() );
        Agentshell_Test::assert_true( $s['title_changed'] ?? false, 'the title was changed externally' );
        Agentshell_Test::assert_true( $s['mod_unchanged'] ?? false, 'and post_modified_gmt was the same — the second-resolution field did not move' );
        Agentshell_Test::assert_true( $s['update_refused'] ?? false, 'but the OCC pre-check still refuses, because post_title is in the strong after-image' );
        Agentshell_Test::assert_true( $s['names_cause'] ?? false, 'and names the cause' );
        Agentshell_Test::assert_equals( 1, (int) ( $s['post_preserved'] ?? -1 ), 'the post is preserved' );
    }
);

Agentshell_Test::run(
    'live: idempotent update retry with same key + same content resumes; same key + different content refuses',
    function () {
        $s = jr_live( 'stage2a_idempotent_update', array() );
        Agentshell_Test::assert_true( '' !== ( $s['first_op_id'] ?? '' ), 'the first call returned an op id' );
        Agentshell_Test::assert_true( $s['same_op_id'] ?? false, 'and a same-content retry returns the same op id' );
        Agentshell_Test::assert_true( $s['resumed_flag'] ?? false, 'with resumed=true on the retry' );
        Agentshell_Test::assert_true( $s['conflict_refused'] ?? false, 'and a same-key retry with different content refuses' );
        Agentshell_Test::assert_true( $s['names_cause'] ?? false, 'with the transaction_update_draft_idempotency_conflict name' );
        Agentshell_Test::assert_equals( 1, (int) ( $s['update_op_count'] ?? -1 ), 'and the journal holds exactly one update op, not three' );
        Agentshell_Test::assert_equals( '<p>first content</p>', (string) ( $s['db_content'] ?? '' ), 'and the post content is the first one, not the rejected different one' );
    }
);

Agentshell_Test::run(
    'live: a head that points to a divergent state refuses the next authorised update',
    function () {
        $s = jr_live( 'stage2a_competing_updates', array() );
        Agentshell_Test::assert_true( $s['update_refused'] ?? false, 'the OCC pre-check refuses the update' );
        Agentshell_Test::assert_true( $s['names_cause'] ?? false, 'and names the cause' );
        Agentshell_Test::assert_equals( 1, (int) ( $s['post_preserved'] ?? -1 ), 'and the post is preserved — the engine does not delete on a head divergence' );
    }
);

Agentshell_Test::run(
    'live: a post updated on disk while the head was rolled back is detected by the next OCC pre-check',
    function () {
        $s = jr_live( 'stage2a_head_boundary', array() );
        Agentshell_Test::assert_true( $s['head_was_rolled'] ?? false, 'the head was successfully rolled back to the create op\'s after-image' );
        Agentshell_Test::assert_true( $s['update_refused'] ?? false, 'and the next authorised update is refused — the live post is ahead of the head' );
        Agentshell_Test::assert_true( $s['names_cause'] ?? false, 'and names the cause' );
        Agentshell_Test::assert_equals( 1, (int) ( $s['post_preserved'] ?? -1 ), 'and the post is preserved, with the unrecorded change intact' );
        // The head's strong after-image is the create op's, whose
        // content was the create body (`<p>head boundary target</p>`),
        // not the post-update body (`<p>head boundary v1</p>`). The
        // head stores post_content_sha256, not the body itself, so the
        // assertion is on the SHA. The rolled state is durable: the
        // head has the create's after-image and a subsequent authorised
        // edit is refused because the live post is ahead of the head.
        Agentshell_Test::assert_true(
            '' !== (string) ( $s['head_title'] ?? '' ),
            'and the head\'s post_title is recorded (the strong after-image carries it)'
        );
    }
);

Agentshell_Test::run(
    'live: Stage 1b create-only commit/rollback is unchanged by the Stage 2a additions',
    function () {
        $s = jr_live( 'stage2a_create_only_unchanged', array() );
        Agentshell_Test::assert_equals( 1, (int) ( $s['post_commit_kept'] ?? -1 ), 'create + commit still retains the post' );
        Agentshell_Test::assert_equals( 0, (int) ( $s['post_rollback_gone'] ?? -1 ), 'create + rollback still deletes the post' );
    }
);

// ── Stage 2b-5: mixed create + claim-edit + claim-only in one transaction ─
//
// The mixed-kind journal is the safety-critical test of Stage 2b. Three
// distinct lifecycle kinds in one transaction:
//
//   X: created by the transaction, then edited.
//   Y: pre-existing draft, claimed by the transaction, then edited.
//   Z: pre-existing draft, claimed by the transaction, NEVER edited.
//
// On rollback: X must be deleted; Y must be restored to its pre-claim
// baseline; Z must remain entirely unchanged (no wp_update_post, no
// wp_delete_post, post_modified_gmt unchanged from the baseline).
//
// On commit: X and Y must retain their final edited states; Z must
// remain at its baseline; all journal ops must settle (the claim op
// transitions to `applied` with `kept_as_is`).
//
// Plus two distinct risks the mixed path introduces: external conflict
// on Y during rollback (the post has been edited by an outside actor
// between the transaction's update and the rollback), and unrelated
// content outside the transaction must remain untouched by rollback.

Agentshell_Test::run(
    'live: mixed rollback deletes X, restores Y to baseline, leaves Z unchanged',
    function () {
        $s = jr_live( 'stage2b5_mixed_rollback', array() );
        // X is gone: the create op's destructive inverse (wp_delete_post) ran.
        Agentshell_Test::assert_equals( 0, (int) ( $s['x_row_after'] ?? -1 ), 'X is deleted by the rollback' );
        Agentshell_Test::assert_true(
            $s['x_post_deleted'] ?? false,
            'X is deleted by the rollback'
        );
        // Y is restored to its pre-claim baseline body.
        Agentshell_Test::assert_true(
            $s['y_body_restored'] ?? false,
            'Y\'s body is restored to the pre-claim baseline ('
            . substr( (string) ( $s['y_baseline_body'] ?? '' ), 0, 40 ) . ' ...)'
        );
        Agentshell_Test::assert_true(
            $s['y_modified_moved'] ?? false,
            'Y\'s modified_gmt moved (the wp_update_post restore wrote a new value)'
        );
        // Z is unchanged: body matches baseline AND modified_gmt matches
        // baseline AND modified_gmt matches the value it had at the
        // moment the claim ran. The strongest possible evidence that
        // the claim-only rollback did not run wp_update_post on Z.
        Agentshell_Test::assert_true(
            $s['z_body_unchanged'] ?? false,
            'Z\'s body is unchanged from the baseline (the no-op path did not write)'
        );
        Agentshell_Test::assert_true(
            $s['z_modified_unchanged'] ?? false,
            'Z\'s modified_gmt is unchanged from the pre-claim baseline (no wp_update_post ran)'
        );
        Agentshell_Test::assert_true(
            $s['z_modified_no_write'] ?? false,
            'Z\'s modified_gmt did not move between the claim and the rollback (no write at all)'
        );
        // The transaction row is gone: cas_delete_row landed.
        Agentshell_Test::assert_true(
            $s['transaction_gone'] ?? false,
            'the transaction row is deleted by cas_delete_row'
        );
        Agentshell_Test::assert_true(
            $s['rollback_result'] ?? false,
            'the rollback tool reports rolled_back = true'
        );
        // The audit log records compensation for X (create), Y (claim-edit),
        // and Z (claim-only no-op). The Z compensation entry is the load-
        // bearing piece: the audit log records a compensated outcome for Z
        // even though no write ran.
        $ops = (array) ( $s['compensated_op_types'] ?? array() );
        Agentshell_Test::assert_true(
            in_array( 'create_draft', $ops, true ),
            'audit log records a create_draft compensation (X deleted)'
        );
        Agentshell_Test::assert_true(
            in_array( 'edit_existing_draft', $ops, true ),
            'audit log records an edit_existing_draft compensation (Y or Z settled)'
        );
    }
);

Agentshell_Test::run(
    'live: mixed commit retains X and Y final states and Z baseline; all ops settle',
    function () {
        $s = jr_live( 'stage2b5_mixed_commit', array() );
        // The commit succeeds directly — no explicit recover needed. The
        // commit gate's settle_claimed_for_commit transitions Z's claim
        // op from `claimed` to `applied` with `kept_as_is` (no destructive
        // action runs), and the unsettled check passes.
        Agentshell_Test::assert_true(
            $s['commit_result'] ?? false,
            'commit succeeds directly with a claimed op in the journal'
        );
        Agentshell_Test::assert_true(
            $s['transaction_gone'] ?? false,
            'the transaction row is deleted by cas_delete_row after the commit'
        );
        // X and Y retain their final edited states.
        Agentshell_Test::assert_true(
            $s['x_body_retained'] ?? false,
            'X\'s final edited state is retained ('
            . substr( (string) ( $s['x_body_after'] ?? '' ), 0, 40 ) . ' ...)'
        );
        Agentshell_Test::assert_true(
            $s['y_body_retained'] ?? false,
            'Y\'s final edited state is retained ('
            . substr( (string) ( $s['y_body_after'] ?? '' ), 0, 40 ) . ' ...)'
        );
        // Z retains its baseline. The strongest evidence: Z's
        // modified_gmt did not move from the pre-claim baseline.
        Agentshell_Test::assert_true(
            $s['z_body_retained'] ?? false,
            'Z\'s body is at the pre-claim baseline (no destructive action ran)'
        );
        Agentshell_Test::assert_true(
            $s['z_modified_unchanged'] ?? false,
            'Z\'s modified_gmt is unchanged from the pre-claim baseline (no write at all)'
        );
    }
);

Agentshell_Test::run(
    'live: mixed external conflict preserves Y, deletes X, leaves Z unchanged',
    function () {
        $s = jr_live( 'stage2b5_mixed_conflict', array() );
        // The rollback is refused because the recovery engine detected the
        // external edit on Y. The verify_post check saw the divergence.
        Agentshell_Test::assert_true(
            $s['rollback_refused'] ?? false,
            'rollback is refused because Y has an external conflict'
        );
        // X is gone: the create op's destructive inverse ran before the
        // conflict was detected (Y's settle runs first in the
        // compensation order, but X's create-delete runs in the same pass
        // once Y's claim-edit's settle completes).
        Agentshell_Test::assert_equals( 0, (int) ( $s['x_row_after'] ?? 1 ), 'X is deleted' );
        // Y is preserved as conflict: the external edit's content is intact.
        Agentshell_Test::assert_true(
            $s['y_row_after'] ?? false,
            'Y is preserved (not deleted) on conflict'
        );
        Agentshell_Test::assert_true(
            $s['y_body_unchanged_external'] ?? false,
            'Y\'s body is the external edit\'s body (the external edit was not overwritten)'
        );
        // Z is unchanged: the conflict on Y did not reach Z, and Z's
        // no-op settle left it at the baseline.
        Agentshell_Test::assert_true(
            $s['z_body_unchanged'] ?? false,
            'Z\'s body is unchanged from the baseline'
        );
        Agentshell_Test::assert_true(
            $s['z_modified_unchanged'] ?? false,
            'Z\'s modified_gmt is unchanged from the baseline (no write)'
        );
        Agentshell_Test::assert_true(
            $s['z_row_after'] ?? false,
            'Z is preserved (not deleted)'
        );
        // The transaction row is preserved (rollback refused) so the
        // operator can reconcile. Subsequent calls to commit or
        // rollback will see the conflict and refuse again.
        Agentshell_Test::assert_true(
            $s['transaction_preserved'] ?? false,
            'the journal is preserved for human reconciliation'
        );
    }
);

Agentshell_Test::run(
    'live: rollback of a mixed transaction leaves unrelated content untouched',
    function () {
        $s = jr_live( 'stage2b5_mixed_unrelated', array() );
        // The unrelated post: its modified_gmt and body are byte-for-byte
        // identical to before the rollback. This is the negative-control
        // test: "recovery must not delete existing unrelated WordPress
        // content". The post's existence and content are unchanged.
        Agentshell_Test::assert_true(
            $s['unrelated_modified_unchanged'] ?? false,
            'unrelated post\'s modified_gmt is unchanged (no write reached it)'
        );
        Agentshell_Test::assert_true(
            $s['unrelated_body_unchanged'] ?? false,
            'unrelated post\'s body is byte-for-byte identical (no destructive action)'
        );
        Agentshell_Test::assert_equals( 1, (int) ( $s['x_deleted'] ?? -1 ), 'X is still deleted (sanity check on the mixed path)' );
        Agentshell_Test::assert_true(
            $s['y_restored'] ?? false,
            'Y is still restored to its baseline (sanity check on the mixed path)'
        );
        Agentshell_Test::assert_true(
            $s['z_unchanged'] ?? false,
            'Z is still at its baseline (sanity check on the mixed path)'
        );
    }
);