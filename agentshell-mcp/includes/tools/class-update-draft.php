<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_update_draft — update a draft that the current transaction created.
 *
 * Stage 2a. The journal-aware counterpart to `agentshell_update_content`,
 * which refuses inside a transaction. This tool is the *only* path that
 * edits a draft through the content journal: every update is recorded as
 * an OP_UPDATE_DRAFT op whose before-image is what the post looked like
 * before the edit, and whose after-image is the post's state after the
 * edit. Rollback unwinds the chain (most-recent edit first) and then
 * deletes the post via the create op's compensation; commit keeps the
 * final state.
 *
 * Refusal conditions, in evaluation order:
 *
 *   - **Not in a transaction.** Outside a transaction the caller should use
 *     `agentshell_update_content`; this tool refuses with the same uniform
 *     message the other content tools use, and names this tool as the
 *     journal-aware alternative for in-transaction work.
 *   - **No `idempotency_key`.** A schema-level refusal before the tool
 *     runs. The key is the only thing that lets a retry return the same
 *     op id with `resumed: true` instead of creating a divergent chain.
 *   - **The post does not exist, is the wrong type, or is not a draft.**
 *     `agentshell_update_draft` only edits drafts; status transitions
 *     (publish, pending, etc.) are deliberately out of scope and are
 *     refused by name. A status change inside a transaction is a
 *     different lifecycle and would change what "the post is recoverable"
 *     means.
 *   - **The post is not transaction-owned.** The post must carry the
 *     journal's `_agentshell_journal_op` marker, and that marker must
 *     match an OP_CREATE_DRAFT op in the *current* transaction's journal
 *     in `applied` (or `partial-applied`) state. A draft created outside
 *     any transaction, or by a different transaction, is refused — the
 *     journal would otherwise be claiming ownership of a post it cannot
 *     prove it owns, which is the failure mode the marker check exists
 *     to prevent.
 *   - **No actual field to update.** The schema requires at least one
 *     of `title`, `content`, `slug`; an empty call refuses at the schema
 *     level.
 *   - **Title and content both empty.** The same rule the create tool
 *     enforces: a draft with neither title nor content is not a draft
 *     anyone can find.
 *
 * Idempotency
 * -----------
 *
 * A retry that supplies the same `idempotency_key` as a previous
 * OP_UPDATE_DRAFT op in `applied` state with matching
 * (actor, transaction, post_id, content fingerprint) returns the
 * original op with `resumed: true` and does not write to the post.
 * A retry that finds an op in any other state (in-flight, conflict,
 * compensated) is refused with the named reason.
 *
 * The fingerprint is the SHA-256 of the request fields (post_id, type,
 * title, content, slug) excluding the key. A retry with the same key but
 * different content is refused with
 * `transaction_update_draft_idempotency_conflict`; that is the
 * guarantee that a typo on retry cannot silently overwrite the post.
 *
 * Atomicity
 * ---------
 *
 * The post update, the marker preservation (no change), and the
 * `mark_applied()` call all run inside one `Content_Write_Guard::run()`
 * SQL transaction, so a `wp_update_post` failure rolls back and the
 * journal is not advanced. The strong after-image is captured inside
 * the same guard, so `mark_applied` records the post's state as
 * observed at the same instant the write succeeded.
 */
class Update_Draft extends Base_Tool {
    public function get_name() { return 'agentshell_update_draft'; }

    public function get_description() {
        return 'Update the title, content, or slug of a draft that the current transaction created. '
            . 'The one journal-aware update tool; it may only be called inside an open transaction, '
            . 'and only for drafts created by `agentshell_create_draft` in the same transaction. '
            . 'Each call records an OP_UPDATE_DRAFT op whose before-image is the post\'s prior '
            . 'state, so a rollback can unwind the chain and then delete the post, and a commit '
            . 'can keep the final state. An `idempotency_key` is required for retry safety.';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'              => array( 'type' => 'integer', 'description' => 'Post ID of the draft to update. Must be a draft created by the current transaction.' ),
                'type'            => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'description' => 'Post type; must match the existing post.' ),
                'title'           => array( 'type' => 'string', 'description' => 'New title (optional).' ),
                'content'         => array( 'type' => 'string', 'description' => 'New raw HTML content (optional). Replaces the whole body.' ),
                'slug'            => array( 'type' => 'string', 'description' => 'New URL slug (optional).' ),
                'idempotency_key' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                    'description' => 'A stable, client-supplied identifier (e.g. a UUID) for retry safety. '
                        . 'Required inside a transaction. A retry with the same key by the same actor '
                        . 'on the same (post, content) returns the original op with `resumed: true`; '
                        . 'a retry with the same key but different content / actor / transaction / post '
                        . 'is refused. The key is never silently replaced by a server-minted value.',
                ),
            ),
            'required'   => array( 'id', 'type', 'idempotency_key' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'id', 'type', 'idempotency_key' ) );

        $id    = (int) $arguments['id'];
        $type  = (string) $arguments['type'];
        $idem  = (string) $arguments['idempotency_key'];

        // The schema constraint already says 1-128 chars; this is defence in
        // depth that also rejects keys that are not printable ASCII.
        if ( '' === $idem || strlen( $idem ) > 128 || ! preg_match( '/^[\x20-\x7E]+$/', $idem ) ) {
            throw new \InvalidArgumentException(
                'idempotency_key must be 1-128 printable ASCII characters; got: ' . bin2hex( $idem )
            );
        }
        if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }

        // At least one of the supported fields must be requested. A "rename
        // the post but change nothing" call is a no-op masquerading as an
        // update; refusing it at the schema level keeps the journal from
        // accumulating empty records.
        $has_title   = array_key_exists( 'title', $arguments );
        $has_content = array_key_exists( 'content', $arguments );
        $has_slug    = array_key_exists( 'slug', $arguments );
        if ( ! $has_title && ! $has_content && ! $has_slug ) {
            throw new \InvalidArgumentException(
                'Nothing to update — pass at least one of title, content, slug. (The supported fields '
                . 'are deliberately narrower than agentshell_update_content: status, parent and other '
                . 'lifecycle-changing fields are not journal-aware and would change what "recoverable" '
                . 'means.)'
            );
        }

        $title = $has_title   ? (string) $arguments['title']   : null;
        $body  = $has_content ? (string) $arguments['content'] : null;
        $slug  = $has_slug    ? (string) $arguments['slug']    : null;

        if ( null !== $title && null !== $body && '' === trim( $title ) && '' === trim( $body ) ) {
            throw new \InvalidArgumentException(
                "Cannot update {$type} #{$id}: a title and content are both empty, which WordPress would "
                . 'store as an untitled draft rather than a page. Pass a title, content, or both.'
            );
        }

        $tx_mgr = \AgentShell_MCP\Transaction_Manager::instance();
        if ( ! $tx_mgr->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_update_draft refuses outside a transaction. No journal-aware update exists '
                . 'for content outside a transaction. Either open a transaction and call '
                . 'agentshell_create_draft + agentshell_update_draft, or call agentshell_update_content '
                . 'directly for an immediate-write edit (no journal, no recovery).'
            );
        }

        $tx          = $tx_mgr->payload();
        $tx_id       = (string) $tx['id'];
        $actor       = \AgentShell_MCP\Store::actor();
        $fingerprint = self::content_fingerprint( $id, $type, $title, $body, $slug );

        // 1. Idempotency check. Find an op in this journal with the same key.
        $content = \AgentShell_MCP\Content_Journal::of( $tx );
        $existing = self::find_op_by_idempotency_key( $content, $idem );
        if ( null !== $existing ) {
            return self::resolve_idempotency_hit( $existing, $actor, $tx_id, $id, $fingerprint, $idem );
        }

        // 2. Verify the post exists, is of the requested type, is a draft,
        //    and is owned by this transaction.
        $post = get_post( $id );
        if ( ! $post ) {
            throw new \InvalidArgumentException(
                "transaction_update_draft_post_not_found: post #{$id} does not exist; the update tool can "
                . 'only edit posts that exist.'
            );
        }
        if ( (string) $post->post_type !== $type ) {
            throw new \InvalidArgumentException(
                "transaction_update_draft_wrong_type: post #{$id} is of type '" . (string) $post->post_type
                . "', not '{$type}'. The type argument must match the post's actual type."
            );
        }
        if ( 'draft' !== (string) $post->post_status ) {
            throw new \InvalidArgumentException(
                "transaction_update_draft_not_a_draft: post #{$id} has status '"
                . (string) $post->post_status . "', not 'draft'. agentshell_update_draft only edits drafts; "
                . 'a status transition (publish / pending / private / future) is a different lifecycle and '
                . 'is out of scope. Use the corresponding publish / unpublish tool outside a transaction, '
                . 'or commit the transaction first and then publish.'
            );
        }

        $marker = (string) get_post_meta( $id, \AgentShell_MCP\Content_Journal::OP_META, true );
        if ( '' === $marker ) {
            throw new \InvalidArgumentException(
                "transaction_update_draft_not_transaction_owned: post #{$id} does not carry the journal's "
                . '`_agentshell_journal_op` marker, so it cannot be shown to be a draft this transaction '
                . 'created. agentshell_update_draft only edits drafts that the current transaction created '
                . 'via agentshell_create_draft. To edit an unrelated draft, commit or rollback the current '
                . 'transaction and use agentshell_update_content outside it.'
            );
        }
        $owning_create = self::find_owning_create_op( $content, $marker );
        if ( null === $owning_create ) {
            throw new \InvalidArgumentException(
                "transaction_update_draft_not_transaction_owned: post #{$id} carries the journal marker "
                . "'{$marker}', but no OP_CREATE_DRAFT op in the current transaction's journal matches it. "
                . 'The post was not created by this transaction; agentshell_update_draft will not edit it.'
            );
        }
        if ( 'applied' !== (string) ( $owning_create['state'] ?? '' )
            && 'partial-applied' !== (string) ( $owning_create['state'] ?? '' ) ) {
            throw new \InvalidArgumentException(
                "transaction_update_draft_create_state_refused: the OP_CREATE_DRAFT op that owns post #{$id} "
                . 'is in state "' . (string) ( $owning_create['state'] ?? '' ) . '", not applied. The owning '
                . 'create op must be in `applied` (or `partial-applied`) for an update to be recorded; '
                . 'reconcile via agentshell_recover_transaction or rollback first.'
            );
        }

        // OCC pre-check against the head (the most recently applied op's
        // after-image for this post). If the live post has been edited
        // outside the transaction since the last authorised update, the
        // head will not match and the update must refuse — applying the
        // change would silently overwrite an external edit. The head may
        // be null for the first update after a create (in which case the
        // pre-check is skipped; the create op's after-image is the
        // reference).
        $head = \AgentShell_MCP\Content_Journal::head_after( $tx, $id );
        if ( is_array( $head ) && ! empty( $head ) ) {
            $oc_mismatch = \AgentShell_MCP\Content_Recovery::verify_against_after(
                $head,
                $post,
                $marker
            );
            if ( '' !== $oc_mismatch ) {
                throw new \InvalidArgumentException(
                    "transaction_update_draft_external_edit_detected: post #{$id} has been edited since the "
                    . "last authorised update by this transaction. {$oc_mismatch} The update is refused to "
                    . "avoid silently overwriting an external change. Reconcile by inspecting the post and "
                    . "either accepting the external change (rollback or commit) or rolling back the post "
                    . "to the head's recorded state before retrying."
                );
            }
        }

        // 3. Build the before-image from the live post. This is what makes
        //    the inverse operation a `wp_update_post` rather than a guess.
        $before = array(
            'post_title'           => (string) $post->post_title,
            'post_content'         => (string) $post->post_content,
            'post_name'            => (string) $post->post_name,
            'post_content_sha256'  => hash( 'sha256', (string) $post->post_content ),
            'post_content_length'  => strlen( (string) $post->post_content ),
            'post_modified_gmt'    => (string) $post->post_modified_gmt,
            'op_meta_value'        => $marker,
        );

        // 4. Compute the new field values (sanitised the way WordPress would,
        //    so the after-image matches what is actually on disk after
        //    wp_update_post). For unchanged fields, keep the current value.
        $new_title = null !== $title ? sanitize_text_field( $title ) : (string) $post->post_title;
        $new_slug  = null !== $slug
            ? ( '' === $slug ? null : sanitize_title( $slug ) )
            : (string) $post->post_name;
        $new_body  = null !== $body ? (string) $body : (string) $post->post_content;

        // 5. Record the durable intent. attach() to the row first.
        $target = array(
            'post_id'     => $id,
            'post_type'   => $type,
            'post_status' => 'draft',
        );
        $op = \AgentShell_MCP\Content_Journal::record_intent(
            \AgentShell_MCP\Content_Journal::OP_UPDATE_DRAFT,
            $target,
            array(
                'idempotency_key'      => $idem,
                'post_title'           => $new_title,
                'post_name'            => (string) $new_slug,
                'op_meta_value'        => $marker,
                'content_fingerprint'  => $fingerprint,
            ),
            $actor
        );
        // The `before` is the load-bearing piece for compensation. Stamp it
        // on the op before attach().
        $op['before'] = $before;

        if ( ! \AgentShell_MCP\Content_Journal::attach( $tx, $op ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx_id . ' changed while the update intent was being recorded; nothing '
                . 'was written. Read agentshell_get_transaction and retry.'
            );
        }

        // Re-read the row after attach() so the guard closure starts from the
        // post-attach bytes. This is the only honest "row is at the version
        // we just attached" baseline; the pre-attach $tx we have in scope has
        // no `ops[$op_id]` for the subsequent mark_applied() / head CAS to
        // find, and the closure must not pass an obsolete row to either.
        $tx_post_attach = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
        if ( empty( $tx_post_attach ) || empty( $tx_post_attach['content']['ops'][ $op['id'] ] ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx_id . ' is no longer present after the update intent was attached; '
                . 'the row was removed by a competing close. Read agentshell_get_transaction and retry.'
            );
        }

        // 6. Apply the update, capture the strong after-image, advance the
        //    head, mark the op applied — all inside one guarded SQL
        //    transaction. The order is now:
        //      (a) wp_update_post — content mutation
        //      (b) compare_and_set_head — value-guarded CAS that records
        //          the new head *with the post-attach row bytes*. A failed
        //          head CAS throws to roll back the wp_update_post; the
        //          op stays in `intended`.
        //      (c) mark_applied — op-state transition from intended to
        //          applied, using the *post-head-CAS* row bytes. A failed
        //          mark_applied CAS throws to roll back both the head
        //          advance and the wp_update_post.
        //    This is the shared head-pointer protocol that
        //    Stage 2b Commit 1 fixes: head advancement and op-state
        //    transition share one SQL transaction, and the row that
        //    mark_applied writes is the row that compare_and_set_head
        //    produced, never the pre-attach bytes.
        $op_id = $op['id'];
        $postarr = array(
            'ID'           => $id,
            'post_type'    => $type,
            'post_status'  => 'draft',
            'post_title'   => $new_title,
            'post_content' => $new_body,
        );
        if ( null !== $new_slug ) {
            $postarr['post_name'] = (string) $new_slug;
        }

        $result = \AgentShell_MCP\Content_Write_Guard::run( function () use ( $postarr, $id, $tx_post_attach, $op_id, $head ) {
            $upd = wp_update_post( wp_slash( $postarr ), true );
            if ( is_wp_error( $upd ) ) {
                throw new \InvalidArgumentException( 'Could not update post: ' . $upd->get_error_message() );
            }
            if ( ! $upd ) {
                throw new \InvalidArgumentException( 'Could not update post #' . $id . '. WordPress returned no result.' );
            }
            $post = get_post( $id );
            if ( ! $post ) {
                throw new \InvalidArgumentException( 'Could not read back post ' . $id . ' after update.' );
            }
            $after_image = array(
                'post_type'           => (string) $post->post_type,
                'post_status'         => (string) $post->post_status,
                'post_name'           => (string) $post->post_name,
                'post_title'          => (string) $post->post_title,
                'post_date_gmt'       => (string) $post->post_date_gmt,
                'post_modified_gmt'   => (string) $post->post_modified_gmt,
                'post_content_sha256' => hash( 'sha256', (string) $post->post_content ),
                'post_content_length' => strlen( (string) $post->post_content ),
                'op_meta_value'       => (string) get_post_meta( $id, \AgentShell_MCP\Content_Journal::OP_META, true ),
                // Do NOT clobber the idempotency_key / content_fingerprint
                // that record_intent() already wrote: those are the load-
                // bearing pieces for retry-resume. mark_applied() merges
                // $after on top of the existing after, so omitting them
                // here preserves them; including them as '' would silently
                // clear them and break the next retry.
            );
            // Head CAS BEFORE mark_applied. For an update, expected_prev is
            // the head we just verified the live post against ($head, which
            // is null for the first update after a create, or the previous
            // op's after-image for subsequent updates). A `false` return
            // means a concurrent caller has already advanced the head
            // since our OCC pre-check — that means the live post is no
            // longer in the state we just verified. Throw to roll back
            // the wp_update_post; the operator can reconcile.
            $head_after = is_array( $head ) ? $head : null;
            $head_tx    = \AgentShell_MCP\Content_Journal::compare_and_set_head(
                $tx_post_attach, (int) $id, $head_after, $after_image
            );
            if ( ! is_array( $head_tx ) ) {
                throw new \InvalidArgumentException(
                    'compare_and_set_head() refused to advance the head for post ' . (int) $id
                    . ' on update: a concurrent update for the same post has already advanced the '
                    . 'head pointer. The wp_update_post is rolled back. Reconcile by inspecting the '
                    . 'post and re-running the update.'
                );
            }
            // mark_applied now uses the post-head-CAS row bytes. Never pass
            // the pre-attach $tx_post_attach (or the obsolete $tx) here —
            // the value-guarded CAS compares on row bytes, and an obsolete
            // row will always fail the CAS even if the underlying journal
            // content is logically equivalent.
            //
            // A failed mark_applied() is a hard error: the head CAS landed
            // (the head pointer was advanced), but the op-state transition
            // did not. The journal is now in a state where the head is ahead
            // of the op whose mark_applied refused to land — a state the
            // OCC pre-check the next op runs would surface as
            // "head does not match post", and the operator would see a
            // chain-break error on the next call. Throwing rolls back the
            // wp_update_post (in a real SQL transaction; the test stub does
            // not roll back the in-memory mutation, but the journal row is
            // not advanced either, so the post and the journal stay in the
            // same state they were in before the closure ran). The
            // post-CAS re-read below handles the case where the row moved
            // for a *benign* reason (e.g. a recovery call that touched the
            // row); the throw here is the *head-CAS-succeeded-but-mark_applied-failed*
            // case, which is a genuine inconsistency the operator must see.
            $applied_tx = \AgentShell_MCP\Content_Journal::mark_applied( $head_tx, $op_id, $id, $after_image );
            if ( ! is_array( $applied_tx ) ) {
                throw new \InvalidArgumentException(
                    'mark_applied() CAS refused to land the op-state transition for '
                    . 'op ' . $op_id . ' on post ' . (int) $id . ' on update. The head '
                    . 'pointer was advanced (the compare_and_set_head call landed), but '
                    . 'the op was not transitioned to `applied`. The wp_update_post is '
                    . 'rolled back. Reconcile by inspecting the journal and re-running '
                    . 'the update.'
                );
            }
            return array(
                'post_id'        => (int) $id,
                'applied_tx'     => $applied_tx,
                'head_advanced'  => true,
            );
        } );

        $post_id_v     = (int) $result['post_id'];
        $applied_tx    = $result['applied_tx'];

        // 7. CAS-failure re-read: mark_applied() throwing is the only way the
        //    closure can fail to return a row, and that throws to the caller;
        //    a returned row is the success path. The post-CAS re-read path is
        //    no longer needed: a failed head CAS throws and rolls back the
        //    wp_update_post; a failed mark_applied() throws and rolls back
        //    both the head advance and the wp_update_post. The throw carries
        //    the reason to the operator.

        return array(
            'id'              => $post_id_v,
            'type'            => $type,
            'title'           => $new_title,
            'content_length'  => strlen( $new_body ),
            'status'          => 'draft',
            'slug'            => (string) $new_slug,
            'journaled'       => true,
            'journal_applied' => true,
            'recoverable'     => true,
            'op_id'           => $op_id,
            'marker'          => $marker,
            'resumed'         => false,
            'idempotency_key' => $idem,
            'transaction'     => $tx_id,
        );
    }

    /**
     * Stable SHA-256 of the request fields that, if changed, mean a new
     * update. Excludes the idempotency_key itself.
     */
    private static function content_fingerprint( $id, $type, $title, $content, $slug ) {
        $payload = array(
            'id'      => (int) $id,
            'type'    => (string) $type,
            'title'   => null === $title ? null : (string) $title,
            'content' => null === $content ? null : (string) $content,
            'slug'    => null === $slug ? null : (string) $slug,
        );
        return hash( 'sha256', wp_json_encode( $payload ) );
    }

    /**
     * Find an op in the journal whose `after.idempotency_key` matches.
     * Returns the op array, or null.
     */
    private static function find_op_by_idempotency_key( array $content, $key ) {
        if ( empty( $content['ops'] ) ) { return null; }
        foreach ( $content['ops'] as $op_id => $op ) {
            if ( ! is_array( $op ) ) { continue; }
            if ( isset( $op['after']['idempotency_key'] )
                && (string) $op['after']['idempotency_key'] === (string) $key ) {
                return $op;
            }
        }
        return null;
    }

    /**
     * Find the OP_CREATE_DRAFT op whose id equals the post's marker.
     * Returns the op array, or null if no create op in the journal owns
     * the post.
     */
    private static function find_owning_create_op( array $content, $marker ) {
        if ( '' === $marker || empty( $content['ops'] ) ) { return null; }
        foreach ( $content['ops'] as $op ) {
            if ( ! is_array( $op ) ) { continue; }
            if ( \AgentShell_MCP\Content_Journal::OP_CREATE_DRAFT !== (string) ( $op['op'] ?? '' ) ) {
                continue;
            }
            // The create op's id is the marker; both are minted together in
            // `record_intent()` via `new_op_id()`.
            if ( (string) ( $op['id'] ?? '' ) === $marker ) {
                return $op;
            }
            // Older records may carry the marker on `after.op_meta_value`
            // but the create tool's record_intent() stamps them on both.
            if ( (string) ( $op['after']['op_meta_value'] ?? '' ) === $marker ) {
                return $op;
            }
        }
        return null;
    }

    /**
     * Handle a retry that found an existing op. The rules mirror
     * create_draft's: in-flight refuses with a named reason; applied
     * with matching (actor, transaction, post, fingerprint) resumes;
     * applied with any binding mismatch refuses with the named reason;
     * conflict / compensated / partial-applied refuses as unrecoverable.
     */
    private static function resolve_idempotency_hit( array $op, $actor, $tx_id, $post_id, $fingerprint, $idem ) {
        $state    = (string) ( $op['state'] ?? '' );
        $op_actor = (string) ( $op['actor'] ?? '' );
        $op_post  = (int) ( $op['post_id'] ?? 0 );
        $op_fp    = (string) ( $op['after']['content_fingerprint'] ?? '' );

        if ( 'intended' === $state || 'compensating' === $state ) {
            throw new \InvalidArgumentException(
                'transaction_update_draft_in_progress: a previous attempt with this idempotency_key is '
                . 'in flight (state="' . $state . '"). Reconcile by waiting or by calling '
                . 'agentshell_recover_transaction.'
            );
        }
        if ( 'conflict' === $state || 'compensated' === $state || 'partial-applied' === $state ) {
            throw new \InvalidArgumentException(
                'transaction_update_draft_unrecoverable: a previous attempt with this idempotency_key '
                . 'is in state "' . $state . '" and cannot be resumed.'
            );
        }
        if ( 'applied' !== $state ) {
            throw new \InvalidArgumentException(
                'transaction_update_draft_unrecoverable: a previous attempt with this idempotency_key '
                . 'is in an unknown state "' . $state . '".'
            );
        }
        if ( $op_actor !== (string) $actor ) {
            throw new \InvalidArgumentException(
                'transaction_update_draft_idempotency_actor_mismatch: a previous attempt with this key '
                . 'was made by actor "' . $op_actor . '", but the current actor is "' . $actor . '".'
            );
        }
        if ( $op_post !== (int) $post_id ) {
            throw new \InvalidArgumentException(
                'transaction_update_draft_idempotency_post_mismatch: a previous attempt with this key '
                . 'updated post #' . $op_post . ', but the current call targets post #' . (int) $post_id . '.'
            );
        }
        if ( '' !== $op_fp && $op_fp !== (string) $fingerprint ) {
            throw new \InvalidArgumentException(
                'transaction_update_draft_idempotency_conflict: a previous attempt with this key used '
                . 'different content for the same post. The original op is preserved.'
            );
        }
        // Resume: return the original op's identity; do not write to the post.
        return array(
            'id'              => (int) ( $op['post_id'] ?? 0 ),
            'type'            => (string) ( $op['target']['post_type'] ?? '' ),
            'title'           => (string) ( $op['after']['post_title'] ?? '' ),
            'content_length'  => isset( $op['after']['post_content_length'] ) ? (int) $op['after']['post_content_length'] : 0,
            'status'          => 'draft',
            'slug'            => (string) ( $op['after']['post_name'] ?? '' ),
            'journaled'       => true,
            'journal_applied' => true,
            'recoverable'     => true,
            'op_id'           => (string) ( $op['id'] ?? '' ),
            'marker'          => (string) ( $op['after']['op_meta_value'] ?? '' ),
            'resumed'         => true,
            'idempotency_key' => $idem,
            'transaction'     => (string) $tx_id,
        );
    }
}
