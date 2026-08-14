<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_restore_revision — roll the live config back to a revision.
 */
class Restore_Revision extends Base_Tool {
    public function get_name() { return 'agentshell_restore_revision'; }
    public function get_description() { return 'Restore the live configuration to a previous revision. The restore itself is recorded as a new revision and audit entry, so it is always reversible.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'    => array( 'type' => 'string', 'description' => 'Revision id to restore, e.g. r12' ),
                'label' => array( 'type' => 'string', 'description' => 'Optional note recorded with the restore' ),
            ),
            'required' => array( 'id' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id' ) );

        if ( \AgentShell_MCP\Transaction_Manager::instance()->is_active() ) {
            throw new \InvalidArgumentException( 'A transaction is open. Commit or roll it back before restoring a revision.' );
        }

        $items = \AgentShell_MCP\Store::revisions()['items'] ?? array();
        $target = null;
        foreach ( $items as $item ) {
            if ( $item['id'] === $arguments['id'] ) {
                $target = $item;
                break;
            }
        }
        if ( null === $target ) {
            throw new \InvalidArgumentException( "Revision not found: {$arguments['id']}. Use agentshell_list_revisions." );
        }

        $current = $this->get_agentshell_config();
        \AgentShell_MCP\Store::$operation_label = $arguments['label']
            ? 'restore revision ' . $target['id'] . ': ' . $arguments['label']
            : 'restore revision ' . $target['id'];
        $this->update_agentshell_config( $target['config'] );

        return array(
            'restored'  => $target['id'],
            'label'     => $target['label'],
            'diff'      => \AgentShell_MCP\Store::diff_config( $current, $target['config'] ),
            'new_revision' => \AgentShell_MCP\Store::revisions()['items'][0]['id'] ?? null,
        );
    }
}