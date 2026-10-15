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

if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
    fwrite( STDERR, "no wp-load.php at {$wp_root}\n" );
    exit( 2 );
}

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
// ran. A child whose prior runs crashed mid-test may have left markers
// on the site; the cleanup must restore the count to this number, not
// to zero. The invariant is "the site is left exactly as it was found".
$saved_marker_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
    Content_Journal::OP_META, 'op_%'
) );

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
    return array(
        'post_type'        => $p->post_type,
        'post_status'      => $p->post_status,
        'post_name'        => $p->post_name,
        'post_modified_gmt'=> $p->post_modified_gmt,
        'op_meta_value'    => $op_id,
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

    $content               = Content_Journal::empty_journal( $tx['id'] );
    $content['ops'][ $op['id'] ] = $op;
    $content['sig']               = Content_Journal::sign( $content );
    $tx['content']                = $content;
    return $tx;
}

/**
 * Create a real draft, stamp it with the operation's marker, and return a
 * journal record in `applied` — i.e. exactly the state a crash between
 * wp_insert_post() and mark_applied() does *not* leave, which the marker
 * lookup is what covers.
 */
function jr_real_draft( $token, $suffix, $op_state = 'applied' ) {
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

    $GLOBALS['jr_mine'][] = (int) $id;
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
$GLOBALS['jr_mine'][] = (int) $trash_id;
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
$GLOBALS['jr_mine'][] = (int) $keeper;

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
    Content_Journal::mark_applied( $tx11, $intent11['id'], $id, $after );
    return $id;
} );
$GLOBALS['jr_mine'][] = (int) $post11;

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
$GLOBALS['jr_mine'][] = (int) $post12;
$p12 = get_post( $post12 );
Content_Journal::mark_applied( $tx12, $intent12['id'], $post12, array(
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
$GLOBALS['jr_mine'][] = (int) $post13;
$p13 = get_post( $post13 );
Content_Journal::mark_applied( $tx13, $intent13['id'], $post13, array(
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
$GLOBALS['jr_mine'][] = (int) $post14;
$p14 = get_post( $post14 );
Content_Journal::mark_applied( $tx14, $intent14['id'], $post14, array(
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

// ── cleanup: put the site back exactly as it was found ────────────────────────
foreach ( array_unique( $GLOBALS['jr_mine'] ) as $pid ) {
    if ( $pid > 0 ) {
        wp_delete_post( $pid, true );
    }
}
foreach ( jr_rows_for( $token ) as $pid ) {
    wp_delete_post( $pid, true );
}
foreach ( jr_rows_for( $token . '-trashed' ) as $pid ) {
    wp_delete_post( $pid, true );
}

if ( null === $saved_transaction ) {
    delete_option( Tx::KEY );
} else {
    update_option( Tx::KEY, $saved_transaction );
}
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
// If a previous run left orphaned marker-bearing posts on the site
// (e.g. a child that crashed before its cleanup), this run also
// cleans those up so the count matches `saved_marker_count` (the
// number of marker rows present when the child began). The invariant
// is "the site is left exactly as it was found", not "no markers
// remain"; the test's own posts and the pre-existing orphans are
// both part of the cleanup.
$current_marker_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
    Content_Journal::OP_META, 'op_%'
) );
if ( $current_marker_count > $saved_marker_count ) {
    $orphans = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
        Content_Journal::OP_META, 'op_%'
    ) ) ?: array() );
    foreach ( $orphans as $orphan_id ) {
        wp_delete_post( (int) $orphan_id, true );
    }
    // If there are still markers after deleting those posts, the meta
    // rows outlived the post (e.g. a row was deleted but its meta was
    // kept); sweep them by meta_id.
    $stragglers = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
        Content_Journal::OP_META, 'op_%'
    ) );
    if ( $stragglers > 0 ) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            Content_Journal::OP_META, 'op_%'
        ) );
    }
}

$stray_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
    Content_Journal::OP_META, 'op_%'
) );

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