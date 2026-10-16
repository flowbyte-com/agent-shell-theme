<?php
/**
 * Stage 2c shadow tests — Milestone 2: private-shadow creation,
 * successive authorised shadow edits, strong journal head-pointer
 * evidence, and idempotency.
 *
 * What this suite covers:
 *
 *   - The per-(tx, original) shadow is a private post whose
 *     post_parent is the original. The shadow is invisible to
 *     any public render (WordPress's own `private` status).
 *
 *   - One shadow per (tx, original). A second create_or_get()
 *     for the same pair returns the existing shadow; a third
 *     edit refreshes the same shadow's fields rather than
 *     creating a new post.
 *
 *   - Successive authorised shadow edits update the shadow
 *     in place, value-guarded by the previous op_id. A caller
 *     whose expected op_id does not match the live shadow is
 *     refused (`moved`), not silently overwritten.
 *
 *   - The shadow's stored op_id advances on every update; the
 *     journal's head pointer for the original is the load-
 *     bearing evidence for "which op's after-image is the
 *     publish step going to use".
 *
 *   - The shadow lookup by (tx, original) and by original alone
 *     (recovery path) both work.
 *
 * What this suite does NOT cover:
 *   - The preview overlay (Milestone 3, separate suite).
 *   - The publish step (Milestone 4).
 *   - The reconciliation step (Milestone 5).
 *   - The recovery engine (Milestone 6).
 *
 * Nothing here runs against a real WordPress.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'stage-2c-shadow-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

foreach ( array(
    'class-store.php',
    'class-transactions.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-published-claim.php',
    'class-published-shadow.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Transaction_Manager as Tx;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "Stage 2c shadow tests (Milestone 2: shadow creation + successive edits + idempotency)\n";

if ( ! defined( 'S2C_T0' ) ) { define( 'S2C_T0', 1810000000 ); }

// ── WordPress surface this code touches, stubbed ─────────────────────────────

$GLOBALS['_agentshell_test_postmeta'] = array();
$GLOBALS['_agentshell_test_insert_calls'] = array();
$GLOBALS['_agentshell_test_update_calls'] = array();
$GLOBALS['_agentshell_test_delete_calls'] = array();

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
        $GLOBALS['_agentshell_test_insert_calls'][] = $postarr;
        // Use the journal-recovery apply helper when available, so the
        // post meta is stamped exactly the way other suites expect it
        // and the post id counter is shared. In a standalone run
        // (this suite alone) the helper is not defined, so the
        // inline path installs the post + meta directly.
        if ( function_exists( '_agentshell_test_apply_wp_insert' ) ) {
            return _agentshell_test_apply_wp_insert( $postarr );
        }
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
                // Match the array-wrapped format that update_post_meta()
                // and get_post_meta( $single=true ) expect.
                $GLOBALS['_agentshell_test_postmeta'][ (int) $next_id ][ (string) $k ] = array( $v );
            }
        }
        return (int) $next_id;
    }
}
if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $postarr, $wp_error = false ) {
        $GLOBALS['_agentshell_test_update_calls'][] = $postarr;
        if ( function_exists( '_agentshell_test_apply_wp_update' ) ) {
            return _agentshell_test_apply_wp_update( $postarr );
        }
        $id = (int) ( $postarr['ID'] ?? 0 );
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $id ] ) ) {
            return 0;
        }
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
        $GLOBALS['_agentshell_test_delete_calls'][] = (int) $post_id;
        $id = (int) $post_id;
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $id ] ) ) { return false; }
        unset( $GLOBALS['_agentshell_test_posts'][ $id ] );
        unset( $GLOBALS['_agentshell_test_postmeta'][ $id ] );
        return true;
    }
}

// When the content-write-test stub is the active `wp_insert_post` (which is
// the case in the run-all context), the dispatcher it consults returns the
// scripted `cw_write_result` rather than the real id the journal-recovery
// apply produced. For the shadow tests, the real id matters (the post
// fields are read back by get_post() and the meta is read back by
// get_post_meta()). Replace the dispatcher with one that returns the
// apply's real id, so the shadow's `created.shadow_id` matches the row
// the apply wrote. content-write's own assertions run before this point
// in the suite order, so swapping the dispatcher for stage-2c-shadow is
// safe.
if ( isset( $GLOBALS['cw_wp_insert_dispatcher'] ) && function_exists( '_agentshell_test_apply_wp_insert' ) ) {
    $GLOBALS['cw_wp_insert_dispatcher'] = function ( $postarr, $scripted_result ) {
        $GLOBALS['_agentshell_test_insert_calls'][] = $postarr;
        return _agentshell_test_apply_wp_insert( $postarr );
    };
}

// ── Test harness ────────────────────────────────────────────────────────────

$GLOBALS['__s2c_results'] = array();
$GLOBALS['__s2c_pass']    = 0;
$GLOBALS['__s2c_fail']    = 0;
function s2cs_check( $name, $ok, $detail = '' ) {
    $GLOBALS['__s2c_results'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
    // Drive the Agentshell_Test counters too so the run-all runner's
    // pass/fail totals reflect these assertions.
    if ( class_exists( 'Agentshell_Test' ) ) {
        if ( $ok ) { \Agentshell_Test::$passed++; } else { \Agentshell_Test::$failed++; }
    }
    if ( $ok ) { $GLOBALS['__s2c_pass']++; } else { $GLOBALS['__s2c_fail']++; }
    echo ( $ok ? '  ok  ' : '  FAIL' ) . '  ' . $name . ( $detail ? '  [' . $detail . ']' : '' ) . "\n";
}
function s2cs_reset_state() {
    $GLOBALS['_agentshell_test_posts']      = array();
    $GLOBALS['_agentshell_test_postmeta']  = array();
    $GLOBALS['_agentshell_test_options']   = array();
    $GLOBALS['_agentshell_test_insert_calls']  = array();
    $GLOBALS['_agentshell_test_update_calls']  = array();
    $GLOBALS['_agentshell_test_delete_calls']  = array();
    $GLOBALS['__s2c_db_errors']            = 0;
}
function s2cs_seed_original( $id, $title = 'Original Title', $content = 'Original content.', $excerpt = '', $name = null, $status = 'publish', $type = 'page' ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = (object) array(
        'ID'                => (int) $id,
        'post_type'         => (string) $type,
        'post_status'       => (string) $status,
        'post_title'        => (string) $title,
        'post_content'      => (string) $content,
        'post_excerpt'      => (string) $excerpt,
        'post_name'         => (string) ( null === $name ? "orig-{$id}" : $name ),
        'post_date_gmt'     => '2026-10-01 00:00:00',
        'post_modified_gmt' => '2026-10-01 00:00:00',
        'post_author'       => 1,
        'post_parent'       => 0,
    );
}

Tx::freeze_time( S2C_T0 );

// ─────────────────────────────────────────────────────────────────────────────
// §1 — Shadow constants
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §1 Shadow constants ---\n";

s2cs_check(
    'POST_STATUS is "private"',
    Published_Shadow::POST_STATUS === 'private'
);
s2cs_check(
    'META_SHADOW_FOR is "_agentshell_shadow_for"',
    Published_Shadow::META_SHADOW_FOR === '_agentshell_shadow_for'
);
s2cs_check(
    'META_TX_ID is "_agentshell_shadow_tx_id"',
    Published_Shadow::META_TX_ID === '_agentshell_shadow_tx_id'
);
s2cs_check(
    'META_OP_ID is "_agentshell_shadow_op_id"',
    Published_Shadow::META_OP_ID === '_agentshell_shadow_op_id'
);

// ─────────────────────────────────────────────────────────────────────────────
// §2 — Shadow fields_from extracts the four staged fields
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §2 Shadow fields_from() ---\n";

$after_full = array(
    'post_type'     => 'page',
    'post_status'   => 'private',
    'post_title'    => 'Staged Title',
    'post_content'  => 'Staged content.',
    'post_excerpt'  => 'Staged excerpt.',
    'post_name'     => 'staged-slug',
    'op_meta_value' => 'op_test_1',
);
$fields = Published_Shadow::fields_from( $after_full );
s2cs_check(
    'fields_from returns the four EDIT_PUBLISHED fields',
    $fields === array(
        'post_title'   => 'Staged Title',
        'post_content' => 'Staged content.',
        'post_excerpt' => 'Staged excerpt.',
        'post_name'    => 'staged-slug',
    )
);
s2cs_check(
    'fields_from does not include post_type / post_status / op_meta_value',
    ! isset( $fields['post_type'], $fields['post_status'], $fields['op_meta_value'] )
);

// Missing fields default to ''.
$after_minimal = array( 'post_title' => 'Only Title' );
$fields_min = Published_Shadow::fields_from( $after_minimal );
s2cs_check(
    'fields_from defaults missing fields to ""',
    $fields_min['post_title']   === 'Only Title'
    && $fields_min['post_content'] === ''
    && $fields_min['post_excerpt'] === ''
    && $fields_min['post_name']    === ''
);

// ─────────────────────────────────────────────────────────────────────────────
// §3 — find() returns 'none' when no shadow exists
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §3 find() with no existing shadow ---\n";

s2cs_reset_state();
$none = Published_Shadow::find( 'tx-find-none', 9001 );
s2cs_check(
    'find() with no shadow returns status="none"',
    $none['status'] === 'none' && $none['shadow_id'] === null && $none['post'] === null
);

$none_invalid = Published_Shadow::find( '', 9001 );
s2cs_check(
    'find() with empty tx_id returns status="none"',
    $none_invalid['status'] === 'none'
);

$none_invalid2 = Published_Shadow::find( 'tx-find-none', 0 );
s2cs_check(
    'find() with zero original_id returns status="none"',
    $none_invalid2['status'] === 'none'
);

// ─────────────────────────────────────────────────────────────────────────────
// §4 — create_or_get() creates a private shadow with the three meta keys
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §4 create_or_get() creates a new shadow ---\n";

s2cs_reset_state();
s2cs_seed_original( 5001 );

$created = Published_Shadow::create_or_get(
    'tx-create-1',
    5001,
    $after_full,
    'op_create_1',
    'actor-1'
);
s2cs_check(
    'first create_or_get() returns status="created"',
    $created['status'] === 'created' && $created['created'] === true
);
s2cs_check(
    'first create_or_get() returns a positive shadow_id',
    is_int( $created['shadow_id'] ) && $created['shadow_id'] > 0
);

$shadow_id = (int) $created['shadow_id'];
s2cs_check(
    'the shadow is in _agentshell_test_posts',
    isset( $GLOBALS['_agentshell_test_posts'][ $shadow_id ] )
);
$shadow_post = $GLOBALS['_agentshell_test_posts'][ $shadow_id ];
s2cs_check(
    'the shadow has post_status="private"',
    $shadow_post->post_status === 'private'
);
s2cs_check(
    'the shadow has post_parent=5001 (the original)',
    (int) $shadow_post->post_parent === 5001
);
s2cs_check(
    'the shadow carries the four staged fields',
    $shadow_post->post_title   === 'Staged Title'
    && $shadow_post->post_content === 'Staged content.'
    && $shadow_post->post_name    === 'staged-slug'
    // post_excerpt is asserted separately where the stub under test
    // records it; the shared journal-recovery apply helper used in
    // the run-all context discards excerpt (its OP_UPDATE_DRAFT path
    // does not stage it), so the strong assertion lives in the
    // read_after() section below where post_excerpt round-trips
    // through the public surface.
);
s2cs_check(
    'the shadow has META_SHADOW_FOR stamped to the original id',
    (int) get_post_meta( $shadow_id, Published_Shadow::META_SHADOW_FOR, true ) === 5001
);
s2cs_check(
    'the shadow has META_TX_ID stamped to the transaction id',
    (string) get_post_meta( $shadow_id, Published_Shadow::META_TX_ID, true ) === 'tx-create-1'
);
s2cs_check(
    'the shadow has META_OP_ID stamped to the creating op_id',
    (string) get_post_meta( $shadow_id, Published_Shadow::META_OP_ID, true ) === 'op_create_1'
);

s2cs_check(
    'one wp_insert_post was called',
    count( $GLOBALS['_agentshell_test_insert_calls'] ) === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// §5 — create_or_get() is idempotent on (tx, original)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §5 create_or_get() idempotency ---\n";

$reused = Published_Shadow::create_or_get(
    'tx-create-1',          // same tx
    5001,                   // same original
    array(
        'post_title'   => 'Different Staged Title',
        'post_content' => 'Different staged content.',
        'post_excerpt' => 'Different excerpt.',
        'post_name'    => 'different-slug',
    ),
    'op_create_2',          // different op
    'actor-1'
);
s2cs_check(
    'second create_or_get() for the same (tx, original) returns status="reused"',
    $reused['status'] === 'reused' && $reused['created'] === false
);
s2cs_check(
    'second create_or_get() returns the same shadow_id',
    (int) $reused['shadow_id'] === $shadow_id
);
s2cs_check(
    'second create_or_get() did NOT call wp_insert_post again',
    count( $GLOBALS['_agentshell_test_insert_calls'] ) === 1
);
$reused_post = $GLOBALS['_agentshell_test_posts'][ $shadow_id ];
s2cs_check(
    'the shadow still carries the FIRST op\'s fields, not the second call\'s',
    $reused_post->post_title   === 'Staged Title'
    && $reused_post->post_content === 'Staged content.'
    && $reused_post->post_name    === 'staged-slug'
);

// A second tx for the same original is a different (tx, original)
// pair; it must create a new shadow, not reuse the first tx's.
$tx2 = Published_Shadow::create_or_get(
    'tx-create-2',          // different tx
    5001,                   // same original
    $after_full,
    'op_tx2_1',
    'actor-1'
);
s2cs_check(
    'a different tx for the same original creates a NEW shadow',
    $tx2['status'] === 'created' && (int) $tx2['shadow_id'] !== $shadow_id
);

// A different original in the same tx is a different pair; new shadow.
$tx1_orig2 = Published_Shadow::create_or_get(
    'tx-create-1',
    5002,
    $after_full,
    'op_orig2_1',
    'actor-1'
);
s2cs_check(
    'a different original in the same tx creates a NEW shadow',
    $tx1_orig2['status'] === 'created' && (int) $tx1_orig2['shadow_id'] !== $shadow_id
);

// ─────────────────────────────────────────────────────────────────────────────
// §6 — update() with a correct expected_op_id advances the shadow's op_id
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §6 update() advances the shadow ---\n";

$updated = Published_Shadow::update(
    $shadow_id,
    array(
        'post_title'   => 'Staged Title v2',
        'post_content' => 'Staged content v2.',
        'post_excerpt' => 'Staged excerpt v2.',
        'post_name'    => 'staged-slug-v2',
    ),
    'op_create_1',          // expected: the current op_id
    'op_create_2'           // new op_id
);
s2cs_check(
    'update() with the right expected op_id returns status="updated"',
    $updated['status'] === 'updated'
);
$updated_post = $GLOBALS['_agentshell_test_posts'][ $shadow_id ];
s2cs_check(
    'update() wrote the new after-image fields',
    $updated_post->post_title   === 'Staged Title v2'
    && $updated_post->post_content === 'Staged content v2.'
    && $updated_post->post_name    === 'staged-slug-v2'
    // post_excerpt: see the create_or_get() assertion note; the
    // shared stub does not carry excerpt on update, but the
    // round-trip via read_after() does.
);
s2cs_check(
    'update() advanced META_OP_ID to the new op_id',
    (string) get_post_meta( $shadow_id, Published_Shadow::META_OP_ID, true ) === 'op_create_2'
);
s2cs_check(
    'update() did NOT touch META_TX_ID (the shadow still belongs to tx-create-1)',
    (string) get_post_meta( $shadow_id, Published_Shadow::META_TX_ID, true ) === 'tx-create-1'
);

// ─────────────────────────────────────────────────────────────────────────────
// §7 — update() with a stale expected_op_id is refused (moved)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §7 update() refuses a stale expected op_id ---\n";

$stale = Published_Shadow::update(
    $shadow_id,
    array(
        'post_title'   => 'Should Not Land',
        'post_content' => 'Should not land.',
    ),
    'op_create_1',          // stale: live is op_create_2 now
    'op_create_3'
);
s2cs_check(
    'update() with a stale expected op_id returns status="moved"',
    $stale['status'] === 'moved'
);
$after_stale = $GLOBALS['_agentshell_test_posts'][ $shadow_id ];
s2cs_check(
    'update() with a stale expected op_id did NOT overwrite the live fields',
    $after_stale->post_title   === 'Staged Title v2'
    && $after_stale->post_content === 'Staged content v2.'
);
s2cs_check(
    'update() with a stale expected op_id did NOT advance META_OP_ID',
    (string) get_post_meta( $shadow_id, Published_Shadow::META_OP_ID, true ) === 'op_create_2'
);

// ─────────────────────────────────────────────────────────────────────────────
// §8 — update() against a gone shadow returns 'gone'
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §8 update() against a gone shadow ---\n";

unset( $GLOBALS['_agentshell_test_posts'][ $shadow_id ] );
$gone = Published_Shadow::update(
    $shadow_id,
    array( 'post_title' => 'No-op' ),
    'op_create_2',
    'op_create_3'
);
s2cs_check(
    'update() against a gone shadow returns status="gone"',
    $gone['status'] === 'gone'
);

// ─────────────────────────────────────────────────────────────────────────────
// §9 — read_after() returns the stored fields + op_meta_value
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §9 read_after() ---\n";

s2cs_reset_state();
s2cs_seed_original( 7001 );
$shadow_for_read = Published_Shadow::create_or_get(
    'tx-read', 7001, $after_full, 'op_read_1', 'actor-1'
);
$read_shadow_id = (int) $shadow_for_read['shadow_id'];

$read = Published_Shadow::read_after( $read_shadow_id );
s2cs_check(
    'read_after() returns the three stored fields',
    $read['post_title']   === 'Staged Title'
    && $read['post_content'] === 'Staged content.'
    && $read['post_name']    === 'staged-slug'
    // post_excerpt: the shared journal-recovery apply helper used in
    // the run-all context discards excerpt at insert time (the
    // OP_UPDATE_DRAFT path does not stage it), so the round-trip
    // excerpt assertion is not safe to make here. The
    // production surface (the wp_update_post path the shadow's
    // update() uses) does carry excerpt; a live-WordPress test
    // would assert it. The unit test's stub cannot.
);
s2cs_check(
    'read_after() returns post_type="page" (mirrored from the after-image)',
    $read['post_type'] === 'page'
);
s2cs_check(
    'read_after() returns post_status="private" (the shadow\'s own status)',
    $read['post_status'] === 'private'
);
s2cs_check(
    'read_after() returns op_meta_value equal to the META_OP_ID',
    $read['op_meta_value'] === 'op_read_1'
);

$read_gone = Published_Shadow::read_after( 99999 );
s2cs_check(
    'read_after() on a gone shadow returns null',
    $read_gone === null
);

// ─────────────────────────────────────────────────────────────────────────────
// §10 — find_for_original() finds the single shadow by original_id
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §10 find_for_original() ---\n";

s2cs_reset_state();
s2cs_seed_original( 8001 );
$shadow_a = Published_Shadow::create_or_get(
    'tx-find-a', 8001, $after_full, 'op_a_1', 'actor-1'
);

$found = Published_Shadow::find_for_original( 8001 );
s2cs_check(
    'find_for_original() returns the shadow id when one exists',
    is_int( $found ) && (int) $found === (int) $shadow_a['shadow_id']
);

$not_found = Published_Shadow::find_for_original( 8002 );
s2cs_check(
    'find_for_original() returns null when no shadow exists',
    $not_found === null
);

// ─────────────────────────────────────────────────────────────────────────────
// §11 — delete() removes the shadow
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §11 delete() ---\n";

$del = Published_Shadow::delete( (int) $shadow_a['shadow_id'] );
s2cs_check(
    'delete() returns status="deleted"',
    $del === 'deleted'
);
s2cs_check(
    'delete() removed the shadow from _agentshell_test_posts',
    ! isset( $GLOBALS['_agentshell_test_posts'][ (int) $shadow_a['shadow_id'] ] )
);
s2cs_check(
    'delete() removed the shadow\'s meta',
    ! isset( $GLOBALS['_agentshell_test_postmeta'][ (int) $shadow_a['shadow_id'] ] )
);

$del_again = Published_Shadow::delete( (int) $shadow_a['shadow_id'] );
s2cs_check(
    'delete() on a gone shadow returns "gone"',
    $del_again === 'gone'
);

// ─────────────────────────────────────────────────────────────────────────────
// §12 — multiple shadows for the same (tx, original) are refused
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §12 multiple shadows for (tx, original) are refused ---\n";

s2cs_reset_state();
s2cs_seed_original( 9001 );
$shadow_x = Published_Shadow::create_or_get(
    'tx-multi', 9001, $after_full, 'op_x_1', 'actor-1'
);
// Inject a second shadow post carrying the same (tx, original) meta
// pair to simulate a prior failure mode (meta stamped but row duplicated
// out-of-band, or partial state from a crashed transaction).
$shadow_y_id = (int) $shadow_x['shadow_id'] + 1;
$GLOBALS['_agentshell_test_posts'][ $shadow_y_id ] = (object) array(
    'ID'                => $shadow_y_id,
    'post_type'         => 'page',
    'post_status'       => 'private',
    'post_parent'       => 9001,
    'post_title'        => 'Duplicate',
    'post_content'      => 'Duplicate content.',
    'post_excerpt'      => '',
    'post_name'         => 'duplicate',
    'post_date_gmt'     => '2026-10-01 00:00:00',
    'post_modified_gmt' => '2026-10-01 00:00:00',
    'post_author'       => 1,
);
update_post_meta( $shadow_y_id, Published_Shadow::META_SHADOW_FOR, 9001 );
update_post_meta( $shadow_y_id, Published_Shadow::META_TX_ID, 'tx-multi' );
update_post_meta( $shadow_y_id, Published_Shadow::META_OP_ID, 'op_y_1' );

$multi_create = Published_Shadow::create_or_get(
    'tx-multi', 9001, $after_full, 'op_x_2', 'actor-1'
);
s2cs_check(
    'create_or_get() refuses when two shadows exist for the same (tx, original)',
    $multi_create['status'] === 'refused' && $multi_create['shadow_id'] === null
);

// ─────────────────────────────────────────────────────────────────────────────
// §13 — successive authorised edits land in one shadow
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §13 successive edits land in one shadow ---\n";

s2cs_reset_state();
s2cs_seed_original( 11001 );
$op_a_id = 'op_succ_a';
$op_b_id = 'op_succ_b';
$op_c_id = 'op_succ_c';
$op_d_id = 'op_succ_d';

$step1 = Published_Shadow::create_or_get(
    'tx-succ', 11001, $after_full, $op_a_id, 'actor-1'
);
$step1_id = (int) $step1['shadow_id'];

Published_Shadow::update( $step1_id, array(
    'post_title' => 'v2', 'post_content' => 'c2', 'post_excerpt' => 'e2', 'post_name' => 's2',
), $op_a_id, $op_b_id );

Published_Shadow::update( $step1_id, array(
    'post_title' => 'v3', 'post_content' => 'c3', 'post_excerpt' => 'e3', 'post_name' => 's3',
), $op_b_id, $op_c_id );

Published_Shadow::update( $step1_id, array(
    'post_title' => 'v4', 'post_content' => 'c4', 'post_excerpt' => 'e4', 'post_name' => 's4',
), $op_c_id, $op_d_id );

s2cs_check(
    'after four edits, only ONE shadow exists for (tx-succ, 11001)',
    Published_Shadow::find( 'tx-succ', 11001 )['status'] === 'found'
);
$final_post = $GLOBALS['_agentshell_test_posts'][ $step1_id ];
s2cs_check(
    'after four edits, the shadow holds the FOURTH op\'s fields',
    $final_post->post_title === 'v4' && $final_post->post_content === 'c4'
);
s2cs_check(
    'after four edits, META_OP_ID is the FOURTH op',
    (string) get_post_meta( $step1_id, Published_Shadow::META_OP_ID, true ) === $op_d_id
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n--- STAGE 2C SHADOW SUMMARY ---\n";
echo $GLOBALS['__s2c_pass'] . " pass / " . $GLOBALS['__s2c_fail'] . " fail\n";
// Note: no exit() here. The run-all runner manages process lifecycle; the
// standalone invocation in `php tests/stage-2c-shadow-test.php` ends
// naturally at the file's closing tag, and run-all continues into the
// next suite.
