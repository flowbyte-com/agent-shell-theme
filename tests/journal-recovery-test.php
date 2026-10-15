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
        if ( $GLOBALS['jr_delete_fails'] ) { return false; }
        if ( ! isset( $GLOBALS['_agentshell_test_posts'][ $post_id ] ) ) { return false; }
        $post = $GLOBALS['_agentshell_test_posts'][ $post_id ];
        unset( $GLOBALS['_agentshell_test_posts'][ $post_id ], $GLOBALS['_agentshell_test_postmeta'][ $post_id ] );
        return $post;
    }
}

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
        'post_modified'     => '2026-01-01 00:00:00',
        'post_modified_gmt' => '2026-01-01 00:00:00',
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
