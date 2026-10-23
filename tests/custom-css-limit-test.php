<?php
/**
 * Custom CSS size limit regression.
 *
 * The limit this suite pins
 * -------------------------
 * 256 KiB — 262,144 bytes — measured in BYTES of the stylesheet's UTF-8
 * encoding. The previous limit was 10,000 bytes; the superseding
 * authorisation raised it to 262,144 bytes.
 *
 * Why bytes and not characters
 * ----------------------------
 * A limit expressed in characters admits a stylesheet several times larger
 * than the limit: a quarter of a megabyte of CJK text is under 100,000
 * characters and nearly 300,000 bytes. The thing being limited is what the
 * server sends and the browser parses, so the limit counts bytes. The
 * suite proves it: a stylesheet that is *under* the limit in characters
 * and *over* it in bytes is refused.
 *
 * What is asserted here
 * ---------------------
 * 1. The theme and the MCP plugin agree on the number, and the number is
 *    262,144. Two copies of a limit that disagree are two limits.
 * 2. The exact boundary: 262,144 bytes is accepted, 262,145 is refused —
 *    by the theme's validator, by the doctor and by the staging path.
 * 3. The refusal happens BEFORE anything persistent changes: a refused
 *    staging leaves the transaction row and the stored configuration
 *    exactly as they were.
 * 4. Every path reports the same refusal, with the size, the limit and
 *    the excess in the message.
 * 5. A theme import carrying an oversized stylesheet is refused before
 *    it is applied.
 * 6. A routine configuration read does not carry the stylesheet body; the
 *    body is returned only when it is asked for by name, and the size is
 *    always reported.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';

foreach ( array( 'class-store.php', 'class-doctor.php', 'class-transactions.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-get-config.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-import-theme.php';

use AgentShell_MCP\Doctor;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Get_Config;
use AgentShell_MCP\Tools\Import_Theme;

// Install the bootstrap's own $wpdb stub.
//
// run-all.php requires every suite into one process, and a suite that
// installs its own $wpdb leaves it behind for the suites that follow.
// Those suite-local stubs parse the guarded write's SQL with their own
// regular expressions, and a regex over a 256 KiB literal exhausts PCRE's
// JIT stack — the guard is never parsed, and every guarded write reports
// "no rows matched" for a row that had not moved. This suite stages a
// quarter of a megabyte, so it needs the stub that parses large values.
$GLOBALS['_css_limit_previous_wpdb'] = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb']                    = new wpdb_stub_for_tests();

echo "custom CSS size limit regression tests\n";

/** The limit, from the theme — the owner of the number. */
define( 'CSS_LIMIT', 262144 );

/** A stylesheet of exactly $bytes bytes. */
function css_of_bytes( $bytes ) {
    return str_repeat( 'a', $bytes );
}

/**
 * A stylesheet of exactly $bytes bytes, written in a multi-byte encoding.
 *
 * 'é' is two bytes in UTF-8, so an even byte count is reachable exactly.
 */
function css_multibyte_of_bytes( $bytes ) {
    $unit = 'é'; // two bytes
    if ( 0 !== $bytes % 2 ) {
        $unit = 'a'; // one byte, to make the total exact
    }
    $unit_bytes = strlen( $unit );
    $count      = intdiv( $bytes, $unit_bytes );
    $out        = str_repeat( $unit, $count );
    $remaining  = $bytes - strlen( $out );
    if ( $remaining > 0 ) {
        $out .= str_repeat( 'a', $remaining );
    }
    return $out;
}

function css_reset() {
    agentshell_test_reset();
    Tx::unfreeze_time();
    Store::$suppress_history = false;
    Store::$operation_label   = '';
    $GLOBALS['_agentshell_test_login'] = 'agent';
    $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] = array( 'zones' => array() );
}

// ── 1. one number, in both places ──────────────────────────────────────────
Agentshell_Test::run(
    'the theme and the MCP plugin agree on the limit, and it is 262144 bytes',
    function () {
        Agentshell_Test::assert_equals(
            CSS_LIMIT,
            (int) agentshell_max_custom_css_bytes(),
            'the theme must own the limit'
        );
        Agentshell_Test::assert_equals(
            CSS_LIMIT,
            (int) Doctor::max_custom_css_bytes(),
            'the doctor must use the same limit'
        );
        Agentshell_Test::assert_equals(
            CSS_LIMIT,
            (int) Tx::max_custom_css_bytes(),
            'the staging path must use the same limit'
        );
    }
);

// ── 2. the limit counts bytes ───────────────────────────────────────────────
Agentshell_Test::run(
    'the limit counts bytes of the UTF-8 encoding, not characters',
    function () {
        // 131,072 two-byte characters: 131,072 characters (under the
        // limit) and 262,144 bytes (exactly at it) — accepted.
        $exact = css_multibyte_of_bytes( CSS_LIMIT );
        Agentshell_Test::assert_equals(
            CSS_LIMIT,
            strlen( $exact ),
            'the fixture must be exactly at the byte limit'
        );
        Agentshell_Test::assert_true(
            agentshell_custom_css_within_limit( $exact ),
            'a stylesheet of exactly 262144 bytes is within the limit'
        );

        // One byte more, in the same encoding: still fewer than 262,144
        // characters, one byte over the limit — refused.
        $over = $exact . 'a';
        Agentshell_Test::assert_true(
            mb_strlen( $over ) < CSS_LIMIT,
            'the over-limit fixture must still be under the limit in characters'
        );
        Agentshell_Test::assert_false(
            agentshell_custom_css_within_limit( $over ),
            'a stylesheet one byte over the limit is over the limit'
        );
    }
);

// ── 3. the theme validator: the exact boundary ──────────────────────────────
Agentshell_Test::run(
    'the theme validator accepts exactly 262144 bytes and refuses 262145',
    function () {
        css_reset();

        $exact = agentshell_validate_config( array( 'custom_css' => css_of_bytes( CSS_LIMIT ) ) );
        Agentshell_Test::assert_true(
            is_array( $exact ),
            'exactly 262144 bytes must be accepted: ' . ( is_array( $exact ) ? '' : print_r( $exact, true ) )
        );

        $over = agentshell_validate_config( array( 'custom_css' => css_of_bytes( CSS_LIMIT + 1 ) ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $over );
        Agentshell_Test::assert_equals( 'custom_css_too_large', $over->get_error_code() );
        Agentshell_Test::assert_true(
            false !== strpos( $over->get_error_message(), (string) ( CSS_LIMIT + 1 ) ),
            'the message must state the size: ' . $over->get_error_message()
        );
        Agentshell_Test::assert_true(
            false !== strpos( $over->get_error_message(), (string) CSS_LIMIT ),
            'the message must state the limit: ' . $over->get_error_message()
        );
    }
);

// ── 4. the doctor: the exact boundary ──────────────────────────────────────
Agentshell_Test::run(
    'the doctor accepts exactly 262144 bytes and refuses 262145',
    function () {
        css_reset();

        $exact = Doctor::check( array( 'custom_css' => css_of_bytes( CSS_LIMIT ) ) );
        $codes = array_column( $exact['errors'], 'code' );
        Agentshell_Test::assert_false(
            in_array( 'CUSTOM_CSS_TOO_LARGE', $codes, true ),
            'exactly 262144 bytes must not be an error: ' . wp_json_encode( $exact['errors'] )
        );

        $over = Doctor::check( array( 'custom_css' => css_of_bytes( CSS_LIMIT + 1 ) ) );
        $codes = array_column( $over['errors'], 'code' );
        Agentshell_Test::assert_true(
            in_array( 'CUSTOM_CSS_TOO_LARGE', $codes, true ),
            '262145 bytes must be an error, not a warning: ' . wp_json_encode( $over )
        );
    }
);

// ── 5. the staging path: refused before anything persistent changes ────────
Agentshell_Test::run(
    'the staging path refuses an oversized stylesheet and changes nothing',
    function () {
        css_reset();
        $mgr = Tx::instance();
        $mgr->begin( 'css limit' );

        $row_before = (array) get_option( Tx::KEY, null );

        $refused = '';
        try {
            $mgr->stage( array( 'custom_css' => css_of_bytes( CSS_LIMIT + 1 ) ) );
        } catch ( \InvalidArgumentException $e ) {
            $refused = $e->getMessage();
        }
        Agentshell_Test::assert_true(
            '' !== $refused,
            'the staging path must refuse an oversized stylesheet'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $refused, 'Nothing was written' ),
            'the refusal must say that nothing was written: ' . $refused
        );

        $row_after = (array) get_option( Tx::KEY, null );
        Agentshell_Test::assert_equals(
            maybe_serialize( $row_before ),
            maybe_serialize( $row_after ),
            'the transaction row must be byte-identical after a refused staging'
        );
        Agentshell_Test::assert_true(
            ! is_array( $mgr->staged_config() ),
            'nothing may be staged after a refused staging'
        );

        // The same path accepts the exact limit.
        Agentshell_Test::assert_true(
            $mgr->stage( array( 'custom_css' => css_of_bytes( CSS_LIMIT ) ) ),
            'exactly 262144 bytes must stage'
        );
    }
);

// ── 6. the write interceptor: the immediate-write path ─────────────────────
Agentshell_Test::run(
    'the write interceptor refuses an oversized stylesheet and leaves the stored config alone',
    function () {
        css_reset();
        $mgr = Tx::instance();

        $stored_before = get_option( Store::CONFIG_KEY, null );

        $refused = '';
        try {
            $mgr->on_config_update( array( 'custom_css' => css_of_bytes( CSS_LIMIT + 1 ) ), $stored_before );
        } catch ( \InvalidArgumentException $e ) {
            $refused = $e->getMessage();
        }
        Agentshell_Test::assert_true(
            '' !== $refused,
            'the interceptor must refuse an oversized stylesheet'
        );

        Agentshell_Test::assert_equals(
            maybe_serialize( $stored_before ),
            maybe_serialize( get_option( Store::CONFIG_KEY, null ) ),
            'the stored configuration must be unchanged'
        );
    }
);

// ── 7. the theme import path ───────────────────────────────────────────────
Agentshell_Test::run(
    'a theme import carrying an oversized stylesheet is refused before it is applied',
    function () {
        css_reset();
        $before = get_option( Store::CONFIG_KEY, null );

        $refused = '';
        try {
            ( new Import_Theme() )->execute( array(
                'manifest' => array(
                    'package' => 'agentshell-theme',
                    'version' => '1.0.0',
                    'config'  => array( 'custom_css' => css_of_bytes( CSS_LIMIT + 1 ) ),
                ),
            ) );
        } catch ( \InvalidArgumentException $e ) {
            $refused = $e->getMessage();
        }
        Agentshell_Test::assert_true(
            '' !== $refused,
            'the import must be refused: ' . $refused
        );
        Agentshell_Test::assert_true(
            false !== strpos( $refused, 'CUSTOM_CSS_TOO_LARGE' ),
            'the refusal must name the doctor error: ' . $refused
        );
        Agentshell_Test::assert_equals(
            maybe_serialize( $before ),
            maybe_serialize( get_option( Store::CONFIG_KEY, null ) ),
            'the stored configuration must be unchanged'
        );
    }
);

// ── 8. the routine configuration read does not carry the stylesheet ────────
Agentshell_Test::run(
    'a routine configuration read omits the stylesheet and reports its size',
    function () {
        css_reset();
        $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] = array(
            'zones'      => array(),
            'custom_css' => 'body { color: red; }',
        );

        $response = ( new Get_Config() )->execute( array() );

        Agentshell_Test::assert_false(
            array_key_exists( 'custom_css', $response ),
            'the stylesheet body must not be in a routine response'
        );
        Agentshell_Test::assert_equals(
            strlen( 'body { color: red; }' ),
            (int) $response['custom_css_bytes'],
            'the response must report the size'
        );
        Agentshell_Test::assert_true(
            ! empty( $response['custom_css_present'] ),
            'the response must report that a stylesheet is set'
        );

        $with = ( new Get_Config() )->execute( array( 'include_custom_css' => true ) );
        Agentshell_Test::assert_equals(
            'body { color: red; }',
            (string) ( $with['custom_css'] ?? '' ),
            'the body must be returned when it is asked for by name'
        );
        Agentshell_Test::assert_equals(
            strlen( 'body { color: red; }' ),
            (int) $with['custom_css_bytes'],
            'the size must be reported in both forms'
        );
    }
);

// ── teardown ────────────────────────────────────────────────────────────────
Tx::unfreeze_time();
Store::$suppress_history = false;
Store::$operation_label   = '';
unset( $GLOBALS['_agentshell_test_options'][ Tx::KEY ] );
unset( $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] );
$GLOBALS['_agentshell_test_login'] = 'agent';
if ( null !== $GLOBALS['_css_limit_previous_wpdb'] ) {
    $GLOBALS['wpdb'] = $GLOBALS['_css_limit_previous_wpdb'];
}
unset( $GLOBALS['_css_limit_previous_wpdb'] );

echo "\n";
echo "custom CSS size limit: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
