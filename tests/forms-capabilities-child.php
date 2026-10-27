<?php
/**
 * Child half of the forms capability suite.
 *
 * Two modes, because the grant and revocation handlers end the request:
 *
 *   `report`  — the role state, the per-user mechanism, and the role-wide
 *               snapshot that proves a grant changes no role.
 *   `handler` — runs one real `admin_post` callback with a real cookie session,
 *               a real nonce and (where the case calls for it) no cookie at all,
 *               and reports what it did through a shutdown function. The
 *               handler's own `exit` is part of what is being tested, so it
 *               cannot be reached from the `report` process.
 *
 * argv: 1 wp_root  2 mode  3 report path  [4 submode]
 */

$wp_root = $argv[1] ?? '';
$mode    = $argv[2] ?? '';
$report  = $argv[3] ?? '';
$submode = $argv[4] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AgentShell_Forms\Form_Audit;
use AgentShell_Forms\Form_Capabilities;

global $wpdb;

$token = 'AScap' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );

/**
 * A snapshot of every role's capability set, for proving a grant changes none.
 *
 * @return array<string,array<string,mixed>>
 */
function role_snapshot() {
    $roles = wp_roles();
    $out   = array();
    foreach ( (array) $roles->roles as $name => $definition ) {
        $out[ $name ] = isset( $definition['capabilities'] ) && is_array( $definition['capabilities'] )
            ? $definition['capabilities']
            : array();
    }
    ksort( $out );
    return $out;
}

/**
 * Every audit event name recorded so far.
 *
 * @return array<int,string>
 */
function audit_events() {
    $names = array();
    foreach ( Form_Audit::entries() as $entry ) {
        $names[] = (string) ( $entry['event'] ?? '' );
    }
    return $names;
}

/**
 * Create a disposable subscriber.
 *
 * @param string $tag Login tag.
 * @return int User id, or 0.
 */
function make_user( $tag ) {
    global $token;
    $login = strtolower( $tag . '-' . $token );
    $id    = wp_insert_user(
        array(
            'user_login' => $login,
            'user_pass'  => wp_generate_password( 24, true, true ),
            'user_email' => $login . '@example.invalid',
            'role'       => 'subscriber',
        )
    );
    return is_wp_error( $id ) ? 0 : (int) $id;
}

/**
 * Whether a user holds the submissions capability.
 *
 * @param int $user_id User id.
 * @return bool|null Null when the user does not exist.
 */
function user_has_submissions( $user_id ) {
    $user = get_user_by( 'id', (int) $user_id );
    return $user instanceof WP_User ? (bool) user_can( $user, Form_Capabilities::CAP_SUBMISSIONS ) : null;
}

/* ---------------------------------------------------------------------------
 * Handler mode
 * ------------------------------------------------------------------------ */

if ( 'handler' === $mode ) {
    $subject = make_user( 'cap-h' );
    $admin   = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );

    $result = array(
        'submode'        => $submode,
        'subject'        => $subject,
        'admin'          => $admin,
        'redirect'       => '',
        'died'           => false,
        'died_message'   => '',
        'died_response'  => 0,
        'returned_normally' => false,
    );

    // A revocation case needs the capability present first, so that the test
    // is "the handler took it away" rather than "it was never there".
    if ( 'revoke_cookie' === $submode && $subject > 0 ) {
        Form_Capabilities::grant_submissions( $subject, $admin );
        $result['subject_before'] = user_has_submissions( $subject );
    }

    register_shutdown_function(
        function () use ( &$result, $report, $submode, $subject, $admin ) {
            $result['subject_has_cap'] = user_has_submissions( $subject );
            $result['admin_has_cap']   = user_has_submissions( $admin );
            $result['audit_events']    = audit_events();

            // Leave the site exactly as it was found. The grant path acts on
            // the signed-in administrator, so this is load-bearing.
            if ( $admin > 0 ) {
                Form_Capabilities::revoke_submissions( $admin, 0 );
            }
            if ( $subject > 0 ) {
                Form_Capabilities::revoke_submissions( $subject, 0 );
                wp_delete_user( $subject );
            }
            $result['subject_remaining'] = $subject > 0 ? ( get_user_by( 'id', $subject ) instanceof WP_User ) : false;
            $result['admin_has_cap_after_cleanup'] = user_has_submissions( $admin );

            file_put_contents( $report, wp_json_encode( $result ) );
        }
    );

    // Capture the redirect instead of sending a header, so the handler's own
    // URL is observable.
    add_filter(
        'wp_redirect',
        function ( $location ) use ( &$result ) {
            $result['redirect'] = (string) $location;
            return false;
        },
        10,
        1
    );

    // Capture a refusal instead of letting it kill the process silently, so
    // the reason and the status code are observable.
    add_filter(
        'wp_die_handler',
        function () use ( &$result ) {
            return function ( $message, $title = '', $args = array() ) use ( &$result ) {
                $result['died']          = true;
                $result['died_message']  = is_string( $message ) ? wp_strip_all_tags( $message ) : '';
                $result['died_response'] = (int) ( $args['response'] ?? 0 );
                exit( 0 );
            };
        },
        10,
        1
    );

    // A real, valid cookie session — the thing an application password cannot
    // produce. `grant_no_cookie` deliberately omits it.
    if ( 'grant_no_cookie' !== $submode ) {
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin, time() + 3600, 'logged_in' );
    }

    wp_set_current_user( $admin );

    $action = 'revoke_cookie' === $submode ? 'revoke' : 'grant';
    $_REQUEST['_wpnonce'] = 'grant_bad_nonce' === $submode
        ? 'not-a-valid-nonce'
        : wp_create_nonce( Form_Capabilities::nonce_action( $action ) );

    if ( 'revoke_cookie' === $submode && $subject > 0 ) {
        $_POST['user_id']    = (string) $subject;
        $_REQUEST['user_id'] = (string) $subject;
    }

    // The handler ends the request; the shutdown function reports.
    if ( 'revoke_cookie' === $submode ) {
        Form_Capabilities::handle_revoke();
    } else {
        Form_Capabilities::handle_grant();
    }

    // Unreachable: the handler either redirected and exited, or refused and
    // exited. Reaching here means it did not end the request, which is itself
    // a finding.
    $result['returned_normally'] = true;
    exit( 0 );
}

/* ---------------------------------------------------------------------------
 * Report mode
 * ------------------------------------------------------------------------ */

$before_roles = role_snapshot();

$a = make_user( 'cap-a' );
$b = make_user( 'cap-b' );
$c = make_user( 'cap-c' );

$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );

$data = array(
    'token'    => $token,
    'users'    => array( 'a' => $a, 'b' => $b, 'c' => $c ),
    'admin_id' => $admin_id,
);

/* ---- role state ------------------------------------------------------- */

$admin_role = get_role( 'administrator' );
$sub_role   = get_role( 'subscriber' );

$data['role'] = array(
    'administrator_has_forms'       => $admin_role instanceof WP_Role ? $admin_role->has_cap( Form_Capabilities::CAP_FORMS ) : null,
    'administrator_has_submissions' => $admin_role instanceof WP_Role ? $admin_role->has_cap( Form_Capabilities::CAP_SUBMISSIONS ) : null,
    'subscriber_has_forms'          => $sub_role instanceof WP_Role ? $sub_role->has_cap( Form_Capabilities::CAP_FORMS ) : null,
    'subscriber_has_submissions'    => $sub_role instanceof WP_Role ? $sub_role->has_cap( Form_Capabilities::CAP_SUBMISSIONS ) : null,
    'form_capability'               => Form_Capabilities::CAP_FORMS,
    'submissions_capability'        => Form_Capabilities::CAP_SUBMISSIONS,
);

/* ---- the administrator does not get submission access ---------------- */

$data['admin'] = array(
    'has_manage_options'     => $admin_id > 0 ? user_can( $admin_id, 'manage_options' ) : null,
    'can_manage_forms'       => $admin_id > 0 ? Form_Capabilities::can_manage_forms( $admin_id ) : null,
    'can_access_submissions' => $admin_id > 0 ? Form_Capabilities::can_access_submissions( $admin_id ) : null,

    // The regression this pins: when the post type's three meta capabilities
    // were all mapped onto the plugin capability, WordPress registered that
    // capability in its global meta-capability table, and a bare check of it
    // resolved to `do_not_allow` for everybody. The plugin capability must
    // still be a plain primitive capability.
    'map_meta_cap_forms'       => map_meta_cap( Form_Capabilities::CAP_FORMS, $admin_id ),
    'map_meta_cap_submissions' => map_meta_cap( Form_Capabilities::CAP_SUBMISSIONS, $admin_id ),
    'post_type_caps_forms'     => ( function () {
        $object = get_post_type_object( 'agentshell_form' );
        return $object instanceof WP_Post_Type ? array(
            'edit_post'   => (string) $object->cap->edit_post,
            'edit_posts'  => (string) $object->cap->edit_posts,
            'read'        => (string) $object->cap->read,
            'publish'     => (string) $object->cap->publish_posts,
        ) : array();
    } )(),
    'per_post_checks' => ( function () use ( $admin_id ) {
        $id = (int) wp_insert_post( array( 'post_type' => 'agentshell_form', 'post_status' => 'publish', 'post_title' => 'capability probe' ), true );
        if ( $id < 1 ) {
            return array( 'error' => 'no_post' );
        }
        $out = array(
            'edit_post'          => (bool) user_can( $admin_id, 'edit_post', $id ),
            'delete_post'        => (bool) user_can( $admin_id, 'delete_post', $id ),
            'read_post'          => (bool) user_can( $admin_id, 'read_post', $id ),
            'edit_agentshell_form' => (bool) user_can( $admin_id, 'edit_agentshell_form', $id ),
        );
        wp_delete_post( $id, true );
        return $out;
    } )(),
);

/* ---- two users of the same role, one grant --------------------------- */

$data['before'] = array(
    'a_can'   => $a > 0 ? Form_Capabilities::can_access_submissions( $a ) : null,
    'b_can'   => $b > 0 ? Form_Capabilities::can_access_submissions( $b ) : null,
    'c_forms' => $c > 0 ? Form_Capabilities::can_manage_forms( $c ) : null,
);

$granted_a = $a > 0 ? Form_Capabilities::grant_submissions( $a, $admin_id ) : false;

$data['after_grant'] = array(
    'granted'                     => $granted_a,
    'a_can'                       => $a > 0 ? Form_Capabilities::can_access_submissions( $a ) : null,
    'b_can'                       => $b > 0 ? Form_Capabilities::can_access_submissions( $b ) : null,
    'subscriber_role_has'         => $sub_role instanceof WP_Role ? $sub_role->has_cap( Form_Capabilities::CAP_SUBMISSIONS ) : null,
    'granted_at'                  => $a > 0 ? Form_Capabilities::granted_at( $a ) : '',
    'granted_at_b'                => $b > 0 ? Form_Capabilities::granted_at( $b ) : '',
    'users_with_access'           => array_map(
        function ( $u ) { return (int) $u->ID; },
        Form_Capabilities::users_with_submissions_access()
    ),
    'roles_unchanged_after_grant' => role_snapshot() === $before_roles,
);

// The capability lives on the user record, not on a role.
$data['user_caps_a'] = $a > 0 ? get_user_meta( $a, $wpdb->get_blog_prefix() . 'capabilities', true ) : null;
$data['user_caps_a_is_array'] = is_array( $data['user_caps_a'] );

$revoked_a = $a > 0 ? Form_Capabilities::revoke_submissions( $a, $admin_id ) : false;

$data['after_revoke'] = array(
    'revoked'                      => $revoked_a,
    'a_can'                        => $a > 0 ? Form_Capabilities::can_access_submissions( $a ) : null,
    'b_can'                        => $b > 0 ? Form_Capabilities::can_access_submissions( $b ) : null,
    'granted_at'                   => $a > 0 ? Form_Capabilities::granted_at( $a ) : '',
    'users_with_access'            => array_map(
        function ( $u ) { return (int) $u->ID; },
        Form_Capabilities::users_with_submissions_access()
    ),
    'roles_unchanged_after_revoke' => role_snapshot() === $before_roles,
);

/* ---- the forms capability gates forms, not manage_options ------------- */

if ( $c > 0 ) {
    $user_c = get_user_by( 'id', $c );
    $user_c->add_cap( Form_Capabilities::CAP_FORMS, true );
    $user_c->get_role_caps();
    $data['forms_cap_grant'] = array(
        'after'           => Form_Capabilities::can_manage_forms( $c ),
        'roles_unchanged' => role_snapshot() === $before_roles,
    );
    $user_c->remove_cap( Form_Capabilities::CAP_FORMS );
    $user_c->get_role_caps();
    $data['forms_cap_revoke'] = array( 'after' => Form_Capabilities::can_manage_forms( $c ) );
}

/* ---- the gate an application password cannot pass -------------------- */

$data['cookie'] = array(
    'is_cookie_authenticated_cli' => Form_Capabilities::is_cookie_authenticated(),
    'logged_in_cookie_defined'    => defined( 'LOGGED_IN_COOKIE' ),
);

/* ---- refusals and successes are audited ------------------------------ */

$data['audit_events'] = audit_events();

/* ---- cleanup ---------------------------------------------------------- */

$deleted = array();
foreach ( array( $a, $b, $c ) as $id ) {
    if ( $id > 0 ) {
        $deleted[ $id ] = (bool) wp_delete_user( (int) $id );
    }
}
$data['deleted'] = $deleted;
$data['users_remaining'] = array_map(
    'intval',
    (array) get_users(
        array(
            'search'         => '*' . $token . '*',
            'search_columns' => array( 'user_login' ),
            'fields'         => 'ID',
        )
    )
);
$data['roles_unchanged_after_cleanup'] = role_snapshot() === $before_roles;

Form_Audit::clear();

file_put_contents( $report, wp_json_encode( $data ) );
echo "reported\n";
exit( 0 );
