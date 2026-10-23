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
    // Faithful to core: pre_update_option_{$option} is a transform filter, not
    // a veto. Core assigns whatever comes back to $value and then skips the
    // write only when it equals the stored value (wp-includes/option.php,
    // "If the new and old values are the same, no need to update"). A stub
    // that treats any non-null return as a short-circuit both lets a cancelled
    // write land and drops a real write on the floor.
    $old   = get_option( $key );
    $value = apply_filters( "pre_update_option_{$key}", $value, $old, $key );

    if ( $value === $old ) {
        return false;
    }
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

/**
 * Serialisation, as wp-includes/functions.php defines it.
 *
 * The transaction lease compare-and-swaps on the *stored* bytes, so a test
 * cannot exercise it against an option store that hands back PHP values and a
 * guard that compares strings unless both sides agree on what the column
 * holds. These are the two halves of that agreement.
 */
if ( ! function_exists( 'maybe_serialize' ) ) {
    function maybe_serialize( $data ) {
        if ( is_array( $data ) || is_object( $data ) ) { return serialize( $data ); }
        return $data;
    }
}
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $value ) {
        if ( is_array( $value ) ) { return array_map( 'wp_slash', $value ); }
        if ( is_object( $value ) ) {
            $out = new stdClass();
            foreach ( (array) $value as $k => $v ) { $out->{$k} = wp_slash( $v ); }
            return $out;
        }
        if ( is_string( $value ) ) { return addslashes( $value ); }
        return $value;
    }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
    function maybe_unserialize( $data ) {
        if ( is_string( $data ) && preg_match( '/^a:\d+:|^s:\d+:|^O:\d+:/', $data ) ) {
            $out = @unserialize( $data );
            return false === $out && 'b:0;' !== $data ? $data : $out;
        }
        return $data;
    }
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

/**
 * Widget sidebar registry stubs.
 *
 * WordPress owns widget content; the test bootstrap mirrors the global
 * $wp_registered_sidebars shape so widgets_init callbacks and the
 * is_registered_sidebar() / is_active_sidebar() checks used by the
 * doctor validator can be exercised without a live WP install.
 */
$GLOBALS['_agentshell_test_sidebars'] = array();
$GLOBALS['_agentshell_test_sidebar_widgets'] = array();
$GLOBALS['_agentshell_test_dynamic_sidebar_calls'] = array();

function register_sidebar( $args ) {
    if ( ! is_array( $args ) || empty( $args['id'] ) ) {
        return;
    }
    $GLOBALS['_agentshell_test_sidebars'][ $args['id'] ] = $args;
}
function wp_registered_sidebars() {
    return $GLOBALS['_agentshell_test_sidebars'];
}
function is_registered_sidebar( $id ) {
    return is_string( $id ) && isset( $GLOBALS['_agentshell_test_sidebars'][ $id ] );
}
function is_active_sidebar( $id ) {
    if ( ! is_string( $id ) || ! isset( $GLOBALS['_agentshell_test_sidebars'][ $id ] ) ) {
        return false;
    }
    return ! empty( $GLOBALS['_agentshell_test_sidebar_widgets'][ $id ] );
}
function dynamic_sidebar( $id ) {
    $GLOBALS['_agentshell_test_dynamic_sidebar_calls'][] = (string) $id;
    if ( is_string( $id ) && ! empty( $GLOBALS['_agentshell_test_sidebar_widgets'][ $id ] ) ) {
        echo '<div class="widget-rendered">' . esc_html( $id ) . '</div>';
    }
}

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
 * The stub understands two families of SQL:
 *   - the audit-log prune:
 *       DELETE FROM {table} WHERE created_at < '<cutoff>'
 *       DELETE FROM {table} ORDER BY id ASC LIMIT {n}
 *       SELECT COUNT(*) FROM {table}
 *       INSERT INTO {table} (...) VALUES (...)
 *   - the transaction lease's compare-and-swap on wp_options:
 *       UPDATE {options} SET option_value = ... WHERE option_name = ... AND option_value = ...
 *       DELETE FROM {options} WHERE option_name = ... AND option_value = ...
 *
 * The second family is the reason the guard is honoured rather than ignored:
 * the lease's entire safety argument is that a write lands only if the row
 * still holds the value the caller read, and a stub that quietly accepted
 * every write would let that argument go untested.
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
        public $options = 'wp_options';

        /**
         * Faithful placeholder substitution.
         *
         * The previous version built the replacement string and handed it to
         * preg_replace(), which silently ate backslashes: the regex engine
         * treats `\\` in a replacement as an escape, so any escaped quote
         * inside a value came out wrong. That stayed invisible because every
         * existing caller passed a bare timestamp. The transaction lease swaps
         * whole serialised option rows — dense with backslashes and quotes —
         * so substitution has to genuinely round-trip. A callback keeps the
         * replacement out of the regex engine's hands entirely.
         */
        public function prepare( $sql, ...$args ) {
            if ( ! $args ) { return $sql; }
            $i = 0;
            return preg_replace_callback(
                '/%[%sdfF]/',
                function ( $m ) use ( &$i, $args ) {
                    $a = array_key_exists( $i, $args ) ? $args[ $i ] : null;
                    $i++;
                    switch ( $m[0] ) {
                        case '%d': return (string) (int) $a;
                        case '%f':
                        case '%F': return (string) (float) $a;
                        default:   return "'" . addslashes( (string) $a ) . "'";
                    }
                },
                $sql
            );
        }

        /**
         * Find the ` WHERE ` that separates a statement's assignments from its
         * conditions — the first one that is not inside a quoted literal.
         *
         * A plain `stripos( $sql, ' WHERE ' )` was enough until the transaction
         * row started carrying text a human might read: a conflict reason that
         * contains the words "where the journal recorded", say. Splitting there
         * hands `cas_apply()` a fragment that ends mid-string and a condition it
         * cannot parse, so the guarded write reports "no rows matched" for a row
         * that had not moved — a false race, in the one place a false race is
         * most expensive to debug. MySQL tokenises the literal and never sees
         * that `where`; the stub now does the same.
         */
        private function top_level_where( $sql ) {
            $len = strlen( $sql );
            $in  = false;
            for ( $i = 0; $i < $len; $i++ ) {
                if ( '\\' === $sql[ $i ] && $in ) { $i++; continue; }
                if ( "'" === $sql[ $i ] ) { $in = ! $in; continue; }
                if ( ! $in && 0 === substr_compare( $sql, ' WHERE ', $i, 7, true ) ) {
                    return $i;
                }
            }
            return false;
        }

        /**
         * Pull `col = 'literal'` pairs out of a fragment, in order.
         *
         * The literal pattern accepts backslash escapes, so a value containing
         * quotes — every serialised option, for instance — survives the round
         * trip instead of terminating the match early.
         *
         * The parser is hand-written rather than a regular expression
         * because the values are not small. The transaction row carries the
         * whole configuration, and the configuration may carry a custom
         * stylesheet of up to 256 KiB; a regex over a literal that long
         * exhausted PCRE's JIT stack and returned false, so the guard was
         * never parsed and every guarded write reported "no rows matched"
         * for a row that had not moved. A parser that walks the string once
         * has no such ceiling.
         */
        private function conditions( $sql ) {
            $pairs = array();
            $len   = strlen( $sql );
            $i     = 0;
            while ( $i < $len ) {
                // The name is the identifier before the '=', with any
                // whitespace between them allowed.
                $eq = strpos( $sql, '=', $i );
                if ( false === $eq ) {
                    break;
                }
                $j = $eq - 1;
                while ( $j >= 0 && ctype_space( $sql[ $j ] ) ) {
                    $j--;
                }
                $name = '';
                for ( ; $j >= 0; $j-- ) {
                    $c = $sql[ $j ];
                    if ( ! ( ctype_alnum( $c ) || '_' === $c ) ) {
                        break;
                    }
                    $name = $c . $name;
                }
                // Skip the whitespace and the opening quote.
                $k = $eq + 1;
                while ( $k < $len && ctype_space( $sql[ $k ] ) ) {
                    $k++;
                }
                if ( '' === $name || $k >= $len || "'" !== $sql[ $k ] ) {
                    $i = $eq + 1;
                    continue;
                }
                $k++;
                // Read the literal, honouring backslash escapes.
                $value = '';
                while ( $k < $len ) {
                    $c = $sql[ $k ];
                    if ( '\\' === $c && $k + 1 < $len ) {
                        $next = $sql[ $k + 1 ];
                        if ( '0' === $next ) {
                            $value .= "\0";
                        } else {
                            $value .= $next;
                        }
                        $k += 2;
                        continue;
                    }
                    if ( "'" === $c ) {
                        break;
                    }
                    $value .= $c;
                    $k++;
                }
                if ( ! array_key_exists( $name, $pairs ) ) {
                    $pairs[ $name ] = $value;
                }
                $i = $k + 1;
            }
            return $pairs;
        }
        public function query( $sql ) {
            if ( $GLOBALS['_agentshell_test_audit_fail'] ) {
                throw new \RuntimeException( 'wpdb query failure (test)' );
            }

            // ── Compare-and-swap on wp_options ────────────────────────────
            // The transaction lease guards every write with the row's current
            // bytes and has to know which side won. A stub that ignored the
            // guard would let every concurrency assertion pass for the wrong
            // reason — the code under test would look correct while the thing
            // that makes it correct was never exercised. So these two shapes
            // are honoured precisely, *including* the losing case: a guard
            // that does not match what is stored now affects no rows, exactly
            // as the database would report.
            if ( preg_match( '/^UPDATE\s+\S*options\s+SET\s/i', $sql ) ) {
                $split = $this->top_level_where( $sql );
                if ( false === $split ) { return 0; }
                return $this->cas_apply(
                    $this->conditions( substr( $sql, 0, $split ) ),
                    $this->conditions( substr( $sql, $split ) ),
                    true
                );
            }
            if ( preg_match( '/^DELETE\s+FROM\s+\S*options\s+WHERE\s+option_name/i', $sql ) ) {
                $split = $this->top_level_where( $sql );
                if ( false === $split ) { return 0; }
                return $this->cas_apply( array(), $this->conditions( substr( $sql, $split ) ), false );
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

        /**
         * Apply a guarded write to the in-memory option store.
         *
         * The guard is `option_value = '<the bytes the caller read>'`, compared
         * against what is stored *now*, after serialisation. Returning 0 when
         * it does not match is the entire mechanism by which a losing racer
         * learns it lost, so it is not a detail of the stub.
         */
        private function cas_apply( array $set, array $where, $is_update ) {
            // The lifecycle tests use a side-channel to force cas_delete_row
            // to fail at the row-moved branch: tampered option_value (so the
            // guard does not match) is the same shape a competing commit or
            // rollback would observe in the database. When the test asks for
            // it, the deletion returns 0 and the row is preserved.
            if ( ! $is_update && ! empty( $GLOBALS['_agentshell_test_cas_delete_fail'] ) ) {
                $GLOBALS['_agentshell_test_cas_delete_calls'] = ( $GLOBALS['_agentshell_test_cas_delete_calls'] ?? 0 ) + 1;
                return 0;
            }
            // The head-pointer tests use a side-channel to force cas_write_row
            // to fail at the row-moved branch: the same shape a concurrent
            // caller would observe if it swapped the row between the
            // compare_and_set_head() read and the cas_write_row() write. A
            // CAS-less mutant would not notice the failure and would return
            // $next as if the swap had landed.
            //
            // Two opt-in shapes:
            //   - `_agentshell_test_cas_write_fail = true` → every cas_write_row
            //     returns 0. The first CAS in a tool (attach) is the one that
            //     fails, which is what the *attach* path is tested against.
            //   - `_agentshell_test_cas_write_fail_after = N` → the first N
            //     cas_write_row calls succeed, the (N+1)th and later return
            //     0. The Nth is the boundary that lets a test exercise a CAS
            //     that lives inside the closure (compare_and_set_head or
            //     mark_applied) while the attach CAS still lands. The counter
            //     is shared with `_agentshell_test_cas_write_calls` so the
            //     test can assert *which* call failed.
            if ( $is_update && ! empty( $GLOBALS['_agentshell_test_cas_write_fail'] ) ) {
                $GLOBALS['_agentshell_test_cas_write_calls'] = ( $GLOBALS['_agentshell_test_cas_write_calls'] ?? 0 ) + 1;
                $fail_after = isset( $GLOBALS['_agentshell_test_cas_write_fail_after'] )
                    ? (int) $GLOBALS['_agentshell_test_cas_write_fail_after'] : -1;
                if ( $fail_after < 0 || $GLOBALS['_agentshell_test_cas_write_calls'] > $fail_after ) {
                    return 0;
                }
            }
            $options = $GLOBALS['_agentshell_test_options'];
            $name    = isset( $where['option_name'] ) ? $where['option_name'] : null;
            if ( null === $name || ! array_key_exists( $name, $options ) ) { return 0; }
            if ( ! isset( $where['option_value'] ) ) { return 0; }
            if ( maybe_serialize( $options[ $name ] ) !== $where['option_value'] ) { return 0; }
            if ( $is_update ) {
                if ( ! isset( $set['option_value'] ) ) { return 0; }
                $next = maybe_unserialize( $set['option_value'] );
                // MySQL reports the number of rows *changed*, not the number
                // of rows matched. An UPDATE that writes the bytes already
                // stored therefore affects ZERO rows — the same value, the
                // same guard, and the statement still reports "nothing
                // happened".
                //
                // The stub honours that, because a stub that always reported 1
                // would hide the ambiguity the compare-and-swap has to
                // resolve: zero affected rows means either "the row moved" or
                // "the write was a no-op", and only the database's own
                // semantics tell the two apart. A stub that reported 1 for
                // a no-op write made a whole class of defect invisible.
                if ( maybe_serialize( $next ) === maybe_serialize( $options[ $name ] ) ) {
                    return 0;
                }
                $options[ $name ] = $next;
            } else {
                // Side-channel: snapshot the row at the moment the cas
                // succeeds but before the row is unlinked. The lifecycle
                // tests use this to read the post-revalidation op state
                // (e.g. an `applied` op that just transitioned to
                // `compensated` because the post is gone).
                $GLOBALS['_agentshell_test_cas_snapshot'] = $options[ $name ];
                unset( $options[ $name ] );
            }
            $GLOBALS['_agentshell_test_options'] = $options;
            return 1;
        }

        public function get_var( $sql ) {
            if ( $GLOBALS['_agentshell_test_audit_fail'] ) {
                throw new \RuntimeException( 'wpdb get_var failure (test)' );
            }
            if ( preg_match( "/SELECT COUNT\(\*\) FROM/i", $sql ) ) {
                return count( $GLOBALS['_agentshell_test_audit_table'] );
            }
            // Advisory lock probe and acquire: the stub pretends the database
            // supports GET_LOCK and grants the lock instantly (timeout 0).
            // Tests that need a different answer (held, NULL, error) override
            // this in a subclass.
            if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                return '1';
            }
            if ( preg_match( '/RELEASE_LOCK\s*\(/i', (string) $sql ) ) {
                return '1';
            }
            // The raw bytes of a stored option. The compare-and-swap reads
            // the row back to tell a no-op write (the guard matched, the
            // value did not change) from a lost race (the guard did not
            // match), and that read has to return the *serialised* bytes —
            // the same bytes the UPDATE's WHERE clause compares against.
            if ( preg_match( '/SELECT\s+option_value\s+FROM\s+\S*options\s+WHERE\s+option_name/i', (string) $sql ) ) {
                $where = $this->conditions( (string) $sql );
                $name  = isset( $where['option_name'] ) ? $where['option_name'] : null;
                if ( null === $name || ! array_key_exists( $name, $GLOBALS['_agentshell_test_options'] ) ) {
                    return null;
                }
                return maybe_serialize( $GLOBALS['_agentshell_test_options'][ $name ] );
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
 * Posts, users and the fake main query.
 *
 * Shared because two suites need the same model of "what the request resolved
 * to", and a second copy would drift from the first — the point of the fixture
 * is that a suite cannot assert against its own private idea of WordPress.
 *
 * authenticate() requires get_userdata() to still resolve the actor, so a
 * deleted account is representable.
 */

$GLOBALS['_agentshell_test_posts'] = array();
$GLOBALS['_agentshell_test_users'] = array();

function agentshell_test_add_post( $id, $status ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = (object) array(
        'ID'          => (int) $id,
        'post_status' => $status,
        'post_type'   => 'page',
        'post_title'  => "Post {$id}",
    );
}
function agentshell_test_add_user( $id ) {
    $GLOBALS['_agentshell_test_users'][ (int) $id ] = (object) array(
        'ID'           => (int) $id,
        'user_login'   => "user{$id}",
    );
}
function get_post( $id = null ) {
    if ( null === $id ) { return $GLOBALS['post'] ?? null; }
    return $GLOBALS['_agentshell_test_posts'][ (int) $id ] ?? null;
}
function get_userdata( $id ) {
    return $GLOBALS['_agentshell_test_users'][ (int) $id ] ?? false;
}

// ── Query surface the binding gate reads ─────────────────────────────────────
// A fake WP_Query whose resolved object the test controls, so "the credential
// was valid but the request resolved somewhere else" is expressible.

class Agentshell_Test_Query {
    public $posts = array();
    public $post = null;
    public $post_count = 0;
    public $found_posts = 0;
    public $is_singular = true;
    public $is_page = true;
    public $is_single = false;
    public $is_404 = false;
    public function set_404() { $this->is_404 = true; }
}

$GLOBALS['_agentshell_test_query'] = new Agentshell_Test_Query();

if ( ! function_exists( 'WP_User' ) ) {
    // Minimal stand-in. Store::actor() only reads ID and user_login, and
    // suites that need a specific principal set $GLOBALS['_agentshell_test_login'].
    class WP_User {
        public $ID = 1;
        public $user_login = 'agent';
    }
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
    /**
     * Store::actor() reads this to name whoever is mutating config, and a
     * transaction cannot even be opened without it. Three suites each defined
     * it locally; hoisting one definition here means a suite reaching the same
     * code path standalone fails for its own reason rather than an undefined
     * function.
     *
     * WP_User is declared just above, so this always resolves to a real user
     * whose login comes from the shared fixture — which is what lets a suite
     * that stores captures per-actor still get distinct principals.
     */
    function wp_get_current_user() {
        $u = new WP_User();
        $u->ID = 1;
        $u->user_login = $GLOBALS['_agentshell_test_login'] ?? 'agent';
        return $u;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    /**
     * Core accepts two call shapes — add_query_arg('k', $v, $url) and
     * add_query_arg(array('k' => $v), $url) — and both are used by the paths
     * under test. Defined once, here, because suites share a process: a
     * per-suite definition is either a redeclare fatal or, worse, a
     * two-argument version that silently breaks the three-argument callers.
     */
    function add_query_arg( ...$a ) {
        if ( is_array( $a[0] ) ) {
            $args = $a[0];
            $url  = $a[1];
        } else {
            $args = array( $a[0] => $a[1] );
            $url  = $a[2];
        }
        $sep = str_contains( $url, '?' ) ? '&' : '?';
        return $url . $sep . http_build_query( $args );
    }
}

function get_queried_object_id() {
    $q = $GLOBALS['_agentshell_test_query'];
    return $q->post ? (int) $q->post->ID : 0;
}
function is_singular() { return (bool) $GLOBALS['_agentshell_test_query']->is_singular; }

/**
 * Put the fake main query into the state it would hold after wp() resolved a
 * request to $resolved_id (or to nothing, for an archive / 404).
 */
function agentshell_test_resolve_query( $resolved_id ) {
    $q = new Agentshell_Test_Query();
    if ( null !== $resolved_id ) {
        $post = get_post( $resolved_id );
        if ( $post ) {
            $q->post = $post;
            $q->posts = array( $post );
            $q->post_count = 1;
            $q->found_posts = 1;
        }
    }
    $GLOBALS['_agentshell_test_query'] = $q;
    return $q;
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
if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter() {}
}
if ( ! function_exists( 'remove_action' ) ) {
    function remove_action() {}
}

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
    $GLOBALS['_agentshell_test_audit_fail_for']   = null;
    $GLOBALS['_agentshell_test_cas_delete_fail']  = false;
    $GLOBALS['_agentshell_test_cas_delete_calls'] = 0;
    $GLOBALS['_agentshell_test_cas_write_fail']   = false;
    $GLOBALS['_agentshell_test_cas_write_fail_after'] = -1;
    $GLOBALS['_agentshell_test_cas_write_calls']  = 0;
    $GLOBALS['_agentshell_test_cas_snapshot']     = null;
    $GLOBALS['_agentshell_test_sidebars'] = array();
    $GLOBALS['_agentshell_test_sidebar_widgets'] = array();
    $GLOBALS['_agentshell_test_dynamic_sidebar_calls'] = array();
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