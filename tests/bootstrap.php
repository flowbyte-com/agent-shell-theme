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
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook ) { return true; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook ) { return false; }
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
    function wp_unschedule_event( $timestamp, $hook ) { return true; }
}
if ( ! function_exists( 'gmdate' ) ) {
    function gmdate( $format, $ts = null ) { return date( $format, $ts ?? time() ); }
}

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

/**
 * Capability check.
 *
 * A stored value may be a bool, or — when the capability is object-scoped, as
 * `edit_post` / `read_post` are — a list of object ids the user may act on, or a
 * callable receiving ( $cap, $object_id ). The object-aware forms exist because
 * per-post capability is precisely what WordPress's own preview authorisation
 * turns on: a bool-only stub would let a test pass while proving nothing about
 * whether a user may read *this* post. Suites written before this ignore the
 * extra shapes and keep working.
 */
function current_user_can( $cap, $object_id = null ) {
    $caps = $GLOBALS['_agentshell_test_user_caps'];
    if ( ! array_key_exists( $cap, $caps ) ) {
        return false;
    }
    $value = $caps[ $cap ];
    if ( is_callable( $value ) ) {
        return (bool) call_user_func( $value, $cap, $object_id );
    }
    if ( is_array( $value ) ) {
        return null === $object_id || in_array( (int) $object_id, array_map( 'intval', $value ), true );
    }
    return (bool) $value;
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
    // Implements ArrayAccess because production code reads route params with
    // array syntax ($request['id']); without the interface PHP refuses the
    // access outright, which is a stub artefact rather than a real defect.
    class WP_REST_Request implements ArrayAccess {
        private $params;
        public function __construct( $params = array() ) { $this->params = $params; }
        public function get_param( $k )         { return $this->params[ $k ] ?? null; }
        public function get_json_params()       { return $this->params; }
        public function get_body()              { return wp_json_encode( $this->params ); }
        public function get_header( $name )     {
            $headers = $this->params['headers'] ?? array();
            foreach ( $headers as $key => $value ) {
                if ( strtolower( (string) $key ) === strtolower( (string) $name ) ) {
                    return $value;
                }
            }
            return '';
        }
        public function offsetExists( $k ): bool  { return isset( $this->params[ $k ] ); }
        public function offsetGet( $k ): mixed    { return $this->params[ $k ] ?? null; }
        public function offsetSet( $k, $v ): void { $this->params[ $k ] = $v; }
        public function offsetUnset( $k ): void   { unset( $this->params[ $k ] ); }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public $status;
        private $headers = array();
        public function __construct( $data = null, $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function header( $key, $value ) { $this->headers[ $key ] = $value; }
        public function get_headers() { return $this->headers; }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}

function rest_url( $path = '/' ) {
    return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function admin_url( $path = '/' ) {
    return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function get_template_directory() {
    return dirname( __DIR__ );
}

function get_template_directory_uri() {
    return 'file://' . dirname( __DIR__ );
}

function file_exists_stub( $path ) {
    return file_exists( $path );
}

function have_posts() { return false; }
function the_post() {}
function the_content() {}
function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return $url; }

/**
 * Real query-string removal, matching WordPress for the cases the tools use.
 *
 * The screenshot tool strips caller-supplied preview credentials and scrubs
 * the ones it mints; a stub that appended instead of removing would have made
 * both assertions pass for the wrong reason.
 */
if ( ! function_exists( 'remove_query_arg' ) ) {
    function remove_query_arg( $keys, $url ) {
        if ( ! is_array( $keys ) ) { $keys = array( $keys ); }
        $pos = strpos( $url, '?' );
        if ( false === $pos ) { return $url; }
        $frag = str_contains( $url, '#' ) ? '#' . substr( $url, strpos( $url, '#' ) + 1 ) : '';
        parse_str( substr( $url, $pos + 1 ), $params );
        foreach ( $keys as $k ) { unset( $params[ $k ] ); }
        if ( empty( $params ) ) { return substr( $url, 0, $pos ) . $frag; }
        return substr( $url, 0, $pos ) . '?' . http_build_query( $params ) . $frag;
    }
}
function esc_js( $text ) { return $text; }
function __( $text, $domain = null ) { return $text; }
function dynamic_sidebar( $id ) {}

$GLOBALS['_agentshell_test_captured_headers'] = array();
$GLOBALS['_agentshell_test_headers_already_sent'] = false;

if ( ! function_exists( 'header' ) ) {
    function header( $string, $replace = true, $http_response_code = 0 ) {
        $GLOBALS['_agentshell_test_captured_headers'][] = (string) $string;
        return true;
    }
}

if ( ! function_exists( 'headers_sent' ) ) {
    function headers_sent( &$file = null, &$line = null ) {
        return (bool) $GLOBALS['_agentshell_test_headers_already_sent'];
    }
}

/**
 * Test capture hook for agentshell_emit_response_header. When defined
 * before functions.php is required, the production wrapper diverts
 * every emitted header into the captured array. Production code never
 * declares this function.
 */
function agentshell_test_capture_header( $header ) {
    $GLOBALS['_agentshell_test_captured_headers'][] = (string) $header;
}

/**
 * Test override for the headers_sent() check inside the production
 * wrapper. Returns the test-controlled flag rather than PHP's built-in
 * (which cannot be stubbed).
 */
function agentshell_test_headers_sent() {
    return (bool) $GLOBALS['_agentshell_test_headers_already_sent'];
}

function agentshell_test_send_security_headers() {
    agentshell_send_security_headers();
}

/**
 * Fake wpdb for the audit-log tests. Operates on a global array, so
 * tests can seed rows, run a prune, and inspect what survived.
 *
 * The stub understands only the SQL the audit-log class emits:
 *   - DELETE FROM {table} WHERE created_at < '<cutoff>'
 *   - DELETE FROM {table} ORDER BY id ASC LIMIT {n}
 *   - SELECT COUNT(*) FROM {table}
 *   - INSERT INTO {table} (...) VALUES (...)
 *
 * Any other query returns 0 / null and is harmless for tests that only
 * exercise the prune path. A global $_agentshell_test_audit_fail flag
 * makes every query throw, used to verify error swallowing.
 */

$GLOBALS['_agentshell_test_audit_table']  = array();
$GLOBALS['_agentshell_test_audit_fail']   = false;
$GLOBALS['_agentshell_test_audit_nextid'] = 0;

if ( ! class_exists( 'wpdb_stub_for_tests' ) ) {
    class wpdb_stub_for_tests {
        public $prefix = 'wp_';
        public function prepare( $sql, ...$args ) {
            // Very small vsprintf-style substitution; sufficient for the
            // audit-log class which only uses %s and %d with positional args.
            $out = $sql;
            $i = 0;
            foreach ( $args as $a ) {
                $marker = $i === 0 ? '%s' : ( strpos( $out, "'%s'" ) !== false ? "'%s'" : '%d' );
                if ( is_numeric( $a ) ) {
                    $out = preg_replace( '/%s|%d/', (string) $a, $out, 1 );
                } else {
                    $out = preg_replace( "/%s/", "'" . addslashes( (string) $a ) . "'", $out, 1 );
                }
                $i++;
            }
            return $out;
        }
        public function query( $sql ) {
            if ( $GLOBALS['_agentshell_test_audit_fail'] ) {
                throw new \RuntimeException( 'wpdb query failure (test)' );
            }
            $table = $GLOBALS['_agentshell_test_audit_table'];
            $before = count( $table );
            // INSERT handled by the dedicated test helper, not by SQL
            // parsing — the helper short-circuits below by returning a
            // callable that ignores the SQL. Kept here for symmetry:
            // if the stub ever needs to accept a raw INSERT, the
            // production code path uses $wpdb->insert() which we don't
            // exercise from these tests.
            if ( preg_match( "/INSERT INTO/i", $sql ) ) {
                return 0;
            }
            if ( preg_match( "/WHERE created_at < '([^']+)'/", $sql, $m ) ) {
                $cutoff = strtotime( $m[1] );
                $GLOBALS['_agentshell_test_audit_table'] = array_values( array_filter(
                    $table,
                    function ( $r ) use ( $cutoff ) { return $r['_ts'] >= $cutoff; }
                ) );
                return $before - count( $GLOBALS['_agentshell_test_audit_table'] );
            }
            if ( preg_match( "/ORDER BY id ASC LIMIT (\d+)/", $sql, $m ) ) {
                $excess = (int) $m[1];
                usort( $GLOBALS['_agentshell_test_audit_table'], function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
                $GLOBALS['_agentshell_test_audit_table'] = array_slice( $GLOBALS['_agentshell_test_audit_table'], $excess );
                return $excess;
            }
            return 0;
        }
        public function get_var( $sql ) {
            if ( $GLOBALS['_agentshell_test_audit_fail'] ) {
                throw new \RuntimeException( 'wpdb get_var failure (test)' );
            }
            if ( preg_match( "/SELECT COUNT\(\*\) FROM/i", $sql ) ) {
                return count( $GLOBALS['_agentshell_test_audit_table'] );
            }
            return null;
        }
    }
}

$wpdb = new wpdb_stub_for_tests();
$GLOBALS['wpdb'] = $wpdb;

function agentshell_test_audit_insert( $user_id, $tool_name, $created_at_ts ) {
    $GLOBALS['_agentshell_test_audit_table'][] = array(
        'id'         => ++$GLOBALS['_agentshell_test_audit_nextid'],
        'wp_user_id' => (int) $user_id,
        'tool_name'  => (string) $tool_name,
        '_ts'        => (int) $created_at_ts,
    );
}

function agentshell_test_audit_all() {
    return $GLOBALS['_agentshell_test_audit_table'];
}

/**
 * Filter/action registry.
 *
 * Filters are recorded so a test can invoke the real registered closure
 * (e.g. the rest_authentication_errors callback) instead of only the pure
 * helper behind it. The registry is deliberately NOT cleared by
 * agentshell_test_reset(): callbacks are registered once when functions.php
 * is required, and suites call reset() repeatedly.
 */
$GLOBALS['_agentshell_test_filters'] = array();

function add_action( $tag = '', $callback = null, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['_agentshell_test_filters'][ $tag ][] = $callback;
}
function add_filter( $tag = '', $callback = null, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['_agentshell_test_filters'][ $tag ][] = $callback;
}
/**
 * Dispatch to registered callbacks, as WordPress does.
 *
 * A no-op stub would make any code path that gathers tools through a filter
 * untestable — Server::gather_tools() would always see an empty list, and a
 * test could not tell "the tool misbehaved" from "the tool was never called".
 */
function apply_filters( $tag, $value, ...$args ) {
    if ( empty( $GLOBALS['_agentshell_test_filters'][ $tag ] ) ) { return $value; }
    foreach ( $GLOBALS['_agentshell_test_filters'][ $tag ] as $callback ) {
        $value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
    }
    return $value;
}
function do_action() {}
function remove_filter() {}
function remove_action() {}

/**
 * Invoke every recorded callback for $tag, threading $value through.
 * Falls back to $value when nothing is registered.
 *
 * Identical to apply_filters() now that the latter dispatches; kept as an
 * explicit alias so existing call sites read as intent rather than accident.
 */
function agentshell_test_apply_filter( $tag, $value, ...$args ) {
    return apply_filters( $tag, $value, ...$args );
}

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
    $GLOBALS['_agentshell_test_audit_table']  = array();
    $GLOBALS['_agentshell_test_audit_fail']   = false;
    $GLOBALS['_agentshell_test_audit_nextid'] = 0;
    $GLOBALS['_agentshell_test_captured_headers'] = array();
    $GLOBALS['_agentshell_test_headers_already_sent'] = false;
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