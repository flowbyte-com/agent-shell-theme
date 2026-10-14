<?php
/**
 * Mutation test for the bounded content-write guard.
 *
 * The defect this guards against is a tool reporting a failed write while the
 * content is still in the database. Such a thing does not fail loudly — the
 * caller gets a clean error message, the tool result looks well-formed, and
 * the orphan only shows up if somebody happens to look at the tables. The only
 * honest way to show the tests would catch that is to take each mechanism away
 * and confirm they do.
 *
 * Each mutant removes exactly one mechanism from the boundary:
 *
 *   - the transaction is not opened, or a failure commits instead of rolling back
 *   - a failure result is not recognised, so it is treated as success
 *   - an exception is swallowed, or the throw path skips the rollback
 *   - a rolled-back post is left sitting in the object cache
 *   - a nested write opens a second transaction (which commits the outer one)
 *   - a non-transactional engine is assumed to be transactional
 *   - a refused START is reported as protection anyway
 *   - the primitives stop routing their writes through the guard
 *   - the record claims an atomicity it did not have
 *
 * Killed means the suite reported at least one failing assertion. It does not
 * mean the mutant crashed — a fatal parse error would be a harness bug, so
 * each mutant is linted before it is judged.
 *
 * Isolation
 * ---------
 * Mutants are written into a throwaway copy of the repository under the system
 * temp directory and the suite is run from that copy. Nothing here opens the
 * working tree for writing; the fingerprint at the end asserts that rather than
 * assuming it. See tests/mutation-sandbox.php.
 *
 * Usage:
 *   php tests/content-write-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

$SUITE_REL = 'tests/content-write-test.php';

$GUARD_REL = 'agentshell-mcp/includes/class-content-write-guard.php';
$CONTENT_REL = 'agentshell-mcp/includes/class-content.php';

$mutants = array(

    // ── the transaction itself ────────────────────────────────────────────

    'no transaction is opened at all' => array(
        $GUARD_REL,
        "        \$started = ( false !== @\$wpdb->query( 'START TRANSACTION' ) );",
        "        return \$write();\n        \$started = ( false !== @\$wpdb->query( 'START TRANSACTION' ) );",
    ),

    'a reported failure is committed instead of rolled back' => array(
        $GUARD_REL,
        "        if ( self::is_failure( \$result ) ) {\n            \$wpdb->query( 'ROLLBACK' );\n            self::discard_caches();\n            return \$result;\n        }",
        "        if ( self::is_failure( \$result ) ) {\n            \$wpdb->query( 'COMMIT' );\n            return \$result;\n        }",
    ),

    'a thrown failure skips the rollback' => array(
        $GUARD_REL,
        "        } catch ( \\Throwable \$e ) {\n            self::\$depth--;\n            \$wpdb->query( 'ROLLBACK' );",
        "        } catch ( \\Throwable \$e ) {\n            self::\$depth--;",
    ),

    'a thrown failure is swallowed' => array(
        $GUARD_REL,
        "            \$wpdb->query( 'ROLLBACK' );\n            self::discard_caches();\n            throw \$e;",
        "            \$wpdb->query( 'ROLLBACK' );\n            return null;",
    ),

    // ── recognising a failure ─────────────────────────────────────────────

    'a WP_Error is not a failure' => array(
        $GUARD_REL,
        "        if ( is_wp_error( \$result ) ) {\n            return true;\n        }",
        "        if ( false ) {\n            return true;\n        }",
    ),

    'a zero or negative id is not a failure' => array(
        $GUARD_REL,
        "        if ( is_numeric( \$result ) && (int) \$result < 1 ) {\n            return true;\n        }",
        "        if ( false ) {\n            return true;\n        }",
    ),

    'null is treated as success' => array(
        $GUARD_REL,
        "        if ( null === \$result || false === \$result ) {\n            return true;\n        }",
        "        if ( false === \$result ) {\n            return true;\n        }",
    ),

    'every result is treated as success' => array(
        $GUARD_REL,
        "        if ( is_numeric( \$result ) && (int) \$result < 1 ) {\n            return true;\n        }\n        return false;",
        "        return false;",
    ),

    'every result is treated as a failure' => array(
        $GUARD_REL,
        "        if ( is_wp_error( \$result ) ) {\n            return true;\n        }",
        "        if ( true ) {\n            return true;\n        }\n        if ( is_wp_error( \$result ) ) {\n            return true;\n        }",
    ),

    // ── the cache a rollback invalidates ──────────────────────────────────

    'a rolled-back post is left in the object cache' => array(
        $GUARD_REL,
        "        if ( self::is_failure( \$result ) ) {\n            \$wpdb->query( 'ROLLBACK' );\n            self::discard_caches();",
        "        if ( self::is_failure( \$result ) ) {\n            \$wpdb->query( 'ROLLBACK' );",
    ),

    'a thrown failure leaves the cache dirty' => array(
        $GUARD_REL,
        "            \$wpdb->query( 'ROLLBACK' );\n            self::discard_caches();\n            throw \$e;",
        "            \$wpdb->query( 'ROLLBACK' );\n            throw \$e;",
    ),

    'the cache is flushed even on a commit' => array(
        $GUARD_REL,
        "        \$wpdb->query( 'COMMIT' );\n        return \$result;",
        "        \$wpdb->query( 'COMMIT' );\n        self::discard_caches();\n        return \$result;",
    ),

    // ── nesting ───────────────────────────────────────────────────────────

    'a nested write opens its own transaction' => array(
        $GUARD_REL,
        "        if ( self::\$depth > 0 || '' === \$engine ) {",
        "        if ( '' === \$engine ) {",
    ),

    // ── the guarantee itself ──────────────────────────────────────────────

    'a non-transactional engine is accepted' => array(
        $GUARD_REL,
        "            if ( ! isset( \$row->Engine ) || 'INNODB' !== strtoupper( (string) \$row->Engine ) ) {",
        "            if ( false ) {",
    ),

    'an unanswerable engine lookup counts as InnoDB' => array(
        $GUARD_REL,
        "        if ( ! is_array( \$rows ) || count( \$rows ) !== count( \$tables ) ) {",
        "        if ( false ) {",
    ),

    'a refused START is reported as protection' => array(
        $GUARD_REL,
        "        \$held   = \$engine && self::\$last_was_transactional;",
        "        \$held   = '' !== \$engine;",
    ),

    'a refused START throws instead of writing unprotected' => array(
        $GUARD_REL,
        "        if ( ! \$started ) {",
        "        if ( false ) {",
    ),

    // ── the tools ─────────────────────────────────────────────────────────

    'create() stops routing through the guard' => array(
        $CONTENT_REL,
        "        \$record = Content_Write_Guard::run( function () use ( \$postarr, \$type ) {",
        "        \$record = ( function () use ( \$postarr, \$type ) {",
    ),

    'update() stops routing through the guard' => array(
        $CONTENT_REL,
        "        \$record = Content_Write_Guard::run( function () use ( \$postarr, \$type, \$id ) {",
        "        \$record = ( function () use ( \$postarr, \$type, \$id ) {",
    ),

    'an empty title and content are written as an untitled draft' => array(
        $CONTENT_REL,
        "        if ( '' === trim( (string) \$title ) && '' === trim( (string) \$content ) ) {",
        "        if ( false ) {",
    ),

    'the record claims an atomicity nobody measured' => array(
        $CONTENT_REL,
        "        \$record['atomicity'] = Content_Write_Guard::report();\n        return \$record;\n    }\n\n    /**\n     * Update a post or page",
        "        \$record['atomicity'] = array( 'database_transaction' => true, 'engine' => 'InnoDB', 'on_failure' => 'rolled back', 'not_covered' => 'nothing' );\n        return \$record;\n    }\n\n    /**\n     * Update a post or page",
    ),

    'a successful update claims no atomicity at all' => array(
        $CONTENT_REL,
        "        \$record['atomicity'] = Content_Write_Guard::report();\n        return \$record;\n    }\n\n    /**\n     * Set a post's status",
        "        return \$record;\n    }\n\n    /**\n     * Set a post's status",
    ),
);

$killed   = 0;
$survived = array();

$repo_before = agentshell_repo_snapshot();
$sandbox     = agentshell_mutation_sandbox_create();

$sandbox_suite = $sandbox . '/' . $SUITE_REL;
if ( ! is_file( $sandbox_suite ) ) {
    echo "SANDBOX FAILED — {$SUITE_REL} is missing from the copy.\n";
    echo "Nothing was written to the checkout.\n";
    exit( 1 );
}

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_mutation_run_suite( $sandbox_suite );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    agentshell_mutation_sandbox_destroy( $sandbox );
    exit( 1 );
}
if ( ! preg_match( '/(\d+) passed/', $baseline['output'], $m ) ) {
    echo "NOTE — the suite prints no pass count standalone (it is a *-test.php;\n";
    echo "       run-all.php prints the tally), so only the FAIL count is used.\n\n";
} else {
    echo "baseline: {$m[1]} passed, 0 failed\n\n";
}

foreach ( $mutants as $label => $mutation ) {
    list( $rel, $find, $replace ) = $mutation;

    $sandbox_file = $sandbox . '/' . $rel;
    if ( ! is_file( $sandbox_file ) ) {
        echo "SKIP   $label — {$rel} is missing from the copy\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    $original = file_get_contents( $sandbox_file );

    if ( false === strpos( $original, $find ) ) {
        // A mutant that cannot be applied is not evidence about the suite.
        echo "SKIP   $label — the target text is no longer in the source\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    $mutated = str_replace( $find, $replace, $original, $count );
    if ( $count < 1 ) {
        echo "SKIP   $label — no replacement made\n";
        $survived[] = $label . ' (not applicable)';
        continue;
    }

    $lint = agentshell_mutation_lint( $mutated );
    if ( 0 !== $lint['rc'] ) {
        echo "ERROR  $label — mutant does not parse, so it proves nothing:\n";
        echo '       ' . $lint['output'] . "\n";
        $survived[] = $label . ' (does not parse)';
        continue;
    }

    // The only write in the whole harness, and it is inside the sandbox.
    file_put_contents( $sandbox_file, $mutated );

    $result = agentshell_mutation_run_suite( $sandbox_suite );

    // Put the pristine copy back so the next mutant starts from the original.
    file_put_contents( $sandbox_file, $original );

    if ( $result['failed'] > 0 ) {
        $killed++;
        echo "killed $label ({$result['failed']} failing assertions)\n";
    } else {
        echo "SURVIVED $label — the suite did not notice\n";
        $survived[] = $label;
    }
}

agentshell_mutation_sandbox_destroy( $sandbox );

$total = count( $mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $survived ) {
    echo "Survivors:\n";
    foreach ( $survived as $label ) {
        echo "  - $label\n";
    }
}

// The whole point of the sandbox: prove it, do not assume it.
$diff = agentshell_repo_diff( $repo_before );
if ( $diff['changed'] || $diff['added'] || $diff['removed'] ) {
    echo "\nREPO INTEGRITY FAILURE — the harness modified the checkout:\n";
    foreach ( array( 'changed', 'added', 'removed' ) as $kind ) {
        foreach ( $diff[ $kind ] as $path ) {
            echo "  $kind: $path\n";
        }
    }
    exit( 1 );
}
echo "checkout untouched\n";

exit( $survived ? 1 : 0 );