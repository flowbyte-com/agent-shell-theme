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

$ACTOR = 'live-recovery-probe';

/** Exactly what was there before this child ran, so it can put it back. */
$saved_transaction = get_option( Tx::KEY, null );
$saved_audit       = get_option( Store::AUDIT_KEY, null );

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
list( $id6, $op6 ) = jr_real_draft( $token, '-unauthorised' );
$tx6 = jr_expired_row( $op6 );
update_option( Tx::KEY, $tx6 );
$refused = Content_Recovery::recover( $tx6, 'somebody-else', true );
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

$f['cleanup'] = array(
    'rows_remaining'       => count( jr_rows_for( $token ) ),
    'trashed_remaining'    => count( jr_rows_for( $token . '-trashed' ) ),
    'stray_marker_meta'    => (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
        Content_Journal::OP_META, 'op_%'
    ) ),
    'transaction_restored' => ( get_option( Tx::KEY, null ) === $saved_transaction ),
    'audit_restored'       => ( get_option( Store::AUDIT_KEY, null ) === $saved_audit ),
);

file_put_contents( $report_path, wp_json_encode( $f ) );
echo "reported\n";