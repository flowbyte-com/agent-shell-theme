<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_recover_transaction — resolve an expired journalled transaction.
 *
 * ## Why this is a separate tool
 *
 * §4.7 of the content-transaction design says no new tool is proposed beyond
 * `agentshell_delete_content`, on the grounds that a destructive capability
 * should not hide behind a mutating-sounding argument. The same argument points
 * the other way here in a way it did not for the six content tools: resolving
 * an abandoned transaction deletes a WordPress post, and doing that as
 * `agentshell_rollback_transaction(force_recovery=true)` would put it one
 * careless argument away from every existing caller of the rollback tool. It
 * is a distinct capability, for a distinct actor, with a distinct set of
 * guards, so it gets its own name.
 *
 * ## What `confirm` is for
 *
 * Required, and required of the owner too. The owner is the one most likely to
 * reach for this after a crash with no idea what the transaction got as far as
 * doing — precisely the moment a required `confirm` is worth something. It is
 * not a safety theatre check on the wire; it is the tool refusing to do
 * something destructive without being asked to, by name, in this call.
 *
 * ## What `authorize_recovery` is for
 *
 * Only meaningful for somebody who is not the transaction's owner. Owner needs
 * nothing; anyone else needs `manage_options` *and* this flag. See
 * `Content_Recovery::authorize_destruction()`. The owner path on that method
 * is the lenient check (actor match; no per-post cap), because the
 * per-post `delete_post` capability is consulted at the moment of
 * destruction inside `compensate()`, not at the tool entry. A user whose
 * `delete_post` cap on the target post was revoked since the transaction
 * opened can therefore still call this tool — the gate they will hit is
 * the per-op gate, named in the per-op result.
 */
class Recover_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_recover_transaction'; }
    public function get_description() {
        return 'Resolve an expired transaction that holds durable content work: settle each journalled operation — '
            . 'deleting the drafts this transaction created once each has been verified against its journal record — '
            . 'and only then release the transaction slot. Refuses rather than guesses when a post has diverged '
            . 'from what the journal recorded. The destructive step is gated on the per-post `delete_post` '
            . 'capability at the moment of `wp_delete_post`, not at tool entry. This build compensates drafts only; '
            . 'updates, navigation, taxonomy and permanent deletion are refused by name.';
    }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'confirm'            => array(
                    'type'        => 'boolean',
                    'description' => 'Required. Confirms you want to resolve this transaction, which may permanently '
                        . 'delete the drafts it created.',
                ),
                'authorize_recovery' => array(
                    'type'        => 'boolean',
                    'description' => 'Required when resolving a transaction opened by a different actor. Grants the '
                        . 'recovery-administrator role, in addition to the manage_options capability the transport '
                        . 'already enforces.',
                ),
            ),
            'required'   => array( 'confirm' ),
        );
    }

    public function execute( array $arguments ) {
        if ( empty( $arguments['confirm'] ) ) {
            throw new \InvalidArgumentException(
                'agentshell_recover_transaction needs confirm: true. Resolving a transaction can permanently delete '
                . 'the drafts it created, and no other step in the flow should do that by being implied.'
            );
        }

        $tx = \AgentShell_MCP\Transaction_Manager::instance()->payload();

        if ( ! is_array( $tx ) ) {
            // Not a refusal. There is genuinely nothing stored, which is the
            // end state a successful recovery aims for — so repeating recovery
            // is a success, not an error, and it must not read as a failure
            // that invites someone to go looking for the transaction again.
            return array(
                'transaction_id' => '',
                'status'         => \AgentShell_MCP\Content_Recovery::STATUS_NOTHING,
                'reason'         => 'No transaction is stored. It was already recovered, or it never existed. '
                    . 'Nothing was read, changed or deleted.',
                'operations'     => array(),
                'conflicts'      => array(),
                'transaction_row_deleted' => false,
            );
        }

        // ── Tool-boundary authority check ─────────────────────────────────
        // Distinct from the per-op check inside `compensate()`: this is the
        // lenient form (actor match for owner; manage_options + opt-in for
        // recovery-administrator). The per-post `delete_post` capability is
        // checked inside `compensate()` immediately before each
        // `wp_delete_post()`, so a role revocation between now and then
        // still refuses the destructive step — just at the per-op gate,
        // not here.
        $actor                 = \AgentShell_MCP\Store::actor();
        $explicitly_authorized = ! empty( $arguments['authorize_recovery'] );
        $role                  = \AgentShell_MCP\Content_Recovery::authorize_destruction(
            $tx,
            $actor,
            $explicitly_authorized,
            null
        );

        if ( '' === $role ) {
            // Same refusal shape `Content_Recovery::recover()` produces when
            // its own `self::authorize()` check fires, so a caller cannot
            // distinguish "refused at the tool entry" from "refused inside
            // recover()". The plan's symmetry rule for inspection is matched
            // here for destruction: the existence of another actor's row is
            // not disclosed beyond what the message already names.
            $tx_id    = isset( $tx['id'] ) ? (string) $tx['id'] : '';
            $tx_owner = isset( $tx['actor'] ) ? (string) $tx['actor'] : '';
            return array(
                'transaction_id'          => $tx_id,
                'transaction_owner'       => $tx_owner,
                'actor'                   => $actor,
                'authorized_as'           => '',
                'actor_capable'           => false,
                'status'                  => \AgentShell_MCP\Content_Recovery::STATUS_REFUSED,
                'reason'                  => 'Transaction ' . $tx_id . ' belongs to '
                    . ( '' === $tx_owner ? '(nobody recorded)' : $tx_owner )
                    . ' and you are ' . $actor . '. Recovery of another actor\'s transaction requires '
                    . 'manage_options and an explicit authorisation.',
                'operations'              => array(),
                'deleted_transactions'    => array(),
                'conflicts'               => array(),
                'transaction_row_deleted' => false,
                'integrity'               => 'unverified',
                'integrity_note'          => '',
                'supported_operations'    => \AgentShell_MCP\Content_Journal::SUPPORTED_OPERATIONS,
            );
        }

        return \AgentShell_MCP\Content_Recovery::recover(
            $tx,
            $actor,
            $explicitly_authorized
        );
    }
}