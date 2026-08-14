<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_explain — human-readable description of the live site.
 *
 * Effectively AGENTS.md generated from live state: what the shell is, what
 * each zone contains, which widgets exist, and how the site is designed.
 */
class Explain extends Base_Tool {
    public function get_name() { return 'agentshell_explain'; }
    public function get_description() { return 'Return a human-readable plain-text description of the site: shell model, zone composition, widgets, and design — generated from live state.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'verbose' => array( 'type' => 'boolean', 'description' => 'Include token-level design details (default false)' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();
        $verbose = ! empty( $arguments['verbose'] );

        $lines = array();
        $lines[] = 'The site uses the AgentShell immutable shell (' . get_bloginfo( 'name' ) . ').';
        $lines[] = '';

        // Zones
        $zones = $config['zones'] ?? array();
        if ( ! empty( $zones ) ) {
            $lines[] = 'Zones:';
            foreach ( $zones as $zone ) {
                $id   = $zone['id'] ?? '';
                $desc = array();
                if ( isset( $zone['slots'] ) ) {
                    foreach ( array( 'left', 'center', 'right' ) as $slot ) {
                        $blocks = $zone['slots'][ $slot ] ?? array();
                        if ( ! empty( $blocks ) ) {
                            $desc[] = $slot . ': ' . $this->describe_blocks( $blocks );
                        }
                    }
                } elseif ( ! empty( $zone['composition'] ) ) {
                    $desc[] = $this->describe_blocks( (array) $zone['composition'] );
                }
                $lines[] = '  ' . $id . ( ! empty( $desc ) ? ': ' . implode( '; ', $desc ) : ': empty' );
            }
        } else {
            $lines[] = 'Zones: none configured (theme defaults apply).';
        }
        $lines[] = '';

        // Widgets
        if ( function_exists( 'agentshell_get_widget_registry' ) ) {
            $widgets = agentshell_get_widget_registry();
            if ( ! empty( $widgets ) ) {
                $lines[] = 'Registered widgets:';
                foreach ( $widgets as $id => $widget ) {
                    $status = $widget['status'] ?? 'active';
                    $lines[] = '  - ' . $id . ' (' . ( $widget['name'] ?? $id ) . ')'
                        . ( 'disabled' === $status ? ' [disabled]' : '' )
                        . ( ! empty( $widget['libs'] ) ? ' libs: ' . implode( ', ', $widget['libs'] ) : '' );
                }
            } else {
                $lines[] = 'Registered widgets: none.';
            }
        }
        $lines[] = '';

        // Design
        $design = $config['design'] ?? array();
        $lines[] = 'Design:';
        $colors = $design['colors'] ?? array();
        if ( ! empty( $colors ) ) {
            $lines[] = '  - palette: ' . implode( ', ', array_map(
                function( $v, $k ) { return $k . ' ' . $v; },
                $colors,
                array_keys( $colors )
            ) );
        }
        $typo = $design['typography'] ?? array();
        if ( ! empty( $typo['fontFamily'] ) ) {
            $lines[] = '  - font: ' . $typo['fontFamily'];
        }
        if ( ! empty( $typo['baseSize'] ) ) {
            $lines[] = '  - base size: ' . $typo['baseSize'];
        }
        if ( ! empty( $design['layout']['radius'] ) ) {
            $lines[] = '  - radius: ' . $design['layout']['radius'];
        }
        if ( ! empty( $design['custom_css_vars'] ) ) {
            $lines[] = '  - custom vars: ' . count( $design['custom_css_vars'] ) . ' (see agentshell_get_design_system)';
        }
        if ( $verbose && ! empty( $colors ) ) {
            $lines[] = '  Tokens:';
            foreach ( \AgentShell_MCP\Store::flatten( $config ) as $key => $value ) {
                $lines[] = '    ' . $key . ': ' . $value;
            }
        }
        $lines[] = '';

        // State
        $lines[] = 'State:';
        $tx = \AgentShell_MCP\Transaction_Manager::instance();
        $lines[] = '  - transaction: ' . ( $tx->is_active() ? $tx->payload()['id'] . ' (open)' : 'none open' );
        $lines[] = '  - revisions: ' . count( \AgentShell_MCP\Store::revisions()['items'] ?? array() );
        $lines[] = '  - snapshots: ' . count( \AgentShell_MCP\Store::snapshots() );
        $lines[] = '  - theme profiles: ' . count( \AgentShell_MCP\Store::profiles() );

        return implode( "\n", $lines );
    }

    private function describe_blocks( array $blocks ) {
        $out = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }
            switch ( $block['type'] ?? '' ) {
                case 'wp_loop':
                    $out[] = 'WordPress loop';
                    break;
                case 'wp_core':
                    $out[] = ( $block['id'] ?? 'core component' );
                    break;
                case 'widget':
                    $out[] = 'widget ' . ( $block['id'] ?? '' );
                    break;
                case 'json_block':
                    $out[] = 'HTML block';
                    break;
                case 'wp_widget_area':
                    $out[] = 'widget area ' . ( $block['id'] ?? '' );
                    break;
                default:
                    $out[] = ( $block['type'] ?? 'unknown' );
            }
        }
        return implode( ', ', $out );
    }
}