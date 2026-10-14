<?php
/**
 * Transaction lease and stale-recovery regressions.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * A transaction lives in exactly one option row, and until this lease existed
 * nothing removed that row except a commit() or a rollback() the owner actually
 * reached. A PHP process that died between begin() and commit() therefore left
 * the row behind, and every later caller was refused with "another actor has an
 * open transaction" — forever. One crashed turn wedged the site, get_transaction
 * reported the row as a perfectly normal open transaction, and the only exit
 * was a direct database edit by somebody with no other way in.
 *
 * What is asserted here
 * ---------------------
 * Four things, and the fourth is the one that is easy to assume and hard to be
 * right about:
 *
 *   1. Expiry is *effective*. An expired transaction does not stage, does not
 *      preview, and does not block a new one.
 *   2. Recovery is *auditable*. A reclaimed transaction is named in the result
 *      and in the audit log, because a silent fix is the same as no fix when
 *      somebody later asks why their work disappeared.
 *   3. Recovery is *safe*. A transaction holding work whose only copy is its
 *      own journal is never discarded, however old it is.
 *   4. Recovery is *atomic*. WordPress's option APIs are read-then-write, so
 *      these tests pin that the guarded form is what actually happens, and that
 *      a caller acting on a value that has since moved on cannot destroy
 *      something newer.
 *
 * Time is controlled, not slept through. LEASE_TTL_SECONDS is an hour, so every
 * expiry assertion would otherwise need an hour; the clock seam exists for
 * exactly this and is confined to the lease. One test asserts it stayed
 * confined — a seam that could also move the clock the preview signer reads
 * would be a seam that could stretch a bearer capability's window.
 *
 * Nothing here touches the live site or the network.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'lease-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    $d = sys_get_temp_dir() . '/agentshell-lease-wpcontent-' . getmypid();
    @mkdir( $d, 0700, true );
    define( 'WP_CONTENT_DIR', $d );
}
if ( ! defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
    define( 'AGENTSHELL_SCREENSHOT_DIR', sys_get_temp_dir() . '/agentshell-lease-store-' . getmypid() );
}

$GLOBALS['_agentshell_test_uploads'] = array(
    'basedir' => '', 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => '',
);
$GLOBALS['_agentshell_test_login']  = 'agent';
$GLOBALS['wp_version']              = '6.5';

foreach ( array( 'class-store.php', 'class-doctor.php', 'class-transactions.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-begin-transaction.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-get-transaction.php';

use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;
use AgentShell_MCP\Tools\Begin_Transaction;
use AgentShell_MCP\Tools\Get_Transaction;

// Doctor's environment check looks for this to decide the theme is active. The
// suite deliberately loads the plugin classes and not functions.php, so it
// provides the marker rather than the whole theme.
if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "transaction lease regression tests\n";

// A fixed instant, so nothing here depends on when the suite runs. It is in the
// future relative to the wall clock on purpose — see the credential test.
define( 'LEASE_T0', 1800000000 );

/**
 * The two configs, as PHP values.
 *
 * Shaped as CSS custom properties because that is what the token diff reads:
 * Store::flatten() collects only keys beginning with `--`, so a fixture written
 * as nested design sections would diff to nothing and every "the peer can see
 * the staged change" assertion would pass for the wrong reason.
 */
function lease_committed() { return array( '--lease-accent' => '#123456', '--lease-radius' => '6px' ); }
function lease_staged()    { return array( '--lease-accent' => '#ff00ff', '--lease-radius' => '6px' ); }

/**
 * Reset to a known site with no transaction, clock frozen at $now.
 *
 * Also clears Store's static history state: those survive
 * agentshell_test_reset() because they are properties rather than option rows,
 * and a leaked $suppress_history would silence the audit entries this suite
 * reads.
 */
function lease_reset( $now = LEASE_T0 ) {
    agentshell_test_reset();
    Tx::unfreeze_time();
    Tx::freeze_time( $now );
    Store::$suppress_history = false;
    Store::$operation_label   = '';
    $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] = lease_committed();
    $GLOBALS['_agentshell_test_login']                        = 'agent';
}

function lease_advance( $seconds ) {
    Tx::freeze_time( LEASE_T0 + $seconds );
}

/** Open a transaction and stage a visible change, the ordinary way. */
function lease_open_staged() {
    $mgr = Tx::instance();
    $tx  = $mgr->begin( 'lease test' );
    $mgr->stage( lease_staged() );
    return $tx;
}

/** The stored row, read past any manager state. */
function lease_row() {
    return $GLOBALS['_agentshell_test_options'][ Tx::KEY ] ?? null;
}

function lease_config() {
    return $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] ?? null;
}

/** Seed a row directly, as a pre-upgrade install or a crashed session leaves one. */
function lease_seed( $row ) {
    $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = $row;
}

/** A row as an interrupted session leaves it: no lease fields, staged work, never committed. */
function lease_abandoned_row( $id = 'tx_crashed', $actor = 'gone-agent', $seconds_ago = 7200 ) {
    return array(
        'id'        => $id,
        'label'     => 'interrupted',
        'actor'     => $actor,
        'timestamp' => LEASE_T0 - $seconds_ago,
        'before'    => lease_committed(),
        'staged'    => lease_staged(),
    );
}

/** A row carrying a durable journal — the shape content transactions will have. */
function lease_journalled_row( $id = 'tx_content' ) {
    return array_merge(
        lease_abandoned_row( $id, 'gone-agent', 86400 ),
        array(
            'created'       => LEASE_T0 - 86400,
            'last_activity' => LEASE_T0 - 86400,
            'expires'       => LEASE_T0 - 82800,
            'content'       => array( 'posts' => array( 'created' => array( 301 => array( 'type' => 'page' ) ) ) ),
        )
    );
}

function lease_audit_operations() {
    return array_column( Store::audit_log(), 'operation' );
}

function lease_audit_entries( $operation ) {
    return array_values( array_filter(
        Store::audit_log(),
        function ( $entry ) use ( $operation ) { return $entry['operation'] === $operation; }
    ) );
}

/** Run $fn, returning the refusal message, or '' when it was allowed. */
function lease_refuse( callable $fn ) {
    try {
        $fn();
    } catch ( \InvalidArgumentException $e ) {
        return $e->getMessage();
    } catch ( \RuntimeException $e ) {
        return $e->getMessage();
    }
    return '';
}

/** Call a private method, so the guarded primitives can be driven directly. */
function lease_private( $method, array $args, $object = null ) {
    $m = new ReflectionMethod( Tx::class, $method );
    $m->setAccessible( true );
    return $m->invokeArgs( $object, $args );
}

/**
 * A $wpdb that lets another request win a race exactly once.
 *
 * The lease's safety argument is entirely about two statements arriving in an
 * order the code does not control. In one PHP process that interleaving
 * normally cannot be produced at all — read, write, read, write — so a stub
 * that simply honours the guard would prove less than it appears to. This
 * subclass puts the interleaving back: on the first guarded write it runs a
 * callback that moves the row underneath the caller, exactly as a concurrent
 * request would, and only then delegates to the real guard.
 */
class Lease_Racing_wpdb extends wpdb_stub_for_tests {
    public $raced = 0;
    public $forever = false;
    private $callback;

    public function on_guarded_write( callable $cb, $every_time = false ) {
        $this->callback = $cb;
        $this->forever  = (bool) $every_time;
    }

    public function on_first_guarded_write( callable $cb ) {
        $this->on_guarded_write( $cb, false );
    }

    public function query( $sql ) {
        if ( $this->callback && preg_match( '/^(UPDATE|DELETE)\s+FROM?\s*\S*options/i', $sql ) && ( $this->forever || 0 === $this->raced ) ) {
            $cb          = $this->callback;
            $this->raced++;
            $cb();
        }
        return parent::query( $sql );
    }
}

// ---------------------------------------------------------------------------
// 1. A live transaction carries a lease, and the clock is reported.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a new transaction records who opened it, when, and when its lease runs out',
    function () {
        lease_reset();
        $tx = Tx::instance()->begin( 'lease test' );
        $d  = Tx::describe( $tx );
        Agentshell_Test::assert_equals( 'agent', $d['actor'] );
        Agentshell_Test::assert_equals( LEASE_T0, $d['created_at'] );
        Agentshell_Test::assert_equals( LEASE_T0, $d['last_activity_at'] );
        Agentshell_Test::assert_equals( LEASE_T0 + Tx::LEASE_TTL_SECONDS, $d['expires_at'] );
        Agentshell_Test::assert_equals( Tx::LEASE_TTL_SECONDS, $d['seconds_remaining'] );
        Agentshell_Test::assert_false( $d['expired'] );
        Agentshell_Test::assert_false( $d['durable_work'] );
        Agentshell_Test::assert_equals( 3600, $d['lease_ttl_seconds'] );
        unset( $tx );
    }
);

Agentshell_Test::run(
    'seconds_remaining counts down and never goes negative',
    function () {
        lease_reset();
        $tx = Tx::instance()->begin( 'lease test' );
        lease_advance( 1800 );
        Agentshell_Test::assert_equals( 1800, Tx::describe( $tx )['seconds_remaining'] );
        lease_advance( 99999 );
        Agentshell_Test::assert_equals( 0, Tx::seconds_remaining( $tx ) );
        Agentshell_Test::assert_equals( 99999, Tx::idle_seconds( $tx ) );
        unset( $tx );
    }
);

Agentshell_Test::run(
    'get_transaction reports the lease, not just the identity',
    function () {
        lease_reset();
        $tx  = Tx::instance()->begin( 'lease test' );
        $out = ( new Get_Transaction() )->execute( array() );
        Agentshell_Test::assert_true( $out['open'] );
        Agentshell_Test::assert_equals( $tx['id'], $out['transaction']['id'] );
        Agentshell_Test::assert_equals( LEASE_T0 + 3600, $out['transaction']['lease']['expires_at'] );
        Agentshell_Test::assert_equals( 3600, $out['transaction']['lease']['seconds_remaining'] );
        Agentshell_Test::assert_false( $out['transaction']['lease']['expired'] );
        unset( $out, $tx );
    }
);

Agentshell_Test::run(
    'two transactions opened in the same second do not share an id',
    function () {
        // The preview credential is bound to the transaction id, so a
        // collision would let a URL minted for a transaction that has since
        // been closed match a different one — and the lease makes that window
        // ordinary, because reclaiming and reopening is now a designed path.
        lease_reset();
        $first  = Tx::instance()->begin( 'one' );
        $second = Tx::instance()->begin( 'two' );
        unset( $second ); // same transaction, re-entered
        $third = Tx::instance()->begin( 'three' );
        unset( $third );
        Tx::instance()->rollback();
        $fourth = Tx::instance()->begin( 'four' );
        Agentshell_Test::assert_not_equals(
            $first['id'],
            $fourth['id'],
            'two transactions in the same second share an id'
        );
        unset( $first, $fourth );
    }
);

// ---------------------------------------------------------------------------
// 2. Owner activity renews the lease. A peer's read must not.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'staging renews the lease and pushes the expiry out',
    function () {
        lease_reset();
        $tx = Tx::instance()->begin( 'lease test' );
        lease_advance( 3000 );
        Agentshell_Test::assert_true( Tx::instance()->stage( lease_staged() ) );
        $row = lease_row();
        Agentshell_Test::assert_equals( LEASE_T0 + 3000, $row['last_activity'], 'staging is not owner activity' );
        Agentshell_Test::assert_equals(
            LEASE_T0 + 3000 + 3600,
            $row['expires'],
            'a transaction worked on for 50 minutes was thrown away 10 minutes later'
        );
        Agentshell_Test::assert_equals( LEASE_T0, $row['created'], 'renewal rewrote the creation time' );
        Agentshell_Test::assert_equals( $tx['id'], $row['id'] );
        unset( $tx );
    }
);

Agentshell_Test::run(
    're-beginning your own transaction renews it',
    function () {
        lease_reset();
        $first = Tx::instance()->begin( 'lease test' );
        lease_advance( 3000 );
        $again = Tx::instance()->begin( 'lease test' );
        Agentshell_Test::assert_equals( $first['id'], $again['id'], 're-begin minted a second transaction' );
        Agentshell_Test::assert_equals( LEASE_T0 + 3000, lease_row()['last_activity'] );
        unset( $first, $again );
    }
);

Agentshell_Test::run(
    'a peer inspecting the transaction does not keep its lease alive',
    function () {
        // Otherwise any reader anywhere pins the transaction open forever and
        // the expiry never means anything. Only the owner can extend it.
        lease_reset();
        lease_open_staged();
        lease_advance( 3500 );
        $GLOBALS['_agentshell_test_login'] = 'other-agent';
        $diff = Tx::instance()->preview();
        Agentshell_Test::assert_true( ! empty( $diff['changed'] ), 'the peer saw no staged diff at all' );
        Agentshell_Test::assert_equals(
            LEASE_T0,
            lease_row()['last_activity'],
            'a read by a different actor extended a lease it does not own'
        );
        unset( $diff );
    }
);

// ---------------------------------------------------------------------------
// 3. Expiry is effective: an expired transaction does not stage, preview, or block.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'an expired transaction is not open',
    function () {
        lease_reset();
        $tx = Tx::instance()->begin( 'lease test' );
        lease_advance( 3600 );
        Agentshell_Test::assert_true( Tx::is_expired( $tx ) );
        Agentshell_Test::assert_false( Tx::instance()->is_active() );
        unset( $tx );
    }
);

Agentshell_Test::run(
    'a write after expiry lands in the database instead of in a dead transaction',
    function () {
        // The distinction that matters: a staged value in a transaction nobody
        // can commit is a change that silently never happened.
        lease_reset();
        lease_open_staged();
        Agentshell_Test::assert_equals(
            lease_staged()['--lease-accent'],
            Tx::instance()->effective_config()['--lease-accent'],
            'the live transaction did not shape reads'
        );

        lease_advance( 3601 );
        Agentshell_Test::assert_equals(
            lease_committed()['--lease-accent'],
            Tx::instance()->effective_config()['--lease-accent'],
            'an expired transaction still answered reads as the effective config'
        );
        Agentshell_Test::assert_equals( null, Tx::instance()->staged_config() );

        // A mutation tool writes through the pre_update_option hook.
        update_option( Store::CONFIG_KEY, lease_staged() );
        Agentshell_Test::assert_equals(
            lease_staged()['--lease-accent'],
            lease_config()['--lease-accent'],
            'a write after expiry was swallowed by a transaction that can never be committed'
        );
        Agentshell_Test::assert_equals( null, Tx::instance()->staged_config(), 'the write was staged after expiry' );
    }
);

Agentshell_Test::run(
    'preview names the expiry rather than reporting no transaction',
    function () {
        lease_reset();
        lease_open_staged();
        lease_advance( 3601 );
        $message = lease_refuse( function () { Tx::instance()->preview(); } );
        Agentshell_Test::assert_true( '' !== $message, 'previewing an expired transaction was allowed' );
        Agentshell_Test::assert_true( str_contains( $message, 'expired' ), "message did not say why: $message" );
        Agentshell_Test::assert_true( str_contains( $message, 'never written to the database' ), $message );
    }
);

Agentshell_Test::run(
    'get_transaction reports an expired transaction as stale, not as open',
    function () {
        lease_reset();
        $tx = lease_open_staged();
        lease_advance( 7200 );
        $out = ( new Get_Transaction() )->execute( array() );
        Agentshell_Test::assert_false( $out['open'], 'an expired transaction was reported as open' );
        Agentshell_Test::assert_equals( null, $out['transaction'] );
        Agentshell_Test::assert_equals( $tx['id'], $out['stale']['id'] );
        Agentshell_Test::assert_equals( 'agent', $out['stale']['actor'] );
        Agentshell_Test::assert_true( $out['stale']['expired'] );
        Agentshell_Test::assert_equals( 0, $out['stale']['seconds_remaining'] );
        Agentshell_Test::assert_equals( LEASE_T0, $out['stale']['last_activity_at'] );
        Agentshell_Test::assert_true( $out['stale']['discarded_staged_changes'] );
        Agentshell_Test::assert_true( str_contains( $out['stale']['recovery'], 'automatic' ) );
        unset( $tx, $out );
    }
);

Agentshell_Test::run(
    'a transaction left by an interrupted session is stale, not fresh for another hour',
    function () {
        // The rows this feature exists for: written before leases existed, by a
        // session that then died. They must not be granted a fresh hour by the
        // upgrade that first taught the code to read a lease at all.
        lease_reset();
        lease_seed( lease_abandoned_row() );
        Agentshell_Test::assert_true( Tx::is_expired( lease_row() ) );
        Agentshell_Test::assert_equals( LEASE_T0 - 3600, Tx::expires_at( lease_row() ) );
        Agentshell_Test::assert_equals( LEASE_T0 - 7200, Tx::last_activity_at( lease_row() ) );
        Agentshell_Test::assert_false( Tx::instance()->is_active() );
    }
);

Agentshell_Test::run(
    'a recent pre-upgrade transaction keeps working and gains a lease',
    function () {
        lease_reset();
        lease_seed( lease_abandoned_row( 'tx_inflight', 'agent', 30 ) );
        Agentshell_Test::assert_false( Tx::is_expired( lease_row() ) );
        Agentshell_Test::assert_true( Tx::instance()->is_active() );
        // Owned by us, so the owner-side lease machinery has to reach the write
        // — the point being that a row written without lease fields gains them
        // on first contact rather than being refused as malformed.
        Agentshell_Test::assert_true( Tx::instance()->stage( lease_staged() ) );
        Agentshell_Test::assert_equals( LEASE_T0, lease_row()['last_activity'] );
        Agentshell_Test::assert_equals( LEASE_T0 + 3600, lease_row()['expires'] );
    }
);

// ---------------------------------------------------------------------------
// 4. Reclaim: automatic for config-only, never for durable work.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'beginning a transaction reclaims the expired one it replaces, and names it',
    function () {
        lease_reset();
        $stale = lease_open_staged();
        lease_advance( 3601 );
        $out = ( new Begin_Transaction() )->execute( array( 'label' => 'fresh' ) );
        Agentshell_Test::assert_true( $out['transaction']['id'] !== $stale['id'] );
        Agentshell_Test::assert_equals( $stale['id'], $out['reclaimed']['id'] );
        Agentshell_Test::assert_equals( 'agent', $out['reclaimed']['actor'] );
        Agentshell_Test::assert_true( str_contains( $out['note'], 'transaction_reclaimed' ) );
        Agentshell_Test::assert_equals( $out['transaction']['id'], lease_row()['id'], 'the reclaim did not take effect' );
        Agentshell_Test::assert_equals( 'fresh', lease_row()['label'] );
        unset( $stale, $out );
    }
);

Agentshell_Test::run(
    'a reclaim is recorded in the audit log, naming who was reclaimed and how long they had been idle',
    function () {
        // A silent fix is the same as no fix when somebody later asks why their
        // staged work disappeared.
        lease_reset();
        lease_open_staged();
        lease_advance( 5400 );
        Tx::instance()->begin( 'fresh' );
        $hits = lease_audit_entries( 'transaction_reclaimed' );
        Agentshell_Test::assert_equals( 1, count( $hits ), 'expected exactly one reclaim record' );
        $hit = $hits[0];
        Agentshell_Test::assert_equals( 'reclaimer', $hit['actor'] );
        Agentshell_Test::assert_equals( 5400, $hit['changed']['idle_seconds'] );
        Agentshell_Test::assert_equals( 'agent', $hit['changed']['owner'] );
        Agentshell_Test::assert_equals( 'lease test', $hit['changed']['label'] );
        Agentshell_Test::assert_true( $hit['changed']['discarded_staged_changes'] );
    }
);

Agentshell_Test::run(
    'reclaiming discards only the intention — the committed config is untouched',
    function () {
        lease_reset();
        lease_open_staged();
        lease_advance( 3601 );
        Tx::instance()->begin( 'fresh' );
        Agentshell_Test::assert_equals(
            lease_committed(),
            lease_config(),
            'a reclaim wrote to the committed config'
        );
        Agentshell_Test::assert_equals( lease_committed(), Tx::instance()->effective_config() );
    }
);

Agentshell_Test::run(
    'a transaction holding durable work is never discarded, however old',
    function () {
        // has_durable_work() is what keeps a future content transaction's
        // journal — the only record of what it already did — from being
        // destroyed by whoever happens to call begin() next.
        lease_reset();
        lease_seed( lease_journalled_row() );
        Agentshell_Test::assert_true( Tx::has_durable_work( lease_row() ) );

        $message = lease_refuse( function () { Tx::instance()->begin( 'fresh' ); } );
        Agentshell_Test::assert_true( '' !== $message, 'a transaction with unrecovered work was replaced' );
        Agentshell_Test::assert_true( str_contains( $message, 'unrecovered work' ), $message );
        Agentshell_Test::assert_equals( 'tx_content', lease_row()['id'], 'the journal was deleted' );
        Agentshell_Test::assert_equals( array(), lease_audit_entries( 'transaction_reclaimed' ) );
        unset( $message );
    }
);

Agentshell_Test::run(
    'an empty content journal is not durable work, however deeply nested its empty drawers are',
    function () {
        // Otherwise a transaction that journalled nothing would be stuck
        // forever, and the safeguard would become its own outage. The nesting
        // matters: a journal that merely *has* buckets is not the same as a
        // journal that has records in them, and only the second is work.
        lease_reset();
        $row = lease_abandoned_row( 'tx_emptied', 'gone-agent', 86400 );
        $row['content'] = array( 'posts' => array( 'created' => array(), 'updated' => array() ), 'terms' => array() );
        lease_seed( $row );
        Agentshell_Test::assert_false( Tx::has_durable_work( lease_row() ) );
        Tx::instance()->begin( 'fresh' );
        Agentshell_Test::assert_equals( 'fresh', lease_row()['label'] );
    }
);

Agentshell_Test::run(
    'get_transaction says a journalled transaction needs recovery rather than offering a one-click fix',
    function () {
        lease_reset();
        lease_seed( lease_journalled_row() );
        $out = ( new Get_Transaction() )->execute( array() );
        Agentshell_Test::assert_false( $out['open'] );
        Agentshell_Test::assert_true( $out['stale']['durable_work'] );
        Agentshell_Test::assert_true( str_contains( $out['stale']['recovery'], 'required' ) );
        unset( $out );
    }
);

Agentshell_Test::run(
    'a journalled transaction does not wedge config writes while it awaits recovery',
    function () {
        // One unrecovered transaction must not stop the whole site. It stops
        // staging, which is what it can no longer do safely; it does not block
        // reads or unrelated writes, or it would be its own outage.
        lease_reset();
        lease_seed( lease_journalled_row() );
        Agentshell_Test::assert_equals(
            lease_committed()['--lease-accent'],
            Tx::instance()->effective_config()['--lease-accent'],
            'an orphaned transaction still shaped reads'
        );
        update_option( Store::CONFIG_KEY, lease_staged() );
        Agentshell_Test::assert_equals(
            lease_staged()['--lease-accent'],
            lease_config()['--lease-accent'],
            'a journalled transaction blocked an unrelated config write'
        );
        Agentshell_Test::assert_equals( 'tx_content', lease_row()['id'] );
    }
);

// ---------------------------------------------------------------------------
// 5. Commit and rollback refuse an expired transaction instead of applying it.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'committing an expired transaction applies nothing and says why',
    function () {
        lease_reset();
        lease_open_staged();
        lease_advance( 3601 );
        $message = lease_refuse( function () { Tx::instance()->commit(); } );
        Agentshell_Test::assert_true( str_contains( $message, 'expired' ), "refusal did not name the expiry: $message" );
        Agentshell_Test::assert_equals(
            lease_committed(),
            lease_config(),
            'a commit past the lease wrote the staged config'
        );
    }
);

Agentshell_Test::run(
    'rolling back an expired transaction says so rather than claiming success',
    function () {
        // Reporting a rollback as done when nothing was open is how a caller
        // concludes its work is safely discarded when it is not.
        lease_reset();
        lease_open_staged();
        lease_advance( 3601 );
        $message = lease_refuse( function () { Tx::instance()->rollback(); } );
        Agentshell_Test::assert_true( str_contains( $message, 'expired' ), $message );
    }
);

Agentshell_Test::run(
    'a live transaction still commits exactly as before',
    function () {
        lease_reset();
        lease_open_staged();
        $result = Tx::instance()->commit();
        Agentshell_Test::assert_true( $result['committed'] );
        Agentshell_Test::assert_equals(
            lease_staged()['--lease-accent'],
            lease_config()['--lease-accent']
        );
        Agentshell_Test::assert_equals( null, lease_row() );
        Agentshell_Test::assert_equals( array(), lease_audit_entries( 'transaction_reclaimed' ) );
    }
);

Agentshell_Test::run(
    'a live transaction still rolls back exactly as before',
    function () {
        lease_reset();
        lease_open_staged();
        $result = Tx::instance()->rollback();
        Agentshell_Test::assert_true( $result['rolled_back'] );
        Agentshell_Test::assert_equals( lease_committed(), lease_config() );
        Agentshell_Test::assert_equals( null, lease_row() );
    }
);

// ---------------------------------------------------------------------------
// 6. Atomicity. The part that is easy to assume and hard to be right about.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'a stale read cannot delete a transaction that has moved on',
    function () {
        // Not a tautology: a guard that did not exist would still let every
        // test above pass, because none of them puts a second writer in the
        // middle of the first one's read. This one does, and it is the exact
        // read-then-delete race that wedged the site — now destroying
        // somebody else's transaction instead of leaving it stranded.
        lease_reset();
        $tx         = Tx::instance()->begin( 'lease test' );
        $stale_read = $tx; // exactly what a concurrent caller read a moment ago

        // Another request renews the transaction in between.
        $renewed                  = $stale_read;
        $renewed['last_activity'] = LEASE_T0 + 10;
        $renewed['expires']       = LEASE_T0 + 10 + 3600;
        lease_seed( $renewed );

        Agentshell_Test::assert_false(
            lease_private( 'cas_delete', array( $stale_read ) ),
            'a stale read deleted a transaction that had moved on'
        );
        Agentshell_Test::assert_equals( $renewed, lease_row(), 'the row was modified by a losing swap' );
        unset( $tx );
    }
);

Agentshell_Test::run(
    'a losing swap reports failure rather than assuming it won',
    function () {
        // Pins the property the reclaim loop depends on: when a swap fails the
        // only thing it tells you is "the world moved", so the caller is
        // obliged to look again rather than assume either outcome.
        lease_reset();
        $tx = Tx::instance()->begin( 'lease test' );
        $m  = new ReflectionMethod( Tx::class, 'cas' );
        $m->setAccessible( true );
        Agentshell_Test::assert_true( $m->invoke( null, $tx, array_merge( $tx, array( 'label' => 'next' ) ) ) );
        // Same expected bytes, second time: the first swap changed them.
        Agentshell_Test::assert_false( $m->invoke( null, $tx, array_merge( $tx, array( 'label' => 'again' ) ) ) );
        Agentshell_Test::assert_equals( 'next', lease_row()['label'] );
        unset( $tx );
    }
);

Agentshell_Test::run(
    'a renewal that raced a reaper loses, and the caller is told',
    function () {
        // The alternative — a renewal that always succeeded — would let an
        // owner whose transaction had already been reclaimed keep writing
        // into a transaction no other caller can see or commit.
        lease_reset();
        $tx = Tx::instance()->begin( 'lease test' );
        lease_seed( array_merge( $tx, array( 'label' => 'somebody-else' ) ) );
        Agentshell_Test::assert_false( lease_private( 'renew', array( $tx ), Tx::instance() ) );
        Agentshell_Test::assert_equals( 'somebody-else', lease_row()['label'] );
        unset( $tx );
    }
);

Agentshell_Test::run(
    'two callers racing to recover: the loser re-reads and respects the renewal',
    function () {
        // The interleaving that cannot happen in one process on its own. A
        // reaper reads an expired row, the owner renews a moment later, and the
        // reaper's delete lands against the renewed bytes. The correct outcome
        // is that the live transaction survives — deleting it would be the
        // precise failure this whole mechanism exists to prevent.
        lease_reset();
        global $wpdb;
        $original = $wpdb;

        $tx    = lease_open_staged();
        $stale = lease_row();
        lease_advance( 3601 );

        $renewed                  = $stale;
        $renewed['last_activity'] = LEASE_T0 + 3601;
        $renewed['expires']       = LEASE_T0 + 3601 + 3600;
        $renewed['label']         = 'renewed mid-recovery';

        $racer = new Lease_Racing_wpdb();
        $racer->on_first_guarded_write( function () use ( $renewed ) {
            lease_seed( $renewed ); // the other request's write lands first
        } );
        $wpdb = $GLOBALS['wpdb'] = $racer;

        try {
            $result = Tx::instance()->begin( 'mine' );
        } finally {
            $wpdb = $GLOBALS['wpdb'] = $original;
        }

        Agentshell_Test::assert_equals( 1, $racer->raced, 'the race was not exercised' );
        Agentshell_Test::assert_equals( $tx['id'], $result['id'], 'the reaper deleted a transaction that was renewed' );
        Agentshell_Test::assert_equals( 'renewed mid-recovery', lease_row()['label'] );
        Agentshell_Test::assert_equals(
            array(),
            lease_audit_entries( 'transaction_reclaimed' ),
            'a recovery that lost its race was recorded as though it had succeeded'
        );
        unset( $tx, $stale, $renewed );
    }
);

Agentshell_Test::run(
    'recovery gives up rather than spinning when the row keeps moving under it',
    function () {
        // Bounded on purpose. A loop that keeps re-reading a row that is
        // changing on every single read turns one contended option into a
        // request that never returns — and giving up is safe, because a
        // transaction nobody could reclaim is still better than one that was
        // deleted by a guess.
        lease_reset();
        global $wpdb;
        $original = $wpdb;

        $stale  = lease_abandoned_row( 'tx_contended', 'gone-agent', 86400 );
        lease_seed( $stale );
        lease_advance( 86401 );

        $tick  = 0;
        $racer = new Lease_Racing_wpdb();
        $racer->on_guarded_write( function () use ( $stale, &$tick ) {
            // Different bytes every time, so the value guard genuinely misses.
            // Re-seeding the same bytes would let the first delete succeed and
            // prove nothing about the loop's behaviour.
            $row          = $stale;
            $row['label'] = 'moved-' . ( ++$tick );
            lease_seed( $row );
        }, true );
        $wpdb = $GLOBALS['wpdb'] = $racer;

        try {
            $message = lease_refuse( function () { Tx::instance()->begin( 'mine' ); } );
        } finally {
            $wpdb = $GLOBALS['wpdb'] = $original;
        }

        Agentshell_Test::assert_true( '' !== $message, 'the transaction was replaced despite losing every swap' );
        // Three attempts recovering, then three more when begin() re-settles
        // once more on the way to reporting the conflict. Both bounded; what
        // matters is that the count is a small constant rather than however
        // long the caller is willing to wait.
        Agentshell_Test::assert_true(
            $racer->raced <= 6,
            'the recovery loop did not stop after a bounded number of attempts (' . $racer->raced . ')'
        );
        Agentshell_Test::assert_equals( 'tx_contended', lease_row()['id'], 'a give-up deleted the row' );
        unset( $stale, $message );
    }
);

Agentshell_Test::run(
    'a transaction row that is not a readable array is never overwritten',
    function () {
        // The property behind begin() inserting rather than upserting: when the
        // insert loses, the row that is already there survives. Staged with an
        // unreadable row, which is what a competing writer's insert looks like
        // from the loser's side.
        lease_reset();
        lease_seed( 'corrupted' );
        $message = lease_refuse( function () { Tx::instance()->begin( 'mine' ); } );
        Agentshell_Test::assert_true( '' !== $message, 'a transaction was opened over an existing row' );
        Agentshell_Test::assert_equals( 'corrupted', lease_row(), 'the existing row was overwritten' );
        unset( $message );
    }
);

Agentshell_Test::run(
    'a second actor cannot stage, commit or roll back somebody else\'s live transaction',
    function () {
        lease_reset();
        $tx = lease_open_staged();
        $GLOBALS['_agentshell_test_login'] = 'intruder';

        Agentshell_Test::assert_true( '' !== lease_refuse( function () { Tx::instance()->stage( lease_staged() ); } ) );
        Agentshell_Test::assert_true( '' !== lease_refuse( function () { Tx::instance()->commit(); } ) );
        Agentshell_Test::assert_true( '' !== lease_refuse( function () { Tx::instance()->rollback(); } ) );
        Agentshell_Test::assert_true( '' !== lease_refuse( function () { Tx::instance()->begin( 'mine' ); } ) );

        // Read-only inspection stays open to peers, by the existing contract.
        $diff = Tx::instance()->preview();
        Agentshell_Test::assert_true( ! empty( $diff['changed'] ) );
        Agentshell_Test::assert_equals(
            lease_staged()['--lease-accent'],
            lease_row()['staged']['--lease-accent'],
            'a refused mutation changed the staged config'
        );
        unset( $tx, $diff );
    }
);

// ---------------------------------------------------------------------------
// 7. Preview isolation. The lease must not weaken it, and its clock must not
//    be the clock the signer reads.
// ---------------------------------------------------------------------------

Agentshell_Test::run(
    'an expired transaction cannot be previewed, even with a validly signed credential',
    function () {
        // The two windows are different lengths. A credential minted in the
        // first minute of a 60-minute lease is still inside its own 900-second
        // window when the lease is dead, so without the lease check the tail of
        // every abandoned transaction stayed renderable to anyone still holding
        // the URL.
        lease_reset();
        $tx   = lease_open_staged();
        $args = Tx::preview_args();
        Agentshell_Test::assert_true(
            Tx::authorize_preview( $args[ Tx::PREVIEW_TX_ARG ], $args[ Tx::PREVIEW_KEY_ARG ], $args[ Tx::PREVIEW_EXP_ARG ] ),
            'the fixture did not mint a working credential'
        );
        lease_advance( 3601 );
        Agentshell_Test::assert_false(
            Tx::authorize_preview( $args[ Tx::PREVIEW_TX_ARG ], $args[ Tx::PREVIEW_KEY_ARG ], $args[ Tx::PREVIEW_EXP_ARG ] ),
            'an expired transaction still authorises a render of its staged config'
        );
        unset( $tx, $args );
    }
);

Agentshell_Test::run(
    'no credential can be minted for an expired transaction',
    function () {
        lease_reset();
        lease_open_staged();
        lease_advance( 3601 );
        $message = lease_refuse( function () { Tx::preview_args(); } );
        Agentshell_Test::assert_true( str_contains( $message, 'expired' ), $message );
        unset( $message );
    }
);

Agentshell_Test::run(
    'a live transaction still previews, and a committed one still does not',
    function () {
        lease_reset();
        lease_open_staged();
        $args = Tx::preview_args();
        Agentshell_Test::assert_true(
            Tx::authorize_preview( $args[ Tx::PREVIEW_TX_ARG ], $args[ Tx::PREVIEW_KEY_ARG ], $args[ Tx::PREVIEW_EXP_ARG ] )
        );
        Tx::instance()->commit();
        Agentshell_Test::assert_false(
            Tx::authorize_preview( $args[ Tx::PREVIEW_TX_ARG ], $args[ Tx::PREVIEW_KEY_ARG ], $args[ Tx::PREVIEW_EXP_ARG ] )
        );
        unset( $args );
    }
);

Agentshell_Test::run(
    'the test clock moves the lease and cannot move the credential',
    function () {
        // The seam exists so expiry rules are testable without an hour of
        // sleep. A seam that could also move the clock the credential signer
        // reads would be a seam that could stretch a bearer capability's
        // window, so the two clocks are kept apart — and this is what keeps
        // them apart. The lease reads the frozen instant, which is in 2027; the
        // credential reads the wall clock. They disagree by years here, and the
        // credential is the one that did not move.
        lease_reset();
        lease_open_staged();
        $args = Tx::preview_args();
        Agentshell_Test::assert_equals(
            LEASE_T0 + 3600,
            lease_row()['expires'],
            'the lease did not follow the frozen clock'
        );
        $credential_expiry = (int) $args[ Tx::PREVIEW_EXP_ARG ];
        $wall_clock_expiry = time() + Tx::PREVIEW_TTL_SECONDS;
        Agentshell_Test::assert_true(
            abs( $credential_expiry - $wall_clock_expiry ) <= 5,
            'the credential expiry was derived from the test clock, so a test '
            . 'freezing time could stretch a bearer capability'
        );
        unset( $args );
    }
);

Agentshell_Test::run(
    'a read-only caller cannot reclaim: inspection writes nothing',
    function () {
        // Preview authorisation and the rendering theme both reach this manager
        // from an anonymous request. If those could reclaim, an
        // unauthenticated page view could delete another actor's transaction.
        lease_reset();
        lease_open_staged();
        lease_advance( 3601 );
        $message = lease_refuse( function () { Tx::instance()->preview(); } );
        Agentshell_Test::assert_true( '' !== $message );
        $row = lease_row();
        Agentshell_Test::assert_true( is_array( $row ), 'a read-only caller deleted the transaction row' );
        Agentshell_Test::assert_equals( 'lease test', $row['label'] );
        Agentshell_Test::assert_equals(
            array(),
            lease_audit_operations(),
            'a read-only caller wrote an audit entry'
        );
        unset( $message, $row );
    }
);

// ── teardown ────────────────────────────────────────────────────────────────
// run-all.php requires every suite into one shared process, so this one leaves
// nothing behind: an unfrozen clock, no half-open transaction, and the static
// history flags other suites read put back.

Tx::unfreeze_time();
Store::$suppress_history = false;
Store::$operation_label   = '';
unset( $GLOBALS['_agentshell_test_options'][ Tx::KEY ] );
$GLOBALS['_agentshell_test_login'] = 'agent';

// No exit here: run-all.php requires every suite into one process, so exiting
// would end the aggregate run before the remaining suites execute. The runner
// reads the counters and sets the exit status itself.
echo "\n";
echo "transaction lease: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";