<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Set_Css_Var extends Base_Tool {
    public function get_name() { return 'agentshell_set_css_var'; }
    public function get_description() { return 'Set a single CSS custom property (variable) on the theme.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name'  => array( 'type' => 'string', 'description' => 'CSS variable name (must start with --)', 'pattern' => '^--' ),
                'value' => array( 'type' => 'string', 'description' => 'CSS value', 'maxLength' => 500 ),
            ),
            'required'   => array( 'name', 'value' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'name', 'value' ) );

        $name = trim( $arguments['name'] );
        if ( ! str_starts_with( $name, '--' ) ) {
            throw new \InvalidArgumentException( 'CSS variable name must start with --' );
        }
        if ( strlen( $arguments['value'] ) > 500 ) {
            throw new \InvalidArgumentException( 'CSS value must be 500 characters or less' );
        }

        $config = $this->get_agentshell_config();
        if ( ! isset( $config['design'] ) ) { $config['design'] = array(); }
        if ( ! isset( $config['design']['custom_css_vars'] ) ) { $config['design']['custom_css_vars'] = array(); }
        $config['design']['custom_css_vars'][ $name ] = $arguments['value'];
        $this->update_agentshell_config( $config );

        return array( 'name' => $name, 'value' => $arguments['value'] );
    }
}
