<?php
/**
 * Child half of the submission concurrency suite — one contender per process.
 *
 * Concurrency cannot be demonstrated inside one PHP process: `$wpdb` is one
 * connection, and a named advisory lock taken twice in the same connection is
 * re-entrant. Every contender here is a separate operating-system process with
 * its own MySQL connection, released at the same instant by a file barrier.
 *
 * argv: 1 wp_root  2 mode  3 report  4 ready  5 go  6.. mode args
 */

$wp_root = $argv[1] ?? '';
$mode    = $argv[2] ?? '';
$report  = $argv[3] ?? '';
$ready   = $argv[4] ?? '';
$go      = $argv[5] ?? '';
$args    = array_slice( $argv, 6 );

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

require_once dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php';

use AgentShell_Forms\Form_Challenge_Endpoint;
use AgentShell_Forms\Form_Challenges;
use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Install;
use AgentShell_Forms\Form_Notifier;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Rate_Limits;
use AgentShell_Forms\Form_Schema;
use AgentShell_Forms\Form_Shortcode;
use AgentShell_Forms\Submission_Handler;
use AgentShell_Forms\Submission_Store;

global $wpdb;

wp_set_current_user( 1 );

Form_Install::activate();
Form_Post_Types::register();
Form_Shortcode::register();
Form_Challenge_Endpoint::register();
Submission_Handler::register();
rest_get_server();

// The site-wide backstop is not what the rate-limit race is testing; the
// per-form quota is. Leaving the production value in place would let the
// backstop refuse requests the race is trying to observe being admitted.
add_filter(
    'agentshell_forms_site_rate_limit',
    function () {
        return 100000;
    }
);

/**
 * Announce readiness, then wait for the barrier.
 *
 * @return void
 */
function fsx_wait() {
    global $ready, $go;

    file_put_contents( $ready, (string) getmypid() );

    $deadline = microtime( true ) + 120;

    while ( microtime( true ) < $deadline ) {
        clearstatcache( true, $go );

        if ( is_file( $go ) ) {
            return;
        }

        usleep( 1000 );
    }

    throw new RuntimeException( 'the barrier never opened' );
}

/**
 * Emit the report and stop.
 *
 * @param array $data Report.
 * @return void
 */
function fsx_report( array $data ) {
    global $report;

    file_put_contents( $report, wp_json_encode( $data ) );
    echo "done\n";
    exit( 0 );
}

/**
 * Capture mail in a file every contender can see.
 *
 * A file, not a PHP variable: each contender is its own process, and the
 * question "how many messages were sent" is a question about all of them.
 *
 * @param string $log Path.
 * @return void
 */
function fsx_mail_log( $log, $delay_ms = 300 ) {
    $GLOBALS['fsx_mail_log_path'] = (string) $log;

    add_filter(
        'pre_wp_mail',
        function ( $return, $atts ) use ( $delay_ms ) {
            $line = gmdate( 'c' ) . "\t" . getmypid() . "\t" . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'] ) . "\n";

            file_put_contents( $GLOBALS['fsx_mail_log_path'], $line, FILE_APPEND | LOCK_EX );

            // Hold the "transport" open long enough that a second worker is
            // genuinely overlapping, rather than arriving after the first has
            // already finished and settled the record.
            usleep( max( 0, (int) $delay_ms ) * 1000 );

            return true;
        },
        10,
        2
    );
}

/**
 * A field list for the race forms.
 *
 * @return array
 */
function fsx_fields() {
    return array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Name',
            'required' => true,
            'settings' => array( 'max_length' => 120, 'min_length' => 0, 'autocomplete' => 'name' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'email',
            'type'     => 'email',
            'label'    => 'Email',
            'required' => false,
            'settings' => array( 'max_length' => 254, 'autocomplete' => 'email' ),
            'options'  => array(),
        ),
    );
}

/**
 * Create and publish a form.
 *
 * @param string $name    Name.
 * @param int    $max     Per-window submission quota.
 * @param bool   $notify  Whether to enable administrator notifications.
 * @return array
 */
function fsx_form( $name, $max, $notify = false ) {
    global $token;

    $definition = Form_Schema::defaults(
        Form_Definition::new_form_id(),
        'fsx-' . strtolower( substr( md5( $name . $token ), 0, 10 ) ),
        $name . ' ' . $token
    );

    $definition['fields'] = fsx_fields();
    $definition['settings']['notification']['enabled']    = (bool) $notify;
    $definition['settings']['notification']['recipients'] = array( 'site-admin' );
    $definition['settings']['notification']['subject']    = 'Race {form:name}';
    $definition['settings']['notification']['body_template'] = '{fields}';
    $definition['settings']['spam']['rate_limit']         = array( 'max' => (int) $max, 'window' => 300 );
    $definition['settings']['spam']['honeypot']           = false;
    $definition['settings']['spam']['min_seconds']        = 0;

    $created = Form_Definition::create(
        array(
            'name'       => $name . ' ' . $token,
            'slug'       => $definition['slug'],
            'definition' => $definition,
        ),
        1
    );

    if ( is_wp_error( $created ) ) {
        return array( 'error' => $created->get_error_message() );
    }

    $published = Form_Definition::publish( (string) $created['form_id'], (int) $created['revision'], 1 );

    if ( is_wp_error( $published ) ) {
        return array( 'error' => $published->get_error_message() );
    }

    return array(
        'form_id' => (string) $created['form_id'],
        'post_id' => (int) $created['post_id'],
        'version' => (int) $published['version'],
    );
}

function fsx_fields_values() {
    return array( 'name' => 'Race Contender', 'email' => 'race@example.invalid' );
}

function fsx_entries( $form_id ) {
    $posts = get_posts(
        array(
            'post_type'      => Form_Post_Types::ENTRY,
            'post_status'    => array( 'publish', 'trash' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'   => Submission_Store::META_FORM_ID,
                    'value' => (string) $form_id,
                ),
            ),
        )
    );

    return array_map( 'intval', (array) $posts );
}

function fsx_mint( $form_id, $address = '198.51.100.10' ) {
    $_SERVER['REMOTE_ADDR'] = $address;

    $request = new WP_REST_Request( 'POST', '/' . Form_Challenge_Endpoint::REST_NAMESPACE . Form_Challenge_Endpoint::ROUTE );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( wp_json_encode( array( 'form_id' => (string) $form_id ) ) );

    $data = rest_do_request( $request )->get_data();

    return array(
        'status' => isset( $data['challenge'] ) ? 'ok' : 'refused',
        'token'  => isset( $data['challenge'] ) ? (string) $data['challenge'] : '',
    );
}

function fsx_submit( $form_id, $version, $token_value, $address = '198.51.100.10' ) {
    $_SERVER['REMOTE_ADDR'] = $address;

    $request = new WP_REST_Request( 'POST', '/' . Submission_Handler::REST_NAMESPACE . Submission_Handler::ROUTE );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_header( 'accept', 'application/json' );
    $request->set_body(
        wp_json_encode(
            array(
                'form_id'      => (string) $form_id,
                'form_version' => (int) $version,
                'challenge'    => (string) $token_value,
                'fields'       => fsx_fields_values(),
            )
        )
    );

    $response = rest_do_request( $request );
    $data     = $response->get_data();

    return array(
        'status' => (int) $response->get_status(),
        'ok'     => is_array( $data ) && isset( $data['ok'] ) ? (bool) $data['ok'] : null,
        'code'   => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
    );
}

// ══ modes ════════════════════════════════════════════════════════════════════

switch ( $mode ) {
    case 'prepare':
        $token = (string) ( $args[0] ?? '' );

        $same    = fsx_form( 'Duplicate race', 100, false );
        $limited = fsx_form( 'Quota race', 3, false );
        $notify  = fsx_form( 'Notify race', 100, true );

        if ( isset( $same['error'] ) || isset( $limited['error'] ) || isset( $notify['error'] ) ) {
            fsx_report( array( 'error' => 'form', 'same' => $same, 'limited' => $limited, 'notify' => $notify ) );
        }

        // One token, to be presented by every duplicate contender.
        $duplicate_token = fsx_mint( $same['form_id'], '198.51.100.11' );

        // Six independent tokens for the quota race, minted before the race so
        // minting is not itself part of what is being measured.
        $quota_tokens = array();

        for ( $i = 0; $i < 6; $i++ ) {
            $minted = fsx_mint( $limited['form_id'], '198.51.100.' . ( 20 + $i ) );

            if ( 'ok' !== $minted['status'] ) {
                fsx_report( array( 'error' => 'mint', 'index' => $i, 'minted' => $minted ) );
            }

            $quota_tokens[] = $minted['token'];
        }

        // One submission with a pending notification, to be attempted by two
        // workers at once.
        $entry = Submission_Store::create(
            array(
                'form_id'      => (string) $notify['form_id'],
                'form_name'    => 'Notify race',
                'form_version' => (int) $notify['version'],
                'fields'       => fsx_fields_values(),
                'spam_status'  => 'clean',
                'meta'         => array( 'received_at' => gmdate( 'c' ) ),
                'challenge'    => '',
                'notification' => Form_Notifier::initial_record( 'admin' ),
                'confirmation' => null,
            )
        );

        if ( is_wp_error( $entry ) ) {
            fsx_report( array( 'error' => 'entry', 'message' => $entry->get_error_message() ) );
        }

        // A second submission with a pending notification, for the settlement
        // race: the first one is settled by the two-worker race below.
        $entry_two = Submission_Store::create(
            array(
                'form_id'      => (string) $notify['form_id'],
                'form_name'    => 'Notify race',
                'form_version' => (int) $notify['version'],
                'fields'       => fsx_fields_values(),
                'spam_status'  => 'clean',
                'meta'         => array( 'received_at' => gmdate( 'c' ) ),
                'challenge'    => '',
                'notification' => Form_Notifier::initial_record( 'admin' ),
                'confirmation' => null,
            )
        );

        if ( is_wp_error( $entry_two ) ) {
            fsx_report( array( 'error' => 'entry_two', 'message' => $entry_two->get_error_message() ) );
        }

        fsx_report(
            array(
                'token'            => (string) $token,
                'same'             => $same,
                'limited'          => $limited,
                'notify'           => $notify,
                'duplicate_token'  => $duplicate_token['token'],
                'quota_tokens'     => $quota_tokens,
                'notify_entry'     => (int) $entry['post_id'],
                'notify_entry_two' => (int) $entry_two['post_id'],
            )
        );
        break;

    case 'submit_same':
        $form_id  = (string) $args[0];
        $version  = (int) $args[1];
        $challenge = (string) $args[2];

        fsx_wait();
        $result = fsx_submit( $form_id, $version, $challenge );
        fsx_report( $result );
        break;

    case 'submit_quota':
        $form_id   = (string) $args[0];
        $version   = (int) $args[1];
        $challenge = (string) $args[2];
        $index     = (int) $args[3];

        fsx_wait();
        // One source address for every contender. The per-form quota is a
        // per-*(source, form)* counter, so six different addresses would be six
        // independent buckets and would prove nothing about the quota.
        $result = fsx_submit( $form_id, $version, $challenge, '198.51.100.20' );
        fsx_report( $result );
        break;

    case 'notify':
    case 'notify_slow':
        $entry_id = (int) $args[0];
        $log      = (string) $args[1];
        $delay    = ( 'notify_slow' === $mode ) ? (int) ( $args[2] ?? 6000 ) : 300;

        // The claim must expire while this worker is still working: the race
        // under test is an older worker finishing *after* a newer worker has
        // taken the record over. Two seconds is the shortest claim the
        // notifier will honour (a filter below one second is refused).
        add_filter(
            'agentshell_forms_notification_claim_ttl',
            function () {
                return 2;
            }
        );

        fsx_mail_log( $log, $delay );

        $record = Submission_Store::get( $entry_id, true );

        if ( is_wp_error( $record ) ) {
            fsx_report( array( 'error' => 'no_entry' ) );
        }

        $post_id = Form_Definition::resolve_post_id( (string) $record['form_id'] );
        $state   = $post_id > 0 ? Form_Definition::get_by_post_id( $post_id ) : null;

        if ( ! is_array( $state ) || ! is_array( $state['published'] ) ) {
            fsx_report( array( 'error' => 'no_definition' ) );
        }

        fsx_wait();

        $result = Form_Notifier::attempt( $entry_id, 'admin', $state['published'], $record['values'] );

        fsx_report(
            array(
                'outcome'  => (string) ( $result['outcome'] ?? '' ),
                'status'   => (string) ( $result['status'] ?? '' ),
                'attempts' => (int) ( $result['attempts'] ?? 0 ),
                'entry'    => Submission_Store::notification_state( $entry_id, 'admin' )['record'],
            )
        );
        break;

    case 'verify':
        $form_ids = array_map( 'strval', $args );

        $out = array();

        foreach ( $form_ids as $form_id ) {
            $out[ $form_id ] = count( fsx_entries( $form_id ) );
        }

        fsx_report( array( 'counts' => $out ) );
        break;

    case 'verify_notification':
        $entry_id = (int) ( $args[0] ?? 0 );
        $state    = Submission_Store::notification_state( $entry_id, 'admin' );

        fsx_report(
            array(
                'notification' => is_array( $state['record'] ) ? $state['record'] : array(),
            )
        );
        break;

    case 'cleanup':
        $form_ids = array_map( 'strval', $args );

        foreach ( $form_ids as $form_id ) {
            foreach ( fsx_entries( $form_id ) as $entry_id ) {
                Submission_Store::delete_permanently( $entry_id, 0, true );
            }

            $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $form_id ) );
            $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Form_Rate_Limits::table() . ' WHERE form_id = %s', $form_id ) );

            $post_id = Form_Definition::resolve_post_id( $form_id );

            if ( $post_id > 0 ) {
                wp_delete_post( $post_id, true );
            }
        }

        fsx_report( array( 'ok' => true ) );
        break;

    default:
        fsx_report( array( 'error' => 'unknown_mode', 'mode' => $mode ) );
}
