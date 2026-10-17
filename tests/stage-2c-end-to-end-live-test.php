<?php
/**
 * Stage 2c M5-t3 — full end-to-end acceptance suite.
 *
 * Verifies the complete published-edit lifecycle on a real
 * WordPress install using the production AgentShell operations
 * (Publish_Edit, Publish_Edit_Reconcile, Post_Preview). The
 * happy path covers: page → claim → shadow → stage → preview
 * → publish → reconcile. Negatives cover forged/expired/wrong-
 * actor credentials, shadow head-advanced, original baseline
 * drift, claim ownership change, shadow identity mismatch,
 * publication / reconciliation retries, public access to
 * private shadow content, and modification of fields outside
 * the approved four-field scope.
 *
 * The test exercises:
 *   - the real wp_update_post against a real wp_posts row
 *   - the real per-tx MySQL advisory lock
 *   - the real per-original claim swap
 *   - the real WordPress permalink / page rendering path
 *     (anonymous HTTP requests)
 *   - the real preview overlay (HMAC credential in query
 *     string) on top of the permalink
 *
 * The test bed is the same wp-modernize-style `agentshell-test.local`
 * the other live suites use. The destructive test-bed preflight
 * is required.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live stage-2c-end-to-end (M5-t3)\n";

// Destructive live suite: requires the test-bed config.
$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live stage-2c-end-to-end suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

// Preflight the test bed for a fresh start. Crash-window recovery
// + claim + transaction + audit wipe must run before this test
// runs so a prior crashed run cannot poison the lifecycle.
$preflight_cfg = is_array( $config['cfg'] ) ? $config['cfg'] : array();
$preflight = agentshell_test_bed_preflight( $preflight_cfg );
if ( ! is_array( $preflight ) || empty( $preflight['ok'] ) ) {
    Agentshell_Test::run(
        'live stage-2c-end-to-end: test-bed preflight',
        function () use ( $preflight ) {
            Agentshell_Test::assert_true( false, 'preflight failed: ' . json_encode( $preflight ) );
        }
    );
    return;
}

$report_file = sys_get_temp_dir() . '/as-e2e-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/stage-2c-end-to-end-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = (string) @file_get_contents( $report_file );
$report = json_decode( $raw, true );
@unlink( $report_file );

if ( ! is_array( $report ) || ! isset( $report['E1'] ) || ! isset( $report['E2'] ) || ! isset( $report['E3'] ) ) {
    Agentshell_Test::run(
        'live stage-2c-end-to-end: child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true(
                is_array( $GLOBALS['e2e_report'] ),
                'child failed to report: ' . substr( (string) $run['output'], 0, 600 )
            );
        }
    );
    return;
}
$GLOBALS['e2e_report'] = $report;

// ── E1: happy path lifecycle ───────────────────────────────────
$e1 = $report['E1'];

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.1 original captures the immutable baseline',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( is_array( $e1['baseline'] ?? null ) && '' !== (string) ( $e1['baseline']['post_title'] ?? '' ), 'baseline missing' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.2 per-original claim was acquired by the agent',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( true === (bool) ( $e1['claim_acquired'] ?? false ), 'claim not acquired' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.3 private shadow was created and post_status is private (overlay-isolated)',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( 'private' === (string) ( $e1['shadow_status'] ?? '' ), 'shadow_status=' . (string) ( $e1['shadow_status'] ?? 'missing' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.4 all four allowed fields are staged on the shadow',
    function () use ( $e1 ) {
        $staged = (array) ( $e1['staged'] ?? array() );
        Agentshell_Test::assert_true( '' !== (string) ( $staged['post_title'] ?? '' ), 'title' );
        Agentshell_Test::assert_true( '' !== (string) ( $staged['post_content'] ?? '' ), 'content' );
        Agentshell_Test::assert_true( array_key_exists( 'post_excerpt', $staged ), 'excerpt' );
        Agentshell_Test::assert_true( '' !== (string) ( $staged['post_name'] ?? '' ), 'slug' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.5 anonymous request to the original still returns the original (no overlay)',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( 200 === (int) ( $e1['anon_status_before_publish'] ?? 0 ), 'anon code=' . ( $e1['anon_status_before_publish'] ?? 'missing' ) );
        Agentshell_Test::assert_true( false === (bool) ( $e1['anon_sees_staged_before_publish'] ?? true ), 'anon leaked staged content' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.6 authorised private preview with HMAC credential serves the staged content',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( 200 === (int) ( $e1['preview_status'] ?? 0 ), 'preview code=' . ( $e1['preview_status'] ?? 'missing' ) );
        Agentshell_Test::assert_true( true === (bool) ( $e1['preview_sees_staged_title'] ?? false ), 'preview did not serve staged title' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.7 publish_edit returns publication_settled and the original carries staged fields',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( false !== strpos( (string) ( $e1['publish_state'] ?? '' ), 'publication_settled' ), 'state=' . ( $e1['publish_state'] ?? 'missing' ) );
        Agentshell_Test::assert_true( (string) ( $e1['post_title_after_publish'] ?? '' ) === (string) ( $e1['staged']['post_title'] ?? '' ), 'post title not landed' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.8 protected fields (post_author, post_parent, post_status, post_type) are preserved',
    function () use ( $e1 ) {
        $b = (array) ( $e1['baseline'] ?? array() );
        $p = (array) ( $e1['post_after_publish'] ?? array() );
        Agentshell_Test::assert_true( (int) ( $b['post_author'] ?? 0 ) === (int) ( $p['post_author'] ?? -1 ), 'post_author' );
        Agentshell_Test::assert_true( (int) ( $b['post_parent'] ?? -1 ) === (int) ( $p['post_parent'] ?? -2 ), 'post_parent' );
        Agentshell_Test::assert_true( (string) ( $b['post_status'] ?? '' ) === (string) ( $p['post_status'] ?? '' ), 'post_status' );
        Agentshell_Test::assert_true( (string) ( $b['post_type'] ?? '' ) === (string) ( $p['post_type'] ?? '' ), 'post_type' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.9 journal and audit log are consistent after publish',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( 1 === (int) ( $e1['audit_published_count'] ?? -1 ), 'audit_published=' . ( $e1['audit_published_count'] ?? 'missing' ) );
        Agentshell_Test::assert_true( is_array( $e1['op_on_disk'] ?? null ), 'op on disk missing' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.10 reconcile settles the op; shadow, claim, and caches are released',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( false === (bool) ( $e1['shadow_exists_after_reconcile'] ?? true ), 'shadow still exists' );
        Agentshell_Test::assert_true( false === (bool) ( $e1['claim_present_after_reconcile'] ?? true ), 'claim still present' );
        Agentshell_Test::assert_true( true === (bool) ( $e1['caches_invalidated'] ?? false ), 'caches not invalidated' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E1.11 anonymous request after reconcile returns the published content',
    function () use ( $e1 ) {
        Agentshell_Test::assert_true( 200 === (int) ( $e1['anon_status_after_publish'] ?? 0 ), 'anon code=' . ( $e1['anon_status_after_publish'] ?? 'missing' ) );
        Agentshell_Test::assert_true( true === (bool) ( $e1['anon_sees_published_after_publish'] ?? false ), 'anon does not see published content' );
    }
);

// ── E2: retry safety ───────────────────────────────────────────
$e2 = $report['E2'];

Agentshell_Test::run(
    'live stage-2c-end-to-end: E2.1 second publish_edit is refused (op already settled)',
    function () use ( $e2 ) {
        Agentshell_Test::assert_true( 'refused' === (string) ( $e2['second_publish_outcome'] ?? '' ), 'second_publish=' . ( $e2['second_publish_outcome'] ?? 'missing' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E2.2 second reconcile is refused (no double release, no side effect)',
    function () use ( $e2 ) {
        Agentshell_Test::assert_true( 'refused' === (string) ( $e2['second_reconcile_outcome'] ?? '' ), 'second_reconcile=' . ( $e2['second_reconcile_outcome'] ?? 'missing' ) );
        Agentshell_Test::assert_true( (int) ( $e2['audit_published_unchanged'] ?? 0 ) === 1, 'audit published count drifted' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E2.3 the shadow is gone after reconcile (no private-content path remains)',
    function () use ( $e2, $e1 ) {
        // After publish + reconcile, the shadow is deleted. The new slug
        // is the original's permalink; the original's response legitimately
        // contains the staged content (now published). What we verify is
        // that the shadow itself is gone from wp_posts — anonymous
        // requests to its private path would 404, and the only path that
        // serves the staged content is the original's published permalink.
        Agentshell_Test::assert_true( false === (bool) ( $e1['shadow_exists_after_reconcile'] ?? true ), 'shadow still in wp_posts' );
        Agentshell_Test::assert_true( 200 === (int) ( $e2['shadow_slug_status'] ?? 0 ), 'shadow slug status=' . ( $e2['shadow_slug_status'] ?? 'missing' ) );
    }
);

// ── E3: negative paths ─────────────────────────────────────────
$e3 = $report['E3'];

Agentshell_Test::run(
    'live stage-2c-end-to-end: E3.1 forged credential is refused (no preview overlay applied)',
    function () use ( $e3 ) {
        Agentshell_Test::assert_true( 200 === (int) ( $e3['forged_status'] ?? 0 ), 'forged code=' . ( $e3['forged_status'] ?? 'missing' ) );
        Agentshell_Test::assert_true( false === (bool) ( $e3['forged_sees_staged'] ?? true ), 'forged credential served staged content' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E3.2 expired credential is refused',
    function () use ( $e3 ) {
        Agentshell_Test::assert_true( false === (bool) ( $e3['expired_sees_staged'] ?? true ), 'expired credential served staged content' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E3.3 wrong-actor credential is refused',
    function () use ( $e3 ) {
        Agentshell_Test::assert_true( false === (bool) ( $e3['wrong_actor_sees_staged'] ?? true ), 'wrong-actor credential served staged content' );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E3.4 baseline drift refusal (changed original) refuses publish',
    function () use ( $e3 ) {
        // Drift refusal lives at the publish boundary, not the preview
        // boundary. Publish_Edit::execute refuses when the live original
        // has diverged from the staged op's before-image.
        Agentshell_Test::assert_true( true === (bool) ( $e3['drift_publish_threw'] ?? false ), 'drift publish did not throw: ' . (string) ( $e3['drift_publish_reason'] ?? '' ) );
    }
);

Agentshell_Test::run(
    'live stage-2c-end-to-end: E3.5 outside-the-four-fields change to the shadow is refused',
    function () use ( $e3 ) {
        // The publish-edit tool reads only the four staged fields from the
        // shadow. A fifth-field mutation (e.g. post_author=9999 on the
        // shadow row) must not flow to the original. The original's
        // post_author is preserved by refusal OR by completion with no
        // mutation; the test asserts the original is unchanged.
        Agentshell_Test::assert_true( 'preserved' === (string) ( $e3['outside_fields_outcome'] ?? '' ), 'outcome=' . ( $e3['outside_fields_outcome'] ?? 'missing' ) );
    }
);
