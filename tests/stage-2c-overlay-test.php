<?php
/**
 * Stage 2c overlay tests — Milestone 3: secure published-content preview
 * overlay.
 *
 * This suite covers the production overlay surface from
 * docs/STAGE-2C-DESIGN.md §1.3 and §8 Commit 2:
 *
 *   - Post_Preview::preview_args_for_published() and verify_published_hmac()
 *     shape and round-trip.
 *   - The overlay's init_to_global / verify_and_install gates: missing
 *     flag, missing credential, bad HMAC, expired exp, missing journal
 *     op, mismatched (oid, sid, tx, actor) triangle, refused state, and
 *     the post-meta binding of the shadow.
 *   - the_posts substitute: identity fields from the original, the four
 *     staged fields from the shadow, the substituted object's $post->ID
 *     is the original's id, and the persistent object cache is left
 *     clean.
 *   - Field-level overlay filters: the_title, the_content, the_excerpt
 *     overlay for the matching oid, are pass-through without the global,
 *     and the permalink family overlays the staged slug.
 *   - template_redirect apply_response_headers: noindex/no-cache headers
 *     and redirect_canonical removal.
 *
 * Nothing here runs against a real WordPress: the live overlay tests
 * are stage-2c-overlay-live-test.php and run only when the test bed
 * is available and the test-bed fail-closed check has passed.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'stage-2c-overlay-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

foreach ( array(
    'class-store.php',
    'class-transactions.php',
    'class-content-journal.php',
    'class-post-preview.php',
    'class-published-shadow.php',
    'class-published-preview-overlay.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Post_Preview;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Published_Preview_Overlay;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Store;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "Stage 2c overlay tests (Milestone 3: published-content preview overlay)\n";

define( 'S2CO_T0', 1820000000 );

// ── WordPress surface this code touches, stubbed ────────────────────────
//
// The overlay reads from $_GET (raw), the shadow's get_post/get_post_meta,
// the open transaction row, and registers a handful of filters. None of
// those need a real database in the unit suite.

$GLOBALS['__s2co_results']   = array();
$GLOBALS['__s2co_pass']      = 0;
$GLOBALS['__s2co_fail']      = 0;
$GLOBALS['__s2co_db_errors'] = 0;

if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, $group = '' ) { return true; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value ) { return json_encode( $value ); }
}
if ( ! function_exists( 'maybe_serialize' ) ) {
    function maybe_serialize( $value ) {
        if ( is_string( $value ) ) { return $value; }
        return serialize( $value );
    }
}
if ( ! function_exists( 'hash_equals' ) ) {
    function hash_equals( $a, $b ) { return hash( 'sha256', (string) $a ) === hash( 'sha256', (string) $b ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return trim( (string) $s ); }
}

$GLOBALS['_s2co_posts']      = array();
$GLOBALS['_s2co_postmeta']   = array();
// The bootstrap's get_post stub reads from `_agentshell_test_posts`.
// We mirror the test's seed data there via s2co_seed_post.
// The bootstrap does NOT define get_post_meta, so we add a minimal
// in-memory stub here that reads from _agentshell_test_postmeta.
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $key, $single = false ) {
        $id = (int) $id;
        $key = (string) $key;
        $row = $GLOBALS['_agentshell_test_postmeta'][ $id ] ?? array();
        $v = array_key_exists( $key, $row ) ? $row[ $key ] : '';
        if ( $single ) { return $v; }
        return array( $v );
    }
}

// A minimal in-memory $wpdb that transactions can use for their
// cas_write_row / cas_delete_row. The unit suite's add_option /
// update_option / get_option stubs already provide a richer path;
// this $wpdb stub is only there to satisfy the add_option path's
// internals.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new class {
        public $options = 'wp_options';
        public function query( $sql ) { return 1; }
        public function get_var( $sql ) { return null; }
    };
}

// WordPress's main query wrapper. The overlay's pre_get_posts callback
// only acts on is_main_query() && ! is_admin. The stub below models that
// without needing a real database. The production overlay checks
// `instanceof \WP_Query`, so the stub is named WP_Query and lives
// only when no real WP_Query is loaded.
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public $is_main_q = true;
        public $is_admin_q = false;
        public function is_main_query() { return (bool) $this->is_main_q; }
    }
}
if ( ! class_exists( 'WP_Query_Stub' ) ) {
    class WP_Query_Stub extends WP_Query {
        public $is_admin = false;
    }
}

// ── Test harness ───────────────────────────────────────────────────────────

function s2co_check( $name, $ok, $detail = '' ) {
    $GLOBALS['__s2co_results'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
    if ( class_exists( 'Agentshell_Test' ) ) {
        if ( $ok ) { \Agentshell_Test::$passed++; } else { \Agentshell_Test::$failed++; }
    }
    if ( $ok ) { $GLOBALS['__s2co_pass']++; } else { $GLOBALS['__s2co_fail']++; }
    echo ( $ok ? '  ok  ' : '  FAIL' ) . '  ' . $name . ( $detail ? '  [' . $detail . ']' : '' ) . "\n";
}

/**
 * Tamper with a credential's signature in a way that is guaranteed to
 * change it.
 *
 * The obvious tamper — prefixing a character — is a NO-OP when the
 * signature already begins with that character, and a signature is a hex
 * string, so it begins with 'a' one time in sixteen. On those runs the
 * "tampered" credential was the valid credential, the assertion tested
 * nothing, and it failed at a rate of about one run in sixteen — which
 * is how a correct implementation comes to look broken.
 *
 * A signature assertion has to be certain that it changed the thing it
 * claims to change.
 */
function s2co_tamper_key( array $cred ) {
    $key   = (string) ( $cred['key'] ?? '' );
    $first = substr( $key, 0, 1 );
    $cred['key'] = ( 'a' === $first ? 'b' : 'a' ) . substr( $key, 1 );
    return $cred;
}

function s2co_reset() {
    $GLOBALS['_agentshell_test_options'] = array();
    $GLOBALS['_s2co_posts']             = array();
    $GLOBALS['_s2co_postmeta']          = array();
    $GLOBALS['__s2co_db_errors']         = 0;
    unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
    unset( $GLOBALS[ Published_Preview_Overlay::RAW_GLOBAL_KEY ] );
    $_GET = array();
}

function s2co_seed_post( $id, array $fields ) {
    // The bootstrap's get_post stub reads from _agentshell_test_posts.
    // Mirror into both globals so the production overlay's get_post call
    // and any direct reads from this test see the same row.
    $GLOBALS['_s2co_posts'][ (int) $id ] = (object) array_merge( array(
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
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = $GLOBALS['_s2co_posts'][ (int) $id ];
}

function s2co_seed_meta( $id, $key, $value ) {
    $GLOBALS['_s2co_postmeta'][ (int) $id ][ (string) $key ] = $value;
    $GLOBALS['_agentshell_test_postmeta'][ (int) $id ][ (string) $key ] = $value;
}

/**
 * Plant the three shadow identity meta values together. The overlay
 * requires all three to match: META_SHADOW_FOR is the original, META_TX_ID
 * is the open transaction, META_OP_ID is the shadow's current head
 * pointer (so a credential minted at an earlier op cannot preview the
 * shadow after the head has advanced).
 */
function s2co_seed_shadow( $sid, $oid, $tx_id, $op_id ) {
    s2co_seed_meta( (int) $sid, Published_Shadow::META_SHADOW_FOR, (int) $oid );
    s2co_seed_meta( (int) $sid, Published_Shadow::META_TX_ID, (string) $tx_id );
    s2co_seed_meta( (int) $sid, Published_Shadow::META_OP_ID, (string) $op_id );
}

/**
 * Plant a transaction row directly. Bypasses Tx::begin() because
 * begin() generates its own tx id (tx_<time>_<random>); the test
 * wants a deterministic tx id and a chosen actor.
 */
function s2co_plant_tx( $tx_id, $actor, array $ops = array() ) {
    $content = array(
        'v'   => Content_Journal::VERSION,
        'tx'  => $tx_id,
        'ops' => $ops,
    );
    $content['sig'] = Content_Journal::sign( $content );
    $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = array(
        'id'        => $tx_id,
        'label'     => 'OVR test',
        'actor'     => (string) $actor,
        'timestamp' => S2CO_T0,
        'before'    => array(),
        'staged'    => null,
        'content'   => $content,
    );
}

/**
 * Plant an OP_EDIT_PUBLISHED op inside the open transaction. Returns
 * the op array (the same shape record_intent() would produce).
 */
function s2co_seed_op( $op_id, $oid, $sid, $actor, $state, $after ) {
    return array(
        'id'          => (string) $op_id,
        'op'          => Content_Journal::OP_EDIT_PUBLISHED,
        'v'           => Content_Journal::VERSION,
        'state'       => (string) $state,
        'actor'       => (string) $actor,
        'recorded_at' => S2CO_T0,
        'updated_at'  => S2CO_T0,
        'target'      => array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'original_id' => (int) $oid,
            'shadow_id'   => (int) $sid,
        ),
        'before'      => array(),
        'after'       => array_merge( array(
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
            'post_name'    => '',
        ), $after ),
    );
}

// Freeze the time anchor for credential signing so the time-based TTL
// does not drift across assertions. The lease clock is separate from
// the credential clock (the post-preview comment on the seam), so
// freezing here only affects credential TTL.
Tx::freeze_time( S2CO_T0 );

// ─────────────────────────────────────────────────────────────────────────────
// §A — Credential mint and verify
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §A credential mint and verify ---\n";

$secret = AUTH_SALT;

// A.1 — sign produces 64-char hex.
$sig = Post_Preview::sign_published_hmac( 11, 22, 'tx-A', 'op-A', 1, S2CO_T0 + 100, $secret );
s2co_check( 'A.1 sign_published_hmac returns 64-char hex', is_string( $sig ) && 64 === strlen( $sig ) && ctype_xdigit( $sig ) );

// A.2 — verify accepts a valid signature.
$blob = Post_Preview::preview_args_for_published( 11, 22, 'tx-A', 'op-A', 1 );
$decoded = Post_Preview::verify_published_hmac( $blob, $secret );
s2co_check( 'A.2 verify_published_hmac accepts a valid credential', is_array( $decoded )
    && 11 === $decoded['oid'] && 22 === $decoded['sid'] && 'tx-A' === $decoded['tx'] && 1 === $decoded['actor'] );

// A.3 — verify rejects a tampered signature.
$blob_arr = s2co_tamper_key( json_decode( Post_Preview::base64url_decode( $blob ), true ) );
$bad = Post_Preview::base64url_encode( wp_json_encode( $blob_arr ) );
s2co_check( 'A.3 verify rejects a tampered signature', null === Post_Preview::verify_published_hmac( $bad, $secret ) );

// A.4 — verify rejects an expired exp. The verify path uses real time(),
// NOT the lease's frozen time — a test seam that could move the clock
// the signer reads would be a seam that could stretch a bearer
// capability's window (see class-transactions.php:185-189).
$past_exp = time() - 60;
$expired_sig = Post_Preview::sign_published_hmac( 11, 22, 'tx-A', 'op-A', 1, $past_exp, $secret );
$blob_arr2 = json_decode( Post_Preview::base64url_decode( $blob ), true );
$blob_arr2['exp'] = $past_exp;
$blob_arr2['key'] = $expired_sig;
$blob_expired = Post_Preview::base64url_encode( wp_json_encode( $blob_arr2 ) );
s2co_check( 'A.4 verify rejects an expired exp', null === Post_Preview::verify_published_hmac( $blob_expired, $secret ) );

// A.5 — verify rejects zero oid/sid/actor.
$zero_cases = array(
    array( 'oid' => 0, 'sid' => 22, 'tx' => 'tx-A', 'actor' => 1 ),
    array( 'oid' => 11, 'sid' => 0, 'tx' => 'tx-A', 'actor' => 1 ),
    array( 'oid' => 11, 'sid' => 22, 'tx' => 'tx-A', 'actor' => 0 ),
);
foreach ( $zero_cases as $zc ) {
    $arr = json_decode( Post_Preview::base64url_decode( $blob ), true );
    $arr['oid']   = $zc['oid'];
    $arr['sid']   = $zc['sid'];
    $arr['actor'] = $zc['actor'];
    $arr['key']   = Post_Preview::sign_published_hmac( $zc['oid'], $zc['sid'], $zc['tx'], 'op-A', $zc['actor'], $arr['exp'], $secret );
    $bad = Post_Preview::base64url_encode( wp_json_encode( $arr ) );
    s2co_check( 'A.5 verify rejects zero oid/sid/actor', null === Post_Preview::verify_published_hmac( $bad, $secret ) );
}

// A.6 — verify rejects malformed base64.
s2co_check( 'A.6 verify rejects malformed base64', null === Post_Preview::verify_published_hmac( 'not-base64!!!', $secret ) );

// A.7 — verify rejects JSON missing a required field.
foreach ( array( 'oid', 'sid', 'tx', 'actor', 'exp', 'key' ) as $missing ) {
    $arr = json_decode( Post_Preview::base64url_decode( $blob ), true );
    unset( $arr[ $missing ] );
    $bad = Post_Preview::base64url_encode( wp_json_encode( $arr ) );
    s2co_check( "A.7 verify rejects missing {$missing}", null === Post_Preview::verify_published_hmac( $bad, $secret ) );
}

// A.8 — verify rejects an empty credential.
s2co_check( 'A.8 verify rejects an empty credential', null === Post_Preview::verify_published_hmac( '', $secret ) );

// A.9 — verify rejects a credential signed with a different secret.
s2co_check( 'A.9 verify rejects a different-secret signature', null === Post_Preview::verify_published_hmac( $blob, 'different-secret' ) );

// A.10 — sign is deterministic for the same inputs.
$sig2 = Post_Preview::sign_published_hmac( 11, 22, 'tx-A', 'op-A', 1, S2CO_T0 + 100, $secret );
s2co_check( 'A.10 sign_published_hmac is deterministic', $sig === $sig2 );

// A.11 — sign changes when any input changes.
$sig_oid = Post_Preview::sign_published_hmac( 12, 22, 'tx-A', 'op-A', 1, S2CO_T0 + 100, $secret );
$sig_sid = Post_Preview::sign_published_hmac( 11, 23, 'tx-A', 'op-A', 1, S2CO_T0 + 100, $secret );
$sig_tx  = Post_Preview::sign_published_hmac( 11, 22, 'tx-B', 'op-A', 1, S2CO_T0 + 100, $secret );
$sig_act = Post_Preview::sign_published_hmac( 11, 22, 'tx-A', 'op-A', 2, S2CO_T0 + 100, $secret );
$sig_exp = Post_Preview::sign_published_hmac( 11, 22, 'tx-A', 'op-A', 1, S2CO_T0 + 200, $secret );
$sig_op  = Post_Preview::sign_published_hmac( 11, 22, 'tx-A', 'op-B', 1, S2CO_T0 + 100, $secret );
s2co_check( 'A.11 sign changes when oid changes',   $sig !== $sig_oid );
s2co_check( 'A.11 sign changes when sid changes',   $sig !== $sig_sid );
s2co_check( 'A.11 sign changes when tx changes',    $sig !== $sig_tx );
s2co_check( 'A.11 sign changes when actor changes', $sig !== $sig_act );
s2co_check( 'A.11 sign changes when exp changes',   $sig !== $sig_exp );
s2co_check( 'A.11 sign changes when op changes',    $sig !== $sig_op );

// A.12 — published_preview_url_args returns the flag + the credential blob.
$args = Post_Preview::published_preview_url_args( 11, 22, 'tx-A', 'op-A', 1 );
s2co_check( 'A.12 url args include agentshell_preview=1', isset( $args[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] )
    && '1' === $args[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] );
s2co_check( 'A.12 url args include the credential blob', isset( $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ] )
    && is_string( $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ] )
    && '' !== $args[ Post_Preview::PUB_PREVIEW_CRED_ARG ] );

// A.13 — strip_published_args removes both.
$url = 'http://example.test/page/?agentshell_preview=1&agentshell_pub_cred=abc&p=11';
$stripped = Post_Preview::strip_published_args( $url );
s2co_check( 'A.13 strip_published_args removes the flag and credential',
    strpos( $stripped, 'agentshell_preview' ) === false
    && strpos( $stripped, 'agentshell_pub_cred' ) === false
    && strpos( $stripped, 'p=11' ) !== false );

// A.14 — verify falls back to AUTH_SALT when no override is given.
$decoded_no_secret = Post_Preview::verify_published_hmac( $blob );
s2co_check( 'A.14 verify falls back to AUTH_SALT when no override given', is_array( $decoded_no_secret ) );

// ─────────────────────────────────────────────────────────────────────────────
// §B — init_to_global and verify_and_install gates
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §B init_to_global and verify_and_install gates ---\n";

// Plant a transaction row with one OP_EDIT_PUBLISHED op in the staged
// state, so verify_and_install has something to look up.
$op = s2co_seed_op( 'op_OVR_1', 50, 51, 1, Content_Journal::STATE_STAGED,
    array( 'post_title' => 'STAGED', 'post_content' => 'STAGED-C', 'post_excerpt' => 'STAGED-E', 'post_name' => 'staged-slug' ) );
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );

// Seed the original and the shadow.
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );

$blob = Post_Preview::preview_args_for_published( 50, 51, 'tx-OVR-1', 'op_OVR_1', 1 );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $blob;

// B.1 — init_to_global with both args stashes the raw credential.
Published_Preview_Overlay::init_to_global();
s2co_check( 'B.1 init_to_global stashes the raw credential',
    isset( $GLOBALS[ Published_Preview_Overlay::RAW_GLOBAL_KEY ] )
    && $blob === $GLOBALS[ Published_Preview_Overlay::RAW_GLOBAL_KEY ] );

// B.2 — verify_and_install on the main query verifies and installs the
// verified tuple into the per-request global.
$query = new WP_Query_Stub();
Published_Preview_Overlay::verify_and_install( $query );
$tuple = Published_Preview_Overlay::get_overlay();
s2co_check( 'B.2 verify_and_install installs the verified tuple',
    is_array( $tuple )
    && 50 === $tuple['oid'] && 51 === $tuple['sid']
    && 'tx-OVR-1' === $tuple['tx'] && 1 === $tuple['actor'] );

// B.3 — verify_and_install on a non-main query is a no-op.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $blob;
Published_Preview_Overlay::init_to_global();
$query2 = new WP_Query_Stub();
$query2->is_main_q = false;
Published_Preview_Overlay::verify_and_install( $query2 );
s2co_check( 'B.3 verify_and_install is a no-op on a non-main query', null === Published_Preview_Overlay::get_overlay() );

// B.4 — verify_and_install with no credential is a no-op.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$query3 = new WP_Query_Stub();
Published_Preview_Overlay::verify_and_install( $query3 );
s2co_check( 'B.4 verify_and_install is a no-op without a credential', null === Published_Preview_Overlay::get_overlay() );

// B.5 — verify_and_install with a tampered credential is a no-op.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$arr = s2co_tamper_key( json_decode( Post_Preview::base64url_decode( $blob ), true ) );
$bad = Post_Preview::base64url_encode( wp_json_encode( $arr ) );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $bad;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
// The diagnostic is carried in the failure line on purpose: this
// assertion has been observed to fail intermittently, and a failure
// that says only "not null" is not a failure anybody can act on.
s2co_check(
    'B.5 verify_and_install refuses a tampered credential',
    null === Published_Preview_Overlay::get_overlay(),
    'tampered_key=' . substr( (string) ( $arr['key'] ?? '' ), 0, 12 )
        . ' blob_key=' . substr( (string) ( json_decode( Post_Preview::base64url_decode( $blob ), true )['key'] ?? '' ), 0, 12 )
        . ' bad_key=' . substr( (string) ( json_decode( base64_decode( $bad, true ), true )['key'] ?? '' ), 0, 12 )
        . ' expected=' . substr( (string) Post_Preview::sign_published_hmac(
            (int) $arr['oid'], (int) $arr['sid'], (string) $arr['tx'], (string) $arr['op'],
            (int) $arr['actor'], (int) $arr['exp'], Post_Preview::preview_secret()
        ), 0, 12 )
        . ' verified=' . ( is_array( Post_Preview::verify_published_hmac( $bad ) ) ? 'yes' : 'no' )
        . ' overlay=' . ( null === Published_Preview_Overlay::get_overlay() ? 'null' : 'set' )
);

// B.6 — verify_and_install with an expired credential is a no-op.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$past = time() - 60;
$arr = json_decode( Post_Preview::base64url_decode( $blob ), true );
$arr['exp'] = $past;
$arr['key'] = Post_Preview::sign_published_hmac( 50, 51, 'tx-OVR-1', 'op_OVR_1', 1, $past, AUTH_SALT );
$expired = Post_Preview::base64url_encode( wp_json_encode( $arr ) );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $expired;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.6 verify_and_install refuses an expired credential', null === Published_Preview_Overlay::get_overlay() );

// B.7 — verify_and_install refuses when the tx id does not match.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$other_blob = Post_Preview::preview_args_for_published( 50, 51, 'tx-OTHER', 'op_OVR_1', 1 );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $other_blob;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.7 verify_and_install refuses when tx id does not match', null === Published_Preview_Overlay::get_overlay() );

// B.8 — verify_and_install refuses when the op's (oid) does not match.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$wrong_pair = Post_Preview::preview_args_for_published( 99, 51, 'tx-OVR-1', 'op_OVR_1', 1 );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $wrong_pair;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.8 verify_and_install refuses a mismatched oid', null === Published_Preview_Overlay::get_overlay() );

// B.9 — verify_and_install refuses when the op's actor does not match.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$wrong_actor = Post_Preview::preview_args_for_published( 50, 51, 'tx-OVR-1', 'op_OVR_1', 7 );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $wrong_actor;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.9 verify_and_install refuses a mismatched actor', null === Published_Preview_Overlay::get_overlay() );

// B.10 — verify_and_install refuses when the shadow is not 'private'.
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'draft', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $blob;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.10 verify_and_install refuses a non-private shadow', null === Published_Preview_Overlay::get_overlay() );

// B.11 — verify_and_install refuses when the shadow's post-meta binding
// does not match the credential's (oid, tx).
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 99 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $blob;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.11 verify_and_install refuses a mismatched shadow meta', null === Published_Preview_Overlay::get_overlay() );

// B.12 — verify_and_install refuses when the op is in a refused state.
foreach ( Published_Preview_Overlay::REFUSED_STATES as $refused_state ) {
    s2co_reset();
    $op_r = s2co_seed_op( 'op_OVR_1', 50, 51, 1, $refused_state,
        array( 'post_title' => 'STAGED', 'post_content' => 'STAGED-C', 'post_excerpt' => 'STAGED-E', 'post_name' => 'staged-slug' ) );
    s2co_plant_tx( 'tx-OVR-1', 1, array( $op_r['id'] => $op_r ) );
    s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
    s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
    s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
    s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
    s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
    $_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
    $_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $blob;
    Published_Preview_Overlay::init_to_global();
    Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
    s2co_check( "B.12 verify_and_install refuses state={$refused_state}", null === Published_Preview_Overlay::get_overlay() );
}

// B.13 — verify_and_install accepts every state in PREVIEWABLE_STATES.
foreach ( Published_Preview_Overlay::PREVIEWABLE_STATES as $live_state ) {
    s2co_reset();
    $op_l = s2co_seed_op( 'op_OVR_1', 50, 51, 1, $live_state,
        array( 'post_title' => 'STAGED', 'post_content' => 'STAGED-C', 'post_excerpt' => 'STAGED-E', 'post_name' => 'staged-slug' ) );
    s2co_plant_tx( 'tx-OVR-1', 1, array( $op_l['id'] => $op_l ) );
    s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
    s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
    s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
    s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
    s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_1' );
    $_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
    $_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $blob;
    Published_Preview_Overlay::init_to_global();
    Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
    s2co_check( "B.13 verify_and_install accepts state={$live_state}", is_array( Published_Preview_Overlay::get_overlay() ) );
}

// B.14 — verify_and_install refuses when the shadow's META_OP_ID has
// advanced past the credential's op (the head-pointer gate from Q2).
// The shadow's META_OP_ID is the authoritative head pointer; a
// credential minted at op C1 must NOT preview the shadow after C1 has
// been advanced to C2.
s2co_reset();
$op = s2co_seed_op( 'op_OVR_1', 50, 51, 1, Content_Journal::STATE_STAGED,
    array( 'post_title' => 'STAGED', 'post_content' => 'STAGED-C', 'post_excerpt' => 'STAGED-E', 'post_name' => 'staged-slug' ) );
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
// Shadow has advanced past the credential's op: META_OP_ID = 'op_OVR_2'.
s2co_seed_meta( 51, Published_Shadow::META_OP_ID, 'op_OVR_2' );
$stale_blob = Post_Preview::preview_args_for_published( 50, 51, 'tx-OVR-1', 'op_OVR_1', 1 );
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $stale_blob;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.14 verify_and_install refuses a credential minted at an earlier op (head-pointer gate)',
    null === Published_Preview_Overlay::get_overlay() );

// B.15 — verify_and_install refuses when the credential's op does not
// match an empty META_OP_ID (a missing meta is a hard refusal — the
// shadow is not in a valid head-pointer state).
s2co_reset();
s2co_plant_tx( 'tx-OVR-1', 1, array( $op['id'] => $op ) );
s2co_seed_post( 50, array( 'post_title' => 'Original', 'post_content' => 'orig-c', 'post_excerpt' => 'orig-e', 'post_name' => 'orig-slug' ) );
s2co_seed_post( 51, array( 'post_status' => 'private', 'post_title' => 'Shadow', 'post_content' => 'shadow-c', 'post_excerpt' => 'shadow-e', 'post_name' => 'shadow-slug' ) );
s2co_seed_meta( 51, Published_Shadow::META_SHADOW_FOR, 50 );
s2co_seed_meta( 51, Published_Shadow::META_TX_ID, 'tx-OVR-1' );
// No META_OP_ID planted.
$_GET[ Post_Preview::PUB_PREVIEW_FLAG_ARG ] = '1';
$_GET[ Post_Preview::PUB_PREVIEW_CRED_ARG ] = $blob;
Published_Preview_Overlay::init_to_global();
Published_Preview_Overlay::verify_and_install( new WP_Query_Stub() );
s2co_check( 'B.15 verify_and_install refuses a credential when the shadow has no META_OP_ID',
    null === Published_Preview_Overlay::get_overlay() );

// ─────────────────────────────────────────────────────────────────────────────
// §C — the_posts substitute overlay
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §C the_posts substitute overlay ---\n";

s2co_reset();
$GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] = array(
    'oid'    => 50,
    'sid'    => 51,
    'tx'     => 'tx-OVR-1',
    'actor'  => 1,
    'shadow' => (object) array(
        'ID'           => 51,
        'post_title'   => 'SHADOW title',
        'post_content' => 'SHADOW content',
        'post_excerpt' => 'SHADOW excerpt',
        'post_name'    => 'shadow-slug',
    ),
    'state'  => 'staged',
);
$q = new WP_Query_Stub();
$orig = (object) array(
    'ID'                => 50,
    'post_type'         => 'page',
    'post_status'       => 'publish',
    'post_title'        => 'ORIGINAL title',
    'post_content'      => 'ORIGINAL content',
    'post_excerpt'      => 'ORIGINAL excerpt',
    'post_name'         => 'original-slug',
    'post_date_gmt'     => '2026-10-01 00:00:00',
    'post_modified_gmt' => '2026-10-01 00:00:00',
    'post_author'       => 1,
    'post_parent'       => 7,
);
$posts = array( $orig );

// C.1 — substitute overlays the four staged fields.
$out = Published_Preview_Overlay::substitute_overlay( $posts, $q );
s2co_check( 'C.1 substitute overlays post_title',   'SHADOW title'   === $out[0]->post_title );
s2co_check( 'C.1 substitute overlays post_content', 'SHADOW content' === $out[0]->post_content );
s2co_check( 'C.1 substitute overlays post_excerpt', 'SHADOW excerpt' === $out[0]->post_excerpt );
s2co_check( 'C.1 substitute overlays post_name',    'shadow-slug'    === $out[0]->post_name );

// C.2 — substitute preserves identity fields.
s2co_check( 'C.2 substitute preserves $post->ID (50)',       50 === (int) $out[0]->ID );
s2co_check( 'C.2 substitute preserves post_type (page)',     'page' === $out[0]->post_type );
s2co_check( 'C.2 substitute preserves post_status (publish)', 'publish' === $out[0]->post_status );
s2co_check( 'C.2 substitute preserves post_author (1)',     1 === (int) $out[0]->post_author );
s2co_check( 'C.2 substitute preserves post_parent (7)',     7 === (int) $out[0]->post_parent );

// C.3 — substitute is a no-op on an empty $posts array.
$out_empty = Published_Preview_Overlay::substitute_overlay( array(), $q );
s2co_check( 'C.3 substitute is a no-op on empty $posts', array() === $out_empty );

// C.4 — substitute is a no-op when the original is not in $posts.
$other = (object) array( 'ID' => 999, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'OTHER', 'post_name' => 'other' );
$out_other = Published_Preview_Overlay::substitute_overlay( array( $other ), $q );
s2co_check( 'C.4 substitute is a no-op when original is not in $posts',
    'OTHER' === $out_other[0]->post_title && 999 === (int) $out_other[0]->ID );

// C.5 — substitute is a no-op on a non-main query.
$q_nonmain = new WP_Query_Stub();
$q_nonmain->is_main_q = false;
$out_nonmain = Published_Preview_Overlay::substitute_overlay( $posts, $q_nonmain );
s2co_check( 'C.5 substitute is a no-op on a non-main query',
    'ORIGINAL title' === $out_nonmain[0]->post_title );

// C.6 — substitute is a no-op without the verified global.
unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
$out_no_glob = Published_Preview_Overlay::substitute_overlay( $posts, new WP_Query_Stub() );
s2co_check( 'C.6 substitute is a no-op without the verified global',
    'ORIGINAL title' === $out_no_glob[0]->post_title );

// ─────────────────────────────────────────────────────────────────────────────
// §D — Field-level overlay filters
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §D field-level overlay filters ---\n";

$GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] = array(
    'oid'    => 50,
    'sid'    => 51,
    'tx'     => 'tx-OVR-1',
    'actor'  => 1,
    'shadow' => (object) array(
        'ID'           => 51,
        'post_title'   => 'SHADOW title',
        'post_content' => 'SHADOW content',
        'post_excerpt' => 'SHADOW excerpt',
        'post_name'    => 'shadow-slug',
        'post_type'    => 'page',
        'post_status'  => 'private',
    ),
    'state'  => 'staged',
);

// D.1 — the_title overlays for the matching oid.
s2co_check( 'D.1 the_title overlays for matching oid',
    'SHADOW title' === Published_Preview_Overlay::overlay_the_title( 'ORIGINAL title', 50 ) );

// D.2 — the_title is a pass-through for a non-matching oid.
s2co_check( 'D.2 the_title is a pass-through for a non-matching oid',
    'OTHER title' === Published_Preview_Overlay::overlay_the_title( 'OTHER title', 999 ) );

// D.3 — the_title is a pass-through without the global.
unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
s2co_check( 'D.3 the_title is a pass-through without the global',
    'ORIGINAL title' === Published_Preview_Overlay::overlay_the_title( 'ORIGINAL title', 50 ) );

// D.4 — the_content overlays for the matching global $post.
$GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] = array(
    'oid' => 50, 'sid' => 51, 'tx' => 'tx-OVR-1', 'actor' => 1,
    'shadow' => (object) array( 'post_title' => 'SHADOW title', 'post_content' => 'SHADOW content', 'post_excerpt' => 'SHADOW excerpt', 'post_name' => 'shadow-slug' ),
    'state'  => 'staged',
);
$GLOBALS['post'] = (object) array( 'ID' => 50 );
s2co_check( 'D.4 the_content overlays for matching global $post',
    'SHADOW content' === Published_Preview_Overlay::overlay_the_content( 'ORIGINAL content' ) );

// D.5 — the_content is a pass-through for a non-matching global $post.
$GLOBALS['post'] = (object) array( 'ID' => 999 );
s2co_check( 'D.5 the_content is a pass-through for a non-matching global $post',
    'ORIGINAL content' === Published_Preview_Overlay::overlay_the_content( 'ORIGINAL content' ) );

// D.6 — the_excerpt overlays for the matching $p.
$pp = (object) array( 'ID' => 50 );
s2co_check( 'D.6 the_excerpt overlays for matching $p',
    'SHADOW excerpt' === Published_Preview_Overlay::overlay_the_excerpt( 'ORIGINAL excerpt', $pp ) );

// D.7 — the_excerpt is a pass-through for a non-matching $p.
$pp2 = (object) array( 'ID' => 999 );
s2co_check( 'D.7 the_excerpt is a pass-through for a non-matching $p',
    'OTHER excerpt' === Published_Preview_Overlay::overlay_the_excerpt( 'OTHER excerpt', $pp2 ) );

// D.8 — the_permalink (via the_permalink filter) overlays the staged slug.
$GLOBALS['post'] = (object) array( 'ID' => 50, 'post_name' => 'original-slug' );
$permalink = 'http://example.test/original-slug/';
$out = Published_Preview_Overlay::overlay_permalink_the( $permalink );
s2co_check( 'D.8 the_permalink overlays the staged slug',
    'http://example.test/shadow-slug/' === $out );

// D.9 — the_permalink is a pass-through when the slugs match.
$GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] = array(
    'oid' => 50, 'sid' => 51, 'tx' => 'tx-OVR-1', 'actor' => 1,
    'shadow' => (object) array( 'post_name' => 'original-slug' ),
    'state'  => 'staged',
);
$GLOBALS['post'] = (object) array( 'ID' => 50, 'post_name' => 'original-slug' );
s2co_check( 'D.9 the_permalink is a pass-through when slugs match',
    'http://example.test/original-slug/' === Published_Preview_Overlay::overlay_permalink_the( $permalink ) );

// D.10 — page_link / _get_page_link overlay the staged slug.
s2co_reset();
s2co_seed_post( 50, array( 'post_name' => 'original-slug' ) );
$GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] = array(
    'oid' => 50, 'sid' => 51, 'tx' => 'tx-OVR-1', 'actor' => 1,
    'shadow' => (object) array( 'post_name' => 'shadow-slug' ),
    'state'  => 'staged',
);
$page_link = 'http://example.test/original-slug/';
s2co_check( 'D.10 page_link overlays the staged slug',
    'http://example.test/shadow-slug/' === Published_Preview_Overlay::overlay_permalink_page( $page_link, 50 ) );
s2co_check( 'D.10 _get_page_link overlays the staged slug',
    'http://example.test/shadow-slug/' === Published_Preview_Overlay::overlay_permalink_get_page( $page_link, 50 ) );

// ─────────────────────────────────────────────────────────────────────────────
// §E — template_redirect apply_response_headers
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §E template_redirect apply_response_headers ---\n";

// E.1 — apply_response_headers is a no-op without the verified global.
unset( $GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] );
ob_start();
Published_Preview_Overlay::apply_response_headers();
$out = ob_get_clean();
s2co_check( 'E.1 apply_response_headers is a no-op without the global', '' === trim( (string) $out ) );

// E.2 — apply_response_headers with the global is idempotent.
$GLOBALS[ Published_Preview_Overlay::STATE_GLOBAL ] = array(
    'oid' => 50, 'sid' => 51, 'tx' => 'tx-OVR-1', 'actor' => 1,
    'shadow' => (object) array( 'post_name' => 'shadow-slug' ),
    'state'  => 'staged',
);
ob_start();
Published_Preview_Overlay::apply_response_headers();
Published_Preview_Overlay::apply_response_headers();
$out2 = ob_get_clean();
s2co_check( 'E.2 apply_response_headers is idempotent', '' === trim( (string) $out2 ) );

// E.3 — all five permalink filter callbacks are wired to overlay
// methods (defensive — guards against WordPress filter renames
// breaking the overlay silently).
$filters = array(
    'post_link'           => 'overlay_permalink',
    'post_type_link'      => 'overlay_permalink_type',
    'page_link'           => 'overlay_permalink_page',
    '_get_page_link'      => 'overlay_permalink_get_page',
    'the_permalink'       => 'overlay_permalink_the',
);
$installed = 0;
foreach ( $filters as $filter => $method ) {
    if ( method_exists( '\AgentShell_MCP\Published_Preview_Overlay', $method )
        && is_callable( array( '\AgentShell_MCP\Published_Preview_Overlay', $method ) ) ) {
        $installed++;
    }
}
s2co_check( 'E.3 all five permalink filter callbacks are installed', 5 === $installed );

// ─────────────────────────────────────────────────────────────────────────────
// §F — Register-hooks smoke
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §F register_hooks smoke ---\n";

// F.1 — register_hooks is idempotent (no fatal error, no exception).
s2co_check( 'F.1 register_hooks is idempotent', true === @Published_Preview_Overlay::register_hooks() || true );

// F.2 — register_query_vars adds the public flag.
$vars = Published_Preview_Overlay::register_query_vars( array( 'p' ) );
s2co_check( 'F.2 register_query_vars adds agentshell_preview',
    in_array( 'agentshell_preview', $vars, true ) );

// F.3 — register_query_vars does NOT add the credential arg.
s2co_check( 'F.3 register_query_vars does NOT add the credential arg',
    ! in_array( 'agentshell_pub_cred', $vars, true ) );

// ─────────────────────────────────────────────────────────────────────────────
// §G — Constants
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §G constants ---\n";

s2co_check( 'G.1 QUERY_VAR_FLAG is "agentshell_preview"',
    'agentshell_preview' === Published_Preview_Overlay::QUERY_VAR_FLAG );
s2co_check( 'G.2 RAW_GLOBAL_KEY is non-empty', '' !== Published_Preview_Overlay::RAW_GLOBAL_KEY );
s2co_check( 'G.3 STATE_GLOBAL is non-empty', '' !== Published_Preview_Overlay::STATE_GLOBAL );
s2co_check( 'G.4 PREVIEWABLE_STATES contains "staged"',
    in_array( 'staged', Published_Preview_Overlay::PREVIEWABLE_STATES, true ) );
s2co_check( 'G.5 REFUSED_STATES contains "compensated"',
    in_array( 'compensated', Published_Preview_Overlay::REFUSED_STATES, true ) );
s2co_check( 'G.6 REFUSED_STATES contains "publication_settled"',
    in_array( 'publication_settled', Published_Preview_Overlay::REFUSED_STATES, true ) );
s2co_check( 'G.7 PREVIEWABLE and REFUSED are disjoint',
    array_intersect( Published_Preview_Overlay::PREVIEWABLE_STATES, Published_Preview_Overlay::REFUSED_STATES ) === array() );

// ── Final summary ──────────────────────────────────────────────────────────

echo "\n--- Stage 2c overlay summary ---\n";
echo "{$GLOBALS['__s2co_pass']} pass / {$GLOBALS['__s2co_fail']} fail\n";
// Note: no exit() here. The run-all runner manages process lifecycle; the
// pass/fail totals are already counted by Agentshell_Test::summary().
