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
 *
 * Usage:
 *   php tests/run-all.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'AGENTSHELL_TEST_AGGREGATE', true );

$suites = array(
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