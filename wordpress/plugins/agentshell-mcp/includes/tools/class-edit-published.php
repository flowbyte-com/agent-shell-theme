<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_edit_published — Stage 2c staging tool.
 *
 * Stages an edit against a published post by creating a private
 * shadow row bound to the original, journaling an
 * `OP_EDIT_PUBLISHED` op in `staged` state, capturing the
 * strong immutable baseline of the original, and acquiring
 * the per-original claim. The commit tool is
 * `agentshell_publish_edit`; the reconcile tool is
 * `agentshell_publish_edit_reconcile`; rollback is the standard
 * `rollback_transaction` path (M3 staging + M4 commit + M5
 * reconciliation tail).
 *
 * The contract is documented in:
 *   - AGENTS.md §6.5
 *   - docs/STAGE-2C-DESIGN.md §2.1
 *   - docs/STAGE-2C-DESIGN.md §1.7 (state machine)
 *
 * ## Refusal cascade (in evaluation order)
 *
 *   1. Not inside a transaction.
 *   2. Missing or non-printable `idempotency_key`.
 *   3. `original_id` does not exist, is the wrong type, or is
 *      not currently `post_status=publish`.
 *   4. None of the four supported fields is supplied.
 *   5. A different transaction holds the claim for this
 *      `original_id` (a competing staging is in progress).
 *   6. The original's claim is held by a different transaction
 *      in a non-terminal state (the caller is told to run
 *      `agentshell_recover_transaction` first).
 *
 * ## Idempotency / head-advancement rules
 *
 *   - No op exists yet: fresh staging. New op + new shadow +
 *     baseline + claim. State: `staged`.
 *   - Op exists in `staged` state with the same
 *     `idempotency_key` and the same staged payload: the
 *     tool returns the prior result with `resumed: true`.
 *   - Op exists in `staged` state with the same
 *     `idempotency_key` but a different staged payload: the
 *     tool refuses with
 *     `transaction_edit_published_idempotency_conflict`.
 *   - Op exists in `staged` state with a different
 *     `idempotency_key`: head advancement. The shadow is
 *     updated to the new staged fields; the head map for the
 *     original (keyed by `original_id`) is CAS-updated; the
 *     op's `shadow_head` is re-stamped. The op id and shadow
 *     id are unchanged.
 *   - Op exists in any non-`staged` state: refused. The
 *     caller is told which state and which tool to run next.
 *
 * ## Transactional safety
 *
 *   - The transaction row is re-read at the start of every
 *     in-tx call (the 87cf0cb pattern: a previous in-tx call
 *     may have advanced the on-disk row, and a stale `$tx`
 *     from request entry would make `cas_write_row()` refuse).
 *   - The same re-read is repeated after `Content_Journal::attach()`
 *     so the subsequent `compare_and_set_head()` sees the
 *     post-attach bytes — mirroring the 0314499 head-CAS re-read
 *     and the create_draft pattern.
 *   - The shadow's `wp_insert_post`, the `attach` CAS, the head
 *     CAS, the claim `create`, and the audit row share one
 *     guarded SQL transaction (`Content_Write_Guard::run()`).
 *   - The audit row is written *inside* the SQL transaction so
 *     a rolled-back staging leaves no audit record of the
 *     attempt.
 *   - The per-original claim (a per-`wp_options` row keyed by
 *     `original_id`) is the staging-time cross-process
 *     exclusion. The per-tx MySQL named advisory lock is the
 *     *publish*-time lock, per STAGE-2C-DESIGN.md §1.4 step 1;
 *     it is NOT acquired by the staging path. (Two requests
 *     staging different originals in the same tx do not
 *     contend; two requests staging the *same* original in the
 *     same tx are serialised by the per-original claim's
 *     value-guarded CAS.)
 */
class Edit_Published extends Base_Tool {

    /**
     * The four fields the publish step will write to the
     * original. Anything else is rejected by the input schema.
     * The publish step's protected-preserved allowlist uses the
     * same set as the source of truth for "what was staged".
     */
    const SUPPORTED_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_name' );

    public function get_name() { return 'agentshell_edit_published'; }

    public function get_description() {
        return 'Stage a published-content edit against a published post. The Stage 2c staging '
            . 'tool. Inside the open transaction, creates a private shadow bound to the '
            . 'original, journals an `OP_EDIT_PUBLISHED` op in `staged` state, captures the '
            . 'strong immutable baseline of the original, and holds the per-original claim. '
            . 'The commit step is `agentshell_publish_edit`. The four supported fields are '
            . '`post_title`, `post_content`, `post_excerpt`, `post_name`. Refuses if the '
            . 'original is not `post_status=publish`, if a competing transaction holds the '
            . 'claim, or if no fields are supplied. A second call with the same '
            . '`idempotency_key` and same payload returns the prior result with `resumed: '
            . 'true` (retry-resume path). A second call with the same `idempotency_key` '
            . 'and a different payload refuses with '
            . '`transaction_edit_published_idempotency_conflict`. A second call with a '
            . 'different `idempotency_key` advances the head (the original\'s head-map '
            . 'entry) and returns `advanced: true`.';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'original_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'The published post id to stage against. The original must currently '
                        . 'be `post_status=publish` of type `post` or `page`.',
                ),
                'post_title'     => array( 'type' => 'string', 'description' => 'New title (optional).' ),
                'post_content'   => array( 'type' => 'string', 'description' => 'New content (optional; raw HTML).' ),
                'post_excerpt'   => array( 'type' => 'string', 'description' => 'New excerpt (optional).' ),
                'post_name'      => array( 'type' => 'string', 'description' => 'New slug (optional).' ),
                'idempotency_key' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                    'description' => 'A stable, client-supplied identifier (1-128 printable ASCII characters) for retry '
                        . 'safety. The tool refuses to run without one. A retry with the same key by the same actor on '
                        . 'the same staged payload returns the original result with `resumed: true`; a retry with '
                        . 'the same key but a different staged payload refuses with '
                        . '`transaction_edit_published_idempotency_conflict`; a retry with a different key advances '
                        . 'the head and returns `advanced: true`.',
                ),
            ),
            'required'   => array( 'original_id', 'idempotency_key' ),
        );
    }

    public function execute( array $arguments ) {
        // ── Argument parsing + required-fields check ─────────────────────
        $original_id  = isset( $arguments['original_id'] )  ? (int)    $arguments['original_id']  : 0;
        $idem         = isset( $arguments['idempotency_key'] ) ? (string) $arguments['idempotency_key'] : '';

        // Presence — not emptiness — decides whether a field was supplied.
        //
        // The four staged fields are optional, and an omitted field must
        // inherit the value that is already in effect. Treating "omitted"
        // as "empty string" (the obvious `isset( ... ) ? ... : ''` shape)
        // silently erased every field the caller did not mention: a
        // content-only edit published an empty title, an empty excerpt
        // and an empty slug. The distinction the contract needs is
        // *present* versus *absent*:
        //
        //   - absent  → inherit the currently effective value (the
        //               published original on a fresh staging; the
        //               shadow's current staged value when an
        //               authorised edit advances the head).
        //   - present → stage exactly what was supplied, including an
        //               empty string where an empty string is meaningful.
        //
        // The two fields where an empty string is never meaningful
        // (post_title, post_content) are refused rather than staged:
        // publishing an untitled or bodiless page destroys content, and
        // "clear it" is not a change anybody makes by accident. An
        // explicitly empty excerpt is allowed — clearing an excerpt is
        // ordinary editorial work and destroys nothing.
        $has_title   = array_key_exists( 'post_title',   $arguments );
        $has_content = array_key_exists( 'post_content', $arguments );
        $has_excerpt = array_key_exists( 'post_excerpt', $arguments );
        $has_name    = array_key_exists( 'post_name',    $arguments );

        $post_title   = $has_title   ? (string) $arguments['post_title']   : null;
        $post_content = $has_content ? (string) $arguments['post_content'] : null;
        $post_excerpt = $has_excerpt ? (string) $arguments['post_excerpt'] : null;
        $post_name    = $has_name    ? (string) $arguments['post_name']    : null;

        // The set of fields this call explicitly authorises a change to.
        // It travels with the staged payload so the publish step can
        // prove that nothing outside the authorised change moved.
        $staged_fields = array();
        if ( $has_title )   { $staged_fields[] = 'post_title'; }
        if ( $has_content ) { $staged_fields[] = 'post_content'; }
        if ( $has_excerpt ) { $staged_fields[] = 'post_excerpt'; }
        if ( $has_name && '' !== $post_name ) { $staged_fields[] = 'post_name'; }

        // Refusal #2 (run before validate_required so an empty key
        // surfaces as `bad_idempotency_key`, not as a generic
        // "Missing required parameters"). The MCP contract
        // (input_schema.required) lists idempotency_key; an empty
        // value is present-but-invalid, distinct from a missing
        // argument.
        if ( '' === $idem || strlen( $idem ) > 128 || ! preg_match( '/^[\x20-\x7E]+$/', $idem ) ) {
            throw new \InvalidArgumentException(
                'transaction_edit_published_refused_bad_idempotency_key: idempotency_key must be 1-128 '
                . 'printable ASCII characters; got: ' . bin2hex( $idem )
            );
        }

        // The remaining required-fields check still applies to
        // original_id, which the contract requires as a non-empty
        // integer.
        $this->validate_required( $arguments, array( 'original_id' ) );

        // Refusal #1: must be inside a transaction.
        $tx_mgr = \AgentShell_MCP\Transaction_Manager::instance();
        if ( ! $tx_mgr->is_active() ) {
            throw new \InvalidArgumentException(
                'transaction_edit_published_refused_not_in_tx: agentshell_edit_published requires an '
                . 'open transaction. Begin a transaction first; the staging path is the journal + '
                . 'shadow + claim path documented in AGENTS.md §6.5 and STAGE-2C-DESIGN.md §2.1.'
            );
        }

        // Refusal #4: at least one of the four supported fields must be
        // supplied. Presence, not emptiness, decides: an explicitly
        // empty excerpt is a real change (clear the excerpt), and a call
        // that names no field at all is a no-op edit.
        $has_fields = $has_title || $has_content || $has_excerpt || $has_name;
        if ( ! $has_fields ) {
            throw new \InvalidArgumentException(
                'transaction_edit_published_refused_no_op: at least one of post_title, post_content, '
                . 'post_excerpt, post_name must be supplied. A no-op edit is refused; the staging '
                . 'tool creates a shadow and holds a claim, both of which must be justified by a '
                . 'real change.'
            );
        }

        // Refusal #4b: an explicitly empty title or body is refused.
        // These are the two fields whose emptiness destroys content that
        // is live. Omitting the field inherits the published value; the
        // staging tool will not publish an untitled or bodiless page.
        if ( $has_title && '' === $post_title ) {
            throw new \InvalidArgumentException(
                'transaction_edit_published_refused_empty_title: post_title was supplied as an empty '
                . 'string. Publishing an untitled page is not a change this tool will make; omit '
                . 'post_title to inherit the published title, or supply a real title.'
            );
        }
        if ( $has_content && '' === $post_content ) {
            throw new \InvalidArgumentException(
                'transaction_edit_published_refused_empty_content: post_content was supplied as an '
                . 'empty string. Publishing a bodiless page destroys the content that is live; omit '
                . 'post_content to inherit the published body, or supply the real body.'
            );
        }

        // Refusal #3: original must exist, be a supported type, and be
        // currently `post_status=publish`.
        $original = get_post( $original_id );
        if ( ! $original ) {
            throw new \InvalidArgumentException(
                "transaction_edit_published_refused_original_gone: original_id {$original_id} does "
                . 'not exist. Staging against a missing post is refused by name.'
            );
        }
        if ( ! in_array( (string) $original->post_type, array( 'post', 'page' ), true ) ) {
            throw new \InvalidArgumentException(
                "transaction_edit_published_refused_original_wrong_type: original_id {$original_id} "
                . "is post_type='" . (string) $original->post_type . "', expected 'post' or 'page'."
            );
        }
        if ( 'publish' !== (string) $original->post_status ) {
            throw new \InvalidArgumentException(
                "transaction_edit_published_refused_original_unpublished: original_id {$original_id} "
                . "is post_status='" . (string) $original->post_status . "', expected 'publish'. "
                . 'Status transitions are out of scope; the staging tool only edits already-published '
                . 'posts.'
            );
        }

        // The effective staged values are resolved per branch below: the
        // fresh path inherits from the published original, the
        // head-advancement path inherits from the shadow's current
        // staged values. See effective_values().

        // Re-read the transaction row at the start of every in-tx call.
        // Same pattern as the Create_Draft fix at 87cf0cb: a previous
        // staging call's attach() / compare_and_set_head() advanced the
        // on-disk row, and a stale $tx from request entry would make
        // cas_write_row() refuse. The empty-row guard fails closed.
        $tx = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
        if ( empty( $tx ) || empty( $tx['id'] ) ) {
            throw new \InvalidArgumentException(
                'edit_published inside a transaction requires an open transaction row; got '
                . ( empty( $tx ) ? 'none' : 'a row with no id' ) . '. Begin a transaction first.'
            );
        }
        $tx_id = (string) $tx['id'];
        $actor = (string) \AgentShell_MCP\Store::actor();
        $content = \AgentShell_MCP\Content_Journal::of( $tx );

        // Refusal #5 / #6: per-original claim check.
        $claim_row = \AgentShell_MCP\Published_Claim::read( $original_id );
        if ( null !== $claim_row ) {
            $holder_tx = (string) ( $claim_row['tx_id'] ?? '' );
            if ( $holder_tx !== $tx_id ) {
                if ( \AgentShell_MCP\Published_Claim::is_fresh( $claim_row ) ) {
                    throw new \InvalidArgumentException(
                        "transaction_edit_published_refused_claim_other_tx: original_id "
                        . "{$original_id} is held by a different transaction (" . $holder_tx
                        . "); a competing staging is in progress. Wait for it to finish, or close "
                        . "that transaction, then retry."
                    );
                }
                throw new \InvalidArgumentException(
                    "transaction_edit_published_refused_claim_stale: original_id {$original_id} "
                    . "is held by a different transaction (" . $holder_tx
                    . ") in a non-terminal state; the claim has expired. Run "
                    . "agentshell_recover_transaction on that transaction first."
                );
            }
            // Same transaction: the claim is left alone (we are advancing
            // an existing op, or this is a follow-up idempotency-keyed
            // call on the same op).
        }

        // ── Idempotency / head-advancement path ────────────────────────
        $existing_op = self::find_op_for_original( $content, $tx_id, $original_id );
        if ( null !== $existing_op ) {
            $op_state  = (string) ( $existing_op['state'] ?? '' );
            $op_id     = (string) ( $existing_op['id'] ?? '' );
            $op_idem   = (string) ( $existing_op['idempotency_key'] ?? '' );
            $op_fp     = self::staged_payload_fingerprint( $existing_op );

            // The effective staged values. An omitted field inherits the
            // shadow's CURRENT staged value — the accumulated state of
            // this transaction's edits — not the original's published
            // value. Inheriting from the original would silently drop
            // the previous edit's staged body when a second authorised
            // edit advances the head.
            $effective = self::effective_values(
                $original,
                is_array( $existing_op['after'] ?? null ) ? $existing_op['after'] : array(),
                $has_title, $post_title,
                $has_content, $post_content,
                $has_excerpt, $post_excerpt,
                $has_name, $post_name
            );
            $new_fp    = self::staged_payload_fingerprint_from_args(
                $effective['post_title'], $effective['post_content'],
                $effective['post_excerpt'], $effective['post_name']
            );

            if ( 'staged' !== $op_state ) {
                $next = self::next_tool_for_state( $op_state );
                throw new \InvalidArgumentException(
                    "transaction_edit_published_refused_op_not_staged: op {$op_id} is in state '"
                    . $op_state . "'; cannot restage. " . $next
                );
            }

            if ( $op_idem === $idem ) {
                if ( $op_fp === $new_fp ) {
                    return self::build_resumed_result( $existing_op, $original_id, $actor, $tx_id );
                }
                throw new \InvalidArgumentException(
                    "transaction_edit_published_idempotency_conflict: op {$op_id} is in `staged` "
                    . "state with idempotency_key='{$idem}' but a different staged payload. The op "
                    . "is unchanged; the head is unchanged. Use a new idempotency_key (and accept "
                    . "the head advancement) or close the transaction."
                );
            }

            return self::advance_shadow(
                $tx, $existing_op, $original_id, $original,
                $effective['post_title'], $effective['post_content'],
                $effective['post_excerpt'], $effective['post_name'],
                $staged_fields,
                $idem, $actor, $tx_id
            );
        }

        // Fresh staging: the effective values inherit from the published
        // original — there is no shadow yet, so the original's current
        // values are what is in effect.
        $effective = self::effective_values(
            $original, array(),
            $has_title, $post_title,
            $has_content, $post_content,
            $has_excerpt, $post_excerpt,
            $has_name, $post_name
        );

        return self::fresh_stage(
            $tx, $original, $original_id,
            $effective['post_title'], $effective['post_content'],
            $effective['post_excerpt'], $effective['post_name'],
            $staged_fields,
            $idem, $actor, $tx_id
        );
    }

    /**
     * Resolve the effective staged values for the four supported fields.
     *
     * A field the caller supplied is staged exactly as given. A field
     * the caller omitted inherits the value that is currently in effect:
     * `$current` — the shadow's staged after-image — when one exists, and
     * the published original when it does not.
     *
     * The four values this returns are the *payload* of the staged edit:
     * they are what the shadow stores and what the publish step writes.
     * Because they are complete, a field the caller never mentioned is
     * written back unchanged rather than blank.
     *
     * @param \WP_Post  $original The published original.
     * @param array     $current  The shadow's current staged after-image
     *                             (empty on a fresh staging).
     * @return array{post_title:string, post_content:string, post_excerpt:string, post_name:string}
     */
    private static function effective_values(
        \WP_Post $original, array $current,
        $has_title, $post_title,
        $has_content, $post_content,
        $has_excerpt, $post_excerpt,
        $has_name, $post_name
    ) : array {
        $inherit = function ( string $field, string $fallback ) use ( $current ) : string {
            if ( array_key_exists( $field, $current ) ) {
                return (string) $current[ $field ];
            }
            return (string) $fallback;
        };
        return array(
            'post_title'   => $has_title
                ? (string) $post_title
                : $inherit( 'post_title', (string) $original->post_title ),
            'post_content' => $has_content
                ? (string) $post_content
                : $inherit( 'post_content', (string) $original->post_content ),
            'post_excerpt' => $has_excerpt
                ? (string) $post_excerpt
                : $inherit( 'post_excerpt', (string) $original->post_excerpt ),
            // An empty post_name keeps its documented meaning — "leave
            // the slug alone". A slug is never authored empty.
            'post_name'    => ( $has_name && '' !== $post_name )
                ? (string) $post_name
                : $inherit( 'post_name', (string) $original->post_name ),
        );
    }

    // ── fresh_stage ────────────────────────────────────────────────────

    /**
     * Fresh staging. The composition is the eight steps in this
     * exact order, all inside one `Content_Write_Guard::run()`
     * SQL transaction so a failure at any step rolls back the
     * whole staging:
     *
     *   1. `record_intent` — mints the op id, returns the op
     *      with `state=staged` and `target = { post_type,
     *      post_status, original_id }`. `before` is `null`,
     *      `post_id` is `0`, `after.op_meta_value` is the op's
     *      id (from `record_intent`).
     *   2. Stamp `idempotency_key`, `idempotency_actor`,
     *      `idempotency_tx`, `content_fingerprint` on the op.
     *   3. `capture_edit_published_baseline` — sets
     *      `op.before` to the strong immutable baseline-A
     *      (with its own fresh `op_meta_value` so the
     *      before/after check in the validator passes).
     *   4. `Published_Shadow::create_or_get` — returns the
     *      `shadow_id` and stamps the shadow's `META_OP_ID`
     *      with the op id. The fresh path returns
     *      `status=created`.
     *   5. Stamp `op.target.shadow_id`, `op.post_id = shadow_id`,
     *      and `op.shadow_head` (the head pointer the publish
     *      step reads). The op now carries the structural
     *      shape the validator demands (target.original_id,
     *      target.shadow_id, before, after.post_*,
     *      after.op_meta_value, post_id, shadow_head).
     *   6. `Content_Journal::attach` — CAS-writes the op into
     *      the journal row. Uses the just-read `$tx` from
     *      `execute()`'s top.
     *   7. Re-read the row for the head CAS — same fix as
     *      0314499 / create-draft, applied to staging.
     *   8. `Content_Journal::compare_and_set_head` —
     *      CAS-writes the original's head-map entry. The
     *      `post_id` argument is `original_id` (the head map
     *      is keyed by the post the op is about to publish
     *      against). `expected_prev` is `null` for the first
     *      op. `new_after` is the staged-after shape the
     *      publish step will read.
     *   9. `Published_Claim::create` — value-guarded
     *      `INSERT ... ON DUPLICATE KEY UPDATE` for the
     *      per-original claim. `'created'` is the only
     *      acceptable status on the fresh path.
     *  10. Audit — inside the SQL transaction so a rolled-back
     *      staging leaves no audit record of the attempt.
     *
     * @return array The result shape documented in §2.1.
     */
    private static function fresh_stage(
        array $tx, \WP_Post $original, int $original_id,
        string $post_title, string $post_content, string $post_excerpt, string $post_name,
        array $staged_fields,
        string $idem, string $actor, string $tx_id
    ) {
        $staged_post_name = '' !== $post_name ? $post_name : (string) $original->post_name;
        $post_content_sha256 = hash( 'sha256', $post_content );

        $result = \AgentShell_MCP\Content_Write_Guard::run( function() use (
            $tx, $original, $original_id, $staged_post_name, $post_content_sha256,
            $post_title, $post_content, $post_excerpt, $post_name, $staged_fields,
            $idem, $actor, $tx_id
        ) {
            $now = \AgentShell_MCP\Transaction_Manager::lease_now();

            // 1. record_intent. The op is born with state=staged
            //    (per Content_Journal::record_intent's
            //    OP_EDIT_PUBLISHED branch) and an after that
            //    array_merges target into user after plus the
            //    op_meta_value the journal will use as the
            //    shadow's marker.
            $op = \AgentShell_MCP\Content_Journal::record_intent(
                \AgentShell_MCP\Content_Journal::OP_EDIT_PUBLISHED,
                array(
                    'post_type'   => (string) $original->post_type,
                    'post_status' => 'publish',
                    'original_id' => (int) $original_id,
                ),
                array(
                    'post_title'   => $post_title,
                    'post_content' => $post_content,
                    'post_excerpt' => $post_excerpt,
                    // Default the staged post_name to the
                    // original's current name when the caller
                    // did not supply one (i.e. "no slug
                    // change"). The publish step's
                    // write-and-verify-C compares the live
                    // `post_name` against this.
                    'post_name'    => $staged_post_name,
                    // The fields this call explicitly authorised a
                    // change to. The publish step uses it to prove
                    // that nothing outside the authorised change
                    // moved between the baseline and the write.
                    'staged_fields' => array_values( $staged_fields ),
                ),
                $actor,
                $now
            );
            $op['idempotency_key']     = $idem;
            $op['idempotency_actor']   = $actor;
            $op['idempotency_tx']      = $tx_id;
            $op['content_fingerprint'] = self::staged_payload_fingerprint_from_args(
                $post_title, $post_content, $post_excerpt, $staged_post_name
            );

            // 2. Capture the strong immutable baseline. The
            //    baseline's op_meta_value is a freshly-minted
            //    op id, *not* the op's id — the validator
            //    refuses a before/after match here (see
            //    validate_op_edit_published at line 1395).
            $op['before'] = \AgentShell_MCP\Content_Journal::capture_edit_published_baseline( $original_id, $now );

            // 3. Create the shadow. create_or_get is idempotent
            //    on (tx_id, original_id); the fresh path always
            //    returns 'created' (the find() inside returns
            //    'none' because no shadow exists yet on this
            //    path). The shadow's META_OP_ID is the op's id.
            $shadow_res = \AgentShell_MCP\Published_Shadow::create_or_get(
                $tx_id,
                $original_id,
                array_merge( $op['after'], array( 'post_type' => (string) $original->post_type ) ),
                (string) $op['id'],
                $actor
            );
            if ( 'created' !== $shadow_res['status'] ) {
                throw new \RuntimeException(
                    "fresh_stage: shadow create_or_get returned '"
                    . $shadow_res['status'] . "' for original_id {$original_id}; expected 'created'."
                );
            }
            $shadow_id = (int) $shadow_res['shadow_id'];

            // 4. Stamp the op so it passes the validator
            //    (target.shadow_id, post_id, shadow_head) and
            //    so the publish step can read the head pointer
            //    it was staged against. The op id and shadow
            //    id are paired here; advance_shadow leaves
            //    both unchanged.
            $op['target']['shadow_id'] = $shadow_id;
            $op['post_id']             = $shadow_id;
            $op['shadow_head']         = array(
                'op_id'        => (string) $op['id'],
                'after_sha256' => $post_content_sha256,
                'at'           => $now,
            );

            // 5. Attach the op to the journal row. The cas
            //    inside attach() guards the row bytes against
            //    the `$tx` we pass; `$tx` was re-read at the
            //    top of execute().
            if ( ! \AgentShell_MCP\Content_Journal::attach( $tx, $op ) ) {
                throw new \RuntimeException(
                    'fresh_stage: Content_Journal::attach refused the write. The journal row '
                    . 'changed between the re-read and this call; the on-disk row is the '
                    . 'source of truth. Nothing was written.'
                );
            }

            // 6. Re-read for the head CAS — same fix as
            //    0314499 / create-draft. The post-attach
            //    row bytes are what the head CAS needs to
            //    see; the pre-attach $tx has no
            //    ops[$op_id] for the head to find.
            $tx_post_attach = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( empty( $tx_post_attach )
                || empty( $tx_post_attach['content']['ops'][ $op['id'] ] ) ) {
                throw new \RuntimeException(
                    'fresh_stage: the transaction row is no longer present after the op was '
                    . 'attached. The on-disk row is the source of truth; the head-map advance '
                    . 'is refused and the staging is rolled back.'
                );
            }

            // 7. Advance the head map for the *original*. The
            //    head map is keyed by the post the op is
            //    about to publish against — for
            //    OP_EDIT_PUBLISHED, that is `original_id`,
            //    not the shadow id. expected_prev is null
            //    (this is the first op for the original).
            //    The new_after carries the four compare
            //    fields the head CAS guards
            //    (post_type, post_status, post_name,
            //    post_content_sha256) plus the shadow's
            //    marker (op_meta_value = the op's id, the
            //    same value the shadow carries on
            //    META_OP_ID).
            $head_tx = \AgentShell_MCP\Content_Journal::compare_and_set_head(
                $tx_post_attach,
                (int) $original_id,
                null,
                array(
                    'post_type'           => (string) $original->post_type,
                    'post_status'         => 'publish',
                    'post_name'           => $staged_post_name,
                    'post_content_sha256' => $post_content_sha256,
                    'post_content_length' => strlen( $post_content ),
                    'op_meta_value'       => (string) $op['id'],
                )
            );
            if ( ! is_array( $head_tx ) ) {
                throw new \RuntimeException(
                    "fresh_stage: compare_and_set_head refused to record the head for original_id "
                    . "{$original_id} on create. A concurrent caller may have advanced the head "
                    . "for this original. The shadow, op, and claim are all rolled back."
                );
            }

            // 8. Hold the per-original claim. The claim row's
            //    tx_id matches the current transaction; any
            //    other tx that tries to stage this original
            //    is refused by refusal #5 above.
            $claim_row = \AgentShell_MCP\Published_Claim::shape(
                $tx_id,
                $original_id,
                $shadow_id,
                $actor,
                $now,
                $now,
                \AgentShell_MCP\Published_Claim::TTL_SECONDS
            );
            $claim_status = \AgentShell_MCP\Published_Claim::create( $original_id, $claim_row );
            if ( 'created' !== $claim_status ) {
                // 'exists' here means a competing claim
                // landed between our read() above and
                // create() — a cross-process race the
                // same-tx check at the top did not catch
                // (it read the option, then the other tx
                // wrote its claim, then we wrote ours).
                // The atomic_create_row refuses to
                // overwrite. Refuse closed.
                throw new \RuntimeException(
                    "fresh_stage: Published_Claim::create returned '{$claim_status}' for "
                    . "original_id {$original_id}; expected 'created'. A competing claim is "
                    . "in place; the shadow, op, and head-map entry are all rolled back."
                );
            }

            // 9. Audit inside the SQL transaction so a
            //    rolled-back staging leaves no record of
            //    the attempt.
            \AgentShell_MCP\Store::record_event(
                $actor,
                'transaction_edit_published',
                array(
                    'transaction_id'    => $tx_id,
                    'op_id'             => (string) $op['id'],
                    'original_id'       => (int) $original_id,
                    'shadow_id'         => (int) $shadow_id,
                    'fields'            => array_filter( array(
                        'post_title'   => '' !== $post_title   ? $post_title   : null,
                        'post_content' => '' !== $post_content ? '<redacted>'  : null,
                        'post_excerpt' => '' !== $post_excerpt ? $post_excerpt : null,
                        'post_name'    => '' !== $post_name    ? $post_name    : null,
                    ), function( $v ) { return null !== $v; } ),
                    'baseline_captured' => true,
                )
            );

            return $op;
        } );

        if ( \AgentShell_MCP\Content_Write_Guard::is_failure( $result ) ) {
            throw new \RuntimeException(
                'fresh_stage: Content_Write_Guard reported a failure; the staging op was '
                . 'rolled back. The shadow, the journal op, the head-map entry, and the claim '
                . 'are all absent.'
            );
        }

        return self::build_fresh_result( $result, $original_id, $actor, $tx_id, $idem );
    }

    // ── advance_shadow ────────────────────────────────────────────────

    /**
     * Head advancement. The op is in `staged` state, but a new
     * call arrived with a different `idempotency_key`. The
     * shadow is updated to the new staged fields; the head
     * map for the original (keyed by `original_id`) is
     * CAS-updated to the new after; the op's `shadow_head`
     * is re-stamped. The op id and shadow id are unchanged.
     * The baseline is NOT re-captured (the original is
     * unchanged; the per-original claim guarantees no other
     * AgentShell transaction is editing this original).
     *
     * @return array The result shape with `advanced: true`.
     */
    private static function advance_shadow(
        array $tx, array $existing_op, int $original_id, \WP_Post $original,
        string $post_title, string $post_content, string $post_excerpt, string $post_name,
        array $staged_fields,
        string $idem, string $actor, string $tx_id
    ) {
        $op_id     = (string) ( $existing_op['id'] ?? '' );
        $shadow_id = (int) ( $existing_op['post_id'] ?? 0 );
        if ( '' === $op_id || $shadow_id < 1 ) {
            throw new \RuntimeException(
                "advance_shadow: existing op for original_id {$original_id} has missing id or "
                . "shadow_id (op_id='" . $op_id . "', shadow_id=" . $shadow_id . "). Refusing to "
                . "advance a malformed op; recovery is the path forward."
            );
        }

        $staged_post_name    = '' !== $post_name ? $post_name : (string) $original->post_name;
        $post_content_sha256 = hash( 'sha256', $post_content );

        // The authorised change set for the *head* is the union of every
        // field this transaction has explicitly staged for this original.
        // The shadow accumulates edits, so the payload the publish step
        // will write contains every staged change, not only the last
        // call's.
        $previous_staged = array();
        if ( is_array( $existing_op['after']['staged_fields'] ?? null ) ) {
            $previous_staged = array_values( array_filter(
                array_map( 'strval', $existing_op['after']['staged_fields'] ),
                'strlen'
            ) );
        }
        $staged_fields = array_values( array_unique( array_merge( $previous_staged, $staged_fields ) ) );

        $result = \AgentShell_MCP\Content_Write_Guard::run( function() use (
            $tx, $existing_op, $original, $original_id, $shadow_id, $op_id,
            $staged_post_name, $post_content_sha256,
            $post_title, $post_content, $post_excerpt, $post_name, $staged_fields,
            $idem, $actor, $tx_id
        ) {
            $now = \AgentShell_MCP\Transaction_Manager::lease_now();

            // 1. Update the shadow. Published_Shadow::update
            //    is value-guarded: the live shadow's
            //    META_OP_ID must match expected_op_id. On
            //    the advance path, the op id is the same
            //    (we never change the op id on
            //    advancement; only the after-image
            //    moves), so expected_op_id == new_op_id.
            $update = \AgentShell_MCP\Published_Shadow::update(
                $shadow_id,
                array(
                    'post_title'   => $post_title,
                    'post_content' => $post_content,
                    'post_excerpt' => $post_excerpt,
                    'post_name'    => $staged_post_name,
                    'post_type'    => (string) $original->post_type,
                ),
                $op_id,
                $op_id
            );
            if ( 'updated' !== $update['status'] ) {
                throw new \RuntimeException(
                    "advance_shadow: Published_Shadow::update returned '" . $update['status']
                    . "' for shadow_id {$shadow_id} (original_id {$original_id}); expected 'updated'. "
                    . "'moved' = a competing caller changed the shadow's META_OP_ID; 'gone' = the "
                    . "shadow was deleted; 'database_error' = a SQL failure. The head is unchanged."
                );
            }

            // 2. Update the op with the new after and the
            //    new shadow_head. The op id is unchanged;
            //    only the staged fields and the head
            //    pointer move.
            $next_op = $existing_op;
            $next_op['after'] = array_merge(
                $existing_op['after'] ?? array(),
                array(
                    'post_title'   => $post_title,
                    'post_content' => $post_content,
                    'post_excerpt' => $post_excerpt,
                    'post_name'    => $staged_post_name,
                    // The union of every field this transaction has
                    // explicitly staged for this original.
                    'staged_fields' => array_values( $staged_fields ),
                )
            );
            $next_op['idempotency_key']     = $idem;
            $next_op['idempotency_actor']   = $actor;
            $next_op['idempotency_tx']      = $tx_id;
            $next_op['content_fingerprint'] = self::staged_payload_fingerprint_from_args(
                $post_title, $post_content, $post_excerpt, $staged_post_name
            );
            $next_op['updated_at']          = $now;
            $next_op['shadow_head']         = array(
                'op_id'        => $op_id,
                'after_sha256' => $post_content_sha256,
                'at'           => $now,
            );

            // 3. Attach the updated op to the journal
            //    row. The cas_write_row inside attach()
            //    guards the row bytes against the `$tx`
            //    we pass; `$tx` was re-read at the top of
            //    execute().
            if ( ! \AgentShell_MCP\Content_Journal::attach( $tx, $next_op ) ) {
                throw new \RuntimeException(
                    "advance_shadow: Content_Journal::attach refused the post-head write for "
                    . "op {$op_id}. The on-disk row changed between the re-read and the "
                    . "attach; the journal is the source of truth."
                );
            }

            // 4. Re-read for the head CAS. Same fix as
            //    fresh_stage.
            $tx_post_attach = (array) get_option( \AgentShell_MCP\Transaction_Manager::KEY, null );
            if ( empty( $tx_post_attach )
                || empty( $tx_post_attach['content']['ops'][ $op_id ] ) ) {
                throw new \RuntimeException(
                    "advance_shadow: the transaction row is no longer present after the op was "
                    . "re-attached. The on-disk row is the source of truth; the head-map advance "
                    . "is refused."
                );
            }

            // 5. CAS-update the head map for the *original*.
            //    The expected_prev is the previous
            //    after-image (the current head for
            //    original_id — the strong-after shape
            //    fresh_stage set, or the previous
            //    advancement's new_after). The new_after
            //    is the staged values the publish step
            //    will write.
            $expected_head = array(
                'post_type'           => (string) $original->post_type,
                'post_status'         => 'publish',
                'post_name'           => (string) ( $existing_op['after']['post_name'] ?? $staged_post_name ),
                'post_content_sha256' => (string) ( $existing_op['shadow_head']['after_sha256'] ?? '' ),
                'post_content_length' => strlen( (string) ( $existing_op['after']['post_content'] ?? '' ) ),
                'op_meta_value'       => $op_id,
            );
            $new_head = array(
                'post_type'           => (string) $original->post_type,
                'post_status'         => 'publish',
                'post_name'           => $staged_post_name,
                'post_content_sha256' => $post_content_sha256,
                'post_content_length' => strlen( $post_content ),
                'op_meta_value'       => $op_id,
            );
            $head_tx = \AgentShell_MCP\Content_Journal::compare_and_set_head(
                $tx_post_attach,
                (int) $original_id,
                $expected_head,
                $new_head
            );
            if ( ! is_array( $head_tx ) ) {
                throw new \RuntimeException(
                    "advance_shadow: compare_and_set_head refused the head update for original_id "
                    . "{$original_id} on op {$op_id}. A competing caller advanced the head. "
                    . "The shadow is updated, the op is re-attached; the head-map entry is "
                    . "unchanged and the staging is rolled back."
                );
            }

            // 6. Audit inside the SQL transaction.
            \AgentShell_MCP\Store::record_event(
                $actor,
                'transaction_edit_published_shadow_advanced',
                array(
                    'transaction_id'   => $tx_id,
                    'op_id'            => $op_id,
                    'original_id'      => (int) $original_id,
                    'shadow_id'        => (int) $shadow_id,
                    'new_after_sha256' => $post_content_sha256,
                    'old_after_sha256' => (string) ( $expected_head['post_content_sha256'] ?? '' ),
                )
            );

            return $next_op;
        } );

        if ( \AgentShell_MCP\Content_Write_Guard::is_failure( $result ) ) {
            throw new \RuntimeException(
                'advance_shadow: Content_Write_Guard reported a failure; the head advance was '
                . 'rolled back. The shadow, the op, the head-map entry, and the claim are '
                . 'unchanged.'
            );
        }

        return self::build_advanced_result( $result, $existing_op, $original_id, $actor, $tx_id, $idem );
    }

    // ── find_op_for_original ───────────────────────────────────────────

    /**
     * Find the journal op for this (tx_id, original_id) — the
     * authoritative lookup the §2.1 idempotency / head-
     * advancement rules use. Returns null if no op exists yet,
     * or the op array otherwise.
     */
    private static function find_op_for_original( array $content, $tx_id, $original_id ) {
        if ( ! isset( $content['ops'] ) || ! is_array( $content['ops'] ) ) {
            return null;
        }
        foreach ( $content['ops'] as $op ) {
            if ( ! is_array( $op ) ) { continue; }
            $op_tgt = ( isset( $op['target']['original_id'] ) && is_numeric( $op['target']['original_id'] ) )
                ? (int) $op['target']['original_id']
                : 0;
            if ( $op_tgt === $original_id
                && isset( $op['op'] )
                && \AgentShell_MCP\Content_Journal::OP_EDIT_PUBLISHED === (string) $op['op'] ) {
                return $op;
            }
        }
        return null;
    }

    // ── staged_payload_fingerprint ─────────────────────────────────────

    private static function staged_payload_fingerprint( array $op ) {
        $after = isset( $op['after'] ) && is_array( $op['after'] ) ? $op['after'] : array();
        return self::staged_payload_fingerprint_from_args(
            (string) ( $after['post_title']   ?? '' ),
            (string) ( $after['post_content'] ?? '' ),
            (string) ( $after['post_excerpt'] ?? '' ),
            (string) ( $after['post_name']    ?? '' )
        );
    }

    private static function staged_payload_fingerprint_from_args(
        $post_title, $post_content, $post_excerpt, $post_name
    ) {
        return hash( 'sha256',
            't:' . $post_title . "\n"
            . 'c:' . $post_content . "\n"
            . 'e:' . $post_excerpt . "\n"
            . 'n:' . $post_name
        );
    }

    // ── next_tool_for_state ────────────────────────────────────────────

    private static function next_tool_for_state( $state ) {
        switch ( $state ) {
            case 'publication_committed':
            case 'reconciliation_pending':
                return 'Call agentshell_publish_edit_reconcile to finish the published post and '
                     . 'release the staging state.';
            case 'publication_settled':
                return 'The op is settled; nothing to do.';
            case 'compensated':
                return 'The op was rolled back; the shadow is gone. Begin a new transaction to '
                     . 're-stage if needed.';
            case 'conflict':
            case 'abandoned':
                return 'Run agentshell_recover_transaction to resolve the conflict and clear the op.';
            default:
                return 'The op is in an unknown state; inspect the journal and audit log.';
        }
    }

    // ── result builders ────────────────────────────────────────────────

    /**
     * Summarise what a staged edit changed and what it inherited.
     *
     * `fields` names the fields the transaction explicitly authorised a
     * change to; `inherited` names the fields it left alone, which the
     * publish step will write back unchanged. An agent can therefore see,
     * without reading the journal, exactly what this edit will do to the
     * published post.
     *
     * @param array $op The journal op.
     * @return array{fields:string[], inherited:string[]}
     */
    private static function staged_summary( array $op ) : array {
        $authorised = array_values( array_map(
            'strval',
            (array) ( $op['after']['staged_fields'] ?? array() )
        ) );
        return array(
            'fields'    => $authorised,
            'inherited' => array_values( array_diff(
                array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ),
                $authorised
            ) ),
        );
    }

    private static function build_fresh_result( array $op, $original_id, $actor, $tx_id, $idem ) {
        return array(
            'original_id'  => (int) $original_id,
            'op_id'        => (string) $op['id'],
            'shadow_id'    => (int) ( $op['post_id'] ?? 0 ),
            'state'        => 'staged',
            'shadow_head'  => $op['shadow_head'],
            'claim'        => array(
                'tx_id'       => (string) $tx_id,
                'original_id' => (int) $original_id,
                'shadow_id'   => (int) ( $op['post_id'] ?? 0 ),
            ),
            'idempotent'   => array(
                'key'                => (string) $idem,
                'content_fingerprint'=> (string) ( $op['content_fingerprint'] ?? '' ),
                'actor'              => (string) $actor,
                'tx'                 => (string) $tx_id,
            ),
            'staged'       => self::staged_summary( $op ),
            'atomicity'    => array(
                'engine'     => (string) \AgentShell_MCP\Content_Write_Guard::engine(),
                'on_failure' => 'rolled_back',
                'not_covered'=> array(),
            ),
            'resumed'      => false,
            'advanced'     => false,
        );
    }

    private static function build_resumed_result( array $op, $original_id, $actor, $tx_id ) {
        return array(
            'original_id'  => (int) $original_id,
            'op_id'        => (string) ( $op['id'] ?? '' ),
            'shadow_id'    => (int) ( $op['post_id'] ?? 0 ),
            'state'        => 'staged',
            'shadow_head'  => $op['shadow_head'] ?? null,
            'claim'        => array(
                'tx_id'       => (string) ( $op['idempotency_tx'] ?? $tx_id ),
                'original_id' => (int) $original_id,
                'shadow_id'   => (int) ( $op['post_id'] ?? 0 ),
            ),
            'idempotent'   => array(
                'key'                => (string) ( $op['idempotency_key'] ?? '' ),
                'content_fingerprint'=> (string) ( $op['content_fingerprint'] ?? '' ),
                'actor'              => (string) ( $op['idempotency_actor'] ?? $actor ),
                'tx'                 => (string) ( $op['idempotency_tx'] ?? $tx_id ),
            ),
            'staged'       => self::staged_summary( $op ),
            'atomicity'    => array(
                'engine'     => (string) \AgentShell_MCP\Content_Write_Guard::engine(),
                'on_failure' => 'rolled_back',
                'not_covered'=> array(),
            ),
            'resumed'      => true,
            'advanced'     => false,
        );
    }

    private static function build_advanced_result( array $next_op, array $existing_op, $original_id, $actor, $tx_id, $idem ) {
        return array(
            'original_id'  => (int) $original_id,
            'op_id'        => (string) ( $next_op['id'] ?? $existing_op['id'] ?? '' ),
            'shadow_id'    => (int) ( $next_op['post_id'] ?? $existing_op['post_id'] ?? 0 ),
            'state'        => 'staged',
            'shadow_head'  => $next_op['shadow_head'] ?? null,
            'claim'        => array(
                'tx_id'       => (string) $tx_id,
                'original_id' => (int) $original_id,
                'shadow_id'   => (int) ( $next_op['post_id'] ?? $existing_op['post_id'] ?? 0 ),
            ),
            'idempotent'   => array(
                'key'                => (string) $idem,
                'content_fingerprint'=> (string) ( $next_op['content_fingerprint'] ?? '' ),
                'actor'              => (string) $actor,
                'tx'                 => (string) $tx_id,
            ),
            'staged'       => self::staged_summary( $next_op ),
            'atomicity'    => array(
                'engine'     => (string) \AgentShell_MCP\Content_Write_Guard::engine(),
                'on_failure' => 'rolled_back',
                'not_covered'=> array(),
            ),
            'resumed'      => false,
            'advanced'     => true,
        );
    }
}
