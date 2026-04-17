<?php
/**
 * AgentShell theme functions
 */
// Disable automatic paragraph tags for agent payloads
remove_filter( 'the_content', 'wpautop' );
remove_filter( 'the_excerpt', 'wpautop' );
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
        // Basic Auth credentials for the configurator
        // In production, use Application Passwords from WP User profile
        'authUser'   => defined( 'AGENTSHELL_AUTH_USER' ) ? AGENTSHELL_AUTH_USER : 'v',
        'authPass'   => defined( 'AGENTSHELL_AUTH_PASS' ) ? AGENTSHELL_AUTH_PASS : '',
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
    if ( ! empty( $config['custom_css'] ) ) {
        echo "<style id='agentshell-custom-css'>\n" . wp_strip_all_tags( $config['custom_css'] ) . "\n</style>\n";
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
}
// Priority 100 ensures this prints AFTER style.css
add_action( 'wp_head', 'agentshell_inject_saved_styles', 100 );


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
    $config = agentshell_get_config();
    $zones  = $config['zones'] ?? array();
    if ( empty( $zones ) ) {
        return array(
            array( 'id' => 'header',  'label' => 'Header',  'source' => 'wp_loop' ),
            array( 'id' => 'main',    'label' => 'Main',    'source' => 'wp_loop' ),
            array( 'id' => 'sidebar', 'label' => 'Sidebar', 'source' => 'wp_widget_area', 'widget_area_id' => 'primary-sidebar' ),
            array( 'id' => 'footer',  'label' => 'Footer',  'source' => 'wp_loop' ),
        );
    }
    return $zones;
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
            $updated  = agentshell_update_config( $merged );
            return $updated
                ? agentshell_flatten_config( $merged )
                : new WP_Error( 'update_failed', 'Failed to update config', array( 'status' => 500 ) );
        },
        'permission_callback' => '__return_true',
    ) );
} );
