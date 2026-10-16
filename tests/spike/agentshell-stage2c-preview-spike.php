<?php
/**
 * Stage 2c preview overlay — spike implementation.
 *
 * Disposability: this file is a feasibility spike, not a production artifact.
 * It mounts an identity-preserving preview overlay for the published-content
 * editing flow on the disposable test bed only. The agent-shell theme's
 * production code path is unchanged.
 *
 * What it does (mirrors docs/STAGE-2C-DESIGN.md §1.3):
 *   1. Registers six public query vars carrying the credential
 *      (oid / sid / tx / actor / exp / key).
 *   2. Verifies the HMAC at the `pre_get_posts` action for the main query
 *      only. On a valid credential, sets a per-request global and registers
 *      a `the_posts` filter.
 *   3. The `the_posts` filter substitutes the main query's result with an
 *      identity-preserving overlay WP_Post: identity fields (ID, post_type,
 *      post_status, post_author, post_parent, post_date, post_date_gmt,
 *      post_modified, post_modified_gmt, post_content_filtered,
 *      post_mime_type, comment_status, ping_status, menu_order,
 *      post_password) come from the ORIGINAL; the four staged fields
 *      (post_title, post_content, post_excerpt, post_name) come from the
 *      SHADOW. The substituted object is never written to wp_object_cache.
 *   4. Field-level overlay filters (the_title, the_content, the_excerpt,
 *      and the permalink family) overlay the four fields for any code path
 *      that reads the post through the persistent cache (e.g. block
 *      renders that call get_post()).
 *
 * What it does NOT do (out of scope for the spike): full transaction /
 * journal lifecycle, claim, baseline-A OCC revalidate, hook-induced
 * change allowlist (those land in the production implementation).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'agentshell_pub_preview_oid';
    $vars[] = 'agentshell_pub_preview_sid';
    $vars[] = 'agentshell_pub_preview_tx';
    $vars[] = 'agentshell_pub_preview_actor';
    $vars[] = 'agentshell_pub_preview_exp';
    $vars[] = 'agentshell_pub_preview_key';
    return $vars;
} );

add_filter( 'the_title', 'agentshell_spike_overlay_the_title', 10, 2 );
add_filter( 'the_content', 'agentshell_spike_overlay_the_content', 10, 1 );
add_filter( 'the_excerpt', 'agentshell_spike_overlay_the_excerpt', 10, 2 );
// The permalink family IS filtered, but in the opposite direction from
// the post_name data field: the post_name on the WP_Post object is the
// shadow's slug (so any direct reader sees the new slug), but the
// canonical URL must stay as the ORIGINAL's permalink (so the request
// stays on the path it was sent to, and links to the previewed page
// resolve to a real URL). The permalink filter reconstructs the URL
// from the original's post_name.
add_filter( 'post_link', 'agentshell_spike_pin_permalink_to_original', 10, 3 );
add_filter( 'post_type_link', 'agentshell_spike_pin_permalink_to_original', 10, 4 );
add_filter( 'page_link', 'agentshell_spike_pin_permalink_to_original_page', 10, 3 );
add_filter( '_get_page_link', 'agentshell_spike_pin_permalink_to_original_get_page', 10, 2 );
add_filter( 'the_permalink', 'agentshell_spike_pin_permalink_to_original_the', 10, 1 );

function agentshell_spike_sign( $oid, $sid, $tx, $actor, $exp, $secret ) {
    return hash_hmac(
        'sha256',
        'agentshell-published-edit|' . (int) $oid . '|' . (int) $sid . '|'
            . (string) $tx . '|' . (int) $actor . '|' . (int) $exp,
        $secret
    );
}

function agentshell_spike_secret() {
    if ( defined( 'AGENTSHELL_REST_TOKEN' ) && AGENTSHELL_REST_TOKEN !== '' ) {
        return AGENTSHELL_REST_TOKEN;
    }
    if ( defined( 'AUTH_SALT' ) && AUTH_SALT !== '' ) {
        return AUTH_SALT;
    }
    return '';
}

function agentshell_spike_get_global() {
    global $agentshell_spike_overlay;
    return is_array( $agentshell_spike_overlay ) ? $agentshell_spike_overlay : null;
}

function agentshell_spike_set_global( $tuple ) {
    global $agentshell_spike_overlay;
    $agentshell_spike_overlay = $tuple;
}

function agentshell_spike_clear_global() {
    global $agentshell_spike_overlay;
    $agentshell_spike_overlay = null;
}

add_action( 'pre_get_posts', 'agentshell_spike_verify_and_install', 10, 1 );
function agentshell_spike_verify_and_install( $query ) {
    if ( ! $query->is_main_query() ) {
        return;
    }
    $oid   = get_query_var( 'agentshell_pub_preview_oid' );
    $sid   = get_query_var( 'agentshell_pub_preview_sid' );
    $tx    = get_query_var( 'agentshell_pub_preview_tx' );
    $actor = get_query_var( 'agentshell_pub_preview_actor' );
    $exp   = get_query_var( 'agentshell_pub_preview_exp' );
    $key   = get_query_var( 'agentshell_pub_preview_key' );
    if ( ! $oid || ! $sid || ! $tx || ! $actor || ! $exp || ! $key ) {
        agentshell_spike_clear_global();
        return;
    }
    $secret = agentshell_spike_secret();
    if ( '' === $secret ) {
        agentshell_spike_clear_global();
        return;
    }
    $oid_int   = (int) $oid;
    $sid_int   = (int) $sid;
    $actor_int = (int) $actor;
    $exp_int   = (int) $exp;
    if ( $oid_int < 1 || $sid_int < 1 || $actor_int < 1 || $exp_int < time() ) {
        agentshell_spike_clear_global();
        return;
    }
    $expected = agentshell_spike_sign( $oid_int, $sid_int, (string) $tx, $actor_int, $exp_int, $secret );
    if ( ! hash_equals( $expected, (string) $key ) ) {
        agentshell_spike_clear_global();
        return;
    }
    agentshell_spike_set_global( array(
        'original_id' => $oid_int,
        'shadow_id'   => $sid_int,
        'tx_id'       => (string) $tx,
        'actor'       => $actor_int,
    ) );
    add_filter( 'the_posts', 'agentshell_spike_substitute_overlay', 10, 2 );
}

function agentshell_spike_substitute_overlay( $posts, $query ) {
    if ( ! $query->is_main_query() ) {
        return $posts;
    }
    $tuple = agentshell_spike_get_global();
    if ( ! $tuple ) {
        return $posts;
    }
    if ( empty( $posts ) ) {
        return $posts;
    }
    $original_index = null;
    $original = null;
    foreach ( $posts as $i => $p ) {
        if ( (int) $p->ID === $tuple['original_id'] ) {
            $original_index = $i;
            $original = $p;
            break;
        }
    }
    if ( null === $original_index || ! $original ) {
        return $posts;
    }
    $shadow = get_post( $tuple['shadow_id'] );
    if ( ! $shadow ) {
        return $posts;
    }
    $overlay = clone $original;
    $overlay->post_title   = $shadow->post_title;
    $overlay->post_content = $shadow->post_content;
    $overlay->post_excerpt = $shadow->post_excerpt;
    $overlay->post_name    = $shadow->post_name;
    $posts[ $original_index ] = $overlay;
    return $posts;
}

function agentshell_spike_field_match( $post_id ) {
    $tuple = agentshell_spike_get_global();
    if ( ! $tuple ) {
        return false;
    }
    return (int) $post_id === $tuple['original_id'];
}

function agentshell_spike_get_shadow() {
    $tuple = agentshell_spike_get_global();
    if ( ! $tuple ) {
        return null;
    }
    return get_post( $tuple['shadow_id'] );
}

function agentshell_spike_overlay_the_title( $title, $post_id = 0 ) {
    if ( ! $post_id || ! agentshell_spike_field_match( $post_id ) ) {
        return $title;
    }
    $shadow = agentshell_spike_get_shadow();
    if ( ! $shadow ) {
        return $title;
    }
    return $shadow->post_title;
}

function agentshell_spike_overlay_the_content( $content ) {
    global $post;
    if ( ! $post || ! agentshell_spike_field_match( $post->ID ) ) {
        return $content;
    }
    $shadow = agentshell_spike_get_shadow();
    if ( ! $shadow ) {
        return $content;
    }
    return $shadow->post_content;
}

function agentshell_spike_overlay_the_excerpt( $excerpt, $post = null ) {
    if ( ! $post || ! agentshell_spike_field_match( $post->ID ) ) {
        return $excerpt;
    }
    $shadow = agentshell_spike_get_shadow();
    if ( ! $shadow ) {
        return $excerpt;
    }
    return $shadow->post_excerpt;
}

// Permalink pinning: the overlay's post_name is the shadow's slug, but
// the canonical URL must stay as the original's. The original's
// post_name is read from a fresh get_post() (the wp_object_cache was
// not poisoned — see (h)), or from the request-time global stored by
// pre_get_posts. We re-read the original here.
function agentshell_spike_get_original_post_name() {
    $tuple = agentshell_spike_get_global();
    if ( ! $tuple ) { return null; }
    $original = get_post( $tuple['original_id'] );
    if ( ! $original ) { return null; }
    return $original->post_name;
}

function agentshell_spike_pin_permalink( $permalink, $expected_slug ) {
    $tuple = agentshell_spike_get_global();
    if ( ! $tuple ) { return $permalink; }
    $original_slug = agentshell_spike_get_original_post_name();
    if ( ! $original_slug ) { return $permalink; }
    // Replace the shadow's slug with the original's slug in the URL.
    if ( $expected_slug === $original_slug ) { return $permalink; }
    return str_replace( '/' . $expected_slug . '/', '/' . $original_slug . '/', $permalink );
}

function agentshell_spike_pin_permalink_to_original( $permalink, $post, $leavename = false ) {
    if ( ! $post || ! agentshell_spike_field_match( $post->ID ) ) { return $permalink; }
    return agentshell_spike_pin_permalink( $permalink, $post->post_name );
}

function agentshell_spike_pin_permalink_to_original_page( $permalink, $post_id = 0, $sample = false ) {
    $post = get_post( $post_id );
    if ( ! $post || ! agentshell_spike_field_match( $post->ID ) ) { return $permalink; }
    return agentshell_spike_pin_permalink( $permalink, $post->post_name );
}

function agentshell_spike_pin_permalink_to_original_get_page( $permalink, $post_id = 0 ) {
    $post = get_post( $post_id );
    if ( ! $post || ! agentshell_spike_field_match( $post->ID ) ) { return $permalink; }
    return agentshell_spike_pin_permalink( $permalink, $post->post_name );
}

function agentshell_spike_pin_permalink_to_original_the( $permalink ) {
    global $post;
    if ( ! $post || ! agentshell_spike_field_match( $post->ID ) ) { return $permalink; }
    return agentshell_spike_pin_permalink( $permalink, $post->post_name );
}
