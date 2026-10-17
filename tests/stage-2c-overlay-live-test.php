<?php
/**
 * Stage 2c overlay live tests — Milestone 3: secure published-content
 * preview overlay, against a real WordPress install.
 *
 * The spike (tests/spike/) proved the production-shape preview overlay
 * against the test bed in disposable mode. This file is the
 * canonical live counterpart: the production overlay code path
 * (agentshell-mcp/includes/class-published-preview-overlay.php), against
 * the same disposable test bed, with a per-original id fixture and the
 * design's full credential format (single base64-encoded JSON blob
 * carrying {oid, sid, tx, actor, exp, key}).
 *
 * What this proves:
 *   (a) get_queried_object_id() returns the original's id
 *   (b) $post->ID is the original's id
 *   (c) the template loader selects the original's page-{ID}.php
 *   (d) get_ancestors($post->ID) returns the original's ancestors
 *   (e) get_post_meta($post->ID, '_wp_page_template', true) returns
 *       the original's page-template selection
 *   (f) a direct get_post($post->ID) call inside the same request
 *       reads the original's row (not the substituted overlay object)
 *   (g) the four supported fields (post_title, post_content,
 *       post_excerpt, post_name) read from the rendered response body
 *       are the shadow's staged values
 *   (g-link) the response body's permalink link reflects the staged
 *       slug (design §1.3 step 6 option (a))
 *   (h) the persistent wp_object_cache holds the original's row, not
 *       a poisoned version (a second request without any credential
 *       sees the original, not the shadow)
 *   (i) anonymous request (no credential) returns the original
 *       unchanged with HTTP 200
 *   (j) unauthorised request (tampered credential) returns the
 *       original unchanged with HTTP 200
 *   (k) expired credential is refused (the original is returned)
 *   (l) wrong-actor credential is refused (the original is returned)
 *   (m) op in a refused state is refused (the original is returned)
 *   (n) the noindex, no-store response headers are set on an
 *       authorised request
 *
 * Refusal paths render the original because the overlay's
 * verify_and_install is a silent no-op when the credential or the
 * journal op is invalid — the page is still public, so anonymous
 * rendering of the original is the only safe fallback.
 *
 * The child lives in the test site; the parent (this file) reaches
 * it via the standard Agentshell_Test runner contract and reads
 * structured results back from a JSON file.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';

echo "Stage 2c overlay live tests (Milestone 3: live overlay against the test site)\n";

// Destructive integration. Skip cleanly when the runner is unverified
// (the local PHP has no mysqli) or the fail-closed check has not
// passed, the same gate the existing live suites use.
$cfg = agentshell_test_bed_load();
if ( ! is_array( $cfg ) ) {
    echo "  SKIPPED: no test-bed config — destructive integration suite requires a real test site\n";
    Agentshell_Test::run(
        'stage-2c-overlay-live: no test-bed config',
        function () { Agentshell_Test::assert_true( true, 'skipped' ); }
    );
    return;
}
if ( ! class_exists( 'mysqli' ) ) {
    echo "  SKIPPED: this PHP has no mysqli, so the fail-closed check cannot run\n";
    Agentshell_Test::run(
        'stage-2c-overlay-live: no mysqli',
        function () { Agentshell_Test::assert_true( true, 'skipped' ); }
    );
    return;
}
$check = agentshell_test_bed_check( $cfg );
if ( ! $check['ok'] ) {
    echo "  SKIPPED: test-bed fail-closed check failed (" . $check['refusal'] . ")\n";
    Agentshell_Test::run(
        'stage-2c-overlay-live: fail-closed',
        function () use ( $check ) { Agentshell_Test::assert_true( false, $check['refusal'] ); }
    );
    return;
}

$child  = __DIR__ . '/stage-2c-overlay-live-child.php';
$php_bin = is_array( $cfg['php'] ?? null )
    ? (string) ( $cfg['php']['binary'] ?? '' )
    : (string) ( $cfg['php'] ?? '' );
$cmd    = sprintf(
    '%s %s 2>&1',
    escapeshellarg( '' !== $php_bin ? $php_bin : 'php' ),
    escapeshellarg( $child )
);

$descriptors = array(
    0 => array( 'pipe', 'r' ),
    1 => array( 'pipe', 'w' ),
    2 => array( 'pipe', 'w' ),
);
$proc = proc_open( $cmd, $descriptors, $pipes );
if ( ! is_resource( $proc ) ) {
    Agentshell_Test::run(
        'stage-2c-overlay-live: child process could be launched',
        function () { Agentshell_Test::assert_true( false, 'proc_open failed' ); }
    );
    return;
}
fclose( $pipes[0] );
$stdout = stream_get_contents( $pipes[1] );
fclose( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[2] );
$exit   = proc_close( $proc );

echo $stdout;
if ( '' !== $stderr ) { fwrite( STDERR, $stderr ); }

// The child writes its structured results to a JSON file before
// exiting. Read it back and convert each line into an Agentshell_Test
// assertion.
$json_path = sys_get_temp_dir() . '/stage-2c-overlay-live-result.json';
if ( ! is_file( $json_path ) ) {
    Agentshell_Test::run(
        'stage-2c-overlay-live: child wrote structured result file',
        function () use ( $exit ) { Agentshell_Test::assert_true( false, "child exit={$exit}, no result file" ); }
    );
    return;
}
$raw  = file_get_contents( $json_path );
$data = json_decode( (string) $raw, true );
if ( ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
    Agentshell_Test::run(
        'stage-2c-overlay-live: child wrote a well-formed result file',
        function () use ( $raw ) { Agentshell_Test::assert_true( false, 'malformed: ' . substr( (string) $raw, 0, 200 ) ); }
    );
    return;
}
foreach ( $data['results'] as $r ) {
    if ( ! is_array( $r ) || ! isset( $r['name'] ) ) { continue; }
    $name    = (string) $r['name'];
    $ok      = (bool) ( $r['ok'] ?? false );
    $detail  = (string) ( $r['detail'] ?? '' );
    Agentshell_Test::run(
        $name,
        function () use ( $ok, $detail ) { Agentshell_Test::assert_true( $ok, $detail ); }
    );
}
