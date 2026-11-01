<?php
/**
 * Child half of the builder storage suite: the optimistic-concurrency
 * guarantees the builder relies on, against a real WordPress.
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

use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Schema;

global $wpdb;

$token = 'ASb4' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array();

function probe( $name, array $data ) {
    global $f;
    $f['probes'][ $name ] = $data;
}

wp_set_current_user( 1 );

/**
 * Create and publish a form.
 *
 * @param string $name Name.
 * @return array
 */
function asb_form( $name ) {
    global $token, $mine;

    $definition = Form_Schema::defaults(
        Form_Definition::new_form_id(),
        'asb-' . strtolower( substr( md5( $name . $token . random_int( 1, 999999 ) ), 0, 8 ) ),
        $name
    );

    $definition['fields'] = array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Name',
            'required' => true,
            'settings' => array( 'max_length' => 120, 'min_length' => 0, 'autocomplete' => 'name' ),
            'options'  => array(),
        ),
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

    return array(
        'form_id'  => (string) $created['form_id'],
        'post_id'  => (int) $created['post_id'],
        'revision' => (int) ( $published['revision'] ?? 0 ),
    );
}

// ══ a stale discard is refused, and the newer work survives ═════════════════

$form = asb_form( 'Discard' );

$state = Form_Definition::get( (string) $form['form_id'] );
$read_revision = (int) $state['revision'];

// Somebody else saves after we read.
$theirs = $state['working'];
$theirs['fields'][] = array(
    'key'      => 'email',
    'type'     => 'email',
    'label'    => 'Email',
    'required' => false,
    'settings' => array( 'max_length' => 254, 'autocomplete' => 'email' ),
    'options'  => array(),
);

$saved = Form_Definition::update_working( (string) $form['form_id'], $theirs, $read_revision, 1 );

if ( is_wp_error( $saved ) ) {
    probe( 'fatal', array( 'where' => 'update', 'code' => $saved->get_error_code(), 'message' => $saved->get_error_message() ) );
    file_put_contents( $report, wp_json_encode( $f ) );
    exit( 0 );
}

// The stale editor discards against the revision they read.
$stale_discard = Form_Definition::discard( (string) $form['form_id'], 1, $read_revision );
$after_stale   = Form_Definition::get( (string) $form['form_id'] );

$kept_fields = is_array( $after_stale['working'] ) && isset( $after_stale['working']['fields'] )
    ? array_map( function ( $field ) { return (string) ( $field['key'] ?? '' ); }, $after_stale['working']['fields'] )
    : array();

probe(
    'stale_discard',
    array(
        'refused'     => is_wp_error( $stale_discard ),
        'code'        => is_wp_error( $stale_discard ) ? $stale_discard->get_error_code() : '',
        'kept_fields' => $kept_fields,
        'revision'    => (int) $after_stale['revision'],
    )
);

// ══ a current discard succeeds and reports the new revision ════════════════

$current = (int) $after_stale['revision'];
$discard = Form_Definition::discard( (string) $form['form_id'], 1, $current );

probe(
    'discard',
    array(
        'ok'       => ! is_wp_error( $discard ),
        'revision' => is_wp_error( $discard ) ? -1 : (int) ( $discard['revision'] ?? 0 ),
        'was'      => $current,
        'fields'   => ! is_wp_error( $discard ) && is_array( $discard['working'] ) ? count( (array) $discard['working']['fields'] ) : -1,
    )
);

// ══ rename is guarded by the same revision ════════════════════════════════

$before_rename = Form_Definition::get( (string) $form['form_id'] );
$rename_ok     = Form_Definition::rename( (string) $form['form_id'], 'Renamed ' . $token, (int) $before_rename['revision'], 1 );

$after_rename = Form_Definition::get( (string) $form['form_id'] );
$stale_rename = Form_Definition::rename( (string) $form['form_id'], 'Stale ' . $token, (int) $before_rename['revision'], 1 );

probe(
    'rename',
    array(
        'ok'          => ! is_wp_error( $rename_ok ),
        'name'        => is_wp_error( $rename_ok ) ? '' : (string) ( $after_rename['working']['name'] ?? '' ),
        'title'       => (string) get_post( (int) $form['post_id'] )->post_title,
        'stale_refused' => is_wp_error( $stale_rename ),
        'stale_code'  => is_wp_error( $stale_rename ) ? $stale_rename->get_error_code() : '',
    )
);

// ══ duplicate creates a distinct, unpublished copy ════════════════════════

$copy = Form_Definition::duplicate( (string) $form['form_id'], 'Copy ' . $token, 1 );

if ( ! is_wp_error( $copy ) ) {
    $mine['posts'][] = (int) $copy['post_id'];
}

probe(
    'duplicate',
    array(
        'ok'          => ! is_wp_error( $copy ),
        'different'   => ! is_wp_error( $copy ) && (string) $copy['form_id'] !== (string) $form['form_id'],
        'version'     => ! is_wp_error( $copy ) ? (int) ( $copy['version'] ?? 0 ) : -1,
        'field_count' => ! is_wp_error( $copy ) && is_array( $copy['working'] ) ? count( (array) $copy['working']['fields'] ) : -1,
        'slug_differs' => ! is_wp_error( $copy ) && (string) $copy['slug'] !== (string) $after_rename['slug'],
    )
);

// ══ cleanup ════════════════════════════════════════════════════════════════

foreach ( $mine['posts'] as $post_id ) {
    wp_delete_post( (int) $post_id, true );
}

probe( 'cleanup', array( 'remaining' => count( get_posts( array( 'post_type' => Form_Post_Types::FORM, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => Form_Post_Types::META_FORM_ID, 'value' => $token, 'compare' => 'LIKE' ) ) ) ) ) ) );

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
