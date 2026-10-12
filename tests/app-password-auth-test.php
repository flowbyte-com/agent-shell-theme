<?php
/**
 * Application-password normalization across both AgentShell auth paths.
 *
 * Pre-fix state: both AgentShell implementations of application-password
 * authentication compared the raw, space-grouped password against a hash
 * that WordPress computed over the alphanumeric-only form. Core strips
 * non-alphanumerics before hashing (wp_authenticate_application_password in
 * wp-includes/user.php), so the standard copy-pasted credential "abcd efgh
 * ijkl ..." was rejected with 401 by the MCP endpoint while the very same
 * credential worked against ordinary WP REST.
 *
 * Post-fix invariants:
 *   1. A valid application password authenticates in both the grouped
 *      display form and the bare alphanumeric form.
 *   2. An incorrect or truncated password is still rejected in both forms
 *      (normalization must not widen the accepted credential set).
 *   3. Both affected paths are covered: the plugin's
 *      AgentShell_MCP\Auth::authenticate() (Basic auth) and the theme's
 *      rest_authentication_errors callback (Basic auth and X-App-Password,
 *      the latter being a header core never handles).
 *   4. The pre-existing authorization boundary is intact: a valid password
 *      belonging to a user without manage_options is still refused, and a
 *      request with no credential stays unauthenticated.
 *   5. Static-token authentication still fails closed, including the
 *      no-implicit-user-1 policy.
 *
 * The password used here is synthetic. No real credential appears in this
 * file, in test output, or in the repository.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "application-password normalization tests\n";

// ─── stubs ─────────────────────────────────────────────────────

/**
 * Faithful stand-in for WP_Application_Passwords.
 *
 * check_password() deliberately does NOT normalize, exactly like core —
 * core's caller is responsible for stripping first. create_stored_hash()
 * models how core stores a freshly created application password, i.e. over
 * the alphanumeric-only form. Because of that split, this stub reproduces
 * the original defect if the normalization under test is removed.
 */
class WP_Application_Passwords {
    public static $store = array();

    public static function get_user_application_passwords( $user_id ) {
        return self::$store[ (int) $user_id ] ?? array();
    }

    public static function check_password( $password, $hash ) {
        if ( ! is_string( $password ) || $password === '' ) { return false; }
        if ( ! is_string( $hash ) || $hash === '' )        { return false; }
        return hash_equals( hash( 'sha256', $password ), $hash );
    }

    // Test-only: models core's storage of a generated password.
    public static function create_stored_hash( $raw ) {
        return hash( 'sha256', preg_replace( '/[^a-z\d]/i', '', $raw ) );
    }

    public static function reset() {
        self::$store = array();
    }
}

class Agentshell_Test_User {
    public $ID;
    public $login;
    public $user_email;
    public $user_nicename;
    public function __construct( $id, $login, $email ) {
        $this->ID            = $id;
        $this->login         = $login;
        $this->user_email    = $email;
        $this->user_nicename = strtolower( $login );
    }
}

$GLOBALS['_agentshell_test_users'] = array();

function get_user_by( $field, $value ) {
    foreach ( $GLOBALS['_agentshell_test_users'] as $user ) {
        if ( $field === 'ID'     && (int) $user->ID === (int) $value ) { return $user; }
        if ( $field === 'login'  && $user->login === $value )           { return $user; }
        if ( $field === 'email'  && $user->user_email === $value )     { return $user; }
        if ( $field === 'slug'   && $user->user_nicename === $value )  { return $user; }
    }
    return false;
}

// The plugin's auth class, loaded directly. It guards on ABSPATH (defined by
// the bootstrap) and depends on the stubs above.
require_once __DIR__ . '/../agentshell-mcp/includes/class-auth.php';

// ─── fixtures ──────────────────────────────────────────────────

const AUTH_TEST_USER_ID  = 7;
const AUTH_TEST_LOGIN    = 'tester';

// Synthetic credential. Grouped exactly like a real one WordPress displays.
const AUTH_TEST_PASSWORD_GROUPED = 'wqjx btre fmno pdhv klyc zrtu';
const AUTH_TEST_PASSWORD_BARE    = 'wqjxbtrefmnopdhvklyczrtu';

function auth_test_reset( array $caps = array( 'manage_options' => true ) ) {
    agentshell_test_reset();
    WP_Application_Passwords::reset();
    $GLOBALS['_agentshell_test_users'] = array(
        new Agentshell_Test_User( AUTH_TEST_USER_ID, AUTH_TEST_LOGIN, 'tester@example.test' ),
    );
    WP_Application_Passwords::$store[ AUTH_TEST_USER_ID ] = array(
        array(
            'uuid'     => 'test-uuid',
            'password' => WP_Application_Passwords::create_stored_hash( AUTH_TEST_PASSWORD_GROUPED ),
        ),
    );
    agentshell_test_set_user( 0, $caps );

    unset(
        $_SERVER['HTTP_AUTHORIZATION'],
        $_SERVER['HTTP_X_APP_PASSWORD'],
        $_SERVER['HTTP_X_AGENTSHELL_TOKEN'],
        $_SERVER['HTTP_APP_PASSWORD'],
        $_REQUEST['_agent_token']
    );
}

/** Drive the plugin path (AgentShell_MCP\Auth::authenticate). */
function auth_test_plugin( $password_header_value = null ) {
    $headers = array();
    if ( $password_header_value !== null ) {
        $headers['Authorization'] = 'Basic ' . base64_encode( AUTH_TEST_LOGIN . ':' . $password_header_value );
    }
    $request = new WP_REST_Request( array( 'headers' => $headers ) );
    $auth    = new \AgentShell_MCP\Auth();
    return $auth->authenticate( $request );
}

/** Drive the theme path (the real rest_authentication_errors closure). */
function auth_test_theme( $password_header_value = null, $via_x_app_password = false ) {
    if ( $via_x_app_password ) {
        $_SERVER['HTTP_X_APP_PASSWORD'] = (string) $password_header_value;
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( AUTH_TEST_LOGIN . ':' . $password_header_value );
    }
    return agentshell_test_apply_filter( 'rest_authentication_errors', null );
}

// ─── normalization helper ──────────────────────────────────────

Agentshell_Test::run(
    'normalization strips the grouping spaces from a display-form password',
    function () {
        Agentshell_Test::assert_equals(
            'wqjxbtrefmnopdhvklyczrtu',
            agentshell_normalize_app_password( AUTH_TEST_PASSWORD_GROUPED )
        );
    }
);

Agentshell_Test::run(
    'normalization strips non-alphanumeric junk the way core does',
    function () {
        Agentshell_Test::assert_equals(
            'abcd2345',
            agentshell_normalize_app_password( ' ab-cd_2345! ' )
        );
    }
);

Agentshell_Test::run(
    'normalization leaves an already-bare password unchanged',
    function () {
        Agentshell_Test::assert_equals(
            AUTH_TEST_PASSWORD_BARE,
            agentshell_normalize_app_password( AUTH_TEST_PASSWORD_BARE )
        );
    }
);

Agentshell_Test::run(
    'normalization of an empty password yields an empty string',
    function () {
        Agentshell_Test::assert_equals( '', agentshell_normalize_app_password( '' ) );
    }
);

Agentshell_Test::run(
    'check_app_password refuses an empty supplied password',
    function () {
        auth_test_reset();
        $stored = WP_Application_Passwords::get_user_application_passwords( AUTH_TEST_USER_ID );
        Agentshell_Test::assert_false( agentshell_check_app_password( '', $stored ) );
        Agentshell_Test::assert_false( agentshell_check_app_password( '   ', $stored ) );
    }
);

// ─── plugin path: AgentShell_MCP\Auth::authenticate ─────────────

Agentshell_Test::run(
    'plugin accepts a valid application password that contains spaces',
    function () {
        auth_test_reset();
        $result = auth_test_plugin( AUTH_TEST_PASSWORD_GROUPED );
        Agentshell_Test::assert_true(
            is_array( $result ),
            'expected an auth result array, got ' . ( is_object( $result ) ? $result->get_error_code() : gettype( $result ) )
        );
        Agentshell_Test::assert_equals( AUTH_TEST_USER_ID, $result['user_id'] );
        Agentshell_Test::assert_equals( AUTH_TEST_USER_ID, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'plugin accepts a valid application password without spaces',
    function () {
        auth_test_reset();
        $result = auth_test_plugin( AUTH_TEST_PASSWORD_BARE );
        Agentshell_Test::assert_true( is_array( $result ), 'expected an auth result array' );
        Agentshell_Test::assert_equals( AUTH_TEST_USER_ID, $result['user_id'] );
    }
);

Agentshell_Test::run(
    'plugin rejects an incorrect application password',
    function () {
        auth_test_reset();
        $result = auth_test_plugin( AUTH_TEST_PASSWORD_GROUPED . 'z' );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_password', $result->get_error_code() );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'plugin rejects a truncated application password',
    function () {
        auth_test_reset();
        $truncated = substr( AUTH_TEST_PASSWORD_GROUPED, 0, -2 );
        $result    = auth_test_plugin( $truncated );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'invalid_password', $result->get_error_code() );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'plugin rejects a request with no Authorization header',
    function () {
        auth_test_reset();
        $result = auth_test_plugin( null );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'no_auth', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'plugin still refuses a valid password from a user without manage_options',
    function () {
        auth_test_reset( array() );
        $result = auth_test_plugin( AUTH_TEST_PASSWORD_GROUPED );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'forbidden', $result->get_error_code() );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
);

// ─── theme path: rest_authentication_errors callback ────────────

Agentshell_Test::run(
    'theme Basic auth accepts a valid application password that contains spaces',
    function () {
        auth_test_reset();
        auth_test_theme( AUTH_TEST_PASSWORD_GROUPED );
        Agentshell_Test::assert_equals( AUTH_TEST_USER_ID, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'theme Basic auth accepts a valid application password without spaces',
    function () {
        auth_test_reset();
        auth_test_theme( AUTH_TEST_PASSWORD_BARE );
        Agentshell_Test::assert_equals( AUTH_TEST_USER_ID, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'theme Basic auth rejects an incorrect application password',
    function () {
        auth_test_reset();
        auth_test_theme( AUTH_TEST_PASSWORD_GROUPED . 'z' );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'theme Basic auth rejects a truncated application password',
    function () {
        auth_test_reset();
        auth_test_theme( substr( AUTH_TEST_PASSWORD_GROUPED, 0, -2 ) );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'X-App-Password value is normalized by the shared checker it now flows through',
    function () {
        // The X-App-Password header value reaches agentshell_check_app_password
        // unchanged from the header, so the normalization under test applies to
        // it exactly as it does to the Basic-auth value.
        auth_test_reset();
        $stored = WP_Application_Passwords::get_user_application_passwords( AUTH_TEST_USER_ID );
        Agentshell_Test::assert_true(
            agentshell_check_app_password( AUTH_TEST_PASSWORD_GROUPED, $stored ),
            'grouped X-App-Password value should verify'
        );
        Agentshell_Test::assert_false(
            agentshell_check_app_password( AUTH_TEST_PASSWORD_GROUPED . 'z', $stored ),
            'incorrect X-App-Password value must not verify'
        );
    }
);

Agentshell_Test::run(
    'characterization: theme X-App-Password alone cannot authenticate (no username source)',
    function () {
        // PRE-EXISTING GAP, unrelated to normalization and deliberately not
        // fixed here. $username is assigned only inside the Basic-auth branch,
        // and that same branch overwrites $app_password. So X-App-Password is
        // unreachable in both combinations: sent alone there is no username to
        // look up; sent alongside Basic it is discarded. This test pins the
        // current behaviour so that changing it later is a deliberate act
        // rather than an accidental one.
        auth_test_reset();
        auth_test_theme( AUTH_TEST_PASSWORD_GROUPED, true );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
);

Agentshell_Test::run(
    'theme leaves a request with no credential unauthenticated',
    function () {
        auth_test_reset();
        auth_test_theme( null );
        Agentshell_Test::assert_equals( 0, get_current_user_id() );
    }
);

// ─── static-token behaviour must be unchanged ───────────────────

Agentshell_Test::run(
    'static token still binds to the configured actor',
    function () {
        Agentshell_Test::assert_equals(
            AUTH_TEST_USER_ID,
            agentshell_check_static_token( 'tok', 'tok', AUTH_TEST_USER_ID, true )
        );
    }
);

Agentshell_Test::run(
    'static token still rejects a non-matching token',
    function () {
        Agentshell_Test::assert_false(
            agentshell_check_static_token( 'wrong', 'tok', AUTH_TEST_USER_ID, true )
        );
    }
);

Agentshell_Test::run(
    'static token still fails closed with no configured actor (no implicit user 1)',
    function () {
        Agentshell_Test::assert_false( agentshell_check_static_token( 'tok', 'tok', 0, true ) );
        Agentshell_Test::assert_false( agentshell_check_static_token( 'tok', '', AUTH_TEST_USER_ID, true ) );
    }
);

Agentshell_Test::run(
    'static token still fails closed when the configured actor does not exist',
    function () {
        Agentshell_Test::assert_false( agentshell_check_static_token( 'tok', 'tok', AUTH_TEST_USER_ID, false ) );
    }
);