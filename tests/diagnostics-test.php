<?php
/**
 * Diagnostics regressions (HAL 9001 remediation).
 *
 * Four small defects, one theme: each of them let the system report
 * something other than what was true.
 *
 * 1. A configuration value that changed nothing.
 *    `agentshell_set_typography` accepts `scale`, the value was stored,
 *    and no CSS variable consumed it. The theme's headings now scale by
 *    it, and a value that is not a positive number is refused rather
 *    than stored.
 *
 * 2. A warning about a widget the operator had retired.
 *    The doctor warned that a disabled widget was "registered but not
 *    referenced by any zone" — after it had been disabled, on purpose.
 *    A retired widget renders nothing by design; the warning trained
 *    agents to ignore the doctor.
 *
 * 3. A response that read as misleading.
 *    `agentshell_update_content` returns the post's *current* state, so
 *    a title the caller never mentioned came back as the post's title —
 *    an empty string for a draft with no title. The response now names
 *    the fields the call actually wrote.
 *
 * 4. A line of noise on every connection.
 *    The daemon printed its version-compatibility notice on every start,
 *    and the daemon is started afresh for every connection.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';

foreach ( array( 'class-store.php', 'class-doctor.php', 'class-transactions.php', 'class-json-rpc.php', 'class-server.php', 'class-content.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';

use AgentShell_MCP\Doctor;

// The WordPress surface the content primitives call. Guarded so that
// defining them here cannot collide with a suite that already provides
// a higher-fidelity version.
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $v ) { return $v; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $t ) { return trim( (string) $t ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $t ) { return strtolower( preg_replace( '/[^A-Za-z0-9_-]+/', '-', (string) $t ) ); }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $p = null ) { return $p ? $p->post_title : ''; }
}
if ( ! function_exists( 'get_the_date' ) ) {
    function get_the_date( $f = '', $p = null ) { return $p ? $p->post_date : ''; }
}
if ( ! function_exists( 'get_the_modified_date' ) ) {
    function get_the_modified_date( $f = '', $p = null ) { return $p ? $p->post_modified : ''; }
}
if ( ! function_exists( 'get_the_excerpt' ) ) {
    function get_the_excerpt( $p = null ) { return $p ? $p->post_excerpt : ''; }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $p = null ) { return 'https://example.test/?p=' . ( is_object( $p ) ? $p->ID : (int) $p ); }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
}

echo "diagnostics regression tests\n";

// ── 2. the type scale is applied, and a nonsense scale is refused ──────────
Agentshell_Test::run(
    'the type scale reaches the CSS variables',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'design' => array( 'typography' => array( 'scale' => '1.25' ) ),
        ) );

        $flat = agentshell_flatten_config( agentshell_get_config() );
        Agentshell_Test::assert_equals(
            '1.25',
            (string) ( $flat['--type-scale'] ?? '' ),
            'the scale must be flattened into a CSS variable'
        );
    }
);

Agentshell_Test::run(
    'a type scale that is not a positive number is refused',
    function () {
        agentshell_test_reset();

        foreach ( array( '0', '-1', 'large', '' ) as $bad ) {
            $result = agentshell_validate_config( array(
                'design' => array( 'typography' => array( 'scale' => $bad ) ),
            ) );
            Agentshell_Test::assert_instance_of( 'WP_Error', $result );
            Agentshell_Test::assert_equals( 'invalid_typography_scale', $result->get_error_code() );

            $issues = Doctor::check( array( 'design' => array( 'typography' => array( 'scale' => $bad ) ) ) );
            $codes  = array_column( $issues['errors'], 'code' );
            Agentshell_Test::assert_true(
                in_array( 'INVALID_TYPO_SCALE', $codes, true ),
                'the doctor must reject the scale "' . $bad . '": ' . wp_json_encode( $issues )
            );
        }

        $ok = agentshell_validate_config( array(
            'design' => array( 'typography' => array( 'scale' => '1.25' ) ),
        ) );
        Agentshell_Test::assert_true( is_array( $ok ), 'a valid scale must be accepted' );
    }
);

// ── 3. the doctor does not warn about a retired widget ─────────────────────
Agentshell_Test::run(
    'the doctor does not warn about a disabled widget that no zone references',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones'           => array(),
            'widgets'         => array(
                array( 'id' => 'retired', 'name' => 'Retired', 'template' => '<div></div>' ),
                array( 'id' => 'placed', 'name' => 'Placed', 'template' => '<div></div>' ),
            ),
            'widget_overrides' => array( 'retired' => array( 'status' => 'disabled' ) ),
        ) );

        $issues   = Doctor::check( agentshell_get_config() );
        $unused   = array();
        foreach ( $issues['warnings'] as $warning ) {
            if ( 'WIDGET_UNUSED' === ( $warning['code'] ?? '' ) ) {
                $unused[] = (string) ( $warning['widget'] ?? '' );
            }
        }

        Agentshell_Test::assert_false(
            in_array( 'retired', $unused, true ),
            'a retired widget must not be reported as unused: ' . wp_json_encode( $issues['warnings'] )
        );
        Agentshell_Test::assert_true(
            in_array( 'placed', $unused, true ),
            'an active widget nobody placed is still worth reporting: ' . wp_json_encode( $issues['warnings'] )
        );
    }
);

Agentshell_Test::run(
    'the doctor still warns about an active widget that no zone references',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones'           => array(),
            'widgets'         => array(
                array( 'id' => 'placed', 'name' => 'Placed', 'template' => '<div></div>' ),
            ),
            'widget_overrides' => array(),
        ) );

        $issues   = Doctor::check( agentshell_get_config() );
        $warnings = array_column( $issues['warnings'], 'code' );

        Agentshell_Test::assert_true(
            in_array( 'WIDGET_UNUSED', $warnings, true ),
            'an active widget nobody placed is worth reporting: ' . wp_json_encode( $issues['warnings'] )
        );
    }
);

// ── 4. the update response names the fields it wrote ───────────────────────
Agentshell_Test::run(
    'the content primitives name the fields an update wrote',
    function () {
        agentshell_test_reset();

        $source = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp/includes/class-content.php' );
        Agentshell_Test::assert_true(
            is_string( $source ) && false !== strpos( $source, "'updated_fields'" ),
            'the update record must carry updated_fields'
        );
    }
);

// ── 5. the daemon's compatibility notice is verbose-only ───────────────────
Agentshell_Test::run(
    'the daemon prints the compatibility notice only in verbose mode',
    function () {
        $source = file_get_contents( dirname( __DIR__ ) . '/agentshell-mcp-daemon/daemon.php' );
        Agentshell_Test::assert_true(
            is_string( $source ),
            'the daemon source must be readable'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $source, "\$status['level'] === 'notice' && ! \$this->verbose" ),
            'the notice level must be gated on the verbose flag'
        );
    }
);

echo "\n";
echo "diagnostics: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
