<?php
/**
 * Hardening tests — persistent sinks and request boundaries.
 *
 * Invariants under test:
 *   1. A custom_js value containing literal </script> cannot break out of
 *      the wrapping <script> tag (defense-in-depth on top of the
 *      unfiltered_html capability gate).
 *   2. A custom_css value containing literal </style> cannot break out of
 *      the wrapping <style> tag.
 *   3. The MCP JSON-RPC parser rejects bodies larger than a sane ceiling
 *      with a structured error rather than allocating gigabytes.
 *   4. The theme REST PUT applies the 10KB custom_js / custom_css cap
 *      that the MCP tool schema advertises.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-json-rpc.php';

echo "Hardening regression tests — sinks + body limits\n";

Agentshell_Test::run(
    'custom_js embedding escapes literal </script>',
    function () {
        $payload = "alert('x')</script><script>fetch('evil')</script>";
        $escaped = agentshell_harden_custom_js( $payload );
        // The literal close-tag must be neutralised so the wrapping <script>
        // element does not terminate early.
        Agentshell_Test::assert_true(
            strpos( $escaped, '</script' ) === false,
            'raw </script> must not survive hardening'
        );
        Agentshell_Test::assert_true(
            strpos( $escaped, '<\\/script' ) !== false,
            'hardened output should contain <\\/script for in-tag safety'
        );
    }
);

Agentshell_Test::run(
    'custom_css embedding escapes literal </style>',
    function () {
        $payload = "body{color:red}</style><script>alert(1)</script>";
        $escaped = agentshell_harden_custom_css( $payload );
        Agentshell_Test::assert_true(
            strpos( $escaped, '</style' ) === false,
            'raw </style> must not survive hardening'
        );
        Agentshell_Test::assert_true(
            strpos( $escaped, '<\\/style' ) !== false,
            'hardened output should contain <\\/style'
        );
    }
);

Agentshell_Test::run(
    'custom_js hardening is a no-op on safe payloads',
    function () {
        $payload = "console.log('hello'); window.foo = 1;";
        Agentshell_Test::assert_equals(
            $payload,
            agentshell_harden_custom_js( $payload )
        );
    }
);

Agentshell_Test::run(
    'custom_css hardening preserves ordinary CSS',
    function () {
        $payload = "body { background: #fff; color: #000; }";
        Agentshell_Test::assert_equals(
            $payload,
            agentshell_harden_custom_css( $payload )
        );
    }
);

Agentshell_Test::run(
    'JSON-RPC parser rejects oversized bodies',
    function () {
        $big = str_repeat( 'a', 2 * 1024 * 1024 ); // 2 MiB
        $result = AgentShell_MCP\JSON_RPC::parse_request( $big );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'request_too_large', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'JSON-RPC parser accepts bodies under the limit',
    function () {
        $result = AgentShell_MCP\JSON_RPC::parse_request( '{"jsonrpc":"2.0","id":1,"method":"ping"}' );
        Agentshell_Test::assert_true( is_array( $result ) );
    }
);

Agentshell_Test::run(
    'JSON-RPC parser still rejects malformed JSON',
    function () {
        $result = AgentShell_MCP\JSON_RPC::parse_request( '{not json' );
        Agentshell_Test::assert_instance_of( 'WP_Error', $result );
        Agentshell_Test::assert_equals( 'parse_error', $result->get_error_code() );
    }
);

Agentshell_Test::run(
    'theme REST PUT enforces custom_js / custom_css size cap',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 1, array(
            'manage_options'      => true,
            'edit_theme_options'  => true,
            'unfiltered_html'     => true,
        ) );

        $oversize = str_repeat( 'a', 11 * 1024 ); // 11 KiB, over the 10 KiB cap
        $validated = agentshell_validate_config( array(
            'custom_js' => $oversize,
        ) );
        Agentshell_Test::assert_instance_of( 'WP_Error', $validated );
        Agentshell_Test::assert_equals( 'custom_js_too_large', $validated->get_error_code() );
    }
);

Agentshell_Test::run(
    'theme REST PUT accepts custom_js at the 10000-byte cap',
    function () {
        agentshell_test_reset();
        agentshell_test_set_user( 1, array(
            'manage_options'      => true,
            'edit_theme_options'  => true,
            'unfiltered_html'     => true,
        ) );

        // The schema declares maxLength: 10000 (matching the MCP tool schema).
        // 10001 bytes must be rejected; exactly 10000 bytes must be accepted.
        $exact = str_repeat( 'a', 10000 );
        $validated = agentshell_validate_config( array(
            'custom_js' => $exact,
        ) );
        Agentshell_Test::assert_true( is_array( $validated ) );
    }
);