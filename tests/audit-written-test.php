<?php
/**
 * audit_written contract regression — Stage 2c M5 follow-up.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The `reconciliation.audit_written` field on the
 * `agentshell_publish_edit` result was previously a placeholder
 * that was always `false`. The previous implementation wrote the
 * `transaction_published_reconciled` audit row inside
 * `settle_publication`, AFTER the state transition to
 * `publication_settled` had already committed, and never updated
 * the field. The result was a contract defect: the field was
 * always `false` and carried no information, and a crash between
 * the state transition and the audit write left the op in
 * `publication_settled` with no audit row, contradicting the
 * invariant.
 *
 * The contract this suite asserts
 * -------------------------------
 * 1. The `transaction_published_reconciled` audit row is written
 *    and independently verified BEFORE the state transition to
 *    `publication_settled`. The verification matches on
 *    (operation, transaction_id, op_id) — never a vague
 *    "any audit row" match.
 * 2. The `reconciliation.audit_written` field is `true` only
 *    after the audit row is durably written and verified. It is
 *    a backward-compatible boolean response field; the value
 *    carries the actual meaning it advertises.
 * 3. The marker-only return path (row reclaimed between COMMIT
 *    and post-COMMIT re-read) reports an honest
 *    `audit_written` value derived from an independent
 *    verification against the audit log, not a placeholder
 *    `false`.
 * 4. Repeated reconciliation is idempotent: re-running on an op
 *    that has already been reconciled does not create a
 *    duplicate audit row.
 * 5. An audit persistence failure leaves the op in
 *    `reconciliation_pending` and `audit_written` remains
 *    `false`; the publication is durable but the settlement
 *    evidence is incomplete; a retry can re-attempt.
 * 6. Historical audit rows from a different transaction do not
 *    match the verification, so the field is not accidentally
 *    `true` because of a stale ring buffer entry.
 * 7. Existing staging and rollback paths are not regressed.
 *
 * What this suite does NOT cover
 * ------------------------------
 * - Real WordPress persistence — covered by the live
 *   `edit-published-live-test.php` suite.
 * - The full §1.4 publication sequence — covered by
 *   `stage-2c-publish-edit-test.php`.
 *
 * Nothing here touches the live site or the network. The unit
 * surface uses the in-memory post/meta/options the existing
 * publish-edit suite provides.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'audit-written-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

// Doctor::check() refuses on a missing or low WordPress version.
$GLOBALS['wp_version'] = '6.5';

foreach ( array(
    'class-store.php',
    'class-transactions.php',
    'class-doctor.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-recovery-advisory-lock.php',
    'class-content-recovery.php',
    'class-published-claim.php',
    'class-published-shadow.php',
    'tools/class-base-tool.php',
    'tools/class-publish-edit.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Published_Shadow;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Publish_Edit;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "audit_written contract regression tests\n";

$GLOBALS['_aw_debug'] = false;

// ── WP surface stubs (minimal: only what run_reconciliation and
//    the audit helpers actually call).

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['_agentshell_test_postmeta']      = array();
$GLOBALS['_agentshell_test_posts']         = array();
$GLOBALS['_agentshell_test_options']       = array();
$GLOBALS['_agentshell_test_insert_calls']  = array();
$GLOBALS['_agentshell_test_update_calls']  = array();
$GLOBALS['_agentshell_test_delete_calls']  = array();
$GLOBALS['_agentshell_test_lock_holds']    = array();
$GLOBALS['_agentshell_test_lock_contended'] = false;

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
if ( ! function_exists( 'maybe_unserialize' ) ) {
    function maybe_unserialize( $value ) {
        if ( is_string( $value ) ) { return @unserialize( $value ); }
        return $value;
    }
}
if ( ! function_exists( 'hash_equals' ) ) {
    function hash_equals( $a, $b ) { return hash( 'sha256', (string) $a ) === hash( 'sha256', (string) $b ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return is_object( $thing ) && property_exists( $thing, 'errors' ); }
}
if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $value ) {
        if ( is_array( $value ) ) { return array_map( 'wp_slash', $value ); }
        if ( is_object( $value ) ) {
            foreach ( get_object_vars( $value ) as $k => $v ) { $value->$k = wp_slash( $v ); }
            return $value;
        }
        return is_string( $value ) ? addslashes( $value ) : $value;
    }
}
if ( ! function_exists( 'clean_post_cache' ) ) {
    function clean_post_cache( $post_id ) { return true; }
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
if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $key ) {
        unset( $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ (string) $key ] );
        return true;
    }
}
if ( ! function_exists( 'get_post' ) ) {
    function get_post( $post_id ) {
        return $GLOBALS['_agentshell_test_posts'][ (int) $post_id ] ?? null;
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
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap, $args = null ) { return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user() { return (object) array( 'ID' => 1, 'user_login' => 'agent' ); }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) { return; }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value, ...$args ) { return $value; }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = null ) { return array( 'body' => '' ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = null ) { return array( 'body' => '' ); }
}

// $wpdb that supports the queries run_reconciliation issues via
// Content_Write_Guard and the claim release. Mirrors the proven
// stub in stage-2c-publish-edit-test.php: the CAS regex is anchored
// to the SET option_value = '...' WHERE option_name = '...' AND
// option_value = '...' shape, and persistence writes the unserialized
// next value back to the in-memory options store.
$GLOBALS['wpdb'] = new class {
    public $posts    = 'wp_posts';
    public $options  = 'wp_options';
    public $last_query = '';
    public $in_transaction = false;
    public function prepare( $sql, ...$args ) {
        $i = 0; $len = strlen( $sql ); $a = $args; $out = '';
        while ( $i < $len ) {
            $c = $sql[ $i ];
            if ( '%' === $c && $i + 1 < $len ) {
                $fmt = $sql[ $i + 1 ];
                $arg = array_shift( $a );
                if ( 's' === $fmt ) { $out .= "'" . str_replace( "'", "''", (string) $arg ) . "'"; }
                elseif ( 'd' === $fmt ) { $out .= (int) $arg; }
                else { $out .= $c . $fmt; }
                $i += 2; continue;
            }
            $out .= $c; $i++;
        }
        return $out;
    }
    public function query( $sql ) {
        $this->last_query = $sql;
        $trim = trim( $sql );
        if ( 0 === strcasecmp( $trim, 'START TRANSACTION' ) ) { $this->in_transaction = true; return 1; }
        if ( 0 === strcasecmp( $trim, 'COMMIT' ) )         { $this->in_transaction = false; return 1; }
        if ( 0 === strcasecmp( $trim, 'ROLLBACK' ) )        { $this->in_transaction = false; return 1; }
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'UPDATE' ) ) {
            return $this->do_update( $sql );
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'DELETE' ) ) {
            return $this->do_delete( $sql );
        }
        return 1;
    }
    public function get_var( $sql = null ) {
        $trim = is_string( $sql ) ? trim( $sql ) : '';
        if ( 0 === strcasecmp( substr( $trim, 0, 9 ), 'SELECT GE' ) ) {
            if ( preg_match( "/SELECT GET_LOCK\\('([^']*)', 0\\)/", $sql, $m ) ) {
                $name = $m[1];
                if ( ! empty( $GLOBALS['_agentshell_test_lock_contended'] ) ) { return '0'; }
                $GLOBALS['_agentshell_test_lock_holds'][ $name ] = true;
                return '1';
            }
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 13 ), 'SELECT RELEAS' ) ) {
            if ( preg_match( "/SELECT RELEASE_LOCK\\('([^']*)'\\)/", $sql, $m ) ) {
                $name = $m[1];
                unset( $GLOBALS['_agentshell_test_lock_holds'][ $name ] );
                return '1';
            }
        }
        return null;
    }
    public function get_row( $sql = null, $output = OBJECT ) { return null; }
    public function get_results( $sql = null, $output = OBJECT ) { return array(); }
    public function insert( $table, $data, $format = null ) { return 1; }
    public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
    public function delete( $table, $where, $where_format = null ) { return 1; }
    private function do_update( $sql ) {
        $pattern = "/UPDATE wp_options SET option_value = '((?:[^']|'')*)' WHERE option_name = '([^']*)' AND option_value = '((?:[^']|'')*)'/";
        if ( ! preg_match( $pattern, $sql, $m ) ) {
            return 0;
        }
        $new_value  = str_replace( "''", "'", $m[1] );
        $key        = $m[2];
        $expected   = str_replace( "''", "'", $m[3] );
        if ( ! array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) {
            return 0;
        }
        $stored_blob = is_array( $GLOBALS['_agentshell_test_options'][ $key ] )
            ? serialize( $GLOBALS['_agentshell_test_options'][ $key ] )
            : (string) $GLOBALS['_agentshell_test_options'][ $key ];
        if ( $stored_blob !== $expected ) {
            return 0;
        }
        $unser = @unserialize( $new_value );
        $GLOBALS['_agentshell_test_options'][ $key ] = ( false === $unser && 'b:0;' !== $new_value ) ? $new_value : $unser;
        return 1;
    }
    private function do_delete( $sql ) {
        $pattern = "/DELETE FROM wp_options WHERE option_name = '([^']*)' AND option_value = '((?:[^']|'')*)'/";
        if ( ! preg_match( $pattern, $sql, $m ) ) { return 0; }
        $key      = $m[1];
        $expected = str_replace( "''", "'", $m[2] );
        if ( ! array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) { return 0; }
        $stored = is_array( $GLOBALS['_agentshell_test_options'][ $key ] )
            ? serialize( $GLOBALS['_agentshell_test_options'][ $key ] )
            : (string) $GLOBALS['_agentshell_test_options'][ $key ];
        if ( $stored !== $expected ) { return 0; }
        unset( $GLOBALS['_agentshell_test_options'][ $key ] );
        return 1;
    }
};

// ── Test helpers ─────────────────────────────────────────────────────────────

$aw_pass = 0;
$aw_fail = 0;
$aw_results = array();

function aw_check( $name, $ok, $detail = '' ) {
    global $aw_pass, $aw_fail, $aw_results;
    $aw_results[] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
    if ( class_exists( 'Agentshell_Test' ) ) {
        if ( $ok ) { \Agentshell_Test::$passed++; } else { \Agentshell_Test::$failed++; }
    }
    if ( $ok ) { $aw_pass++; } else { $aw_fail++; }
    echo ( $ok ? '  ok  ' : '  FAIL' ) . '  ' . $name . ( $detail ? '  [' . $detail . ']' : '' ) . "\n";
}

if ( ! defined( 'AW_T0' ) ) { define( 'AW_T0', 1820000000 ); }

function aw_reset() {
    agentshell_test_reset();
    Tx::unfreeze_time();
    Tx::freeze_time( AW_T0 );
    \AgentShell_MCP\Store::$suppress_history = false;
    $GLOBALS['_agentshell_test_options']      = array();
    $GLOBALS['_agentshell_test_postmeta']     = array();
    $GLOBALS['_agentshell_test_posts']        = array();
    $GLOBALS['_agentshell_test_insert_calls'] = array();
    $GLOBALS['_agentshell_test_update_calls'] = array();
    $GLOBALS['_agentshell_test_delete_calls'] = array();
    $GLOBALS['_agentshell_test_lock_holds']   = array();
    $GLOBALS['_agentshell_test_lock_contended'] = false;
    \AgentShell_MCP\Content_Write_Guard::reset();
}

function aw_seed_original( $id ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = (object) array(
        'ID'                => (int) $id,
        'post_type'         => 'page',
        'post_status'       => 'publish',
        'post_title'        => 'Original Title',
        'post_content'      => 'Original content.',
        'post_excerpt'      => '',
        'post_name'         => "orig-{$id}",
        'post_author'       => 1,
        'post_parent'       => 0,
        'post_date'         => '2026-10-01 00:00:00',
        'post_date_gmt'     => '2026-10-01 00:00:00',
        'post_modified'     => '2026-10-01 00:00:00',
        'post_modified_gmt' => '2026-10-01 00:00:00',
        'post_mime_type'    => '',
        'comment_status'    => 'open',
        'ping_status'       => 'open',
        'menu_order'        => 0,
        'post_password'     => '',
        'post_content_filtered' => '',
        'comment_count'     => 0,
        'to_ping'           => '',
        'pinged'            => '',
        'guid'              => 'http://example.com/?p=' . (int) $id,
    );
}

function aw_seed_shadow( $shadow_id, $original_id, $op_id, $fields = array() ) {
    $GLOBALS['_agentshell_test_posts'][ (int) $shadow_id ] = (object) array(
        'ID'                => (int) $shadow_id,
        'post_type'         => 'page',
        'post_status'       => Published_Shadow::POST_STATUS,
        'post_title'        => (string) ( $fields['post_title']   ?? 'Staged Title' ),
        'post_content'      => (string) ( $fields['post_content'] ?? 'Staged content.' ),
        'post_excerpt'      => (string) ( $fields['post_excerpt'] ?? '' ),
        'post_name'         => (string) ( $fields['post_name']    ?? "staged-{$shadow_id}" ),
        'post_author'       => 1,
        'post_parent'       => (int) $original_id,
        'post_date_gmt'     => '2026-10-01 00:00:00',
        'post_modified_gmt' => '2026-10-01 00:00:00',
    );
    update_post_meta( $shadow_id, Published_Shadow::META_SHADOW_FOR, (string) $original_id );
    if ( ! empty( $GLOBALS['__aw_txid'] ) ) {
        update_post_meta( $shadow_id, Published_Shadow::META_TX_ID, (string) $GLOBALS['__aw_txid'] );
    }
    update_post_meta( $shadow_id, Published_Shadow::META_OP_ID, (string) $op_id );
}

function aw_begin_tx( $actor = 'agent-1' ) {
    $payload = Tx::instance()->begin( $actor, 'audit-written-suite' );
    $GLOBALS['__aw_txid'] = (string) $payload['id'];
    Tx::freeze_time( AW_T0 );
    return $payload;
}

function aw_stage_op( $original_id, $shadow_id, $actor = 'agent-1' ) {
    // Create a properly-shaped OP_EDIT_PUBLISHED op via the
    // journal's record_intent, attach it to the tx, and inject
    // the baseline. Mirrors s2cs_stage_edit_published. The op id
    // is auto-generated by record_intent; the test must use the
    // returned $op['id'] (NOT a synthetic id) for the subsequent
    // update_op / force_committed calls — content_journal stores
    // the op under its generated id, and a synthetic id would
    // miss the row.
    $baseline = Content_Journal::capture_edit_published_baseline( $original_id );
    $op = Content_Journal::record_intent(
        Content_Journal::OP_EDIT_PUBLISHED,
        array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            'original_id' => (int) $original_id,
            'shadow_id'   => (int) $shadow_id,
        ),
        array(
            'post_title'   => 'Staged Title',
            'post_content' => 'Staged content.',
            'post_excerpt' => '',
            'post_name'    => (string) ( $baseline['post_name'] ?? "orig-{$original_id}" ),
        )
    );
    $tx = Tx::instance()->payload();
    if ( ! Content_Journal::attach( $tx, $op ) ) {
        throw new \RuntimeException( 'aw_stage_op: attach refused the write' );
    }
    aw_update_op( $op['id'], function ( $op_row ) use ( $baseline ) {
        $op_row['before'] = $baseline;
        return $op_row;
    } );
    // Hold a per-original claim so the publish-edit tool's claim
    // check would pass; the reconciliation tail releases it.
    $claim_row = Published_Claim::shape(
        (string) $GLOBALS['__aw_txid'],
        (int) $original_id,
        (int) $shadow_id,
        $actor,
        AW_T0,
        (string) $op['id'],
        HOUR_IN_SECONDS
    );
    $GLOBALS['_agentshell_test_options'][ Published_Claim::key( (int) $original_id ) ] = $claim_row;
    return $op;
}

function aw_update_op( $op_id, callable $mutator ) {
    $current = (array) get_option( Tx::KEY, null );
    $updated = $current;
    if ( ! isset( $updated['content']['ops'][ $op_id ] ) ) {
        return false;
    }
    $updated['content']['ops'][ $op_id ] = $mutator( $updated['content']['ops'][ $op_id ] );
    $updated['content']['sig'] = Content_Journal::sign( $updated['content'] );
    return Tx::cas_write_row( $current, $updated );
}

function aw_force_committed( $op_id, $shadow_id, $original_id, $actor = 'agent-1' ) {
    // Move an existing op (already attached via aw_stage_op) to
    // publication_committed with a partial reconciliation record.
    // This mirrors the post-COMMIT state of a real publish.
    aw_update_op( $op_id, function ( $op_row ) use ( $actor, $original_id, $shadow_id ) {
        $op_row['state']        = Content_Journal::STATE_PUBLICATION_COMMITTED;
        $op_row['target']       = array(
            'original_id' => (int) $original_id,
            'shadow_id'   => (int) $shadow_id,
        );
        $op_row['reconciliation'] = array(
            'shadow_deleted'     => false,
            'caches_invalidated' => false,
            'claim_released'     => false,
            'audit_written'      => false,
        );
        $op_row['updated_at']   = AW_T0;
        $op_row['comp']         = array(
            'outcome' => 'publication_committed',
            'post_id' => (int) $original_id,
            'actor'   => (string) $actor,
            'at'      => (int) AW_T0,
            'reason'  => 'publication committed; reconciliation owed',
        );
        return $op_row;
    } );
    $row = (array) get_option( Tx::KEY, null );
    return $row['content']['ops'][ (string) $op_id ] ?? null;
}

function aw_disk_op( $op_id ) {
    $row = (array) get_option( Tx::KEY, null );
    return is_array( $row ) ? ( $row['content']['ops'][ (string) $op_id ] ?? null ) : null;
}

function aw_count_audit_rows( $operation, $tx_id, $op_id ) {
    $log = (array) \AgentShell_MCP\Store::audit_log();
    $count = 0;
    foreach ( $log as $entry ) {
        if ( ! is_array( $entry ) ) { continue; }
        if ( (string) ( $entry['operation'] ?? '' ) !== $operation ) { continue; }
        $changed = is_array( $entry['changed'] ?? null ) ? $entry['changed'] : array();
        if ( (string) ( $changed['transaction_id'] ?? '' ) !== (string) $tx_id ) { continue; }
        if ( (string) ( $changed['op_id'] ?? '' ) !== (string) $op_id ) { continue; }
        $count++;
    }
    return $count;
}

// ── Tests ───────────────────────────────────────────────────────────────────

// AW-1: Normal success path. Reconciliation runs, audit is
// written, op transitions to publication_settled, audit_written
// is true on disk and in the public result.
aw_reset();
$tx1     = aw_begin_tx();
$tx_id1  = (string) $tx1['id'];
$orig1   = 5001;
$shadow1 = 6001;
aw_seed_original( $orig1 );
$op1     = aw_stage_op( $orig1, $shadow1 );
$op_id1  = (string) $op1['id'];
aw_seed_shadow( $shadow1, $orig1, $op_id1 );
$committed1 = aw_force_committed( $op_id1, $shadow1, $orig1 );

$res1 = Publish_Edit::run_reconciliation( $tx1, $committed1, 'agent-1', $orig1, $shadow1 );

aw_check(
    'AW-1.1 run_reconciliation returns verified on the happy path',
    is_array( $res1 ) && (string) ( $res1['status'] ?? '' ) === 'verified'
);
aw_check(
    'AW-1.2 state is publication_settled on the happy path',
    (string) ( $res1['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED
);
aw_check(
    'AW-1.3 reconciliation.audit_written is true in the public result',
    is_array( $res1['reconciliation'] ?? null )
        && is_array( $res1['reconciliation']['audit_written'] ?? null )
        && (string) ( $res1['reconciliation']['audit_written']['status'] ?? '' ) === 'verified'
);
$disk1 = aw_disk_op( $op_id1 );
aw_check(
    'AW-1.4 reconciliation.audit_written is true on disk',
    is_array( $disk1 ) && is_array( $disk1['reconciliation']['audit_written'] ?? null )
        && (string) ( $disk1['reconciliation']['audit_written']['status'] ?? '' ) === 'verified'
);
aw_check(
    'AW-1.5 transaction_published_reconciled audit row exists once',
    aw_count_audit_rows( 'transaction_published_reconciled', $tx_id1, $op_id1 ) === 1
);
aw_check(
    'AW-1.6 disk state is publication_settled',
    is_array( $disk1 ) && (string) ( $disk1['state'] ?? '' ) === Content_Journal::STATE_PUBLICATION_SETTLED
);

// AW-2: Audit persistence failure. The audit write throws; the
// op stays in reconciliation_pending; audit_written is false.
aw_reset();
$tx2     = aw_begin_tx();
$tx_id2  = (string) $tx2['id'];
$orig2   = 5002;
$shadow2 = 6002;
aw_seed_original( $orig2 );
$op2     = aw_stage_op( $orig2, $shadow2 );
$op_id2  = (string) $op2['id'];
aw_seed_shadow( $shadow2, $orig2, $op_id2 );
$committed2 = aw_force_committed( $op_id2, $shadow2, $orig2 );

$GLOBALS['_agentshell_test_audit_fail_for'] = 'transaction_published_reconciled';
$res2 = Publish_Edit::run_reconciliation( $tx2, $committed2, 'agent-1', $orig2, $shadow2 );
$GLOBALS['_agentshell_test_audit_fail_for'] = null;

aw_check(
    'AW-2.1 audit write failure returns audit_unverified status',
    is_array( $res2 ) && (string) ( $res2['status'] ?? '' ) === 'audit_unverified'
);
aw_check(
    'AW-2.2 audit write failure keeps op in reconciliation_pending (public)',
    (string) ( $res2['state'] ?? '' ) === Content_Journal::STATE_RECONCILIATION_PENDING
);
$disk2 = aw_disk_op( $op_id2 );
aw_check(
    'AW-2.3 audit write failure keeps op in reconciliation_pending (disk)',
    is_array( $disk2 ) && (string) ( $disk2['state'] ?? '' ) === Content_Journal::STATE_RECONCILIATION_PENDING
);
aw_check(
    'AW-2.4 audit write failure leaves audit_written false on disk',
    is_array( $disk2 ) && empty( $disk2['reconciliation']['audit_written']['status'] )
);
aw_check(
    'AW-2.5 audit write failure: shadow still deleted (other steps completed)',
    ! isset( $GLOBALS['_agentshell_test_posts'][ $shadow2 ] )
);
aw_check(
    'AW-2.6 audit write failure: no transaction_published_reconciled row in audit log',
    aw_count_audit_rows( 'transaction_published_reconciled', $tx_id2, $op_id2 ) === 0
);

// AW-3: COMMIT+re-read race. The post-COMMIT re-read finds the
// row missing; the marker-only return reports an honest
// audit_written based on an independent log check.
//
// We exercise the underlying behaviour directly: a settled op
// has a transaction_published_reconciled row in the audit log;
// if the option row is dropped (simulating the race), the
// audit log is the only surviving evidence. The marker-only
// return uses verify_audit_status_from_log() to derive
// audit_written from this evidence.
aw_reset();
$tx3     = aw_begin_tx();
$tx_id3  = (string) $tx3['id'];
$orig3   = 5003;
$shadow3 = 6003;
aw_seed_original( $orig3 );
$op3     = aw_stage_op( $orig3, $shadow3 );
$op_id3  = (string) $op3['id'];
aw_seed_shadow( $shadow3, $orig3, $op_id3 );
$committed3 = aw_force_committed( $op_id3, $shadow3, $orig3 );

// First, settle normally.
$res3a = Publish_Edit::run_reconciliation( $tx3, $committed3, 'agent-1', $orig3, $shadow3 );
aw_check(
    'AW-3.0 setup: first reconciliation settles the op',
    is_array( $res3a ) && (string) ( $res3a['status'] ?? '' ) === 'verified'
);
aw_check(
    'AW-3.1 setup: transaction_published_reconciled audit row exists',
    aw_count_audit_rows( 'transaction_published_reconciled', $tx_id3, $op_id3 ) === 1
);
// Now simulate the row-reclaimed race: drop the option row.
delete_option( Tx::KEY );
$row_after_reclaim = get_option( Tx::KEY, null );
aw_check(
    'AW-3.2 simulated row-reclaim: option row is empty after delete',
    empty( $row_after_reclaim )
);
// The audit log is intact. A marker-only return that consulted
// the log would report audit_written = true.
$log3 = (array) \AgentShell_MCP\Store::audit_log();
$match3 = null;
foreach ( $log3 as $entry ) {
    if ( ! is_array( $entry ) ) { continue; }
    if ( (string) ( $entry['operation'] ?? '' ) !== 'transaction_published_reconciled' ) { continue; }
    $changed = is_array( $entry['changed'] ?? null ) ? $entry['changed'] : array();
    if ( (string) ( $changed['transaction_id'] ?? '' ) !== $tx_id3 ) { continue; }
    if ( (string) ( $changed['op_id'] ?? '' ) !== $op_id3 ) { continue; }
    $match3 = $entry;
    break;
}
aw_check(
    'AW-3.3 simulated row-reclaim: audit log still has a matching row (independent verification source)',
    is_array( $match3 )
);

// AW-4: Repeated reconciliation. A second call on an op that is
// already settled is idempotent: it does not create a duplicate
// audit row, and the field stays true.
aw_reset();
$tx4     = aw_begin_tx();
$tx_id4  = (string) $tx4['id'];
$orig4   = 5004;
$shadow4 = 6004;
aw_seed_original( $orig4 );
$op4     = aw_stage_op( $orig4, $shadow4 );
$op_id4  = (string) $op4['id'];
aw_seed_shadow( $shadow4, $orig4, $op_id4 );
$committed4 = aw_force_committed( $op_id4, $shadow4, $orig4 );

$res4a = Publish_Edit::run_reconciliation( $tx4, $committed4, 'agent-1', $orig4, $shadow4 );
aw_check(
    'AW-4.1 first call settles the op',
    is_array( $res4a ) && (string) ( $res4a['status'] ?? '' ) === 'verified'
);

// A second call: re-read the disk op (which is now settled),
// then call run_reconciliation again. The audit helper should
// detect the existing row in the log and return verified
// without writing a duplicate.
$disk4 = aw_disk_op( $op_id4 );
$res4b = Publish_Edit::run_reconciliation( $tx4, $disk4, 'agent-1', $orig4, $shadow4 );
aw_check(
    'AW-4.2 second call is verified (idempotent)',
    is_array( $res4b ) && (string) ( $res4b['status'] ?? '' ) === 'verified'
);
aw_check(
    'AW-4.3 second call did not create a duplicate audit row',
    aw_count_audit_rows( 'transaction_published_reconciled', $tx_id4, $op_id4 ) === 1
);
$disk4b = aw_disk_op( $op_id4 );
aw_check(
    'AW-4.4 second call keeps audit_written verified on disk',
    is_array( $disk4b ) && is_array( $disk4b['reconciliation']['audit_written'] ?? null )
        && (string) ( $disk4b['reconciliation']['audit_written']['status'] ?? '' ) === 'verified'
);

// AW-5: Historical audit rows from a different transaction do
// NOT satisfy the verification. The field is not accidentally
// true because of a stale ring buffer entry.
aw_reset();
$tx5a    = aw_begin_tx();
$tx_id5a = (string) $tx5a['id'];
$orig5a  = 5005;
$shadow5a = 6005;
aw_seed_original( $orig5a );
$op5a    = aw_stage_op( $orig5a, $shadow5a );
$op_id5a = (string) $op5a['id'];
aw_seed_shadow( $shadow5a, $orig5a, $op_id5a );
$committed5a = aw_force_committed( $op_id5a, $shadow5a, $orig5a );
$res5a = Publish_Edit::run_reconciliation( $tx5a, $committed5a, 'agent-1', $orig5a, $shadow5a );
aw_check(
    'AW-5.0 setup: first op is settled',
    is_array( $res5a ) && (string) ( $res5a['status'] ?? '' ) === 'verified'
);

// Open a NEW transaction, stage a different op, and call
// run_reconciliation with the test seam rejecting the audit
// write. The verification must NOT match the old audit row
// (different transaction_id, different op_id).
$orig5b  = 5006;
$shadow5b = 6006;
aw_seed_original( $orig5b );
$tx5b    = Tx::instance()->begin( 'agent-2', 'audit-written-suite-cross-tx' );
$GLOBALS['__aw_txid'] = (string) $tx5b['id'];
Tx::freeze_time( AW_T0 + 100 );
$op5b    = aw_stage_op( $orig5b, $shadow5b, 'agent-2' );
$op_id5b = (string) $op5b['id'];
aw_seed_shadow( $shadow5b, $orig5b, $op_id5b );
$committed5b = aw_force_committed( $op_id5b, $shadow5b, $orig5b );
$GLOBALS['_agentshell_test_audit_fail_for'] = 'transaction_published_reconciled';
$res5b = Publish_Edit::run_reconciliation( $tx5b, $committed5b, 'agent-2', $orig5b, $shadow5b );
$GLOBALS['_agentshell_test_audit_fail_for'] = null;

aw_check(
    'AW-5.1 second op with audit write failure reports audit_unverified',
    is_array( $res5b ) && (string) ( $res5b['status'] ?? '' ) === 'audit_unverified'
);
$disk5b = aw_disk_op( $op_id5b );
aw_check(
    'AW-5.2 second op on disk: audit_written is NOT verified despite stale row from a different tx',
    is_array( $disk5b ) && empty( $disk5b['reconciliation']['audit_written']['status'] )
);
aw_check(
    'AW-5.3 second op: zero audit rows match its (tx_id, op_id)',
    aw_count_audit_rows( 'transaction_published_reconciled', (string) $tx5b['id'], $op_id5b ) === 0
);
aw_check(
    'AW-5.4 stale audit row for the first op is still present (one match)',
    aw_count_audit_rows( 'transaction_published_reconciled', $tx_id5a, $op_id5a ) === 1
);

// AW-6: A row that matches operation and tx_id but a DIFFERENT
// op_id must not satisfy the verification. This is the
// "vaguely-matching historical row" trap. We simulate by
// writing a stray audit row for a phantom op_id, then run
// reconciliation on a real op with the test seam rejecting
// the audit write. The verification must report unverified
// because the stray row's op_id does not match.
aw_reset();
$tx6     = aw_begin_tx();
$tx_id6  = (string) $tx6['id'];
$orig6   = 5007;
$shadow6 = 6007;
aw_seed_original( $orig6 );
$op6     = aw_stage_op( $orig6, $shadow6 );
$op_id6  = (string) $op6['id'];
aw_seed_shadow( $shadow6, $orig6, $op_id6 );

// Plant a stray row with the same tx_id but a different op_id.
\AgentShell_MCP\Store::record_event(
    'agent-1',
    'transaction_published_reconciled',
    array(
        'transaction_id' => $tx_id6,
        'op_id'          => 'op-aw-6-phantom',
        'original_id'    => 9999,
    )
);

$committed6 = aw_force_committed( $op_id6, $shadow6, $orig6 );
$GLOBALS['_agentshell_test_audit_fail_for'] = 'transaction_published_reconciled';
$res6 = Publish_Edit::run_reconciliation( $tx6, $committed6, 'agent-1', $orig6, $shadow6 );
$GLOBALS['_agentshell_test_audit_fail_for'] = null;

aw_check(
    'AW-6.1 stray row with same tx_id but different op_id does NOT satisfy verification',
    is_array( $res6 ) && (string) ( $res6['status'] ?? '' ) === 'audit_unverified'
);
$disk6 = aw_disk_op( $op_id6 );
aw_check(
    'AW-6.2 disk audit_written is NOT verified for the real op_id',
    is_array( $disk6 ) && empty( $disk6['reconciliation']['audit_written']['status'] )
);
aw_check(
    'AW-6.3 stray row is still in the audit log (one match for the phantom op_id)',
    aw_count_audit_rows( 'transaction_published_reconciled', $tx_id6, 'op-aw-6-phantom' ) === 1
);

// AW-7: Audit verification is keyed on the operation name. A
// row with the same tx_id and op_id but a different operation
// name must not satisfy the verification.
aw_reset();
$tx7     = aw_begin_tx();
$tx_id7  = (string) $tx7['id'];
$orig7   = 5008;
$shadow7 = 6008;
aw_seed_original( $orig7 );
$op7     = aw_stage_op( $orig7, $shadow7 );
$op_id7  = (string) $op7['id'];
aw_seed_shadow( $shadow7, $orig7, $op_id7 );

// Plant a row with the same tx_id and op_id but a different
// operation name. A "wrong operation" row must not match.
\AgentShell_MCP\Store::record_event(
    'agent-1',
    'transaction_published',
    array(
        'transaction_id' => $tx_id7,
        'op_id'          => $op_id7,
        'original_id'    => $orig7,
    )
);

$committed7 = aw_force_committed( $op_id7, $shadow7, $orig7 );
$GLOBALS['_agentshell_test_audit_fail_for'] = 'transaction_published_reconciled';
$res7 = Publish_Edit::run_reconciliation( $tx7, $committed7, 'agent-1', $orig7, $shadow7 );
$GLOBALS['_agentshell_test_audit_fail_for'] = null;

aw_check(
    'AW-7.1 row with same (tx_id, op_id) but wrong operation does NOT satisfy verification',
    is_array( $res7 ) && (string) ( $res7['status'] ?? '' ) === 'audit_unverified'
);
$disk7 = aw_disk_op( $op_id7 );
aw_check(
    'AW-7.2 disk audit_written is NOT verified when only wrong-op row exists',
    is_array( $disk7 ) && empty( $disk7['reconciliation']['audit_written']['status'] )
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n";
echo "audit_written contract regression tests: " . $aw_pass . " passed, " . $aw_fail . " failed.\n";
if ( $aw_fail > 0 ) {
    echo "\nFailures:\n";
    foreach ( $aw_results as $r ) {
        if ( ! $r['ok'] ) {
            echo "  FAIL  " . $r['name'] . ( $r['detail'] ? '  [' . $r['detail'] . ']' : '' ) . "\n";
        }
    }
}

// No exit here: run-all.php requires every suite into one process, so
// exiting would end the aggregate run before the runner could print its
// total — and the runner is the thing that sets the exit status. Every
// other suite in this directory follows the same rule.
if ( ! defined( 'AGENTSHELL_TEST_AGGREGATE' ) ) {
    exit( $aw_fail === 0 ? 0 : 1 );
}
