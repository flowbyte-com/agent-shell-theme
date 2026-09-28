<?php
/**
 * Minimal WordPress test bootstrap.
 *
 * Stubs the WP functions used by AgentShell code so we can exercise it
 * without a full WP/MySQL install. NOT a substitute for wp-tests/phpunit;
 * the goal is to pin targeted regressions, not to validate end-to-end WP
 * integration.
 *
 * What this provides:
 *  - In-memory option store keyed by name
 *  - Fake current user (id + capabilities)
 *  - Stubs for current_user_can / wp_set_current_user / get_current_user_id
 *  - Stubs for update_option / get_option / delete_option
 *  - A tiny error_log capture so tests can inspect logged warnings
 *
 * What it does NOT provide (and why):
 *  - Real WP_REST_Request, WP_Error dispatch — we test capability boundaries
 *    directly via the underlying callback rather than spinning up REST.
 *  - Real user meta / post meta — AgentShell's tests don't need them.
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) { define( 'AGENTSHELL_REST_TOKEN', '' ); }

$GLOBALS['_agentshell_test_options']  = array();
$GLOBALS['_agentshell_test_user_id']  = 0;
$GLOBALS['_agentshell_test_user_caps'] = array();
$GLOBALS['_agentshell_test_error_log'] = array();

function get_option( $key, $default = false ) {
    return $GLOBALS['_agentshell_test_options'][$key] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['_agentshell_test_options'][$key] = $value;
    return true;
}

function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
    if ( array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) {
        return false;
    }
    $GLOBALS['_agentshell_test_options'][$key] = $value;
    return true;
}

function delete_option( $key ) {
    if ( ! array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) {
        return false;
    }
    unset( $GLOBALS['_agentshell_test_options'][ $key ] );
    return true;
}

function get_current_user_id() {
    return (int) $GLOBALS['_agentshell_test_user_id'];
}

function wp_set_current_user( $id ) {
    $GLOBALS['_agentshell_test_user_id'] = (int) $id;
}

function current_user_can( $cap, $object_id = null ) {
    $caps = $GLOBALS['_agentshell_test_user_caps'];
    return ! empty( $caps[ $cap ] );
}

function user_can( $user, $cap ) {
    $caps = $GLOBALS['_agentshell_test_user_caps'];
    return ! empty( $caps[ $cap ] );
}

function wp_check_invalid_utf8( $text ) {
    return is_string( $text ) ? $text : '';
}

function wp_kses_post( $text ) {
    // Naive stripper: drops <script>/<style> tags + on* attrs.
    if ( ! is_string( $text ) ) { return ''; }
    $text = preg_replace( '/<\/?script\b[^>]*>/i', '', $text );
    $text = preg_replace( '/<\/?style\b[^>]*>/i', '', $text );
    $text = preg_replace( '/\s+on[a-z]+\s*=\s*["\'][^"\']*["\']/i', '', $text );
    return $text;
}

function sanitize_text_field( $text ) {
    return is_string( $text ) ? trim( $text ) : '';
}

function sanitize_key( $key ) {
    return is_string( $key ) ? strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ) : '';
}

function sanitize_title( $title ) {
    return is_string( $title ) ? strtolower( preg_replace( '/[^a-z0-9\-]/', '-', $title ) ) : '';
}

function wp_json_encode( $data ) {
    return json_encode( $data, JSON_UNESCAPED_SLASHES );
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }
    public function get_error_code()    { return $this->code; }
    public function get_error_message() { return $this->message; }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $params;
        public function __construct( $params = array() ) { $this->params = $params; }
        public function get_param( $k )         { return $this->params[ $k ] ?? null; }
        public function get_json_params()       { return $this->params; }
        public function get_body()              { return wp_json_encode( $this->params ); }
        public function get_header( $name )     { return ''; }
        public function offsetExists( $k )      { return isset( $this->params[ $k ] ); }
        public function offsetGet( $k )         { return $this->params[ $k ] ?? null; }
    }
}

function rest_url( $path = '/' ) {
    return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function admin_url( $path = '/' ) {
    return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function remove_filter() {}
function remove_action() {}

if ( ! function_exists( 'error_log' ) ) {
    function error_log( $msg ) {
        $GLOBALS['_agentshell_test_error_log'][] = (string) $msg;
    }
}

/**
 * Test helpers
 */

function agentshell_test_set_user( $id, array $caps = array() ) {
    $GLOBALS['_agentshell_test_user_id']   = (int) $id;
    $GLOBALS['_agentshell_test_user_caps'] = $caps;
}

function agentshell_test_reset() {
    $GLOBALS['_agentshell_test_options']   = array();
    $GLOBALS['_agentshell_test_user_id']   = 0;
    $GLOBALS['_agentshell_test_user_caps'] = array();
    $GLOBALS['_agentshell_test_error_log'] = array();
}

/**
 * Minimal assertion library
 */
class Agentshell_Test {
    public static $passed = 0;
    public static $failed = 0;
    public static $current_test = '';

    public static function run( $name, callable $fn ) {
        self::$current_test = $name;
        try {
            $fn();
            self::$passed++;
            echo "  ok   $name\n";
        } catch ( Throwable $e ) {
            self::$failed++;
            echo "  FAIL $name\n";
            echo "       " . $e->getMessage() . "\n";
            echo "       at " . $e->getFile() . ":" . $e->getLine() . "\n";
        }
    }

    public static function assert_true( $cond, $msg = '' ) {
        if ( ! $cond ) { throw new Exception( $msg ?: 'expected truthy' ); }
    }
    public static function assert_false( $cond, $msg = '' ) {
        if ( $cond ) { throw new Exception( $msg ?: 'expected falsy' ); }
    }
    public static function assert_equals( $a, $b, $msg = '' ) {
        if ( $a !== $b ) {
            throw new Exception( ( $msg ?: 'assert_equals' ) . ': expected ' . var_export( $a, true ) . ' got ' . var_export( $b, true ) );
        }
    }
    public static function assert_not_equals( $a, $b, $msg = '' ) {
        if ( $a === $b ) { throw new Exception( $msg ?: 'assert_not_equals' ); }
    }
    public static function assert_instance_of( $class, $obj, $msg = '' ) {
        if ( ! ( $obj instanceof $class ) ) {
            throw new Exception( ( $msg ?: 'assert_instance_of' ) . ': expected instance of ' . $class );
        }
    }
    public static function summary() {
        echo "\n" . self::$passed . " passed, " . self::$failed . " failed\n";
        return self::$failed === 0;
    }
}