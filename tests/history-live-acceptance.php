<?php
/**
 * Live acceptance for history deletion against a real WordPress REST API.
 *
 * It creates only records whose names and ids it knows, deletes only those,
 * and restores the committed configuration through the same API. The owner's
 * revisions/snapshots/audit options should still be snapshotted with wp-cli
 * before running this and restored afterwards, because the bounded revision
 * ring drops old entries when new ones are recorded.
 *
 * Configuration is the same as the transaction acceptance script:
 *   AGENTSHELL_ACCEPTANCE_URL / _USER / _APP_PASSWORD
 *   AGENTSHELL_ACCEPTANCE_INSECURE=1 for a self-signed certificate
 *
 * Exit status: 0 when every assertion passed, 1 otherwise.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This acceptance script runs from the CLI.\n" );
	exit( 1 );
}

$base = rtrim( (string) getenv( 'AGENTSHELL_ACCEPTANCE_URL' ), '/' );
$user = (string) getenv( 'AGENTSHELL_ACCEPTANCE_USER' );
$pass = (string) getenv( 'AGENTSHELL_ACCEPTANCE_APP_PASSWORD' );
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

function request( $method, $path, $body = null, $auth = true, $token = null ) {
	global $base, $user, $pass, $insecure;
	$ch = curl_init( $base . '/wp-json/wp/v2/agentshell/' . ltrim( $path, '/' ) );
	$headers = array( 'Accept: application/json' );
	if ( $auth ) {
		$headers[] = 'Authorization: Basic ' . base64_encode( $user . ':' . $pass );
	}
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
	unset( $ch );
	return array( $status, is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null, $error );
}

function canon( $value ) {
	if ( is_array( $value ) ) {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) { ksort( $value ); }
		foreach ( $value as $key => $child ) { $value[ $key ] = canon( $child ); }
	}
	return $value;
}

function payload_from( array $response ) {
	$payload = array();
	foreach ( (array) ( $response['config'] ?? array() ) as $key => $value ) { $payload[ $key ] = $value; }
	$payload['custom_css'] = $response['custom_css'] ?? '';
	$payload['custom_js']  = $response['custom_js'] ?? '';
	$payload['zones']      = $response['zones'] ?? array();
	return $payload;
}

function fingerprint(array $response) {
	return hash( 'sha256', json_encode( canon( payload_from( $response ) ) ) );
}

function revision_ids() {
	list( $status, $data ) = request( 'GET', 'revisions' );
	if ( 200 !== $status || ! is_array( $data ) ) { return array(); }
	return array_map( function ( $r ) { return (string) ( $r['id'] ?? '' ); }, (array) ( $data['revisions'] ?? array() ) );
}

function snapshot_names() {
	list( $status, $data ) = request( 'GET', 'snapshots' );
	if ( 200 !== $status || ! is_array( $data ) ) { return array(); }
	return array_map( function ( $s ) { return (string) ( $s['name'] ?? '' ); }, (array) ( $data['snapshots'] ?? array() ) );
}

function commit_change( $payload ) {
	list( $status, $begin ) = request( 'POST', 'transaction', array( 'label' => 'history acceptance' ) );
	if ( 200 !== $status ) { return array( false, 0, 'begin ' . $status ); }
	$token = (string) ( $begin['editor_session'] ?? '' );
	list( $status ) = request( 'PUT', 'config', $payload, true, $token );
	if ( 200 !== $status ) { return array( false, 0, 'put ' . $status ); }
	list( $status ) = request( 'POST', 'transaction/commit', null, true, $token );
	return array( 200 === $status, 0, 'commit ' . $status );
}

echo "live history deletion (real WordPress REST)\n";
echo "  site: {$base}\n";

list( $status, $baseline ) = request( 'GET', 'config' );
if ( 200 !== $status || ! is_array( $baseline ) ) {
	check( 'the site answers the configuration route', false, 'HTTP ' . $status );
	exit( 1 );
}
$baseline_hash    = fingerprint( $baseline );
$baseline_payload = payload_from( $baseline );
$baseline_bg      = (string) ( $baseline_payload['--theme-bg'] ?? '' );

$run    = substr( (string) time(), -6 ) . substr( (string) getmypid(), -3 );
$prefix = 'astbacc' . $run;
$keep   = $prefix . 'keep';
$drop   = $prefix . 'drop';

// ── 1. Create disposable snapshots ─────────────────────────────────────
list( $status, $snap ) = request( 'POST', 'snapshot', array( 'name' => $keep, 'label' => 'Acceptance keep ' . $run ) );
check( 'a disposable snapshot is created', 200 === $status, 'HTTP ' . $status );
list( $status ) = request( 'POST', 'snapshot', array( 'name' => $drop, 'label' => 'Acceptance drop ' . $run ) );
check( 'a second disposable snapshot is created', 200 === $status, 'HTTP ' . $status );
check( 'both snapshots are listed', in_array( $keep, snapshot_names(), true ) && in_array( $drop, snapshot_names(), true ) );

// ── 2. Create disposable revisions ─────────────────────────────────────
$before_ids = revision_ids();
$created    = array();
for ( $i = 1; $i <= 4; $i++ ) {
	$payload = $baseline_payload;
	$payload['--theme-bg'] = sprintf( '#%02x%02x%02x', 1, 2, $i + 16 );
	list( $ok, , $why ) = commit_change( $payload );
	check( 'a disposable revision is committed (' . $i . ')', $ok, $why );
	foreach ( revision_ids() as $id ) {
		if ( ! in_array( $id, $before_ids, true ) && ! in_array( $id, $created, true ) ) {
			$created[] = $id;
		}
	}
}
check( 'four new revisions were recorded', 4 === count( $created ), implode( ',', $created ) );
$config_after_commits = request( 'GET', 'config' );
$config_after_commits = $config_after_commits[1];

// ── 3. Individual snapshot deletion ────────────────────────────────────
list( $status, $deleted ) = request( 'POST', 'snapshots/delete', array( 'names' => array( $drop ) ) );
check( 'an individual snapshot delete is accepted', 200 === $status, 'HTTP ' . $status );
check( 'the snapshot delete reports the name', in_array( $drop, (array) ( $deleted['deleted'] ?? array() ), true ) );
check( 'the deletion reports a confirmed outcome', 'deleted' === ( $deleted['outcome'] ?? '' ), 'outcome=' . ( $deleted['outcome'] ?? 'none' ) );
check( 'the deletion reports that its audit record was written', 'deleted' === ( $deleted['audit_state'] ?? '' ), 'audit_state=' . ( $deleted['audit_state'] ?? 'none' ) );
check( 'the deletion ran inside a database transaction', ! empty( $deleted['atomicity']['database_transaction'] ),
    json_encode( $deleted['atomicity'] ?? array() ) );
check( 'the deleted snapshot is gone', ! in_array( $drop, snapshot_names(), true ) );
check( 'the other snapshot survives', in_array( $keep, snapshot_names(), true ) );
$config_after_snapshot_delete = request( 'GET', 'config' )[1];
check( 'deleting a snapshot does not change the configuration',
	$config_after_commits && fingerprint( $config_after_commits ) === fingerprint( $config_after_snapshot_delete ) );

// ── 4. Individual revision deletion, lineage and missing ───────────────
$middle = $created[1]; // The second-newest: its child's parent points at it.
list( $status, $deleted ) = request( 'POST', 'revisions/delete', array( 'ids' => array( $middle ) ) );
check( 'an individual revision delete is accepted', 200 === $status, 'HTTP ' . $status );
check( 'the revision delete reports the id', in_array( $middle, (array) ( $deleted['deleted'] ?? array() ), true ) );
check( 'the revision deletion reports a confirmed outcome', 'deleted' === ( $deleted['outcome'] ?? '' ), 'outcome=' . ( $deleted['outcome'] ?? 'none' ) );
check( 'the tombstone is recorded', in_array( $middle, (array) ( $deleted['tombstones'] ?? array() ), true ) );

list( $status, $list ) = request( 'GET', 'revisions' );
$child = null;
foreach ( (array) ( $list['revisions'] ?? array() ) as $item ) {
	if ( ( $item['parent'] ?? '' ) === $middle ) { $child = $item; break; }
}
check( 'a surviving child still points at the deleted id', is_array( $child ) && ( $child['parent'] ?? '' ) === $middle,
	is_array( $child ) ? $child['id'] . ' -> ' . $child['parent'] : 'no child' );
check( 'the surviving child reports the parent as deleted', is_array( $child ) && ! empty( $child['parent_deleted'] ) );

list( $status, $again ) = request( 'POST', 'revisions/delete', array( 'ids' => array( $middle ) ) );
check( 'deleting it again reports it missing', 200 === $status && in_array( $middle, (array) ( $again['missing'] ?? array() ), true ) );

list( $status ) = request( 'POST', 'revisions/delete', array( 'ids' => array( 'not-an-id' ) ) );
check( 'a malformed revision id is refused', 400 === $status, 'HTTP ' . $status );
list( $status ) = request( 'POST', 'snapshots/delete', array( 'names' => array( '../' . $keep ) ) );
check( 'a snapshot name that sanitises differently is refused', 400 === $status, 'HTTP ' . $status );
check( 'the near-miss snapshot survives', in_array( $keep, snapshot_names(), true ) );

// ── 5. Bulk deletion ───────────────────────────────────────────────────
$remaining = array_values( array_filter( $created, function ( $id ) use ( $middle ) { return $id !== $middle; } ) );
list( $status, $bulk ) = request( 'POST', 'revisions/delete', array( 'ids' => $remaining ) );
check( 'a bulk revision delete is accepted', 200 === $status, 'HTTP ' . $status );
check( 'every selected revision is deleted',
	count( array_intersect( $remaining, (array) ( $bulk['deleted'] ?? array() ) ) ) === count( $remaining ) );
check( 'no disposable revision remains', empty( array_intersect( $remaining, revision_ids() ) ) );

// ── 6. Competing deletions ─────────────────────────────────────────────
// Two requests deleting the same record at the same moment. Exactly one
// may report the record deleted; the other must report it as already gone
// rather than deleting it twice or losing one of the two requests.
list( $status, $seed ) = request( 'POST', 'transaction', array( 'label' => 'history competition' ) );
$seed_session = (string) ( $seed['editor_session'] ?? '' );
$seed_payload = payload_from( (array) request( 'GET', 'config' )[1] );
$seed_payload['--theme-bg'] = '#123123';
request( 'PUT', 'config', $seed_payload, true, $seed_session );
request( 'POST', 'transaction/commit', null, true, $seed_session );
$contested = null;
foreach ( revision_ids() as $id ) {
    if ( ! in_array( $id, $created, true ) ) { $contested = $id; break; }
}
if ( null === $contested ) {
    check( 'a record to delete twice exists', false, 'no fresh revision was recorded' );
} else {
    $multi   = curl_multi_init();
    $handles = array();
    for ( $i = 0; $i < 2; $i++ ) {
        $ch = curl_init( $base . '/wp-json/wp/v2/agentshell/revisions/delete' );
        $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode( $user . ':' . $pass ),
        );
        curl_setopt_array( $ch, array(
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode( array( 'ids' => array( $contested ) ) ),
            CURLOPT_TIMEOUT        => 30,
        ) );
        curl_multi_add_handle( $multi, $ch );
        $handles[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec( $multi, $running );
        curl_multi_select( $multi, 0.1 );
    } while ( $running > 0 );
    $bodies = array();
    $statuses = array();
    foreach ( $handles as $ch ) {
        $bodies[] = json_decode( (string) curl_multi_getcontent( $ch ), true );
        $statuses[] = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_multi_remove_handle( $multi, $ch );
    }
    curl_multi_close( $multi );
    $reported_deleted = 0;
    foreach ( $bodies as $body ) {
        if ( is_array( $body ) && in_array( 'deleted', (array) ( $body['outcome'] ?? '' ), true ) ) {
            $reported_deleted++;
        }
    }
    check( 'two competing deletions of one record: exactly one reports it deleted',
        1 === $reported_deleted, 'reported deleted by ' . $reported_deleted . ' of 2' );
    check( 'the record is gone exactly once', ! in_array( $contested, revision_ids(), true ) );
}

// ── 7. Permission refusal ──────────────────────────────────────────────
list( $status ) = request( 'POST', 'revisions/delete', array( 'ids' => array( 'r1' ) ), false );
check( 'an unauthenticated delete is refused', 401 === $status, 'HTTP ' . $status );

// ── 8. Restore safety and the active-transaction conflict ─────────────
$current_for_restore = request( 'GET', 'config' )[1];
list( $status ) = request( 'POST', 'snapshot', array( 'name' => $keep . 'r', 'label' => 'Acceptance restore ' . $run ) );
check( 'a restore snapshot is created', 200 === $status, 'HTTP ' . $status );
$changed = payload_from( $current_for_restore );
$changed['--theme-bg'] = '#010203' === (string) $changed['--theme-bg'] ? '#040506' : '#010203';
$before_restore_ids = revision_ids();
commit_change( $changed );
list( $status ) = request( 'POST', 'snapshots/restore', array( 'name' => $keep . 'r' ) );
check( 'a disposable snapshot restores', 200 === $status, 'HTTP ' . $status );
$restored = request( 'GET', 'config' )[1];
check( 'the restore returns the snapshotted configuration',
	fingerprint( $restored ) === fingerprint( $current_for_restore ) );

// The restore itself recorded a revision; it is this test's to remove.
foreach ( revision_ids() as $id ) {
	if ( ! in_array( $id, $before_restore_ids, true ) ) {
		request( 'POST', 'revisions/delete', array( 'ids' => array( $id ) ) );
	}
}

list( $status, $begin ) = request( 'POST', 'transaction', array( 'label' => 'history conflict' ) );
$token = (string) ( $begin['editor_session'] ?? '' );
list( $status ) = request( 'POST', 'snapshots/restore', array( 'name' => $keep ) );
check( 'restoring is refused while a transaction is open', 409 === $status, 'HTTP ' . $status );
request( 'DELETE', 'transaction', null, true, $token );
check( 'the conflicting transaction is gone', empty( request( 'GET', 'transaction' )[1]['open'] ) );

// ── 9. Restore the committed configuration and clean up ────────────────
$cleanup_ids = revision_ids();
list( $status, $restore_begin ) = request( 'POST', 'transaction', array( 'label' => 'history acceptance restore' ) );
$restore_session = (string) ( $restore_begin['editor_session'] ?? '' );
list( $status ) = request( 'PUT', 'config', $baseline_payload, true, $restore_session );
$stage_ok = 200 === $status;
list( $status ) = request( 'POST', 'transaction/commit', null, true, $restore_session );
$commit_ok = 200 === $status;
$final = request( 'GET', 'config' )[1];
check( 'the committed configuration was restored exactly', $stage_ok && $commit_ok && fingerprint( $final ) === $baseline_hash,
	'stage=' . ( $stage_ok ? 'yes' : 'no' ) . ' commit=' . ( $commit_ok ? 'yes' : 'no' ) );
foreach ( revision_ids() as $id ) {
	if ( ! in_array( $id, $cleanup_ids, true ) ) {
		request( 'POST', 'revisions/delete', array( 'ids' => array( $id ) ) );
	}
}
request( 'POST', 'snapshots/delete', array( 'names' => array( $keep, $keep . 'r' ) ) );
check( 'the disposable snapshots are gone',
	! in_array( $keep, snapshot_names(), true ) && ! in_array( $keep . 'r', snapshot_names(), true ) );
check( 'no transaction is left open', empty( request( 'GET', 'transaction' )[1]['open'] ) );

echo "\n" . $GLOBALS['_passed'] . ' passed, ' . $GLOBALS['_failed'] . " failed\n";
exit( $GLOBALS['_failed'] > 0 ? 1 : 0 );
