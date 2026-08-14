<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_validate — deterministic site doctor.
 *
 * Returns machine-readable diagnostics with stable codes. Errors block
 * transaction commits; warnings are advisory.
 */
class Validate extends Base_Tool {
    public function get_name() { return 'agentshell_validate'; }
    public function get_description() { return 'Run deterministic validation over the site configuration: malformed config, unknown zones/widgets/core components, missing widget dependencies, unsafe json_block content, duplicate ids, auth configuration, and PHP/WordPress compatibility. Returns { errors, warnings } with stable codes.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'config' => array(
                    'type'        => 'object',
                    'description' => 'Optional config to validate instead of the stored one (e.g. staged changes).',
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $config = isset( $arguments['config'] ) && is_array( $arguments['config'] )
            ? $arguments['config']
            : null;

        $issues = \AgentShell_MCP\Doctor::check( $config );

        return array(
            'status'     => empty( $issues['errors'] ) ? 'ok' : 'issues',
            'errors'     => $issues['errors'],
            'warnings'   => $issues['warnings'],
            'checks_run' => count( $issues['errors'] ) + count( $issues['warnings'] ),
            'tip'        => 'Errors must be fixed before a transaction can commit.',
        );
    }
}