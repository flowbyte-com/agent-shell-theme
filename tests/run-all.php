<?php
/**
 * Unified test runner — runs every suite, exits non-zero on any failure.
 *
 * Suites:
 *   - security         Commit 1 capability boundary
 *   - hardening        Commit 2 sink hardening
 *   - inject-block     Commit 3 json_block render contract
 *   - widget-registry  Commit 4 widget lifecycle consolidation
 *   - render-block     renderer block-type coverage
 *   - flatten          flatten/unflatten roundtrip + custom-var precedence
 *   - doctor           minimal validator surface coverage
 *   - screenshot       capture lock, wall-clock deadline, no stale/unsafe output
 *   - screenshot-privacy  inline delivery, private storage, authorisation,
 *                          retention, legacy migration
 *   - browser-isolation    a fresh private browser profile per capture, and
 *                          its removal on success, failure and timeout
 *   - navigation           nav menu row building and pre-write validation
 *   - navigation-live      agentshell_register_navigation against a real
 *                          WordPress install: ordering, idempotency, location
 *                          assignment, frontend rendering, and the failure
 *                          paths. Skips when no WordPress install is found.
 *   - transaction-preview transaction-scoped rendering of staged config,
 *                          and its failure to leak to visitors or logs
 *   - post-preview     draft capture without publishing: mint, signature,
 *                      route and post-binding gates, cross-post misuse,
 *                      privilege escalation, credential hygiene
 *   - preview-credential-hygiene  the credential never reaches the rendered
 *                      HTML; the admin bar is suppressed only for an authorised
 *                      preview; expiry, invalidation and the combined draft +
 *                      transaction preview still hold
 *
 * Usage:
 *   php tests/run-all.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'AGENTSHELL_TEST_AGGREGATE', true );

$suites = array(
    // First: this suite loads the real AgentShell_MCP\Store, which later
    // suites then reuse instead of installing their own stubs. Anything that
    // needs a different Store has to run before it, in its own process.
    'transaction-preview'  => __DIR__ . '/transaction-preview-test.php',
    'post-preview'         => __DIR__ . '/post-preview-test.php',
    // Shares the fixture globals with post-preview above, so it must run in the
    // same process and after it.
    'preview-credential-hygiene' => __DIR__ . '/preview-credential-hygiene-test.php',
    'security'             => __DIR__ . '/security-test.php',
    'hardening'            => __DIR__ . '/hardening-test.php',
    'inject-block'         => __DIR__ . '/inject-block-test.php',
    'widget-registry'      => __DIR__ . '/widget-registry-test.php',
    'render-block'         => __DIR__ . '/render-block-test.php',
    'flatten'              => __DIR__ . '/flatten-test.php',
    'doctor'               => __DIR__ . '/doctor-test.php',
    'audit-log'            => __DIR__ . '/audit-log-test.php',
    'static-token-auth'    => __DIR__ . '/static-token-auth-test.php',
    'app-password-auth'    => __DIR__ . '/app-password-auth-test.php',
    'tool-schema'          => __DIR__ . '/tool-schema-test.php',
    'security-headers'     => __DIR__ . '/security-headers-test.php',
    'daemon-version'       => __DIR__ . '/daemon-version-test.php',
    'navigation'           => __DIR__ . '/navigation-test.php',
    'navigation-live'      => __DIR__ . '/navigation-live-test.php',
    'screenshot'           => __DIR__ . '/screenshot-test.php',
    'screenshot-privacy'   => __DIR__ . '/screenshot-privacy-test.php',
    'browser-isolation'    => __DIR__ . '/browser-isolation-test.php',
    'browser-trust'        => __DIR__ . '/browser-trust-test.php',
    'configurator-state'   => __DIR__ . '/run-configurator-state.php',
);

$total_passed = 0;
$total_failed = 0;
$failed_suites = array();

foreach ( $suites as $name => $path ) {
    echo "\n=== $name ===\n";
    Agentshell_Test::$passed = 0;
    Agentshell_Test::$failed = 0;
    Agentshell_Test::$current_test = '';
    require $path;
    Agentshell_Test::summary();
    $total_passed += Agentshell_Test::$passed;
    $total_failed += Agentshell_Test::$failed;
    if ( Agentshell_Test::$failed > 0 ) {
        $failed_suites[] = $name;
    }
}

echo "\n=== TOTAL ===\n";
echo "$total_passed passed, $total_failed failed\n";
if ( ! empty( $failed_suites ) ) {
    echo "Failed suites: " . implode( ', ', $failed_suites ) . "\n";
}

exit( $total_failed === 0 ? 0 : 1 );