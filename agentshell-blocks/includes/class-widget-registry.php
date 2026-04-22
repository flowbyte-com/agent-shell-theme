<?php
namespace AgentShell_Blocks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Central widget registry — manages agent-defined widgets in wp_options.
 *
 * Theme queries this via do_action('agentshell_render_widget', $widget_id).
 * Plugin hooks in and renders via Widget_Registry::render().
 */
class Widget_Registry {
    private static $instance = null;

    /** @var array Cached registry */
    private $cache = null;

    /** @var int Cache-busting version, incremented on every write */
    private $version = null;

    const VERSION_OPTION = 'agentshell_widgets_version';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get all registered widgets from wp_options.
     *
     * @return array
     */
    public function get_widgets() {
        if ( null === $this->cache ) {
            $this->cache = get_option( AGENTSHELL_WIDGETS_OPTION, array() );
        }
        return is_array( $this->cache ) ? $this->cache : array();
    }

    /**
     * Get current registry version for cache busting.
     *
     * @return int
     */
    public function get_version() {
        if ( null === $this->version ) {
            $this->version = (int) get_option( self::VERSION_OPTION, 1 );
        }
        return $this->version;
    }

    /**
     * Increment version to bust cached renders.
     */
    private function bump_version() {
        $this->version = $this->get_version() + 1;
        update_option( self::VERSION_OPTION, $this->version, false );
    }

    /**
     * Get a single widget by ID.
     *
     * @param string $id
     * @return array|null
     */
    public function get_widget( $id ) {
        $widgets = $this->get_widgets();
        foreach ( $widgets as $widget ) {
            if ( ( $widget['id'] ?? '' ) === $id ) {
                return $widget;
            }
        }
        return null;
    }

    /**
     * Add or update a widget.
     *
     * @param array $widget Must have 'id' and 'name'.
     * @return array The saved widget.
     */
    public function save_widget( array $widget ) {
        if ( empty( $widget['id'] ) ) {
            throw new \InvalidArgumentException( 'Widget ID is required.' );
        }

        $widgets = $this->get_widgets();
        $found   = false;

        foreach ( $widgets as &$w ) {
            if ( $w['id'] === $widget['id'] ) {
                $w = array_merge( $w, $widget );
                $found = true;
                break;
            }
        }
        unset( $w );

        if ( ! $found ) {
            $widgets[] = $widget;
        }

        update_option( AGENTSHELL_WIDGETS_OPTION, $widgets, false );
        $this->cache = null; // Bust cache
        $this->bump_version();

        return $this->get_widget( $widget['id'] );
    }

    /**
     * Remove a widget by ID.
     *
     * @param string $id
     * @return bool True if removed.
     */
    public function delete_widget( $id ) {
        $widgets = $this->get_widgets();
        $original = count( $widgets );
        $widgets  = array_values( array_filter( $widgets, function( $w ) use ( $id ) {
            return ( $w['id'] ?? '' ) !== $id;
        } ) );

        if ( count( $widgets ) < $original ) {
            update_option( AGENTSHELL_WIDGETS_OPTION, $widgets, false );
            $this->cache = null;
            $this->bump_version();
            return true;
        }
        return false;
    }

    /**
     * Render a widget by ID, returning the appropriate <div>.
     * Called by the do_action('agentshell_render_widget', $id) hook handler.
     *
     * @param string $id
     * @return string
     */
    public function render_widget( $id ) {
        $widget = $this->get_widget( $id );
        if ( ! $widget ) {
            return '<!-- Widget not found: ' . esc_html( $id ) . ' -->';
        }

        if ( ! empty( $widget['template'] ) ) {
            return wp_kses_post( $widget['template'] );
        }
        return '<div data-widget-id="' . esc_attr( $id ) . '"></div>';
    }

    /**
     * Get consolidated CSS for all widgets.
     *
     * @return string
     */
    public function get_all_css() {
        $css = '';
        foreach ( $this->get_widgets() as $widget ) {
            if ( ! empty( $widget['css'] ) ) {
                $css .= "\n/* Widget: " . esc_html( $widget['id'] ?? '' ) . " */\n" . $widget['css'];
            }
        }
        return $css;
    }

    /**
     * Get consolidated init_js for all widgets.
     * Returns the window.AgentshellWidgets registry + MutationObserver init.
     *
     * @return string
     */
    public function get_init_js() {
        $inits = array();
        foreach ( $this->get_widgets() as $widget ) {
            if ( ! empty( $widget['init_js'] ) ) {
                $inits[] = $widget['init_js'];
            }
        }

        if ( empty( $inits ) ) {
            return '';
        }

        $registry_js = "/* agentshell-widgets v" . $this->get_version() . " */\n";
        $registry_js .= "window.AgentshellWidgets = window.AgentshellWidgets || {};\n";
        $registry_js .= "window.AgentshellWidgets.version = " . $this->get_version() . ";\n";
        foreach ( $inits as $idx => $init ) {
            $registry_js .= "(function() {\n";
            $registry_js .= "try {\n" . $init . "\n";
            $registry_js .= "} catch(e) {\n";
            $registry_js .= "console.error('AgentshellWidgets init_js error in widget " . ( $idx + 1 ) . ":', e);\n";
            $registry_js .= "}\n";
            $registry_js .= "})();\n";
        }

        // MutationObserver to init [data-widget] elements
        $registry_js .= <<<'JS'
document.addEventListener('DOMContentLoaded', function() {
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    if (node.hasAttribute('data-widget')) {
                        AgentshellWidgets.initWidget(node, node.getAttribute('data-widget'));
                    }
                    node.querySelectorAll('[data-widget]').forEach(function(el) {
                        AgentshellWidgets.initWidget(el, el.getAttribute('data-widget'));
                    });
                }
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });
    // Init any pre-existing data-widget elements
    document.querySelectorAll('[data-widget]').forEach(function(el) {
        AgentshellWidgets.initWidget(el, el.getAttribute('data-widget'));
    });
});
JS;
        return $registry_js;
    }
}

/**
 * Hook handler: do_action('agentshell_render_widget', $id)
 * Plugin intercepts and renders the widget.
 */
add_action( 'agentshell_render_widget', function( $widget_id ) {
    $registry = Widget_Registry::get_instance();
    echo $registry->render_widget( $widget_id );
} );
