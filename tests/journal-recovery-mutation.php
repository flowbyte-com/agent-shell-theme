<?php
/**
 * Mutation test for the content journal and its recovery engine.
 *
 * Why this needs its own harness
 * ------------------------------
 * A recovery bug is the worst kind to ship, because it is silent by
 * construction. Every mechanism in this code exists to stop something
 * irreversible happening quietly: a guard that stops deleting the wrong post,
 * a claim that stops two callers doing it twice, a signature that stops a
 * hand-edited journal from being believed. Remove any one of them and the code
 * still runs, still returns a well-formed report, and still looks like it
 * worked. Nothing crashes. Nothing logs an error. The post is simply gone.
 *
 * A test suite cannot detect that by passing. It can only be shown to detect
 * it, and the way to show it is to take each mechanism away and confirm the
 * suite notices.
 *
 * Each mutant removes exactly one safeguard:
 *
 *   - authority: the capability check, the explicit opt-in, or the expiry check
 *   - the crash window: post_id 0 read as "nothing was created"
 *   - evidence: the marker lookup, the ambiguity detector, the divergence checks
 *   - restraint: force delete weakened to trash, or a refusal turned into a no-op
 *   - concurrency: the claim, or the CAS behind it
 *   - the record: the signature, the validation, the audit trail
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
 *   php tests/journal-recovery-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

$SUITE_REL = 'tests/journal-recovery-test.php';

$RECOVERY_REL = 'agentshell-mcp/includes/class-content-recovery.php';
$JOURNAL_REL  = 'agentshell-mcp/includes/class-content-journal.php';
$TX_REL       = 'agentshell-mcp/includes/class-transactions.php';
$TOOL_REL     = 'agentshell-mcp/includes/tools/class-recover-transaction.php';

$mutants = array(

    // ── authority ─────────────────────────────────────────────────────────

    'the capability check is dropped' => array(
        $RECOVERY_REL,
        "        if ( \$explicitly_authorized\n            && function_exists( 'current_user_can' )\n            && current_user_can( 'manage_options' ) ) {",
        "        if ( \$explicitly_authorized ) {",
    ),

    'the explicit opt-in is dropped' => array(
        $RECOVERY_REL,
        "        if ( \$explicitly_authorized\n            && function_exists( 'current_user_can' )",
        "        if ( function_exists( 'current_user_can' )",
    ),

    'any actor may resolve any transaction' => array(
        $RECOVERY_REL,
        "        \$owner = isset( \$tx['actor'] ) ? (string) \$tx['actor'] : '';\n        if ( '' !== \$owner && \$owner === (string) \$actor ) {\n            return 'owner';\n        }",
        "        return 'owner';",
    ),

    'a live transaction is recovered anyway' => array(
        $RECOVERY_REL,
        "        if ( ! Transaction_Manager::is_expired( \$tx ) ) {",
        "        if ( false ) {",
    ),

    // ── the crash window ──────────────────────────────────────────────────

    'an unrecorded post id is read as nothing having been created' => array(
        $RECOVERY_REL,
        "            \$found = self::posts_carrying_marker( \$marker );",
        "            \$found = array();",
    ),

    'the marker lookup returns the first post rather than all of them' => array(
        $RECOVERY_REL,
        "        return array_map( 'intval', (array) \$ids );\n    }\n\n    /**\n     * Post ids of the recorded type and slug, regardless of status.",
        "        \$ids = (array) \$ids;\n        return \$ids ? array( (int) reset( \$ids ) ) : array();\n    }\n\n    /**\n     * Post ids of the recorded type and slug, regardless of status.",
    ),

    'an unmarked lookalike is treated as no evidence at all' => array(
        $RECOVERY_REL,
        "                \$lookalikes = self::posts_matching_target( \$op );",
        "                \$lookalikes = array();",
    ),

    // ── evidence ──────────────────────────────────────────────────────────

    'the marker on the post is not checked' => array(
        $RECOVERY_REL,
        "        if ( (string) \$found_marker !== (string) \$marker ) {",
        "        if ( false ) {",
    ),

    'a diverged post_type, status or slug is ignored' => array(
        $RECOVERY_REL,
        "        foreach ( array( 'post_type', 'post_status', 'post_name' ) as \$field ) {",
        "        foreach ( array() as \$field ) {",
    ),

    'the modification-time guard is dropped' => array(
        $RECOVERY_REL,
        "        if ( '' !== \$recorded_modified ) {\n            \$have = (string) ( \$post->post_modified_gmt ?? '' );\n            if ( \$have !== \$recorded_modified ) {",
        "        if ( false ) {\n            \$have = (string) ( \$post->post_modified_gmt ?? '' );\n            if ( \$have !== \$recorded_modified ) {",
    ),

    // ── restraint ─────────────────────────────────────────────────────────

    'the draft is trashed rather than deleted' => array(
        $RECOVERY_REL,
        "                return wp_delete_post( \$post_id, true );",
        "                return wp_delete_post( \$post_id );",
    ),

    'the delete is not guarded by a transaction' => array(
        $RECOVERY_REL,
        "        \$deleted = Content_Write_Guard::run(\n            function () use ( \$post_id ) {\n                return wp_delete_post( \$post_id, true );\n            }\n        );",
        "        \$deleted = wp_delete_post( \$post_id, true );",
    ),

    'a failed delete is reported as success' => array(
        $RECOVERY_REL,
        "        if ( Content_Write_Guard::is_failure( \$deleted ) ) {",
        "        if ( false ) {",
    ),

    'an unsupported operation type is compensated anyway' => array(
        $RECOVERY_REL,
        "        if ( ! in_array( \$type, Content_Journal::SUPPORTED_OPERATIONS, true ) ) {\n            return array(\n                'outcome' => 'conflict',",
        "        if ( false ) {\n            return array(\n                'outcome' => 'conflict',",
    ),

    'a conflict is treated as a successful compensation' => array(
        $RECOVERY_REL,
        "        \$mismatch = self::verify_post( \$op, \$post, \$marker );\n        if ( '' !== \$mismatch ) {",
        "        \$mismatch = '';\n        if ( '' !== \$mismatch ) {",
    ),

    // ── concurrency ───────────────────────────────────────────────────────

    'the claim is not taken' => array(
        $RECOVERY_REL,
        "        if ( ! Transaction_Manager::cas_write_row( \$tx, \$claimed ) ) {",
        "        if ( false ) {",
    ),

    'a live claim from another caller is ignored' => array(
        $RECOVERY_REL,
        "        if ( 'compensating' === \$op['state'] && ! \$mine && ! \$stale ) {",
        "        if ( false ) {",
    ),

    'a crashed claim never ages out' => array(
        $RECOVERY_REL,
        "        \$stale  = empty( \$claim['at'] ) || ( \$now - (int) \$claim['at'] ) >= self::CLAIM_TTL_SECONDS;",
        "        \$stale  = false;",
    ),

    'a losing race is treated as success' => array(
        $RECOVERY_REL,
        "        if ( ! Transaction_Manager::cas_write_row( \$claimed, \$done ) ) {",
        "        if ( false ) {",
    ),

    // ── the record ────────────────────────────────────────────────────────

    'a tampered journal is trusted' => array(
        $JOURNAL_REL,
        "        if ( ! hash_equals( \$expected, \$stored ) ) {",
        "        if ( false ) {",
    ),

    'an unsupported operation type passes validation' => array(
        $JOURNAL_REL,
        "            if ( ! isset( \$op['op'] ) || ! in_array( \$op['op'], self::SUPPORTED_OPERATIONS, true ) ) {",
        "            if ( false ) {",
    ),

    'a missing identifying field passes validation' => array(
        $JOURNAL_REL,
        "                if ( ! isset( \$op['after'][ \$needed ] ) || '' === \$op['after'][ \$needed ] ) {\n                    \$problems[] = \$label . ' has no after.' . \$needed . ' to identify its post with';\n                }",
        "                if ( false ) {\n                    \$problems[] = \$label . ' has no after.' . \$needed . ' to identify its post with';\n                }",
    ),

    'a journal whose own signature is missing is reported as intact' => array(
        $JOURNAL_REL,
        "                'status' => 'unverified',",
        "                'status' => 'ok',",
    ),

    // ── what the row is allowed to do afterwards ───────────────────────────

    'a conflicted journal counts as nothing owed' => array(
        $JOURNAL_REL,
        "    const SETTLED_STATES = array( self::STATE_COMPENSATED );",
        "    const SETTLED_STATES = array( self::STATE_COMPENSATED, self::STATE_CONFLICT );",
    ),

    'a transaction holding an unreadable journal counts as reclaimable' => array(
        $JOURNAL_REL,
        "        if ( ! is_array( \$content ) || ! isset( \$content[ self::OPS_KEY ] ) || ! is_array( \$content[ self::OPS_KEY ] ) ) {\n            return true;",
        "        if ( ! is_array( \$content ) || ! isset( \$content[ self::OPS_KEY ] ) || ! is_array( \$content[ self::OPS_KEY ] ) ) {\n            return false;",
    ),

    'the transaction row is deleted while work is still owed' => array(
        $RECOVERY_REL,
        "        if ( Content_Journal::has_outstanding_work( \$final ) ) {",
        "        if ( false ) {",
    ),

    'the row is deleted without a compare-and-swap' => array(
        $RECOVERY_REL,
        "        \$report['transaction_row_deleted'] = Transaction_Manager::cas_delete_row( \$working );",
        "        delete_option( Transaction_Manager::KEY );\n        \$report['transaction_row_deleted'] = true;",
    ),

    // ── the tools ─────────────────────────────────────────────────────────

    'the recover tool acts without confirmation' => array(
        $TOOL_REL,
        "        if ( empty( \$arguments['confirm'] ) ) {",
        "        if ( false ) {",
    ),

    'the confirm requirement is only described, not required' => array(
        $TOOL_REL,
        "            'required'   => array( 'confirm' ),",
        "            'required'   => array(),",
    ),

    // ── the audit trail ───────────────────────────────────────────────────

    'a refusal is not audited' => array(
        $RECOVERY_REL,
        "        \$operation = ( 'refused' === \$report['status'] ) ? 'transaction_recovery_refused' : 'transaction_recovery';",
        "        \$operation = 'transaction_recovery';",
    ),

    'the audit record does not name the role that acted' => array(
        $RECOVERY_REL,
        "                'authorized_as'           => \$report['authorized_as'],",
        "                'authorized_as'           => '',",
    ),
);

$killed   = 0;
$survived = array();

/**
 * Mutants that cannot be killed because a second gate already covers them.
 *
 * These are not gaps and they are not kills either, and calling them either
 * would be a lie. Each entry says which gate takes over, so the redundancy is
 * a stated fact rather than an unexplained zero.
 */
$equivalent = array(
    'an unsupported operation type is compensated anyway' =>
        'compensate() re-checks the operation type, but recover() refuses the whole journal for the same '
        . 'reason before compensate() is reachable, so removing the inner check changes no observable '
        . 'outcome. Defence in depth: only the outer gate has a test, and this mutant is the record of '
        . 'that being true rather than assumed.',
);

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
    } elseif ( isset( $equivalent[ $label ] ) ) {
        echo "equivalent $label\n";
    } else {
        echo "SURVIVED $label — the suite did not notice\n";
        $survived[] = $label;
    }
}

agentshell_mutation_sandbox_destroy( $sandbox );

$total = count( $mutants );
echo "\n=== MUTATION ===\n";
echo "$killed of $total killed\n";

if ( $equivalent ) {
    echo "\nEquivalent — a second gate already covers these, so no kill is possible:\n";
    foreach ( $equivalent as $label => $why ) {
        echo "  - $label\n    $why\n";
    }
}

if ( $survived ) {
    echo "\nSurvivors:\n";
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