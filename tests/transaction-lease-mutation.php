<?php
/**
 * Mutation test for the transaction lease and stale recovery.
 *
 * Why this needs its own harness
 * ------------------------------
 * The lease's entire claim is about behaviour that only exists under
 * contention and over time: that an expired transaction stops mattering, that
 * a transaction with unrecovered work is never discarded, that recovery is
 * recorded, and that none of it is done by a read-then-delete. Every one of
 * those is easy to assert and easy to assert *vacuously* — delete the expiry
 * check and a suite that never advances the clock still passes; replace the
 * guarded swap with a plain delete and a suite that never introduces a second
 * writer still passes. So each mechanism is removed in turn here, and the suite
 * has to notice.
 *
 * Killed means "an assertion failed", not "the mutation produced an error". A
 * parse error would satisfy a naive check and prove nothing, so each mutant is
 * linted before it counts, and reported separately if it does not parse.
 *
 * Isolation
 * ---------
 * Mutants are written into a throwaway copy of the repository under the system
 * temp directory and the suite runs from that copy. Nothing here opens the
 * working tree for writing.
 *
 * Note on the kill condition: standalone `*-test.php` files do not set an exit
 * status — run-all.php does — so the verdict keys on FAIL lines in the output.
 * Those lines are indented by the reporter, so the match is anchored at line
 * start.
 *
 * Usage:
 *   php tests/transaction-lease-mutation.php
 */

require_once __DIR__ . '/mutation-sandbox.php';

$TARGET_REL = 'agentshell-mcp/includes/class-transactions.php';
$SUITE_REL  = 'tests/transaction-lease-test.php';

$original = file_get_contents( agentshell_repo_root() . '/' . $TARGET_REL );

/**
 * Mutants, each the minimal edit that removes one guarantee.
 */
$mutants = array(
    // ── expiry stops meaning anything ────────────────────────────────────
    'a transaction never expires' => array(
        "        return is_array( \$tx ) && self::now() >= self::expires_at( \$tx );",
        "        return false;",
    ),
    'is_active ignores expiry' => array(
        "        return is_array( \$tx ) && ! self::is_expired( \$tx );",
        "        return is_array( \$tx );",
    ),
    'a row without a lease field is treated as live forever' => array(
        "        return self::last_activity_at( \$tx ) + self::LEASE_TTL_SECONDS;",
        "        return PHP_INT_MAX;",
    ),
    'a row without a lease field is granted a fresh hour' => array(
        "        return self::last_activity_at( \$tx ) + self::LEASE_TTL_SECONDS;",
        "        return self::now() + self::LEASE_TTL_SECONDS;",
    ),
    'seconds_remaining can go negative' => array(
        "        return max( 0, self::expires_at( \$tx ) - self::now() );",
        "        return self::expires_at( \$tx ) - self::now();",
    ),

    // ── unrecovered work ─────────────────────────────────────────────────
    'has_durable_work is always false' => array(
        "    public static function has_durable_work( \$tx ) {\n        if ( ! is_array( \$tx ) || empty( \$tx['content'] ) || ! is_array( \$tx['content'] ) ) {\n            return false;\n        }\n        foreach ( \$tx['content'] as \$bucket ) {\n            if ( self::journal_has_record( \$bucket ) ) { return true; }\n        }\n        return false;\n    }",
        "    public static function has_durable_work( \$tx ) {\n        return false;\n    }",
    ),
    'reclaim ignores durable work' => array(
        "            if ( self::has_durable_work( \$tx ) ) {\n                \$this->orphaned = \$tx;\n                return null;\n            }\n",
        "",
    ),
    'the journal check stops at one level' => array(
        "            if ( self::journal_has_record( \$bucket ) ) { return true; }",
        "            if ( ! empty( \$bucket ) ) { return true; }",
    ),
    'begin proceeds past an unrecovered transaction' => array(
        "        if ( is_array( \$this->orphaned ) ) {\n            throw new \\InvalidArgumentException(\n                'Transaction ' . \$this->orphaned['id'] . ' expired but still holds unrecovered work, so it was not '\n                . 'discarded and cannot be replaced. Recover or commit it before opening another.'\n            );\n        }\n",
        "",
    ),

    // ── atomicity ────────────────────────────────────────────────────────
    'the delete guard is dropped' => array(
        "\"DELETE FROM {\$wpdb->options} WHERE option_name = %s AND option_value = %s\",",
        "\"DELETE FROM {\$wpdb->options} WHERE option_name = %s\",",
    ),
    'the update guard is dropped' => array(
        "\"UPDATE {\$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s\",",
        "\"UPDATE {\$wpdb->options} SET option_value = %s WHERE option_name = %s\",",
    ),
    'a losing swap reports success' => array(
        "        self::flush_option_cache();\n        return 1 === (int) \$affected;\n    }\n\n    /**\n     * Delete the transaction row only if it still holds \$expected. See cas().",
        "        self::flush_option_cache();\n        return (int) \$affected >= 0;\n    }\n\n    /**\n     * Delete the transaction row only if it still holds \$expected. See cas().",
    ),
    'a losing renewal reports success' => array(
        "        if ( ! self::cas( \$tx, \$next ) ) {\n            return false;\n        }\n        return true;",
        "        self::cas( \$tx, \$next );\n        return true;",
    ),
    'the reclaim loop does not re-read' => array(
        "            if ( \$attempt > 0 ) {\n                \$tx           = get_option( self::KEY, null );\n            }",
        "",
    ),
    'the reclaim loop retries for ever' => array(
        "        for ( \$attempt = 0; \$attempt < 3; \$attempt++ ) {",
        "        for ( \$attempt = 0; \$attempt < 1000; \$attempt++ ) {",
    ),
    'begin upserts instead of inserting' => array(
        "        if ( ! add_option( self::KEY, \$tx, '', false ) ) {",
        "        if ( ! update_option( self::KEY, \$tx, false ) ) {",
    ),

    // ── activity and renewal ─────────────────────────────────────────────
    'staging does not renew the lease' => array(
        "        \$next           = \$live;\n        \$next['staged'] = \$config;\n        \$next           = self::stamp_lease( \$next, self::now() );",
        "        \$next           = \$live;\n        \$next['staged'] = \$config;",
    ),
    're-beginning does not renew the lease' => array(
        "            \$this->renew( \$existing );\n            return \$this->payload();",
        "            return \$existing;",
    ),
    'renewal rewrites the creation time' => array(
        "        \$tx['created']       = isset( \$tx['created'] ) ? (int) \$tx['created'] : (int) ( \$tx['timestamp'] ?? \$now );",
        "        \$tx['created']       = \$now;",
    ),

    // ── reporting and isolation ──────────────────────────────────────────
    'recovery is not audited' => array(
        "                    'transaction_reclaimed',",
        "                    'transaction_discarded',",
    ),
    'a read-only caller can reclaim' => array(
        "        if ( ! \$may_write ) {\n            \$this->expired_seen = \$tx;\n            return null;\n        }",
        "        if ( ! \$may_write ) {\n            \$this->expired_seen = \$tx;\n        }",
    ),
    'commit does not apply the lease' => array(
        "        \$tx = \$this->require_live( true );\n        \$this->assert_owner( \$tx );\n        if ( ! is_array( \$tx['staged'] ) ) {",
        "        \$tx = \$this->settle( true );\n        \$this->assert_owner( \$tx );\n        if ( ! is_array( \$tx['staged'] ) ) {",
    ),
    'preview does not apply the lease' => array(
        "        \$tx = \$this->require_live( false );\n        return Store::diff_config( \$tx['before'], \$tx['staged'] ?: \$tx['before'] );",
        "        \$tx = \$this->settle( false );\n        return \$tx ? Store::diff_config( \$tx['before'], \$tx['staged'] ?: \$tx['before'] ) : array();",
    ),
    'an expired transaction still authorises a preview' => array(
        "        if ( self::is_expired( \$tx ) ) {\n            return false;\n        }\n        \$secret = self::preview_secret();\n        if ( '' === \$secret || ! is_string( \$key )",
        "        \$secret = self::preview_secret();\n        if ( '' === \$secret || ! is_string( \$key )",
    ),
    'a credential can still be minted for an expired transaction' => array(
        "        if ( self::is_expired( \$tx ) ) {\n            throw new \\InvalidArgumentException( self::expired_message( \$tx ) );\n        }\n        \$secret = self::preview_secret();",
        "        \$secret = self::preview_secret();",
    ),
    'the test clock can reach the credential window' => array(
        "        \$expires = time() + self::PREVIEW_TTL_SECONDS;",
        "        \$expires = self::now() + self::PREVIEW_TTL_SECONDS;",
    ),
    'an expired transaction still shapes reads' => array(
        "        if ( is_array( \$tx ) && ! self::is_expired( \$tx ) && is_array( \$tx['staged'] ?? null ) ) {",
        "        if ( is_array( \$tx ) && is_array( \$tx['staged'] ?? null ) ) {",
    ),
    'the expiry refusal does not name the expiry' => array(
        "        if ( is_array( \$this->expired_seen ) ) {\n            throw new \\InvalidArgumentException( self::expired_message( \$this->expired_seen ) );\n        }\n",
        "",
    ),
    'transaction ids repeat within the same second' => array(
        "                'id'        => 'tx_' . self::now() . '_' . bin2hex( random_bytes( 4 ) ),",
        "                'id'        => 'tx_' . self::now(),",
    ),
);

$killed   = 0;
$survived = array();

$repo_before = agentshell_repo_snapshot();
$sandbox     = agentshell_mutation_sandbox_create();

$sandbox_target = $sandbox . '/' . $TARGET_REL;
if ( ! is_file( $sandbox_target ) ) {
    echo "SANDBOX FAILED — {$TARGET_REL} is missing from the copy.\n";
    echo "Nothing was written to the checkout.\n";
    exit( 1 );
}

// Sanity: the unmutated suite must be green, or "killed" means nothing.
$baseline = agentshell_mutation_run_suite( $sandbox . '/' . $SUITE_REL );
if ( $baseline['failed'] > 0 ) {
    echo "BASELINE FAILED — the suite is red before any mutation, so kill counts mean nothing.\n";
    echo $baseline['output'];
    agentshell_mutation_sandbox_destroy( $sandbox );
    exit( 1 );
}
echo "baseline: 0 failures\n\n";

foreach ( $mutants as $label => $mutation ) {
    list( $find, $replace ) = $mutation;

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
    file_put_contents( $sandbox_target, $mutated );
    $result = agentshell_mutation_run_suite( $sandbox . '/' . $SUITE_REL );
    file_put_contents( $sandbox_target, $original );

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
$diff = agentshell_repo_diff( $repo_before, array( agentshell_repo_root() . '/' . $TARGET_REL ) );
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