<?php
/**
 * Unified test runner — runs every suite, exits non-zero on any failure.
 *
 * How a suite runs
 * ----------------
 * Each suite is a **process**, not a file the runner `require`s. The
 * runner spawns `tests/run-suite.php` for it, that script loads the
 * harness, requires the suite, and prints a machine-readable verdict.
 * The runner reads the verdict and aggregates it.
 *
 * This is not a preference. The runner used to `require` every suite
 * into its own process, which made the runner's lifecycle the suites'
 * lifecycle: a suite that called `exit()` — as `destructive-identity-guard`
 * did when it found no live WordPress — ended the *whole run*, silently,
 * with a success exit code. Every suite registered after it was skipped
 * without a word, and the totals the runner printed described a fraction
 * of the work. A suite is now a process, so it can exit, die or be
 * skipped without touching the run, and a suite that stops without
 * reporting a result is reported as **incomplete** — a failure of the
 * run, not a silent omission.
 *
 * The environment is resolved once, here, with the same functions the
 * suites use, so the runner and a suite cannot disagree about whether
 * an environment exists. That disagreement was the other half of the
 * early-exit defect: the runner judged a suite eligible because a
 * *profile file* existed, while the suite judged it ineligible because
 * the profile did not *resolve*.
 *
 * Every suite declares what it requires, next to its path:
 *
 *   'unit'        — no external dependency. Always eligible.
 *   'testbed'     — needs the opt-in test-bed profile, and only reads it.
 *   'live'        — needs a resolvable live WordPress, and only reads it.
 *   'destructive' — needs a *verified* test bed and writes to it: the
 *                   profile, the mysqli extension in the runner's PHP,
 *                   the fail-closed identity check and the HTTP identity
 *                   handshake must all be in place.
 *
 * A suite that cannot run is **skipped with a reason**, and a skip is
 * never counted as a pass. A suite that declares a skip in the suite
 * itself says so through `Agentshell_Test::skip()`.
 *
 * The report distinguishes passed, failed, skipped and incomplete, and
 * the exit status is non-zero if anything failed or was incomplete.
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
 *   - published-preview-credential-hygiene  the THIRD preview credential — the
 *                      one that renders a published page with its staged edit
 *                      overlaid — is redacted out of reported URLs, stripped
 *                      from caller-supplied ones, scrubbed from REQUEST_URI and
 *                      refused by the canonical-redirect guard, exactly like
 *                      the other two
 *   - published-preview-transport  the published-preview credential survives
 *                      the query string it travels in: base64url encoding, and
 *                      a URL builder that does not truncate its padding
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
 *   - stage-2c-foundation  Stage 2c Milestone 1: OP_EDIT_PUBLISHED journal
 *                      operation, strong immutable baseline, per-original
 *                      exclusive claim
 *   - stage-2c-shadow   Stage 2c Milestone 2: private-shadow creation,
 *                      successive authorised edits, idempotency
 *   - stage-2c-overlay  Stage 2c Milestone 3: secure published-content
 *                      preview overlay — credential mint/verify, the
 *                      overlay's verify_and_install gates, the_posts
 *                      substitute, field-level overlay filters, permalink
 *                      family, and template_redirect headers
 *
 *   - run-all-lifecycle  the runner's own regression test: an early-exiting
 *                      suite is incomplete, the suite after it still runs, a
 *                      failure is a failure and a skip is a skip
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
require_once __DIR__ . '/runner-dispatch.php';

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
    'transaction-preview' => array(
        'path'     => __DIR__ . '/transaction-preview-test.php',
        'requires' => 'unit',
    ),
    'post-preview' => array(
        'path'     => __DIR__ . '/post-preview-test.php',
        'requires' => 'unit',
    ),
    // Shares the fixture globals with post-preview above, so it must run in the
    // same process and after it.
    'preview-credential-hygiene' => array(
        'path'     => __DIR__ . '/preview-credential-hygiene-test.php',
        'requires' => 'unit',
    ),
    'published-preview-credential-hygiene' => array(
        'path'     => __DIR__ . '/published-preview-credential-hygiene-test.php',
        'requires' => 'unit',
    ),
    'published-preview-transport' => array(
        'path'     => __DIR__ . '/published-preview-transport-test.php',
        'requires' => 'unit',
    ),
    // Loads the real Store, Doctor and Transaction_Manager and reads the
    // preview gate's credential, so it belongs beside the suites that have
    // already put those in memory.
    'config-consistency' => array(
        'path'     => __DIR__ . '/config-consistency-test.php',
        'requires' => 'unit',
    ),
    // The configuration's round trip through the REST surface: what is
    // stored is what is read back, and what is read back is what a
    // second read agrees with. Unit-only.
    'config-roundtrip' => array(
        'path'     => __DIR__ . '/config-roundtrip-test.php',
        'requires' => 'unit',
    ),
    // The stored configuration's historical geometry (design.layout
    // borderWidth/borderStyle) and the unrelated-edit refusal it used to
    // cause. Unit-only, with the real captured shape as its fixture.
    'config-legacy-layout' => array(
        'path'     => __DIR__ . '/config-legacy-layout-test.php',
        'requires' => 'unit',
    ),
    // The transaction read the configurator reloads through: the owner's
    // staged draft in GET shape, a foreign transaction's existence without
    // its contents, and an expired lease reported as stale.
    'configurator-transaction-read' => array(
        'path'     => __DIR__ . '/configurator-transaction-read-test.php',
        'requires' => 'unit',
    ),
    // History management: descriptive revision labels, individual and bulk
    // deletion, tombstones that preserve lineage, audit rows that outlive the
    // revision ring, and the committed configuration staying untouched.
    'history-management' => array(
        'path'     => __DIR__ . '/history-management-test.php',
        'requires' => 'unit',
    ),
    // Loads the real Store, Doctor and Transaction_Manager and drives the
    // lease with a frozen clock, so it belongs after the suites that have
    // already put those in memory.
    'transaction-lease' => array(
        'path'     => __DIR__ . '/transaction-lease-test.php',
        'requires' => 'unit',
    ),
    // Defines wp_insert_post()/wp_update_post()/get_permalink() stubs for the
    // runner's shared process, and drives the bounded content-write guard with
    // a recording wpdb. Runs after the suites that need the real Store.
    'content-write' => array(
        'path'     => __DIR__ . '/content-write-test.php',
        'requires' => 'unit',
    ),
    // Needs those same stubs, and a real WordPress. Skips without one.
    'content-write-live' => array(
        'path'     => __DIR__ . '/content-write-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Defines get_post_meta()/wp_delete_post() for the runner's shared process,
    // which the earlier content-write stubs deliberately did not, and drives the
    // journal's compensation path against them.
    'journal-recovery' => array(
        'path'     => __DIR__ . '/journal-recovery-test.php',
        'requires' => 'unit',
    ),
    // Needs that post meta, and a real WordPress and database — which is the
    // point of it, since the marker lookup and the force delete are exactly what
    // a stub would agree with for the wrong reason. Skips without one.
    'journal-recovery-live' => array(
        'path'     => __DIR__ . '/journal-recovery-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    'security' => array(
        'path'     => __DIR__ . '/security-test.php',
        'requires' => 'unit',
    ),
    'hardening' => array(
        'path'     => __DIR__ . '/hardening-test.php',
        'requires' => 'unit',
    ),
    'inject-block' => array(
        'path'     => __DIR__ . '/inject-block-test.php',
        'requires' => 'unit',
    ),
    'widget-registry' => array(
        'path'     => __DIR__ . '/widget-registry-test.php',
        'requires' => 'unit',
    ),
    // HAL 9001 remediation regression: a widget's init function must
    // actually be called. A widget's init_js only *registers* an
    // initialiser, and nothing invoked it, so every widget that needed
    // JavaScript rendered as an empty element. The behavioural half runs
    // in Node against a small DOM stub; the PHP half checks that the
    // runtime the theme emits recognises the attribute the renderers
    // emit. Non-destructive unit suite.
    'widget-init' => array(
        'path'     => __DIR__ . '/widget-init-test.php',
        'requires' => 'unit',
    ),
    'widget-root-marker' => array(
        'path'     => __DIR__ . '/widget-root-marker-test.php',
        'requires' => 'unit',
    ),
    // The widget area and widget asset surfaces: which areas the doctor
    // accepts, and that a widget's CSS is emitted once. Unit-only.
    'widget-areas' => array(
        'path'     => __DIR__ . '/widget-areas-test.php',
        'requires' => 'unit',
    ),
    'widget-assets-emit' => array(
        'path'     => __DIR__ . '/widget-assets-emit-test.php',
        'requires' => 'unit',
    ),
    'render-block' => array(
        'path'     => __DIR__ . '/render-block-test.php',
        'requires' => 'unit',
    ),
    // Navigation blocks: menu selection, resolution, the no-substitution
    // guarantee, unique ids, escaping, the doctor's dependency warnings and
    // MCP ↔ configurator equivalence. Must run before nine-area-zones, which
    // reuses the menu stubs this suite installs first (tests/agentshell-nav-stubs.php).
    'navigation-blocks' => array(
        'path'     => __DIR__ . '/navigation-blocks-test.php',
        'requires' => 'unit',
    ),
    // The navigation script's open/close/Escape rules, in Node.
    'nav-model' => array(
        'path'     => __DIR__ . '/run-nav-model.php',
        'requires' => 'unit',
    ),
    // The redesigned editor: its own model (Node), the editor itself driven
    // in a real browser against the theme's real stylesheet, modules and
    // markup, and the authorisation boundary that decides who is sent it.
    //
    // These sit here because the runner stops at the destructive-identity-
    // guard suite when there is no live WordPress to guard: a suite
    // registered after it would never run in the unit-only job. The CI
    // workflow runs the Node suites directly as well, so a skip in the
    // runner can never be mistaken for a pass.
    'configurator-editor' => array(
        'path'     => __DIR__ . '/run-configurator-editor.php',
        'requires' => 'unit',
    ),
    'configurator-browser' => array(
        'path'     => __DIR__ . '/run-configurator-browser.php',
        'requires' => 'unit',
    ),
    // The component picker's choices: the vocabulary the server supports,
    // the names it uses, and what it says when the site offers nothing.
    'configurator-picker' => array(
        'path'     => __DIR__ . '/run-configurator-picker.php',
        'requires' => 'unit',
    ),
    // The secret a preview credential is signed with: one rule, in one
    // place, and an empty secret is not a secret.
    'preview-secret' => array(
        'path'     => __DIR__ . '/preview-secret-test.php',
        'requires' => 'unit',
    ),
    'configurator-enqueue' => array(
        'path'     => __DIR__ . '/configurator-enqueue-test.php',
        'requires' => 'unit',
    ),
    // Nine-area header/footer zones: canonical schema, the three-slot
    // compatibility layer, markup parity, layout derivation, validation,
    // and MCP ↔ configurator equivalence. Defines the wp_core stubs the
    // shared bootstrap omits, guarded with function_exists().
    'nine-area-zones' => array(
        'path'     => __DIR__ . '/nine-area-zones-test.php',
        'requires' => 'unit',
    ),
    // Live HTTP regression for the WordPress on-demand block-style pipeline.
    // Reads the rendered home page and asserts that WP's own template
    // enhancement output buffer ran to completion (no placeholder stubs
    // survive in <head>). Skips cleanly when no runtime is reachable.
    'block-style-pipeline' => array(
        'path'     => __DIR__ . '/block-style-pipeline-test.php',
        'requires' => 'unit',
    ),
    'flatten' => array(
        'path'     => __DIR__ . '/flatten-test.php',
        'requires' => 'unit',
    ),
    'doctor' => array(
        'path'     => __DIR__ . '/doctor-test.php',
        'requires' => 'unit',
    ),
    'audit-log' => array(
        'path'     => __DIR__ . '/audit-log-test.php',
        'requires' => 'unit',
    ),
    'static-token-auth' => array(
        'path'     => __DIR__ . '/static-token-auth-test.php',
        'requires' => 'unit',
    ),
    'app-password-auth' => array(
        'path'     => __DIR__ . '/app-password-auth-test.php',
        'requires' => 'unit',
    ),
    'tool-schema' => array(
        'path'     => __DIR__ . '/tool-schema-test.php',
        'requires' => 'unit',
    ),
    // Section E regression: agentshell_mcp_execute_tool filter must have
    // an accepted_args that matches its callback's parameter count. The
    // pre-fix source had a 4-parameter callback registered with
    // accepted_args=3, which turned every unknown tool name into an
    // uncaught ArgumentCountError → HTTP 500 instead of the JSON-RPC
    // METHOD_NOT_FOUND the dispatcher wants. Non-destructive: reads
    // the plugin source and exercises the filter via the test
    // bootstrap, no WordPress install required.
    'mcp-execute-tool-dispatch' => array(
        'path'     => __DIR__ . '/mcp-execute-tool-dispatch-test.php',
        'requires' => 'unit',
    ),
    // Gate 3 §3: every destructive child script (`tests/*-live-child.php`)
    // must call agentshell_destructive_child_identity_guard() before
    // require_once wp-load.php and exit 7 when the wp_root does not match
    // the test-bed profile. The parent runner's agentshell_test_bed_check()
    // runs once before any suite; the guard runs in the standalone child
    // even when the parent is bypassed. Negative cases (bad wp_root,
    // integration site's wp_root, wrong sentinel) refuse; the contaminated
    // test bed refuses until Phase 4 restores it.
    'destructive-identity-guard' => array(
        'path'     => __DIR__ . '/destructive-identity-guard-test.php',
        'requires' => 'live',
    ),    // Read-only against a live WordPress: it resolves one itself and
    // asserts that a destructive child refuses the wrong installation.

    'destructive-identity-guard-siteurl' => array(
        'path'     => __DIR__ . '/destructive-identity-guard-siteurl-test.php',
        'requires' => 'testbed',
    ),    // Needs the opt-in test-bed profile: it asserts something about the
    // profile's own contents, so it has nothing to assert without one.

    'bootstrap-contract' => array(
        'path'     => __DIR__ . '/bootstrap-contract-test.php',
        'requires' => 'unit',
    ),
    'security-headers' => array(
        'path'     => __DIR__ . '/security-headers-test.php',
        'requires' => 'unit',
    ),
    'daemon-version' => array(
        'path'     => __DIR__ . '/daemon-version-test.php',
        'requires' => 'unit',
    ),
    'navigation' => array(
        'path'     => __DIR__ . '/navigation-test.php',
        'requires' => 'unit',
    ),
    'navigation-live' => array(
        'path'     => __DIR__ . '/navigation-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Independent navigation blocks against a real WordPress: a named menu, a
    // registered location, nested items, unique list ids across placements,
    // and no substitution when a menu is missing. Destructive (creates and
    // deletes its own menus), so it is gated like navigation-live.
    'navigation-blocks-live' => array(
        'path'     => __DIR__ . '/navigation-blocks-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    'screenshot' => array(
        'path'     => __DIR__ . '/screenshot-test.php',
        'requires' => 'unit',
    ),
    'screenshot-privacy' => array(
        'path'     => __DIR__ . '/screenshot-privacy-test.php',
        'requires' => 'unit',
    ),
    // The request policy: what a capture is allowed to ask for, and what
    // the browser is told when it asks for something else. The first is
    // the decision, the second is the interception that enforces it
    // against a browser that pauses every request, and the third is the
    // same property against a real browser and two disposable sites.
    'browser-request-policy' => array(
        'path'     => __DIR__ . '/browser-request-policy-test.php',
        'requires' => 'unit',
    ),
    'screenshot-interception' => array(
        'path'     => __DIR__ . '/screenshot-interception-test.php',
        'requires' => 'unit',
    ),
    'screenshot-request-policy-live' => array(
        'path'     => __DIR__ . '/screenshot-request-policy-live-test.php',
        'requires' => 'unit',
    ),
    'browser-isolation' => array(
        'path'     => __DIR__ . '/browser-isolation-test.php',
        'requires' => 'unit',
    ),
    'browser-trust' => array(
        'path'     => __DIR__ . '/browser-trust-test.php',
        'requires' => 'unit',
    ),
    // The live-style layer: the browser-local overlay of the draft's design
    // tokens. Its bookkeeping is tested in Node; what it does to the page is
    // tested in a real browser, and against the real site.
    'configurator-live-style' => array(
        'path'     => __DIR__ . '/run-configurator-live-style.php',
        'requires' => 'unit',
    ),
    'configurator-state' => array(
        'path'     => __DIR__ . '/run-configurator-state.php',
        'requires' => 'unit',
    ),
    // The runner's own regression test: a suite that exits early is
    // reported as incomplete, a suite registered after it still runs, a
    // failing suite is reported as failed, and a skipped suite is never
    // counted as a pass. Unit-only.
    'run-all-lifecycle' => array(
        'path'     => __DIR__ . '/run-all-lifecycle-test.php',
        'requires' => 'unit',
    ),
    'mutation-sandbox' => array(
        'path'     => __DIR__ . '/mutation-sandbox-test.php',
        'requires' => 'unit',
    ),
    // Stage 2c Milestone 1: the journal/claim foundation. Unit-only.
    'stage-2c-foundation' => array(
        'path'     => __DIR__ . '/stage-2c-foundation-test.php',
        'requires' => 'unit',
    ),
    // Stage 2c Milestone 2: private-shadow creation, successive authorised
    // edits, idempotency. Unit-only; needs the wp_insert_post/wp_update_post
    // stubs defined inline. Runs after journal-recovery so the postmeta
    // store is already in memory if it ever needs to be.
    'stage-2c-shadow' => array(
        'path'     => __DIR__ . '/stage-2c-shadow-test.php',
        'requires' => 'unit',
    ),
    // Stage 2c Milestone 3: secure published-content preview overlay.
    // Unit-only; covers credential mint/verify, the overlay's verify_and_install
    // gates, the_posts substitute, field-level overlay filters, permalink
    // family, and template_redirect headers. Live verification is in
    // stage-2c-overlay-live-test.php and is gated on the test-bed check.
    'stage-2c-overlay' => array(
        'path'     => __DIR__ . '/stage-2c-overlay-test.php',
        'requires' => 'unit',
    ),
    // The Stage 2c review: the invariants the M5 audit produced, checked
    // against the classes as they are now. Unit-only.
    'stage-2c-review' => array(
        'path'     => __DIR__ . '/stage-2c-review-test.php',
        'requires' => 'unit',
    ),
    // The publish-edit tool's unit half: the allowlist, the claim, the
    // shadow, and the refusal cascade, all against stubs. The live half
    // is stage-2c-publish-edit-live below. Unit-only.
    'stage-2c-publish-edit' => array(
        'path'     => __DIR__ . '/stage-2c-publish-edit-test.php',
        'requires' => 'unit',
    ),
    // Stage 2c Milestone 3 live verification: the production overlay
    // code path against a real WordPress install. Gated on the test-bed
    // fail-closed check having passed; otherwise skipped cleanly.
    'stage-2c-overlay-live' => array(
        'path'     => __DIR__ . '/stage-2c-overlay-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Stage 2c Milestone 4 live verification: the publish-edit tool
    // against a real WordPress install. Runs the same publication
    // path the unit tests drive with a recording wpdb, but with
    // wp_update_post against a real wp_posts row, a real advisory
    // lock, a real per-original claim swap, and a real anonymous
    // HTTP request that walks the permalink redirect chain.
    'stage-2c-publish-edit-live' => array(
        'path'     => __DIR__ . '/stage-2c-publish-edit-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Stage 2c M5-s2 self-test: confirms the end-of-suite leak
    // check reports synthetic agentshell_published_claim_* rows
    // and synthetic _agentshell_shadow_* postmeta rows. Gated on
    // the same test-bed fail-closed check the other destructive
    // live suites use.
    'stage-2c-leak-check-live' => array(
        'path'     => __DIR__ . '/stage-2c-leak-check-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Stage 2c M5-t1 race regression: two concurrent publish_edit
    // calls (R1) and two concurrent publish_edit_reconcile calls
    // (R2) against the same op_id. Spawns two child PHP processes
    // per race with file-based barriers, asserts exactly one
    // committed / reconciled and one refused. Destructive live.
    'stage-2c-publish-edit-race-live' => array(
        'path'     => __DIR__ . '/stage-2c-publish-edit-race-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Stage 2c M5-t2 post-COMMIT recovery: spawn a pre-commit child
    // that runs the publish SQL transaction and exits without
    // reconciliation, then spawn a fresh-process child that calls
    // Publish_Edit_Reconcile. Asserts publication_committed is
    // authoritative, the post is durable, the audit count is exactly
    // 1, the shadow and claim survive, reconciliation settles without
    // re-publishing, and a second reconcile is mutation-free.
    // Destructive live.
    'stage-2c-publish-edit-recovery-live' => array(
        'path'     => __DIR__ . '/stage-2c-publish-edit-recovery-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Stage 2c M5-t3 end-to-end acceptance: the full published-edit
    // lifecycle against a real WordPress install — page → claim →
    // shadow → stage → preview (real HTTP) → publish → reconcile,
    // plus retry safety, anonymous-isolation, forged / expired /
    // wrong-actor credentials, baseline drift, and outside-the-
    // four-fields shadow changes. Destructive live.
    'stage-2c-end-to-end-live' => array(
        'path'     => __DIR__ . '/stage-2c-end-to-end-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Gate 3 Section D regression: journal-only commit must succeed
    // without a dummy set_css_var, must preserve the persisted config,
    // and must not regress the empty-tx refuse or the staged-only path.
    // Destructive live: gated on the same fail-closed check the other
    // destructive suites use.
    'journal-only-commit-live' => array(
        'path'     => __DIR__ . '/journal-only-commit-live-test.php',
        'requires' => 'destructive',
    ),
    // The rollback evidence a journal-only commit leaves behind, against
    // a real WordPress. Destructive: it writes to the test site.
    'rollback-evidence-live' => array(
        'path'     => __DIR__ . '/rollback-evidence-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Gate 3 Task 4 regression: create_draft must not refuse the second
    // and subsequent creates in a single transaction, must not refuse on
    // a site with prior journal state, and must fail closed on a
    // tampered-signature journal or a stuck-intended tx. Eight scenarios
    // plus a no-orphan teardown, all through a real WordPress install
    // with the journal and the option cache. Destructive live.
    'create-draft-cas-persistent-live' => array(
        'path'     => __DIR__ . '/create-draft-cas-persistent-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Phase 1 port of the integration worktree's F7/F8 refusal
    // scenarios (tampered-signature journal, stuck intended op) as a
    // separate destructive-live suite, leaving the development
    // create-draft-cas-persistent-live F0–F9 (stress + idempotency
    // resume) intact. Destructive live: gated on the same fail-closed
    // check the other destructive suites use.
    'create-draft-cas-refusal-live' => array(
        'path'     => __DIR__ . '/create-draft-cas-refusal-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Gate 3 Task 5 regression: the new `agentshell_edit_published`
    // staging tool. Exercised through the same real WordPress install
    // the other destructive live suites use, against a per-run
    // `page-{orig_id}.php`-style seeded original. Covers the 6-step
    // refusal cascade, the 5 idempotency cases, the per-original claim,
    // the shadow META_OP_ID invariant, the head-map CAS, and the
    // no-orphan teardown. Destructive live.
    // HAL 9001 remediation regression: the compare-and-swap on the
    // transaction row read MySQL's changed-row count as a verdict, so a
    // write whose bytes equal the bytes already stored was reported as a
    // lost race. Staging the same configuration twice inside one second
    // failed, and so did a journal head whose compare fields had not
    // moved. Non-destructive unit suite.
    'transaction-cas' => array(
        'path'     => __DIR__ . '/transaction-cas-test.php',
        'requires' => 'unit',
    ),
    // HAL 9001 remediation regression: the custom CSS size limit. The
    // limit is 256 KiB (262,144 bytes), measured in bytes of the UTF-8
    // encoding, and it is applied in the theme's validator, in the MCP
    // staging path, in the write interceptor, in the doctor and in the
    // theme import. The exact boundary (262,144 accepted, 262,145
    // refused) and the byte-not-character measurement are pinned here.
    // Non-destructive unit suite.
    'custom-css-limit' => array(
        'path'     => __DIR__ . '/custom-css-limit-test.php',
        'requires' => 'unit',
    ),
    // The custom stylesheet as a static, cacheable file: the file's bytes
    // and the inline block's bytes are the same string, the URL changes
    // exactly when the content changes, a materialisation failure leaves
    // the site rendering inline, and the pruning touches nothing but this
    // feature's own old files. Non-destructive unit suite.
    'custom-css-asset' => array(
        'path'     => __DIR__ . '/custom-css-asset-test.php',
        'requires' => 'unit',
    ),
    // The deployment is a build, and this suite asserts what it carries.
    // It builds the archive, so it runs after the suites that change the
    // working tree.
    'release-archive' => array(
        'path'     => __DIR__ . '/release-archive-test.php',
        'requires' => 'unit',
    ),
    // HAL 9001 remediation regression: the saved-configuration token
    // block is CSS, not HTML. It was emitted with esc_attr() — the HTML
    // attribute escaper — inside a <style> element, where entities are
    // not decoded, so a quoted font stack reached the browser as
    // &quot;Inter&quot;. The block now uses a CSS-context escape that keeps
    // the characters CSS needs and removes the ones that could end a
    // declaration, open a rule or close the element. Non-destructive
    // unit suite.
    'css-context' => array(
        'path'     => __DIR__ . '/css-context-test.php',
        'requires' => 'unit',
    ),
    // The layout tokens: the four known layout keys, and the deep merge
    // of a user's breakpoints over the defaults. Unit-only.
    'layout-css' => array(
        'path'     => __DIR__ . '/layout-css-test.php',
        'requires' => 'unit',
    ),
    // HAL 9001 remediation regression: a missing argument is refused by
    // name instead of raising a PHP warning and creating something the
    // caller never asked for. Non-destructive unit suite.
    'argument-handling' => array(
        'path'     => __DIR__ . '/argument-handling-test.php',
        'requires' => 'unit',
    ),
    // HAL 9001 remediation regression: the small diagnostics defects. The
    // type scale reaches the CSS variables instead of being stored and
    // ignored; the doctor does not warn about a widget the operator
    // retired; the update response names the fields it wrote; the
    // daemon's compatibility notice is verbose-only. Non-destructive unit
    // suite.
    'diagnostics' => array(
        'path'     => __DIR__ . '/diagnostics-test.php',
        'requires' => 'unit',
    ),
    // HAL 9001 remediation regression: the documented tool count must
    // equal the number the plugins register. A count that is written
    // down can only be kept right by remembering to change it; this one
    // fails the build when it drifts. Non-destructive unit suite.
    'tool-count' => array(
        'path'     => __DIR__ . '/tool-count-test.php',
        'requires' => 'unit',
    ),
    // The documentation gates of AGENTS.md §9, executable: a stale tool
    // count, a duplicated tool table or a contradicted source-of-truth
    // statement is a build failure, not a surprise for whoever reads
    // the file next.
    'doc-consistency' => array(
        'path'     => __DIR__ . '/doc-consistency-test.php',
        'requires' => 'unit',
    ),
    // Site identity and reading settings: the bounded surface, its
    // validation, and the projection onto WordPress's own options.
    'site-settings' => array(
        'path'     => __DIR__ . '/site-settings-test.php',
        'requires' => 'unit',
    ),
    // The DevTools client's wire format: pure functions, no browser.
    'cdp' => array(
        'path'     => __DIR__ . '/cdp-test.php',
        'requires' => 'unit',
    ),
    // The narrow-viewport capture, against a real browser. Skips when the
    // host has no Chrome.
    'viewport-live' => array(
        'path'     => __DIR__ . '/viewport-live-test.php',
        'requires' => 'unit',
    ),
    // HAL 9001 remediation regression: the published-edit field
    // preservation contract. A partial edit must inherit every field the
    // caller did not name (title, slug, excerpt) instead of blanking it,
    // an explicitly empty excerpt must be honoured as a deliberate clear,
    // an explicitly empty title or body must be refused rather than
    // published, a second staged edit must inherit the first edit's
    // staged values, and the publish step must refuse to write a field
    // the transaction never authorised. Destructive live: gated on the
    // same fail-closed check the other destructive suites use.
    'edit-published-preservation-live' => array(
        'path'     => __DIR__ . '/edit-published-preservation-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // HAL 9001 remediation regression: the MCP route to a staged edit of
    // a published page. The published-preview overlay credential existed
    // but no tool exposed it, so the only way to see a staged edit was to
    // capture the shadow row's id by hand, on an undocumented route.
    // Destructive live: gated on the same fail-closed check the other
    // destructive suites use.
    'published-preview-live' => array(
        'path'     => __DIR__ . '/published-preview-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    'published-preview-ownership-live' => array(
        'path'     => __DIR__ . '/published-preview-ownership-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    'edit-published-live' => array(
        'path'     => __DIR__ . '/edit-published-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Phase 3 regression: the preflight's `multi_query` + drain
    // sequence must catch queued-statement failures (a duplicate-key
    // collision surfaced by `next_result()` after the first
    // statement succeeded) and return a structured `restore_failed`
    // action — not propagate the `mysqli_sql_exception` to the
    // caller. The child constructs an in-process poison baseline so
    // the failure is deterministic. Destructive live: gated on the
    // same fail-closed check the other destructive suites use.
    'agentshell-test-bed-preflight-live' => array(
        'path'     => __DIR__ . '/agentshell-test-bed-preflight-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Site settings against a real WordPress: a staged setting does not
    // reach the live site, a commit applies it, a rollback does not, and a
    // refused setting changes nothing. Destructive: it writes the site's
    // own options (and restores them).
    'site-settings-live' => array(
        'path'     => __DIR__ . '/site-settings-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // The custom stylesheet as a static file, against a real WordPress: a
    // staged stylesheet never reaches a file, the preview inlines it, a
    // commit materialises it with the same bytes the inline block would
    // have carried, and a snapshot restore re-materialises. Destructive:
    // it writes the site's own configuration (and restores it).
    'custom-css-asset-live' => array(
        'path'     => __DIR__ . '/custom-css-asset-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Reversible content deletion against a real WordPress: the content
    // reaches the Trash and not the bin, a restore brings it back, both
    // operations are idempotent, and every refusal leaves the content
    // exactly as it was. Destructive: it creates and removes content.
    'content-trash-live' => array(
        'path'     => __DIR__ . '/content-trash-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // Compact responses: the default contract is unchanged, and the
    // compact form is measurably smaller. Destructive: it creates content.
    'compact-response-live' => array(
        'path'     => __DIR__ . '/compact-response-live-test.php',
        'requires' => 'destructive',
    ),    // Destructive: it writes to the test site, so it runs only against a
    // *verified* test bed — the profile, mysqli in the runner's PHP, the
    // fail-closed identity check and the HTTP identity handshake.

    // audit_written contract regression: the
    // `reconciliation.audit_written` field is now an honest,
    // independently-verified boolean response. Covers the
    // happy path, audit-persistence failure (test seam),
    // COMMIT+re-read race (marker-only return path with
    // independent log verification), repeated reconciliation
    // idempotency, rejection of historical rows from other
    // transactions, rejection of vaguely-matching rows
    // (same tx_id, different op_id; same (tx_id, op_id),
    // wrong operation), and existing staging/rollback
    // preservation. Non-destructive unit suite.
    'audit-written' => array(
        'path'     => __DIR__ . '/audit-written-test.php',
        'requires' => 'unit',
    ),
    // The draft tools' bootstrap: the require_once lines in the plugin
    // are present, correctly ordered, and the classes load. Unit-only.
    'draft-tools-bootstrap' => array(
        'path'     => __DIR__ . '/draft-tools-bootstrap-test.php',
        'requires' => 'unit',
    ),
    // The mobile visibility of the header's and the footer's middle centre,
    // in both layouts, measured in a browser against the theme's real
    // stylesheet and the theme's real renderer.
    'mobile-zone-visibility' => array(
        'path'     => __DIR__ . '/run-mobile-zone-visibility.php',
        'requires' => 'unit',
    ),

    // ── AgentShell Forms, Phase 1 ───────────────────────────────────────────
    //
    // The standalone forms plugin (agentshell-forms). It depends on nothing in
    // AgentShell and works on plain WordPress, so these suites deliberately run
    // against the same disposable bed the other destructive suites use rather
    // than against the theme.

    // The authoritative schema: every §5.5 refusal by name, the field-type
    // registry, and the settings walk that stops the validator, the builder and
    // the renderer drifting apart. Pure functions, no database.
    'forms-schema' => array(
        'path'     => __DIR__ . '/forms-schema-test.php',
        'requires' => 'unit',
    ),
    // The storage invariants against real rows: one authoritative row per key
    // under an advisory lock, duplicate-meta detection, the `meta_id`-scoped
    // compare-and-swap, the publication transaction, and native revisions.
    // Destructive: it writes to the test site.
    'forms-storage' => array(
        'path'     => __DIR__ . '/forms-storage-test.php',
        'requires' => 'destructive',
    ),
    // Twelve races, each contender a separate process with its own MySQL
    // connection, released together by a file barrier. Destructive.
    'forms-concurrency' => array(
        'path'     => __DIR__ . '/forms-concurrency-test.php',
        'requires' => 'destructive',
    ),
    // The bounded-transaction contract: what happens when BEGIN, COMMIT or
    // ROLLBACK fails, why a required transaction refuses to run its callback,
    // why a nested call joins instead of opening a second transaction, and how
    // an unconfirmed commit is reconciled from the data. Pure unit: `$wpdb` is
    // replaced with a recorder whose answers are scripted.
    'forms-transaction' => array(
        'path'     => __DIR__ . '/forms-transaction-test.php',
        'requires' => 'unit',
    ),
    // The challenge and rate-limit stores: hashing, consume-once, expiry, the
    // counter ceiling, window rollover and pruning. The concurrent halves live
    // in `forms-concurrency`. Destructive.
    'forms-protocol' => array(
        'path'     => __DIR__ . '/forms-protocol-test.php',
        'requires' => 'destructive',
    ),
    // Two permission domains, the per-user grant that keeps them separate, and
    // the `admin_post` gate an application password cannot pass. Destructive:
    // it creates and deletes users, and it runs the real handlers.
    'forms-capabilities' => array(
        'path'     => __DIR__ . '/forms-capabilities-test.php',
        'requires' => 'destructive',
    ),
    // Activation, deactivation and the two-constant uninstall. Destructive: it
    // drops and recreates the plugin's tables.
    'forms-lifecycle' => array(
        'path'     => __DIR__ . '/forms-lifecycle-test.php',
        'requires' => 'destructive',
    ),

    // ── AgentShell Forms, Phase 2 ───────────────────────────────────────────

    // The renderer's markup, escaping and accessibility contract for all ten
    // field types, plus the published-only selection rule. Pure functions: no
    // database, no WordPress.
    'forms-render' => array(
        'path'     => __DIR__ . '/forms-render-test.php',
        'requires' => 'unit',
    ),
    // The same markup in a real browser: contrast, focus, per-instance
    // challenge provisioning and the bfcache refresh. Node and a browser are
    // required; either one missing is a skip here and a failure in CI.
    'forms-render-browser' => array(
        'path'     => __DIR__ . '/run-forms-render-browser.php',
        'requires' => 'unit',
    ),
    // The shortcode, the widget, the documented API and the public challenge
    // route against a real WordPress: publish, edit without publishing, render,
    // mint, rate-limit, clean up. Destructive.
    'forms-render-live' => array(
        'path'     => __DIR__ . '/forms-render-live-test.php',
        'requires' => 'destructive',
    ),

    // ── AgentShell Forms, Phase 3 ───────────────────────────────────────────

    // Cloudflare Turnstile: the three policies, every verification branch, the
    // fixed endpoint, the empty-constant rule and the refusal of a test
    // credential in production. The transport is injected, so no network.
    'forms-turnstile' => array(
        'path'     => __DIR__ . '/forms-turnstile-test.php',
        'requires' => 'unit',
    ),
    // What a notification says: the templates, the header-injection defences,
    // the recipient allowlist and the two clock invariants. Pure functions.
    'forms-notifier' => array(
        'path'     => __DIR__ . '/forms-notifier-test.php',
        'requires' => 'unit',
    ),
    // The whole submission pipeline against a real WordPress: validation before
    // the claim, the replay, a genuinely stale page, foreign and expired
    // challenges, the honeypot, the quota, Turnstile's policies, declared-field
    // storage, permission checks and the export. Destructive.
    'forms-submission' => array(
        'path'     => __DIR__ . '/forms-submission-test.php',
        'requires' => 'destructive',
    ),
    // The notification lifecycle with a transport that accepts, refuses, hangs
    // and throws: claims, backoff, exhaustion, reconciliation and the rule that
    // a mail failure never creates a submission. Destructive.
    'forms-notification-live' => array(
        'path'     => __DIR__ . '/forms-notification-live-test.php',
        'requires' => 'destructive',
    ),
    // Retention as a hard delete (per form, inherited, orphaned, trashed) and
    // the export's spreadsheet-formula defence. Destructive.
    'forms-retention' => array(
        'path'     => __DIR__ . '/forms-retention-test.php',
        'requires' => 'destructive',
    ),
    // WordPress's own privacy exporter and eraser. Destructive.
    'forms-privacy' => array(
        'path'     => __DIR__ . '/forms-privacy-test.php',
        'requires' => 'destructive',
    ),
    // The submission pipeline's races in separate processes: one challenge and
    // four contenders, six contenders against a quota of three, and two mail
    // workers claiming one record. Destructive.
    'forms-submission-concurrency' => array(
        'path'     => __DIR__ . '/forms-submission-concurrency-test.php',
        'requires' => 'destructive',
    ),
    // The client a visitor actually runs: success, field errors, a stale page,
    // a pending attempt, a retry, a lost response, a double click and a failed
    // provisioning. Node and a browser are required; either missing is a skip
    // here and a failure in CI.
    'forms-submit-browser' => array(
        'path'     => __DIR__ . '/run-forms-submit-browser.php',
        'requires' => 'unit',
    ),
    'forms-builder-browser' => array(
        'path'     => __DIR__ . '/run-forms-builder-browser.php',
        'requires' => 'unit',
    ),
    // The optimistic-concurrency guarantees the builder depends on: a stale
    // discard refused, a current discard reporting its revision, a rename
    // guarded the same way, and a duplicate that is a distinct form.
    'forms-builder-storage' => array(
        'path'     => __DIR__ . '/forms-builder-storage-test.php',
        'requires' => 'destructive',
    ),
    // The full builder workflow in a real browser against a real WordPress:
    // create, fields, settings, reorder, preview, save, publish, shortcode
    // page, unpublished edit, in-flight save, discard, validation and network
    // recovery, two-editor conflict, multi-form selection, rename, duplicate,
    // trash and insertion. Destructive: it writes forms and pages. The browser
    // run is bounded (timeout 240); a timeout fails, never passes green.
    'forms-builder-acceptance' => array(
        'path'     => __DIR__ . '/forms-builder-acceptance-test.php',
        'requires' => 'destructive',
    ),
);

// ── the environment, resolved once ───────────────────────────────────────────
// What this machine can offer is decided here, once, and every suite is
// judged against the same answer. The runner used to decide a suite's
// eligibility from the *presence of a profile file*, while the suite
// decided it from whether that profile *resolved*; the two disagreed,
// and the suite's `exit()` ended the whole run. The environment is now
// resolved with the same functions the suites use, and the disagreement
// cannot arise.
$verification_unverified = $test_bed_unverified;
$verification_failed     = ( is_array( $test_bed_cfg ) && is_array( $test_bed_check ) && ! $test_bed_check['ok'] );
$verification_http_fail  = ( is_array( $test_bed_cfg ) && ! $test_bed_http_ok );

$agentshell_environment = agentshell_runner_environment( array(
    'test_bed_cfg'   => $test_bed_cfg,
    'test_bed_check' => $test_bed_check,
    'http_ok'        => $test_bed_http_ok,
    'unverified'     => $test_bed_unverified,
    'live'           => agentshell_wp_live_config(),
    'destructive'    => agentshell_wp_live_config_destructive(),
) );

echo "\n=== ENVIRONMENT ===\n";
echo "unit suites:        always available\n";
echo "test-bed profile:   " . ( $agentshell_environment['testbed'] ? 'present' : 'absent' ) . "\n";
echo "live WordPress:     " . ( $agentshell_environment['live'] ? 'resolved' : 'not resolved' ) . "\n";
echo "verified test bed:  " . ( $agentshell_environment['destructive'] ? 'verified' : 'not verified' ) . "\n";

// ── the run ─────────────────────────────────────────────────────────────────
// Every suite runs in its own process (tests/run-suite.php). A suite that
// exits, dies, or is skipped can no longer end the run, and every suite
// produces a verdict the summary can report.
$agentshell_run = agentshell_dispatch_suites( $suites, $agentshell_environment, true );

$total_passed   = $agentshell_run['passed'];
$total_failed   = $agentshell_run['failed'];
$failed_suites  = $agentshell_run['failed_suites'];
$skipped_suites = $agentshell_run['skipped'];
$incomplete_suites = $agentshell_run['incomplete'];

echo "\n=== TOTAL ===\n";
echo "$total_passed passed, $total_failed failed";
if ( ! empty( $skipped_suites ) ) {
    echo ", " . count( $skipped_suites ) . " skipped";
}
if ( ! empty( $incomplete_suites ) ) {
    echo ", " . count( $incomplete_suites ) . " incomplete";
}
echo "\n";
if ( ! empty( $skipped_suites ) ) {
    echo "\nSkipped suites (unexecuted, with the reason):\n";
    foreach ( $skipped_suites as $n => $why ) {
        echo "  $n — $why\n";
    }
}
if ( ! empty( $incomplete_suites ) ) {
    echo "\nIncomplete suites (the suite stopped without reporting a result):\n";
    foreach ( $incomplete_suites as $n => $why ) {
        echo "  $n — $why\n";
    }
}
if ( ! empty( $failed_suites ) ) {
    echo "\nFailed suites: " . implode( ', ', $failed_suites ) . "\n";
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
                    foreach ( array( 'posts_with_fixture_title', 'posts_with_journal_marker', 'nav_menus_with_fixture_prefix', 'claim_rows_with_fixture_prefix', 'shadow_meta_with_marker' ) as $k ) {
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
                        if ( ! empty( $leak['sample_claim_option_names'] ) ) {
                            echo "  sample claim option names: " . implode( ', ', array_slice( (array) $leak['sample_claim_option_names'], 0, 5 ) ) . "\n";
                        }
                        if ( ! empty( $leak['sample_shadow_meta_rows'] ) ) {
                            echo "  sample shadow meta rows: " . count( (array) $leak['sample_shadow_meta_rows'] ) . " of " . (int) ( $leak['shadow_meta_with_marker'] ?? 0 ) . "\n";
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