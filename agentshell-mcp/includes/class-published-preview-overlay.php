<?php
/**
 * Stage 2c Commit 2 — Published-content preview overlay.
 *
 * The overlay preserves the original's identity in the main query
 * (get_queried_object_id() is the original's id, $post->ID is the
 * original's id, the template loader selects the original's
 * page-specific template) and overlays the four staged fields
 * (post_title, post_content, post_excerpt, post_name) from the shadow.
 *
 * The overlay is gated by an HMAC credential bound to (original_id,
 * shadow_id, tx_id, actor, expires) and verified against the journal
 * op's triangle at pre_get_posts time. The substituted WP_Post lives
 * only in $wp_query->posts[0] of the one request; it is never written
 * to wp_object_cache.
 */
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Published_Preview_Overlay {

    /**
     * The public flag the overlay registers as a wp_query_var. The
     * credential itself is NOT a wp_query_var (it rides in
     * Post_Preview::PUB_PREVIEW_CRED_ARG as a base64 blob, read from
     * $_GET directly) so a persistent cache bucketed by query vars
     * does not differentiate URLs by their credential.
     */
    const QUERY_VAR_FLAG = 'agentshell_preview';

    /**
     * Per-request globals. Set in init_to_global (raw) and
     * verify_and_install (verified tuple + shadow).
     */
    const RAW_GLOBAL_KEY  = 'agentshell_pub_preview_cred_raw';
    const STATE_GLOBAL    = 'agentshell_pub_preview_overlay';

    /**
     * States in which a preview is meaningful: the staging is in flight
     * or has been published but is not yet settled.
     */
    const PREVIEWABLE_STATES = array(
        'staged', 'publication_in_progress', 'publication_committed', 'reconciliation_pending',
    );

    /**
     * States in which a preview is refused: the staging is gone
     * (compensated), failed (conflict), abandoned, or settled.
     */
    const REFUSED_STATES = array(
        'compensated', 'conflict', 'abandoned', 'publication_settled',
    );

    /**
     * Install all hooks the overlay needs. Idempotent.
     */
    public static function register_hooks() {
        add_filter( 'query_vars',           array( __CLASS__, 'register_query_vars' ) );
        add_action( 'init',                 array( __CLASS__, 'init_to_global' ), 9 );
        add_action( 'pre_get_posts',        array( __CLASS__, 'verify_and_install' ), 10, 1 );
        add_filter( 'the_posts',            array( __CLASS__, 'substitute_overlay' ), 10, 2 );

        // Field-level overlay filters. The callback is a pass-through
        // without the verified global; the substitution path goes
        // through `the_posts` and is the primary render surface.
        add_filter( 'the_title',            array( __CLASS__, 'overlay_the_title' ), 10, 2 );
        add_filter( 'the_content',          array( __CLASS__, 'overlay_the_content' ), 10, 1 );
        add_filter( 'the_excerpt',          array( __CLASS__, 'overlay_the_excerpt' ), 10, 2 );

        // Permalink family. The design specifies option (a): the response
        // body's permalink links reflect the staged slug so the preview
        // is consistent with what the visitor will see after publish.
        // The request URL stays on the original's permalink (it's what
        // the browser hit), and apply_response_headers() disables
        // redirect_canonical for the request's lifetime so the canonical
        // does not 301 to a non-existent URL.
        add_filter( 'post_link',            array( __CLASS__, 'overlay_permalink' ), 10, 4 );
        add_filter( 'post_type_link',       array( __CLASS__, 'overlay_permalink_type' ), 10, 4 );
        add_filter( 'page_link',            array( __CLASS__, 'overlay_permalink_page' ), 10, 3 );
        add_filter( '_get_page_link',       array( __CLASS__, 'overlay_permalink_get_page' ), 10, 2 );
        add_filter( 'the_permalink',        array( __CLASS__, 'overlay_permalink_the' ), 10, 1 );

        add_action( 'template_redirect',    array( __CLASS__, 'apply_response_headers' ), 1 );
    }

    /**
     * Register the public flag as a wp_query_var.
     *
     * The credential itself is NOT a wp_query_var (it lives in $_GET
     * and is read directly in init_to_global).
     */
    public static function register_query_vars( $vars ) {
        $vars[] = self::QUERY_VAR_FLAG;
        return $vars;
    }

    /**
     * At init priority 9 — before any main query runs — read the raw
     * credential from $_GET directly (not WP_Query) and stash it in a
     * per-request global. The credential is verified against the journal
     * op's triangle later in pre_get_posts.
     *
     * No-op if the public flag is not set, the credential arg is missing,
     * or the credential class is not loaded.
     */
    public static function init_to_global() {
        if ( empty( $_GET[ self::QUERY_VAR_FLAG ] ) ) {
            return;
        }
        if ( empty( $_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ) ) {
            return;
        }
        if ( ! class_exists( '\AgentShell_MCP\Post_Preview' ) ) {
            return;
        }
        $GLOBALS[ self::RAW_GLOBAL_KEY ] = (string) $_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
    }

    /**
     * Per-request verified overlay accessor. Returns the (oid, sid, tx,
     * actor, shadow, state) tuple, or null if no overlay is active.
     */
    public static function get_overlay() {
        if ( ! isset( $GLOBALS[ self::STATE_GLOBAL ] ) ) {
            return null;
        }
        $tuple = $GLOBALS[ self::STATE_GLOBAL ];
        return is_array( $tuple ) ? $tuple : null;
    }

    /**
     * pre_get_posts — the earliest point where the main query is mutable
     * but has not yet executed. Verifies the credential, traverses the
     * open transaction's ops for a matching OP_EDIT_PUBLISHED, checks the
     * shadow's post-meta binding, and installs the verified tuple into the
     * per-request global.
     *
     * Refuses silently on any failure: the field-level filters become
     * no-ops without the global, and the main query continues to resolve
     * the original (a post_status=publish post that any anonymous request
     * would render).
     */
    public static function verify_and_install( $query ) {
        if ( ! $query instanceof \WP_Query ) { return; }
        if ( ! $query->is_main_query() || ! empty( $query->is_admin ) ) { return; }

        $raw = isset( $GLOBALS[ self::RAW_GLOBAL_KEY ] )
            ? (string) $GLOBALS[ self::RAW_GLOBAL_KEY ]
            : '';
        if ( '' === $raw ) { return; }

        if ( ! class_exists( '\AgentShell_MCP\Post_Preview' ) ) { return; }
        $cred = Post_Preview::verify_published_hmac( $raw );
        if ( ! is_array( $cred ) ) { return; }

        $op = self::find_matching_op( $cred );
        if ( ! is_array( $op ) ) { return; }
        if ( in_array( $op['state'], self::REFUSED_STATES, true ) ) { return; }

        $shadow = \get_post( $cred['sid'] );
        if ( ! $shadow ) { return; }
        if ( 'private' !== $shadow->post_status ) { return; }

        // The shadow's post-meta binding to (original, tx) is the
        // ground truth; the credential's (oid, tx) triangle must match.
        if ( ! class_exists( '\AgentShell_MCP\Published_Shadow' ) ) { return; }
        $shadow_for = (int) \get_post_meta( $cred['sid'], Published_Shadow::META_SHADOW_FOR, true );
        $shadow_tx  = (string) \get_post_meta( $cred['sid'], Published_Shadow::META_TX_ID, true );
        if ( $shadow_for !== $cred['oid'] || $shadow_tx !== $cred['tx'] ) {
            return;
        }

        // The shadow's META_OP_ID is the head pointer: a credential
        // minted at op C1 must NOT preview the shadow after C1 has
        // been advanced to C2. The credential is rebound to the
        // shadow's current op by the caller; if they disagree, refuse
        // — the new op (or no new op) gets a fresh credential, not
        // a replay of the old one.
        $shadow_op = (string) \get_post_meta( $cred['sid'], Published_Shadow::META_OP_ID, true );
        if ( '' === $shadow_op || $shadow_op !== $cred['op'] ) {
            return;
        }

        $GLOBALS[ self::STATE_GLOBAL ] = array(
            'oid'    => $cred['oid'],
            'sid'    => $cred['sid'],
            'tx'     => $cred['tx'],
            'op'     => $cred['op'],
            'actor'  => $cred['actor'],
            'shadow' => $shadow,
            'state'  => $op['state'],
        );
    }

    /**
     * Find a journal op that matches the credential's (oid, sid, tx, actor)
     * triangle. Refuses if the open transaction row is absent, the tx id
     * does not match, or no OP_EDIT_PUBLISHED op in the row matches.
     *
     * The caller traverses `$tx_row['content']['ops']` because the journal
     * does not (yet) provide a public "find op by (tx, oid, sid)" lookup.
     *
     * @return array|null The op row, or null.
     */
    private static function find_matching_op( array $cred ) {
        if ( ! class_exists( '\AgentShell_MCP\Transaction_Manager' ) ) {
            return null;
        }
        $tx_row = Transaction_Manager::instance()->payload();
        if ( ! is_array( $tx_row ) ) { return null; }
        if ( ! isset( $tx_row['id'] ) || (string) $tx_row['id'] !== $cred['tx'] ) {
            return null;
        }
        if ( ! class_exists( '\AgentShell_MCP\Content_Journal' ) ) { return null; }
        $ops = isset( $tx_row['content']['ops'] ) && is_array( $tx_row['content']['ops'] )
            ? $tx_row['content']['ops']
            : array();
        // The op's `actor` field is the user_login (set by
        // Content_Journal::record_intent() from Store::actor()), while
        // the credential's `actor` is the integer user_id (set by
        // Post_Preview::published_preview_url_args()). Resolve the op
        // actor to a user_id and compare; numeric-string op actors
        // (test fixtures) are compared as-is.
        $cred_actor = (int) ( $cred['actor'] ?? 0 );
        foreach ( $ops as $op ) {
            if ( ! is_array( $op ) ) { continue; }
            if ( ! isset( $op['op'] ) || Content_Journal::OP_EDIT_PUBLISHED !== $op['op'] ) {
                continue;
            }
            if ( ! isset( $op['target']['original_id'] ) || ! isset( $op['target']['shadow_id'] ) ) {
                continue;
            }
            if ( (int) $op['target']['original_id'] !== $cred['oid'] ) { continue; }
            if ( (int) $op['target']['shadow_id']   !== $cred['sid'] ) { continue; }
            if ( ! self::op_actor_matches( $op, $cred_actor ) ) {
                continue;
            }
            return $op;
        }
        return null;
    }

    /**
     * Resolve the op's `actor` field to a user_id and compare against
     * the credential's actor (which is the integer user_id). If the op
     * actor is a numeric string, treat it as a user_id directly (test
     * fixture path). If it is a user_login, look it up.
     */
    private static function op_actor_matches( array $op, int $cred_actor ) : bool {
        $op_actor = (string) ( $op['actor'] ?? '' );
        if ( '' === $op_actor ) { return false; }
        if ( ctype_digit( $op_actor ) ) {
            return (int) $op_actor === $cred_actor;
        }
        $user = \get_user_by( 'login', $op_actor );
        if ( $user instanceof \WP_User ) {
            return (int) $user->ID === $cred_actor;
        }
        return false;
    }

    /**
     * the_posts filter — substitute the main query's WP_Post with an
     * identity-preserving overlay: identity fields from the ORIGINAL,
     * the four staged fields from the SHADOW. Lives in
     * $wp_query->posts[0] only — never written to wp_object_cache.
     */
    public static function substitute_overlay( $posts, $query ) {
        if ( ! $query instanceof \WP_Query ) { return $posts; }
        if ( ! $query->is_main_query() ) { return $posts; }
        $tuple = self::get_overlay();
        if ( ! $tuple ) { return $posts; }
        if ( empty( $posts ) ) { return $posts; }

        $original_index = null;
        $original = null;
        foreach ( $posts as $i => $p ) {
            if ( (int) $p->ID === $tuple['oid'] ) {
                $original_index = $i;
                $original = $p;
                break;
            }
        }
        if ( null === $original_index || ! $original ) { return $posts; }

        $shadow = $tuple['shadow'];
        $overlay = clone $original;
        // The four staged fields come from the shadow.
        $overlay->post_title   = $shadow->post_title;
        $overlay->post_content = $shadow->post_content;
        $overlay->post_excerpt = $shadow->post_excerpt;
        $overlay->post_name    = $shadow->post_name;
        // Every identity field is preserved from the original (cloned
        // object), including post_type=page, post_status=publish,
        // post_parent, post_date, post_date_gmt, post_modified,
        // post_modified_gmt, post_author, comment_status, ping_status,
        // menu_order, post_password, post_mime_type,
        // post_content_filtered, ID, guid.
        $posts[ $original_index ] = $overlay;
        return $posts;
    }

    // ── Field-level overlay filters (request-scoped no-ops) ────────────

    public static function overlay_the_title( $title, $post_id = 0 ) {
        $tuple = self::get_overlay();
        if ( ! $tuple || (int) $post_id !== $tuple['oid'] ) { return $title; }
        return $tuple['shadow']->post_title;
    }

    public static function overlay_the_content( $content ) {
        if ( ! isset( $GLOBALS['post'] ) || ! $GLOBALS['post'] ) { return $content; }
        $tuple = self::get_overlay();
        $post = $GLOBALS['post'];
        if ( ! $tuple || (int) $post->ID !== $tuple['oid'] ) { return $content; }
        return $tuple['shadow']->post_content;
    }

    public static function overlay_the_excerpt( $excerpt, $p = null ) {
        if ( ! $p ) { return $excerpt; }
        $tuple = self::get_overlay();
        if ( ! $tuple || (int) $p->ID !== $tuple['oid'] ) { return $excerpt; }
        return $tuple['shadow']->post_excerpt;
    }

    // ── Permalink family: overlay the staged slug in the response body ──
    //
    // The design specifies option (a): the response body's permalink
    // links (canonical, nav, JSON-LD) reflect the staged slug so the
    // preview is consistent with what the visitor will see after
    // publish. The request URL stays on the original's permalink (it's
    // what the browser hit), and apply_response_headers() disables
    // redirect_canonical for the request's lifetime so the canonical
    // does not 301 to a non-existent URL.

    public static function overlay_permalink( $permalink, $post, $leavename = false, $sample = false ) {
        return self::staged_permalink( $permalink, $post );
    }

    public static function overlay_permalink_type( $permalink, $post, $leavename = false, $sample = false ) {
        return self::staged_permalink( $permalink, $post );
    }

    public static function overlay_permalink_page( $permalink, $post_id = 0, $sample = false ) {
        if ( ! $post_id ) { return $permalink; }
        $p = get_post( $post_id );
        return self::staged_permalink( $permalink, $p );
    }

    public static function overlay_permalink_get_page( $permalink, $post_id = 0 ) {
        if ( ! $post_id ) { return $permalink; }
        $p = get_post( $post_id );
        return self::staged_permalink( $permalink, $p );
    }

    public static function overlay_permalink_the( $permalink ) {
        if ( ! isset( $GLOBALS['post'] ) || ! $GLOBALS['post'] ) { return $permalink; }
        return self::staged_permalink( $permalink, $GLOBALS['post'] );
    }

    /**
     * Replace the trailing /<original-slug>/ path component with the
     * shadow's post_name. No-op without the overlay or when the slug
     * has not changed.
     */
    private static function staged_permalink( $permalink, $post ) {
        if ( ! is_string( $permalink ) || '' === $permalink ) { return $permalink; }
        $tuple = self::get_overlay();
        if ( ! $tuple ) { return $permalink; }
        if ( ! $post || (int) $post->ID !== $tuple['oid'] ) { return $permalink; }
        $original_slug = (string) $post->post_name;
        $shadow_slug   = (string) $tuple['shadow']->post_name;
        if ( '' === $shadow_slug || $shadow_slug === $original_slug ) {
            return $permalink;
        }
        return preg_replace(
            '#/' . preg_quote( $original_slug, '#' ) . '(/|$)#',
            '/' . $shadow_slug . '$1',
            $permalink,
            1
        );
    }

    /**
     * template_redirect priority 1 — apply noindex/no-cache headers and
     * disable redirect_canonical for the request's lifetime. No-op without
     * the verified overlay global.
     */
    public static function apply_response_headers() {
        $tuple = self::get_overlay();
        if ( ! $tuple ) { return; }
        if ( ! headers_sent() ) {
            header( 'X-Robots-Tag: noindex, nofollow', true );
            header( 'Cache-Control: no-store, private, must-revalidate', true );
            header( 'Pragma: no-cache', true );
        }
        // Without this, the canonical-redirect filter would 301 the
        // request from /original-slug/ to /staged-slug/ — but
        // /staged-slug/ is not a real URL yet (it will be after
        // publication). The preview stays on the original's URL with
        // the staged slug in the response's permalink links.
        remove_filter( 'template_redirect', 'redirect_canonical' );
    }
}
