<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_inspect — full machine-readable site model.
 *
 * Gives an agent a structural understanding of the site before touching it:
 * shell, design, zones, widgets, content, capabilities, state, warnings.
 */
class Inspect extends Base_Tool {
    public function get_name() { return 'agentshell_inspect'; }
    public function get_description() { return 'Return a complete machine-readable model of the AgentShell site: shell, design, zones, widgets, content, capabilities, state, and warnings. Call this first when working on an unfamiliar site.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $config = $this->get_agentshell_config();

        return array(
            'site' => array(
                'title'        => get_bloginfo( 'name' ),
                'description'  => get_bloginfo( 'description' ),
                'url'          => home_url( '/' ),
                'wp_version'   => get_bloginfo( 'version' ),
                'php_version'  => PHP_VERSION,
            ),
            'shell' => array(
                'immutable'  => true,
                'theme'      => function_exists( 'wp_get_theme' ) ? wp_get_theme()->get( 'Name' ) : '',
                'version'    => function_exists( 'wp_get_theme' ) ? wp_get_theme()->get( 'Version' ) : '',
                'zones'      => \AgentShell_MCP\Doctor::ALLOWED_ZONES,
                'layout'     => $config['layout'] ?? array(),
                'editable'   => 'Design tokens, zones, widgets, custom CSS/JS. The shell grid itself is immutable.',
            ),
            'design' => $this->design_view( $config ),
            'zones'  => $this->zones_view( $config ),
            'widgets' => $this->widgets_view(),
            'content' => array(
                'posts' => (int) wp_count_posts( 'post' )->publish ?? 0,
                'pages' => (int) wp_count_posts( 'page' )->publish ?? 0,
            ),
            'capabilities' => $this->capabilities(),
            'state' => array(
                'transaction'     => \AgentShell_MCP\Transaction_Manager::instance()->is_active()
                    ? \AgentShell_MCP\Transaction_Manager::instance()->payload()
                    : null,
                'revision_count'  => count( \AgentShell_MCP\Store::revisions()['items'] ?? array() ),
                'latest_revision' => ( \AgentShell_MCP\Store::revisions()['items'][0]['id'] ?? null ),
                'snapshot_count'  => count( \AgentShell_MCP\Store::snapshots() ),
                'profile_count'   => count( \AgentShell_MCP\Store::profiles() ),
            ),
            'warnings' => \AgentShell_MCP\Doctor::check( $config )['warnings'],
        );
    }

    private function design_view( $config ) {
        $design = $config['design'] ?? array();
        return array(
            'colors'      => $design['colors'] ?? array(),
            'typography'  => $design['typography'] ?? array(),
            'geometry'    => $design['layout'] ?? array(),
            'custom_vars' => $design['custom_css_vars'] ?? array(),
            'tokens'      => \AgentShell_MCP\Store::flatten( $config ),
        );
    }

    private function zones_view( $config ) {
        $view = array();
        foreach ( ( $config['zones'] ?? array() ) as $zone ) {
            $entry = array( 'id' => $zone['id'] ?? '', 'label' => $zone['label'] ?? ( $zone['id'] ?? '' ) );
            if ( isset( $zone['slots'] ) ) {
                $entry['slots'] = $zone['slots'];
            } else {
                $entry['composition'] = $zone['composition'] ?? array();
            }
            $view[] = $entry;
        }
        return $view;
    }

    private function widgets_view() {
        if ( ! function_exists( 'agentshell_get_widget_registry' ) ) {
            return array();
        }
        $out = array();
        foreach ( agentshell_get_widget_registry() as $id => $widget ) {
            $out[] = array(
                'id'     => $id,
                'name'   => $widget['name'] ?? $id,
                'status' => $widget['status'] ?? 'active',
                'libs'   => $widget['libs'] ?? array(),
                'source' => ( $widget['source'] ?? 'config' ) === 'file' ? 'file' : 'config',
            );
        }
        return $out;
    }

    private function capabilities() {
        return array(
            'design'       => true,
            'zones'        => true,
            'widgets'      => true,
            'content'      => true,
            'transactions' => true,
            'revisions'    => true,
            'snapshots'    => true,
            'profiles'     => true,
            'preview'      => true,
            'screenshot'   => false,
            'audit'        => true,
        );
    }
}