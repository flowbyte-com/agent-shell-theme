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

    // ──────────────────────────────────────────────────────────────────────
    //  Published-content preview credentials (Stage 2c Commit 2)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Query argument carrying the published-content preview credential.
     *
     * NOT registered as a wp_query_var. The overlay reads it from $_GET
     * directly so WP_Query and any persistent cache that buckets by query
     * vars does not differentiate preview URLs by their credential. The
     * public boolean (agentshell_preview) IS a wp_query_var, so the theme
     * loader can detect the request.
     */
    const PUB_PREVIEW_CRED_ARG = 'agentshell_pub_cred';

    /**
     * The public flag the overlay registers as a wp_query_var.
     *
     * Set to '1' on every preview URL. The actual credential travels in
     * PUB_PREVIEW_CRED_ARG as a base64-encoded JSON blob.
     */
    const PUB_PREVIEW_FLAG_ARG = 'agentshell_preview';

    /**
     * Same window as the unpublished post preview. A URL-riding capability
     * that lives in browser history and server access logs deserves the
     * same short lifespan.
     */
    const PUB_PREVIEW_TTL_SECONDS = 300;

    /**
     * Mint a credential for rendering one PUBLISHED post with its staged
     * shadow overlaid. Bound to (original_id, shadow_id, tx_id, actor,
     * expires) so a credential issued for one staging cannot be replayed
     * against another, and cannot be replayed across actors.
     *
     * Returns the base64-encoded JSON blob that goes into the URL.
     *
     * @param int    $original_id Published post being previewed.
     * @param int    $shadow_id   Private shadow carrying the four staged fields.
     * @param string $tx_id       Open transaction id (from the journal).
     * @param int    $actor_id    MCP caller (the editor, not the browser).
     * @return string Base64-encoded JSON blob.
     * @throws \InvalidArgumentException when no signing secret is available.
     */
    public static function preview_args_for_published( $original_id, $shadow_id, $tx_id, $actor_id ) {
        $secret = self::preview_secret();
        if ( '' === $secret ) {
            throw new \InvalidArgumentException(
                'Cannot authorise a published-content preview: neither AGENTSHELL_REST_TOKEN nor '
                . 'AUTH_SALT is defined. Without a signing secret the credential could be forged, '
                . 'so no preview is offered.'
            );
        }
        $oid   = (int) $original_id;
        $sid   = (int) $shadow_id;
        $actor = (int) $actor_id;
        $tx    = (string) $tx_id;
        $exp   = time() + self::PUB_PREVIEW_TTL_SECONDS;
        $cred  = array(
            'oid'   => $oid,
            'sid'   => $sid,
            'tx'    => $tx,
            'actor' => $actor,
            'exp'   => $exp,
            'key'   => self::sign_published_hmac( $oid, $sid, $tx, $actor, $exp, $secret ),
        );
        return base64_encode( wp_json_encode( $cred ) );
    }

    /**
     * URL arguments for a published-content preview request.
     *
     * Two query parameters: the public boolean flag and the credential
     * blob. The flag is registered as a wp_query_var; the credential is
     * NOT (read from $_GET directly) so a persistent cache bucketed by
     * query vars cannot differentiate URLs by their credential content.
     *
     * @return array<string,string>  query arguments to add to the URL.
     */
    public static function published_preview_url_args( $original_id, $shadow_id, $tx_id, $actor_id ) {
        return array(
            self::PUB_PREVIEW_FLAG_ARG => '1',
            self::PUB_PREVIEW_CRED_ARG => self::preview_args_for_published(
                $original_id, $shadow_id, $tx_id, $actor_id
            ),
        );
    }

    /**
     * HMAC over (oid, sid, tx, actor, exp). Bound to the (original, shadow)
     * pair so a credential for one staging cannot preview a different
     * original's shadow, or a different shadow's original, or be replayed
     * across actors, or outlive its window.
     */
    public static function sign_published_hmac( $oid, $sid, $tx, $actor, $exp, $secret ) {
        return hash_hmac(
            'sha256',
            'agentshell-published-edit-preview|'
                . (int) $oid . '|'
                . (int) $sid . '|'
                . (string) $tx . '|'
                . (int) $actor . '|'
                . (int) $exp,
            $secret
        );
    }

    /**
     * Verify a published-content preview credential's HMAC and basic shape.
     * Returns the decoded credential array on success, null on any failure.
     *
     * Does NOT consult the journal — the overlay does that separately to
     * reject state-terminal ops. This is just the cryptographic gate.
     *
     * @param string $raw_cred The base64-encoded JSON blob from the URL.
     * @param string $secret   Override the resolved preview secret (used by tests).
     * @return array{oid:int,sid:int,tx:string,actor:int,exp:int}|null
     */
    public static function verify_published_hmac( $raw_cred, $secret = '' ) {
        if ( '' === $secret ) {
            $secret = self::preview_secret();
        }
        if ( '' === $secret || ! is_string( $raw_cred ) || '' === $raw_cred ) {
            return null;
        }
        $blob = base64_decode( $raw_cred, true );
        if ( false === $blob ) {
            return null;
        }
        $decoded = json_decode( $blob, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }
        foreach ( array( 'oid', 'sid', 'tx', 'actor', 'exp', 'key' ) as $required ) {
            if ( ! array_key_exists( $required, $decoded ) ) {
                return null;
            }
        }
        $oid   = (int) $decoded['oid'];
        $sid   = (int) $decoded['sid'];
        $actor = (int) $decoded['actor'];
        $exp   = (int) $decoded['exp'];
        // Zero or non-numeric ids are caught by these < 1 checks. A zero or
        // negative exp casts from a string passes (int) and would compare
        // < time() too, but the < 1 catch is a clearer failure.
        if ( $oid < 1 || $sid < 1 || $actor < 1 || $exp < time() ) {
            return null;
        }
        if ( ! is_string( $decoded['tx'] ) || '' === $decoded['tx'] ) {
            return null;
        }
        if ( ! is_string( $decoded['key'] ) || '' === $decoded['key'] ) {
            return null;
        }
        $expected = self::sign_published_hmac(
            $oid, $sid, (string) $decoded['tx'], $actor, $exp, $secret
        );
        if ( ! hash_equals( $expected, $decoded['key'] ) ) {
            return null;
        }
        return array(
            'oid'   => $oid,
            'sid'   => $sid,
            'tx'    => (string) $decoded['tx'],
            'actor' => $actor,
            'exp'   => $exp,
        );
    }

    /**
     * Remove published-preview credentials from a URL destined for a caller.
     *
     * The transaction id and expiry survive — useless without the signature
     * — so a returned URL stays diagnosable.
     */
    public static function strip_published_args( $url ) {
        return remove_query_arg(
            array( self::PUB_PREVIEW_FLAG_ARG, self::PUB_PREVIEW_CRED_ARG ),
            (string) $url
        );
    }
}