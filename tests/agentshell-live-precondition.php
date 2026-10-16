<?php
/**
 * Destructive live suite precondition — close any stale `live *`
 * transaction before the child runs.
 *
 * **The defect.** Stage 1b added a "refuses inside a transaction"
 * guard to every content and navigation tool (create_page,
 * create_post, update_content, update_post_content, publish,
 * unpublish, register_navigation, delete_*). The guard fires when
 * `Transaction_Manager::is_active()` returns true, which is
 * whenever an unexpired transaction row is in `wp_options`. The
 * content-write-live and navigation-live child tests were not
 * updated to handle a pre-existing transaction on the site; in
 * the presence of one, every tool call refuses and the suite
 * reports 16 failures (1 in content-write-live, 15 in
 * navigation-live).
 *
 * **The precondition.** Before any destructive live child runs,
 * check the transaction state:
 *
 *   - **No transaction.** Return `{ ok: true, action: 'none' }`.
 *     The child proceeds.
 *
 *   - **`live *` label with no applied work.** The transaction
 *     is a test artifact from a prior crashed run. Its `ops`
 *     are all `intended` (not `applied`), so no real work needs
 *     to be rolled back. Delete the option row directly and
 *     return `{ ok: true, action: 'closed_test_artifact',
 *     tx_id, tx_label }`. The child proceeds against a clean
 *     slot.
 *
 *   - **`live *` label with applied work.** A prior run
 *     committed or applied work but crashed before closing. The
 *     work is in the database; closing the option row directly
 *     would orphan it. Refuse with `{ ok: false, action:
 *     'refused_orphan_work', tx_id, tx_label, applied_op_count
 *     }`. The operator must run `agentshell_recover_transaction`
 *     or roll the prior work back manually.
 *
 *   - **Non-`live *` label.** A real user transaction is open.
 *     Closing it via this path would be a destructive
 *     cross-actor operation. Refuse with `{ ok: false, action:
 *     'refused_non_test', tx_id, tx_label, tx_actor }`. The
 *     operator must close the real transaction first.
 *
 * **Why not call `agentshell_rollback_transaction` directly.**
 * The transaction's owner is the prior test's actor, and the
 * current child is not authenticated as that user (the child
 * runs via `wp-load.php` with no user). The rollback's
 * `assert_owner` would refuse. Direct option delete is the
 * safe path for the test-artifact case (no applied work) and
 * the only path that is consistent with what the test
 * infrastructure is allowed to do autonomously.
 *
 * **The 16 failures root cause.** The accepted Stage 2b
 * checkpoint (commit 15d837f) reported 712/712 passing. The
 * current report shows 16 failures. Investigation: a `live 2a
 * single edit commit` transaction row on the test site from a
 * prior run is in `agentshell_transaction` with `expires` set
 * to a future time. The transaction is `live *` (a test
 * artifact) and has one `applied` op. The two test children
 * (content-write-live, navigation-live) were written before
 * the Stage 1b guard and do not handle this case. They
 * therefore fire 15 + 1 = 16 refusals.
 *
 * The fix is the precondition. The child calls
 * `agentshell_live_precondition()` at the start; the function
 * returns one of four statuses. The child reports the status
 * and proceeds accordingly.
 *
 * The non-test path (refused_non_test) is a real safety
 * gate: the test infrastructure will never silently destroy
 * a user's open transaction.
 *
 * @return array { ok: bool, action: string, tx_id?: string,
 *                 tx_label?: string, tx_actor?: string,
 *                 applied_op_count?: int }
 */
function agentshell_live_precondition() {
    if ( ! defined( 'ABSPATH' ) || ! function_exists( 'get_option' ) ) {
        return array( 'ok' => false, 'action' => 'no_wordpress' );
    }
    $tx = get_option( 'agentshell_transaction', null );
    if ( ! is_array( $tx ) ) {
        return array( 'ok' => true, 'action' => 'none' );
    }
    $tx_id    = (string) ( $tx['id'] ?? '' );
    $tx_label = (string) ( $tx['label'] ?? '' );
    $tx_actor = (string) ( $tx['actor'] ?? '' );

    // Real (non-test) transaction — refuse unconditionally. Closing
    // it autonomously would be a cross-actor destructive operation.
    if ( 0 !== strpos( $tx_label, 'live ' ) ) {
        return array(
            'ok'       => false,
            'action'   => 'refused_non_test',
            'tx_id'    => $tx_id,
            'tx_label' => $tx_label,
            'tx_actor' => $tx_actor,
        );
    }

    // Test artifact: check for applied work. If any op is `applied`,
    // the prior run wrote real posts to the database. Direct option
    // delete would orphan them. Refuse; the operator must run
    // recovery.
    $applied = 0;
    $content = isset( $tx['content'] ) && is_array( $tx['content'] ) ? $tx['content'] : array();
    $ops     = isset( $content['ops'] ) && is_array( $content['ops'] ) ? $content['ops'] : array();
    foreach ( $ops as $op ) {
        if ( is_array( $op ) && ( $op['state'] ?? '' ) === 'applied' ) {
            $applied++;
        }
    }
    if ( $applied > 0 ) {
        return array(
            'ok'                => false,
            'action'            => 'refused_orphan_work',
            'tx_id'             => $tx_id,
            'tx_label'          => $tx_label,
            'tx_actor'          => $tx_actor,
            'applied_op_count'  => $applied,
        );
    }

    // Test artifact with no applied work — safe to close directly.
    // The work in the transaction is `intended` only, meaning
    // `create_draft` recorded the intent but `mark_applied` never
    // ran; no real post was written. Closing the slot is correct.
    // The wipe is value-guarded: a transaction whose row moved
    // (e.g. the test config points at a different site, or a
    // concurrent runner somehow wrote through) is not touched.
    global $wpdb;
    $current_serialized = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'agentshell_transaction'
    ) );
    if ( null === $current_serialized ) {
        // Row was already gone between our get_option and the
        // re-read. The site is clean; no action needed.
        return array( 'ok' => true, 'action' => 'none' );
    }
    $deleted = (int) $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        'agentshell_transaction',
        (string) $current_serialized
    ) );
    if ( 0 === $deleted ) {
        // Row moved between the re-read and the delete. Treat
        // as already closed; the operator can investigate.
        return array(
            'ok'      => true,
            'action'  => 'already_closed',
            'tx_id'   => $tx_id,
            'tx_label'=> $tx_label,
        );
    }
    wp_cache_delete( 'agentshell_transaction', 'options' );
    return array(
        'ok'      => true,
        'action'  => 'closed_test_artifact',
        'tx_id'   => $tx_id,
        'tx_label'=> $tx_label,
    );
}
