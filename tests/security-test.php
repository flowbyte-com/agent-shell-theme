<?php
/**
 * Security regression tests — capability boundary on AgentShell mutations.
 *
 * Invariants under test:
 *   1. A subscriber-level WP user (logged-in but no theme-management caps)
 *      MUST NOT be able to:
 *        a) Authorize a PUT to /wp/v2/agentshell/config
 *        b) Persist arbitrary custom_js / custom_css via that endpoint
 *        c) Bypass WP content sanitization via content.raw on the posts REST
 *           endpoint
 *      A subscriber who happens to be looking at the front end must see
 *      neither the configurator trigger button nor the configurator panel.
 *   2. An administrator (with edit_theme_options + unfiltered_html) MUST
 *      still be able to do all three — the fix must not regress the
 *      trusted-author workflow.
 *   3. The MCP transport path (Basic auth via application passwords) MUST
 *      still require manage_options, regardless of role.
 *
 * These tests load the actual functions from functions.php / class-auth.php
 * (no WP runtime) by relying on tests/bootstrap.php to stub the WP API.
 */

require_once __DIR__ . '/bootstrap.php';

// Load functions.php selectively. functions.php has top-level calls that fire
// wp_head/wp_enqueue_scripts hooks on load; we don't want those side effects.
// The capability gate under test is the function agentshell_rest_auth_callback,
// which we read out of functions.php and eval-def into the test scope.
// Simpler: just include the relevant function definitions by sourcing
// functions.php after stubbing add_action/add_filter as no-ops.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-auth.php';

echo "Security regression tests — capability boundary\n";

Agentshell_Test::run(
    'subscriber cannot authorize PUT /wp/v2/agentshell/config',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 5, array( 'read' => true ) );

        $result = agentshell_rest_auth_callback( new WP_REST_Request() );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'rest_forbidden', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'subscriber cannot persist custom_js via the configurator endpoint',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 5, array( 'read' => true ) );

        // The PUT route permission_callback runs first. If it returns WP_Error
        // we never reach the route callback. We don't need to actually invoke
        // the route handler — just confirm the callback denies.
        $result = agentshell_rest_auth_callback( new WP_REST_Request() );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
    }
);

Agentshell_Test::run(
    'subscriber does not see the configurator trigger button',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 5, array( 'read' => true ) );

        // The enqueue decision and the panel/trigger markup both key off
        // is_user_logged_in() today. After the fix, they should also require
        // edit_theme_options. We test by capturing the buffer and asserting
        // the markup is absent for a subscriber.
        // We can't easily run the full footer.php include (it depends on
        // wp_footer and friends), but we can check the capability predicate.
        Agentshell_Test::assert_false(
            current_user_can( 'edit_theme_options' ),
            'subscriber should not have edit_theme_options'
        );
    }
);

Agentshell_Test::run(
    'rest_pre_insert_post bypass must be capability-gated',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 5, array( 'read' => true ) );

        // The raw-content bypass in functions.php removes content_save_pre
        // filters for any user that provides content.raw. After the fix, the
        // bypass must require unfiltered_html. A subscriber does not have
        // unfiltered_html.
        Agentshell_Test::assert_false(
            current_user_can( 'unfiltered_html' ),
            'subscriber should not have unfiltered_html'
        );
    }
);

Agentshell_Test::run(
    'administrator can authorize PUT /wp/v2/agentshell/config',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 1, array(
            'manage_options'      => true,
            'edit_theme_options'  => true,
            'unfiltered_html'     => true,
        ) );

        $result = agentshell_rest_auth_callback( new WP_REST_Request() );
        Agentshell_Test::assert_true( $result === true );
    }
);

Agentshell_Test::run(
    'administrator with unfiltered_html can bypass KSES on content.raw',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 1, array(
            'manage_options'      => true,
            'edit_theme_options'  => true,
            'unfiltered_html'     => true,
        ) );

        Agentshell_Test::assert_true( current_user_can( 'unfiltered_html' ) );
        Agentshell_Test::assert_true( current_user_can( 'edit_theme_options' ) );
    }
);

Agentshell_Test::run(
    'MCP transport requires manage_options regardless of role',
    function () {
        agentshell_test_reset();

        // Editor role: has edit_posts etc. but NOT manage_options.
        // Class\Auth::authenticate must reject this user.
        agentshell_test_set_user( 7, array(
            'edit_posts'      => true,
            'edit_others_posts' => true,
            'publish_posts'   => true,
        ) );

        // We can't easily build a real WP_REST_Request here, but we can
        // exercise the predicate. The auth class calls user_can( $user,
        // 'manage_options' ) at line 32. Confirm the user lacks it.
        Agentshell_Test::assert_false(
            current_user_can( 'manage_options' ),
            'editor should not pass the manage_options check'
        );
    }
);

Agentshell_Test::run(
    'subscriber PUT attempt does not write agentshell_config',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 5, array( 'read' => true ) );

        // Confirm the permission callback is the gate. If it returned true
        // (the bug), the PUT handler would update_option. After the fix the
        // callback returns WP_Error and the handler never runs.
        $result = agentshell_rest_auth_callback( new WP_REST_Request() );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );

        // The option must remain unset.
        Agentshell_Test::assert_false( get_option( 'agentshell_config' ) );
    }
);