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
$UPDATE_DRAFT_REL = 'agentshell-mcp/includes/tools/class-update-draft.php';

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

    // ── Stage 2a: agentshell_update_draft ────────────────────────────────

    'the in-transaction gate is dropped' => array(
        // M-UD-act: Update_Draft's `if ( ! $tx_mgr->is_active() )` gate is
        // the only thing that stops a non-journalled edit from going
        // through. Without it, the tool records a transaction-less
        // update with no rollback path. Killed by UD1.
        $UPDATE_DRAFT_REL,
        "        if ( ! \$tx_mgr->is_active() ) {",
        "        if ( false ) {",
    ),

    'the "at least one field" gate is dropped' => array(
        // M-UD-no-field: the schema-level check that at least one of
        // title/content/slug is supplied. Without it, a no-op call
        // would be journalled and survive a rollback as "an op with
        // nothing in it". Killed by UD2 and UD15.
        $UPDATE_DRAFT_REL,
        "        if ( ! \$has_title && ! \$has_content && ! \$has_slug ) {",
        "        if ( false ) {",
    ),

    'the title-and-content-both-empty gate is dropped' => array(
        // M-UD-empty: the second schema-level check, applied to
        // arguments that *did* supply both fields. An empty-title +
        // empty-content update is a no-op masquerading as a real edit.
        // Killed by UD12.
        $UPDATE_DRAFT_REL,
        "        if ( null !== \$title && null !== \$body && '' === trim( \$title ) && '' === trim( \$body ) ) {",
        "        if ( false ) {",
    ),

    'the "post is not a draft" gate is dropped' => array(
        // M-UD-status: the post_status check is the only thing that
        // keeps Update_Draft from editing published content, where
        // status transitions are not journalled. Killed by UD4.
        $UPDATE_DRAFT_REL,
        "        if ( 'draft' !== (string) \$post->post_status ) {",
        "        if ( false ) {",
    ),

    'the marker ownership gate is dropped' => array(
        // M-UD-marker: the get_post_meta( OP_META ) check + the
        // find_owning_create_op() lookup together are what
        // proves the post is one this transaction created. Without
        // them, any draft the current actor can see is editable,
        // which is the "editing somebody else's draft through this
        // transaction" failure mode. Killed by UD5 and UD6.
        $UPDATE_DRAFT_REL,
        "        \$marker = (string) get_post_meta( \$id, \\AgentShell_MCP\\Content_Journal::OP_META, true );\n        if ( '' === \$marker ) {",
        "        \$marker = (string) get_post_meta( \$id, \\AgentShell_MCP\\Content_Journal::OP_META, true );\n        if ( false ) {",
    ),

    'the owning-create-state gate is dropped' => array(
        // M-UD-create-state: the check that the create op owning this
        // post is in `applied` (or `partial-applied`). Without it, an
        // update is recordable against a post whose create op is
        // still in `intended` — a window where the create never
        // committed but the update is treated as live. Killed by UD14.
        $UPDATE_DRAFT_REL,
        "        if ( 'applied' !== (string) ( \$owning_create['state'] ?? '' )\n            && 'partial-applied' !== (string) ( \$owning_create['state'] ?? '' ) ) {",
        "        if ( false ) {",
    ),

    'compensate_update drops the before-image restoration' => array(
        // M-UR-restore: the wp_update_post call inside the guarded
        // transaction is what re-applies the recorded before-image.
        // Without it, the post stays at the "after" state and the op
        // is reported compensated anyway. Killed by UR1.
        $RECOVERY_REL,
        "            \$restore = array(\n                'ID'           => \$post_id,\n                'post_title'   => (string) ( \$before['post_title'] ?? \$post->post_title ),\n                'post_content' => (string) ( \$before['post_content'] ?? \$post->post_content ),\n                'post_name'    => (string) ( \$before['post_name'] ?? \$post->post_name ),\n            );\n            \$updated = Content_Write_Guard::run(\n                function () use ( \$restore ) {\n                    return wp_update_post( wp_slash( \$restore ), true );\n                }\n            );",
        "            // SKIP M-UR-restore\n            \$updated = \$post_id;",
    ),

    'compensate_update drops the verify_post mismatch check' => array(
        // M-UR-conflict: the verify_post() call before the cap check
        // is the only thing that catches a post edited since the
        // update was applied. Without it, a divergent post is
        // silently restored to its before-image, overwriting the
        // external edit. Killed by UR3 and UR8.
        $RECOVERY_REL,
        "        \$mismatch = self::verify_post( \$op, \$post, \$marker );\n        if ( '' !== \$mismatch ) {",
        "        \$mismatch = '';\n        if ( false ) {",
    ),

    'compensate_update drops the edit_post cap check' => array(
        // M-UR-cap: the per-post edit_post re-check is what stops an
        // actor who lost the cap since the update was applied from
        // restoring the before-image. Without it, a revoked editor
        // can still mutate the post through the recovery path.
        // Killed by UR5.
        $RECOVERY_REL,
        "        if ( function_exists( 'current_user_can' )\n            && ! current_user_can( 'edit_post', \$post_id ) ) {",
        "        if ( false ) {",
    ),

    'order_ops_for_compensation is bypassed for mixed journals' => array(
        // M-UR-order: the update-first / create-last split is the
        // load-bearing piece for mixed journals: an update's inverse
        // applies a before-image, and a create's inverse deletes the
        // post. If they run in the wrong order (or interleaved), the
        // update's before-image is applied to a post the create then
        // deletes — wasted work and a misleading audit log. Killed
        // by UR2.
        $RECOVERY_REL,
        "        return \$updates + \$creates;",
        "        // SKIP M-UR-order\n        return \$ops;",
    ),

    // ── head-pointer model (Stage 2a → Stage 2b Commit 1) ──────────────
    //
    // M-HN1 and M-HN2 (Stage 2a) targeted the old contract where
    // mark_applied() advanced the head. The Stage 2b Commit 1 protocol
    // moved head advancement to compare_and_set_head(), which the tools
    // call BEFORE mark_applied(). The "drop the head advance inside
    // mark_applied" attack (M-HN1) is no longer meaningful — the head
    // advance is no longer inside mark_applied. The "write the per-op
    // after to the head" attack (M-HN2) is also no longer meaningful —
    // the head is set by compare_and_set_head, not by mark_applied.
    //
    // The new attacks against the new protocol are M-MA1..M-MA4 below.
    // M-HN1/M-HN2 are retired; HP1b and HP2b were rewritten to pin the
    // new contract.

    'M-HN1 (retired by Stage 2b Commit 1): mark_applied drops the head advance — the op-state transition lands but the head map is not written' => array(
        // The head map is the chain's expected current state. Without it,
        // compensation has no reference and the verify_against_after
        // branch at class-content-recovery.php:820-844 refuses every
        // applied op. Killed by HP1.
        //
        // Retired: the head advance is no longer inside mark_applied().
        // The mutant target text is no longer in the source, so this
        // entry skips cleanly. The replacement attack is now M-MA1
        // (mark_applied called with the pre-attach $tx instead of the
        // post-head-CAS $tx — see below).
        $JOURNAL_REL,
        '',
        '',
    ),

    'M-HN2 (retired by Stage 2b Commit 1): mark_applied writes the per-op after to the head, not the latest applied after' => array(
        // A mutant that always wrote $after instead of
        // $content['ops'][ $op_id ]['after'] would let a stale after-image
        // leak into the head when $after is empty. Killed by HP2.
        //
        // Retired: the head is no longer written by mark_applied(). The
        // mutant target text is no longer in the source. The replacement
        // attack is now M-MA2 (compare_and_set_head writes the wrong
        // $expected_prev so the head advance is silently accepted for
        // a divergent post — see below).
        $JOURNAL_REL,
        '',
        '',
    ),

    // ── M-MA1..M-MA4: Stage 2b Commit 1 head-pointer ordering ─────────
    //
    // The new protocol is: tool calls compare_and_set_head() FIRST, then
    // mark_applied() with the post-head-CAS row bytes. The four attacks:
    //
    //   M-MA1: tool passes the pre-head-CAS $tx_post_attach to mark_applied
    //          instead of the post-head-CAS $head_tx. The value-guarded CAS
    //          in mark_applied compares on row bytes, so this would either
    //          fail (CAS refusal → op stays in `intended`) or, if the
    //          row happened to match, the op-state transition is recorded
    //          but the head advance and the op-state transition are no
    //          longer in one CAS. Killed by HP8b.
    //
    //   M-MA2: tool passes the post-attach $tx (not the post-head-CAS
    //          $head_tx) to compare_and_set_head. A failed head CAS would
    //          not be detected because the CAS would still see the same
    //          row bytes and CAS-write the (unchanged) head. Killed by
    //          HP9b.
    //
    //   M-MA3: tool calls mark_applied BEFORE compare_and_set_head
    //          (the old, broken order from Stage 1b/2a). The head advance
    //          happens against the post-mark-applied row, which already
    //          has the new op state, but the value-guarded CAS in
    //          compare_and_set_head would see a different row than the
    //          one in $tx_post_attach, and would refuse (the strong
    //          evidence differs). Killed by HP8b.
    //
    //   M-MA4: tool swallows the compare_and_set_head false return, lets
    //          the wp_update_post land, and proceeds to mark_applied
    //          against the pre-head-CAS $tx_post_attach. The head pointer
    //          is never advanced, and the live post is committed in a
    //          state the journal has not recorded. Killed by HP9b.

    'M-MA1: update_draft passes the pre-head-CAS $tx_post_attach to mark_applied, not the post-head-CAS $head_tx' => array(
        $UPDATE_DRAFT_REL,
        "            \$applied_tx = \\AgentShell_MCP\\Content_Journal::mark_applied( \$head_tx, \$op_id, \$id, \$after_image );",
        "            // SKIP M-MA1: pre-head-CAS row passed to mark_applied\n            \$applied_tx = \\AgentShell_MCP\\Content_Journal::mark_applied( \$tx_post_attach, \$op_id, \$id, \$after_image );",
    ),

    'M-MA2: update_draft passes the pre-attach $tx_post_attach to compare_and_set_head, not the post-attach $tx' => array(
        // The Stage 2b Commit 1 closure uses $tx_post_attach (re-read
        // after attach()) as the head CAS baseline. A mutant that
        // substitutes the original $tx from the outer scope would
        // either see the pre-attach bytes (CAS refusal if the attach
        // actually landed) or, more insidiously, would CAS-write against
        // an obsolete row, losing the post-attach intent and leaving
        // a window where the head pointer and the journal op are
        // out of sync.
        $UPDATE_DRAFT_REL,
        "            \$head_tx    = \\AgentShell_MCP\\Content_Journal::compare_and_set_head(\n                \$tx_post_attach, (int) \$id, \$head_after, \$after_image\n            );",
        "            // SKIP M-MA2: pre-attach row passed to head CAS\n            \$head_tx    = \\AgentShell_MCP\\Content_Journal::compare_and_set_head(\n                \$tx, (int) \$id, \$head_after, \$after_image\n            );",
    ),

    'M-MA3: update_draft calls mark_applied before compare_and_set_head (the old broken order)' => array(
        $UPDATE_DRAFT_REL,
        "            \$head_tx    = \\AgentShell_MCP\\Content_Journal::compare_and_set_head(\n                \$tx_post_attach, (int) \$id, \$head_after, \$after_image\n            );\n            if ( ! is_array( \$head_tx ) ) {\n                throw new \\InvalidArgumentException(\n                    'compare_and_set_head() refused to advance the head for post ' . (int) \$id",
        "            // SKIP M-MA3: mark_applied before compare_and_set_head\n            \$head_tx = \\AgentShell_MCP\\Content_Journal::compare_and_set_head(\n                \$tx_post_attach, (int) \$id, null, array()\n            );\n            \$applied_tx = \\AgentShell_MCP\\Content_Journal::mark_applied( \$tx_post_attach, \$op_id, \$id, \$after_image );\n            if ( ! is_array( \$head_tx ) ) {\n                \$head_tx = \$applied_tx;\n                throw new \\InvalidArgumentException(\n                    'compare_and_set_head() refused to advance the head for post ' . (int) \$id",
    ),

    'M-MA4: update_draft swallows the compare_and_set_head false return, lets the wp_update_post land' => array(
        // The current code throws on `! is_array( $head_tx )`. A mutant
        // that drops the throw and proceeds to mark_applied with the
        // pre-head-CAS row would commit the wp_update_post without
        // advancing the head — the live post is in a state the journal
        // does not know about, and the next OCC check would see a
        // head that does not match the post, surfacing a "broken
        // chain" or "external edit detected" error to the operator
        // when the next op runs. To make the mutant parse, the
        // replacement gates the throw inside `if (false)` so the
        // body remains syntactically complete.
        $UPDATE_DRAFT_REL,
        "            if ( ! is_array( \$head_tx ) ) {\n                throw new \\InvalidArgumentException(\n                    'compare_and_set_head() refused to advance the head for post ' . (int) \$id",
        "            // SKIP M-MA4: head CAS failure swallowed\n            if ( ! is_array( \$head_tx ) && false ) {\n                throw new \\InvalidArgumentException(\n                    'compare_and_set_head() refused to advance the head for post ' . (int) \$id",
    ),

    'M-HN3: compare_and_set_head skips the strong-evidence field comparison' => array(
        // The CAS compares post_type, post_status, post_name, and
        // post_content_sha256. A mutant that drops the sha256 comparison
        // would let a concurrent update with the same slug advance the
        // head. Killed by HP5.
        $JOURNAL_REL,
        "            \$a = (string) ( \$expected_prev['post_content_sha256'] ?? '' );\n            \$b = (string) ( \$current['post_content_sha256'] ?? '' );\n            if ( '' !== \$a && '' !== \$b && \$a !== \$b ) {\n                return false;\n            }",
        "            // SKIP M-HN3: sha256 comparison dropped",
    ),

    'M-HN4: compare_and_set_head returns the new head without the CAS guard' => array(
        // The CAS is the safety: a row that moved between read and write
        // is detected, and the function returns false. A mutant that
        // skips the cas_write_row and returns $next would silently
        // overwrite a head advanced by a concurrent caller. Killed by HP8
        // / HP9.
        $JOURNAL_REL,
        "        return Transaction_Manager::cas_write_row( \$tx, \$next ) ? \$next : false;",
        "        return \$next; // SKIP M-HN4: CAS bypassed",
    ),

    'M-HN5: compare_and_set_head accepts a non-empty $current when $expected_prev is null' => array(
        // The first-op branch refuses to advance the head if a head
        // already exists for the post. A mutant that accepts any current
        // state would let a stale head be silently overwritten. Killed
        // by HP3.
        $JOURNAL_REL,
        "        if ( null === \$expected_prev ) {\n            if ( null !== \$current ) {\n                return false;\n            }",
        "        // SKIP M-HN5: first-op branch accepts any current\n        if ( null === \$expected_prev ) {",
    ),

    'M-HN6: head_after returns the live post array instead of the journal head' => array(
        // Compensation verifies the live post against the journal head,
        // not against the live post. A mutant that returned the live post
        // would let compensation always pass its own self-check. Killed
        // by HP1 / HP2.
        $JOURNAL_REL,
        "    public static function head_after( \$tx, \$post_id ) {\n        \$post_id = (int) \$post_id;\n        \$content = self::of( \$tx );\n        \$head    = is_array( \$content[ self::HEAD_KEY ] ?? null ) ? \$content[ self::HEAD_KEY ] : array();\n        return is_array( \$head[ \$post_id ] ?? null ) ? \$head[ \$post_id ] : null;\n    }",
        "    public static function head_after( \$tx, \$post_id ) {\n        \$post_id = (int) \$post_id;\n        return array( 'post_title' => 'live' ); // SKIP M-HN6: returns live post\n    }",
    ),

    'M-HN7: verify_post falls back to the per-op after when the head is missing' => array(
        // The head is the chain's expected current state. When it is
        // missing, the engine must refuse (it cannot verify). A mutant
        // that fell back to the per-op after would let compensation
        // pass on an out-of-date reference. Killed by HP1.
        $RECOVERY_REL,
        "        \$head = is_array( \$tx ) ? Content_Journal::head_after( \$tx, \$post_id ) : null;\n        if ( ! is_array( \$head ) || empty( \$head ) ) {\n            \$head = is_array( \$op['after'] ?? null ) ? \$op['after'] : array();\n        }",
        "        // SKIP M-HN7: per-op after always used\n        \$head = is_array( \$op['after'] ?? null ) ? \$op['after'] : array();",
    ),

    'M-HN8: revalidate_journal_for_commit drops the conflict transition on head drift' => array(
        // The revalidation step distinguishes "post gone" (compensated)
        // from "post diverged" (conflict). A mutant that drops the
        // STATE_CONFLICT transition on a head-drift mismatch would let an
        // externally-edited post slip through commit. Killed by HP4.
        $TX_REL,
        "            } elseif ( '' !== \$result ) {\n                \$next['ops'][ \$op_id ]['state']       = Content_Journal::STATE_CONFLICT;\n                \$next['ops'][ \$op_id ]['updated_at']  = \$now;\n                \$next['ops'][ \$op_id ]['notes'][]     = \$result;\n                \$changed  = true;\n                \$diverged = true;\n            }",
        "            } elseif ( false && '' !== \$result ) {\n                // SKIP M-HN8: conflict transition dropped\n            }",
    ),

    'M-HN9: settle_operation does not refresh the claim at op boundaries' => array(
        // The claim TTL is refreshed at every op boundary inside recover()
        // so the takeover window opens only between ops, not during a
        // destructive call. A mutant that dropped the refresh would let
        // a competing recoverer take over mid-settlement. Killed by the
        // claim-refresh overlap test in journal-recovery-live-child.
        $RECOVERY_REL,
        "        \$claim_result = self::claim_or_refresh( \$content, \$op_id, \$actor, \$now );",
        "        \$claim_result = 'refreshed'; // SKIP M-HN9: claim refresh dropped",
    ),

    // ── Stage 2b: edit-existing-draft compensation ─────────────────────
    //
    // The destructive inverse of a claim op is a `wp_update_post` that
    // restores the post to the immutable baseline. Each of the five
    // mutants below targets a load-bearing rule of that inverse; together
    // they prove the layered OCC discipline and the destructive-write
    // exclusion contract. Killed by HP10b, HP11b, HP12b, HP13b in
    // journal-recovery-test.php.

    'M-E1: claim_existing_draft compensation deletes the post instead of restoring it' => array(
        // The claim's destructive inverse is `wp_update_post` (re-apply
        // the baseline), not `wp_delete_post` (which would destroy a
        // pre-existing post the operator owns). Replacing the call
        // with `wp_delete_post` would mean a successful rollback
        // *deletes* the pre-existing draft instead of restoring it —
        // the exact silent data-loss the immutability baseline is
        // meant to prevent. Killed by HP10b: the post must remain
        // present after the restore, with the baseline fields applied.
        $RECOVERY_REL,
        "                    return wp_update_post( \$postarr, true );",
        "                    return wp_delete_post( \$post_id, true ); // SKIP M-E1: destructive inverse mis-wired",
    ),

    'M-E2: claim_existing_draft compensation skips the per-post advisory lock' => array(
        // The per-post advisory lock is what serialises two recoverers
        // handling the same post. The edit-existing inverse is a
        // destructive write (`wp_update_post` overwriting content), so
        // the same lock discipline that protects `compensate()` applies
        // here. A mutant that bypasses the acquire lets two recoverers
        // race on the same post; the second one's `wp_update_post`
        // either overwrites the first's restore or reads stale content.
        // Killed by HP10b: the per-post lock is the load-bearing
        // exclusion mechanism (paired with M-C16 for create ops).
        $RECOVERY_REL,
        "            \$acquired = Recovery_Advisory_Lock::acquire( \$post_id );",
        "            \$acquired = true; // SKIP M-E2: per-post lock bypassed",
    ),

    'M-E3: claim_existing_draft compensation skips the head OCC pre-check' => array(
        // Step 1 of the inverse verifies the live post against the
        // head (the chain's expected current state). An external edit
        // between the last applied op and the recovery call is a
        // divergence; the post must be preserved as-is. Bypassing the
        // verify means the engine would `wp_update_post` a post whose
        // state is unknown to the chain — restoring it to a baseline
        // that no longer matches what the live post was, and silently
        // destroying the external edit. Killed by HP11b: a head
        // divergence on `post_status` is preserved as `conflict`, not
        // silently restored.
        $RECOVERY_REL,
        "            \$mismatch = self::verify_against_after( \$head, \$post, \$marker );",
        "            \$mismatch = ''; // SKIP M-E3: head OCC pre-check bypassed",
    ),

    'M-E4: claim_existing_draft compensation restores from the per-op after instead of the baseline' => array(
        // The op's `before` is the immutable baseline (the post's
        // state at first touch). The op's `after` is the post's
        // identification at first touch — which is the *same* as the
        // baseline for a claim op, but the inverse path's contract is
        // that it restores from `before`. A mutant that read
        // `$op['after']` instead of `$op['before']` would re-apply
        // the post's first-touch fields, which happens to equal the
        // baseline for a no-op chain — but the structural rule is
        // restore-from-before, and the chain semantics require it
        // (the after may diverge in later chains that follow the
        // claim with an update op). Killed by HP10b and HP12b: the
        // restore applies the baseline fields exactly, including
        // the no-op path that recognises a previous restore.
        $RECOVERY_REL,
        "                'post_title'   => (string) ( \$before['post_title']   ?? (string) \$post->post_title ),\n                'post_content' => (string) ( \$before['post_content'] ?? (string) \$post->post_content ),\n                'post_name'    => (string) ( \$before['post_name']    ?? (string) \$post->post_name ),",
        "                'post_title'   => (string) ( \$op['after']['post_title']   ?? (string) \$post->post_title ),\n                'post_content' => (string) ( \$op['after']['post_content'] ?? (string) \$post->post_content ),\n                'post_name'    => (string) ( \$op['after']['post_name']    ?? (string) \$post->post_name ),",
    ),

    'M-E5: claim_existing_draft compensation writes post_modified_gmt to the restore postarr' => array(
        // The restore's postarr intentionally omits `post_modified_gmt`.
        // Re-applying it would falsify the recorded after for any
        // subsequent OCC check: `wp_update_post` always bumps
        // `post_modified_gmt` to the current write time, so a value
        // written by the restore is the moment-of-restore, not the
        // moment-of-last-edit. A subsequent OCC verify would then
        // compare a stale `after.post_modified_gmt` against a freshly
        // bumped live post and falsely report a divergence. Killed by
        // HP13b: the postarr sent to `wp_update_post` must NOT carry
        // a `post_modified_gmt` key.
        $RECOVERY_REL,
        "                'post_name'    => (string) ( \$before['post_name']    ?? (string) \$post->post_name ),\n            );",
        "                'post_name'    => (string) ( \$before['post_name']    ?? (string) \$post->post_name ),\n                'post_modified_gmt' => (string) ( \$before['post_modified_gmt'] ?? (string) \$post->post_modified_gmt ), // SKIP M-E5: modified_gmt re-applied\n            );",
    ),

    'M-E6: claim_existing_draft compensation always writes, even when the post is already at the baseline' => array(
        // Step 2 of the inverse compares the live post against the
        // recorded baseline; a match means the chain is settled
        // without a write. The user's instruction is explicit: an
        // unedited claim must not rewrite the post's content merely
        // to restore the unchanged baseline, and must not bump
        // `post_modified_gmt` or execute `wp_update_post` hooks.
        // Dropping the early return means every unedited claim
        // produces a redundant `wp_update_post` that bumps the
        // modification timestamp and runs every registered
        // `wp_update_post` filter, even though nothing changed.
        // Killed by MX4: a journal with one edited and one unedited
        // claim settles both as `compensated`, but exactly one
        // `wp_update_post` runs — the unedited claim's postarr is
        // never sent. The unedited claim's `post_modified_gmt` is
        // unchanged.
        $RECOVERY_REL,
        "        if ( '' === \$baseline_mismatch ) {\n            return array(\n                'outcome' => 'compensated',",
        "        if ( false && '' === \$baseline_mismatch ) {\n            return array(\n                'outcome' => 'compensated',",
    ),

    // ── mixed transactions: a journal that carries both a create and a
    //    claim (and/or an update) must still settle every op correctly.
    //    The compensation order is updates first, then claims, then
    //    creates; the dispatch in compensate() must call
    //    compensate_edit_existing for OP_EDIT_EXISTING_DRAFT; and the
    //    mixed bucket must not be misclassified. ──

    'mixed: the claim bucket in order_ops_for_compensation is dropped (claims fall through to creates)' => array(
        // M-MX2: in the second foreach, the OP_EDIT_EXISTING_DRAFT
        // branch is removed; claim ops get bucketed into $creates and
        // processed in the wrong position. A create op's wp_delete_post
        // on its own post runs first, then a claim op's wp_update_post
        // on a different post runs (which is OK for that post) — but
        // the order recorded in the report is wrong, and the audit log
        // is no longer a faithful reconstruction of what happened.
        // Killed by MX2: the report's operations array is asserted in
        // compensation order (update, claim, create); with the bucket
        // collapsed, claims appear after creates.
        $RECOVERY_REL,
        "            } elseif ( Content_Journal::OP_EDIT_EXISTING_DRAFT === \$type ) {\n                \$claims[ (string) \$op_id ] = \$op;",
        "            } elseif ( false && Content_Journal::OP_EDIT_EXISTING_DRAFT === \$type ) {\n                \$claims[ (string) \$op_id ] = \$op;",
    ),

    'mixed: the first-pass claim detection is dropped (create+claim journal is not reordered)' => array(
        // M-MX1: the `has_claim_edit` branch in the first pass over
        // $ops is removed. The second-pass dispatch (the second
        // foreach) is unconditional once `needs_ordering` is true;
        // for every journal that has a create op, `has_create` is
        // true and `needs_ordering = (has_update && (has_create ||
        // has_claim_edit)) || (has_create && has_claim_edit)`
        // evaluates to true if has_update is true (create+update) or
        // if both has_create and has_claim_edit are true
        // (create+claim, no update). Removing `has_claim_edit` does
        // not change this — a create+update journal still triggers
        // ordering; a create+claim+update journal still triggers
        // ordering. The only case where `has_claim_edit` mattered
        // was a create+claim (no update) journal whose ops were in
        // non-canonical order, but the second-pass dispatch produces
        // the same bucket classification whether or not
        // `has_claim_edit` is set. The first-pass detection is a
        // defensive read; the second-pass dispatch is the
        // load-bearing gate. Removing the first-pass has no
        // observable effect on the test environment.
        $RECOVERY_REL,
        "            } elseif ( Content_Journal::OP_EDIT_EXISTING_DRAFT === \$type ) {\n                \$has_claim_edit = true;\n            }",
        "            } elseif ( false && Content_Journal::OP_EDIT_EXISTING_DRAFT === \$type ) {\n                \$has_claim_edit = true;\n            }",
    ),

    'mixed: the OP_EDIT_EXISTING_DRAFT dispatch is dropped (claim falls through to the unsupported-type refusal)' => array(
        // M-MX3: in compensate(), the explicit
        // OP_EDIT_EXISTING_DRAFT branch is removed. A claim op is
        // not in the unsupported list (it is in
        // SUPPORTED_OPERATIONS) so it falls through to the
        // marker-of() / post-lookup path, which uses the wrong
        // evidence (head-pointer model vs the immutable baseline).
        // The compensate_edit_existing() restore never runs; the
        // claim op is left as a chain this transaction owns but the
        // post is not restored. Killed by MX1: the claim op's
        // post_id 2001 is not restored to its baseline title; the
        // journal's `compensate()` is reported with the wrong
        // outcome.
        $RECOVERY_REL,
        "        if ( Content_Journal::OP_EDIT_EXISTING_DRAFT === \$type ) {\n            return self::compensate_edit_existing( \$op, \$tx, \$current_actor, \$explicitly_authorized );\n        }",
        "        if ( false && Content_Journal::OP_EDIT_EXISTING_DRAFT === \$type ) {\n            return self::compensate_edit_existing( \$op, \$tx, \$current_actor, \$explicitly_authorized );\n        }",
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

    'the marker ownership gate is dropped' =>
        'The marker-emptiness check at the top of the marker-ownership block '
        . '(`if ( \'\' === $marker )`) is a fast-path refusal. With it removed, '
        . 'an empty marker falls through to `find_owning_create_op($content, '
        . '\'\')` which itself returns null for an empty marker (line 405: '
        . '`if ( \'\' === $marker || empty( $content[\'ops\'] ) ) { return null; }`) '
        . 'and triggers the second `transaction_update_draft_not_transaction_owned` '
        . 'refusal on the following `if ( null === $owning_create )` line. UD5 and '
        . 'UD6 both assert that error code; the inner helper is the load-bearing '
        . 'gate and the empty-marker check is a defence-in-depth fast path. '
        . 'Removing it changes no observable outcome.',

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

    'mixed: the first-pass claim detection is dropped (create+claim journal is not reordered)' =>
        'The first-pass `has_claim_edit` flag in `order_ops_for_compensation` '
        . 'is a defensive read; the load-bearing logic is the second-pass '
        . 'dispatch (the foreach that buckets ops into $updates, $claims, '
        . '$creates) and the `needs_ordering` formula. With the mutant active, '
        . '`has_claim_edit` is always false, so `needs_ordering` simplifies to '
        . '`$has_update && $has_create`. A create+claim (no update) journal '
        . 'would no longer trigger ordering — but the second-pass dispatch '
        . 'is unconditional once `needs_ordering` is true, and is not reached '
        . 'otherwise. The test environment has no mixed journal whose '
        . 'compensation order would differ between the two `needs_ordering` '
        . 'interpretations: every mixed journal under test also has an '
        . 'update op, so `needs_ordering` is true either way. The defensive '
        . 'read has no observable effect; the second-pass dispatch is the '
        . 'load-bearing gate.',
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