<?php
/**
 * AgentShell theme functions
 */
// Disable automatic formatting for agent payloads
// This prevents WordPress from wrapping content in <p>, <br>, etc.
remove_filter( 'the_content', 'wpautop' );
remove_filter( 'the_excerpt', 'wpautop' );
remove_filter( 'the_content', 'wptexturize' );
remove_filter( 'the_content', 'convert_smilies' );
remove_filter( 'the_content', 'convert_chars' );
remove_filter( 'the_content', 'wp_make_content_images_responsive' );
remove_filter( 'the_excerpt', 'wptexturize' );
remove_filter( 'the_excerpt', 'convert_smilies' );
remove_filter( 'the_excerpt', 'convert_chars' );

// Remove Emoji Scripts
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles', 'print_emoji_styles');

// Remove Head Bloat
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'rest_output_link_wp_head', 10);
remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
remove_action('wp_head', 'wp_oembed_add_host_js');

// Prevent WordPress from adding rel="noopener" or other auto-attributes to links
// that could interfere with agent-built interactive widgets
remove_filter( 'the_content', 'wp_targeted_link_rel' );
/**
 * Register agentshell_config option on theme activation.
 */
function agentshell_theme_activation() {
    if ( get_option( 'agentshell_config' ) !== false ) {
        return;
    }
    $seed_file = get_template_directory() . '/default-config.json';
    if ( ! file_exists( $seed_file ) ) {
        return;
    }
    $seed = json_decode( file_get_contents( $seed_file ), true );
    if ( ! is_array( $seed ) ) {
        return;
    }
    add_option( 'agentshell_config', $seed );
}
add_action( 'after_switch_theme', 'agentshell_theme_activation' );

/**
 * Enqueue theme assets
 */
function agentshell_enqueue_assets() {
    // Deregister WordPress core styles that conflict with agent shell
    wp_deregister_style( 'wp-block-library' );
    wp_deregister_style( 'wp-block-library-theme' );
    wp_deregister_style( 'wp-block-style' );
    wp_deregister_style( 'wp-components' );
    wp_deregister_style( 'wp-editor' );
    wp_deregister_style( 'wp-format-library' );
    wp_deregister_style( 'wp-nux' );
    wp_deregister_style( 'wp-list-reusable-blocks' );
    wp_deregister_style( 'wp-emoji-styles' );
    wp_deregister_style( 'wp-img-autosizes' );
    wp_deregister_style( 'classic-theme-styles' );
    wp_deregister_style( 'global-styles' );

    // Remove the wp-img-autosizes inline style that gets printed separately
    remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
    remove_action( 'wp_enqueue_scripts', 'wp_enqueue_block_template_styles' );
    remove_action( 'wp_enqueue_scripts', 'wp_print_styles' );
    add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
        if ( 'dns-prefetch' === $relation_type ) {
            $urls = array_filter( $urls, function( $url ) {
                return ! strpos( $url['href'] ?? '', 'wp-includes' );
            } );
        }
        return $urls;
    }, 10, 2 );

    // Main theme stylesheet — always enqueued with filemtime cache busting
    wp_enqueue_style(
        'agentshell-style',
        get_template_directory_uri() . '/style.css',
        array(),
        filemtime( get_template_directory() . '/style.css' )
    );

    // Configurator assets: logged-in users only
    if ( ! is_user_logged_in() ) {
        return;
    }
    wp_enqueue_style( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.css', array( 'agentshell-style' ), '1.0.0' );
    wp_enqueue_script( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.js', array(), '1.0.0', true );
    wp_localize_script( 'agentshell-configurator', 'AgentShellConfig', array(
        'adminUrl'   => admin_url( 'admin-ajax.php' ),
        'restUrl'    => rest_url(),
        'nonce'      => wp_create_nonce( 'wp_rest' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'agentshell_enqueue_assets' );

/**
 * 1. Inject Saved CSS Variables, custom_css, and structural prohibition.
 * Injects all -- vars from flattened config, custom_css if present,
 * and a grid-fix rule that prevents agents from breaking layout with
 * position: fixed/absolute on zone containers.
 */
function agentshell_inject_saved_styles() {
    $config = get_option( 'agentshell_config', array() );

    if ( function_exists( 'agentshell_flatten_config' ) ) {
        $flat = agentshell_flatten_config( $config );
    } else {
        $flat = $config;
    }

    if ( empty( $flat ) ) return;

    echo "<style id='agentshell-saved-config'>\n:root {\n";
    foreach ( $flat as $key => $value ) {
        if ( strpos( $key, '--' ) === 0 && ! empty( $value ) ) {
            echo '    ' . esc_attr( $key ) . ': ' . esc_attr( $value ) . ";\n";
        }
    }
    echo "}\n</style>\n";

    // custom_css — trusted author context
    // Strip <style> tags but keep the CSS rules inside. Use a negative lookahead
    // to avoid bypassing the strip via </style> appearing inside CSS string literals.
    if ( ! empty( $config['custom_css'] ) ) {
        $css = preg_replace( '/<\/?style\b[^>]*>/i', '', $config['custom_css'] );
        $css = preg_replace( '/<\/?style\b[^>]*>/i', '', $css ); // second pass for nested
        echo "<style id='agentshell-custom-css'>\n" . trim( $css ) . "\n</style>\n";
    }

    // Structural prohibition: prevent agents from breaking the grid
    // with position: fixed or absolute on zone containers.
    echo "<style id='agentshell-grid-fix'>
#zone-header, #zone-main, #zone-sidebar, #zone-footer {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    right: auto !important;
    bottom: auto !important;
    z-index: auto !important;
}
</style>\n";

    // Layout grid CSS from config (breakpoints + grid areas)
    if ( function_exists( 'agentshell_get_layout_config' ) ) {
        $layout_cfg = agentshell_get_layout_config();
        // grid-areas.php defines agentshell_get_layout_css()
        locate_template( 'template-parts/grid-areas.php', true, false );
        if ( function_exists( 'agentshell_get_layout_css' ) ) {
            $zones = array( 'header', 'main', 'sidebar', 'footer' );
            echo agentshell_get_layout_css(
                $zones,
                $layout_cfg['grid_areas'],
                $layout_cfg['breakpoints'],
                $layout_cfg['grid_gap'],
                $layout_cfg['grid_padding']
            );
        }
    }
}
// Priority 100 ensures this prints AFTER style.css
add_action( 'wp_head', 'agentshell_inject_saved_styles', 100 );

// Strip unwanted WordPress inline styles from head - runs last at wp_head
function agentshell_cleanup_wp_head() {
    remove_action( 'wp_head', 'wp_enqueue_global_styles' );
    remove_action( 'wp_head', 'wp_enqueue_block_template_styles' );
    remove_action( 'wp_head', 'wp_print_styles' );
    remove_action( 'wp_head', 'wp_print_font_measure_styles' );
}
add_action( 'init', 'agentshell_cleanup_wp_head', 100 );

// Output buffer to strip persistent inline styles that bypass deregister
function agentshell_ob_strip_inline_styles() {
    if ( ! is_admin() && ! did_action( 'wp_head' ) ) {
        ob_start();
    }
}
add_action( 'wp', 'agentshell_ob_strip_inline_styles', 1 );

function agentshell_ob_flush() {
    if ( ob_get_level() ) {
        $html = ob_get_clean();
        $html = preg_replace( '/<style id=["\']wp-img-auto-sizes[^>]*>.*?<\/style>\s*/is', '', $html );
        echo $html;
    }
}
add_action( 'wp_head', 'agentshell_ob_flush', 99999 );


/**
 * 2. Persist the Sidebar State
 * Reads the DB and injects the 'sidebar-enabled' class into the <body> tag on load.
 */
function agentshell_persist_sidebar( $classes ) {
    $config = get_option( 'agentshell_config', array() );

    // Check if sidebar is enabled in the nested config OR flat config
    if ( ! empty( $config['sidebar_enabled'] ) || ! empty( $config['layout']['sidebar_enabled'] ) ) {
        $classes[] = 'sidebar-enabled';
    }

    return $classes;
}
add_filter( 'body_class', 'agentshell_persist_sidebar' );

/**
 * Pre-load approved libraries for agent-built Web Component widgets.
 * Agents do NOT need to inject <script src="..."> for these.
 * Currently available: d3 (window.d3), mathjs (window.math)
 *
 * To add more libraries, add another wp_enqueue_script line here.
 * Keep pre-loaded libraries minimal — each adds load time.
 */
function agentshell_enqueue_widget_libs() {
    // D3.js — data visualizations, charts, graphs
    wp_enqueue_script(
        'agentshell-d3',
        'https://d3js.org/d3.v7.min.js',
        array(),
        null,
        true
    );
    // Math.js — math expressions, calculators
    wp_enqueue_script(
        'agentshell-mathjs',
        'https://cdnjs.cloudflare.com/ajax/libs/mathjs/11.8.0/math.min.js',
        array(),
        null,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'agentshell_enqueue_widget_libs' );

/**
 * Register the primary sidebar widget area.
 * Used by #zone-sidebar in header.php when sidebar_enabled is true.
 */
function agentshell_widgets_init() {
    register_sidebar( array(
        'name'          => esc_html__( 'Primary Sidebar', 'agentshell' ),
        'id'            => 'primary-sidebar',
        'description'   => esc_html__( 'Widgets for the sidebar zone.', 'agentshell' ),
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ) );
}
add_action( 'widgets_init', 'agentshell_widgets_init' );

/**
 * Register nav menus.
 */
function agentshell_setup() {
    // Load shell-render.php to make agentshell_render_zone() available to header.php
    locate_template( 'template-parts/shell-render.php', true, false );

    register_nav_menus( array(
        'primary' => esc_html__( 'Primary Navigation', 'agentshell' ),
    ) );
}
add_action( 'after_setup_theme', 'agentshell_setup' );

/**
 * Get the full shell config.
 */
function agentshell_get_config() {
    $config = get_option( 'agentshell_config' );
    return is_array( $config ) ? $config : array();
}

/**
 * Update the shell config atomically
 */
function agentshell_update_config( array $config ) {
    return update_option( 'agentshell_config', $config );
}

/**
 * Get the declared zones from config.
 * Falls back to default zones for existing installs that predate the zone registry.
 *
 * @return array
 */
function agentshell_get_zones() {
    $defaults = array(
        array( 'id' => 'header',  'label' => 'Header',  'source' => 'wp_loop' ),
        array( 'id' => 'main',    'label' => 'Main',    'source' => 'wp_loop' ),
        array( 'id' => 'sidebar', 'label' => 'Sidebar', 'source' => 'wp_widget_area', 'widget_area_id' => 'primary-sidebar' ),
        array( 'id' => 'footer',  'label' => 'Footer',  'source' => 'wp_loop' ),
    );
    $config = agentshell_get_config();
    $saved  = $config['zones'] ?? array();

    // Merge saved zone configs over defaults (saved overrides take precedence)
    $by_id = array();
    foreach ( $defaults as $d ) {
        $by_id[ $d['id'] ] = $d;
    }
    foreach ( $saved as $s ) {
        $id = $s['id'] ?? null;
        if ( $id && isset( $by_id[ $id ] ) ) {
            $by_id[ $id ] = array_merge( $by_id[ $id ], $s );
        } elseif ( $id ) {
            $by_id[ $id ] = $s;
        }
    }

    return array_values( $by_id );
}

/**
 * Merge stable widgets (from /widgets/ files) with agent-defined widgets (from wp_options).
 * JSON-defined widgets override file-defined widgets with the same ID.
 *
 * @return array Merged widget registry keyed by widget ID
 */
function agentshell_get_widget_registry() {
    $registry = array();

    // 1. Load stable widgets from /widgets/ directory
    $stable_index = get_template_directory() . '/widgets/.index.json';
    if ( file_exists( $stable_index ) ) {
        $index = json_decode( file_get_contents( $stable_index ), true );
        foreach ( $index['stable'] ?? array() as $entry ) {
            if ( empty( $entry['id'] ) || empty( $entry['file'] ) ) {
                continue;
            }
            $widget_file = get_template_directory() . '/widgets/' . $entry['file'];
            if ( file_exists( $widget_file ) ) {
                $widget = include $widget_file;
                if ( is_array( $widget ) && ! empty( $widget['id'] ) ) {
                    $registry[ $widget['id'] ] = $widget;
                }
            }
        }
    }

    // 2. Merge agent-defined widgets from config (these override stable with same ID)
    $config = agentshell_get_config();
    foreach ( $config['widgets'] ?? array() as $widget ) {
        if ( empty( $widget['id'] ) ) {
            continue;
        }
        $registry[ $widget['id'] ] = $widget;
    }

    return $registry;
}

/**
 * Render a single widget by ID.
 * Returns empty string if widget not found.
 *
 * @param string $widget_id
 * @return string HTML
 */
function agentshell_render_widget( $widget_id ) {
    $registry = agentshell_get_widget_registry();
    if ( ! isset( $registry[ $widget_id ] ) ) {
        return '';
    }
    $widget = $registry[ $widget_id ];

    // Render template (optional HTML skeleton)
    $html = '';
    if ( ! empty( $widget['template'] ) ) {
        $html = wp_kses_post( $widget['template'] );
    } else {
        $html = "<div data-widget-id=\"{$widget_id}\"></div>";
    }

    return $html;
}

/**
 * Get all widget init scripts and scoped CSS for footer injection.
 * Returns array with 'init_js' and 'css' keys.
 *
 * @return array
 */
function agentshell_get_widget_assets() {
    $registry = agentshell_get_widget_registry();
    $init_js  = '';
    $css      = '';

    foreach ( $registry as $widget ) {
        if ( ! empty( $widget['init_js'] ) ) {
            $init_js .= "\n/* Widget: {$widget['id']} */\n" . $widget['init_js'];
        }
        if ( ! empty( $widget['css'] ) ) {
            $css .= "\n/* Widget: {$widget['id']} */\n" . $widget['css'];
        }
    }

    return array( 'init_js' => $init_js, 'css' => $css );
}

/**
 * Get layout configuration (breakpoints, grid areas, gap, padding).
 * Deep-merges user config over hard defaults so partial configs work.
 *
 * @return array
 */
function agentshell_get_layout_config() {
    $config = agentshell_get_config();
    $layout = $config['layout'] ?? array();

    $defaults = array(
        'breakpoints' => array(
            'mobile'  => '0px',
            'tablet'  => '768px',
            'desktop' => '1024px',
        ),
        'grid_areas' => array(
            'mobile'  => array( 'header', 'main', 'footer' ),
            'tablet'  => array( 'header header', 'main sidebar', 'footer footer' ),
            'desktop' => array( 'header header', 'main sidebar', 'footer footer' ),
        ),
        'grid_gap'     => '1rem',
        'grid_padding' => '2rem',
    );

    // Deep-merge user config over defaults
    foreach ( $defaults as $key => $default ) {
        if ( ! isset( $layout[ $key ] ) ) {
            $layout[ $key ] = $default;
        } elseif ( is_array( $default ) && is_array( $layout[ $key ] ) ) {
            $layout[ $key ] = array_merge( $default, $layout[ $key ] );
        }
    }

    return $layout;
}

/**
 * Authenticate REST requests via X-AgentShell-Token header.
 *
 * Supports two methods:
 * 1. Static token: X-AgentShell-Token: <token>
 *    - Set AGENTSHELL_REST_TOKEN in wp-config.php or use default dev token
 *    - Simplest for headless / programmatic clients
 *
 * 2. Basic Auth (WP Application Passwords):
 *    - Authorization: Basic <base64(username:app_password)>
 *    - Standard WP REST auth method
 *
 * Note: X-WP-Nonce (cookie auth) is NOT supported for headless clients
 * because WP 5.7+ changed wp_verify_nonce() to use wp_validate_auth_cookie()
 * which requires session cookies that headless clients don't send.
 *
 * @param WP_Error|null $errors
 * @return WP_Error|null
 */
add_filter( 'rest_authentication_errors', function( $errors ) {
    // If another auth method already set an error, don't override
    if ( is_wp_error( $errors ) && $errors->get_error_code() ) {
        return $errors;
    }

    // Method 1: Static token auth
    $static_token = defined( 'AGENTSHELL_REST_TOKEN' ) ? AGENTSHELL_REST_TOKEN : 'agentshell_dev_token';
    $provided = isset( $_SERVER['HTTP_X_AGENTSHELL_TOKEN'] )
        ? $_SERVER['HTTP_X_AGENTSHELL_TOKEN']
        : ( isset( $_REQUEST['_agent_token'] ) ? $_REQUEST['_agent_token'] : '' );

    if ( $provided && hash_equals( $static_token, $provided ) ) {
        wp_set_current_user( 1 );
        return null;
    }

    // Method 2: Basic Auth (Application Passwords)
    // Supports both Authorization: Basic header and X-App-Password header.
    $app_password = isset( $_SERVER['HTTP_X_APP_PASSWORD'] ) ? $_SERVER['HTTP_X_APP_PASSWORD'] : '';
    $auth_header  = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

    if ( $auth_header && str_starts_with( strtolower( $auth_header ), 'basic ' ) ) {
        $creds = base64_decode( substr( $auth_header, 6 ), true );
        if ( $creds && str_contains( $creds, ':' ) ) {
            $parts       = explode( ':', $creds, 2 );
            $username    = trim( $parts[0] );
            $app_password = $parts[1];
        }
    }

    if ( ! empty( $app_password ) ) {
        $user = get_user_by( 'slug', $username ?? '' )
              ?: get_user_by( 'login', $username ?? '' )
              ?: get_user_by( 'email', $username ?? '' );
        if ( $user ) {
            $stored = WP_Application_Passwords::get_user_application_passwords( $user->ID );
            foreach ( $stored as $item ) {
                if ( WP_Application_Passwords::check_password( $app_password, $item['password'] ) ) {
                    wp_set_current_user( $user->ID );
                    return null; // Auth succeeded
                }
            }
        }
    }

    return $errors; // No recognized auth — let WP's defaults handle it
}, 1 );

/**
 * Flatten nested config to CSS-variable key space for configurator form.
 * e.g. { design: { colors: { bg: "#fff" } } } → { "--theme-bg": "#fff" }
 *
 * Wildcard: any key starting with -- is a CSS variable, written directly —
 * agents can add custom CSS vars without touching path mapping.
 *
 * @param array $config
 * @return array
 */
function agentshell_flatten_config( array $config ) {
    $flat = array( 'sidebar_enabled' => ! empty( $config['sidebar_enabled'] ) );

    // Wildcard: any key starting with -- is a CSS variable, write directly
    array_walk_recursive( $config, function( $value, $key ) use ( &$flat ) {
        if ( strpos( $key, '--' ) === 0 && is_string( $value ) ) {
            $flat[ $key ] = $value;
        }
    } );

    // Legacy map: still include these so older configs flatten correctly
    $map = array(
        '--theme-bg'        => array( 'design', 'colors', 'background' ),
        '--theme-surface'   => array( 'design', 'colors', 'surface' ),
        '--theme-text'      => array( 'design', 'colors', 'text' ),
        '--theme-accent'    => array( 'design', 'colors', 'accent' ),
        '--theme-border'    => array( 'design', 'colors', 'border' ),
        '--theme-header-bg' => array( 'design', 'colors', 'primary' ),
        '--theme-footer-bg' => array( 'design', 'colors', 'secondary' ),
        '--font-base'       => array( 'design', 'typography', 'fontFamily' ),
        '--font-mono'       => array( 'design', 'typography', 'mono' ),
        '--spacing-base'    => array( 'design', 'typography', 'baseSize' ),
        '--radius-base'     => array( 'design', 'layout', 'radius' ),
        '--zone-header-height' => array( 'design', 'layout', 'headerHeight' ),
        '--zone-footer-height' => array( 'design', 'layout', 'footerHeight' ),
    );

    foreach ( $map as $var => $path ) {
        $val = $config;
        foreach ( $path as $k ) {
            $val = is_array( $val ) ? ( $val[ $k ] ?? null ) : null;
            if ( $val === null ) break;
        }
        if ( $val !== null ) {
            $flat[ $var ] = $val;
        }
    }

    return $flat;
}

/**
 * Expand flat CSS-variable keys back into nested config structure,
 * then merge into the existing stored config so no keys are lost.
 *
 * Wildcard: any key starting with -- that's not in the legacy map is
 * written directly to design.custom_css_vars so agents can add custom vars.
 *
 * @param array $flat   Flat key-value pairs from configurator
 * @param array $existing Existing stored config to merge into
 * @return array Merged nested config
 */
function agentshell_unflatten_config( array $flat, array $existing ) {
    $css_to_path = array(
        '--theme-bg'        => array( 'design', 'colors', 'background' ),
        '--theme-surface'   => array( 'design', 'colors', 'surface' ),
        '--theme-text'      => array( 'design', 'colors', 'text' ),
        '--theme-accent'    => array( 'design', 'colors', 'accent' ),
        '--theme-border'    => array( 'design', 'colors', 'border' ),
        '--theme-header-bg' => array( 'design', 'colors', 'primary' ),
        '--theme-footer-bg' => array( 'design', 'colors', 'secondary' ),
        '--font-base'       => array( 'design', 'typography', 'fontFamily' ),
        '--font-mono'       => array( 'design', 'typography', 'mono' ),
        '--spacing-base'    => array( 'design', 'typography', 'baseSize' ),
        '--radius-base'     => array( 'design', 'layout', 'radius' ),
        '--zone-header-height' => array( 'design', 'layout', 'headerHeight' ),
        '--zone-footer-height' => array( 'design', 'layout', 'footerHeight' ),
    );

    // Deep-merge $flat into a copy of $existing
    $merged = $existing;
    foreach ( $flat as $key => $value ) {
        if ( $key === 'sidebar_enabled' ) {
            $merged['sidebar_enabled'] = (bool) $value;
            continue;
        }
        if ( $key === 'custom_css' ) {
            $merged['custom_css'] = is_string( $value ) ? $value : '';
            continue;
        }
        if ( $key === 'custom_js' ) {
            $merged['custom_js'] = is_string( $value ) ? $value : '';
            continue;
        }
        if ( $key === 'zones' ) {
            $merged['zones'] = is_array( $value ) ? $value : array();
            continue;
        }
        if ( $key === 'widgets' ) {
            $merged['widgets'] = is_array( $value ) ? $value : array();
            continue;
        }
        if ( $key === 'layout' ) {
            $merged['layout'] = is_array( $value ) ? $value : array();
            continue;
        }
        if ( isset( $css_to_path[ $key ] ) ) {
            $path = $css_to_path[ $key ];
            $ref  = &$merged;
            foreach ( $path as $i => $k ) {
                if ( $i === count( $path ) - 1 ) {
                    $ref[ $k ] = is_string( $value ) ? $value : $ref[ $k ];
                } else {
                    if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                        $ref[ $k ] = array();
                    }
                    $ref = &$ref[ $k ];
                }
            }
            unset( $ref );
        } elseif ( strpos( $key, '--' ) === 0 ) {
            // Wildcard: any --key not in legacy map goes to custom_css_vars
            if ( ! isset( $merged['design'] ) ) {
                $merged['design'] = array();
            }
            if ( ! isset( $merged['design']['custom_css_vars'] ) ) {
                $merged['design']['custom_css_vars'] = array();
            }
            $merged['design']['custom_css_vars'][ $key ] = $value;
        }
    }

    return $merged;
}

/**
 * REST API: GET/PUT /wp/v2/agentshell/config
 * GET returns schema metadata + defaults + config for agent introspection.
 * PUT accepts flat key-value pairs, merges into nested config, persists.
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'GET',
        'callback' => function() {
            $config = agentshell_get_config();
            $flat   = agentshell_flatten_config( $config );
            $schema = array(
                'sidebar_enabled' => array( 'type' => 'boolean' ),
                'zones'           => array(
                    'type'  => 'array',
                    'items' => array(
                        'id'     => 'string',
                        'label'  => 'string',
                        'source' => 'string',
                    ),
                ),
                'widgets'    => array( 'type' => 'array' ),
                'custom_css' => array( 'type' => 'string', 'maxLength' => 10000 ),
                'custom_js'  => array( 'type' => 'string', 'maxLength' => 10000 ),
                'design'     => array( 'type' => 'object' ),
                'layout'     => array( 'type' => 'object' ),
            );
            return array(
                'schema'   => $schema,
                'defaults' => $flat,
                'config'   => $flat,
            );
        },
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'PUT',
        'callback' => function( WP_REST_Request $request ) {
            $flat = $request->get_json_params();
            if ( ! is_array( $flat ) ) {
                return new WP_Error( 'invalid_config', 'Config must be a valid JSON object', array( 'status' => 400 ) );
            }
            $existing = agentshell_get_config();
            $merged   = agentshell_unflatten_config( $flat, $existing );
            // update_option returns false when the value hasn't changed — not an error
            update_option( 'agentshell_config', $merged );
            return agentshell_flatten_config( $merged );
        },
        'permission_callback' => '__return_true',
    ) );

    // Preserve raw content format for agents — prevents WordPress from
    // auto-formatting when content is saved via REST API.
    // Agents should send { "content": { "raw": "..." } }
    add_filter( 'rest_pre_insert_post', function( $post, $request ) {
        $content = $request->get_param( 'content' );
        if ( is_array( $content ) && isset( $content['raw'] ) && is_string( $content['raw'] ) ) {
            // Remove all content filtering hooks for raw agent content
            remove_filter( 'content_save_pre', 'wp_filter_post_kses', 10 );
            remove_filter( 'content_save_pre', 'wp_filter_kses', 10 );
            remove_filter( 'content_save_pre', 'balanceTags', 10 );
            remove_filter( 'content_save_pre', 'wpautop', 10 );
            remove_filter( 'content_save_pre', 'wptexturize', 10 );
            remove_filter( 'content_save_pre', 'convert_smilies', 10 );
            remove_filter( 'content_save_pre', 'convert_chars', 10 );
            remove_filter( 'content_save_pre', 'wp_slash', 10 );
            remove_filter( 'content_save_pre', 'force_balance_tags', 10 );

            // Directly set post_content to raw value, bypassing sanitization
            $post['post_content'] = $content['raw'];
        }
        return $post;
    }, 10, 2 );

    add_filter( 'rest_pre_update_post', function( $post, $request ) {
        $content = $request->get_param( 'content' );
        if ( is_array( $content ) && isset( $content['raw'] ) && is_string( $content['raw'] ) ) {
            remove_filter( 'content_save_pre', 'wp_filter_post_kses', 10 );
            remove_filter( 'content_save_pre', 'wp_filter_kses', 10 );
            remove_filter( 'content_save_pre', 'balanceTags', 10 );
            remove_filter( 'content_save_pre', 'wpautop', 10 );
            remove_filter( 'content_save_pre', 'wptexturize', 10 );
            remove_filter( 'content_save_pre', 'convert_smilies', 10 );
            remove_filter( 'content_save_pre', 'convert_chars', 10 );
            remove_filter( 'content_save_pre', 'wp_slash', 10 );
            remove_filter( 'content_save_pre', 'force_balance_tags', 10 );

            $post['post_content'] = $content['raw'];
        }
        return $post;
    }, 10, 2 );
} );
