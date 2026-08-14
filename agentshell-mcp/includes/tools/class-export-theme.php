<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_export_theme — export the site configuration as a theme package.
 *
 * The package is a portable JSON manifest (design, zones, layout, widgets)
 * that can be imported on another AgentShell installation.
 */
class Export_Theme extends Base_Tool {
    public function get_name() { return 'agentshell_export_theme'; }
    public function get_description() { return 'Export the full AgentShell configuration as a portable theme package (JSON manifest): design, zones, layout, widgets. Import on another installation with agentshell_import_theme.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'snapshot' => array( 'type' => 'string', 'description' => 'Optional snapshot name to export instead of the live config' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! empty( $arguments['snapshot'] ) ) {
            $snapshot = \AgentShell_MCP\Store::get_snapshot( sanitize_key( $arguments['snapshot'] ) );
            if ( null === $snapshot ) {
                throw new \InvalidArgumentException( "Snapshot not found: {$arguments['snapshot']}. Use agentshell_list_snapshots." );
            }
            $config = $snapshot['config'];
        } else {
            $config = $this->get_agentshell_config();
        }

        $manifest = array(
            'package'     => 'agentshell-theme',
            'version'     => 1,
            'site'        => get_bloginfo( 'name' ),
            'exported_by' => \AgentShell_MCP\Store::actor(),
            'exported_at' => time(),
            'config'      => $config,
        );

        return array(
            'manifest'  => $manifest,
            'json'      => wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            'tip'       => 'Save the json field, then import on another installation with agentshell_import_theme.',
        );
    }
}