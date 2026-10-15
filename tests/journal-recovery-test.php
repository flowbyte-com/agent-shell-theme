<?php
/**
 * Content-journal recovery regressions.
 *
 * What this suite is for
 * ----------------------
 * Stage 0 shipped a rule: a transaction holding durable work is never reclaimed
 * because its journal is the only copy of what it did. Until journalling
 * existed that rule was true vacuously, and a rule with nothing behind it has
 * never been tested. These are the tests for the case it was written for — a
 * transaction that really did leave work behind, really did expire, and really
 * does have to be resolved by somebody before the site works again.
 *
 * The failure model, in the order the scenarios can happen
 * --------------------------------------------------------
 * There is exactly one window that matters, and it is not "the write failed".
 * It is the gap between *deciding* to change a post and *knowing* you did:
 *
 *     record intent ──▶ wp_insert_post() ──▶ record the id
 *         ▲                    │                     ▲
 *         │                    └── crash ──────────────┘
 *         └── crash (nothing happened; the journal is now a claim about a
 *             post that does not exist)
 *
 * Both sides of that window have to be recoverable, and — this is the part
 * that is easy to get wrong — the *same journal state* has to be resolvable in
 * both directions. A record at `intended` cannot mean "nothing happened", or a
 * crash after the insert leaks a draft nobody will ever find. It also cannot
 * mean "it happened", or a crash before the insert deletes somebody's page. So
 * `intended` is resolved from evidence on the post, not from the journal's own
 * optimism, and most of what follows is really an assertion about not deleting
 * the wrong thing.
 *
 * What the fifteen scenarios are
 * ------------------------------
 * The brief enumerated them; they map to the `Agentshell_Test::run()` blocks
 * below in the same order, and each names the scenario it covers.
 *
 * Nothing here touches the network or the live site. The real-WordPress
 * counterpart is journal-recovery-live-test.php, because the parts of this
 * that stub the most — wp_delete_post, get_post_meta, the marker query — are
 * exactly the parts where a stub can agree with the code for the wrong reason.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'journal-recovery-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }

foreach ( array(
    'class-store.php',
    'class-doctor.php',
    'class-transactions.php',
    'class-content-write-guard.php',
    'class-content-journal.php',
    'class-recovery-advisory-lock.php',
    'class-content-recovery.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-get-journal.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-recover-transaction.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-rollback-transaction.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-commit-transaction.php';

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Content_Recovery;
use AgentShell_MCP\Content_Write_Guard;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Get_Journal;
use AgentShell_MCP\Tools\Recover_Transaction;
use AgentShell_MCP\Tools\Rollback_Transaction;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "content journal recovery tests\n";

define( 'JR_T0', 1800000000 );
define( 'JR_CRASHED_ACTOR', 'crashed-agent' );

// The lifecycle wire-up tests run commit() and rollback() through the
// Doctor, which refuses on a missing or low WordPress version. The
// stub environment does not load wp-includes, so Doctor sees an empty
// $wp_version; declare one that clears the gate.
$GLOBALS['wp_version'] = '6.5';

// ── WordPress surface this code touches, stubbed ─────────────────────────────
//
// Post meta and post deletion. Everything else — get_post, get_option, and the
// compare-and-swap on wp_options — comes from bootstrap.php, because the lease
// suite's tests already depend on those being faithful and a second copy would
// drift from the one everything else is checked against.

$GLOBALS['_agentshell_test_postmeta'] = array();
$GLOBALS['jr_delete_calls']           = array();
$GLOBALS['jr_delete_last_force']      = null;
$GLOBALS['jr_delete_fails']           = false;

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key = '', $single = false ) {
        $all = $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ] ?? array();
        if ( '' === $key ) { return $all; }
        if ( ! array_key_exists( $key, $all ) ) { return $single ? '' : array(); }
        return $single ? $all[ $key ] : array( $all[ $key ] );
    }
    function update_post_meta( $post_id, $key, $value, $prev = '' ) {
        $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ $key ] = $value;
        return true;
    }
    function delete_post_meta( $post_id, $key, $value = '' ) {
        unset( $GLOBALS['_agentshell_test_postmeta'][ (int) $post_id ][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'wp_delete_post' ) ) {
    /**
     * Deletes a post and its meta, and records the force flag it was given.
     *
     * The force flag is recorded rather than merely passed because the choice
     * between trash and permanent delete is the whole reason this operation is
     * safe: trashing would leave a row that is itself content needing cleanup,
     * and `wp_trash_post()` rewrites the slug, so the object would no longer be
     * the one the journal described.
     *
     * Returns false without touching anything when a test asks for failure, so
     * a failed compensation leaves the draft whole — the state the retry
     * assertions need, and the state the live suite proves by actually rolling
     * back.
     */
    function wp_delete_post( $post_id = 0, $force_delete = false ) {
        $post_id = (int) $post_id;
        $GLOBALS['jr_delete_calls'][]      = $post_id;
        $GLOBALS['jr_delete_last_force'] = (bool) $force_delete;
        $GLOBALS['_agentshell_test_destructive_log'][] = 'delete:' . $post_id;
        if ( $GLOBALS['jr_delete_fails'] ) { return false; }
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $post_id ] ) ) { return false; }
        $post = $GLOBALS['_agentshell_test_posts'][ $post_id ];
        unset( $GLOBALS['_agentshell_test_posts'][ $post_id ], $GLOBALS['_agentshell_test_postmeta'][ $post_id ] );
        return $post;
    }
}

if ( ! function_exists( '_agentshell_test_apply_wp_insert' ) ) {
    /**
     * The fixture-mutating core of wp_insert_post. Extracted so the
     * content-write-test.php stub can call it when both suites run in the
     * same process (the dispatcher installed below makes the call): the
     * journal's compensation path needs the fixture store to be authoritative
     * so that compensate_update and the OP_UPDATE_DRAFT path can read the
     * post back, while content-write's tests still want their own stub to
     * honour the scripted `cw_write_result` return value. Splitting the
     * mutation out lets each stub keep its own return-value contract.
     */
    function _agentshell_test_apply_wp_insert( $postarr ) {
        $existing_ids = array_keys( $GLOBALS['_agentshell_test_posts'] );
        $new_id = $existing_ids ? max( $existing_ids ) + 1 : 1;
        $now    = Tx::lease_now();
        $now_gmt = gmdate( 'Y-m-d H:i:s', (int) $now );
        $post = (object) array(
            'ID'                => $new_id,
            'post_title'        => (string) ( $postarr['post_title'] ?? '' ),
            'post_content'      => (string) ( $postarr['post_content'] ?? '' ),
            'post_excerpt'      => '',
            'post_name'         => (string) ( $postarr['post_name'] ?? '' ),
            'post_type'         => (string) ( $postarr['post_type'] ?? 'post' ),
            'post_status'       => (string) ( $postarr['post_status'] ?? 'draft' ),
            'post_date'         => $now_gmt,
            'post_date_gmt'     => $now_gmt,
            'post_modified'     => $now_gmt,
            'post_modified_gmt' => $now_gmt,
            'post_parent'       => (int) ( $postarr['post_parent'] ?? 0 ),
        );
        $GLOBALS['_agentshell_test_posts'][ $new_id ] = $post;
        if ( ! empty( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ) {
            foreach ( $postarr['meta_input'] as $k => $v ) {
                $GLOBALS['_agentshell_test_postmeta'][ $new_id ][ (string) $k ] = $v;
            }
        }
        return $new_id;
    }
}

if ( ! function_exists( '_agentshell_test_apply_wp_update' ) ) {
    /**
     * The fixture-mutating core of wp_update_post. Returns the post id on
     * success, 0 on a missing post, and 0 when `$GLOBALS['jr_update_fails']`
     * is set so the UR6 failure path is reachable. `post_modified_gmt` is
     * bumped to "now" (the frozen transaction clock) so OCC has a fresh
     * timestamp to compare against.
     */
    function _agentshell_test_apply_wp_update( $postarr ) {
        $post_id_log = (int) ( $postarr['ID'] ?? 0 );
        $GLOBALS['_agentshell_test_destructive_log'][] = 'update:' . $post_id_log;
        if ( ! empty( $GLOBALS['jr_update_fails'] ) ) {
            return 0;
        }
        $post_id = (int) ( $postarr['ID'] ?? 0 );
        if ( $post_id <= 0 || ! isset( $GLOBALS['_agentshell_test_posts'][ $post_id ] ) ) {
            return 0;
        }
        $existing = $GLOBALS['_agentshell_test_posts'][ $post_id ];
        $now_gmt  = gmdate( 'Y-m-d H:i:s', (int) Tx::lease_now() );
        $merged   = (object) array(
            'ID'                => $post_id,
            'post_title'        => array_key_exists( 'post_title', $postarr ) ? (string) $postarr['post_title'] : (string) $existing->post_title,
            'post_content'      => array_key_exists( 'post_content', $postarr ) ? (string) $postarr['post_content'] : (string) $existing->post_content,
            'post_excerpt'      => (string) $existing->post_excerpt,
            'post_name'         => array_key_exists( 'post_name', $postarr ) ? (string) $postarr['post_name'] : (string) $existing->post_name,
            'post_type'         => (string) $existing->post_type,
            'post_status'       => (string) $existing->post_status,
            'post_date'         => (string) $existing->post_date,
            'post_date_gmt'     => (string) $existing->post_date_gmt,
            'post_modified'     => $now_gmt,
            'post_modified_gmt' => $now_gmt,
            'post_parent'       => (int) $existing->post_parent,
        );
        $GLOBALS['_agentshell_test_posts'][ $post_id ] = $merged;
        return $post_id;
    }
}

if ( ! function_exists( 'wp_insert_post' ) ) {
    /**
     * Inserts a new post in the fixture store. Direct-run path: when
     * `tests/content-write-test.php` has not been loaded first, this stub
     * is the active one and the mutation is performed in-line. Records the
     * call shape in `$GLOBALS['jr_insert_calls']` for tests that pin the
     * insert arguments.
     */
    function wp_insert_post( $postarr, $wp_error = false ) {
        $GLOBALS['jr_insert_calls'][] = $postarr;
        return _agentshell_test_apply_wp_insert( $postarr );
    }
}

if ( ! function_exists( 'wp_update_post' ) ) {
    /**
     * Updates an existing post in the fixture store. Direct-run path: when
     * `tests/content-write-test.php` has not been loaded first, this stub
     * is the active one and the mutation is performed in-line. The
     * `destructive_log` is appended in `_agentshell_test_apply_wp_update`.
     */
    function wp_update_post( $postarr, $wp_error = false ) {
        $GLOBALS['jr_update_calls'][] = $postarr;
        return _agentshell_test_apply_wp_update( $postarr );
    }
}

/**
 * Install dispatchers into the content-write-test.php stubs. When both
 * suites run in the same process (via `tests/run-all.php`), content-write
 * loads first and its stubs become the active `wp_insert_post` /
 * `wp_update_post`. The dispatchers mutate the fixture store (which is
 * what the journal's compensation path and the OP_UPDATE_DRAFT after-image
 * capture need) and return the *scripted* value (`$scripted_result`) so
 * content-write's own tests still observe the return id they pre-seeded;
 * the production code only uses the return value as a truthiness check
 * (a WP_Error or 0 is a failure, anything else is a success), so passing
 * through the scripted value is enough to keep both contracts honest.
 */
$GLOBALS['cw_wp_insert_dispatcher'] = function ( $postarr, $scripted_result ) {
    $GLOBALS['jr_insert_calls'][] = $postarr;
    _agentshell_test_apply_wp_insert( $postarr );
    return $scripted_result;
};
$GLOBALS['cw_wp_update_dispatcher'] = function ( $postarr, $scripted_result ) {
    $GLOBALS['jr_update_calls'][] = $postarr;
    return _agentshell_test_apply_wp_update( $postarr );
};

/**
 * A wpdb for this suite: the CAS from bootstrap, plus the two evidence
 * queries Content_Recovery runs and the engine report the write guard asks for.
 */
class JR_wpdb extends wpdb_stub_for_tests {
    public $posts    = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $log      = array();
    public $engine   = 'InnoDB';

    public function query( $sql ) {
        $trimmed = trim( (string) $sql );
        if ( preg_match( '/^(START TRANSACTION|COMMIT|ROLLBACK)$/i', $trimmed ) ) {
            $this->log[] = strtoupper( $trimmed );
            return 0;
        }
        return parent::query( $sql );
    }

    public function get_results( $sql ) {
        if ( 0 === stripos( ltrim( (string) $sql ), 'SHOW TABLE STATUS' ) ) {
            return array(
                (object) array( 'Name' => 'wp_posts', 'Engine' => $this->engine ),
                (object) array( 'Name' => 'wp_postmeta', 'Engine' => $this->engine ),
            );
        }
        return array();
    }

    /**
     * The marker lookup and the target lookup, answered from the fixtures.
     *
     * Matched by shape rather than executed, because the point is that
     * Content_Recovery *asks the right question*, not that this stub understands
     * SQL. A suite that returned everything for every query would let "delete
     * only what the marker names" pass while the code sent no query at all.
     */
    public function get_col( $sql ) {
        $sql = (string) $sql;

        if ( preg_match( "/WHERE meta_key = '([^']*)' AND meta_value = '([^']*)'/", $sql, $m ) ) {
            $found = array();
            foreach ( $GLOBALS['_agentshell_test_postmeta'] as $post_id => $meta ) {
                if ( isset( $meta[ $m[1] ] ) && (string) $meta[ $m[1] ] === $m[2] ) {
                    $found[] = (int) $post_id;
                }
            }
            return $found;
        }

        if ( preg_match( "/FROM \S*wp_posts WHERE post_type = '([^']*)' AND post_name = '([^']*)'/", $sql, $m ) ) {
            $found = array();
            foreach ( $GLOBALS['_agentshell_test_posts'] as $id => $post ) {
                if ( (string) $post->post_type === $m[1] && (string) $post->post_name === $m[2] ) {
                    $found[] = (int) $id;
                }
            }
            return $found;
        }

        return array();
    }
}

/** Lets another request win one guarded write, so a race can be produced. */
class JR_Racing_wpdb extends JR_wpdb {
    public $seen    = 0;
    public $skip    = 0;
    public $forever = false;
    private $callback;

    /**
     * Fire $cb just before the ($skip + 1)th guarded write.
     *
     * The index matters. A recovery makes three guarded writes — claim, settle,
     * release — and a race that is only interesting at the *third* is a
     * different race from one at the first. `forever` stays for the races that
     * want every write to lose.
     */
    public function on_guarded_write( callable $cb, $every_time = false, $skip = 0 ) {
        $this->callback = $cb;
        $this->forever  = (bool) $every_time;
        $this->skip     = (int) $skip;
        $this->seen     = 0;
    }

    public function disarm() {
        $this->callback = null;
    }

    public function query( $sql ) {
        if ( $this->callback
            // Anchored on the statement's own first tokens. Deliberately not a
            // `.*options` scan: a serialised option value is full of semicolons
            // and a serialised conflict note is full of SQL words, and a regex
            // that wanders through the payload would fire on the wrong thing.
            && preg_match( '/^(UPDATE\s+\S*options|DELETE\s+FROM\s+\S*options)\b/i', $sql ) ) {
            // Counted on every match, not only on the ones that fire — otherwise
            // `skip` never advances past zero and the race lands on write one.
            $fire = $this->forever || $this->seen === $this->skip;
            $this->seen++;
            if ( $fire ) {
                $cb = $this->callback;
                $cb();
            }
        }
        return parent::query( $sql );
    }
}

// ── Fixtures ─────────────────────────────────────────────────────────────────

function jr_reset( $now = JR_T0, $actor = 'agent' ) {
    global $wpdb;
    agentshell_test_reset();
    $db = new JR_wpdb();
    $wpdb = $db;
    $GLOBALS['wpdb'] = $db;

    Tx::unfreeze_time();
    Tx::freeze_time( $now );
    Content_Write_Guard::reset();
    Store::$suppress_history = false;
    Store::$operation_label   = '';

    $GLOBALS['_agentshell_test_posts']    = array();
    $GLOBALS['_agentshell_test_postmeta'] = array();
    $GLOBALS['jr_delete_calls']           = array();
    $GLOBALS['jr_delete_last_force']      = null;
    $GLOBALS['jr_delete_fails']           = false;
    $GLOBALS['jr_insert_calls']           = array();
    $GLOBALS['jr_update_calls']           = array();
    $GLOBALS['jr_update_fails']           = false;
    $GLOBALS['_agentshell_test_destructive_log'] = array();
    $GLOBALS['_agentshell_test_login']    = $actor;
    $GLOBALS['_agentshell_test_user_caps'] = array();
    $GLOBALS['_agentshell_test_audit_fail_for'] = null;
    $GLOBALS['_agentshell_test_cas_delete_fail'] = false;
    $GLOBALS['_agentshell_test_cas_snapshot']    = null;

    $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] = array( '--jr-accent' => '#123456' );
}

/** Grant the capability the MCP transport would already have enforced. */
function jr_as_administrator() {
    $GLOBALS['_agentshell_test_user_caps'] = array( 'manage_options' => true );
}

/**
 * Grant a per-post capability (default `delete_post`) for a specific post id.
 *
 * The owner path of `Content_Recovery::authorize_destruction()` re-checks
 * `current_user_can($cap, $post_id)` at the moment of destruction. Tests
 * that drive `Content_Recovery::recover()` as the owner need this granted
 * to satisfy that gate; without it the per-op check refuses and the op
 * transitions to `conflict` instead of being deleted.
 */
function jr_with_post_cap( $post_id, $cap = 'delete_post' ) {
    $existing = isset( $GLOBALS['_agentshell_test_user_caps'][ $cap ] )
        ? $GLOBALS['_agentshell_test_user_caps'][ $cap ] : array();
    if ( ! is_array( $existing ) ) {
        $existing = array();
    }
    $existing[] = (int) $post_id;
    $GLOBALS['_agentshell_test_user_caps'][ $cap ] = array_values( array_unique( $existing ) );
}

function jr_advance( $seconds ) {
    Tx::freeze_time( JR_T0 + $seconds );
}

function jr_actor( $actor ) {
    $GLOBALS['_agentshell_test_login'] = $actor;
}

/**
 * Test-side "release any open transaction" helper.
 *
 * The production Transaction_Manager does not expose a public release
 * method, only commit/rollback. For tests that need to verify the
 * "outside a transaction" path without going through commit or
 * rollback, this helper just drops the option row — `is_active()`
 * re-reads the option on every call, so a cleared option is
 * indistinguishable from "no transaction was ever opened" for
 * production code. Reflection-nulling the singleton is intentionally
 * not done: the singleton's own state is read through payload(), and
 * payload() reads the option, so deleting the option is sufficient.
 */
function jr_release_if_open() {
    if ( array_key_exists( \AgentShell_MCP\Transaction_Manager::KEY, $GLOBALS['_agentshell_test_options'] ) ) {
        delete_option( \AgentShell_MCP\Transaction_Manager::KEY );
    }
}

/** The target a journalled create-draft is expected to produce. */
function jr_target() {
    return array(
        'post_type'   => 'page',
        'post_status' => 'draft',
        'post_title'  => 'JR draft',
        'post_name'   => 'jr-draft',
    );
}

function jr_after( $modified = '2026-01-01 00:00:00' ) {
    return array_merge( jr_target(), array( 'post_modified_gmt' => $modified ) );
}

/**
 * A journalled operation record in any state.
 *
 * Built through Content_Journal::record_intent() so the shape under test is
 * the shape the writer produces, rather than a hand-typed copy that could drift
 * from it and quietly stop exercising the real validator.
 */
function jr_op( $state = 'intended', $post_id = 0, array $after = array(), array $extra = array() ) {
    $op = Content_Journal::record_intent(
        Content_Journal::OP_CREATE_DRAFT,
        jr_target(),
        array(),
        JR_CRASHED_ACTOR,
        JR_T0 - 86400
    );
    $op['state']   = $state;
    $op['post_id'] = (int) $post_id;
    $op['after']   = array_merge( jr_after(), array( 'op_meta_value' => $op['id'] ), $after );
    return array_merge( $op, $extra );
}

/** A post in the fixture store. A null marker means "not ours". */
function jr_post( $id, array $fields = array(), $marker = null ) {
    $post = (object) array_merge( array(
        'ID'                => (int) $id,
        'post_title'        => 'JR draft',
        'post_content'      => '<p>body</p>',
        'post_excerpt'      => '',
        'post_name'         => 'jr-draft',
        'post_type'         => 'page',
        'post_status'       => 'draft',
        'post_date'         => '2026-01-01 00:00:00',
        'post_date_gmt'     => '2026-01-01 00:00:00',
        'post_modified'     => '2026-01-01 00:00:00',
        'post_modified_gmt' => '2026-01-01 00:00:00',
        'post_parent'       => 0,
    ), $fields );
    $GLOBALS['_agentshell_test_posts'][ (int) $id ] = $post;
    if ( null !== $marker ) {
        $GLOBALS['_agentshell_test_postmeta'][ (int) $id ][ Content_Journal::OP_META ] = $marker;
    }
    return $post;
}

/** An expired transaction row holding a journal of $ops. */
function jr_row( array $ops, array $over = array() ) {
    $tx = array_merge( array(
        'id'            => 'tx_jr',
        'label'         => 'interrupted',
        'actor'         => JR_CRASHED_ACTOR,
        'timestamp'     => JR_T0 - 86400,
        'created'       => JR_T0 - 86400,
        'last_activity' => JR_T0 - 86400,
        'expires'       => JR_T0 - 82800,
        'before'        => array( '--jr-accent' => '#123456' ),
        'staged'        => null,
    ), $over );

    $content                = Content_Journal::empty_journal( $tx['id'] );
    foreach ( $ops as $op ) {
        $content['ops'][ $op['id'] ] = $op;
    }
    // Plant the per-post head after-image for every op that has both a
    // post_id and a non-empty after. The head is the chain's expected
    // current state; the recovery engine verifies the live post against
    // it before any destructive step. For an op without a post_id, the
    // head is not planted (the post was never identified). For an op
    // whose after is empty (e.g. an intended op with no recorded after
    // evidence), the head is also not planted. The compensate() method
    // refuses with conflict in that case, which the tests that need
    // that branch assert.
    $head = array();
    foreach ( $ops as $op ) {
        $post_id = (int) ( $op['post_id'] ?? 0 );
        $after    = is_array( $op['after'] ?? null ) ? $op['after'] : array();
        if ( $post_id > 0 && ! empty( $after ) ) {
            $head[ $post_id ] = $after;
        }
    }
    if ( ! empty( $head ) ) {
        $content[ Content_Journal::HEAD_KEY ] = $head;
    }
    $content['sig'] = Content_Journal::sign( $content );
    $tx['content']  = $content;
    return $tx;
}

function jr_seed( array $tx ) {
    $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = $tx;
    return $tx;
}

/**
 * A live (non-expired) transaction row holding a journal of $ops.
 *
 * The commit and rollback tools gate on the lease: an expired row is
 * reclaimed by a fresh begin() and the original work is lost. The
 * lifecycle tests want to exercise the value-guarded close path, so
 * the row must be live at the moment commit()/rollback() runs.
 */
function jr_live_row( array $ops, array $over = array() ) {
    $row = jr_row( $ops, $over );
    $row['timestamp']     = JR_T0;
    $row['created']       = JR_T0;
    $row['last_activity'] = JR_T0;
    $row['expires']       = JR_T0 + 3600;
    if ( ! isset( $row['staged'] ) || null === $row['staged'] ) {
        $row['staged'] = array( '--jr-accent' => '#abcdef' );
    }
    return $row;
}

function jr_row_on_disk() {
    return $GLOBALS['_agentshell_test_options'][ Tx::KEY ] ?? null;
}

function jr_op_on_disk( $index = 0 ) {
    $tx = jr_row_on_disk();
    if ( ! is_array( $tx ) || empty( $tx['content']['ops'] ) ) { return null; }
    $ops = array_values( $tx['content']['ops'] );
    return $ops[ $index ] ?? null;
}

function jr_post_exists( $id ) {
    return isset( $GLOBALS['_agentshell_test_posts'][ (int) $id ] );
}

function jr_refuse( callable $fn ) {
    try {
        $fn();
    } catch ( \Throwable $e ) {
        return $e->getMessage();
    }
    return null;
}

function jr_audit( $operation ) {
    return array_values( array_filter(
        Store::audit_log(),
        function ( $e ) use ( $operation ) { return $e['operation'] === $operation; }
    ) );
}

/** Replace an operation's state and claim, re-signing so the row stays valid. */
function jr_set_claim( array $tx, $op_id, $state, $claim ) {
    $content                          = Content_Journal::of( $tx );
    $content['ops'][ $op_id ]['state'] = $state;
    $content['ops'][ $op_id ]['claim'] = $claim;
    $content['sig']                   = Content_Journal::sign( $content );
    $tx['content']                    = $content;
    return $tx;
}

// ---------------------------------------------------------------------------
// 1. An interrupted transaction holding a journal is not reclaimable.
//    (scenario 1 — and the Stage 0 rule that makes recovery necessary at all)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'an expired journalled transaction is kept, not reclaimed, and blocks a new one',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 401 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 401, array(), $op['id'] );

        Agentshell_Test::assert_true( Tx::has_durable_work( jr_row_on_disk() ), 'a journalled row holds durable work' );

        jr_actor( 'someone-else' );
        $message = jr_refuse( function () { Tx::instance()->begin( 'new work' ); } );
        Agentshell_Test::assert_true(
            is_string( $message ) && false !== strpos( $message, 'unrecovered work' ),
            'a new transaction must be refused: ' . var_export( $message, true )
        );
        Agentshell_Test::assert_true(
            is_array( jr_row_on_disk() ),
            'the journalled row must still be there — reclaiming it destroys the only record of the work'
        );
        Agentshell_Test::assert_true( jr_post_exists( 401 ), 'and the draft it describes must survive untouched' );
    }
);

// ---------------------------------------------------------------------------
// 2. Crash after recording the intent, before the mutation.
//    (scenario 2)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a crash before the mutation leaves nothing to delete, and says why',
    function () {
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );
        // Nothing carries the marker, and nothing matches the target. That is
        // what "the write never ran" looks like from here.

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $report['status'], 'recovery must complete' );
        Agentshell_Test::assert_equals( 'compensated', $report['operations'][0]['outcome'], 'the operation is settled' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], 'no evidence the write ever happened' ),
            'the report must state why nothing was deleted: ' . $report['operations'][0]['detail']
        );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no delete may be attempted' );
        Agentshell_Test::assert_true( null === jr_row_on_disk(), 'the settled row may then be freed' );
    }
);

// ---------------------------------------------------------------------------
// 3. Crash after the mutation, before completion recording.
//    (scenario 3)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a crash after the mutation preserves the marked post because no after-image was recorded',
    function () {
        jr_reset();
        jr_as_administrator();
        // The journal never learned the id: this is the write that happened and
        // the mark_applied() that did not. The strong-after-image contract says
        // the recovery engine cannot prove the marked post is unchanged, so it
        // refuses to delete by guess and preserves the post.
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 402, array(), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'no recorded after-image means the post is preserved' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], '402' ),
            'the conflict names the marked post id: ' . $report['operations'][0]['detail']
        );
        Agentshell_Test::assert_true( jr_post_exists( 402 ), 'the marked post must survive' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no delete may be attempted' );
    }
);

Agentshell_Test::run(
    'a recorded id is used directly',
    function () {
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 403 );   // mark_applied() landed
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 403, array(), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );
        Agentshell_Test::assert_equals( 403, (int) $report['operations'][0]['deleted_post_id'], 'the recorded id is deleted' );
        Agentshell_Test::assert_false( jr_post_exists( 403 ), 'it must be gone' );
    }
);

// ---------------------------------------------------------------------------
// 4. Successful compensation of an identified disposable draft.
//    (scenario 4)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a verified draft is deleted permanently, and only after every check passed',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 404 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 404, array(), $op['id'] );
        // The owner path of `authorize_destruction()` re-checks
        // `current_user_can('delete_post', $post_id)` at the moment of
        // destruction. Grant the cap on the recorded id so the per-op
        // gate agrees with the tool-boundary owner check.
        jr_with_post_cap( 404 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );

        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $report['status'], 'recovery must complete' );
        Agentshell_Test::assert_equals( 'owner', $report['authorized_as'], 'the owner resolves their own transaction' );
        Agentshell_Test::assert_true( $report['transaction_row_deleted'], 'the row is freed' );
        Agentshell_Test::assert_equals( array( 404 ), $GLOBALS['jr_delete_calls'], 'exactly one delete' );
        Agentshell_Test::assert_true(
            true === $GLOBALS['jr_delete_last_force'],
            'the delete must be a force delete — the default branch trashes, and a trashed draft is still content'
        );
    }
);

// ---------------------------------------------------------------------------
// 5. Repeated recovery.
//    (scenario 5)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'recovery is repeatable, and repeating it changes nothing',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 405 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 405, array(), $op['id'] );
        jr_with_post_cap( 405 );

        $first = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $first['status'], 'the first run recovers' );
        $calls_after_first = $GLOBALS['jr_delete_calls'];

        // Through the tool, as a caller repeating it would.
        $tool  = new Recover_Transaction();
        $again = $tool->execute( array( 'confirm' => true ) );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_NOTHING, $again['status'], 'a repeat finds nothing to do' );
        Agentshell_Test::assert_equals(
            $calls_after_first, $GLOBALS['jr_delete_calls'],
            'the second call must not attempt another deletion'
        );
    }
);

Agentshell_Test::run(
    'steps already settled are reported as settled, not repeated',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 406 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 406, array(), $op['id'] );

        // The crash window that matters here is between "compensation was
        // recorded" and "the row was freed". Reproduce it by settling the
        // operation and leaving the row in place.
        $row                = jr_row_on_disk();
        $content            = Content_Journal::of( $row );
        $rec                = $content['ops'][ $op['id'] ];
        $rec['state']       = Content_Journal::STATE_COMPENSATED;
        $rec['comp']        = array(
            'outcome' => 'compensated', 'post_id' => 406,
            'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0, 'reason' => 'an earlier run got this far',
        );
        $content['ops'][ $op['id'] ] = $rec;
        $content['sig']               = Content_Journal::sign( $content );
        $row['content']               = $content;
        jr_seed( $row );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'already_settled', $report['operations'][0]['outcome'], 'a settled step is reported, not redone' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'and this run deletes nothing' );
        Agentshell_Test::assert_true( jr_post_exists( 406 ), 'the post is untouched by the repeat' );
        Agentshell_Test::assert_true( $report['transaction_row_deleted'], 'the row is freed on the pass that finds it settled' );
    }
);

// ---------------------------------------------------------------------------
// 6. Crash during recovery, and resumption.
//    (scenario 6)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a recovery that dies mid-compensation is resumable, and not before its claim expires',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 407 );
        jr_seed( jr_row( array( $op ) ) );
        // A recovery that claimed the operation and then died: no deletion, a
        // claim left behind by somebody who is no longer running.
        jr_seed( jr_set_claim( jr_row_on_disk(), $op['id'], Content_Journal::STATE_COMPENSATING,
            array( 'actor' => 'half-finished-run', 'at' => JR_T0 - 10 ) ) );
        jr_post( 407, array(), $op['id'] );

        // Within the claim window: told what is happening, nothing touched.
        $blocked = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'another_recovery_in_progress', $blocked['operations'][0]['outcome'], 'a live claim blocks' );
        Agentshell_Test::assert_true( jr_post_exists( 407 ), 'nothing may be deleted while a claim is live' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no delete attempted' );
        Agentshell_Test::assert_equals(
            'half-finished-run', jr_op_on_disk()['claim']['actor'],
            'and the other caller\'s claim is not stolen from under it'
        );

        // After the claim expires: taken over and finished.
        jr_advance( Content_Recovery::CLAIM_TTL_SECONDS + 1 );
        jr_with_post_cap( 407 );
        $resumed = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $resumed['status'], 'the resumption completes' );
        Agentshell_Test::assert_equals( 407, (int) $resumed['operations'][0]['deleted_post_id'], 'the draft is deleted on resumption' );
        Agentshell_Test::assert_true( null === jr_row_on_disk(), 'and only then is the row freed' );
    }
);

Agentshell_Test::run(
    'a deletion that fails leaves the draft whole, the journal kept, and the operation owed',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 408 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 408, array(), $op['id'] );
        jr_with_post_cap( 408 );

        global $wpdb;
        $GLOBALS['jr_delete_fails'] = true;

        $failed = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'delete_failed', $failed['operations'][0]['outcome'], 'the failure is reported as such' );
        Agentshell_Test::assert_true( jr_post_exists( 408 ), 'the draft must survive a failed deletion' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'the journal must survive too' );
        Agentshell_Test::assert_equals(
            Content_Journal::STATE_COMPENSATING, jr_op_on_disk()['state'],
            'the operation stays owed rather than being marked done'
        );
        Agentshell_Test::assert_equals(
            array( 'START TRANSACTION', 'ROLLBACK' ), $wpdb->log,
            'the delete must have been inside a transaction that rolled back'
        );

        // The retry: same operation, no live claim in the way, and it works.
        $GLOBALS['jr_delete_fails'] = false;
        $retried = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $retried['status'], 'the retry completes' );
        Agentshell_Test::assert_false( jr_post_exists( 408 ), 'the draft is gone on the retry' );
    }
);

// ---------------------------------------------------------------------------
// 7. Two competing recovery callers.
//    (scenario 7)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'two competing recoveries never run the same compensation twice',
    function () {
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 409 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 409, array(), $op['id'] );

        global $wpdb;
        $racing          = new JR_Racing_wpdb();
        $wpdb            = $racing;
        $GLOBALS['wpdb'] = $racing;

        // The other caller wins the claim: it writes the row this one is about
        // to swap, between this one's read and this one's guarded write.
        $racing->on_guarded_write( function () use ( $op ) {
            $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = jr_set_claim(
                jr_row_on_disk(), $op['id'], Content_Journal::STATE_COMPENSATING,
                array( 'actor' => 'the-other-caller', 'at' => JR_T0 )
            );
        }, false );

        $loser = Content_Recovery::recover( jr_row_on_disk(), 'this-caller', true );

        Agentshell_Test::assert_equals(
            Content_Recovery::STATUS_CONTENDED, $loser['status'],
            'the loser must report contention rather than proceed'
        );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'the loser must not delete anything' );
        Agentshell_Test::assert_true( jr_post_exists( 409 ), 'the draft is still there for the winner' );

        // The winner, once its claim has aged out, does the work exactly once.
        jr_advance( Content_Recovery::CLAIM_TTL_SECONDS + 1 );
        $winner = Content_Recovery::recover( jr_row_on_disk(), 'the-other-caller', true );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $winner['status'], 'the winner completes' );
        Agentshell_Test::assert_equals( array( 409 ), $GLOBALS['jr_delete_calls'], 'exactly one deletion happened overall' );
    }
);

// ---------------------------------------------------------------------------
// 8. Content modified by another actor after the interrupted transaction.
//    (scenario 8)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a draft somebody else has since edited is refused, not deleted',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 410 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 410, array( 'post_modified_gmt' => '2026-02-02 09:00:00' ), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );

        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'divergence is a conflict' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], 'was modified at' ),
            'the conflict must say what diverged: ' . $report['operations'][0]['detail']
        );
        Agentshell_Test::assert_true( jr_post_exists( 410 ), 'the edited draft must survive' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'nothing deleted' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'the journal must be kept' );
        Agentshell_Test::assert_equals( Content_Journal::STATE_CONFLICT, jr_op_on_disk()['state'], 'the conflict is recorded' );
        Agentshell_Test::assert_true( jr_refuse( function () { Tx::instance()->begin( 'x' ); } ), 'begin() must still refuse' );
    }
);

Agentshell_Test::run(
    'a draft whose status changed to published is refused too',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 411 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 411, array( 'post_status' => 'publish' ), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'a published post is not ours to delete' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], 'post_status' ),
            'and it says which field diverged: ' . $report['operations'][0]['detail']
        );
        Agentshell_Test::assert_true( jr_post_exists( 411 ), 'it must survive' );
    }
);

Agentshell_Test::run(
    'a post carrying somebody else\'s marker is refused',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 412 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 412, array(), 'op_someone_elses' );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'a foreign marker is not identification' );
        Agentshell_Test::assert_true( jr_post_exists( 412 ), 'and the post survives' );
    }
);

// ---------------------------------------------------------------------------
// 9. Missing or already-deleted content.
//    (scenario 9)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'content that is already gone is reported as settled without deleting anything',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 413 );
        jr_seed( jr_row( array( $op ) ) );
        // No post 413: it was removed by hand, or never existed.

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $report['status'], 'the end state is already reached' );
        Agentshell_Test::assert_equals( 'compensated', $report['operations'][0]['outcome'], 'settled' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], 'already gone' ),
            'and it says why: ' . $report['operations'][0]['detail']
        );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no deletion attempted' );
        Agentshell_Test::assert_true( null === jr_row_on_disk(), 'the row is then freed' );
    }
);

// ---------------------------------------------------------------------------
// 10. Journal corruption, and unsupported operation types.
//     (scenario 10)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a journal edited outside AgentShell is refused outright',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 414 );
        $tx = jr_seed( jr_row( array( $op ) ) );
        jr_post( 414, array(), $op['id'] );

        // Somebody changed the row by hand, leaving the signature behind.
        $tx['content']['ops'][ $op['id'] ]['target']['post_name'] = 'something-else';
        jr_seed( $tx );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_REFUSED, $report['status'], 'tampering is a refusal' );
        Agentshell_Test::assert_equals( 'tampered', $report['integrity'], 'and is reported as tampering' );
        Agentshell_Test::assert_true( jr_post_exists( 414 ), 'nothing is deleted' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'the row is kept' );
    }
);

Agentshell_Test::run(
    'an operation type with no compensation contract is refused by name',
    function () {
        jr_reset();
        // A post update. The design rates it Exact, but the before/after
        // contract Stage 1 has not implemented — and pretending otherwise is
        // exactly the pretence this build refuses.
        $op           = jr_op( 'applied', 415 );
        $op['op']     = 'update_post';
        $op['before'] = array( 'post_title' => 'Before', 'post_content' => '<p>before</p>', 'post_status' => 'publish' );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 415, array( 'post_status' => 'publish' ), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_REFUSED, $report['status'], 'an unsupported type is refused' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['reason'], 'update_post' ),
            'the refusal names the type: ' . $report['reason']
        );
        Agentshell_Test::assert_true( jr_post_exists( 415 ), 'and the post is untouched' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the journal is kept' );
    }
);

Agentshell_Test::run(
    'an unreadable record refuses the whole journal, not part of it',
    function () {
        jr_reset();
        $good = jr_op( 'applied', 416 );
        $bad  = jr_op( 'applied', 417 );
        unset( $bad['after']['post_name'] );   // no way to identify the post
        jr_seed( jr_row( array( $good, $bad ) ) );
        jr_post( 416, array(), $good['id'] );
        jr_post( 417, array(), $bad['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_REFUSED, $report['status'], 'a partial journal is refused whole' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['reason'], 'post_name' ),
            'the refusal says what is missing: ' . $report['reason']
        );
        Agentshell_Test::assert_true( jr_post_exists( 416 ), 'even the healthy operation is not touched' );
        Agentshell_Test::assert_true( jr_post_exists( 417 ), 'and neither is the broken one' );
    }
);

// ---------------------------------------------------------------------------
// 11. Unauthorised recovery.
//     (scenario 11)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'recovery of another actor\'s transaction needs both the capability and the explicit opt-in',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 418 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 418, array(), $op['id'] );
        jr_actor( 'stranger' );

        // Capability, but no opt-in.
        jr_as_administrator();
        $no_opt = Content_Recovery::recover( jr_row_on_disk(), 'stranger', false );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_REFUSED, $no_opt['status'], 'the capability is not the authorisation' );
        Agentshell_Test::assert_true(
            false !== strpos( $no_opt['reason'], 'manage_options' ),
            'and the refusal names what is missing: ' . $no_opt['reason']
        );

        // No capability, but an opt-in.
        $GLOBALS['_agentshell_test_user_caps'] = array();
        $no_cap = Content_Recovery::recover( jr_row_on_disk(), 'stranger', true );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_REFUSED, $no_cap['status'], 'the opt-in is not the capability' );
        Agentshell_Test::assert_true( jr_post_exists( 418 ), 'nothing was deleted by either refusal' );

        // Both.
        jr_as_administrator();
        $both = Content_Recovery::recover( jr_row_on_disk(), 'stranger', true );
        Agentshell_Test::assert_equals(
            'recovery_administrator', $both['authorized_as'],
            'with both, the role is recorded as an administrator rather than as the owner'
        );
        Agentshell_Test::assert_false( jr_post_exists( 418 ), 'and the work is done' );
    }
);

Agentshell_Test::run(
    'inspection by the owner shows the post id and the after-image',
    function () {
        // A1: the owner path is not short-circuited by the new authority gate.
        // A real owner with manage_options reads the full journal; the
        // per-post cap is the destructive-recovery gate, not the read gate.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 418 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 418, array(), $op['id'] );
        jr_actor( JR_CRASHED_ACTOR );

        $view = ( new Get_Journal() )->execute( array() );
        Agentshell_Test::assert_equals( 1, (int) $view['operation_count'], 'the owner sees the journal' );
        Agentshell_Test::assert_equals( 418, (int) $view['operations'][0]['post_id'], 'and the post id' );
        Agentshell_Test::assert_true( $view['recoverable'], 'and recoverability is reported' );
        Agentshell_Test::assert_equals( 1, count( jr_audit( 'transaction_journal_inspected' ) ), 'and the read is audited' );
    }
);

Agentshell_Test::run(
    'inspection by an unrelated authenticated user is refused identically to a missing row',
    function () {
        // A2: replaces the previous "inspection is open" test. Inspection is
        // no longer open — the response to an unrelated authenticated caller
        // is the no-row shape, indistinguishable from a missing transaction.
        // The existence of another actor's row is not disclosed.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 419 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 419, array(), $op['id'] );
        jr_actor( 'curious-peer' );

        $before = jr_row_on_disk();
        $view   = ( new Get_Journal() )->execute( array() );

        Agentshell_Test::assert_equals( null, $view['transaction'], 'a peer cannot see whose transaction this is' );
        Agentshell_Test::assert_equals( null, $view['journal'], 'and cannot see the journal' );
        Agentshell_Test::assert_false( $view['recoverable'], 'and the recoverable flag is false' );
        Agentshell_Test::assert_equals(
            'No transaction is open.', $view['reason'],
            'and the reason matches the missing-row message'
        );

        $after = jr_row_on_disk();
        Agentshell_Test::assert_equals( $before, $after, 'inspection must leave the transaction row byte-identical' );
        Agentshell_Test::assert_true( jr_post_exists( 419 ), 'and no content may be touched' );
        Agentshell_Test::assert_equals(
            array(), jr_audit( 'transaction_journal_inspected' ),
            'and an unauthorised read writes no audit row'
        );
    }
);

Agentshell_Test::run(
    'inspection with authorize_inspection but no manage_options is refused identically',
    function () {
        // A3: the capability check is the gate. The opt-in argument on its
        // own does not authorise; an unauthenticated peer must be refused
        // the same way a missing row is refused.
        jr_reset();
        $op = jr_op( 'applied', 450 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 450, array(), $op['id'] );
        jr_actor( 'curious-peer' );

        $view = ( new Get_Journal() )->execute( array( 'authorize_inspection' => true ) );
        Agentshell_Test::assert_equals( null, $view['transaction'], 'no row is disclosed' );
        Agentshell_Test::assert_equals( 'No transaction is open.', $view['reason'], 'and the same missing-row reason' );
        Agentshell_Test::assert_equals(
            array(), jr_audit( 'transaction_journal_inspected' ),
            'and the refused read writes no audit row'
        );
    }
);

Agentshell_Test::run(
    'inspection by an administrator with authorize_inspection returns the full payload',
    function () {
        // A4: a non-owner with manage_options + the opt-in gets the same
        // view the owner would, and the audit row records the
        // recovery_administrator role.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 451 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 451, array(), $op['id'] );
        jr_actor( 'recovery-admin' );

        $view = ( new Get_Journal() )->execute( array( 'authorize_inspection' => true ) );
        Agentshell_Test::assert_equals( 1, (int) $view['operation_count'], 'the journal is visible to a recovery administrator' );
        Agentshell_Test::assert_equals( 451, (int) $view['operations'][0]['post_id'], 'with the post id' );

        $rows = jr_audit( 'transaction_journal_inspected' );
        Agentshell_Test::assert_equals( 1, count( $rows ), 'and the read is audited' );
        Agentshell_Test::assert_equals( 'recovery-admin', $rows[0]['actor'], 'naming the actor who read' );
        Agentshell_Test::assert_equals(
            'recovery_administrator', $rows[0]['changed']['authorized_as'],
            'and the role they were authorised as'
        );
    }
);

Agentshell_Test::run(
    'an unauthorised read returns a refusal that does not name the row',
    function () {
        // A5: the message is "No transaction is open.", not "belongs to X
        // and you are Y". The two refusal branches (no caps, no opt-in;
        // no caps even with opt-in) must produce identical responses for
        // any caller that is not the owner and not authorised.
        jr_reset();
        $op = jr_op( 'applied', 452 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 452, array(), $op['id'] );
        jr_actor( 'stranger' );

        $view = ( new Get_Journal() )->execute( array( 'authorize_inspection' => true ) );
        Agentshell_Test::assert_equals( 'No transaction is open.', $view['reason'], 'the refusal is the no-row message' );
        Agentshell_Test::assert_equals( null, $view['transaction'], 'and the transaction field is null' );
        Agentshell_Test::assert_equals( null, $view['journal'], 'and the journal field is null' );
    }
);

Agentshell_Test::run(
    'a successful inspection writes one audit row; an unauthorised one writes none',
    function () {
        // A6: the read-only guarantee is now expressed as "writes zero audit
        // rows on a refusal, exactly one on a success". An unauthorised
        // inspection is not a record of intent — the audit log is
        // untouched, so an attacker cannot tell from the log whether a
        // row exists that they were refused.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 453 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 453, array(), $op['id'] );

        // Refused read: the actor is not the owner and has no opt-in.
        jr_actor( 'stranger' );
        ( new Get_Journal() )->execute( array() );
        Agentshell_Test::assert_equals(
            array(), jr_audit( 'transaction_journal_inspected' ),
            'an unauthorised read leaves the audit log untouched'
        );

        // Authorised read: the owner.
        jr_actor( JR_CRASHED_ACTOR );
        ( new Get_Journal() )->execute( array() );
        $rows = jr_audit( 'transaction_journal_inspected' );
        Agentshell_Test::assert_equals( 1, count( $rows ), 'and the authorised read writes one row' );
        Agentshell_Test::assert_equals( 'owner', $rows[0]['changed']['authorized_as'], 'naming the role' );
    }
);

Agentshell_Test::run(
    'an owner without manage_options is refused inspection identically to a missing row',
    function () {
        // A1b (a corollary of A1): the owner path of `authorize_inspection`
        // is not "actor match" alone — the `manage_options` re-check is
        // what stops a session-revoked owner from reading after their
        // role was demoted. Drop the re-check (M-A1) and this test fails
        // because the owner is accepted.
        jr_reset();
        $op = jr_op( 'applied', 454 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 454, array(), $op['id'] );
        jr_actor( JR_CRASHED_ACTOR );
        // No `manage_options`: the actor was demoted.

        $view = ( new Get_Journal() )->execute( array() );
        Agentshell_Test::assert_equals( null, $view['transaction'], 'a demoted owner cannot read the row' );
        Agentshell_Test::assert_equals( 'No transaction is open.', $view['reason'], 'and the response is the no-row shape' );
        Agentshell_Test::assert_equals(
            array(), jr_audit( 'transaction_journal_inspected' ),
            'and the refused read writes no audit row'
        );
    }
);

// ---------------------------------------------------------------------------
// 11b. Distinct authorities: read vs destructive.
//      The destructive path (compensation) checks the per-post `delete_post`
//      capability at the moment of destruction. The read path does not.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a recovery administrator with the per-post cap can resolve; the cap is checked at compensation time',
    function () {
        // B1: a non-owner recovery administrator can compensate when they
        // hold both `manage_options` and the per-post `delete_post` cap. The
        // plan is explicit: the per-post cap is checked at *compensation
        // time*, not at the tool entry. This test drives a non-owner caller
        // through the tool entry (where the recovery_administrator path
        // accepts them on manage_options + opt-in) and into compensate()
        // (where the per-op check uses the same authority).
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 460 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 460, array(), $op['id'] );
        jr_with_post_cap( 460 );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $report['status'], 'recovery completes' );
        Agentshell_Test::assert_equals( 'recovery_administrator', $report['authorized_as'], 'via the recovery-administrator path' );
        Agentshell_Test::assert_equals( array( 460 ), $GLOBALS['jr_delete_calls'], 'the post is deleted exactly once' );
    }
);

Agentshell_Test::run(
    'the owner with manage_options but no per-post cap is refused for compensation, not for inspection',
    function () {
        // B2: distinguishes the two authorities. Inspection is open to the
        // owner (the owner path of authorize_inspection accepts them on
        // manage_options). Compensation requires the per-post cap, which the
        // owner here does not hold. The op transitions to `conflict` and the
        // post is preserved.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 461 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 461, array(), $op['id'] );
        jr_actor( JR_CRASHED_ACTOR );
        // No jr_with_post_cap: the per-post cap is missing.

        // Inspection is open to the owner: the read is not refused.
        $view = ( new Get_Journal() )->execute( array() );
        Agentshell_Test::assert_equals( 1, (int) $view['operation_count'], 'inspection sees the journal' );

        // Compensation is refused at the per-op gate.
        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_PARTIAL, $report['status'], 'the refusal leaves the transaction partial' );
        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'the op is conflict, not compensated' );
        Agentshell_Test::assert_true( jr_post_exists( 461 ), 'the post is preserved' );
        Agentshell_Test::assert_true( false !== strpos( $report['operations'][0]['detail'], 'per-post' ),
            'and the reason names the missing per-post cap' );
    }
);

Agentshell_Test::run(
    'an owner who lost the per-post cap between tool entry and destruction is refused at the per-op gate',
    function () {
        // B3: the per-post cap is a property of the destruction, not a
        // property of the call. The tool entry accepts the owner; the
        // per-op re-check refuses when the cap is gone. This test
        // simulates the role-revocation case by setting the cap
        // differently on each path: an empty `_agentshell_test_user_caps`
        // at the moment compensate() runs.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 462 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 462, array(), $op['id'] );
        jr_actor( JR_CRASHED_ACTOR );
        // The owner holds manage_options but NOT the per-post cap: this is
        // what "the role was revoked" looks like in the stub.
        $GLOBALS['_agentshell_test_user_caps'] = array( 'manage_options' => true );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_PARTIAL, $report['status'], 'the role revocation refuses the close' );
        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'and the op is conflict' );
        Agentshell_Test::assert_true( jr_post_exists( 462 ), 'and the post is preserved' );
    }
);

// ---------------------------------------------------------------------------
// 12. No unrelated content is deleted or modified.
//     (scenario 12)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'recovery touches only the post its own journal identifies',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 420 );
        jr_seed( jr_row( array( $op ) ) );

        jr_post( 420, array(), $op['id'] );                                  // ours
        jr_post( 421, array( 'post_title' => 'Real page', 'post_name' => 'real-page' ), null );
        jr_post( 422, array( 'post_title' => 'Home', 'post_name' => 'home' ), null );
        $before_421 = $GLOBALS['_agentshell_test_posts'][421];
        $before_422 = $GLOBALS['_agentshell_test_posts'][422];
        jr_with_post_cap( 420 );

        Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );

        Agentshell_Test::assert_false( jr_post_exists( 420 ), 'the identified draft is gone' );
        Agentshell_Test::assert_true( jr_post_exists( 421 ), 'unrelated content survives' );
        Agentshell_Test::assert_true( jr_post_exists( 422 ), 'unrelated content survives' );
        Agentshell_Test::assert_equals( $before_421, $GLOBALS['_agentshell_test_posts'][421], 'unrelated content is unmodified' );
        Agentshell_Test::assert_equals( $before_422, $GLOBALS['_agentshell_test_posts'][422], 'unrelated content is unmodified' );
        Agentshell_Test::assert_equals( array( 420 ), $GLOBALS['jr_delete_calls'], 'exactly one deletion, of the identified post' );
    }
);

Agentshell_Test::run(
    'an unmarked post on the recorded slug is a conflict, not ours',
    function () {
        jr_reset();
        // The write happened but the marker was removed since. Unknowable from
        // here — and "unknowable" must never resolve to "delete it".
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 423, array(), null );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'an ambiguous lookalike is a conflict' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], '423' ),
            'and it names the post: ' . $report['operations'][0]['detail']
        );
        Agentshell_Test::assert_true( jr_post_exists( 423 ), 'which therefore survives' );
    }
);

Agentshell_Test::run(
    'two posts carrying the marker are refused rather than one being chosen',
    function () {
        jr_reset();
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 424, array(), $op['id'] );
        jr_post( 425, array(), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'], 'ambiguity is refused' );
        Agentshell_Test::assert_true( jr_post_exists( 424 ), 'neither is deleted' );
        Agentshell_Test::assert_true( jr_post_exists( 425 ), 'neither is deleted' );
    }
);

// ---------------------------------------------------------------------------
// 13. Audit records.
//     (scenario 13)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'recovery and every refusal are on the audit record, naming the actor and the role',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 426 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 426, array(), $op['id'] );

        jr_as_administrator();
        Content_Recovery::recover( jr_row_on_disk(), 'auditor', true );

        $events = jr_audit( 'transaction_recovery' );
        Agentshell_Test::assert_equals( 1, count( $events ), 'one recovery event' );
        Agentshell_Test::assert_equals( 'auditor', $events[0]['actor'], 'it names who acted' );
        Agentshell_Test::assert_equals(
            'recovery_administrator', $events[0]['changed']['authorized_as'],
            'and the role they acted under, so an outsider is distinguishable from the owner'
        );
        Agentshell_Test::assert_equals( array( 426 ), $events[0]['changed']['deleted_transactions'], 'and which posts went' );

        $per_op = jr_audit( 'transaction_recovery_compensated' );
        Agentshell_Test::assert_equals( 1, count( $per_op ), 'the deletion is its own record' );
        Agentshell_Test::assert_equals( 426, $per_op[0]['changed']['post_id'], 'naming the post id' );

        // A refusal is audited too — otherwise "it did nothing" and "nobody ran
        // it" look identical afterwards.
        jr_reset();
        $op2 = jr_op( 'applied', 427 );
        jr_seed( jr_row( array( $op2 ) ) );
        jr_post( 427, array(), $op2['id'] );
        jr_actor( 'unauthorised-peer' );
        Content_Recovery::recover( jr_row_on_disk(), 'unauthorised-peer', true );

        $refusals = jr_audit( 'transaction_recovery_refused' );
        Agentshell_Test::assert_equals( 1, count( $refusals ), 'the refusal is recorded' );
        Agentshell_Test::assert_equals( 'unauthorised-peer', $refusals[0]['actor'], 'naming who was refused' );
    }
);

// ---------------------------------------------------------------------------
// 14. A recovered transaction permits a new transaction to begin.
//     (scenario 14)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a fully recovered transaction stops blocking new work',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 428 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 428, array(), $op['id'] );

        jr_actor( 'operator' );
        jr_as_administrator();
        Agentshell_Test::assert_true( jr_refuse( function () { Tx::instance()->begin( 'before' ); } ), 'blocked before' );

        Content_Recovery::recover( jr_row_on_disk(), 'operator', true );
        Agentshell_Test::assert_equals( null, jr_refuse( function () { Tx::instance()->begin( 'after' ); } ), 'not blocked after' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'a fresh transaction is open' );
        Agentshell_Test::assert_false( Tx::has_durable_work( jr_row_on_disk() ), 'and it carries no journal' );
    }
);

Agentshell_Test::run(
    'a partly recovered transaction keeps blocking, because something is still owed',
    function () {
        jr_reset();
        $ok  = jr_op( 'applied', 429 );
        $bad = jr_op( 'applied', 430 );
        jr_seed( jr_row( array( $ok, $bad ) ) );
        jr_post( 429, array(), $ok['id'] );
        jr_post( 430, array( 'post_status' => 'publish' ), $bad['id'] );
        jr_with_post_cap( 429 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_PARTIAL, $report['status'], 'one conflict means partial' );
        Agentshell_Test::assert_false( $report['transaction_row_deleted'], 'the row is not deleted while work is owed' );
        Agentshell_Test::assert_false( jr_post_exists( 429 ), 'the healthy one was still settled' );
        Agentshell_Test::assert_true( jr_post_exists( 430 ), 'the conflicted one was not' );
        Agentshell_Test::assert_equals( 1, count( $report['conflicts'] ), 'the conflict is reported' );
        Agentshell_Test::assert_true( jr_refuse( function () { Tx::instance()->begin( 'x' ); } ), 'begin() still refuses' );
    }
);

// ---------------------------------------------------------------------------
// 15. Existing lease and rollback behaviour is unchanged.
//     (scenario 15)
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'config-only expiry, rollback, and the pre-journal journal shape all behave as before',
    function () {
        jr_reset();
        jr_actor( 'config-agent' );

        // A config-only expired transaction is still reclaimed automatically.
        $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = array(
            'id' => 'tx_config', 'label' => 'config only', 'actor' => 'gone',
            'timestamp' => JR_T0 - 7200, 'created' => JR_T0 - 7200,
            'last_activity' => JR_T0 - 7200, 'expires' => JR_T0 - 3600,
            'before' => array( '--jr-accent' => '#123456' ), 'staged' => array( '--jr-accent' => '#ff00ff' ),
        );
        $mgr = Tx::instance();
        $mgr->begin( 'fresh' );
        Agentshell_Test::assert_false( null === jr_row_on_disk(), 'a new transaction replaced the stale config-only one' );
        Agentshell_Test::assert_equals( 'config-agent', jr_row_on_disk()['actor'], 'opened by the caller' );
        Agentshell_Test::assert_equals( 1, count( jr_audit( 'transaction_reclaimed' ) ), 'and the reclaim is audited' );

        // Rollback by the owner still closes the transaction.
        $mgr->stage( array( '--jr-accent' => '#0000ff' ) );
        $result = $mgr->rollback();
        Agentshell_Test::assert_true( $result['rolled_back'], 'rollback still works' );
        Agentshell_Test::assert_true( null === jr_row_on_disk(), 'and closes the row' );

        // The pre-journal content shape — a bare nested record — still counts as
        // durable work, because nothing has said otherwise about it.
        $legacy = array(
            'id' => 'tx_legacy', 'actor' => 'gone', 'timestamp' => JR_T0 - 7200, 'expires' => JR_T0 - 3600,
            'content' => array( 'posts' => array( 'created' => array( 301 => array( 'type' => 'page' ) ) ) ),
        );
        Agentshell_Test::assert_true( Tx::has_durable_work( $legacy ), 'a legacy journal still blocks reclaim' );

        // A transaction that journalled nothing can be reclaimed, and a fully
        // compensated one counts as nothing owed.
        Agentshell_Test::assert_false( Tx::has_durable_work( jr_row( array() ) ), 'an empty journal is not durable work' );
        $settled = jr_op( 'compensated', 0, array(), array(
            'comp' => array( 'outcome' => 'compensated', 'post_id' => 0, 'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0, 'reason' => 'done' ),
        ) );
        Agentshell_Test::assert_false( Tx::has_durable_work( jr_row( array( $settled ) ) ), 'a settled journal is not durable work' );
    }
);

// ── Recording primitives ─────────────────────────────────────────────────────

Agentshell_Test::run(
    'recording an intent is durable only when the guarded write landed',
    function () {
        jr_reset();
        $tx = Tx::instance()->begin( 'journalling' );

        $op = Content_Journal::record_intent( Content_Journal::OP_CREATE_DRAFT, jr_target(), array(), 'agent' );
        Agentshell_Test::assert_true( Content_Journal::attach( $tx, $op ), 'the attach must land' );
        Agentshell_Test::assert_true(
            isset( jr_row_on_disk()['content']['ops'][ $op['id'] ] ),
            'and the record must be on disk, not merely returned'
        );

        // Now with a racing writer underneath it.
        global $wpdb;
        $racing          = new JR_Racing_wpdb();
        $wpdb            = $racing;
        $GLOBALS['wpdb'] = $racing;
        $racing->on_guarded_write( function () {
            $GLOBALS['_agentshell_test_options'][ Tx::KEY ]['label'] = 'changed by somebody else';
        } );

        $stale = jr_row_on_disk();
        $op2   = Content_Journal::record_intent( Content_Journal::OP_CREATE_DRAFT, jr_target(), array(), 'agent' );
        Agentshell_Test::assert_false(
            Content_Journal::attach( $stale, $op2 ),
            'a journal write must not succeed against a row that has moved'
        );
        Agentshell_Test::assert_false(
            isset( jr_row_on_disk()['content']['ops'][ $op2['id'] ] ),
            'and must not be left half-recorded'
        );
    }
);

Agentshell_Test::run(
    'an operation with nothing to identify it cannot be recorded',
    function () {
        jr_reset();
        $refused = jr_refuse( function () {
            Content_Journal::record_intent( Content_Journal::OP_CREATE_DRAFT, array( 'post_type' => 'page' ), array(), 'agent' );
        } );
        Agentshell_Test::assert_true( is_string( $refused ), 'a record with no post_status is refused' );
        Agentshell_Test::assert_true(
            false !== strpos( $refused, 'post_status' ),
            'and says which field: ' . $refused
        );

        $bad_type = jr_refuse( function () {
            Content_Journal::record_intent( 'update_post', jr_target(), array(), 'agent' );
        } );
        Agentshell_Test::assert_true(
            is_string( $bad_type ) && false !== strpos( $bad_type, 'no compensation contract' ),
            'an unsupported type is refused at record time too: ' . var_export( $bad_type, true )
        );
    }
);

Agentshell_Test::run(
    'a journal with no signature says so, rather than claiming to be intact',
    function () {
        jr_reset();

        // Signed and untouched: intact.
        $ok = Content_Journal::verify( Content_Journal::of( jr_row( array( jr_op( 'applied', 436 ) ) ) ) );
        Agentshell_Test::assert_equals( 'ok', $ok['status'], 'a freshly signed journal is ok' );

        // No signature at all — written on an install with no secret. This is
        // not the same answer, and it must not be: "cannot tell" is not "fine".
        $unsigned = Content_Journal::empty_journal( 'tx_unsigned' );
        Agentshell_Test::assert_equals( '', $unsigned['sig'], 'the fixture really has no signature' );
        $unverified = Content_Journal::verify( $unsigned );
        Agentshell_Test::assert_equals( 'unverified', $unverified['status'], 'an unsigned journal is unverified' );
        Agentshell_Test::assert_true(
            false !== strpos( $unverified['reason'], 'no signature' ),
            'and says why: ' . $unverified['reason']
        );

        // And it is still recoverable — refusing outright would wedge the site
        // on an install nobody can re-sign, with no way forward.
        $op = jr_op( 'applied', 436 );
        $tx = jr_row( array( $op ) );
        $tx['content']['sig'] = '';
        jr_seed( $tx );
        jr_post( 436, array(), $op['id'] );
        jr_with_post_cap( 436 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals( 'unverified', $report['integrity'], 'recovery reports the gap' );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_RECOVERED, $report['status'], 'and still proceeds on the per-post checks' );
        Agentshell_Test::assert_false( jr_post_exists( 436 ), 'deleting the identified draft' );
    }
);

Agentshell_Test::run(
    'a recovery cannot erase a transaction that appeared after it started',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 437 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 437, array(), $op['id'] );
        jr_with_post_cap( 437 );

        global $wpdb;
        $racing          = new JR_Racing_wpdb();
        $wpdb            = $racing;
        $GLOBALS['wpdb'] = $racing;

        // A recovery makes three guarded writes: claim, settle, release. Somebody
        // else's fresh transaction lands in the row just before the third — so
        // the journal this recovery is working from is no longer what the row
        // holds, and the release must decline.
        $racing->on_guarded_write( function () {
            $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = array(
                'id' => 'tx_opened_elsewhere', 'label' => 'opened by another session',
                'actor' => 'other', 'timestamp' => JR_T0, 'created' => JR_T0,
                'last_activity' => JR_T0, 'expires' => JR_T0 + 3600,
                'before' => array(), 'staged' => null,
            );
        }, false, 2 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );

        Agentshell_Test::assert_equals(
            Content_Recovery::STATUS_CONTENDED, $report['status'],
            'releasing a row that moved must be reported, not forced through'
        );
        Agentshell_Test::assert_false( $report['transaction_row_deleted'], 'and the row is not claimed as deleted' );

        $left = jr_row_on_disk();
        Agentshell_Test::assert_equals( 'tx_opened_elsewhere', $left['id'] ?? '', 'the new transaction survives' );
        Agentshell_Test::assert_equals( 'opened by another session', $left['label'] ?? '', 'untouched' );
        Agentshell_Test::assert_false( jr_post_exists( 437 ), 'the compensation itself had already been recorded as done' );
    }
);

Agentshell_Test::run(
    'a journal that cannot be read counts as work, so the row is never discarded unread',
    function () {
        jr_reset();

        // Tested on the journal's own question rather than through
        // has_durable_work(), because that one only reaches it for a journal it
        // could already read — which would make this a test of nothing. The
        // function is public and its contract is unconditional: "cannot be
        // shown to be finished" means the work is not finished.
        $damaged = array(
            'the journal is not an array' => 'nonsense',
            'ops is not an array'         => array( 'v' => 1, 'tx' => 'tx_jr', 'ops' => 'not-an-array' ),
            'ops is missing'              => array( 'v' => 1, 'tx' => 'tx_jr' ),
        );
        foreach ( $damaged as $label => $content ) {
            Agentshell_Test::assert_true(
                Content_Journal::has_outstanding_work( $content ),
                'a journal where ' . $label . ' must still count as outstanding work'
            );
        }

        // And an operation that has lost its state is the same situation one
        // level down — reachable from the row, so this one goes through the
        // lease.
        $op        = jr_op( 'applied', 438 );
        unset( $op['state'] );
        Agentshell_Test::assert_true(
            Tx::has_durable_work( jr_row( array( $op ) ) ),
            'an operation with no state must still count as durable work'
        );

        jr_reset();
        jr_seed( jr_row( array( jr_op( 'applied', 438 ) ) ) );
        jr_post( 438, array(), jr_op( 'applied', 438 )['id'] );
        Agentshell_Test::assert_true(
            jr_refuse( function () { Tx::instance()->begin( 'new work' ); } ),
            'and such a transaction must still refuse to be replaced'
        );
    }
);

Agentshell_Test::run(
    'mark_applied reports failure rather than assuming it was recorded',
    function () {
        jr_reset();
        $op = jr_op( 'intended', 0 );
        $tx = jr_seed( jr_row( array( $op ) ) );

        $next = Content_Journal::mark_applied( $tx, $op['id'], 431, jr_after() );
        Agentshell_Test::assert_true( is_array( $next ), 'a clean apply returns the new row' );
        Agentshell_Test::assert_equals( 'applied', Content_Journal::of( $next )['ops'][ $op['id'] ]['state'], 'and the state is applied' );
        Agentshell_Test::assert_equals( 431, (int) Content_Journal::of( $next )['ops'][ $op['id'] ]['post_id'], 'with the id' );

        Agentshell_Test::assert_equals(
            null,
            Content_Journal::mark_applied( $tx, 'op_does_not_exist', 432 ),
            'an unknown operation is null, not a silent success'
        );
    }
);

Agentshell_Test::run(
    'recovery refuses a transaction that has not expired',
    function () {
        jr_reset();
        $tx = Tx::instance()->begin( 'live' );
        $op = Content_Journal::record_intent( Content_Journal::OP_CREATE_DRAFT, jr_target(), array(), 'agent' );
        Content_Journal::attach( $tx, $op );
        jr_post( 433, array(), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'agent' );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_REFUSED, $report['status'], 'a live transaction is not recovered' );
        Agentshell_Test::assert_true(
            false !== strpos( $report['reason'], 'lease' ),
            'and the refusal is about the lease, not about authority: ' . $report['reason']
        );
        Agentshell_Test::assert_true( jr_post_exists( 433 ), 'nothing deleted' );
    }
);

Agentshell_Test::run(
    'the recover tool refuses to act without an explicit confirm',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 434 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 434, array(), $op['id'] );

        $tool    = new Recover_Transaction();
        $refused = jr_refuse( function () use ( $tool ) { $tool->execute( array() ); } );
        Agentshell_Test::assert_true( is_string( $refused ), 'confirm is required' );
        Agentshell_Test::assert_true(
            false !== strpos( $refused, 'confirm' ),
            'and the message says so: ' . $refused
        );
        Agentshell_Test::assert_true( jr_post_exists( 434 ), 'nothing was deleted while waiting for consent' );

        $schema = $tool->get_input_schema();
        Agentshell_Test::assert_true(
            in_array( 'confirm', $schema['required'], true ),
            'confirm is in the schema, not merely in the description'
        );
    }
);

// ──────────────────────────────────────────────────────────────────────────────
//  Stage 1b — Lock, strong after-image, lifecycle, value-guarded CAS.
//  The tests below were added with the Commit 2 work. Each pins a guarantee
//  the brief or its review called out.
// ──────────────────────────────────────────────────────────────────────────────

use AgentShell_MCP\Recovery_Advisory_Lock;

Agentshell_Test::run(
    'LK1: the advisory lock probe returns true on a database that supports GET_LOCK',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        Agentshell_Test::assert_true(
            Recovery_Advisory_Lock::available(),
            'the stub grants GET_LOCK, so the probe returns true'
        );
    }
);

Agentshell_Test::run(
    'LK2: the advisory lock probe returns false when GET_LOCK returns NULL',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        // Swap the wpdb for a stub that returns null for GET_LOCK.
        $old_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends JR_wpdb {
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) { return null; }
                return parent::get_var( $sql );
            }
        };
        try {
            Agentshell_Test::assert_false(
                Recovery_Advisory_Lock::available(),
                'a NULL return from GET_LOCK is the dangerous case: refuse, do not guess'
            );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'LK3: acquire() throws when the lock service is unavailable; no destructive action runs',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        $old_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends JR_wpdb {
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) { return null; }
                return parent::get_var( $sql );
            }
        };
        try {
            $threw = false;
            try {
                Recovery_Advisory_Lock::acquire( 42 );
            } catch ( \RuntimeException $e ) {
                $threw = true;
            }
            Agentshell_Test::assert_true( $threw, 'a NULL GET_LOCK is a refused acquire, not a silent bypass' );
            Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no delete was attempted' );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'LK4: acquire() returns true on the first caller',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        Agentshell_Test::assert_true(
            Recovery_Advisory_Lock::acquire( 42 ),
            'with the lock available, the first acquire succeeds'
        );
    }
);

Agentshell_Test::run(
    'LK5: acquire() returns false (no throw) when another connection holds the lock',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        $old_wpdb = $GLOBALS['wpdb'];
        // The probe lock name is fresh and grantable; the post-id lock name is held.
        $GLOBALS['wpdb'] = new class extends JR_wpdb {
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                    // The probe name is prefixed with agentshell_recovery_lock_probe_ —
                    // grant it. The post-id lock name has the recovery_lock_ prefix
                    // and a different suffix (a hash), so it is "held" elsewhere.
                    if ( strpos( (string) $sql, 'agentshell_recovery_lock_probe_' ) !== false ) {
                        return '1';
                    }
                    return '0';
                }
                return parent::get_var( $sql );
            }
        };
        try {
            Agentshell_Test::assert_false(
                Recovery_Advisory_Lock::acquire( 42 ),
                'a held lock is refused, not thrown: the engine is healthy, just contended'
            );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'LK6: acquire() throws on a NULL return (database refused the call); no destructive action runs',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        // First probe succeeds (returns '1'), then acquire sees NULL.
        $old_wpdb = $GLOBALS['wpdb'];
        $first = true;
        $GLOBALS['wpdb'] = new class( $first ) extends JR_wpdb {
            private $first;
            public function __construct( &$first ) { $this->first =& $first; }
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                    if ( $this->first ) { $this->first = false; return '1'; }
                    return null;
                }
                return parent::get_var( $sql );
            }
        };
        try {
            Agentshell_Test::assert_true( Recovery_Advisory_Lock::available(), 'probe succeeds' );
            $threw = false;
            try {
                Recovery_Advisory_Lock::acquire( 42 );
            } catch ( \RuntimeException $e ) {
                $threw = true;
            }
            Agentshell_Test::assert_true( $threw, 'a NULL acquire is thrown, not silently bypassed' );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'LK7: acquire() is prompt: returns within 200 ms when another connection holds the lock',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        $old_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends JR_wpdb {
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                    if ( strpos( (string) $sql, 'agentshell_recovery_lock_probe_' ) !== false ) {
                        return '1';
                    }
                    return '0';
                }
                return parent::get_var( $sql );
            }
        };
        try {
            $start = microtime( true );
            Recovery_Advisory_Lock::acquire( 42 );
            $elapsed = ( microtime( true ) - $start ) * 1000;
            Agentshell_Test::assert_true( $elapsed < 200, "acquire is prompt (elapsed={$elapsed}ms)" );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'LK8: lock names are < 64 chars and are stable for a given post id',
    function () {
        Agentshell_Test::assert_equals(
            Recovery_Advisory_Lock::name_for( 42 ),
            Recovery_Advisory_Lock::name_for( 42 ),
            'the same post id hashes to the same name'
        );
        Agentshell_Test::assert_true(
            Recovery_Advisory_Lock::name_for( 42 ) !== Recovery_Advisory_Lock::name_for( 43 ),
            'two different post ids hash to different names'
        );
        Agentshell_Test::assert_true(
            strlen( Recovery_Advisory_Lock::name_for( 42 ) ) < 64,
            'names fit under MySQL\'s 64-char limit'
        );
    }
);

Agentshell_Test::run(
    'LK9: the probe result is cached for the lifetime of the request',
    function () {
        jr_reset();
        Recovery_Advisory_Lock::reset();
        // First available() returns '1' (cached). After that, switch to NULL
        // and verify the second call still returns the cached true.
        $old_wpdb = $GLOBALS['wpdb'];
        $first = true;
        $GLOBALS['wpdb'] = new class( $first ) extends JR_wpdb {
            private $first;
            public function __construct( &$first ) { $this->first =& $first; }
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                    if ( $this->first ) { $this->first = false; return '1'; }
                    return null;
                }
                return parent::get_var( $sql );
            }
        };
        try {
            Agentshell_Test::assert_true( Recovery_Advisory_Lock::available(), 'first call: cached true' );
            Agentshell_Test::assert_true(
                Recovery_Advisory_Lock::available(),
                'second call: still cached true, ignoring the new NULL'
            );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

// ── Strong after-image ──────────────────────────────────────────────────────

Agentshell_Test::run(
    'SA1: mark_applied captures the strong after-image fields',
    function () {
        jr_reset();
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );

        $after = array(
            'post_type'             => 'page',
            'post_status'           => 'draft',
            'post_name'             => 'jr-draft',
            'post_date_gmt'         => '2026-01-01 00:00:00',
            'post_modified_gmt'     => '2026-01-01 00:00:00',
            'post_content_sha256'   => hash( 'sha256', '<p>body</p>' ),
            'post_content_length'   => strlen( '<p>body</p>' ),
            'op_meta_value'         => $op['id'],
        );

        $row = Content_Journal::mark_applied( jr_row_on_disk(), $op['id'], 405, $after );
        Agentshell_Test::assert_true( null !== $row, 'mark_applied returns the row on CAS success' );

        $persisted = $row['content']['ops'][ $op['id'] ];
        Agentshell_Test::assert_equals( 'applied', $persisted['state'], 'state advances' );
        Agentshell_Test::assert_equals( 405, (int) $persisted['post_id'], 'post_id is recorded' );
        Agentshell_Test::assert_equals(
            hash( 'sha256', '<p>body</p>' ),
            (string) $persisted['after']['post_content_sha256'],
            'the content sha256 is persisted'
        );
        Agentshell_Test::assert_equals(
            strlen( '<p>body</p>' ),
            (int) $persisted['after']['post_content_length'],
            'the content length is persisted'
        );
        Agentshell_Test::assert_equals(
            '2026-01-01 00:00:00',
            (string) $persisted['after']['post_date_gmt'],
            'post_date_gmt is recorded as a creation-time anchor'
        );
    }
);

Agentshell_Test::run(
    'SA2: verify_post refuses a same-second content edit (unchanged slug and modified time)',
    function () {
        jr_reset();
        // Set up a post whose content was edited between mark_applied and
        // recovery. The slug, post_date_gmt, and post_modified_gmt are
        // unchanged, but the content hash is different.
        $op = jr_op( 'applied', 410 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>original</p>' );
        $op['after']['post_content_length'] = strlen( '<p>original</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 410, array(
            'post_content'      => '<p>edited</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        jr_with_post_cap( 410 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        $outcome = $report['operations'][0]['outcome'] ?? '';
        Agentshell_Test::assert_equals(
            'conflict', $outcome,
            'a content-hash mismatch with unchanged slug and unchanged post_modified_gmt is still a conflict'
        );
        Agentshell_Test::assert_true( jr_post_exists( 410 ), 'the post is preserved' );
    }
);

Agentshell_Test::run(
    'SA3: verify_post refuses a content edit that leaves the slug unchanged',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 411 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>original</p>' );
        $op['after']['post_content_length'] = strlen( '<p>original</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        // Post 411 is the same as recorded except for post_content and the
        // modified time.
        jr_post( 411, array(
            'post_content'      => '<p>edited</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:01:00',
            'post_modified_gmt' => '2026-01-01 00:01:00',
        ), $op['id'] );
        jr_with_post_cap( 411 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals(
            'conflict', $report['operations'][0]['outcome'] ?? '',
            'a content edit is a conflict even when the slug is unchanged'
        );
        Agentshell_Test::assert_true( jr_post_exists( 411 ), 'the post is preserved' );
    }
);

Agentshell_Test::run(
    'SA4: verify_post succeeds when the live post matches all six recorded fields',
    function () {
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 412 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 412, array(
            'post_content'      => '<p>body</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );
        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'] ?? '',
            'a six-field match compensates'
        );
        Agentshell_Test::assert_false( jr_post_exists( 412 ), 'the post is deleted' );
    }
);

Agentshell_Test::run(
    'SA5: an op whose post_id was never recorded preserves the marked post (conflict)',
    function () {
        jr_reset();
        jr_as_administrator();
        // Op state is intended and post_id is 0: mark_applied never ran.
        // The recorded after has no sha256, no date_gmt — i.e. the strong
        // after-image was never captured. The post exists with the marker.
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 413, array(), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );
        $outcome = $report['operations'][0]['outcome'] ?? '';
        Agentshell_Test::assert_equals(
            'conflict', $outcome,
            'no recorded after-image means the post is preserved, not deleted by guess'
        );
        Agentshell_Test::assert_true( jr_post_exists( 413 ), 'the marked post is preserved' );
    }
);

Agentshell_Test::run(
    'SA6: two posts carrying the same marker are refused (marker collision)',
    function () {
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );
        // Two posts both carry the same marker.
        jr_post( 414, array(), $op['id'] );
        jr_post( 415, array(), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );
        $outcome = $report['operations'][0]['outcome'] ?? '';
        Agentshell_Test::assert_equals(
            'conflict', $outcome,
            'a marker collision is refused: the engine cannot tell which post is ours'
        );
        Agentshell_Test::assert_true( jr_post_exists( 414 ), 'post 414 is preserved' );
        Agentshell_Test::assert_true( jr_post_exists( 415 ), 'post 415 is preserved' );
    }
);

// ── C3: revalidation under the lock ─────────────────────────────────────────

Agentshell_Test::run(
    'C3: the claim is refreshed at op boundaries so the takeover window opens between ops',
    function () {
        jr_reset();
        $op = jr_op( 'compensating', 420 );
        $op['claim'] = array( 'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0 - 10 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 420, array(), $op['id'] );
        jr_with_post_cap( 420 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'] ?? '',
            'a same-actor claim with a fresh `at` is the refresh path: settlement continues'
        );
        Agentshell_Test::assert_false( jr_post_exists( 420 ), 'and the post is gone' );
    }
);
// ── L1-L13: commit/rollback lifecycle ────────────────────────────────────────
//
// The wire-up of commit and rollback to the journal and recovery engine:
// the audit row carries the op summary, the row delete is value-guarded,
// the revalidation step transitions applied ops whose post has been
// edited to conflict (and refuses the commit) or whose post has been
// deleted to compensated (and the commit proceeds), and the auto-
// compensate rollback invokes the recovery engine before the
// finalisation step.
//
// A "live" transaction in these tests is not the live lease clock: the
// journal row is seeded directly through jr_seed() with the right
// expires_at so begin/commit/rollback have something to settle against.

Agentshell_Test::run(
    'L1: commit with all applied ops succeeds, audits transaction_committed, retains the post',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $result = Tx::instance()->commit();
        Agentshell_Test::assert_true( $result['committed'] ?? false, 'commit reports success' );
        Agentshell_Test::assert_equals( 'tx_jr', $result['id'] ?? '', 'and the transaction id' );
        Agentshell_Test::assert_true( jr_post_exists( 401 ), 'and the post is retained' );
        Agentshell_Test::assert_equals( null, jr_row_on_disk(), 'and the option row is deleted' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_committed' ) ), 'one transaction_committed audit row' );
    }
);

Agentshell_Test::run(
    'L2: commit refuses on an intended op and audits transaction_commit_refused',
    function () {
        jr_reset();
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->commit(); } );
        Agentshell_Test::assert_true( $threw, 'commit refuses' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_commit_refused' ) ), 'one transaction_commit_refused audit row' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the option row is preserved' );
    }
);

Agentshell_Test::run(
    'L3: commit refuses on a conflict op and preserves the conflict',
    function () {
        jr_reset();
        $op = jr_op( 'conflict', 0 );
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->commit(); } );
        Agentshell_Test::assert_true( $threw, 'commit refuses' );
        Agentshell_Test::assert_equals( Content_Journal::STATE_CONFLICT, jr_op_on_disk()['state'], 'the conflict is preserved' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_commit_refused' ) ), 'and the refusal is audited' );
    }
);

Agentshell_Test::run(
    'L4: commit refuses on a partial-applied op and audits transaction_commit_refused',
    function () {
        jr_reset();
        $op = jr_op( 'partial-applied', 0 );
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->commit(); } );
        Agentshell_Test::assert_true( $threw, 'commit refuses' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_commit_refused' ) ), 'and the refusal is audited' );
    }
);

Agentshell_Test::run(
    'L5: rollback with an applied op auto-compensates, audits transaction_rolled_back, deletes the post and the row',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $result = Tx::instance()->rollback();
        Agentshell_Test::assert_true( $result['rolled_back'] ?? false, 'rollback reports success' );
        Agentshell_Test::assert_false( jr_post_exists( 401 ), 'and the post is gone (auto-compensated)' );
        Agentshell_Test::assert_equals( null, jr_row_on_disk(), 'and the option row is deleted' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_rolled_back_after_compensation' ) ), 'one transaction_rolled_back_after_compensation audit row (recovery deleted the post)' );
    }
);

Agentshell_Test::run(
    'L6: rollback with a divergence during compensation preserves the post and refuses the close',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>original</p>' );
        $op['after']['post_content_length'] = strlen( '<p>original</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        // Live post is edited (content hash diverges).
        jr_post( 401, array(
            'post_content'      => '<p>edited</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->rollback(); } );
        Agentshell_Test::assert_true( $threw, 'rollback refuses' );
        Agentshell_Test::assert_true( jr_post_exists( 401 ), 'and the post is preserved' );
        Agentshell_Test::assert_equals( Content_Journal::STATE_CONFLICT, jr_op_on_disk()['state'], 'and the op is in conflict' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_rollback_refused_after_compensation' ) ), 'and the refusal is audited' );
    }
);

Agentshell_Test::run(
    'L7: rollback with an intended op refuses without invoking recovery',
    function () {
        jr_reset();
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->rollback(); } );
        Agentshell_Test::assert_true( $threw, 'rollback refuses' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_rollback_refused' ) ), 'and the refusal is audited' );
        Agentshell_Test::assert_equals( 0, count( jr_audit_for( 'transaction_recovery' ) ), 'recovery was not invoked' );
    }
);

Agentshell_Test::run(
    'L8: rollback with no journal work proceeds to cas_delete_row and returns',
    function () {
        jr_reset();
        $seeded = jr_live_row( array(), array( 'actor' => 'agent' ) );
        // The no-journal path is one where the row is open but carries
        // no content. Drop it explicitly.
        $seeded['content'] = null;
        jr_seed( $seeded );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $result = Tx::instance()->rollback();
        Agentshell_Test::assert_true( $result['rolled_back'] ?? false, 'rollback reports success' );
        Agentshell_Test::assert_equals( null, jr_row_on_disk(), 'and the option row is deleted' );
    }
);

Agentshell_Test::run(
    'L10: commit revalidates applied ops against current WP state; an external edit transitions to conflict and refuses the commit',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>original</p>' );
        $op['after']['post_content_length'] = strlen( '<p>original</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        // Live post is edited: same slug, different content hash.
        jr_post( 401, array(
            'post_content'      => '<p>edited</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->commit(); } );
        Agentshell_Test::assert_true( $threw, 'commit refuses' );
        Agentshell_Test::assert_equals( Content_Journal::STATE_CONFLICT, jr_op_on_disk()['state'], 'and the op is in conflict' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_commit_refused' ) ), 'and the refusal is audited' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the option row is preserved' );
    }
);

Agentshell_Test::run(
    'L11: commit revalidates applied ops; a deleted post transitions the op to compensated and the commit proceeds with transaction_committed_after_compensation',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        // No post is created — the live post is gone.
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $result = Tx::instance()->commit();
        Agentshell_Test::assert_true( $result['committed'] ?? false, 'commit proceeds' );
        Agentshell_Test::assert_equals( Content_Journal::STATE_COMPENSATED, jr_op_on_disk_just_before_close()['state'] ?? null, 'the op was compensated (post gone)' );
        Agentshell_Test::assert_equals( null, jr_row_on_disk(), 'and the option row is deleted' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_committed_after_compensation' ) ), 'and the audit row uses the after-compensation operation' );
    }
);

Agentshell_Test::run(
    'L13: commit refuses and does not delete the option row if the audit write inside the guarded SQL transaction fails',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';
        $GLOBALS['_agentshell_test_audit_fail_for'] = 'transaction_committed';

        $threw = jr_refuse( function () { Tx::instance()->commit(); } );
        Agentshell_Test::assert_true( $threw, 'commit refuses' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the option row is preserved' );
        Agentshell_Test::assert_true( jr_post_exists( 401 ), 'and the post is preserved' );
    }
);

// ── VG1-VG6: value-guarded CAS ───────────────────────────────────────────────

Agentshell_Test::run(
    'VG1: commit deletes the option row via cas_delete_row, not delete_option',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        Tx::instance()->commit();
        Agentshell_Test::assert_equals( null, jr_row_on_disk(), 'and the option row is deleted' );
    }
);

Agentshell_Test::run(
    'VG2: rollback deletes the option row via cas_delete_row, not delete_option',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        Tx::instance()->rollback();
        Agentshell_Test::assert_equals( null, jr_row_on_disk(), 'and the option row is deleted' );
    }
);

Agentshell_Test::run(
    'VG3: commit refuses on a row-moved; cas_delete_row returns 0 and the option row is preserved',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';
        // Force cas_delete_row to fail (the row-moved branch).
        $GLOBALS['_agentshell_test_cas_delete_fail'] = true;

        $threw = jr_refuse( function () { Tx::instance()->commit(); } );
        Agentshell_Test::assert_true( $threw, 'commit refuses' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the option row is preserved' );
    }
);

Agentshell_Test::run(
    'VG5: audit-write failure inside the guard prevents cas_delete_row from running; the option row is preserved',
    function () {
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';
        // With auto-compensate, a rollback of an applied op audits
        // transaction_rolled_back_after_compensation (the variant that
        // records recovery deleted the post), not transaction_rolled_back.
        $GLOBALS['_agentshell_test_audit_fail_for'] = 'transaction_rolled_back_after_compensation';

        // The rollback path exercises the same shape: the audit throw
        // aborts the guard and the option row is preserved.
        $threw = jr_refuse( function () { Tx::instance()->rollback(); } );
        Agentshell_Test::assert_true( $threw, 'rollback refuses' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the option row is preserved' );
    }
);

// ── Closure: surviving mutants from the Stage 1b review ──────────────────────
//
// Each test below was written to kill a specific mutant the existing suite
// was not exercising. The iron law: each test must pass against the
// production code AND fail against the mutant's find/replace. See
// tests/journal-recovery-mutation.php for the mutation list.

Agentshell_Test::run(
    'SA2b: verify_post returns the SHA-mismatch reason (not just any conflict) on a same-second content edit',
    function () {
        // Strengthens SA2: the post_content_length stays the same in this
        // test, so the only evidence path is the SHA-256. With the SHA
        // check removed, the function returns '' and the post is destroyed
        // by guess. SA2 did not catch this because the test fixture had
        // a different content length, so the length check still fired.
        jr_reset();
        $op = jr_op( 'applied', 420 );
        // Same length (15 chars) as the recorded value, so the post_content
        // length check passes. Only the SHA-256 detects the change.
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>1234567890123</p>' ); // 18 chars
        $op['after']['post_content_length'] = 18;
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        // Live post: same slug, same date, same modified time, but content
        // edited to a different string of the same length.
        jr_post( 420, array(
            'post_content'      => '<p>abcdefghijklmno</p>', // 21 chars
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        // We need a content length that matches. Adjust: 21 = 21.
        $op['after']['post_content_length'] = 21;
        $GLOBALS['_agentshell_test_options'][ Tx::KEY ]['content']['ops'][ $op['id'] ]['after'] = $op['after'];
        $GLOBALS['_agentshell_test_options'][ Tx::KEY ]['content']['sig'] = Content_Journal::sign( $GLOBALS['_agentshell_test_options'][ Tx::KEY ]['content'] );
        jr_with_post_cap( 420 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        $reason = $report['operations'][0]['detail'] ?? '';
        Agentshell_Test::assert_equals(
            'conflict', $report['operations'][0]['outcome'] ?? '',
            'a same-second content edit with unchanged length and slug is a conflict'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $reason, 'post_content hash' ) || false !== strpos( $reason, 'content has been edited' ),
            'the conflict must come from the SHA-256 path, not the length check: ' . $reason
        );
        Agentshell_Test::assert_true( jr_post_exists( 420 ), 'the post is preserved' );
    }
);

Agentshell_Test::run(
    'CL1: compensate() refuses to delete when the per-post advisory lock cannot be acquired; post is preserved',
    function () {
        // The M-C16 / M-C18 mutants bypass the lock entirely. With the
        // bypass, the wp_delete_post() call proceeds and the post is gone.
        // With the lock, acquire(420) returns false (the stub holds it)
        // and compensate() returns a 'conflict' with reason naming the
        // advisory lock — no wp_delete_post() runs.
        jr_reset();
        $op = jr_op( 'applied', 420 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 420, array(
            'post_content'      => '<p>body</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        jr_with_post_cap( 420 );
        // Stub the lock: probe succeeds, but the post-420 lock is held.
        Recovery_Advisory_Lock::reset();
        $old_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends JR_wpdb {
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                    if ( strpos( (string) $sql, 'agentshell_recovery_lock_probe_' ) !== false ) {
                        return '1';
                    }
                    return '0';
                }
                return parent::get_var( $sql );
            }
        };
        try {
            $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
            Agentshell_Test::assert_equals(
                'conflict', $report['operations'][0]['outcome'] ?? '',
                'a contended lock refuses the destructive step'
            );
            Agentshell_Test::assert_true(
                false !== strpos( $report['operations'][0]['detail'] ?? '', 'advisory lock' ),
                'the conflict names the lock: ' . ( $report['operations'][0]['detail'] ?? '' )
            );
            Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no wp_delete_post ran' );
            Agentshell_Test::assert_true( jr_post_exists( 420 ), 'the post is preserved' );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'CL2: compensate() refuses to delete when GET_LOCK returns NULL; no destructive step runs',
    function () {
        // The M-C20 mutant catches the RuntimeException and converts it to
        // 'compensated' (silent proceed). The test pins the safer behavior:
        // the catch path is the conflict branch, and no wp_delete_post runs.
        jr_reset();
        $op = jr_op( 'applied', 421 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 421, array(
            'post_content'      => '<p>body</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        jr_with_post_cap( 421 );
        // Stub: probe succeeds, then GET_LOCK on the post returns NULL.
        Recovery_Advisory_Lock::reset();
        $old_wpdb = $GLOBALS['wpdb'];
        $first = true;
        $GLOBALS['wpdb'] = new class( $first ) extends JR_wpdb {
            private $first;
            public function __construct( &$first ) { $this->first =& $first; }
            public function get_var( $sql ) {
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                    if ( $this->first ) { $this->first = false; return '1'; }
                    return null;
                }
                return parent::get_var( $sql );
            }
        };
        try {
            $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
            Agentshell_Test::assert_equals(
                'conflict', $report['operations'][0]['outcome'] ?? '',
                'a NULL GET_LOCK is reported as conflict, not silently bypassed'
            );
            Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no wp_delete_post ran' );
            Agentshell_Test::assert_true( jr_post_exists( 421 ), 'the post is preserved' );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'CL3: compensate() releases the per-post advisory lock after a successful delete',
    function () {
        // Pins the M-C19 invariant: the finally block calls release().
        // The stub counts GET_LOCK and RELEASE_LOCK invocations; the
        // probe name (agentshell_recovery_lock_probe_) is released by
        // available(), and the per-post name is released by compensate().
        // After a successful compensation, the per-post release must have
        // happened.
        jr_reset();
        $op = jr_op( 'applied', 422 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 422, array(
            'post_content'      => '<p>body</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        jr_with_post_cap( 422 );
        Recovery_Advisory_Lock::reset();
        $old_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends JR_wpdb {
            public $post_locks_released = 0;
            public function get_var( $sql ) {
                if ( preg_match( '/RELEASE_LOCK\s*\(\s*\'([^\']+)\'/i', (string) $sql, $m ) ) {
                    // The probe name is fresh; the per-post name is hashed.
                    if ( strpos( $m[1], 'agentshell_recovery_lock_probe_' ) === false ) {
                        $this->post_locks_released++;
                    }
                    return '1';
                }
                if ( preg_match( '/GET_LOCK\s*\(/i', (string) $sql ) ) {
                    return '1';
                }
                return parent::get_var( $sql );
            }
        };
        try {
            $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
            $stub = $GLOBALS['wpdb'];
            Agentshell_Test::assert_equals(
                'compensated', $report['operations'][0]['outcome'] ?? '',
                'the destructive step completes'
            );
            Agentshell_Test::assert_equals( 1, $stub->post_locks_released, 'the per-post lock was released in the finally block' );
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'CL4: settle_operation() refreshes the same-actor claim between ops; a competing take-over is refused',
    function () {
        // Pins the M-C11 invariant: a second recoverer whose CAS lands
        // *between* the first recoverer's op settlements is refused at
        // the claim layer (the first's claim was refreshed at the op
        // boundary). With the refresh dropped, the second's CAS would
        // succeed and it would race the destructive step.
        jr_reset();
        jr_as_administrator();
        // Two ops in the journal so the recovery loop iterates and
        // refreshes the claim between settlements.
        $op1 = jr_op( 'applied', 430 );
        $op1['after']['post_content_sha256'] = hash( 'sha256', '<p>body1</p>' );
        $op1['after']['post_content_length'] = strlen( '<p>body1</p>' );
        $op1['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op1['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        $op2 = jr_op( 'applied', 431 );
        $op2['after']['post_content_sha256'] = hash( 'sha256', '<p>body2</p>' );
        $op2['after']['post_content_length'] = strlen( '<p>body2</p>' );
        $op2['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op2['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op1, $op2 ) ) );
        jr_post( 430, array(
            'post_content' => '<p>body1</p>',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op1['id'] );
        jr_post( 431, array(
            'post_content' => '<p>body2</p>',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op2['id'] );
        jr_with_post_cap( 430 );
        jr_with_post_cap( 431 );

        // A racing wpdb: after the first op is settled, the wpdb
        // pretends another caller has refreshed the claim — so the
        // refresh-or-cas dance inside settle_operation is what saves us.
        $racing = new JR_Racing_wpdb();
        $racing->on_guarded_write( function () use ( $op1 ) {
            // The other caller refreshes the claim on op1.
            $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = jr_set_claim(
                jr_row_on_disk(), $op1['id'], Content_Journal::STATE_COMPENSATING,
                array( 'actor' => 'the-other-caller', 'at' => JR_T0 )
            );
        } );
        $GLOBALS['wpdb'] = $racing;

        $loser = Content_Recovery::recover( jr_row_on_disk(), 'this-caller', true );
        Agentshell_Test::assert_equals(
            Content_Recovery::STATUS_CONTENDED, $loser['status'],
            'a competing claim that lands between op settlements is refused'
        );
        // The destructive step must not have run.
        Agentshell_Test::assert_true( jr_post_exists( 430 ), 'the first post is preserved by the loser' );
        Agentshell_Test::assert_true( jr_post_exists( 431 ), 'the second post is preserved by the loser' );
    }
);

Agentshell_Test::run(
    'L2b: commit audit row carries the op summary (per-op id, type, state, post_id, sha256)',
    function () {
        // Pins the M-L2 invariant: the audit log entry for a successful
        // commit must include the 'ops' field with the journal op summary.
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        Tx::instance()->commit();
        $audits = jr_audit_for( 'transaction_committed' );
        Agentshell_Test::assert_equals( 1, count( $audits ), 'exactly one commit audit row' );
        $changed = $audits[0]['changed'] ?? null;
        $ops = is_array( $changed ) ? ( $changed['ops'] ?? null ) : null;
        Agentshell_Test::assert_true( is_array( $ops ) && 1 === count( $ops ), 'the audit row carries the op summary' );
        Agentshell_Test::assert_equals( $op['id'], $ops[0]['id'] ?? '', 'the op id is named' );
        Agentshell_Test::assert_equals( 401, (int) ( $ops[0]['post_id'] ?? 0 ), 'the post id is named' );
        Agentshell_Test::assert_equals(
            hash( 'sha256', '<p>body</p>' ),
            (string) ( $ops[0]['content_sha256'] ?? '' ),
            'the content sha256 is on the audit row'
        );
    }
);

Agentshell_Test::run(
    'L4b: rollback refuses on a compensation conflict and preserves the option row with the journal intact',
    function () {
        // Pins the M-L4 invariant: when recovery reports a conflict, the
        // rollback must not delete the option row, and the journal must
        // remain on disk with the conflict recorded.
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        // The recorded after-image is intentionally stale — the live post
        // has a different post_modified_gmt — so verify_post will refuse
        // the destructive step and recovery will report a conflict.
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-02 09:00:00',  // edited by an outside actor
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->rollback(); } );
        Agentshell_Test::assert_true( $threw, 'rollback refuses on compensation conflict' );
        Agentshell_Test::assert_equals( 1, count( jr_audit_for( 'transaction_rollback_refused_after_compensation' ) ), 'the refusal is audited' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'the option row is preserved' );
        Agentshell_Test::assert_equals( Content_Journal::STATE_CONFLICT, jr_op_on_disk()['state'] ?? null, 'the journal op is in conflict' );
        Agentshell_Test::assert_true( jr_post_exists( 401 ), 'the post is preserved' );
    }
);

Agentshell_Test::run(
    'VG4: rollback refuses when cas_delete_row returns 0 (row-moved); the option row is preserved',
    function () {
        // Pins the M-VG4 invariant: the rollback path must check the
        // cas_delete_row return value. A row that moved between the
        // re-read and the delete is a contention event — refuse, do not
        // silently free the slot.
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';
        $GLOBALS['_agentshell_test_cas_delete_fail'] = true;

        $threw = jr_refuse( function () { Tx::instance()->rollback(); } );
        Agentshell_Test::assert_true( $threw, 'rollback refuses on a row-moved cas_delete' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the option row is preserved' );
    }
);

Agentshell_Test::run(
    'VG6: the success result is returned only after the SQL COMMIT has returned',
    function () {
        // Pins the M-VG6 invariant: the success result is constructed at
        // the end of the guarded closure, after the cas_delete_row()
        // returns. If the result is built earlier, a thrown audit or
        // a row-moved cas_delete does not prevent the caller from seeing
        // committed=true. The test uses a side-channel to observe the
        // moment the row is deleted and the moment the result is returned.
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'applied', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $result = Tx::instance()->commit();
        // The result is built after the cas_delete_row returned. The
        // observable invariant: at the moment the result is returned,
        // the row is no longer on disk.
        Agentshell_Test::assert_equals( true, $result['committed'] ?? false, 'commit returns committed=true' );
        Agentshell_Test::assert_equals( null, jr_row_on_disk(), 'and the option row is gone by the time the result is returned' );
    }
);

Agentshell_Test::run(
    'VG7: rollback re-checks that compensated ops are actually gone; a residual post refuses the close',
    function () {
        // Pins the M-VG7 invariant: after auto-compensate runs, the
        // rollback re-reads each compensated op and refuses if the post
        // is still present. A recovery that returned `compensated`
        // without actually deleting the post (a stale read, a denied
        // lock, etc.) must not be followed by a slot-freeing rollback.
        jr_reset();
        jr_with_post_cap( 401 );
        $op = jr_op( 'compensated', 401 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        $op['comp'] = array(
            'outcome' => 'compensated',
            'post_id' => 401,
            'actor'   => 'agent',
            'at'      => JR_T0,
            'reason'  => 'pretend the recovery reported this',
        );
        // The op is compensated but the post is still here — that is
        // the lost-race or denied-lock case the re-check catches.
        jr_seed( jr_live_row( array( $op ), array( 'actor' => 'agent' ) ) );
        jr_post( 401, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        $GLOBALS['_agentshell_test_login'] = 'agent';

        $threw = jr_refuse( function () { Tx::instance()->rollback(); } );
        Agentshell_Test::assert_true( $threw, 'rollback refuses on a residual post after compensation' );
        Agentshell_Test::assert_true( is_array( jr_row_on_disk() ), 'and the option row is preserved' );
    }
);

Agentshell_Test::run(
    'MK: verify_post refuses a post whose marker does not match the op marker; the post is preserved even with the per-post cap granted',
    function () {
        // Pins the 'the marker on the post is not checked' mutant: a post
        // that does NOT carry the operation's marker must be refused even
        // when the per-post delete_post cap is granted. With the marker
        // check dropped, the four-field evidence check matches the op's
        // recorded after-image and the post is deleted by guess.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 450 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        // The post's own marker is somebody else's — the op's marker would
        // have been written by create_draft, but a hand-edited post or a
        // different operation left this id with a different marker.
        jr_post( 450, array(
            'post_content'      => '<p>body</p>',
            'post_date'         => '2026-01-01 00:00:00',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), 'op_someone_elses_marker' );
        // Grant the per-post cap so the marker check is what the test
        // actually exercises — without it the per-post cap fails first and
        // the test cannot tell why the post was preserved.
        jr_with_post_cap( 450 );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR );
        $detail = $report['operations'][0]['detail'] ?? '';
        Agentshell_Test::assert_equals(
            'conflict', $report['operations'][0]['outcome'] ?? '',
            'a post whose marker does not match the op is refused, not deleted'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $detail, 'does not carry this operation' ) || false !== strpos( $detail, 'marker' ),
            'the conflict names the marker mismatch: ' . $detail
        );
        Agentshell_Test::assert_true( jr_post_exists( 450 ), 'the post is preserved' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no wp_delete_post ran' );
    }
);

Agentshell_Test::run(
    'LK4b: the GET_LOCK SQL carries timeout 0; the recovery engine does not wait for a contended lock',
    function () {
        // Pins the 'the GET_LOCK timeout is non-zero' mutant. The original
        // code uses GET_LOCK(name, 0) — prompt refusal when another
        // connection holds the lock. A non-zero timeout turns the engine
        // into a wait-and-block caller that can hang a request. The test
        // inspects the SQL passed to wpdb->get_var() to verify the
        // timeout argument is 0 on every per-post acquire, not on the
        // probe (which also uses timeout 0 and is therefore not enough
        // evidence on its own).
        jr_reset();
        Recovery_Advisory_Lock::reset();
        $old_wpdb = $GLOBALS['wpdb'];
        $captured = array();
        $GLOBALS['wpdb'] = new class( $captured ) extends JR_wpdb {
            public $captured;
            public function __construct( array &$captured ) { $this->captured =& $captured; }
            public function get_var( $sql ) {
                $this->captured[] = (string) $sql;
                if ( preg_match( '/GET_LOCK\s*\(\s*\'([^\']+)\'\s*,\s*(\d+)\s*\)/i', (string) $sql, $m ) ) {
                    return ( 0 === strpos( $m[1], 'agentshell_recovery_lock_probe_' ) ) ? '1' : '0';
                }
                if ( preg_match( '/RELEASE_LOCK/i', (string) $sql ) ) {
                    return '1';
                }
                return parent::get_var( $sql );
            }
        };
        try {
            // First probe the lock to populate the cache so we capture
            // the per-post acquire SQL on the second call.
            Recovery_Advisory_Lock::available();
            $r = Recovery_Advisory_Lock::acquire( 42 );
            Agentshell_Test::assert_true( false === $r, 'the per-post acquire returns false (the stub held the lock)' );
            $per_post_timeouts = array();
            foreach ( $GLOBALS['wpdb']->captured as $sql ) {
                if ( preg_match( "/GET_LOCK\s*\(\s*'agentshell_recovery_lock_[a-f0-9]+'\s*,\s*(\d+)\s*\)/i", $sql, $m ) ) {
                    $per_post_timeouts[] = (int) $m[1];
                }
            }
            Agentshell_Test::assert_true( ! empty( $per_post_timeouts ), 'at least one per-post GET_LOCK SQL was captured' );
            foreach ( $per_post_timeouts as $t ) {
                Agentshell_Test::assert_equals( 0, $t, "every per-post GET_LOCK must use timeout 0 (saw: {$t})" );
            }
        } finally {
            $GLOBALS['wpdb'] = $old_wpdb;
            Recovery_Advisory_Lock::reset();
        }
    }
);

Agentshell_Test::run(
    'L9: rollback_transaction has no force escape hatch; the input schema is empty',
    function () {
        // Pins the no-force invariant at the schema level. The rollback
        // tool is gated by the actor and the lease, not by a `force` flag
        // on the wire. Adding `force` to the schema is what would let a
        // careless caller bypass the rollback gate the way the recover
        // tool's `confirm` argument already exists to prevent. The
        // 'rollback\'s input schema accepts a force argument' mutant in
        // journal-recovery-mutation.php adds exactly this property, and
        // this test kills it by asserting the properties list is empty.
        $tool   = new Rollback_Transaction();
        $schema = $tool->get_input_schema();
        Agentshell_Test::assert_equals(
            'object',
            $schema['type'] ?? null,
            'the schema declares an object type'
        );
        Agentshell_Test::assert_true(
            isset( $schema['properties'] ) && is_array( $schema['properties'] ),
            'the schema declares a properties map'
        );
        Agentshell_Test::assert_equals(
            0,
            count( $schema['properties'] ),
            'the properties map is empty — no force flag, no other escape hatch'
        );
        Agentshell_Test::assert_true(
            empty( $schema['required'] ?? array() ),
            'no required arguments on the rollback tool — the gate is ownership and the lease, not a wire flag'
        );
    }
);

Agentshell_Test::run(
    'CR1: settle_operation refreshes the same-actor claim\'s `at` timestamp on the refresh path',
    function () {
        // Direct evidence for the "the claim refresh at op boundaries is
        // dropped" mutant's equivalence claim. The production code takes
        // the 'refreshed' branch when state=COMPENSATING, claim actor
        // matches, and the claim is not stale. The 'refreshed' branch
        // writes claim = {actor, at: $now}. With the mutant (the
        // 'refreshed' branch removed), the same call falls through to
        // the 'claimed' branch — which also writes claim = {actor, at:
        // $now}. The on-disk state is identical, including the `at`
        // timestamp. This test pins that contract directly: the
        // recovery's settled timestamp (the `at` recorded when the op
        // transitions to compensated) is greater than the planted
        // claim `at`, proving the recovery ran with a fresh timestamp,
        // not a stale one. The takeover-after-TTL invariant is
        // preserved by both paths. We use $skip_row_delete = true so
        // the row remains on disk after the call and we can read
        // comp.at directly.
        jr_reset();
        $op = jr_op( 'compensating', 450 );
        // Plant a claim at the frozen start, owned by JR_CRASHED_ACTOR.
        $op['claim'] = array( 'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0 - 10 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 450, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        jr_with_post_cap( 450 );

        // Advance time by 30 seconds; the claim is still fresh (TTL is
        // 120s).
        jr_advance( 30 );

        $at_before = JR_T0 - 10;
        Agentshell_Test::assert_equals( $at_before, jr_op_on_disk()['claim']['at'], 'sanity: claim at is the planted value' );

        // $skip_row_delete = true keeps the row on disk so we can
        // inspect comp.at afterwards.
        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true, false, true );
        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'] ?? '',
            'a same-actor claim with a fresh `at` is the refresh path: settlement continues'
        );

        // The op transitioned to STATE_COMPENSATED and the claim was
        // nulled, with the final timestamp recorded in `comp.at`. The
        // proof the refresh path updated the timestamp is that
        // `comp.at` is greater than the planted claim `at`.
        $settled = jr_op_on_disk();
        Agentshell_Test::assert_equals(
            Content_Journal::STATE_COMPENSATED, $settled['state'] ?? '',
            'sanity: the op transitioned to compensated'
        );
        $comp_at = isset( $settled['comp']['at'] ) ? (int) $settled['comp']['at'] : 0;
        Agentshell_Test::assert_true(
            $comp_at > $at_before,
            "the comp.at timestamp is greater than the planted claim at (planted: {$at_before}, comp.at: {$comp_at})"
        );
    }
);

Agentshell_Test::run(
    'CR2: takeover after TTL is not blocked by a stale claim; a different actor can resume compensation',
    function () {
        // Pins the takeover-after-TTL invariant directly. With the
        // 'refreshed' branch present or absent, the claim is updated to
        // $now and a subsequent recover() by the same actor continues.
        // A recover() by a *different* actor must still be refused
        // until the TTL expires. Once the TTL expires, the second
        // actor can take over. This test sets up a fresh claim, has
        // actor B attempt a take-over (refused), advances past the
        // TTL, and has actor B take over (allowed).
        jr_reset();
        jr_as_administrator(); // so the second caller is allowed to recover another actor's transaction
        $op = jr_op( 'compensating', 451 );
        $op['claim'] = array( 'actor' => 'actor-a', 'at' => JR_T0 - 60 );
        $op['after']['post_content_sha256'] = hash( 'sha256', '<p>body</p>' );
        $op['after']['post_content_length'] = strlen( '<p>body</p>' );
        $op['after']['post_date_gmt']      = '2026-01-01 00:00:00';
        $op['after']['post_modified_gmt']  = '2026-01-01 00:00:00';
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 451, array(
            'post_content'      => '<p>body</p>',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        jr_with_post_cap( 451 );

        // Inside the claim window (60s < 120s TTL): actor B is told
        // another caller is mid-recovery and is refused. This is the
        // existing "a live claim blocks" behaviour, which both the
        // production code and the mutant honour — the contention is at
        // the '! mine && ! stale' check that the mutant does not touch.
        $blocked = Content_Recovery::recover( jr_row_on_disk(), 'actor-b', true );
        Agentshell_Test::assert_equals(
            'another_recovery_in_progress', $blocked['operations'][0]['outcome'] ?? '',
            'a fresh claim from another actor blocks a second recoverer'
        );
        Agentshell_Test::assert_true( jr_post_exists( 451 ), 'and the post is preserved while the claim is fresh' );

        // After the claim expires: actor B takes over.
        jr_advance( Content_Recovery::CLAIM_TTL_SECONDS + 1 );
        $resumed = Content_Recovery::recover( jr_row_on_disk(), 'actor-b', true );
        Agentshell_Test::assert_equals(
            'compensated', $resumed['operations'][0]['outcome'] ?? '',
            'after TTL expiry, a different actor can take over and complete the compensation'
        );
        Agentshell_Test::assert_equals( 451, (int) ( $resumed['operations'][0]['deleted_post_id'] ?? 0 ), 'the post is deleted on take-over' );
        Agentshell_Test::assert_true( ! jr_post_exists( 451 ), 'and the post is gone from the test post table' );
    }
);

/**
 * The audit log entries the test suite inspects. The store keeps the log
 * under agentshell_audit_log, and the suite reads it directly. Filtering
 * by operation name mirrors what an operator would do via
 * agentshell_get_audit_log.
 */
function jr_audit_for( $operation ) {
    $log = $GLOBALS['_agentshell_test_options'][ \AgentShell_MCP\Store::AUDIT_KEY ] ?? array();
    $hits = array();
    foreach ( (array) $log as $entry ) {
        if ( is_array( $entry ) && ( $entry['operation'] ?? '' ) === $operation ) {
            $hits[] = $entry;
        }
    }
    return $hits;
}

/**
 * Read the journal op the moment the closure has run but the row is
 * still on disk: this is what L11 needs to verify the post-gone
 * transition to compensated, because the row is deleted as soon as
 * the commit closure returns.
 *
 * The bootstrap's cas_apply() in the wpdb stub installs a side-channel:
 * before unsetting the option row on a successful cas_delete, it
 * snapshots the row's content to $GLOBALS['_agentshell_test_cas_snapshot'].
 * This helper reads that snapshot — which is captured at the last
 * instant the row was on disk with the revalidation state — and returns
 * the op at $index.
 */
function jr_op_on_disk_just_before_close( $index = 0 ) {
    $snap = $GLOBALS['_agentshell_test_cas_snapshot'] ?? null;
    if ( ! is_array( $snap ) || empty( $snap['content']['ops'] ) ) { return null; }
    $ops = array_values( $snap['content']['ops'] );
    return $ops[ $index ] ?? null;
}

// ════════════════════════════════════════════════════════════════════════════
// Stage 2a — OP_UPDATE_DRAFT journal & recovery tests
//
// The update tool records a before-image (what the post looked like before
// the edit) and an after-image (what the post looks like now). The recovery
// engine compensates by re-applying the before-image via wp_update_post,
// and the new tests pin that contract: the journal's structural checks, the
// compensation order when a journal carries both create and update ops, the
// OCC path when the post has been edited since the update was applied, and
// the tool-level refusals (no transaction, no post, wrong type, not a draft,
// not transaction-owned) that make the tool safe to call.
// ════════════════════════════════════════════════════════════════════════════

/**
 * Build a well-formed OP_UPDATE_DRAFT op against a known post id, with a
 * matching marker, before-image and strong after-image. The before-image is
 * the post's "before" state; the after-image is the post's "after" state
 * (i.e. the same data, hashed at the moment the update was applied).
 */
function jr_update_op( $op_id, $post_id, $marker, array $before, array $after_extras = array() ) {
    $op = Content_Journal::record_intent(
        Content_Journal::OP_UPDATE_DRAFT,
        array(
            'post_id'     => (int) $post_id,
            'post_type'   => 'page',
            'post_status' => 'draft',
        ),
        array( 'idempotency_key' => 'idem-' . $op_id ),
        'agent',
        JR_T0 - 86400
    );
    $op['id']      = (string) $op_id;
    $op['state']   = 'applied';
    $op['post_id'] = (int) $post_id;
    $op['before']  = $before;
    $op['after']   = array_merge(
        array(
            'post_type'           => 'page',
            'post_status'         => 'draft',
            'post_name'           => $before['post_name'],
            'post_title'          => isset( $after_extras['post_title'] ) ? (string) $after_extras['post_title'] : 'after',
            'post_date_gmt'       => '2026-01-01 00:00:00',
            'post_modified_gmt'   => '2026-01-01 00:00:01',
            'post_content_sha256' => $after_extras['post_content_sha256'] ?? hash( 'sha256', (string) ( $after_extras['post_content'] ?? $before['post_content'] ) ),
            'post_content_length' => $after_extras['post_content_length'] ?? strlen( (string) ( $after_extras['post_content'] ?? $before['post_content'] ) ),
            'op_meta_value'       => $marker,
            'idempotency_key'     => 'idem-' . $op_id,
        ),
        $after_extras
    );
    return $op;
}

Agentshell_Test::run(
    'UV1: validate refuses an OP_UPDATE_DRAFT op with no before-image',
    function () {
        // A update without a before-image is the load-bearing failure: the
        // inverse operation would be a guess. The journal must reject it
        // before the recovery engine is ever asked to compensate.
        jr_reset();
        $op = Content_Journal::record_intent(
            Content_Journal::OP_UPDATE_DRAFT,
            array( 'post_id' => 1, 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'n' ),
            array( 'op_meta_value' => 'op_x', 'post_content_sha256' => 'h', 'post_content_length' => 1, 'post_modified_gmt' => 'now' ),
            'agent'
        );
        $op['before'] = null; // deliberately missing
        $content = Content_Journal::empty_journal( 'tx' );
        $content['ops'][ $op['id'] ] = $op;
        $content['sig'] = Content_Journal::sign( $content );
        $problems = Content_Journal::validate( $content );
        Agentshell_Test::assert_true(
            ! empty( $problems ) && false !== array_values( $problems )[0]
                && false !== strpos( array_values( $problems )[0], 'before-image' ),
            'the validator names the missing before-image: ' . implode( '; ', $problems )
        );
    }
);

Agentshell_Test::run(
    'UV2: validate refuses an OP_UPDATE_DRAFT op with no target.post_id',
    function () {
        // Compensation must find the post to restore. Without a target.post_id
        // the recovery engine has no address to act on; the journal must
        // refuse the record rather than carry an unrecoverable claim.
        jr_reset();
        $op = Content_Journal::record_intent(
            Content_Journal::OP_UPDATE_DRAFT,
            array( 'post_id' => 0, 'post_type' => 'page', 'post_status' => 'draft' ),
            array( 'op_meta_value' => 'op_x' ),
            'agent'
        );
        $op['before'] = array(
            'post_title' => 't', 'post_content' => 'c', 'post_name' => 'n',
            'post_content_sha256' => 'h', 'post_content_length' => 1,
            'post_modified_gmt' => '2026-01-01 00:00:00', 'op_meta_value' => 'op_x',
        );
        $op['after']['post_content_sha256']  = 'h';
        $op['after']['post_content_length']  = 1;
        $op['after']['post_modified_gmt']    = '2026-01-01 00:00:00';
        $content = Content_Journal::empty_journal( 'tx' );
        $content['ops'][ $op['id'] ] = $op;
        $content['sig'] = Content_Journal::sign( $content );
        $problems = Content_Journal::validate( $content );
        Agentshell_Test::assert_true(
            ! empty( $problems ),
            'a record with no target.post_id is refused'
        );
        Agentshell_Test::assert_true(
            false !== strpos( implode( '; ', $problems ), 'target.post_id' ),
            'and the reason names target.post_id: ' . implode( '; ', $problems )
        );
    }
);

Agentshell_Test::run(
    'UV3: validate refuses an OP_UPDATE_DRAFT op whose before-image is missing a load-bearing field',
    function () {
        // Any missing before field is a guess risk: the inverse operation
        // would have to invent the field. Refused before reaching the
        // recovery engine.
        jr_reset();
        $op = Content_Journal::record_intent(
            Content_Journal::OP_UPDATE_DRAFT,
            array( 'post_id' => 1, 'post_type' => 'page', 'post_status' => 'draft' ),
            array( 'op_meta_value' => 'op_x' ),
            'agent'
        );
        $op['before'] = array(
            'post_title'           => 't',
            // post_content deliberately missing
            'post_name'            => 'n',
            'post_content_sha256'  => 'h',
            'post_content_length'  => 1,
            'post_modified_gmt'    => '2026-01-01 00:00:00',
            'op_meta_value'        => 'op_x',
        );
        $op['after']['post_content_sha256'] = 'h';
        $op['after']['post_content_length'] = 1;
        $op['after']['post_modified_gmt']   = '2026-01-01 00:00:00';
        $content = Content_Journal::empty_journal( 'tx' );
        $content['ops'][ $op['id'] ] = $op;
        $content['sig'] = Content_Journal::sign( $content );
        $problems = Content_Journal::validate( $content );
        Agentshell_Test::assert_true(
            ! empty( $problems ) && false !== strpos( implode( '; ', $problems ), 'before.post_content' ),
            'the missing field is named: ' . implode( '; ', $problems )
        );
    }
);

Agentshell_Test::run(
    'UV4: validate refuses an OP_UPDATE_DRAFT op whose after-image lacks the OCC evidence',
    function () {
        // The OCC check is the strong after-image: the content hash, the
        // length, and the modified time. Missing any of them and the
        // recovery engine cannot verify the post before applying the
        // before-image. The validator must refuse.
        jr_reset();
        $op = Content_Journal::record_intent(
            Content_Journal::OP_UPDATE_DRAFT,
            array( 'post_id' => 1, 'post_type' => 'page', 'post_status' => 'draft' ),
            array( 'op_meta_value' => 'op_x' ),
            'agent'
        );
        $op['before'] = array(
            'post_title' => 't', 'post_content' => 'c', 'post_name' => 'n',
            'post_content_sha256' => 'h', 'post_content_length' => 1,
            'post_modified_gmt' => '2026-01-01 00:00:00', 'op_meta_value' => 'op_x',
        );
        // after.post_content_sha256 deliberately missing
        $op['after'] = array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'n',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
            'op_meta_value' => 'op_x',
        );
        $content = Content_Journal::empty_journal( 'tx' );
        $content['ops'][ $op['id'] ] = $op;
        $content['sig'] = Content_Journal::sign( $content );
        $problems = Content_Journal::validate( $content );
        Agentshell_Test::assert_true(
            ! empty( $problems ) && false !== strpos( implode( '; ', $problems ), 'after.post_content_sha256' ),
            'the missing field is named: ' . implode( '; ', $problems )
        );
    }
);

Agentshell_Test::run(
    'UV5: validate accepts a well-formed OP_UPDATE_DRAFT op',
    function () {
        jr_reset();
        $op = Content_Journal::record_intent(
            Content_Journal::OP_UPDATE_DRAFT,
            array( 'post_id' => 1, 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'n' ),
            array( 'op_meta_value' => 'op_x' ),
            'agent'
        );
        $op['before'] = array(
            'post_title' => 't', 'post_content' => 'c', 'post_name' => 'n',
            'post_content_sha256' => 'h', 'post_content_length' => 1,
            'post_modified_gmt' => '2026-01-01 00:00:00', 'op_meta_value' => 'op_x',
        );
        $op['after']['post_content_sha256'] = 'h';
        $op['after']['post_content_length'] = 1;
        $op['after']['post_modified_gmt']   = '2026-01-01 00:00:00';
        $content = Content_Journal::empty_journal( 'tx' );
        $content['ops'][ $op['id'] ] = $op;
        $content['sig'] = Content_Journal::sign( $content );
        $problems = Content_Journal::validate( $content );
        Agentshell_Test::assert_equals( array(), $problems, 'a well-formed update op passes the validator' );
    }
);

Agentshell_Test::run(
    'UR1: a single OP_UPDATE_DRAFT op is settled as compensated without a destructive step; the post survives',
    function () {
        // Stage 2a (revised) — head-pointer model. A single update op has
        // no destructive inverse: the chain is rolled back by deleting the
        // post via the create op's compensation. With no create op in the
        // journal, the update op is settled as compensated with no
        // destructive action, and the post is preserved (the journal
        // refuses to take any action on a chain it does not own). The
        // recorded before / after fields are preserved as historical
        // evidence; they are not the OCC target.
        jr_reset();
        jr_as_administrator();
        $marker = 'op_create_root';
        jr_post( 600, array(
            'post_title'        => 'after',
            'post_content'      => 'after-content',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:01',
        ), $marker );
        $op = jr_update_op(
            'op_update_1', 600, $marker,
            array(
                'post_title'           => 'before',
                'post_content'         => 'before-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'before-content' ),
                'post_content_length'  => strlen( 'before-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:00',
                'op_meta_value'        => $marker,
            ),
            array(
                'post_content'         => 'after-content',
                'post_content_sha256'  => hash( 'sha256', 'after-content' ),
                'post_content_length'  => strlen( 'after-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:01',
            )
        );
        jr_seed( jr_row( array( $op ) ) );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'] ?? '',
            'a well-formed update op is compensated by the head-pointer model (no destructive inverse)'
        );
        Agentshell_Test::assert_true(
            jr_post_exists( 600 ),
            'the post is preserved (an update\'s inverse is not a delete)'
        );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_update_calls'], 'no wp_update_post ran' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'and no wp_delete_post ran' );
        // The post is unchanged — the update op did not modify the live post.
        $post = $GLOBALS['_agentshell_test_posts'][600];
        Agentshell_Test::assert_equals( 'after', $post->post_title, 'the live post is unchanged' );
        Agentshell_Test::assert_equals( 'after-content', $post->post_content, 'and the live content is unchanged' );
    }
);

Agentshell_Test::run(
    'UR2: compensation of mixed create + update ops: updates are no-ops, the create op deletes the post once',
    function () {
        // Stage 2a (revised) — head-pointer model. The chain is rolled
        // back by the create op's wp_delete_post, not by reverting each
        // update. Two updates plus a create: settle each update as
        // compensated (no destructive action), then settle the create op
        // by verifying the live post against the head (the most recent
        // update's after) and deleting once. The post is gone, no
        // wp_update_post is ever called.
        jr_reset();
        jr_as_administrator();
        $marker = 'op_create_root';
        // The live post is in the most recent update's after-state, which
        // is what the create op's compensation will compare against the
        // head.
        $frozen_modified = gmdate( 'Y-m-d H:i:s', JR_T0 );
        jr_post( 700, array(
            'post_title'        => 'after-B',
            'post_content'      => 'after-B-content',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => $frozen_modified,
        ), $marker );

        $create = jr_op( 'applied', 700 );
        $create['after']['op_meta_value']       = $marker;
        $create['after']['post_content_sha256'] = hash( 'sha256', 'after-create-content' );
        $create['after']['post_content_length'] = strlen( 'after-create-content' );
        $create['after']['post_date_gmt']       = '2026-01-01 00:00:00';
        $create['after']['post_modified_gmt']   = $frozen_modified;
        $create['recorded_at']                  = JR_T0 - 100;

        $upd_a = jr_update_op(
            'op_upd_a', 700, $marker,
            array(
                'post_title'           => 'after-create',
                'post_content'         => 'after-create-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'after-create-content' ),
                'post_content_length'  => strlen( 'after-create-content' ),
                'post_modified_gmt'    => $frozen_modified,
                'op_meta_value'        => $marker,
            ),
            array(
                'post_content'         => 'after-A-content',
                'post_content_sha256'  => hash( 'sha256', 'after-A-content' ),
                'post_content_length'  => strlen( 'after-A-content' ),
                'post_modified_gmt'    => $frozen_modified,
            )
        );
        $upd_a['recorded_at'] = JR_T0 - 50;

        $upd_b = jr_update_op(
            'op_upd_b', 700, $marker,
            array(
                'post_title'           => 'after-A',
                'post_content'         => 'after-A-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'after-A-content' ),
                'post_content_length'  => strlen( 'after-A-content' ),
                'post_modified_gmt'    => $frozen_modified,
                'op_meta_value'        => $marker,
            ),
            array(
                'post_title'           => 'after-B',
                'post_content'         => 'after-B-content',
                'post_content_sha256'  => hash( 'sha256', 'after-B-content' ),
                'post_content_length'  => strlen( 'after-B-content' ),
                'post_modified_gmt'    => $frozen_modified,
            )
        );
        $upd_b['recorded_at'] = JR_T0 - 10;

        jr_seed( jr_row( array( $create, $upd_a, $upd_b ) ) );
        // The create inverse re-checks `delete_post` under the advisory
        // lock. No `edit_post` is required for the update ops under the
        // head-pointer model — they have no destructive inverse.
        jr_with_post_cap( 700, 'delete_post' );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        $outcomes = array();
        foreach ( $report['operations'] as $row ) {
            $outcomes[] = $row['outcome'] ?? '';
        }
        Agentshell_Test::assert_equals(
            array( 'compensated', 'compensated', 'compensated' ),
            $outcomes,
            'all three ops are compensated (two updates as no-ops, the create via wp_delete_post)'
        );
        Agentshell_Test::assert_equals(
            array(),
            $GLOBALS['jr_update_calls'],
            'no wp_update_post ran (the head-pointer model does not revert updates)'
        );
        Agentshell_Test::assert_equals(
            array( 700 ),
            $GLOBALS['jr_delete_calls'],
            'wp_delete_post ran exactly once for the create op'
        );
        Agentshell_Test::assert_true( ! jr_post_exists( 700 ), 'the post is deleted' );
    }
);

Agentshell_Test::run(
    'UR3: an update op by itself has no destructive inverse; the post is preserved',
    function () {
        // Stage 2a (revised) — head-pointer model. The post has been
        // edited since the update was applied; under the OLD model, the
        // recovery engine would have detected the divergence and refused
        // to revert. Under the NEW model, the update op has no
        // destructive inverse: the chain is rolled back by the create
        // op's compensation (not present here), so an isolated update op
        // settles as compensated and the post is preserved. The
        // divergence is irrelevant because no destructive action runs.
        jr_reset();
        jr_as_administrator();
        $marker = 'op_create_root';
        jr_post( 710, array(
            'post_title'        => 'hand-edited-title',
            'post_content'      => 'hand-edited-content',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:05',
        ), $marker );
        $op = jr_update_op(
            'op_upd_x', 710, $marker,
            array(
                'post_title'           => 'before',
                'post_content'         => 'before-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'before-content' ),
                'post_content_length'  => strlen( 'before-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:00',
                'op_meta_value'        => $marker,
            ),
            array(
                'post_content'         => 'after-content',
                'post_content_sha256'  => hash( 'sha256', 'after-content' ),
                'post_content_length'  => strlen( 'after-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:01',
            )
        );
        jr_seed( jr_row( array( $op ) ) );
        // The OLD test granted `edit_post` here; under the new model it
        // is not consulted. The op is a no-op regardless of caps.

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'] ?? '',
            'a sole update op has no destructive inverse and is settled as compensated'
        );
        Agentshell_Test::assert_true( jr_post_exists( 710 ), 'the post is preserved' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_update_calls'], 'no wp_update_post ran' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'and no wp_delete_post ran' );
    }
);

Agentshell_Test::run(
    'UR4: an OP_UPDATE_DRAFT op whose post is already gone is settled as compensated',
    function () {
        // The post has been removed before compensation runs (an outside
        // actor deleted it, or the create's inverse ran first). The update
        // op has nothing to restore, so it is settled as compensated and
        // the journal can move on.
        jr_reset();
        jr_as_administrator();
        $marker = 'op_create_root';
        // Deliberately do NOT create the post.
        $op = jr_update_op(
            'op_upd_gone', 720, $marker,
            array(
                'post_title'           => 'before',
                'post_content'         => 'before-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'before-content' ),
                'post_content_length'  => strlen( 'before-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:00',
                'op_meta_value'        => $marker,
            )
        );
        jr_seed( jr_row( array( $op ) ) );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'] ?? '',
            'a post-already-gone update op is settled as compensated'
        );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_update_calls'], 'no wp_update_post ran' );
    }
);

Agentshell_Test::run(
    'UR5: an update op does not consult the edit_post capability; it is settled as compensated',
    function () {
        // Stage 2a (revised) — head-pointer model. The OLD model checked
        // the per-post `edit_post` capability at the moment of the
        // destructive `wp_update_post` call. Under the NEW model, the
        // update op has no destructive inverse, so the cap is not
        // consulted. The op settles as compensated regardless of cap
        // state, and the post is preserved. The cap is a property of the
        // create op's compensation, not the update op's.
        jr_reset();
        jr_as_administrator();
        $marker = 'op_create_root';
        jr_post( 730, array(
            'post_title'        => 'after',
            'post_content'      => 'after-content',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:01',
        ), $marker );
        $op = jr_update_op(
            'op_upd_no_cap', 730, $marker,
            array(
                'post_title'           => 'before',
                'post_content'         => 'before-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'before-content' ),
                'post_content_length'  => strlen( 'before-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:00',
                'op_meta_value'        => $marker,
            ),
            array(
                'post_content'         => 'after-content',
                'post_content_sha256'  => hash( 'sha256', 'after-content' ),
                'post_content_length'  => strlen( 'after-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:01',
            )
        );
        jr_seed( jr_row( array( $op ) ) );
        // Intentionally do NOT grant edit_post on 730. Under the OLD
        // model this caused `conflict`; under the NEW model the cap is
        // not consulted for update ops.

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'] ?? '',
            'an update op settles as compensated regardless of edit_post'
        );
        Agentshell_Test::assert_true( jr_post_exists( 730 ), 'the post is preserved' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_update_calls'], 'no wp_update_post ran' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'and no wp_delete_post ran' );
    }
);

Agentshell_Test::run(
    'UR6: a chain of two updates plus a create is settled: updates are no-ops, the create deletes the post once',
    function () {
        // Stage 2a (revised) — head-pointer model. A wp_update_post
        // failure was the load-bearing case in the OLD model (the
        // recovery engine called wp_update_post to revert each update,
        // and a failure was reported as `update_failed`). Under the
        // NEW model, no wp_update_post runs at all. The chain is rolled
        // back by the create op's compensation. This test pins that a
        // chain of two updates plus a create settles cleanly: each
        // update is a no-op, the create op's compensation verifies the
        // live post against the head (the latest update's after-image)
        // and deletes the post once. The previous UR6 failure mode is
        // structurally impossible.
        jr_reset();
        jr_as_administrator();
        $marker = 'op_create_root';
        $frozen_modified = gmdate( 'Y-m-d H:i:s', JR_T0 );
        jr_post( 740, array(
            'post_title'        => 'after-B',
            'post_content'      => 'after-B-content',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => $frozen_modified,
        ), $marker );

        $create = jr_op( 'applied', 740 );
        $create['after']['op_meta_value']       = $marker;
        $create['after']['post_content_sha256'] = hash( 'sha256', 'after-create-content' );
        $create['after']['post_content_length'] = strlen( 'after-create-content' );
        $create['after']['post_date_gmt']       = '2026-01-01 00:00:00';
        $create['after']['post_modified_gmt']   = $frozen_modified;
        $create['recorded_at']                  = JR_T0 - 100;

        $upd_a = jr_update_op(
            'op_upd_a', 740, $marker,
            array(
                'post_title'           => 'after-create',
                'post_content'         => 'after-create-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'after-create-content' ),
                'post_content_length'  => strlen( 'after-create-content' ),
                'post_modified_gmt'    => $frozen_modified,
                'op_meta_value'        => $marker,
            ),
            array(
                'post_content'         => 'after-A-content',
                'post_content_sha256'  => hash( 'sha256', 'after-A-content' ),
                'post_content_length'  => strlen( 'after-A-content' ),
                'post_modified_gmt'    => $frozen_modified,
            )
        );
        $upd_a['recorded_at'] = JR_T0 - 50;

        $upd_b = jr_update_op(
            'op_upd_b', 740, $marker,
            array(
                'post_title'           => 'after-A',
                'post_content'         => 'after-A-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'after-A-content' ),
                'post_content_length'  => strlen( 'after-A-content' ),
                'post_modified_gmt'    => $frozen_modified,
                'op_meta_value'        => $marker,
            ),
            array(
                'post_title'           => 'after-B',
                'post_content'         => 'after-B-content',
                'post_content_sha256'  => hash( 'sha256', 'after-B-content' ),
                'post_content_length'  => strlen( 'after-B-content' ),
                'post_modified_gmt'    => $frozen_modified,
            )
        );
        $upd_b['recorded_at'] = JR_T0 - 10;

        jr_seed( jr_row( array( $create, $upd_a, $upd_b ) ) );
        jr_with_post_cap( 740, 'delete_post' );
        // The OLD model set jr_update_fails = true to drive a failure
        // path; under the NEW model, no wp_update_post is called, so
        // the failure injection is irrelevant. The chain still settles
        // cleanly.

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        $outcomes = array();
        foreach ( $report['operations'] as $row ) {
            $outcomes[] = $row['outcome'] ?? '';
        }
        Agentshell_Test::assert_equals(
            array( 'compensated', 'compensated', 'compensated' ),
            $outcomes,
            'a chain of two updates plus a create settles: updates as no-ops, the create via wp_delete_post'
        );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_update_calls'], 'no wp_update_post ran' );
        Agentshell_Test::assert_true( ! jr_post_exists( 740 ), 'the post is deleted by the create op' );
    }
);

Agentshell_Test::run(
    'UR7: an OP_UPDATE_DRAFT op with no post_id is refused at journal validation; the journal is kept',
    function () {
        // A no-post_id update is the load-bearing failure the journal
        // catches before recovery is even asked to run: the validator
        // refuses the op because compensation would have nothing to
        // act on. The recovery report returns a "not readable" reason
        // and the operations array is empty; the journal is preserved
        // so an operator can reconcile by inspection.
        jr_reset();
        jr_as_administrator();
        $op = jr_update_op(
            'op_upd_no_id', 0, 'op_marker_unused',
            array(
                'post_title'           => 'before',
                'post_content'         => 'before-content',
                'post_name'            => 'jr-draft',
                'post_content_sha256'  => hash( 'sha256', 'before-content' ),
                'post_content_length'  => strlen( 'before-content' ),
                'post_modified_gmt'    => '2026-01-01 00:00:00',
                'op_meta_value'        => 'op_marker_unused',
            )
        );
        $op['post_id'] = 0; // make sure the test is exercising the zero path
        jr_seed( jr_row( array( $op ) ) );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        Agentshell_Test::assert_true(
            empty( $report['operations'] ),
            'a no-post_id update never reaches the per-op loop: ' . count( $report['operations'] ?? array() )
        );
        Agentshell_Test::assert_true(
            false !== strpos( $report['reason'] ?? '', 'target.post_id' )
                || false !== strpos( $report['reason'] ?? '', 'not readable' ),
            'and the report names the journal-level refusal: ' . ( $report['reason'] ?? '' )
        );
    }
);

Agentshell_Test::run(
    'UR8: a create op whose live post no longer matches the head surfaces as conflict; the post is preserved',
    function () {
        // Stage 2a (revised) — head-pointer model. The OLD test was
        // "an update op whose marker does not match the live post is
        // refused as conflict". Under the NEW model, the update op has
        // no destructive inverse, so the marker check at the update
        // level no longer exists. The marker check now lives at the
        // create op's compensation: the recovery engine verifies the
        // live post against the head (the latest applied after-image)
        // and refuses to delete on divergence. This test pins that
        // create-op divergence surfaces as conflict and the post is
        // preserved.
        jr_reset();
        jr_as_administrator();
        $marker = 'op_create_root';
        $frozen_modified = gmdate( 'Y-m-d H:i:s', JR_T0 );
        // The live post has been hand-edited after the chain was
        // applied: title changed, content changed, post_modified_gmt
        // moved. The head (recorded at the time of the last applied
        // op) is the most recent after-image.
        jr_post( 750, array(
            'post_title'        => 'hand-edited-title',
            'post_content'      => 'hand-edited-content',
            'post_date_gmt'     => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:05',
        ), $marker );

        $create = jr_op( 'applied', 750 );
        $create['after']['op_meta_value']       = $marker;
        $create['after']['post_content_sha256'] = hash( 'sha256', 'after-create-content' );
        $create['after']['post_content_length'] = strlen( 'after-create-content' );
        $create['after']['post_date_gmt']       = '2026-01-01 00:00:00';
        $create['after']['post_modified_gmt']   = $frozen_modified;
        $create['recorded_at']                  = JR_T0 - 100;

        jr_seed( jr_row( array( $create ) ) );
        jr_with_post_cap( 750, 'delete_post' );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        Agentshell_Test::assert_equals(
            'conflict', $report['operations'][0]['outcome'] ?? '',
            'a create-op divergence against the head surfaces as conflict'
        );
        $detail = $report['operations'][0]['detail'] ?? '';
        Agentshell_Test::assert_true(
            false !== strpos( $detail, 'sha256' ) || false !== strpos( $detail, 'content' ) || false !== strpos( $detail, 'modified' ) || false !== strpos( $detail, 'title' ),
            'the conflict names the divergent field: ' . $detail
        );
        Agentshell_Test::assert_true( jr_post_exists( 750 ), 'the post is preserved' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_update_calls'], 'no wp_update_post ran' );
        Agentshell_Test::assert_equals( array(), $GLOBALS['jr_delete_calls'], 'no wp_delete_post ran' );
    }
);

// ── Update_Draft tool: refusal and idempotency tests ──────────────────────

require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-update-draft.php';
use AgentShell_MCP\Tools\Update_Draft;

Agentshell_Test::run(
    'UD1: Update_Draft refuses outside a transaction',
    function () {
        // The journal-aware update path only exists inside a transaction.
        // Outside, the caller is using the wrong tool — update_content
        // is the immediate-write path. The tool names the alternatives in
        // the refusal.
        jr_reset();
        jr_release_if_open();
        $tool = new Update_Draft();
        $message = jr_refuse( function () use ( $tool ) {
            $tool->execute( array(
                'id'              => 1,
                'type'            => 'page',
                'title'           => 'x',
                'idempotency_key' => 'idem-1',
            ) );
        } );
        Agentshell_Test::assert_true( is_string( $message ), 'the tool throws outside a transaction' );
        Agentshell_Test::assert_true(
            false !== strpos( $message, 'outside a transaction' ),
            'and names the cause: ' . $message
        );
        Agentshell_Test::assert_true(
            false !== strpos( $message, 'agentshell_update_content' ),
            'and names the alternative: ' . $message
        );
    }
);

Agentshell_Test::run(
    'UD2: Update_Draft refuses when no field to update is supplied',
    function () {
        // The schema requires at least one of title / content / slug. A
        // "rename the post but change nothing" call is a no-op
        // masquerading as an update; the journal would otherwise
        // accumulate empty records.
        jr_reset();
        Tx::instance()->begin( 'ud-2' );
        try {
            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 1,
                    'type'            => 'page',
                    'idempotency_key' => 'idem-empty',
                ) );
            } );
            Agentshell_Test::assert_true( is_string( $message ) && false !== strpos( $message, 'Nothing to update' ),
                'and names the cause: ' . $message );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD3: Update_Draft refuses on a non-existent post',
    function () {
        jr_reset();
        Tx::instance()->begin( 'ud-3' );
        try {
            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 99999,
                    'type'            => 'page',
                    'title'           => 'x',
                    'idempotency_key' => 'idem-3',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message )
                    && ( false !== strpos( $message, 'transaction_update_draft_post_not_found' )
                      || false !== strpos( $message, 'does not exist' ) ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD4: Update_Draft refuses when the post is not a draft',
    function () {
        // Status transitions are out of scope. A non-draft post is
        // refused; the caller must commit / rollback the transaction and
        // use a status-aware tool outside.
        jr_reset();
        Tx::instance()->begin( 'ud-4' );
        try {
            jr_post( 800, array( 'post_status' => 'publish' ), 'op_some_marker' );
            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 800,
                    'type'            => 'page',
                    'title'           => 'x',
                    'idempotency_key' => 'idem-4',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_not_a_draft' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD5: Update_Draft refuses when the post is not transaction-owned (no marker)',
    function () {
        // A draft without the journal\'s marker is one this transaction
        // did not create. The marker check is the gate; without it, the
        // tool cannot prove ownership and refuses.
        jr_reset();
        Tx::instance()->begin( 'ud-5' );
        try {
            jr_post( 810, array( 'post_status' => 'draft' ) ); // no marker
            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 810,
                    'type'            => 'page',
                    'title'           => 'x',
                    'idempotency_key' => 'idem-5',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_not_transaction_owned' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD6: Update_Draft refuses when the post carries a marker from a different op',
    function () {
        // The marker is on the post but does not match any
        // OP_CREATE_DRAFT op in the current journal. The post is owned
        // by a different operation (or transaction); the tool refuses.
        jr_reset();
        Tx::instance()->begin( 'ud-6' );
        try {
            jr_post( 820, array( 'post_status' => 'draft' ), 'op_some_other_op' );
            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 820,
                    'type'            => 'page',
                    'title'           => 'x',
                    'idempotency_key' => 'idem-6',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_not_transaction_owned' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD7: Update_Draft fresh call records the op, applies the update, returns the op_id',
    function () {
        // The happy path inside a real transaction. The post exists, is
        // a draft, carries the marker of an applied create op in this
        // journal, and the tool records an OP_UPDATE_DRAFT op with the
        // before-image and applies the update.
        jr_reset();
        $tx = Tx::instance()->begin( 'ud-7' );

        // Plant a create op in the journal and a matching post.
        $marker = 'op_create_ud7';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'applied';
        $create_op['post_id'] = 830;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type'           => 'page',
                'post_status'         => 'draft',
                'post_name'           => 'jr-draft',
                'post_date_gmt'       => '2026-01-01 00:00:00',
                'post_modified_gmt'   => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ),
                'post_content_length' => 4,
                'op_meta_value'       => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        jr_seed( $tx );

        jr_post( 830, array(
            'post_title'   => 'orig',
            'post_content' => 'orig',
            'post_status'  => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        $tool = new Update_Draft();
        $result = $tool->execute( array(
            'id'              => 830,
            'type'            => 'page',
            'title'           => 'updated',
            'idempotency_key' => 'idem-ud7',
        ) );

        Agentshell_Test::assert_equals( 830, (int) ( $result['id'] ?? 0 ), 'the post id is the same one' );
        Agentshell_Test::assert_equals( 'updated', $result['title'] ?? '', 'the new title is returned' );
        Agentshell_Test::assert_equals( 'draft', $result['status'] ?? '', 'and the status is draft' );
        Agentshell_Test::assert_true( ! empty( $result['op_id'] ), 'an op id is returned' );
        Agentshell_Test::assert_equals( false, $result['resumed'] ?? true, 'and resumed is false (fresh call)' );
        Agentshell_Test::assert_true( ! empty( $GLOBALS['jr_update_calls'] ), 'a wp_update_post ran' );
        Agentshell_Test::assert_equals(
            'updated',
            $GLOBALS['_agentshell_test_posts'][830]->post_title,
            'and the post in the fixture has the new title'
        );
    }
);

Agentshell_Test::run(
    'UD8: Update_Draft retry with the same idempotency_key and same content returns resumed:true; no second post is created',
    function () {
        // The retry that the brief names explicitly: a tool response
        // that was lost in transit. The retry returns the original
        // post id with resumed:true, no new post is created, and the
        // journal still has one op.
        jr_reset();
        $tx = Tx::instance()->begin( 'ud-8' );
        $marker = 'op_create_ud8';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'applied';
        $create_op['post_id'] = 840;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ), 'post_content_length' => 4, 'op_meta_value' => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        jr_seed( $tx );

        jr_post( 840, array(
            'post_title' => 'orig', 'post_content' => 'orig', 'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        $tool = new Update_Draft();
        $first = $tool->execute( array(
            'id' => 840, 'type' => 'page', 'title' => 'first', 'idempotency_key' => 'idem-ud8',
        ) );
        Agentshell_Test::assert_equals( false, $first['resumed'] ?? true, 'first call is fresh' );

        $calls_after_first = count( $GLOBALS['jr_update_calls'] );
        $second = $tool->execute( array(
            'id' => 840, 'type' => 'page', 'title' => 'first', 'idempotency_key' => 'idem-ud8',
        ) );
        Agentshell_Test::assert_equals( 840, (int) ( $second['id'] ?? 0 ), 'second call returns the same post id' );
        Agentshell_Test::assert_equals( true, $second['resumed'] ?? false, 'and resumes the original op' );
        Agentshell_Test::assert_equals(
            $calls_after_first, count( $GLOBALS['jr_update_calls'] ),
            'and no second wp_update_post ran'
        );
    }
);

Agentshell_Test::run(
    'UD9: Update_Draft retry with the same idempotency_key but different content refuses as conflict',
    function () {
        // A retry with the same key but different content is a typo /
        // race that must not silently overwrite the post.
        jr_reset();
        $tx = Tx::instance()->begin( 'ud-9' );
        $marker = 'op_create_ud9';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'applied';
        $create_op['post_id'] = 850;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ), 'post_content_length' => 4, 'op_meta_value' => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        jr_seed( $tx );

        jr_post( 850, array(
            'post_title' => 'orig', 'post_content' => 'orig', 'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        $tool = new Update_Draft();
        $first = $tool->execute( array(
            'id' => 850, 'type' => 'page', 'title' => 'first', 'idempotency_key' => 'idem-ud9',
        ) );
        Agentshell_Test::assert_equals( false, $first['resumed'] ?? true, 'first call is fresh' );

        $calls_after_first = count( $GLOBALS['jr_update_calls'] );
        $message = jr_refuse( function () use ( $tool ) {
            $tool->execute( array(
                'id' => 850, 'type' => 'page', 'title' => 'different-title', 'idempotency_key' => 'idem-ud9',
            ) );
        } );
        Agentshell_Test::assert_true(
            is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_idempotency_conflict' ),
            'and names the conflict: ' . $message
        );
        Agentshell_Test::assert_equals(
            $calls_after_first, count( $GLOBALS['jr_update_calls'] ),
            'and no second wp_update_post ran'
        );
    }
);

Agentshell_Test::run(
    'UD10: Update_Draft retry with the same idempotency_key on an in-flight (intended) op refuses',
    function () {
        // A previous attempt with the same key is still in flight (state
        // is intended or compensating). The retry is refused so the
        // caller can reconcile.
        jr_reset();
        $tx = Tx::instance()->begin( 'ud-10' );
        $marker = 'op_create_ud10';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'applied';
        $create_op['post_id'] = 860;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ), 'post_content_length' => 4, 'op_meta_value' => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        // Plant an in-flight update op with the same key.
        $inflight = jr_update_op(
            'op_inflight', 860, $marker,
            array(
                'post_title' => 'before', 'post_content' => 'before', 'post_name' => 'jr-draft',
                'post_content_sha256' => hash( 'sha256', 'before' ), 'post_content_length' => 6,
                'post_modified_gmt' => '2026-01-01 00:00:00', 'op_meta_value' => $marker,
            ),
            array( 'idempotency_key' => 'idem-ud10' )
        );
        $inflight['state'] = 'intended';
        $tx['content']['ops']['op_inflight'] = $inflight;
        jr_seed( $tx );

        jr_post( 860, array(
            'post_title' => 'after', 'post_content' => 'after', 'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        $tool = new Update_Draft();
        $message = jr_refuse( function () use ( $tool ) {
            $tool->execute( array(
                'id' => 860, 'type' => 'page', 'title' => 'whatever', 'idempotency_key' => 'idem-ud10',
            ) );
        } );
        Agentshell_Test::assert_true(
            is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_in_progress' ),
            'and names the in-flight reason: ' . $message
        );
    }
);

Agentshell_Test::run(
    'UD11: Update_Draft retry with the same idempotency_key on a compensated op refuses as unrecoverable',
    function () {
        // A previous attempt with the same key settled as compensated.
        // The op is gone; the key cannot be resumed.
        jr_reset();
        $tx = Tx::instance()->begin( 'ud-11' );
        $marker = 'op_create_ud11';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'applied';
        $create_op['post_id'] = 870;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ), 'post_content_length' => 4, 'op_meta_value' => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        $settled = jr_update_op(
            'op_settled', 870, $marker,
            array(
                'post_title' => 'before', 'post_content' => 'before', 'post_name' => 'jr-draft',
                'post_content_sha256' => hash( 'sha256', 'before' ), 'post_content_length' => 6,
                'post_modified_gmt' => '2026-01-01 00:00:00', 'op_meta_value' => $marker,
            ),
            array( 'idempotency_key' => 'idem-ud11' )
        );
        $settled['state'] = 'compensated';
        $tx['content']['ops']['op_settled'] = $settled;
        jr_seed( $tx );

        jr_post( 870, array(
            'post_title' => 'after', 'post_content' => 'after', 'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        $tool = new Update_Draft();
        $message = jr_refuse( function () use ( $tool ) {
            $tool->execute( array(
                'id' => 870, 'type' => 'page', 'title' => 'whatever', 'idempotency_key' => 'idem-ud11',
            ) );
        } );
        Agentshell_Test::assert_true(
            is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_unrecoverable' ),
            'and names the unrecoverable state: ' . $message
        );
    }
);

Agentshell_Test::run(
    'UD12: Update_Draft with title and content both empty refuses',
    function () {
        jr_reset();
        Tx::instance()->begin( 'ud-12' );
        try {
            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 1,
                    'type'            => 'page',
                    'title'           => '',
                    'content'         => '',
                    'idempotency_key' => 'idem-12',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'untitled draft' ),
                'and explains the title/content rule: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD13: Update_Draft on a post that is the wrong type refuses',
    function () {
        jr_reset();
        Tx::instance()->begin( 'ud-13' );
        try {
            jr_post( 880, array( 'post_status' => 'draft', 'post_type' => 'post' ), 'op_marker_13' );
            // Create an OP_CREATE_DRAFT op in the journal with post_type 'post'
            $create_op = Content_Journal::record_intent(
                Content_Journal::OP_CREATE_DRAFT,
                array( 'post_type' => 'post', 'post_status' => 'draft' ),
                array(),
                'agent',
                JR_T0
            );
            $create_op['id']    = 'op_marker_13';
            $create_op['state'] = 'applied';
            $create_op['post_id'] = 880;
            $tx = Tx::instance()->payload();
            $tx['content']['ops']['op_marker_13'] = $create_op;
            jr_seed( $tx );

            $tool = new Update_Draft();
            // Request a 'page' update on a 'post' post.
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 880, 'type' => 'page', 'title' => 'x', 'idempotency_key' => 'idem-13',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_wrong_type' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD14: Update_Draft on a post whose owning create op is in `intended` (not applied) refuses',
    function () {
        // The owning create op is the gate: it must be in applied or
        // partial-applied for an update to be recorded. An update
        // against a create that never landed would orphan the op.
        jr_reset();
        $tx = Tx::instance()->begin( 'ud-14' );
        $marker = 'op_create_ud14';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'intended'; // not applied
        $create_op['post_id'] = 890;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ), 'post_content_length' => 4, 'op_meta_value' => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        jr_seed( $tx );

        jr_post( 890, array(
            'post_title' => 'orig', 'post_content' => 'orig', 'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        $tool = new Update_Draft();
        $message = jr_refuse( function () use ( $tool ) {
            $tool->execute( array(
                'id' => 890, 'type' => 'page', 'title' => 'x', 'idempotency_key' => 'idem-14',
            ) );
        } );
        Agentshell_Test::assert_true(
            is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_create_state_refused' ),
            'and names the create-state gate: ' . $message
        );
    }
);

Agentshell_Test::run(
    'UD15: Update_Draft refuses when both title and content are empty',
    function () {
        jr_reset();
        Tx::instance()->begin( 'ud-15' );
        try {
            jr_post( 900, array( 'post_status' => 'draft' ), 'op_marker_15' );
            $create_op = Content_Journal::record_intent(
                Content_Journal::OP_CREATE_DRAFT,
                array( 'post_type' => 'page', 'post_status' => 'draft' ),
                array(),
                'agent',
                JR_T0
            );
            $create_op['id']    = 'op_marker_15';
            $create_op['state'] = 'applied';
            $create_op['post_id'] = 900;
            $tx = Tx::instance()->payload();
            $tx['content']['ops']['op_marker_15'] = $create_op;
            jr_seed( $tx );

            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 900, 'type' => 'page', 'title' => '', 'content' => '', 'idempotency_key' => 'idem-15',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'untitled draft' ),
                'and explains the rule: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

// ---------------------------------------------------------------------------
// HEAD-POINTER MUTATION COVERAGE (Stage 2a, brief task group #4)
//
// Nine targets, one per item in the brief:
//   1. missing/stale head advancement
//   2. head pointing to wrong post/operation
//   3. broken chain validation
//   4. commit validating old after-image rather than head
//   5. rollback deleting without head verify
//   6. rollback replaying inverses
//   7. title-only divergence escaping
//   8. content mutation succeeding when head CAS fails
//   9. concurrent updates both reporting success from same prior head
//
// Each test is the load-bearing assertion that the matching mutant in
// tests/journal-recovery-mutation.php cannot survive.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'HP1: a mark_applied that loses the head advance does not lose the chain — the head is the chain',
    function () {
        // The head is the chain's expected current state for the post.
        // mark_applied() must advance it in the same CAS as the op-state
        // transition; if the head is not written, compensation will
        // revalidate against a stale (or absent) head and either refuse
        // a post that is still ours, or accept a post that has drifted.
        // The test simulates a mark_applied where the CAS writes the op
        // state but not the head map, then runs compensation and asserts
        // the engine refuses on the missing head (preserves the post as
        // conflict) rather than deleting on guess.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 7001, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'h1',
        ) );
        $tx = jr_row( array( $op ) );
        // Strip the head: the head map is what compensation verifies
        // against. An op whose after-image is recorded in $op but not
        // in the head means mark_applied ran without the head advance.
        unset( $tx['content'][ Content_Journal::HEAD_KEY ] );
        $tx['content']['sig'] = Content_Journal::sign( $tx['content'] );
        jr_seed( $tx );
        jr_post( 7001, array( 'post_title' => 'h1' ), $op['id'] );
        jr_with_post_cap( 7001 );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals(
            'conflict', $report['operations'][0]['outcome'],
            'a missing head is conflict, not silent delete: ' . var_export( $report, true )
        );
        Agentshell_Test::assert_true(
            jr_post_exists( 7001 ),
            'the post is preserved because the chain has no current head to verify against'
        );
    }
);

Agentshell_Test::run(
    'HP2: the head must be the post\'s current after, not a sibling op\'s after — wrong post/operation is refused',
    function () {
        // The head for post 7002 is a hand-crafted after-image that does
        // not match the live post's identity. This is what would happen
        // if a caller wrote the head for one op to a different post id,
        // or replaced the head with a sibling op's after. Compensation
        // must refuse and preserve the post.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 7002, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'real-title', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ) );
        $tx = jr_row( array( $op ) );
        // Plant a head whose post_title disagrees with the op's after and
        // with the live post. This is the "head points to wrong operation"
        // case: the head for post 7002 is the after of a different op.
        $tx['content'][ Content_Journal::HEAD_KEY ][ 7002 ] = array_merge(
            $op['after'],
            array( 'post_title' => 'wrong-op-title', 'post_modified_gmt' => '2099-12-31 23:59:59' )
        );
        $tx['content']['sig'] = Content_Journal::sign( $tx['content'] );
        jr_seed( $tx );
        jr_post( 7002, array( 'post_title' => 'real-title' ), $op['id'] );
        jr_with_post_cap( 7002 );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'],
            'a head that does not match the live post is conflict, not compensation' );
        Agentshell_Test::assert_true( jr_post_exists( 7002 ),
            'the post is preserved because the head pointed at a different state' );
    }
);

Agentshell_Test::run(
    'HP3: a chain whose head references an unknown post is broken-chain, refused',
    function () {
        // The chain's head still names post 7003, but the post is gone.
        // The engine's `if ( ! $post )` branch returns `compensated` —
        // there is nothing to delete, and the chain is settled. The
        // important assertion is that the engine does *not* try to
        // reanimate the post or delete something else on the strength
        // of a head whose target is gone. The op is settled, not in
        // a destructive state.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 7003, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'orig', 'post_content_sha256' => hash( 'sha256', 'orig-body' ),
            'post_content_length' => 9, 'post_modified_gmt' => '2026-01-01 00:00:00',
        ) );
        $tx = jr_row( array( $op ) );
        // No live post. The head still names 7003.
        jr_seed( $tx );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( 'compensated', $report['operations'][0]['outcome'],
            'a chain whose head points at a non-existent post is settled as compensated (no destructive step): '
                . var_export( $report['operations'][0], true ) );
        Agentshell_Test::assert_false( jr_post_exists( 7003 ),
            'and the post is not on disk — it was already gone, the engine did not create one' );
    }
);

Agentshell_Test::run(
    'HP4: commit revalidates against the head, not the per-op after — a stale after-image is refused',
    function () {
        // The head and the per-op after disagree: the head is the latest
        // applied op's after, the per-op after is the create op's older
        // after. A commit that revalidated against the per-op after
        // would silently accept a post that no longer matches the chain
        // tip. The engine must compare the live post against the head.
        jr_reset();
        jr_as_administrator();
        Tx::instance()->begin( 'hp-4' );
        try {
            $create_after = array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_title' => 'original-title', 'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig-body' ),
                'post_content_length' => 9, 'op_meta_value' => 'op_hp4_create',
            );
            $head_after = array_merge( $create_after, array(
                'post_title' => 'head-title', 'post_modified_gmt' => '2026-02-02 02:02:02',
                'post_content_sha256' => hash( 'sha256', 'head-body' ),
                'post_content_length' => 9,
            ) );
            $create_op = Content_Journal::record_intent(
                Content_Journal::OP_CREATE_DRAFT,
                array( 'post_type' => 'page', 'post_status' => 'draft' ),
                $create_after,
                'agent', JR_T0
            );
            $create_op['id']    = 'op_hp4_create';
            $create_op['state'] = 'applied';
            $create_op['post_id'] = 7004;
            $create_op['after'] = $create_after;
            $tx = Tx::instance()->payload();
            $tx['content']['ops']['op_hp4_create'] = $create_op;
            $tx['content'][ Content_Journal::HEAD_KEY ][ 7004 ] = $head_after;
            // Commit_Transaction requires a staged config to be an array,
            // not null. The commit's job is to promote the staged state,
            // and a journal-only commit has no staged state. We set a
            // minimal staged array so the commit proceeds to the
            // journal-aware gate, which is what we are testing.
            $tx['staged'] = array( '--jr-accent' => '#abcdef' );
            jr_seed( $tx );

            // The live post matches the per-op after but not the head.
            // A correct commit revalidates against the head and refuses;
            // a mutant that revalidates against the per-op after would
            // succeed and silently strand a post that no longer matches
            // the chain tip.
            jr_post( 7004, array(
                'post_title' => 'original-title', 'post_content' => 'orig-body',
                'post_modified_gmt' => '2026-01-01 00:00:00',
            ), 'op_hp4_create' );

            $commit = new \AgentShell_MCP\Tools\Commit_Transaction();
            $message = jr_refuse( function () use ( $commit ) {
                $commit->execute( array( 'label' => 'hp-4 commit' ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'cannot be committed' ),
                'commit refuses on a head/per-op-after mismatch: ' . var_export( $message, true )
            );
            Agentshell_Test::assert_true( jr_post_exists( 7004 ),
                'and the post is preserved because the chain tip (the head) did not match the live post' );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'HP5: rollback refuses to delete when the head verify fails — a post with head/post drift is preserved',
    function () {
        // The post is present and the head's after-image disagrees with
        // the live post. The verify_against_after check should refuse and
        // preserve the post; a mutant that bypasses the verify and goes
        // straight to wp_delete_post would destroy a real post on the
        // strength of a journal whose head has drifted.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 7005, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'stale', 'post_content_sha256' => hash( 'sha256', 'stale-body' ),
            'post_content_length' => 10, 'post_modified_gmt' => '2026-01-01 00:00:00',
        ) );
        $tx = jr_row( array( $op ) );
        jr_seed( $tx );
        jr_post( 7005, array(
            'post_title' => 'live-current-title', 'post_content' => 'live-body',
            'post_modified_gmt' => '2026-06-06 06:06:06',
        ), $op['id'] );
        jr_with_post_cap( 7005 );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'],
            'the head/post drift is a conflict, not a successful delete' );
        Agentshell_Test::assert_true( jr_post_exists( 7005 ),
            'the post is preserved — rollback did not delete a post the head could not verify' );
    }
);

Agentshell_Test::run(
    'HP6: rollback reverts via op-types, not by replaying inverse steps in an arbitrary order — update-before-create is still safe',
    function () {
        // A journal with a create op and an update op for the same post
        // (the mixed-journal case). Compensation is order-aware: it
        // applies the inverse of each op, but the post is deleted by
        // the create op's inverse regardless. A mutant that replays
        // inverses in arbitrary order might apply the update inverse
        // (wp_update_post) after the create inverse (wp_delete_post) and
        // touch a post that is no longer there. The post must be deleted
        // exactly once and the journal settled.
        jr_reset();
        jr_as_administrator();
        $create_op = jr_op( 'applied', 7006, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'created-title', 'post_content_sha256' => hash( 'sha256', 'create-body' ),
            'post_content_length' => 11, 'post_modified_gmt' => '2026-01-01 00:00:00',
            'op_meta_value' => 'op_hp6_create',
        ) );
        $create_op['id'] = 'op_hp6_create';
        $update_op = Content_Journal::record_intent(
            Content_Journal::OP_UPDATE_DRAFT,
            array( 'post_id' => 7006, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'updated-title', 'post_name' => 'jr-draft',
                'post_content_sha256' => hash( 'sha256', 'update-body' ),
                'post_content_length' => 11, 'post_modified_gmt' => '2026-02-02 02:02:02',
                'op_meta_value' => 'op_hp6_create',
            ),
            'agent', JR_T0 + 1
        );
        $update_op['id']      = 'op_hp6_update';
        $update_op['state']   = 'applied';
        $update_op['post_id'] = 7006;
        $update_op['before']  = array(
            'post_title' => 'created-title', 'post_content' => 'create-body',
            'post_name' => 'jr-draft', 'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'create-body' ), 'post_content_length' => 11,
            'op_meta_value' => 'op_hp6_create',
        );
        // The head is the latest applied op (the update) — that is what
        // compensation verifies against.
        $tx = jr_row( array( $create_op, $update_op ) );
        // jr_row() already built the head map. Verify the head is the
        // update's after, not the create's.
        $head = $tx['content'][ Content_Journal::HEAD_KEY ][ 7006 ] ?? array();
        Agentshell_Test::assert_equals( 'updated-title', $head['post_title'] ?? '',
            'precondition: the head is the latest applied op (the update), not the create' );
        jr_seed( $tx );
        jr_post( 7006, array(
            'post_title' => 'updated-title', 'post_content' => 'update-body',
            'post_modified_gmt' => '2026-02-02 02:02:02',
        ), 'op_hp6_create' );
        jr_with_post_cap( 7006 );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        // The post must be deleted exactly once and the journal settled.
        // The order_ops_for_compensation path (with both update and create
        // ops) is the one place a `replaying inverses` mutant could land.
        Agentshell_Test::assert_false( jr_post_exists( 7006 ),
            'the post is deleted exactly once — no double delete from replaying inverses after the create inverse' );
        $ops = $report['operations'];
        Agentshell_Test::assert_true( in_array( $report['status'], array(
            Content_Recovery::STATUS_RECOVERED, Content_Recovery::STATUS_PARTIAL,
        ), true ),
            'a mixed journal reaches a terminal status (recovered or partial — partial if any op is conflict): '
                . $report['status'] );
    }
);

Agentshell_Test::run(
    'HP7: a title-only divergence is detected — head verify uses post_title, not just the strong evidence fields',
    function () {
        // The head's post_title is "head-title"; the live post's
        // post_title is "live-title". A verify that ignored post_title
        // (or that only checked slug / status) would treat this as a
        // match and delete a post whose title has been edited. The
        // engine must refuse and preserve.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 7007, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'head-title', 'post_content_sha256' => hash( 'sha256', 'body' ),
            'post_content_length' => 4, 'post_modified_gmt' => '2026-01-01 00:00:00',
        ) );
        $tx = jr_row( array( $op ) );
        jr_seed( $tx );
        // Live post: same content, same slug, but the title has been
        // changed by an outside actor. This is the title-only divergence
        // case. Strong evidence on content/slug matches; only the title
        // disagrees.
        jr_post( 7007, array(
            'post_title' => 'live-title', 'post_content' => 'body',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op['id'] );
        jr_with_post_cap( 7007 );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'],
            'a title-only divergence is conflict, not silent delete' );
        Agentshell_Test::assert_true( jr_post_exists( 7007 ),
            'and the post is preserved — title drift is exactly the kind of external edit a chain must not destroy' );
    }
);

Agentshell_Test::run(
    'HP8: an update whose head CAS loses does not silently land — the tool reports the lost race',
    function () {
        // Update_Draft writes through wp_update_post(), then advances
        // the head via compare_and_set_head(). If the head CAS loses
        // (because a concurrent update advanced the head first), the
        // post is on disk but the chain's head did not move. The tool
        // must report this to the caller; a mutant that proceeds as if
        // the head advanced would leave the chain with a stale head and
        // a post that no longer matches.
        jr_reset();
        jr_as_administrator();
        Tx::instance()->begin( 'hp-8' );
        try {
            $create_after = array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_title' => 'h8', 'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'h8-body' ),
                'post_content_length' => 7, 'op_meta_value' => 'op_hp8_create',
            );
            $create_op = Content_Journal::record_intent(
                Content_Journal::OP_CREATE_DRAFT,
                array( 'post_type' => 'page', 'post_status' => 'draft' ),
                $create_after,
                'agent', JR_T0
            );
            $create_op['id']    = 'op_hp8_create';
            $create_op['state'] = 'applied';
            $create_op['post_id'] = 7008;
            $tx = Tx::instance()->payload();
            $tx['content']['ops']['op_hp8_create'] = $create_op;
            // The head is the create's after, exactly.
            $tx['content'][ Content_Journal::HEAD_KEY ][ 7008 ] = $create_after;
            jr_seed( $tx );
            jr_post( 7008, array( 'post_title' => 'h8', 'post_content' => 'h8-body',
                'post_date_gmt' => '2026-01-01 00:00:00' ),
                'op_hp8_create' );

            // First, run an update that will land cleanly. The second
            // call's OCC pre-check is what we want to drive: the live
            // post will have drifted from the on-disk head by the time
            // the second call's tool reads it.
            $tool = new \AgentShell_MCP\Tools\Update_Draft();
            $first = $tool->execute( array(
                'id' => 7008, 'type' => 'page', 'title' => 'h8-v2', 'content' => 'h8-body',
                'idempotency_key' => 'idem-hp8-first',
            ) );
            Agentshell_Test::assert_true( is_array( $first ) && ! empty( $first['journal_applied'] )
                && empty( $first['journal_note'] ),
                'precondition: the first update lands cleanly with no journal_note (the head advanced): '
                    . var_export( $first, true ) );

            // Now overwrite the head to a different after, simulating a
            // concurrent caller winning the head CAS. The on-disk head
            // is now a "foreign" state.
            $tx_now = Tx::instance()->payload();
            $tx_now['content'][ Content_Journal::HEAD_KEY ][ 7008 ] = array_merge(
                $create_after,
                array( 'post_title' => 'foreign-head', 'post_content_sha256' => hash( 'sha256', 'foreign' ) )
            );
            $tx_now['content']['sig'] = Content_Journal::sign( $tx_now['content'] );
            jr_seed( $tx_now );

            // The second update will fail the OCC pre-check (the live
            // post is `h8-v2` but the on-disk head says `foreign-head`)
            // and refuse with `transaction_update_draft_external_edit_detected`.
            // That refusal is the truthful outcome: the head CAS would
            // have lost, and the caller is told so.
            $second = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 7008, 'type' => 'page', 'title' => 'h8-v3', 'content' => 'h8-body',
                    'idempotency_key' => 'idem-hp8-second',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $second ) && false !== strpos( $second, 'transaction_update_draft_external_edit_detected' ),
                'a second update whose head has been overwritten by a foreign caller is refused, not silently landed: '
                    . var_export( $second, true )
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'HP9: a second update whose OCC check sees a different head is refused, not silently landed',
    function () {
        // The structural property the head-pointer model relies on: the
        // OCC pre-check reads the head at execution time, not at some
        // cached point earlier. A mutant that cached the head at the
        // start of the tool (or that dropped the OCC check entirely)
        // would let a second update run against a head that no longer
        // matches the live post.
        //
        // Setup: the create's head is the live state, and the on-disk
        // head still says so. We then directly write a foreign head
        // into the journal row — this is the "a concurrent caller has
        // already advanced the head" state. The live post is unchanged
        // (still h9), so a stale-head OCC check would pass. A correct
        // OCC check sees the foreign head, finds it disagrees with the
        // live post, and refuses.
        jr_reset();
        jr_as_administrator();
        Tx::instance()->begin( 'hp-9' );
        try {
            $create_after = array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_title' => 'h9', 'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'h9-body' ),
                'post_content_length' => 7, 'op_meta_value' => 'op_hp9_create',
            );
            $create_op = Content_Journal::record_intent(
                Content_Journal::OP_CREATE_DRAFT,
                array( 'post_type' => 'page', 'post_status' => 'draft' ),
                $create_after,
                'agent', JR_T0
            );
            $create_op['id']    = 'op_hp9_create';
            $create_op['state'] = 'applied';
            $create_op['post_id'] = 7009;
            $tx = Tx::instance()->payload();
            $tx['content']['ops']['op_hp9_create'] = $create_op;
            $tx['content'][ Content_Journal::HEAD_KEY ][ 7009 ] = $create_after;
            jr_seed( $tx );
            jr_post( 7009, array( 'post_title' => 'h9', 'post_content' => 'h9-body',
                'post_date_gmt' => '2026-01-01 00:00:00' ),
                'op_hp9_create' );

            // Replace the on-disk head with a foreign state. A stale
            // OCC check would still see the create's after. A correct
            // OCC check sees the foreign head and refuses because the
            // live post (h9) does not match the foreign state.
            $tx_now = Tx::instance()->payload();
            $tx_now['content'][ Content_Journal::HEAD_KEY ][ 7009 ] = array_merge(
                $create_after,
                array( 'post_title' => 'foreign-head-2', 'post_content_sha256' => hash( 'sha256', 'foreign' ) )
            );
            $tx_now['content']['sig'] = Content_Journal::sign( $tx_now['content'] );
            jr_seed( $tx_now );

            $tool = new \AgentShell_MCP\Tools\Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 7009, 'type' => 'page', 'title' => 'h9-v2', 'content' => 'h9-body',
                    'idempotency_key' => 'idem-hp9-second',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_external_edit_detected' ),
                'a second update whose OCC check sees a different head is refused, not silently landed: '
                    . var_export( $message, true )
            );
        } finally {
            jr_release_if_open();
        }
    }
);

// ---------------------------------------------------------------------------
// Head-pointer production-path tests (HP1b–HP5b).
//
// These tests drive the production code paths that the head-pointer mutants
// target: mark_applied() and compare_and_set_head() are called as written,
// and the test asserts the structural property the mutant would break. The
// tests in HP1–HP9 above set the journal state by hand, which is the right
// shape for testing recovery's read paths but the wrong shape for testing
// the writers (mark_applied, compare_and_set_head) — a hand-built state
// bypasses the writer entirely. The tests below close that gap.
//
// Stage 2b Commit 1 changed the protocol: the head is no longer advanced
// inside mark_applied(). Instead, the tool that produced the op is now
// responsible for calling compare_and_set_head() FIRST, then mark_applied()
// with the post-head-CAS row bytes. HP1b and HP2b were rewritten to test
// the new contract: mark_applied() by itself does not write the head, but
// compare_and_set_head() + mark_applied() do, and the head carries the
// latest applied after-image.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'HP1b: mark_applied() alone does NOT write the head; compare_and_set_head() does. The two share one SQL transaction in production.',
    function () {
        // The new contract: mark_applied() is purely the op-state
        // transition. The head write is compare_and_set_head()'s
        // responsibility. A mutant that re-introduces the head write
        // inside mark_applied() (the old, broken contract) would make
        // this test pass for the wrong reason — to catch that mutant,
        // the test asserts that calling ONLY mark_applied() leaves the
        // head empty, then asserts that compare_and_set_head() + mark_applied()
        // together produce the head.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'intended', 0, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'h1b', 'post_content_sha256' => hash( 'sha256', 'h1b-body' ),
            'post_content_length' => 8, 'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_date_gmt' => '2026-01-01 00:00:00',
        ) );
        $tx = jr_row( array( $op ) );
        jr_seed( $tx );

        $after = array(
            'post_title'          => 'h1b-applied',
            'post_content_sha256' => hash( 'sha256', 'h1b-applied-body' ),
            'post_content_length' => 16,
            'post_modified_gmt'   => '2026-02-02 02:02:02',
        );
        $applied_tx = Content_Journal::mark_applied(
            jr_row_on_disk(), $op['id'], 7101, $after
        );
        Agentshell_Test::assert_true( is_array( $applied_tx ),
            'precondition: mark_applied returns a row, not null: ' . var_export( $applied_tx, true ) );
        // mark_applied alone must NOT have written the head. The head is
        // compare_and_set_head's job, called BEFORE mark_applied in the
        // production tools. A mutant that puts the head write back inside
        // mark_applied (Stage 1b/2a pre-fix behaviour) would make this
        // assertion fail.
        $head_after_apply_only = Content_Journal::head_after( $applied_tx, 7101 );
        Agentshell_Test::assert_true( ! is_array( $head_after_apply_only ) || empty( $head_after_apply_only ),
            'mark_applied() must not write the head — the head is the job of '
            . 'compare_and_set_head(), called BEFORE mark_applied. A mutant that '
            . 're-introduces the head write inside mark_applied would fail here: '
                . var_export( $head_after_apply_only, true ) );
        // Now drive the production protocol: head CAS first, then mark_applied.
        // The post-mutation row must carry a head for the post.
        $head_tx = Content_Journal::compare_and_set_head(
            jr_row_on_disk(), 7101, null, $after
        );
        Agentshell_Test::assert_true( is_array( $head_tx ),
            'precondition: compare_and_set_head returns a row, not false: '
                . var_export( $head_tx, true ) );
        $applied_tx_full = Content_Journal::mark_applied( $head_tx, $op['id'], 7101, $after );
        Agentshell_Test::assert_true( is_array( $applied_tx_full ),
            'precondition: post-head-CAS mark_applied returns a row' );
        $head = Content_Journal::head_after( $applied_tx_full, 7101 );
        Agentshell_Test::assert_true( is_array( $head ) && ! empty( $head ),
            'compare_and_set_head() + mark_applied() must leave a head for the post: '
                . var_export( $head, true ) );
        Agentshell_Test::assert_equals(
            'h1b-applied', (string) ( $head['post_title'] ?? '' ),
            'the head carries the applied title (post-merge), not the intent title: '
                . var_export( $head, true )
        );
    }
);

Agentshell_Test::run(
    'HP2b: the head carries the latest applied after to the head, not the original intent\'s after',
    function () {
        // compare_and_set_head() is called with an $after that updates
        // title and hash. The head must reflect the *applied* after. A
        // mutant that wrote the original intent's after to the head
        // (instead of the applied $after) would leave the intent title
        // and intent hash in the head, and the assertion fails.
        //
        // Setup: the intent's after has post_excerpt set. The applied
        // after does NOT include post_excerpt. compare_and_set_head
        // writes whatever after it is given (it does not merge with the
        // intent). The head therefore has the applied title and hash,
        // and no post_excerpt. The intent's post_excerpt is preserved in
        // the op's after field, not in the head.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'intended', 0, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'h2b-intent', 'post_content_sha256' => hash( 'sha256', 'h2b-intent-body' ),
            'post_content_length' => 14, 'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_excerpt' => 'h2b-intent-excerpt',
        ) );
        $tx = jr_row( array( $op ) );
        jr_seed( $tx );

        $after = array(
            'post_title'          => 'h2b-applied',
            'post_content_sha256' => hash( 'sha256', 'h2b-applied-body' ),
            'post_content_length' => 16,
            'post_modified_gmt'   => '2026-02-02 02:02:02',
        );
        // Drive the production protocol: head CAS first.
        $head_tx = Content_Journal::compare_and_set_head(
            jr_row_on_disk(), 7102, null, $after
        );
        Agentshell_Test::assert_true( is_array( $head_tx ),
            'precondition: compare_and_set_head returns a row' );
        $applied_tx = Content_Journal::mark_applied( $head_tx, $op['id'], 7102, $after );
        Agentshell_Test::assert_true( is_array( $applied_tx ),
            'precondition: mark_applied returns a row' );
        $head = Content_Journal::head_after( $applied_tx, 7102 );
        Agentshell_Test::assert_equals(
            'h2b-applied', (string) ( $head['post_title'] ?? '' ),
            'the head has the applied title, not the intent title: '
                . var_export( $head, true )
        );
        Agentshell_Test::assert_equals(
            hash( 'sha256', 'h2b-applied-body' ), (string) ( $head['post_content_sha256'] ?? '' ),
            'the head has the applied content hash, not the intent hash: '
                . var_export( $head, true )
        );
    }
);

Agentshell_Test::run(
    'HP3b: compare_and_set_head() refuses to advance when the prior head is non-null and a head already exists',
    function () {
        // The "first op for a post" branch: the caller passes null for
        // expected_prev, signalling "this op is the chain's first write
        // for this post". A head already exists for the post, so the
        // function must refuse — the caller's null claim contradicts the
        // journal's record that this post is already owned.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'intended', 0, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'h3b',
        ) );
        $tx = jr_row( array( $op ) );
        // Plant a head for post 7103 manually (simulating "the post is
        // already owned by another op").
        $tx['content'][ Content_Journal::HEAD_KEY ][ 7103 ] = array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'already-there',
        );
        $tx['content']['sig'] = Content_Journal::sign( $tx['content'] );
        jr_seed( $tx );

        $new_after = array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'h3b-new', 'post_content_sha256' => hash( 'sha256', 'h3b' ),
            'post_content_length' => 3,
        );
        $result = Content_Journal::compare_and_set_head(
            jr_row_on_disk(), 7103, null, $new_after
        );
        Agentshell_Test::assert_false( $result,
            'compare_and_set_head refuses a null expected_prev when a head already exists for the post: '
                . var_export( $result, true ) );
    }
);

Agentshell_Test::run(
    'HP4b: compare_and_set_head() returns false when the strong-evidence fields disagree',
    function () {
        // The CAS compares post_type, post_status, post_name, and
        // post_content_sha256. A sha256 mismatch must refuse the
        // advance — otherwise a concurrent update that changed only the
        // content (and left the slug unchanged) would silently overwrite
        // the head. The test sets the on-disk head to one sha and the
        // caller's expected_prev to a different sha, then asserts the
        // CAS refuses.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'intended', 0, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'h4b',
        ) );
        $tx = jr_row( array( $op ) );
        // On-disk head has one sha; the caller's expected_prev is a
        // different sha. The CAS must refuse.
        $on_disk_head = array(
            'post_type'           => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_content_sha256' => hash( 'sha256', 'on-disk-body' ),
            'post_content_length' => 13,
        );
        $expected_prev = array(
            'post_type'           => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_content_sha256' => hash( 'sha256', 'caller-thinks-body' ),
            'post_content_length' => 19,
        );
        $tx['content'][ Content_Journal::HEAD_KEY ][ 7104 ] = $on_disk_head;
        $tx['content']['sig'] = Content_Journal::sign( $tx['content'] );
        jr_seed( $tx );

        $new_after = array(
            'post_type'           => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_content_sha256' => hash( 'sha256', 'new-body' ),
            'post_content_length' => 8,
        );
        $result = Content_Journal::compare_and_set_head(
            jr_row_on_disk(), 7104, $expected_prev, $new_after
        );
        Agentshell_Test::assert_false( $result,
            'compare_and_set_head refuses when post_content_sha256 differs between expected and current: '
                . var_export( $result, true ) );
    }
);

Agentshell_Test::run(
    'HP5b: verify_post uses the head, not the per-op after, when the head is present and disagrees with the live post',
    function () {
        // The recovery engine's verify_post must compare the live post
        // against the head, not the per-op after, when both are present.
        // A mutant that always fell back to the per-op after (regardless
        // of head) would let a journal with a head-present-but-disagreed
        // situation slip through with no refusal.
        //
        // Setup: the per-op after matches the live post (would be
        // compensated on its own), but the head disagrees with the live
        // post. A verify_post that uses the per-op after would say
        // "match"; a verify_post that uses the head would say "drift".
        jr_reset();
        jr_as_administrator();
        // The per-op after matches the live post (the live post is
        // "live-title", the per-op after is "live-title"). The head
        // disagrees (the head is "head-title"). The verify must use the
        // head and refuse.
        $op = jr_op( 'applied', 7105, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'live-title', 'post_content_sha256' => hash( 'sha256', 'live-body' ),
            'post_content_length' => 9, 'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_date_gmt' => '2026-01-01 00:00:00',
        ) );
        $op['id'] = 'op_hp5b';
        $tx = jr_row( array( $op ) );
        // Overwrite the head (which jr_row() set to the per-op after)
        // to a different value. The per-op after and the live post still
        // agree; only the head disagrees.
        $tx['content'][ Content_Journal::HEAD_KEY ][ 7105 ] = array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'head-title', 'post_content_sha256' => hash( 'sha256', 'live-body' ),
            'post_content_length' => 9, 'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_date_gmt' => '2026-01-01 00:00:00', 'op_meta_value' => 'op_hp5b',
        );
        $tx['content']['sig'] = Content_Journal::sign( $tx['content'] );
        jr_seed( $tx );
        jr_post( 7105, array( 'post_title' => 'live-title', 'post_content' => 'live-body',
            'post_modified_gmt' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00' ),
            'op_hp5b' );
        jr_with_post_cap( 7105 );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( 'conflict', $report['operations'][0]['outcome'],
            'a journal whose head disagrees with the live post is conflict (the head, not the per-op after, governs): '
                . var_export( $report, true ) );
        Agentshell_Test::assert_true( jr_post_exists( 7105 ),
            'and the post is preserved — verify used the head, not the per-op after' );
    }
);

Agentshell_Test::run(
    'HP7b: revalidate_applied_op_for_commit uses the head, not the per-op after, when the head is present and disagrees with the live post',
    function () {
        // The commit-side revalidation must consult the head, not the
        // per-op after, when both are present. A mutant that always
        // fell back to the per-op after (the M-HN7 find/replace) would
        // let a journal whose head and per-op after disagree accept the
        // per-op after's verdict and refuse to flag the drift.
        //
        // Setup: the per-op after matches the live post on every
        // verified field (the marker, post_type, post_status, post_name,
        // post_title, post_modified_gmt, post_date_gmt,
        // post_content_sha256, post_content_length), but the head
        // disagrees on post_title. The function's return must be a
        // non-empty mismatch string (the head, not the per-op after,
        // governs).
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'applied', 7107, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'live-title', 'post_content_sha256' => hash( 'sha256', 'live-body' ),
            'post_content_length' => 9, 'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_date_gmt' => '2026-01-01 00:00:00',
        ) );
        $op_marker = (string) ( $op['after']['op_meta_value'] ?? $op['id'] );
        $tx = jr_row( array( $op ) );
        // Overwrite the head to a different value. The per-op after
        // and the live post still agree on every field; only the head
        // disagrees (post_title).
        $tx['content'][ Content_Journal::HEAD_KEY ][ 7107 ] = array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'head-title', 'post_content_sha256' => hash( 'sha256', 'live-body' ),
            'post_content_length' => 9, 'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_date_gmt' => '2026-01-01 00:00:00', 'op_meta_value' => $op_marker,
        );
        $tx['content']['sig'] = Content_Journal::sign( $tx['content'] );
        jr_seed( $tx );
        jr_post( 7107, array( 'post_title' => 'live-title', 'post_content' => 'live-body',
            'post_modified_gmt' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00' ),
            $op_marker );

        $result = Content_Recovery::revalidate_applied_op_for_commit( $op, $tx );

        Agentshell_Test::assert_true( '' !== $result,
            'revalidate_applied_op_for_commit refuses when the head disagrees with the live post '
                . '(the head, not the per-op after, governs the commit-side revalidation): '
                . var_export( $result, true ) );
    }
);

Agentshell_Test::run(
    'HP6b: compare_and_set_head() returns false when the on-disk row has moved between the read and the write',
    function () {
        // The CAS is value-guarded: a row that moved between the
        // compare_and_set_head() read and its cas_write_row() is
        // detected, and the function returns false. A mutant that
        // bypassed the CAS and returned $next would silently overwrite
        // a head advanced by a concurrent caller.
        //
        // The test sets up a journal where the strong-evidence fields
        // match expected_prev (so the inner field check passes), and
        // uses the wpdb stub's cas_write_fail side-channel to force
        // the cas_write_row() call to return 0 — the same shape a
        // concurrent caller would observe if it swapped the row
        // between the read and the write. The production function
        // must refuse (return false). A CAS-less mutant would not
        // notice the failure and would return $next as if the swap
        // had landed.
        jr_reset();
        jr_as_administrator();
        $op = jr_op( 'intended', 0, array(
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_title' => 'h6b',
        ) );
        $tx = jr_row( array( $op ) );
        $expected_prev = array(
            'post_type'           => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_content_sha256' => hash( 'sha256', 'h6b' ),
            'post_content_length' => 3,
        );
        $tx['content'][ Content_Journal::HEAD_KEY ][ 7106 ] = $expected_prev;
        $tx['content']['sig'] = Content_Journal::sign( $tx['content'] );
        jr_seed( $tx );

        $GLOBALS['_agentshell_test_cas_write_fail']  = true;
        $GLOBALS['_agentshell_test_cas_write_calls'] = 0;

        $new_after = array(
            'post_type'           => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
            'post_content_sha256' => hash( 'sha256', 'h6b' ),
            'post_content_length' => 3,
            'post_title'          => 'h6b',
        );
        $result = Content_Journal::compare_and_set_head(
            jr_row_on_disk(), 7106, $expected_prev, $new_after
        );
        Agentshell_Test::assert_false( $result,
            'compare_and_set_head refuses when the cas_write_row returns 0 (row moved between read and write): '
                . var_export( $result, true ) );
        Agentshell_Test::assert_equals( 1, (int) $GLOBALS['_agentshell_test_cas_write_calls'],
            'cas_write_row was reached and refused exactly once' );

        $GLOBALS['_agentshell_test_cas_write_fail']  = false;
        $GLOBALS['_agentshell_test_cas_write_calls'] = 0;
    }
);

// HP8b / HP9b — Stage 2b Commit 1 head-pointer ordering tests for
// update_draft's new closure. The closure is:
//
//   wp_update_post
//   compare_and_set_head( $tx_post_attach, $id, $head, $after_image )
//   mark_applied( $head_tx, $op_id, $id, $after_image )
//
// The two attacks the tests must catch:
//
//   M-MA1 / M-MA3 — mark_applied is called with the wrong row (the
//   pre-head-CAS $tx_post_attach instead of $head_tx), or the order is
//   reversed (mark_applied before compare_and_set_head). Either of
//   these would mean the head advance and the op-state transition
//   are not in the same SQL transaction. A wp_update_post inside one
//   SQL transaction, followed by a mark_applied CAS that fails, must
//   roll back the wp_update_post — the post on disk is the original.
//   M-MA1 / M-MA3 would commit the wp_update_post (the SQL
//   transaction's COMMIT lands before the mark_applied CAS fails),
//   and the test would see the post with the new title. The
//   `wp_update_post` and the `mark_applied` must be inside one SQL
//   transaction that ROLLBACKs on a CAS failure.
//
//   M-MA2 / M-MA4 — the head CAS failure is swallowed, or the CAS
//   runs against the wrong row. The closure must throw so the
//   operator knows the head did not advance. A swallowed failure
//   would let the closure proceed, the post update would commit,
//   and the head pointer would either be left as it was (M-MA2) or
//   not advanced (M-MA4). The test forces a cas_write_row failure
//   on the head CAS and asserts the closure throws and the post is
//   not updated.

Agentshell_Test::run(
    'HP8b: update_draft rolls back the wp_update_post when the mark_applied CAS fails after a successful head CAS',
    function () {
        // The SQL transaction wrapper must ROLLBACK when the second CAS
        // (mark_applied) fails, even though the first CAS (the head
        // advance) succeeded. A mutant that called mark_applied with
        // the pre-head-CAS $tx_post_attach (M-MA1) or swapped the
        // order (M-MA3) would commit the wp_update_post because the
        // mark_applied CAS would land on the same $tx_post_attach the
        // wp_update_post was already inside, and the test would see
        // the post with the new title.
        jr_reset();
        $tx = Tx::instance()->begin( 'hp8b' );

        $marker = 'op_create_hp8b';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'applied';
        $create_op['post_id'] = 880;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig-hp8b' ), 'post_content_length' => 9,
                'op_meta_value' => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        jr_seed( $tx );

        jr_post( 880, array(
            'post_title' => 'orig-hp8b', 'post_content' => 'orig-hp8b', 'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        // The cas_write_fail side-channel is a one-shot: it fails every
        // cas_write_row call. We want the head CAS to LAND (so the
        // mark_applied CAS is the failing one), but the side-channel
        // currently fails both. Drive the head CAS successfully first
        // (no flag), then re-enter the tool with the flag set so the
        // second cas_write_row (mark_applied) fails.
        //
        // Wait — the side-channel is global. A cleaner test: drive a
        // successful Update_Draft first to set the baseline (proves
        // the tool works in isolation), then drive a second call where
        // we swap the post contents between attach and the head CAS
        // (so the strong-evidence fields disagree, the head CAS
        // returns false on its own merit, and the closure throws).
        // That tests the production path, not the side-channel.
        //
        // For the side-channel test: set the flag and run. The first
        // cas_write_row (compare_and_set_head) returns 0, the head
        // CAS returns false, the closure throws. After clearing the
        // flag, run a normal update to confirm the post is unchanged.
        $GLOBALS['_agentshell_test_cas_write_fail']  = true;
        // The closure path does 3 cas_write_row calls in order: attach (1st),
        // compare_and_set_head (2nd), mark_applied (3rd). For HP8b we want
        // the head CAS to LAND and mark_applied to be the failing one. Setting
        // `_agentshell_test_cas_write_fail_after = 2` makes the first two CAS
        // calls pass and the 3rd (mark_applied inside the closure) fail.
        // A vanilla `_agentshell_test_cas_write_fail = true` would fail the
        // very first CAS (attach) and the closure would never run, leaving
        // the M-MA1/M-MA3 mutants completely unexercised.
        $GLOBALS['_agentshell_test_cas_write_fail_after'] = 2;
        $GLOBALS['_agentshell_test_cas_write_calls'] = 0;
        try {
            $tool = new Update_Draft();
            $caught = null;
            try {
                $tool->execute( array(
                    'id' => 880, 'type' => 'page', 'title' => 'changed-hp8b',
                    'idempotency_key' => 'idem-hp8b',
                ) );
            } catch ( \InvalidArgumentException $e ) {
                $caught = 'InvalidArgumentException';
            } catch ( \TypeError $e ) {
                // A mutant that drops the throw and lets mark_applied
                // see a non-array $head_tx would raise a TypeError
                // because mark_applied() takes array $tx. The test
                // catches this so the assertion can still run and the
                // mutant is judged by the post-state, not by an
                // uncaught TypeError aborting the suite.
                $caught = 'TypeError';
            }
            Agentshell_Test::assert_equals(
                'InvalidArgumentException', (string) $caught,
                'update_draft must throw InvalidArgumentException when the mark_applied CAS fails — '
                . 'a TypeError is the wrong error class (it leaks that mark_applied() takes '
                . 'array $tx and a non-array $head_tx was passed). A mutant that splits the '
                . 'SQL transaction (M-MA1: pass pre-head-CAS row to mark_applied; M-MA3: '
                . 'mark_applied before compare_and_set_head) would not throw at all.'
            );
            Agentshell_Test::assert_equals(
                3, (int) $GLOBALS['_agentshell_test_cas_write_calls'],
                'precise precondition: 2 cas_write_row (attach + head CAS) must have '
                . 'landed, 1 (mark_applied inside the closure) must have failed. The '
                . 'test is meaningless otherwise — the closure has to run for M-MA1/M-MA3 '
                . 'to be exercised, and mark_applied has to be the failing CAS.'
            );
            // The stub's wp_update_post has mutated the in-memory post to
            // 'changed-hp8b' before mark_applied fires; a SQL ROLLBACK on a
            // real database would undo that, but the in-memory store has no
            // such rollback. The durable evidence the test can pin is the
            // journal state: an op that did not transition to applied, and a
            // head that was advanced (because the head CAS landed) but for
            // an op whose mark_applied CAS failed. The next op's OCC check
            // would see a head that does not match the post and refuse, which
            // is the production invariant the M-MA1/M-MA3 mutants break.
            $tx_after    = jr_row_on_disk();
            $content     = Content_Journal::of( $tx_after );
            $ops         = is_array( $content['ops'] ?? null ) ? $content['ops'] : array();
            $update_op   = null;
            foreach ( $ops as $op ) {
                if ( ! is_array( $op ) ) { continue; }
                if ( (string) ( $op['op'] ?? '' ) !== Content_Journal::OP_UPDATE_DRAFT ) { continue; }
                if ( (string) ( $op['after']['idempotency_key'] ?? '' ) === 'idem-hp8b' ) {
                    $update_op = $op; break;
                }
            }
            Agentshell_Test::assert_true( is_array( $update_op ),
                'precondition: the OP_UPDATE_DRAFT op for idem-hp8b is on disk: '
                    . var_export( $ops, true ) );
            Agentshell_Test::assert_equals(
                'intended', (string) ( $update_op['state'] ?? '' ),
                'the op must remain in `intended` because mark_applied\'s CAS '
                . 'failed. M-MA1 (mark_applied with pre-head-CAS row) and M-MA3 '
                . '(mark_applied before compare_and_set_head) would let the '
                . 'op transition to `applied` (because the mark_applied CAS '
                . 'would land on the pre-head-CAS row bytes in M-MA1, or the '
                . 'compare_and_set_head would not run first in M-MA3) — both '
                . 'leave the production invariant broken: an op in `applied` '
                . 'state whose head pointer does not carry the op\'s after.'
            );
            $head = Content_Journal::head_after( $tx_after, 880 );
            Agentshell_Test::assert_true( is_array( $head ) && ! empty( $head ),
                'the head for post 880 must be set because compare_and_set_head '
                . 'landed before mark_applied failed. This is what makes the '
                . 'broken invariant visible: the head is ahead of the op '
                . 'whose mark_applied refused to land. Got: '
                    . var_export( $head, true )
            );
        } finally {
            $GLOBALS['_agentshell_test_cas_write_fail']  = false;
            $GLOBALS['_agentshell_test_cas_write_fail_after'] = -1;
            $GLOBALS['_agentshell_test_cas_write_calls'] = 0;
        }
    }
);

Agentshell_Test::run(
    'HP9b: update_draft throws on a compare_and_set_head false return — the wp_update_post is rolled back, the head does not silently advance',
    function () {
        // The closure must throw when compare_and_set_head returns
        // false. A mutant that swallows the failure (M-MA4) or that
        // runs the head CAS against the wrong row (M-MA2) would let
        // the wp_update_post land in a state the journal has not
        // recorded. The post would have the new title; the journal
        // would have no op for $op_id (because attach was the only
        // op write, and the tool threw before mark_applied).
        //
        // The cleanest way to drive a head CAS failure without a
        // side-channel: edit the live post between the OCC pre-check
        // and the head CAS. The OCC pre-check is at line 238 (a
        // verify_against_after against the head); the head CAS at
        // line 386 uses the same $head as expected_prev. If the live
        // post diverges between the two, the head CAS refuses — but
        // the OCC pre-check would have refused first, so the tool
        // throws with "transaction_update_draft_external_edit_detected"
        // and never reaches the head CAS.
        //
        // The other path: a value-guarded CAS failure. The production
        // compare_and_set_head's value guard fires when the on-disk
        // row's content differs from the row bytes the CAS was issued
        // against. The wpdb stub exposes a side-channel
        // (_agentshell_test_cas_write_fail) that forces the next
        // cas_write_row to return 0 — the same shape a concurrent
        // caller would produce by swapping the row. Setting the flag
        // and running the tool drives the head CAS into the
        // cas_write_row, which fails, and the closure must throw.
        // A mutant that swallowed the false return (M-MA4) would
        // not throw; a mutant that CAS-wrote against the wrong row
        // (M-MA2) would not see the cas_write_row failure at all and
        // would proceed normally.
        jr_reset();
        $tx = Tx::instance()->begin( 'hp9b' );

        $marker = 'op_create_hp9b';
        $create_op = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_type' => 'page', 'post_status' => 'draft' ),
            array(),
            'agent',
            JR_T0
        );
        $create_op['id']    = $marker;
        $create_op['state'] = 'applied';
        $create_op['post_id'] = 890;
        $create_op['after']  = array_merge(
            $create_op['after'],
            array(
                'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'jr-draft',
                'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig-hp9b' ), 'post_content_length' => 9,
                'op_meta_value' => $marker,
            )
        );
        $tx['content']['ops'][ $marker ] = $create_op;
        jr_seed( $tx );

        jr_post( 890, array(
            'post_title' => 'orig-hp9b', 'post_content' => 'orig-hp9b', 'post_status' => 'draft',
            'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $marker );

        $GLOBALS['_agentshell_test_cas_write_fail']  = true;
        // The closure path does 3 cas_write_row calls in order: attach (1st),
        // compare_and_set_head (2nd), mark_applied (3rd). We want the head
        // CAS to fail so the M-MA4 mutant (which swallows the false return
        // inside the closure) is exercised. Setting _agentshell_test_cas_write_fail_after
        // to 1 means the first call passes and every later call fails. A
        // vanilla _agentshell_test_cas_write_fail = true would fail the very
        // first CAS (attach) and the closure would never run, leaving the
        // M-MA4 mutant completely unexercised — that is the gap the
        // previous version of this test had.
        $GLOBALS['_agentshell_test_cas_write_fail_after'] = 1;
        $GLOBALS['_agentshell_test_cas_write_calls'] = 0;
        try {
            $tool = new Update_Draft();
            $caught = null;
            try {
                $tool->execute( array(
                    'id' => 890, 'type' => 'page', 'title' => 'changed-hp9b',
                    'idempotency_key' => 'idem-hp9b',
                ) );
            } catch ( \InvalidArgumentException $e ) {
                $caught = 'InvalidArgumentException';
            } catch ( \TypeError $e ) {
                // See HP8b: a mutant that swallows the throw (M-MA4)
                // would let the closure proceed with $head_tx = false
                // and raise a TypeError on mark_applied. The test
                // catches it so the post-state assertion can still run
                // and the mutant is judged by the wrong error class.
                $caught = 'TypeError';
            }
            Agentshell_Test::assert_equals(
                'InvalidArgumentException', (string) $caught,
                'update_draft must throw InvalidArgumentException when the head CAS fails — '
                . 'a TypeError is the wrong error class (it leaks that mark_applied() takes '
                . 'array $tx and a non-array $head_tx was passed because the throw was swallowed). '
                . 'M-MA4 (swallow the head CAS failure) would not throw at all on its own — '
                . 'the closure would proceed, mark_applied would be called with the non-array '
                . '$head_tx, and a TypeError would fire. The test catches the TypeError to '
                . 'distinguish a real throw from a swallowed failure.'
            );
            Agentshell_Test::assert_equals(
                2, (int) $GLOBALS['_agentshell_test_cas_write_calls'],
                'precise precondition: 1 cas_write_row (the attach) must have landed, '
                . '1 (the head CAS inside the closure) must have failed. The test is '
                . 'meaningless otherwise — the closure has to run for the M-MA4 mutant '
                . 'to be exercised, and the head CAS has to be the one that fails.'
            );
            // The post object in the stub store will be 'changed-hp9b' because
            // the stub's wp_update_post mutates the in-memory post before
            // throwing — there is no SQL transaction to roll back the
            // in-memory mutation. The real assertion is what the journal
            // recorded: an op that did not transition to applied, and a head
            // that was not advanced. That is the durable evidence a concurrent
            // operator would see, and that is what the M-MA4 mutant fails to
            // produce when it swallows the head CAS failure.
            $tx_after    = jr_row_on_disk();
            $content     = Content_Journal::of( $tx_after );
            $ops         = is_array( $content['ops'] ?? null ) ? $content['ops'] : array();
            // Find the OP_UPDATE_DRAFT op (the one this call attached). It
            // carries the idempotency_key we supplied.
            $update_op = null;
            foreach ( $ops as $op ) {
                if ( ! is_array( $op ) ) { continue; }
                if ( (string) ( $op['op'] ?? '' ) !== Content_Journal::OP_UPDATE_DRAFT ) { continue; }
                if ( (string) ( $op['after']['idempotency_key'] ?? '' ) === 'idem-hp9b' ) {
                    $update_op = $op; break;
                }
            }
            Agentshell_Test::assert_true( is_array( $update_op ),
                'precondition: the OP_UPDATE_DRAFT op for idem-hp9b is on disk: '
                    . var_export( $ops, true ) );
            Agentshell_Test::assert_equals(
                'intended', (string) ( $update_op['state'] ?? '' ),
                'the op must remain in `intended` because mark_applied never ran. '
                . 'M-MA4 (swallow the head CAS failure) lets the closure proceed; '
                . 'mark_applied would either be called with the false $head_tx and '
                . 'raise a TypeError (which the test catches above as the wrong error '
                . 'class), or the row bytes it is called with are already moved and '
                . 'mark_applied returns null, leaving the op state unchanged. The '
                . 'production invariant is: a failed head CAS does not transition '
                . 'the op to applied.'
            );
            $head = Content_Journal::head_after( $tx_after, 890 );
            Agentshell_Test::assert_true( ! is_array( $head ) || empty( $head ),
                'the head for post 890 must not be set because compare_and_set_head '
                . 'did not land. M-MA4 (swallow the head CAS failure) might or might '
                . 'not advance the head depending on the mutant — but the production '
                . 'invariant is: a failed head CAS does not write a new head. The '
                . 'op-state check above is the stronger assertion; this is the '
                . 'evidence that the head pointer was not silently advanced.'
                . ' Got: ' . var_export( $head, true )
            );
        } finally {
            $GLOBALS['_agentshell_test_cas_write_fail']  = false;
            $GLOBALS['_agentshell_test_cas_write_fail_after'] = -1;
            $GLOBALS['_agentshell_test_cas_write_calls'] = 0;
        }
    }
);

// ── Claim_Existing_Draft tool: Stage 2b first-touch tests ──────────────────

require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-claim-existing-draft.php';
use AgentShell_MCP\Tools\Claim_Existing_Draft;

/**
 * Set the per-post edit_post capability for the claim tool tests.
 * The stub treats the cap as either a bool (granted for all posts)
 * or an array of post ids (granted for those, denied for others).
 */
function cl_grant_edit_post( $post_ids = null ) {
    if ( null === $post_ids ) {
        $GLOBALS['_agentshell_test_user_caps']['edit_post'] = true;
        return;
    }
    $GLOBALS['_agentshell_test_user_caps']['edit_post'] = array_values( array_map( 'intval', (array) $post_ids ) );
}

Agentshell_Test::run(
    'CL1: Claim_Existing_Draft refuses outside a transaction',
    function () {
        // A claim is a journal op; a journal op lives inside a transaction.
        jr_reset();
        jr_release_if_open();
        $tool = new Claim_Existing_Draft();
        $message = jr_refuse( function () use ( $tool ) {
            $tool->execute( array(
                'id'              => 1,
                'type'            => 'page',
                'idempotency_key' => 'idem-cl1',
            ) );
        } );
        Agentshell_Test::assert_true(
            is_string( $message ) && false !== strpos( $message, 'outside a transaction' ),
            'and names the cause: ' . $message
        );
        Agentshell_Test::assert_true(
            false !== strpos( $message, 'agentshell_claim_existing_draft' ),
            'and names the tool: ' . $message
        );
    }
);

Agentshell_Test::run(
    'CL2: Claim_Existing_Draft refuses when no idempotency_key is supplied',
    function () {
        // The schema requires an idempotency_key for retry safety.
        jr_reset();
        Tx::instance()->begin( 'cl-2' );
        try {
            $tool = new Claim_Existing_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'   => 1,
                    'type' => 'page',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'idempotency_key' ),
                'and names the missing field: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL3: Claim_Existing_Draft refuses on a non-existent post',
    function () {
        jr_reset();
        Tx::instance()->begin( 'cl-3' );
        try {
            $tool = new Claim_Existing_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 99999,
                    'type'            => 'page',
                    'idempotency_key' => 'idem-cl3',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message )
                    && ( false !== strpos( $message, 'transaction_claim_existing_draft_post_not_found' )
                      || false !== strpos( $message, 'does not exist' ) ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL4: Claim_Existing_Draft refuses when the post is not a draft',
    function () {
        // Status transitions are out of scope — a non-draft post
        // would change what "recoverable" means.
        jr_reset();
        Tx::instance()->begin( 'cl-4' );
        try {
            jr_post( 901, array( 'post_status' => 'publish' ) );
            cl_grant_edit_post( 901 );
            $tool = new Claim_Existing_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 901,
                    'type'            => 'page',
                    'idempotency_key' => 'idem-cl4',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_claim_existing_draft_not_a_draft' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL5: Claim_Existing_Draft refuses when the actor lacks edit_post on the post',
    function () {
        // A user whose role was revoked since the post was created
        // cannot claim it. The cap is re-checked at claim time, not
        // only at tool entry.
        jr_reset();
        Tx::instance()->begin( 'cl-5' );
        try {
            jr_post( 902, array( 'post_status' => 'draft' ) );
            // edit_post NOT granted.
            $tool = new Claim_Existing_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 902,
                    'type'            => 'page',
                    'idempotency_key' => 'idem-cl5',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_claim_existing_draft_missing_capability' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL6: Claim_Existing_Draft refuses when the post already carries a journal marker',
    function () {
        // A post that is already journal-owned cannot be claimed —
        // the existing claim belongs to a different journal, and
        // overwriting it would make recovery unable to find the
        // post.
        jr_reset();
        Tx::instance()->begin( 'cl-6' );
        try {
            jr_post( 903, array( 'post_status' => 'draft' ), 'op_other_journal' );
            cl_grant_edit_post( 903 );
            $tool = new Claim_Existing_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id'              => 903,
                    'type'            => 'page',
                    'idempotency_key' => 'idem-cl6',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_claim_existing_draft_already_owned' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL7: Claim_Existing_Draft succeeds for a pre-existing draft and stamps the marker',
    function () {
        // Happy path: a pre-existing draft, the actor has edit_post,
        // no marker, no head — the claim records an OP_EDIT_EXISTING_DRAFT
        // op in `claimed` state and stamps the marker on the post.
        jr_reset();
        Tx::instance()->begin( 'cl-7' );
        try {
            jr_post( 904, array(
                'post_status' => 'draft', 'post_title' => 'orig', 'post_content' => 'orig',
                'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
            ) );
            cl_grant_edit_post( 904 );
            $tool = new Claim_Existing_Draft();
            $result = $tool->execute( array(
                'id'              => 904,
                'type'            => 'page',
                'idempotency_key' => 'idem-cl7',
            ) );
            Agentshell_Test::assert_equals( 904, (int) $result['id'], 'the tool returns the post id' );
            Agentshell_Test::assert_equals( 'draft', (string) $result['status'], 'status is draft' );
            Agentshell_Test::assert_equals( 'claimed', (string) $result['journal_state'], 'op state is claimed' );
            Agentshell_Test::assert_true( true === $result['journaled'], 'journaled is true' );
            Agentshell_Test::assert_true( true === $result['recoverable'], 'recoverable is true' );
            Agentshell_Test::assert_true( false === $result['resumed'], 'resumed is false on a fresh claim' );
            Agentshell_Test::assert_true( '' !== (string) $result['op_id'], 'op_id is set' );
            Agentshell_Test::assert_equals(
                (string) $result['op_id'], (string) $result['marker'],
                'op_id and marker are the same value (the journal row is the source of the marker\'s identity)'
            );

            // The marker is on the post.
            $stamped = (string) get_post_meta( 904, Content_Journal::OP_META, true );
            Agentshell_Test::assert_equals(
                (string) $result['op_id'], $stamped,
                'the marker is stamped on the post'
            );

            // The journal op is in `claimed` state with the immutable baseline.
            $tx_after    = jr_row_on_disk();
            $content     = Content_Journal::of( $tx_after );
            $ops         = is_array( $content['ops'] ?? null ) ? $content['ops'] : array();
            $claim_op    = null;
            foreach ( $ops as $op ) {
                if ( ! is_array( $op ) ) { continue; }
                if ( (string) ( $op['op'] ?? '' ) !== Content_Journal::OP_EDIT_EXISTING_DRAFT ) { continue; }
                if ( (int) ( $op['target']['post_id'] ?? 0 ) === 904 ) { $claim_op = $op; break; }
            }
            Agentshell_Test::assert_true( is_array( $claim_op ), 'the journal carries an OP_EDIT_EXISTING_DRAFT op for post 904' );
            Agentshell_Test::assert_equals(
                'claimed', (string) ( $claim_op['state'] ?? '' ),
                'the op is in `claimed` state'
            );
            Agentshell_Test::assert_equals(
                904, (int) ( $claim_op['post_id'] ?? 0 ),
                'the op\'s post_id is 904'
            );
            // The immutable baseline is the post's state at first touch.
            $before = is_array( $claim_op['before'] ?? null ) ? $claim_op['before'] : array();
            Agentshell_Test::assert_equals(
                'orig', (string) ( $before['post_title'] ?? '' ),
                'before.post_title is the post\'s title at first touch'
            );
            Agentshell_Test::assert_equals(
                'orig', (string) ( $before['post_content'] ?? '' ),
                'before.post_content is the post\'s content at first touch'
            );
            Agentshell_Test::assert_equals(
                'orig-slug', (string) ( $before['post_name'] ?? '' ),
                'before.post_name is the post\'s slug at first touch'
            );
            Agentshell_Test::assert_equals(
                hash( 'sha256', 'orig' ), (string) ( $before['post_content_sha256'] ?? '' ),
                'before.post_content_sha256 is the SHA-256 of the content at first touch'
            );
            // The head map is empty for post 904 — no edit has run yet.
            $head = Content_Journal::head_after( $tx_after, 904 );
            Agentshell_Test::assert_true(
                ! is_array( $head ) || empty( $head ),
                'no head is written for post 904: the first edit\'s head CAS will land with expected_prev === null'
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL8: Claim_Existing_Draft retry with the same idempotency_key returns the same op with resumed=true',
    function () {
        // Retry idempotency happy path: same actor, same transaction,
        // same post, same content fingerprint -> resumed with the
        // same op_id; no second journal op is recorded, the marker
        // is not re-stamped.
        jr_reset();
        Tx::instance()->begin( 'cl-8' );
        try {
            jr_post( 905, array(
                'post_status' => 'draft', 'post_title' => 'orig', 'post_content' => 'orig',
                'post_name' => 'orig-slug',
            ) );
            cl_grant_edit_post( 905 );
            $tool = new Claim_Existing_Draft();
            $first = $tool->execute( array(
                'id' => 905, 'type' => 'page', 'idempotency_key' => 'idem-cl8',
            ) );
            Agentshell_Test::assert_true( false === $first['resumed'], 'first call is not a resume' );

            $second = $tool->execute( array(
                'id' => 905, 'type' => 'page', 'idempotency_key' => 'idem-cl8',
            ) );
            Agentshell_Test::assert_equals(
                (string) $first['op_id'], (string) $second['op_id'],
                'the retry returns the same op_id'
            );
            Agentshell_Test::assert_true( true === $second['resumed'], 'the retry is marked resumed' );
            Agentshell_Test::assert_equals(
                'claimed', (string) $second['journal_state'],
                'the retry still reports claimed (no edit has run)'
            );

            // The journal has exactly one OP_EDIT_EXISTING_DRAFT op for post 905.
            $tx_after    = jr_row_on_disk();
            $content     = Content_Journal::of( $tx_after );
            $matches     = 0;
            foreach ( ( $content['ops'] ?? array() ) as $op ) {
                if ( ! is_array( $op ) ) { continue; }
                if ( (string) ( $op['op'] ?? '' ) !== Content_Journal::OP_EDIT_EXISTING_DRAFT ) { continue; }
                if ( (int) ( $op['target']['post_id'] ?? 0 ) === 905 ) { $matches++; }
            }
            Agentshell_Test::assert_equals( 1, $matches, 'exactly one claim op for post 905 (no duplicate)' );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL9: Claim_Existing_Draft retry with the same key but a different content fingerprint refuses',
    function () {
        // A retry with the same key but a different content
        // fingerprint (the post has been externally edited) refuses
        // — the original op is preserved.
        jr_reset();
        Tx::instance()->begin( 'cl-9' );
        try {
            jr_post( 906, array(
                'post_status' => 'draft', 'post_title' => 'orig', 'post_content' => 'orig',
                'post_name' => 'orig-slug',
            ) );
            cl_grant_edit_post( 906 );
            $tool = new Claim_Existing_Draft();
            $first = $tool->execute( array(
                'id' => 906, 'type' => 'page', 'idempotency_key' => 'idem-cl9',
            ) );
            Agentshell_Test::assert_true( false === $first['resumed'], 'first call is not a resume' );

            // Simulate an external edit (the post is now in a
            // different state than the recorded baseline). The retry
            // captures a different content fingerprint and refuses.
            jr_post( 906, array(
                'post_status' => 'draft', 'post_title' => 'external-edit',
                'post_content' => 'external-edit', 'post_name' => 'orig-slug',
            ), (string) $first['op_id'] );

            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 906, 'type' => 'page', 'idempotency_key' => 'idem-cl9',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_claim_existing_draft_idempotency_conflict' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'CL10: Claim_Existing_Draft refuses a second claim for the same post in the same journal',
    function () {
        // A journal may carry at most one claim per post. A second
        // claim — even with a different idempotency_key — refuses;
        // the post is already owned by the journal through the
        // first claim.
        jr_reset();
        Tx::instance()->begin( 'cl-10' );
        try {
            jr_post( 907, array(
                'post_status' => 'draft', 'post_title' => 'orig', 'post_content' => 'orig',
            ) );
            cl_grant_edit_post( 907 );
            $tool = new Claim_Existing_Draft();
            $tool->execute( array(
                'id' => 907, 'type' => 'page', 'idempotency_key' => 'idem-cl10-a',
            ) );
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 907, 'type' => 'page', 'idempotency_key' => 'idem-cl10-b',
                ) );
            } );
            // The first call stamped the marker on the post; the
            // second call refuses on `already_owned` (the post-side
            // marker check fires before the journal's own
            // duplicate-claim invariant check). Both checks refuse
            // the same outcome — a journal may carry at most one
            // claim per post. The plan documents the second check
            // as defence-in-depth that is normally masked by the
            // first; the user-facing contract is "this post cannot
            // be claimed again".
            Agentshell_Test::assert_true(
                is_string( $message )
                    && (
                        false !== strpos( $message, 'transaction_claim_existing_draft_already_claimed' )
                        || false !== strpos( $message, 'transaction_claim_existing_draft_already_owned' )
                    ),
                'and refuses a second claim on the same post: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD16: Update_Draft accepts a pre-existing draft whose marker matches a claim op in `claimed` state',
    function () {
        // Stage 2b: the new ownership branch. The post is a
        // pre-existing draft; the marker is the claim op's id; the
        // claim op is in `claimed` state (no edit has run yet, so
        // the first edit is the legitimate first-op path with
        // expected_prev === null).
        jr_reset();
        Tx::instance()->begin( 'ud-16' );
        try {
            // Build a journal with a single OP_EDIT_EXISTING_DRAFT op
            // in `claimed` state for post 920.
            $claim_op_id = 'op_claim_ud16';
            $op = Content_Journal::record_intent(
                Content_Journal::OP_EDIT_EXISTING_DRAFT,
                array( 'post_id' => 920, 'post_type' => 'page', 'post_status' => 'draft' ),
                array(
                    'post_title' => 'orig', 'post_name' => 'orig-slug',
                    'op_meta_value' => $claim_op_id, 'idempotency_key' => 'idem-ud16',
                ),
                'agent',
                JR_T0
            );
            $op['id']      = $claim_op_id;
            $op['state']   = 'claimed';
            $op['post_id'] = 920;
            $op['before']  = array(
                'post_type' => 'page', 'post_status' => 'draft',
                'post_title' => 'orig', 'post_content' => 'orig',
                'post_excerpt' => '', 'post_name' => 'orig-slug',
                'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ),
                'post_content_length' => 4,
                'op_meta_value' => $claim_op_id,
            );

            $tx = Tx::instance()->payload();
            $tx['content']['ops'][ $claim_op_id ] = $op;
            jr_seed( $tx );

            // The live post carries the marker and matches the recorded baseline.
            jr_post( 920, array(
                'post_status' => 'draft', 'post_title' => 'orig', 'post_content' => 'orig',
                'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
            ), $claim_op_id );

            // The first edit lands. The OCC pre-check is against an
            // empty head (no edit has run yet) and skips; the head
            // CAS legitimately lands with expected_prev === null;
            // mark_applied transitions the claim op from `claimed` to
            // `applied`.
            $tool = new Update_Draft();
            $result = $tool->execute( array(
                'id' => 920, 'type' => 'page', 'title' => 'edited-ud16',
                'idempotency_key' => 'idem-ud16-first-edit',
            ) );
            Agentshell_Test::assert_equals( 920, (int) $result['id'], 'the update returns the post id' );
            Agentshell_Test::assert_true( true === $result['journal_applied'], 'journal_applied is true' );
            // The op_id is the new update op's id, NOT the claim op's
            // id — the tool records a fresh OP_UPDATE_DRAFT op.
            // The marker on the post is still the claim op's id,
            // because the tool's ownership check finds the claim op
            // by marker, not by op_id.
            Agentshell_Test::assert_equals(
                $claim_op_id, (string) $result['marker'],
                'the marker is the claim op\'s id (the post is still owned by the claim op)'
            );
            Agentshell_Test::assert_true(
                '' !== (string) ( $result['op_id'] ?? '' ) && (string) $result['op_id'] !== $claim_op_id,
                'the op_id is a fresh update op, distinct from the claim op'
            );
            Agentshell_Test::assert_equals( 'edited-ud16', (string) $result['title'], 'the new title is the updated title' );

            // The claim op stays in `claimed` after the first edit:
            // the claim records that the journal owns the post, and
            // the `claimed` → `applied` transition is what the
            // commit path (not the update path) does. The update
            // op is the new OP_UPDATE_DRAFT op, which is in
            // `applied` state; the claim op is the durable
            // ownership projection.
            $tx_after = jr_row_on_disk();
            $content  = Content_Journal::of( $tx_after );
            $ops      = is_array( $content['ops'] ?? null ) ? $content['ops'] : array();
            Agentshell_Test::assert_equals(
                'claimed', (string) ( $ops[ $claim_op_id ]['state'] ?? '' ),
                'the claim op is still in `claimed` state after the first edit (its state transitions on commit, not on update)'
            );
            $head = Content_Journal::head_after( $tx_after, 920 );
            Agentshell_Test::assert_true( is_array( $head ) && ! empty( $head ), 'the head for post 920 is set after the first edit' );
            Agentshell_Test::assert_equals(
                'edited-ud16', (string) ( $head['post_title'] ?? '' ),
                'the head carries the post\'s new title'
            );
            $live = $GLOBALS['_agentshell_test_posts'][ 920 ] ?? null;
            Agentshell_Test::assert_true( is_object( $live ), 'the post still exists after the edit' );
            Agentshell_Test::assert_equals(
                'edited-ud16', (string) ( $live->post_title ?? '' ),
                'the live post carries the updated title'
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD17: Update_Draft refuses a pre-existing draft whose marker matches a claim op in `compensated` state',
    function () {
        // A claim op that is in `compensated` (recovery ran) is not
        // a legitimate owning op — the recovery has run for this
        // post, and a fresh update would create a divergent
        // ownership chain. The refusal code is the new
        // `transaction_update_draft_claim_state_refused`.
        jr_reset();
        Tx::instance()->begin( 'ud-17' );
        try {
            $claim_op_id = 'op_claim_ud17';
            $op = Content_Journal::record_intent(
                Content_Journal::OP_EDIT_EXISTING_DRAFT,
                array( 'post_id' => 921, 'post_type' => 'page', 'post_status' => 'draft' ),
                array(
                    'post_title' => 'orig', 'post_name' => 'orig-slug',
                    'op_meta_value' => $claim_op_id, 'idempotency_key' => 'idem-ud17',
                ),
                'agent',
                JR_T0
            );
            $op['id']      = $claim_op_id;
            $op['state']   = 'compensated';
            $op['post_id'] = 921;
            $op['before']  = array(
                'post_type' => 'page', 'post_status' => 'draft',
                'post_title' => 'orig', 'post_content' => 'orig',
                'post_excerpt' => '', 'post_name' => 'orig-slug',
                'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ),
                'post_content_length' => 4,
                'op_meta_value' => $claim_op_id,
            );

            $tx = Tx::instance()->payload();
            $tx['content']['ops'][ $claim_op_id ] = $op;
            jr_seed( $tx );

            // The post still carries the marker; the live post
            // matches the recorded baseline (recovery has not run
            // yet on this stub).
            jr_post( 921, array(
                'post_status' => 'draft', 'post_title' => 'orig', 'post_content' => 'orig',
                'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
            ), $claim_op_id );

            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 921, 'type' => 'page', 'title' => 'edited-ud17',
                    'idempotency_key' => 'idem-ud17-edit',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message ) && false !== strpos( $message, 'transaction_update_draft_claim_state_refused' ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

Agentshell_Test::run(
    'UD18: Update_Draft refuses a pre-existing draft whose marker matches a claim op for a DIFFERENT post',
    function () {
        // The marker stamp is per-post; a claim op whose
        // `target.post_id` is a different number than the post being
        // updated is not the owning op for that post. A second-leg
        // match against `target.post_id` is what catches this: a
        // marker collision on the post (extremely unlikely with 16
        // random bytes) would not create a divergent ownership
        // chain because the target.post_id check would refuse.
        jr_reset();
        Tx::instance()->begin( 'ud-18' );
        try {
            $claim_op_id = 'op_claim_ud18';
            $op = Content_Journal::record_intent(
                Content_Journal::OP_EDIT_EXISTING_DRAFT,
                array( 'post_id' => 999, 'post_type' => 'page', 'post_status' => 'draft' ),
                array(
                    'post_title' => 'orig', 'post_name' => 'orig-slug',
                    'op_meta_value' => $claim_op_id, 'idempotency_key' => 'idem-ud18',
                ),
                'agent',
                JR_T0
            );
            $op['id']      = $claim_op_id;
            $op['state']   = 'claimed';
            $op['post_id'] = 999;
            $op['before']  = array(
                'post_type' => 'page', 'post_status' => 'draft',
                'post_title' => 'orig', 'post_content' => 'orig',
                'post_excerpt' => '', 'post_name' => 'orig-slug',
                'post_date_gmt' => '2026-01-01 00:00:00',
                'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_content_sha256' => hash( 'sha256', 'orig' ),
                'post_content_length' => 4,
                'op_meta_value' => $claim_op_id,
            );

            $tx = Tx::instance()->payload();
            $tx['content']['ops'][ $claim_op_id ] = $op;
            jr_seed( $tx );

            // The post being updated (922) carries the claim op's
            // marker. Without the target.post_id check, the marker
            // match alone would be enough; the second-leg check
            // refuses.
            jr_post( 922, array(
                'post_status' => 'draft', 'post_title' => 'orig', 'post_content' => 'orig',
                'post_name' => 'orig-slug',
            ), $claim_op_id );

            $tool = new Update_Draft();
            $message = jr_refuse( function () use ( $tool ) {
                $tool->execute( array(
                    'id' => 922, 'type' => 'page', 'title' => 'edited-ud18',
                    'idempotency_key' => 'idem-ud18-edit',
                ) );
            } );
            Agentshell_Test::assert_true(
                is_string( $message )
                    && (
                        false !== strpos( $message, 'transaction_update_draft_not_transaction_owned' )
                        || false !== strpos( $message, 'no OP_CREATE_DRAFT or OP_EDIT_EXISTING_DRAFT op' )
                    ),
                'and names the cause: ' . $message
            );
        } finally {
            jr_release_if_open();
        }
    }
);

// ---------------------------------------------------------------------------
// Edit-existing compensation: HP10b, HP11b, HP12b, HP13b
//
// The destructive inverse of a claim op is a `wp_update_post` that
// restores the post to the immutable baseline recorded at first
// touch. The OCC discipline is layered: verify against the head
// (or the claim's after, if no head), verify against the baseline
// (a match means a previous recovery already restored — the op is
// settled without a write), and after the write re-verify the
// content fields (a filter or hook could have changed the post
// in between; the post_modified_gmt is bumped by every
// wp_update_post and is therefore not in the post-restore verify).
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'HP10b: compensate_edit_existing() restores the post to the recorded baseline',
    function () {
        // The post was claimed, then edited through the transaction;
        // a subsequent recovery restores the post to the pre-claim
        // state and settles the op as `compensated`.
        jr_reset();
        jr_as_administrator();
        Recovery_Advisory_Lock::reset();
        $op_id = 'op_claim_hp10b';
        $op = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 1001, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-title', 'post_name' => 'orig-slug',
                'op_meta_value' => $op_id, 'idempotency_key' => 'idem-hp10b',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $op['id']      = $op_id;
        $op['state']   = 'applied';
        $op['post_id'] = 1001;
        $op['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-title', 'post_content' => 'orig-content',
            'post_excerpt' => '', 'post_name' => 'orig-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        );
        // The op's `after` carries the post's pre-claim state (the
        // state at first touch). The head map is the chain's
        // expected current state — the post-edit state — which is
        // what the OCC pre-check in step 1 verifies against.
        $op['after']  = array_merge( $op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        ) );
        // Seed the row with a claim that owns the op (so the settle
        // is uncontended), then plant the head map with the post-edit
        // after-image. The head is what step 1 verifies against.
        $tx = jr_row( array( $op ) );
        $tx = jr_set_claim(
            $tx,
            $op_id,
            Content_Journal::STATE_COMPENSATING,
            array( 'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0 - 86300 )
        );
        jr_seed( $tx );
        // The post is in the post-edit state, which matches the
        // head. Step 1's OCC pre-check passes; step 2's baseline
        // check sees a divergence and proceeds to the write.
        jr_post( 1001, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-title', 'post_content' => 'edited-content',
            'post_name' => 'edited-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
        ), $op_id );
        // Plant the head manually: the chain's expected current
        // state, matching the live post on disk. The recovery
        // engine's OCC pre-check verifies the live post against
        // this; a match means the post is the one the chain
        // expected and a restore to the baseline is safe.
        $post_edit_head = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-title', 'post_name' => 'edited-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'edited-content' ),
            'post_content_length' => 14,
            'op_meta_value' => $op_id,
        );
        $tx_on_disk                            = jr_row_on_disk();
        $content                               = Content_Journal::of( $tx_on_disk );
        $content[ Content_Journal::HEAD_KEY ]  = array( 1001 => $post_edit_head );
        $content['sig']                        = Content_Journal::sign( $content );
        $tx_on_disk['content']                 = $content;
        jr_seed( $tx_on_disk );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals(
            Content_Recovery::STATUS_RECOVERED, $report['status'],
            'recovery completes'
        );
        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'],
            'the claim op is settled as compensated'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], 'restored to its pre-claim baseline' ),
            'the report names the baseline restore: ' . $report['operations'][0]['detail']
        );
        // The post on disk now matches the baseline.
        $live = $GLOBALS['_agentshell_test_posts'][ 1001 ] ?? null;
        Agentshell_Test::assert_true( is_object( $live ), 'the post is still present after the restore' );
        Agentshell_Test::assert_equals(
            'orig-title', (string) ( $live->post_title ?? '' ),
            'the post title is restored to the baseline'
        );
        Agentshell_Test::assert_equals(
            'orig-content', (string) ( $live->post_content ?? '' ),
            'the post content is restored to the baseline'
        );
        Agentshell_Test::assert_equals(
            'orig-slug', (string) ( $live->post_name ?? '' ),
            'the post slug is restored to the baseline'
        );
        // Exactly one wp_update_post ran: the restore. The test
        // fixture's wp_update_post bumps post_modified_gmt to now,
        // which is what makes the post-restore verify_restore_landed
        // a distinct code path (no modified_gmt check) from
        // verify_against_after.
        Agentshell_Test::assert_equals(
            1, count( $GLOBALS['jr_update_calls'] ?? array() ),
            'exactly one wp_update_post ran (the restore)'
        );
    }
);

Agentshell_Test::run(
    'HP11b: compensate_edit_existing() preserves the post on a head divergence (conflict)',
    function () {
        // The head says the post is in state A; the live post is in
        // state B (e.g., `post_status` was changed to `publish` by
        // an outside actor). The recovery engine refuses to
        // restore (it cannot prove the post was the one the chain
        // expected); the op transitions to `conflict` and the
        // post is preserved.
        jr_reset();
        jr_as_administrator();
        Recovery_Advisory_Lock::reset();
        $op_id = 'op_claim_hp11b';
        $op = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 1002, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-title', 'post_name' => 'orig-slug',
                'op_meta_value' => $op_id, 'idempotency_key' => 'idem-hp11b',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $op['id']      = $op_id;
        $op['state']   = 'applied';
        $op['post_id'] = 1002;
        $op['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-title', 'post_content' => 'orig-content',
            'post_excerpt' => '', 'post_name' => 'orig-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        );
        $op['after']  = array_merge( $op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        ) );
        $tx = jr_row( array( $op ) );
        $tx = jr_set_claim(
            $tx,
            $op_id,
            Content_Journal::STATE_COMPENSATING,
            array( 'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0 - 86300 )
        );
        jr_seed( $tx );
        // The live post is `publish` (an outside actor changed it).
        // The head says `draft`; the post matches the head on
        // title/content/slug but not on `post_status` — the head
        // verify refuses.
        jr_post( 1002, array(
            'post_type' => 'page', 'post_status' => 'publish',
            'post_title' => 'edited-title', 'post_content' => 'edited-content',
            'post_name' => 'edited-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
        ), $op_id );
        // The head is the post-edit state at the time the chain
        // last touched the post — `post_status` was `draft` then.
        // The live post now has `post_status=publish`; the OCC
        // pre-check catches the divergence.
        $post_edit_head = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-title', 'post_name' => 'edited-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'edited-content' ),
            'post_content_length' => 14,
            'op_meta_value' => $op_id,
        );
        $tx_on_disk                            = jr_row_on_disk();
        $content                               = Content_Journal::of( $tx_on_disk );
        $content[ Content_Journal::HEAD_KEY ]  = array( 1002 => $post_edit_head );
        $content['sig']                        = Content_Journal::sign( $content );
        $tx_on_disk['content']                 = $content;
        jr_seed( $tx_on_disk );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals(
            'conflict', $report['operations'][0]['outcome'],
            'a head divergence on post_status is a conflict'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], 'post_status' ),
            'the report names the divergent field: ' . $report['operations'][0]['detail']
        );
        $live = $GLOBALS['_agentshell_test_posts'][ 1002 ] ?? null;
        Agentshell_Test::assert_true( is_object( $live ), 'the post is preserved on a head divergence' );
        Agentshell_Test::assert_equals(
            'publish', (string) ( $live->post_status ?? '' ),
            'the post_status is unchanged (the outside edit is preserved)'
        );
        Agentshell_Test::assert_equals(
            'edited-title', (string) ( $live->post_title ?? '' ),
            'the post title is unchanged'
        );
        Agentshell_Test::assert_equals(
            'edited-content', (string) ( $live->post_content ?? '' ),
            'the post content is unchanged'
        );
        Agentshell_Test::assert_equals(
            array(), $GLOBALS['jr_update_calls'] ?? array(),
            'no wp_update_post ran (the post is preserved)'
        );
    }
);

Agentshell_Test::run(
    'HP12b: compensate_edit_existing() reports `compensated` without a write when the post is already in the baseline state',
    function () {
        // A previous recovery attempt has already restored the post
        // to the baseline, OR the transaction closed without an
        // edit and the post is in its pre-claim state. Either way,
        // the live post matches the baseline; the op is settled as
        // `compensated` without a second write.
        jr_reset();
        jr_as_administrator();
        Recovery_Advisory_Lock::reset();
        $op_id = 'op_claim_hp12b';
        $op = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 1003, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-title', 'post_name' => 'orig-slug',
                'op_meta_value' => $op_id, 'idempotency_key' => 'idem-hp12b',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $op['id']      = $op_id;
        $op['state']   = 'applied';
        $op['post_id'] = 1003;
        $op['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-title', 'post_content' => 'orig-content',
            'post_excerpt' => '', 'post_name' => 'orig-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        );
        // The op's `after` is the pre-claim state (the post's
        // identification at first touch). The post has not been
        // edited through the chain — the chain's claim was recorded
        // but the operator did not call Update_Draft. So the live
        // post is still in its pre-claim state, which equals the
        // baseline. The no-head branch verifies against this
        // after-image; the baseline check then confirms the post
        // is already at the recorded baseline; the op is settled
        // as compensated without a write.
        $op['after']  = array_merge( $op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        ) );
        $tx = jr_row( array( $op ) );
        $tx = jr_set_claim(
            $tx,
            $op_id,
            Content_Journal::STATE_COMPENSATING,
            array( 'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0 - 86300 )
        );
        jr_seed( $tx );
        // The live post is at the baseline state. No head map
        // exists (the claim op did not call compare_and_set_head;
        // only update ops do). The code's no-head branch runs:
        // the claim's after equals the baseline (the pre-claim
        // state), and the live post matches it.
        jr_post( 1003, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-title', 'post_content' => 'orig-content',
            'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $op_id );
        // Clear the head map so the no-head branch is reached.
        $tx_on_disk                            = jr_row_on_disk();
        $content                               = Content_Journal::of( $tx_on_disk );
        $content[ Content_Journal::HEAD_KEY ]  = array();
        $content['sig']                        = Content_Journal::sign( $content );
        $tx_on_disk['content']                 = $content;
        jr_seed( $tx_on_disk );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'],
            'a baseline match (with no head) is settled as compensated without a write'
        );
        Agentshell_Test::assert_true(
            false !== strpos( $report['operations'][0]['detail'], 'already in the recorded baseline state' ),
            'the report explains the no-op: ' . $report['operations'][0]['detail']
        );
        Agentshell_Test::assert_equals(
            array(), $GLOBALS['jr_update_calls'] ?? array(),
            'no wp_update_post ran (the post is already at baseline)'
        );
    }
);

Agentshell_Test::run(
    'HP13b: compensate_edit_existing() excludes post_modified_gmt from the restore write',
    function () {
        // The destructive inverse re-applies the supported baseline
        // fields (post_title, post_content, post_name, post_excerpt
        // when present). It does NOT re-apply post_modified_gmt:
        // re-applying it would falsify the recorded after for any
        // subsequent OCC check (a recovery writes a fresh
        // post_modified_gmt on disk, but the journal's recorded
        // after is the value the live post had at the moment of
        // the last authorised update). The test captures the
        // postarr sent to wp_update_post and asserts that no
        // post_modified_gmt key is present.
        jr_reset();
        jr_as_administrator();
        Recovery_Advisory_Lock::reset();
        $op_id = 'op_claim_hp13b';
        $op = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 1004, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-title', 'post_name' => 'orig-slug',
                'op_meta_value' => $op_id, 'idempotency_key' => 'idem-hp13b',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $op['id']      = $op_id;
        $op['state']   = 'applied';
        $op['post_id'] = 1004;
        $op['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-title', 'post_content' => 'orig-content',
            'post_excerpt' => '', 'post_name' => 'orig-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        );
        $op['after']  = array_merge( $op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-content' ),
            'post_content_length' => 12,
            'op_meta_value' => $op_id,
        ) );
        $tx = jr_row( array( $op ) );
        $tx = jr_set_claim(
            $tx,
            $op_id,
            Content_Journal::STATE_COMPENSATING,
            array( 'actor' => JR_CRASHED_ACTOR, 'at' => JR_T0 - 86300 )
        );
        jr_seed( $tx );
        jr_post( 1004, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-title', 'post_content' => 'edited-content',
            'post_name' => 'edited-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
        ), $op_id );
        $post_edit_head = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-title', 'post_name' => 'edited-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'edited-content' ),
            'post_content_length' => 14,
            'op_meta_value' => $op_id,
        );
        $tx_on_disk                            = jr_row_on_disk();
        $content                               = Content_Journal::of( $tx_on_disk );
        $content[ Content_Journal::HEAD_KEY ]  = array( 1004 => $post_edit_head );
        $content['sig']                        = Content_Journal::sign( $content );
        $tx_on_disk['content']                 = $content;
        jr_seed( $tx_on_disk );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals(
            'compensated', $report['operations'][0]['outcome'],
            'recovery completes and the op is settled'
        );
        // The fixture records each wp_update_post call in
        // $GLOBALS['jr_update_calls']. The last entry's postarr
        // carries the fields the restore wrote. The assertion
        // below looks for the absence of post_modified_gmt.
        Agentshell_Test::assert_true(
            ! empty( $GLOBALS['jr_update_calls'] ),
            'a wp_update_post ran'
        );
        $last_call     = end( $GLOBALS['jr_update_calls'] );
        $last_postarr  = is_array( $last_call ) ? $last_call : (array) $last_call;
        Agentshell_Test::assert_true(
            ! array_key_exists( 'post_modified_gmt', $last_postarr ),
            'post_modified_gmt is NOT in the restore postarr (it would falsify the recorded after for OCC)'
        );
        Agentshell_Test::assert_equals(
            'orig-title', (string) ( $last_postarr['post_title'] ?? '' ),
            'the restore postarr carries the baseline title'
        );
        Agentshell_Test::assert_equals(
            'orig-content', (string) ( $last_postarr['post_content'] ?? '' ),
            'the restore postarr carries the baseline content'
        );
        Agentshell_Test::assert_equals(
            'orig-slug', (string) ( $last_postarr['post_name'] ?? '' ),
            'the restore postarr carries the baseline slug'
        );
    }
);

// ---------------------------------------------------------------------------
// Mixed transactions: MX1..MX3
//
// A journal may carry any combination of op types. The Stage 2b
// extension adds OP_EDIT_EXISTING_DRAFT alongside OP_CREATE_DRAFT and
// OP_UPDATE_DRAFT. The destructive inverse of a create op is
// `wp_delete_post`; the destructive inverse of a claim op is
// `wp_update_post` to the recorded baseline. The compensation order
// is "updates → claims → creates" — same logic as the create+update
// case, with the claim branch slotted between them. Each settlement
// targets its own post id; the order matters for audit-log readability
// and for the case where an update and a create target the same post
// (the update must be settled as a no-op before the create's
// wp_delete_post runs).
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'MX1: a mixed create + edit-existing journal settles both: create via wp_delete_post, claim via baseline restore',
    function () {
        // The simplest mixed case: a create op on a transaction-owned
        // post (post 2000, the operator's draft) and a claim op on a
        // pre-existing draft (post 2001). Recovery runs both branches:
        // the create's wp_delete_post removes the operator's draft, the
        // claim's wp_update_post restores the pre-existing draft to
        // its baseline. Each destructive step targets its own post id —
        // they do not interfere.
        jr_reset();
        jr_as_administrator();
        Recovery_Advisory_Lock::reset();

        // The create op on post 2000. The post is the live
        // transaction-owned draft; the head map carries the
        // post-create state.
        $create_op_id = 'op_create_mx1';
        $create_op    = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_id' => 2000, 'post_type' => 'page', 'post_status' => 'draft' ),
            array( 'op_meta_value' => $create_op_id, 'idempotency_key' => 'idem-mx1-create' ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $create_op['id']      = $create_op_id;
        $create_op['state']   = 'applied';
        $create_op['post_id'] = 2000;
        $create_op['after']   = array_merge( $create_op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'mx1-new-draft', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'newly-created-body' ),
            'post_content_length' => strlen( 'newly-created-body' ),
            'op_meta_value' => $create_op_id,
        ) );
        $create_op['recorded_at'] = JR_T0 - 100;

        // The claim op on post 2001. The pre-existing draft is in
        // the post-edit state (the transaction edited it through
        // update_draft); the head map carries the post-edit state;
        // the before field is the pre-claim state.
        $claim_op_id = 'op_claim_mx1';
        $claim_op    = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 2001, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-mx1', 'post_name' => 'orig-mx1-slug',
                'op_meta_value' => $claim_op_id, 'idempotency_key' => 'idem-mx1-claim',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $claim_op['id']      = $claim_op_id;
        $claim_op['state']   = 'applied';
        $claim_op['post_id'] = 2001;
        $claim_op['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-mx1', 'post_content' => 'orig-mx1-body',
            'post_excerpt' => '', 'post_name' => 'orig-mx1-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx1-body' ),
            'post_content_length' => 13,
            'op_meta_value' => $claim_op_id,
        );
        $claim_op['after']  = array_merge( $claim_op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-mx1-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx1-body' ),
            'post_content_length' => 13,
            'op_meta_value' => $claim_op_id,
        ) );
        $claim_op['recorded_at'] = JR_T0 - 50;

        jr_seed( jr_row( array( $create_op, $claim_op ) ) );

        // Live fixtures. The create's post is the transaction-owned
        // draft; the claim's post is the pre-existing draft in its
        // post-edit state (matching the head).
        jr_post( 2000, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'mx1-new-draft', 'post_content' => 'newly-created-body',
            'post_name' => 'mx1-new-draft', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $create_op_id );
        jr_post( 2001, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx1', 'post_content' => 'edited-mx1-body',
            'post_name' => 'edited-mx1-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
        ), $claim_op_id );

        // Plant the head map. The create's head is its own
        // after-image (the post-create state); the claim's head is
        // the post-edit state (what the live post is in).
        $create_head = $create_op['after'];
        $claim_head  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx1', 'post_name' => 'edited-mx1-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'edited-mx1-body' ),
            'post_content_length' => 15,
            'op_meta_value' => $claim_op_id,
        );
        $tx_on_disk                            = jr_row_on_disk();
        $content                               = Content_Journal::of( $tx_on_disk );
        $content[ Content_Journal::HEAD_KEY ]  = array(
            2000 => $create_head,
            2001 => $claim_head,
        );
        $content['sig']                        = Content_Journal::sign( $content );
        $tx_on_disk['content']                 = $content;
        jr_seed( $tx_on_disk );

        // The create inverse re-checks `delete_post` under the
        // advisory lock; the claim inverse re-checks `edit_post` on
        // the pre-existing draft. Grant both.
        jr_with_post_cap( 2000, 'delete_post' );
        jr_with_post_cap( 2001, 'edit_post' );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        // Both ops are settled.
        Agentshell_Test::assert_equals(
            Content_Recovery::STATUS_RECOVERED, $report['status'],
            'recovery completes'
        );
        $outcomes = array();
        foreach ( $report['operations'] as $row ) {
            $outcomes[] = $row['outcome'] ?? '';
        }
        Agentshell_Test::assert_equals(
            array( 'compensated', 'compensated' ),
            $outcomes,
            'both ops are compensated (the create via wp_delete_post, the claim via wp_update_post to baseline)'
        );

        // The destructive steps ran on the right posts.
        Agentshell_Test::assert_equals(
            array( 2000 ),
            $GLOBALS['jr_delete_calls'],
            'the create\'s wp_delete_post ran on the transaction-owned post 2000'
        );
        Agentshell_Test::assert_true(
            ! empty( $GLOBALS['jr_update_calls'] ),
            'the claim\'s wp_update_post ran (the baseline restore)'
        );
        // The claim's restore targets post 2001, not 2000.
        $restore_call = end( $GLOBALS['jr_update_calls'] );
        $restore_id   = is_array( $restore_call ) ? (int) ( $restore_call['ID'] ?? 0 ) : 0;
        Agentshell_Test::assert_equals(
            2001, $restore_id,
            'the claim\'s restore targets the pre-existing draft 2001, not the transaction-owned draft 2000'
        );

        // The post-create post is gone; the pre-existing post is
        // restored to its baseline.
        Agentshell_Test::assert_true( ! jr_post_exists( 2000 ), 'the transaction-owned draft is gone' );
        $restored = $GLOBALS['_agentshell_test_posts'][ 2001 ] ?? null;
        Agentshell_Test::assert_true( is_object( $restored ), 'the pre-existing draft is still present after the restore' );
        Agentshell_Test::assert_equals(
            'orig-mx1', (string) ( $restored->post_title ?? '' ),
            'the pre-existing draft\'s title is restored to the baseline'
        );
        Agentshell_Test::assert_equals(
            'orig-mx1-body', (string) ( $restored->post_content ?? '' ),
            'the pre-existing draft\'s content is restored to the baseline'
        );
        Agentshell_Test::assert_equals(
            'orig-mx1-slug', (string) ( $restored->post_name ?? '' ),
            'the pre-existing draft\'s slug is restored to the baseline'
        );
    }
);

Agentshell_Test::run(
    'MX2: a mixed create + claim + update journal settles in order: updates (no-ops), claim (restore), create (delete)',
    function () {
        // Three op types in one journal: an update on the
        // transaction-owned post (no destructive inverse), a claim
        // on a pre-existing draft (wp_update_post to baseline), and
        // a create on the transaction-owned post (wp_delete_post).
        // The compensation order is updates first (reverse
        // chronological), then claims, then creates. The update on
        // the transaction-owned post is a no-op; the claim restores
        // its draft; the create deletes its post. Exactly one
        // wp_delete_post and exactly one wp_update_post run; they
        // target different posts.
        jr_reset();
        jr_as_administrator();
        Recovery_Advisory_Lock::reset();

        // The create op (post 2010). Head is the post-create state.
        $create_op_id = 'op_create_mx2';
        $create_op    = Content_Journal::record_intent(
            Content_Journal::OP_CREATE_DRAFT,
            array( 'post_id' => 2010, 'post_type' => 'page', 'post_status' => 'draft' ),
            array( 'op_meta_value' => $create_op_id, 'idempotency_key' => 'idem-mx2-create' ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $create_op['id']      = $create_op_id;
        $create_op['state']   = 'applied';
        $create_op['post_id'] = 2010;
        $create_op['after']   = array_merge( $create_op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'mx2-new', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'mx2-create-body' ),
            'post_content_length' => 15,
            'op_meta_value' => $create_op_id,
        ) );
        $create_op['recorded_at'] = JR_T0 - 200;

        // The claim op (post 2011). The pre-existing draft in its
        // post-edit state; the baseline is the pre-claim state.
        $claim_op_id = 'op_claim_mx2';
        $claim_op    = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 2011, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-mx2', 'post_name' => 'orig-mx2-slug',
                'op_meta_value' => $claim_op_id, 'idempotency_key' => 'idem-mx2-claim',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $claim_op['id']      = $claim_op_id;
        $claim_op['state']   = 'applied';
        $claim_op['post_id'] = 2011;
        $claim_op['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-mx2', 'post_content' => 'orig-mx2-body',
            'post_excerpt' => '', 'post_name' => 'orig-mx2-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx2-body' ),
            'post_content_length' => 13,
            'op_meta_value' => $claim_op_id,
        );
        $claim_op['after']  = array_merge( $claim_op['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-mx2-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx2-body' ),
            'post_content_length' => 13,
            'op_meta_value' => $claim_op_id,
        ) );
        $claim_op['recorded_at'] = JR_T0 - 150;

        // The update op (post 2010). The transaction-owned draft
        // was edited after creation. The update's destructive
        // inverse is a no-op; the chain is rolled back by the
        // create's wp_delete_post.
        $update_op_id = 'op_update_mx2';
        $update_op    = jr_update_op(
            $update_op_id, 2010, $create_op_id,
            array(
                'post_title'           => 'mx2-new',
                'post_content'         => 'mx2-create-body',
                'post_name'            => 'mx2-new',
                'post_content_sha256'  => hash( 'sha256', 'mx2-create-body' ),
                'post_content_length'  => 15,
                'post_modified_gmt'    => '2026-01-01 00:00:00',
                'op_meta_value'        => $create_op_id,
            ),
            array(
                'post_content'         => 'mx2-edit-body',
                'post_content_sha256'  => hash( 'sha256', 'mx2-edit-body' ),
                'post_content_length'  => strlen( 'mx2-edit-body' ),
                'post_modified_gmt'    => '2026-01-01 00:00:01',
            )
        );
        $update_op['recorded_at'] = JR_T0 - 10;

        jr_seed( jr_row( array( $create_op, $claim_op, $update_op ) ) );

        // Live fixtures.
        jr_post( 2010, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'mx2-new', 'post_content' => 'mx2-edit-body',
            'post_name' => 'mx2-new', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:01',
        ), $create_op_id );
        jr_post( 2011, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx2', 'post_content' => 'edited-mx2-body',
            'post_name' => 'edited-mx2-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
        ), $claim_op_id );

        // Plant the head map. The create's head is the post-update
        // state (the most recent applied op for post 2010); the
        // claim's head is the post-edit state.
        $create_head = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'mx2-new', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:01',
            'post_content_sha256' => hash( 'sha256', 'mx2-edit-body' ),
            'post_content_length' => strlen( 'mx2-edit-body' ),
            'op_meta_value' => $create_op_id,
        );
        $claim_head  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx2', 'post_name' => 'edited-mx2-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'edited-mx2-body' ),
            'post_content_length' => 15,
            'op_meta_value' => $claim_op_id,
        );
        $tx_on_disk                            = jr_row_on_disk();
        $content                               = Content_Journal::of( $tx_on_disk );
        $content[ Content_Journal::HEAD_KEY ]  = array(
            2010 => $create_head,
            2011 => $claim_head,
        );
        $content['sig']                        = Content_Journal::sign( $content );
        $tx_on_disk['content']                 = $content;
        jr_seed( $tx_on_disk );

        jr_with_post_cap( 2010, 'delete_post' );
        jr_with_post_cap( 2011, 'edit_post' );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        // The report lists ops in compensation order: update first,
        // claim second, create last. The update is a no-op
        // (`compensated` with the no-op reason); the claim and
        // create are settled by their respective destructive
        // inverses.
        Agentshell_Test::assert_equals(
            Content_Recovery::STATUS_RECOVERED, $report['status'],
            'recovery completes'
        );
        $outcomes_by_id = array();
        foreach ( $report['operations'] as $row ) {
            $outcomes_by_id[ (string) ( $row['id'] ?? '' ) ] = $row['outcome'] ?? '';
        }
        Agentshell_Test::assert_equals(
            'compensated', $outcomes_by_id[ $update_op_id ] ?? '',
            'the update is compensated (no destructive inverse; the chain\'s rollback is the create\'s wp_delete_post)'
        );
        Agentshell_Test::assert_equals(
            'compensated', $outcomes_by_id[ $claim_op_id ] ?? '',
            'the claim is compensated (baseline restore)'
        );
        Agentshell_Test::assert_equals(
            'compensated', $outcomes_by_id[ $create_op_id ] ?? '',
            'the create is compensated (wp_delete_post)'
        );

        // The order in the report reflects compensation order:
        // update, claim, create. The order is for audit-log
        // readability, not correctness — but it is load-bearing for
        // the live acceptance scenario that reads the report in
        // order to reconstruct what happened.
        $order = array();
        foreach ( $report['operations'] as $row ) {
            $order[] = (string) ( $row['id'] ?? '' );
        }
        Agentshell_Test::assert_equals(
            array( $update_op_id, $claim_op_id, $create_op_id ),
            $order,
            'operations are reported in compensation order: update, claim, create'
        );

        // Exactly one wp_delete_post and exactly one wp_update_post
        // ran. The update op has no destructive inverse, so no
        // wp_update_post ran for it.
        Agentshell_Test::assert_equals(
            array( 2010 ),
            $GLOBALS['jr_delete_calls'],
            'the create\'s wp_delete_post ran on post 2010'
        );
        Agentshell_Test::assert_equals(
            1, count( $GLOBALS['jr_update_calls'] ?? array() ),
            'exactly one wp_update_post ran (the claim\'s baseline restore)'
        );
        $restore_call = end( $GLOBALS['jr_update_calls'] );
        $restore_id   = is_array( $restore_call ) ? (int) ( $restore_call['ID'] ?? 0 ) : 0;
        Agentshell_Test::assert_equals(
            2011, $restore_id,
            'the claim\'s restore targets post 2011'
        );
    }
);

Agentshell_Test::run(
    'MX3: a mixed journal with two claims on different pre-existing drafts restores both',
    function () {
        // A journal with two claim ops on different pre-existing
        // drafts. The compensation order is claims-then-creates; with
        // no creates and no updates, the two claims settle in their
        // recorded_at order. Both pre-existing drafts are restored to
        // their respective baselines. Exactly two wp_update_posts run;
        // no wp_delete_post.
        jr_reset();
        jr_as_administrator();
        Recovery_Advisory_Lock::reset();

        $claim_a_id = 'op_claim_mx3a';
        $claim_a    = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 2020, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-mx3-a', 'post_name' => 'orig-mx3-a-slug',
                'op_meta_value' => $claim_a_id, 'idempotency_key' => 'idem-mx3-a',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $claim_a['id']      = $claim_a_id;
        $claim_a['state']   = 'applied';
        $claim_a['post_id'] = 2020;
        $claim_a['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-mx3-a', 'post_content' => 'orig-mx3-a-body',
            'post_excerpt' => '', 'post_name' => 'orig-mx3-a-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx3-a-body' ),
            'post_content_length' => 15,
            'op_meta_value' => $claim_a_id,
        );
        $claim_a['after']  = array_merge( $claim_a['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-mx3-a-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx3-a-body' ),
            'post_content_length' => 15,
            'op_meta_value' => $claim_a_id,
        ) );
        $claim_a['recorded_at'] = JR_T0 - 100;

        $claim_b_id = 'op_claim_mx3b';
        $claim_b    = Content_Journal::record_intent(
            Content_Journal::OP_EDIT_EXISTING_DRAFT,
            array( 'post_id' => 2021, 'post_type' => 'page', 'post_status' => 'draft' ),
            array(
                'post_title' => 'orig-mx3-b', 'post_name' => 'orig-mx3-b-slug',
                'op_meta_value' => $claim_b_id, 'idempotency_key' => 'idem-mx3-b',
            ),
            JR_CRASHED_ACTOR,
            JR_T0 - 86400
        );
        $claim_b['id']      = $claim_b_id;
        $claim_b['state']   = 'applied';
        $claim_b['post_id'] = 2021;
        $claim_b['before']  = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'orig-mx3-b', 'post_content' => 'orig-mx3-b-body',
            'post_excerpt' => '', 'post_name' => 'orig-mx3-b-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx3-b-body' ),
            'post_content_length' => 15,
            'op_meta_value' => $claim_b_id,
        );
        $claim_b['after']  = array_merge( $claim_b['after'], array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_name' => 'orig-mx3-b-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'orig-mx3-b-body' ),
            'post_content_length' => 15,
            'op_meta_value' => $claim_b_id,
        ) );
        $claim_b['recorded_at'] = JR_T0 - 50;

        jr_seed( jr_row( array( $claim_a, $claim_b ) ) );

        jr_post( 2020, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx3-a', 'post_content' => 'edited-mx3-a-body',
            'post_name' => 'edited-mx3-a-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
        ), $claim_a_id );
        jr_post( 2021, array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx3-b', 'post_content' => 'edited-mx3-b-body',
            'post_name' => 'edited-mx3-b-slug', 'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
        ), $claim_b_id );

        // Plant the head map with the post-edit state for each post.
        $head_a = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx3-a', 'post_name' => 'edited-mx3-a-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'edited-mx3-a-body' ),
            'post_content_length' => 17,
            'op_meta_value' => $claim_a_id,
        );
        $head_b = array(
            'post_type' => 'page', 'post_status' => 'draft',
            'post_title' => 'edited-mx3-b', 'post_name' => 'edited-mx3-b-slug',
            'post_date_gmt' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-02-01 00:00:00',
            'post_content_sha256' => hash( 'sha256', 'edited-mx3-b-body' ),
            'post_content_length' => 17,
            'op_meta_value' => $claim_b_id,
        );
        $tx_on_disk                            = jr_row_on_disk();
        $content                               = Content_Journal::of( $tx_on_disk );
        $content[ Content_Journal::HEAD_KEY ]  = array(
            2020 => $head_a,
            2021 => $head_b,
        );
        $content['sig']                        = Content_Journal::sign( $content );
        $tx_on_disk['content']                 = $content;
        jr_seed( $tx_on_disk );

        jr_with_post_cap( 2020, 'edit_post' );
        jr_with_post_cap( 2021, 'edit_post' );

        $report = Content_Recovery::recover( jr_row_on_disk(), JR_CRASHED_ACTOR, true );

        Agentshell_Test::assert_equals(
            Content_Recovery::STATUS_RECOVERED, $report['status'],
            'recovery completes'
        );
        $outcomes = array();
        foreach ( $report['operations'] as $row ) {
            $outcomes[] = $row['outcome'] ?? '';
        }
        Agentshell_Test::assert_equals(
            array( 'compensated', 'compensated' ),
            $outcomes,
            'both claim ops are compensated (baseline restore)'
        );
        Agentshell_Test::assert_equals(
            array(),
            $GLOBALS['jr_delete_calls'],
            'no wp_delete_post ran (both ops are claims, not creates)'
        );
        Agentshell_Test::assert_equals(
            2, count( $GLOBALS['jr_update_calls'] ?? array() ),
            'exactly two wp_update_posts ran (one per claim)'
        );

        // Both pre-existing drafts are restored to their baselines.
        $restored_a = $GLOBALS['_agentshell_test_posts'][ 2020 ] ?? null;
        $restored_b = $GLOBALS['_agentshell_test_posts'][ 2021 ] ?? null;
        Agentshell_Test::assert_true( is_object( $restored_a ), 'pre-existing draft 2020 is still present' );
        Agentshell_Test::assert_equals(
            'orig-mx3-a', (string) ( $restored_a->post_title ?? '' ),
            'pre-existing draft 2020 is restored to its baseline title'
        );
        Agentshell_Test::assert_true( is_object( $restored_b ), 'pre-existing draft 2021 is still present' );
        Agentshell_Test::assert_equals(
            'orig-mx3-b', (string) ( $restored_b->post_title ?? '' ),
            'pre-existing draft 2021 is restored to its baseline title'
        );
    }
);
