<?php
/**
 * AgentShell theme functions
 */

/**
 * Register agentshell_config option on theme activation
 */
function agentshell_theme_activation() {
    $default_config = json_decode( file_get_contents( get_template_directory() . '/shell-config.json' ), true );
    if ( get_option( 'agentshell_config' ) === false ) {
        add_option( 'agentshell_config', $default_config );
    }
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
 * Register primary sidebar widget area
 */
function agentshell_widgets_init() {
    register_sidebar( array(
        'name'          => 'Primary Sidebar',
        'id'            => 'primary-sidebar',
        'description'   => 'Widgets for the primary sidebar zone',
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ) );
}
add_action( 'widgets_init', 'agentshell_widgets_init' );

/**
 * Get the full shell config
 * @return array
 */
function agentshell_get_config() {
    $config = get_option( 'agentshell_config' );
    if ( ! $config || is_wp_error( $config ) ) {
        $config = json_decode( file_get_contents( get_template_directory() . '/shell-config.json' ), true );
    }
    return $config;
}

/**
 * Update the shell config atomically
 * @param array $config
 * @return bool
 */
function agentshell_update_config( array $config ) {
    return update_option( 'agentshell_config', $config );
}

/**
 * REST API: PUT /wp/v1/agentshell/config
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wp/v2', '/agentshell/config', array(
        'methods'  => 'GET',
        'callback' => function() {
            return agentshell_get_config();
        },
        'permission_callback' => '__return_true',
    ) );

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
        'permission_callback' => function() {
            return current_user_can( 'edit_theme_options' );
        },
    ) );
} );
