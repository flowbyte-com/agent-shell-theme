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

$config = agentshell_wp_live_config();
if ( null === $config ) {
    Agentshell_Test::run(
        'live journal-recovery suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no WordPress install found' );
        }
    );
    echo "  (skipped: no WordPress install found; set AGENTSHELL_TEST_WP_ROOT to enable)\n";
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
    'live: a crash after the write is found through the marker and cleaned up',
    function () {
        $c = jr_live( 'crash_after_write', array() );
        Agentshell_Test::assert_equals( 'compensated', $c['outcome'] ?? '', 'the orphan must be compensated' );
        Agentshell_Test::assert_true( (int) ( $c['deleted'] ?? 0 ) > 0, 'a post must have been identified' );
        Agentshell_Test::assert_equals( 0, (int) ( $c['rows_after'] ?? -1 ), 'and it must be gone from wp_posts' );
        Agentshell_Test::assert_equals( 0, (int) ( $c['meta_after'] ?? -1 ), 'with its metadata' );
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
        Agentshell_Test::assert_equals( 0, (int) ( $c['stray_marker_meta'] ?? -1 ), 'no journal marker may be left on any post' );
        Agentshell_Test::assert_true( $c['transaction_restored'] ?? false, 'the transaction row must be as it was' );
        Agentshell_Test::assert_true( $c['audit_restored'] ?? false, 'and so must the audit log' );
    }
);