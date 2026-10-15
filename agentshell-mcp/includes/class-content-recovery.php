<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Recovery for an expired transaction that holds journalled work.
 *
 * ## The problem
 *
 * `Transaction_Manager::reclaim()` deletes an expired transaction when it holds
 * nothing durable, and refuses to delete one that does. With a journal present
 * the second case is no longer hypothetical, and a refusal is only a useful
 * behaviour if something can follow it: without this class an expired
 * journalled transaction wedges begin() permanently, which is the outage the
 * lease was written to end.
 *
 * So this class is the other half of the safeguard. It is deliberately the
 * *only* way a journalled transaction's row is ever removed, and it removes
 * that row as its last action — after everything it is responsible for has
 * been settled. Every early exit leaves the journal exactly where it was.
 *
 * ## What it will and will not touch
 *
 * One operation type: a draft this transaction created. For that operation the
 * inverse is `wp_delete_post( $id, true )`, which §3.2.2 rates Exact because
 * the object being destroyed never belonged to anybody else and was never
 * published. Everything else in the design — updates, navigation, taxonomy,
 * permanent deletion — has no before-image contract yet, and an operation type
 * not in `Content_Journal::SUPPORTED_OPERATIONS` is refused here with a
 * message naming it, not skipped and not approximated.
 *
 * ## Why it deletes nothing without identifying
 *
 * Compensation is destructive, so it is gated on evidence that comes from
 * outside the journal:
 *
 *   - the post must carry the operation marker, which is the one value both
 *     the journal and the post were written with;
 *   - its type, status and slug must match what the journal recorded;
 *   - if the journal recorded a modification time, that must match too, which
 *     is what catches somebody having edited the draft since.
 *
 * Any mismatch is a conflict: reported, journalled as a conflict, and left
 * alone. A recovery that guessed would eventually delete somebody's page.
 *
 * ## The claim
 *
 * A journal operation is claimed by a compare-and-swap on the transaction row
 * before anything is deleted. Two callers that both read the same row compute
 * the same claim and both try to write it; the swap lets exactly one land. That
 * is the whole concurrency control — there is no second lock to keep in step
 * with the first.
 *
 * A claim is a lease too, and short. A caller that dies mid-compensation
 * leaves the operation in `compensating`, and the claim's own timestamp is what
 * lets a later caller take it over. Until then a second caller is told what is
 * happening rather than duplicating a destructive step.
 */
class Content_Recovery {

    /**
     * How long a compensation claim is honoured before another actor may take
     * it over.
     *
     * Short, because the thing it guards is a single `wp_delete_post()` and a
     * journalled state transition — seconds of work — and a long claim would
     * turn one crashed recovery into a long site-wide block on a transaction
     * slot. Long enough that two ordinary concurrent callers do not overlap.
     */
    const CLAIM_TTL_SECONDS = 120;

    const STATUS_REFUSED  = 'refused';
    const STATUS_CONTENDED = 'contended';
    const STATUS_PARTIAL  = 'partial';
    const STATUS_RECOVERED = 'recovered';
    const STATUS_NOTHING   = 'nothing_to_do';

    // ── Authorisation ──────────────────────────────────────────────────────

    /**
     * May this actor resolve this transaction? Returns a role, or ''.
     *
     * Two ways to be allowed, and they are not the same:
     *
     *   - `owner` — the transaction row's own `actor`. Unchanged from commit
     *     and rollback: an owner who lost their session mid-write can clean up
     *     their own mess without asking anyone. The capability is intentionally
     *     NOT checked here: a session-revoked owner still needs a path to
     *     settle their own transaction, and the destructive re-check below
     *     (`authorize_destruction`) is the moment the per-post cap is
     *     consulted, not here.
     *   - `recovery_administrator` — somebody else, and only with two things
     *     true at once: the capability, and an explicit opt-in argument on the
     *     call. The capability is the same `manage_options` every MCP tool
     *     already requires, so on its own it discriminates nothing. Requiring
     *     the caller to also say "yes, I mean to resolve someone else's
     *     transaction" is what makes the act deliberate rather than a
     *     side effect of a session that happened to hold the right cookie.
     *
     * Inspection is not here, and deliberately so: reading the journal is free
     * of consequences, so it is not gated on being allowed to act. The
     * read-side gate is `Content_Journal::authorize_inspection()`.
     */
    public static function authorize( array $tx, $actor, $explicitly_authorized = false ) {
        $owner = isset( $tx['actor'] ) ? (string) $tx['actor'] : '';
        if ( '' !== $owner && $owner === (string) $actor ) {
            return 'owner';
        }
        if ( $explicitly_authorized
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' ) ) {
            return 'recovery_administrator';
        }
        return '';
    }

    /**
     * May this actor *destroy* the post this op names? Returns a role, or ''.
     *
     * Distinct from `authorize()` because destruction is gated on the
     * per-post `delete_post` capability at the moment of the destructive
     * call — not on stored actor identity, and not on the lease-time check
     * the tool boundary did. A user whose role was revoked since the
     * transaction opened can still own the row (and call rollback to
     * discard their own draft) but cannot destroy a post whose
     * `delete_post` cap they no longer hold.
     *
     * Two ways to be allowed:
     *
     *   - `owner` — the transaction row's own `actor` *and* the per-post
     *     `current_user_can( 'delete_post', $post_id )`. The per-post check
     *     is the load-bearing one: it is what stops a demoted actor from
     *     deleting content they used to be able to delete.
     *   - `recovery_administrator` — the existing rule, preserved: an
     *     explicit opt-in *and* `manage_options`. The point of this path
     *     is exactly to compensate when the owner no longer can, so no
     *     per-post cap is required.
     *
     * Called at the tool boundary *and* re-called inside `compensate()`
     * immediately before each `wp_delete_post()`. The first call gates the
     * operation; the second is the one that destroys.
     *
     * @param array      $tx                     The stored row.
     * @param string     $actor                  The acting actor.
     * @param bool       $explicitly_authorized  Opt-in for a non-owner.
     * @param int|null   $post_id                The post the cap is checked on.
     *                                           Null for the tool-boundary
     *                                           call (no specific post yet).
     */
    public static function authorize_destruction( array $tx, $actor, $explicitly_authorized = false, $post_id = null ) {
        $owner = isset( $tx['actor'] ) ? (string) $tx['actor'] : '';
        if ( '' !== $owner
            && $owner === (string) $actor
            && ( null === $post_id
                || ( function_exists( 'current_user_can' )
                    && current_user_can( 'delete_post', (int) $post_id ) ) ) ) {
            return 'owner';
        }
        if ( $explicitly_authorized
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' ) ) {
            return 'recovery_administrator';
        }
        return '';
    }

    // ── Entry point ────────────────────────────────────────────────────────

    /**
     * Resolve an expired journalled transaction.
     *
     * Returns a report. Never throws for a refusal: a refusal is a result the
     * caller needs the contents of, and an exception would make "somebody else
     * owns this" indistinguishable from "the database is broken".
     *
     * @param array       $tx                     The stored row.
     * @param string|null $actor                  Defaults to the current actor.
     * @param bool        $explicitly_authorized  Opt-in for a non-owner.
     */
    public static function recover( array $tx, $actor = null, $explicitly_authorized = false, $allow_live = false, $skip_row_delete = false ) {
        $actor  = ( null === $actor ) ? Store::actor() : (string) $actor;
        $report = array(
            'transaction_id'          => isset( $tx['id'] ) ? (string) $tx['id'] : '',
            'transaction_owner'       => isset( $tx['actor'] ) ? (string) $tx['actor'] : '',
            'actor'                   => $actor,
            'authorized_as'           => '',
            'actor_capable'           => false,
            'status'                  => self::STATUS_REFUSED,
            'reason'                  => '',
            'operations'              => array(),
            'deleted_transactions'    => array(),
            'conflicts'               => array(),
            'transaction_row_deleted' => false,
            'integrity'               => 'unverified',
            'integrity_note'          => '',
            'supported_operations'    => Content_Journal::SUPPORTED_OPERATIONS,
        );

        // ── 1. Who may do this ───────────────────────────────────────────
        $role = self::authorize( $tx, $actor, $explicitly_authorized );
        if ( '' === $role ) {
            $report['reason'] = 'Transaction ' . $report['transaction_id'] . ' belongs to '
                . ( '' === $report['transaction_owner'] ? '(nobody recorded)' : $report['transaction_owner'] )
                . ' and you are ' . $actor . '. Recovery of another actor\'s transaction requires '
                . 'manage_options and an explicit authorisation.';
            self::audit( $report );
            return $report;
        }
        $report['authorized_as'] = $role;

        // ── 2. Only an expired transaction is recovered ───────────────────
        // A live transaction is not an incident; its owner should commit or
        // roll it back. Refusing here is also what stops recovery from ever
        // reaching a transaction that somebody else has just opened.
        //
        // The auto-compensate rollback calls recover() with $allow_live = true
        // so the per-op settlement loop can run on the owner's live
        // transaction without forcing the lease-clock detour.
        if ( ! $allow_live && ! Transaction_Manager::is_expired( $tx ) ) {
            $report['reason'] = 'Transaction ' . $report['transaction_id'] . ' has '
                . Transaction_Manager::seconds_remaining( $tx ) . 's of lease left, so it is not an abandoned '
                . 'transaction and is not recovered. Its owner should commit or roll it back.';
            self::audit( $report );
            return $report;
        }

        // ── 3. Is there a journal to resolve? ────────────────────────────
        $content = Content_Journal::of( $tx );
        if ( empty( $content['ops'] ) ) {
            $report['status']  = self::STATUS_NOTHING;
            $report['reason']  = 'Transaction ' . $report['transaction_id'] . ' holds no content journal, so there '
                . 'is nothing to recover. Its staged configuration was never written to the database and is gone.';
            self::audit( $report );
            return $report;
        }

        // ── 4. Can the journal be read, and can it be trusted ────────────
        $integrity = Content_Journal::verify( $content );
        $report['integrity']      = $integrity['status'];
        $report['integrity_note'] = $integrity['reason'];
        if ( 'tampered' === $integrity['status'] ) {
            $report['reason'] = $integrity['reason'];
            self::audit( $report );
            return $report;
        }

        $problems = Content_Journal::validate( $content, $tx );
        if ( $problems ) {
            $report['reason'] = 'The journal in transaction ' . $report['transaction_id']
                . ' is not readable, so nothing was done with it: ' . implode( ' ', $problems );
            self::audit( $report );
            return $report;
        }

        // ── 5. Settle each operation, then and only then free the slot ────
        $working = $tx;
        $settled = 0;
        $owed    = 0;

        // Compensation order matters when the journal carries both
        // OP_UPDATE_DRAFT and OP_CREATE_DRAFT ops. An update's inverse is
        // `wp_update_post` (apply the before-image); a create's inverse is
        // `wp_delete_post`. Once the create's inverse has run, the post is
        // gone and the update inverses have nothing to act on. Process
        // updates first, in reverse chronological order, and the create
        // last. For a journal with only one op type, the order is a no-op.
        $ordered_ops = self::order_ops_for_compensation( $content['ops'] );

        foreach ( $ordered_ops as $op_id => $op ) {
            $step = self::settle_operation( $working, (string) $op_id, $actor, $explicitly_authorized, $report );
            if ( self::STATUS_CONTENDED === $step['status'] ) {
                // Somebody else moved the row while we held a decision about
                // it. Stop rather than re-read and continue: the winner is
                // mid-compensation with its own view of what is owed, and two
                // recoveries interleaving over a destructive step is precisely
                // what the claim exists to prevent.
                $report['status']       = self::STATUS_CONTENDED;
                $report['reason']       = 'Transaction ' . $report['transaction_id'] . ' changed while recovery was '
                    . 'working on it, so this recovery stopped without deleting anything. The journal is intact; '
                    . 'read agentshell_get_journal and run recovery again.';
                $report['operations'][] = $step['report'];
                self::audit( $report );
                return $report;
            }
            $working                        = $step['tx'];
            $report['operations'][]         = $step['report'];
            if ( ! empty( $step['report']['deleted_post_id'] ) ) {
                $report['deleted_transactions'][] = (int) $step['report']['deleted_post_id'];
                // A deletion that actually ran means the per-post cap was held
                // at the moment of destruction (the gate inside compensate()
                // would have refused otherwise). Mark the report so audit can
                // record the gate held.
                $report['actor_capable'] = true;
            }
            if ( 'compensated' === $step['report']['outcome'] || 'already_settled' === $step['report']['outcome'] ) {
                $settled++;
            } else {
                $owed++;
            }
            if ( ! empty( $step['conflict'] ) ) {
                $report['conflicts'][] = $step['conflict'];
            }
        }

        $final = Content_Journal::of( $working );

        // ── 6. Free the slot, only if nothing is still owed ──────────────
        // The one place a journalled transaction's row is removed. It is last
        // so that every path above leaves the journal behind, and it is a
        // guarded delete so that a transaction opened in the meantime is not
        // the thing that gets deleted.
        if ( Content_Journal::has_outstanding_work( $final ) ) {
            $report['status'] = self::STATUS_PARTIAL;
            $report['reason'] = $settled . ' of ' . ( $settled + $owed ) . ' journalled operations are settled. '
                . $owed . ' still owe attention, so the journal and the transaction row were both kept: '
                . 'begin() will keep refusing until a human resolves what is listed under conflicts. '
                . 'Nothing was discarded to free the slot.';
            self::audit( $report );
            return $report;
        }

        $report['transaction_row_deleted'] = $skip_row_delete
            ? false
            : Transaction_Manager::cas_delete_row( $working );
        if ( ! $skip_row_delete && ! $report['transaction_row_deleted'] ) {
            // The row is not what we read. Somebody opened a new transaction in
            // between — which cannot happen through begin(), so this means an
            // out-of-band write — and we did not touch it.
            $report['status'] = self::STATUS_CONTENDED;
            $report['reason'] = 'Every journalled operation was settled, but the transaction row changed before it '
                . 'could be removed, so it was left alone. Read agentshell_get_transaction.';
            self::audit( $report );
            return $report;
        }

        $report['status'] = self::STATUS_RECOVERED;
        $report['reason'] = 'Transaction ' . $report['transaction_id'] . ' was recovered as ' . $role
            . '. ' . $settled . ' journalled operation(s) settled'
            . ( $report['deleted_transactions']
                ? '; deleted post(s) ' . implode( ', ', $report['deleted_transactions'] )
                : '; no post needed deleting' )
            . '. The transaction row was removed afterwards, and only then.';
        self::audit( $report );
        return $report;
    }

    // ── One operation ──────────────────────────────────────────────────────

    /**
     * Decide whether this actor may claim (or refresh) the op, and what state
     * to leave the claim in.
     *
     * Returns one of:
     *   'refreshed' — the op is in `compensating` and the claim already
     *                  belongs to $actor; the caller should bump the `at`
     *                  to $now and write the row through the CAS.
     *   'claimed'   — the op is unclaimed or its claim is stale; the caller
     *                  should set state=`compensating` and a fresh claim
     *                  and write the row through the CAS.
     *   'contended' — a different actor holds a fresh claim; refuse.
     */
    private static function claim_or_refresh( array $content, $op_id, $actor, $now ) {
        $op    = $content['ops'][ $op_id ] ?? null;
        if ( ! is_array( $op ) ) {
            return 'contended';
        }
        $claim = is_array( $op['claim'] ) ? $op['claim'] : array();
        $mine  = isset( $claim['actor'] ) && (string) $claim['actor'] === (string) $actor;
        $stale = empty( $claim['at'] ) || ( $now - (int) $claim['at'] ) >= self::CLAIM_TTL_SECONDS;

        if ( Content_Journal::STATE_COMPENSATING === ( $op['state'] ?? '' ) && $mine && ! $stale ) {
            return 'refreshed';
        }
        if ( Content_Journal::STATE_COMPENSATING === ( $op['state'] ?? '' ) && ! $mine && ! $stale ) {
            return 'contended';
        }
        return 'claimed';
    }

    /**
     * Claim, compensate and settle a single journalled operation.
     *
     * @return array {tx, report, conflict, status}
     */
    private static function settle_operation( array $tx, $op_id, $actor, $explicitly_authorized, array $report ) {
        $content = Content_Journal::of( $tx );
        $op      = $content['ops'][ $op_id ] ?? null;
        if ( ! is_array( $op ) ) {
            return self::contended( array(
                'id'      => $op_id,
                'outcome' => 'unknown_operation',
                'detail'  => 'the journal no longer holds this operation',
            ) );
        }

        $base = array(
            'id'      => $op_id,
            'type'    => (string) $op['op'],
            'state'   => (string) $op['state'],
            'outcome' => '',
            'detail'  => '',
            'deleted_post_id' => 0,
        );

        // Already terminal: a repeat recovery says so and touches nothing.
        // This is what makes recovery repeatable, and it is why the "already
        // compensated" case needs no special handling anywhere above.
        if ( Content_Journal::STATE_COMPENSATED === $op['state'] ) {
            $base['outcome'] = 'already_settled';
            $base['detail']  = 'already compensated — nothing done';
            return array( 'tx' => $tx, 'report' => $base, 'conflict' => null, 'status' => self::STATUS_RECOVERED );
        }

        // Already in conflict. Recovery will not retry it on its own — a
        // diverged post is a question for a human — but it is not settled, so
        // the row stays and the conflict is re-reported rather than swallowed.
        // Silently reporting this as "nothing left to do" is what would let a
        // later begin() discard the record of the unresolved conflict.
        if ( Content_Journal::STATE_CONFLICT === $op['state'] ) {
            $reason = is_array( $op['comp'] ) && ! empty( $op['comp']['reason'] )
                ? (string) $op['comp']['reason']
                : 'the reason was not recorded';
            $base['outcome']        = 'conflict';
            $base['detail']         = 'already in conflict from an earlier recovery — nothing done. ' . $reason;
            $base['deleted_post_id'] = 0;
            return array(
                'tx'      => $tx,
                'report'  => $base,
                'conflict'=> array(
                    'operation'   => $op_id,
                    'type'        => (string) $op['op'],
                    'reason'      => $reason,
                    'post_id'     => (int) ( is_array( $op['comp'] ) ? ( $op['comp']['post_id'] ?? 0 ) : 0 ),
                    'recorded_at' => (int) ( is_array( $op['comp'] ) ? ( $op['comp']['at'] ?? 0 ) : 0 ),
                    'pre-existing'=> true,
                ),
                'status'  => self::STATUS_RECOVERED,
            );
        }

        // ── the claim ─────────────────────────────────────────────────────
        // claim_or_refresh() returns one of:
        //   'refreshed' — we already own the claim; its `at` is bumped to now
        //                  so the takeover window opens only between settle_operation
        //                  calls, not during a long-running destructive step.
        //   'claimed'   — fresh claim, written into the row.
        //   'contended' — a different actor holds a fresh claim; refuse.
        $now          = Transaction_Manager::lease_now();
        $claim_result = self::claim_or_refresh( $content, $op_id, $actor, $now );
        if ( 'contended' === $claim_result ) {
            $base['outcome'] = 'another_recovery_in_progress';
            $base['detail']  = 'claimed by ' . (string) ( $op['claim']['actor'] ?? '?' ) . ' at '
                . gmdate( 'Y-m-d H:i:s', (int) ( $op['claim']['at'] ?? 0 ) ) . ' UTC, '
                . max( 0, self::CLAIM_TTL_SECONDS - ( $now - (int) ( $op['claim']['at'] ?? 0 ) ) ) . 's ago. Not touched.';
            return array( 'tx' => $tx, 'report' => $base, 'conflict' => null, 'status' => self::STATUS_PARTIAL );
        }

        if ( 'refreshed' === $claim_result ) {
            // Refresh: the claim was ours, the `at` is now. Write the refreshed
            // claim with the same CAS so a concurrent swap (e.g. takeover
            // between settle calls) cannot be silently overridden.
            $claimed_content                       = $content;
            $claimed_content['ops'][ $op_id ]['claim']       = array( 'actor' => $actor, 'at' => $now );
            $claimed_content['ops'][ $op_id ]['updated_at'] = $now;
            $claimed_content['sig']                = Content_Journal::sign( $claimed_content );
            $claimed                 = $tx;
            $claimed[ Content_Journal::CONTENT_KEY ] = $claimed_content;
            if ( ! Transaction_Manager::cas_write_row( $tx, $claimed ) ) {
                return self::contended( array_merge( $base, array(
                    'outcome' => 'lost_race',
                    'detail'  => 'another caller changed the journal while we were refreshing the claim; nothing was deleted',
                ) ) );
            }
        } else {
            $claimed_content                       = $content;
            $claimed_content['ops'][ $op_id ]['state']       = Content_Journal::STATE_COMPENSATING;
            $claimed_content['ops'][ $op_id ]['claim']       = array( 'actor' => $actor, 'at' => $now );
            $claimed_content['ops'][ $op_id ]['updated_at'] = $now;
            $claimed_content['sig']                = Content_Journal::sign( $claimed_content );

            $claimed                 = $tx;
            $claimed[ Content_Journal::CONTENT_KEY ] = $claimed_content;

            if ( ! Transaction_Manager::cas_write_row( $tx, $claimed ) ) {
                return self::contended( array_merge( $base, array(
                    'outcome' => 'lost_race',
                    'detail'  => 'another caller changed the journal first; nothing was deleted',
                ) ) );
            }
        }

        // ── the compensation itself ──────────────────────────────────────
        $claimed_op      = $claimed_content['ops'][ $op_id ];
        $result          = self::compensate( $claimed_op, $claimed, $actor, $explicitly_authorized );
        $base['outcome'] = $result['outcome'];
        $base['detail']  = $result['reason'];

        $conflict = ( 'conflict' === $result['outcome'] ) ? array(
            'operation'  => $op_id,
            'type'       => (string) $claimed_op['op'],
            'reason'     => $result['reason'],
            'post_id'    => (int) $result['post_id'],
            'recorded_at'=> $now,
        ) : null;

        // ── settle: the state transition is the record of what happened ───
        $done_content = $claimed_content;
        $done_content['ops'][ $op_id ]['claim']       = null;
        $done_content['ops'][ $op_id ]['updated_at'] = Transaction_Manager::lease_now();
        $done_content['ops'][ $op_id ]['comp']        = array(
            'outcome'  => $result['outcome'],
            'post_id'  => (int) $result['post_id'],
            'actor'    => $actor,
            'at'       => Transaction_Manager::lease_now(),
            'reason'   => $result['reason'],
        );

        if ( 'compensated' === $result['outcome'] ) {
            $done_content['ops'][ $op_id ]['state'] = Content_Journal::STATE_COMPENSATED;
            $base['deleted_post_id'] = (int) $result['post_id'];
        } elseif ( 'conflict' === $result['outcome'] ) {
            $done_content['ops'][ $op_id ]['state'] = Content_Journal::STATE_CONFLICT;
            $done_content['ops'][ $op_id ]['notes'][] = $result['reason'];
        } else {
            // `delete_failed` and `nothing_recorded` both leave the operation
            // in `compensating` with the claim released, so the next recovery
            // picks it up immediately rather than waiting out a lease that no
            // longer belongs to anyone.
            $done_content['ops'][ $op_id ]['state'] = Content_Journal::STATE_COMPENSATING;
            $done_content['ops'][ $op_id ]['notes'][] = $result['reason'];
        }
        $done_content['sig'] = Content_Journal::sign( $done_content );

        $done                 = $claimed;
        $done[ Content_Journal::CONTENT_KEY ] = $done_content;

        if ( ! Transaction_Manager::cas_write_row( $claimed, $done ) ) {
            return self::contended( array_merge( $base, array(
                'outcome' => 'lost_race',
                'detail'  => $base['detail'] . ' — but the journal moved before the outcome could be recorded',
            ) ), $conflict );
        }

        if ( 'compensated' === $result['outcome'] ) {
            Store::record_event( $actor, 'transaction_recovery_compensated', array(
                'transaction_id' => $report['transaction_id'],
                'operation'      => $op_id,
                'operation_type' => (string) $claimed_op['op'],
                'post_id'        => (int) $result['post_id'],
                'post_type'      => (string) ( $claimed_op['target']['post_type'] ?? '' ),
            ) );
        }

        return array(
            'tx'      => $done,
            'report'  => $base,
            'conflict'=> $conflict,
            'status'  => self::STATUS_RECOVERED,
        );
    }

    // ── Compensation ───────────────────────────────────────────────────────

    /**
     * Revalidate an `applied` operation against the current WP state, for a
     * commit.
     *
     * The recovery engine is the only place destructive action is allowed;
     * commit must not delete anything, but it must know whether the post the
     * journal still names is actually the one it would be committing. This
     * is the read-only counterpart to `verify_against_after()`.
     *
     * Stage 2a (revised) — head-pointer model. The check uses the
     * per-post head after-image, not the op's own `after`. For a chain
     * [create, update1, update2] the head is update2's after; the create
     * op's per-op after is the original creation state, which is stale
     * once any update has been applied. Verifying against the head is what
     * makes the chain "the post is in the state the latest update said it
     * was in".
     *
     * Returns one of three shapes:
     *   - `''` (empty string): the live post matches every recorded field;
     *     the op may stay `applied` and the commit may proceed.
     *   - `'gone'`: `get_post( $post_id )` returned null; the post has been
     *     deleted by an outside actor. The op transitions to `compensated`
     *     and the commit may proceed (recording
     *     `transaction_committed_after_compensation`).
     *   - a string mismatch reason: a divergence between the live post and
     *     the head's recorded after-image. The op transitions to
     *     `conflict`, the post is preserved, and the commit refuses.
     *
     * The op's `state` is not changed here; the caller writes the new state
     * through the journal CAS, the same way recovery does. The contract is
     * "what does the live state say", not "what should the row say".
     */
    public static function revalidate_applied_op_for_commit( array $op, $tx = null ) {
        $post_id = (int) ( $op['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            // An applied op without a post id is a half-applied op: the
            // mark_applied() CAS failed and the op ended up in
            // `partial-applied` (or a stale `applied` from an earlier build).
            // Either way, there is no row to revalidate against, so the
            // commit must refuse — the op is not safe to settle without a
            // recorded id.
            return 'op has no post id recorded; it cannot be revalidated against current WP state';
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            return 'gone';
        }
        $marker = self::marker_of( $op );

        // Prefer the head after-image (the chain's expected current state)
        // when one is available. For the very first applied op of a freshly
        // created post the head equals $op['after']; for any subsequent
        // applied op (any update in a chain) the head is the latest
        // applied op's after, not the op being revalidated.
        $head = is_array( $tx ) ? Content_Journal::head_after( $tx, $post_id ) : null;
        if ( ! is_array( $head ) || empty( $head ) ) {
            $head = is_array( $op['after'] ?? null ) ? $op['after'] : array();
        }
        $mismatch = self::verify_against_after( $head, $post, $marker );
        if ( '' !== $mismatch ) {
            return $mismatch;
        }
        return '';
    }

    /**
     * Order operations for compensation.
     *
     * The only ordering requirement is that the OP_CREATE_DRAFT op (whose
     * inverse is `wp_delete_post`) must be the last op processed when the
     * journal also carries OP_UPDATE_DRAFT ops for the same post. The
     * update ops are mutually independent — each one carries its own
     * before-image — so the order within the update group does not matter
     * for correctness, but processing them in reverse chronological order
     * means the most recent edit is reverted first, which matches what an
     * operator reading the audit log would expect.
     *
     * A journal containing only OP_CREATE_DRAFT ops is returned unchanged
     * (with the same keys, so downstream `ksort` semantics are preserved).
     *
     * @param array<string,array> $ops The journal's operations map.
     * @return array<string,array> Re-keyed in compensation order.
     */
    private static function order_ops_for_compensation( array $ops ) {
        if ( count( $ops ) <= 1 ) {
            return $ops;
        }
        $has_update = false;
        $has_create = false;
        foreach ( $ops as $op ) {
            $type = isset( $op['op'] ) ? (string) $op['op'] : '';
            if ( Content_Journal::OP_UPDATE_DRAFT === $type ) {
                $has_update = true;
            } elseif ( Content_Journal::OP_CREATE_DRAFT === $type ) {
                $has_create = true;
            }
        }
        if ( ! ( $has_update && $has_create ) ) {
            // One op type only: original key order is fine.
            return $ops;
        }

        $updates = array();
        $creates = array();
        foreach ( $ops as $op_id => $op ) {
            $type = isset( $op['op'] ) ? (string) $op['op'] : '';
            if ( Content_Journal::OP_UPDATE_DRAFT === $type ) {
                $updates[ (string) $op_id ] = $op;
            } else {
                $creates[ (string) $op_id ] = $op;
            }
        }
        // Reverse chronological: most recent first. `recorded_at` is set by
        // record_intent() to a monotonic transaction clock, so sorting on it
        // matches the operator's mental model of "last edit first".
        uasort( $updates, function ( $a, $b ) {
            $ra = isset( $a['recorded_at'] ) ? (int) $a['recorded_at'] : 0;
            $rb = isset( $b['recorded_at'] ) ? (int) $b['recorded_at'] : 0;
            return $rb <=> $ra;
        } );
        return $updates + $creates;
    }

    /**
     * Undo one journalled operation, or say why it cannot be undone.
     *
     * The return shape is fixed — `outcome`, `post_id`, `reason` — because
     * every outcome has to be recordable, and a reason is the only thing that
     * makes a refusal actionable by a human later.
     *
     * Stage 2a (revised) — head-pointer model.
     *
     * The journal is a chain of authorised state transitions for the post,
     * not a log of independent actions. The head of the chain — the most
     * recently applied op's after-image — is the expected current state of
     * the post. Compensation verifies the live post against the head, and
     * on success deletes the post once. It never reverts individual
     * updates with `wp_update_post()`.
     *
     * For OP_CREATE_DRAFT: this is the operation that actually deletes the
     * post, after verifying the live post against the head. The head is
     * used (not $op['after']) so that the create op's compensation is
     * checking the current expected state, not the original creation
     * state — which would be stale once any update has been applied.
     *
     * For OP_UPDATE_DRAFT: there is no destructive inverse. The update is
     * part of the chain; the create op's compensation deletes the post.
     * This method records the op as compensated and explains why nothing
     * was done here.
     *
     * @param array    $op             The journalled operation.
     * @param array    $tx             The current transaction row, used to
     *                                 read the per-post head after-image.
     * @param string   $current_actor  The actor who triggered this
     *                                 compensation.
     * @param bool     $explicitly_authorized  Pass-through of the
     *                                 tool-boundary opt-in.
     */
    private static function compensate( array $op, array $tx, $current_actor = '', $explicitly_authorized = false ) {
        $type = (string) $op['op'];

        if ( Content_Journal::OP_UPDATE_DRAFT === $type ) {
            return self::compensate_update( $op, $tx, $current_actor, $explicitly_authorized );
        }

        if ( ! in_array( $type, Content_Journal::SUPPORTED_OPERATIONS, true ) ) {
            return array(
                'outcome' => 'conflict',
                'post_id' => (int) $op['post_id'],
                'reason'  => 'Operation type "' . $type . '" has no compensation contract in this build '
                    . '(supported: ' . implode( ', ', Content_Journal::SUPPORTED_OPERATIONS )
                    . '). It is not guessed at and not deleted; a human must decide.',
            );
        }

        $marker  = self::marker_of( $op );
        $post_id = (int) $op['post_id'];

        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                // Already gone. Compensation is a no-op rather than a failure:
                // the end state this operation wanted has been reached by
                // something else, and there is nothing left to undo.
                return array(
                    'outcome' => 'compensated',
                    'post_id' => $post_id,
                    'reason'  => 'post ' . $post_id . ' was already gone; nothing to delete',
                );
            }
        } else {
            // No id was ever learned — a crash between recording the intent and
            // mark_applied(). The journal cannot say which post it made, so the
            // post has to be found by the marker that was written with it.
            //
            // When the journal has no strong after-image, the recovery engine
            // cannot prove the post is unchanged — there is no content hash,
            // no creation-time anchor — and the engine refuses to delete by
            // guess. The marker-found branch preserves the post as a
            // conflict; a recovery_administrator must reconcile.
            $found = self::posts_carrying_marker( $marker );
            if ( count( $found ) > 1 ) {
                return array(
                    'outcome' => 'conflict',
                    'post_id' => 0,
                    'reason'  => count( $found ) . ' posts (' . implode( ', ', $found )
                        . ') carry this operation\'s marker, so which one this transaction created cannot be told.',
                );
            }
            if ( 1 === count( $found ) ) {
                $found_id = (int) $found[0];
                $found_post = get_post( $found_id );
                if ( ! $found_post ) {
                    return array(
                        'outcome' => 'compensated',
                        'post_id' => $found_id,
                        'reason'  => 'the marked post ' . $found_id . ' is already gone; nothing to delete',
                    );
                }
                return array(
                    'outcome' => 'conflict',
                    'post_id' => $found_id,
                    'reason'  => 'this operation\'s post_id was never recorded, so the post\'s content cannot be '
                        . 'verified against the operation that created it. The marked post (id ' . $found_id
                        . ') is preserved.',
                );
            } else {
                // No marker anywhere. Either the write never happened, or it
                // happened and the marker was removed since. Those are
                // indistinguishable from here, so the second is looked for
                // rather than assumed away: a draft of the recorded type and
                // slug that is *not* marked may be our orphan, and it may be
                // somebody's page. Neither is deleted by a guess.
                $lookalikes = self::posts_matching_target( $op );
                if ( ! empty( $lookalikes ) ) {
                    return array(
                        'outcome' => 'conflict',
                        'post_id' => 0,
                        'reason'  => 'No post carries this operation\'s marker, but post(s) '
                            . implode( ', ', $lookalikes ) . ' match the recorded type and slug '
                            . '(' . (string) ( $op['target']['post_type'] ?? '?' ) . '/'
                            . (string) ( $op['target']['post_name'] ?? '?' ) . '). Either this '
                            . 'transaction\'s marker was removed, or these are unrelated posts. '
                            . 'Nothing was deleted.',
                    );
                }
                return array(
                    'outcome' => 'compensated',
                    'post_id' => 0,
                    'reason'  => 'no post carries this operation\'s marker and none matches its recorded type and '
                        . 'slug, so there is no evidence the write ever happened; nothing to delete',
                );
            }
        }

        // The OCC check uses the head (the most recently applied op's
        // after-image for this post) rather than the per-op after. The head
        // is the chain's expected current state. For a create-only journal
        // it equals the create op's after; for a create+updates journal it
        // is the latest update's after. The strong evidence (post_type,
        // post_status, post_name, post_date_gmt, post_content_sha256,
        // post_content_length) is checked, and post_modified_gmt is a fast
        // pre-check.
        $head = Content_Journal::head_after( $tx, $post_id );
        if ( ! is_array( $head ) || empty( $head ) ) {
            // No head recorded for this post. This should be impossible
            // for a create op whose mark_applied ran (mark_applied is the
            // only writer of the head), but a crash between record_intent
            // and mark_applied could leave a record with post_id=0 set
            // some other way. The branch above handles post_id=0; this
            // branch handles the "we have a post_id but no head" case.
            // Refuse to delete by guess.
            return array(
                'outcome' => 'conflict',
                'post_id' => $post_id,
                'reason'  => 'post ' . $post_id . ' is recorded in this operation but the journal carries no '
                    . 'head after-image for it, so the post\'s current state cannot be verified. The draft '
                    . 'is preserved.',
            );
        }
        $mismatch = self::verify_against_after( $head, $post, $marker );
        if ( '' !== $mismatch ) {
            return array(
                'outcome' => 'conflict',
                'post_id' => $post_id,
                'reason'  => $mismatch,
            );
        }

        // Per-post `delete_post` re-check, immediately before destruction.
        //
        // The tool boundary already called authorize_destruction() (or, for
        // older callers, the equivalent authorize() check), but that was
        // before the post id was necessarily known — and a role revoked
        // between tool entry and this point must not result in a delete.
        // Re-checking here makes the per-post cap a property of the
        // destruction, not a property of the call.
        //
        // An actor who passed the tool-boundary check as recovery_administrator
        // and still holds manage_options at the moment of destruction is
        // unchanged. An owner who lost the per-post cap since the row was
        // written is refused here, and the post is preserved.
        //
        // Deliberately not inside the SQL transaction: a session that
        // has lost the cap should not have to wait for the database to
        // agree. The current_user_can() check is the gate; the
        // transaction is atomicity, not authority.
        //
        // The owner path compares $current_actor against the *transaction's*
        // actor (the caller of recover / rollback), not the op's actor
        // (which is whoever initiated the operation at record_intent time
        // and may differ — the common case is a crashed actor whose
        // transaction is being rolled back by the surviving owner).
        $tx_proxy    = array( 'actor' => (string) $current_actor );
        $destruction = self::authorize_destruction( $tx_proxy, $current_actor, $explicitly_authorized, $post_id );
        if ( '' === $destruction ) {
            return array(
                'outcome' => 'conflict',
                'post_id' => $post_id,
                'reason'  => 'post ' . $post_id . ' cannot be destroyed by the current actor: the per-post '
                    . '`delete_post` capability is no longer held. The draft is preserved and the journal '
                    . 'is kept so a recovery_administrator (manage_options + explicit opt-in) can resolve it.',
            );
        }

        // §3.2.2: create-draft is Exact because we created it. Force delete,
        // not the default trash, and the reason matters:
        //
        //   - wp_delete_post()'s default branch TRASHES, gated on
        //     EMPTY_TRASH_DAYS. Trashing would leave a row that is itself
        //     content needing a later cleanup, and wp_trash_post() rewrites the
        //     slug, so the object would not be what the journal described.
        //   - this object was never published and never reachable; it existed
        //     only to be promoted at commit, and commit did not happen.
        //
        // Guarded, so a delete that fails part-way — meta gone, row not — is
        // rolled back and the draft is left whole for the next attempt.
        //
        // The per-post advisory lock serialises destructive compensation
        // across PHP processes: a second recoverer acquiring GET_LOCK on the
        // same name while this one is mid-delete is refused at the lock
        // layer (promptly, with timeout 0) rather than racing the delete. The
        // lock is connection-scoped and is released by the server on
        // connection close (KILL -9, PHP-FPM worker death) — which is what
        // the previous `add_option`-based lock could not guarantee. A
        // failure to acquire is recorded as a `locked` outcome; the post is
        // preserved so the next recoverer can finish the job.
        $acquired = false;
        try {
            $acquired = Recovery_Advisory_Lock::acquire( $post_id );
        } catch ( \RuntimeException $e ) {
            return array(
                'outcome' => 'conflict',
                'post_id' => $post_id,
                'reason'  => $e->getMessage() . ' The draft is preserved and the journal is kept so a '
                    . 'recovery_administrator (manage_options + explicit opt-in) can resolve it on an '
                    . 'engine that supports advisory locks.',
            );
        }
        if ( ! $acquired ) {
            return array(
                'outcome' => 'conflict',
                'post_id' => $post_id,
                'reason'  => 'another recovery holds the per-post advisory lock for post ' . $post_id
                    . '. The draft is preserved; retry once the holder has finished.',
            );
        }

        try {
            $deleted = Content_Write_Guard::run(
                function () use ( $post_id ) {
                    return wp_delete_post( $post_id, true );
                }
            );

            if ( Content_Write_Guard::is_failure( $deleted ) ) {
                return array(
                    'outcome' => 'delete_failed',
                    'post_id' => $post_id,
                    'reason'  => 'wp_delete_post( ' . $post_id . ', true ) failed'
                        . ( is_wp_error( $deleted ) ? ': ' . $deleted->get_error_code() . ' — ' . $deleted->get_error_message() : '' )
                        . '. The write was rolled back, so the post is unchanged and this operation is still owed. '
                        . 'Recovery can be retried.',
                );
            }
        } finally {
            // Implicit release on connection close is what catches KILL -9;
            // this is the orderly path.
            Recovery_Advisory_Lock::release( $post_id );
        }

        return array(
            'outcome' => 'compensated',
            'post_id' => $post_id,
            'reason'  => 'post ' . $post_id . ' was created by this transaction and still matched the chain\'s '
                . 'head after-image (the most recent applied op\'s after); deleted permanently under the '
                . 'per-post advisory lock',
        );
    }

    /**
     * Compensate an OP_UPDATE_DRAFT op: under the head-pointer model, the
     * update has no destructive inverse. The chain of authorised state
     * transitions is rolled back by deleting the post once (via the create
     * op's compensation), and the update op's effect is undone by that
     * single deletion.
     *
     * Why no `wp_update_post( before )`? The previous design reverted each
     * update by writing the before-image back, but `wp_update_post` bumps
     * `post_modified_gmt`, which made the create op's per-op after-image
     * stale and forced a false-positive `conflict` at rollback. The
     * head-pointer model fixes this by having the create op verify against
     * the head (the most recently applied op's after), not against its own
     * per-op after. Each update op is then just a historical state
     * transition; the only inverse that matters is the create op's
     * `wp_delete_post`.
     *
     * The op is recorded as `compensated` with an explanatory reason. No
     * destructive action is taken here; the post is preserved if the create
     * op is later found in `conflict` (and a recovery_administrator must
     * reconcile), and gone if the create op's compensation succeeded. Both
     * outcomes are correct: the chain is settled either way.
     *
     * @param array  $op                    The op record.
     * @param array  $tx                    The current transaction row.
     * @param string $current_actor         The actor who triggered compensation.
     * @param bool   $explicitly_authorized Pass-through of the tool-boundary opt-in.
     * @return array{outcome:string,post_id:int,reason:string}
     */
    private static function compensate_update( array $op, array $tx, $current_actor = '', $explicitly_authorized = false ) {
        $post_id = (int) ( $op['post_id'] ?? 0 );

        if ( $post_id <= 0 ) {
            // An update op without a post id is unrecoverable here: it has
            // nothing to act on, and the create-side partial-applied path
            // does not produce an update op. Refuse and keep the journal so
            // a human can reconcile.
            return array(
                'outcome' => 'conflict',
                'post_id' => 0,
                'reason'  => 'update op ' . (string) ( $op['id'] ?? '' ) . ' has no post_id recorded; the inverse '
                    . 'operation has nothing to act on. The journal is kept so a recovery_administrator '
                    . '(manage_options + explicit opt-in) can resolve it.',
            );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            // Post already gone. The create op's compensation (or an
            // outside actor) has done our work; this update op is settled
            // by the deletion.
            return array(
                'outcome' => 'compensated',
                'post_id' => $post_id,
                'reason'  => 'post ' . $post_id . ' is already gone; the update is settled by the deletion',
            );
        }

        // The post is still here. The update op has no destructive inverse
        // in the head-pointer model: the chain is rolled back by the create
        // op's `wp_delete_post`, not by reverting each update. Mark the op
        // compensated; the create op's compensation in this same settle
        // pass will delete the post. If the create op is in `conflict` (an
        // external edit detected via the head), the post is preserved and a
        // human reconciles; this update op's "compensated" status still
        // reads correctly because the chain is settled (the post is no
        // longer in the state this update recorded).
        return array(
            'outcome' => 'compensated',
            'post_id' => $post_id,
            'reason'  => 'post ' . $post_id . ' is part of the chain this transaction owns; the chain is rolled '
                . 'back by the create op\'s `wp_delete_post`, not by reverting individual updates. No '
                . 'destructive action was taken here.',
        );
    }

    /** The marker value a post must carry for this operation to be recognised. */
    private static function marker_of( array $op ) {
        $from_after = (string) ( $op['after']['op_meta_value'] ?? '' );
        if ( '' !== $from_after ) {
            return $from_after;
        }
        // Older records did not carry it; the id is the same value by
        // construction (see Content_Journal::new_op_id()).
        return (string) ( $op['id'] ?? '' );
    }

    /**
     * Compare the live post against a recorded after-image.
     *
     * Returns '' when the live post matches every recorded field, or a
     * sentence explaining the divergence. The after-image is the
     * journal's "expected current state" — for a tx-owned draft chain,
     * this is the head (the most recently applied op's after-image).
     *
     * The check runs the strong evidence: post_type, post_status,
     * post_name, post_date_gmt, post_content_sha256,
     * post_content_length. The post_modified_gmt is a fast pre-check; the
     * SHA-256 of post_content is the strong evidence (catches same-second
     * edits and content-only edits that leave the slug unchanged).
     *
     * @param array $after  The recorded after-image. May come from
     *                      `$op['after']` (the per-op after) or from
     *                      `Content_Journal::head_after($tx, $post_id)`
     *                      (the head of the post's mutation chain).
     * @param mixed $post   The live `WP_Post` to verify.
     * @param string $marker The marker the live post must carry.
     */
    public static function verify_against_after( array $after, $post, $marker ) {
        $id = (int) ( $post->ID ?? 0 );

        $found_marker = get_post_meta( $id, Content_Journal::OP_META, true );
        if ( (string) $found_marker !== (string) $marker ) {
            return 'post ' . $id . ' does not carry this operation\'s marker (it carries "'
                . (string) $found_marker . '"), so it cannot be shown to be the post this transaction created. '
                . 'Nothing was deleted.';
        }

        foreach ( array( 'post_type', 'post_status', 'post_name' ) as $field ) {
            if ( ! isset( $after[ $field ] ) || '' === $after[ $field ] ) { continue; }
            $have = isset( $post->$field ) ? (string) $post->$field : '';
            if ( $have !== (string) $after[ $field ] ) {
                return 'post ' . $id . ' has ' . $field . ' "' . $have . '" where the journal recorded "'
                    . (string) $after[ $field ] . '". Something other than this transaction changed it. '
                    . 'Nothing was deleted.';
            }
        }

        $recorded_date = (string) ( $after['post_date_gmt'] ?? '' );
        if ( '' !== $recorded_date ) {
            $have = (string) ( $post->post_date_gmt ?? '' );
            if ( $have !== $recorded_date ) {
                return 'post ' . $id . ' has post_date_gmt "' . ( $have ?: '(unknown)' )
                    . '" where the journal recorded "' . $recorded_date . '". A different post or a replaced row '
                    . 'is at this id. Nothing was deleted.';
            }
        }

        $recorded_modified = (string) ( $after['post_modified_gmt'] ?? '' );
        if ( '' !== $recorded_modified ) {
            $have = (string) ( $post->post_modified_gmt ?? '' );
            if ( $have !== $recorded_modified ) {
                return 'post ' . $id . ' was modified at "' . ( $have ?: '(unknown)' )
                    . '" after this transaction recorded "' . $recorded_modified . '". Another actor has '
                    . 'worked on it, so deleting it would destroy their work. Nothing was deleted.';
            }
        }

        $recorded_sha = (string) ( $after['post_content_sha256'] ?? '' );
        if ( '' !== $recorded_sha ) {
            $have_sha = hash( 'sha256', (string) ( $post->post_content ?? '' ) );
            if ( $have_sha !== $recorded_sha ) {
                return 'post ' . $id . ' has a post_content hash "' . $have_sha
                    . '" that does not match the journal\'s recorded "'
                    . $recorded_sha . '". The content has been edited (including edits that leave the slug '
                    . 'unchanged and edits that occur inside one second). Nothing was deleted.';
            }
        }
        $recorded_len = isset( $after['post_content_length'] ) ? (int) $after['post_content_length'] : -1;
        if ( $recorded_len >= 0 ) {
            $have_len = strlen( (string) ( $post->post_content ?? '' ) );
            if ( $have_len !== $recorded_len ) {
                return 'post ' . $id . ' has post_content length ' . $have_len
                    . ' where the journal recorded ' . $recorded_len . '. The post is not the one the journal '
                    . 'hashed. Nothing was deleted.';
            }
        }

        return '';
    }

    /**
     * Compare the live post against what the journal said it would find.
     *
     * This is a thin shim over verify_against_after() that pulls the
     * after-image from the op record. For tx-owned chains, callers should
     * prefer verify_against_after() with the head (the most recently
     * applied op's after-image), so that historical after-images from
     * superseded ops do not cause a false conflict.
     *
     * Returns '' on match, or a sentence explaining the divergence. It
     * never repairs a mismatch and never decides one is fine — a
     * mismatch means a human looks at it.
     */
    private static function verify_post( array $op, $post, $marker ) {
        $after = is_array( $op['after'] ?? null ) ? $op['after'] : array();
        return self::verify_against_after( $after, $post, $marker );
    }

    // ── Evidence ───────────────────────────────────────────────────────────

    /**
     * Post ids whose marker equals $marker.
     *
     * Direct SQL rather than a meta query: this is one indexed equality
     * lookup, and `get_posts()` would add status, type and pagination defaults
     * that recovery does not want — most importantly it would hide a post that
     * has since been trashed, which is precisely the post whose fate recovery
     * needs to know.
     */
    private static function posts_carrying_marker( $marker ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->postmeta ) || '' === $marker ) {
            return array();
        }
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                Content_Journal::OP_META,
                (string) $marker
            )
        );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Post ids of the recorded type and slug, regardless of status.
     *
     * The secondary search for "the write happened but the marker is gone".
     * It answers a question, not performs a deletion: the caller refuses when
     * it finds anything at all.
     */
    private static function posts_matching_target( array $op ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->posts ) ) {
            return array();
        }
        $type = (string) ( $op['target']['post_type'] ?? '' );
        $name = (string) ( $op['target']['post_name'] ?? '' );
        if ( '' === $type || '' === $name ) {
            return array();
        }
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s",
                $type,
                $name
            )
        );
        return array_map( 'intval', (array) $ids );
    }

    // ── Audit ──────────────────────────────────────────────────────────────

    private static function contended( array $report ) {
        $report['outcome'] = 'lost_race';
        return array(
            'tx'       => array(),
            'report'   => $report,
            'conflict' => null,
            'status'   => self::STATUS_CONTENDED,
        );
    }

    /**
     * Write the audit record.
     *
     * Every outcome gets one, including the refusals. A recovery that quietly
     * did nothing looks exactly like a recovery that was never run, and the
     * question somebody asks afterwards — "who deleted that draft, and when?" —
     * is only answerable if the refusal is on the record too. The event names
     * the actor and the role they acted under, so a stranger resolving another
     * actor's transaction is distinguishable in the log from its owner doing so.
     *
     * `actor_capable` records whether the per-post `delete_post` capability
     * was held *at the moment of the destructive call* (i.e. when
     * `authorize_destruction` was re-evaluated inside `compensate()`). A
     * row deleted with `actor_capable: false` would mean the gate was
     * bypassed; the field makes that observable in the audit log.
     */
    private static function audit( array $report ) {
        $operation = ( 'refused' === $report['status'] ) ? 'transaction_recovery_refused' : 'transaction_recovery';

        Store::record_event(
            $report['actor'],
            $operation,
            array(
                'transaction_id'          => $report['transaction_id'],
                'transaction_owner'       => $report['transaction_owner'],
                'authorized_as'           => $report['authorized_as'],
                'actor_capable'           => ! empty( $report['actor_capable'] ),
                'status'                  => $report['status'],
                'reason'                  => $report['reason'],
                'integrity'               => $report['integrity'],
                'deleted_transactions'    => $report['deleted_transactions'],
                'conflicts'               => count( $report['conflicts'] ),
                'transaction_row_deleted' => $report['transaction_row_deleted'],
            )
        );
    }
}