<?php
/**
 * Regression tests for agentshell_siteurl_validate().
 *
 * Why this exists
 * ---------------
 * Gate 3 §2 introduced the destructive-child identity guard
 * (`agentshell_destructive_identity_guard()`) with a siteurl check that
 * used `strpos( observed, expected_url_with_port ) === 0` to require
 * the observed siteurl to be a prefix of the configured transport URL.
 * The check rejected the canonical siteurl of the restored test-bed
 * baseline (http://agentshell-test.local, no port) because the test-bed
 * config's `url_http` includes the LocalWP listener port (:10008). The
 * two are different concepts: `url_http` is the *transport* endpoint;
 * `siteurl` is the *canonical* site identity. A guard that confuses
 * them refuses on the correct canonical state and accepts the wrong
 * contamination shapes.
 *
 * The fix introduces `agentshell_siteurl_validate()` — a pure helper
 * that derives the canonical scheme, host, and path from the configured
 * transport URL (intentionally stripping the port, since the port is
 * a property of the listener, not of site identity) and compares
 * strictly: scheme, host, path, and the *absence* of port, query,
 * fragment, and userinfo. strpos() prefix matching is never used.
 *
 * What this test asserts
 * ----------------------
 * The tests exercise the helper directly — no DB, no wp-config, no
 * HTTP. They are pure unit tests of the URL identity contract.
 *
 *   1. Positive: the restored baseline's portless siteurl validates.
 *   2. Positive: an equivalent trailing-slash spelling validates.
 *   3. Negative: an integration-port siteurl (:10011) refuses.
 *   4. Negative: an incorrect scheme (https vs http) refuses.
 *   5. Negative: a lookalike hostname refuses.
 *   6. Negative: an unexpected path refuses.
 *   7. Negative: a malformed URL refuses.
 *   8. Negative: a userinfo trick refuses.
 *   9. Negative: a query string refuses.
 *  10. Negative: a case-mismatched host refuses.
 *  11. Negative: the transport URL with its port refuses (port must be absent).
 *  12. Negative: empty observed refuses.
 *  13. Negative: empty expected refuses.
 *  14. Negative: a malformed expected URL refuses.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';

echo "Destructive identity guard — siteurl validate helper (gate-3 siteurl-correction)\n";

$cfg = agentshell_test_bed_load();
Agentshell_Test::assert_true(
    is_array( $cfg ),
    'test-bed config must load'
);
$expected_url = isset( $cfg['url_http'] ) ? (string) $cfg['url_http'] : '';
Agentshell_Test::assert_true(
    '' !== $expected_url,
    'test-bed config must declare url_http'
);

// ── 1. Positive: restored baseline's portless siteurl.
Agentshell_Test::run(
    'siteurl validate: portless canonical siteurl',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local', $expected_url );
        Agentshell_Test::assert_true(
            $r['ok'],
            'portless canonical siteurl must validate; got reason: ' . $r['reason']
        );
    }
);

// ── 2. Positive: trailing-slash spelling.
Agentshell_Test::run(
    'siteurl validate: trailing-slash spelling',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local/', $expected_url );
        Agentshell_Test::assert_true(
            $r['ok'],
            'trailing-slash spelling must validate; got reason: ' . $r['reason']
        );
    }
);

// ── 3. Negative: integration siteurl (port 10011, different host).
Agentshell_Test::run(
    'siteurl validate: integration port 10011 refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://127.0.0.1:10011', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'integration siteurl (port 10011, host 127.0.0.1) must refuse'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $r['reason'], 'port' ) || false !== strpos( $r['reason'], 'host' ),
            'refusal must mention port or host; got: ' . $r['reason']
        );
    }
);

// ── 4. Negative: incorrect scheme.
Agentshell_Test::run(
    'siteurl validate: incorrect scheme refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'https://agentshell-test.local', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'https siteurl when http is expected must refuse'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $r['reason'], 'scheme' ),
            'refusal must mention scheme; got: ' . $r['reason']
        );
    }
);

// ── 5. Negative: lookalike hostname.
Agentshell_Test::run(
    'siteurl validate: lookalike hostname refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local.evil.com', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'lookalike hostname must refuse'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $r['reason'], 'host' ),
            'refusal must mention host; got: ' . $r['reason']
        );
    }
);

// ── 6. Negative: unexpected path.
Agentshell_Test::run(
    'siteurl validate: unexpected path refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local/wp/', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'unexpected path /wp/ must refuse'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $r['reason'], 'path' ),
            'refusal must mention path; got: ' . $r['reason']
        );
    }
);

// ── 7. Negative: malformed URL.
Agentshell_Test::run(
    'siteurl validate: malformed URL refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'not a url', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'malformed URL must refuse'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $r['reason'], 'malformed' ),
            'refusal must mention malformed; got: ' . $r['reason']
        );
    }
);

// ── 8. Negative: userinfo trick.
Agentshell_Test::run(
    'siteurl validate: userinfo trick refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local@evil.com', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'userinfo trick must refuse'
        );
    }
);

// ── 9. Negative: query string.
Agentshell_Test::run(
    'siteurl validate: query string refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local/?foo=bar', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'siteurl with query string must refuse'
        );
    }
);

// ── 10. Negative: case-mismatched host.
Agentshell_Test::run(
    'siteurl validate: case-mismatched host refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://AGENTSHELL-TEST.LOCAL', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'case-mismatched host must refuse (hostnames are case-sensitive in this check)'
        );
    }
);

// ── 11. Negative: transport URL with its port (port must be absent).
Agentshell_Test::run(
    'siteurl validate: transport URL with port refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local:10008', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'observed siteurl carrying the transport port must refuse'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $r['reason'], 'port' ),
            'refusal must mention port; got: ' . $r['reason']
        );
    }
);

// ── 12. Negative: empty observed.
Agentshell_Test::run(
    'siteurl validate: empty observed refuses',
    function () use ( $expected_url ) {
        $r = agentshell_siteurl_validate( '', $expected_url );
        Agentshell_Test::assert_false(
            $r['ok'],
            'empty observed siteurl must refuse'
        );
    }
);

// ── 13. Negative: empty expected.
Agentshell_Test::run(
    'siteurl validate: empty expected refuses',
    function () {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local', '' );
        Agentshell_Test::assert_false(
            $r['ok'],
            'empty expected URL must refuse'
        );
    }
);

// ── 14. Negative: malformed expected.
Agentshell_Test::run(
    'siteurl validate: malformed expected refuses',
    function () {
        $r = agentshell_siteurl_validate( 'http://agentshell-test.local', 'not a url' );
        Agentshell_Test::assert_false(
            $r['ok'],
            'malformed expected URL must refuse'
        );
    }
);

Agentshell_Test::summary();
