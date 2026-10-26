<?php
/**
 * The transaction read the configurator reloads through.
 *
 * The editor has to survive a reload, a second session and an expired lease
 * without ever trusting a local flag about who owns the transaction. That
 * makes this response a security boundary as well as a convenience:
 *
 *   - the owner sees the staged configuration, in the same shape as a normal
 *     GET, so the draft it was previewing can be restored;
 *   - another actor sees that a transaction exists and whether it holds staged
 *     work, but not what that work is;
 *   - an expired row is reported as closed (`stale: true`), because Save and
 *     Discard on it would be promises the manager refuses to keep.
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'AUTH_SALT' ) ) { define( 'AUTH_SALT', 'configurator-transaction-read-suite' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : $v; } }

$GLOBALS['_agentshell_test_login'] = 'owner';
$GLOBALS['wp_version']              = '6.5';

require_once dirname( __DIR__ ) . '/functions.php';
foreach ( array(
    'class-store.php', 'class-doctor.php', 'class-content-write-guard.php',
    'class-content-journal.php', 'class-recovery-advisory-lock.php',
    'class-content-recovery.php', 'class-transactions.php',
) as $inc ) {
    require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/' . $inc;
}

use AgentShell_MCP\Transaction_Manager as Tx;

echo "configurator transaction read (staged, owner, stale)\n";

$staged_config = array(
    'design' => array(
        'colors' => array( 'background' => '#123456' ),
        'layout' => array( 'borderWidth' => '3px', 'borderStyle' => 'dashed' ),
    ),
    'zones'      => array(
        array( 'id' => 'main', 'label' => 'Main', 'composition' => array( array( 'type' => 'wp_loop' ) ) ),
    ),
    'custom_css' => '/* staged only */',
);

Agentshell_Test::run(
    'the owner reads their staged configuration in GET shape',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::unfreeze_time();

        Tx::instance()->begin( 'configurator test' );
        Tx::instance()->stage( $staged_config );

        $response = agentshell_build_transaction_response();

        Agentshell_Test::assert_true( $response['open'] );
        Agentshell_Test::assert_true( $response['owned_by_us'] );
        Agentshell_Test::assert_equals( 'owner', $response['actor'] );
        Agentshell_Test::assert_true( $response['has_staged'] );
        Agentshell_Test::assert_true( isset( $response['staged'] ), 'the owner receives the staged draft' );
        Agentshell_Test::assert_equals( '#123456', $response['staged']['config']['--theme-bg'] );
        Agentshell_Test::assert_equals( '3px', $response['staged']['config']['--border-width'] );
        Agentshell_Test::assert_equals( '/* staged only */', $response['staged']['custom_css'] );

        Tx::instance()->rollback();
    }
);

Agentshell_Test::run(
    'another actor sees the transaction but not its staged contents',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::unfreeze_time();

        Tx::instance()->begin( 'configurator test' );
        Tx::instance()->stage( $staged_config );

        $GLOBALS['_agentshell_test_login'] = 'someone-else';
        $response = agentshell_build_transaction_response();

        Agentshell_Test::assert_true( $response['open'] );
        Agentshell_Test::assert_false( $response['owned_by_us'] );
        Agentshell_Test::assert_equals( 'owner', $response['actor'] );
        Agentshell_Test::assert_true( $response['has_staged'] );
        Agentshell_Test::assert_false( isset( $response['staged'] ), 'a foreign staged draft is not disclosed' );

        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::instance()->rollback();
    }
);

Agentshell_Test::run(
    'an expired transaction is reported stale, not open',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';

        $start = 1_800_000_000;
        Tx::freeze_time( $start );
        Tx::instance()->begin( 'configurator test' );

        Tx::freeze_time( $start + 3700 );
        $response = agentshell_build_transaction_response();

        Agentshell_Test::assert_false( $response['open'], 'an expired row is not an open transaction' );
        Agentshell_Test::assert_true( $response['stale'], 'the expired row is named as stale' );
        Agentshell_Test::assert_false( isset( $response['staged'] ), 'expired work is not offered as a draft' );

        Tx::unfreeze_time();
        agentshell_test_reset();
    }
);
