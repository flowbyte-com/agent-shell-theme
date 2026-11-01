<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Get_Config extends Base_Tool {
    public function get_name() { return 'agentshell_get_config'; }
    public function get_description() { return 'Get the AgentShell configuration including zones, design, layout, widgets, and CSS variables. The custom CSS body is omitted by default — pass include_custom_css=true to receive it.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'include_custom_css' => array(
                    'type'        => 'boolean',
                    'description' => 'Include the full custom CSS body in the response. Off by default: a stylesheet can be a quarter of a megabyte, and it is almost never what a reader of the configuration wants.',
                    'default'     => false,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        if ( function_exists( 'agentshell_get_widget_registry' ) ) {
            // Built from $config — the effective one — not re-read from the
            // committed option. Otherwise the two halves of this single response
            // describe different transactions.
            $config['widgets'] = array_values( agentshell_get_widget_registry( $config ) );
        }
        if ( function_exists( 'agentshell_flatten_config' ) ) {
            $config['tokens'] = agentshell_flatten_config( $config );
        }

        // The custom CSS body is not part of a routine configuration read.
        //
        // The limit on custom CSS is 256 KiB, and a configuration read
        // that carries the whole stylesheet is a quarter of a megabyte of
        // text in every response — in a transcript, in a log, in an agent's
        // context window — for a field almost no reader looks at. What a
        // reader does want is the *fact* of the stylesheet: how big it is,
        // whether one is set at all.
        //
        // So the body is omitted by default and the size is reported
        // instead. A caller that genuinely needs the stylesheet asks for
        // it by name.
        $include_custom_css = ! empty( $arguments['include_custom_css'] );
        $custom_css         = $config['custom_css'] ?? null;
        $custom_css_bytes   = is_string( $custom_css ) ? strlen( $custom_css ) : 0;

        if ( ! $include_custom_css ) {
            unset( $config['custom_css'] );
        }

        $config['custom_css_bytes']   = $custom_css_bytes;
        $config['custom_css_present'] = is_string( $custom_css ) && '' !== $custom_css;
        if ( $include_custom_css ) {
            // The body is present, so the summary fields describe it.
            $config['custom_css_bytes'] = $custom_css_bytes;
        }

        return $config;
    }
}
