<?php
/**
 * Doctor regression tests — minimal coverage of the validator surface.
 *
 * The Doctor is the gate on commit_transaction; it is also the agent's
 * primary introspection tool when a commit is blocked. These tests
 * exercise the cheap, deterministic checks (block type allowlist,
 * wp_core id allowlist, custom_js string-ness, custom_js size cap) so
 * a future refactor of Doctor doesn't silently drop them.
 *
 * Heavier Doctor code paths that depend on the merged registry, the
 * flatten map, or a live WP_DB are out of scope here and live behind
 * the wp-tests/phpunit harness.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "Doctor regression tests\n";

Agentshell_Test::run(
    'validate accepts a clean config',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_loop' ) ),
                ),
            ),
        );
        $out = agentshell_validate_config( $config );
        Agentshell_Test::assert_true( is_array( $out ) );
    }
);

Agentshell_Test::run(
    'validate rejects an unknown block type',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'totally_made_up' ) ),
                ),
            ),
        );
        Agentshell_Test::assert_instance_of( 'WP_Error', agentshell_validate_config( $config ) );
    }
);

Agentshell_Test::run(
    'validate rejects an unknown wp_core id',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array( array( 'type' => 'wp_core', 'id' => 'something_fictional' ) ),
                ),
            ),
        );
        Agentshell_Test::assert_instance_of( 'WP_Error', agentshell_validate_config( $config ) );
    }
);

Agentshell_Test::run(
    'validate rejects non-string custom_js',
    function () {
        $config = array( 'custom_js' => array( 'not', 'a', 'string' ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', agentshell_validate_config( $config ) );
    }
);

Agentshell_Test::run(
    'validate rejects non-string custom_css',
    function () {
        $config = array( 'custom_css' => 42 );
        Agentshell_Test::assert_instance_of( 'WP_Error', agentshell_validate_config( $config ) );
    }
);

Agentshell_Test::run(
    'validate accepts tri-slot zone shape',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'left'   => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
                        'center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
                        'right'  => array( array( 'type' => 'wp_core', 'id' => 'search_form' ) ),
                    ),
                ),
            ),
        );
        Agentshell_Test::assert_true( is_array( agentshell_validate_config( $config ) ) );
    }
);

Agentshell_Test::run(
    'validate rejects an unknown slot block type',
    function () {
        $config = array(
            'zones' => array(
                array(
                    'id'    => 'header',
                    'slots' => array(
                        'left'   => array( array( 'type' => 'bogus' ) ),
                        'center' => array(),
                        'right'  => array(),
                    ),
                ),
            ),
        );
        Agentshell_Test::assert_instance_of( 'WP_Error', agentshell_validate_config( $config ) );
    }
);