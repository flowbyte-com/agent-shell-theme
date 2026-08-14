<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_import_theme — apply an exported theme package.
 *
 * Accepts the manifest object (from agentshell_export_theme) or a raw JSON
 * string. The imported config is validated before it is applied, and the
 * import is recorded as a revision and audit entry.
 */
class Import_Theme extends Base_Tool {
    public function get_name() { return 'agentshell_import_theme'; }
    public function get_description() { return 'Import a theme package (from agentshell_export_theme) and apply it. Accepts the manifest object or a raw JSON string. Validated before applying; recorded as a revision.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'manifest' => array( 'type' => 'object', 'description' => 'Exported manifest object { package, version, config }' ),
                'json'     => array( 'type' => 'string', 'description' => 'Raw JSON manifest string (alternative to manifest)' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! empty( $arguments['manifest'] ) && is_array( $arguments['manifest'] ) ) {
            $manifest = $arguments['manifest'];
        } elseif ( ! empty( $arguments['json'] ) && is_string( $arguments['json'] ) ) {
            $manifest = json_decode( $arguments['json'], true );
            if ( ! is_array( $manifest ) ) {
                throw new \InvalidArgumentException( 'json is not valid JSON.' );
            }
        } else {
            throw new \InvalidArgumentException( 'Provide either manifest or json.' );
        }

        $config = $manifest['config'] ?? null;
        if ( ! is_array( $config ) ) {
            throw new \InvalidArgumentException( 'Manifest is missing a config object. Expected { package: "agentshell-theme", config: {...} }.' );
        }

        // Hard validation gate before touching the live site.
        $issues = \AgentShell_MCP\Doctor::check( $config );
        if ( ! empty( $issues['errors'] ) ) {
            throw new \InvalidArgumentException( 'Import rejected — config has validation errors: ' . wp_json_encode( $issues['errors'] ) );
        }

        $before = $this->get_agentshell_config();
        $label  = 'import theme'
            . ( ! empty( $manifest['package'] ) ? ' (' . $manifest['package'] . ' v' . ( $manifest['version'] ?? '?' ) . ')' : '' )
            . ( ! empty( $manifest['site'] ) ? ' from ' . $manifest['site'] : '' );

        \AgentShell_MCP\Store::$operation_label = $label;
        $this->update_agentshell_config( $config );

        return array(
            'imported' => true,
            'label'    => $label,
            'diff'     => \AgentShell_MCP\Store::diff_config( $before, $config ),
            'warnings' => $issues['warnings'],
        );
    }
}