<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_create_draft — create a draft with the content journal.
 *
 * The one content tool that may run inside an open transaction. Every
 * other content tool (create_page, create_post, update_content, etc.)
 * refuses inside a transaction; this one journals its work so the
 * journal-aware lifecycle (commit / rollback / recover) can do
 * something with the draft afterwards.
 *
 * Two paths:
 *
 *   - **Inside a transaction**: record the durable intent before
 *     inserting the post, write the post and the marker inside one
 *     guarded SQL transaction, capture the strong after-image inside
 *     the same SQL transaction, and stamp the journal with the
 *     resulting post id. The `idempotency_key` is bound to
 *     (actor, transaction, content fingerprint): a retry with the
 *     same key returns the original post id with `resumed: true`
 *     instead of creating a second post.
 *
 *   - **Outside a transaction**: the tool is immediate-write, no
 *     journal record, no marker, no idempotency semantics. Two
 *     calls with the same key create two drafts.
 *
 * Status is fixed to `draft`. The input schema does not accept a
 * `status` argument; passing one is a schema-level refusal before
 * the tool runs.
 *
 * The result does not include preview credentials. The screenshot
 * tool mints them when called with `post_id` and `transaction: true`.
 */
class Create_Draft extends Base_Tool {
    public function get_name() { return 'agentshell_create_draft'; }
    public function get_description() {
        return 'Create a WordPress draft (post or page) with raw HTML content. The one content tool that '
            . 'may be called inside an open transaction; the operation is journalled so commit / rollback / '
            . 'recover can act on it. Outside a transaction the tool is immediate-write. The `status` is '
            . 'fixed to `draft` and an `idempotency_key` is required for retry safety.';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'type'            => array( 'type' => 'string', 'enum' => array( 'post', 'page' ), 'description' => 'Defaults to page' ),
                'title'           => array( 'type' => 'string', 'description' => 'Draft title' ),
                'content'         => array( 'type' => 'string', 'description' => 'Raw HTML content' ),
                'slug'            => array( 'type' => 'string', 'description' => 'Optional URL slug' ),
                'parent'          => array( 'type' => 'integer', 'description' => 'Optional parent page ID (pages only)' ),
                'idempotency_key' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                    'description' => 'A stable, client-supplied identifier (e.g. a UUID) for retry safety. '
                        . 'The tool refuses to run without one. Inside a transaction, a retry with the '
                        . 'same key by the same actor on the same content returns the original post id '
                        . 'with `resumed: true`; a retry with the same key but different content / actor '
                        . '/ transaction is refused. Outside a transaction, the key is ignored.',
                ),
            ),
            'required'   => array( 'title', 'content', 'idempotency_key' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'title', 'content', 'idempotency_key' ) );

        $type   = isset( $arguments['type'] ) ? (string) $arguments['type'] : 'page';
        $title  = (string) $arguments['title'];
        $body   = (string) $arguments['content'];
        $slug   = isset( $arguments['slug'] ) ? (string) $arguments['slug'] : '';
        $parent = isset( $arguments['parent'] ) ? (int) $arguments['parent'] : 0;
        $idem   = (string) $arguments['idempotency_key'];

        // The schema constraint already says 1-128 chars; this is a defence
        // in depth that also rejects keys that are not printable ASCII.
        if ( '' === $idem || strlen( $idem ) > 128 || ! preg_match( '/^[\x20-\x7E]+$/', $idem ) ) {
            throw new \InvalidArgumentException(
                'idempotency_key must be 1-128 printable ASCII characters; got: ' . bin2hex( $idem )
            );
        }
        if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }
        if ( '' === trim( $title ) && '' === trim( $body ) ) {
            throw new \InvalidArgumentException(
                "Cannot create {$type}: a title and content are both empty, which WordPress stores as an "
                . 'untitled draft rather than a page. Pass a title, content, or both.'
            );
        }

        $tx_mgr  = \AgentShell_MCP\Transaction_Manager::instance();
        $in_tx   = $tx_mgr->is_active();
        $actor   = \AgentShell_MCP\Store::actor();
        $fingerprint = self::content_fingerprint( $type, $title, $body, $slug, $parent );

        // ── Outside a transaction: immediate write, no journal, no idempotency. ──
        if ( ! $in_tx ) {
            $post_id = wp_insert_post( wp_slash( array(
                'post_type'    => $type,
                'post_status'  => 'draft',
                'post_title'   => sanitize_text_field( $title ),
                'post_content' => $body,
                'post_name'    => '' === $slug ? null : sanitize_title( $slug ),
                'post_parent'  => $parent > 0 && 'page' === $type ? $parent : 0,
            ) ), true );
            if ( is_wp_error( $post_id ) || ! $post_id ) {
                $msg = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'WordPress returned no post ID.';
                throw new \InvalidArgumentException( 'Could not create ' . $type . ': ' . $msg );
            }
            return array(
                'id'            => (int) $post_id,
                'type'          => $type,
                'title'         => $title,
                'status'        => 'draft',
                'journaled'     => false,
                'journal_applied' => false,
                'recoverable'   => false,
                'marker'        => null,
                'resumed'       => false,
                'idempotency_key' => $idem,
                'transaction'   => null,
            );
        }

        // ── Inside a transaction: journalled, idempotent, recoverable. ──
        $tx          = $tx_mgr->payload();
        $tx_id       = (string) $tx['id'];
        $tx_actor    = (string) $tx['actor'];
        $content     = \AgentShell_MCP\Content_Journal::of( $tx );

        // 1. Idempotency check. Find an op in this journal with the same key.
        $existing = self::find_op_by_idempotency_key( $content, $idem );
        if ( null !== $existing ) {
            return self::resolve_idempotency_hit( $existing, $actor, $tx_id, $fingerprint, $idem );
        }

        // 2. Record the durable intent. mint the op id here, attach to the row.
        $target = array(
            'post_type'   => $type,
            'post_status' => 'draft',
        );
        // Defence in depth: if the user provided a slug, stamp it on the op
        // as the expected post_name. mark_applied will overwrite it with the
        // post's actual slug on success; if mark_applied never runs (a
        // crash, a CAS refusal), recovery still has a non-empty post_name
        // to look for and validate() does not refuse the record.
        $intent_after = array( 'idempotency_key' => $idem );
        if ( '' !== $slug ) {
            $intent_after['post_name'] = sanitize_title( $slug );
        }
        $op = \AgentShell_MCP\Content_Journal::record_intent(
            \AgentShell_MCP\Content_Journal::OP_CREATE_DRAFT,
            $target,
            $intent_after,
            $actor
        );
        // Bind the content fingerprint up front; the op now carries the
        // (actor, transaction, fingerprint) tuple the resume branch checks.
        $op['after']['content_fingerprint'] = $fingerprint;

        if ( ! \AgentShell_MCP\Content_Journal::attach( $tx, $op ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx_id . ' changed while the intent was being recorded; nothing was written. '
                . 'Read agentshell_get_transaction and retry.'
            );
        }

        // Re-read the row after attach() so the guard closure starts from the
        // post-attach bytes. This is the only honest "row is at the version
        // we just attached" baseline; the pre-attach $tx we have in scope has
        // no `ops[$op_id]` for the subsequent mark_applied() / head CAS to
        // find, and the closure must not pass an obsolete row to either.
        // Without this re-read, the create path's compare_and_set_head CAS
        // sends a row that no longer matches the on-disk option value and
        // the post is rolled back, leaving the op in `intended` with no
        // post id and no recoverable after-image (the bug Gate 3 fixed).
        $tx_post_attach = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
        if ( empty( $tx_post_attach ) || empty( $tx_post_attach['content']['ops'][ $op['id'] ] ) ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx_id . ' is no longer present after the create intent was attached; '
                . 'the row was removed by a competing close. Read agentshell_get_transaction and retry.'
            );
        }

        // 3. Create the post, stamp the marker, capture the strong after-image
        //    — all inside one guarded SQL transaction.
        $op_id   = $op['id'];
        $postarr = array(
            'post_type'    => $type,
            'post_status'  => 'draft',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => $body,
            'post_name'    => '' === $slug ? null : sanitize_title( $slug ),
            'post_parent'  => $parent > 0 && 'page' === $type ? $parent : 0,
            'meta_input'   => array( \AgentShell_MCP\Content_Journal::OP_META => $op_id ),
        );

        $result = \AgentShell_MCP\Content_Write_Guard::run( function () use ( $postarr, $type, $tx_post_attach, $op_id ) {
            $id = wp_insert_post( wp_slash( $postarr ), true );
            if ( is_wp_error( $id ) || ! $id ) {
                $msg = is_wp_error( $id ) ? $id->get_error_message() : 'WordPress returned no post ID.';
                throw new \InvalidArgumentException( 'Could not create ' . $type . ': ' . $msg );
            }
            $post = get_post( $id );
            if ( ! $post ) {
                throw new \InvalidArgumentException( 'Could not read back post ' . $id . ' after creation.' );
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
                'op_meta_value'       => $op_id,
            );
            // Head advance FIRST, mark_applied SECOND. For a create there is
            // no prior head (expected_prev is null) and compare_and_set_head
            // refuses only if a head already exists for this post id; in the
            // create path that should be impossible because the post was just
            // inserted, but the guard makes the failure mode honest. After a
            // successful head CAS, mark_applied transitions the op from
            // intended to applied using the post-head-CAS row bytes. Both
            // writes share this one SQL transaction; a failed head CAS
            // throws to roll back the wp_insert_post, and a failed
            // mark_applied CAS throws to roll back both.
            $head_tx = \AgentShell_MCP\Content_Journal::compare_and_set_head(
                $tx_post_attach, (int) $id, null, $after_image
            );
            if ( ! is_array( $head_tx ) ) {
                throw new \InvalidArgumentException(
                    'compare_and_set_head() refused to record the head for post ' . (int) $id
                    . ' on create; the post was inserted but the journal head could not be set, '
                    . 'so the post is rolled back. Retry the create.'
                );
            }
            $applied_tx = \AgentShell_MCP\Content_Journal::mark_applied( $head_tx, $op_id, $id, $after_image );
            return array( 'post_id' => (int) $id, 'applied_tx' => $applied_tx );
        } );

        $post_id    = (int) $result['post_id'];
        $applied_tx = $result['applied_tx'];

        // 4. CAS-failure re-read: if mark_applied returned null the row
        //    moved; the actual state could be applied, partial-applied, or
        //    intended. Re-read the journal to find out, and report the
        //    truthful state.
        $journal_applied = is_array( $applied_tx );
        $journal_note    = null;
        if ( ! $journal_applied ) {
            $re_read     = get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            $re_content  = is_array( $re_read ) ? \AgentShell_MCP\Content_Journal::of( $re_read ) : array();
            $re_op       = isset( $re_content['ops'][ $op_id ] ) ? $re_content['ops'][ $op_id ] : null;
            $re_state    = is_array( $re_op ) ? (string) ( $re_op['state'] ?? '' ) : '';
            $journal_note = 'mark_applied() CAS did not land; the journal currently reports state="' . $re_state
                . '". The post exists with the marker; recovery can settle it.';
        }

        return array(
            'id'            => $post_id,
            'type'          => $type,
            'title'         => $title,
            'status'        => 'draft',
            'journaled'     => true,
            'journal_applied' => $journal_applied,
            'recoverable'   => true,
            'marker'        => $op_id,
            'resumed'       => false,
            'idempotency_key' => $idem,
            'transaction'   => $tx_id,
            'journal_note'  => $journal_note,
        );
    }

    /**
     * Stable SHA-256 of the request fields that, if changed, mean a new draft.
     * Excludes the idempotency_key itself (the key is what selects the op).
     */
    private static function content_fingerprint( $type, $title, $content, $slug, $parent ) {
        $payload = array(
            'type'    => (string) $type,
            'title'   => (string) $title,
            'content' => (string) $content,
            'slug'    => (string) $slug,
            'parent'  => (int) $parent,
        );
        return hash( 'sha256', wp_json_encode( $payload ) );
    }

    /**
     * Find an op in the journal whose `after.idempotency_key` matches.
     * Returns the op array, or null if no op has this key.
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
     * Handle a retry that found an existing op. The rules:
     *   - in-flight (intended, compensating): refuse, in progress.
     *   - applied + matching actor, tx, fingerprint: resume (no second post).
     *   - applied + any binding mismatch: refuse with the named reason.
     *   - conflict or compensated: refuse, the original is settled.
     */
    private static function resolve_idempotency_hit( array $op, $actor, $tx_id, $fingerprint, $idem ) {
        $state    = (string) ( $op['state'] ?? '' );
        $op_actor = (string) ( $op['actor'] ?? '' );
        $op_tx    = (string) ( $op['op']['tx'] ?? '' );
        $op_fp    = (string) ( $op['after']['content_fingerprint'] ?? '' );

        if ( 'intended' === $state || 'compensating' === $state ) {
            throw new \InvalidArgumentException(
                'transaction_create_draft_in_progress: a previous attempt with this idempotency_key is in '
                . 'flight (state="' . $state . '"). Reconcile by waiting or by calling agentshell_recover_transaction.'
            );
        }
        if ( 'conflict' === $state || 'compensated' === $state || 'partial-applied' === $state ) {
            throw new \InvalidArgumentException(
                'transaction_create_draft_unrecoverable: a previous attempt with this idempotency_key is in '
                . 'state "' . $state . '" and cannot be resumed.'
            );
        }
        if ( 'applied' !== $state ) {
            throw new \InvalidArgumentException(
                'transaction_create_draft_unrecoverable: a previous attempt with this idempotency_key is in '
                . 'an unknown state "' . $state . '".'
            );
        }
        if ( $op_actor !== (string) $actor ) {
            throw new \InvalidArgumentException(
                'transaction_create_draft_idempotency_actor_mismatch: a previous attempt with this key was '
                . 'made by actor "' . $op_actor . '", but the current actor is "' . $actor . '".'
            );
        }
        if ( '' !== $op_tx && $op_tx !== (string) $tx_id ) {
            throw new \InvalidArgumentException(
                'transaction_create_draft_idempotency_tx_mismatch: a previous attempt with this key was '
                . 'made in transaction "' . $op_tx . '", but the current transaction is "' . $tx_id . '".'
            );
        }
        if ( '' !== $op_fp && $op_fp !== (string) $fingerprint ) {
            throw new \InvalidArgumentException(
                'transaction_create_draft_idempotency_conflict: a previous attempt with this key used '
                . 'different content. The original op is preserved.'
            );
        }
        // Resume: return the original post id; do not create a second post.
        return array(
            'id'            => (int) ( $op['post_id'] ?? 0 ),
            'type'          => (string) ( $op['target']['post_type'] ?? '' ),
            'title'         => '',
            'status'        => 'draft',
            'journaled'     => true,
            'journal_applied' => true,
            'recoverable'   => true,
            'marker'        => (string) ( $op['id'] ?? '' ),
            'resumed'       => true,
            'idempotency_key' => $idem,
            'transaction'   => (string) $tx_id,
        );
    }
}
