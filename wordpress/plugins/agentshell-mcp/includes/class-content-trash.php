<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-content-write-guard.php';

/**
 * Reversible content deletion: moving content to the Trash, and bringing it
 * back.
 *
 * Why this exists
 * ---------------
 * The content tools could create, update, publish and unpublish, and there
 * was no way to *remove* something. The obvious answer — a delete tool
 * calling `wp_delete_post()` — is the one thing this class deliberately does
 * not provide: WordPress's delete is permanent, it skips the Trash, and it
 * rewrites the slug on the way, so the object that is left behind is not
 * the object the caller was talking about. There is no `force` argument
 * anywhere in this file, and there will not be one.
 *
 * What it does instead is what a person does: move the post to the Trash,
 * where it can be looked at, and restore it if that was a mistake. Both
 * operations are idempotent — trashing a trashed post, or restoring one
 * that was never trashed, reports what it found and changes nothing — so a
 * retried call is safe.
 *
 * The safeguards
 * --------------
 * A deletion is the one content operation an agent can lose data with, so
 * the checks are explicit and they are checked *before* anything is
 * written:
 *
 *   - The content must exist and be of the type named. Identity is
 *     explicit; a post is never "the latest one".
 *   - The caller must hold the post type's `delete_post` capability *for
 *     that post*. An administrator who may edit a page but may not delete
 *     it is refused, and an editor's draft cannot be trashed by somebody
 *     who merely has `manage_options`.
 *   - The call refuses inside an open transaction, like every other content
 *     write that is not journal-aware (§6.1).
 *   - Content that a transaction is holding is refused: a draft carrying
 *     the journal marker, a post with a live published-edit shadow, and a
 *     post with a fresh claim. Those are somebody's uncommitted work, and
 *     the recovery engine resolves them by evidence — a trashed post no
 *     longer matches the evidence it recorded, so the operation would end
 *     in a conflict instead of a clean recovery.
 *
 * Every write runs inside `Content_Write_Guard`, so a failed trash leaves
 * the content exactly as it was.
 */
class Content_Trash {
    /**
     * Move a post or page to the Trash.
     *
     * @param int    $id   The post id.
     * @param string $type 'post' or 'page'.
     * @return array The record.
     * @throws \InvalidArgumentException When the operation is refused.
     */
    public static function trash( $id, $type = 'page' ) {
        $post = self::require_deletable( $id, $type );

        if ( 'trash' === $post->post_status ) {
            // Idempotent: the content is already in the Trash. Say so and
            // change nothing.
            return self::record(
                $post,
                array(
                    'action'       => 'trash',
                    'already'      => true,
                    'status'       => 'trash',
                    'previous'     => (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true ),
                    'changed'      => false,
                )
            );
        }

        $previous = (string) $post->post_status;

        $record = Content_Write_Guard::run( function () use ( $post, $previous ) {
            $trashed = wp_trash_post( $post->ID );
            if ( ! $trashed ) {
                throw new \InvalidArgumentException(
                    'Could not move #' . (int) $post->ID . ' to the Trash. WordPress refused the operation.'
                );
            }
            return self::describe( $post->ID );
        } );

        $record['atomicity'] = Content_Write_Guard::report();
        $record['previous_status'] = $previous;
        $record['changed']    = true;
        $record['action']     = 'trash';
        $record['already']    = false;

        Store::record_event(
            Store::actor(),
            'content_trashed',
            array(
                'post_id'        => (int) $post->ID,
                'post_type'      => (string) $post->post_type,
                'previous_status' => $previous,
                'new_status'     => 'trash',
            )
        );

        return $record;
    }

    /**
     * Restore a trashed post or page.
     *
     * @param int    $id     The post id.
     * @param string $type   'post' or 'page'.
     * @param string $status 'draft' (WordPress's own behaviour: a restored
     *                       post comes back as a draft) or 'previous' (the
     *                       status it had before it was trashed).
     * @return array The record.
     * @throws \InvalidArgumentException When the operation is refused.
     */
    public static function restore( $id, $type = 'page', $status = 'draft' ) {
        $post = self::require_deletable( $id, $type );

        if ( 'draft' !== $status && 'previous' !== $status ) {
            throw new \InvalidArgumentException(
                "Unsupported restore status '{$status}'. Use 'draft' (WordPress's own behaviour: restored content comes back as a draft) or 'previous' (the status it had before it was trashed)."
            );
        }

        if ( 'trash' !== $post->post_status ) {
            // Idempotent: the content is not in the Trash, so there is
            // nothing to restore. Say so and change nothing.
            return self::record(
                $post,
                array(
                    'action'   => 'restore',
                    'already'  => true,
                    'status'   => (string) $post->post_status,
                    'changed'  => false,
                )
            );
        }

        $record = Content_Write_Guard::run( function () use ( $post, $status ) {
            // WordPress restores trashed content as a *draft* since 5.6:
            // the trash meta that holds the previous status is deleted by
            // `wp_untrash_post()` before the write, and the status comes
            // from the `wp_untrash_post_status` filter. Core ships
            // `wp_untrash_post_set_previous_status()` for the older
            // behaviour, so "put it back exactly as it was" is one filter,
            // not a re-implementation of the untrash.
            if ( 'previous' === $status ) {
                add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
            }
            try {
                $restored = wp_untrash_post( $post->ID );
            } finally {
                if ( 'previous' === $status ) {
                    remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
                }
            }
            if ( ! $restored ) {
                throw new \InvalidArgumentException(
                    'Could not restore #' . (int) $post->ID . ' from the Trash. WordPress refused the operation.'
                );
            }
            return self::describe( $post->ID );
        } );

        $record['atomicity'] = Content_Write_Guard::report();
        $record['previous_status'] = 'trash';
        $record['changed']    = true;
        $record['action']     = 'restore';
        $record['already']    = false;
        $record['restored_as'] = (string) $record['status'];

        Store::record_event(
            Store::actor(),
            'content_restored',
            array(
                'post_id'        => (int) $post->ID,
                'post_type'      => (string) $post->post_type,
                'previous_status' => 'trash',
                'new_status'     => (string) $record['status'],
            )
        );

        return $record;
    }

    /**
     * The record for an operation that changed nothing.
     */
    private static function record( $post, array $extra ) {
        $record         = self::describe( $post->ID );
        $record['atomicity'] = Content_Write_Guard::report();
        return array_merge( $record, $extra );
    }

    /**
     * A compact record. Deliberately compact: the full body is not echoed
     * back, because the caller's question is "what happened to this post",
     * not "what does it contain" (§ B5 of the programme).
     */
    private static function describe( $id ) {
        $post = get_post( (int) $id );
        if ( ! $post ) {
            return array( 'id' => (int) $id );
        }
        return array(
            'id'       => (int) $post->ID,
            'title'    => get_the_title( $post ),
            'type'     => (string) $post->post_type,
            'status'   => (string) $post->post_status,
            'slug'     => (string) $post->post_name,
            'modified' => (string) $post->post_modified_gmt,
        );
    }

    /**
     * The checks every trash and restore operation makes first.
     *
     * @param int    $id   The post id.
     * @param string $type 'post' or 'page'.
     * @return \WP_Post
     * @throws \InvalidArgumentException When the operation is refused.
     */
    private static function require_deletable( $id, $type ) {
        $id = (int) $id;
        if ( $id <= 0 ) {
            throw new \InvalidArgumentException( 'A post id is required.' );
        }
        if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== $type ) {
            throw new \InvalidArgumentException(
                $type . ' #' . $id . ' not found.'
            );
        }

        // The capability is checked against *this* post, not against the
        // role in general: `delete_post` maps to `delete_others_posts` for
        // somebody else's content, so an editor cannot trash a page they
        // do not own, and a subscriber cannot trash anything.
        $post_type = get_post_type_object( $post->post_type );
        $cap       = $post_type ? $post_type->cap->delete_post : 'delete_post';
        if ( ! current_user_can( $cap, $post->ID ) ) {
            throw new \InvalidArgumentException(
                'The ' . $cap . ' capability is required to move #' . $id . ' to the Trash.'
            );
        }

        // A content write that is not journal-aware refuses inside a
        // transaction: the transaction's commit and rollback assume that
        // the content they are reasoning about is not being changed
        // underneath them (§6.1).
        if ( Transaction_Manager::instance()->is_active() ) {
            throw new \InvalidArgumentException(
                'This operation refuses inside an open transaction. Trashing and restoring are not '
                . 'journal-aware status changes, so they are refused while a transaction is open rather '
                . 'than performed outside it. Close the transaction (commit or rollback) and call it again.'
            );
        }

        self::require_unclaimed( $post );

        return $post;
    }

    /**
     * Refuse content that a transaction or a published edit is holding.
     *
     * @param \WP_Post $post The post.
     * @throws \InvalidArgumentException When the content is claimed.
     */
    private static function require_unclaimed( $post ) {
        // A draft created inside a transaction carries the journal marker.
        // The recovery engine verifies a post by six recorded fields
        // before it deletes it; a trashed post does not match them, so the
        // operation would end in a conflict rather than a clean recovery.
        $marker = get_post_meta( $post->ID, Content_Journal::OP_META, true );
        if ( '' !== (string) $marker ) {
            throw new \InvalidArgumentException(
                '#' . (int) $post->ID . ' is recorded in a content journal (operation ' . (string) $marker . '), '
                . 'so it is uncommitted work. Resolve that transaction before trashing it.'
            );
        }

        // A published edit stages its changes in a private shadow whose
        // parent is the original. Trashing the original would leave the
        // shadow pointing at nothing.
        if ( Published_Shadow::find_for_original( (int) $post->ID ) ) {
            throw new \InvalidArgumentException(
                '#' . (int) $post->ID . ' has a live published-edit shadow, so a staged edit is still '
                . 'attached to it. Publish or reconcile that edit before trashing the original.'
            );
        }

        // The post may itself be a shadow.
        $shadow_for = (int) get_post_meta( $post->ID, Published_Shadow::META_SHADOW_FOR, true );
        if ( $shadow_for > 0 ) {
            throw new \InvalidArgumentException(
                '#' . (int) $post->ID . ' is a published-edit shadow of #' . $shadow_for . '. Reconcile '
                . 'that edit before removing it.'
            );
        }

        // A fresh claim means another actor holds the original right now.
        $claim = Published_Claim::read( (int) $post->ID );
        if ( is_array( $claim ) && Published_Claim::is_fresh( $claim ) ) {
            throw new \InvalidArgumentException(
                '#' . (int) $post->ID . ' is claimed by a published edit that is still live. Reconcile it '
                . 'before trashing the original.'
            );
        }
    }
}
