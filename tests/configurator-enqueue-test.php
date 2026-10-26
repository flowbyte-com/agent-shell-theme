<?php
/**
 * The configurator's authorisation boundary and its asset footprint.
 *
 * The redesign kept the floating trigger as the entry point, and the trigger
 * being visible to administrators is not an authorisation boundary by itself.
 * Three things have to hold, and this suite is where they are stated:
 *
 *   1. An ordinary visitor is sent no configurator asset at all. Not a
 *      hidden panel, not a script that loads and finds nothing to do: no
 *      bytes.
 *   2. A subscriber is no more privileged than a visitor. The capability
 *      that matters is `edit_theme_options`, because that is what the REST
 *      permission callback checks.
 *   3. The markup that opens the editor — the panel and the trigger — is
 *      printed only for a user who holds that capability.
 *
 * The suite also checks that every file the theme enqueues actually exists:
 * the release archive once shipped a configurator trigger with no panel
 * behind it, and a missing file is invisible until a browser asks for it.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
        $GLOBALS['_agentshell_test_scripts'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'footer' => $in_footer );
    }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
        $GLOBALS['_agentshell_test_styles'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
    }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $name, $data ) {
        $GLOBALS['_agentshell_test_localized'][ $handle ] = array( 'name' => $name, 'data' => $data );
    }
}
if ( ! function_exists( 'wp_add_inline_script' ) ) {
    function wp_add_inline_script( $handle, $data, $position = 'after' ) {
        $GLOBALS['_agentshell_test_inline'][ $handle ][] = $data;
        return true;
    }
}
if ( ! function_exists( 'wp_deregister_style' ) ) {
    function wp_deregister_style( $handle ) { return true; }
}
if ( ! function_exists( 'wp_deregister_script' ) ) {
    function wp_deregister_script( $handle ) { return true; }
}
if ( ! function_exists( 'wp_dequeue_style' ) ) {
    function wp_dequeue_style( $handle ) { return true; }
}
if ( ! function_exists( 'wp_dequeue_script' ) ) {
    function wp_dequeue_script( $handle ) { return true; }
}
if ( ! function_exists( 'wp_footer' ) ) {
    function wp_footer() {}
}
if ( ! function_exists( 'get_template_directory' ) ) {
    function get_template_directory() { return dirname( __DIR__ ); }
}
if ( ! function_exists( 'get_template_directory_uri' ) ) {
    function get_template_directory_uri() { return 'https://example.test/wp-content/themes/agentshell'; }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
}
if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . $path; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) { return 'nonce-' . md5( (string) $action ); }
}

require_once dirname( __DIR__ ) . '/functions.php';

/**
 * Run the theme's enqueue callback as a given user, and report what it sent.
 *
 * @param int    $user_id  0 for a visitor.
 * @param array  $caps     the capabilities the user holds.
 * @return array{scripts:array,styles:array,localized:array,inline:array}
 */
function agentshell_configurator_enqueue_as( $user_id, array $caps ) {
    $GLOBALS['_agentshell_test_scripts']    = array();
    $GLOBALS['_agentshell_test_styles']     = array();
    $GLOBALS['_agentshell_test_localized']  = array();
    $GLOBALS['_agentshell_test_inline']     = array();

    agentshell_test_reset();
    wp_set_current_user( $user_id );
    $GLOBALS['_agentshell_test_user_caps'] = $caps;

    agentshell_enqueue_assets();

    return array(
        'scripts'   => $GLOBALS['_agentshell_test_scripts'],
        'styles'    => $GLOBALS['_agentshell_test_styles'],
        'localized' => $GLOBALS['_agentshell_test_localized'],
        'inline'    => $GLOBALS['_agentshell_test_inline'],
    );
}

function agentshell_configurator_script_handles( array $result ) {
    return array_values( array_filter(
        array_keys( $result['scripts'] ),
        function ( $handle ) { return 0 === strpos( $handle, 'agentshell-configurator' ); }
    ) );
}

/**
 * The configurator's own stylesheet handles.
 *
 * The theme's own stylesheet is enqueued for everybody — that is the site,
 * not the editor — so only the handles that belong to the editor are
 * reported here.
 */
function agentshell_configurator_style_handles( array $result ) {
    return array_values( array_filter(
        array_keys( $result['styles'] ),
        function ( $handle ) { return 0 === strpos( $handle, 'agentshell-configurator' ); }
    ) );
}

Agentshell_Test::run(
    'an ordinary visitor is sent no configurator asset',
    function () {
        $result = agentshell_configurator_enqueue_as( 0, array() );
        Agentshell_Test::assert_equals(
            array(),
            agentshell_configurator_script_handles( $result ),
            'a visitor must not be sent the editor\'s scripts'
        );
        Agentshell_Test::assert_equals(
            array(),
            agentshell_configurator_style_handles( $result ),
            'a visitor must not be sent the editor\'s stylesheet'
        );
    }
);

Agentshell_Test::run(
    'a subscriber is no more privileged than a visitor',
    function () {
        $result = agentshell_configurator_enqueue_as(
            42,
            array( 'read' => true, 'edit_posts' => true, 'edit_theme_options' => false )
        );
        Agentshell_Test::assert_equals(
            array(),
            agentshell_configurator_script_handles( $result ),
            'a subscriber must not be sent the editor\'s scripts'
        );
        Agentshell_Test::assert_equals(
            array(),
            agentshell_configurator_style_handles( $result ),
            'a subscriber must not be sent the editor\'s stylesheet'
        );
    }
);

Agentshell_Test::run(
    'an administrator is sent the editor, as four modules and a stylesheet',
    function () {
        $result = agentshell_configurator_enqueue_as(
            7,
            array( 'edit_theme_options' => true, 'manage_options' => true )
        );
        $handles = agentshell_configurator_script_handles( $result );
        sort( $handles );
        Agentshell_Test::assert_equals(
            array(
                'agentshell-configurator',
                'agentshell-configurator-api',
                'agentshell-configurator-live-style',
                'agentshell-configurator-picker',
                'agentshell-configurator-state',
                'agentshell-configurator-ui',
            ),
            $handles,
            'the editor is six small modules, and every one is enqueued'
        );
        Agentshell_Test::assert_true(
            isset( $result['styles']['agentshell-configurator'] ),
            'the editor\'s stylesheet is enqueued'
        );
        Agentshell_Test::assert_true(
            isset( $result['localized']['agentshell-configurator'] ),
            'the editor is given its REST settings'
        );
    }
);

Agentshell_Test::run(
    'every file the editor enqueues exists in the theme',
    function () {
        $result = agentshell_configurator_enqueue_as(
            7,
            array( 'edit_theme_options' => true )
        );
        $root = dirname( __DIR__ );
        foreach ( array_merge( $result['scripts'], $result['styles'] ) as $handle => $asset ) {
            if ( 0 !== strpos( $handle, 'agentshell-configurator' ) ) {
                continue;
            }
            // The URL is whatever the environment's template directory
            // reports; what matters is the path *inside* the theme, and
            // that the file is really there.
            $relative = agentshell_configurator_path_in_theme( $asset['src'], $root );
            Agentshell_Test::assert_true(
                '' !== $relative && is_file( $root . '/' . $relative ),
                'the theme enqueues ' . $handle . ' from a file that exists (' . $relative . ')'
            );
        }
    }
);

Agentshell_Test::run(
    'the editor is given a REST url and a nonce, and no credential',
    function () {
        $result = agentshell_configurator_enqueue_as(
            7,
            array( 'edit_theme_options' => true )
        );
        $data = $result['localized']['agentshell-configurator']['data'];
        Agentshell_Test::assert_true(
            isset( $data['restUrl'] ) && '' !== $data['restUrl'],
            'the editor is told where the REST API is'
        );
        Agentshell_Test::assert_true(
            isset( $data['nonce'] ) && '' !== $data['nonce'],
            'the editor is given the REST nonce'
        );
        // A credential in the page would be a credential in every cached copy
        // of every page an administrator visits.
        $serialised = strtolower( wp_json_encode( $data ) );
        foreach ( array( 'password', 'app_password', 'application_password', 'secret', 'api_key', 'apikey', 'bearer', 'authorization' ) as $forbidden ) {
            Agentshell_Test::assert_true(
                false === strpos( $serialised, $forbidden ),
                'the localised configuration carries no "' . $forbidden . '"'
            );
        }
    }
);

Agentshell_Test::run(
    'the editor is started from an inline script',
    function () {
        $result = agentshell_configurator_enqueue_as(
            7,
            array( 'edit_theme_options' => true )
        );
        $inline = implode( "\n", $result['inline']['agentshell-configurator'] );
        Agentshell_Test::assert_true(
            false !== strpos( $inline, 'AgentshellConfiguratorApp.boot' ),
            'the editor is started, not merely enqueued'
        );
    }
);

// ── the markup that opens the editor ──────────────────────────────────────

/**
 * The path inside the theme that an enqueued asset points at.
 *
 * The URL is environment-specific — a site URL in production, a file:// URL
 * in a test — so the path is taken from the tail of it: everything after
 * the theme's own directory name.
 *
 * @param string $url
 * @param string $root The theme directory.
 * @return string
 */
function agentshell_configurator_path_in_theme( $url, $root ) {
    $needle = basename( $root ) . '/';
    $at     = strrpos( $url, $needle );
    if ( false !== $at ) {
        return substr( $url, $at + strlen( $needle ) );
    }
    return ltrim( (string) preg_replace( '#^[a-z]+://#', '', $url ), '/' );
}

/**
 * Render the footer as a given user and report what it printed.
 *
 * @param int   $user_id
 * @param array $caps
 * @return string
 */
function agentshell_configurator_render_footer_as( $user_id, array $caps ) {
    agentshell_test_reset();
    wp_set_current_user( $user_id );
    $GLOBALS['_agentshell_test_user_caps'] = $caps;

    ob_start();
    include dirname( __DIR__ ) . '/footer.php';
    return (string) ob_get_clean();
}

Agentshell_Test::run(
    'the panel and the trigger are printed only for a user who may edit the theme',
    function () {
        $visitor = agentshell_configurator_render_footer_as( 0, array() );
        Agentshell_Test::assert_true(
            false === strpos( $visitor, 'agentshell-config-trigger' ),
            'a visitor is not given the trigger'
        );
        Agentshell_Test::assert_true(
            false === strpos( $visitor, 'agentshell-config-panel' ),
            'a visitor is not given the panel'
        );

        $subscriber = agentshell_configurator_render_footer_as(
            42,
            array( 'read' => true, 'edit_theme_options' => false )
        );
        Agentshell_Test::assert_true(
            false === strpos( $subscriber, 'agentshell-config-trigger' ),
            'a subscriber is not given the trigger'
        );

        $administrator = agentshell_configurator_render_footer_as(
            7,
            array( 'edit_theme_options' => true )
        );
        Agentshell_Test::assert_true(
            false !== strpos( $administrator, 'id="agentshell-config-trigger"' ),
            'an administrator is given the trigger'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $administrator, 'id="agentshell-config-panel"' ),
            'an administrator is given the panel'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $administrator, 'aria-label="Open theme configurator"' ),
            'the trigger carries an accessible name'
        );
    }
);

Agentshell_Test::run(
    'the trigger is a real button, so it is reachable by keyboard',
    function () {
        $administrator = agentshell_configurator_render_footer_as(
            7,
            array( 'edit_theme_options' => true )
        );
        Agentshell_Test::assert_true(
            false !== strpos( $administrator, '<button id="agentshell-config-trigger"' ),
            'the trigger is a button element, not a div'
        );
    }
);
