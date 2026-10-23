<?php
/**
 * Compare-and-swap regression: a no-op write is not a lost race.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * Every write to the transaction row is a compare-and-swap: the UPDATE
 * carries the row's current bytes in its WHERE clause, and the swap
 * succeeds only if the row still holds them. WordPress's own option
 * APIs cannot do this (update_option() writes by name alone; delete_option()
 * reads and then deletes by name), which is why the row has a guarded
 * write of its own.
 *
 * The swap read MySQL's affected-row count as a verdict, and MySQL
 * reports the number of rows *changed* rather than the number of rows
 * matched. An UPDATE that writes the bytes already stored therefore
 * reports zero affected rows — the same count a lost race reports.
 *
 * Two ordinary operations produced exactly that no-op write:
 *
 *   - staging the same configuration twice inside one second (the lease
 *     stamp has one-second resolution, so the second staging produced a
 *     byte-identical row), and
 *   - advancing a journal head whose compare fields had not moved (an
 *     edit that stages nothing the head tracks), which serialises to the
 *     bytes it replaces.
 *
 * Both were reported as "a competing caller advanced the head", and the
 * caller rolled back work that was in fact correctly staged. The symptom
 * was a transaction that could not be closed: the second staging threw,
 * and the caller retried into the same wall.
 *
 * What is asserted here
 * ---------------------
 * 1. A write whose bytes equal the bytes already stored succeeds. The
 *    desired state is already stored, and refusing it is a lie about the
 *    world.
 * 2. A write whose bytes differ succeeds when the guard matches, and
 *    fails when it does not. The lost-race refusal is unchanged.
 * 3. A no-op write fails when the row moved underneath it, even though
 *    the value it would have written is the value it read. A caller that
 *    cannot prove the row is where it left it must not claim it wrote
 *    to it.
 * 4. A database error fails. "Zero rows" and "no verdict" are different
 *    things and must not be conflated.
 *
 * Nothing here touches the live site, the database or the network: the
 * row lives in the in-memory option store, and the $wpdb stub honours
 * MySQL's changed-rows semantics — an UPDATE that writes the value
 * already stored affects zero rows.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'cas-suite-auth-salt' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    $d = sys_get_temp_dir() . '/agentshell-cas-wpcontent-' . getmypid();
    @mkdir( $d, 0700, true );
    define( 'WP_CONTENT_DIR', $d );
}
if ( ! defined( 'AGENTSHELL_SCREENSHOT_DIR' ) ) {
    define( 'AGENTSHELL_SCREENSHOT_DIR', sys_get_temp_dir() . '/agentshell-cas-store-' . getmypid() );
}

$GLOBALS['_agentshell_test_uploads'] = array(
    'basedir' => '', 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => '',
);
$GLOBALS['_agentshell_test_login']  = 'agent';
$GLOBALS['wp_version']              = '6.5';

foreach ( array( 'class-store.php', 'class-doctor.php', 'class-content-write-guard.php', 'class-transactions.php' ) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/tools/class-base-tool.php';

use AgentShell_MCP\Store;
use AgentShell_MCP\Transaction_Manager as Tx;

if ( ! function_exists( 'agentshell_get_config' ) ) {
    function agentshell_get_config( $mode = 'committed', $key = null, $default = null ) {
        return Tx::instance()->effective_config();
    }
}

echo "transaction compare-and-swap regression tests\n";

// Install the bootstrap's own $wpdb stub.
//
// run-all.php requires every suite into one process, and a suite that
// installs its own $wpdb leaves it behind for the suites that follow. The
// stub this suite needs is the one that honours MySQL's changed-rows
// semantics and the failure side-channels; a suite-local stub that does
// not would silently answer questions this suite is asking.
$GLOBALS['_cas_previous_wpdb'] = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb']              = new wpdb_stub_for_tests();

// A fixed instant, so nothing depends on when the suite runs.
define( 'CAS_T0', 1800000000 );

function cas_committed() { return array( '--cas-accent' => '#123456' ); }
function cas_staged()    { return array( '--cas-accent' => '#ff00ff' ); }

/** Reset to a known site with no transaction and a frozen clock. */
function cas_reset() {
    agentshell_test_reset();
    Tx::unfreeze_time();
    Tx::freeze_time( CAS_T0 );
    Store::$suppress_history = false;
    Store::$operation_label   = '';
    $GLOBALS['_agentshell_test_options'][ Store::CONFIG_KEY ] = cas_committed();
    $GLOBALS['_agentshell_test_login']                        = 'agent';
}

/** The stored row, read past any manager state. */
function cas_row() {
    return $GLOBALS['_agentshell_test_options'][ Tx::KEY ] ?? null;
}

/** Call a private method, so the guarded primitive can be driven directly. */
function cas_private( $method, array $args, $object = null ) {
    $m = new ReflectionMethod( Tx::class, $method );
    $m->setAccessible( true );
    return $m->invokeArgs( $object, $args );
}

// ── C1: staging the same configuration twice must not fail ───────────
Agentshell_Test::run(
    'C1: staging the same configuration twice inside one second succeeds both times',
    function () {
        cas_reset();
        $mgr = Tx::instance();
        $mgr->begin( 'cas test' );

        // The first staging changes the row.
        Agentshell_Test::assert_true( $mgr->stage( cas_staged() ), 'the first staging lands' );

        // The second staging writes the same bytes: the staged
        // configuration is identical and the lease stamp has one-second
        // resolution. MySQL reports zero changed rows for that.
        $second = true;
        try {
            $second = $mgr->stage( cas_staged() );
        } catch ( \InvalidArgumentException $e ) {
            $second = false;
        }
        Agentshell_Test::assert_true(
            $second,
            'an identical staging is a no-op write, not a lost race: ' . ( $second ? '' : 'it was refused' )
        );
        Agentshell_Test::assert_equals(
            cas_staged(),
            $mgr->staged_config(),
            'the staged configuration is the one that was asked for'
        );
    }
);

// ── C2: the swap itself: a no-op write succeeds ──────────────────────
Agentshell_Test::run(
    'C2: cas_write_row() succeeds when the bytes it writes equal the bytes already stored',
    function () {
        cas_reset();
        Tx::instance()->begin( 'cas test' );
        $row = cas_row();
        Agentshell_Test::assert_true(
            cas_private( 'cas', array( $row, $row ) ),
            'a write of the row\'s own bytes is a no-op and succeeds'
        );
    }
);

// ── C3: a genuine lost race still fails ──────────────────────────────
Agentshell_Test::run(
    'C3: cas_write_row() fails when the row no longer holds the bytes the caller read',
    function () {
        cas_reset();
        Tx::instance()->begin( 'cas test' );
        $row = cas_row();

        // A competing writer swaps the row.
        $moved                          = $row;
        $moved['label']                = 'someone else';
        $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = $moved;

        $next             = $row;
        $next['label']   = 'me';
        Agentshell_Test::assert_false(
            cas_private( 'cas', array( $row, $next ) ),
            'a write whose guard does not match is a lost race and fails'
        );
    }
);

// ── C4: a no-op write against a row that moved still fails ───────────
Agentshell_Test::run(
    'C4: cas_write_row() fails on a no-op write when the row moved underneath it',
    function () {
        cas_reset();
        Tx::instance()->begin( 'cas test' );
        $row = cas_row();

        // The row moves, and the bytes the caller would write are the
        // bytes it read. The write changes nothing, so the count of
        // changed rows is zero — but the row the caller was looking at
        // is gone, and the caller must not claim it wrote to it.
        $moved                          = $row;
        $moved['label']                = 'someone else';
        $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = $moved;

        Agentshell_Test::assert_false(
            cas_private( 'cas', array( $row, $row ) ),
            'a no-op write against a row that moved is refused'
        );
    }
);

// ── C5: a database error is not a no-op ──────────────────────────────
Agentshell_Test::run(
    'C5: cas_write_row() fails when the database errors',
    function () {
        cas_reset();
        Tx::instance()->begin( 'cas test' );
        $row = cas_row();

        // The stub raises on every query when the audit-failure side
        // channel is set. A statement that did not run to a verdict is
        // not a statement that changed nothing.
        $GLOBALS['_agentshell_test_audit_fail'] = true;
        try {
            $result = cas_private( 'cas', array( $row, $row ) );
        } catch ( \Throwable $e ) {
            $result = false;
        }
        $GLOBALS['_agentshell_test_audit_fail'] = false;

        Agentshell_Test::assert_false(
            $result,
            'a database error is a failure, never a successful no-op'
        );
    }
);

// ── C6: the row is left exactly as it was found ──────────────────────
Agentshell_Test::run(
    'C6: a refused no-op write leaves the row byte-identical',
    function () {
        cas_reset();
        Tx::instance()->begin( 'cas test' );
        $row = cas_row();

        $moved                          = $row;
        $moved['label']                = 'someone else';
        $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = $moved;

        cas_private( 'cas', array( $row, $row ) );

        Agentshell_Test::assert_equals(
            maybe_serialize( $moved ),
            maybe_serialize( cas_row() ),
            'the competing writer\'s row survives untouched'
        );
    }
);

// ── teardown ────────────────────────────────────────────────────────────────
Tx::unfreeze_time();
Store::$suppress_history = false;
Store::$operation_label   = '';
unset( $GLOBALS['_agentshell_test_options'][ Tx::KEY ] );
$GLOBALS['_agentshell_test_login'] = 'agent';
if ( null !== $GLOBALS['_cas_previous_wpdb'] ) {
    $GLOBALS['wpdb'] = $GLOBALS['_cas_previous_wpdb'];
}
unset( $GLOBALS['_cas_previous_wpdb'] );

echo "\n";
echo "transaction compare-and-swap: " . Agentshell_Test::$passed . " passed, " . Agentshell_Test::$failed . " failed\n";
