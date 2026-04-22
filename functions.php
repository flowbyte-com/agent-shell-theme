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

    // Structural prohibition: prevent agents from breaking the FSE layout
    // with position: fixed or absolute on zone containers.
    echo "<style id='agentshell-grid-fix'>
#zone-header, #zone-main, #zone-footer {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    right: auto !important;
    bottom: auto !important;
    z-index: auto !important;
}
</style>\n";
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

// Output buffer to strip persistent inline styles that bypass deregister.
// By wp_head (priority 99999), template rendering is complete so there should
// be exactly one buffer (ours). We use ob_end_flush to close it properly
// instead of ob_get_clean which only retrieves content without closing.
function agentshell_ob_strip_inline_styles() {
    if ( is_admin() || did_action( 'wp_head' ) ) {
        return;
    }
    ob_start();
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
 * @deprecated v2.0 — sidebar removed. Keeping stub to prevent fatals on upgrade.
 */
function agentshell_persist_sidebar( $classes ) {
    return $classes;
}

/**
 * Register nav menus.
 */
function agentshell_setup() {
    locate_template( 'template-parts/shell-render.php', true, false );

    register_nav_menus( array(
        'primary' => esc_html__( 'Primary Navigation', 'agentshell' ),
    ) );
}
add_action( 'after_setup_theme', 'agentshell_setup' );

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

/**
 * Get the full shell config.
 */
function agentshell_get_config() {
    $config = get_option( 'agentshell_config' );

    // Re-seed from default-config.json if the DB option is missing or empty.
    // Handles the case where get_option returns false (option not set yet).
    if ( $config === false || ! is_array( $config ) || empty( $config ) ) {
        $seed_file = get_template_directory() . '/default-config.json';
        if ( file_exists( $seed_file ) ) {
            $seed = json_decode( file_get_contents( $seed_file ), true );
            if ( is_array( $seed ) ) {
                update_option( 'agentshell_config', $seed );
                $config = $seed;
            }
        }
    }

    // Schema self-healing: migrate flat 'composition' zones to 'slots' for header/footer
    if ( isset( $config['zones'] ) && is_array( $config['zones'] ) ) {
        foreach ( $config['zones'] as &$zone ) {
            if ( in_array( $zone['id'] ?? '', array( 'header', 'footer' ), true ) && ! isset( $zone['slots'] ) ) {
                $zone['slots'] = array(
                    'left'   => array(),
                    'center' => $zone['composition'] ?? array(),
                    'right'  => array(),
                );
                unset( $zone['composition'] );
            }
        }
    }

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
        array( 'id' => 'header', 'label' => 'Header', 'composition' => array( array( 'type' => 'wp_loop' ) ) ),
        array( 'id' => 'main',   'label' => 'Main',   'composition' => array( array( 'type' => 'wp_loop' ) ) ),
        array( 'id' => 'footer', 'label' => 'Footer', 'composition' => array( array( 'type' => 'wp_loop' ) ) ),
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
 * Return supported WordPress core components as composable blocks.
 *
 * @return array
 */
function agentshell_get_core_components() {
    return array(
        array( 'id' => 'site_title',    'name' => 'Site Title' ),
        array( 'id' => 'site_tagline', 'name' => 'Site Tagline' ),
        array( 'id' => 'site_logo',    'name' => 'Site Logo' ),
        array( 'id' => 'nav_menu',     'name' => 'Primary Navigation Menu' ),
        array( 'id' => 'search_form',  'name' => 'Search Form' ),
    );
}

/**
 * Render a single WordPress core component by ID.
 *
 * @param string $id Component ID (site_title, nav_menu, etc.)
 * @return string HTML
 */
function agentshell_render_core_component( $id ) {
    switch ( $id ) {
        case 'site_title':
            return get_bloginfo( 'name' ) ?: '';
        case 'site_tagline':
            return get_bloginfo( 'description' ) ?: '';
        case 'site_logo':
            ob_start();
            the_custom_logo();
            return ob_get_clean();
        case 'nav_menu':
            return wp_nav_menu(array(
                'echo'           => false,
                'theme_location'=> 'primary',
                'container'     => 'nav',
                'container_class'=> 'wp-core-nav',
            )) ?: '<p class="wp-core-empty">No menu assigned to Primary Location</p>';
        case 'search_form':
            return get_search_form( array( 'echo' => false ) );
        default:
            return '<!-- wp_core not found: ' . esc_html( $id ) . ' -->';
    }
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
            $init_js .= "\n/* Widget: {$widget['id']} */\n";
            $init_js .= "(function() {\ntry {\n" . $widget['init_js'] . "\n} catch(e) {\nconsole.error('Agentshell widget init error (" . esc_js( $widget['id'] ) . "):', e);\n}\n})();\n";
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
 * REST auth callback — validates X-AgentShell-Token or Basic Auth (App Password).
 * Returns true if authorized, WP_Error if not.
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function agentshell_rest_auth_callback( WP_REST_Request $request ) {
    // If a WP user is already set via cookie auth (X-WP-Nonce validated upstream),
    // or via our custom token/app-password auth (which also calls wp_set_current_user),
    // just confirm get_current_user_id() is truthy.
    if ( get_current_user_id() ) {
        return true;
    }

    return new WP_Error(
        'rest_not_logged_in',
        'Authentication required. Provide X-AgentShell-Token header or Basic Auth.',
        array( 'status' => 401 )
    );
}

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
    $flat = array();

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
 * Validate config structure against allowed schema.
 * Returns WP_Error on failure, validated config array on success.
 *
 * @param array $config
 * @return array|WP_Error
 */
function agentshell_validate_config( $config ) {
    $allowed_colors = array(
        'background', 'surface', 'text', 'border', 'accent', 'primary', 'secondary',
    );
    $allowed_typography = array(
        'fontFamily', 'mono', 'baseSize', 'scale',
    );
    $allowed_layout = array(
        'radius', 'headerHeight', 'footerHeight',
    );
    $allowed_zone_ids = array( 'header', 'main', 'footer' );
    $allowed_block_types = array(
        'wp_loop', 'wp_core', 'widget', 'json_block', 'wp_widget_area',
    );
    $allowed_wp_core_ids = array(
        'site_title', 'nav_menu', 'search_form', 'site_logo', 'site_tagline',
    );

    if ( ! is_array( $config ) ) {
        return new WP_Error( 'invalid_config', 'Config must be an array.' );
    }

    if ( isset( $config['design']['colors'] ) ) {
        foreach ( $config['design']['colors'] as $key => $val ) {
            if ( ! in_array( $key, $allowed_colors, true ) ) {
                return new WP_Error( 'invalid_color_key', "Unknown color key: {$key}" );
            }
            if ( ! is_string( $val ) || ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $val ) ) {
                return new WP_Error( 'invalid_color_value', "Invalid color value for {$key}: {$val}" );
            }
        }
    }

    if ( isset( $config['design']['typography'] ) ) {
        foreach ( $config['design']['typography'] as $key => $val ) {
            if ( ! in_array( $key, $allowed_typography, true ) ) {
                return new WP_Error( 'invalid_typography_key', "Unknown typography key: {$key}" );
            }
        }
    }

    if ( isset( $config['design']['layout'] ) ) {
        foreach ( $config['design']['layout'] as $key => $val ) {
            if ( ! in_array( $key, $allowed_layout, true ) ) {
                return new WP_Error( 'invalid_layout_key', "Unknown layout key: {$key}" );
            }
        }
    }

    if ( isset( $config['zones'] ) && is_array( $config['zones'] ) ) {
        foreach ( $config['zones'] as $zone ) {
            if ( ! isset( $zone['id'] ) || ! in_array( $zone['id'], $allowed_zone_ids, true ) ) {
                return new WP_Error( 'invalid_zone_id', "Unknown or missing zone id: " . ( $zone['id'] ?? 'null' ) );
            }
            if ( isset( $zone['composition'] ) && is_array( $zone['composition'] ) ) {
                foreach ( $zone['composition'] as $block ) {
                    if ( ! isset( $block['type'] ) || ! in_array( $block['type'], $allowed_block_types, true ) ) {
                        return new WP_Error( 'invalid_block_type', "Unknown block type: " . ( $block['type'] ?? 'null' ) );
                    }
                }
            }
            if ( isset( $zone['slots'] ) && is_array( $zone['slots'] ) ) {
                foreach ( array( 'left', 'center', 'right' ) as $slot ) {
                    if ( isset( $zone['slots'][ $slot ] ) && is_array( $zone['slots'][ $slot ] ) ) {
                        foreach ( $zone['slots'][ $slot ] as $block ) {
                            if ( ! isset( $block['type'] ) || ! in_array( $block['type'], $allowed_block_types, true ) ) {
                                return new WP_Error( 'invalid_slot_block_type', "Unknown slot block type: " . ( $block['type'] ?? 'null' ) );
                            }
                            if ( $block['type'] === 'wp_core' && isset( $block['id'] ) && ! in_array( $block['id'], $allowed_wp_core_ids, true ) ) {
                                return new WP_Error( 'invalid_wp_core_id', "Unknown wp_core id: {$block['id']}" );
                            }
                        }
                    }
                }
            }
        }
    }

    if ( isset( $config['layout'] ) && ! is_array( $config['layout'] ) ) {
        return new WP_Error( 'invalid_layout', 'Layout must be an array.' );
    }

    if ( isset( $config['custom_css'] ) && ! is_string( $config['custom_css'] ) ) {
        return new WP_Error( 'invalid_custom_css', 'custom_css must be a string.' );
    }

    if ( isset( $config['custom_js'] ) && ! is_string( $config['custom_js'] ) ) {
        return new WP_Error( 'invalid_custom_js', 'custom_js must be a string.' );
    }

    return $config;
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

            // Pull lightweight widget registry from blocks plugin (if active)
            $widgets_option = get_option( 'agentshell_widgets', array() );
            $available_widgets = array();
            foreach ( $widgets_option as $w ) {
                $available_widgets[] = array(
                    'id'   => $w['id']   ?? '',
                    'name' => $w['name'] ?? '',
                );
            }

            // Core WP components — now served by the same function used at render time
            $core_components = agentshell_get_core_components();

            $schema = array(
                'zones'           => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'          => array( 'type' => 'string' ),
                            'label'       => array( 'type' => 'string' ),
                            'composition' => array( 'type' => 'array' ),
                            'slots'      => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'left'   => array( 'type' => 'array' ),
                                    'center' => array( 'type' => 'array' ),
                                    'right'  => array( 'type' => 'array' ),
                                ),
                            ),
                        ),
                    ),
                ),
                'custom_css' => array( 'type' => 'string', 'maxLength' => 10000 ),
                'custom_js'  => array( 'type' => 'string', 'maxLength' => 10000 ),
                'design'     => array( 'type' => 'object' ),
                'layout'     => array( 'type' => 'object' ),
            );

            // Ensure zones and compositions are always proper sequential arrays
            $zones = $config['zones'] ?? array();
            if ( is_array( $zones ) ) {
                $zones = array_values( $zones );
                foreach ( $zones as &$zone ) {
                    if ( isset( $zone['composition'] ) && is_array( $zone['composition'] ) ) {
                        $zone['composition'] = array_values( $zone['composition'] );
                    }
                    // Normalize slot arrays to sequential arrays
                    if ( isset( $zone['slots'] ) && is_array( $zone['slots'] ) ) {
                        foreach ( array( 'left', 'center', 'right' ) as $slot_key ) {
                            if ( isset( $zone['slots'][ $slot_key ] ) && is_array( $zone['slots'][ $slot_key ] ) ) {
                                $zone['slots'][ $slot_key ] = array_values( $zone['slots'][ $slot_key ] );
                            } else {
                                $zone['slots'][ $slot_key ] = array();
                            }
                        }
                    }
                }
                unset( $zone );
            } else {
                $zones = array();
            }

            return array(
                'schema'             => $schema,
                'defaults'           => $flat,
                'config'             => $flat,
                'zones'              => $zones,
                'available_widgets'  => $available_widgets,
                'core_components'    => $core_components,
            );
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
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
            $validated = agentshell_validate_config( $merged );
            if ( is_wp_error( $validated ) ) {
                return $validated;
            }
            update_option( 'agentshell_config', $validated );
            return agentshell_flatten_config( $validated );
        },
        'permission_callback' => 'agentshell_rest_auth_callback',
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
