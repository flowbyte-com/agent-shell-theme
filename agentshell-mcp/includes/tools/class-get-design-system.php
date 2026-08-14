<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_design_system — structured view of all design tokens.
 */
class Get_Design_System extends Base_Tool {
    public function get_name() { return 'agentshell_get_design_system'; }
    public function get_description() { return 'Return the design system in structured groups (palette, typography, geometry, custom vars) plus the flat token map. Prefer this over raw CSS variables when reasoning about design.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        $design = $config['design'] ?? array();

        return array(
            'palette'    => $design['colors'] ?? array(),
            'typography' => $design['typography'] ?? array(),
            'geometry'   => $design['layout'] ?? array(),
            'custom_vars' => $design['custom_css_vars'] ?? array(),
            'tokens'     => \AgentShell_MCP\Store::flatten( $config ),
            'valid_keys' => array(
                'colors'     => \AgentShell_MCP\Doctor::ALLOWED_COLORS,
                'typography' => \AgentShell_MCP\Doctor::ALLOWED_TYPO,
                'geometry'   => \AgentShell_MCP\Doctor::ALLOWED_LAYOUT,
            ),
            'semantic_tools' => array(
                'agentshell_set_palette'     => 'Set colors by key (background, surface, text, border, accent, primary, secondary)',
                'agentshell_set_typography'  => 'Set fontFamily, mono, baseSize, scale',
                'agentshell_set_spacing'     => 'Set the base spacing unit',
                'agentshell_set_shape'       => 'Set radius, borderWidth, borderStyle',
                'agentshell_apply_theme'     => 'Apply a named theme profile',
            ),
        );
    }
}