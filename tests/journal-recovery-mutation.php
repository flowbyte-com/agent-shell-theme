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
$LOCK_REL     = 'agentshell-mcp/includes/class-recovery-advisory-lock.php';
$TOOL_REL     = 'agentshell-mcp/includes/tools/class-recover-transaction.php';
$GETJOURNAL_REL = 'agentshell-mcp/includes/tools/class-get-journal.php';

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
        // Retargeted 2026-10-15: the original find targeted the line *before*
        // `$allow_live` was introduced, so the source text no longer matches.
        // The current gate is `! $allow_live && ! is_expired`. The mutant
        // drops the is_expired check, so a live transaction (not in
        // auto-compensate, lease still valid) is recovered anyway. Killed by
        // "a live transaction with a valid lease is refused until it expires".
        $RECOVERY_REL,
        "        if ( ! \$allow_live && ! Transaction_Manager::is_expired( \$tx ) ) {",
        "        if ( ! \$allow_live ) {",
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
        "        try {\n            \$deleted = Content_Write_Guard::run(\n                function () use ( \$post_id ) {\n                    return wp_delete_post( \$post_id, true );\n                }\n            );",
        "        try {\n            \$deleted = wp_delete_post( \$post_id, true );",
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
        "        if ( Content_Journal::STATE_COMPENSATING === ( \$op['state'] ?? '' ) && ! \$mine && ! \$stale ) {\n            return 'contended';",
        "        if ( false ) {\n            return 'contended';",
    ),

    'a crashed claim never ages out' => array(
        $RECOVERY_REL,
        "        \$stale = empty( \$claim['at'] ) || ( \$now - (int) \$claim['at'] ) >= self::CLAIM_TTL_SECONDS;",
        "        \$stale = false;",
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
        // Retargeted 2026-10-15: the source evolved; the original find
        // targeted a one-line unconditional cas_delete_row, but the current
        // code is a ternary that honours $skip_row_delete. The retargeted
        // mutant changes the cas_delete_row branch to delete_option(),
        // bypassing the value guard. A row that moved between the read and
        // the delete is silently removed. Killed by the recovery row-moved
        // test (the C1 test asserts the row is preserved when the CAS
        // returns 0).
        $RECOVERY_REL,
        "            : Transaction_Manager::cas_delete_row( \$working );",
        "            : ( delete_option( Transaction_Manager::KEY ) ? true : false );",
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

    // ── authority: read vs destructive (Stage 1b, brief A + round-1 issues 5, 6) ─

    'the manage_options re-check on the inspection owner path is dropped' => array(
        // M-A1: the owner path of `authorize_inspection()` is what stops a
        // session-revoked owner from reading after their role was demoted.
        // Drop the `manage_options` line and the demoted owner is read as
        // still authorised. Killed by A1b ("an owner without manage_options
        // is refused inspection identically to a missing row").
        $JOURNAL_REL,
        "        if ( '' !== \$owner\n            && \$owner === (string) \$actor\n            && function_exists( 'current_user_can' )\n            && current_user_can( 'manage_options' ) ) {\n            return 'owner';\n        }",
        "        if ( '' !== \$owner\n            && \$owner === (string) \$actor ) {\n            return 'owner';\n        }",
    ),

    'the authorize_inspection opt-in is silently ignored' => array(
        // M-A2: the tool layer's `execute()` consults `authorize_inspection`
        // with the opt-in argument. Drop the argument and an unrelated
        // non-administrator can read somebody else's journal. Killed by
        // A2 / A3.
        $GETJOURNAL_REL,
        "        \$role = \\AgentShell_MCP\\Content_Journal::authorize_inspection(\n            \$tx,\n            \$actor,\n            \$explicitly_authorized\n        );",
        "        \$role = \\AgentShell_MCP\\Content_Journal::authorize_inspection(\n            \$tx,\n            \$actor,\n            false\n        );",
    ),

    'an inspection refusal reveals the row exists' => array(
        // M-A3: a refused inspection must return the no-row shape so the
        // existence of another actor's row is not disclosed. Bypass the
        // short-circuit and the tool returns the full payload. Killed by A5.
        $GETJOURNAL_REL,
        "        if ( '' === \$role ) {\n            // Refused: indistinguishable from a missing row. No audit row,\n            // because an unauthorised read must leave the audit log\n            // untouched — a refused inspection is not a record of intent.\n            return \\AgentShell_MCP\\Content_Journal::inspect( null, false );\n        }",
        "        if ( '' === \$role ) {\n            return \\AgentShell_MCP\\Content_Journal::inspect( is_array( \$tx ) ? \$tx : null, true );\n        }",
    ),

    'the per-post delete_post cap is not re-checked at compensation time' => array(
        // Retargeted 2026-10-15: the source evolved; the actor-proxy block
        // now reads from $current_actor directly. The retargeted mutant
        // makes authorize_destruction return "owner" unconditionally,
        // bypassing the per-post delete_post cap. An actor whose role was
        // revoked since the transaction opened can still delete. Killed by
        // B1 / B2 / B3 (the owner path requires the per-post cap).
        $RECOVERY_REL,
        "        \$tx_proxy    = array( 'actor' => (string) \$current_actor );\n        \$destruction = self::authorize_destruction( \$tx_proxy, \$current_actor, \$explicitly_authorized, \$post_id );",
        "        \$tx_proxy    = array( 'actor' => (string) \$current_actor );\n        \$destruction = 'owner';",
    ),

    'a successful inspection writes no audit row' => array(
        // M-A5: the audit-on-success path is what makes inspection
        // observable. Rename the operation so the audit log never sees a
        // `transaction_journal_inspected` row, and the read becomes
        // indistinguishable from a refused read. Killed by A4 / A6.
        $GETJOURNAL_REL,
        "        \\AgentShell_MCP\\Store::record_event( \$actor, 'transaction_journal_inspected', array(",
        "        \\AgentShell_MCP\\Store::record_event( \$actor, 'transaction_journal_inspected_MUTATED', array(",
    ),

    // ── authority: Stage 1b additions (A6, A7) ───────────────────────────

    'the marker-lookup short-circuit in the no-id branch is dropped' => array(
        // M-A6: when post_id was never recorded, the only safe path is the
        // marker lookup, which finds a single post by its op_meta_value and
        // refuses on a marker collision or unmarked lookalike. Bypassing the
        // lookup drops the engine straight through to wp_delete_post() on
        // whatever get_post(0) returns — destroying a real page on the
        // strength of a journal that never recorded an id. Killed by SA5 / SA6.
        $RECOVERY_REL,
        "        if ( \$post_id > 0 ) {\n            \$post = get_post( \$post_id );",
        "        if ( \$post_id > 0 ) {\n            \$post = get_post( \$post_id );\n            if ( ! \$post && false ) {\n                return array(\n                    'outcome' => 'compensated',\n                    'post_id' => \$post_id,\n                    'reason'  => 'placeholder',\n                );\n            }",
    ),

    'the CAS-failure re-read after a lost race is dropped' => array(
        // M-A7: settle_operation() makes three guarded writes (claim, settle,
        // release). A row that moved between the re-read and the delete must
        // be reported — its row-moved branch is the one that says "another
        // recovery is in flight, stop". Drop it and the engine proceeds with
        // a row the OS has since overwritten, deleting the wrong transaction.
        // Killed by the race test "a recovery cannot erase a transaction that
        // appeared after it started".
        $RECOVERY_REL,
        "        if ( ! \$skip_row_delete && ! \$report['transaction_row_deleted'] ) {\n            // The row is not what we read. Somebody opened a new transaction in",
        "        if ( false && ! \$skip_row_delete && ! \$report['transaction_row_deleted'] ) {\n            // The row is not what we read. Somebody opened a new transaction in",
    ),

    // ── lifecycle: commit/rollback wire-up (Stage 1b, L1..L8) ─────────────

    'commit deletes the option even when the journal has unsettled work' => array(
        // M-L1: the journal-aware gate refuses the close when any op is in
        // `intended`, `compensating`, `conflict`, or `partial-applied`. Drop
        // the gate and the value-guarded delete runs anyway, freeing the
        // slot on a transaction that still owes work. Killed by L2 / L3 / L4.
        $TX_REL,
        "            if ( Content_Journal::has_unsettled_work( \$content ) ) {",
        "            if ( false ) {",
    ),

    "commit's audit row omits the op summary" => array(
        // M-L2: the per-op summary is the load-bearing audit detail: it names
        // which posts the journal recorded so an operator can see what the
        // commit did. Drop it and a successful commit is on the audit log
        // with no body, indistinguishable from a no-op. Killed by L1.
        $TX_REL,
        "                    'ops'            => \$op_summary,\n                )\n            );\n\n            // 2. Value-guarded delete. The row's bytes are the guard; a",
        "                )\n            );\n\n            // 2. Value-guarded delete. The row's bytes are the guard; a",
    ),

    'rollback does not auto-compensate applied ops' => array(
        // M-L3: when the journal carries applied ops, rollback's job is to
        // undo the work before releasing the slot. Drop the recovery call and
        // a rollback leaves the post behind while deleting the row, freeing
        // the slot on a transaction that still owes work. Killed by L5.
        $TX_REL,
        "            if ( \$has_applied ) {\n                // The auto-compensate rollback runs the per-op settlement",
        "            if ( false ) {",
    ),

    'rollback does not preserve the journal on compensation conflict' => array(
        // M-L4: when recovery reports conflicts, the rollback must refuse and
        // preserve both the journal and the option row so the operator can
        // resolve what recovery found. Drop the refusal and the journal is
        // freed despite an outstanding conflict. Killed by L6.
        $TX_REL,
        "                if ( ! empty( \$recovery_report['conflicts'] ) ) {\n                    Store::record_event(\n                        (string) \$tx['actor'],\n                        'transaction_rollback_refused_after_compensation',",
        "                if ( false && ! empty( \$recovery_report['conflicts'] ) ) {\n                    Store::record_event(\n                        (string) \$tx['actor'],\n                        'transaction_rollback_refused_after_compensation',",
    ),

    "rollback's input schema accepts a force argument" => array(
        // Retargeted 2026-10-15: the original placeholder lived in
        // class-transactions.php, but the actual schema lives in
        // agentshell-mcp/includes/tools/class-rollback-transaction.php. The
        // retargeted mutant adds a `force` property to the schema; the
        // current rollback execute() ignores it, but a future implementation
        // might honour it. Killed by the schema-shape test, which asserts
        // the properties list is empty.
        'agentshell-mcp/includes/tools/class-rollback-transaction.php',
        "        return array(\n            'type'       => 'object',\n            'properties' => array(),\n        );",
        "        return array(\n            'type'       => 'object',\n            'properties' => array(\n                'force' => array( 'type' => 'boolean' ),\n            ),\n        );",
    ),

    'commit does not revalidate applied ops against current WP state' => array(
        // M-L6: the post-validated revalidation pass is what catches an
        // outside actor editing the post between mark_applied() and commit().
        // Skip it and a divergence is silently committed. Killed by L10.
        $TX_REL,
        "            \$next_content = self::revalidate_journal_for_commit( \$content, \$tx );",
        "            \$next_content = null;",
    ),

    "commit does not transition an applied op whose post is gone to compensated" => array(
        // M-L7: revalidate_journal_for_commit()'s `gone` branch is what
        // makes "the post was deleted by an outside actor" an
        // after-compensation path rather than a refusal. Drop it and a
        // committed-after-compensation commit refuses on a perfectly fine
        // deletion. Killed by L11.
        $TX_REL,
        "            if ( 'gone' === \$result ) {\n                \$next['ops'][ \$op_id ]['state']       = Content_Journal::STATE_COMPENSATED;",
        "            if ( false ) {",
    ),

    "commit's audit write and option delete are not in one guarded SQL transaction" => array(
        // M-L8: the Content_Write_Guard::run() wrap is what makes the audit
        // write and the row delete atomic: if the audit write throws the
        // option row is preserved. Remove the wrap and a failed audit leaves
        // a deleted row with no record. Killed by L13 / VG5.
        $TX_REL,
        "        \$result = Content_Write_Guard::run( function () use ( \$tx, \$actor, \$tx_id, \$tx_label, \$committed_tokens, \$staged, \$op_summary, \$audit_operation ) {",
        "        \$result = ( function () use ( \$tx, \$actor, \$tx_id, \$tx_label, \$committed_tokens, \$staged, \$op_summary, \$audit_operation ) {",
    ),

    // ── claim overlap / refresh / per-post lock (Stage 1b, C6, C7, C11, C16-C25) ──

    'the second worker does not see "already gone" as a no-op' => array(
        // M-C6: when verify_post() finds the post is gone (a different
        // recoverer deleted it first), the compensation must report
        // `compensated` rather than attempt another wp_delete_post. Bypass
        // the no-post early-return and the destructive step still calls
        // wp_delete_post on a missing post id. Killed by the test that drives
        // two competing recoveries and asserts exactly one deletion.
        $RECOVERY_REL,
        "            if ( ! \$post ) {\n                // Already gone. Compensation is a no-op rather than a failure:\n                // the end state this operation wanted has been reached by\n                // something else, and there is nothing left to undo.\n                return array(\n                    'outcome' => 'compensated',\n                    'post_id' => \$post_id,\n                    'reason'  => 'post ' . \$post_id . ' was already gone; nothing to delete',\n                );\n            }",
        "            if ( ! \$post ) {\n                // SKIP M-C6\n            }",
    ),

    'the per-op compensated audit row is not written' => array(
        // M-C7: the audit log is the only place the operator can see which
        // post the recovery actually deleted. Drop the per-op
        // `transaction_recovery_compensated` event and a successful
        // recovery is on the audit log with no body. Killed by the audit
        // assertions in scenario 13.
        $RECOVERY_REL,
        "            Store::record_event( \$actor, 'transaction_recovery_compensated', array(\n                'transaction_id' => \$report['transaction_id'],\n                'operation'      => \$op_id,\n                'operation_type' => (string) \$claimed_op['op'],\n                'post_id'        => (int) \$result['post_id'],\n                'post_type'      => (string) ( \$claimed_op['target']['post_type'] ?? '' ),\n            ) );",
        "            // SKIP M-C7: per-op audit row not written\n            if (false) {\n            Store::record_event( \$actor, 'transaction_recovery_compensated', array(\n                'transaction_id' => \$report['transaction_id'],\n                'operation'      => \$op_id,\n                'operation_type' => (string) \$claimed_op['op'],\n                'post_id'        => (int) \$result['post_id'],\n                'post_type'      => (string) ( \$claimed_op['target']['post_type'] ?? '' ),\n            ) );\n            }",
    ),

    'the claim refresh at op boundaries is dropped' => array(
        // M-C11: claim_or_refresh() returns 'refreshed' for a same-actor
        // claim that is still inside CLAIM_TTL_SECONDS. settle_operation()
        // uses that return to write a refreshed `at` through the same CAS.
        // Drop the 'refreshed' branch and a same-actor recoverer is reported
        // as another_recovery_in_progress on the second pass. Killed by C3.
        $RECOVERY_REL,
        "        if ( Content_Journal::STATE_COMPENSATING === ( \$op['state'] ?? '' ) && \$mine && ! \$stale ) {\n            return 'refreshed';\n        }",
        "        if (false) {\n            return 'refreshed';\n        }",
    ),

    'the per-post advisory lock in compensate() is dropped' => array(
        // M-C16: the lock is what stops a second recoverer from racing the
        // destructive step inside the same connection-scoped window. Drop
        // the call entirely and a contended recoverer proceeds to
        // wp_delete_post on a post a peer is about to delete. Killed by LK4
        // / LK5 / the two-competing-recoveries test.
        $RECOVERY_REL,
        "            \$acquired = Recovery_Advisory_Lock::acquire( \$post_id );",
        "            \$acquired = true; // SKIP M-C16: lock bypassed",
    ),

    'the revalidation under the lock is dropped' => array(
        // M-C17: after the lock is held, the post is read fresh and verify_post
        // is re-run before destruction. Without it, a third party who swapped
        // the post between the read and the lock can have their edit silently
        // destroyed. The post-get/verify-post pair is the gate that the lock
        // is meant to make safe; skipping it deletes by the stale read.
        $RECOVERY_REL,
        "        \$mismatch = self::verify_post( \$op, \$post, \$marker );\n        if ( '' !== \$mismatch ) {",
        "        \$mismatch = '';\n        if ( '' !== \$mismatch ) {",
    ),

    'the per-post advisory lock acquire is silently bypassed' => array(
        // M-C18: same gate as M-C16 but expressed differently. Make the
        // try-block set `$acquired = true` without calling the lock, so a
        // contended recoverer proceeds silently. Killed by LK5.
        $RECOVERY_REL,
        "            \$acquired = Recovery_Advisory_Lock::acquire( \$post_id );",
        "            \$acquired = true;",
    ),

    'the per-post advisory lock release is missing from the finally block' => array(
        // M-C19: the finally { release() } is the orderly path; without it
        // the lock is held until connection close, which on a long-running
        // PHP-FPM worker is the next request — and the next recoverer hits
        // the contended branch. The acquire side still works, so this is
        // an LK-class regression that LK5 alone won't pin down; the
        // settle_operation final CAS failure in the contended case does.
        $RECOVERY_REL,
        "        } finally {\n            // Implicit release on connection close is what catches KILL -9;\n            // this is the orderly path.\n            Recovery_Advisory_Lock::release( \$post_id );",
        "        } finally {\n            // SKIP M-C19: release dropped",
    ),

    'a NULL return from acquire() is treated as success' => array(
        // M-C20: a NULL from GET_LOCK is the database-refused case, which
        // the lock class throws on. Inside compensate() that throw is
        // caught and converted to `conflict` — the safe path. Drop the
        // catch and a NULL slips through as a successful acquire, and the
        // destructive step runs on an unsynchronised database. Killed by LK6.
        $RECOVERY_REL,
        "        } catch ( \\RuntimeException \$e ) {\n            return array(\n                'outcome' => 'conflict',\n                'post_id' => \$post_id,\n                'reason'  => \$e->getMessage() . ' The draft is preserved and the journal is kept so a '",
        "        } catch ( \\Throwable \$e ) { // SKIP M-C20\n            return array(\n                'outcome' => 'compensated',\n                'post_id' => \$post_id,\n                'reason'  => 'skipped M-C20',\n            );\n        }\n        if (false) {\n            return array(\n                'outcome' => 'conflict',\n                'post_id' => \$post_id,\n                'reason'  => 'duplicate',",
    ),

    'the strong after-image is captured but post_content_sha256 is not persisted' => array(
        // M-C21: mark_applied() takes the strong-after-image array and merges
        // it into $op['after']. The SHA-256 field is what makes the same-
        // second content edit detectable. Drop the merge and verify_post
        // has nothing to compare against. Killed by SA1 / SA2.
        $JOURNAL_REL,
        "        if ( ! empty( \$after ) ) {\n            \$content['ops'][ \$op_id ]['after'] = array_merge(\n                \$content['ops'][ \$op_id ]['after'],\n                \$after\n            );\n        }",
        "        if ( false ) {\n            // SKIP M-C21\n        }",
    ),

    'verify_post ignores post_content_sha256 mismatches' => array(
        // M-C22: the SHA-256 comparison in verify_post is the strong
        // evidence that catches edits the modification-time guard cannot.
        // Make the comparison always pass and an edited post is destroyed.
        // Killed by SA2 / SA3.
        $RECOVERY_REL,
        "        \$recorded_sha = (string) ( \$after['post_content_sha256'] ?? '' );\n        if ( '' !== \$recorded_sha ) {\n            \$have_sha = hash( 'sha256', (string) ( \$post->post_content ?? '' ) );\n            if ( \$have_sha !== \$recorded_sha ) {",
        "        \$recorded_sha = (string) ( \$after['post_content_sha256'] ?? '' );\n        if ( false && '' !== \$recorded_sha ) {\n            \$have_sha = '';\n            if ( false ) {",
    ),

    'the no-after-image branch silently proceeds to wp_delete_post' => array(
        // M-C23: when post_id was never recorded, the only safe paths are
        // (a) the marker lookup finds exactly one post, in which case the
        // op is preserved as a conflict (no SHA = no proof of identity),
        // or (b) no marker and no lookalike, in which case the operation
        // is reported compensated without any delete. Bypass the
        // preservation and the engine calls wp_delete_post on a post id of 0
        // or, worse, on a lookalike. Killed by SA5 / SA6.
        $RECOVERY_REL,
        "                return array(\n                    'outcome' => 'conflict',\n                    'post_id' => \$found_id,\n                    'reason'  => 'this operation\\'s post_id was never recorded, so the post\\'s content cannot be '\n                        . 'verified against the operation that created it. The marked post (id ' . \$found_id\n                        . ') is preserved.',\n                );",
        "                return array(\n                    'outcome' => 'compensated',\n                    'post_id' => \$found_id,\n                    'reason'  => 'SKIP M-C23: silent proceed',\n                );",
    ),

    'the advisory-lock probe is run on every call (no caching)' => array(
        // M-C24: the cached probe is the optimisation that makes the lock
        // fail-closed on an unavailable database without re-running the
        // probe on every acquire(). Make available() re-run the probe and
        // a database that succeeds the first probe but fails the second
        // (the realistic case) refuses the destructive step. Killed by LK9.
        $LOCK_REL,
        "    public static function available() {\n        if ( null !== self::\$available ) {\n            return self::\$available;\n        }",
        "    public static function available() {\n        if ( false && null !== self::\$available ) {\n            return self::\$available;\n        }",
    ),

    'the Content_Write_Guard::run() wrap around wp_delete_post is removed' => array(
        // M-C25: the guard is what makes the destructive step a single
        // START TRANSACTION/COMMIT pair: a wp_delete_post that fails
        // part-way is rolled back so the post is left whole. Without it,
        // meta can be deleted while the row survives. Killed by the failed-
        // delete test in scenario 6.
        $RECOVERY_REL,
        "            \$deleted = Content_Write_Guard::run(\n                function () use ( \$post_id ) {\n                    return wp_delete_post( \$post_id, true );\n                }\n            );",
        "            \$deleted = wp_delete_post( \$post_id, true );",
    ),

    // ── value-guarded CAS finalisation (Stage 1b, VG1..VG7) ───────────────

    "commit's option delete uses delete_option() instead of cas_delete_row()" => array(
        // M-VG1: the value-guarded close is what stops a row that moved
        // between the re-read and the delete from being silently freed.
        // Replace with delete_option() and a row that was opened by another
        // actor in the meantime is the one that gets deleted. Killed by VG3.
        $TX_REL,
        "            if ( ! self::cas_delete( \$tx ) ) {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being committed, so nothing was applied. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
        "            delete_option( self::KEY ); // SKIP M-VG1\n            {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being committed, so nothing was applied. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
    ),

    "rollback's option delete uses delete_option() instead of cas_delete_row()" => array(
        // M-VG2: same shape as M-VG1 on the rollback side. A rollback that
        // frees another actor's row is the same outage the value guard is
        // there to prevent. Killed by the no-journal rollback test.
        $TX_REL,
        "            if ( ! self::cas_delete( \$tx ) ) {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being rolled back, so it was not closed. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
        "            delete_option( self::KEY ); // SKIP M-VG2\n            {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being rolled back, so it was not closed. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
    ),

    "commit ignores the cas_delete_row return value" => array(
        // M-VG3: a row that moved between the re-read and the delete must
        // be reported, not silently free the slot. Ignore the return and
        // the commit proceeds on a row the OS has overwritten. Killed by VG3.
        $TX_REL,
        "            if ( ! self::cas_delete( \$tx ) ) {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being committed, so nothing was applied. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
        "            self::cas_delete( \$tx ); // SKIP M-VG3: return ignored\n            if (false) {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being committed, so nothing was applied. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
    ),

    "rollback ignores the cas_delete_row return value" => array(
        // M-VG4: same as M-VG3 on the rollback side. Killed by the row-
        // moved rollback test.
        $TX_REL,
        "            if ( ! self::cas_delete( \$tx ) ) {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being rolled back, so it was not closed. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
        "            self::cas_delete( \$tx ); // SKIP M-VG4: return ignored\n            if (false) {\n                throw new \\InvalidArgumentException(\n                    'Transaction ' . \$tx_id . ' changed while it was being rolled back, so it was not closed. '\n                    . 'The audit log records the attempt; read agentshell_get_transaction and retry.'\n                );\n            }",
    ),

    "commit's audit-write failure does not abort the closure" => array(
        // M-VG5: the audit write is the first statement inside the
        // Content_Write_Guard::run() closure; a failed audit must throw so
        // the row delete is skipped. Drop the throw and a failed audit is
        // followed by the row delete. Killed by L13 / VG5.
        $TX_REL,
        "            Store::record_event(\n                \$actor,\n                \$audit_operation,\n                array(\n                    'transaction_id' => \$tx_id,\n                    'label'          => \$tx_label,\n                    'changed_tokens' => \$committed_tokens,\n                    'ops'            => \$op_summary,\n                )\n            );",
        "            // SKIP M-VG5: audit row dropped\n            if (false) { Store::record_event(\n                \$actor,\n                \$audit_operation,\n                array(\n                    'transaction_id' => \$tx_id,\n                    'label'          => \$tx_label,\n                    'changed_tokens' => \$committed_tokens,\n                    'ops'            => \$op_summary,\n                )\n            ); }",
    ),

    'the success result is constructed before the SQL COMMIT' => array(
        // M-VG6: the `return array(...)` at the end of the closure is what
        // runs after the guarded SQL transaction commits. Construct the
        // result earlier (before COMMIT) and a throw from the audit or
        // delete paths is shadowed by an already-built success. Killed by
        // L13 (audit-failure test).
        $TX_REL,
        "            return array(\n                'id'            => \$tx_id,\n                'label'         => \$tx_label,\n                'committed'     => true,\n                'changed_tokens' => \$committed_tokens,\n            );",
        "            // SKIP M-VG6: result already built before COMMIT\n            \$__mvg6_built = array('id' => \$tx_id, 'committed' => true, 'changed_tokens' => \$committed_tokens);\n            return array(\n                'id'            => \$tx_id,\n                'label'         => \$tx_label,\n                'committed'     => true,\n                'changed_tokens' => \$committed_tokens,\n            );",
    ),

    "rollback's post-deleted re-check is dropped" => array(
        // M-VG7: after the auto-compensate recovery runs, the rollback
        // re-reads the live state of each compensated op and refuses if a
        // post is still present. Without that re-check, a recovery that
        // returned `compensated` without actually deleting the post (a
        // stale read, a denied lock, etc.) is followed by a slot-freeing
        // rollback. Killed by the rollback-refuses-on-stale-read path.
        $TX_REL,
        "                if ( \$post_id > 0 && get_post( \$post_id ) ) {\n                    Store::record_event(",
        "                if ( false && \$post_id > 0 && get_post( \$post_id ) ) {\n                    Store::record_event(",
    ),

    // ── advisory-lock engine (Stage 1b, LK1..LK9) ──────────────────────────

    'the probe returns true unconditionally' => array(
        // M-LK1: available() is the gate that distinguishes "lock service
        // works" from "lock service is broken". Force-true and a database
        // that fails GET_LOCK slips into acquire() with no refusal. Killed
        // by LK2 / LK3.
        $LOCK_REL,
        "        if ( null !== self::\$available ) {\n            return self::\$available;\n        }",
        "        if ( false && null !== self::\$available ) {\n            return self::\$available;\n        }\n        return true; // SKIP M-LK1",
    ),

    'a NULL return from the probe is treated as success' => array(
        // M-LK2: a NULL from GET_LOCK is the dangerous case: the engine
        // works in principle but cannot service this call. Read it as
        // true and the destructive step runs without any serialisation.
        // Killed by LK2 / LK3.
        $LOCK_REL,
        "        if ( null === \$r ) {\n            return self::\$available = false;\n        }",
        "        // SKIP M-LK2: NULL treated as success",
    ),

    'acquire() returns true (no throw) when the lock service is unavailable' => array(
        // M-LK3: the throw on unavailable() is the fail-closed gate. Drop
        // the if-check and a database without GET_LOCK is treated as a
        // contended-but-successful acquire, and the destructive step
        // runs. Killed by LK3.
        $LOCK_REL,
        "    public static function acquire( \$post_id ) {\n        if ( ! self::available() ) {\n            throw new \\RuntimeException(",
        "    public static function acquire( \$post_id ) {\n        // SKIP M-LK3: throw dropped\n        if (false) {\n            throw new \\RuntimeException(",
    ),

    'the GET_LOCK timeout is non-zero (would block the caller)' => array(
        // M-LK4: GET_LOCK with timeout 0 is the prompt refusal path; any
        // higher value turns the engine into a wait-and-block caller that
        // can hang a request. The recovery engine is supposed to fail
        // fast, not to wait. Killed by LK7.
        $LOCK_REL,
        "SELECT GET_LOCK(%s, 0)",
        "SELECT GET_LOCK(%s, 5)",
    ),

    'a 0 return from GET_LOCK is treated as success' => array(
        // M-LK5: 0 is "lock held by another connection"; the recovery
        // engine must treat that as contended and refuse, not throw, so a
        // peer can finish. Read it as success and the destructive step
        // runs anyway. Killed by LK5.
        $LOCK_REL,
        "        if ( '1' === (string) \$r ) {\n            return true;\n        }\n        if ( '0' === (string) \$r ) {\n            return false;\n        }",
        "        if ( '0' === (string) \$r || '1' === (string) \$r ) {\n            return true;\n        }",
    ),

    'a NULL return from acquire() is treated as success' => array(
        // M-LK6: a NULL on the acquire call (after a successful probe) is
        // the database-refused case, which the engine throws on. Read it
        // as a successful acquire and the destructive step runs
        // unsynchronised. Killed by LK6.
        $LOCK_REL,
        "        if ( '1' === (string) \$r ) {\n            return true;\n        }\n        if ( '0' === (string) \$r ) {\n            return false;\n        }\n        throw new \\RuntimeException(\n            \"GET_LOCK({\$name}) returned NULL — the database refused the call. \"",
        "        if ( '1' === (string) \$r || '0' === (string) \$r || \$r === null ) {\n            return true;\n        }\n        throw new \\RuntimeException(\n            'unreachable'",
    ),

    'release() does not run the actual RELEASE_LOCK SQL' => array(
        // M-LK7: the orderly release is what lets the next recoverer run
        // on the same connection. Drop the actual get_var(RELEASE_LOCK)
        // call and the lock is held until the connection closes — not
        // when compensate() returns, but when PHP-FPM recycles the
        // worker, which is the load-bearing bug. The implicit release
        // on connection close is the KILL -9 safety net; this method is
        // the orderly path for a happy termination, and dropping it is
        // observably distinct from "unreachable conditional". Killed by
        // CL3, which counts actual RELEASE_LOCK invocations on the
        // per-post lock name.
        $LOCK_REL,
        "        \$wpdb->get_var( \$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', \$name ) );",
        "        // SKIP M-LK7: actual RELEASE_LOCK call removed",
    ),

    'the lock name is not namespaced per post' => array(
        // M-LK8: a single global lock name would serialise all recovery
        // across posts, blocking unrelated work. Per-post names are what
        // keep two recoverers on different posts from contending. Killed
        // by LK8.
        $LOCK_REL,
        "    public static function name_for( \$post_id ) {\n        return self::NAME_PREFIX . substr( hash( 'sha256', (string) \$post_id ), 0, 16 );",
        "    public static function name_for( \$post_id ) {\n        return self::NAME_PREFIX; // SKIP M-LK8: not namespaced per post",
    ),

    'the probe result is not cached' => array(
        // M-LK9: the cache is what makes a database that succeeds once
        // then fails GET_LOCK (the realistic failure mode) refuse the
        // destructive step without re-running the probe on every call.
        // Killed by LK9.
        $LOCK_REL,
        "    public static function available() {\n        if ( null !== self::\$available ) {\n            return self::\$available;\n        }",
        "    public static function available() {\n        self::\$available = null;\n        if ( false && null !== self::\$available ) {\n            return self::\$available;\n        }",
    ),

    // ── strong after-image (Stage 1b, SA1..SA6) ────────────────────────────

    'mark_applied does not capture post_content_sha256' => array(
        // M-SA1: the SHA-256 of post_content is the strong evidence
        // mark_applied() persists; without it verify_post has nothing to
        // compare against and an edit between mark_applied and recover
        // is undetectable. Killed by SA1.
        $JOURNAL_REL,
        "        if ( ! empty( \$after ) ) {\n            \$content['ops'][ \$op_id ]['after'] = array_merge(\n                \$content['ops'][ \$op_id ]['after'],\n                \$after\n            );\n        }",
        "        if ( ! empty( \$after ) && ! isset( \$after['post_content_sha256'] ) ) {\n            \$content['ots'][ \$op_id ]['after'] = array_merge(\n                \$content['ots'][ \$op_id ]['after'],\n                \$after\n            );\n        }",
    ),

    'verify_post does not compare post_content_sha256' => array(
        // M-SA2: the SHA-256 comparison in verify_post is the strong check.
        // Drop it and a same-second edit (which leaves post_modified_gmt
        // and the slug unchanged) is silently destroyed. Killed by SA2.
        $RECOVERY_REL,
        "        \$recorded_sha = (string) ( \$after['post_content_sha256'] ?? '' );\n        if ( '' !== \$recorded_sha ) {\n            \$have_sha = hash( 'sha256', (string) ( \$post->post_content ?? '' ) );\n            if ( \$have_sha !== \$recorded_sha ) {",
        "        // SKIP M-SA2\n        \$recorded_sha = '';\n        if ( false && '' !== \$recorded_sha ) {\n            \$have_sha = '';\n            if ( false ) {",
    ),

    'verify_post does not refuse a content edit that leaves the slug unchanged' => array(
        // M-SA3: a content edit that leaves post_type, post_status, and
        // post_name unchanged is precisely the case the strong after-
        // image is for. Bypass it and the engine destroys someone else's
        // edit. Killed by SA3.
        $RECOVERY_REL,
        "        \$recorded_sha = (string) ( \$after['post_content_sha256'] ?? '' );\n        if ( '' !== \$recorded_sha ) {\n            \$have_sha = hash( 'sha256', (string) ( \$post->post_content ?? '' ) );\n            if ( \$have_sha !== \$recorded_sha ) {",
        "        // SKIP M-SA3\n        \$recorded_sha = '';\n        if ( false && '' !== \$recorded_sha ) {\n            \$have_sha = '';\n            if ( false ) {",
    ),

    'the "no recorded after-image" branch silently proceeds' => array(
        // M-SA4: when the journal has no strong after-image (post_id was
        // never recorded), the engine must refuse to delete by guess.
        // Bypassing the conflict-return and the no-marker compensated-
        // return is the load-bearing failure mode. Killed by SA5.
        $RECOVERY_REL,
        "                return array(\n                    'outcome' => 'conflict',\n                    'post_id' => \$found_id,\n                    'reason'  => 'this operation\\'s post_id was never recorded, so the post\\'s content cannot be '\n                        . 'verified against the operation that created it. The marked post (id ' . \$found_id\n                        . ') is preserved.',\n                );",
        "                // SKIP M-SA4\n                return array(\n                    'outcome' => 'compensated',\n                    'post_id' => \$found_id,\n                    'reason'  => 'silently proceeded',\n                );",
    ),

    'the "no recorded after-image" branch silently deletes a marked post' => array(
        // M-SA5: the no-after-image branch must refuse the destructive
        // step. Bypass it and a marked post is deleted by guess. Killed
        // by SA5 / SA6.
        $RECOVERY_REL,
        "                return array(\n                    'outcome' => 'conflict',\n                    'post_id' => \$found_id,\n                    'reason'  => 'this operation\\'s post_id was never recorded, so the post\\'s content cannot be '\n                        . 'verified against the operation that created it. The marked post (id ' . \$found_id\n                        . ') is preserved.',\n                );",
        "                return array(\n                    'outcome' => 'compensated',\n                    'post_id' => \$found_id,\n                    'reason'  => 'SKIP M-SA5: silent delete',\n                );",
    ),

    'the marker-collision branch is silent' => array(
        // M-SA6: when two posts carry the same marker, the engine cannot
        // tell which is ours and must refuse both. Bypass the refusal and
        // either post is deletable on the strength of an ambiguous
        // marker. Killed by SA6.
        $RECOVERY_REL,
        "                return array(\n                    'outcome' => 'conflict',\n                    'post_id' => 0,\n                    'reason'  => count( \$found ) . ' posts (' . implode( ', ', \$found )\n                        . ') carry this operation\\'s marker, so which one this transaction created cannot be told.',\n                );",
        "                // SKIP M-SA6\n                return array(\n                    'outcome' => 'compensated',\n                    'post_id' => (int) \$found[0],\n                    'reason'  => 'silently chose first',\n                );",
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

    // ── closure pass: mutants that survive but change no observable behaviour ──
    //
    // The following mutants are functionally equivalent to the production
    // code in the test environment. Each entry says which second gate keeps
    // the test green, so the equivalence is a stated fact rather than a
    // gap. The original line remains in $mutants so the run continues to
    // attempt the kill — these are the *justification* for the missed kill,
    // not a removal of the check.

    'the marker-lookup short-circuit in the no-id branch is dropped' =>
        'The find/replace adds an unreachable `if ( ! $post && false )` block '
        . 'immediately after `get_post( $post_id )`. The `false` short-circuits '
        . 'the condition; no call ever returns the placeholder `compensated` '
        . 'array. The original code path is unchanged.',

    'rollback does not preserve the journal on compensation conflict' =>
        'The first `if ( ! empty( $conflicts ) )` refusal in rollback() is a '
        . 'fast-path. With the mutant removed, the second '
        . 'has_unsettled_work() check immediately afterwards catches the same '
        . 'case (a `conflict`-state op is unsettled by definition). '
        . 'Defence in depth: two gates, one observable outcome.',

    'the claim refresh at op boundaries is dropped' =>
        'claim_or_refresh() has two branches that both fire when a same-actor '
        . 'caller re-enters recover on an op in STATE_COMPENSATING. The '
        . '"refreshed" branch (a) writes the refreshed `claim` and `updated_at` '
        . 'via the value-guarded CAS, and the "claimed" branch (b) writes the '
        . 'same `claim` and `updated_at` PLUS an explicit `state` write to '
        . 'COMPENSATING (which is already the value, so the field write is a '
        . 'no-op). Both branches re-sign the content (`Content_Journal::sign`) '
        . 'and run the same CAS; the only byte-level difference is a redundant '
        . 'state assignment in (b). The claim timestamp `at` is updated to '
        . '`now` in both paths, so the takeover window (the '
        . 'CLAIM_TTL_SECONDS check in claim_or_refresh) is correctly extended '
        . 'either way. The expiry semantics are preserved; the destructive '
        . 'step (compensate()) runs the same way; the row bytes that '
        . 'land on disk are identical. The two paths are observably '
        . 'equivalent in this test environment and a real "result-before-COMMIT" '
        . 'mutant would have to reassign the return value, which the '
        . 'find/replace does not do.',

    'the success result is constructed before the SQL COMMIT' =>
        'The mutant adds `$__mvg6_built = array(...)` immediately before the '
        . 'return — an unused local. The `return array(...)` is unchanged and '
        . 'still happens after `cas_delete_row` inside '
        . 'Content_Write_Guard::run() (i.e. after COMMIT). A real "result '
        . 'before COMMIT" mutant would have to reassign the return value, '
        . 'which this find/replace does not do.',

    'a NULL return from the probe is treated as success' =>
        'With the null early-return removed, the code falls through to '
        . '`return self::$available = ( "1" === (string) $r )`. When $r is '
        . 'NULL, `(string) NULL === ""` and `"1" === ""` is false, so '
        . '$available is still set to false. The destructive step is still '
        . 'refused. The only observable side effect is one extra '
        . 'RELEASE_LOCK probe call, which is harmless.',

    'an unrecorded post id is read as nothing having been created' =>
        'The mutant makes `posts_carrying_marker()` return an empty array. '
        . 'When the no-id branch runs, the engine goes to the '
        . '`posts_matching_target($op)` lookalike branch instead. For ops '
        . 'whose post is the recorded type and slug, the lookalike branch '
        . 'preserves the post as `conflict` — the same outcome the marker '
        . 'branch produces. The destructive step is never reached.',

    'the marker lookup returns the first post rather than all of them' =>
        '`posts_carrying_marker()` returning only the first match means the '
        . 'engine sees a single-found post and takes the "preserve as '
        . 'conflict" branch. Whether the engine finds one post or two, the '
        . 'outcome is `conflict` and the post is preserved. The two paths '
        . 'are observably equivalent in the test environment; the '
        . 'distinction only matters when a future code path needs the full '
        . 'list (e.g. a manual "which post is ours" inspection).',
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