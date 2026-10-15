<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_journal — read-only inspection of a transaction's content journal.
 *
 * ## Why a tool rather than a field on get_transaction
 *
 * A journal is not a summary; it is a list of objects that may be destroyed,
 * each with the evidence recovery would use before destroying it. Folding that
 * into `agentshell_get_transaction` would put it in front of every caller who
 * wants to know whether a transaction is open, and the answer they would get
 * would depend on them not reading carefully.
 *
 * ## Read-only, and read-only in a way that means something
 *
 * This tool never writes the journal itself. It does not settle the lease, so
 * it cannot reclaim an expired transaction; it does not renew, so it cannot
 * keep somebody else's work alive; it does not touch a post. That is the
 * security property the design needs: being able to *look* at a problem must
 * never be a way to change it. `Content_Journal::inspect()` takes a row and
 * returns a description, so there is no code path from this tool to a journal
 * write at all.
 *
 * The tool does append one row to the audit log on a *successful* inspection
 * (a write the journal does not make, by design), and zero rows on a refused
 * inspection — so an unauthorised read is indistinguishable from no read.
 *
 * ## Authority
 *
 * Two distinct authorities, two distinct refusals, and the same response
 * shape for both refusals so a non-owner cannot tell from the response
 * whether another actor's row exists:
 *
 *   - **owner** — the transaction's `actor` *and* a current `manage_options`
 *     capability. The capability re-checks `brief B`: an actor whose role
 *     was revoked since the transaction opened can no longer read the row.
 *   - **recovery_administrator** — `manage_options` *and* `authorize_inspection:
 *     true`. The opt-in makes the read deliberate rather than a side effect
 *     of a session that happened to hold the right cookie.
 *
 * Anyone else gets the same shape an absent transaction gets: the existence
 * of another actor's row is not disclosed.
 */
class Get_Journal extends Base_Tool {
    public function get_name() { return 'agentshell_get_journal'; }
    public function get_description() {
        return 'Inspect a transaction\'s durable content journal without changing anything: which operations it '
            . 'recorded, which WordPress post each one concerns, what state each is in, whether the journal is '
            . 'intact, and whether it can be recovered. Read-only with respect to the journal itself; an '
            . 'authorised inspection appends one row to the audit log. An unauthorised inspection returns the '
            . 'same shape an absent transaction does. Owners and administrators (with `authorize_inspection: true`) '
            . 'can read; nobody else can.';
    }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'authorize_inspection' => array(
                    'type'        => 'boolean',
                    'description' => 'Required when reading a transaction you did not open. Confirms you want to '
                        . 'read another actor\'s journal. The owner path does not need this; the recovery-administrator '
                        . 'path (manage_options + this flag) is what makes the read deliberate.',
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $explicitly_authorized = ! empty( $arguments['authorize_inspection'] );
        $actor                 = \AgentShell_MCP\Store::actor();
        $tx                    = \AgentShell_MCP\Transaction_Manager::instance()->payload();

        $role = \AgentShell_MCP\Content_Journal::authorize_inspection(
            $tx,
            $actor,
            $explicitly_authorized
        );

        if ( '' === $role ) {
            // Refused: indistinguishable from a missing row. No audit row,
            // because an unauthorised read must leave the audit log
            // untouched — a refused inspection is not a record of intent.
            return \AgentShell_MCP\Content_Journal::inspect( null, false );
        }

        $view = \AgentShell_MCP\Content_Journal::inspect( is_array( $tx ) ? $tx : null, true );

        // One audit row per successful inspection. The actor is who read;
        // the changed payload records the role, so a peer reading under
        // their own ownership is distinguishable from a recovery administrator
        // doing it on someone else's behalf.
        \AgentShell_MCP\Store::record_event( $actor, 'transaction_journal_inspected', array(
            'transaction_id'  => isset( $tx['id'] ) ? (string) $tx['id'] : '',
            'transaction_owner' => isset( $tx['actor'] ) ? (string) $tx['actor'] : '',
            'authorized_as'   => $role,
            'operation_count' => (int) ( $view['operation_count'] ?? 0 ),
            'recoverable'     => (bool) ( $view['recoverable'] ?? false ),
        ) );

        return $view;
    }
}