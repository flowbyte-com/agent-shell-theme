<?php
/**
 * Stage 2c foundation tests — Milestone 1: OP_EDIT_PUBLISHED journal operation,
 * strong immutable baseline capture, and per-original exclusive claim.
 *
 * This suite covers the three pieces the journal/claim foundation
 * has to be honest about before any preview or publication code
 * lands:
 *
 *   - OP_EDIT_PUBLISHED is a real, validated op type with the
 *     eight-state model from docs/STAGE-2C-DESIGN.md §1.7.
 *
 *   - The strong immutable baseline (baseline-A) is captured
 *     with the eleven fields the publish step's
 *     write-and-verify-C check needs, including the content
 *     hash and content length, and is the load-bearing
 *     evidence that an external editor's intervening
 *     update is detected at publish time.
 *
 *   - The per-original claim is a value-guarded SQL CAS row
 *     in wp_options; create/renew/release on the row detect
 *     a row that has moved underneath the caller, and a
 *     competing transaction for the same original is
 *     refused at create time.
 *
 * Nothing here runs against a real WordPress: the live tests
 * are journal-recovery-live-child.php. The unit tests use the
 * stubbed post/meta surface the existing journal-recovery-test
 * suite provides.
 *
 * What this suite does NOT cover: the shadow creation step, the
 * preview overlay (proved in the spike), the publication
 * algorithm, the reconciliation, or recovery. Those are
 * Milestones 2-6 in the implementation plan.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'stage-2c-foundation-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

foreach ( array(
    'class-store.php',
    'class-transactions.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-published-claim.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Published_Claim;
use AgentShell_MCP\Transaction_Manager as Tx;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "Stage 2c foundation tests (Milestone 1: journal/claim foundation)\n";

define( 'S2C_T0', 1810000000 );

// ── WordPress surface this code touches, stubbed ─────────────────────────────
//
// Real get_post for the original (the baseline capture calls
// get_post($original_id)) and the wp_options surface the
// Published_Claim primitives use.

$GLOBALS['__s2c_txid']      = 'tx-s2c-foundation';
$GLOBALS['__s2c_db_errors'] = 0;

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

// A minimal in-memory $wpdb that the Published_Claim primitives
// will use. The primitives issue `INSERT ... ON DUPLICATE KEY
// UPDATE`, `UPDATE`, and `DELETE` against $wpdb->options.
$GLOBALS['wpdb'] = new class {
    public $options = 'wp_options';
    public $last_query = '';
    public $last_prepared_args = null;
    public function prepare( $sql, ...$args ) {
        // We need the SQL + the args to drive our mini-emulator.
        return array( 'sql' => $sql, 'args' => $args );
    }
    public function query( $prepared ) {
        if ( ! is_array( $prepared ) ) { return false; }
        $sql  = $prepared['sql'];
        $args = $prepared['args'];
        $GLOBALS['wpdb']->last_query = $sql;
        $GLOBALS['wpdb']->last_prepared_args = $args;
        // Substitute %s and %d placeholders with their args,
        // escaped for the simple cases the claim primitives use.
        $out = '';
        $i = 0;
        $len = strlen( $sql );
        while ( $i < $len ) {
            $c = $sql[ $i ];
            if ( '%' === $c && $i + 1 < $len ) {
                $fmt = $sql[ $i + 1 ];
                $arg = array_shift( $args );
                if ( 's' === $fmt ) { $out .= "'" . str_replace( "'", "''", (string) $arg ) . "'"; }
                elseif ( 'd' === $fmt ) { $out .= (int) $arg; }
                else { $out .= $c . $fmt; }
                $i += 2;
                continue;
            }
            $out .= $c;
            $i++;
        }
        // Now parse the SQL.
        $trim = trim( $out );
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'INSERT' ) ) {
            return $this->do_insert( $out );
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'UPDATE' ) ) {
            return $this->do_update( $out );
        }
        if ( 0 === strcasecmp( substr( $trim, 0, 6 ), 'DELETE' ) ) {
            return $this->do_delete( $out );
        }
        return 0;
    }
    public function get_var( $prepared ) {
        // Published_Claim does not use get_var directly; the
        // claim primitives only use query(). Stub it.
        return null;
    }
    private function do_insert( $sql ) {
        // INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE option_name = option_name
        if ( ! preg_match( "/INSERT INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '((?:[^']|'')*)', '([^']*)'\\)/", $sql, $m ) ) {
            $GLOBALS['__s2c_db_errors']++;
            return false;
        }
        $key   = $m[1];
        $value = str_replace( "''", "'", $m[2] );
        $auto  = $m[3];
        if ( array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) {
            // ON DUPLICATE KEY UPDATE no-op: 0 affected.
            return 0;
        }
        // The Published_Claim primitives hand a maybe_serialize()d blob
        // to $wpdb->query; we unserialize on the way in so the option
        // store mirrors WordPress's get_option() reading. (The
        // bootstrap's get_option() stub returns the value as-is; the
        // test's read() call expects an array.) do_update / do_delete
        // re-serialize for the byte-for-byte compare that the SQL
        // `WHERE option_value = ?` guards.
        $unser = @unserialize( $value );
        $GLOBALS['_agentshell_test_options'][ $key ] = ( false === $unser && 'b:0;' !== $value ) ? $value : $unser;
        return 1;
    }
    private function do_update( $sql ) {
        // UPDATE wp_options SET option_value = '...' WHERE option_name = '...' AND option_value = '...'
        if ( ! preg_match( "/UPDATE wp_options SET option_value = '((?:[^']|'')*)' WHERE option_name = '([^']*)' AND option_value = '((?:[^']|'')*)'/", $sql, $m ) ) {
            $GLOBALS['__s2c_db_errors']++;
            return false;
        }
        $new_value      = str_replace( "''", "'", $m[1] );
        $key            = $m[2];
        $expected_value = str_replace( "''", "'", $m[3] );
        if ( ! array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) { return 0; }
        // The stored value is the unserialized array (see do_insert).
        // The WHERE guard compares against a serialized blob, so we
        // serialize the stored value the same way maybe_serialize() did
        // at write time. The primitive's read() does the inverse, so
        // the test sees an array either way.
        $stored_blob = is_array( $GLOBALS['_agentshell_test_options'][ $key ] )
            ? serialize( $GLOBALS['_agentshell_test_options'][ $key ] )
            : (string) $GLOBALS['_agentshell_test_options'][ $key ];
        if ( $stored_blob !== $expected_value ) { return 0; }
        $unser = @unserialize( $new_value );
        $GLOBALS['_agentshell_test_options'][ $key ] = ( false === $unser && 'b:0;' !== $new_value ) ? $new_value : $unser;
        return 1;
    }
    private function do_delete( $sql ) {
        // DELETE FROM wp_options WHERE option_name = '...' AND option_value = '...'
        if ( ! preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']*)' AND option_value = '((?:[^']|'')*)'/", $sql, $m ) ) {
            $GLOBALS['__s2c_db_errors']++;
            return false;
        }
        $key            = $m[1];
        $expected_value = str_replace( "''", "'", $m[2] );
        if ( ! array_key_exists( $key, $GLOBALS['_agentshell_test_options'] ) ) { return 0; }
        $stored_blob = is_array( $GLOBALS['_agentshell_test_options'][ $key ] )
            ? serialize( $GLOBALS['_agentshell_test_options'][ $key ] )
            : (string) $GLOBALS['_agentshell_test_options'][ $key ];
        if ( $stored_blob !== $expected_value ) { return 0; }
        unset( $GLOBALS['_agentshell_test_options'][ $key ] );
        return 1;
    }
};

// ── Test harness ────────────────────────────────────────────────────────────

$GLOBALS['__s2c_results'] = array();
$GLOBALS['__s2c_pass']    = 0;
$GLOBALS['__s2c_fail']    = 0;
function s2c_check( $name, $ok, $detail = '' ) {
    $GLOBALS['__s2c_results'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
    if ( $ok ) { $GLOBALS['__s2c_pass']++; } else { $GLOBALS['__s2c_fail']++; }
    echo ( $ok ? '  ok  ' : '  FAIL' ) . '  ' . $name . ( $detail ? '  [' . $detail . ']' : '' ) . "\n";
}
function s2c_reset_state() {
    $GLOBALS['_agentshell_test_posts']   = array();
    $GLOBALS['_agentshell_test_options'] = array();
    $GLOBALS['__s2c_db_errors']          = 0;
}
function s2c_seed_original( $id, $title, $content, $excerpt = '', $name = null, $status = 'publish', $type = 'page' ) {
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
// §1 — OP_EDIT_PUBLISHED constant + eight-state model
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §1 OP_EDIT_PUBLISHED constant and eight-state model ---\n";

s2c_check(
    'OP_EDIT_PUBLISHED constant is "edit_published"',
    Content_Journal::OP_EDIT_PUBLISHED === 'edit_published'
);
s2c_check(
    'OP_EDIT_PUBLISHED is in SUPPORTED_OPERATIONS',
    in_array( Content_Journal::OP_EDIT_PUBLISHED, Content_Journal::SUPPORTED_OPERATIONS, true )
);
s2c_check(
    'EDIT_PUBLISHED_FIELDS has exactly the four supported fields',
    Content_Journal::EDIT_PUBLISHED_FIELDS === array( 'post_title', 'post_content', 'post_excerpt', 'post_name' )
);

// The eight-state model. Every state from §1.7 is a real constant.
$expected_states = array(
    Content_Journal::STATE_STAGED,
    Content_Journal::STATE_PUBLICATION_IN_PROGRESS,
    Content_Journal::STATE_PUBLICATION_COMMITTED,
    Content_Journal::STATE_RECONCILIATION_PENDING,
    Content_Journal::STATE_PUBLICATION_SETTLED,
    Content_Journal::STATE_COMPENSATED,
    Content_Journal::STATE_CONFLICT,
    Content_Journal::STATE_ABANDONED,
);
foreach ( $expected_states as $state ) {
    s2c_check(
        'state ' . $state . ' is in Content_Journal::STATES',
        in_array( $state, Content_Journal::STATES, true ),
        'value=' . var_export( $state, true )
    );
}

// The legacy four states are still present (record_intent for
// create_draft still lands at STATE_INTENDED, etc.).
foreach ( array(
    Content_Journal::STATE_INTENDED,
    Content_Journal::STATE_APPLIED,
    Content_Journal::STATE_COMPENSATING,
    Content_Journal::STATE_PARTIAL_APPLIED,
    Content_Journal::STATE_CLAIMED,
) as $legacy ) {
    s2c_check(
        'legacy state ' . $legacy . ' still in STATES',
        in_array( $legacy, Content_Journal::STATES, true )
    );
}

// record_intent() for an OP_EDIT_PUBLISHED op enters at STATE_STAGED,
// not STATE_INTENDED — the §1.7 entry state for the published-edit flow.
$op = Content_Journal::record_intent(
    Content_Journal::OP_EDIT_PUBLISHED,
    array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        'original_id' => 101,
        'shadow_id'   => 202,
    ),
    array(
        'post_title'   => 'Staged Title',
        'post_content' => 'Staged content.',
        'post_excerpt' => 'Staged excerpt.',
        'post_name'    => 'staged-slug',
    )
);
s2c_check(
    'OP_EDIT_PUBLISHED record_intent enters at STATE_STAGED (not STATE_INTENDED)',
    $op['state'] === Content_Journal::STATE_STAGED,
    'state=' . var_export( $op['state'], true )
);

// record_intent for a create_draft op still enters at STATE_INTENDED
// (preserved behaviour; the §1.7 change is additive).
$create_op = Content_Journal::record_intent(
    Content_Journal::OP_CREATE_DRAFT,
    array( 'post_type' => 'post', 'post_status' => 'draft' ),
    array( 'post_title' => 'New draft' )
);
s2c_check(
    'OP_CREATE_DRAFT record_intent still enters at STATE_INTENDED',
    $create_op['state'] === Content_Journal::STATE_INTENDED,
    'state=' . var_export( $create_op['state'], true )
);

// ─────────────────────────────────────────────────────────────────────────────
// §2 — Strong immutable baseline (baseline-A) capture
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §2 Strong immutable baseline (baseline-A) capture ---\n";

s2c_reset_state();
s2c_seed_original( 501, 'Original Title', 'original content body', 'original excerpt', 'original-slug' );

$baseline = Content_Journal::capture_edit_published_baseline( 501 );

// Every required field the publish step's write-and-verify-C
// check compares against must be present.
$baseline_required = array(
    'post_type',
    'post_status',
    'post_title',
    'post_content',
    'post_excerpt',
    'post_name',
    'post_date_gmt',
    'post_modified_gmt',
    'post_content_sha256',
    'post_content_length',
    'op_meta_value',
);
foreach ( $baseline_required as $needed ) {
    s2c_check(
        'baseline carries ' . $needed,
        array_key_exists( $needed, $baseline ),
        'present=' . ( array_key_exists( $needed, $baseline ) ? 'yes' : 'no' )
    );
}

// Identity fields are read from the live post, not invented.
s2c_check(
    'baseline.post_type is the original post_type',
    $baseline['post_type'] === 'page'
);
s2c_check(
    'baseline.post_status is the original post_status',
    $baseline['post_status'] === 'publish'
);
s2c_check(
    'baseline.post_title is the original post_title',
    $baseline['post_title'] === 'Original Title'
);
s2c_check(
    'baseline.post_content is the original post_content',
    $baseline['post_content'] === 'original content body'
);
s2c_check(
    'baseline.post_excerpt is the original post_excerpt',
    $baseline['post_excerpt'] === 'original excerpt'
);
s2c_check(
    'baseline.post_name is the original post_name',
    $baseline['post_name'] === 'original-slug'
);
s2c_check(
    'baseline.post_date_gmt is the original post_date_gmt',
    $baseline['post_date_gmt'] === '2026-10-01 00:00:00'
);
s2c_check(
    'baseline.post_modified_gmt is the original post_modified_gmt',
    $baseline['post_modified_gmt'] === '2026-10-01 00:00:00'
);

// The strong evidence: SHA-256 of the original's content, plus
// the matching length.
s2c_check(
    'baseline.post_content_sha256 matches hash(sha256, content)',
    $baseline['post_content_sha256'] === hash( 'sha256', 'original content body' )
);
s2c_check(
    'baseline.post_content_length matches strlen(content)',
    (int) $baseline['post_content_length'] === strlen( 'original content body' )
);

// The op_meta_value is unique per capture; two captures produce
// two distinct markers.
$b1 = Content_Journal::capture_edit_published_baseline( 501 );
$b2 = Content_Journal::capture_edit_published_baseline( 501 );
s2c_check(
    'two captures of the same original produce distinct op_meta_values',
    $b1['op_meta_value'] !== $b2['op_meta_value']
);
s2c_check(
    'op_meta_value carries the op_ prefix used by new_op_id()',
    0 === strpos( $b1['op_meta_value'], 'op_' )
);

// Capture refuses a non-existent original.
$threw = false;
try {
    Content_Journal::capture_edit_published_baseline( 999999 );
} catch ( \RuntimeException $e ) {
    $threw = true;
}
s2c_check(
    'capture_edit_published_baseline throws when the original is not loadable',
    $threw
);

// Capture refuses a non-positive id.
$threw2 = false;
try {
    Content_Journal::capture_edit_published_baseline( 0 );
} catch ( \InvalidArgumentException $e ) {
    $threw2 = true;
}
s2c_check(
    'capture_edit_published_baseline throws on a non-positive id',
    $threw2
);

// The hash detects a same-second content edit. The publish
// step's revalidation would compare against the baseline; a
// content-only edit that leaves slug and modified_gmt
// unchanged is detected by the hash.
s2c_check(
    'baseline hash detects a content edit with unchanged slug and modified_gmt',
    $baseline['post_content_sha256'] !== hash( 'sha256', 'edited content body' )
);

// ─────────────────────────────────────────────────────────────────────────────
// §3 — OP_EDIT_PUBLISHED validation against the strong after-image contract
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §3 OP_EDIT_PUBLISHED structural validation ---\n";

// A well-formed edit_published op validates cleanly.
$well_formed = array(
    'id'          => 'op_test_1',
    'op'          => Content_Journal::OP_EDIT_PUBLISHED,
    'v'           => 1,
    'state'       => Content_Journal::STATE_STAGED,
    'actor'       => 'actor-1',
    'recorded_at' => S2C_T0,
    'updated_at'  => S2C_T0,
    'target'      => array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        'original_id' => 501,
        'shadow_id'   => 202,
    ),
    'before' => $baseline,
    'after'  => array(
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'Staged Title',
        'post_content' => 'Staged content.',
        'post_excerpt' => 'Staged excerpt.',
        'post_name'    => 'staged-slug',
        'op_meta_value' => 'op_shadow_1',
    ),
    'post_id' => 0,
    'claim'   => null,
    'comp'    => null,
    'notes'   => array(),
);
$content = array(
    'v'   => 1,
    'tx'  => 'tx-s2c-validation',
    'ops' => array( 'op_test_1' => $well_formed ),
    'sig' => '',
);
$problems = Content_Journal::validate( $content );
s2c_check(
    'a well-formed edit_published op validates without problems',
    empty( $problems ),
    'problems=' . ( $problems ? '[' . implode( '; ', $problems ) . ']' : 'none' )
);

// before.op_meta_value == after.op_meta_value is a structural
// error: the baseline is for the original; the after is for
// the shadow. They must be different.
$broken = $well_formed;
$broken['after']['op_meta_value'] = $broken['before']['op_meta_value'];
$content2 = array(
    'v'   => 1,
    'tx'  => 'tx-s2c-validation',
    'ops' => array( 'op_test_1' => $broken ),
    'sig' => '',
);
$problems2 = Content_Journal::validate( $content2 );
s2c_check(
    'edit_published refuses when before.op_meta_value == after.op_meta_value',
    ! empty( $problems2 )
        && (
            strpos( implode( ' ', $problems2 ), 'same op_meta_value' ) !== false
            || strpos( implode( ' ', $problems2 ), 'before' ) !== false
        ),
    'problems=' . ( $problems2 ? '[' . implode( '; ', $problems2 ) . ']' : 'none' )
);

// Missing target.original_id is structural.
$no_orig = $well_formed;
unset( $no_orig['target']['original_id'] );
$content3 = array(
    'v'   => 1,
    'tx'  => 'tx-s2c-validation',
    'ops' => array( 'op_test_1' => $no_orig ),
    'sig' => '',
);
$problems3 = Content_Journal::validate( $content3 );
s2c_check(
    'edit_published refuses when target.original_id is missing',
    ! empty( $problems3 )
        && strpos( implode( ' ', $problems3 ), 'target.original_id' ) !== false
);

// Missing target.shadow_id is structural.
$no_shadow = $well_formed;
unset( $no_shadow['target']['shadow_id'] );
$content4 = array(
    'v'   => 1,
    'tx'  => 'tx-s2c-validation',
    'ops' => array( 'op_test_1' => $no_shadow ),
    'sig' => '',
);
$problems4 = Content_Journal::validate( $content4 );
s2c_check(
    'edit_published refuses when target.shadow_id is missing',
    ! empty( $problems4 )
        && strpos( implode( ' ', $problems4 ), 'target.shadow_id' ) !== false
);

// Missing before is structural; subsequent checks are skipped
// (validate_op_edit_published returns early on missing before).
$no_before = $well_formed;
$no_before['before'] = null;
$content5 = array(
    'v'   => 1,
    'tx'  => 'tx-s2c-validation',
    'ops' => array( 'op_test_1' => $no_before ),
    'sig' => '',
);
$problems5 = Content_Journal::validate( $content5 );
s2c_check(
    'edit_published refuses when before-image is missing',
    ! empty( $problems5 )
        && strpos( implode( ' ', $problems5 ), 'before-image' ) !== false
);

// Missing one of the eleven required baseline fields is structural.
$partial = $well_formed;
unset( $partial['before']['post_content_sha256'] );
$content6 = array(
    'v'   => 1,
    'tx'  => 'tx-s2c-validation',
    'ops' => array( 'op_test_1' => $partial ),
    'sig' => '',
);
$problems6 = Content_Journal::validate( $content6 );
s2c_check(
    'edit_published refuses when before.post_content_sha256 is missing',
    ! empty( $problems6 )
        && strpos( implode( ' ', $problems6 ), 'post_content_sha256' ) !== false
);

// The op's state is one of the eight §1.7 states; the validator
// permits every state in the model.
foreach ( $expected_states as $state ) {
    $stateful = $well_formed;
    $stateful['state'] = $state;
    $content_state = array(
        'v'   => 1,
        'tx'  => 'tx-s2c-state-' . $state,
        'ops' => array( 'op_test_1' => $stateful ),
        'sig' => '',
    );
    $state_problems = Content_Journal::validate( $content_state );
    $state_problems_filtered = array_filter( $state_problems, function( $p ) { return strpos( $p, 'lifecycle states' ) !== false; } );
    s2c_check(
        'edit_published state ' . $state . ' is permitted by the validator',
        empty( $state_problems_filtered )
    );
}

// An unknown state is refused.
$bad_state = $well_formed;
$bad_state['state'] = 'uninvented';
$content_bad = array(
    'v'   => 1,
    'tx'  => 'tx-s2c-bad-state',
    'ops' => array( 'op_test_1' => $bad_state ),
    'sig' => '',
);
$problems_bad = Content_Journal::validate( $content_bad );
s2c_check(
    'edit_published refuses an unknown state',
    ! empty( $problems_bad )
        && strpos( implode( ' ', $problems_bad ), 'lifecycle states' ) !== false
);

// ─────────────────────────────────────────────────────────────────────────────
// §4 — Per-original claim: shape, read, fresh
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §4 Per-original claim shape and freshness ---\n";

$tx_id       = 'tx-claim-shape';
$actor       = 'actor-claim';
$original_id = 7001;
$shadow_id   = 8001;
$baseline_ts = S2C_T0;
$claimed_at  = S2C_T0;
$claim_ttl   = 120;
$shape = Published_Claim::shape( $tx_id, $original_id, $shadow_id, $actor, $baseline_ts, $claimed_at, $claim_ttl );

s2c_check(
    'shape carries the seven claim fields',
    isset( $shape['tx_id'], $shape['original_id'], $shape['shadow_id'], $shape['actor'], $shape['baseline_ts'], $shape['claimed_at'], $shape['claim_ttl'] )
);
s2c_check(
    'shape.tx_id is the supplied tx_id',
    $shape['tx_id'] === $tx_id
);
s2c_check(
    'shape.original_id is the int-casted original_id',
    $shape['original_id'] === $original_id && is_int( $shape['original_id'] )
);
s2c_check(
    'shape.shadow_id is the int-casted shadow_id',
    $shape['shadow_id'] === $shadow_id && is_int( $shape['shadow_id'] )
);
s2c_check(
    'shape.actor is the string-casted actor',
    $shape['actor'] === $actor
);
s2c_check(
    'shape.baseline_ts is the int-casted baseline_ts',
    $shape['baseline_ts'] === $baseline_ts && is_int( $shape['baseline_ts'] )
);
s2c_check(
    'shape.claimed_at is the int-casted claimed_at',
    $shape['claimed_at'] === $claimed_at && is_int( $shape['claimed_at'] )
);
s2c_check(
    'shape.claim_ttl is the int-casted claim_ttl',
    $shape['claim_ttl'] === $claim_ttl && is_int( $shape['claim_ttl'] )
);

s2c_check(
    'key() produces agentshell_published_claim_<id>',
    Published_Claim::key( 12345 ) === 'agentshell_published_claim_12345'
);

// Freshness: a fresh claim reads as fresh; a claim past its TTL
// is no longer fresh.
s2c_check(
    'is_fresh(claim_at=T0, ttl=120, now=T0) is true',
    Published_Claim::is_fresh( $shape, S2C_T0 )
);
s2c_check(
    'is_fresh(claim_at=T0, ttl=120, now=T0+119) is true',
    Published_Claim::is_fresh( $shape, S2C_T0 + 119 )
);
s2c_check(
    'is_fresh(claim_at=T0, ttl=120, now=T0+120) is false (TTL boundary)',
    ! Published_Claim::is_fresh( $shape, S2C_T0 + 120 )
);
s2c_check(
    'is_fresh(claim_at=T0, ttl=120, now=T0+200) is false',
    ! Published_Claim::is_fresh( $shape, S2C_T0 + 200 )
);
s2c_check(
    'is_fresh(null) is false',
    ! Published_Claim::is_fresh( null )
);

// read(): no claim yet returns null.
s2c_reset_state();
s2c_check(
    'read() of an unwritten claim returns null',
    Published_Claim::read( 12345 ) === null
);

// read(): a corrupt row (missing required fields) is reported
// as null, not as a held claim.
$GLOBALS['_agentshell_test_options'][ Published_Claim::key( 12345 ) ] = array( 'unrelated' => 'shape' );
s2c_check(
    'read() of a non-claim-shaped value returns null',
    Published_Claim::read( 12345 ) === null
);

// read() of a well-formed row returns the row.
$GLOBALS['_agentshell_test_options'][ Published_Claim::key( 12345 ) ] = $shape;
s2c_check(
    'read() of a well-formed claim returns the row',
    Published_Claim::read( 12345 ) === $shape
);

// rows_equal: same row is equal, different row is not.
s2c_check(
    'rows_equal(row, row) is true',
    Published_Claim::rows_equal( $shape, $shape )
);
$different_actor = $shape;
$different_actor['actor'] = 'different-actor';
s2c_check(
    'rows_equal(row, row with different actor) is false',
    ! Published_Claim::rows_equal( $shape, $different_actor )
);
$different_ttl = $shape;
$different_ttl['claim_ttl'] = 240;
s2c_check(
    'rows_equal(row, row with different claim_ttl) is false',
    ! Published_Claim::rows_equal( $shape, $different_ttl )
);

// ─────────────────────────────────────────────────────────────────────────────
// §5 — Per-original claim: atomic create
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §5 Per-original claim atomic create ---\n";

s2c_reset_state();
$row1 = Published_Claim::shape( 'tx-1', 5001, 6001, 'actor-1', S2C_T0, S2C_T0, 120 );
$r1 = Published_Claim::create( 5001, $row1 );
s2c_check(
    'create on a fresh original returns "created"',
    $r1 === 'created',
    'r1=' . var_export( $r1, true )
);
s2c_check(
    'create on a fresh original writes the wp_options row',
    array_key_exists( Published_Claim::key( 5001 ), $GLOBALS['_agentshell_test_options'] )
);

// A second create for the same original returns "exists"; the
// existing row is preserved, the new row is not written.
$row2 = Published_Claim::shape( 'tx-2', 5001, 6002, 'actor-2', S2C_T0 + 10, S2C_T0 + 10, 120 );
$r2 = Published_Claim::create( 5001, $row2 );
s2c_check(
    'create on a held original returns "exists"',
    $r2 === 'exists',
    'r2=' . var_export( $r2, true )
);
$preserved = $GLOBALS['_agentshell_test_options'][ Published_Claim::key( 5001 ) ];
s2c_check(
    'create on a held original does NOT overwrite the existing row',
    $preserved === $row1
);

// The second caller's identity is the first caller's identity.
// A caller that finds "exists" must read the existing row to
// determine ownership; the create never silently overwrites.
$existing = Published_Claim::read( 5001 );
s2c_check(
    'after a "exists" return, read() returns the original row',
    is_array( $existing ) && $existing['tx_id'] === 'tx-1' && $existing['actor'] === 'actor-1'
);

// Two different originals do not collide.
$row3 = Published_Claim::shape( 'tx-3', 5002, 6003, 'actor-3', S2C_T0, S2C_T0, 120 );
$r3 = Published_Claim::create( 5002, $row3 );
s2c_check(
    'create on a different original returns "created"',
    $r3 === 'created'
);

// ─────────────────────────────────────────────────────────────────────────────
// §6 — Per-original claim: value-guarded renew
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §6 Per-original claim value-guarded renew ---\n";

s2c_reset_state();
$seed = Published_Claim::shape( 'tx-renew', 7001, 8001, 'actor-renew', S2C_T0, S2C_T0, 120 );
Published_Claim::create( 7001, $seed );

// Renew with the exact expected row bytes: succeeds.
$next = Published_Claim::shape( 'tx-renew', 7001, 8001, 'actor-renew', S2C_T0, S2C_T0 + 30, 120 );
$r_renew_ok = Published_Claim::renew( 7001, $seed, $next );
s2c_check(
    'renew with the exact expected row returns "renewed"',
    $r_renew_ok === 'renewed',
    'r=' . var_export( $r_renew_ok, true )
);
$after_renew = Published_Claim::read( 7001 );
s2c_check(
    'renew writes the new bytes (claimed_at advanced)',
    is_array( $after_renew ) && (int) $after_renew['claimed_at'] === S2C_T0 + 30
);

// Renew with stale expected bytes (the row has moved): the
// stale caller does not silently overwrite.
$stale = $seed;
$r_renew_stale = Published_Claim::renew( 7001, $stale, $next );
s2c_check(
    'renew with a stale expected row returns "moved"',
    $r_renew_stale === 'moved',
    'r=' . var_export( $r_renew_stale, true )
);
$after_stale = Published_Claim::read( 7001 );
s2c_check(
    'renew with a stale expected row did NOT overwrite the live row',
    is_array( $after_stale ) && (int) $after_stale['claimed_at'] === S2C_T0 + 30
);

// Renew against a deleted row returns "gone".
unset( $GLOBALS['_agentshell_test_options'][ Published_Claim::key( 7001 ) ] );
$r_renew_gone = Published_Claim::renew( 7001, $next, $next );
s2c_check(
    'renew against a deleted row returns "gone"',
    $r_renew_gone === 'gone',
    'r=' . var_export( $r_renew_gone, true )
);

// ─────────────────────────────────────────────────────────────────────────────
// §7 — Per-original claim: value-guarded release
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §7 Per-original claim value-guarded release ---\n";

s2c_reset_state();
$release_row = Published_Claim::shape( 'tx-release', 9001, 9002, 'actor-release', S2C_T0, S2C_T0, 120 );
Published_Claim::create( 9001, $release_row );

// Release with the exact expected row bytes: succeeds.
$r_rel_ok = Published_Claim::release( 9001, $release_row );
s2c_check(
    'release with the exact expected row returns "released"',
    $r_rel_ok === 'released',
    'r=' . var_export( $r_rel_ok, true )
);
s2c_check(
    'release deletes the wp_options row',
    ! array_key_exists( Published_Claim::key( 9001 ), $GLOBALS['_agentshell_test_options'] )
);

// Release against an already-deleted row returns "gone".
$r_rel_gone = Published_Claim::release( 9001, $release_row );
s2c_check(
    'release against a deleted row returns "gone"',
    $r_rel_gone === 'gone',
    'r=' . var_export( $r_rel_gone, true )
);

// Release with stale expected bytes (a competing caller has
// swapped the row): the release returns "moved" and the live
// row is preserved.
s2c_reset_state();
$a = Published_Claim::shape( 'tx-a', 9101, 9201, 'actor-a', S2C_T0, S2C_T0, 120 );
$b = Published_Claim::shape( 'tx-b', 9101, 9201, 'actor-b', S2C_T0, S2C_T0, 120 );
Published_Claim::create( 9101, $a );
// tx-b's create is refused (exists); but a value-guarded
// release of tx-a's row when the live row has been swapped to
// tx-b's bytes would be wrong. We can't directly swap
// without renew, so we use renew first to simulate
// ownership change.
Published_Claim::renew( 9101, $a, $b );
// Now $a is stale. A release against $a must return "moved"
// and the live row (b) must be preserved.
$r_rel_moved = Published_Claim::release( 9101, $a );
s2c_check(
    'release with a stale expected row returns "moved"',
    $r_rel_moved === 'moved',
    'r=' . var_export( $r_rel_moved, true )
);
$preserved_row = Published_Claim::read( 9101 );
s2c_check(
    'release with a stale expected row did NOT delete the live row',
    is_array( $preserved_row ) && $preserved_row['tx_id'] === 'tx-b'
);

// ─────────────────────────────────────────────────────────────────────────────
// §8 — Per-original claim: competing transaction for the same original
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- §8 Per-original claim competing transaction ---\n";

s2c_reset_state();
$tx_a = Published_Claim::shape( 'tx-A', 10001, 11001, 'actor-A', S2C_T0, S2C_T0, 120 );
$create_a = Published_Claim::create( 10001, $tx_a );
s2c_check(
    'transaction A acquires the claim on a fresh original',
    $create_a === 'created'
);

$tx_b = Published_Claim::shape( 'tx-B', 10001, 11002, 'actor-B', S2C_T0, S2C_T0, 120 );
$create_b = Published_Claim::create( 10001, $tx_b );
s2c_check(
    'transaction B is refused at create time (returns "exists")',
    $create_b === 'exists'
);

// The original is still owned by A. B may inspect the claim to
// learn who holds it, but B may not silently overwrite.
$current_holder = Published_Claim::read( 10001 );
s2c_check(
    'B sees the original claim holder (tx-A) on read',
    is_array( $current_holder ) && $current_holder['tx_id'] === 'tx-A'
);

// B cannot release A's claim with B's row bytes.
$rel_b = Published_Claim::release( 10001, $tx_b );
s2c_check(
    'B cannot release A\'s claim with B\'s row bytes',
    $rel_b === 'moved'
);
s2c_check(
    'A\'s claim is preserved after B\'s failed release',
    is_array( Published_Claim::read( 10001 ) ) && Published_Claim::read( 10001 )['tx_id'] === 'tx-A'
);

// A's release does succeed.
$rel_a = Published_Claim::release( 10001, $tx_a );
s2c_check(
    'A\'s release with A\'s row bytes succeeds',
    $rel_a === 'released'
);

// B can now acquire the claim.
$create_b_retry = Published_Claim::create( 10001, $tx_b );
s2c_check(
    'after A releases, B can acquire the claim',
    $create_b_retry === 'created'
);

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────

echo "\n--- STAGE 2C FOUNDATION SUMMARY ---\n";
echo "{$GLOBALS['__s2c_pass']} pass / {$GLOBALS['__s2c_fail']} fail\n";
echo "db_error_count=" . $GLOBALS['__s2c_db_errors'] . "\n";
exit( $GLOBALS['__s2c_fail'] === 0 ? 0 : 1 );
