<?php
/**
 * Child half of the forms protocol-state suite.
 *
 * argv: 1 wp_root  2 report path
 */

$wp_root = $argv[1] ?? '';
$report  = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

use AgentShell_Forms\Form_Challenges;
use AgentShell_Forms\Form_Rate_Limits;

global $wpdb;

$f = array();

/**
 * Record a probe.
 *
 * @param string $name Probe name.
 * @param array  $data Facts.
 * @return void
 */
function probe( $name, array $data ) {
    global $f;
    $f[ $name ] = $data;
}

/**
 * One challenge row by token.
 *
 * @param string $token The plaintext token.
 * @return array|null
 */
function challenge_row( $token ) {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . Form_Challenges::table() . ' WHERE token_hash = %s',
            hash( 'sha256', (string) $token )
        ),
        ARRAY_A
    );
    return is_array( $row ) ? $row : null;
}

/* ---------------------------------------------------------------------------
 * Challenges
 * ------------------------------------------------------------------------ */

$form_id = str_repeat( 'ab', 16 ); // a 32-hex identifier, not a real form: the
                                   // challenge table has no foreign key by design.

$token = Form_Challenges::mint( $form_id, 7, 'subject-hash', 3600 );

$row = is_wp_error( $token ) ? null : challenge_row( $token );

probe(
    'c1_mint',
    array(
        'is_error'      => is_wp_error( $token ),
        'code'          => is_wp_error( $token ) ? $token->get_error_code() : '',
        'token_is_hex'  => is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token ),
        'token_length'  => is_string( $token ) ? strlen( $token ) : 0,
        'row_exists'    => null !== $row,
        'stored_is_hash' => null !== $row && $row['token_hash'] === hash( 'sha256', (string) $token ),
        'plaintext_absent' => null !== $row && false === strpos( (string) $row['token_hash'], (string) $token ),
        'form_id'       => null !== $row ? (string) $row['form_id'] : '',
        'form_version'  => null !== $row ? (int) $row['form_version'] : 0,
        'subject_hash'  => null !== $row ? (string) $row['subject_hash'] : '',
        'unconsumed'    => null !== $row && null === $row['consumed_at'],
        'submission_id' => null !== $row ? (int) $row['submission_id'] : 0,
        'seconds_to_expiry' => null !== $row ? strtotime( (string) $row['expires_at'] . ' UTC' ) - time() : 0,
    )
);

$first_claim  = Form_Challenges::claim( (string) $token );
$second_claim = Form_Challenges::claim( (string) $token );
$linked       = Form_Challenges::link( (string) $token, 4242 );
$after_link   = Form_Challenges::claim( (string) $token );
$row_after    = challenge_row( (string) $token );

probe(
    'c2_claim',
    array(
        'first_status'      => (string) ( $first_claim['status'] ?? '' ),
        'first_form_id'     => (string) ( $first_claim['form_id'] ?? '' ),
        'first_form_version' => (int) ( $first_claim['form_version'] ?? 0 ),
        'second_status'     => (string) ( $second_claim['status'] ?? '' ),
        'second_submission' => (int) ( $second_claim['submission_id'] ?? -1 ),
        'linked'            => true === ( $linked['linked'] ?? false ),
        'linked_status'     => (string) ( $linked['status'] ?? '' ),
        'linked_changed'    => true === ( $linked['changed'] ?? false ),
        'linked_submission' => (int) ( $linked['submission_id'] ?? -1 ),
        'after_link_status' => (string) ( $after_link['status'] ?? '' ),
        'after_link_submission' => (int) ( $after_link['submission_id'] ?? -1 ),
        'consumed_at_set'   => null !== $row_after && null !== $row_after['consumed_at'],
        'submission_stored' => null !== $row_after ? (int) $row_after['submission_id'] : 0,
    )
);

/* ---------------------------------------------------------------------------
 * C2b — the linking rules
 *
 * Linking is what turns a consumed token into a recognisable retry, so the
 * first link is the only one that may ever happen. Each refusal below is a
 * different reason for not writing, and none of them may write anyway.
 * ------------------------------------------------------------------------ */

$link_probe = array();

// (1) An unclaimed challenge has no attempt to record a submission for.
$unclaimed_token = Form_Challenges::mint( $form_id, 1, '', 3600 );
$unclaimed_link  = Form_Challenges::link( (string) $unclaimed_token, 111 );
$link_probe['unclaimed_status'] = (string) ( $unclaimed_link['status'] ?? '' );
$link_probe['unclaimed_linked'] = true === ( $unclaimed_link['linked'] ?? true );
$link_probe['unclaimed_changed'] = true === ( $unclaimed_link['changed'] ?? true );
$link_probe['unclaimed_submission'] = (int) ( ( challenge_row( (string) $unclaimed_token )['submission_id'] ?? -1 ) );

// (2) An expired challenge is not a valid one, even if it was claimed.
$rotten_token = Form_Challenges::mint( $form_id, 1, '', 3600 );
Form_Challenges::claim( (string) $rotten_token );
$wpdb->update(
    Form_Challenges::table(),
    array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
    array( 'token_hash' => hash( 'sha256', (string) $rotten_token ) ),
    array( '%s' ),
    array( '%s' )
);
$rotten_link = Form_Challenges::link( (string) $rotten_token, 222 );
$link_probe['expired_status'] = (string) ( $rotten_link['status'] ?? '' );
$link_probe['expired_linked'] = true === ( $rotten_link['linked'] ?? true );
$link_probe['expired_submission'] = (int) ( ( challenge_row( (string) $rotten_token )['submission_id'] ?? -1 ) );

// (3) A live, claimed challenge links once — and only once to one submission.
$once_token = Form_Challenges::mint( $form_id, 1, '', 3600 );
Form_Challenges::claim( (string) $once_token );
$first_link  = Form_Challenges::link( (string) $once_token, 333 );
$second_link = Form_Challenges::link( (string) $once_token, 444 );
$third_link  = Form_Challenges::link( (string) $once_token, 333 );
$once_row    = challenge_row( (string) $once_token );

$link_probe['first_status']      = (string) ( $first_link['status'] ?? '' );
$link_probe['first_changed']     = true === ( $first_link['changed'] ?? false );
$link_probe['conflict_status']   = (string) ( $second_link['status'] ?? '' );
$link_probe['conflict_linked']   = true === ( $second_link['linked'] ?? true );
$link_probe['conflict_changed']  = true === ( $second_link['changed'] ?? true );
$link_probe['conflict_reports_existing'] = (int) ( $second_link['submission_id'] ?? -1 );
$link_probe['idempotent_status'] = (string) ( $third_link['status'] ?? '' );
$link_probe['idempotent_linked'] = true === ( $third_link['linked'] ?? false );
$link_probe['idempotent_changed'] = true === ( $third_link['changed'] ?? true );
$link_probe['stored_submission'] = null !== $once_row ? (int) $once_row['submission_id'] : -1;

// (4) Inputs that cannot ever be a link.
$link_probe['invalid_token_status'] = (string) ( Form_Challenges::link( 'not-a-token', 555 )['status'] ?? '' );
$link_probe['short_token_status']   = (string) ( Form_Challenges::link( 'abc', 555 )['status'] ?? '' );
$link_probe['absent_status']        = (string) ( Form_Challenges::link( bin2hex( random_bytes( 32 ) ), 555 )['status'] ?? '' );
$link_probe['zero_submission_status'] = (string) ( Form_Challenges::link( (string) $once_token, 0 )['status'] ?? '' );
$link_probe['stored_after_invalid'] = (int) ( ( challenge_row( (string) $once_token )['submission_id'] ?? -1 ) );

probe( 'c2b_link_rules', $link_probe );

probe(
    'c3_absent',
    ( function () {
        $r = Form_Challenges::claim( bin2hex( random_bytes( 32 ) ) );
        return array( 'status' => (string) ( $r['status'] ?? '' ) );
    } )()
);

// Expiry: mint, move the expiry into the past, then claim.
$expiring = Form_Challenges::mint( $form_id, 1, '', 60 );
$wpdb->update(
    Form_Challenges::table(),
    array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
    array( 'token_hash' => hash( 'sha256', (string) $expiring ) ),
    array( '%s' ),
    array( '%s' )
);
$expired_claim = Form_Challenges::claim( (string) $expiring );

probe(
    'c4_expired',
    array(
        'mint_ok' => ! is_wp_error( $expiring ),
        'status'  => (string) ( $expired_claim['status'] ?? '' ),
        'form_id' => (string) ( $expired_claim['form_id'] ?? '' ),
    )
);

// A live token and an expired one: prune must remove exactly the expired one.
$kept = Form_Challenges::mint( $form_id, 1, '', 3600 );
Form_Challenges::prune();
$kept_row   = challenge_row( (string) $kept );
$expired_row = challenge_row( (string) $expiring );

probe(
    'c5_prune',
    array(
        'kept_survives'    => null !== $kept_row,
        'expired_removed'  => null === $expired_row,
    )
);

/* ---------------------------------------------------------------------------
 * Rate limits
 * ------------------------------------------------------------------------ */

$scope   = Form_Rate_Limits::SCOPE_FORM;
$subject = 'rate-subject-' . bin2hex( random_bytes( 6 ) );
$window  = 60;
$base    = 1700000000 - ( 1700000000 % $window ); // a window boundary

$sequence = array();
for ( $i = 1; $i <= 4; $i++ ) {
    $r = Form_Rate_Limits::reserve( $scope, $subject, $form_id, 3, $window, $base );
    $sequence[] = array(
        'admitted' => (bool) $r['admitted'],
        'hits'     => (int) $r['hits'],
        'error'    => is_wp_error( $r['error'] ) ? $r['error']->get_error_code() : '',
    );
}

$hits_now = Form_Rate_Limits::hits( $scope, $subject, $form_id, $window, $base );

$next_window = Form_Rate_Limits::reserve( $scope, $subject, $form_id, 3, $window, $base + $window );
$site_scope  = Form_Rate_Limits::reserve( Form_Rate_Limits::SCOPE_SITE, Form_Rate_Limits::SITE_SUBJECT, '', 3, $window, $base );

probe(
    'r1_sequence',
    array(
        'sequence'      => $sequence,
        'hits_read'     => $hits_now,
        'next_window'   => array(
            'admitted' => (bool) $next_window['admitted'],
            'hits'     => (int) $next_window['hits'],
            'start'    => (int) $next_window['window_start'],
        ),
        'expected_next_start' => (int) $base + (int) $window,
        'site_scope_admitted' => (bool) $site_scope['admitted'],
        'site_scope_hits'     => (int) $site_scope['hits'],
        'window_start_floors' => array(
            Form_Rate_Limits::window_start( 60, $base + 59 ),
            Form_Rate_Limits::window_start( 60, $base + 60 ),
            Form_Rate_Limits::window_start( 60, $base ),
        ),
        'window_start_expected' => array( $base, $base + $window, $base ),
        'base_window' => $base,
    )
);

// Prune: an expired bucket goes, a live one stays.
global $wpdb;
$live_window = 3600;
$live = Form_Rate_Limits::reserve( $scope, 'live-' . $subject, $form_id, 5, $live_window, time() );
$wpdb->update(
    Form_Rate_Limits::table(),
    array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ),
    array( 'subject_hash' => $subject ),
    array( '%s' ),
    array( '%s' )
);
Form_Rate_Limits::prune();

$stale_rows = (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Form_Rate_Limits::table() . ' WHERE subject_hash = %s',
        $subject
    )
);
$live_rows = (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Form_Rate_Limits::table() . ' WHERE subject_hash = %s',
        'live-' . $subject
    )
);

probe(
    'r2_prune',
    array(
        'live_reserve_ok' => (bool) $live['admitted'],
        'stale_rows'      => $stale_rows,
        'live_rows'       => $live_rows,
    )
);

/* ---------------------------------------------------------------------------
 * Cleanup
 * ------------------------------------------------------------------------ */

Form_Challenges::delete_all();
Form_Rate_Limits::delete_all();

$challenge_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Form_Challenges::table() );
$rate_rows      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Form_Rate_Limits::table() );

probe(
    'cleanup',
    array(
        'challenge_rows' => $challenge_rows,
        'rate_rows'      => $rate_rows,
    )
);

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
exit( 0 );
