<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Post preview credentials: letting the screenshot browser render one
 * unpublished post without publishing it.
 *
 * The problem: headless Chrome carries no credentials, so a draft is
 * unreachable. The workarounds — publish-then-revert, injecting login cookies,
 * filtering the status check — each give up more than they buy.
 *
 * What this does instead: restore the *caller's own* WordPress identity for the
 * one request, and let core's existing preview path decide. WordPress already
 * enforces per-post capability for front-end previews
 * (`WP_Query::get_posts()`: anonymous refused; `protected` needs
 * `current_user_can('edit_post', $id)`; `private` needs `read_post`). The only
 * thing missing was an identity for the browser's request.
 *
 * The safety argument, stated precisely: the impersonated principal is the
 * authenticated MCP caller, not an administrator chosen to reach around
 * ownership. So this delegates authority the caller already holds rather than
 * granting any. Measured on a naive implementation, an unrestricted
 * `wp_set_current_user()` also opened unrelated private posts and the whole
 * REST API — which is why the credential is bound to one actor and one post,
 * the request is gated to a front-end GET, and the resolved post is checked
 * before a single byte of template output.
 */
class Post_Preview {
    /**
     * Query arguments carrying the credential.
     *
     * A bearer capability, like the transaction preview key: whoever holds it
     * can read one unpublished post as the actor who minted it.
     */
    const POST_ARG = 'agentshell_post';
    const USER_ARG = 'agentshell_post_user';
    const KEY_ARG  = 'agentshell_post_key';
    const EXP_ARG  = 'agentshell_post_exp';

    /**
     * Credential lifetime.
     *
     * Shorter than the transaction preview's, because this one carries a
     * person's authority rather than a design, and because it travels in a URL
     * — which web server access logs record. Short enough that a log line is a
     * dead end rather than a standing capability.
     */
    const TTL_SECONDS = 300;

    /** Post statuses this credential is for. Published posts need none of this. */
    const UNPUBLISHED_STATUSES = array( 'draft', 'pending', 'private', 'future' );

    /**
     * Can the current user preview this post?
     *
     * Checked when the credential is minted, against core's own capability
     * rules, so a tool call for someone else's private post fails at the call
     * rather than silently producing a 404 screenshot. Core re-checks at render
     * time; this check is not a substitute for it, only an earlier and clearer
     * failure.
     *
     * @param int $post_id Post to preview.
     * @return string One of: 'ok', 'no-user', 'no-post', 'published', 'forbidden'.
     */
    public static function authorize( $post_id ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return 'no-user';
        }
        $post = get_post( (int) $post_id );
        if ( ! $post ) {
            return 'no-post';
        }
        if ( ! in_array( $post->post_status, self::UNPUBLISHED_STATUSES, true ) ) {
            return 'published';
        }
        // Core's rule, not ours: protected statuses are edited to preview,
        // private ones are read.
        if ( 'private' === $post->post_status ) {
            return current_user_can( 'read_post', $post->ID ) ? 'ok' : 'forbidden';
        }
        return current_user_can( 'edit_post', $post->ID ) ? 'ok' : 'forbidden';
    }

    /**
     * Mint a credential for rendering one post as the current user.
     *
     * @param int $post_id Post to preview.
     * @return array Query arguments to attach to the preview URL.
     * @throws \InvalidArgumentException when the caller may not preview it, or
     *         when there is no secret to sign with.
     */
    public static function preview_args( $post_id ) {
        $verdict = self::authorize( $post_id );
        if ( 'ok' !== $verdict ) {
            throw new \InvalidArgumentException( self::explain( $verdict, $post_id ) );
        }

        $secret = self::preview_secret();
        if ( '' === $secret ) {
            throw new \InvalidArgumentException(
                'Cannot authorise a post preview: neither AGENTSHELL_REST_TOKEN nor AUTH_SALT is defined. '
                . 'Without a signing secret the credential could be forged, so no preview is offered.'
            );
        }

        $user_id = (int) get_current_user_id();
        $expires = time() + self::TTL_SECONDS;

        return array(
            self::POST_ARG => (string) $post_id,
            self::USER_ARG => (string) $user_id,
            self::EXP_ARG  => (string) $expires,
            self::KEY_ARG  => self::sign( $post_id, $user_id, $expires, $secret ),
        );
    }

    /**
     * HMAC over the actor, the post and the expiry.
     *
     * The actor id is signed so a credential minted for one user cannot be
     * replayed as another, and the post id so it cannot be pointed at
     * different content. The expiry is signed so the window cannot be stretched
     * by editing the parameter.
     */
    private static function sign( $post_id, $user_id, $expires, $secret ) {
        return hash_hmac(
            'sha256',
            'agentshell-post-preview|' . (int) $post_id . '|' . (int) $user_id . '|' . (int) $expires,
            $secret
        );
    }

    /**
     * Verify a credential and return the identity it speaks for.
     *
     * The actor id travels as its own query argument rather than being
     * recovered from the signature: an HMAC is one-way, and the browser's
     * request has no identity of its own to read it from. Carrying it in the
     * clear costs nothing — a user id is not a secret — and the signature is
     * what makes it trustworthy, since any edit to it invalidates the match.
     *
     * Returns the user id to restore, or 0 to render as an ordinary anonymous
     * visitor. Fails closed on every path: missing pieces, a non-numeric
     * expiry, an expired window, or a signature mismatch.
     *
     * Note what is *not* checked here: whether this request may be a preview at
     * all, and whether it ends up on the bound post. Those are separate gates —
     * a signature that verifies still proves nothing about the route being
     * requested or the post being resolved.
     *
     * @return int User id to impersonate, or 0.
     */
    public static function authenticate( $post_id, $user_id, $key, $expires ) {
        $secret = self::preview_secret();
        if ( '' === $secret || ! is_string( $key ) || '' === $key ) {
            return 0;
        }
        if ( ! is_string( $post_id ) || '' === $post_id ) {
            return 0;
        }
        if ( ! is_string( $user_id ) || '' === $user_id ) {
            return 0;
        }
        if ( ! is_string( $expires ) && ! is_int( $expires ) ) {
            return 0;
        }
        $post_id = (int) $post_id;
        $user_id = (int) $user_id;
        $expires = (int) $expires;

        // A zero or non-numeric expiry casts to 0, which is always in the past,
        // so one comparison covers "expired", "zero" and "not a number" alike.
        if ( $expires < time() ) {
            return 0;
        }
        // Anonymous has no identity to restore, and a credential naming user 0
        // would be a way to preview a post nobody is allowed to read.
        if ( $post_id < 1 || $user_id < 1 ) {
            return 0;
        }
        // The user must still exist: a deleted account must not be resurrected
        // by a credential minted while it existed. With no caps, core's own
        // preview check then refuses the post.
        if ( ! get_userdata( $user_id ) ) {
            return 0;
        }
        if ( ! hash_equals( self::sign( $post_id, $user_id, $expires, $secret ), $key ) ) {
            return 0;
        }
        return $user_id;
    }

    /**
     * Signing secret. Shared with the transaction preview so there is one place
     * to look when deciding what this secret is worth.
     */
    public static function preview_secret() {
        return Transaction_Manager::preview_secret();
    }

    private static function explain( $verdict, $post_id ) {
        switch ( $verdict ) {
            case 'no-user':
                return 'Previewing an unpublished post requires an authenticated caller.';
            case 'no-post':
                return "No post with id {$post_id}.";
            case 'published':
                return "Post {$post_id} is already published, so it can be captured without a preview "
                    . 'credential. Call agentshell_screenshot(url=…) with its permalink.';
            case 'forbidden':
                return "You do not have permission to read or edit post {$post_id}. "
                    . 'WordPress refuses this for drafts and private posts unless you own them or hold '
                    . 'the matching capability; that check is not something a preview bypasses.';
            default:
                return 'Post preview could not be authorised.';
        }
    }

    /**
     * Remove post-preview credentials from a caller-supplied URL.
     *
     * The screenshot tool mints its own, so anything arriving in a `url`
     * argument is stale or borrowed — and the audit log records arguments
     * verbatim, so a key pasted into a URL would otherwise be persisted there.
     */
    public static function strip_args( $url ) {
        return remove_query_arg(
            array( self::POST_ARG, self::USER_ARG, self::KEY_ARG, self::EXP_ARG ),
            (string) $url
        );
    }

    /**
     * Remove the credential from a URL destined for a caller.
     *
     * The post id and expiry survive — useless without the signature — so a
     * returned URL stays diagnosable.
     */
    public static function redact( $url ) {
        return remove_query_arg( array( self::KEY_ARG ), (string) $url );
    }
}