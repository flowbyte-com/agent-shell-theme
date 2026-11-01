<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_diff_revisions — token-level diff between two revisions.
 */
class Diff_Revisions extends Base_Tool {
    public function get_name() { return 'agentshell_diff_revisions'; }
    public function get_description() { return 'Compare two configuration revisions at the CSS-token level. Returns added/removed/changed tokens between the two states.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'from' => array( 'type' => 'string', 'description' => 'Revision id, e.g. r12' ),
                'to'   => array( 'type' => 'string', 'description' => 'Revision id, e.g. r15. Omit to diff from-revision against the current live config.' ),
            ),
            'required' => array( 'from' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'from' ) );
        $from = $arguments['from'];
        $to   = $arguments['to'] ?? null;

        $items = \AgentShell_MCP\Store::revisions()['items'] ?? array();
        $from_config = null;
        foreach ( $items as $item ) {
            if ( $item['id'] === $from ) {
                $from_config = $item['config'];
                break;
            }
        }
        if ( null === $from_config ) {
            throw new \InvalidArgumentException( "Revision not found: {$from}. Use agentshell_list_revisions." );
        }

        if ( null === $to ) {
            $to_config = $this->get_agentshell_config();
            $to_label  = 'current live config';
        } else {
            $to_config = null;
            foreach ( $items as $item ) {
                if ( $item['id'] === $to ) {
                    $to_config = $item['config'];
                    break;
                }
            }
            if ( null === $to_config ) {
                throw new \InvalidArgumentException( "Revision not found: {$to}. Use agentshell_list_revisions." );
            }
            $to_label = $to;
        }

        return array(
            'from' => $from,
            'to'   => $to ?? 'current',
            'diff' => \AgentShell_MCP\Store::diff_config( $from_config, $to_config ),
        );
    }
}