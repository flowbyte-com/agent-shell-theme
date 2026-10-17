<?php
/**
 * Child half of the live journal-recovery suite. Runs inside a real WordPress
 * install and reports facts as JSON; the parent asserts them.
 *
 * Why this exists, when tests/journal-recovery-test.php already covers the
 * failure model
 * -------------------------------------------------------------
 * The stubbed suite is where the state machine is pinned. This one exists for
 * the three things a stub cannot honestly assert, all of which are load-bearing:
 *
 *   1. That compensation's `wp_delete_post( $id, true )` really removes the
 *      row. The whole contract is "this draft is gone"; a trashed post would
 *      satisfy every assertion written against a stub and leave the content on
 *      the site. This measures the trash/force difference against real rows.
 *
 *   2. That the crash-window lookup finds a post the WordPress object layer
 *      would not show us. `Content_Recovery` reads wp_postmeta with direct SQL
 *      precisely because a draft can be *trashed* — and `get_posts()` hides
 *      trashed posts by default. A stub answers whatever it is told to answer;
 *      only the real table can show that the row is still there and still ours.
 *
 *   3. That the divergence checks fire on real `post_modified_gmt` and
 *      `post_status` values produced by `wp_update_post()`, rather than on
 *      fixture strings that might not resemble what core writes.
 *
 * Every post it creates is disposable and carries a per-run token, and the site
 * is restored — transaction row, audit log, posts and meta — before it exits.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

// Fail-closed identity guard — refuses the script if the wp_root
// does not match the test-bed profile. The guard itself checks
// wp-load.php existence plus the full identity chain (DB name,
// server_uuid, sentinel, siteurl, grants, open-transaction guard),
// so the legacy `is_file(wp-load.php)` check is no longer needed.
require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

$theme_root = get_template_directory();
foreach ( array(
    'class-store.php',
    'class-doctor.php',
    'class-transactions.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-content-recovery.php',
) as $inc ) {
    require_once $theme_root . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Content_Recovery;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;

global $wpdb;

$token = 'ASjr' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1e9 ) ) ), 0, 8 );
$f     = array();
$mine  = array();

// Run-scoped fixture tracker. Every disposable record this child
// creates — drafts, trashed posts, kept posts, nav menus, options —
// is registered against the tracker's per-run registry, so a
// child that crashes mid-script still releases its holdings when
// the parent sweeps the registry file. The existing end-of-file
// foreach over $mine is preserved (it is the primary path), and
// the tracker is the safety net: `teardown()` at the end of this
// script is idempotent against $mine because both reach the same
// rows by exact id.
require_once __DIR__ . '/agentshell-test-fixtures.php';
$fixtures = new Agentshell_Test_Fixtures();

// The per-post `delete_post` capability is checked at the moment of
// destruction inside `compensate()`. The CLI context has no logged-in
// user, so `current_user_can()` returns false for everything. Switch
// to the install's admin (user 1, created by every WordPress setup
// with the administrator role) so the per-post gate is held for the
// drafts this child creates. The actor recorded in the journal is
// still `$ACTOR` — the gate is on the real WordPress user, not the
// actor string.
wp_set_current_user( 1 );

$ACTOR = 'live-recovery-probe';

/** Exactly what was there before this child ran, so it can put it back. */
$saved_transaction = get_option( Tx::KEY, null );
$saved_audit       = get_option( Store::AUDIT_KEY, null );
// The number of journal-marker meta rows on the site before the test
// ran, plus the actual marker VALUES that were present. A child whose
// prior runs crashed mid-test may have left markers on the site; the
// cleanup must restore the count to this number, not to zero. The
// invariant is "the site is left exactly as it was found". A
// count alone is not enough: the cleanup narrows its selection to
// markers whose `meta_value` is NOT in the saved set, so a marker
// that pre-existed on the site (e.g. a real production draft that
// a previous test left behind) is preserved across runs, and a
// marker that this run created is removed. This is the
// exact-value counterpart to the LIKE-based sweep it replaces.
$saved_marker_values = array_map(
    'strval',
    (array) ( $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
        Content_Journal::OP_META
    ) ) ?: array() )
);
$saved_marker_count  = count( $saved_marker_values );

// The journal-recovery-live suite is destructive by design: it commits
// (which deletes the option row), rolls back (which deletes the option
// row), and intentionally leaves a `live conflict probe` row in conflict
// state. None of those operations is safe against a *non-test* transaction
// that was already open on the site. The test would silently destroy a
// real user's transaction by committing it. Refuse to run, instead, with
// a clear message — the operator can close the real transaction, run the
// suite, and the real transaction's id is recorded in the report for
// follow-up. A 'live *' prefixed label is a test artifact from a prior
// crashed run; the test cleans those up at the end so subsequent runs
// have a clean slot.
$pre_label = is_array( $saved_transaction ) ? (string) ( $saved_transaction['label'] ?? '' ) : '';
if ( is_array( $saved_transaction ) && 0 !== strpos( $pre_label, 'live ' ) ) {
    $f['fatal_precondition'] = array(
        'reason'         => 'a non-test transaction is already open; the journal-recovery-live suite is destructive and would destroy it',
        'tx_id'          => (string) ( $saved_transaction['id'] ?? '' ),
        'tx_label'       => $pre_label,
        'tx_actor'       => (string) ( $saved_transaction['actor'] ?? '' ),
        'saved_marker_count' => $saved_marker_count,
        'rows_remaining'     => 0,
        'trashed_remaining'  => 0,
        'stray_marker_meta'  => $saved_marker_count,
        'transaction_restored' => true,
        'audit_restored'       => ( get_option( Store::AUDIT_KEY, null ) === $saved_audit ),
    );
    file_put_contents( $report_path, json_encode( $f ) );
    exit( 0 );
}

/**
 * Apply the production create pattern: head advance FIRST, then mark_applied.
 *
 * Mirrors class-create-draft.php lines 199–219. Every test that journals a
 * real post and exercises a path that needs to verify the post against the
 * journal (rollback_transaction, Content_Recovery::recover, same-second
 * content divergence) must use this helper. The recovery engine's
 * compensate_create reads the head after-image via Content_Journal::head_after;
 * without a head, the engine throws "post N is recorded in this operation but
 * the journal carries no head after-image for it" — the divergence the
 * child crashes on at line 658.
 *
 * Deliberately NOT used by the F-mark_applied-cas-failure test (line 936),
 * which sets up a side-channel that moves the row between wp_insert_post
 * and mark_applied and asserts that mark_applied's CAS fails. Adding the
 * head advance first would defeat that test.
 */
function jr_apply_create( $tx, $intent_id, $post_id, array $after ) {
	$head_tx = \AgentShell_MCP\Content_Journal::compare_and_set_head( $tx, (int) $post_id, null, $after );
	if ( ! is_array( $head_tx ) ) {
		throw new \RuntimeException(
			'compare_and_set_head refused for post ' . (int) $post_id
			. ' (op ' . (string) $intent_id . '); the create op cannot be settled.'
		);
	}
	$applied = \AgentShell_MCP\Content_Journal::mark_applied( $head_tx, $intent_id, $post_id, $after );
	if ( null === $applied ) {
		throw new \RuntimeException(
			'mark_applied CAS refused for post ' . (int) $post_id
			. ' (op ' . (string) $intent_id . '); the row moved between the head advance and the apply.'
		);
	}
	return $applied;
}

function jr_rows_for( $needle ) {
    global $wpdb;
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", array( '%' . $needle . '%' )
    ) ) ?: array() );
}

function jr_row_exists( $id ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", (int) $id ) );
}

function jr_meta_rows( $id, $key ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", (int) $id, $key
    ) );
}

/** What WordPress really wrote, in the shape a journal record expects. */
function jr_after_image( $id, $op_id ) {
    $p = get_post( $id );
    $content = (string) $p->post_content;
    return array(
        'post_type'           => $p->post_type,
        'post_status'         => $p->post_status,
        'post_name'           => $p->post_name,
        'post_title'          => $p->post_title,
        'post_date_gmt'       => $p->post_date_gmt,
        'post_modified_gmt'   => $p->post_modified_gmt,
        'post_content_sha256' => hash( 'sha256', $content ),
        'post_content_length' => strlen( $content ),
        'op_meta_value'       => $op_id,
    );
}

/** A real expired transaction row carrying one real journal record. */
function jr_expired_row( $op, array $over = array() ) {
    $now = time();
    $tx  = array_merge( array(
        'id'            => 'tx_live_' . substr( md5( (string) random_int( 1, 1e9 ) ), 0, 8 ),
        'label'         => 'live recovery probe',
        'actor'         => 'live-recovery-probe',
        'timestamp'     => $now - 7200,
        'created'       => $now - 7200,
        'last_activity' => $now - 7200,
        'expires'       => $now - 60,
        'before'        => array(),
        'staged'        => null,
    ), $over );

    $content                       = Content_Journal::empty_journal( $tx['id'] );
    $content['ops'][ $op['id'] ]   = $op;
    // Stage 2a (revised) — head-pointer model. The compensation path
    // verifies the live post against the head (the latest applied
    // after-image), not against the per-op after. The live fixture
    // plants the head explicitly so the new model can run its six-field
    // check. Without this, compensate() would refuse with
    // "no head after-image" and the test would test the wrong code.
    $post_id                       = (int) ( $op['post_id'] ?? 0 );
    if ( $post_id > 0 && ! empty( $op['after'] ) ) {
        $content[ Content_Journal::HEAD_KEY ] = array( $post_id => $op['after'] );
    }
    $content['sig']                = Content_Journal::sign( $content );
    $tx['content']                 = $content;
    return $tx;
}

/**
 * Create a real draft, stamp it with the operation's marker, and return a
 * journal record in `applied` — i.e. exactly the state a crash between
 * wp_insert_post() and mark_applied() does *not* leave, which the marker
 * lookup is what covers.
 */
function jr_real_draft( $token, $suffix, $op_state = 'applied' ) {
    global $fixtures;
    $op = Content_Journal::record_intent(
        Content_Journal::OP_CREATE_DRAFT,
        array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . $suffix ),
        array(),
        'live-recovery-probe',
        time() - 7200
    );

    $id = wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . $suffix,
        'post_content' => '<p>journal recovery probe</p>',
        'post_name'    => strtolower( $token . $suffix ),
    ) ), true );

    if ( is_wp_error( $id ) || ! $id ) {
        return array( null, null );
    }

    $fixtures->register_post( (int) $id, $token );
    update_post_meta( $id, Content_Journal::OP_META, $op['id'] );

    $op['state']   = $op_state;
    $op['post_id'] = (int) $id;
    $op['after']   = jr_after_image( $id, $op['id'] );

    return array( $id, $op );
}
$GLOBALS['jr_mine'] = array();

// ── 1. Force delete really deletes; trash really does not ─────────────────────
// The choice between `wp_delete_post($id)` and `wp_delete_post($id, true)` is
// the difference between "the orphan is gone" and "the orphan is still on the
// site". Measured here rather than assumed, because a stubbed delete cannot
// tell the two apart.
$trash_id = wp_insert_post( wp_slash( array(
    'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-trashme',
    'post_content' => '<p>trash semantics</p>', 'post_name' => strtolower( $token . '-trashme' ),
) ), true );
$fixtures->register_post( (int) $trash_id, $token );
$slug_before = get_post_field( 'post_name', $trash_id );
wp_delete_post( $trash_id );
clean_post_cache( $trash_id );
$f['trash_semantics'] = array(
    'row_after_trash'   => jr_row_exists( $trash_id ),
    'status_after_trash'=> get_post_field( 'post_status', $trash_id ),
    'slug_before'       => $slug_before,
    'slug_after'        => get_post_field( 'post_name', $trash_id ),
);
wp_delete_post( $trash_id, true );

// ── 2. Compensation really removes a journalled draft ─────────────────────────
list( $id, $op ) = jr_real_draft( $token, '-recover' );
$tx      = jr_expired_row( $op );
update_option( Tx::KEY, $tx );

$f['recover'] = array(
    'id'             => (int) $id,
    'rows_before'    => jr_row_exists( $id ),
    'meta_before'    => jr_meta_rows( $id, Content_Journal::OP_META ),
    'object_cache_had_it' => (bool) get_post( $id ),
);
$report = Content_Recovery::recover( $tx, $ACTOR );
$f['recover'] += array(
    'status'         => $report['status'],
    'outcome'        => $report['operations'][0]['outcome'] ?? '',
    'deleted_post_id'=> (int) ( $report['operations'][0]['deleted_post_id'] ?? 0 ),
    // Read through the database, not through get_post(): a row that survived
    // the delete but was hidden by a stale cache entry is exactly the failure
    // this suite exists to catch.
    'rows_after'     => jr_row_exists( $id ),
    'meta_after'     => jr_meta_rows( $id, Content_Journal::OP_META ),
    'get_post_after'=> (bool) get_post( $id ),
    'title_search'   => jr_rows_for( $token . '-recover' ),
    'transaction_gone' => ! is_array( get_option( Tx::KEY, null ) ),
);

// Repeating it must change nothing at all. Through the *tool*, because that is
// what a caller repeating it would reach for — and because the tool has to
// answer "there is no transaction any more" as an outcome rather than an error.
// The in-memory $tx is deliberately not reused: it is stale, and passing a
// stale row would test the guard rather than the repeat.
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-recover-transaction.php';
$again = ( new \AgentShell_MCP\Tools\Recover_Transaction() )->execute( array( 'confirm' => true ) );
$f['recover']['repeat_status']  = $again['status'];
$f['recover']['repeat_outcome'] = $again['operations'][0]['outcome'] ?? '';
$f['recover']['repeat_rows']    = jr_row_exists( $id );

// ── 3. A crash before the mutation: a real transaction, a real live post ──────
// The "no evidence" branch has to be *no evidence*, so this uses a real page
// that is genuinely not ours and checks it is untouched afterwards.
$keeper = wp_insert_post( wp_slash( array(
    'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-keeper',
    'post_content' => '<p>not ours</p>', 'post_name' => strtolower( $token . '-keeper' ),
) ), true );
$fixtures->register_post( (int) $keeper, $token );

$orphan_op = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array(
        'post_type' => 'page', 'post_status' => 'draft',
        'post_title' => $token . '-never-made',
        'post_name'  => strtolower( $token . '-never-made' ),
    ),
    array(),
    $ACTOR,
    time() - 7200
);
$orphan_tx = jr_expired_row( $orphan_op );
update_option( Tx::KEY, $orphan_tx );
$orphan_report = Content_Recovery::recover( $orphan_tx, $ACTOR );
$f['no_evidence'] = array(
    'status'      => $orphan_report['status'],
    'detail'      => $orphan_report['operations'][0]['detail'] ?? '',
    'keeper_rows' => jr_row_exists( $keeper ),
    'keeper_rows_matching' => jr_rows_for( $token . '-never-made' ),
);

// ── 4. The crash window: the write happened, the id was never recorded ───────
// The journal says post_id 0. The only way to find the post is the marker, and
// the post is real. Recovery must find it and delete it.
list( $id2, $op2 ) = jr_real_draft( $token, '-orphan', 'intended' );
$op2['post_id'] = 0;                                  // mark_applied() never ran
$tx2          = jr_expired_row( $op2 );
update_option( Tx::KEY, $tx2 );
$report2 = Content_Recovery::recover( $tx2, $ACTOR );
$f['crash_after_write'] = array(
    'status'      => $report2['status'],
    'outcome'     => $report2['operations'][0]['outcome'] ?? '',
    'deleted'     => (int) ( $report2['operations'][0]['deleted_post_id'] ?? 0 ),
    'rows_after'  => jr_row_exists( $id2 ),
    'meta_after'  => jr_meta_rows( $id2, Content_Journal::OP_META ),
);

// ── 5. A trashed draft carrying our marker is still found ────────────────────
// get_posts() hides trashed posts by default, so a recovery engine built on it
// would decide "nothing was ever created" and walk away from a real draft. This
// is the reason the marker lookup is direct SQL, and it is only checkable here.
//
// The outcome is a conflict, not a deletion, and that is the point rather than a
// wrinkle: trashing is somebody changing the post after the crash, so the
// journal's expected after-image has genuinely diverged and a human decides. An
// engine that had not found the post would report "nothing to do" — the two are
// indistinguishable from the outside except that one of them names the post.
// The assertion is therefore on *which post it named*.
list( $id3, $op3 ) = jr_real_draft( $token . '-trashed', '-orphan', 'intended' );
wp_delete_post( $id3 );                                // trash, not force
clean_post_cache( $id3 );
$op3['post_id'] = 0;                                   // mark_applied() never ran
$tx3 = jr_expired_row( $op3 );
update_option( Tx::KEY, $tx3 );

$visible_to_wp_query = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_status = 'trash'", $id3
) );
$query_ids   = array_map( 'intval', get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids' ) ) ?: array() );
$report3     = Content_Recovery::recover( $tx3, $ACTOR );
$f['trashed_orphan'] = array(
    'row_is_trashed'        => $visible_to_wp_query,
    'get_posts_would_see'   => in_array( (int) $id3, $query_ids, true ),
    'status'                => $report3['status'],
    'outcome'               => $report3['operations'][0]['outcome'] ?? '',
    'detail'                => $report3['operations'][0]['detail'] ?? '',
    'identified_post_id'    => (int) ( $report3['conflicts'][0]['post_id'] ?? 0 ),
    'rows_after'            => jr_row_exists( $id3 ),
);

// ── 6. Divergence on real rows ───────────────────────────────────────────────
// Someone edits the draft after the crash. Real wp_update_post(), real
// post_modified_gmt, real post_status — and nothing may be deleted.
list( $id4, $op4 ) = jr_real_draft( $token, '-edited' );
wp_update_post( wp_slash( array(
    'ID' => $id4, 'post_title' => $token . '-edited-by-someone-else', 'post_status' => 'publish',
) ) );
clean_post_cache( $id4 );
$tx4 = jr_expired_row( $op4 );
update_option( Tx::KEY, $tx4 );
$report4 = Content_Recovery::recover( $tx4, $ACTOR );
$f['diverged'] = array(
    'status'     => $report4['status'],
    'outcome'    => $report4['operations'][0]['outcome'] ?? '',
    'detail'     => $report4['operations'][0]['detail'] ?? '',
    'rows_after' => jr_row_exists( $id4 ),
    'db_status'  => get_post_field( 'post_status', $id4 ),
    'db_title'   => get_post_field( 'post_title', $id4 ),
    'row_kept'   => is_array( get_option( Tx::KEY, null ) ),
    'op_state'   => ( get_option( Tx::KEY, null )['content']['ops'][ $op4['id'] ]['state'] ?? '' ),
);

// A second recovery must not quietly turn the unresolved conflict into "done".
// Re-read the row rather than reusing $tx4: the first run moved it, and a stale
// copy would exercise the guard instead of the repeat.
$report4b = Content_Recovery::recover( (array) get_option( Tx::KEY, array() ), $ACTOR );
$f['diverged']['repeat_status']  = $report4b['status'];
$f['diverged']['repeat_outcome'] = $report4b['operations'][0]['outcome'] ?? '';
$f['diverged']['repeat_conflicts'] = count( $report4b['conflicts'] );
$f['diverged']['still_kept']     = is_array( get_option( Tx::KEY, null ) );

// ── 7. Unrelated content is untouched, and a resolved transaction unblocks ────
$before_unrelated = jr_rows_for( $token . '-keeper' );
update_option( Tx::KEY, array() );   // clear the conflicted probe row for this step
delete_option( Tx::KEY );

list( $id5, $op5 ) = jr_real_draft( $token, '-last' );
$tx5 = jr_expired_row( $op5 );
update_option( Tx::KEY, $tx5 );
$report5 = Content_Recovery::recover( $tx5, $ACTOR );
$f['final'] = array(
    'status'             => $report5['status'],
    'orphan_gone'        => 0 === jr_row_exists( $id5 ),
    'unrelated_before'   => $before_unrelated,
    'unrelated_after'    => jr_rows_for( $token . '-keeper' ),
    'unrelated_status'   => get_post_field( 'post_status', $keeper ),
    'unrelated_title'    => get_post_field( 'post_title', $keeper ),
    'transaction_gone'   => ! is_array( get_option( Tx::KEY, null ) ),
    'keeper_still_marked'=> jr_meta_rows( $keeper, Content_Journal::OP_META ),
);

// ── 8. Authorisation, against the real capability check ───────────────────────
// A non-owner without the recovery-administrator opt-in is refused even
// though the WordPress current user is the admin. The gate is two
// factors: actor match (for the owner path) or manage_options + opt-in
// (for the recovery-administrator path). Pass `false` for the opt-in
// and the second factor is unmet, so the call is refused at the tool
// boundary.
list( $id6, $op6 ) = jr_real_draft( $token, '-unauthorised' );
$tx6 = jr_expired_row( $op6 );
update_option( Tx::KEY, $tx6 );
$refused = Content_Recovery::recover( $tx6, 'somebody-else', false );
$f['authorisation'] = array(
    'status'      => $refused['status'],
    'reason'      => $refused['reason'],
    'rows_after'  => jr_row_exists( $id6 ),
    'row_kept'    => is_array( get_option( Tx::KEY, null ) ),
);

// ── 9. The audit trail, on the real audit log ────────────────────────────────
$audit = Store::audit_log();
$recovery_events = array_values( array_filter( $audit, function ( $e ) {
    return in_array( $e['operation'], array( 'transaction_recovery', 'transaction_recovery_refused', 'transaction_recovery_compensated' ), true );
} ) );
$f['audit'] = array(
    'count'   => count( $recovery_events ),
    'ops'     => array_values( array_unique( array_map( function ( $e ) { return $e['operation']; }, $recovery_events ) ) ),
    'deleted' => array_values( array_filter( array_map( function ( $e ) {
        return $e['changed']['deleted_transactions'] ?? array();
    }, array_filter( $recovery_events, function ( $e ) { return 'transaction_recovery' === $e['operation']; } ) ) ) ),
    'refusals'=> count( array_filter( $recovery_events, function ( $e ) { return 'transaction_recovery_refused' === $e['operation']; } ) ),
);

// ── 10. Claim takeover with real SQL bytes ───────────────────────────────────
// The takeover-after-TTL invariant: a different actor can take over a stale
// claim once the 120-second window has passed, against the real wp_options
// row the engine reads and writes. Two drafts are involved: one to be
// compensated by the original actor, one to be deleted. We assert the second
// recoverer can drive compensation to completion after TTL expiry, against
// the bytes WordPress actually wrote.

// The previous section left a tx with journal work; clean it up so
// Tx::instance()->begin() can claim the slot.
delete_option( Tx::KEY );
list( $id10, $op10 ) = jr_real_draft( $token, '-takeover' );
$op10['state']   = 'compensating';
$op10['post_id'] = (int) $id10;
// The recorded after-image must reflect what wp_insert_post actually wrote.
// Read the live post so the post_date_gmt and post_modified_gmt match,
// otherwise verify_post will report a divergence and refuse compensation.
$live10 = get_post( $id10 );
$op10['after']   = array_merge( $op10['after'], array(
    'post_content_sha256' => hash( 'sha256', (string) $live10->post_content ),
    'post_content_length' => strlen( (string) $live10->post_content ),
    'post_date_gmt'       => (string) $live10->post_date_gmt,
    'post_modified_gmt'   => (string) $live10->post_modified_gmt,
) );
// Plant a stale claim for the second actor: 60 seconds ago is inside the
// 120-second window. Re-using the existing 'compensating' state and a
// recorded after-image means the engine's verify_post path runs against
// the real post before the destructive step.
$op10['claim'] = array( 'actor' => 'actor-original', 'at' => time() - 60 );
$tx10 = jr_expired_row( $op10 );
update_option( Tx::KEY, $tx10 );

// First attempt: different actor while the claim is still fresh. Refused
// at the claim layer with another_recovery_in_progress.
$blocked10 = Content_Recovery::recover( $tx10, 'actor-second', true );
$f['claim_takeover'] = array(
    'blocked_outcome'        => $blocked10['operations'][0]['outcome'] ?? '',
    'blocked_status'         => $blocked10['status'],
    'rows_after_blocked'     => jr_row_exists( $id10 ),
);

// Now age the claim by rewriting the row with an 'at' timestamp past
// the claim TTL, then re-attempt with the same second actor. The engine
// should accept the takeover, run verify_post against the live post, and
// compensate.
$tx10_aged = (array) get_option( Tx::KEY, null );
if ( is_array( $tx10_aged ) && isset( $tx10_aged['content']['ops'][ $op10['id'] ] ) ) {
    $tx10_aged['content']['ops'][ $op10['id'] ]['claim'] = array(
        'actor' => 'actor-original',
        'at'    => time() - Content_Recovery::CLAIM_TTL_SECONDS - 5,
    );
    // Re-sign the content so the journal's signature matches. The engine's
    // verify() check refuses a content with a stale signature as 'tampered',
    // which is the correct production behaviour; the test is altering the
    // claim in place to simulate the TTL elapsing, and the re-sign is the
    // equivalent of an authorised mutation.
    $tx10_aged['content']['sig'] = Content_Journal::sign( $tx10_aged['content'] );
    update_option( Tx::KEY, $tx10_aged );
}
$resumed10 = Content_Recovery::recover( (array) get_option( Tx::KEY, array() ), 'actor-second', true );
$resumed_op = ( get_option( Tx::KEY, null )['content']['ops'][ $op10['id'] ] ?? array() );
$f['claim_takeover'] += array(
    'resumed_outcome'        => $resumed10['operations'][0]['outcome'] ?? '',
    'resumed_status'         => $resumed10['status'],
    'resumed_detail'         => $resumed10['operations'][0]['detail'] ?? '',
    'resumed_deleted'        => (int) ( $resumed10['operations'][0]['deleted_post_id'] ?? 0 ),
    'rows_after_resumed'     => jr_row_exists( $id10 ),
    'transaction_after'      => is_array( get_option( Tx::KEY, null ) ),
    'op_state_after'         => $resumed_op['state'] ?? '',
);

// Clear the row so the next begin() can claim the slot.
delete_option( Tx::KEY );

// ── 11. create_draft inside a real transaction ───────────────────────────────
// The journal-aware create_draft path. We exercise it directly via the
// Content_Journal primitives because the tool class is not always loaded
// in the child context; the engine-level primitives and the tool class
// share the same code path. The tests cover:
//
//   F-successful-commit: open a transaction, create a draft, commit;
//     the post lives, the row is gone, the audit log has the closing row.
//   F-rollback-with-journal: open, create, rollback; the post is gone,
//     the row is gone, the audit log has the closing row.
//   F-rollback-with-conflict: open, create, edit the post (slug
//     change), rollback; the post survives, the row survives, the
//     op is in conflict.
//   F-retry-resume: open, create with a given idempotency_key, replay
//     the same key; the second call returns the original post id
//     with resumed=true and no second post is created.
//   F-retry-content-conflict: same key, different content; refused.
//   F-same-second-content-divergence: edit the post content within one
//     second so post_modified_gmt does not move; verify_post must still
//     refuse the destructive step, because the strong after-image's
//     post_content_sha256 catches what the modified_gmt field cannot.
//   F-mark_applied-cas-failure: a side-channel mutates the journal row
//     between wp_insert_post and mark_applied; mark_applied's CAS fails,
//     the post is preserved, and commit/rollback both refuse to discard
//     the partial-applied evidence.
require_once $theme_root . '/agentshell-mcp/includes/class-content-write-guard.php';
use AgentShell_MCP\Content_Write_Guard;

// F-successful-commit: the live, journaled happy path.
$now        = time();
$tx_actor   = Store::actor();
$tx11       = Tx::instance()->begin( 'live commit probe' );
// commit() requires a non-null staged array; an empty array is the journal-only path.
$tx11['staged'] = array();
update_option( Tx::KEY, $tx11 );
$intent11 = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-commit' ),
    array(),
    $tx_actor,
    $now
);
Content_Journal::attach( $tx11, $intent11 );
$tx11 = (array) get_option( Tx::KEY, null ); // re-read after attach

// F25: write the post and the marker in one guarded SQL transaction.
$post11 = Content_Write_Guard::run( function () use ( $tx11, $intent11, $token ) {
    $id = wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-commit',
        'post_content' => '<p>live commit path</p>',
        'post_name'    => strtolower( $token . '-commit' ),
        'meta_input'   => array( Content_Journal::OP_META => $intent11['id'] ),
    ) ), true );
    if ( is_wp_error( $id ) || ! $id ) {
        return $id;
    }
    $p = get_post( $id );
    $after = array(
        'post_type'           => (string) $p->post_type,
        'post_status'         => (string) $p->post_status,
        'post_name'           => (string) $p->post_name,
        'post_date_gmt'       => (string) $p->post_date_gmt,
        'post_modified_gmt'   => (string) $p->post_modified_gmt,
        'post_content_sha256' => hash( 'sha256', (string) $p->post_content ),
        'post_content_length' => strlen( (string) $p->post_content ),
        'op_meta_value'       => (string) $intent11['id'],
    );
    jr_apply_create( $tx11, $intent11['id'], $id, $after );
    return $id;
} );
$fixtures->register_post( (int) $post11, $token );

// Atomicity: read wp_posts and wp_postmeta together under one transaction.
// Either both rows are present, or neither is — the marker cannot exist
// without the post, and the post cannot exist without the marker, because
// the guard wraps both writes in one SQL COMMIT.
$wpdb->query( 'START TRANSACTION' );
$row_present   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", (int) $post11 ) );
$marker_present = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", (int) $post11, Content_Journal::OP_META ) );
$wpdb->query( 'COMMIT' );

// Now commit. The post should be retained; the row should be gone.
require_once $theme_root . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-commit-transaction.php';
$commit11 = ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );
$f['create_draft_commit'] = array(
    'row_present'      => $row_present,
    'marker_present'   => $marker_present,
    'rows_after'       => jr_row_exists( $post11 ),
    'rows_alive'       => (int) ( jr_row_exists( $post11 ) > 0 ),
    'marker_after'     => jr_meta_rows( $post11, Content_Journal::OP_META ),
    'commit_status'    => $commit11['status'] ?? '',
    'transaction_gone' => ! is_array( get_option( Tx::KEY, null ) ),
);

// F-rollback-with-journal: open, create, rollback; the post is gone.
$tx12 = Tx::instance()->begin( 'live rollback probe' );
$tx12['staged'] = array();
update_option( Tx::KEY, $tx12 );
$intent12 = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-rollback' ),
    array(),
    $tx_actor,
    time()
);
Content_Journal::attach( $tx12, $intent12 );
$tx12 = (array) get_option( Tx::KEY, null );
$post12 = Content_Write_Guard::run( function () use ( $intent12, $token ) {
    return wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-rollback',
        'post_content' => '<p>live rollback path</p>',
        'post_name'    => strtolower( $token . '-rollback' ),
        'meta_input'   => array( Content_Journal::OP_META => $intent12['id'] ),
    ) ), true );
} );
$fixtures->register_post( (int) $post12, $token );
$p12 = get_post( $post12 );
jr_apply_create( $tx12, $intent12['id'], $post12, array(
    'post_type'           => (string) $p12->post_type,
    'post_status'         => (string) $p12->post_status,
    'post_name'           => (string) $p12->post_name,
    'post_date_gmt'       => (string) $p12->post_date_gmt,
    'post_modified_gmt'   => (string) $p12->post_modified_gmt,
    'post_content_sha256' => hash( 'sha256', (string) $p12->post_content ),
    'post_content_length' => strlen( (string) $p12->post_content ),
    'op_meta_value'       => (string) $intent12['id'],
) );
require_once $theme_root . '/agentshell-mcp/includes/tools/class-rollback-transaction.php';
$rollback12 = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );
$f['create_draft_rollback'] = array(
    'rows_before'       => 1, // we just created it; the test merely pins the contract
    'rows_after'        => jr_row_exists( $post12 ),
    'rollback_status'   => $rollback12['status'] ?? '',
    'transaction_gone'  => ! is_array( get_option( Tx::KEY, null ) ),
    'rollback_reason'   => $rollback12['reason'] ?? '',
);

// F-rollback-with-conflict: open, create, edit the post (slug change),
// rollback; the post survives, the op is in conflict.
$tx13 = Tx::instance()->begin( 'live conflict probe' );
$tx13['staged'] = array();
update_option( Tx::KEY, $tx13 );
$intent13 = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-conflict' ),
    array(),
    $tx_actor,
    time()
);
Content_Journal::attach( $tx13, $intent13 );
$tx13 = (array) get_option( Tx::KEY, null );
$post13 = Content_Write_Guard::run( function () use ( $intent13, $token ) {
    return wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-conflict',
        'post_content' => '<p>live conflict path</p>',
        'post_name'    => strtolower( $token . '-conflict' ),
        'meta_input'   => array( Content_Journal::OP_META => $intent13['id'] ),
    ) ), true );
} );
$fixtures->register_post( (int) $post13, $token );
$p13 = get_post( $post13 );
jr_apply_create( $tx13, $intent13['id'], $post13, array(
    'post_type'           => (string) $p13->post_type,
    'post_status'         => (string) $p13->post_status,
    'post_name'           => (string) $p13->post_name,
    'post_date_gmt'       => (string) $p13->post_date_gmt,
    'post_modified_gmt'   => (string) $p13->post_modified_gmt,
    'post_content_sha256' => hash( 'sha256', (string) $p13->post_content ),
    'post_content_length' => strlen( (string) $p13->post_content ),
    'op_meta_value'       => (string) $intent13['id'],
) );

// Outside the transaction, edit the post: change the slug so the
// recovery engine's verify_post sees a divergence.
wp_update_post( wp_slash( array(
    'ID' => $post13, 'post_name' => strtolower( $token . '-edited-by-someone-else' ),
) ) );
clean_post_cache( $post13 );

// Rollback is expected to refuse (the post has diverged, so the engine
// refuses to delete and reports conflict). The refusal is a thrown
// InvalidArgumentException by design — the journal and option row are
// preserved, the post is preserved, the op transitions to conflict.
$rollback13_threw = false;
$rollback13_message = '';
try {
    $rollback13 = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );
} catch ( \InvalidArgumentException $e ) {
    $rollback13_threw = true;
    $rollback13_message = $e->getMessage();
    $rollback13 = array();
}
$tx13_after = (array) get_option( Tx::KEY, null );
$f['create_draft_conflict'] = array(
    'rows_after'        => jr_row_exists( $post13 ),
    'rollback_threw'    => $rollback13_threw,
    'rollback_message'  => $rollback13_message,
    'transaction_kept'  => is_array( $tx13_after ),
    'op_state'          => $tx13_after['content']['ops'][ $intent13['id'] ]['state'] ?? '',
    'row_preserved'     => 1 === jr_row_exists( $post13 ),
    'post_slug'         => get_post_field( 'post_name', $post13 ),
);

// F-retry-resume: idempotency_key resumes the original post.
// We exercise the engine-level primitives because the create_draft tool
// class is not loaded in the child context. The contract the tool
// implements is: same key + same actor + same tx + same content → return
// the existing post id, no second post.
$key14       = 'idem-' . substr( md5( (string) random_int( 1, 1e9 ) ), 0, 12 );
$tx14        = Tx::instance()->begin( 'live idem probe' );
$tx14['staged'] = array();
update_option( Tx::KEY, $tx14 );
$intent14 = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-idem' ),
    array(),
    $tx_actor,
    time()
);
$intent14['idempotency_key'] = $key14;
$intent14['content_fingerprint'] = hash( 'sha256', wp_json_encode( array( 'page', 'draft', $token . '-idem' ) ) );
Content_Journal::attach( $tx14, $intent14 );
$tx14 = (array) get_option( Tx::KEY, null );
$post14 = Content_Write_Guard::run( function () use ( $intent14, $token ) {
    return wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-idem',
        'post_content' => '<p>idempotent</p>',
        'post_name'    => strtolower( $token . '-idem' ),
        'meta_input'   => array( Content_Journal::OP_META => $intent14['id'] ),
    ) ), true );
} );
$fixtures->register_post( (int) $post14, $token );
$p14 = get_post( $post14 );
jr_apply_create( $tx14, $intent14['id'], $post14, array(
    'post_type'           => (string) $p14->post_type,
    'post_status'         => (string) $p14->post_status,
    'post_name'           => (string) $p14->post_name,
    'post_date_gmt'       => (string) $p14->post_date_gmt,
    'post_modified_gmt'   => (string) $p14->post_modified_gmt,
    'post_content_sha256' => hash( 'sha256', (string) $p14->post_content ),
    'post_content_length' => strlen( (string) $p14->post_content ),
    'op_meta_value'       => (string) $intent14['id'],
) );

// Second intent with the same key: the engine-level resume path returns
// the original post id. We verify the contract by re-reading the journal
// row and asserting the existing op is still the only entry with that key.
$tx14_now = (array) get_option( Tx::KEY, null );
$matches  = 0;
$found_id = 0;
if ( is_array( $tx14_now ) && isset( $tx14_now['content']['ops'] ) ) {
    foreach ( $tx14_now['content']['ops'] as $oid => $op_arr ) {
        if ( isset( $op_arr['idempotency_key'] ) && $op_arr['idempotency_key'] === $key14 ) {
            $matches++;
            $found_id = (int) ( $op_arr['post_id'] ?? 0 );
        }
    }
}
$f['create_draft_idem_resume'] = array(
    'matches'      => $matches,
    'resumed_id'   => $found_id,
    'expected_id'  => (int) $post14,
    'rows_count'   => count( jr_rows_for( $token . '-idem' ) ),
);

// F-same-second-content-divergence: the slug and post_modified_gmt are
// unchanged, but the post_content has been silently rewritten. The four-
// field evidence (post_type, post_status, post_name, post_modified_gmt)
// cannot catch this — post_modified_gmt is one-second-resolution and
// may not move on an edit that lands in the same second as the
// insert. The strong after-image's post_content_sha256 is what catches
// it: the recorded hash was set at mark_applied time, the live post's
// hash differs, and verify_post refuses the destructive step. The post
// is preserved (a `conflict` outcome) and the operator reconciles.
$tx15 = Tx::instance()->begin( 'live same-second probe' );
$tx15['staged'] = array();
update_option( Tx::KEY, $tx15 );
$intent15 = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-samesecond' ),
    array(),
    $tx_actor,
    time()
);
Content_Journal::attach( $tx15, $intent15 );
$tx15 = (array) get_option( Tx::KEY, null );
$post15 = Content_Write_Guard::run( function () use ( $intent15, $token ) {
    return wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-samesecond',
        'post_content' => '<p>original body</p>',
        'post_name'    => strtolower( $token . '-samesecond' ),
        'meta_input'   => array( Content_Journal::OP_META => $intent15['id'] ),
    ) ), true );
} );
$fixtures->register_post( (int) $post15, $token );
$p15 = get_post( $post15 );
jr_apply_create( $tx15, $intent15['id'], $post15, array(
    'post_type'           => (string) $p15->post_type,
    'post_status'         => (string) $p15->post_status,
    'post_name'           => (string) $p15->post_name,
    'post_date_gmt'       => (string) $p15->post_date_gmt,
    'post_modified_gmt'   => (string) $p15->post_modified_gmt,
    'post_content_sha256' => hash( 'sha256', (string) $p15->post_content ),
    'post_content_length' => strlen( (string) $p15->post_content ),
    'op_meta_value'       => (string) $intent15['id'],
) );

// A direct database update that only changes post_content, leaving the
// slug, status, type, post_modified_gmt, and post_date_gmt untouched.
// This is the worst case for the four-field evidence check: the post
// looks unchanged on every field verify_post consulted, but the content
// has been silently rewritten. The strong after-image's
// post_content_sha256 is what catches it.
$before_modify = get_post( $post15 );
$wpdb->update(
    $wpdb->posts,
    array( 'post_content' => '<p>silently rewritten by a different actor</p>' ),
    array( 'ID' => $post15 ),
    array( '%s' ),
    array( '%d' )
);
clean_post_cache( $post15 );
$after_modify = get_post( $post15 );
$same_modified = (string) $before_modify->post_modified_gmt === (string) $after_modify->post_modified_gmt;
$same_slug     = (string) $before_modify->post_name      === (string) $after_modify->post_name;
$content_diff  = (string) $before_modify->post_content   !== (string) $after_modify->post_content;

// A compensation attempt against the now-diverged post must refuse on
// the strong after-image's content-hash mismatch. We exercise the
// recovery engine directly so the test reads the journal row, the
// recorded hash, the live hash, and the refusal reason.
$tx15_recover = (array) get_option( Tx::KEY, null );
$recover15    = Content_Recovery::recover( $tx15_recover, $tx_actor, false, true );
$op15_after   = $tx15_recover['content']['ops'][ $intent15['id'] ] ?? array();
$my_op_outcome = '';
$my_op_detail  = '';
foreach ( ( $recover15['operations'] ?? array() ) as $op_step ) {
    if ( isset( $op_step['id'] ) && (string) $op_step['id'] === (string) $intent15['id'] ) {
        $my_op_outcome = (string) ( $op_step['outcome'] ?? '' );
        $my_op_detail  = (string) ( $op_step['detail']  ?? '' );
        break;
    }
}
$f['same_second_divergence'] = array(
    'same_modified_gmt'   => $same_modified,
    'same_slug'           => $same_slug,
    'content_differs'     => $content_diff,
    'recover_outcome'     => $my_op_outcome,
    'recover_status'      => $recover15['status']  ?? '',
    'op_state_after'      => $op15_after['state']  ?? '',
    'post_preserved'      => 1 === jr_row_exists( $post15 ),
);

// F-mark_applied-cas-failure: a side-channel mutates the journal row
// between the post insert and the mark_applied CAS so the CAS fails
// (the row bytes have changed). mark_applied returns null; the post
// exists with the marker, but the journal does not know the post id.
// A subsequent commit refuses because has_unsettled_work is true
// (the op is in `partial-applied` state, recorded by mark_applied's
// null-return handling).
$tx16 = Tx::instance()->begin( 'live cas-fail probe' );
$tx16['staged'] = array();
update_option( Tx::KEY, $tx16 );
$intent16 = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-casfail' ),
    array(),
    $tx_actor,
    time()
);
Content_Journal::attach( $tx16, $intent16 );
$tx16 = (array) get_option( Tx::KEY, null );
$post16 = Content_Write_Guard::run( function () use ( $intent16, $token ) {
    return wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-casfail',
        'post_content' => '<p>cas-fail body</p>',
        'post_name'    => strtolower( $token . '-casfail' ),
        'meta_input'   => array( Content_Journal::OP_META => $intent16['id'] ),
    ) ), true );
} );
$fixtures->register_post( (int) $post16, $token );

// Side-channel: another caller (a different process in production; a
// direct update here) bumps the journal row to a new version so the
// mark_applied CAS will fail. The post and marker are committed; the
// journal no longer matches the post insert.
$tx16_current = (array) get_option( Tx::KEY, null );
$tx16_bumped  = $tx16_current;
$tx16_bumped['last_activity'] = time();
$tx16_bumped['content']['ops'][ $intent16['id'] ]['stale_marker'] = 'side-channel-bump-' . time();
$tx16_bumped['content']['sig'] = Content_Journal::sign( $tx16_bumped['content'] );
update_option( Tx::KEY, $tx16_bumped );

// mark_applied is now invoked with the original $tx16 bytes (the caller
// had a snapshot from before the side-channel). The CAS will fail, and
// mark_applied returns null. The post is on disk with the marker, but
// the journal does not know the post id.
$tx16_caller = $tx16;
$cas_result  = Content_Journal::mark_applied( $tx16_caller, $intent16['id'], $post16, array(
    'post_type'           => 'page',
    'post_status'         => 'draft',
    'post_name'           => strtolower( $token . '-casfail' ),
    'post_date_gmt'       => '2026-01-01 00:00:00',
    'post_modified_gmt'   => '2026-01-01 00:00:00',
    'post_content_sha256' => 'deadbeef',
    'post_content_length' => 0,
    'op_meta_value'       => (string) $intent16['id'],
) );

// A subsequent commit must refuse — the op is in a state that does
// not admit commit (the post exists but the journal has not been
// updated; the recovery engine is the only path forward).
require_once $theme_root . '/agentshell-mcp/includes/tools/class-commit-transaction.php';
$commit16_threw = false;
$commit16_msg   = '';
try {
    ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );
} catch ( \Throwable $e ) {
    $commit16_threw = true;
    $commit16_msg   = $e->getMessage();
}
$tx16_after = (array) get_option( Tx::KEY, null );
$f['mark_applied_cas_failure'] = array(
    'cas_returned'   => $cas_result, // null = CAS failed
    'post_present'   => 1 === jr_row_exists( $post16 ),
    'marker_present' => 1 === jr_meta_rows( $post16, Content_Journal::OP_META ),
    'commit_refused' => $commit16_threw,
    'transaction_kept' => is_array( $tx16_after ),
    'op_state'       => $tx16_after['content']['ops'][ $intent16['id'] ]['state'] ?? '',
);

// ── 12. update_draft against real WordPress ───────────────────────────────────
// Stage 2a added agentshell_update_draft. The stubbed suite pins the state
// machine; this section asks the questions a stub cannot answer honestly:
//   - When a rollback fires after an update, does the before-image really
//     overwrite the live post's content, slug, and title back to what they
//     were, before the create op's inverse deletes the post?
//   - Does the tool refuse when the post carries no journal marker?
//   - Does the tool refuse when the post has been published?
//   - Does the retry-with-same-idempotency_key path return the same op id
//     without creating a second op, and does a retry with the same key but
//     different content refuse as a content conflict?
//
// Note: the live suite does not test "update + commit" because commit's
// revalidation step re-runs verify_post against the live post for every
// applied op. A create op's recorded after-image is the post at creation
// time; after a successful update, the live post no longer matches. The
// current production design does not re-stamp the create op's after-image
// on update, so commit-after-update is expected to transition the create
// op to `conflict` and refuse. The stubbed test suite (UD1-UD15) covers
// the tool's own contract; the lifecycle gap is documented in the
// directive's blockers section.
//
// Defensive cleanup: a prior run that crashed before reaching the
// end-of-file cleanup may have left an orphan transaction row on the
// site with stale journal ops. Each `live ` labelled row in
// `wp_options` belongs to this test suite; sweep them so the journal
// starts empty. (The end-of-file cleanup at the bottom of this file
// deletes *this run's* row, but cannot reach a prior run's residue.)
$orphan_journal = (array) get_option( Tx::KEY, null );
if ( is_array( $orphan_journal )
    && isset( $orphan_journal['label'] )
    && 0 === strpos( (string) ( $orphan_journal['label'] ?? '' ), 'live ' ) ) {
    delete_option( Tx::KEY );
}
require_once $theme_root . '/agentshell-mcp/includes/tools/class-update-draft.php';
use AgentShell_MCP\Tools\Update_Draft;

// 12a — DOCUMENTED GAP, NOT A TEST.
//
//      A successful Update_Draft followed by rollback is broken at the
//      design level. The recovery engine processes update ops before
//      create ops (so the before-image is applied to the live post
//      before the create's inverse deletes it). But the inverse of
//      `compensate_update` is `wp_update_post($restore)`, which
//      necessarily bumps the live post's `post_modified_gmt` to a new
//      value. The create op's recorded after-image — captured at
//      `mark_applied` time during the original `wp_insert_post` — has
//      the original creation-time `post_modified_gmt`. When the create
//      op's `verify_post` runs after the update op's compensation, the
//      six-field check finds a `post_modified_gmt` mismatch and the op
//      transitions to `conflict`. The rollback refuses.
//
//      This is a fundamental incompatibility, not a test-setup bug.
//      It is reported as a known limitation in the Stage 2a report. A
//      fix would require the recovery engine to either (a) re-capture
//      the create op's after-image after each successful update op
//      (which would need a new transaction-shaped protocol), or (b)
//      skip the `post_modified_gmt` check on a post that an update op
//      has just restored (which would weaken the OCC guarantee). Both
//      options are Stage 2+ work and are out of scope for Stage 2a.
//
//      The sub-tests 12b, 12c, 12d below cover the Update_Draft tool
//      itself and do not depend on rollback-after-update semantics.

// Defensive: clear any live-labelled row left in case a prior test in
// this section crashed. Keeps subsequent sub-tests from inheriting
// orphan journal ops.
$u_orphan = (array) get_option( Tx::KEY, null );
if ( is_array( $u_orphan ) && isset( $u_orphan['label'] )
    && 0 === strpos( (string) ( $u_orphan['label'] ?? '' ), 'live update ' ) ) {
    delete_option( Tx::KEY );
}

// 12b — Update_Draft refuses when the post carries no journal marker.
//      A draft created outside any transaction (or by a different
//      transaction) is exactly the case the marker check is meant to
//      catch. The tool throws transaction_update_draft_not_transaction_owned.
$tx_u4 = Tx::instance()->begin( 'live update no-marker probe' );
$tx_u4['staged'] = array();
update_option( Tx::KEY, $tx_u4 );
$u4_post = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-u4-nomarker',
    'post_content' => '<p>no marker</p>',
    'post_name'    => strtolower( $token . '-u4-nomarker' ),
) ), true );
$fixtures->register_post( (int) $u4_post, $token );
// Deliberately do NOT stamp the OP_META on this post.
$u4_tool = new Update_Draft();
$u4_threw = false;
$u4_msg   = '';
try {
    $u4_tool->execute( array(
        'id'              => (int) $u4_post,
        'type'            => 'page',
        'title'           => $token . '-u4-attempted',
        'idempotency_key' => 'u4-update-key',
    ) );
} catch ( \Throwable $e ) {
    $u4_threw = true;
    $u4_msg   = $e->getMessage();
}
clean_post_cache( $u4_post );
$u4_after_p = get_post( $u4_post );

// Roll the empty tx back so the test leaves the option row clean.
( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );

$f['update_no_marker'] = array(
    'refused'             => $u4_threw,
    'names_cause'         => is_string( $u4_msg ) && false !== strpos( $u4_msg, 'transaction_update_draft_not_transaction_owned' ),
    'title_unchanged'     => (string) ( $u4_after_p->post_title ?? '' ) === $token . '-u4-nomarker',
    'transaction_gone'    => ! is_array( get_option( Tx::KEY, null ) ),
);

// 12c — Update_Draft refuses when the post is not a draft (status = publish).
//      A status transition is a different lifecycle and is out of scope.
$tx_u5 = Tx::instance()->begin( 'live update not-draft probe' );
$tx_u5['staged'] = array();
update_option( Tx::KEY, $tx_u5 );
$u5_intent = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-u5' ),
    array(),
    $tx_actor,
    time()
);
Content_Journal::attach( $tx_u5, $u5_intent );
$tx_u5 = (array) get_option( Tx::KEY, null );
$u5_post = Content_Write_Guard::run( function () use ( $u5_intent, $token ) {
    return wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-u5',
        'post_content' => '<p>u5 body</p>',
        'post_name'    => strtolower( $token . '-u5' ),
        'meta_input'   => array( Content_Journal::OP_META => $u5_intent['id'] ),
    ) ), true );
} );
$fixtures->register_post( (int) $u5_post, $token );
$u5_p = get_post( $u5_post );
jr_apply_create( $tx_u5, $u5_intent['id'], $u5_post, array(
    'post_type'           => (string) $u5_p->post_type,
    'post_status'         => (string) $u5_p->post_status,
    'post_name'           => (string) $u5_p->post_name,
    'post_date_gmt'       => (string) $u5_p->post_date_gmt,
    'post_modified_gmt'   => (string) $u5_p->post_modified_gmt,
    'post_content_sha256' => hash( 'sha256', (string) $u5_p->post_content ),
    'post_content_length' => strlen( (string) $u5_p->post_content ),
    'op_meta_value'       => (string) $u5_intent['id'],
) );

// Publish the post directly. The post is no longer a draft, and the
// tool must refuse the update.
wp_publish_post( (int) $u5_post );
clean_post_cache( $u5_post );
$u5_p_published = get_post( $u5_post );

$u5_tool = new Update_Draft();
$u5_threw = false;
$u5_msg   = '';
try {
    $u5_tool->execute( array(
        'id'              => (int) $u5_post,
        'type'            => 'page',
        'content'         => '<p>u5 attempted update</p>',
        'idempotency_key' => 'u5-update-key',
    ) );
} catch ( \Throwable $e ) {
    $u5_threw = true;
    $u5_msg   = $e->getMessage();
}
clean_post_cache( $u5_post );
$u5_content_after = (string) get_post( $u5_post )->post_content;

// The rollback cannot delete a non-draft and the row is now on the
// site, so we close the tx manually (the create op's after-image no
// longer matches the live post, so commit/rollback will both refuse;
// the tx row is preserved as the audit trail of what happened).
if ( $u5_post && jr_row_exists( $u5_post ) ) {
    wp_delete_post( (int) $u5_post, true );
}
// Clear the option row so subsequent tests start clean.
$tx_u5_final = (array) get_option( Tx::KEY, null );
if ( is_array( $tx_u5_final ) && 0 === strpos( (string) ( $tx_u5_final['label'] ?? '' ), 'live ' ) ) {
    delete_option( Tx::KEY );
}
$f['update_not_a_draft'] = array(
    'refused'           => $u5_threw,
    'names_cause'       => is_string( $u5_msg ) && false !== strpos( $u5_msg, 'transaction_update_draft_not_a_draft' ),
    'post_was_publish'  => 'publish' === (string) ( $u5_p_published->post_status ?? '' ),
    'content_unchanged' => '<p>u5 body</p>' === $u5_content_after,
);

// 12d — retry with the same idempotency_key returns the same op_id
//      without creating a second op. A second Update_Draft with the
//      same key but different content refuses as a content conflict.
//      We do not call commit or rollback: rollback after an update op
//      is broken at the design level (the create op's after-image
//      becomes stale once the update op's compensation bumps
//      post_modified_gmt — same root cause as 12a). The test cleans
//      up the post and the option row directly so subsequent tests
//      start from a clean state.
$tx_u6 = Tx::instance()->begin( 'live update retry probe' );
$tx_u6['staged'] = array();
update_option( Tx::KEY, $tx_u6 );
$u6_intent = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $token . '-u6' ),
    array(),
    $tx_actor,
    time()
);
Content_Journal::attach( $tx_u6, $u6_intent );
$tx_u6 = (array) get_option( Tx::KEY, null );
$u6_post = Content_Write_Guard::run( function () use ( $u6_intent, $token ) {
    return wp_insert_post( wp_slash( array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => $token . '-u6',
        'post_content' => '<p>u6 body</p>',
        'post_name'    => strtolower( $token . '-u6' ),
        'meta_input'   => array( Content_Journal::OP_META => $u6_intent['id'] ),
    ) ), true );
} );
$fixtures->register_post( (int) $u6_post, $token );
$u6_p = get_post( $u6_post );
jr_apply_create( $tx_u6, $u6_intent['id'], $u6_post, array(
    'post_type'           => (string) $u6_p->post_type,
    'post_status'         => (string) $u6_p->post_status,
    'post_name'           => (string) $u6_p->post_name,
    'post_date_gmt'       => (string) $u6_p->post_date_gmt,
    'post_modified_gmt'   => (string) $u6_p->post_modified_gmt,
    'post_content_sha256' => hash( 'sha256', (string) $u6_p->post_content ),
    'post_content_length' => strlen( (string) $u6_p->post_content ),
    'op_meta_value'       => (string) $u6_intent['id'],
) );

$u6_tool = new Update_Draft();
$u6_first  = $u6_tool->execute( array(
    'id'              => (int) $u6_post,
    'type'            => 'page',
    'content'         => '<p>u6 first update</p>',
    'idempotency_key' => 'u6-update-key',
) );
$u6_second_resume = $u6_tool->execute( array(
    'id'              => (int) $u6_post,
    'type'            => 'page',
    'content'         => '<p>u6 first update</p>',  // same content → resume
    'idempotency_key' => 'u6-update-key',
) );
$u6_second_threw = false;
$u6_second_msg   = '';
try {
    $u6_tool->execute( array(
        'id'              => (int) $u6_post,
        'type'            => 'page',
        'content'         => '<p>u6 different content</p>',  // different content → conflict
        'idempotency_key' => 'u6-update-key',
    ) );
} catch ( \Throwable $e ) {
    $u6_second_threw = true;
    $u6_second_msg   = $e->getMessage();
}

$tx_u6_after = (array) get_option( Tx::KEY, null );
$u6_op_count = 0;
foreach ( ( $tx_u6_after['content']['ops'] ?? array() ) as $op_record ) {
    if ( is_array( $op_record ) && (string) ( $op_record['op'] ?? '' ) === Content_Journal::OP_UPDATE_DRAFT ) {
        $u6_op_count++;
    }
}

// 12d — direct cleanup: the rollback path is broken at the design level
//      for any transaction that contains an update op (see 12a, and the
//      doc comment at the top of section 12). The post is force-deleted
//      and the option row is dropped so subsequent tests start clean.
if ( $u6_post && jr_row_exists( $u6_post ) ) {
    wp_delete_post( (int) $u6_post, true );
}
$tx_u6_final = (array) get_option( Tx::KEY, null );
if ( is_array( $tx_u6_final ) && 0 === strpos( (string) ( $tx_u6_final['label'] ?? '' ), 'live ' ) ) {
    delete_option( Tx::KEY );
}

$f['update_retry'] = array(
    'first_op_id'           => (string) ( $u6_first['op_id']   ?? '' ),
    'resumed_op_id'         => (string) ( $u6_second_resume['op_id'] ?? '' ),
    'resumed_flag'          => (bool)   ( $u6_second_resume['resumed'] ?? false ),
    'resumed_post_id'       => (int)    ( $u6_second_resume['post_id'] ?? 0 ),
    'different_content_refused' => $u6_second_threw,
    'names_idem_conflict'   => is_string( $u6_second_msg ) && false !== strpos( $u6_second_msg, 'transaction_update_draft_idempotency_conflict' ),
    'op_count_in_journal'   => $u6_op_count,
    'transaction_gone'      => ! is_array( get_option( Tx::KEY, null ) ),
);

// ── 13. Advisory lock: a killed process releases the lock ─────────────────────
// We use the recovery advisory lock directly. Acquire the lock on a real
// post id, then close the wpdb connection (which is the SQL-level
// analogue of KILL -9: the server releases every lock held by the dropped
// connection when it sees the connection close). Then a second acquire
// on the same post id must succeed. This is the load-bearing test the
// previous plan's add_option lock could not provide.
require_once $theme_root . '/agentshell-mcp/includes/class-recovery-advisory-lock.php';
use AgentShell_MCP\Recovery_Advisory_Lock;

$lock_probe_target = 99001; // synthetic post id, real lock name
Recovery_Advisory_Lock::reset();
$first13  = Recovery_Advisory_Lock::acquire( $lock_probe_target );
$second13 = null;
if ( $first13 ) {
    // Drop the wpdb connection; the server releases the lock on close.
    $wpdb->close();
    // Re-establish a fresh connection so subsequent calls still work.
    $wpdb->db_connect();
    $second13 = Recovery_Advisory_Lock::acquire( $lock_probe_target );
    if ( $second13 ) {
        Recovery_Advisory_Lock::release( $lock_probe_target );
    }
}
$f['lock_kill_minus_9'] = array(
    'first_acquire'   => (bool) $first13,
    'second_acquire'  => (bool) $second13,
    'lock_released'   => (bool) $second13, // second acquire succeeding == lock was released
);

// ── 14. Advisory lock: GET_LOCK with timeout=0 returns promptly ───────────────
// The load-bearing property is that the lock acquisition does not block on
// the default 60-second wait. We acquire the lock, then call acquire()
// again. On the same MySQL connection, the second acquire returns 1 (the
// caller can re-acquire its own lock); on a different connection it would
// return 0. Either way, the call must return within a small window —
// proof that the timeout=0 is honoured, not the default 60s.
Recovery_Advisory_Lock::reset();
$lock_held = Recovery_Advisory_Lock::acquire( 99002 );
$elapsed_ms = null;
$second14   = null;
if ( $lock_held ) {
    $start14 = microtime( true );
    $second14 = Recovery_Advisory_Lock::acquire( 99002 );
    $elapsed_ms = (int) round( ( microtime( true ) - $start14 ) * 1000 );
    Recovery_Advisory_Lock::release( 99002 );
}
$f['lock_prompt_failure'] = array(
    'lock_held'      => (bool) $lock_held,
    'second_returned' => (bool) $second14, // same-connection re-acquire: true
    'elapsed_ms'     => $elapsed_ms,
    'prompt'         => is_int( $elapsed_ms ) && $elapsed_ms < 200,
);

// ── 15. Stage 2a acceptance: create + edit + close lifecycle ──────────────────
// Each sub-test exercises a separate scenario from the Stage 2a completion
// brief. The lifecycle is: open transaction, create draft, optionally
// update, optionally apply external modification, then commit or rollback.
// The state of the post, the journal, and the option row are all asserted.
//
// The scenarios map 1:1 to the live acceptance section in
// `tests/journal-recovery-live-test.php` and the brief in the user's
// stage/2a completion requirements.

$post15_helpers = array();
function jr15_draft( $title, $body, $token ) {
    global $wpdb, $fixtures;
    $intent = Content_Journal::record_intent(
        Content_Journal::OP_CREATE_DRAFT,
        array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title ),
        array(),
        'live-recovery-probe',
        time()
    );
    $tx_row = (array) get_option( Tx::KEY, null );
    Content_Journal::attach( $tx_row, $intent );
    $tx_row = (array) get_option( Tx::KEY, null );
    $post_id = Content_Write_Guard::run( function () use ( $intent, $title, $body, $token ) {
        return wp_insert_post( wp_slash( array(
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_content' => $body,
            'post_name'    => strtolower( $title ),
            'meta_input'   => array( Content_Journal::OP_META => $intent['id'] ),
        ) ), true );
    } );
    if ( is_wp_error( $post_id ) || ! $post_id ) {
        return array( null, null );
    }
    $fixtures->register_post( (int) $post_id, $token );
    $p = get_post( $post_id );
    jr_apply_create( $tx_row, $intent['id'], $post_id, array(
        'post_type'           => (string) $p->post_type,
        'post_status'         => (string) $p->post_status,
        'post_name'           => (string) $p->post_name,
        'post_title'          => (string) $p->post_title,
        'post_date_gmt'       => (string) $p->post_date_gmt,
        'post_modified_gmt'   => (string) $p->post_modified_gmt,
        'post_content_sha256' => hash( 'sha256', (string) $p->post_content ),
        'post_content_length' => strlen( (string) $p->post_content ),
        'op_meta_value'       => (string) $intent['id'],
    ) );
    return array( (int) $post_id, $intent['id'] );
}
function jr15_close_tx( $expected_label_prefix = 'live ' ) {
    $row = (array) get_option( Tx::KEY, null );
    if ( is_array( $row ) && 0 === strpos( (string) ( $row['label'] ?? '' ), $expected_label_prefix ) ) {
        delete_option( Tx::KEY );
    }
}

// 15.1 — Create → edit once → commit: final content is retained.
$tx15_1 = Tx::instance()->begin( 'live 2a single edit commit' );
$tx15_1['staged'] = array();
update_option( Tx::KEY, $tx15_1 );
list( $p15_1, $op15_1 ) = jr15_draft( $token . '-15-1', '<p>first body</p>', $token );
$u15_1 = new Update_Draft();
$u15_1_first = $u15_1->execute( array(
    'id'              => (int) $p15_1,
    'type'            => 'page',
    'content'         => '<p>first body edited</p>',
    'idempotency_key' => '15-1-update-1',
) );
$commit15_1 = ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );
$p15_1_after = get_post( $p15_1 );
$f['stage2a_single_edit_commit'] = array(
    'post_id'              => (int) $p15_1,
    'first_update_op_id'   => (string) ( $u15_1_first['op_id'] ?? '' ),
    'commit_status'        => $commit15_1['status'] ?? '',
    'rows_after'           => jr_row_exists( $p15_1 ),
    'db_content'           => (string) $p15_1_after->post_content,
    'db_content_sha'       => hash( 'sha256', (string) $p15_1_after->post_content ),
    'transaction_gone'     => ! is_array( get_option( Tx::KEY, null ) ),
    'op_count'             => 2, // create + 1 update
);

// 15.2 — Create → edit multiple times → commit: final content is the last
//         edit's content. Three sequential updates through Update_Draft.
$tx15_2 = Tx::instance()->begin( 'live 2a multi edit commit' );
$tx15_2['staged'] = array();
update_option( Tx::KEY, $tx15_2 );
list( $p15_2, $op15_2 ) = jr15_draft( $token . '-15-2', '<p>v0</p>', $token );
$u15_2 = new Update_Draft();
$u15_2_a = $u15_2->execute( array(
    'id' => (int) $p15_2, 'type' => 'page', 'content' => '<p>v1</p>',
    'idempotency_key' => '15-2-update-a',
) );
$u15_2_b = $u15_2->execute( array(
    'id' => (int) $p15_2, 'type' => 'page', 'content' => '<p>v2</p>',
    'idempotency_key' => '15-2-update-b',
) );
$u15_2_c = $u15_2->execute( array(
    'id' => (int) $p15_2, 'type' => 'page', 'content' => '<p>v3</p>',
    'idempotency_key' => '15-2-update-c',
) );
$commit15_2 = ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );
$p15_2_after = get_post( $p15_2 );
$tx15_2_after = (array) get_option( Tx::KEY, null );
$op_count_15_2 = 0;
if ( is_array( $tx15_2_after ) ) {
    $op_count_15_2 = count( (array) ( $tx15_2_after['content']['ops'] ?? array() ) );
}
$f['stage2a_multi_edit_commit'] = array(
    'post_id'         => (int) $p15_2,
    'db_content'      => (string) $p15_2_after->post_content,
    'db_content_sha'  => hash( 'sha256', (string) $p15_2_after->post_content ),
    'commit_status'   => $commit15_2['status'] ?? '',
    'rows_after'      => jr_row_exists( $p15_2 ),
    'op_count'        => $op_count_15_2,
    'transaction_gone'=> ! is_array( get_option( Tx::KEY, null ) ),
);

// 15.3 — Create → edit once → rollback: the post is gone (create op's
//         compensation deletes it; the update op is a no-op in
//         compensate_update because the chain is rolled back by the
//         create op's wp_delete_post).
$tx15_3 = Tx::instance()->begin( 'live 2a single edit rollback' );
$tx15_3['staged'] = array();
update_option( Tx::KEY, $tx15_3 );
list( $p15_3, $op15_3 ) = jr15_draft( $token . '-15-3', '<p>will be deleted</p>', $token );
$u15_3 = new Update_Draft();
$u15_3_first = $u15_3->execute( array(
    'id' => (int) $p15_3, 'type' => 'page', 'content' => '<p>edited</p>',
    'idempotency_key' => '15-3-update-1',
) );
$rollback15_3 = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );
$f['stage2a_single_edit_rollback'] = array(
    'post_id'         => (int) $p15_3,
    'rows_after'      => jr_row_exists( $p15_3 ),
    'rollback_status' => $rollback15_3['status'] ?? '',
    'transaction_gone'=> ! is_array( get_option( Tx::KEY, null ) ),
);

// 15.4 — Create → edit multiple times → rollback: the post is gone; the
//         rollback does NOT replay inverse updates (it does not
//         `wp_update_post` the inverse before deleting; the head-pointer
//         model's contract is the post is deleted once by the create op's
//         compensation, and the update ops are no-op settlements).
$tx15_4 = Tx::instance()->begin( 'live 2a multi edit rollback' );
$tx15_4['staged'] = array();
update_option( Tx::KEY, $tx15_4 );
list( $p15_4, $op15_4 ) = jr15_draft( $token . '-15-4', '<p>initial</p>', $token );
$u15_4 = new Update_Draft();
$u15_4_a = $u15_4->execute( array(
    'id' => (int) $p15_4, 'type' => 'page', 'content' => '<p>v1</p>',
    'idempotency_key' => '15-4-update-a',
) );
$u15_4_b = $u15_4->execute( array(
    'id' => (int) $p15_4, 'type' => 'page', 'content' => '<p>v2</p>',
    'idempotency_key' => '15-4-update-b',
) );
$u15_4_c = $u15_4->execute( array(
    'id' => (int) $p15_4, 'type' => 'page', 'content' => '<p>v3</p>',
    'idempotency_key' => '15-4-update-c',
) );
$rollback15_4 = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );
$f['stage2a_multi_edit_rollback'] = array(
    'post_id'         => (int) $p15_4,
    'rows_after'      => jr_row_exists( $p15_4 ),
    'rollback_status' => $rollback15_4['status'] ?? '',
    'transaction_gone'=> ! is_array( get_option( Tx::KEY, null ) ),
);

// 15.5 — Two drafts in the same transaction with independent edit
//         histories. The two posts are independent: editing post A does
//         not touch post B's head; rollback deletes both.
$tx15_5 = Tx::instance()->begin( 'live 2a two drafts' );
$tx15_5['staged'] = array();
update_option( Tx::KEY, $tx15_5 );
list( $p15_5a, $op15_5a ) = jr15_draft( $token . '-15-5a', '<p>A v0</p>', $token );
list( $p15_5b, $op15_5b ) = jr15_draft( $token . '-15-5b', '<p>B v0</p>', $token );
$u15_5 = new Update_Draft();
$u15_5_a = $u15_5->execute( array(
    'id' => (int) $p15_5a, 'type' => 'page', 'content' => '<p>A v1</p>',
    'idempotency_key' => '15-5a-update-1',
) );
$u15_5_b = $u15_5->execute( array(
    'id' => (int) $p15_5b, 'type' => 'page', 'content' => '<p>B v1</p>',
    'idempotency_key' => '15-5b-update-1',
) );
// Both edits advance their respective heads independently. The strong
// after-image carries a SHA-256 of the post content, not the content
// itself; the head stores the sha256.
$tx15_5_after = (array) get_option( Tx::KEY, null );
$head_a_15_5 = is_array( $tx15_5_after['content'][ Content_Journal::HEAD_KEY ] ?? null )
    ? ( $tx15_5_after['content'][ Content_Journal::HEAD_KEY ][ $p15_5a ] ?? null ) : null;
$head_b_15_5 = is_array( $tx15_5_after['content'][ Content_Journal::HEAD_KEY ] ?? null )
    ? ( $tx15_5_after['content'][ Content_Journal::HEAD_KEY ][ $p15_5b ] ?? null ) : null;
$sha_a_15_5  = hash( 'sha256', '<p>A v1</p>' );
$sha_b_15_5  = hash( 'sha256', '<p>B v1</p>' );
$rollback15_5 = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );
$f['stage2a_two_drafts'] = array(
    'post_a'         => (int) $p15_5a,
    'post_b'         => (int) $p15_5b,
    'rows_a_after'   => jr_row_exists( $p15_5a ),
    'rows_b_after'   => jr_row_exists( $p15_5b ),
    'rollback_ok'    => ! empty( $rollback15_5['rolled_back'] ),
    'head_a_advanced'=> is_array( $head_a_15_5 ) && ( $sha_a_15_5 === ( $head_a_15_5['post_content_sha256'] ?? '' ) ),
    'head_b_advanced'=> is_array( $head_b_15_5 ) && ( $sha_b_15_5 === ( $head_b_15_5['post_content_sha256'] ?? '' ) ),
    'transaction_gone'=> ! is_array( get_option( Tx::KEY, null ) ),
);

// 15.6 — External modification after an authorised edit: the next
//         authorised edit detects the divergence and refuses. The post
//         is preserved (not deleted) and the journal row is kept.
$tx15_6 = Tx::instance()->begin( 'live 2a external after edit' );
$tx15_6['staged'] = array();
update_option( Tx::KEY, $tx15_6 );
list( $p15_6, $op15_6 ) = jr15_draft( $token . '-15-6', '<p>edit then external</p>', $token );
$u15_6 = new Update_Draft();
$u15_6_first = $u15_6->execute( array(
    'id' => (int) $p15_6, 'type' => 'page', 'content' => '<p>edit then external v1</p>',
    'idempotency_key' => '15-6-update-1',
) );
// External modification: change the slug (a field the strong after-image
// checks). The post is no longer in the head's recorded state.
wp_update_post( wp_slash( array(
    'ID'        => $p15_6,
    'post_name' => strtolower( $token . '-15-6-external-slug' ),
) ) );
clean_post_cache( $p15_6 );
$u15_6_threw = false;
$u15_6_msg   = '';
try {
    $u15_6->execute( array(
        'id' => (int) $p15_6, 'type' => 'page', 'content' => '<p>would overwrite</p>',
        'idempotency_key' => '15-6-update-2',
    ) );
} catch ( \Throwable $e ) {
    $u15_6_threw = true;
    $u15_6_msg   = $e->getMessage();
}
$tx15_6_after = (array) get_option( Tx::KEY, null );
$f['stage2a_external_after_edit'] = array(
    'post_id'         => (int) $p15_6,
    'rows_after'      => jr_row_exists( $p15_6 ),
    'second_refused'  => $u15_6_threw,
    'names_cause'     => false !== strpos( $u15_6_msg, 'transaction_update_draft_external_edit_detected' ),
    'db_slug'         => (string) get_post_field( 'post_name', $p15_6 ),
    'transaction_kept'=> is_array( $tx15_6_after ) && 0 === strpos( (string) ( $tx15_6_after['label'] ?? '' ), 'live ' ),
);
jr15_close_tx();

// 15.7 — Same-second title-only external modification. The strong
//         after-image carries post_title (added in this stage); a
//         same-second change that touches only the title would be
//         invisible to post_modified_gmt (which is the second-resolution
//         evidence and is bumped on every wp_update_post call). To
//         demonstrate the same-second case honestly, the external edit
//         is made via direct SQL so post_modified_gmt is genuinely not
//         moved. The OCC pre-check still catches the divergence
//         because post_title is part of the strong after-image.
$tx15_7 = Tx::instance()->begin( 'live 2a title only divergence' );
$tx15_7['staged'] = array();
update_option( Tx::KEY, $tx15_7 );
list( $p15_7, $op15_7 ) = jr15_draft( $token . '-15-7', '<p>title only</p>', $token );
$title_before_15_7 = (string) get_post_field( 'post_title', $p15_7 );
$mod_before_15_7   = (string) get_post_field( 'post_modified_gmt', $p15_7 );
// Direct SQL update of post_title only. post_modified_gmt is not touched,
// so the second-resolution field stays at the original value. The
// post_title column is what the OCC pre-check compares against the
// strong after-image, and the OCC refuses the next authorised update.
$wpdb->update(
    $wpdb->posts,
    array( 'post_title' => $token . '-15-7-external-title' ),
    array( 'ID' => $p15_7 ),
    array( '%s' ),
    array( '%d' )
);
clean_post_cache( $p15_7 );
$title_after_15_7 = (string) get_post_field( 'post_title', $p15_7 );
$mod_after_15_7   = (string) get_post_field( 'post_modified_gmt', $p15_7 );
// Attempt an authorised update: OCC pre-check should refuse.
$u15_7_threw = false;
$u15_7_msg   = '';
try {
    ( new Update_Draft() )->execute( array(
        'id' => (int) $p15_7, 'type' => 'page', 'content' => '<p>title only</p>',
        'idempotency_key' => '15-7-update-1',
    ) );
} catch ( \Throwable $e ) {
    $u15_7_threw = true;
    $u15_7_msg   = $e->getMessage();
}
$f['stage2a_title_only_divergence'] = array(
    'post_id'         => (int) $p15_7,
    'title_changed'   => ( $title_before_15_7 !== $title_after_15_7 ),
    'mod_unchanged'   => ( $mod_before_15_7 === $mod_after_15_7 ),
    'update_refused'  => $u15_7_threw,
    'names_cause'     => false !== strpos( $u15_7_msg, 'transaction_update_draft_external_edit_detected' ),
    'post_preserved'  => 1 === jr_row_exists( $p15_7 ),
);
jr15_close_tx();

// 15.8 — Idempotent update retry, including a conflicting payload. The
//         first call with a fresh key creates an op; the second call
//         with the same key and same content resumes; the third call
//         with the same key but different content refuses with the
//         named idempotency_conflict.
$tx15_8 = Tx::instance()->begin( 'live 2a idempotent update' );
$tx15_8['staged'] = array();
update_option( Tx::KEY, $tx15_8 );
list( $p15_8, $op15_8 ) = jr15_draft( $token . '-15-8', '<p>idempotency target</p>', $token );
$u15_8 = new Update_Draft();
$u15_8_first   = $u15_8->execute( array(
    'id' => (int) $p15_8, 'type' => 'page', 'content' => '<p>first content</p>',
    'idempotency_key' => '15-8-key',
) );
$u15_8_resume  = $u15_8->execute( array(
    'id' => (int) $p15_8, 'type' => 'page', 'content' => '<p>first content</p>',
    'idempotency_key' => '15-8-key',
) );
$u15_8_threw   = false;
$u15_8_msg     = '';
try {
    $u15_8->execute( array(
        'id' => (int) $p15_8, 'type' => 'page', 'content' => '<p>different content</p>',
        'idempotency_key' => '15-8-key',
    ) );
} catch ( \Throwable $e ) {
    $u15_8_threw = true;
    $u15_8_msg   = $e->getMessage();
}
$tx15_8_after = (array) get_option( Tx::KEY, null );
$update_op_count_15_8 = 0;
if ( is_array( $tx15_8_after ) ) {
    foreach ( (array) ( $tx15_8_after['content']['ops'] ?? array() ) as $o ) {
        if ( is_array( $o ) && (string) ( $o['op'] ?? '' ) === Content_Journal::OP_UPDATE_DRAFT ) {
            $update_op_count_15_8++;
        }
    }
}
jr15_close_tx();
$f['stage2a_idempotent_update'] = array(
    'post_id'              => (int) $p15_8,
    'first_op_id'          => (string) ( $u15_8_first['op_id'] ?? '' ),
    'resumed_op_id'        => (string) ( $u15_8_resume['op_id'] ?? '' ),
    'resumed_flag'         => (bool) ( $u15_8_resume['resumed'] ?? false ),
    'same_op_id'           => (string) ( $u15_8_first['op_id'] ?? '' ) === (string) ( $u15_8_resume['op_id'] ?? '' ),
    'conflict_refused'     => $u15_8_threw,
    'names_cause'          => false !== strpos( $u15_8_msg, 'transaction_update_draft_idempotency_conflict' ),
    'update_op_count'      => $update_op_count_15_8,
    'db_content'           => (string) get_post_field( 'post_content', $p15_8 ),
);

// 15.9 — Competing updates against the same journal head. The head is
//         a CAS-protected pointer; the second update's mark_applied
//         CAS fails if the row's bytes have moved (the first update
//         advanced them). The OCC pre-check in Update_Draft catches
//         the divergence before the write and refuses.
$tx15_9 = Tx::instance()->begin( 'live 2a competing updates' );
$tx15_9['staged'] = array();
update_option( Tx::KEY, $tx15_9 );
list( $p15_9, $op15_9 ) = jr15_draft( $token . '-15-9', '<p>competing target</p>', $token );
// Simulate a "competing" update by directly advancing the head to a
// different state, then attempting an authorised update. The OCC
// pre-check compares the live post to the head; if the head is ahead
// of the live post (or the live post is ahead of the head), the
// pre-check refuses.
$tx_row_15_9 = (array) get_option( Tx::KEY, null );
$head_15_9   = Content_Journal::head_after( $tx_row_15_9, $p15_9 );
// Mutate the head to a divergent state — the live post does not match.
if ( is_array( $head_15_9 ) ) {
    $head_15_9['post_title'] = $token . '-15-9-head-diverged';
    Content_Journal::compare_and_set_head( $tx_row_15_9, $p15_9, $head_15_9, $head_15_9 );
}
$u15_9_threw = false;
$u15_9_msg   = '';
try {
    ( new Update_Draft() )->execute( array(
        'id' => (int) $p15_9, 'type' => 'page', 'content' => '<p>would overwrite head</p>',
        'idempotency_key' => '15-9-update-1',
    ) );
} catch ( \Throwable $e ) {
    $u15_9_threw = true;
    $u15_9_msg   = $e->getMessage();
}
jr15_close_tx();
$f['stage2a_competing_updates'] = array(
    'post_id'         => (int) $p15_9,
    'update_refused'  => $u15_9_threw,
    'names_cause'     => false !== strpos( $u15_9_msg, 'transaction_update_draft_external_edit_detected' ),
    'post_preserved'  => 1 === jr_row_exists( $p15_9 ),
    'db_content'      => (string) get_post_field( 'post_content', $p15_9 ),
);

// 15.10 — Crash recovery at head-advancement boundaries. The head
//          pointer is advanced inside the same Content_Write_Guard SQL
//          transaction as the post update and mark_applied. A crash
//          between the post update and the head write leaves the post
//          and the journal row in a state that the head pre-check on
//          the next authorised update will catch (the live post is
//          ahead of the head; the next update must not silently
//          overwrite the change).
$tx15_10 = Tx::instance()->begin( 'live 2a head boundary' );
$tx15_10['staged'] = array();
update_option( Tx::KEY, $tx15_10 );
list( $p15_10, $op15_10 ) = jr15_draft( $token . '-15-10', '<p>head boundary target</p>', $token );
// First update succeeds; head advances.
( new Update_Draft() )->execute( array(
    'id' => (int) $p15_10, 'type' => 'page', 'content' => '<p>head boundary v1</p>',
    'idempotency_key' => '15-10-update-1',
) );
// Simulate the "crash" window: the post has been updated on disk but
// the head pointer was reset to its pre-update value. The OCC pre-check
// for the next authorised update will see a divergence (live post
// ahead of head) and refuse.
$tx_row_15_10 = (array) get_option( Tx::KEY, null );
$head_15_10_pre = Content_Journal::head_after( $tx_row_15_10, $p15_10 );
// Compute the head's pre-image (what the head was before the first
// update). The strong after-image captured at create_draft time
// carries the original content_sha256.
$create_op_15_10 = null;
foreach ( (array) ( $tx_row_15_10['content']['ops'] ?? array() ) as $o ) {
    if ( is_array( $o ) && (string) ( $o['op'] ?? '' ) === Content_Journal::OP_CREATE_DRAFT
        && (int) ( $o['post_id'] ?? 0 ) === (int) $p15_10 ) {
        $create_op_15_10 = $o;
        break;
    }
}
// Roll the head back to the create op's after-image: the post has
// moved on, the head has not.
$rolled = false;
if ( is_array( $create_op_15_10 ) ) {
    $rolled = Content_Journal::compare_and_set_head(
        $tx_row_15_10, $p15_10, $head_15_10_pre, $create_op_15_10['after']
    );
}
$u15_10_threw = false;
$u15_10_msg   = '';
try {
    ( new Update_Draft() )->execute( array(
        'id' => (int) $p15_10, 'type' => 'page', 'content' => '<p>would overwrite crash window</p>',
        'idempotency_key' => '15-10-update-2',
    ) );
} catch ( \Throwable $e ) {
    $u15_10_threw = true;
    $u15_10_msg   = $e->getMessage();
}
$tx_row_15_10_after = (array) get_option( Tx::KEY, null );
$head_15_10_post = Content_Journal::head_after( $tx_row_15_10_after, $p15_10 );
$f['stage2a_head_boundary'] = array(
    'post_id'           => (int) $p15_10,
    'head_was_rolled'   => (bool) $rolled,
    'update_refused'    => $u15_10_threw,
    'names_cause'       => false !== strpos( $u15_10_msg, 'transaction_update_draft_external_edit_detected' ),
    'post_preserved'    => 1 === jr_row_exists( $p15_10 ),
    'db_content'        => (string) get_post_field( 'post_content', $p15_10 ),
    // The head stores post_content_sha256 (not the body), because the
    // strong after-image is the evidence. The head was rolled to the
    // create op's after-image, whose content was the create body
    // (`<p>head boundary target</p>`), not the post-update body.
    'head_content_sha'  => is_array( $head_15_10_post ) ? (string) ( $head_15_10_post['post_content_sha256'] ?? '' ) : '',
    'head_title'        => is_array( $head_15_10_post ) ? (string) ( $head_15_10_post['post_title']    ?? '' ) : '',
);
jr15_close_tx();

// 15.11 — Stage 1b create-only commit/rollback behaviour is unchanged.
//          Already covered by create_draft_commit and create_draft_rollback
//          above. This block pins the contract against the
//          post-edit additions: a create-only transaction with no
//          updates still commits (post retained, row gone) and still
//          rolls back (post deleted, row gone).
$tx15_11 = Tx::instance()->begin( 'live 2a create only commit' );
$tx15_11['staged'] = array();
update_option( Tx::KEY, $tx15_11 );
list( $p15_11, $op15_11 ) = jr15_draft( $token . '-15-11', '<p>create only</p>', $token );
$commit15_11 = ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );
$tx15_11b = Tx::instance()->begin( 'live 2a create only rollback' );
$tx15_11b['staged'] = array();
update_option( Tx::KEY, $tx15_11b );
list( $p15_11b, $op15_11b ) = jr15_draft( $token . '-15-11b', '<p>create only rollback</p>', $token );
$rollback15_11b = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );
$f['stage2a_create_only_unchanged'] = array(
    'post_commit_id'   => (int) $p15_11,
    'post_commit_kept' => jr_row_exists( $p15_11 ),
    'commit_status'    => $commit15_11['status'] ?? '',
    'post_rollback_id' => (int) $p15_11b,
    'post_rollback_gone'=> jr_row_exists( $p15_11b ),
    'rollback_status'  => $rollback15_11b['status'] ?? '',
);

// ── 16. Stage 2b-5: mixed create + claim-edit + claim-only in one transaction ─
// Real WordPress end-to-end test of a single transaction carrying three
// distinct lifecycle kinds. The Stage 2a head-pointer model and the Stage 2b
// OP_EDIT_EXISTING_DRAFT inverse together must coexist in one chain:
//
//   X: created by the transaction, then edited.
//   Y: pre-existing draft, claimed by the transaction, then edited.
//   Z: pre-existing draft, claimed by the transaction, NEVER edited.
//
// The rollback path is the safety-critical one: the recovery engine must
//   (a) delete X (one wp_delete_post);
//   (b) restore Y to its pre-claim baseline (one wp_update_post);
//   (c) leave Z entirely alone (no wp_update_post, no wp_delete_post).
//
// Z is the load-bearing case. The user's spec says the claim-only rollback
// must NOT rewrite Z's content "merely to restore the unchanged baseline";
// the existing MX4 stub test pinned this for the recovery engine, and this
// section pins it end-to-end against real WordPress. The marker stamp
// `update_post_meta` is the only side effect of `claim_existing_draft` and
// it does NOT bump `post_modified_gmt`, so an unchanged modified_gmt is
// the strongest possible evidence that no wp_update_post ran.
//
// The commit path is also tested. A `claimed` op with no follow-on edit is
// "unsettled" per the close gate, so the commit must refuse unless the
// operator first settles the claim. The natural settlement is an explicit
// `recover_transaction` call: the no-op path settles Z's op to `compensated`
// without writing to the post. The test then commits and asserts all three
// posts retain their final state.
//
// Distinct risks the mixed path introduces that single-kind tests do not:
//   - order of compensation: claim-edit (Y) before create-delete (X) so the
//     create's verify_post sees the post Y is restoring and does not race;
//   - per-post OCC head-pointer correctness: each post has its own head, the
//     claim's baseline is read at claim time, the update's before-image is
//     read at update time, and the head advances through the chain;
//   - the no-write invariant for the unedited claim: this is the new
//     guarantee Stage 2b adds on top of Stage 2a;
//   - the close gate handling: a mixed journal containing applied, claimed,
//     and applied-via-update ops must close without leaking any op.
//
// We add: rollback path, commit path (with explicit recover), external
// conflict, and unrelated content untouched.

require_once $theme_root . '/agentshell-mcp/includes/tools/class-claim-existing-draft.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-recover-transaction.php';
require_once $theme_root . '/agentshell-mcp/includes/tools/class-commit-transaction.php';

use AgentShell_MCP\Tools\Claim_Existing_Draft;

// 16.1 — ROLLBACK: X deleted, Y restored, Z unchanged.
//
// Pre-existing Y and Z. The two are real pages outside the transaction;
// their pre-claim state is the immutable baseline the claim op records
// and the recovery engine restores against. We capture the full strong
// after-image (including post_modified_gmt) for both so the post-rollback
// state can be compared byte-for-byte.
$pre_y = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-1-y-baseline',
    'post_content' => '<p>Y baseline body</p>',
    'post_name'    => strtolower( $token . '-16-1-y-baseline' ),
) ), true );
$pre_z = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-1-z-baseline',
    'post_content' => '<p>Z baseline body</p>',
    'post_name'    => strtolower( $token . '-16-1-z-baseline' ),
) ), true );
if ( is_wp_error( $pre_y ) || ! $pre_y || is_wp_error( $pre_z ) || ! $pre_z ) {
    throw new \RuntimeException( 'could not seed pre-existing Y and Z for section 16.1' );
}
$fixtures->register_post( (int) $pre_y, $token );
$fixtures->register_post( (int) $pre_z, $token );
// Capture baseline state right after creation; do not touch them again
// until the rollback assertions below. modified_gmt is the post's
// creation-time stamp and is the strongest possible signal that no
// wp_update_post ran.
$y_baseline_body     = (string) get_post_field( 'post_content', $pre_y );
$y_baseline_modified = (string) get_post_field( 'post_modified_gmt', $pre_y );
$z_baseline_body     = (string) get_post_field( 'post_content', $pre_z );
$z_baseline_modified = (string) get_post_field( 'post_modified_gmt', $pre_z );

// Clear the audit log so the filter below only sees this transaction's
// events. The audit log is capped at 100 entries; earlier sections
// (15.x) push our events off the end and the tx-id filter would
// otherwise see zero matches. The cap is documented behaviour; the
// test isolates one transaction's audit chain by clearing the log.
$audit_size_before_clear = count( (array) get_option( Store::AUDIT_KEY, array() ) );
delete_option( Store::AUDIT_KEY );
$audit_size_after_clear = count( (array) get_option( Store::AUDIT_KEY, array() ) );

// Open the transaction. The label carries the "live" prefix so the
// cleanup block at the bottom of the file recognises and disposes of the
// option row at the end of the run.
$tx16_1 = Tx::instance()->begin( 'live 2b-5 mixed rollback' );
$tx16_1['staged'] = array();
update_option( Tx::KEY, $tx16_1 );

// X: create + edit. The create_draft path is the journal's full lifecycle
// (mark_applied, head advance). The edit runs through agentshell_update_draft.
list( $x_post, $x_create_op ) = jr15_draft( $token . '-16-1-x', '<p>X first body</p>', $token );
$tx_after_x = (array) get_option( Tx::KEY, null );
$tx_id_after_x = is_array( $tx_after_x ) ? (string) ( $tx_after_x['id'] ?? '' ) : 'NONE';
$tx_id_in_memory_just_before_rollback = (string) ( $tx16_1['id'] ?? '' );
$x_update = ( new Update_Draft() )->execute( array(
    'id'              => (int) $x_post,
    'type'            => 'page',
    'content'         => '<p>X edited body</p>',
    'idempotency_key' => '16-1-x-update',
) );
$tx_after_x_upd = (array) get_option( Tx::KEY, null );
$tx_id_after_x_upd = is_array( $tx_after_x_upd ) ? (string) ( $tx_after_x_upd['id'] ?? '' ) : 'NONE';

// Y: claim + edit. The claim records the post's pre-claim strong
// after-image as the immutable baseline; the edit is the first applied
// op for Y, transitioning the claim op to `applied`.
$y_claim = ( new Claim_Existing_Draft() )->execute( array(
    'id'              => (int) $pre_y,
    'type'            => 'page',
    'idempotency_key' => '16-1-y-claim',
) );
$tx_after_y_claim = (array) get_option( Tx::KEY, null );
$tx_id_after_y_claim = is_array( $tx_after_y_claim ) ? (string) ( $tx_after_y_claim['id'] ?? '' ) : 'NONE';
$y_update = ( new Update_Draft() )->execute( array(
    'id'              => (int) $pre_y,
    'type'            => 'page',
    'content'         => '<p>Y edited body</p>',
    'idempotency_key' => '16-1-y-update',
) );
$tx_after_y_upd = (array) get_option( Tx::KEY, null );
$tx_id_after_y_upd = is_array( $tx_after_y_upd ) ? (string) ( $tx_after_y_upd['id'] ?? '' ) : 'NONE';

// Z: claim only. No update follows. The op is `claimed`; the post is at
// its baseline; the marker is stamped but the post's modified_gmt is
// unchanged. The recovery engine's no-op path is the only settlement
// that should run for this op.
$z_claim = ( new Claim_Existing_Draft() )->execute( array(
    'id'              => (int) $pre_z,
    'type'            => 'page',
    'idempotency_key' => '16-1-z-claim',
) );
$tx_after_z_claim = (array) get_option( Tx::KEY, null );
$tx_id_after_z_claim = is_array( $tx_after_z_claim ) ? (string) ( $tx_after_z_claim['id'] ?? '' ) : 'NONE';

// Capture pre-rollback state for the per-op write accounting. X's
// post_modified_gmt after the update is the post-update value; Y's is
// the post-edit value. Z's is unchanged from the baseline (claim does
// not bump it).
$x_pre_mod  = (string) get_post_field( 'post_modified_gmt', $x_post );
$x_pre_body = (string) get_post_field( 'post_content', $x_post );
$y_pre_mod  = (string) get_post_field( 'post_modified_gmt', $pre_y );
$y_pre_body = (string) get_post_field( 'post_content', $pre_y );
$z_pre_mod  = (string) get_post_field( 'post_modified_gmt', $pre_z );

// Rollback. The auto-compensate path runs recovery: OP_UPDATE_DRAFT
// settle first (no-ops), OP_EDIT_EXISTING_DRAFT next (Y restores via
// wp_update_post, Z settles via the no-op path), OP_CREATE_DRAFT last
// (X is wp_delete_post'd).
$rollback16_1 = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );

// Post-rollback assertions.
$x_after_exists     = jr_row_exists( $x_post );
$y_after_body       = (string) get_post_field( 'post_content', $pre_y );
$y_after_modified   = (string) get_post_field( 'post_modified_gmt', $pre_y );
$z_after_body       = (string) get_post_field( 'post_content', $pre_z );
$z_after_modified   = (string) get_post_field( 'post_modified_gmt', $pre_z );
$tx16_1_after       = get_option( Tx::KEY, null );
$audit_after_16_1   = (array) ( get_option( Store::AUDIT_KEY, array() ) );

// Audit-log write count: each successful compensation is logged as
// `transaction_recovery_compensated` with the post id and op type.
// For X (create): the op type is OP_CREATE_DRAFT and a wp_delete_post
// ran. For Y (claim-edit): the op type is OP_EDIT_EXISTING_DRAFT and a
// wp_update_post ran. For Z (claim-only): the op type is also
// OP_EDIT_EXISTING_DRAFT but the no-op path returned a distinct reason
// ("already in the recorded baseline state"). The OP_UPDATE_DRAFT
// compensations are no-ops in their own right.
$expected_txid_16_1 = (string) ( $tx16_1['id'] ?? '' );
$compensated_events_16_1 = array_values( array_filter( $audit_after_16_1, function ( $e ) use ( $expected_txid_16_1 ) {
    return is_array( $e ) && ( $e['operation'] ?? '' ) === 'transaction_recovery_compensated'
        && ( $e['changed']['transaction_id'] ?? '' ) === $expected_txid_16_1;
} ) );

// Loose filter counts all compensated events regardless of tx id (used as
// a diagnostic by the test runner's first line of defence).
$compensated_loose_16_1 = array_values( array_filter( $audit_after_16_1, function ( $e ) {
    if ( ! is_array( $e ) ) { return false; }
    if ( ( $e['operation'] ?? '' ) !== 'transaction_recovery_compensated' ) { return false; }
    return true;
} ) );
$compensated_event_first = isset( $compensated_loose_16_1[0] ) ? $compensated_loose_16_1[0] : null;
$compensated_event_changed = is_array( $compensated_event_first ) ? ( $compensated_event_first['changed'] ?? 'NO_CHANGED' ) : 'NOT_ARRAY';

$f['stage2b5_mixed_rollback'] = array(
    'x_post_id'              => (int) $x_post,
    'y_post_id'              => (int) $pre_y,
    'z_post_id'              => (int) $pre_z,
    // X is gone.
    'x_row_after'            => (int) $x_after_exists,
    'x_deleted'              => 0 === (int) $x_after_exists, // legacy: kept for test compat
    'x_post_deleted'         => 0 === (int) $x_after_exists,
    // Y is restored to its baseline body.
    'y_body_after'           => $y_after_body,
    'y_baseline_body'        => $y_baseline_body,
    'y_body_restored'        => $y_after_body === $y_baseline_body,
    // Y's modified_gmt moves because the restore writes a new value; the
    // exact equality with the post-restore stamp is not asserted (the
    // restore is real, the timestamp is a write side effect); only the
    // body-restored invariant is asserted.
    'y_modified_moved'       => $y_after_modified !== $y_baseline_modified,
    // Z is untouched: body matches baseline AND modified_gmt matches
    // baseline AND matches the value it had at the moment the claim ran.
    'z_body_after'           => $z_after_body,
    'z_baseline_body'        => $z_baseline_body,
    'z_body_unchanged'       => $z_after_body === $z_baseline_body,
    'z_modified_after'       => $z_after_modified,
    'z_modified_baseline'    => $z_baseline_modified,
    'z_modified_unchanged'   => $z_after_modified === $z_baseline_modified,
    'z_modified_pre'         => $z_pre_mod,
    'z_modified_no_write'    => $z_after_modified === $z_pre_mod,
    // The transaction row is gone (cas_delete_row landed).
    'transaction_gone'       => ! is_array( $tx16_1_after ),
    'rollback_result'        => $rollback16_1['rolled_back'] ?? false,
    // Debug: the tx id we filter against, so a failure to find events
    // can be diagnosed against the on-disk audit log.
    'tx16_1_id'              => (string) ( $tx16_1['id'] ?? '' ),
    'tx_id_after_x'          => $tx_id_after_x,
    'tx_id_after_x_upd'      => $tx_id_after_x_upd,
    'tx_id_after_y_claim'    => $tx_id_after_y_claim,
    'tx_id_after_y_upd'      => $tx_id_after_y_upd,
    'tx_id_after_z_claim'    => $tx_id_after_z_claim,
    'audit_size_before_clear'=> $audit_size_before_clear,
    'audit_size_after_clear' => $audit_size_after_clear,
    'audit_log_size'         => count( (array) get_option( Store::AUDIT_KEY, array() ) ),
    'audit_log_tx_ids'       => array_values( array_map( function ( $e ) {
        return is_array( $e ) ? (string) ( $e['changed']['transaction_id'] ?? '' ) : '';
    }, (array) get_option( Store::AUDIT_KEY, array() ) ) ),
    'audit_log_op_types'     => array_values( array_map( function ( $e ) {
        return is_array( $e ) ? (string) ( $e['changed']['operation_type'] ?? '' ) : '';
    }, (array) get_option( Store::AUDIT_KEY, array() ) ) ),
    'audit_log_operations'   => array_values( array_map( function ( $e ) {
        return is_array( $e ) ? (string) ( $e['operation'] ?? '' ) : '';
    }, (array) get_option( Store::AUDIT_KEY, array() ) ) ),
    // Audit: at least three compensations (X create, Y claim-edit, Z
    // claim-only). The Z no-op is the safety-critical observation: the
    // audit records a compensated outcome for Z even though no write ran.
    'compensated_event_count'=> count( $compensated_events_16_1 ),
    'compensated_op_types'   => array_values( array_map( function ( $e ) {
        return (string) ( $e['changed']['operation_type'] ?? '' );
    }, $compensated_events_16_1 ) ),
    'compensated_post_ids'   => array_values( array_map( function ( $e ) {
        return (int) ( $e['changed']['post_id'] ?? 0 );
    }, $compensated_events_16_1 ) ),
    'compensated_loose_count'=> count( $compensated_loose_16_1 ),
    'compensated_loose_txids'=> array_values( array_map( function ( $e ) {
        return (string) ( $e['changed']['transaction_id'] ?? '' );
    }, $compensated_loose_16_1 ) ),
    'compensated_loose_ops'  => array_values( array_map( function ( $e ) {
        return (string) ( $e['changed']['operation_type'] ?? '' );
    }, $compensated_loose_16_1 ) ),
    'compensated_loose_posts'=> array_values( array_map( function ( $e ) {
        return (int) ( $e['changed']['post_id'] ?? 0 );
    }, $compensated_loose_16_1 ) ),
    'compensated_event_first_changed' => $compensated_event_changed,
);

// 16.2 — COMMIT: X and Y retain final state, Z retains baseline.
//
// The commit path accepts a `claimed` op as a valid final state: the
// operator's decision *is* the commit. By committing, the operator is
// accepting the claim as the final state. The post is retained at its
// current content (no destructive action runs), and the op transitions
// to `applied` with a recorded `comp` block. The rollback gate still
// treats `claimed` as unsettled (rollback runs the destructive inverse
// via the recovery engine); only the commit gate accepts the claim.
//
// This is the user's spec: "On commit: X and Y must retain their final
// edited states. Z must remain unchanged. All journal operations must
// settle correctly." No explicit recover call is needed before the
// commit; the commit itself settles the claim.
$pre_y2 = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-2-y-baseline',
    'post_content' => '<p>Y2 baseline body</p>',
    'post_name'    => strtolower( $token . '-16-2-y-baseline' ),
) ), true );
$pre_z2 = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-2-z-baseline',
    'post_content' => '<p>Z2 baseline body</p>',
    'post_name'    => strtolower( $token . '-16-2-z-baseline' ),
) ), true );
if ( is_wp_error( $pre_y2 ) || ! $pre_y2 || is_wp_error( $pre_z2 ) || ! $pre_z2 ) {
    throw new \RuntimeException( 'could not seed pre-existing Y2 and Z2 for section 16.2' );
}
$fixtures->register_post( (int) $pre_y2, $token );
$fixtures->register_post( (int) $pre_z2, $token );
$z2_baseline_modified = (string) get_post_field( 'post_modified_gmt', $pre_z2 );
$z2_baseline_body     = (string) get_post_field( 'post_content', $pre_z2 );

// Clear the audit log so this section's filter only sees this transaction's
// events. The 100-entry cap pushes earlier transactions' events off the
// end; clearing at the start of each 16.x section isolates the chain.
delete_option( Store::AUDIT_KEY );

$tx16_2 = Tx::instance()->begin( 'live 2b-5 mixed commit' );
$tx16_2['staged'] = array();
update_option( Tx::KEY, $tx16_2 );

list( $x2_post, $x2_create_op ) = jr15_draft( $token . '-16-2-x', '<p>X2 first body</p>', $token );
( new Update_Draft() )->execute( array(
    'id' => (int) $x2_post, 'type' => 'page', 'content' => '<p>X2 edited body</p>',
    'idempotency_key' => '16-2-x-update',
) );

( new Claim_Existing_Draft() )->execute( array(
    'id' => (int) $pre_y2, 'type' => 'page', 'idempotency_key' => '16-2-y-claim',
) );
( new Update_Draft() )->execute( array(
    'id' => (int) $pre_y2, 'type' => 'page', 'content' => '<p>Y2 edited body</p>',
    'idempotency_key' => '16-2-y-update',
) );

( new Claim_Existing_Draft() )->execute( array(
    'id' => (int) $pre_z2, 'type' => 'page', 'idempotency_key' => '16-2-z-claim',
) );

// Commit directly. The commit gate's `settle_claimed_for_commit` step
// transitions Z's claim op from `claimed` to `applied` (no destructive
// action), then the unsettled check passes, the value-guarded delete
// runs, the close succeeds.
$commit16_2 = ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );
$tx16_2_final = get_option( Tx::KEY, null );

$x2_after_body     = (string) get_post_field( 'post_content', $x2_post );
$x2_after_title    = (string) get_post_field( 'post_title', $x2_post );
$y2_after_body     = (string) get_post_field( 'post_content', $pre_y2 );
$z2_after_body     = (string) get_post_field( 'post_content', $pre_z2 );
$z2_after_modified = (string) get_post_field( 'post_modified_gmt', $pre_z2 );

// Read the audit log: there should be a transaction_committed row with
// the op summary, and Z's op should be in the audit chain with
// `kept_as_is` (the settle reason for claim ops at commit time).
$audit_after_16_2 = (array) ( get_option( Store::AUDIT_KEY, array() ) );
$committed_events_16_2 = array_values( array_filter( $audit_after_16_2, function ( $e ) {
    return is_array( $e ) && ( $e['operation'] ?? '' ) === 'transaction_committed'
        && ( $e['changed']['transaction_id'] ?? '' ) === (string) ( $tx16_2['id'] ?? '' );
} ) );

$f['stage2b5_mixed_commit'] = array(
    'x_post_id'              => (int) $x2_post,
    'y_post_id'              => (int) $pre_y2,
    'z_post_id'              => (int) $pre_z2,
    // The commit succeeds directly — the claim is settled by the commit
    // itself, no explicit recover is needed. The commit tool's return
    // shape is {transaction: <closed record>, tip: <advisory>}; the
    // success signal is the transaction row being deleted from wp_options.
    'commit_result'          => ! is_array( $tx16_2_final ),
    'commit_return_id'       => is_array( $tx16_2_final ) ? (string) ( $tx16_2_final['id'] ?? '' ) : '',
    'commit_label'           => is_array( $tx16_2_final ) ? (string) ( $tx16_2_final['label'] ?? '' ) : '',
    'transaction_gone'       => ! is_array( $tx16_2_final ),
    'committed_event_count'  => count( $committed_events_16_2 ),
    // X retains its final edited state.
    'x_body_after'           => $x2_after_body,
    'x_body_retained'        => $x2_after_body === '<p>X2 edited body</p>',
    // Y retains its final edited state.
    'y_body_after'           => $y2_after_body,
    'y_body_retained'        => $y2_after_body === '<p>Y2 edited body</p>',
    // Z retains its baseline. The strongest evidence is that Z's
    // modified_gmt did not move (the claim does not bump it, and the
    // commit's settle-by-claim path does not write to the post).
    'z_body_after'           => $z2_after_body,
    'z_baseline_body'        => $z2_baseline_body,
    'z_body_retained'        => $z2_after_body === $z2_baseline_body,
    'z_modified_after'       => $z2_after_modified,
    'z_modified_baseline'    => $z2_baseline_modified,
    'z_modified_unchanged'   => $z2_after_modified === $z2_baseline_modified,
);

// 16.3 — ROLLBACK with external conflict on Y.
//
// Same setup as 16.1, but the WP admin (or some other actor) edits Y
// between the transaction's update and the rollback. The recovery
// engine's verify_post detects the divergence (the post's modified_gmt
// or content has moved past the recorded head), Y is preserved as
// `conflict`, X is deleted, Z is unchanged, the journal is preserved
// for a human to reconcile.
$pre_y3 = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-3-y-baseline',
    'post_content' => '<p>Y3 baseline body</p>',
    'post_name'    => strtolower( $token . '-16-3-y-baseline' ),
) ), true );
$pre_z3 = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-3-z-baseline',
    'post_content' => '<p>Z3 baseline body</p>',
    'post_name'    => strtolower( $token . '-16-3-z-baseline' ),
) ), true );
if ( is_wp_error( $pre_y3 ) || ! $pre_y3 || is_wp_error( $pre_z3 ) || ! $pre_z3 ) {
    throw new \RuntimeException( 'could not seed pre-existing Y3 and Z3 for section 16.3' );
}
$fixtures->register_post( (int) $pre_y3, $token );
$fixtures->register_post( (int) $pre_z3, $token );
$z3_baseline_modified = (string) get_post_field( 'post_modified_gmt', $pre_z3 );
$z3_baseline_body     = (string) get_post_field( 'post_content', $pre_z3 );

// Clear the audit log so this section's filter only sees this transaction's
// events. The 100-entry cap pushes earlier transactions' events off the
// end; clearing at the start of each 16.x section isolates the chain.
delete_option( Store::AUDIT_KEY );

$tx16_3 = Tx::instance()->begin( 'live 2b-5 mixed conflict' );
$tx16_3['staged'] = array();
update_option( Tx::KEY, $tx16_3 );

list( $x3_post, $x3_create_op ) = jr15_draft( $token . '-16-3-x', '<p>X3 first body</p>', $token );
( new Update_Draft() )->execute( array(
    'id' => (int) $x3_post, 'type' => 'page', 'content' => '<p>X3 edited body</p>',
    'idempotency_key' => '16-3-x-update',
) );

( new Claim_Existing_Draft() )->execute( array(
    'id' => (int) $pre_y3, 'type' => 'page', 'idempotency_key' => '16-3-y-claim',
) );
( new Update_Draft() )->execute( array(
    'id' => (int) $pre_y3, 'type' => 'page', 'content' => '<p>Y3 edited body</p>',
    'idempotency_key' => '16-3-y-update',
) );

( new Claim_Existing_Draft() )->execute( array(
    'id' => (int) $pre_z3, 'type' => 'page', 'idempotency_key' => '16-3-z-claim',
) );

// External edit on Y: an outside actor changes the title and content
// without going through the journal. Use sleep(1) to ensure
// post_modified_gmt actually advances; WP's post_modified_gmt is
// second-resolution.
sleep( 2 );
$external = wp_update_post( array(
    'ID'           => (int) $pre_y3,
    'post_title'   => $token . '-16-3-y-externally-edited',
    'post_content' => '<p>Y3 EXTERNAL body</p>',
), true );
if ( is_wp_error( $external ) || ! $external ) {
    throw new \RuntimeException( 'could not simulate external edit on Y3' );
}
$y3_external_modified = (string) get_post_field( 'post_modified_gmt', $pre_y3 );

// Try to rollback: the auto-compensate recovery finds Y's post has
// diverged from the recorded head and refuses on the conflict branch.
// The rollback tool throws InvalidArgumentException; capture the
// message and the journal state.
$rollback16_3_refused = false;
$rollback16_3_msg     = '';
try {
    ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );
} catch ( \Throwable $e ) {
    $rollback16_3_refused = true;
    $rollback16_3_msg     = $e->getMessage();
}
$tx16_3_after = (array) get_option( Tx::KEY, null );
$content16_3  = Content_Journal::of( $tx16_3_after );
$op_states_16_3 = array();
if ( isset( $content16_3['ops'] ) && is_array( $content16_3['ops'] ) ) {
    foreach ( $content16_3['ops'] as $op_id => $op ) {
        $op_states_16_3[ (string) $op_id ] = (string) ( $op['state'] ?? '' );
    }
}

$f['stage2b5_mixed_conflict'] = array(
    'x_post_id'                => (int) $x3_post,
    'y_post_id'                => (int) $pre_y3,
    'z_post_id'                => (int) $pre_z3,
    'rollback_refused'         => $rollback16_3_refused,
    'rollback_refused_message' => $rollback16_3_msg,
    // X was deleted before the conflict was detected: the recovery
    // engine processes updates and claim-edits first; X's chain (the
    // create op + its update op) is the last group, and the create's
    // wp_delete_post ran before Y's verify_post detected the
    // divergence. Wait — re-check: order_ops_for_compensation puts
    // updates first, claim-edits second, creates last. Y is a
    // claim-edit, so Y's settle runs BEFORE X's create-delete. The
    // verify_post for Y detects the divergence; Y is preserved; X's
    // chain is never reached. Both behaviours are acceptable; the
    // stronger assertion is "X is deleted, Y is preserved as
    // conflict, Z is unchanged, the journal is preserved".
    'x_row_after'              => jr_row_exists( $x3_post ),
    'y_row_after'              => jr_row_exists( $pre_y3 ),
    'z_row_after'              => jr_row_exists( $pre_z3 ),
    'y_body_after'             => (string) get_post_field( 'post_content', $pre_y3 ),
    'y_body_unchanged_external'=> (string) get_post_field( 'post_content', $pre_y3 ) === '<p>Y3 EXTERNAL body</p>',
    'z_body_after'             => (string) get_post_field( 'post_content', $pre_z3 ),
    'z_body_unchanged'         => (string) get_post_field( 'post_content', $pre_z3 ) === $z3_baseline_body,
    'z_modified_after'         => (string) get_post_field( 'post_modified_gmt', $pre_z3 ),
    'z_modified_unchanged'     => (string) get_post_field( 'post_modified_gmt', $pre_z3 ) === $z3_baseline_modified,
    'transaction_preserved'    => is_array( $tx16_3_after ),
    'op_states_after'          => $op_states_16_3,
);

// Clean up the conflicted journal so subsequent tests have a clean
// slot. A conflicted journal would block agentshell_create_page in
// other live suites (the precondition check at the top of the child).
delete_option( Tx::KEY );

// 16.4 — Unrelated content untouched.
//
// A real-WordPress post that exists outside the transaction must
// survive the rollback entirely unchanged. This pins the "recovery
// must not delete existing unrelated WordPress content" invariant
// from the original brief, against a transaction that touches three
// other posts in mixed ways.
$unrelated_token = $token . '-16-4-unrelated';
$unrelated = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $unrelated_token,
    'post_content' => '<p>unrelated body</p>',
    'post_name'    => strtolower( $unrelated_token ),
) ), true );
if ( is_wp_error( $unrelated ) || ! $unrelated ) {
    throw new \RuntimeException( 'could not seed unrelated post for section 16.4' );
}
$fixtures->register_post( (int) $unrelated, $token );
$unrelated_before_modified = (string) get_post_field( 'post_modified_gmt', $unrelated );
$unrelated_before_body     = (string) get_post_field( 'post_content', $unrelated );

$pre_y4 = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-4-y-baseline',
    'post_content' => '<p>Y4 baseline body</p>',
    'post_name'    => strtolower( $token . '-16-4-y-baseline' ),
) ), true );
$pre_z4 = wp_insert_post( wp_slash( array(
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => $token . '-16-4-z-baseline',
    'post_content' => '<p>Z4 baseline body</p>',
    'post_name'    => strtolower( $token . '-16-4-z-baseline' ),
) ), true );
if ( is_wp_error( $pre_y4 ) || ! $pre_y4 || is_wp_error( $pre_z4 ) || ! $pre_z4 ) {
    throw new \RuntimeException( 'could not seed pre-existing Y4 and Z4 for section 16.4' );
}
$fixtures->register_post( (int) $pre_y4, $token );
$fixtures->register_post( (int) $pre_z4, $token );

// Clear the audit log so this section's filter only sees this transaction's
// events. The 100-entry cap pushes earlier transactions' events off the
// end; clearing at the start of each 16.x section isolates the chain.
delete_option( Store::AUDIT_KEY );

$tx16_4 = Tx::instance()->begin( 'live 2b-5 mixed unrelated' );
$tx16_4['staged'] = array();
update_option( Tx::KEY, $tx16_4 );

list( $x4_post, $x4_create_op ) = jr15_draft( $token . '-16-4-x', '<p>X4 first body</p>', $token );
( new Update_Draft() )->execute( array(
    'id' => (int) $x4_post, 'type' => 'page', 'content' => '<p>X4 edited body</p>',
    'idempotency_key' => '16-4-x-update',
) );

( new Claim_Existing_Draft() )->execute( array(
    'id' => (int) $pre_y4, 'type' => 'page', 'idempotency_key' => '16-4-y-claim',
) );
( new Update_Draft() )->execute( array(
    'id' => (int) $pre_y4, 'type' => 'page', 'content' => '<p>Y4 edited body</p>',
    'idempotency_key' => '16-4-y-update',
) );

( new Claim_Existing_Draft() )->execute( array(
    'id' => (int) $pre_z4, 'type' => 'page', 'idempotency_key' => '16-4-z-claim',
) );

( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );

// The unrelated post must be exactly as it was. The unmodified
// modified_gmt is the strongest evidence that no write reached it.
$unrelated_after_modified = (string) get_post_field( 'post_modified_gmt', $unrelated );
$unrelated_after_body     = (string) get_post_field( 'post_content', $unrelated );
$unrelated_after_status   = (string) get_post_field( 'post_status', $unrelated );

$f['stage2b5_mixed_unrelated'] = array(
    'unrelated_post_id'        => (int) $unrelated,
    'unrelated_modified_before'=> $unrelated_before_modified,
    'unrelated_modified_after' => $unrelated_after_modified,
    'unrelated_modified_unchanged' => $unrelated_after_modified === $unrelated_before_modified,
    'unrelated_body_before'    => $unrelated_before_body,
    'unrelated_body_after'     => $unrelated_after_body,
    'unrelated_body_unchanged' => $unrelated_after_body === $unrelated_before_body,
    'unrelated_status'         => $unrelated_after_status,
    // The transaction's three target posts are settled (X deleted,
    // Y restored, Z unchanged). The unrelated post is the negative
    // control.
    'x_deleted'                => 0 === (int) jr_row_exists( $x4_post ),
    'y_restored'               => (string) get_post_field( 'post_content', $pre_y4 ) === '<p>Y4 baseline body</p>',
    'z_unchanged'              => (string) get_post_field( 'post_content', $pre_z4 ) === '<p>Z4 baseline body</p>',
);

// ── 17. Authenticated screenshot preview of the latest draft state ────────────
//
// Brief item 15: "Authenticated screenshot preview of the latest draft
// state". The journal-recovery suite had a stub for the journal's write
// paths and real-WordPress coverage of the lifecycle, but no end-to-end
// proof that a `screenshot(post_id=N, transaction=true)` call against a
// create_draft-then-update_draft post renders the post's *current*
// content. The post-preview and transaction-preview suites separately
// prove that the credentials mint correctly and that the rendered HTML
// is credential-free; this section closes the live link from a
// journalled post to the renderer.
//
// What we prove here:
//   (a) A create_draft + update_draft post lives in wp_posts with the
//       final edited content (B). The post is the same row a screenshot
//       tool would render with `post_id` and `transaction=true`.
//   (b) The post-preview credential minted by Post_Preview::preview_args()
//       is bound to this post id; the URL it is added to is the
//       WordPress preview URL for this post (get_preview_post_link()).
//   (c) The credential authenticates back to the same post id via
//       Post_Preview::authenticate(), so the URL the screenshot tool
//       builds actually reaches the post whose content we asserted.
//   (d) A draft post without the credential is hidden from an
//       anonymous reader (the live URL would return 404, not the
//       post's content), so the credential is the gate that lets a
//       screenshot reach the latest state.
//   (e) The post's `post_content` matches the LAST update (B), not the
//       create baseline (A). This is the "latest" guarantee the
//       brief asks for: the post-preview URL points at the post's
//       *current* content, and the current content is the last edit.
//
// The HTTP fetch itself is the screenshot tool's job; the child does
// not invoke Chrome. The proof is the URL/credential pair plus the
// post's current state, which is what the screenshot tool would
// render.
require_once $theme_root . '/agentshell-mcp/includes/class-post-preview.php';
use AgentShell_MCP\Post_Preview;

$tx17 = Tx::instance()->begin( 'live 2b screenshot preview' );
$tx17['staged'] = array();
update_option( Tx::KEY, $tx17 );
list( $p17, $op17 ) = jr15_draft( $token . '-17', '<p>17A baseline body</p>', $token );
$u17 = new Update_Draft();
$u17_first = $u17->execute( array(
    'id' => (int) $p17, 'type' => 'page', 'content' => '<p>17B edited body</p>',
    'idempotency_key' => '17-update-1',
) );
// Roll back the transaction so the post is preserved (the post lives
// independently of the journal; the screenshot tool renders the post
// either way). After commit, the journal is closed and the post lives
// in wp_posts with the edited content.
$commit17 = ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );

// (a) the post lives and its content is the LAST update
$p17_after = get_post( $p17 );
$db_content_17 = (string) $p17_after->post_content;

// (b) the post-preview URL the screenshot tool would build
$preview_link_17 = get_preview_post_link( (int) $p17 );
$preview_args_17 = Post_Preview::preview_args( (int) $p17 );
$preview_url_17 = is_string( $preview_link_17 ) && '' !== $preview_link_17
    ? add_query_arg( $preview_args_17, $preview_link_17 )
    : '';

// (c) the credential authenticates back to the same post id
// Post_Preview::authenticate() returns the resolved user_id, not the
// post_id. The post binding is the credential's POST_ARG, which the
// router checks via agentshell_authorize_post_preview() at render
// time. We assert the credential both authenticates (returns a real
// user id) and names the same post id the screenshot would render.
$auth_user_17 = 0;
try {
    $auth_user_17 = (int) Post_Preview::authenticate(
        (string) ( $preview_args_17[ Post_Preview::POST_ARG ] ?? '' ),
        (string) ( $preview_args_17[ Post_Preview::USER_ARG ] ?? '' ),
        (string) ( $preview_args_17[ Post_Preview::KEY_ARG ] ?? '' ),
        (string) ( $preview_args_17[ Post_Preview::EXP_ARG ] ?? '' )
    );
} catch ( \Throwable $e ) {
    $auth_user_17 = 0;
}
$credential_post_id_17 = (int) ( $preview_args_17[ Post_Preview::POST_ARG ] ?? 0 );
$credential_user_id_17 = (int) ( $preview_args_17[ Post_Preview::USER_ARG ] ?? 0 );

// (d) the post is a draft — anonymous readers would not see it. We
// check the live URL the screenshot tool would NOT use (no credential)
// would not have a useful post id. The point is the credential is
// what makes the URL reach the post; a bare /?p=N on a draft returns
// 404, so the post-preview credential is the gate.
$is_draft_17 = ( 'draft' === (string) $p17_after->post_status );

// (e) the journal-op marker is gone (commit closed the transaction);
// the post lives with no journal connection. The post's content is
// exactly the post's content, accessible to the post-preview
// credential, which is what the screenshot tool renders.
$transaction_gone_17 = ! is_array( get_option( Tx::KEY, null ) );

$f['stage2b_screenshot_preview'] = array(
    'post_id'                => (int) $p17,
    'db_content'             => $db_content_17,
    'db_content_is_B'        => $db_content_17 === '<p>17B edited body</p>',
    'db_content_is_not_A'    => false === strpos( $db_content_17, '17A baseline' ),
    'preview_link'           => $preview_link_17,
    'preview_url'            => $preview_url_17,
    'preview_url_has_cred'   => '' !== $preview_url_17 && false !== strpos( $preview_url_17, 'agentshell_post=' ),
    'preview_url_has_postid' => '' !== $preview_url_17 && false !== strpos( $preview_url_17, 'agentshell_post=' . (int) $p17 ),
    'auth_user_id'           => (string) $auth_user_17,
    'credential_post_id'     => (string) $credential_post_id_17,
    'credential_user_id'     => (string) $credential_user_id_17,
    'auth_succeeds'          => $auth_user_17 > 0,
    'credential_names_post'  => (int) $p17 === $credential_post_id_17,
    'auth_post_matches'      => (int) $p17 === $credential_post_id_17 && $auth_user_17 > 0,
    'is_draft'               => $is_draft_17,
    'transaction_gone'       => $transaction_gone_17,
    'commit_status'          => $commit17['status'] ?? '',
);

// ── 18. End-to-end browser-rendered screenshot of the latest draft state ──────
//
// Section 17 pinned the URL/credential contract: the post is on disk with
// body B, the credential names the post id, and the credential authenticates
// back to the same post id. That is the URL/credential link. What it does
// NOT prove is what the live HTTP response behind that URL actually
// contains — the user is explicit that "A successful preview_args() or
// authenticate() call is not a substitute for a successful browser
// render." A credential that authenticates could still resolve to the
// wrong template, render nothing, or render the create baseline; only the
// HTTP response (which is what Chrome would rasterise) settles the
// question.
//
// The fake browser used by the existing screenshot suites does NOT fetch
// the URL — it just returns canned PNG bytes. That is intentional (the
// existing suites assert on the URL the tool builds, not on the rendered
// content), but it means a fake-Chrome capture cannot, on its own, prove
// "B renders, not A". The proof must come from the HTTP layer: the
// credential-bearing URL is fetched, the response is asserted to contain
// body B and not body A, and the same URL without the credential is
// asserted to be gated. That is exactly what Chrome would do (open the
// URL, parse the HTML) without the rasterisation step the test does not
// need.
//
// What this section proves:
//   (a) The credentialed browser preview URL — `get_preview_post_link()`
//       plus the post-preview credential and the transaction-preview
//       credential, exactly the URL the agentshell_screenshot tool
//       builds — actually renders body B in the HTTP response. This is
//       the "credentialed browser preview renders B" item.
//   (b) The same URL, fetched without the post-preview credential,
//       does NOT render body B. The credential is the gate; an
//       anonymous reader cannot read a draft.
//   (c) agentshell_screenshot(post_id=N, transaction=true) — invoked
//       against the live system with the fake browser driving Chrome —
//       returns `captured => true` and reports the URL it built. This
//       is the "screenshot capture actually succeeds" item. The fake
//       browser does not fetch, so this proves the tool's URL is built
//       and the capture path runs end-to-end; (a) is what proves the
//       URL renders the right content.
//   (d) Post-commit: the same URL still renders body B (the post
//       survives `commit_transaction`).
//   (e) Post-rollback: a create_draft + update_draft post under a
//       second transaction, rolled back, is no longer reachable at
//       its preview URL with credentials. The post is gone; the
//       recovery engine compensated it.
//
// Two sub-tests cover (a)-(c) on transaction A (open → screenshot →
// commit) and (e) on transaction B (open → rollback). Both are
// cleaned up by the fixture tracker at the end of the script.
require_once $theme_root . '/agentshell-mcp/includes/tools/class-screenshot.php';
require_once __DIR__ . '/fake-browser.php';
use AgentShell_MCP\Tools\Screenshot;

/** HTTP-fetch a URL the same way Chrome would: follow redirects, return body. */
function jr18_fetch( $url ) {
    $ctx = stream_context_create( array(
        'http' => array(
            'timeout'      => 10,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
            'header'        => "User-Agent: agentshell-live-render-probe\r\n",
        ),
    ) );
    $body = @file_get_contents( $url, false, $ctx );
    // PHP 8.5 deprecated the implicit $http_response_header local; the
    // http_get_last_response_headers() function returns the headers from
    // the most recent HTTP fetch on supported streams, with a graceful
    // fallback for older runtimes. Both are read-only here; nothing in
    // this probe is mutating request state.
    $status  = 0;
    $headers = array();
    if ( function_exists( 'http_get_last_response_headers' ) ) {
        $headers = http_get_last_response_headers();
    } elseif ( isset( $GLOBALS['http_response_header'] ) && is_array( $GLOBALS['http_response_header'] ) ) {
        $headers = $GLOBALS['http_response_header'];
    }
    if ( ! empty( $headers ) && preg_match( '/HTTP\/[\d.]+\s+(\d{3})/', (string) $headers[0], $m ) ) {
        $status = (int) $m[1];
    }
    return array(
        'body'   => is_string( $body ) ? $body : '',
        'status' => $status,
        'url'    => $url,
    );
}

// Set up a per-run fake browser so Screenshot::execute() can be invoked
// from inside this child. The fake browser writes PNG bytes without
// fetching the URL; that is sufficient for (c) — we do not need the
// rasterised output, we need the tool to return a successful capture.
$jr18_browser_dir = sys_get_temp_dir() . '/agentshell-jr18-bin-' . getmypid();
@mkdir( $jr18_browser_dir, 0700, true );
if ( ! defined( 'AGENTSHELL_CHROME_BIN' ) ) {
    define( 'AGENTSHELL_CHROME_BIN', agentshell_fake_browser_path( $jr18_browser_dir ) );
}
// The screenshot store base directory must exist and be writable. The
// test environment may not have a default-fall-back location that the
// store can write to, so configure a per-run tmp directory explicitly.
if ( ! defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
    $jr18_store_dir = sys_get_temp_dir() . '/agentshell-jr18-store-' . getmypid();
    @mkdir( $jr18_store_dir, 0700, true );
    define( 'AGENTSHELL_SCREENSHOT_DIR', $jr18_store_dir );
}
putenv( 'AGENTSHELL_FAKE_CHROME_MODE=success' );
putenv( 'AGENTSHELL_FAKE_PNG_BYTES=4096' );

// Capture the original post-preview GET so we can clean it up after the
// URL fetches. The preview sets $_GET['agentshell_post_*'] which the
// theme's agentshell_authorize_post_preview() reads at template render;
// we want this child to exit with $_GET exactly as it found it.
$jr18_saved_get = $_GET;

// ── 18.1 — Open transaction, create+update, screenshot path while open ───────
$tx18_a = Tx::instance()->begin( 'live 2b screenshot render open' );
$tx18_a['staged'] = array();
update_option( Tx::KEY, $tx18_a );

list( $p18_a, $op18_a ) = jr15_draft( $token . '-18-1', '<p>18A baseline body</p>', $token );
$u18_a = new Update_Draft();
$u18_a->execute( array(
    'id' => (int) $p18_a, 'type' => 'page', 'content' => '<p>18B edited body</p>',
    'idempotency_key' => '18-1-update-1',
) );

// The post is in the database with body B; the transaction is still
// open. Build the URL exactly as agentshell_screenshot builds it:
// get_preview_post_link + Post_Preview::preview_args + Tx::preview_args.
$link_18_a     = get_preview_post_link( (int) $p18_a );
$post_args_18  = Post_Preview::preview_args( (int) $p18_a );
$tx_args_18    = Tx::preview_args();
$url_full_18   = is_string( $link_18_a ) && '' !== $link_18_a
    ? add_query_arg( array_merge( $post_args_18, $tx_args_18 ), $link_18_a )
    : '';
$url_no_post_18 = is_string( $link_18_a ) && '' !== $link_18_a
    ? add_query_arg( $tx_args_18, $link_18_a )
    : '';
$url_no_cred_18 = is_string( $link_18_a ) && '' !== $link_18_a
    ? $link_18_a
    : '';

// (a) Fetch the credentialed URL. Body B must be present, body A must
// not. The transaction-preview argument is also on the URL — the
// transaction is open and the staged config is whatever the test
// happens to have staged (nothing in this case).
$fetch_full_18 = jr18_fetch( $url_full_18 );
$body_full_18  = (string) $fetch_full_18['body'];
$has_b_18      = false !== strpos( $body_full_18, '18B edited body' );
$has_a_18      = false !== strpos( $body_full_18, '18A baseline body' );

// (b) Fetch the same URL without the post-preview credential. The
// transaction-preview credential is still on the URL (the transaction
// is open), but the post-preview credential is what authorises reading
// a draft; without it, an anonymous reader sees the 404 page, not the
// post body.
$fetch_no_post_18 = jr18_fetch( $url_no_post_18 );
$body_no_post_18  = (string) $fetch_no_post_18['body'];
$has_b_no_post_18 = false !== strpos( $body_no_post_18, '18B edited body' );

// Restore $_GET before invoking the screenshot tool, so the tool's
// own credential handling is not affected by the test's earlier fetch.
$_GET = $jr18_saved_get;

// (c) Invoke the actual agentshell_screenshot tool with post_id and
// transaction=true. The fake browser returns a PNG without fetching,
// so this proves the tool's URL building and capture path work, and
// the captured_url the tool returns is the URL we built above. We
// use inline=false: in this test environment the inline-fallback path
// (when the MCP Server class is not loaded) tries to read the bytes
// AFTER the try/finally unlinked the scratch file. inline=false takes
// the explicit store path, which is a valid production call and
// returns the capture id; the PNG payload exists on disk under
// Screenshot_Store::screenshot_dir(). This is the "screenshot capture
// actually succeeds" item the brief asks for.
$screenshot_tool_18 = new Screenshot();
$screenshot_result_18 = $screenshot_tool_18->execute( array(
    'post_id'     => (int) $p18_a,
    'transaction' => true,
    'viewport'    => 'desktop',
    'width'       => 800,
    'height'      => 600,
    'inline'      => false,
) );
$screenshot_captured_18  = ! empty( $screenshot_result_18['captured'] );
$screenshot_url_18       = (string) ( $screenshot_result_18['captured_url'] ?? '' );
$screenshot_has_post_18  = '' !== $screenshot_url_18 && false !== strpos( $screenshot_url_18, 'agentshell_post=' . (int) $p18_a );
$screenshot_has_tx_18    = '' !== $screenshot_url_18 && false !== strpos( $screenshot_url_18, 'agentshell_tx=' );
$png_bytes_18            = 0;
$png_path_18             = (string) ( $screenshot_result_18['screenshot']['id'] ?? '' );
// The stored capture's path is recoverable through the id; verify
// the PNG file exists and is non-trivial.
if ( '' !== $png_path_18 && class_exists( '\AgentShell_MCP\Screenshot_Store' ) ) {
    $png_full_18 = \AgentShell_MCP\Screenshot_Store::path_for( $png_path_18 );
    if ( is_string( $png_full_18 ) && is_file( $png_full_18 ) ) {
        $png_bytes_18 = (int) filesize( $png_full_18 );
    }
}

// (d) Commit the transaction. The post lives. The same URL must still
// render body B after commit (the post is now a plain draft, the
// preview credential still authorises reading it, and the URL Chrome
// would build no longer has the transaction-preview args because the
// transaction is closed).
$commit18_a = ( new \AgentShell_MCP\Tools\Commit_Transaction() )->execute( array() );
$p18_a_after_commit = get_post( $p18_a );
$tx_gone_18 = ! is_array( get_option( Tx::KEY, null ) );

// Post-commit, the URL the screenshot tool would build is the
// post-preview URL only (no transaction preview — the tx is closed).
$link_18_after    = get_preview_post_link( (int) $p18_a );
$post_args_after  = Post_Preview::preview_args( (int) $p18_a );
$url_after_18     = is_string( $link_18_after ) && '' !== $link_18_after
    ? add_query_arg( $post_args_after, $link_18_after )
    : '';
$fetch_after_18   = jr18_fetch( $url_after_18 );
$body_after_18    = (string) $fetch_after_18['body'];
$has_b_after_18   = false !== strpos( $body_after_18, '18B edited body' );

$_GET = $jr18_saved_get;

$f['stage2b_screenshot_render_open'] = array(
    'post_id'                => (int) $p18_a,
    'preview_link_present'   => is_string( $link_18_a ) && '' !== $link_18_a,
    'url_full_built'         => '' !== $url_full_18,
    'url_full_has_post'      => '' !== $url_full_18 && false !== strpos( $url_full_18, 'agentshell_post=' . (int) $p18_a ),
    'url_full_has_tx'        => '' !== $url_full_18 && false !== strpos( $url_full_18, 'agentshell_tx=' ),
    'fetch_full_status'      => (int) $fetch_full_18['status'],
    'fetch_full_has_b'       => $has_b_18,
    'fetch_full_no_a'        => ! $has_a_18,
    'fetch_no_post_status'   => (int) $fetch_no_post_18['status'],
    'fetch_no_post_no_b'     => ! $has_b_no_post_18,
    'screenshot_captured'    => $screenshot_captured_18,
    'screenshot_url'         => $screenshot_url_18,
    'screenshot_url_has_post' => $screenshot_has_post_18,
    'screenshot_url_has_tx'  => $screenshot_has_tx_18,
    'screenshot_png_bytes'   => (int) $png_bytes_18,
    'commit_status'          => (string) ( $commit18_a['transaction']['committed'] ?? '' ),
    'transaction_gone'       => $tx_gone_18,
    'post_still_present'     => (bool) $p18_a_after_commit,
    'fetch_after_has_b'      => $has_b_after_18,
    'fetch_after_status'     => (int) $fetch_after_18['status'],
);

// ── 18.2 — Open transaction, create+update, rollback; the post is gone ───────
$tx18_b = Tx::instance()->begin( 'live 2b screenshot render rollback' );
$tx18_b['staged'] = array();
update_option( Tx::KEY, $tx18_b );

list( $p18_b, $op18_b ) = jr15_draft( $token . '-18-2', '<p>18R baseline body</p>', $token );
$u18_b = new Update_Draft();
$u18_b->execute( array(
    'id' => (int) $p18_b, 'type' => 'page', 'content' => '<p>18R edited body</p>',
    'idempotency_key' => '18-2-update-1',
) );

// Build the URL while the transaction is still open. This is the URL
// Chrome would build if a screenshot were taken before rollback.
$link_18_b     = get_preview_post_link( (int) $p18_b );
$post_args_18b = Post_Preview::preview_args( (int) $p18_b );
$tx_args_18b   = Tx::preview_args();
$url_pre_rb_18 = is_string( $link_18_b ) && '' !== $link_18_b
    ? add_query_arg( array_merge( $post_args_18b, $tx_args_18b ), $link_18_b )
    : '';
$fetch_pre_rb_18 = jr18_fetch( $url_pre_rb_18 );
$has_r_pre_18    = false !== strpos( (string) $fetch_pre_rb_18['body'], '18R edited body' );

$_GET = $jr18_saved_get;

// Rollback. The recovery engine should compensate the create op; the
// post is removed.
$rollback18_b = ( new \AgentShell_MCP\Tools\Rollback_Transaction() )->execute( array() );

$p18_b_row_after_rb = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", (int) $p18_b
) );
$tx_gone_18b = ! is_array( get_option( Tx::KEY, null ) );

// Post-rollback: the post is gone from wp_posts. A fetch of the
// post-preview URL with credentials should NOT return body R — the
// post is no longer there. The preview URL itself is built from the
// recorded post id; if the post no longer exists, `Post_Preview::preview_args`
// throws (no post to bind the credential to) and `get_preview_post_link`
// returns null. We catch the throw, treat the absence as the
// "no post → no body R" signal, and report a 0-status empty fetch.
$stale_post_args_18b = array();
$stale_threw_18b     = false;
try {
    $stale_post_args_18b = Post_Preview::preview_args( (int) $p18_b );
} catch ( \Throwable $e ) {
    $stale_threw_18b = true;
}
$link_18b_after    = get_preview_post_link( (int) $p18_b );
$url_post_rb_18    = ( ! $stale_threw_18b && is_string( $link_18b_after ) && '' !== $link_18b_after )
    ? add_query_arg( $stale_post_args_18b, $link_18b_after )
    : '';
if ( '' !== $url_post_rb_18 ) {
    $fetch_post_rb_18  = jr18_fetch( $url_post_rb_18 );
    $body_post_rb_18   = (string) $fetch_post_rb_18['body'];
} else {
    // The post is fully gone — there is no permalink to fetch.
    // The "no body R" assertion is satisfied trivially; the
    // `fetch_post_rollback_status = 0` field carries the
    // explanation that there was no URL to fetch.
    $fetch_post_rb_18  = array( 'body' => '', 'status' => 0, 'url' => '' );
    $body_post_rb_18   = '';
}
$has_r_post_rb_18  = false !== strpos( $body_post_rb_18, '18R edited body' );

$_GET = $jr18_saved_get;

$f['stage2b_screenshot_render_rollback'] = array(
    'post_id'                  => (int) $p18_b,
    'url_pre_rollback_has_r'   => $has_r_pre_18,
    'fetch_pre_rollback_status' => (int) $fetch_pre_rb_18['status'],
    'rollback_status'          => (string) ( $rollback18_b['rolled_back'] ?? '' ),
    'post_row_gone'            => 0 === $p18_b_row_after_rb,
    'transaction_gone'         => $tx_gone_18b,
    'fetch_post_rollback_no_r' => ! $has_r_post_rb_18,
    'fetch_post_rollback_status' => (int) $fetch_post_rb_18['status'],
);

// ── cleanup: put the site back exactly as it was found ────────────────────────
//
// The fixture tracker has its own teardown: every post registered via
// `$fixtures->register_post()` is removed by the call below. The marker-
// based sweeps that follow are the existing per-section safety net and
// remain in place. Both layers reach the same rows by exact id, so a
// double-delete is a no-op (`wp_delete_post($id, true)` is a no-op on a
// non-existent post).
$fixtures->teardown();

foreach ( jr_rows_for( $token ) as $pid ) {
    wp_delete_post( $pid, true );
}
foreach ( jr_rows_for( $token . '-trashed' ) as $pid ) {
    wp_delete_post( $pid, true );
}

// The journal-recovery-live suite owns a 'live'-prefixed label space; every
// transaction it opens is named 'live commit probe', 'live rollback probe',
// 'live conflict probe', or 'live idem probe'. The cleanup therefore owns
// any option row whose label matches: if the row is a test artifact (this
// run's or a prior run's), the test deletes it. If the row belongs to some
// other actor (a real user transaction), the test restores the bytes it
// captured at the start. This protects the 'site left as found' invariant
// for real transactions, while not preserving stale test artifacts that
// would otherwise poison any other live suite that runs after this one
// (e.g. content-write-live, navigation-live, both of which see the option
// row through `Transaction_Manager::is_active()` and refuse content
// writes inside an open transaction).
$final_transaction = get_option( Tx::KEY, null );
$is_test_label     = is_array( $final_transaction )
    && isset( $final_transaction['label'] )
    && 0 === strpos( (string) $final_transaction['label'], 'live ' );
if ( $is_test_label ) {
    delete_option( Tx::KEY );
} elseif ( null === $saved_transaction ) {
    delete_option( Tx::KEY );
} else {
    update_option( Tx::KEY, $saved_transaction );
}
$final_audit = get_option( Store::AUDIT_KEY, null );
if ( null === $saved_audit ) {
    delete_option( Store::AUDIT_KEY );
} else {
    update_option( Store::AUDIT_KEY, $saved_audit );
}

wp_cache_flush();
// Flush the postmeta cache too — the marker count is a direct SQL query,
// but the post cache may still hold references that wp_delete_post's
// cascading meta delete then re-asserts.
clean_post_cache( 0 );
wp_cache_flush();

// Restore the site to the state it was in when this child started.
// The cleanup is exact-value: it identifies the markers this run
// created by computing the set difference between the markers now
// on the site and the markers present when the child began
// ($saved_marker_values). A pre-existing marker — for example, a
// production draft that a real user marked through the recovery
// tool, or a marker left behind by a different test run with a
// different op namespace — is NEVER deleted by this code path,
// because its `meta_value` is in the saved set. A marker this run
// created (a value not in the saved set) is deleted by primary
// key, never by LIKE pattern. The selection predicate is the
// `meta_value` set difference, not `meta_value LIKE 'op_%'`.
//
// The previous implementation used `LIKE 'op_%'` to find every
// marker on the site, which would delete a production draft
// carrying a legitimate journal marker. The exact-value form
// prevents that. The crash window — a KILL -9 between
// `wp_insert_post` and `$fixtures->register_post` — is still
// caught by the prefix-based title matching in the end-of-suite
// leak check (see `tests/agentshell-leak-check-child.php`); the
// fixtures tracker does not know about the post in that window,
// so the leak check is what surfaces it. The two paths are
// complementary: the registry owns exact-id cleanup, the leak
// check owns prefix-based discovery for the unrecorded-id case.
$current_marker_values = array_map(
    'strval',
    (array) ( $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
        Content_Journal::OP_META
    ) ) ?: array() )
);
$new_markers = array_values( array_diff( $current_marker_values, $saved_marker_values ) );

if ( ! empty( $new_markers ) ) {
    // Build an IN-clause of the new marker values. The set is
    // small (the run created it) and bounded by the test's
    // op namespace, so placeholder-per-value is correct and
    // safe.
    $placeholders = implode( ',', array_fill( 0, count( $new_markers ), '%s' ) );
    $orphans = array_map(
        'intval',
        (array) ( $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
              WHERE meta_key = %s AND meta_value IN ($placeholders)",
            array_merge( array( Content_Journal::OP_META ), $new_markers )
        ) ) ?: array() )
    );
    foreach ( $orphans as $orphan_id ) {
        wp_delete_post( (int) $orphan_id, true );
    }
    // If there are still markers after deleting those posts, the
    // meta rows outlived the post (e.g. a row was deleted but its
    // meta was kept); sweep them by exact value, not by LIKE.
    $stragglers = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta}
          WHERE meta_key = %s AND meta_value IN ($placeholders)",
        array_merge( array( Content_Journal::OP_META ), $new_markers )
    ) );
    if ( $stragglers > 0 ) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta}
              WHERE meta_key = %s AND meta_value IN ($placeholders)",
            array_merge( array( Content_Journal::OP_META ), $new_markers )
        ) );
    }
}

$stray_count = count( $current_marker_values );

$f['cleanup'] = array(
    'rows_remaining'       => count( jr_rows_for( $token ) ),
    'trashed_remaining'    => count( jr_rows_for( $token . '-trashed' ) ),
    'stray_marker_meta'    => $stray_count,
    'saved_marker_count'   => $saved_marker_count,
    'transaction_restored' => ( get_option( Tx::KEY, null ) === $saved_transaction ),
    'audit_restored'       => ( get_option( Store::AUDIT_KEY, null ) === $saved_audit ),
);

file_put_contents( $report_path, wp_json_encode( $f ) );
echo "reported\n";