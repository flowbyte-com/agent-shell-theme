<?php
/**
 * AgentShell theme functions
 */

/**
 * Register agentshell_config option on theme activation.
 *
 * Uses default-config.json ONLY as a seed to populate wp_options on first
 * install. After activation, wp_options is the sole source of truth.
 * The physical default-config.json file exists for IDE reference and
 * re-seeding, but agents always read/write via wp_options.
 */
function agentshell_theme_activation() {
    if ( get_option( 'agentshell_config' ) !== false ) {
        return; // already installed, preserve existing config
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
    // Also seed the standalone sidebar option
    add_option( 'agentshell_sidebar_enabled', ! empty( $seed['sidebar_enabled'] ) );
}
add_action( 'after_switch_theme', 'agentshell_theme_activation' );

/**
 * Enqueue theme assets
 */
function agentshell_enqueue_assets() {
    wp_enqueue_style( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.css', array(), '1.0.0' );
    wp_enqueue_script( 'agentshell-configurator', get_template_directory_uri() . '/configurator/configurator.js', array(), '1.0.0', true );
    wp_localize_script( 'agentshell-configurator', 'AgentShellConfig', array(
        'adminUrl'   => admin_url( 'admin-ajax.php' ),
        'restUrl'    => rest_url(),
        'nonce'      => wp_create_nonce( 'wp_rest' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'agentshell_enqueue_assets' );

/**
 * Register nav menu locations and widget areas.
 */
function agentshell_setup() {
    // Primary navigation location (used by header.php)
    register_nav_menus( array(
        'primary' => esc_html__( 'Primary Menu', 'agentshell' ),
    ) );

    // Primary sidebar (used by header.php #zone-sidebar)
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
add_action( 'after_setup_theme', 'agentshell_setup' );

/**
 * Get the full shell config.
 *
 * wp_options is the SOLE source of truth at runtime. The physical
 * default-config.json file is only a seed for fresh installs.
 * Agents must always read/write via this function or the REST API.
 *
 * @return array
 */
function agentshell_get_config() {
    $config = get_option( 'agentshell_config' );
    return is_array( $config ) ? $config : array();
}

/**
 * Update the shell config atomically.
 * Also syncs agentshell_sidebar_enabled as a standalone option so that
 * get_option('agentshell_sidebar_enabled') stays consistent with the
 * sidebar_enabled key inside agentshell_config.
 *
 * @param array $config
 * @return bool
 */
function agentshell_update_config( array $config ) {
    // Sync sidebar state to standalone option for direct get_option() callers
    update_option( 'agentshell_sidebar_enabled', ! empty( $config['sidebar_enabled'] ) );
    return update_option( 'agentshell_config', $config );
}

/**
 * REST API: GET/PUT /wp/v2/agentshell/config
 *             GET     /wp/v2/agentshell/guide
 */
add_action( 'rest_api_init', function() {
    // GET config — always includes _guide for agent discovery
    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'GET',
        'callback' => function() {
            $config             = agentshell_get_config();
            $config['_guide']   = agentshell_get_guide();
            return $config;
        },
        'permission_callback' => '__return_true',
    ) );

    // PUT config
    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'PUT',
        'callback' => function( WP_REST_Request $request ) {
            $config = $request->get_json_params();
            if ( ! is_array( $config ) ) {
                return new WP_Error( 'invalid_config', 'Config must be a valid JSON object', array( 'status' => 400 ) );
            }
            $updated = agentshell_update_config( $config );
            return $updated ? agentshell_get_config() : new WP_Error( 'update_failed', 'Failed to update config', array( 'status' => 500 ) );
        },
        'permission_callback' => function( WP_REST_Request $request ) {
            // Replicate what WP Application Passwords does internally:
            // decode the Basic Auth header and call wp_set_current_user().
            // This is needed because determine_current_user may not have
            // run before this callback depending on plugin load order.
            static $done = false;
            if ( ! $done ) {
                $done = true;
                $header = $request->get_header( 'authorization' );
                if ( $header && str_starts_with( strtolower( $header ), 'basic ' ) ) {
                    $creds = base64_decode( substr( $header, 6 ), true );
                    if ( $creds && str_contains( $creds, ':' ) ) {
                        $parts    = explode( ':', $creds, 2 );
                        $username = trim( $parts[0] );
                        $user     = get_user_by( 'slug', $username )
                                 ?: get_user_by( 'login', $username )
                                 ?: get_user_by( 'email', $username );
                        if ( $user && WP_Application_Passwords::check_app_password( $user->ID, $parts[1] ) ) {
                            wp_set_current_user( $user->ID );
                        }
                    }
                }
            }
            return get_current_user_id() > 0 && current_user_can( 'edit_theme_options' );
        },
    ) );

    // GET guide — AGENTS.md content as plain text for agent discovery
    register_rest_route( 'wp/v2', '/agentshell/guide', array(
        'methods'  => 'GET',
        'callback' => function() {
            return new WP_REST_Response( agentshell_get_guide(), 200 );
        },
        'permission_callback' => '__return_true',
    ) );
} );

/**
 * Read AGENTS.md content for REST API serving.
 * Agents get this automatically in GET /wp/v2/agentshell/config._guide
 * or explicitly at GET /wp/v2/agentshell/guide
 *
 * @return string
 */
function agentshell_get_guide() {
    $file = get_template_directory() . '/AGENTS.md';
    if ( file_exists( $file ) ) {
        return file_get_contents( $file );
    }
    return '';
}
