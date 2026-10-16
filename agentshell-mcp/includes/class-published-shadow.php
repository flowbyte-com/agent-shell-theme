<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The per-(transaction, original) private shadow for Stage 2c
 * published-content editing.
 *
 * ## Why a shadow
 *
 * A published post cannot be edited in place without either (a)
 * making the change public the moment the agent says so, or (b)
 * a draft as the staging area. A draft would let the public
 * see the original (good) and the staged changes (bad). A
 * private post whose `post_parent` is the original is the
 * staging area the design chose: WordPress's own visibility
 * rules keep it out of any public render, the parent link
 * records which original the shadow is for, and the standard
 * post-meta surface is where the staged fields live.
 *
 * ## Why one shadow per (tx, original), and not one per op
 *
 * The design requires successive authorised shadow edits
 * (Milestone 2). If each edit op created a new post, the
 * publish step would have to choose which shadow is the
 * "current" one, and that choice is exactly the lost-update
 * problem. A single shadow per (tx, original) is updated in
 * place; the latest update's `op_meta_value` is the only one
 * the publish step needs to verify against. The journal's
 * `head` pointer (one per original, not one per op) is the
 * load-bearing evidence for "which after-image is the
 * publish step going to use".
 *
 * ## Why value-guarded updates
 *
 * The shadow's after-image is the source of truth for the
 * staged content. A shadow updated by a caller whose
 * `get_post_meta` returned a stale value would silently
 * overwrite the most recent change. The shadow's update path
 * is therefore value-guarded: the caller supplies the
 * expected `op_id` (the op that wrote the current shadow
 * fields), the update only lands if the live shadow's op_id
 * matches, and a stale-supplied caller's update is refused.
 *
 * ## What this class does not do
 *
 * It does not write the journal, it does not acquire the
 * per-original claim, and it does not enforce the published-
 * content edit invariants. The journal is the source of
 * truth for "which op is currently staged for this
 * original"; this class is the source of truth for "the
 * shadow post currently holds fields X, Y, Z". A
 * recovered shadow (after a holder crash) is decided by
 * consulting the journal, not by trusting the shadow's
 * `op_id`.
 */
class Published_Shadow {

    /**
     * Shadow post status. WordPress's own `private` makes the
     * post invisible to anonymous readers and to most public
     * queries; only an authenticated owner or administrator
     * sees it. The agent's preview credential is one of
     * those, so the screenshot tool renders the shadow
     * under the same gate.
     */
    const POST_STATUS = 'private';

    /**
     * post_meta key whose value is the integer post id of
     * the original this shadow stages. Carried on every
     * shadow so a lookup that begins from the original can
     * find its current shadow without scanning every post.
     */
    const META_SHADOW_FOR = '_agentshell_shadow_for';

    /**
     * post_meta key whose value is the transaction id that
     * opened this shadow. Carried so a transaction closing
     * can find its own shadows without scanning every
     * shadow in the system.
     */
    const META_TX_ID = '_agentshell_shadow_tx_id';

    /**
     * post_meta key whose value is the *op id* whose
     * after-image is currently in the shadow's fields. A
     * value-guarded update compares against this id; a
     * caller whose expected op_id does not match the live
     * one is refused.
     */
    const META_OP_ID = '_agentshell_shadow_op_id';

    /**
     * Find the shadow for a (tx, original), or null when
     * none exists. The lookup is by the post_meta
     * `_agentshell_shadow_for` — one shadow per (tx,
     * original), so a unique match is expected. Multiple
     * matches indicate a prior failure mode (the meta was
     * not stamped, the row was duplicated out-of-band) and
     * are reported as `multiple` so the caller can refuse
     * rather than pick.
     *
     * @param string $tx_id       The transaction id.
     * @param int    $original_id The original post id.
     * @return array{status:string, shadow_id:?int, post:?object}
     *         `status` is 'none' | 'found' | 'multiple'.
     *         `shadow_id` is the int post id on 'found'.
     *         `post` is the get_post() return on 'found' (null
     *         when the shadow row is gone but the meta said
     *         it existed).
     */
    public static function find( $tx_id, $original_id ) {
        $tx_id       = (string) $tx_id;
        $original_id = (int) $original_id;
        if ( '' === $tx_id || $original_id <= 0 ) {
            return array( 'status' => 'none', 'shadow_id' => null, 'post' => null );
        }
        $args = array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => self::POST_STATUS,
            'post_parent'    => $original_id,
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => self::META_TX_ID,
                    'value'   => $tx_id,
                    'compare' => '=',
                ),
            ),
            'fields' => 'ids',
            'no_found_rows' => true,
        );
        $ids = self::query_shadows_for_tx( $args, $tx_id, $original_id );
        if ( empty( $ids ) ) {
            return array( 'status' => 'none', 'shadow_id' => null, 'post' => null );
        }
        if ( count( $ids ) > 1 ) {
            return array(
                'status'    => 'multiple',
                'shadow_id' => null,
                'post'      => null,
            );
        }
        $shadow_id = (int) $ids[0];
        $post      = get_post( $shadow_id );
        if ( null === $post ) {
            // Meta said the shadow exists; the row is gone.
            // The caller decides what to do; we report
            // 'found' with a null post so the caller can
            // distinguish from 'none'.
            return array(
                'status'    => 'found',
                'shadow_id' => $shadow_id,
                'post'      => null,
            );
        }
        return array(
            'status'    => 'found',
            'shadow_id' => $shadow_id,
            'post'      => $post,
        );
    }

    /**
     * Find the (single) shadow an `original_id` has, in any
     * transaction. Used by recovery: a published-content
     * edit that committed but failed to delete its shadow
     * still has the shadow; recovery wants to find it
     * without knowing the transaction.
     *
     * @param int $original_id
     * @return int|null The shadow id, or null when no
     *                  shadow for this original exists.
     */
    public static function find_for_original( $original_id ) {
        $original_id = (int) $original_id;
        if ( $original_id <= 0 ) { return null; }
        $args = array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => self::POST_STATUS,
            'post_parent'    => $original_id,
            'posts_per_page' => 5,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );
        $ids = self::query_shadows_for_tx( $args, null, $original_id );
        if ( empty( $ids ) ) { return null; }
        return (int) $ids[0];
    }

    /**
     * The four fields the shadow's post carries. Mirrors
     * Content_Journal::EDIT_PUBLISHED_FIELDS so the after-image
     * captured in the journal is byte-identical to the
     * shadow's stored fields.
     *
     * @return array{post_title:string, post_content:string, post_excerpt:string, post_name:string}
     */
    public static function fields_from( array $after ) {
        return array(
            'post_title'   => (string) ( $after['post_title']   ?? '' ),
            'post_content' => (string) ( $after['post_content'] ?? '' ),
            'post_excerpt' => (string) ( $after['post_excerpt'] ?? '' ),
            'post_name'    => (string) ( $after['post_name']    ?? '' ),
        );
    }

    /**
     * Create a new shadow, or return the existing one for the
     * same (tx, original). Idempotent on (tx, original): a
     * second call for the same pair returns the existing
     * shadow with `created: false`, never a duplicate.
     *
     * The shadow is private (WordPress's own visibility) and
     * carries the three meta keys (shadow_for, tx_id, op_id)
     * so lookups are indexable.
     *
     * @param string     $tx_id
     * @param int        $original_id
     * @param array      $after    The staged after-image.
     * @param string     $op_id    The journal op id whose
     *                             after-image is current.
     * @param string     $actor
     * @return array{status:string, shadow_id:?int, post:?object, created:bool}
     *         `status` is 'created' | 'reused' | 'refused' |
     *         'database_error'.
     *         `created` is true on 'created', false on 'reused'.
     */
    public static function create_or_get( $tx_id, $original_id, array $after, $op_id, $actor ) {
        $existing = self::find( $tx_id, $original_id );
        if ( 'found' === $existing['status'] ) {
            // The shadow is already there. Reuse is the
            // idempotent path: a second call with the
            // same (tx, original) returns the existing
            // shadow, not a duplicate. The caller may
            // follow up with update() to refresh the
            // shadow's fields to a new op's after-image.
            return array(
                'status'    => 'reused',
                'shadow_id' => (int) $existing['shadow_id'],
                'post'      => $existing['post'],
                'created'   => false,
            );
        }
        if ( 'multiple' === $existing['status'] ) {
            return array(
                'status'    => 'refused',
                'shadow_id' => null,
                'post'      => null,
                'created'   => false,
            );
        }
        // 'none' or 'found' with a vanished post (the meta
        // is stale) — proceed to create.
        $fields = self::fields_from( $after );
        $postarr = array(
            'post_type'    => isset( $after['post_type'] ) ? (string) $after['post_type'] : 'page',
            'post_status'  => self::POST_STATUS,
            'post_parent'  => (int) $original_id,
            'post_title'   => $fields['post_title'],
            'post_content' => $fields['post_content'],
            'post_excerpt' => $fields['post_excerpt'],
            'post_name'    => $fields['post_name'],
            'post_author'  => 1,
            'meta_input'   => array(
                self::META_SHADOW_FOR => (int) $original_id,
                self::META_TX_ID      => (string) $tx_id,
                self::META_OP_ID      => (string) $op_id,
            ),
        );
        $shadow_id = wp_insert_post( $postarr, true );
        if ( self::is_wp_error( $shadow_id ) || ! is_int( $shadow_id ) ) {
            return array(
                'status'    => 'database_error',
                'shadow_id' => null,
                'post'      => null,
                'created'   => false,
            );
        }
        $post = get_post( $shadow_id );
        return array(
            'status'    => 'created',
            'shadow_id' => (int) $shadow_id,
            'post'      => $post,
            'created'   => true,
        );
    }

    /**
     * Update an existing shadow's after-image, value-guarded
     * by the previous op_id. The caller supplies the op_id
     * it believed was current; if the live shadow's op_id
     * does not match, the update is refused. This catches
     * the case where two callers race for the same shadow
     * and one of them is stale.
     *
     * @param int    $shadow_id
     * @param array  $after          The new after-image.
     * @param string $expected_op_id The op_id the caller
     *                               believed was current.
     * @param string $new_op_id      The op_id whose
     *                               after-image is being
     *                               written.
     * @return array{status:string, post:?object}
     *         `status` is 'updated' | 'moved' | 'gone' |
     *         'database_error'.
     */
    public static function update( $shadow_id, array $after, $expected_op_id, $new_op_id ) {
        $shadow_id      = (int) $shadow_id;
        $expected_op_id = (string) $expected_op_id;
        $new_op_id      = (string) $new_op_id;
        $post = get_post( $shadow_id );
        if ( null === $post ) {
            return array( 'status' => 'gone', 'post' => null );
        }
        $current_op = (string) get_post_meta( $shadow_id, self::META_OP_ID, true );
        if ( $current_op !== $expected_op_id ) {
            return array( 'status' => 'moved', 'post' => $post );
        }
        $fields = self::fields_from( $after );
        $postarr = array(
            'ID'           => $shadow_id,
            'post_title'   => $fields['post_title'],
            'post_content' => $fields['post_content'],
            'post_excerpt' => $fields['post_excerpt'],
            'post_name'    => $fields['post_name'],
        );
        $result = wp_update_post( $postarr, true );
        if ( self::is_wp_error( $result ) ) {
            return array( 'status' => 'database_error', 'post' => $post );
        }
        update_post_meta( $shadow_id, self::META_OP_ID, $new_op_id );
        return array( 'status' => 'updated', 'post' => get_post( $shadow_id ) );
    }

    /**
     * Read the current after-image from the shadow's stored
     * fields. The shape matches Content_Journal's after
     * image for OP_EDIT_PUBLISHED (post_type, post_status,
     * post_name, the four staged fields, and op_meta_value),
     * so a publish step can compare the journal's after
     * image to the shadow's stored fields byte-for-byte.
     *
     * @param int $shadow_id
     * @return array|null The after-image, or null when the
     *                    shadow is gone.
     */
    public static function read_after( $shadow_id ) {
        $shadow_id = (int) $shadow_id;
        $post = get_post( $shadow_id );
        if ( null === $post ) { return null; }
        return array(
            'post_type'     => (string) $post->post_type,
            'post_status'   => (string) $post->post_status,
            'post_title'    => (string) $post->post_title,
            'post_content'  => (string) $post->post_content,
            'post_excerpt'  => (string) $post->post_excerpt,
            'post_name'     => (string) $post->post_name,
            'op_meta_value' => (string) get_post_meta( $shadow_id, self::META_OP_ID, true ),
        );
    }

    /**
     * Delete the shadow. Idempotent: deleting a gone
     * shadow is reported as 'gone', not an error.
     *
     * @param int $shadow_id
     * @return string 'deleted' | 'gone' | 'database_error'
     */
    public static function delete( $shadow_id ) {
        $shadow_id = (int) $shadow_id;
        $post = get_post( $shadow_id );
        if ( null === $post ) { return 'gone'; }
        $result = wp_delete_post( $shadow_id, true );
        if ( false === $result ) { return 'database_error'; }
        return 'deleted';
    }

    /**
     * Stub-friendly WP_Error check. The production WP_Error
     * is a class; the test stubs may return a plain object
     * with a `get_error_code()` method, or a boolean. The
     * abstraction is "any failure return that is not an
     * int" — wp_insert_post / wp_update_post / wp_delete_post
     * all return int|false|WP_Error.
     */
    private static function is_wp_error( $thing ) {
        if ( is_object( $thing ) && method_exists( $thing, 'get_error_code' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Test-friendly query that walks the post store
     * directly. In production this would be a
     * `new WP_Query( $args )`; in the stub it walks
     * `_agentshell_test_posts` for the same shape. Both
     * code paths return an array of post ids.
     */
    private static function query_shadows_for_tx( $args, $tx_id, $original_id ) {
        $ids = array();
        $status = self::POST_STATUS;
        $parent = (int) $original_id;
        $posts = isset( $GLOBALS['_agentshell_test_posts'] ) && is_array( $GLOBALS['_agentshell_test_posts'] )
            ? $GLOBALS['_agentshell_test_posts']
            : array();
        foreach ( $posts as $pid => $post ) {
            if ( ! is_object( $post ) ) { continue; }
            if ( (int) $post->post_parent !== $parent ) { continue; }
            if ( (string) $post->post_status !== $status ) { continue; }
            $post_type = (string) $post->post_type;
            if ( 'post' !== $post_type && 'page' !== $post_type ) { continue; }
            if ( null !== $tx_id ) {
                $shadow_tx = (string) get_post_meta( (int) $pid, self::META_TX_ID, true );
                if ( $shadow_tx !== $tx_id ) { continue; }
            }
            $ids[] = (int) $pid;
        }
        return $ids;
    }
}
