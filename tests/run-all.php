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
 *   - config-consistency  the committed/effective split: register and enable
 *                      in one transaction, validation sees what would be
 *                      committed, ordinary renders do not see staged state,
 *                      an authorised preview sees all of it, rollback and
 *                      commit each restore the right thing, and the legacy
 *                      agentshell_widgets option is reconciled rather than
 *                      competing
 *   - mutation-sandbox  the mutation harnesses' isolation primitive really
 *                      isolates: a sandbox lives outside the checkout,
 *                      writing to it cannot change the checkout, and a change
 *                      to the checkout is detected rather than assumed away
 *
 * The run fingerprints the checkout before it starts and again after it ends.
 * A test that writes into the working tree turns every other suite into a
 * reader of mutated production code — a failure mode that looks exactly like a
 * real regression and is not one. That case now fails the run outright.
 *
 * Usage:
 *   php tests/run-all.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mutation-sandbox.php';

define( 'AGENTSHELL_TEST_AGGREGATE', true );

$agentshell_repo_before = agentshell_repo_snapshot();

$suites = array(
    // First: this suite loads the real AgentShell_MCP\Store, which later
    // suites then reuse instead of installing their own stubs. Anything that
    // needs a different Store has to run before it, in its own process.
    'transaction-preview'  => __DIR__ . '/transaction-preview-test.php',
    'post-preview'         => __DIR__ . '/post-preview-test.php',
    // Shares the fixture globals with post-preview above, so it must run in the
    // same process and after it.
    'preview-credential-hygiene' => __DIR__ . '/preview-credential-hygiene-test.php',
    // Loads the real Store, Doctor and Transaction_Manager and reads the
    // preview gate's credential, so it belongs beside the suites that have
    // already put those in memory.
    'config-consistency'   => __DIR__ . '/config-consistency-test.php',
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
    'mutation-sandbox'     => __DIR__ . '/mutation-sandbox-test.php',
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

// ── checkout integrity ──────────────────────────────────────────────────────
// Compare against the fingerprint taken before the first suite ran, so a test
// that wrote into the working tree is reported as the infrastructure failure it
// is instead of surfacing later as a mystery regression somewhere else.
$repo_diff = agentshell_repo_diff( $agentshell_repo_before );
// count(), not `+`: PHP's array union is not addition, and `array() + array()`
// is an empty array, which is never `=== 0` — the gate would then report a
// modified checkout on every clean run, and everyone would learn to ignore it.
$touched = count( $repo_diff['changed'] ) + count( $repo_diff['added'] ) + count( $repo_diff['removed'] );

echo "\n=== CHECKOUT INTEGRITY ===\n";
if ( 0 === $touched ) {
    echo "untouched\n";
} else {
    echo "MODIFIED BY THE TEST RUN — every suite above may have read mutated code:\n";
    foreach ( array( 'changed', 'added', 'removed' ) as $kind ) {
        foreach ( $repo_diff[ $kind ] as $path ) {
            echo "  $kind: $path\n";
        }
    }
    $total_failed++;
}

exit( $total_failed === 0 ? 0 : 1 );