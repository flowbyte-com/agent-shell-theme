<?php
/**
 * The response headers of the most recent HTTP fetch.
 *
 * PHP 8.5 deprecated the *implicitly local* `$http_response_header`: inside
 * a function, reading it raises a deprecation notice. In the daemon that
 * notice would be printed into the JSON-RPC stream on stdout, where a
 * strict MCP client reads it as a malformed message; in a live suite it
 * lands in the middle of the evidence the suite reports.
 *
 * The function form is used where it exists (PHP 8.5 and later). Before
 * that, the headers live in the *caller's* local scope, so the caller
 * hands them in: a function cannot see another function's locals, and
 * the headers are not in $GLOBALS.
 *
 * This lives in its own file because it is needed by two kinds of caller:
 * the unit bootstrap and the live child scripts, which run inside
 * WordPress and do not load the bootstrap.
 *
 * @param array|null $local The caller's own $http_response_header, passed
 *                          by reference so the caller's scope is preserved.
 * @return array<int,string> The response headers, status line first.
 */

if ( ! function_exists( 'agentshell_test_last_response_headers' ) ) {
    function agentshell_test_last_response_headers( &$local = null ) {
        if ( function_exists( 'http_get_last_response_headers' ) ) {
            return (array) http_get_last_response_headers();
        }
        return is_array( $local ) ? $local : array();
    }
}
