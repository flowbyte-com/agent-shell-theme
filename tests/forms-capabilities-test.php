<?php
/**
 * The forms capability model, against a real WordPress.
 *
 * Two claims are being tested, and both would be invisible in a stub:
 *
 *   1. **An administrator is not automatically a submission reader.** The MCP
 *      transport requires `manage_options` for every call, so every agent is an
 *      administrator. If the submissions capability were granted to the
 *      administrator role, every agent would hold it and the separation between
 *      "may edit a form" and "may read what people wrote into it" would
 *      collapse. Activation therefore grants `manage_agentshell_forms` to the
 *      administrator role and `manage_agentshell_forms_submissions` to no role
 *      at all.
 *
 *   2. **A grant is per user, and changes no role.** `WP_User::add_cap()`
 *      writes the user's own capability set, so a second user of the same role
 *      must still be refused, and every role's capability set must be
 *      byte-identical before and after.
 *
 * The grant and revocation handlers are tested by running them for real — with
 * a real cookie session, a real nonce, and a case with no cookie — because
 * their refusals are the whole point and they end the request when they act.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms capabilities (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'forms capability suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

/**
 * Run one child to completion and decode its report.
 *
 * @param array  $config Test-bed config.
 * @param string $script Child script.
 * @param array  $argv   Arguments after wp_root.
 * @return array
 */
function fcap_run( array $config, $script, array $argv ) {
    $report = rtrim( sys_get_temp_dir(), '/' ) . '/as-fcap-' . getmypid() . '-' . mt_rand( 1000, 999999 ) . '.json';
    @unlink( $report );

    // The child's layout is: wp_root, mode, report, then the mode's own
    // arguments. The report path is inserted by the runner, not by the caller.
    $mode = (string) array_shift( $argv );
    $full = array_merge( array( (string) $config['wp_root'], $mode, $report ), $argv );

    $cmd = '';
    foreach ( $config['env'] as $k => $v ) {
        $cmd .= $k . '=' . escapeshellarg( (string) $v ) . ' ';
    }
    $cmd .= 'AGENTSHELL_TEST_WP_ROOT=' . escapeshellarg( (string) $config['wp_root'] ) . ' ';
    if ( is_array( $config['cfg'] ) && isset( $config['cfg']['_config_path'] ) ) {
        $cmd .= 'AGENTSHELL_TEST_BED_CONFIG=' . escapeshellarg( (string) $config['cfg']['_config_path'] ) . ' ';
    }
    $cmd .= escapeshellarg( (string) $config['php'] );
    foreach ( $config['ini'] as $arg ) {
        $cmd .= ' -d ' . escapeshellarg( (string) $arg );
    }
    $cmd .= ' ' . escapeshellarg( $script );
    foreach ( $full as $arg ) {
        $cmd .= ' ' . escapeshellarg( (string) $arg );
    }
    $cmd .= ' 2>&1';

    exec( $cmd, $out, $status );

    $decoded = json_decode( (string) @file_get_contents( $report ), true );
    @unlink( $report );

    return is_array( $decoded ) ? $decoded : array( 'error' => 'no_report', 'output' => implode( "\n", $out ) );
}

$child  = __DIR__ . '/forms-capabilities-child.php';
$report = fcap_run( $config, $child, array( 'report' ) );

if ( isset( $report['error'] ) ) {
    Agentshell_Test::run(
        'forms capabilities: the child reported',
        function () use ( $report ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . wp_json_encode( $report ) );
        }
    );
    return;
}

/* ---------------------------------------------------------------------------
 * The role state
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms capabilities: the administrator may manage forms but is not a submission reader',
    function () use ( $report ) {
        $role = isset( $report['role'] ) && is_array( $report['role'] ) ? $report['role'] : array();

        Agentshell_Test::assert_equals(
            'manage_agentshell_forms',
            $role['form_capability'] ?? null,
            'the forms capability must be the documented name'
        );
        Agentshell_Test::assert_equals(
            'manage_agentshell_forms_submissions',
            $role['submissions_capability'] ?? null,
            'the submissions capability must be the documented name'
        );

        Agentshell_Test::assert_true(
            true === ( $role['administrator_has_forms'] ?? false ),
            'activation must grant form management to the administrator role'
        );
        Agentshell_Test::assert_true(
            false === ( $role['administrator_has_submissions'] ?? true ),
            'activation must NOT grant submission access to the administrator role'
        );
        Agentshell_Test::assert_false(
            (bool) ( $role['subscriber_has_forms'] ?? true ),
            'the subscriber role must not be able to manage forms'
        );
        Agentshell_Test::assert_false(
            (bool) ( $role['subscriber_has_submissions'] ?? true ),
            'the subscriber role must not have submission access'
        );

        // The same user, checked through the plugin's own predicate: holding
        // `manage_options` must not be accepted as a substitute.
        $admin = isset( $report['admin'] ) && is_array( $report['admin'] ) ? $report['admin'] : array();
        Agentshell_Test::assert_true( true === ( $admin['has_manage_options'] ?? false ), 'the probe administrator must hold manage_options' );
        Agentshell_Test::assert_true( true === ( $admin['can_manage_forms'] ?? false ), 'an administrator must be able to manage forms' );
        Agentshell_Test::assert_true(
            false === ( $admin['can_access_submissions'] ?? true ),
            'an administrator must not reach submission contents by virtue of being an administrator'
        );
    }
);

Agentshell_Test::run(
    'forms capabilities: the plugin capabilities stay plain primitive capabilities',
    function () use ( $report ) {
        $admin = isset( $report['admin'] ) && is_array( $report['admin'] ) ? $report['admin'] : array();

        // A regression that is invisible until measured: registering the post
        // type with all three meta capabilities mapped onto the plugin
        // capability puts that capability in WordPress's global meta-capability
        // table, after which a bare check of it resolves to `do_not_allow`.
        // The held capability must map to itself.
        Agentshell_Test::assert_equals(
            array( 'manage_agentshell_forms' ),
            array_values( (array) ( $admin['map_meta_cap_forms'] ?? array() ) ),
            'manage_agentshell_forms must resolve to itself, not to a meta capability'
        );
        Agentshell_Test::assert_equals(
            array( 'manage_agentshell_forms_submissions' ),
            array_values( (array) ( $admin['map_meta_cap_submissions'] ?? array() ) ),
            'manage_agentshell_forms_submissions must resolve to itself'
        );

        // And the per-post model still works: the singular meta capabilities
        // resolve to the one primitive the role actually holds.
        $caps = isset( $admin['post_type_caps_forms'] ) && is_array( $admin['post_type_caps_forms'] )
            ? $admin['post_type_caps_forms']
            : array();
        Agentshell_Test::assert_equals( 'manage_agentshell_forms', $caps['edit_posts'] ?? null, 'the primitive edit capability must be the plugin one' );
        Agentshell_Test::assert_equals( 'manage_agentshell_forms', $caps['read'] ?? null, 'the read capability must be the plugin one, not the generic read' );
        Agentshell_Test::assert_equals( 'manage_agentshell_forms', $caps['publish'] ?? null, 'the publish capability must be the plugin one' );
        Agentshell_Test::assert_equals( 'edit_agentshell_form', $caps['edit_post'] ?? null, 'the singular meta capability must keep its own name' );

        $checks = isset( $admin['per_post_checks'] ) && is_array( $admin['per_post_checks'] ) ? $admin['per_post_checks'] : array();
        Agentshell_Test::assert_false( isset( $checks['error'] ), 'the per-post probe must have created a form' );
        Agentshell_Test::assert_true( true === ( $checks['edit_post'] ?? false ), 'current_user_can(edit_post, $form) must be true for an administrator' );
        Agentshell_Test::assert_true( true === ( $checks['delete_post'] ?? false ), 'current_user_can(delete_post, $form) must be true' );
        Agentshell_Test::assert_true( true === ( $checks['read_post'] ?? false ), 'current_user_can(read_post, $form) must be true' );
        Agentshell_Test::assert_true(
            true === ( $checks['edit_agentshell_form'] ?? false ),
            'current_user_can(edit_agentshell_form, $form) must resolve through the meta capability to the plugin one'
        );
    }
);

/* ---------------------------------------------------------------------------
 * Per-user grant and revocation
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms capabilities: a per-user grant gives that user access and no other',
    function () use ( $report ) {
        $before = isset( $report['before'] ) && is_array( $report['before'] ) ? $report['before'] : array();
        $after  = isset( $report['after_grant'] ) && is_array( $report['after_grant'] ) ? $report['after_grant'] : array();

        Agentshell_Test::assert_true( false === ( $before['a_can'] ?? true ), 'the subject must not have access before the grant' );
        Agentshell_Test::assert_true( false === ( $before['b_can'] ?? true ), 'the peer must not have access before the grant' );

        Agentshell_Test::assert_true( true === ( $after['granted'] ?? false ), 'the grant must report success' );
        Agentshell_Test::assert_true( true === ( $after['a_can'] ?? false ), 'the granted user must have access' );
        Agentshell_Test::assert_true( false === ( $after['b_can'] ?? true ), 'a second user of the same role must NOT gain access' );
        Agentshell_Test::assert_true( false === ( $after['subscriber_role_has'] ?? true ), 'the role itself must not gain the capability' );
        Agentshell_Test::assert_true( '' !== (string) ( $after['granted_at'] ?? '' ), 'the grant must be timestamped' );
        Agentshell_Test::assert_equals( '', (string) ( $after['granted_at_b'] ?? 'x' ), 'the peer must carry no grant record' );

        Agentshell_Test::assert_true(
            in_array( (int) ( $report['users']['a'] ?? 0 ), array_map( 'intval', (array) ( $after['users_with_access'] ?? array() ) ), true ),
            'the granted user must be listed as having access'
        );
        Agentshell_Test::assert_false(
            in_array( (int) ( $report['users']['b'] ?? 0 ), array_map( 'intval', (array) ( $after['users_with_access'] ?? array() ) ), true ),
            'the peer must not be listed as having access'
        );
        Agentshell_Test::assert_false(
            in_array( (int) ( $report['admin_id'] ?? 0 ), array_map( 'intval', (array) ( $after['users_with_access'] ?? array() ) ), true ),
            'the administrator must not be listed as having access'
        );

        // The capability is on the user record, which is what makes it per-user.
        Agentshell_Test::assert_true( true === ( $report['user_caps_a_is_array'] ?? false ), 'the user capability set must be a real capability map' );
        Agentshell_Test::assert_true(
            true === ( ( $report['user_caps_a']['manage_agentshell_forms_submissions'] ?? false ) === true ),
            'the capability must be written on the user’s own record'
        );

        Agentshell_Test::assert_true(
            true === ( $after['roles_unchanged_after_grant'] ?? false ),
            'a grant must not modify any role'
        );
    }
);

Agentshell_Test::run(
    'forms capabilities: revocation removes that user’s access and nothing else',
    function () use ( $report ) {
        $after = isset( $report['after_revoke'] ) && is_array( $report['after_revoke'] ) ? $report['after_revoke'] : array();

        Agentshell_Test::assert_true( true === ( $after['revoked'] ?? false ), 'the revocation must report success' );
        Agentshell_Test::assert_true( false === ( $after['a_can'] ?? true ), 'the revoked user must no longer have access' );
        Agentshell_Test::assert_equals( '', (string) ( $after['granted_at'] ?? 'x' ), 'the grant record must be removed' );
        Agentshell_Test::assert_false(
            in_array( (int) ( $report['users']['a'] ?? 0 ), array_map( 'intval', (array) ( $after['users_with_access'] ?? array() ) ), true ),
            'the revoked user must no longer be listed'
        );
        Agentshell_Test::assert_true(
            true === ( $after['roles_unchanged_after_revoke'] ?? false ),
            'a revocation must not modify any role'
        );
    }
);

Agentshell_Test::run(
    'forms capabilities: form management is its own capability, not manage_options',
    function () use ( $report ) {
        $grant  = isset( $report['forms_cap_grant'] ) && is_array( $report['forms_cap_grant'] ) ? $report['forms_cap_grant'] : array();
        $revoke = isset( $report['forms_cap_revoke'] ) && is_array( $report['forms_cap_revoke'] ) ? $report['forms_cap_revoke'] : array();

        Agentshell_Test::assert_false( (bool) ( $report['before']['c_forms'] ?? true ), 'a subscriber must not manage forms' );
        Agentshell_Test::assert_true( true === ( $grant['after'] ?? false ), 'granting the forms capability must enable form management' );
        Agentshell_Test::assert_true( true === ( $grant['roles_unchanged'] ?? false ), 'granting a user a capability must not modify a role' );
        Agentshell_Test::assert_false( (bool) ( $revoke['after'] ?? true ), 'removing the forms capability must disable form management' );
    }
);

/* ---------------------------------------------------------------------------
 * The audit trail
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms capabilities: grants and revocations are audited',
    function () use ( $report ) {
        $events = isset( $report['audit_events'] ) && is_array( $report['audit_events'] ) ? $report['audit_events'] : array();

        Agentshell_Test::assert_true( in_array( 'form_submission_access_granted', $events, true ), 'a grant must be recorded' );
        Agentshell_Test::assert_true( in_array( 'form_submission_access_revoked', $events, true ), 'a revocation must be recorded' );
    }
);

/* ---------------------------------------------------------------------------
 * The handler gate — three conditions, tested by running the real callbacks
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms capabilities: without a cookie session an application-password principal cannot grant access',
    function () use ( $config, $child ) {
        $r = fcap_run( $config, $child, array( 'handler', 'grant_no_cookie' ) );

        Agentshell_Test::assert_false( isset( $r['error'] ), 'the handler probe must report: ' . wp_json_encode( $r ) );
        Agentshell_Test::assert_true( true === ( $r['died'] ?? false ), 'the request must be refused, not performed' );
        Agentshell_Test::assert_equals( 403, (int) ( $r['died_response'] ?? 0 ), 'the refusal must be a 403' );
        Agentshell_Test::assert_equals( '', (string) ( $r['redirect'] ?? 'x' ), 'a refused request must not redirect as though it succeeded' );
        Agentshell_Test::assert_false( (bool) ( $r['admin_has_cap'] ?? true ), 'the administrator must not have gained submission access' );
        Agentshell_Test::assert_true(
            in_array( 'form_submission_access_refused', (array) ( $r['audit_events'] ?? array() ), true ),
            'a refused grant must be audited, so it is distinguishable from one that never happened'
        );
        Agentshell_Test::assert_false(
            in_array( 'form_submission_access_granted', (array) ( $r['audit_events'] ?? array() ), true ),
            'no grant may be recorded for a refused request'
        );
    }
);

Agentshell_Test::run(
    'forms capabilities: a bad nonce is refused even with a valid cookie session',
    function () use ( $config, $child ) {
        $r = fcap_run( $config, $child, array( 'handler', 'grant_bad_nonce' ) );

        Agentshell_Test::assert_false( isset( $r['error'] ), 'the handler probe must report: ' . wp_json_encode( $r ) );
        Agentshell_Test::assert_true( true === ( $r['died'] ?? false ), 'a bad nonce must stop the request' );
        Agentshell_Test::assert_false( (bool) ( $r['admin_has_cap'] ?? true ), 'the administrator must not have gained submission access' );
        Agentshell_Test::assert_equals( '', (string) ( $r['redirect'] ?? 'x' ), 'a refused request must not redirect as though it succeeded' );
    }
);

Agentshell_Test::run(
    'forms capabilities: a cookie session with a valid nonce performs the grant',
    function () use ( $config, $child ) {
        $r = fcap_run( $config, $child, array( 'handler', 'grant_cookie' ) );

        Agentshell_Test::assert_false( isset( $r['error'] ), 'the handler probe must report: ' . wp_json_encode( $r ) );
        Agentshell_Test::assert_false( true === ( $r['died'] ?? false ), 'a valid grant request must not be refused: ' . ( $r['died_message'] ?? '' ) );
        Agentshell_Test::assert_true( true === ( $r['admin_has_cap'] ?? false ), 'the signed-in administrator must receive the capability' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $r['redirect'] ?? '' ), 'agentshell_forms_access=granted' ),
            'the handler must redirect with a success flag: ' . ( $r['redirect'] ?? '' )
        );
        Agentshell_Test::assert_true(
            in_array( 'form_submission_access_granted', (array) ( $r['audit_events'] ?? array() ), true ),
            'the grant must be audited'
        );

        // The probe must not leave the site changed.
        Agentshell_Test::assert_false(
            (bool) ( $r['admin_has_cap_after_cleanup'] ?? true ),
            'the probe must have removed the capability again'
        );
    }
);

Agentshell_Test::run(
    'forms capabilities: a cookie session with a valid nonce performs a revocation',
    function () use ( $config, $child ) {
        $r = fcap_run( $config, $child, array( 'handler', 'revoke_cookie' ) );

        Agentshell_Test::assert_false( isset( $r['error'] ), 'the handler probe must report: ' . wp_json_encode( $r ) );
        Agentshell_Test::assert_true( true === ( $r['subject_before'] ?? false ), 'the probe must have granted access before revoking it' );
        Agentshell_Test::assert_false( true === ( $r['died'] ?? true ), 'a valid revocation request must not be refused' );
        Agentshell_Test::assert_false( (bool) ( $r['subject_has_cap'] ?? true ), 'the subject must no longer have access' );
        Agentshell_Test::assert_true(
            false !== strpos( (string) ( $r['redirect'] ?? '' ), 'agentshell_forms_access=revoked' ),
            'the handler must redirect with a revocation flag: ' . ( $r['redirect'] ?? '' )
        );
        Agentshell_Test::assert_true(
            in_array( 'form_submission_access_revoked', (array) ( $r['audit_events'] ?? array() ), true ),
            'the revocation must be audited'
        );
        Agentshell_Test::assert_false( (bool) ( $r['subject_remaining'] ?? true ), 'the probe must have removed its own user' );
    }
);

/* ---------------------------------------------------------------------------
 * The site is left as it was found
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms capabilities: the site is left exactly as it was found',
    function () use ( $report ) {
        Agentshell_Test::assert_equals( array(), $report['users_remaining'] ?? null, 'every disposable user must be gone' );
        Agentshell_Test::assert_true(
            true === ( $report['roles_unchanged_after_cleanup'] ?? false ),
            'every role must be exactly as it was before the suite ran'
        );
    }
);
