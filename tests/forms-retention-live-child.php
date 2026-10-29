<?php
/**
 * Child half of the retention and export suite.
 *
 * Two claims:
 *
 *   1. **Retention is a hard delete, per form, and it is bounded by the form's
 *      own period.** An entry past its period is *deleted* — not trashed, which
 *      would keep the personal data recoverable and make the period a
 *      decoration. An entry inside its period survives. A form that declares
 *      no period inherits the site default. A trashed entry is purged too: the
 *      trash is not a place retention stops applying.
 *
 *   2. **An export cannot attack the operator.** A visitor controls field
 *      values, and a spreadsheet executes a cell beginning `=`, `+`, `-` or
 *      `@`. Every such cell is prefixed so it is text, and RFC 4180 quoting
 *      keeps commas, quotes and newlines inside their own cell. JSON is data,
 *      not a program, and is not prefixed.
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
use AgentShell_Forms\Form_Audit;
use AgentShell_Forms\Form_Capabilities;
use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Schema;
use AgentShell_Forms\Submission_Store;

global $wpdb;

$token = 'ASr3' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array( 'posts' => array() );

function probe( $name, array $data ) {
    global $f;
    $f['probes'][ $name ] = $data;
}

wp_set_current_user( 1 );

/**
 * Create and publish a form.
 *
 * @param string $name       Name.
 * @param array  $storage    `storage` settings.
 * @param array  $fields     Fields, or null for a two-field form.
 * @return array
 */
function asr3_form( $name, array $storage = array(), $fields = null ) {
    global $token, $mine;

    $definition = Form_Schema::defaults(
        Form_Definition::new_form_id(),
        'asr-' . strtolower( substr( md5( $name . $token . random_int( 1, 999999 ) ), 0, 8 ) ),
        $name
    );

    $definition['fields'] = null === $fields ? array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Name',
            'required' => true,
            'settings' => array( 'max_length' => 200, 'min_length' => 0, 'autocomplete' => 'name' ),
            'options'  => array(),
        ),
        array(
            'key'      => 'message',
            'type'     => 'textarea',
            'label'    => 'Message',
            'required' => false,
            'settings' => array( 'max_length' => 2000, 'min_length' => 0, 'rows' => 4 ),
            'options'  => array(),
        ),
    ) : $fields;

    $definition['settings']['notification']['enabled'] = false;
    $definition['settings']['storage'] = array_merge( $definition['settings']['storage'], $storage );

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

/**
 * Create one submission and backdate it.
 *
 * @param array $form    Form.
 * @param array $values  Values.
 * @param int   $days_ago Age in days.
 * @return int
 */
function asr3_entry( array $form, array $values, $days_ago = 0 ) {
    global $wpdb, $mine;

    $created = Submission_Store::create(
        array(
            'form_id'      => (string) $form['form_id'],
            'form_name'    => 'Retention fixture',
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

    $id = (int) $created['post_id'];
    $mine['posts'][] = $id;

    if ( $days_ago > 0 ) {
        $when = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days_ago * DAY_IN_SECONDS ) );

        $wpdb->update(
            $wpdb->posts,
            array( 'post_date' => $when, 'post_date_gmt' => $when, 'post_modified' => $when, 'post_modified_gmt' => $when ),
            array( 'ID' => $id )
        );

        clean_post_cache( $id );
    }

    return $id;
}

/**
 * Does an entry still exist?
 *
 * @param int $id Entry id.
 * @return bool
 */
function asr3_alive( $id ) {
    $post = get_post( (int) $id );

    return $post instanceof WP_Post && Form_Post_Types::ENTRY === $post->post_type;
}

function asr3_audit_count( $event ) {
    $count = 0;

    foreach ( Form_Audit::entries() as $entry ) {
        if ( isset( $entry['event'] ) && (string) $entry['event'] === (string) $event ) {
            $count++;
        }
    }

    return $count;
}

// ══ retention ════════════════════════════════════════════════════════════════

$site_default_before = (int) get_option( Submission_Store::OPTION_RETENTION_DAYS, Submission_Store::DEFAULT_RETENTION_DAYS );

update_option( Submission_Store::OPTION_RETENTION_DAYS, 30, false );

$strict = asr3_form( 'Strict retention', array( 'retain_days' => 1 ) );
$inherit = asr3_form( 'Inherited retention', array( 'retain_days' => 0 ) );

$strict_old   = asr3_entry( $strict, array( 'name' => 'Old', 'message' => 'ten days' ), 10 );
$strict_recent = asr3_entry( $strict, array( 'name' => 'Recent', 'message' => 'today' ), 0 );
$strict_trashed = asr3_entry( $strict, array( 'name' => 'Trashed', 'message' => 'ten days, trashed' ), 10 );

wp_trash_post( $strict_trashed );
clean_post_cache( $strict_trashed );

$inherit_old    = asr3_entry( $inherit, array( 'name' => 'Old', 'message' => 'forty days' ), 40 );
$inherit_recent = asr3_entry( $inherit, array( 'name' => 'Recent', 'message' => 'twenty days' ), 20 );

$audit_before = asr3_audit_count( 'form_submissions_purged' );

$purge = Submission_Store::purge_expired();

$audit_after = asr3_audit_count( 'form_submissions_purged' );

$second = Submission_Store::purge_expired();

probe(
    'retention',
    array(
        'site_default'      => (int) Submission_Store::site_retention_days(),
        'strict_old_alive'  => asr3_alive( $strict_old ),
        'strict_recent_alive' => asr3_alive( $strict_recent ),
        'trashed_purged'    => ! asr3_alive( $strict_trashed ),
        'inherit_old_alive' => asr3_alive( $inherit_old ),
        'inherit_recent_alive' => asr3_alive( $inherit_recent ),
        'deleted'           => (int) $purge['deleted'],
        'failed'            => (int) $purge['failed'],
        'details'           => $purge['details'],
        'audited'           => $audit_after - $audit_before,
        'second_deleted'    => (int) $second['deleted'],
        'second_failed'     => (int) $second['failed'],
    )
);

// A submission whose form no longer exists is still purged on the site
// default: an unattributable record is not a reason to keep personal data
// forever, and nothing else will ever apply a period to it.
// Forty days is past the site default (thirty) and past the form's own period
// (one); the point is that *no form* decides for it any more.
$orphan = asr3_entry( $strict, array( 'name' => 'Orphan', 'message' => 'forty days' ), 40 );
// A form identifier that belongs to no form, and to no other run: a fixed
// value would collide with the same fixture from an earlier run on a shared
// bed, and the two orphans would be reported as one form that lost two
// submissions.
$orphan_id = substr( hash( 'sha256', 'orphan:' . $token . ':' . random_int( 1, 1000000000 ) ), 0, 32 );

$wpdb->update(
    $wpdb->postmeta,
    array( 'meta_value' => $orphan_id ),
    array( 'post_id' => $orphan, 'meta_key' => Submission_Store::META_FORM_ID )
);
clean_post_cache( $orphan );

$orphan_purge = Submission_Store::purge_expired();

// The detail that describes **this** orphan, by the identifier its meta row
// was rewritten to. A bed is disposable but shared: an interrupted run can
// leave an orphan of its own behind, and the next purge removes it too. The
// assertion is therefore about this fixture's orphan, not about the run
// having found exactly one thing to do.
$orphan_detail = null;

foreach ( (array) $orphan_purge['details'] as $detail ) {
    if ( 'unknown_form' === (string) ( $detail['reason'] ?? '' ) && $orphan_id === (string) ( $detail['form_id'] ?? '' ) ) {
        $orphan_detail = $detail;
    }
}

probe(
    'orphan',
    array(
        'alive'         => asr3_alive( $orphan ),
        'run_deleted'   => (int) $orphan_purge['deleted'],
        'detail'        => $orphan_detail,
        'audited'       => asr3_audit_count( 'form_submissions_purged' ) > 0,
    )
);

// ══ export: formula injection and quoting ════════════════════════════════════

$export_form = asr3_form( 'Export' );

// Exporting is a personal-data read, and the administrator role does not hold
// that capability by design (§8.3). The suite grants it for the duration of the
// export probes and revokes it again below, so the account is left as found.
$granted = Form_Capabilities::grant_submissions( get_current_user_id(), 0 );

$dangerous = array(
    '=HYPERLINK("http://evil.example","click")',
    '+1-800-ATTACK',
    '-2+3',
    '@SUM(A1:A2)',
    "\t=cmd",
    'plain, with a comma',
    'quoted "value" here',
    "line one\nline two",
);

foreach ( $dangerous as $index => $value ) {
    asr3_entry( $export_form, array( 'name' => 'Person ' . $index, 'message' => $value ) );
}

$payload = Form_Admin::export_payload( $export_form['form_id'], 'csv', get_current_user_id() );
$body    = is_array( $payload ) ? (string) $payload['body'] : '';

/**
 * Is a raw value present as a prefixed, quoted CSV cell?
 *
 * @param string $body  The CSV body.
 * @param string $value The raw value.
 * @return bool
 */
function asr3_prefixed_cell( $body, $value ) {
    $quoted = str_replace( '"', '""', "'" . $value );

    return false !== strpos( (string) $body, '"' . $quoted . '"' );
}

$prefix_detail = array();

foreach ( $dangerous as $value ) {
    if ( '' === $value || false === strpos( "=+-@\t", $value[0] ) ) {
        continue;
    }

    $prefix_detail[] = array(
        'value'    => $value,
        'prefixed' => asr3_prefixed_cell( $body, $value ),
        'raw_cell' => false !== strpos( $body, '"' . str_replace( '"', '""', $value ) . '"' ),
    );
}

$unsafe = 0;

foreach ( $prefix_detail as $row ) {
    if ( ! $row['prefixed'] ) {
        $unsafe++;
    }
}

$encoded = Form_Admin::export_payload( $export_form['form_id'], 'json', get_current_user_id() );
$decoded = is_array( $encoded ) ? json_decode( (string) $encoded['body'], true ) : null;

$json_values = array();

if ( is_array( $decoded ) && isset( $decoded['records'] ) ) {
    foreach ( $decoded['records'] as $record ) {
        if ( isset( $record['Message'] ) ) {
            $json_values[] = (string) $record['Message'];
        }
    }
}

$first_line = trim( (string) strtok( $body, "\r\n" ) );

probe(
    'export',
    array(
        'granted'        => $granted,
        'csv_is_array'   => is_array( $payload ),
        'csv_type'       => is_array( $payload ) ? (string) $payload['content_type'] : '',
        'csv_rows'       => is_array( $payload ) ? (int) $payload['count'] : -1,
        'header'         => $first_line,
        'prefix_detail'  => $prefix_detail,
        'unsafe_cells'   => $unsafe,
        'quote_doubled'  => false !== strpos( $body, '"quoted ""value"" here"' ),
        'comma_quoted'   => false !== strpos( $body, '"plain, with a comma"' ),
        'newline_quoted' => false !== strpos( $body, "\"line one\nline two\"" ),
        'json_decoded'   => is_array( $decoded ),
        'json_count'     => is_array( $decoded ) ? (int) $decoded['count'] : -1,
        'json_values'    => $json_values,
        'json_keys'      => is_array( $decoded ) ? array_keys( (array) $decoded ) : array(),
    )
);

// ══ cleanup ═════════════════════════════════════════════════════════════════

Form_Capabilities::revoke_submissions( get_current_user_id(), 0 );

foreach ( $mine['posts'] as $post_id ) {
    wp_delete_post( (int) $post_id, true );
}

$remaining = 0;

foreach ( array( $strict['form_id'], $inherit['form_id'], $export_form['form_id'] ) as $form_id ) {
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

update_option( Submission_Store::OPTION_RETENTION_DAYS, $site_default_before > 0 ? $site_default_before : Submission_Store::DEFAULT_RETENTION_DAYS, false );

probe( 'cleanup', array( 'remaining' => $remaining ) );

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
