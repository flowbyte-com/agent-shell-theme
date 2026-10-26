<?php
/**
 * The transaction read the configurator reloads through, and the
 * editor-session capability that decides who may act on it.
 *
 * A WordPress account is not an editing operation. Two browser sessions
 * logged in as the same administrator are the same actor, and treating that
 * as ownership lets one tab read, overwrite, commit or roll back the other's
 * staged work. This suite pins the boundary:
 *
 *   - the session that opened the transaction sees the staged draft;
 *   - another session of the same account sees the transaction but not the
 *     draft, and cannot stage, commit or roll back;
 *   - an agent-owned (unbound) transaction is not a browser session's either;
 *   - recovery is explicit, same-account, audited and rotates the capability;
 *   - an expired row is reported as closed (`stale: true`).
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

echo "configurator transaction read and editor-session ownership\n";

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

function tx_read_assert_throws( $fn, $message ) {
    try {
        $fn();
    } catch ( \Throwable $e ) {
        Agentshell_Test::assert_true( true );
        return $e;
    }
    Agentshell_Test::assert_true( false, $message );
    return null;
}

function tx_read_session_begin() {
    Tx::clear_editor_session_token();
    $result = Tx::instance()->begin_editor_session( 'configurator test' );
    Tx::set_editor_session_token( $result['token'] );
    return $result;
}

Agentshell_Test::run(
    'the session that opened the transaction reads its staged configuration',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::unfreeze_time();

        tx_read_session_begin();
        Tx::instance()->stage( $staged_config );

        $response = agentshell_build_transaction_response();

        Agentshell_Test::assert_true( $response['open'] );
        Agentshell_Test::assert_true( $response['owned_by_us'] );
        Agentshell_Test::assert_true( $response['owned_by_session'] );
        Agentshell_Test::assert_true( $response['editable_by_us'] );
        Agentshell_Test::assert_true( $response['session_bound'] );
        Agentshell_Test::assert_equals( '', $response['lock_reason'] );
        Agentshell_Test::assert_equals( 'owner', $response['actor'] );
        Agentshell_Test::assert_true( $response['has_staged'] );
        Agentshell_Test::assert_true( isset( $response['staged'] ), 'the owner receives the staged draft' );
        Agentshell_Test::assert_equals( '#123456', $response['staged']['config']['--theme-bg'] );
        Agentshell_Test::assert_equals( '/* staged only */', $response['staged']['custom_css'] );

        Tx::instance()->rollback();
        Tx::clear_editor_session_token();
    }
);

Agentshell_Test::run(
    'another actor sees the transaction but not its staged contents',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::unfreeze_time();

        tx_read_session_begin();
        Tx::instance()->stage( $staged_config );

        $GLOBALS['_agentshell_test_login'] = 'someone-else';
        Tx::clear_editor_session_token();
        $response = agentshell_build_transaction_response();

        Agentshell_Test::assert_true( $response['open'] );
        Agentshell_Test::assert_false( $response['owned_by_us'] );
        Agentshell_Test::assert_false( $response['editable_by_us'] );
        Agentshell_Test::assert_equals( 'owner', $response['actor'] );
        Agentshell_Test::assert_equals( 'other_actor', $response['lock_reason'] );
        Agentshell_Test::assert_false( $response['can_take_over'], 'a different account may not recover it' );
        Agentshell_Test::assert_true( $response['has_staged'] );
        Agentshell_Test::assert_false( isset( $response['staged'] ), 'a foreign staged draft is not disclosed' );

        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::clear_editor_session_token();
        $recovered = Tx::instance()->takeover_editor_session( 'cleanup' );
        Tx::set_editor_session_token( $recovered['token'] );
        Tx::instance()->rollback();
        Tx::clear_editor_session_token();
    }
);

Agentshell_Test::run(
    'another session of the same account is locked out of the staged work',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';

        tx_read_session_begin();
        Tx::instance()->stage( $staged_config );

        // The same account, a second browser session: no capability.
        Tx::clear_editor_session_token();
        $response = agentshell_build_transaction_response();

        Agentshell_Test::assert_true( $response['open'] );
        Agentshell_Test::assert_true( $response['owned_by_us'], 'the account matches' );
        Agentshell_Test::assert_false( $response['owned_by_session'], 'the session does not' );
        Agentshell_Test::assert_false( $response['editable_by_us'], 'the other session may not edit it' );
        Agentshell_Test::assert_equals( 'other_session', $response['lock_reason'] );
        Agentshell_Test::assert_true( $response['can_take_over'], 'explicit recovery is offered to the same account' );
        Agentshell_Test::assert_false( isset( $response['staged'] ), 'staged work is not disclosed to the other session' );

        // A second session cannot stage, commit or roll back it either.
        tx_read_assert_throws(
            function () use ( $staged_config ) {
                Tx::instance()->stage( $staged_config );
            },
            'another session cannot stage into it'
        );
        tx_read_assert_throws(
            function () { Tx::instance()->commit(); },
            'another session cannot commit it'
        );
        tx_read_assert_throws(
            function () { Tx::instance()->rollback(); },
            'another session cannot roll it back'
        );
        Agentshell_Test::assert_true( Tx::instance()->is_active(), 'the refusals left it open and intact' );

        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::set_editor_session_token( '' );
        // Recover with the owner session to clean up (the token was cleared).
        $recovered = Tx::instance()->takeover_editor_session( 'cleanup' );
        Tx::set_editor_session_token( $recovered['token'] );
        Tx::instance()->rollback();
        Tx::clear_editor_session_token();
    }
);

Agentshell_Test::run(
    'an agent-owned transaction is not a browser session\'s to edit',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';

        // An agent opens a transaction through the tool path: no session.
        Tx::clear_editor_session_token();
        Tx::instance()->begin( 'agent work' );
        Tx::instance()->stage( $staged_config );

        $response = agentshell_build_transaction_response();
        Agentshell_Test::assert_true( $response['open'] );
        Agentshell_Test::assert_true( $response['owned_by_us'] );
        Agentshell_Test::assert_false( $response['session_bound'] );
        Agentshell_Test::assert_false( $response['editable_by_us'] );
        Agentshell_Test::assert_equals( 'unbound', $response['lock_reason'] );
        Agentshell_Test::assert_true( $response['can_take_over'] );
        Agentshell_Test::assert_false( isset( $response['staged'] ), 'an unbound staged draft is not handed to a browser' );

        // Agent ownership itself is unchanged: the same unbound row can be
        // committed by the tool path with no session token.
        Tx::instance()->commit();

        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::clear_editor_session_token();
    }
);

Agentshell_Test::run(
    'explicit recovery rotates the capability and keeps the staged work',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';

        $first = tx_read_session_begin();
        Tx::instance()->stage( $staged_config );

        // Second session (same account) recovers explicitly.
        Tx::clear_editor_session_token();
        $recovered = Tx::instance()->takeover_editor_session( 'recover preview' );

        Agentshell_Test::assert_true( is_string( $recovered['token'] ) && strlen( $recovered['token'] ) > 32 );
        Agentshell_Test::assert_false( hash_equals( $first['token'], $recovered['token'] ), 'the capability was rotated' );

        // The old capability no longer matches the row.
        Tx::set_editor_session_token( $first['token'] );
        $old_view = agentshell_build_transaction_response();
        Agentshell_Test::assert_false( $old_view['editable_by_us'], 'the previous session lost access' );
        Agentshell_Test::assert_false( isset( $old_view['staged'] ) );

        // The new capability sees the preserved staged draft.
        Tx::set_editor_session_token( $recovered['token'] );
        $new_view = agentshell_build_transaction_response();
        Agentshell_Test::assert_true( $new_view['editable_by_us'] );
        Agentshell_Test::assert_true( isset( $new_view['staged'] ) );
        Agentshell_Test::assert_equals( '#123456', $new_view['staged']['config']['--theme-bg'] );

        $audit = AgentShell_MCP\Store::audit_log();
        $hit  = null;
        foreach ( $audit as $entry ) {
            if ( 'transaction_editor_session_recovered' === ( $entry['operation'] ?? '' ) ) { $hit = $entry; break; }
        }
        Agentshell_Test::assert_true( is_array( $hit ), 'the recovery is audited' );
        Agentshell_Test::assert_equals( true, $hit['changed']['had_staged_changes'] ?? null );

        Tx::instance()->rollback();
        Tx::clear_editor_session_token();
    }
);

Agentshell_Test::run(
    'recovery refuses a different account and durable content work',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        tx_read_session_begin();

        $GLOBALS['_agentshell_test_login'] = 'someone-else';
        Tx::clear_editor_session_token();
        tx_read_assert_throws(
            function () { Tx::instance()->takeover_editor_session( 'intrusion' ); },
            'a different account cannot recover the transaction'
        );

        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::set_editor_session_token( '' );
        $row = Tx::instance()->payload();
        $row['content'] = array( 'v' => 1, 'ops' => array( 'op_1' => array( 'state' => 'intended', 'op' => 'create_draft' ) ) );
        $GLOBALS['_agentshell_test_options'][ Tx::KEY ] = $row;
        tx_read_assert_throws(
            function () { Tx::instance()->takeover_editor_session( 'content work' ); },
            'a transaction holding durable content work cannot be recovered by an editor'
        );

        agentshell_test_reset();
    }
);

Agentshell_Test::run(
    're-beginning the same session resumes it without minting a second token',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';

        $first = tx_read_session_begin();
        $again = Tx::instance()->begin_editor_session( 'again' );

        Agentshell_Test::assert_true( $again['resumed'] );
        Agentshell_Test::assert_equals( null, $again['token'], 'the server holds only a hash; it cannot reissue the token' );
        Agentshell_Test::assert_equals( $first['transaction']['id'], $again['transaction']['id'] );

        // Another session re-beginning is refused rather than handed the row.
        Tx::clear_editor_session_token();
        tx_read_assert_throws(
            function () { Tx::instance()->begin_editor_session( 'second tab' ); },
            'a second session cannot re-begin the first session\'s transaction'
        );

        Tx::set_editor_session_token( $first['token'] );
        Tx::instance()->rollback();
        Tx::clear_editor_session_token();
    }
);

Agentshell_Test::run(
    'an expired transaction is reported stale, not open',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::clear_editor_session_token();

        $start = 1_800_000_000;
        Tx::freeze_time( $start );
        tx_read_session_begin();

        Tx::freeze_time( $start + 3700 );
        $response = agentshell_build_transaction_response();

        Agentshell_Test::assert_false( $response['open'], 'an expired row is not an open transaction' );
        Agentshell_Test::assert_true( $response['stale'], 'the expired row is named as stale' );
        Agentshell_Test::assert_false( isset( $response['staged'] ), 'expired work is not offered as a draft' );

        Tx::unfreeze_time();
        Tx::clear_editor_session_token();
        agentshell_test_reset();
    }
);

Agentshell_Test::run(
    'the outcome read distinguishes a commit from a rollback by its audit record',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';

        $result = tx_read_session_begin();
        $tx_id  = $result['transaction']['id'];
        Tx::instance()->stage( $staged_config );

        $open = agentshell_build_transaction_outcome_response( $tx_id );
        Agentshell_Test::assert_equals( 'open', $open['state'] );
        Agentshell_Test::assert_true( $open['owned_by_session'] );

        Tx::instance()->commit();
        Tx::clear_editor_session_token();

        $committed = agentshell_build_transaction_outcome_response( $tx_id );
        Agentshell_Test::assert_equals( 'committed', $committed['state'], 'a committed id reconciles as committed' );
        Agentshell_Test::assert_equals( 'transaction_committed', $committed['operation'] );

        // A second transaction rolled back reconciles as rolled back.
        $second = tx_read_session_begin();
        Tx::instance()->stage( $staged_config );
        $second_id = $second['transaction']['id'];
        Tx::instance()->rollback();
        Tx::clear_editor_session_token();

        $rolled = agentshell_build_transaction_outcome_response( $second_id );
        Agentshell_Test::assert_equals( 'rolled_back', $rolled['state'] );

        $unknown = agentshell_build_transaction_outcome_response( 'tx_does_not_exist' );
        Agentshell_Test::assert_equals( 'unknown', $unknown['state'], 'no evidence is reported as unknown, never guessed' );
    }
);

Agentshell_Test::run(
    'another account cannot read an outcome by guessing the id',
    function () use ( $staged_config ) {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        $result = tx_read_session_begin();
        $tx_id  = $result['transaction']['id'];
        Tx::instance()->stage( $staged_config );

        // A guessed id must not disclose even a live transaction's existence.
        $GLOBALS['_agentshell_test_login'] = 'someone-else';
        Tx::clear_editor_session_token();
        $live_spy = agentshell_build_transaction_outcome_response( $tx_id );
        Agentshell_Test::assert_equals( 'unknown', $live_spy['state'], 'a live transaction is not disclosed across accounts' );

        $GLOBALS['_agentshell_test_login'] = 'owner';
        Tx::set_editor_session_token( $result['token'] );
        Tx::instance()->commit();
        Tx::clear_editor_session_token();

        $GLOBALS['_agentshell_test_login'] = 'someone-else';
        $spied = agentshell_build_transaction_outcome_response( $tx_id );
        Agentshell_Test::assert_equals( 'unknown', $spied['state'], 'the audit record is not disclosed across accounts' );

        $GLOBALS['_agentshell_test_login'] = 'owner';
        agentshell_test_reset();
    }
);
