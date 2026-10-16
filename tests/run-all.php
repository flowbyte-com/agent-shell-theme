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
 *   - transaction-lease   the inactivity lease and stale recovery: expiry is
 *                          effective, recovery is audited and never silently
 *                          destroys journalled work, and the option row is
 *                          swapped under a value guard rather than by
 *                          read-then-delete
 *   - journal-recovery    the durable content journal and the recovery engine:
 *                          an expired journalled transaction is resolved by
 *                          evidence rather than by the journal's own account of
 *                          itself, two callers cannot compensate the same
 *                          operation, a diverged post is refused and kept, and
 *                          an unresolved conflict is never discarded to free
 *                          the slot
 *   - journal-recovery-live  the same guarantee against a real WordPress and
 *                          database: that a force delete really removes the
 *                          row, that a trashed orphan is really found, and
 *                          that nothing unrelated is touched
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
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

define( 'AGENTSHELL_TEST_AGGREGATE', true );

// ── test-bed gate ──────────────────────────────────────────────────────────
// When a test-bed config is present, run the fail-closed check before any
// suite. The check refuses to run unless every fingerprint (server_uuid,
// datadir, port, sentinel, siteurl, db user grants) matches the expected
// test site. A failure here is reported and the runner exits before
// touching any suite. When no config is present, the runner proceeds as
// before — every live suite individually skips cleanly, the no-test-bed
// case is treated as "no live test bed available" rather than "fail".
$test_bed_cfg     = agentshell_test_bed_load();
$test_bed_check   = null;
$test_bed_skipped = false;
$test_bed_unverified = false;
$test_bed_http_ok    = true;
$test_bed_token      = '';
$test_bed_identity   = '';
if ( is_array( $test_bed_cfg ) ) {
    // The check talks to MySQL via the `mysqli` extension. When the runner
    // is invoked with a PHP that has no `mysqli` (e.g. the system CLI on
    // a workstation with only LocalWP's PHP for the test site), the
    // check cannot run. **The runner is then in an unverified state for
    // destructive work** — the whole point of the fail-closed check is
    // to gate destructive integration verification, and a missing check
    // is the same as a failed check. The destructive live suites below
    // refuse to run (their strict resolution returns null) and the
    // leak check refuses to run; unit suites continue as before. A
    // re-run with the test-bed's PHP (which has mysqli) is required for
    // destructive verification.
    if ( ! class_exists( 'mysqli' ) ) {
        echo "\n[test-bed] config present at " . (string) ( $test_bed_cfg['_config_path'] ?? '' ) . "\n";
        echo "[test-bed] UNVERIFIED: this PHP has no mysqli, so the fail-closed check cannot run.\n";
        echo "[test-bed]            Destructive live suites and the end-of-suite leak check\n";
        echo "[test-bed]            will refuse to run. Re-run with the test-bed's PHP to\n";
        echo "[test-bed]            enable destructive verification.\n";
        $test_bed_skipped   = true;
        $test_bed_unverified = true;
    } else {
        $test_bed_check = agentshell_test_bed_check( $test_bed_cfg );
        if ( ! $test_bed_check['ok'] ) {
            fwrite( STDERR, "\n[test-bed] FAIL-CLOSED CHECK FAILED — refusing to run\n" );
            fwrite( STDERR, "[test-bed] refusal: " . $test_bed_check['refusal'] . "\n" );
            fwrite( STDERR, "[test-bed] config:  " . (string) ( $test_bed_cfg['_config_path'] ?? '(unknown)' ) . "\n" );
            fwrite( STDERR, "[test-bed] expected: server_uuid=" . (string) ( $test_bed_cfg['expect']['server_uuid'] ?? '' )
                . " datadir=" . (string) ( $test_bed_cfg['expect']['expected_datadir'] ?? '' )
                . " port=" . (string) ( $test_bed_cfg['expect']['expected_port'] ?? '' )
                . " sentinel=" . (string) ( $test_bed_cfg['expect']['site_mode_sentinel'] ?? '' ) . "\n" );
            fwrite( STDERR, "[test-bed] observed: server_uuid=" . $test_bed_check['server_uuid']
                . " datadir=" . $test_bed_check['datadir']
                . " port=" . (string) $test_bed_check['port']
                . " sentinel=" . $test_bed_check['sentinel'] . "\n" );
            fwrite( STDERR, "\nThis is a fail-closed gate, not a test failure. The test\n" );
            fwrite( STDERR, "infrastructure refuses to run against the wrong MySQL instance\n" );
            fwrite( STDERR, "or the wrong WordPress site. Re-check the config, fix the\n" );
            fwrite( STDERR, "sentinel, or move the test-bed.json aside to skip the live\n" );
            fwrite( STDERR, "suites for this run.\n\n" );
            exit( 2 );
        }
        echo "\n[test-bed] ok: " . (string) ( $test_bed_cfg['_config_path'] ?? '' ) . "\n";
        foreach ( $test_bed_check['checks'] as $name => $r ) {
            echo "  $name: " . ( $r['ok'] ? 'ok' : 'FAIL' ) . ' ' . $r['detail'] . "\n";
        }

        // ── HTTP identity handshake ────────────────────────────────────
        // The 15-check CLI/DB check above proves the runner is talking
        // to the right MySQL instance and the right wp_options row for
        // siteurl. It does *not* prove the HTTP service resolves to the
        // same installation. A config that pointed at a different
        // WordPress on the same network — a clone of the dev site on a
        // separate hostname, or a stale DNS record pointing at a
        // long-gone server — would pass the CLI checks. The HTTP
        // identity handshake closes that gap: the runner provisions a
        // per-run token and identity, fetches the test-site REST
        // endpoint, and asserts the response identity matches the
        // value the runner just wrote to wp_options. The match proves
        // HTTP and CLI read the same value from the same database.
        $install = agentshell_test_bed_install_mu_plugin( $test_bed_cfg );
        if ( ! $install['ok'] ) {
            fwrite( STDERR, "\n[test-bed] MU-PLUGIN INSTALL FAILED — refusing to run\n" );
            fwrite( STDERR, "[test-bed] reason: " . $install['reason'] . "\n" );
            fwrite( STDERR, "[test-bed] the identity REST endpoint cannot be registered without\n" );
            fwrite( STDERR, "[test-bed] the mu-plugin; the HTTP-side of the verification is missing.\n\n" );
            exit( 2 );
        }
        $provision = agentshell_test_bed_provision_identity( $test_bed_cfg );
        if ( ! $provision['ok'] ) {
            fwrite( STDERR, "\n[test-bed] IDENTITY PROVISION FAILED — refusing to run\n" );
            fwrite( STDERR, "[test-bed] reason: " . $provision['reason'] . "\n" );
            fwrite( STDERR, "[test-bed] the runner could not write the per-run token and identity\n" );
            fwrite( STDERR, "[test-bed] to wp_options; the HTTP handshake cannot complete.\n\n" );
            exit( 2 );
        }
        $test_bed_token    = $provision['token'];
        $test_bed_identity = $provision['identity'];
        $http_check = agentshell_test_bed_check_http_identity( $test_bed_cfg, $provision['token'], $provision['identity'] );
        if ( ! $http_check['ok'] ) {
            echo "  http_identity: FAIL " . $http_check['detail'] . " (status=" . $http_check['status'] . ")\n";
            $test_bed_http_ok = false;
        } else {
            echo "  http_identity: ok " . $http_check['detail'] . "\n";
        }
    }
}

// ── exclusive runner lock ──────────────────────────────────────────────────
// Once the test-bed check has passed, take the exclusive MySQL named
// advisory lock for the test instance. Two concurrent `php
// tests/run-all.php` invocations would both pass the fail-closed check
// and then both mutate the test database — the lock prevents that.
// The lock is held by the runner's mysqli connection for the lifetime
// of the process; the matching `agentshell_test_bed_unlock` (called
// via `register_shutdown_function` below) releases it on graceful
// exit, and a KILL -9 of the runner releases it implicitly when the
// server closes the dropped connection. There is no "stale lock"
// failure mode analogous to a stale file lock.
//
// This is the *only* mechanism that prevents two runners from racing
// against the same test instance. The fail-closed check above
// verifies the *site*; the lock verifies the *run*. Both are
// required.
$test_bed_lock_held = false;
if ( is_array( $test_bed_check ) && $test_bed_check['ok'] ) {
    if ( ! agentshell_test_bed_lock( $test_bed_cfg, $test_bed_check, 0 ) ) {
        fwrite( STDERR, "\n[test-bed] ANOTHER RUNNER HOLDS THE EXCLUSIVE LOCK — refusing to run\n" );
        fwrite( STDERR, "[test-bed] another `run-all.php` is currently executing against this test instance.\n" );
        fwrite( STDERR, "[test-bed] the lock is held by the runner's MySQL connection; it is released\n" );
        fwrite( STDERR, "[test-bed] on process exit (or on KILL -9, when the server drops the connection).\n" );
        fwrite( STDERR, "[test-bed] wait for the in-flight run to finish, then re-run.\n\n" );
        exit( 3 );
    }
    $test_bed_lock_held = true;
    echo "[test-bed] exclusive runner lock: ok\n";
    register_shutdown_function( function () {
        agentshell_test_bed_unlock();
    } );
}

// ── preflight: crash-window leak check ──────────────────────────────────────
// Once the lock is held, scan the test site for journal markers left
// behind by a prior crashed run. The crash window is the gap between
// `wp_insert_post` returning and the fixture tracker recording the
// post id; a child that dies inside this window (KILL -9, PHP fatal,
// OOM) leaves a journaled post on disk with no record in the
// per-run registry. The preflight either restores the test site
// from its verified baseline (destructive but bounded) or refuses
// to run when an active recovery is in progress (the operator must
// resolve it).
//
// The preflight runs under the lock so a parallel runner cannot see
// mid-restore state. It does NOT introduce a generic prefix-based
// delete: a baseline restore is a known-good state, not a delete-by-
// prefix sweep. An active recovery is preserved; the option row
// for a `live *` transaction with no applied work is closed via the
// same value-guarded delete the live precondition uses, so the
// restore does not have to deal with an open slot.
if ( is_array( $test_bed_check ) && $test_bed_check['ok'] ) {
    $preflight = agentshell_test_bed_preflight( $test_bed_cfg );
    if ( ! $preflight['ok'] ) {
        fwrite( STDERR, "\n[preflight] REFUSED — refusing to run\n" );
        fwrite( STDERR, "[preflight] action: " . $preflight['action'] . "\n" );
        fwrite( STDERR, "[preflight] detail: " . $preflight['detail'] . "\n" );
        fwrite( STDERR, "[preflight] operator must resolve the open transaction or the leak\n" );
        fwrite( STDERR, "[preflight] before re-running. The preflight does not delete any data;\n" );
        fwrite( STDERR, "[preflight] it either restores the verified baseline (when no active\n" );
        fwrite( STDERR, "[preflight] recovery is in progress) or refuses (when one is).\n\n" );
        exit( 4 );
    }
    if ( 'clean' !== $preflight['action'] ) {
        echo "[preflight] " . $preflight['action'] . ": " . $preflight['detail'] . "\n";
    } else {
        echo "[preflight] clean (no journal markers on the test site)\n";
    }
}

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
    // Loads the real Store, Doctor and Transaction_Manager and drives the
    // lease with a frozen clock, so it belongs after the suites that have
    // already put those in memory.
    'transaction-lease'    => __DIR__ . '/transaction-lease-test.php',
    // Defines wp_insert_post()/wp_update_post()/get_permalink() stubs for the
    // runner's shared process, and drives the bounded content-write guard with
    // a recording wpdb. Runs after the suites that need the real Store.
    'content-write'        => __DIR__ . '/content-write-test.php',
    // Needs those same stubs, and a real WordPress. Skips without one.
    'content-write-live'   => __DIR__ . '/content-write-live-test.php',
    // Defines get_post_meta()/wp_delete_post() for the runner's shared process,
    // which the earlier content-write stubs deliberately did not, and drives the
    // journal's compensation path against them.
    'journal-recovery'     => __DIR__ . '/journal-recovery-test.php',
    // Needs that post meta, and a real WordPress and database — which is the
    // point of it, since the marker lookup and the force delete are exactly what
    // a stub would agree with for the wrong reason. Skips without one.
    'journal-recovery-live' => __DIR__ . '/journal-recovery-live-test.php',
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
    // Stage 2c Milestone 1: the journal/claim foundation. Unit-only.
    'stage-2c-foundation'  => __DIR__ . '/stage-2c-foundation-test.php',
    // Stage 2c Milestone 2: private-shadow creation, successive authorised
    // edits, idempotency. Unit-only; needs the wp_insert_post/wp_update_post
    // stubs defined inline. Runs after journal-recovery so the postmeta
    // store is already in memory if it ever needs to be.
    'stage-2c-shadow'      => __DIR__ . '/stage-2c-shadow-test.php',
);

$total_passed   = 0;
$total_failed   = 0;
$failed_suites  = array();
$skipped_suites = array();

// Destructive live suites are gated on the fail-closed check having
// actually run and passed. The list mirrors the four `-live-test.php`
// files that spawn children against the test site. When the runner
// is unverified (no mysqli extension in the runner's PHP), the
// check failed, or the HTTP identity handshake failed, these suites
// are replaced with an explicit "skipped: not verified" pass so the
// report shows the destructive verification as **unexecuted**, not
// as silently passing.
$destructive_live_suites = array(
    'content-write-live',
    'journal-recovery-live',
    'navigation-live',
);
$verification_unverified = $test_bed_unverified;
$verification_failed     = ( is_array( $test_bed_cfg ) && is_array( $test_bed_check ) && ! $test_bed_check['ok'] );
$verification_http_fail  = ( is_array( $test_bed_cfg ) && ! $test_bed_http_ok );

foreach ( $suites as $name => $path ) {
    echo "\n=== $name ===\n";
    // Explicit "no test bed config" gate: when no test-bed.json is
    // present, the destructive live suites (which spawn children
    // against a real WordPress install) cannot run. The unit-only
    // path is the legitimate default for `php tests/run-all.php`
    // without a config; the destructive integration verification
    // is opt-in. The skip is recorded in the report so the test
    // output makes clear which suites are *integration* and which
    // are unit-only.
    if ( ! is_array( $test_bed_cfg ) && in_array( $name, $destructive_live_suites, true ) ) {
        echo "  SKIPPED: no test-bed config — destructive integration suite requires\n";
        echo "           a verified test installation. The unit-only mode is the\n";
        echo "           legitimate default; the destructive path is opt-in via\n";
        echo "           tests/config/test-bed.json.\n";
        $skipped_suites[ $name ] = 'no_test_bed_config';
        Agentshell_Test::$passed = 0;
        Agentshell_Test::$failed = 0;
        Agentshell_Test::summary();
        continue;
    }
    if ( $verification_unverified && in_array( $name, $destructive_live_suites, true ) ) {
        echo "  SKIPPED: runner is unverified (no mysqli in the runner's PHP).\n";
        echo "           Re-run with the test-bed's PHP to enable destructive verification.\n";
        $skipped_suites[ $name ] = 'unverified';
        Agentshell_Test::$passed = 0;
        Agentshell_Test::$failed = 0;
        Agentshell_Test::summary();
        continue;
    }
    if ( $verification_failed && in_array( $name, $destructive_live_suites, true ) ) {
        echo "  SKIPPED: fail-closed check failed (refusal: " . $test_bed_check['refusal'] . ").\n";
        $skipped_suites[ $name ] = 'failed_verification';
        Agentshell_Test::$passed = 0;
        Agentshell_Test::$failed = 0;
        Agentshell_Test::summary();
        continue;
    }
    if ( $verification_http_fail && in_array( $name, $destructive_live_suites, true ) ) {
        echo "  SKIPPED: HTTP identity handshake failed — HTTP site is not the same\n";
        echo "           installation as the CLI/DB path the runner verified. The\n";
        echo "           destructive live suites are gated on the round-trip, not\n";
        echo "           on hostname/port matching alone.\n";
        $skipped_suites[ $name ] = 'failed_http_identity';
        Agentshell_Test::$passed = 0;
        Agentshell_Test::$failed = 0;
        Agentshell_Test::summary();
        continue;
    }
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
echo "$total_passed passed, $total_failed failed";
if ( ! empty( $skipped_suites ) ) {
    echo ", " . count( $skipped_suites ) . " skipped";
    foreach ( $skipped_suites as $n => $why ) {
        echo "\n  skipped: $n ($why)";
    }
}
echo "\n";
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

// ── end-of-suite leak assertion ─────────────────────────────────────────────
// When a test bed is configured, every test must leave the test database
// exactly as the baseline snapshot describes. The leak check spawns a
// child PHP (LocalWP's PHP, with the test socket) that connects to the
// test database, scans for fixture markers, and reports any leftovers.
// A leftover is any post whose title carries a per-run token prefix
// (ASTB/ASjr/AScw/ASNav), any post whose `_agentshell_journal_op` meta
// exists, or any nav menu term whose name starts with `ASTB `. The
// thresholds are zero by design: a clean run is a clean run. The
// assertion is run even when live suites were skipped (no WordPress
// install found) because fixture state from a prior crashed run
// persists on disk and on the database.
if ( is_array( $test_bed_cfg ) ) {
    echo "\n=== LEAK CHECK (test bed: " . (string) ( $test_bed_cfg['_config_path'] ?? '' ) . ") ===\n";
    if ( $verification_unverified ) {
        echo "  SKIPPED: runner is unverified (no mysqli in the runner's PHP).\n";
        echo "           Re-run with the test-bed's PHP to enable the leak assertion.\n";
    } elseif ( $verification_failed ) {
        echo "  SKIPPED: fail-closed check failed (refusal: " . $test_bed_check['refusal'] . ").\n";
    } elseif ( ! $test_bed_http_ok ) {
        echo "  SKIPPED: HTTP identity handshake failed — the leak check runs against\n";
        echo "           the test database via the same identity path; if HTTP and\n";
        echo "           CLI do not agree, the leak check cannot run with confidence.\n";
    } else {
        $leak_report = sys_get_temp_dir() . '/agentshell-leak-check-' . getmypid() . '.json';
        @unlink( $leak_report );

        // The leak check queries the test database. Use the strict
        // resolution path so the check never runs against an auto-
        // discovered (dev) site. A null return is a clean skip, not
        // a fail; if the test-bed config is missing, the destructive
        // suites above also skipped and the leak check has nothing
        // to verify against.
        $leak_cfg = agentshell_wp_live_config_destructive();
        if ( null === $leak_cfg ) {
            echo "  SKIP: no test-bed config (destructive suites also skipped)\n";
        } else {
            $leak_child = __DIR__ . '/agentshell-leak-check-child.php';
            if ( ! is_file( $leak_child ) ) {
                echo "  SKIP: child not present at $leak_child\n";
            } else {
                $leak_run = agentshell_wp_live_run(
                    $leak_cfg,
                    $leak_child,
                    array( $leak_cfg['wp_root'], $leak_report )
                );
                $leak = json_decode( (string) @file_get_contents( $leak_report ), true );
                @unlink( $leak_report );
                if ( ! is_array( $leak ) ) {
                    echo "  FAIL: child did not report: " . substr( $leak_run['output'], 0, 400 ) . "\n";
                    $total_failed++;
                } else {
                    $leak_count = 0;
                    foreach ( array( 'posts_with_fixture_title', 'posts_with_journal_marker', 'nav_menus_with_fixture_prefix' ) as $k ) {
                        $n = (int) ( $leak[ $k ] ?? 0 );
                        $leak_count += $n;
                        echo "  $k: $n\n";
                    }
                    if ( $leak_count > 0 ) {
                        echo "  LEAKS DETECTED — the test database is not clean.\n";
                        if ( ! empty( $leak['sample_post_titles'] ) ) {
                            echo "  sample post titles: " . implode( ', ', array_slice( (array) $leak['sample_post_titles'], 0, 5 ) ) . "\n";
                        }
                        if ( ! empty( $leak['sample_menu_names'] ) ) {
                            echo "  sample menu names:  " . implode( ', ', array_slice( (array) $leak['sample_menu_names'], 0, 5 ) ) . "\n";
                        }
                        $total_failed++;
                    } else {
                        echo "  ok: test database is clean\n";
                    }
                }
            }
        }
    }
}

exit( $total_failed === 0 ? 0 : 1 );