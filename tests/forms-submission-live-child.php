<?php
/**
 * Child half of the Phase 3 submission suite. Runs inside a real WordPress
 * and reports facts as JSON; the parent asserts them.
 *
 * Everything it creates carries a per-run marker and is removed before the
 * child exits. The values are obviously synthetic (`test@example.invalid`,
 * `Test User`) — no real visitor data is used anywhere in this suite.
 *
 * argv: 1 WordPress root  2 report path
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

require_once dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php';

use AgentShell_Forms\Form_Admin;
use AgentShell_Forms\Form_Capabilities;
use AgentShell_Forms\Form_Challenge_Endpoint;
use AgentShell_Forms\Form_Challenges;
use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Install;
use AgentShell_Forms\Form_Notifier;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Privacy;
use AgentShell_Forms\Form_Rejections;
use AgentShell_Forms\Form_Rate_Limits;
use AgentShell_Forms\Form_Schema;
use AgentShell_Forms\Form_Shortcode;
use AgentShell_Forms\Form_Turnstile;
use AgentShell_Forms\Submission_Handler;
use AgentShell_Forms\Submission_Store;

global $wpdb;

$token = 'ASs3' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array( 'posts' => array(), 'users' => array() );

/**
 * Record one probe's facts.
 *
 * @param string $name Probe.
 * @param array  $data Facts.
 * @return void
 */
function probe( $name, array $data ) {
    global $f;
    $f['probes'][ $name ] = $data;
}

/**
 * Track a post for cleanup.
 *
 * @param int $id Post id.
 * @return int
 */
function track( $id ) {
    global $mine;
    $mine['posts'][] = (int) $id;
    return (int) $id;
}

// ── plugin state ─────────────────────────────────────────────────────────────
// The permission probes below are about a real account: who may read a
// submission, and what the administrator role does *not* hold. User 0 is not
// an account, so every one of those questions would answer "no" for the
// wrong reason.
wp_set_current_user( 1 );

Form_Install::activate();
Form_Post_Types::register();
Form_Shortcode::register();
Form_Challenge_Endpoint::register();
Submission_Handler::register();
Form_Privacy::register();
rest_get_server();

// Keep the site-wide backstop out of the way: these probes deliberately make
// dozens of requests in one window, and the site limit is a production
// control, not something the suite is testing here (the per-form limit is).
add_filter(
    'agentshell_forms_site_rate_limit',
    function () {
        return 100000;
    }
);

// ── mail capture ─────────────────────────────────────────────────────────────
$GLOBALS['asf3_mail']     = array();
$GLOBALS['asf3_mail_ok']  = true;
$GLOBALS['asf3_mail_tick'] = 0;

add_filter(
    'pre_wp_mail',
    function ( $return, $atts ) {
        $GLOBALS['asf3_mail'][] = array(
            'to'      => $atts['to'],
            'subject' => $atts['subject'],
            'message' => $atts['message'],
            'headers' => $atts['headers'],
        );
        $GLOBALS['asf3_mail_tick']++;

        return $GLOBALS['asf3_mail_ok'] ? true : false;
    },
    10,
    2
);

function asf3_mails() {
    return $GLOBALS['asf3_mail'];
}

function asf3_mail_reset( $ok = true ) {
    $GLOBALS['asf3_mail']    = array();
    $GLOBALS['asf3_mail_ok'] = (bool) $ok;
}

// ── Turnstile transport ──────────────────────────────────────────────────────
$GLOBALS['asf3_ts'] = null;

add_filter(
    'agentshell_forms_turnstile_transport',
    function () {
        return function () {
            $response = $GLOBALS['asf3_ts'];

            if ( is_callable( $response ) ) {
                return call_user_func( $response );
            }

            return $response;
        };
    }
);

function asf3_ts_ok( $form_id ) {
    return array(
        'response' => array( 'code' => 200 ),
        'body'     => wp_json_encode(
            array(
                'success'  => true,
                'hostname' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
                'action'   => (string) $form_id,
            )
        ),
    );
}

function asf3_ts_fail( array $codes = array( 'timeout-or-duplicate' ) ) {
    return array(
        'response' => array( 'code' => 200 ),
        'body'     => wp_json_encode( array( 'success' => false, 'error-codes' => $codes ) ),
    );
}

// ── fixtures ─────────────────────────────────────────────────────────────────

/**
 * The standard field list for a contact form.
 *
 * @return array
 */
function asf3_fields() {
    return array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Your name',
            'required' => true,
            'settings' => array( 'max_length' => 120, 'min_length' => 0, 'autocomplete' => 'name' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'email',
            'type'     => 'email',
            'label'    => 'Your email',
            'required' => true,
            'settings' => array( 'max_length' => 254, 'autocomplete' => 'email' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'message',
            'type'     => 'textarea',
            'label'    => 'How can we help?',
            'required' => true,
            'settings' => array( 'max_length' => 200, 'min_length' => 0, 'rows' => 4 ),
            'options'  => array(),
        ),
        array(
            'key'      => 'consent',
            'type'     => 'consent',
            'label'    => 'I agree',
            'required' => true,
            'settings' => array( 'privacy_url' => '' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'source',
            'type'     => 'hidden',
            'label'    => 'Source',
            'required' => false,
            'settings' => array( 'value' => 'contact-page' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'topics',
            'type'     => 'checkbox',
            'label'    => 'Topics',
            'required' => false,
            'settings' => array(),
            'options'  => array(
                array( 'value' => 'design', 'label' => 'Design' ),
                array( 'value' => 'build', 'label' => 'Build' ),
            ),
        ),
    );
}

/**
 * Create and publish a form.
 *
 * @param string $name     Name.
 * @param array  $overrides Definition overrides (top level `settings` merge).
 * @param array  $fields   Fields, or null for the standard set.
 * @return array|\WP_Error
 */
function asf3_form( $name, array $overrides = array(), $fields = null ) {
    global $token;

    $definition = Form_Schema::defaults(
        Form_Definition::new_form_id(),
        'asf3-' . strtolower( substr( md5( $name . $token . random_int( 1, 99999 ) ), 0, 8 ) ),
        $name
    );

    $definition['fields'] = null === $fields ? asf3_fields() : $fields;

    $settings = Form_Schema::settings_defaults();
    $settings['success_message'] = 'Thanks from the bed.';
    $settings['notification']['enabled']    = true;
    $settings['notification']['recipients'] = array( 'site-admin' );
    $settings['notification']['subject']    = 'New submission: {form:name}';
    $settings['notification']['body_template'] = "A new submission arrived.\n\n{fields}\n";
    $settings['notification']['reply_to_field'] = 'email';
    $settings['spam']['honeypot']  = true;
    $settings['spam']['min_seconds'] = 0;
    $settings['spam']['rate_limit'] = array( 'max' => 50, 'window' => 300 );

    if ( isset( $overrides['settings'] ) && is_array( $overrides['settings'] ) ) {
        $settings = array_replace_recursive( $settings, $overrides['settings'] );
    }

    $definition['settings'] = $settings;

    $created = Form_Definition::create(
        array(
            'name'       => $name . ' ' . $token,
            'slug'       => $definition['slug'],
            'definition' => $definition,
        ),
        1
    );

    if ( is_wp_error( $created ) ) {
        return $created;
    }

    track( (int) $created['post_id'] );

    $published = Form_Definition::publish( (string) $created['form_id'], (int) $created['revision'], 1 );

    if ( is_wp_error( $published ) ) {
        return $published;
    }

    return array(
        'form_id'  => (string) $created['form_id'],
        'post_id'  => (int) $created['post_id'],
        'version'  => (int) $published['version'],
        'revision' => (int) $published['revision'],
        'slug'     => (string) $created['slug'],
    );
}

function asf3_reply( $response ) {
    $data = $response->get_data();

    return array(
        'status'  => (int) $response->get_status(),
        'body'    => $data,
        'code'    => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
        'ok'      => is_array( $data ) && isset( $data['ok'] ) ? (bool) $data['ok'] : null,
        'message' => is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '',
        'headers' => $response->get_headers(),
    );
}

function asf3_mint( $form_id, $address = '203.0.113.10' ) {
    $_SERVER['REMOTE_ADDR'] = $address;

    $request = new WP_REST_Request( 'POST', '/' . Form_Challenge_Endpoint::REST_NAMESPACE . Form_Challenge_Endpoint::ROUTE );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( wp_json_encode( array( 'form_id' => $form_id ) ) );

    $response = rest_do_request( $request );
    $data     = $response->get_data();

    return array(
        'status'  => (int) $response->get_status(),
        'token'   => is_array( $data ) && isset( $data['challenge'] ) ? (string) $data['challenge'] : '',
        'version' => is_array( $data ) && isset( $data['form_version'] ) ? (int) $data['form_version'] : 0,
    );
}

function asf3_submit( $form_id, $version, $challenge, array $fields, array $extra = array(), $address = '203.0.113.10' ) {
    $_SERVER['REMOTE_ADDR'] = $address;

    $body = array_merge(
        array(
            'form_id'      => (string) $form_id,
            'form_version' => (int) $version,
            'challenge'    => (string) $challenge,
            'fields'       => $fields,
        ),
        $extra
    );

    $request = new WP_REST_Request( 'POST', '/' . Submission_Handler::REST_NAMESPACE . Submission_Handler::ROUTE );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_header( 'accept', 'application/json' );
    $request->set_body( (string) wp_json_encode( $body ) );

    return asf3_reply( rest_do_request( $request ) );
}

function asf3_raw_submit( $raw, $address = '203.0.113.10' ) {
    $_SERVER['REMOTE_ADDR'] = $address;

    $request = new WP_REST_Request( 'POST', '/' . Submission_Handler::REST_NAMESPACE . Submission_Handler::ROUTE );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_header( 'accept', 'application/json' );
    $request->set_body( (string) $raw );

    return asf3_reply( rest_do_request( $request ) );
}

function asf3_entries( $form_id ) {
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

function asf3_entry( $id ) {
    return Submission_Store::get( (int) $id, true );
}

function asf3_challenge_row( $token ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            'SELECT form_id, form_version, consumed_at, submission_id, expires_at FROM ' . Form_Challenges::table() . ' WHERE token_hash = %s',
            hash( 'sha256', (string) $token )
        ),
        ARRAY_A
    );
}

function asf3_valid_fields() {
    return array(
        'name'    => 'Ada Lovelace',
        'email'   => 'test@example.invalid',
        'message' => 'A synthetic message for the bed.',
        'consent' => true,
        'topics'  => array( 'design' ),
        'evil'    => 'must not be stored',
    );
}

// ══ environment ══════════════════════════════════════════════════════════════

$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

probe(
    'environment',
    array(
        'wp_version'       => get_bloginfo( 'version' ),
        'php_version'      => PHP_VERSION,
        'submit_route'     => isset(
            rest_get_server()->get_routes()[ '/' . Submission_Handler::REST_NAMESPACE . Submission_Handler::ROUTE ]
        ),
        'entry_type'       => post_type_exists( Form_Post_Types::ENTRY ),
        'exporter'         => isset( $exporters[ Form_Privacy::KEY ] ) && is_callable( $exporters[ Form_Privacy::KEY ]['callback'] ),
        'eraser'           => isset( $erasers[ Form_Privacy::KEY ] ) && is_callable( $erasers[ Form_Privacy::KEY ]['callback'] ),
        'admin_class'      => class_exists( 'AgentShell_Forms\\Form_Admin' ),
        'cron_notifications' => false !== wp_next_scheduled( 'agentshell_forms_notifications' ),
        'cron_retention'   => false !== wp_next_scheduled( 'agentshell_forms_retention' ),
    )
);

// ══ the successful journey, and its replay ═══════════════════════════════════

asf3_mail_reset( true );

$form = asf3_form( 'Contact' );

if ( is_wp_error( $form ) ) {
    probe( 'fatal', array( 'where' => 'create', 'code' => $form->get_error_code(), 'message' => $form->get_error_message() ) );
    file_put_contents( $report, wp_json_encode( $f ) );
    exit( 0 );
}

$mint  = asf3_mint( $form['form_id'] );
$sent  = asf3_submit( $form['form_id'], $form['version'], $mint['token'], asf3_valid_fields() );

$entries = asf3_entries( $form['form_id'] );
$entry   = ! empty( $entries ) ? asf3_entry( $entries[0] ) : null;
$row     = asf3_challenge_row( $mint['token'] );
$mails   = asf3_mails();

$replay      = asf3_submit( $form['form_id'], $form['version'], $mint['token'], asf3_valid_fields() );
$after_replay = asf3_entries( $form['form_id'] );

$mail_headers = isset( $mails[0] ) ? $mails[0]['headers'] : array();

probe(
    'success',
    array(
        'status'       => $sent['status'],
        'ok'           => $sent['ok'],
        'message'      => $sent['message'],
        'cache_control' => isset( $sent['headers']['Cache-Control'] ) ? (string) $sent['headers']['Cache-Control'] : '',
        'robots'       => isset( $sent['headers']['X-Robots-Tag'] ) ? (string) $sent['headers']['X-Robots-Tag'] : '',
        'has_id'       => is_array( $sent['body'] ) && isset( $sent['body']['submission_id'] ),
        'entries'      => count( $entries ),
        'version'      => $entry ? (int) $entry['form_version'] : -1,
        'form_id'      => $entry ? (string) $entry['form_id'] : '',
        'data'         => $entry ? $entry['values'] : array(),
        'hidden'       => $entry && isset( $entry['values']['source'] ) ? $entry['values']['source'] : null,
        'spam'         => $entry ? (string) $entry['spam_status'] : '',
        'hash_ok'      => $entry ? hash_equals(
            Submission_Store::hash_payload( $form['form_id'], $form['version'], $entry['values'] ),
            (string) $entry['hash']
        ) : false,
        'challenge_linked' => $row ? (int) $row['submission_id'] : -1,
        'entry_id'     => $entry ? (int) $entry['id'] : 0,
        'mail_count'   => count( $mails ),
        'mail_to'      => isset( $mails[0] ) && is_array( $mails[0]['to'] )
            ? implode( ', ', array_map( 'strval', $mails[0]['to'] ) )
            : ( isset( $mails[0] ) ? (string) $mails[0]['to'] : '' ),
        'mail_subject' => isset( $mails[0] ) ? (string) $mails[0]['subject'] : '',
        'mail_body'    => isset( $mails[0] ) ? (string) $mails[0]['message'] : '',
        'mail_headers' => $mail_headers,
        'admin_email'  => (string) get_option( 'admin_email' ),
        'notification' => $entry && is_array( $entry['notification'] ) ? $entry['notification'] : null,
        'replay_status' => $replay['status'],
        'replay_ok'     => $replay['ok'],
        'replay_entries' => count( $after_replay ),
        'replay_mail'   => count( asf3_mails() ),
    )
);

// ══ validation happens before the token is spent ═════════════════════════════

$form_v = asf3_form( 'Validation' );
$mint_v = asf3_mint( $form_v['form_id'], '203.0.113.20' );

$fields_missing = asf3_valid_fields();
unset( $fields_missing['name'] );

$missing = asf3_submit( $form_v['form_id'], $form_v['version'], $mint_v['token'], $fields_missing, array(), '203.0.113.20' );
$missing_entries = asf3_entries( $form_v['form_id'] );
$missing_row     = asf3_challenge_row( $mint_v['token'] );

$corrected = asf3_submit( $form_v['form_id'], $form_v['version'], $mint_v['token'], asf3_valid_fields(), array(), '203.0.113.20' );

probe(
    'validation',
    array(
        'status'        => $missing['status'],
        'code'          => $missing['code'],
        'errors'        => is_array( $missing['body'] ) && isset( $missing['body']['errors'] ) ? $missing['body']['errors'] : array(),
        'entries'       => count( $missing_entries ),
        'challenge_consumed' => null !== $missing_row['consumed_at'],
        'corrected_status'   => $corrected['status'],
        'corrected_entries'  => count( asf3_entries( $form_v['form_id'] ) ),
    )
);

// ══ malformed requests ═══════════════════════════════════════════════════════

$form_m = asf3_form( 'Malformed' );
$mint_m = asf3_mint( $form_m['form_id'], '203.0.113.30' );

$list_fields = asf3_raw_submit(
    wp_json_encode(
        array(
            'form_id'      => $form_m['form_id'],
            'form_version' => $form_m['version'],
            'challenge'    => $mint_m['token'],
            'fields'       => array( 'one', 'two' ),
        )
    ),
    '203.0.113.30'
);

$no_version = asf3_raw_submit(
    wp_json_encode(
        array(
            'form_id'   => $form_m['form_id'],
            'challenge' => $mint_m['token'],
            'fields'    => asf3_valid_fields(),
        )
    ),
    '203.0.113.30'
);

$many = array();

for ( $i = 0; $i < 120; $i++ ) {
    $many[ 'k' . $i ] = 'x';
}

$too_many = asf3_submit( $form_m['form_id'], $form_m['version'], $mint_m['token'], $many, array(), '203.0.113.30' );

$oversize = asf3_submit(
    $form_m['form_id'],
    $form_m['version'],
    $mint_m['token'],
    array_merge( asf3_valid_fields(), array( 'message' => str_repeat( 'x', 22000 ) ) ),
    array(),
    '203.0.113.30'
);

$unknown = asf3_submit( str_repeat( 'a', 32 ), 1, $mint_m['token'], asf3_valid_fields(), array(), '203.0.113.30' );

$bad_challenge = asf3_submit( $form_m['form_id'], $form_m['version'], 'not-a-token', asf3_valid_fields(), array(), '203.0.113.30' );

$get_request  = new WP_REST_Request( 'GET', '/' . Submission_Handler::REST_NAMESPACE . Submission_Handler::ROUTE );
$get_response = rest_do_request( $get_request );

probe(
    'malformed',
    array(
        'list_fields_status' => $list_fields['status'],
        'list_fields_code'   => $list_fields['code'],
        'no_version_status'  => $no_version['status'],
        'no_version_code'    => $no_version['code'],
        'too_many_status'    => $too_many['status'],
        'oversize_status'    => $oversize['status'],
        'unknown_status'     => $unknown['status'],
        'unknown_code'       => $unknown['code'],
        'unknown_message'    => $unknown['message'],
        'bad_challenge_status' => $bad_challenge['status'],
        'bad_challenge_code'   => $bad_challenge['code'],
        'bad_challenge_message' => $bad_challenge['message'],
        'get_status'         => (int) $get_response->get_status(),
        'entries'            => count( asf3_entries( $form_m['form_id'] ) ),
    )
);

// ══ a genuinely stale page ═══════════════════════════════════════════════════

$form_s   = asf3_form( 'Stale' );
$old_mint = asf3_mint( $form_s['form_id'], '203.0.113.40' );

// The visitor's page was rendered from this version and its token was minted
// for it. Then the operator publishes a new one.
$working = Form_Definition::get_by_post_id( $form_s['post_id'] );
$changed = $working['working'];
$changed['settings']['success_message'] = 'A newer message.';
$updated = Form_Definition::update_working( $form_s['form_id'], $changed, (int) $working['revision'], 1 );
$published_v2 = is_wp_error( $updated ) ? $updated : Form_Definition::publish( $form_s['form_id'], (int) $updated['revision'], 1 );

$current_v2 = (int) Form_Definition::get_by_post_id( $form_s['post_id'] )['version'];

$stale = asf3_submit( $form_s['form_id'], $form_s['version'], $old_mint['token'], asf3_valid_fields(), array(), '203.0.113.40' );
$stale_row = asf3_challenge_row( $old_mint['token'] );

// The page claims the new version but presents a token minted for the old one.
$lying = asf3_submit( $form_s['form_id'], $current_v2, $old_mint['token'], asf3_valid_fields(), array(), '203.0.113.40' );

probe(
    'stale',
    array(
        'old_version'   => (int) $form_s['version'],
        'new_version'   => $current_v2,
        'published'     => ! is_wp_error( $published_v2 ) ? (int) $published_v2['version'] : -1,
        'status'        => $stale['status'],
        'code'          => $stale['code'],
        'reload'        => is_array( $stale['body'] ) && ! empty( $stale['body']['reload'] ),
        'body_version'  => is_array( $stale['body'] ) && isset( $stale['body']['version'] ) ? (int) $stale['body']['version'] : -1,
        'entries'       => count( asf3_entries( $form_s['form_id'] ) ),
        'challenge_consumed' => null !== $stale_row['consumed_at'],
        'lying_status'  => $lying['status'],
        'lying_code'    => $lying['code'],
    )
);

// ══ another form's token, and an expired token ══════════════════════════════

$form_a2 = asf3_form( 'Other A' );
$form_b2 = asf3_form( 'Other B' );
$mint_b  = asf3_mint( $form_b2['form_id'], '203.0.113.50' );

$mismatch = asf3_submit( $form_a2['form_id'], $form_a2['version'], $mint_b['token'], asf3_valid_fields(), array(), '203.0.113.50' );
$mint_b_row = asf3_challenge_row( $mint_b['token'] );

$mint_e = asf3_mint( $form_a2['form_id'], '203.0.113.51' );

$wpdb->query(
    $wpdb->prepare(
        'UPDATE ' . Form_Challenges::table() . ' SET expires_at = %s WHERE token_hash = %s',
        gmdate( 'Y-m-d H:i:s', time() - 60 ),
        hash( 'sha256', $mint_e['token'] )
    )
);

$expired = asf3_submit( $form_a2['form_id'], $form_a2['version'], $mint_e['token'], asf3_valid_fields(), array(), '203.0.113.51' );

probe(
    'challenge_rules',
    array(
        'mismatch_status'   => $mismatch['status'],
        'mismatch_code'     => $mismatch['code'],
        'mismatch_consumed' => null !== $mint_b_row['consumed_at'],
        'other_entries'     => count( asf3_entries( $form_a2['form_id'] ) ),
        'expired_status'    => $expired['status'],
        'expired_code'      => $expired['code'],
        'expired_entries'   => count( asf3_entries( $form_a2['form_id'] ) ),
        'expired_logged'    => Form_Rejections::count( 'challenge_expired' ) > 0,
    )
);

// ══ a claimed challenge that produced nothing: pending, then retry ══════════

$form_p   = asf3_form( 'Pending' );
$mint_p1  = asf3_mint( $form_p['form_id'], '203.0.113.65' );
$claimed  = Form_Challenges::claim( $mint_p1['token'] );

$pending = asf3_submit( $form_p['form_id'], $form_p['version'], $mint_p1['token'], asf3_valid_fields(), array(), '203.0.113.65' );

// Captured here, not after the retry: a pending attempt must not have written
// anything, and the later successful one must not be able to hide that.
$pending_entries = count( asf3_entries( $form_p['form_id'] ) );

// The attempt that claimed it is treated as dead once its claim is older than
// the grace period. The same token then earns a fresh challenge rather than
// an indefinite wait.
$wpdb->query(
    $wpdb->prepare(
        'UPDATE ' . Form_Challenges::table() . ' SET consumed_at = %s WHERE token_hash = %s',
        gmdate( 'Y-m-d H:i:s', time() - 120 ),
        hash( 'sha256', $mint_p1['token'] )
    )
);

$retry = asf3_submit( $form_p['form_id'], $form_p['version'], $mint_p1['token'], asf3_valid_fields(), array(), '203.0.113.65' );
$fresh = is_array( $retry['body'] ) && isset( $retry['body']['challenge'] ) ? (string) $retry['body']['challenge'] : '';

// The fresh token works, and produces exactly one submission.
$after_retry = '' !== $fresh
    ? asf3_submit( $form_p['form_id'], $form_p['version'], $fresh, asf3_valid_fields(), array(), '203.0.113.65' )
    : null;

probe(
    'pending',
    array(
        'claim_status'   => $claimed['status'],
        'pending_status' => $pending['status'],
        'pending_code'   => $pending['code'],
        'pending_retry_after' => isset( $pending['headers']['Retry-After'] ) ? (string) $pending['headers']['Retry-After'] : '',
        'pending_entries' => $pending_entries,
        'retry_status'   => $retry['status'],
        'retry_code'     => $retry['code'],
        'retry_flag'     => is_array( $retry['body'] ) && ! empty( $retry['body']['retry'] ),
        'retry_token'    => '' !== $fresh,
        'retry_version'  => is_array( $retry['body'] ) && isset( $retry['body']['form_version'] ) ? (int) $retry['body']['form_version'] : -1,
        'after_status'   => null !== $after_retry ? $after_retry['status'] : -1,
        'entries'        => count( asf3_entries( $form_p['form_id'] ) ),
        'logged_pending' => Form_Rejections::count( 'challenge_pending' ) > 0,
    )
);

// ══ the honeypot ═════════════════════════════════════════════════════════════

$form_h = asf3_form( 'Honeypot' );
$mint_h = asf3_mint( $form_h['form_id'], '203.0.113.60' );
asf3_mail_reset( true );

$honeypot = asf3_submit(
    $form_h['form_id'],
    $form_h['version'],
    $mint_h['token'],
    asf3_valid_fields(),
    array( Submission_Handler::HONEYPOT_FIELD => 'http://spam.example' ),
    '203.0.113.60'
);

probe(
    'honeypot',
    array(
        'status'   => $honeypot['status'],
        'ok'       => $honeypot['ok'],
        'message'  => $honeypot['message'],
        'entries'  => count( asf3_entries( $form_h['form_id'] ) ),
        'mail'     => count( asf3_mails() ),
        'logged'   => Form_Rejections::count( 'honeypot' ) > 0,
    )
);

// ══ the rate limit ═══════════════════════════════════════════════════════════

$form_r = asf3_form(
    'Rate limit',
    array(
        'settings' => array(
            'spam' => array(
                'rate_limit' => array( 'max' => 2, 'window' => 300 ),
            ),
        ),
    )
);

$statuses = array();
$retry    = '';

for ( $i = 0; $i < 3; $i++ ) {
    $m  = asf3_mint( $form_r['form_id'], '203.0.113.70' );
    $r  = asf3_submit( $form_r['form_id'], $form_r['version'], $m['token'], asf3_valid_fields(), array(), '203.0.113.70' );
    $statuses[] = $r['status'];

    if ( isset( $r['headers']['Retry-After'] ) ) {
        $retry = (string) $r['headers']['Retry-After'];
    }
}

probe(
    'rate_limit',
    array(
        'statuses' => $statuses,
        'retry_after' => $retry,
        'entries'  => count( asf3_entries( $form_r['form_id'] ) ),
        'logged'   => Form_Rejections::count( 'rate_limited' ) > 0,
    )
);

// ══ Turnstile policies ═══════════════════════════════════════════════════════

update_option( Form_Turnstile::OPTION_SITE_KEY, '0x4AAAAAAAtestsitekey' );

$form_t = asf3_form(
    'Turnstile required',
    array(
        'settings' => array(
            'spam' => array( 'turnstile' => 'required' ),
            'notification' => array( 'enabled' => false ),
        ),
    )
);

update_option( Form_Turnstile::OPTION_SECRET, '0x4AAAAAAAtestsecretvalue' );

$GLOBALS['asf3_ts'] = asf3_ts_ok( $form_t['form_id'] );
$mint_t_ok = asf3_mint( $form_t['form_id'], '203.0.113.80' );
$ts_ok = asf3_submit( $form_t['form_id'], $form_t['version'], $mint_t_ok['token'], asf3_valid_fields(), array( 'turnstile_token' => 'valid-token' ), '203.0.113.80' );
$ts_entry = asf3_entries( $form_t['form_id'] );

$GLOBALS['asf3_ts'] = asf3_ts_fail();
$mint_t_bad = asf3_mint( $form_t['form_id'], '203.0.113.81' );
$ts_bad = asf3_submit( $form_t['form_id'], $form_t['version'], $mint_t_bad['token'], asf3_valid_fields(), array( 'turnstile_token' => 'bad-token' ), '203.0.113.81' );

$GLOBALS['asf3_ts'] = new WP_Error( 'http_request_failed', 'timeout' );
$mint_t_down = asf3_mint( $form_t['form_id'], '203.0.113.82' );
$ts_down = asf3_submit( $form_t['form_id'], $form_t['version'], $mint_t_down['token'], asf3_valid_fields(), array( 'turnstile_token' => 'a-token' ), '203.0.113.82' );

$GLOBALS['asf3_ts'] = asf3_ts_ok( $form_t['form_id'] );
$mint_t_none = asf3_mint( $form_t['form_id'], '203.0.113.83' );
$ts_none = asf3_submit( $form_t['form_id'], $form_t['version'], $mint_t_none['token'], asf3_valid_fields(), array(), '203.0.113.83' );

$form_o = asf3_form(
    'Turnstile optional',
    array(
        'settings' => array(
            'spam' => array( 'turnstile' => 'optional' ),
            'notification' => array( 'enabled' => false ),
        ),
    )
);

$opt_before = asf3_entries( $form_o['form_id'] );

$GLOBALS['asf3_ts'] = asf3_ts_fail();
$mint_o_bad = asf3_mint( $form_o['form_id'], '203.0.113.84' );
$opt_bad = asf3_submit( $form_o['form_id'], $form_o['version'], $mint_o_bad['token'], asf3_valid_fields(), array( 'turnstile_token' => 'bad-token' ), '203.0.113.84' );
$opt_after_bad = asf3_entries( $form_o['form_id'] );
$opt_bad_new   = array_values( array_diff( $opt_after_bad, $opt_before ) );
$opt_record    = 1 === count( $opt_bad_new ) ? asf3_entry( (int) $opt_bad_new[0] ) : null;

$GLOBALS['asf3_ts'] = new WP_Error( 'http_request_failed', 'timeout' );
$mint_o_down = asf3_mint( $form_o['form_id'], '203.0.113.85' );
$opt_down = asf3_submit( $form_o['form_id'], $form_o['version'], $mint_o_down['token'], asf3_valid_fields(), array( 'turnstile_token' => 'a-token' ), '203.0.113.85' );
$opt_after_down  = asf3_entries( $form_o['form_id'] );
$opt_down_new    = array_values( array_diff( $opt_after_down, $opt_after_bad ) );
$opt_down_record = 1 === count( $opt_down_new ) ? asf3_entry( (int) $opt_down_new[0] ) : null;

// A required form with no secret cannot be protected, and says so by refusing.
update_option( Form_Turnstile::OPTION_SECRET, '' );
$mint_t_mis = asf3_mint( $form_t['form_id'], '203.0.113.86' );
$ts_mis = asf3_submit( $form_t['form_id'], $form_t['version'], $mint_t_mis['token'], asf3_valid_fields(), array( 'turnstile_token' => 'a-token' ), '203.0.113.86' );

// A test credential in production is refused as a misconfiguration.
update_option( Form_Turnstile::OPTION_SECRET, '1x0000000000000000000000000000000AA' );
$mint_t_test = asf3_mint( $form_t['form_id'], '203.0.113.87' );
$ts_test = asf3_submit( $form_t['form_id'], $form_t['version'], $mint_t_test['token'], asf3_valid_fields(), array( 'turnstile_token' => 'a-token' ), '203.0.113.87' );

delete_option( Form_Turnstile::OPTION_SECRET );
delete_option( Form_Turnstile::OPTION_SITE_KEY );

probe(
    'turnstile',
    array(
        'ok_status'        => $ts_ok['status'],
        'ok_spam'          => ! empty( $ts_entry ) ? (string) asf3_entry( $ts_entry[0] )['spam_status'] : '',
        'failed_status'    => $ts_bad['status'],
        'failed_code'      => $ts_bad['code'],
        'failed_logged'    => Form_Rejections::count( 'turnstile_failed' ) > 0,
        'down_status'      => $ts_down['status'],
        'down_logged'      => Form_Rejections::count( 'turnstile_unavailable' ) > 0,
        'missing_status'   => $ts_none['status'],
        'required_entries' => count( asf3_entries( $form_t['form_id'] ) ),
        'opt_failed_status' => $opt_bad['status'],
        'opt_failed_spam'   => $opt_record ? (string) $opt_record['spam_status'] : '',
        'opt_down_status'   => $opt_down['status'],
        'opt_down_spam'     => $opt_down_record ? (string) $opt_down_record['spam_status'] : '',
        'misconfigured_status' => $ts_mis['status'],
        'misconfigured_logged' => Form_Rejections::count( 'turnstile_misconfigured' ) > 0,
        'test_credential_status' => $ts_test['status'],
        'env_type'              => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
    )
);

// ══ storage is limited to the declared fields ═══════════════════════════════

$form_x = asf3_form( 'Cross form' );
$mint_x = asf3_mint( $form_x['form_id'], '203.0.113.90' );

$cross = asf3_submit(
    $form_x['form_id'],
    $form_x['version'],
    $mint_x['token'],
    array_merge( asf3_valid_fields(), array( 'other_form_secret' => 'leak', 'source' => 'spoofed' ) ),
    array(),
    '203.0.113.90'
);

$cross_entries = asf3_entries( $form_x['form_id'] );
$cross_entry   = ! empty( $cross_entries ) ? asf3_entry( $cross_entries[0] ) : null;

probe(
    'storage',
    array(
        'status'        => $cross['status'],
        'has_other'     => $cross_entry && array_key_exists( 'other_form_secret', $cross_entry['values'] ),
        'hidden_value'  => $cross_entry && isset( $cross_entry['values']['source'] ) ? $cross_entry['values']['source'] : null,
        'keys'          => $cross_entry ? array_keys( $cross_entry['values'] ) : array(),
    )
);

// ══ permissions and export ══════════════════════════════════════════════════

$admin_role = get_role( 'administrator' );
$admin_user = (int) get_current_user_id();

$admin_has_cap = $admin_role instanceof WP_Role && $admin_role->has_cap( Form_Capabilities::CAP_SUBMISSIONS );
$admin_manage  = user_can( $admin_user, 'manage_options' );

$target_id = ! empty( $cross_entries ) ? (int) $cross_entries[0] : 0;

$can_before = Form_Capabilities::can_access_submissions( $admin_user );

// An account with no submission access cannot delete a submission, even one
// that exists and is visible to it.
$editor_id = wp_create_user( 'asf3editor' . strtolower( $token ), 'EditorPass!' . $token, 'editor@example.invalid' );
$mine['users'][] = (int) $editor_id;

$editor_delete  = Submission_Store::delete_permanently( $target_id, (int) $editor_id, true );
$editor_refused = is_wp_error( $editor_delete );
$still_there    = ! is_wp_error( Submission_Store::get( $target_id, false ) );

// The owner grants it to themselves, which is the Phase 1 per-user mechanism.
$granted   = Form_Capabilities::grant_submissions( $admin_user, $admin_user );
$can_after = Form_Capabilities::can_access_submissions( $admin_user );

// Now the per-post capability really does hold, and the same call succeeds
// against a *second* submission (the first is the export's subject).
$second_id = 0;

foreach ( $cross_entries as $candidate ) {
    if ( (int) $candidate !== $target_id ) {
        $second_id = (int) $candidate;
        break;
    }
}

$cap_user_can_delete = user_can( $admin_user, 'delete_post', $target_id );

$export_csv  = Form_Admin::export_payload( $form_x['form_id'], 'csv', $admin_user );
$export_json = Form_Admin::export_payload( $form_x['form_id'], 'json', $admin_user );

$revoked     = Form_Capabilities::revoke_submissions( $admin_user, $admin_user );
$can_revoked = Form_Capabilities::can_access_submissions( $admin_user );

// Leave the account exactly as it was found: it never held this capability.
$export_after_revoke = Form_Admin::export_payload( $form_x['form_id'], 'csv', $admin_user );

$audit_events = array();

foreach ( \AgentShell_Forms\Form_Audit::entries() as $entry_row ) {
    if ( isset( $entry_row['event'] ) ) {
        $audit_events[] = (string) $entry_row['event'];
    }
}

probe(
    'permissions',
    array(
        'admin_role_has_cap' => $admin_has_cap,
        'admin_manage_options' => $admin_manage,
        'can_before'   => $can_before,
        'granted'      => $granted,
        'can_after'    => $can_after,
        'editor_refused' => $editor_refused,
        'editor_code'  => is_wp_error( $editor_delete ) ? $editor_delete->get_error_code() : '',
        'editor_left_it' => $still_there,
        'cap_user_can_delete' => (bool) $cap_user_can_delete,
        'csv_is_array' => is_array( $export_csv ),
        'csv_rows'     => is_array( $export_csv ) ? (int) $export_csv['count'] : -1,
        'csv_has_email' => is_array( $export_csv ) && false !== strpos( $export_csv['body'], 'test@example.invalid' ),
        'json_is_array' => is_array( $export_json ),
        'revoked'      => $revoked,
        'can_revoked'  => $can_revoked,
        'export_after_revoke_refused' => is_wp_error( $export_after_revoke ),
        'audit'        => $audit_events,
    )
);

// ══ cleanup ═════════════════════════════════════════════════════════════════

// Entries first: they are the personal data, and a form deleted before them
// would leave them unattributable.
foreach ( array( $form['form_id'], $form_v['form_id'], $form_m['form_id'], $form_s['form_id'], $form_a2['form_id'], $form_b2['form_id'], $form_h['form_id'], $form_r['form_id'], $form_t['form_id'], $form_o['form_id'], $form_x['form_id'], $form_p['form_id'] ) as $cleanup_form ) {
    foreach ( asf3_entries( $cleanup_form ) as $entry_id ) {
        Submission_Store::delete_permanently( $entry_id, 0, true );
    }

    $wpdb->query(
        $wpdb->prepare( 'DELETE FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $cleanup_form )
    );
    $wpdb->query(
        $wpdb->prepare( 'DELETE FROM ' . Form_Rate_Limits::table() . ' WHERE form_id = %s', $cleanup_form )
    );
}

foreach ( $mine['posts'] as $post_id ) {
    wp_delete_post( (int) $post_id, true );
}

foreach ( $mine['users'] as $user_id ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( (int) $user_id );
}

Form_Rejections::clear();

probe(
    'cleanup',
    array(
        'remaining_entries' => count(
            get_posts(
                array(
                    'post_type'      => Form_Post_Types::ENTRY,
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                        array(
                            'key'     => Submission_Store::META_FORM_ID,
                            'value'   => array( $form['form_id'], $form_v['form_id'], $form_m['form_id'], $form_s['form_id'], $form_a2['form_id'], $form_b2['form_id'], $form_h['form_id'], $form_r['form_id'], $form_t['form_id'], $form_o['form_id'], $form_x['form_id'], $form_p['form_id'] ),
                            'compare' => 'IN',
                        ),
                    ),
                )
            )
        ),
    )
);

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
