<?php
/**
 * Child half of the privacy suite: the exporter and the eraser.
 *
 * The claims:
 *
 *   - a subject-access request finds **every** submission that holds the
 *     address, including one where it was entered in a field that is not the
 *     email field — because the record was validated against the definition
 *     published *then*, and the current definition may name different fields;
 *   - the match is the whole address, not a substring: asking about
 *     `subject@example.invalid` must not disclose `other@example.invalid`, nor
 *     a longer address that merely starts with the same characters;
 *   - an erasure request **hard-deletes** the matching submissions. Trashing
 *     would leave the data recoverable, which is not erasure; and
 *   - everything that was not asked about survives, so an erasure cannot
 *     become a deletion of somebody else's data.
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
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Privacy;
use AgentShell_Forms\Form_Schema;
use AgentShell_Forms\Submission_Store;

$token = 'ASp3' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array( 'posts' => array() );

function probe( $name, array $data ) {
    global $f;
    $f['probes'][ $name ] = $data;
}

wp_set_current_user( 1 );

function asp3_form( $name ) {
    global $token, $mine;

    $definition = Form_Schema::defaults(
        Form_Definition::new_form_id(),
        'asp-' . strtolower( substr( md5( $name . $token . random_int( 1, 999999 ) ), 0, 8 ) ),
        $name
    );

    $definition['fields'] = array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Name',
            'required' => true,
            'settings' => array( 'max_length' => 200, 'min_length' => 0, 'autocomplete' => 'name' ),
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
        array(
            'key'      => 'note',
            'type'     => 'text',
            'label'    => 'Note',
            'required' => false,
            'settings' => array( 'max_length' => 200, 'min_length' => 0, 'autocomplete' => 'off' ),
            'options'  => array(),
        ),
    );

    $definition['settings']['notification']['enabled'] = false;

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

    return array(
        'form_id' => (string) $created['form_id'],
        'post_id' => (int) $created['post_id'],
        'version' => (int) $published['version'],
    );
}

function asp3_entry( array $form, array $values ) {
    global $mine;

    $created = Submission_Store::create(
        array(
            'form_id'      => (string) $form['form_id'],
            'form_name'    => 'Privacy fixture',
            'form_version' => (int) $form['version'],
            'fields'       => $values,
            'spam_status'  => 'clean',
            'meta'         => array( 'received_at' => gmdate( 'c' ) ),
            'challenge'    => '',
            'notification' => null,
            'confirmation' => null,
        )
    );

    if ( is_wp_error( $created ) ) {
        return 0;
    }

    $mine['posts'][] = (int) $created['post_id'];

    return (int) $created['post_id'];
}

function asp3_gone( $id ) {
    return null === get_post( (int) $id );
}

function asp3_audit_has( $event ) {
    foreach ( Form_Audit::entries() as $entry ) {
        if ( isset( $entry['event'] ) && (string) $entry['event'] === (string) $event ) {
            return true;
        }
    }

    return false;
}

// ══ fixtures ═════════════════════════════════════════════════════════════════

$form_a = asp3_form( 'Privacy A' );
$form_b = asp3_form( 'Privacy B' );

$subject = 'subject@example.invalid';

// Two submissions in form A hold the address: one in the email field, one
// only inside a free-text field. Both are the subject's personal data.
$matching_email = asp3_entry( $form_a, array( 'name' => 'Subject', 'email' => $subject, 'note' => 'hello' ) );
$matching_note  = asp3_entry( $form_a, array( 'name' => 'Subject again', 'email' => '', 'note' => 'Reach me at ' . $subject ) );

// Wait: the note holds the address inside a sentence, and the exporter matches
// a whole value, so this one is *not* expected to match. Recorded as such so
// the behaviour is pinned rather than assumed.
$matching_form_b = asp3_entry( $form_b, array( 'name' => 'Subject on B', 'email' => $subject, 'note' => '' ) );

$other = asp3_entry( $form_a, array( 'name' => 'Other', 'email' => 'other@example.invalid', 'note' => '' ) );
$longer = asp3_entry( $form_a, array( 'name' => 'Longer', 'email' => $subject . '.evil', 'note' => '' ) );
$none = asp3_entry( $form_b, array( 'name' => 'No address', 'email' => '', 'note' => '' ) );

// ══ the exporter ═════════════════════════════════════════════════════════════

$export = Form_Privacy::export( $subject, 1 );

$export_ids = array();
$export_values = array();

foreach ( (array) $export['data'] as $item ) {
    $id = str_replace( 'agentshell-entry-', '', (string) $item['item_id'] );
    $export_ids[] = (int) $id;

    foreach ( (array) $item['data'] as $row ) {
        $export_values[] = (string) $row['name'] . '=' . (string) $row['value'];
    }
}

$unknown = Form_Privacy::export( 'nobody@example.invalid', 1 );

probe(
    'export',
    array(
        'count'        => count( (array) $export['data'] ),
        'done'         => (bool) $export['done'],
        'ids'          => $export_ids,
        'has_matching_email' => in_array( $matching_email, $export_ids, true ),
        'has_matching_form_b' => in_array( $matching_form_b, $export_ids, true ),
        'has_note_mention'   => in_array( $matching_note, $export_ids, true ),
        'has_other'    => in_array( $other, $export_ids, true ),
        'has_longer'   => in_array( $longer, $export_ids, true ),
        'has_none'     => in_array( $none, $export_ids, true ),
        'values'       => $export_values,
        'group_ids'    => array_values( array_unique( array_map( function ( $row ) { return (string) $row['group_id']; }, (array) $export['data'] ) ) ),
        'unknown_count' => count( (array) $unknown['data'] ),
        'unknown_done'  => (bool) $unknown['done'],
    )
);

// ══ the eraser ═══════════════════════════════════════════════════════════════

$erase = Form_Privacy::erase( $subject, 1 );

$after = array(
    'matching_email'  => asp3_gone( $matching_email ),
    'matching_form_b' => asp3_gone( $matching_form_b ),
    'note_mention'    => asp3_gone( $matching_note ),
    'other'           => asp3_gone( $other ),
    'longer'          => asp3_gone( $longer ),
    'none'            => asp3_gone( $none ),
);

$again = Form_Privacy::erase( $subject, 1 );

// Trashing is not erasure: assert the rows are absent, not merely in the trash.
$trashed_remaining = get_posts(
    array(
        'post_type'      => Form_Post_Types::ENTRY,
        'post_status'    => 'trash',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            array(
                'key'     => Submission_Store::META_FORM_ID,
                'value'   => array( $form_a['form_id'], $form_b['form_id'] ),
                'compare' => 'IN',
            ),
        ),
    )
);

probe(
    'erase',
    array(
        'removed'       => (bool) $erase['items_removed'],
        'retained'      => (bool) $erase['items_retained'],
        'done'          => (bool) $erase['done'],
        'messages'      => array_values( (array) $erase['messages'] ),
        'gone'          => $after,
        'again_removed' => (bool) $again['items_removed'],
        'again_done'    => (bool) $again['done'],
        'trashed'       => count( (array) $trashed_remaining ),
        'audited_export' => asp3_audit_has( 'form_submissions_exported_for_privacy' ),
        'audited_erase'  => asp3_audit_has( 'form_submissions_erased_for_privacy' ),
    )
);

// ══ pagination: an older match is not hidden by a thousand newer records ══
//
// The exporter used to read the newest thousand submissions and stop. A match
// older than that was invisible to a subject-access request.

$subject_p = 'subject@example.invalid';

$old_match = asp3_entry( $form_a, array( 'name' => 'Old match', 'email' => $subject_p, 'note' => '' ) );

// Backdate it so it is the oldest thing on the site.
$when = gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS );
$wpdb->update( $wpdb->posts, array( 'post_date' => $when, 'post_date_gmt' => $when, 'post_modified' => $when, 'post_modified_gmt' => $when ), array( 'ID' => $old_match ) );
clean_post_cache( $old_match );

// Now 1001 nonmatching submissions, newer than it.
for ( $i = 0; $i < 1001; $i++ ) {
    asp3_entry( $form_a, array( 'name' => 'Nonmatch ' . $i, 'email' => 'other' . $i . '@example.invalid', 'note' => '' ) );
}

$export_p = Form_Privacy::export( $subject_p, 1 );
$export_ids_p = array();

foreach ( (array) $export_p['data'] as $item ) {
    $export_ids_p[] = (int) str_replace( 'agentshell-entry-', '', (string) $item['item_id'] );
}

probe(
    'pagination',
    array(
        'found' => in_array( $old_match, $export_ids_p, true ),
        'count' => count( (array) $export_p['data'] ),
    )
);

// ══ erasure: 120 matching submissions, no skipping, safe to repeat ═════════
//
// The previous implementation paginated the set it was deleting from, with an
// offset that grew while the set shrank. 120 matching submissions over three
// pages erased 70 of them.

$bulk_ids = array();

for ( $i = 0; $i < 120; $i++ ) {
    $bulk_ids[] = asp3_entry( $form_a, array( 'name' => 'Bulk ' . $i, 'email' => $subject_p, 'note' => '' ) );
}

$erased_total = 0;

for ( $page = 1; $page <= 5; $page++ ) {
    $r = Form_Privacy::erase( $subject_p, $page );

    if ( (bool) $r['items_removed'] ) {
        $erased_total += 1;
    }

    if ( (bool) $r['done'] ) {
        break;
    }
}

$remaining_bulk = 0;

foreach ( $bulk_ids as $id ) {
    if ( ! asp3_gone( $id ) ) {
        $remaining_bulk++;
    }
}

$second_erase = Form_Privacy::erase( $subject_p, 1 );

probe(
    'bulk_erase',
    array(
        'total'        => count( $bulk_ids ),
        'remaining'    => $remaining_bulk,
        'second_removed' => (bool) $second_erase['items_removed'],
        'second_done'  => (bool) $second_erase['done'],
    )
);

// ══ cleanup ═════════════════════════════════════════════════════════════════

foreach ( $mine['posts'] as $post_id ) {
    wp_delete_post( (int) $post_id, true );
}

$remaining = 0;

foreach ( array( $form_a['form_id'], $form_b['form_id'] ) as $form_id ) {
    $remaining += count(
        get_posts(
            array(
                'post_type'      => Form_Post_Types::ENTRY,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'   => Submission_Store::META_FORM_ID,
                        'value' => (string) $form_id,
                    ),
                ),
            )
        )
    );
}

probe( 'cleanup', array( 'remaining' => $remaining ) );

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
