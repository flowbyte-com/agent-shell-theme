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
 * Content_Recovery::authorize().
 */
class Recover_Transaction extends Base_Tool {
    public function get_name() { return 'agentshell_recover_transaction'; }
    public function get_description() {
        return 'Resolve an expired transaction that holds durable content work: settle each journalled operation — '
            . 'deleting the drafts this transaction created once each has been verified against its journal record — '
            . 'and only then release the transaction slot. Refuses rather than guesses when a post has diverged '
            . 'from what the journal recorded. This build compensates drafts only; updates, navigation, taxonomy '
            . 'and permanent deletion are refused by name.';
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

        return \AgentShell_MCP\Content_Recovery::recover(
            $tx,
            null,
            ! empty( $arguments['authorize_recovery'] )
        );
    }
}