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
    'class-content-recovery.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-get-journal.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-recover-transaction.php';

use AgentShell_MCP\Content_Journal;
use AgentShell_MCP\Content_Recovery;
use AgentShell_MCP\Content_Write_Guard;
use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Get_Journal;
use AgentShell_MCP\Tools\Recover_Transaction;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "content journal recovery tests\n";

define( 'JR_T0', 1800000000 );
define( 'JR_CRASHED_ACTOR', 'crashed-agent' );

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

    $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] = array( '--jr-accent' => '#123456' );
}

/** Grant the capability the MCP transport would already have enforced. */
function jr_as_administrator() {
    $GLOBALS['_agentshell_test_user_caps'] = array( 'manage_options' => true );
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
    'a crash after the mutation is recovered by finding the post through its marker',
    function () {
        jr_reset();
        jr_as_administrator();
        // The journal never learned the id: this is the write that happened and
        // the mark_applied() that did not.
        $op = jr_op( 'intended', 0 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 402, array(), $op['id'] );

        $report = Content_Recovery::recover( jr_row_on_disk(), 'recovery-admin', true );

        Agentshell_Test::assert_equals( 'compensated', $report['operations'][0]['outcome'], 'it must be compensated' );
        Agentshell_Test::assert_equals( 402, (int) $report['operations'][0]['deleted_post_id'], 'post 402 must be the one deleted' );
        Agentshell_Test::assert_false( jr_post_exists( 402 ), 'the orphan draft must be gone' );
        Agentshell_Test::assert_equals( array( 402 ), $GLOBALS['jr_delete_calls'], 'exactly one delete' );
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
    'inspection is open, and it renews, reclaims and resolves nothing',
    function () {
        jr_reset();
        $op = jr_op( 'applied', 419 );
        jr_seed( jr_row( array( $op ) ) );
        jr_post( 419, array(), $op['id'] );
        jr_actor( 'curious-peer' );

        $before = jr_row_on_disk();
        $tool   = new Get_Journal();
        $view   = $tool->execute( array() );

        Agentshell_Test::assert_true( $view['read_only'], 'inspection declares itself read-only' );
        Agentshell_Test::assert_true( $view['recoverable'], 'and reports that recovery is possible' );
        Agentshell_Test::assert_equals( 419, $view['operations'][0]['post_id'], 'including which post is at stake' );
        Agentshell_Test::assert_equals( 1, (int) $view['operation_count'], 'and how much is at stake' );

        // Reading must not have moved anything: no renewal of the dead lease,
        // no reclaim, no deletion, no journal advance.
        $after = jr_row_on_disk();
        Agentshell_Test::assert_equals( $before, $after, 'inspection must leave the transaction row byte-identical' );
        Agentshell_Test::assert_true( jr_post_exists( 419 ), 'and no content may be touched' );

        // And the same peer still cannot resolve it.
        $refused = Content_Recovery::recover( $after, 'curious-peer', false );
        Agentshell_Test::assert_equals( Content_Recovery::STATUS_REFUSED, $refused['status'], 'a peer cannot resolve it' );
        Agentshell_Test::assert_true( jr_post_exists( 419 ), 'the draft survives the refusal' );
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