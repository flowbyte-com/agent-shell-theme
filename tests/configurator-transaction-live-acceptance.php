<?php
/**
 * Live acceptance for the configurator's transaction ownership and outcome
 * reconciliation — against a real WordPress REST API.
 *
 * This is not a unit suite. It speaks to a running site with an Application
 * Password, because the failures it exists to catch (a second browser session
 * of the same account, a lost commit response, a stale capability) are
 * transport and identity failures that a stub cannot reproduce.
 *
 * Configuration:
 *   AGENTSHELL_ACCEPTANCE_URL            e.g. https://agentshell-demo.local
 *   AGENTSHELL_ACCEPTANCE_USER           an administrator login
 *   AGENTSHELL_ACCEPTANCE_APP_PASSWORD   that user's Application Password
 *   AGENTSHELL_ACCEPTANCE_USER_B         optional second administrator
 *   AGENTSHELL_ACCEPTANCE_APP_PASSWORD_B optional second Application Password
 *   AGENTSHELL_ACCEPTANCE_INSECURE=1     allow a self-signed certificate
 *
 * The script snapshots the committed configuration, exercises the lifecycle,
 * and restores the snapshot through the same REST API. It does not touch
 * revisions, snapshots or the audit log; an acceptance run should snapshot
 * those options with wp-cli before it starts and restore them afterwards, so
 * the owner's history is exactly as it was.
 *
 * Exit status: 0 when every assertion passed, 1 otherwise.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This acceptance script runs from the CLI.\n" );
	exit( 1 );
}

$base   = rtrim( (string) getenv( 'AGENTSHELL_ACCEPTANCE_URL' ), '/' );
$user   = (string) getenv( 'AGENTSHELL_ACCEPTANCE_USER' );
$pass   = (string) getenv( 'AGENTSHELL_ACCEPTANCE_APP_PASSWORD' );
$user_b = (string) getenv( 'AGENTSHELL_ACCEPTANCE_USER_B' );
$pass_b = (string) getenv( 'AGENTSHELL_ACCEPTANCE_APP_PASSWORD_B' );
$insecure = '1' === (string) getenv( 'AGENTSHELL_ACCEPTANCE_INSECURE' );

if ( '' === $base || '' === $user || '' === $pass ) {
	fwrite( STDERR, "SKIP: set AGENTSHELL_ACCEPTANCE_URL, AGENTSHELL_ACCEPTANCE_USER and AGENTSHELL_ACCEPTANCE_APP_PASSWORD.\n" );
	exit( 2 );
}

$GLOBALS['_passed'] = 0;
$GLOBALS['_failed'] = 0;

function check( $name, $condition, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['_passed']++;
		echo "  ok   {$name}\n";
		return true;
	}
	$GLOBALS['_failed']++;
	echo "  FAIL {$name}" . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
	return false;
}

function request( $method, $path, $body = null, $token = null, $as = 'a' ) {
	global $base, $user, $pass, $user_b, $pass_b, $insecure;
	$u = 'a' === $as ? $user : $user_b;
	$p = 'a' === $as ? $pass : $pass_b;
	if ( '' === $u || '' === $p ) {
		return array( 0, null, 'no credentials for account ' . $as );
	}
	$ch = curl_init( $base . '/wp-json/wp/v2/agentshell/' . ltrim( $path, '/' ) );
	$headers = array(
		'Accept: application/json',
		'Authorization: Basic ' . base64_encode( $u . ':' . $p ),
	);
	if ( null !== $token ) {
		$headers[] = 'X-AgentShell-Editor-Session: ' . $token;
	}
	if ( null !== $body ) {
		$headers[] = 'Content-Type: application/json';
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
	}
	curl_setopt_array( $ch, array(
		CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_TIMEOUT        => 30,
	) );
	if ( $insecure ) {
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
	}
	$raw    = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$error  = curl_error( $ch );
	unset( $ch ); // PHP 8.5 deprecates curl_close(); the handle frees itself.
	$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
	return array( $status, $data, $error );
}

function canon( $value ) {
	if ( is_array( $value ) ) {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = canon( $child );
		}
	}
	return $value;
}

function fingerprint( array $config_response ) {
	$payload = array(
		'config'     => $config_response['config'] ?? array(),
		'custom_css' => $config_response['custom_css'] ?? '',
		'custom_js'  => $config_response['custom_js'] ?? '',
		'zones'      => $config_response['zones'] ?? array(),
	);
	return hash( 'sha256', json_encode( canon( $payload ) ) );
}

function payload_from( array $config_response ) {
	$payload = array();
	foreach ( (array) ( $config_response['config'] ?? array() ) as $key => $value ) {
		$payload[ $key ] = $value;
	}
	$payload['custom_css'] = $config_response['custom_css'] ?? '';
	$payload['custom_js']  = $config_response['custom_js'] ?? '';
	$payload['zones']      = $config_response['zones'] ?? array();
	return $payload;
}

echo "live configurator transaction ownership (real WordPress REST)\n";
echo "  site: {$base}\n";

// ── 1. Baseline ─────────────────────────────────────────────────────────
list( $status, $baseline ) = request( 'GET', 'config' );
if ( 200 !== $status || ! is_array( $baseline ) ) {
	check( 'the site answers the configuration route', false, 'HTTP ' . $status );
	echo "\nCannot continue without a configuration response.\n";
	exit( 1 );
}
check( 'the site answers the configuration route', true );
$baseline_hash    = fingerprint( $baseline );
$baseline_payload = payload_from( $baseline );
$baseline_bg      = (string) ( $baseline_payload['--theme-bg'] ?? '' );
$temp_bg          = '#010203' === $baseline_bg ? '#040506' : '#010203';

// ── 2. Begin session A ─────────────────────────────────────────────────
list( $status, $begin ) = request( 'POST', 'transaction', array( 'label' => 'acceptance run' ) );
check( 'a configurator transaction begins', 200 === $status && ! empty( $begin['id'] ), 'HTTP ' . $status );
$tx_id  = (string) ( $begin['id'] ?? '' );
$token_a = (string) ( $begin['editor_session'] ?? '' );
check( 'the begin response mints a session capability', strlen( $token_a ) >= 64, (string) strlen( $token_a ) );
check( 'the begin response reports the transaction id', 0 === strpos( $tx_id, 'tx_' ), $tx_id );

list( $status, $view_a ) = request( 'GET', 'transaction', null, $token_a );
check( 'session A reads the transaction it owns', 200 === $status && ! empty( $view_a['editable_by_us'] ), 'HTTP ' . $status );
check( 'session A sees the session binding', ! empty( $view_a['session_bound'] ) );

// ── 3. Stage through the canonical PUT ─────────────────────────────────
$staged = $baseline_payload;
$staged['--theme-bg'] = $temp_bg;
list( $status, $put ) = request( 'PUT', 'config', $staged, $token_a );
check( 'session A stages a configuration change', 200 === $status, 'HTTP ' . $status . ' ' . json_encode( $put ) );

list( $status, $view_a2 ) = request( 'GET', 'transaction', null, $token_a );
check( 'the staged configuration is visible to session A',
	200 === $status && ! empty( $view_a2['staged']['config']['--theme-bg'] ) &&
	$temp_bg === $view_a2['staged']['config']['--theme-bg'],
	'bg=' . ( $view_a2['staged']['config']['--theme-bg'] ?? 'none' ) );

// ── 4. Same account, no capability ─────────────────────────────────────
list( $status, $view_b ) = request( 'GET', 'transaction' );
check( 'a second session of the same account sees the transaction', 200 === $status && ! empty( $view_b['open'] ) );
check( 'the second session is not the owner', empty( $view_b['editable_by_us'] ) );
check( 'the second session is told it is another editor session', 'other_session' === ( $view_b['lock_reason'] ?? '' ) );
check( 'the second session does not receive the staged draft', ! isset( $view_b['staged'] ) );
check( 'the second session is offered explicit recovery', ! empty( $view_b['can_take_over'] ) );

list( $s1 ) = request( 'PUT', 'config', $staged );
list( $s2 ) = request( 'POST', 'transaction/commit' );
list( $s3 ) = request( 'DELETE', 'transaction' );
list( $s4 ) = request( 'GET', 'transaction/preview' );
check( 'a second session cannot stage into the transaction', 409 === $s1, 'HTTP ' . $s1 );
check( 'a second session cannot commit the transaction', 409 === $s2, 'HTTP ' . $s2 );
check( 'a second session cannot roll the transaction back', 409 === $s3, 'HTTP ' . $s3 );
check( 'a second session cannot mint a preview credential', 409 === $s4, 'HTTP ' . $s4 );

// ── 5. Explicit recovery rotates the capability ────────────────────────
list( $status, $unconfirmed ) = request( 'POST', 'transaction/recover', array( 'confirm' => false ) );
check( 'recovery without confirmation is refused', 400 === $status, 'HTTP ' . $status );

list( $status, $recovered ) = request( 'POST', 'transaction/recover', array( 'confirm' => true, 'label' => 'acceptance recovery' ) );
check( 'recovery is accepted for the same account', 200 === $status && ! empty( $recovered['editor_session'] ), 'HTTP ' . $status . ' ' . json_encode( $recovered ) );
$token_b = (string) ( $recovered['editor_session'] ?? '' );
check( 'recovery rotates the capability', '' !== $token_b && $token_b !== $token_a );

list( $status, $old_view ) = request( 'GET', 'transaction', null, $token_a );
check( 'the previous capability no longer edits the transaction', empty( $old_view['editable_by_us'] ) );
list( $status, $new_view ) = request( 'GET', 'transaction', null, $token_b );
check( 'the recovered capability edits the transaction', ! empty( $new_view['editable_by_us'] ) );
check( 'recovery preserves the staged draft', $temp_bg === ( $new_view['staged']['config']['--theme-bg'] ?? '' ),
	'bg=' . ( $new_view['staged']['config']['--theme-bg'] ?? 'none' ) );

// ── 6. Commit, and reconcile it by audit id ────────────────────────────
list( $status, $commit ) = request( 'POST', 'transaction/commit', null, $token_b );
check( 'the recovered session commits the staged change', 200 === $status && ! empty( $commit['committed'] ), 'HTTP ' . $status . ' ' . json_encode( $commit ) );

list( $status, $after_commit ) = request( 'GET', 'config' );
check( 'the committed configuration changed', 200 === $status && $temp_bg === ( $after_commit['config']['--theme-bg'] ?? '' ),
	'bg=' . ( $after_commit['config']['--theme-bg'] ?? 'none' ) );

list( $status, $outcome ) = request( 'GET', 'transaction/outcome?id=' . rawurlencode( $tx_id ), null, $token_b );
check( 'a committed transaction reconciles as committed', 200 === $status && 'committed' === ( $outcome['state'] ?? '' ),
	'HTTP ' . $status . ' state=' . ( $outcome['state'] ?? 'none' ) );

list( $status, $closed ) = request( 'GET', 'transaction', null, $token_b );
check( 'the transaction is closed after commit', 200 === $status && empty( $closed['open'] ) );

// ── 7. Rollback, and its outcome ───────────────────────────────────────
list( $status, $begin_c ) = request( 'POST', 'transaction', array( 'label' => 'acceptance rollback' ) );
$token_c = (string) ( $begin_c['editor_session'] ?? '' );
$tx_c    = (string) ( $begin_c['id'] ?? '' );
$temp2   = '#010203' === $temp_bg ? '#040506' : '#010203';
$staged2 = payload_from( $after_commit );
$staged2['--theme-bg'] = $temp2;
request( 'PUT', 'config', $staged2, $token_c );
list( $status, $rolled ) = request( 'DELETE', 'transaction', null, $token_c );
check( 'a session rolls its own transaction back', 200 === $status && ! empty( $rolled['rolled_back'] ), 'HTTP ' . $status );

list( $status, $rolled_outcome ) = request( 'GET', 'transaction/outcome?id=' . rawurlencode( $tx_c ), null, $token_c );
check( 'a rolled-back transaction reconciles as rolled back', 200 === $status && 'rolled_back' === ( $rolled_outcome['state'] ?? '' ),
	'state=' . ( $rolled_outcome['state'] ?? 'none' ) );

list( $status, $after_rollback ) = request( 'GET', 'config' );
check( 'a rollback leaves the committed configuration alone',
	200 === $status && $temp_bg === ( $after_rollback['config']['--theme-bg'] ?? '' ),
	'bg=' . ( $after_rollback['config']['--theme-bg'] ?? 'none' ) );

// ── 8. A different account cannot recover or read ──────────────────────
if ( '' !== $user_b && '' !== $pass_b ) {
	list( $status, $begin_d ) = request( 'POST', 'transaction', array( 'label' => 'acceptance foreign' ) );
	$token_d = (string) ( $begin_d['editor_session'] ?? '' );
	list( $status, $foreign_view ) = request( 'GET', 'transaction', null, null, 'b' );
	check( 'a different account sees the transaction but not the draft',
		200 === $status && empty( $foreign_view['owned_by_us'] ) && ! isset( $foreign_view['staged'] ),
		'HTTP ' . $status );
	list( $status, $foreign_recover ) = request( 'POST', 'transaction/recover', array( 'confirm' => true ), null, 'b' );
	check( 'a different account cannot recover it', 409 === $status, 'HTTP ' . $status );
	request( 'DELETE', 'transaction', null, $token_d );
} else {
	echo "  SKIP a second account was not supplied (AGENTSHELL_ACCEPTANCE_USER_B)\n";
}

// ── 9. Restore the owner's committed configuration ─────────────────────
list( $status, $begin_r ) = request( 'POST', 'transaction', array( 'label' => 'acceptance restore' ) );
$token_r = (string) ( $begin_r['editor_session'] ?? '' );
list( $status ) = request( 'PUT', 'config', $baseline_payload, $token_r );
$stage_ok = 200 === $status;
list( $status ) = request( 'POST', 'transaction/commit', null, $token_r );
$commit_ok = 200 === $status;
list( $status, $restored ) = request( 'GET', 'config' );
$restored_hash = is_array( $restored ) ? fingerprint( $restored ) : '';
check( 'the committed configuration was restored exactly', $stage_ok && $commit_ok && $restored_hash === $baseline_hash,
	'stage=' . ( $stage_ok ? 'yes' : 'no' ) . ' commit=' . ( $commit_ok ? 'yes' : 'no' ) . ' hash=' . substr( $restored_hash, 0, 12 ) );
list( $status, $final_tx ) = request( 'GET', 'transaction' );
check( 'no transaction is left open', 200 === $status && empty( $final_tx['open'] ),
	'open=' . ( ! empty( $final_tx['open'] ) ? ( $final_tx['id'] ?? 'yes' ) : 'no' ) );

echo "\n" . $GLOBALS['_passed'] . ' passed, ' . $GLOBALS['_failed'] . " failed\n";
exit( $GLOBALS['_failed'] > 0 ? 1 : 0 );
