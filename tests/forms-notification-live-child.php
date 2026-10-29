<?php
/**
 * Child half of the notification lifecycle suite. Runs inside a real
 * WordPress, with `wp_mail()` intercepted so the transport can be made to
 * accept, refuse, hang or throw.
 *
 * The claims under test are the ones §11.6 and §11.6a make:
 *
 *   - a notification is claimed atomically, so two workers cannot both send;
 *   - a failure is retried with a growing delay and then given up on;
 *   - a claim that outlives its TTL becomes `uncertain`, and that is the only
 *     status that admits not knowing;
 *   - an attempt that runs past the budget is `uncertain`, not `accepted`;
 *   - **a notification failure never creates a second submission**, and a
 *     retry never sends a second message after one is accepted.
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

use AgentShell_Forms\Form_Audit;
use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Notifier;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Schema;
use AgentShell_Forms\Submission_Store;

global $wpdb;

$token = 'ASn3' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array( 'posts' => array() );

function probe( $name, array $data ) {
    global $f;
    $f['probes'][ $name ] = $data;
}

wp_set_current_user( 1 );

// ── the transport, under the suite's control ────────────────────────────────

$GLOBALS['asn_mail']     = array();
$GLOBALS['asn_mode']     = 'ok';      // ok | fail | throw | slow
$GLOBALS['asn_slow']     = 2;

add_filter(
    'pre_wp_mail',
    function ( $return, $atts ) {
        $GLOBALS['asn_mail'][] = array(
            'to'      => $atts['to'],
            'subject' => $atts['subject'],
            'message' => $atts['message'],
            'headers' => $atts['headers'],
            'mode'    => (string) $GLOBALS['asn_mode'],
        );

        if ( 'throw' === $GLOBALS['asn_mode'] ) {
            throw new RuntimeException( 'transport exploded' );
        }

        if ( 'slow' === $GLOBALS['asn_mode'] ) {
            sleep( (int) $GLOBALS['asn_slow'] );
            return true;
        }

        return 'fail' !== $GLOBALS['asn_mode'];
    },
    10,
    2
);

function asn_reset( $mode = 'ok' ) {
    $GLOBALS['asn_mail'] = array();
    $GLOBALS['asn_mode'] = (string) $mode;
}

function asn_mails() {
    return $GLOBALS['asn_mail'];
}

// ── fixtures ────────────────────────────────────────────────────────────────

function asn_fields() {
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
    );
}

/**
 * A definition document, without creating a form.
 *
 * The notifier is given a definition and values; it does not read the store
 * for them, because a retry must send what the form promised *then*.
 *
 * @param array $notification Notification overrides.
 * @param array $fields       Fields.
 * @return array
 */
function asn_definition( array $notification = array(), $fields = null ) {
    global $token;

    $definition = Form_Schema::defaults( Form_Definition::new_form_id(), 'asn-' . strtolower( substr( md5( $token ), 0, 8 ) ), 'Contact' );
    $definition['version'] = 1;
    $definition['fields']  = null === $fields ? asn_fields() : $fields;
    $definition['settings']['notification'] = array_merge(
        array(
            'enabled'        => true,
            'recipients'     => array( 'site-admin' ),
            'subject'        => 'New submission: {form:name}',
            'body_template'  => "From {field:name}\n\n{fields}",
            'reply_to_field' => 'email',
            'confirmation'   => array( 'enabled' => false, 'subject' => '', 'body_template' => '' ),
        ),
        $notification
    );

    return $definition;
}

/**
 * Create and publish a real form, and return its published definition.
 *
 * The retry worker reads the **published** definition of the form a record
 * belongs to, so a fixture built from a document that was never stored would
 * prove nothing: the worker would find no definition and skip the record.
 *
 * @param string $name         Name.
 * @param array  $notification Notification overrides, applied before publication.
 * @return array
 */
function asn_real_form( $name = 'Notifications', array $notification = array() ) {
    global $token, $mine;

    $definition = Form_Schema::defaults(
        Form_Definition::new_form_id(),
        'asn-' . strtolower( substr( md5( $name . $token . random_int( 1, 999999 ) ), 0, 8 ) ),
        $name
    );

    $definition['fields'] = asn_fields();
    $definition['settings']['notification'] = array_merge(
        $definition['settings']['notification'],
        array(
            'enabled'        => true,
            'recipients'     => array( 'site-admin' ),
            'subject'        => 'New submission: {form:name}',
            'body_template'  => "From {field:name}\n\n{fields}",
            'reply_to_field' => 'email',
            'confirmation'   => array( 'enabled' => false, 'subject' => '', 'body_template' => '' ),
        ),
        $notification
    );

    $created = Form_Definition::create(
        array(
            'name'       => $name . ' ' . $token,
            'slug'       => $definition['slug'],
            'definition' => $definition,
        ),
        1
    );

    if ( is_wp_error( $created ) ) {
        return array();
    }

    $mine['posts'][] = (int) $created['post_id'];

    $published = Form_Definition::publish( (string) $created['form_id'], (int) $created['revision'], 1 );

    if ( is_wp_error( $published ) ) {
        return array();
    }

    $state = Form_Definition::get_by_post_id( (int) $created['post_id'] );

    return array(
        'form_id'    => (string) $created['form_id'],
        'post_id'    => (int) $created['post_id'],
        'version'    => (int) $state['version'],
        'definition' => is_array( $state['published'] ) ? $state['published'] : array(),
    );
}

function asn_values() {
    return array( 'name' => 'Ada Lovelace', 'email' => 'test@example.invalid' );
}

/**
 * Create a submission with a notification record, and return its id.
 *
 * @param array      $definition Definition.
 * @param array|null $notification Admin record, or null for none.
 * @param array|null $confirmation Confirmation record, or null for none.
 * @return int
 */
function asn_entry( array $definition, $notification = null, $confirmation = null ) {
    global $mine;

    if ( true === $notification ) {
        $notification = Form_Notifier::initial_record( 'admin' );
    }

    if ( true === $confirmation ) {
        $confirmation = Form_Notifier::initial_record( 'confirmation' );
    }

    $created = Submission_Store::create(
        array(
            'form_id'      => (string) $definition['form_id'],
            'form_name'    => (string) $definition['name'],
            'form_version' => (int) $definition['version'],
            'fields'       => asn_values(),
            'spam_status'  => 'clean',
            'meta'         => array( 'received_at' => gmdate( 'c' ), 'source' => 'test' ),
            'challenge'    => '',
            'notification' => is_array( $notification ) ? $notification : null,
            'confirmation' => is_array( $confirmation ) ? $confirmation : null,
        )
    );

    if ( is_wp_error( $created ) ) {
        return 0;
    }

    $mine['posts'][] = (int) $created['post_id'];

    return (int) $created['post_id'];
}

/**
 * Read a notification record.
 *
 * @param int    $id   Entry id.
 * @param string $kind Kind.
 * @return array
 */
function asn_record( $id, $kind = 'admin' ) {
    $state = Submission_Store::notification_state( $id, $kind );

    return is_array( $state['record'] ) ? $state['record'] : array();
}

/**
 * Replace a notification record outright, for a fixture state.
 *
 * @param int    $id     Entry id.
 * @param string $kind   Kind.
 * @param array  $record Record.
 * @return void
 */
function asn_set( $id, $kind, array $record ) {
    Submission_Store::write_notification( $id, $kind, $record );
}

function asn_count_entries( $form_id ) {
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

    return count( (array) $posts );
}

function asn_audit_has( $event ) {
    foreach ( Form_Audit::entries() as $entry ) {
        if ( isset( $entry['event'] ) && (string) $entry['event'] === (string) $event ) {
            return true;
        }
    }

    return false;
}

// ══ accepted, and only once ══════════════════════════════════════════════════

$definition = asn_definition();
$accepted_id = asn_entry( $definition, true );

asn_reset( 'ok' );
$first       = Form_Notifier::attempt( $accepted_id, 'admin', $definition, asn_values() );
$record      = asn_record( $accepted_id );
$first_mails = asn_mails();

asn_reset( 'ok' );
$again       = Form_Notifier::attempt( $accepted_id, 'admin', $definition, asn_values() );
$again_mails = asn_mails();

probe(
    'accepted',
    array(
        'outcome'       => $first['outcome'] ?? '',
        'status'        => $record['status'] ?? '',
        'attempts'      => (int) ( $record['attempts'] ?? 0 ),
        'accepted_at'   => (string) ( $record['accepted_at'] ?? '' ),
        'message_id'    => (string) ( $record['message_id'] ?? '' ),
        'mails'         => count( $first_mails ),
        'again_outcome' => $again['outcome'] ?? '',
        'again_mails'   => count( $again_mails ),
        'entries'       => asn_count_entries( $definition['form_id'] ),
    )
);

// ══ failure, backoff, and the not-yet-due answer ════════════════════════════

$failing_id = asn_entry( $definition, true );

asn_reset( 'fail' );
$failed        = Form_Notifier::attempt( $failing_id, 'admin', $definition, asn_values() );
$failed_record = asn_record( $failing_id );
$failed_mails  = asn_mails();

asn_reset( 'ok' );
$not_due = Form_Notifier::attempt( $failing_id, 'admin', $definition, asn_values() );
$not_due_mails = asn_mails();

$due_at = strtotime( (string) ( $failed_record['next_attempt_at'] ?? '' ) );
$wait   = $due_at > 0 ? $due_at - time() : -1;

probe(
    'failed',
    array(
        'outcome'    => $failed['outcome'] ?? '',
        'status'     => $failed_record['status'] ?? '',
        'attempts'   => (int) ( $failed_record['attempts'] ?? 0 ),
        'has_error'  => '' !== (string) ( $failed_record['last_error'] ?? '' ),
        'wait'       => $wait,
        'mails'      => count( $failed_mails ),
        'not_due_outcome' => $not_due['outcome'] ?? '',
        'not_due_status'  => $not_due['status'] ?? '',
        'not_due_mails'   => count( $not_due_mails ),
        'entries'    => asn_count_entries( $definition['form_id'] ),
    )
);

// ══ a due retry sends, and the delay grows ══════════════════════════════════

$failed_record['next_attempt_at'] = gmdate( 'c', time() - 5 );
asn_set( $failing_id, 'admin', $failed_record );

asn_reset( 'fail' );
$second        = Form_Notifier::attempt( $failing_id, 'admin', $definition, asn_values() );
$second_record = asn_record( $failing_id );
$second_due    = strtotime( (string) ( $second_record['next_attempt_at'] ?? '' ) );
$second_mails  = count( asn_mails() );

probe(
    'backoff',
    array(
        'outcome'  => $second['outcome'] ?? '',
        'attempts' => (int) ( $second_record['attempts'] ?? 0 ),
        'wait'     => $second_due > 0 ? $second_due - time() : -1,
        'mails'    => $second_mails,
    )
);

// ══ the attempt limit, and the audited give-up ═══════════════════════════════

$exhaust_id     = asn_entry( $definition, true );
$exhaust_record = asn_record( $exhaust_id );
$exhaust_record['attempts']        = Form_Notifier::MAX_ATTEMPTS - 1;
$exhaust_record['next_attempt_at'] = gmdate( 'c', time() - 5 );
asn_set( $exhaust_id, 'admin', $exhaust_record );

asn_reset( 'fail' );
$exhausted     = Form_Notifier::attempt( $exhaust_id, 'admin', $definition, asn_values() );
$exhaust_after = asn_record( $exhaust_id );
$exhaust_mails = asn_mails();

asn_reset( 'ok' );
$after_exhaust       = Form_Notifier::attempt( $exhaust_id, 'admin', $definition, asn_values() );
$after_exhaust_mails = asn_mails();

probe(
    'exhausted',
    array(
        'outcome'  => $exhausted['outcome'] ?? '',
        'status'   => $exhaust_after['status'] ?? '',
        'attempts' => (int) ( $exhaust_after['attempts'] ?? 0 ),
        'next'     => array_key_exists( 'next_attempt_at', $exhaust_after ) ? $exhaust_after['next_attempt_at'] : 'missing',
        'mails'    => count( $exhaust_mails ),
        'after_outcome' => $after_exhaust['outcome'] ?? '',
        'after_mails'   => count( $after_exhaust_mails ),
        'audited'  => asn_audit_has( 'form_notification_exhausted' ),
    )
);

// ══ a live claim is respected; an abandoned one is taken over ═══════════════

$busy_id     = asn_entry( $definition, true );
$busy_record = asn_record( $busy_id );
$busy_record['status']     = 'claimed';
$busy_record['claimed_at'] = gmdate( 'c' );
$busy_record['attempts']   = 1;
asn_set( $busy_id, 'admin', $busy_record );

asn_reset( 'ok' );
$busy       = Form_Notifier::attempt( $busy_id, 'admin', $definition, asn_values() );
$busy_mails = asn_mails();

$stale_id     = asn_entry( $definition, true );
$stale_record = asn_record( $stale_id );
$stale_record['status']     = 'claimed';
$stale_record['claimed_at'] = gmdate( 'c', time() - ( Form_Notifier::claim_ttl() + 60 ) );
$stale_record['attempts']   = 1;
asn_set( $stale_id, 'admin', $stale_record );

asn_reset( 'ok' );
$taken_over     = Form_Notifier::attempt( $stale_id, 'admin', $definition, asn_values() );
$taken_record   = asn_record( $stale_id );
$taken_mails    = asn_mails();

probe(
    'claim',
    array(
        'busy_outcome'  => $busy['outcome'] ?? '',
        'busy_status'   => $busy['status'] ?? '',
        'busy_mails'    => count( $busy_mails ),
        'stale_outcome' => $taken_over['outcome'] ?? '',
        'stale_status'  => $taken_record['status'] ?? '',
        'stale_attempts' => (int) ( $taken_record['attempts'] ?? 0 ),
        'stale_mails'   => count( $taken_mails ),
    )
);

// ══ reconcile: two workers' claims do not become two sends ══════════════════

asn_reset( 'ok' );

$reconcile_id     = asn_entry( $definition, true );
$reconcile_record = asn_record( $reconcile_id );
$reconcile_record['status']     = 'claimed';
$reconcile_record['claimed_at'] = gmdate( 'c', time() - ( Form_Notifier::claim_ttl() + 60 ) );
$reconcile_record['attempts']   = 1;
asn_set( $reconcile_id, 'admin', $reconcile_record );

$first_reconcile   = Form_Notifier::reconcile();
$reconciled_record = asn_record( $reconcile_id );
$second_reconcile  = Form_Notifier::reconcile();
$reconcile_mails   = asn_mails();

probe(
    'reconcile',
    array(
        'first'    => (int) $first_reconcile,
        'second'   => (int) $second_reconcile,
        'status'   => $reconciled_record['status'] ?? '',
        'error'    => (string) ( $reconciled_record['last_error'] ?? '' ),
        'claimed_at' => array_key_exists( 'claimed_at', $reconciled_record ) ? $reconciled_record['claimed_at'] : 'missing',
        'mails'    => count( $reconcile_mails ),
    )
);

// ══ an attempt that runs past its budget is uncertain, not accepted ═════════

$slow_id = asn_entry( $definition, true );

add_filter(
    'agentshell_forms_notification_attempt_budget',
    function () {
        return 1;
    }
);

asn_reset( 'slow' );
$slow         = Form_Notifier::attempt( $slow_id, 'admin', $definition, asn_values() );
$slow_record  = asn_record( $slow_id );
$slow_mails   = count( asn_mails() );

probe(
    'budget',
    array(
        'outcome'  => $slow['outcome'] ?? '',
        'status'   => $slow_record['status'] ?? '',
        'error'    => (string) ( $slow_record['last_error'] ?? '' ),
        'mails'    => $slow_mails,
        'has_next' => null !== ( $slow_record['next_attempt_at'] ?? null ),
        'entries'  => asn_count_entries( $definition['form_id'] ),
    )
);

// ══ a throwing transport is a failed attempt, not a broken submission ═══════

$throw_id = asn_entry( $definition, true );

asn_reset( 'throw' );
$thrown       = Form_Notifier::attempt( $throw_id, 'admin', $definition, asn_values() );
$throw_record = asn_record( $throw_id );
$throw_mails  = asn_mails();

probe(
    'throw',
    array(
        'outcome' => $thrown['outcome'] ?? '',
        'status'  => $throw_record['status'] ?? '',
        'error'   => (string) ( $throw_record['last_error'] ?? '' ),
        'mails'   => count( $throw_mails ),
        'entries' => asn_count_entries( $definition['form_id'] ),
    )
);

// ══ notifications switched off after the submission is not five retries ═════

$off_id     = asn_entry( $definition, true );
$off_def    = $definition;
$off_def['settings']['notification']['enabled'] = false;

asn_reset( 'ok' );
$off        = Form_Notifier::attempt( $off_id, 'admin', $off_def, asn_values() );
$off_record = asn_record( $off_id );
$off_mails  = count( asn_mails() );

probe(
    'disabled_later',
    array(
        'outcome' => $off['outcome'] ?? '',
        'status'  => $off_record['status'] ?? '',
        'error'   => (string) ( $off_record['last_error'] ?? '' ),
        'mails'   => $off_mails,
    )
);

// ══ the confirmation is a separate message with its own record ══════════════

$confirm_def = asn_definition(
    array(
        'confirmation' => array(
            'enabled'       => true,
            'subject'       => 'We received your message',
            'body_template' => 'Thanks {field:name}.',
        ),
    )
);

$confirm_id = asn_entry( $confirm_def, true, true );

asn_reset( 'ok' );
$dispatch = Form_Notifier::dispatch( $confirm_id, $confirm_def, asn_values() );
$admin_record = asn_record( $confirm_id, 'admin' );
$conf_record  = asn_record( $confirm_id, 'confirmation' );
$mails        = asn_mails();

asn_reset( 'ok' );
$second_dispatch = Form_Notifier::dispatch( $confirm_id, $confirm_def, asn_values() );
$second_mails    = asn_mails();

$subjects = array();
$tos      = array();

foreach ( $mails as $mail ) {
    $subjects[] = (string) $mail['subject'];
    $tos[]      = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
}

probe(
    'confirmation',
    array(
        'admin_outcome'  => $dispatch['admin']['outcome'] ?? '',
        'conf_outcome'   => $dispatch['confirmation']['outcome'] ?? '',
        'admin_status'   => $admin_record['status'] ?? '',
        'conf_status'    => $conf_record['status'] ?? '',
        'mails'          => count( $mails ),
        'to'             => implode( '|', $tos ),
        'subjects'       => $subjects,
        'admin_email'    => (string) get_option( 'admin_email' ),
        'second_mails'   => count( $second_mails ),
        'entries'        => asn_count_entries( $confirm_def['form_id'] ),
    )
);

// A confirmation requested by a form with no email field is skipped, audited,
// and never silently reported as accepted.
$no_email_fields = array( asn_fields()[0] );
$skipped_def     = asn_definition( array( 'confirmation' => array( 'enabled' => true, 'subject' => 'x', 'body_template' => 'y' ) ), $no_email_fields );
$skipped_id      = asn_entry( $skipped_def, true, false );

asn_reset( 'ok' );
$skipped       = Form_Notifier::dispatch( $skipped_id, $skipped_def, array( 'name' => 'Ada' ) );
$skipped_mails = asn_mails();

probe(
    'confirmation_skipped',
    array(
        'outcome' => $skipped['confirmation']['outcome'] ?? '',
        'reason'  => $skipped['confirmation']['reason'] ?? '',
        'record'  => Submission_Store::notification_state( $skipped_id, 'confirmation' )['record'],
        'mails'   => count( $skipped_mails ),
        'audited' => asn_audit_has( 'form_notification_skipped' ),
    )
);

// ══ run_due: the retry worker, against a real published form ════════════════

// The worker reads the published definition of the form the record belongs to,
// so this fixture must be a form that really exists and really published.
$real = asn_real_form(
    'Worker',
    array(
        'confirmation' => array(
            'enabled'       => true,
            'subject'       => 'We received your message',
            'body_template' => 'Thanks {field:name}.',
        ),
    )
);

$real_def = isset( $real['definition'] ) ? $real['definition'] : array();

$due_id     = asn_entry( $real_def, true, true );
$due_record = asn_record( $due_id );
$due_record['next_attempt_at'] = gmdate( 'c', time() - 5 );
asn_set( $due_id, 'admin', $due_record );

$due_conf = asn_record( $due_id, 'confirmation' );
$due_conf['next_attempt_at'] = gmdate( 'c', time() - 5 );
asn_set( $due_id, 'confirmation', $due_conf );

asn_reset( 'ok' );
$run_admin  = Form_Notifier::run_due( 'admin' );
$run_conf   = Form_Notifier::run_due( 'confirmation' );
$run_mails  = asn_mails();

probe(
    'run_due',
    array(
        'admin_attempted' => (int) $run_admin['attempted'],
        'admin_outcomes'  => $run_admin['outcomes'],
        'conf_attempted'  => (int) $run_conf['attempted'],
        'admin_status'    => asn_record( $due_id, 'admin' )['status'] ?? '',
        'conf_status'     => asn_record( $due_id, 'confirmation' )['status'] ?? '',
        'mails'           => count( $run_mails ),
        'subjects'        => array_map(
            function ( $mail ) {
                return (string) $mail['subject'];
            },
            $run_mails
        ),
        'entries'         => asn_count_entries( $real['form_id'] ),
    )
);

// ══ a form republished under a different recipient list ═══════════════════
//
// A submission is notified under the settings it was accepted with. A form
// that is republished with a different recipient list in between must not
// redirect an old message: the person who was promised the message is the one
// who receives it.

$host_r = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$to_a   = 'desk-a@' . $host_r;
$to_b   = 'desk-b@' . $host_r;

$form_recipients = asn_real_form( 'Recipients', array( 'recipients' => array( $to_a ) ) );
$definition_a    = isset( $form_recipients['definition'] ) ? $form_recipients['definition'] : array();

$entry_recipients = 0;

if ( ! empty( $definition_a ) ) {
    $created_recipients = Submission_Store::create(
        array(
            'form_id'      => (string) $form_recipients['form_id'],
            'form_name'    => 'Recipients fixture',
            'form_version' => (int) $form_recipients['version'],
            'fields'       => asn_values(),
            'spam_status'  => 'clean',
            'meta'         => array( 'received_at' => gmdate( 'c' ) ),
            'challenge'    => '',
            'notification' => Form_Notifier::initial_record( 'admin' ),
            'confirmation' => null,
            // What it was accepted under.
            'notification_snapshot' => Form_Notifier::snapshot( $definition_a ),
        )
    );

    if ( ! is_wp_error( $created_recipients ) ) {
        $entry_recipients = (int) $created_recipients['post_id'];
    }

    // Republish the form with the other recipient.
    $definition_b              = $definition_a;
    $definition_b['settings']['notification']['recipients'] = array( $to_b );

    $state_before = Form_Definition::get( (string) $form_recipients['form_id'] );
    $updated      = Form_Definition::update_working(
        (string) $form_recipients['form_id'],
        $definition_b,
        is_array( $state_before ) ? (int) $state_before['revision'] : 0,
        1
    );

    if ( ! is_wp_error( $updated ) ) {
        Form_Definition::publish(
            (string) $form_recipients['form_id'],
            (int) ( $updated['revision'] ?? 0 ),
            1
        );
    }
}

asn_reset( 'ok' );
$run_recipients = Form_Notifier::run_due( 'admin' );
$mails_recipients = asn_mails();

$sent_to = array();

foreach ( $mails_recipients as $mail ) {
    $sent_to[] = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
}

probe(
    'republished',
    array(
        'entry'       => $entry_recipients,
        'to_a'        => $to_a,
        'to_b'        => $to_b,
        'recipients'  => $sent_to,
        'sent_to_a'   => in_array( $to_a, $sent_to, true ),
        'sent_to_b'   => in_array( $to_b, $sent_to, true ),
    )
);

// ══ the retry worker must not be starved by settled records ═══════════════
//
// The scan is bounded, so on an installation with a long history the window
// fills with records that were settled long ago. If the window always started
// at the oldest record, a notification that became due behind them would
// never be examined at all.

global $wpdb;

$settled_count = 2001;
$settled_ids   = array();
$when          = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
$settled_body  = wp_json_encode(
    array(
        'kind'            => 'admin',
        'status'          => 'accepted',
        'attempts'        => 1,
        'next_attempt_at' => null,
        'claimed_at'      => null,
        'claimed_by'      => null,
        'last_error'      => null,
        'accepted_at'     => gmdate( 'c' ),
        'message_id'      => '<settled@example.test>',
    )
);

for ( $i = 0; $i < $settled_count; $i++ ) {
    $wpdb->insert(
        $wpdb->posts,
        array(
            'post_type'         => Form_Post_Types::ENTRY,
            'post_status'       => 'publish',
            'post_title'        => 'Settled notification ' . $i,
            'post_content'      => '',
            'post_author'       => 0,
            'post_name'         => '',
            'post_date'         => $when,
            'post_date_gmt'     => $when,
            'post_modified'     => $when,
            'post_modified_gmt' => $when,
            'post_parent'       => 0,
            'guid'              => '',
            'comment_status'    => 'closed',
            'ping_status'       => 'closed',
        )
    );

    $settled_id = (int) $wpdb->insert_id;

    if ( $settled_id > 0 ) {
        $wpdb->insert(
            $wpdb->postmeta,
            array(
                'post_id'    => $settled_id,
                'meta_key'   => Submission_Store::META_NOTIFICATION,
                'meta_value' => $settled_body,
            )
        );

        $settled_ids[] = $settled_id;
    }
}

// The pending notification, behind all of them.
$real_form = asn_real_form( 'Starvation' );
$pending_id = 0;

if ( ! empty( $real_form['form_id'] ) ) {
    $created_pending = Submission_Store::create(
        array(
            'form_id'      => (string) $real_form['form_id'],
            'form_name'    => 'Starvation fixture',
            'form_version' => (int) $real_form['version'],
            'fields'       => asn_values(),
            'spam_status'  => 'clean',
            'meta'         => array( 'received_at' => gmdate( 'c' ) ),
            'challenge'    => '',
            'notification' => Form_Notifier::initial_record( 'admin' ),
            'confirmation' => null,
        )
    );

    if ( ! is_wp_error( $created_pending ) ) {
        $pending_id = (int) $created_pending['post_id'];
    }
}

// The scan starts from the beginning, so the fixture is deterministic.
delete_option( 'agentshell_forms_notification_cursor' );



$starvation_runs   = array();
$starvation_status = array();

delete_option( 'agentshell_forms_notification_cursor' );

$starvation_runs   = array();
$starvation_status = array();

for ( $run = 1; $run <= 3; $run++ ) {
    $result = Form_Notifier::run_due( 'admin' );

    $starvation_runs[] = (int) $result['attempted'];

    $state = $pending_id > 0 ? Submission_Store::notification_state( $pending_id, 'admin' ) : array( 'record' => null );
    $record = is_array( $state['record'] ) ? $state['record'] : array();

    $starvation_status[] = (string) ( $record['status'] ?? '' );

    if ( 'accepted' === (string) ( $record['status'] ?? '' ) ) {
        break;
    }
}

file_put_contents( '/tmp/opencode/starve.txt', 'after all runs: cursor=' . var_export( get_option( 'agentshell_forms_notification_cursor' ), true ) . ' pending_status=' . ( $pending_id > 0 ? Submission_Store::notification_state( $pending_id, 'admin' )['record']['status'] : 'none' ) . "\n", FILE_APPEND );

delete_option( 'agentshell_forms_notification_cursor' );

probe(
    'starvation',
    array(
        'settled'    => count( $settled_ids ),
        'pending_id' => $pending_id,
        'runs'       => $starvation_runs,
        'statuses'   => $starvation_status,
        'processed'  => in_array( 'accepted', $starvation_status, true ),
    )
);

// The settled records are fixtures, not submissions: they are removed here so
// the suite leaves nothing behind.
foreach ( $settled_ids as $settled_id ) {
    wp_delete_post( (int) $settled_id, true );
}

// ══ cleanup ═════════════════════════════════════════════════════════════════

foreach ( $mine['posts'] as $post_id ) {
    wp_delete_post( (int) $post_id, true );
}

if ( $pending_id > 0 ) {
    wp_delete_post( (int) $pending_id, true );
}

if ( $entry_recipients > 0 ) {
    wp_delete_post( (int) $entry_recipients, true );
}

probe(
    'cleanup',
    array(
        'remaining' => count(
            get_posts(
                array(
                    'post_type'      => Form_Post_Types::ENTRY,
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                        array(
                            'key'     => Submission_Store::META_FORM_ID,
                            'value'   => array( $definition['form_id'], $confirm_def['form_id'], $skipped_def['form_id'], $real['form_id'] ),
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
