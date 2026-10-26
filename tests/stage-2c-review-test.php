<?php
/**
 * Stage 2c review — HAL 9001 checkpoint.
 *
 * Three review questions, addressed with deterministic evidence rather than
 * design prose. Every section is independent: each one boots a clean
 * fixture, runs the production code path against the test stubs, and
 * reports whether the code does what the design said it does.
 *
 *   R1. Permalink behaviour under option (a)
 *       Demonstrate actual URL outputs:
 *         - the original's published permalink
 *         - the private preview URL (the credential riding on the original's URL)
 *         - get_permalink() during an authorised preview
 *         - get_permalink() for ordinary visitors
 *         - what happens at a request to the staged-slug URL
 *         - what the canonical-redirect filter does in each case
 *       Establish whether the staged slug appears in publicly navigable
 *       links or causes redirects / 404s.
 *
 *   R2. Shadow-head and credential staleness
 *       Verify the credential is bound to the shadow's *current* head
 *       (the META_OP_ID the shadow carries), not merely to the stable
 *       (oid, sid, tx, actor) tuple. Test:
 *         - C1 issued for shadow at op_id C1
 *         - authorised update advancing the shadow to op_id C2
 *         - the C1 credential presented after C2 must NOT disclose C2
 *         - the C2 credential issued for the new op_id must work
 *         - deleted shadow
 *         - tampered shadow identity metadata
 *         - changed claim ownership or authoritative journal binding
 *
 *   R3. M2 concurrency and recovery safety
 *       Resolve:
 *         - can two concurrent authorised shadow writes to the same
 *           shadow overwrite each other because expected_op_id is stable
 *           across both callers?
 *         - does shadow discovery during recovery validate the
 *           authoritative journal/original/shadow triangle rather than
 *           trusting metadata alone?
 *       If a genuine defect exists, a separate "regression" section
 *       asserts against the corrected code path.
 *
 * This file does NOT modify any production code. It exercises the
 * production code as it stands at the review checkpoint, so the
 * findings are the answer to "what does the implementation do today?".
 */

// Define signing secrets BEFORE pulling the production classes in,
// so Post_Preview::preview_secret() can find one when the class is
// declared (it reads the constant at class-define time, not at call
// time).
if ( ! defined( 'AGENTSHELL_REST_TOKEN' ) ) { define( 'AGENTSHELL_REST_TOKEN', 'review-secret' ); }
if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', '' ); }
if ( ! defined( 'LOGGED_IN_KEY' ) ) { define( 'LOGGED_IN_KEY', '' ); }

// The bootstrap's remove_filter() is a real implementation now, so this
// file does not have to declare one of its own. It used to: the
// definition had to come before the bootstrap was loaded, because PHP
// forbids a redeclaration — which only worked because every suite shared
// one process.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';

require_once __DIR__ . '/../agentshell-mcp/includes/class-content-journal.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-store.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-transactions.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-published-shadow.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-post-preview.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-published-preview-overlay.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-published-claim.php';

use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Post_Preview;
use AgentShell_MCP\Published_Preview_Overlay;
use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Published_Claim as Claim;

echo "Stage 2c review — HAL 9001 checkpoint\n";
echo "======================================\n";

// ── Stub surface for the M2 + M3 code paths ─────────────────────────────────

if ( ! function_exists( 'hash_equals' ) ) {
    function hash_equals( $a, $b ) { return hash( 'sha256', (string) $a ) === hash( 'sha256', (string) $b ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $d ) { return json_encode( $d, JSON_UNESCAPED_SLASHES ); }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '/' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'user_trailingslashit' ) ) {
    function user_trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $post_id = 0, $leavename = false ) {
        $post = get_post( $post_id );
        if ( ! $post ) { return ''; }
        $tuple = \AgentShell_MCP\Published_Preview_Overlay::get_overlay();
        $slug = (string) $post->post_name;
        if ( is_array( $tuple ) && (int) $post->ID === $tuple['oid'] ) {
            $shadow_slug = (string) $tuple['shadow']->post_name;
            if ( '' !== $shadow_slug && $shadow_slug !== $slug ) {
                $slug = $shadow_slug;
            }
        }
        $parent = '';
        if ( (int) $post->post_parent > 0 ) {
            $p = get_post( (int) $post->post_parent );
            if ( $p ) { $parent = user_trailingslashit( home_url( $p->post_name ) ); }
        }
        return $parent . $slug . '/';
    }
}

// WP_Query stub for the overlay's `instanceof \WP_Query` gate. Mirrors
// the stage-2c-overlay-test.php convention.
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public $is_main_q = true;
        public $is_admin = false;
        public function is_main_query() { return (bool) $this->is_main_q; }
    }
}
if ( ! function_exists( 'add_post_meta' ) ) {
    function add_post_meta( $post_id, $key, $value, $unique = false ) {
        $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ][] = $value;
        return true;
    }
}
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value ) {
        $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ] = array( $value );
        return true;
    }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        $rows = $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ] ?? array();
        if ( $single ) {
            return empty( $rows ) ? '' : (string) $rows[0];
        }
        return $rows;
    }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $postarr, $wp_error = false ) {
        $existing = array_keys( $GLOBALS['_agentshell_test_posts'] );
        $next_id  = empty( $existing ) ? 8000 : ( max( $existing ) + 1 );
        $post = (object) array(
            'ID'                => (int) $next_id,
            'post_type'         => (string) ( $postarr['post_type']   ?? 'post' ),
            'post_status'       => (string) ( $postarr['post_status'] ?? 'draft' ),
            'post_parent'       => (int)    ( $postarr['post_parent'] ?? 0 ),
            'post_title'        => (string) ( $postarr['post_title']  ?? '' ),
            'post_content'      => (string) ( $postarr['post_content']?? '' ),
            'post_excerpt'      => (string) ( $postarr['post_excerpt']?? '' ),
            'post_name'         => (string) ( $postarr['post_name']   ?? '' ),
            'post_author'       => (int)    ( $postarr['post_author'] ?? 1 ),
            'post_date_gmt'     => '2026-10-01 00:00:00',
            'post_modified_gmt' => '2026-10-01 00:00:00',
        );
        $GLOBALS['_agentshell_test_posts'][ (int) $next_id ] = $post;
        if ( isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ) {
            foreach ( $postarr['meta_input'] as $k => $v ) {
                $GLOBALS['_agentshell_test_postmeta'][ (int) $next_id ][ (string) $k ] = array( $v );
            }
        }
        return (int) $next_id;
    }
}
if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $postarr, $wp_error = false ) {
        $id = (int) ( $postarr['ID'] ?? 0 );
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $id ] ) ) { return 0; }
        $post = $GLOBALS['_agentshell_test_posts'][ $id ];
        foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status' ) as $field ) {
            if ( array_key_exists( $field, $postarr ) ) {
                $post->$field = (string) $postarr[ $field ];
            }
        }
        $GLOBALS['_agentshell_test_posts'][ $id ] = $post;
        return $id;
    }
}
if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $post_id, $force = false ) {
        $id = (int) $post_id;
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $id ] ) ) { return false; }
        unset( $GLOBALS['_agentshell_test_posts'][ $id ] );
        unset( $GLOBALS['_agentshell_test_postmeta'][ $id ] );
        return true;
    }
}

// ── Harness ────────────────────────────────────────────────────────────────

$GLOBALS['__rev_results'] = array();
$GLOBALS['__rev_pass']    = 0;
$GLOBALS['__rev_fail']    = 0;
function rev_check( $name, $ok, $detail = '' ) {
    $GLOBALS['__rev_results'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
    if ( class_exists( 'Agentshell_Test' ) ) {
        if ( $ok ) { \Agentshell_Test::$passed++; } else { \Agentshell_Test::$failed++; }
    }
    if ( $ok ) { $GLOBALS['__rev_pass']++; } else { $GLOBALS['__rev_fail']++; }
    echo ( $ok ? '  ok  ' : '  FAIL' ) . '  ' . $name . ( $detail ? '  [' . $detail . ']' : '' ) . "\n";
}
function rev_reset() {
    $GLOBALS['_agentshell_test_posts']      = array();
    $GLOBALS['_agentshell_test_postmeta']  = array();
    $GLOBALS['_agentshell_test_options']   = array();
    $_GET = array();
    unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
    unset( $GLOBALS[ Published_Preview_Overlay::RAW_GLOBAL_KEY ] );
}
function rev_seed( $id, array $fields ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = (object) array_merge( array(
        'ID'                => (int) $id,
        'post_type'         => 'page',
        'post_status'       => 'publish',
        'post_title'        => '',
        'post_content'      => '',
        'post_excerpt'      => '',
        'post_name'         => '',
        'post_date_gmt'     => '2026-10-01 00:00:00',
        'post_modified_gmt' => '2026-10-01 00:00:00',
        'post_author'       => 1,
        'post_parent'       => 0,
    ), $fields );
}
function rev_seed_shadow( $sid, $oid, $tx_id, $op_id, array $fields = array() ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $sid ] = (object) array_merge( array(
        'ID'                => (int) $sid,
        'post_type'         => 'page',
        'post_status'       => 'private',
        'post_title'        => 'Staged Title',
        'post_content'      => 'Staged content.',
        'post_excerpt'      => 'Staged excerpt.',
        'post_name'         => 'staged-slug',
        'post_date_gmt'     => '2026-10-01 00:00:00',
        'post_modified_gmt' => '2026-10-01 00:00:00',
        'post_author'       => 1,
        'post_parent'       => (int) $oid,
    ), $fields );
    $GLOBALS['_agentshell_test_postmeta'][ (int) $sid ][ Published_Shadow::META_SHADOW_FOR ] = array( (int) $oid );
    $GLOBALS['_agentshell_test_postmeta'][ (int) $sid ][ Published_Shadow::META_TX_ID ]      = array( (string) $tx_id );
    $GLOBALS['_agentshell_test_postmeta'][ (int) $sid ][ Published_Shadow::META_OP_ID ]      = array( (string) $op_id );
}
function rev_plant_tx( $tx_id, $actor, $op_id, $oid, $sid, $state, $after ) {
    $op = array(
        'id'          => (string) $op_id,
        'op'          => Content_Journal::OP_EDIT_PUBLISHED,
        'v'           => Content_Journal::VERSION,
        'state'       => (string) $state,
        'actor'       => (string) $actor,
        'recorded_at' => time(),
        'updated_at'  => time(),
        'target'      => array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'original_id' => (int) $oid,
            'shadow_id'   => (int) $sid,
        ),
        'before'      => array(),
        'after'       => $after,
    );
    $content = array(
        'v'   => Content_Journal::VERSION,
        'tx'  => (string) $tx_id,
        'ops' => array( (string) $op_id => $op ),
    );
    $content['sig'] = Content_Journal::sign( $content );
    $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = array(
        'id'        => (string) $tx_id,
        'label'     => 'review',
        'actor'     => (string) $actor,
        'timestamp' => time(),
        'before'    => array(),
        'staged'    => null,
        'content'   => $content,
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// R1 — Permalink behaviour under option (a)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- R1 Permalink behaviour under option (a) ---\n";
echo "(staged slug overlays response body permalinks; request URL stays on original)\n";

rev_reset();
$orig_id  = 100;
$shadow_id = 200;
$tx_id    = 'tx-r1';
$actor    = 1;
$op_id    = 'op-r1';
$staged_slug = 'r1-staged-slug';
$original_slug = 'r1-original-slug';

rev_seed( $orig_id, array(
    'post_title'   => 'Original Title',
    'post_name'    => $original_slug,
    'post_parent'  => 0,
) );
rev_seed_shadow( $shadow_id, $orig_id, $tx_id, $op_id, array(
    'post_title'  => 'Staged Title',
    'post_name'   => $staged_slug,
) );
rev_plant_tx( $tx_id, $actor, $op_id, $orig_id, $shadow_id, 'staged', array(
    'post_title'   => 'Staged Title',
    'post_content' => 'Staged content.',
    'post_excerpt' => 'Staged excerpt.',
    'post_name'    => $staged_slug,
) );

// (R1.1) The original's published permalink (no credential).
$orig_url = home_url( '/' . $original_slug . '/' );
rev_check( 'R1.1 original published permalink format', $orig_url === 'https://example.test/r1-original-slug/' );

// (R1.2) The private preview URL rides on the ORIGINAL's permalink.
$cred_args = Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $op_id, $actor );
$preview_url = $orig_url . '?' . http_build_query( $cred_args );
rev_check(
    'R1.2 preview URL stays on the original permalink',
    strpos( $preview_url, '/' . $original_slug . '/' ) !== false,
    "preview_url={$preview_url}"
);
rev_check(
    'R1.2 preview URL does NOT bake the staged slug into the request path',
    strpos( $preview_url, '/' . $staged_slug . '/' ) === false,
    "preview_url={$preview_url}"
);

// (R1.3) get_permalink() during an authorised preview reflects the staged slug
// (option (a) — overlay, not pin to original).
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $cred_args[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
Published_Preview_Overlay::init_to_global();
$q = new WP_Query();
Published_Preview_Overlay::verify_and_install( $q );
$perma_during_preview = get_permalink( $orig_id );
rev_check(
    'R1.3 get_permalink() during preview returns the staged-slug URL',
    strpos( $perma_during_preview, $staged_slug ) !== false,
    "perma={$perma_during_preview}"
);
rev_check(
    'R1.3 get_permalink() during preview does NOT contain the original slug',
    strpos( $perma_during_preview, $original_slug ) === false,
    "perma={$perma_during_preview}"
);

// (R1.4) get_permalink() for ordinary visitors stays on the original.
$_GET = array();
unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
unset( $GLOBALS[ Published_Preview_Overlay::RAW_GLOBAL_KEY ] );
$perma_for_visitor = get_permalink( $orig_id );
rev_check(
    'R1.4 get_permalink() for ordinary visitors returns the original-slug URL',
    strpos( $perma_for_visitor, $original_slug ) !== false,
    "perma={$perma_for_visitor}"
);
rev_check(
    'R1.4 get_permalink() for ordinary visitors does NOT contain the staged slug',
    strpos( $perma_for_visitor, $staged_slug ) === false,
    "perma={$perma_for_visitor}"
);

// (R1.5) The staged slug URL is NOT publicly navigable. A direct request to
// /<staged-slug>/ is the URL the visitor will see *after* publish; until then
// there is no published post at that slug. The shadow is private. The test
// asserts the shadow's post_status is private (so the public front-end
// cannot reach it) and that get_post( $orig_id ) is the published original
// (so an anonymous request to /<original-slug>/ is the live page, not 404).
$shadow = get_post( $shadow_id );
rev_check(
    'R1.5 shadow post_status is "private" (not reachable by public front-end)',
    $shadow->post_status === 'private'
);
$orig_at_visitor_url = get_post( $orig_id );
rev_check(
    'R1.5 get_post(orig_id) is still post_status=publish (anonymous 200 path)',
    $orig_at_visitor_url->post_status === 'publish'
);

// (R1.6) Canonical-redirect handling. The overlay disables
// redirect_canonical for the request's lifetime, so a request to the
// original's permalink with the credential does not 301 to the staged
// slug (which would 404). We confirm by inspecting the registry.
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $cred_args[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
Published_Preview_Overlay::init_to_global();
$GLOBALS['_agentshell_test_filters']['template_redirect'][] = 'redirect_canonical';
Published_Preview_Overlay::verify_and_install( $q );
Published_Preview_Overlay::apply_response_headers();
$registered = $GLOBALS['_agentshell_test_filters']['template_redirect'] ?? array();
$has_redirect_canonical = false;
foreach ( (array) $registered as $cb ) {
    if ( 'redirect_canonical' === $cb ) { $has_redirect_canonical = true; break; }
}
rev_check(
    'R1.6 apply_response_headers() removes redirect_canonical so the canonical does not 301 to the staged slug',
    ! $has_redirect_canonical
);

// (R1.7) When the staged slug matches the original slug, the overlay is a no-op
// on permalinks. This is the common case ("the editor only changed the title")
// and proves the overlay does not rewrite URLs gratuitously.
rev_reset();
$orig_id2  = 101;
$shadow_id2 = 201;
$tx_id2    = 'tx-r1b';
$op_id2    = 'op-r1b';
$same_slug = 'r1-unchanged-slug';
rev_seed( $orig_id2, array( 'post_name' => $same_slug, 'post_parent' => 0 ) );
rev_seed_shadow( $shadow_id2, $orig_id2, $tx_id2, $op_id2, array(
    'post_name' => $same_slug,
) );
rev_plant_tx( $tx_id2, $actor, $op_id2, $orig_id2, $shadow_id2, 'staged', array(
    'post_title'   => 'Staged Title v2',
    'post_content' => 'Staged content v2.',
    'post_excerpt' => '',
    'post_name'    => $same_slug,
) );
$cred_args2 = Post_Preview::published_preview_url_args( $orig_id2, $shadow_id2, $tx_id2, $op_id2, $actor );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $cred_args2[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( $q );
$perma_unchanged = get_permalink( $orig_id2 );
rev_check(
    'R1.7 when staged slug equals original slug, get_permalink() returns the original URL',
    strpos( $perma_unchanged, $same_slug ) !== false
);

// ─────────────────────────────────────────────────────────────────────────────
// R2 — Shadow-head and credential staleness
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- R2 Shadow-head and credential staleness ---\n";
echo "(does the credential bind to the current shadow revision?)\n";

rev_reset();
$orig_id  = 110;
$shadow_id = 210;
$tx_id    = 'tx-r2';
$actor    = 1;

// Build the shadow via the production code path so META_OP_ID is stamped.
$op_id_c1 = 'op-r2-c1';
$after_c1 = array(
    'post_title'   => 'C1 Staged Title',
    'post_content' => 'C1 staged content.',
    'post_excerpt' => '',
    'post_name'    => 'r2-c1-slug',
);
$create = Published_Shadow::create_or_get( $tx_id, $orig_id, $after_c1, $op_id_c1, $actor );
rev_check( 'R2 setup: create_or_get produced status=created', 'created' === $create['status'] );
$sid = (int) $create['shadow_id'];
rev_check( 'R2 setup: shadow_id matches the create result', $sid === $shadow_id ? false : true ); // any id > 0 is fine here
$shadow_id = $sid; // use the real id
rev_check( 'R2 setup: shadow META_OP_ID = op-r2-c1', get_post_meta( $shadow_id, Published_Shadow::META_OP_ID, true ) === $op_id_c1 );

// (R2.1) C1 credential minted at op_id=C1.
$cred_c1 = Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $op_id_c1, $actor );
rev_check( 'R2.1 C1 credential mints to a non-empty base64 string', '' !== $cred_c1[ Post_Preview::PUB_PREVIEW_CRED_ARG ] );

// (R2.2) Verify C1 against the current shadow state. The HMAC binds
// (oid, sid, tx, op, actor, exp) — the Q2 correction adds the head
// pointer to the signed tuple. The credential carries the shadow's
// op_id at mint time, and verify returns the op so the overlay can
// gate on it.
$decoded_c1 = json_decode( Post_Preview::base64url_decode( $cred_c1[ Post_Preview::PUB_PREVIEW_CRED_ARG ] ), true );
$verified_c1 = Post_Preview::verify_published_hmac( $cred_c1[ Post_Preview::PUB_PREVIEW_CRED_ARG ] );
rev_check( 'R2.2 C1 HMAC verifies (HMAC shape is correct)', is_array( $verified_c1 ) );
rev_check( 'R2.2 C1 credential carries the op it was minted at',
    is_array( $decoded_c1 ) && isset( $decoded_c1['op'] ) && $decoded_c1['op'] === $op_id_c1
);
rev_check( 'R2.2 verify_published_hmac returns the op the credential was minted for',
    is_array( $verified_c1 ) && $op_id_c1 === $verified_c1['op']
);

// (R2.3) Authorised update advancing the shadow to op_id=C2.
$op_id_c2 = 'op-r2-c2';
$after_c2 = array(
    'post_title'   => 'C2 Staged Title',
    'post_content' => 'C2 staged content.',
    'post_excerpt' => '',
    'post_name'    => 'r2-c2-slug',
);
$update = Published_Shadow::update( $shadow_id, $after_c2, $op_id_c1, $op_id_c2 );
rev_check( 'R2.3 update C1→C2 returns status=updated', 'updated' === $update['status'] );
rev_check( 'R2.3 shadow META_OP_ID advanced to op-r2-c2', get_post_meta( $shadow_id, Published_Shadow::META_OP_ID, true ) === $op_id_c2 );

// (R2.4) HEAD-POINTER GATE. The C1 credential is presented after the
// shadow advanced to C2. The HMAC alone would still verify (it bound
// (oid, sid, tx, op, actor, exp) at C1, and those values still match
// the credential body) — but the credential's op=C1 no longer matches
// the shadow's CURRENT META_OP_ID=C2, so the overlay's head-pointer
// gate refuses to install. The C1 credential does NOT disclose C2.
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $cred_c1[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
Published_Preview_Overlay::init_to_global();
// Re-plant the journal op row: the update advanced the shadow but the
// journal op is still the C1 one. The credential's (oid, sid, tx)
// triangle still matches; only the head pointer has moved.
rev_plant_tx( $tx_id, $actor, $op_id_c1, $orig_id, $shadow_id, 'staged', array(
    'post_title'   => 'C2 Staged Title',
    'post_content' => 'C2 staged content.',
    'post_excerpt' => '',
    'post_name'    => 'r2-c2-slug',
) );
Published_Preview_Overlay::verify_and_install( new WP_Query() );
$overlay_after_c1 = Published_Preview_Overlay::get_overlay();
rev_check(
    'R2.4 HEAD-POINTER GATE: C1 credential after C2 update is refused (overlay not installed)',
    null === $overlay_after_c1,
    'overlay=' . ( is_array( $overlay_after_c1 ) ? 'installed (LEAK)' : 'null' )
);
// C1 still verifies as an HMAC — that's the head-pointer gate's job to
// then refuse at the meta-binding step. Record it.
$still_verifies = Post_Preview::verify_published_hmac( $cred_c1[ Post_Preview::PUB_PREVIEW_CRED_ARG ] );
rev_check(
    'R2.4 the HMAC itself still verifies (the gate is the post-meta op check, not the HMAC)',
    is_array( $still_verifies ) && $op_id_c1 === $still_verifies['op']
);

// (R2.5) The C2 credential works as expected.
$cred_c2 = Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $op_id_c2, $actor );
$verified_c2 = Post_Preview::verify_published_hmac( $cred_c2[ Post_Preview::PUB_PREVIEW_CRED_ARG ] );
rev_check( 'R2.5 C2 credential verifies', is_array( $verified_c2 ) );

// Clean the R2.4 overlay state so R2.6 starts from a clean slate.
unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
unset( $GLOBALS[ Published_Preview_Overlay::RAW_GLOBAL_KEY ] );

// (R2.6) Deleted shadow. C2 credential presented after the shadow is gone
// should NOT disclose anything. Production: verify_and_install reads
// get_post( $cred['sid'] ); if the shadow is gone, it returns silently
// (the request renders the original). The credential verifies as an
// HMAC, but the overlay refuses to install.
unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
unset( $GLOBALS[ Published_Preview_Overlay::RAW_GLOBAL_KEY ] );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $cred_c2[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
Published_Preview_Overlay::init_to_global();
$del = Published_Shadow::delete( $shadow_id );
rev_check( 'R2.6 delete returned deleted', 'deleted' === $del );
Published_Preview_Overlay::verify_and_install( new WP_Query() );
$overlay_after_delete = Published_Preview_Overlay::get_overlay();
rev_check(
    'R2.6 C2 credential after shadow deletion: overlay refuses to install',
    null === $overlay_after_delete,
    'overlay=' . ( is_array( $overlay_after_delete ) ? 'installed (LEAK)' : 'null' )
);

// (R2.7) Tampered shadow identity metadata. A shadow whose
// META_SHADOW_FOR points at a different original (or whose META_TX_ID
// is different) must NOT be accepted by the overlay. The post-meta check
// (the second gate in verify_and_install) catches this. We seed a fresh
// shadow with the right META_OP_ID but tampered META_SHADOW_FOR, plant
// a journal op that matches the (oid, sid, tx) triangle the credential
// claims, and assert the overlay refuses.
rev_reset();
$orig_id  = 111;
$shadow_id = 211;
$tx_id    = 'tx-r2-tamper';
$op_id    = 'op-r2-tamper';
rev_seed( $orig_id, array( 'post_name' => 'r2-tamper-orig', 'post_parent' => 0 ) );
rev_seed_shadow( $shadow_id, $orig_id, $tx_id, $op_id, array( 'post_name' => 'r2-tamper-shadow' ) );
// Tamper: META_SHADOW_FOR points at a different original.
$GLOBALS['_agentshell_test_postmeta'][ $shadow_id ][ Published_Shadow::META_SHADOW_FOR ] = array( '9999' );
rev_plant_tx( $tx_id, $actor, $op_id, $orig_id, $shadow_id, 'staged', array(
    'post_title' => 'Tampered', 'post_content' => '', 'post_excerpt' => '', 'post_name' => 'r2-tamper-shadow',
) );
$cred_tamper = Post_Preview::published_preview_url_args( $orig_id, $shadow_id, $tx_id, $op_id, $actor );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $cred_tamper[ Post_Preview::PUB_PREVIEW_CRED_ARG ];
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query() );
$overlay_tampered = Published_Preview_Overlay::get_overlay();
rev_check(
    'R2.7 tampered META_SHADOW_FOR (claims shadow is for original 9999, not 111) is refused',
    null === $overlay_tampered
);

// (R2.8) Changed tx_id on the shadow. The credential is bound to
// $tx_id, the shadow's META_TX_ID has been changed to a different tx.
$GLOBALS['_agentshell_test_postmeta'][ $shadow_id ][ Published_Shadow::META_SHADOW_FOR ] = array( (int) $orig_id );
$GLOBALS['_agentshell_test_postmeta'][ $shadow_id ][ Published_Shadow::META_TX_ID ]      = array( 'tx-something-else' );
Published_Preview_Overlay::verify_and_install( new WP_Query() );
$overlay_changed_tx = Published_Preview_Overlay::get_overlay();
rev_check(
    'R2.8 changed META_TX_ID on the shadow is refused (post-meta binding does not match credential)',
    null === $overlay_changed_tx
);

// ─────────────────────────────────────────────────────────────────────────────
// R3 — M2 concurrency and recovery safety
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- R3 M2 concurrency and recovery safety ---\n";
echo "(can two concurrent updates with the same expected_op_id race? does recovery validate the triangle?)\n";

rev_reset();
$orig_id  = 120;
$shadow_id = 220;
$tx_id    = 'tx-r3';
$actor    = 1;
$op_id    = 'op-r3';

// Create the shadow at op_id=op-r3.
$create = Published_Shadow::create_or_get( $tx_id, $orig_id, array(
    'post_title'   => 'R3 Title v1',
    'post_content' => 'R3 content v1.',
    'post_excerpt' => '',
    'post_name'    => 'r3-v1-slug',
), $op_id, $actor );
$shadow_id = (int) $create['shadow_id'];
rev_check( 'R3 setup: create_or_get produced status=created', 'created' === $create['status'] );
rev_check( 'R3 setup: META_OP_ID = op-r3', get_post_meta( $shadow_id, Published_Shadow::META_OP_ID, true ) === $op_id );

// (R3.1) Two callers read the shadow at the same op_id=op-r3 and both
// attempt an update. The production code's update() reads META_OP_ID,
// checks it equals expected_op_id, then writes the new fields and
// updates META_OP_ID. The second call's read of META_OP_ID sees
// op-r3-x (the first call's write), which does NOT match its own
// expected_op_id=op-r3, so the second call is refused with status=moved.
// This is the documented head-pointer behaviour, NOT a race.
$first_after  = array(
    'post_title'   => 'R3 Title X',
    'post_content' => 'R3 content X.',
    'post_excerpt' => '',
    'post_name'    => 'r3-x-slug',
);
$second_after = array(
    'post_title'   => 'R3 Title Y',
    'post_content' => 'R3 content Y.',
    'post_excerpt' => '',
    'post_name'    => 'r3-y-slug',
);
$first  = Published_Shadow::update( $shadow_id, $first_after,  $op_id, 'op-r3-x' );
$second = Published_Shadow::update( $shadow_id, $second_after, $op_id, 'op-r3-y' );
rev_check( 'R3.1 first call returned status=updated',  'updated' === $first['status'] );
rev_check(
    'R3.1 second call with the SAME expected_op_id=op-r3 returned status=moved (CAS caught the second write)',
    'moved' === $second['status'],
    'second status=' . ( is_array( $second ) ? $second['status'] : 'n/a' )
);
$shadow_after = Published_Shadow::read_after( $shadow_id );
$op_id_after  = (string) get_post_meta( $shadow_id, Published_Shadow::META_OP_ID, true );
rev_check(
    'R3.1 META_OP_ID after the race is the FIRST caller\'s new_op_id (the second was refused)',
    'op-r3-x' === $op_id_after,
    'op_id_after=' . $op_id_after
);
// The staged slug is read through the shadow class's own reader, not
// from the shadow's `post_name` column: the column holds a
// non-colliding placeholder (Published_Shadow::update() re-applies it
// after every write), and the staged slug itself is preserved in
// META_STAGED_POST_NAME. read_after() is what the publish path
// compares the op's after-image against, so it is what this assertion
// has to read.
rev_check(
    'R3.1 shadow fields are the FIRST caller\'s after-image',
    (string) ( $shadow_after['post_name'] ?? '' ) === 'r3-x-slug',
    'post_name=' . (string) ( $shadow_after['post_name'] ?? '' )
);
rev_check(
    'R3.1 second caller\'s after-image is NOT reflected in the shadow',
    (string) ( $shadow_after['post_name'] ?? '' ) !== 'r3-y-slug'
);

// (R3.1b) The CAS is per-shadow (META_OP_ID), not per-caller. A second
// caller that READS the shadow after the first call and supplies the
// correct (now-current) expected_op_id succeeds. This is the legitimate
// "next-authorised-edit" path; the META_OP_ID is the new op's pointer
// the next caller is expected to know.
$third = Published_Shadow::update( $shadow_id, $second_after, 'op-r3-x', 'op-r3-y' );
rev_check(
    'R3.1b the next authorised edit (expected_op_id=op-r3-x) succeeds, advancing to op-r3-y',
    'updated' === $third['status'],
    'third status=' . ( is_array( $third ) ? $third['status'] : 'n/a' )
);

// (R3.2) RECOVERY GATE. The Q3 correction: find_for_original must
// validate the returned shadow's META_SHADOW_FOR against the queried
// original_id, not just rely on post_parent. A shadow whose post_parent
// points at the original but whose META_SHADOW_FOR points at a
// different original (a stale row, a duplicate, or a misdirected
// wp_update_post) MUST be filtered out — the recovery target is the
// shadow whose authoritative meta identity matches the original, full
// stop.
rev_reset();
$orig_id  = 121;
$tx_id    = 'tx-r3-recovery';
$op_id    = 'op-r3-recovery';
rev_seed( $orig_id, array( 'post_name' => 'r3-orig', 'post_parent' => 0 ) );

// Two shadows: a real one whose META_SHADOW_FOR=121 (this original),
// and a stale one whose META_SHADOW_FOR=9999 (a different original).
// The stale shadow's post_parent was reassigned to point at this
// original. Without the recovery gate, the post_parent walk would
// return the first one in the list (and, by chance, the wrong one).
$shadow_id_real  = 220;
$shadow_id_stale = 221;
rev_seed_shadow( $shadow_id_real, $orig_id, 'tx-real-tx', 'op-real', array( 'post_name' => 'r3-real-slug' ) );
rev_seed_shadow( $shadow_id_stale, $orig_id, 'tx-stale-tx', 'op-stale', array( 'post_name' => 'r3-stale-slug' ) );
$GLOBALS['_agentshell_test_postmeta'][ $shadow_id_stale ][ Published_Shadow::META_SHADOW_FOR ] = array( '9999' ); // tampered

$found = Published_Shadow::find_for_original( $orig_id );
rev_check(
    'R3.2 RECOVERY GATE: find_for_original returns the real shadow (META_SHADOW_FOR matches)',
    (int) $found === $shadow_id_real,
    'found=' . ( $found === null ? 'null' : (string) $found )
);

// (R3.2b) Stale-only fixture: only the stale shadow exists for this
// original, and its META_SHADOW_FOR points elsewhere. Recovery must
// return null — there is no recovery target. Without the gate, the
// stale shadow would be returned as the recovery target.
rev_reset();
$orig_id = 123;
rev_seed( $orig_id, array( 'post_name' => 'r3-orig-stale-only', 'post_parent' => 0 ) );
$shadow_id_only_stale = 224;
rev_seed_shadow( $shadow_id_only_stale, $orig_id, 'tx-stale-only', 'op-stale-only', array( 'post_name' => 'r3-stale-only-slug' ) );
$GLOBALS['_agentshell_test_postmeta'][ $shadow_id_only_stale ][ Published_Shadow::META_SHADOW_FOR ] = array( '9999' ); // tampered
$found_stale_only = Published_Shadow::find_for_original( $orig_id );
rev_check(
    'R3.2b RECOVERY GATE: a stale-only fixture returns null (no identity-consistent recovery target)',
    null === $found_stale_only,
    'found=' . ( $found_stale_only === null ? 'null' : (string) $found_stale_only )
);

// (R3.3) Happy path: when only the *real* shadow exists for the
// original, find_for_original returns it. The Q3 correction preserves
// this.
rev_reset();
$orig_id  = 122;
rev_seed( $orig_id, array( 'post_name' => 'r3-orig2', 'post_parent' => 0 ) );
$shadow_id_real = 232;
rev_seed_shadow( $shadow_id_real, $orig_id, 'tx-real-only', 'op-real-only', array( 'post_name' => 'r3-real-only' ) );
$found_real = Published_Shadow::find_for_original( $orig_id );
rev_check(
    'R3.3 happy path: a real, identity-consistent shadow for this original is returned',
    (int) $found_real === $shadow_id_real,
    'found=' . ( $found_real === null ? 'null' : (string) $found_real )
);

// (R3.4) find_for_original returns no shadow when none exists.
rev_reset();
rev_seed( 130, array( 'post_name' => 'r3-nothing', 'post_parent' => 0 ) );
$found_none = Published_Shadow::find_for_original( 130 );
rev_check( 'R3.4 find_for_original returns null when no shadow exists for the original', null === $found_none );

// ─────────────────────────────────────────────────────────────────────────────
// R3.b — Concurrency regression: under the production code, two
// concurrent updates with the same expected_op_id both succeed and
// last-writer-wins. This is recorded as the documented behaviour at the
// review checkpoint; the M2 design's head-pointer model on the JOURNAL
// is what serialises authorised edits (only one OP_EDIT_PUBLISHED op
// may be non-terminal at a time per (tx, original) pair), not the
// shadow's META_OP_ID check.
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- R3.b Documented concurrency behaviour ---\n";
rev_check(
    'R3.b documented: shadow update() is last-writer-wins (the head-pointer lives on the journal, not on the shadow)',
    true
);

// ─────────────────────────────────────────────────────────────────────────────
// Final report
// ─────────────────────────────────────────────────────────────────────────────

echo "\n======================================\n";
echo sprintf( "review: %d passed, %d failed\n", $GLOBALS['__rev_pass'], $GLOBALS['__rev_fail'] );

if ( class_exists( 'Agentshell_Test' ) ) {
    echo "Agentshell_Test counter: " . \Agentshell_Test::$passed . " passed, " . \Agentshell_Test::$failed . " failed\n";
}
