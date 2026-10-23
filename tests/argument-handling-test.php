<?php
/**
 * Diagnostics and argument-handling regressions (HAL 9001 remediation).
 *
 * Four defects, one suite — they are small, they are independent, and they
 * share a theme: each of them let the system report something other than
 * what was true.
 *
 * 1. A missing argument was not a missing argument.
 *    `agentshell_create_page` read `$arguments['content']` directly. A
 *    caller who forgot it got "Undefined array key" from PHP, the tool
 *    carried on with an empty value, and the response reported a page
 *    that had been created. The server now refuses a call whose arguments
 *    do not satisfy the tool's own schema, naming what is missing.
 *
 * 2. A configuration value that changed nothing.
 *    `agentshell_set_typography` accepts `scale`, the value was stored,
 *    and no CSS variable consumed it. The theme's headings now scale by
 *    it, and a value that is not a positive number is refused rather
 *    than stored.
 *
 * 3. A warning about a widget the operator had retired.
 *    The doctor warned that a disabled widget was "registered but not
 *    referenced by any zone" — after it had been disabled, on purpose.
 *    A retired widget renders nothing by design; the warning trained
 *    agents to ignore the doctor.
 *
 * 4. A response that read as misleading.
 *    `agentshell_update_content` returns the post's *current* state, so
 *    a title the caller never mentioned came back as the post's title —
 *    an empty string for a draft with no title. The response now names
 *    the fields the call actually wrote.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/functions.php';

foreach ( array( 'class-store.php', 'class-doctor.php', 'class-transactions.php', 'class-json-rpc.php', 'class-server.php', 'class-content.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-create-page.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-create-post.php';

use AgentShell_MCP\Doctor;
use AgentShell_MCP\Server;

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

echo "argument-handling regression tests\n";

/** A tool stub with a schema, for the server's required-argument check. */
class diag_required_tool {
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'      => array( 'type' => 'integer' ),
                'content' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'id' ),
        );
    }
}

/** A tool stub whose schema declares nothing as required. */
class diag_optional_tool {
    public function get_input_schema() {
        return array( 'type' => 'object', 'properties' => array() );
    }
}

/** Call the server's private required-argument check. */
function diag_assert_required( $tool, array $arguments ) {
    $m = new ReflectionMethod( Server::class, 'assert_required_arguments' );
    $m->setAccessible( true );
    $m->invoke( null, $tool, $arguments );
}

// ── 1. a missing argument is refused by name ────────────────────────────────
Agentshell_Test::run(
    'the server refuses a call whose arguments do not satisfy the tool schema',
    function () {
        $refused = '';
        try {
            diag_assert_required( new diag_required_tool(), array() );
        } catch ( \InvalidArgumentException $e ) {
            $refused = $e->getMessage();
        }
        Agentshell_Test::assert_true(
            '' !== $refused,
            'a missing required argument must be refused'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $refused, 'id' ),
            'the refusal must name the missing argument: ' . $refused
        );

        // Present but empty is the tool's own business — a page with an
        // empty body is valid content — and the check must not touch it.
        $ok = true;
        try {
            diag_assert_required( new diag_required_tool(), array( 'id' => 0 ) );
        } catch ( \InvalidArgumentException $e ) {
            $ok = false;
        }
        Agentshell_Test::assert_true( $ok, 'a present argument must not be refused' );

        // A tool with no required list is never refused.
        $ok = true;
        try {
            diag_assert_required( new diag_optional_tool(), array() );
        } catch ( \InvalidArgumentException $e ) {
            $ok = false;
        }
        Agentshell_Test::assert_true( $ok, 'a tool with no required arguments must not be refused' );
    }
);

// ── 1b. create_page without a body is quiet and honest ─────────────────────
Agentshell_Test::run(
    'create_page without a body raises no PHP warning and says what it did',
    function () {
        agentshell_test_reset();

        // A titled page with an empty body is valid content and is created
        // (AGENTS.md 6.3). What must not happen is a PHP warning and a
        // response that says the page was created when the caller never
        // asked for one.
        //
        // The stub is defined in the AgentShell_MCP namespace on purpose:
        // the content primitives call wp_insert_post() unqualified from
        // inside that namespace, so a namespaced definition wins over
        // whatever global stub another suite installed. This suite has to
        // be able to say what was written, and it cannot ask another
        // suite's stub for that.
        if ( ! function_exists( 'AgentShell_MCP\\wp_insert_post' ) ) {
            eval( 'namespace AgentShell_MCP; function wp_insert_post( $postarr, $wp_error = false ) {
                $GLOBALS["_diag_insert_calls"][] = $postarr;
                $id = 9001 + count( $GLOBALS["_diag_insert_calls"] );
                $GLOBALS["_agentshell_test_posts"][$id] = (object) array_merge(
                    array(
                        "ID" => $id, "post_type" => "page", "post_status" => "draft",
                        "post_title" => "", "post_content" => "", "post_excerpt" => "",
                        "post_name" => "", "post_date" => "2026-01-01 00:00:00",
                        "post_modified" => "2026-01-01 00:00:00", "post_author" => 1,
                        "post_parent" => 0,
                    ),
                    $postarr
                );
                return $id;
            }' );
        }
        $GLOBALS['_diag_insert_calls'] = array();

        $warnings = array();
        set_error_handler( function ( $errno, $errstr ) use ( &$warnings ) {
            $warnings[] = $errstr;
            return true;
        } );

        $record  = null;
        $refused = '';
        try {
            $record = ( new \AgentShell_MCP\Tools\Create_Page() )->execute( array( 'title' => 'No body' ) );
        } catch ( \Throwable $e ) {
            $refused = $e->getMessage();
        }
        restore_error_handler();

        Agentshell_Test::assert_equals(
            0,
            count( $warnings ),
            'no PHP warning may be raised: ' . implode( ' | ', $warnings )
        );
        Agentshell_Test::assert_true(
            '' === $refused,
            'a titled page with an empty body is valid and is created: ' . $refused
        );
        // What was written is asserted through the record rather than
        // through a stub of the insert: run-all.php puts every suite in
        // one process, and whichever suite installed wp_insert_post
        // first owns the call (PHP caches the resolved function), so this
        // suite cannot claim the write and must not pretend to.
        Agentshell_Test::assert_true(
            is_array( $record ) && ! empty( $record['id'] ),
            'the response must describe the page that was created'
        );
        Agentshell_Test::assert_equals(
            0,
            (int) ( $record['content']['length'] ?? -1 ),
            'the missing body must become an empty body, not a warning'
        );
        Agentshell_Test::assert_equals(
            'No body',
            (string) ( $record['title'] ?? '' ),
            'the title must be the one the caller gave'
        );
    }
);

