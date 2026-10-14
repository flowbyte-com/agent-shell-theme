<?php
/**
 * The mutation harnesses' isolation primitive, tested on its own.
 *
 * The harnesses used to mutate production files in the working tree and
 * restore them afterwards. Two things went wrong, and neither is visible to a
 * test that only checks whether the suites still pass:
 *
 *   1. A second run started while a mutant was in place, read mutated
 *      production code, and reported four failures that belonged to neither
 *      run.
 *   2. A harness that did not reach its restore step left the mutation on disk,
 *      and it survived until someone noticed `git status`.
 *
 * So isolation has to be a property of the harness, not a promise in its
 * docblock. This suite tests the mechanism that enforces it — a sandbox copy
 * of the checkout under the system temp directory — rather than re-running the
 * mutation suites, which would make this file as slow as they are.
 *
 * The fingerprint half of that mechanism is tested by actually creating,
 * editing and removing a file in the checkout, because a guard that never fires
 * is indistinguishable from no guard. It is dot-prefixed and removed in the
 * same breath; if the process dies mid-test the residue is a stray dotfile
 * rather than mutated source, and run-all.php's own fingerprint check reports
 * it.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mutation-sandbox.php';

$root    = agentshell_repo_root();
$sandbox = agentshell_mutation_sandbox_create();

Agentshell_Test::run( 'sandbox: it is created, and it is outside the checkout', function () use ( $sandbox, $root ) {
    Agentshell_Test::assert_true( is_dir( $sandbox ), 'the sandbox exists' );

    // If the sandbox were inside the checkout, "isolated" would be a word
    // rather than a fact, and the fingerprint would be watching itself change.
    Agentshell_Test::assert_false(
        str_starts_with( $sandbox . '/', $root . '/' ),
        'the sandbox is not inside the checkout'
    );
    Agentshell_Test::assert_true(
        str_starts_with( $sandbox . '/', rtrim( sys_get_temp_dir(), '/' ) . '/' ),
        'the sandbox lives under the system temp directory'
    );
} );

Agentshell_Test::run( 'sandbox: it is a usable stand-in for the checkout', function () use ( $sandbox, $root ) {
    // Suites resolve their sources through dirname( __DIR__ ), so a copied
    // tree is a drop-in — but only if the copy actually holds what they load.
    $required = array(
        'agentshell-mcp/includes/class-screenshot.php',
        'functions.php',
        'template-parts/shell-render.php',
        'tests/bootstrap.php',
        'tests/browser-isolation-test.php',
        'tests/browser-trust-test.php',
        'tests/preview-credential-hygiene-test.php',
    );
    foreach ( $required as $rel ) {
        Agentshell_Test::assert_true(
            is_file( $sandbox . '/' . $rel ),
            "the sandbox contains $rel, so the suite loads the mutant"
        );
        Agentshell_Test::assert_equals(
            file_get_contents( $root . '/' . $rel ),
            file_get_contents( $sandbox . '/' . $rel ),
            "the sandbox copy of $rel starts identical to the checkout"
        );
    }
} );

Agentshell_Test::run( 'sandbox: writing to it cannot change the checkout', function () use ( $sandbox, $root ) {
    // The property the whole refactor exists to provide. Mutate the copy the
    // way a harness does, and prove the production file is untouched.
    $target = 'agentshell-mcp/includes/class-screenshot.php';
    $real   = $root . '/' . $target;

    $real_before = hash_file( 'sha256', $real );
    $repo_before = agentshell_repo_snapshot( array( $real ) );

    file_put_contents( $sandbox . '/' . $target, "<?php\n// mutant written into the sandbox\n" );

    Agentshell_Test::assert_equals(
        $real_before,
        hash_file( 'sha256', $real ),
        'the checkout file is byte-identical after the sandbox copy is overwritten'
    );

    $after = agentshell_repo_diff( $repo_before, array( $real ) );
    Agentshell_Test::assert_equals(
        array(),
        array_merge( $after['changed'], $after['added'], $after['removed'] ),
        'the fingerprint reports no change after a sandbox write'
    );

    // Put the copy back, as a harness does between mutants.
    file_put_contents( $sandbox . '/' . $target, file_get_contents( $real ) );
    Agentshell_Test::assert_equals(
        file_get_contents( $real ),
        file_get_contents( $sandbox . '/' . $target ),
        'restoring the copy is enough for the next mutant to start from the original'
    );
} );

Agentshell_Test::run( 'fingerprint: a change is detected, not just absence of one', function () use ( $root ) {
    $canary = $root . '/.agentshell-integrity-canary-' . getmypid() . '.tmp';

    $before_empty = agentshell_repo_snapshot();

    file_put_contents( $canary, "canary\n" );
    $added = agentshell_repo_diff( $before_empty, array( $canary ) );
    Agentshell_Test::assert_true(
        in_array( $canary, $added['added'], true ),
        'a file added to the checkout is reported as added'
    );
    Agentshell_Test::assert_equals(
        "canary\n",
        file_get_contents( $canary ),
        'the canary holds the content it was given, so the comparison is real'
    );

    // Snapshot again now that it exists, so its removal is a change to compare
    // against rather than a path the earlier fingerprint never knew about.
    $with_canary = agentshell_repo_snapshot();

    unlink( $canary );
    $removed = agentshell_repo_diff( $with_canary, array( $canary ) );
    Agentshell_Test::assert_true(
        in_array( $canary, $removed['removed'], true ),
        'removing it again is reported as removed'
    );

    file_put_contents( $canary, "changed\n" );
    $edited = agentshell_repo_diff( $with_canary, array( $canary ) );
    Agentshell_Test::assert_true(
        in_array( $canary, $edited['changed'], true ),
        'an edited file is reported as changed, not silently accepted'
    );
    unlink( $canary );

    // Compared against the fingerprint from before the canary existed, so
    // "back to where it started" covers every kind of change.
    $settled = agentshell_repo_diff( $before_empty );
    Agentshell_Test::assert_equals(
        array(),
        array_merge( $settled['changed'], $settled['added'], $settled['removed'] ),
        'with the canary gone the checkout is back to where it started'
    );
} );

Agentshell_Test::run( 'lint: a broken mutant is reported, not counted as a kill', function () {
    // A parse error makes every assertion fail, which a naive kill check
    // scores as a kill for the wrong reason.
    $broken = agentshell_mutation_lint( "<?php\nfunction ( {\n" );
    Agentshell_Test::assert_not_equals( 0, $broken['rc'], 'a parse error is caught' );
    Agentshell_Test::assert_true( '' !== $broken['output'], 'the lint says why, so the report is actionable' );

    $fine = agentshell_mutation_lint( "<?php\n\$x = 1;\n" );
    Agentshell_Test::assert_equals( 0, $fine['rc'], 'a valid mutant passes the same check' );
} );

Agentshell_Test::run( 'suite runner: FAIL lines are counted, not exit codes', function () {
    // Standalone suites always exit 0, so a kill check keyed on the exit status
    // would score every mutant as surviving. A regression here would make both
    // mutation harnesses quietly report 0 of 17 killed — a green run that
    // proves nothing. The reporter indents FAIL lines, so a substring count
    // anchored mid-line misses them; both cases are pinned.
    $failing = tempnam( sys_get_temp_dir(), 'as-runner-' ) . '.php';
    file_put_contents( $failing, "<?php\necho \"  FAIL one thing\\n\";\necho \"  FAIL another thing\\n\";\n" );

    $result = agentshell_mutation_run_suite( $failing );
    Agentshell_Test::assert_equals( 2, $result['failed'], 'indented FAIL lines are counted' );
    Agentshell_Test::assert_equals( 0, $result['rc'], 'the exit status is 0 even when assertions failed' );
    unlink( $failing );

    $passing = tempnam( sys_get_temp_dir(), 'as-runner-' ) . '.php';
    file_put_contents( $passing, "<?php\necho \"  ok   a passing assertion\\n\";\n" );
    Agentshell_Test::assert_equals(
        0,
        agentshell_mutation_run_suite( $passing )['failed'],
        'a clean run counts zero'
    );
    unlink( $passing );
} );

Agentshell_Test::run( 'cleanup: the sandbox does not outlive its use', function () use ( $sandbox ) {
    // A symlink pointing out of the sandbox is the way a naive recursive
    // delete turns into data loss, so it gets its own case.
    $outside = sys_get_temp_dir() . '/as-sandbox-escapee-' . getmypid() . '.tmp';
    file_put_contents( $outside, "must survive\n" );
    symlink( $outside, $sandbox . '/escapee' );

    agentshell_mutation_sandbox_destroy( $sandbox );

    Agentshell_Test::assert_false( is_dir( $sandbox ), 'the sandbox is gone after destroy' );
    Agentshell_Test::assert_true( is_file( $outside ), 'rmtree did not follow a symlink out of the sandbox' );

    unlink( $outside );
} );