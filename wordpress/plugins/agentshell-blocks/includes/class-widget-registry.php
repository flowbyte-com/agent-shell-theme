<?php
namespace AgentShell_Blocks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Widget registry — a *view* over the canonical store, not a second one.
 *
 * This class used to own an `agentshell_widgets` option, which is where the
 * blocks plugin's register_widget wrote and nowhere the theme ever read. The
 * theme's registry — `agentshell_get_widget_registry()`, built from
 * agentshell_config.widgets merged with the file widgets in widgets/ — is the
 * canonical one, and a second store is not merely redundant: registering
 * through this class produced a widget that `agentshell_enable_widget` then
 * reported as unknown, because the guard asked the canonical registry, which
 * had never heard of it.
 *
 * So reads and writes both go through the theme now. The legacy option is
 * still honoured as a *fallback* when the theme is not loaded, because this
 * plugin declares a dependency on the theme and should never be the reason a
 * site cannot boot; the theme drains that option into the canonical store via
 * agentshell_migrate_legacy_widgets_option() on activation and on admin_init.
 *
 * Theme queries widgets via do_action('agentshell_render_widget', $widget_id).
 * Plugin hooks in and renders via Widget_Registry::render_widget().
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
     * Whether the theme's canonical registry is available to defer to.
     *
     * @return bool
     */
    public static function has_canonical_store() {
        return function_exists( 'agentshell_get_widget_registry' );
    }

    /**
     * Get all registered widgets, from the canonical store when there is one.
     *
     * @return array Sequentially indexed list of widget arrays.
     */
    public function get_widgets() {
        if ( null === $this->cache ) {
            if ( self::has_canonical_store() ) {
                // Committed, not effective. This is what the tools and every
                // ordinary visitor read. Rendering does not come through here —
                // it goes through get_widgets_for_render() below, so that the
                // preview gate stays the single place that decides whether a
                // request may see staged widgets.
                $this->cache = array_values( agentshell_get_widget_registry() );
            } else {
                $this->cache = get_option( self::legacy_option(), array() );
            }
        }
        return is_array( $this->cache ) ? $this->cache : array();
    }

    /**
     * The legacy option name, resolved late so the constant does not have to
     * exist in tests that load this class directly.
     */
    private static function legacy_option() {
        return defined( 'AGENTSHELL_WIDGETS_OPTION' ) ? AGENTSHELL_WIDGETS_OPTION : 'agentshell_widgets';
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
     * Writes through the canonical store when the theme is loaded, so the write
     * is staged inside a transaction and recorded in history like every other
     * config mutation. The legacy option is only written when there is no
     * canonical store to write to.
     *
     * @param array $widget Must have 'id' and 'name'.
     * @return array The saved widget.
     */
    public function save_widget( array $widget ) {
        if ( empty( $widget['id'] ) ) {
            throw new \InvalidArgumentException( 'Widget ID is required.' );
        }

        if ( self::has_canonical_store() ) {
            $config = agentshell_get_config( 'effective' );
            if ( ! isset( $config['widgets'] ) || ! is_array( $config['widgets'] ) ) {
                $config['widgets'] = array();
            }

            $found = false;
            foreach ( $config['widgets'] as $i => $w ) {
                if ( ( $w['id'] ?? '' ) === $widget['id'] ) {
                    $config['widgets'][ $i ] = array_merge( $w, $widget );
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $config['widgets'][] = $widget;
            }

            update_option( 'agentshell_config', $config );
            $this->cache = null; // Bust cache
            $this->bump_version();

            return $this->get_widget( $widget['id'] ) ?? $widget;
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

        update_option( self::legacy_option(), $widgets, false );
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
        if ( self::has_canonical_store() ) {
            // Delegate to the theme's helper so the rules — file widgets are not
            // removable, dangling zone references are stripped — hold whichever
            // plugin the caller came through.
            $config = agentshell_get_config( 'effective' );
            try {
                $updated = agentshell_remove_widget_from_config( $config, $id );
            } catch ( \InvalidArgumentException $e ) {
                return false;
            }
            update_option( 'agentshell_config', $updated );
            $this->cache = null;
            $this->bump_version();
            return true;
        }

        $widgets = $this->get_widgets();
        $original = count( $widgets );
        $widgets  = array_values( array_filter( $widgets, function( $w ) use ( $id ) {
            return ( $w['id'] ?? '' ) !== $id;
        } ) );

        if ( count( $widgets ) < $original ) {
            update_option( self::legacy_option(), $widgets, false );
            $this->cache = null;
            $this->bump_version();
            return true;
        }
        return false;
    }

    /**
     * Resolve a widget for the render path.
     *
     * get_widgets() is committed on purpose — it is what every tool and every
     * ordinary visitor reads. The render path is different: shell-render.php
     * fires agentshell_render_widget for a widget id that *this plugin* then
     * looks up, so resolving through the committed registry meant an authorised
     * preview rendered the staged zone composition and then printed
     * "Widget not found" for the widget the page had just asked for. The zone
     * composition came from agentshell_zones_for_render() and the widget body
     * from here, so the two halves of one preview could disagree.
     *
     * The theme's resolver is the single place that decides whether a request
     * may see staged widgets, so the render path defers to it. Without the
     * theme loaded there is nothing to defer to and this falls back to the
     * committed registry, which is all this plugin ever had.
     *
     * @param string $id
     * @return array|null
     */
    private function get_widget_for_render( $id ) {
        if ( function_exists( 'agentshell_widget_registry_for_render' ) ) {
            $registry = agentshell_widget_registry_for_render();
            return isset( $registry[ $id ] ) ? $registry[ $id ] : null;
        }
        return $this->get_widget( $id );
    }

    /**
     * Every widget the render path may use, in the same shape get_widgets()
     * returns.
     *
     * @return array
     */
    private function get_widgets_for_render() {
        if ( function_exists( 'agentshell_widget_registry_for_render' ) ) {
            return array_values( agentshell_widget_registry_for_render() );
        }
        return $this->get_widgets();
    }

    /**
     * Render a widget by ID, returning the appropriate <div>.
     * Called by the do_action('agentshell_render_widget', $id) hook handler.
     *
     * @param string $id
     * @return string
     */
    public function render_widget( $id ) {
        $widget = $this->get_widget_for_render( $id );
        if ( ! $widget ) {
            return '<!-- Widget not found: ' . esc_html( $id ) . ' -->';
        }

        // A disabled widget renders nothing. The theme's agentshell_render_widget()
        // has always done this; the plugin's copy did not, so which of the two
        // answered depended on whether the blocks plugin was active.
        if ( ( $widget['status'] ?? 'active' ) === 'disabled' ) {
            return '';
        }

        if ( ! empty( $widget['template'] ) ) {
            $html = wp_kses_post( $widget['template'] );
        } else {
            $html = '<div data-widget-id="' . esc_attr( $id ) . '"></div>';
        }

        // The rendered widget must have exactly one marked root, or the
        // widget runtime never finds it and its init function is never
        // called — silently. The theme owns the rule (it is the base of
        // the pair); this plugin delegates to it and keeps its own
        // behaviour when the theme is absent.
        if ( function_exists( 'agentshell_mark_widget_root' ) ) {
            return agentshell_mark_widget_root( $html, $id );
        }
        return $html;
    }

    /**
     * Get consolidated CSS for all widgets.
     *
     * @return string
     */
    public function get_all_css() {
        $css = '';
        foreach ( $this->get_widgets_for_render() as $widget ) {
            if ( ! empty( $widget['css'] ) ) {
                $css .= "\n/* Widget: " . esc_html( $widget['id'] ?? '' ) . " */\n" . $widget['css'];
            }
        }
        return $css;
    }

    /**
     * Get consolidated init_js for all widgets.
     * Returns the window.AgentshellWidgets registry + the widget runtime.
     *
     * The runtime — the code that actually calls a widget's init function —
     * belongs to the theme, which is the base of this pair (this plugin
     * declares a dependency on the theme). It used to have a second copy
     * here, and that copy called `AgentshellWidgets.initWidget(el, id)`,
     * a function it never defined, while selecting `[data-widget]` where
     * every renderer in this repository emits `data-widget-id`. Two broken
     * copies of the same responsibility; one of them is now enough.
     *
     * @return string
     */
    public function get_init_js() {
        $inits = array();
        foreach ( $this->get_widgets_for_render() as $widget ) {
            if ( ! empty( $widget['init_js'] ) ) {
                $inits[] = $widget['init_js'];
            }
        }

        if ( empty( $inits ) ) {
            return '';
        }

        $registry_js = "/* agentshell-widgets v" . $this->get_version() . " */\n";
        if ( function_exists( 'agentshell_widget_runtime_js' ) ) {
            $registry_js .= agentshell_widget_runtime_js() . "\n";
        }
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
