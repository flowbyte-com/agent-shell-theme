<?php
/**
 * Child half of the forms storage suite. Runs inside a real WordPress and
 * reports facts as JSON; the parent asserts them.
 *
 * Everything it creates is disposable, carries a per-run token, and is deleted
 * before the child exits — including anything a failed write leaked, because
 * the parent asserts the count is zero.
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

use AgentShell_Forms\Form_Audit;
use AgentShell_Forms\Form_Challenges;
use AgentShell_Forms\Form_DB;
use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Install;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Rate_Limits;
use AgentShell_Forms\Form_Schema;

global $wpdb;

$token = 'ASf1' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array();

/**
 * Record one probe's facts.
 *
 * @param string $name Probe name.
 * @param array  $data Facts.
 * @return void
 */
function probe( $name, array $data ) {
    global $f;
    $f['probes'][ $name ] = $data;
}

/**
 * Track a post so the child can delete it.
 *
 * @param int $id Post id.
 * @return int
 */
function track( $id ) {
    global $mine;
    if ( (int) $id > 0 ) {
        $mine[] = (int) $id;
    }
    return (int) $id;
}

/**
 * Every raw meta row for one key: the `meta_id` and the stored bytes.
 *
 * Deliberately not `get_post_meta()`: that collapses duplicates into one
 * value, which is exactly the corruption under test.
 *
 * @param int    $post_id Post id.
 * @param string $key     Meta key.
 * @return array<int,array{meta_id:int,meta_value:string}>
 */
function raw_rows( $post_id, $key ) {
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
            (int) $post_id,
            (string) $key
        ),
        ARRAY_A
    );
    $out = array();
    foreach ( (array) $rows as $row ) {
        $out[] = array( 'meta_id' => (int) $row['meta_id'], 'meta_value' => (string) $row['meta_value'] );
    }
    return $out;
}

/**
 * The number of rows for each definition key.
 *
 * @param int $post_id Post id.
 * @return array<string,int>
 */
function row_counts( $post_id ) {
    $out = array();
    foreach ( Form_Post_Types::all_meta_keys() as $key ) {
        $out[ $key ] = count( raw_rows( $post_id, $key ) );
    }
    return $out;
}

/**
 * One field list used by most probes.
 *
 * @param string $suffix A suffix so two forms' definitions differ.
 * @return array
 */
function fields( $suffix = '' ) {
    return array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Your name' . $suffix,
            'required' => true,
        ),
        array(
            'key'      => 'email',
            'type'     => 'email',
            'label'    => 'Your email',
            'required' => true,
        ),
    );
}

/* ---------------------------------------------------------------------------
 * Precondition: the plugin is loaded, its types are registered, its tables
 * exist. If any of that is false the rest of the suite is proving nothing.
 * ------------------------------------------------------------------------ */

probe(
    'precondition',
    array(
        'plugin_constant'   => defined( 'AGENTSHELL_FORMS_VERSION' ),
        'version'           => defined( 'AGENTSHELL_FORMS_VERSION' ) ? AGENTSHELL_FORMS_VERSION : '',
        'schema_version'    => defined( 'AGENTSHELL_FORMS_SCHEMA_VERSION' ) ? AGENTSHELL_FORMS_SCHEMA_VERSION : 0,
        'form_type'         => post_type_exists( Form_Post_Types::FORM ),
        'entry_type'        => post_type_exists( Form_Post_Types::ENTRY ),
        'revisions_support' => post_type_supports( Form_Post_Types::FORM, 'revisions' ),
        'tables_exist'      => Form_Install::tables_exist(),
        'engine'            => Form_DB::engine_for( Form_DB::definition_tables() ),
    )
);

/* ---------------------------------------------------------------------------
 * P1 — create a form, and observe the rows it created
 * ------------------------------------------------------------------------ */

$created = Form_Definition::create(
    array(
        'name'   => 'Storage probe ' . $token,
        'slug'   => 'storage-' . strtolower( $token ),
        'fields' => fields(),
    ),
    0
);

if ( is_wp_error( $created ) ) {
    probe( 'p1_create', array( 'error' => $created->get_error_code(), 'message' => $created->get_error_message() ) );
    file_put_contents( $report, wp_json_encode( $f ) );
    echo "reported\n";
    exit( 0 );
}

$main_id     = track( (int) $created['post_id'] );
$main_form   = (string) $created['form_id'];
$main_slug   = (string) $created['slug'];
$post_after  = get_post( $main_id );

probe(
    'p1_create',
    array(
        'post_id'            => $main_id,
        'form_id'            => $main_form,
        'slug'               => $main_slug,
        'revision'           => (int) $created['revision'],
        'version'            => (int) $created['version'],
        'has_been_published' => (bool) $created['has_been_published'],
        'working_name'       => isset( $created['working']['name'] ) ? (string) $created['working']['name'] : '',
        'working_fields'     => isset( $created['working']['fields'] ) ? count( $created['working']['fields'] ) : -1,
        'post_status'        => $post_after instanceof WP_Post ? $post_after->post_status : '',
        'post_type'          => $post_after instanceof WP_Post ? $post_after->post_type : '',
        'row_counts'         => row_counts( $main_id ),
        'form_id_is_32_hex'  => 1 === preg_match( '/^[a-f0-9]{32}$/', $main_form ),
        'published_absent'   => null === $created['published'],
        'previous_absent'    => null === $created['published_previous'],
    )
);

$main_meta = array();
foreach ( Form_Post_Types::all_meta_keys() as $key ) {
    $rows = raw_rows( $main_id, $key );
    $main_meta[ $key ] = $rows ? $rows[0] : null;
}
probe( 'p1_meta_ids', $main_meta );

/* ---------------------------------------------------------------------------
 * P2 — an invalid definition is refused before anything is written
 * ------------------------------------------------------------------------ */

$before = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Form_Post_Types::FORM )
);

// No fields: §5.5 requires at least one, so this must never become a form.
$refused = Form_Definition::create( array( 'name' => 'No fields ' . $token, 'fields' => array() ), 0 );

$after = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Form_Post_Types::FORM )
);

probe(
    'p2_create_invalid',
    array(
        'is_error'       => is_wp_error( $refused ),
        'code'           => is_wp_error( $refused ) ? $refused->get_error_code() : '',
        'error_codes'    => ( function () use ( $refused ) {
            if ( ! is_wp_error( $refused ) ) {
                return array();
            }
            $data = $refused->get_error_data();
            if ( ! is_array( $data ) || ! isset( $data['errors'] ) || ! is_array( $data['errors'] ) ) {
                return array();
            }
            return array_values( array_map( function ( $e ) { return (string) $e['code']; }, $data['errors'] ) );
        } )(),
        'posts_before'   => $before,
        'posts_after'    => $after,
        'no_post_created' => $before === $after,
    )
);

/* ---------------------------------------------------------------------------
 * P3 — lookup by the immutable id, and by slug
 * ------------------------------------------------------------------------ */

$by_id = Form_Definition::get( $main_form );
$slug_ids = Form_Definition::find_post_ids_by_meta( Form_Post_Types::META_SLUG, $main_slug );

probe(
    'p3_lookup',
    array(
        'by_id_ok'       => ! is_wp_error( $by_id ) && (int) $by_id['post_id'] === $main_id,
        'slug_ids'       => array_map( 'intval', $slug_ids ),
        'slug_taken'     => Form_Definition::slug_is_taken( $main_slug, 0 ),
        'slug_free_for_self' => Form_Definition::slug_is_taken( $main_slug, $main_id ),
        'bad_id_code'    => ( function () {
            $e = Form_Definition::get( 'not-a-form-id' );
            return is_wp_error( $e ) ? $e->get_error_code() : '';
        } )(),
        'missing_id_code' => ( function () {
            $e = Form_Definition::get( 'ffffffffffffffffffffffffffffffff' );
            return is_wp_error( $e ) ? $e->get_error_code() : '';
        } )(),
    )
);

/* ---------------------------------------------------------------------------
 * P4 — a valid update, guarded on the revision the caller saw
 * ------------------------------------------------------------------------ */

$working_row_before = raw_rows( $main_id, Form_Post_Types::META_WORKING );

$updated = Form_Definition::update_working(
    $main_form,
    array(
        'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
        'name'           => 'Storage probe renamed ' . $token,
        'description'    => 'renamed',
        'fields'         => fields( ' (edited)' ),
        'settings'       => Form_Schema::settings_defaults(),
    ),
    0,
    0
);

$working_row_after = raw_rows( $main_id, Form_Post_Types::META_WORKING );
$decoded           = json_decode( $working_row_after ? $working_row_after[0]['meta_value'] : '', true );

$update_working_name = is_wp_error( $updated )
    ? ''
    : ( isset( $updated['working']['name'] ) ? (string) $updated['working']['name'] : '' );

probe(
    'p4_update',
    array(
        'is_error'             => is_wp_error( $updated ),
        'revision'             => is_wp_error( $updated ) ? -1 : (int) $updated['revision'],
        'working_name'         => $update_working_name,
        'row_count_before'     => count( $working_row_before ),
        'row_count_after'      => count( $working_row_after ),
        'meta_id_stable'       => $working_row_before && $working_row_after
            ? $working_row_before[0]['meta_id'] === $working_row_after[0]['meta_id']
            : false,
        'bytes_changed'        => $working_row_before && $working_row_after
            ? $working_row_before[0]['meta_value'] !== $working_row_after[0]['meta_value']
            : false,
        'stored_name'          => is_array( $decoded ) && isset( $decoded['name'] ) ? (string) $decoded['name'] : '',
        'stored_has_edited'    => false !== strpos( (string) ( $working_row_after[0]['meta_value'] ?? '' ), '(edited)' ),
    )
);

/* ---------------------------------------------------------------------------
 * P5 — a stale revision is refused and nothing is written
 * ------------------------------------------------------------------------ */

$before_bytes = (string) ( raw_rows( $main_id, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' );
$before_revision_row = raw_rows( $main_id, Form_Post_Types::META_REVISION );

$stale = Form_Definition::update_working(
    $main_form,
    array(
        'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
        'name'           => 'Stale write ' . $token,
        'fields'         => fields( ' (stale)' ),
        'settings'       => Form_Schema::settings_defaults(),
    ),
    0, // the revision is now 1; 0 is stale
    0
);

$after_bytes = (string) ( raw_rows( $main_id, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' );
$after_revision_row = raw_rows( $main_id, Form_Post_Types::META_REVISION );

probe(
    'p5_stale_update',
    array(
        'is_error'       => is_wp_error( $stale ),
        'code'           => is_wp_error( $stale ) ? $stale->get_error_code() : '',
        'data'           => is_wp_error( $stale ) && is_array( $stale->get_error_data() ) ? $stale->get_error_data() : array(),
        'bytes_unchanged' => $before_bytes === $after_bytes,
        'revision_unchanged' => ( $before_revision_row[0]['meta_value'] ?? '' ) === ( $after_revision_row[0]['meta_value'] ?? '' ),
        'no_stale_marker' => false === strpos( $after_bytes, '(stale)' ),
    )
);

/* ---------------------------------------------------------------------------
 * P6 — the compare-and-swap itself is guarded on the previous bytes
 * ------------------------------------------------------------------------ */

$row = raw_rows( $main_id, Form_Post_Types::META_REVISION )[0];
$cas_wrong = Form_DB::cas_update( (int) $row['meta_id'], '9999', 'not-the-previous-value', $main_id );
$row_after_wrong = raw_rows( $main_id, Form_Post_Types::META_REVISION )[0];
$cas_right = Form_DB::cas_update( (int) $row_after_wrong['meta_id'], (string) $row_after_wrong['meta_value'], (string) $row_after_wrong['meta_value'], $main_id );

probe(
    'p6_cas',
    array(
        'wrong_old_refused'   => false === $cas_wrong,
        'value_unchanged'     => $row['meta_value'] === $row_after_wrong['meta_value'],
        'same_value_accepted' => true === $cas_right,
    )
);

/* ---------------------------------------------------------------------------
 * P7 — a duplicated meta row is detected and refused, and never overwritten
 * ------------------------------------------------------------------------ */

$dup_id = track(
    (int) wp_insert_post(
        array(
            'post_type'   => Form_Post_Types::FORM,
            'post_status' => 'draft',
            'post_title'  => 'Duplicate probe ' . $token,
        ),
        true
    )
);

$dup_form_id = Form_Definition::new_form_id();
Form_DB::insert_row( $dup_id, Form_Post_Types::META_FORM_ID, $dup_form_id );
Form_DB::insert_row( $dup_id, Form_Post_Types::META_SLUG, 'dup-' . strtolower( $token ) );
Form_DB::insert_row( $dup_id, Form_Post_Types::META_WORKING, wp_json_encode( Form_Schema::defaults( $dup_form_id, 'dup-' . strtolower( $token ), 'Duplicate probe' ) ) );
Form_DB::insert_row( $dup_id, Form_Post_Types::META_REVISION, '0' );
Form_DB::insert_row( $dup_id, Form_Post_Types::META_VERSION, '0' );
Form_DB::insert_row( $dup_id, Form_Post_Types::META_META, '{}' );

// The corruption: a second row for a key that must have exactly one.
$wpdb->insert(
    $wpdb->postmeta,
    array(
        'post_id'    => $dup_id,
        'meta_key'   => Form_Post_Types::META_WORKING,
        'meta_value' => wp_json_encode( Form_Schema::defaults( $dup_form_id, 'dup-' . strtolower( $token ), 'A different duplicate' ) ),
    ),
    array( '%d', '%s', '%s' )
);

$dup_read  = Form_Definition::get_by_post_id( $dup_id );
$dup_write = Form_Definition::update_working(
    $dup_form_id,
    array(
        'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
        'name'           => 'Should never be written',
        'fields'         => fields(),
        'settings'       => Form_Schema::settings_defaults(),
    ),
    0,
    0
);

$dup_rows = raw_rows( $dup_id, Form_Post_Types::META_WORKING );

probe(
    'p7_duplicate_meta',
    array(
        'row_count'            => count( $dup_rows ),
        'read_code'            => is_wp_error( $dup_read ) ? $dup_read->get_error_code() : '',
        'update_code'          => is_wp_error( $dup_write ) ? $dup_write->get_error_code() : '',
        'both_rows_unchanged'  => 2 === count( $dup_rows )
            && false === strpos( (string) $dup_rows[0]['meta_value'], 'Should never be written' )
            && false === strpos( (string) $dup_rows[1]['meta_value'], 'Should never be written' ),
        'audited'              => ( function () use ( $dup_form_id ) {
            foreach ( Form_Audit::recent( 20 ) as $entry ) {
                if ( 'form_meta_duplicate' === ( $entry['event'] ?? '' ) ) {
                    return true;
                }
            }
            return false;
        } )(),
    )
);

/* ---------------------------------------------------------------------------
 * P8 — publish the working definition
 * ------------------------------------------------------------------------ */

$current = Form_Definition::get( $main_form );
$published = Form_Definition::publish( $main_form, (int) $current['revision'], 0 );
$post_after_publish = get_post( $main_id );

probe(
    'p8_publish',
    array(
        'is_error'            => is_wp_error( $published ),
        'code'                => is_wp_error( $published ) ? $published->get_error_code() : '',
        'version'             => is_wp_error( $published ) ? -1 : (int) $published['version'],
        'has_been_published'  => is_wp_error( $published ) ? false : (bool) $published['has_been_published'],
        'published_name'      => is_wp_error( $published ) ? '' : (string) ( $published['published']['name'] ?? '' ),
        'published_status'    => is_wp_error( $published ) ? '' : (string) ( $published['published']['status'] ?? '' ),
        'published_version'   => is_wp_error( $published ) ? -1 : (int) ( $published['published']['version'] ?? -1 ),
        'previous_absent'     => is_wp_error( $published ) ? false : null === $published['published_previous'],
        'post_status'         => $post_after_publish instanceof WP_Post ? $post_after_publish->post_status : '',
        'row_counts'          => row_counts( $main_id ),
    )
);

$published_row_bytes = (string) ( raw_rows( $main_id, Form_Post_Types::META_PUBLISHED )[0]['meta_value'] ?? '' );
$version_row_bytes   = (string) ( raw_rows( $main_id, Form_Post_Types::META_VERSION )[0]['meta_value'] ?? '' );

/* ---------------------------------------------------------------------------
 * P9 — a publication against a stale revision is refused
 * ------------------------------------------------------------------------ */

$stale_publish = Form_Definition::publish( $main_form, 0, 0 );

probe(
    'p9_publish_stale',
    array(
        'is_error'            => is_wp_error( $stale_publish ),
        'code'                => is_wp_error( $stale_publish ) ? $stale_publish->get_error_code() : '',
        'version_unchanged'   => (string) ( raw_rows( $main_id, Form_Post_Types::META_VERSION )[0]['meta_value'] ?? '' ) === $version_row_bytes,
        'published_unchanged' => (string) ( raw_rows( $main_id, Form_Post_Types::META_PUBLISHED )[0]['meta_value'] ?? '' ) === $published_row_bytes,
    )
);

/* ---------------------------------------------------------------------------
 * P10 — an invalid working definition cannot be published
 *
 * The working definition is written directly, past the validator, because
 * that is the only way to reach this state in a test: `update_working()`
 * would refuse. It stands in for a definition written by an older build, or
 * by a bug, and the point is that publication refuses rather than promoting
 * a document that fails the rules.
 * ------------------------------------------------------------------------ */

$invalid_id = track(
    (int) wp_insert_post(
        array(
            'post_type'   => Form_Post_Types::FORM,
            'post_status' => 'draft',
            'post_title'  => 'Invalid publish probe ' . $token,
        ),
        true
    )
);

$invalid_form_id = Form_Definition::new_form_id();
$invalid_slug    = 'invalid-' . strtolower( $token );

$invalid_doc = Form_Schema::defaults( $invalid_form_id, $invalid_slug, 'Invalid publish probe' );
$invalid_doc['fields'] = fields();
$invalid_doc['settings'] = Form_Schema::settings_defaults();

// Break it in a way the validator names: a key the system does not understand.
$invalid_doc['surprise'] = 'this key is not part of the schema';

Form_DB::insert_row( $invalid_id, Form_Post_Types::META_FORM_ID, $invalid_form_id );
Form_DB::insert_row( $invalid_id, Form_Post_Types::META_SLUG, $invalid_slug );
Form_DB::insert_row( $invalid_id, Form_Post_Types::META_WORKING, wp_json_encode( $invalid_doc ) );
Form_DB::insert_row( $invalid_id, Form_Post_Types::META_REVISION, '0' );
Form_DB::insert_row( $invalid_id, Form_Post_Types::META_VERSION, '0' );
Form_DB::insert_row( $invalid_id, Form_Post_Types::META_META, '{}' );

$invalid_state   = Form_Definition::get_by_post_id( $invalid_id );
$invalid_publish = Form_Definition::publish( $invalid_form_id, is_wp_error( $invalid_state ) ? 0 : (int) $invalid_state['revision'], 0 );

probe(
    'p10_publish_invalid',
    array(
        'is_error'     => is_wp_error( $invalid_publish ),
        'code'         => is_wp_error( $invalid_publish ) ? $invalid_publish->get_error_code() : '',
        'error_codes'  => ( function () use ( $invalid_publish ) {
            if ( ! is_wp_error( $invalid_publish ) ) {
                return array();
            }
            $data = $invalid_publish->get_error_data();
            if ( ! is_array( $data ) || ! isset( $data['errors'] ) || ! is_array( $data['errors'] ) ) {
                return array();
            }
            return array_values( array_map( function ( $e ) { return (string) $e['code']; }, $data['errors'] ) );
        } )(),
        'published_absent' => 0 === count( raw_rows( $invalid_id, Form_Post_Types::META_PUBLISHED ) ),
        'version_still_zero' => '0' === (string) ( raw_rows( $invalid_id, Form_Post_Types::META_VERSION )[0]['meta_value'] ?? 'x' ),
    )
);

/* ---------------------------------------------------------------------------
 * P11 — the slug freezes after the first publication, permanently
 * ------------------------------------------------------------------------ */

$rename = Form_Definition::set_slug( $main_form, 'renamed-' . strtolower( $token ), 0 );
$after_rename_slug = (string) ( raw_rows( $main_id, Form_Post_Types::META_SLUG )[0]['meta_value'] ?? '' );

probe(
    'p11_slug_frozen',
    array(
        'is_error'      => is_wp_error( $rename ),
        'code'          => is_wp_error( $rename ) ? $rename->get_error_code() : '',
        'slug_unchanged' => $after_rename_slug === $main_slug,
    )
);

/* ---------------------------------------------------------------------------
 * P12 — discard reverts the working definition to the published one
 * ------------------------------------------------------------------------ */

$pre_discard_state  = Form_Definition::get( $main_form );
$pre_discard_rev    = is_wp_error( $pre_discard_state ) ? 0 : (int) $pre_discard_state['revision'];
$post_discard_edit  = Form_Definition::update_working(
    $main_form,
    array(
        'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
        'name'           => 'Uncommitted edit ' . $token,
        'fields'         => fields( ' (uncommitted)' ),
        'settings'       => Form_Schema::settings_defaults(),
    ),
    $pre_discard_rev,
    0
);
$uncommitted_bytes = (string) ( raw_rows( $main_id, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' );

$post_discard_state = Form_Definition::get( $main_form );
$discarded = Form_Definition::discard( $main_form, 0 );
$working_after_discard = (string) ( raw_rows( $main_id, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' );

probe(
    'p12_discard',
    array(
        'edit_ok'            => ! is_wp_error( $post_discard_edit ),
        'uncommitted_present' => false !== strpos( $uncommitted_bytes, 'Uncommitted edit' ),
        'is_error'           => is_wp_error( $discarded ),
        'code'               => is_wp_error( $discarded ) ? $discarded->get_error_code() : '',
        'working_equals_published' => $working_after_discard === $published_row_bytes,
        'working_name'       => is_wp_error( $discarded ) ? '' : (string) ( $discarded['working']['name'] ?? '' ),
        'revision_advanced'  => ! is_wp_error( $discarded ) && (int) $discarded['revision'] > (int) ( $post_discard_state['revision'] ?? 0 ),
    )
);

$discard_on_unpublished = ( function () use ( $token ) {
    $made = Form_Definition::create(
        array( 'name' => 'Never published ' . $token, 'slug' => 'never-' . strtolower( $token ), 'fields' => fields() ),
        0
    );
    if ( is_wp_error( $made ) ) {
        return array( 'create_error' => $made->get_error_code() );
    }
    track( (int) $made['post_id'] );
    $e = Form_Definition::discard( (string) $made['form_id'], 0 );
    return array(
        'is_error' => is_wp_error( $e ),
        'code'     => is_wp_error( $e ) ? $e->get_error_code() : '',
    );
} )();
probe( 'p12b_discard_unpublished', $discard_on_unpublished );

/* ---------------------------------------------------------------------------
 * P13 — a failure part-way through a publication leaves nothing behind
 *
 * The seam fires inside the publication transaction, immediately before one
 * row is written. The handler makes the *version* write fail by removing the
 * row it is about to update, which is a genuine failure of a genuine write
 * rather than a patched return value. Everything the transaction wrote before
 * that point must be gone.
 * ------------------------------------------------------------------------ */

$fault_id = track(
    (int) wp_insert_post(
        array(
            'post_type'   => Form_Post_Types::FORM,
            'post_status' => 'draft',
            'post_title'  => 'Publication fault probe ' . $token,
        ),
        true
    )
);

$fault_form_id = Form_Definition::new_form_id();
$fault_slug    = 'fault-' . strtolower( $token );

$fault_doc = Form_Schema::defaults( $fault_form_id, $fault_slug, 'Publication fault probe' );
$fault_doc['fields']   = fields();
$fault_doc['settings'] = Form_Schema::settings_defaults();

Form_DB::insert_row( $fault_id, Form_Post_Types::META_FORM_ID, $fault_form_id );
Form_DB::insert_row( $fault_id, Form_Post_Types::META_SLUG, $fault_slug );
Form_DB::insert_row( $fault_id, Form_Post_Types::META_WORKING, wp_json_encode( $fault_doc ) );
Form_DB::insert_row( $fault_id, Form_Post_Types::META_REVISION, '0' );
Form_DB::insert_row( $fault_id, Form_Post_Types::META_VERSION, '0' );
Form_DB::insert_row( $fault_id, Form_Post_Types::META_META, '{}' );

$fault_version_before = raw_rows( $fault_id, Form_Post_Types::META_VERSION );

$fault_handler = function ( $post_id, $key ) use ( $fault_id ) {
    if ( (int) $post_id !== (int) $fault_id ) {
        return;
    }
    if ( Form_Post_Types::META_VERSION !== $key ) {
        return;
    }
    global $wpdb;
    // A real write that a real failure can produce: the row the compare-and-
    // swap expects is no longer there when the swap runs.
    $wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $fault_id, 'meta_key' => Form_Post_Types::META_VERSION ), array( '%d', '%s' ) );
    wp_cache_delete( (int) $fault_id, 'post_meta' );
};

add_action( 'agentshell_forms_before_definition_write', $fault_handler, 10, 2 );

$fault_state   = Form_Definition::get_by_post_id( $fault_id );
$fault_publish = Form_Definition::publish( $fault_form_id, is_wp_error( $fault_state ) ? 0 : (int) $fault_state['revision'], 0 );

remove_action( 'agentshell_forms_before_definition_write', $fault_handler, 10 );

$fault_published_after = raw_rows( $fault_id, Form_Post_Types::META_PUBLISHED );
$fault_version_after   = raw_rows( $fault_id, Form_Post_Types::META_VERSION );
$fault_previous_after  = raw_rows( $fault_id, Form_Post_Types::META_PUBLISHED_PREVIOUS );

probe(
    'p13_publication_failure',
    array(
        'is_error'            => is_wp_error( $fault_publish ),
        'code'                => is_wp_error( $fault_publish ) ? $fault_publish->get_error_code() : '',
        'published_absent'    => 0 === count( $fault_published_after ),
        'previous_absent'     => 0 === count( $fault_previous_after ),
        'version_row_restored' => 1 === count( $fault_version_after ),
        'version_unchanged'   => $fault_version_before && $fault_version_after
            ? $fault_version_before[0]['meta_value'] === $fault_version_after[0]['meta_value']
            : false,
        'post_status'         => (string) get_post_status( $fault_id ),
        'state_after'         => ( function () use ( $fault_form_id ) {
            $s = Form_Definition::get( $fault_form_id );
            return array(
                'is_error'   => is_wp_error( $s ),
                'published'  => ! is_wp_error( $s ) && null === $s['published'],
                'version'    => is_wp_error( $s ) ? -1 : (int) $s['version'],
                'has_been_published' => is_wp_error( $s ) ? true : (bool) $s['has_been_published'],
            );
        } )(),
    )
);

/* ---------------------------------------------------------------------------
 * P14 — a native revision records the definition metadata
 * ------------------------------------------------------------------------ */

$revisions = wp_get_post_revisions( $main_id, array( 'numberposts' => 20, 'orderby' => 'ID', 'order' => 'DESC' ) );
$latest    = $revisions ? reset( $revisions ) : null;

$rev_working   = $latest ? get_post_meta( $latest->ID, Form_Post_Types::META_WORKING, true ) : '';
$rev_published = $latest ? get_post_meta( $latest->ID, Form_Post_Types::META_PUBLISHED, true ) : '';
$rev_parent    = $latest ? (int) $latest->post_parent : 0;

$live_published = (string) ( raw_rows( $main_id, Form_Post_Types::META_PUBLISHED )[0]['meta_value'] ?? '' );
$decoded_rev_published = json_decode( (string) $rev_published, true );

probe(
    'p14_revisions',
    array(
        'count'                 => count( $revisions ),
        'parent_is_form'        => $rev_parent === $main_id,
        'revision_type'         => $latest ? (string) $latest->post_type : '',
        'has_working_meta'      => '' !== (string) $rev_working,
        'has_published_meta'    => '' !== (string) $rev_published,
        'published_matches_live' => (string) $rev_published === $live_published,
        'published_version'     => is_array( $decoded_rev_published ) ? (int) ( $decoded_rev_published['version'] ?? -1 ) : -1,
        'working_is_definition' => is_array( json_decode( (string) $rev_working, true ) ),
        'published_is_definition' => is_array( $decoded_rev_published ),
    )
);

/* ---------------------------------------------------------------------------
 * P14b — the documented PHP API reads the two definitions
 * ------------------------------------------------------------------------ */

$api_working   = agentshell_forms_get_working( $main_form );
$api_published = agentshell_forms_get_published( $main_form );
$api_by_post   = agentshell_forms_get_published( $main_id );
$api_unknown   = agentshell_forms_get_published( 'ffffffffffffffffffffffffffffffff' );

probe(
    'p14b_api',
    array(
        'functions_exist' => function_exists( 'agentshell_forms_get_working' )
            && function_exists( 'agentshell_forms_get_published' )
            && function_exists( 'agentshell_forms_validate' )
            && function_exists( 'agentshell_forms_field_types' ),
        'working_is_array'   => is_array( $api_working ),
        'published_is_array' => is_array( $api_published ),
        'working_matches_row' => is_array( $api_working )
            && wp_json_encode( $api_working ) === (string) ( raw_rows( $main_id, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' ),
        'published_matches_row' => is_array( $api_published )
            && wp_json_encode( $api_published ) === (string) ( raw_rows( $main_id, Form_Post_Types::META_PUBLISHED )[0]['meta_value'] ?? '' ),
        'by_post_id_resolves' => is_array( $api_by_post ),
        'unknown_is_null'     => null === $api_unknown,
        'field_type_count'    => count( agentshell_forms_field_types() ),
    )
);

/* ---------------------------------------------------------------------------
 * P15 — the challenge and rate-limit tables exist and are the plugin's own
 * ------------------------------------------------------------------------ */

probe(
    'p15_tables',
    array(
        'challenges_table'  => Form_Challenges::table(),
        'rate_limits_table' => Form_Rate_Limits::table(),
        'challenges_exist'  => Form_Challenges::table() === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Form_Challenges::table() ) ),
        'rate_limits_exist' => Form_Rate_Limits::table() === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Form_Rate_Limits::table() ) ),
        'token_hash_unique' => ( function () {
            global $wpdb;
            $indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Form_Challenges::table(), ARRAY_A );
            foreach ( (array) $indexes as $index ) {
                if ( 'token_hash' === (string) $index['Key_name'] && 0 === (int) $index['Non_unique'] ) {
                    return true;
                }
            }
            return false;
        } )(),
        'bucket_unique' => ( function () {
            global $wpdb;
            $indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Form_Rate_Limits::table(), ARRAY_A );
            foreach ( (array) $indexes as $index ) {
                if ( 'bucket' === (string) $index['Key_name'] && 0 === (int) $index['Non_unique'] ) {
                    return true;
                }
            }
            return false;
        } )(),
    )
);

/* ---------------------------------------------------------------------------
 * P17 — the slug has two representations, and they are changed together
 *
 * The dedicated meta row is what the shortcode resolver reads; the definition
 * carries a copy because the document describes itself. A change that wrote
 * only one of them would leave the form saying two different things — and the
 * next editor to save would silently reinstate whichever copy it read.
 * ------------------------------------------------------------------------ */

$slug_made = Form_Definition::create(
    array(
        'name'   => 'Slug consistency ' . $token,
        'slug'   => 'slug-one-' . strtolower( $token ),
        'fields' => fields(),
    ),
    0
);

$slug_probe = array(
    'create_ok' => ! is_wp_error( $slug_made ),
    'code'      => is_wp_error( $slug_made ) ? $slug_made->get_error_code() : '',
);

if ( ! is_wp_error( $slug_made ) ) {
    $slug_post = track( (int) $slug_made['post_id'] );
    $slug_form = (string) $slug_made['form_id'];
    $slug_old  = (string) $slug_made['slug'];
    $slug_new  = 'slug-two-' . strtolower( $token );

    $slug_probe['consistent_at_create'] = true === ( $slug_made['slug_consistent'] ?? null );

    // The change itself.
    $slug_change = Form_Definition::set_slug( $slug_form, $slug_new, 0 );

    $slug_row_after   = (string) ( raw_rows( $slug_post, Form_Post_Types::META_SLUG )[0]['meta_value'] ?? '' );
    $working_after    = json_decode( (string) ( raw_rows( $slug_post, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' ), true );
    $revision_after   = (string) ( raw_rows( $slug_post, Form_Post_Types::META_REVISION )[0]['meta_value'] ?? '' );
    $slug_state_after = Form_Definition::get( $slug_form );

    $slug_probe['change_ok']        = ! is_wp_error( $slug_change );
    $slug_probe['change_code']      = is_wp_error( $slug_change ) ? $slug_change->get_error_code() : '';
    $slug_probe['slug_row']         = $slug_row_after;
    $slug_probe['working_slug']     = is_array( $working_after ) ? (string) ( $working_after['slug'] ?? '' ) : '';
    $slug_probe['both_changed']     = $slug_row_after === $slug_new
        && is_array( $working_after )
        && (string) ( $working_after['slug'] ?? '' ) === $slug_new;
    $slug_probe['revision_bumped']  = '1' === $revision_after;
    $slug_probe['consistent_flag']  = ! is_wp_error( $slug_state_after ) && true === ( $slug_state_after['slug_consistent'] ?? null );
    $slug_probe['reported_slug']    = is_wp_error( $slug_change ) ? '' : (string) $slug_change['slug'];
    $slug_probe['no_old_slug']      = false === strpos( (string) json_encode( $working_after ), (string) '"slug":"' . $slug_old . '"' );

    // A stale editor — one that read the form before the slug change — must be
    // refused, and must not reinstate the old slug on its way to being
    // refused. Its write carries the old slug because that is what it read.
    $stale_doc = array(
        'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
        'name'           => 'Stale editor ' . $token,
        'slug'           => $slug_old,
        'fields'         => fields( ' (stale)' ),
        'settings'       => Form_Schema::settings_defaults(),
    );

    $stale_editor   = Form_Definition::update_working( $slug_form, $stale_doc, 0, 0 );
    $working_stale  = json_decode( (string) ( raw_rows( $slug_post, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' ), true );

    $slug_probe['stale_editor_refused'] = is_wp_error( $stale_editor );
    $slug_probe['stale_editor_code']    = is_wp_error( $stale_editor ) ? $stale_editor->get_error_code() : '';
    $slug_probe['stale_slug_not_written'] = is_array( $working_stale )
        && (string) ( $working_stale['slug'] ?? '' ) === $slug_new;

    // Publishing after the change: the published document must carry the new
    // slug, and the row must still agree with it.
    $slug_state   = Form_Definition::get( $slug_form );
    $slug_publish = Form_Definition::publish( $slug_form, is_wp_error( $slug_state ) ? 0 : (int) $slug_state['revision'], 0 );

    $published_doc = is_wp_error( $slug_publish ) || ! is_array( $slug_publish['published'] )
        ? null
        : $slug_publish['published'];

    $slug_probe['publish_ok']          = ! is_wp_error( $slug_publish );
    $slug_probe['publish_code']        = is_wp_error( $slug_publish ) ? $slug_publish->get_error_code() : '';
    $slug_probe['published_slug']      = is_array( $published_doc ) ? (string) ( $published_doc['slug'] ?? '' ) : '';
    $slug_probe['published_slug_is_new'] = is_array( $published_doc ) && (string) ( $published_doc['slug'] ?? '' ) === $slug_new;
    $slug_probe['published_matches_row'] = is_array( $published_doc )
        && (string) ( $published_doc['slug'] ?? '' ) === (string) ( raw_rows( $slug_post, Form_Post_Types::META_SLUG )[0]['meta_value'] ?? '' );
    $slug_probe['status_synced']       = ! is_wp_error( $slug_publish ) && true === ( $slug_publish['status_synced'] ?? null );
    $slug_probe['revision_id']         = is_wp_error( $slug_publish ) ? -1 : (int) ( $slug_publish['revision_id'] ?? 0 );
    $slug_probe['post_status']         = (string) get_post_status( $slug_post );
    $slug_probe['slug_consistent_after_publish'] = ! is_wp_error( $slug_publish ) && true === ( $slug_publish['slug_consistent'] ?? null );
}

probe( 'p17_slug_consistency', $slug_probe );

/* ---------------------------------------------------------------------------
 * P17b — a drifted definition cannot be published under the wrong slug
 *
 * The embedded slug can be stale in a document written by an older build, or
 * by anything that bypassed this store. The published document must take the
 * dedicated row's value, because that row is what the resolver actually reads.
 * ------------------------------------------------------------------------ */

$drift_made = Form_Definition::create(
    array(
        'name'   => 'Drifted slug ' . $token,
        'slug'   => 'drift-' . strtolower( $token ),
        'fields' => fields(),
    ),
    0
);

$drift_probe = array( 'create_ok' => ! is_wp_error( $drift_made ) );

if ( ! is_wp_error( $drift_made ) ) {
    $drift_post = track( (int) $drift_made['post_id'] );
    $drift_form = (string) $drift_made['form_id'];
    $drift_row  = (string) $drift_made['slug'];

    // Corrupt only the embedded copy, the way a bypassing writer would.
    $drift_doc                = json_decode( (string) raw_rows( $drift_post, Form_Post_Types::META_WORKING )[0]['meta_value'], true );
    $drift_doc['slug']        = 'somebody-elses-slug';
    $drift_working_rows       = raw_rows( $drift_post, Form_Post_Types::META_WORKING );
    Form_DB::cas_update( (int) $drift_working_rows[0]['meta_id'], (string) wp_json_encode( $drift_doc ), (string) $drift_working_rows[0]['meta_value'], $drift_post );

    $drift_state_before = Form_Definition::get( $drift_form );
    $drift_probe['inconsistent_before'] = ! is_wp_error( $drift_state_before ) && false === ( $drift_state_before['slug_consistent'] ?? null );

    $drift_publish = Form_Definition::publish( $drift_form, is_wp_error( $drift_state_before ) ? 0 : (int) $drift_state_before['revision'], 0 );

    $drift_published = is_wp_error( $drift_publish ) || ! is_array( $drift_publish['published'] )
        ? null
        : $drift_publish['published'];

    $drift_probe['publish_ok']        = ! is_wp_error( $drift_publish );
    $drift_probe['code']              = is_wp_error( $drift_publish ) ? $drift_publish->get_error_code() : '';
    $drift_probe['row_slug']          = $drift_row;
    $drift_probe['published_slug']    = is_array( $drift_published ) ? (string) ( $drift_published['slug'] ?? '' ) : '';
    $drift_probe['row_is_authority']  = is_array( $drift_published ) && (string) ( $drift_published['slug'] ?? '' ) === $drift_row;
}

probe( 'p17b_slug_drift', $drift_probe );

/* ---------------------------------------------------------------------------
 * P17c — a failed publication after a slug change leaves the pair consistent
 * ------------------------------------------------------------------------ */

$slugfault_made = Form_Definition::create(
    array(
        'name'   => 'Slug fault ' . $token,
        'slug'   => 'slugfault-one-' . strtolower( $token ),
        'fields' => fields(),
    ),
    0
);

$slugfault_probe = array( 'create_ok' => ! is_wp_error( $slugfault_made ) );

if ( ! is_wp_error( $slugfault_made ) ) {
    $slugfault_post = track( (int) $slugfault_made['post_id'] );
    $slugfault_form = (string) $slugfault_made['form_id'];
    $slugfault_new  = 'slugfault-two-' . strtolower( $token );

    $slugfault_change = Form_Definition::set_slug( $slugfault_form, $slugfault_new, 0 );

    $slugfault_handler = function ( $post_id, $key ) use ( $slugfault_post ) {
        if ( (int) $post_id !== (int) $slugfault_post || Form_Post_Types::META_VERSION !== $key ) {
            return;
        }
        global $wpdb;
        $wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $slugfault_post, 'meta_key' => Form_Post_Types::META_VERSION ), array( '%d', '%s' ) );
        wp_cache_delete( (int) $slugfault_post, 'post_meta' );
    };

    add_action( 'agentshell_forms_before_definition_write', $slugfault_handler, 10, 2 );

    $slugfault_state   = Form_Definition::get( $slugfault_form );
    $slugfault_publish = Form_Definition::publish( $slugfault_form, is_wp_error( $slugfault_state ) ? 0 : (int) $slugfault_state['revision'], 0 );

    remove_action( 'agentshell_forms_before_definition_write', $slugfault_handler, 10 );

    $slugfault_working = json_decode( (string) ( raw_rows( $slugfault_post, Form_Post_Types::META_WORKING )[0]['meta_value'] ?? '' ), true );

    $slugfault_probe['change_ok']       = ! is_wp_error( $slugfault_change );
    $slugfault_probe['publish_refused'] = is_wp_error( $slugfault_publish );
    $slugfault_probe['code']            = is_wp_error( $slugfault_publish ) ? $slugfault_publish->get_error_code() : '';
    $slugfault_probe['slug_row']        = (string) ( raw_rows( $slugfault_post, Form_Post_Types::META_SLUG )[0]['meta_value'] ?? '' );
    $slugfault_probe['working_slug']    = is_array( $slugfault_working ) ? (string) ( $slugfault_working['slug'] ?? '' ) : '';
    $slugfault_probe['pair_consistent'] = $slugfault_probe['slug_row'] === $slugfault_new
        && $slugfault_probe['working_slug'] === $slugfault_new;
    $slugfault_probe['published_absent'] = 0 === count( raw_rows( $slugfault_post, Form_Post_Types::META_PUBLISHED ) );
    $slugfault_probe['version_zero']     = '0' === (string) ( raw_rows( $slugfault_post, Form_Post_Types::META_VERSION )[0]['meta_value'] ?? 'x' );
}

probe( 'p17c_slug_publication_failure', $slugfault_probe );

/* ---------------------------------------------------------------------------
 * P18 — every definition mutation records exactly one audit entry
 *
 * The record is written inside the same transaction as the change, so the
 * order of the entries is the order of the changes, and there is exactly one
 * of each. A missing entry is as important as a duplicated one.
 * ------------------------------------------------------------------------ */

$audit_made = Form_Definition::create(
    array(
        'name'   => 'Audit trail ' . $token,
        'slug'   => 'audit-' . strtolower( $token ),
        'fields' => fields(),
    ),
    0
);

$audit_probe = array( 'create_ok' => ! is_wp_error( $audit_made ) );

if ( ! is_wp_error( $audit_made ) ) {
    $audit_post = track( (int) $audit_made['post_id'] );
    $audit_form = (string) $audit_made['form_id'];

    $audit_slug = Form_Definition::set_slug( $audit_form, 'audit-renamed-' . strtolower( $token ), 0 );

    $audit_state = Form_Definition::get( $audit_form );
    $audit_update = Form_Definition::update_working(
        $audit_form,
        array(
            'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
            'name'           => 'Audit trail edited ' . $token,
            'fields'         => fields( ' (audited)' ),
            'settings'       => Form_Schema::settings_defaults(),
        ),
        is_wp_error( $audit_state ) ? 0 : (int) $audit_state['revision'],
        0
    );

    $audit_state2  = Form_Definition::get( $audit_form );
    $audit_publish = Form_Definition::publish( $audit_form, is_wp_error( $audit_state2 ) ? 0 : (int) $audit_state2['revision'], 0 );
    $audit_discard = Form_Definition::discard( $audit_form, 0 );

    $events = array();
    $well_formed = 0;
    foreach ( Form_Audit::entries() as $entry ) {
        if ( ! is_array( $entry ) || ! isset( $entry['data'] ) || ! is_array( $entry['data'] ) ) {
            continue;
        }
        if ( (string) ( $entry['data']['form_id'] ?? '' ) !== $audit_form ) {
            continue;
        }
        if ( isset( $entry['event'], $entry['at'], $entry['actor'] ) ) {
            $well_formed++;
        }
        $events[] = (string) ( $entry['event'] ?? '' );
    }

    $audit_probe['ops_ok']         = ! is_wp_error( $audit_slug ) && ! is_wp_error( $audit_update ) && ! is_wp_error( $audit_publish ) && ! is_wp_error( $audit_discard );
    $audit_probe['op_codes']       = array(
        is_wp_error( $audit_slug ) ? $audit_slug->get_error_code() : 'ok',
        is_wp_error( $audit_update ) ? $audit_update->get_error_code() : 'ok',
        is_wp_error( $audit_publish ) ? $audit_publish->get_error_code() : 'ok',
        is_wp_error( $audit_discard ) ? $audit_discard->get_error_code() : 'ok',
    );
    $audit_probe['events']        = $events;
    $audit_probe['expected']      = array( 'form_created', 'form_slug_changed', 'form_updated', 'form_published', 'form_discarded' );
    $audit_probe['exact_sequence'] = $events === $audit_probe['expected'];
    $audit_probe['well_formed']    = $well_formed;
    $audit_probe['all_well_formed'] = count( $events ) === $well_formed;
    $audit_probe['actors_zero']     = ( function () use ( $audit_form ) {
        foreach ( Form_Audit::entries() as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['data'] ) || ! is_array( $entry['data'] ) ) {
                continue;
            }
            if ( (string) ( $entry['data']['form_id'] ?? '' ) === $audit_form && 0 !== (int) ( $entry['actor'] ?? -1 ) ) {
                return false;
            }
        }
        return true;
    } )();
}

probe( 'p18_audit_per_mutation', $audit_probe );

/* ---------------------------------------------------------------------------
 * P19 — a mutation whose audit row cannot be written is refused, whole
 *
 * This is the difference between "the audit log usually records changes" and
 * "a change that cannot be recorded does not happen". The veto filter is the
 * only way to make the append fail without breaking the database, and it fails
 * *inside* the transaction — so the rows the mutation already wrote must be
 * rolled back with it.
 * ------------------------------------------------------------------------ */

/**
 * A veto handler that refuses one event.
 *
 * @param string $event Event to refuse.
 * @return callable
 */
function audit_veto( $event ) {
    return function ( $allow, $name ) use ( $event ) {
        return $name === $event ? false : $allow;
    };
}

$veto_made = Form_Definition::create(
    array(
        'name'   => 'Veto probe ' . $token,
        'slug'   => 'veto-' . strtolower( $token ),
        'fields' => fields(),
    ),
    0
);

$veto_probe = array( 'create_ok' => ! is_wp_error( $veto_made ) );

if ( ! is_wp_error( $veto_made ) ) {
    $veto_post = track( (int) $veto_made['post_id'] );
    $veto_form = (string) $veto_made['form_id'];

    // --- create: the refusal must leave no form behind at all -------------
    $forms_before = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Form_Post_Types::FORM )
    );

    $veto_form_created = audit_veto( 'form_created' );
    add_filter( Form_Audit::VETO_FILTER, $veto_form_created, 10, 2 );
    $veto_create = Form_Definition::create(
        array( 'name' => 'Vetoed create ' . $token, 'slug' => 'veto-create-' . strtolower( $token ), 'fields' => fields() ),
        0
    );
    remove_filter( Form_Audit::VETO_FILTER, $veto_form_created, 10 );

    $forms_after = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Form_Post_Types::FORM )
    );

    // --- update ----------------------------------------------------------
    $veto_state       = Form_Definition::get( $veto_form );
    $veto_working_row = raw_rows( $veto_post, Form_Post_Types::META_WORKING );

    $veto_form_updated = audit_veto( 'form_updated' );
    add_filter( Form_Audit::VETO_FILTER, $veto_form_updated, 10, 2 );
    $veto_update = Form_Definition::update_working(
        $veto_form,
        array(
            'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
            'name'           => 'Vetoed update ' . $token,
            'fields'         => fields( ' (vetoed)' ),
            'settings'       => Form_Schema::settings_defaults(),
        ),
        is_wp_error( $veto_state ) ? 0 : (int) $veto_state['revision'],
        0
    );
    remove_filter( Form_Audit::VETO_FILTER, $veto_form_updated, 10 );

    $veto_working_after = raw_rows( $veto_post, Form_Post_Types::META_WORKING );

    // --- slug ------------------------------------------------------------
    $veto_form_slug_changed = audit_veto( 'form_slug_changed' );
    add_filter( Form_Audit::VETO_FILTER, $veto_form_slug_changed, 10, 2 );
    $veto_slug = Form_Definition::set_slug( $veto_form, 'veto-renamed-' . strtolower( $token ), 0 );
    remove_filter( Form_Audit::VETO_FILTER, $veto_form_slug_changed, 10 );

    // --- publish ---------------------------------------------------------
    $veto_state2 = Form_Definition::get( $veto_form );

    $veto_form_published = audit_veto( 'form_published' );
    add_filter( Form_Audit::VETO_FILTER, $veto_form_published, 10, 2 );
    $veto_publish = Form_Definition::publish( $veto_form, is_wp_error( $veto_state2 ) ? 0 : (int) $veto_state2['revision'], 0 );
    remove_filter( Form_Audit::VETO_FILTER, $veto_form_published, 10 );

    // Captured here, while the refused publication is the latest thing that
    // happened — the real publication below is what the retry proves.
    $veto_after_refusal = array(
        'published' => count( raw_rows( $veto_post, Form_Post_Types::META_PUBLISHED ) ),
        'previous'  => count( raw_rows( $veto_post, Form_Post_Types::META_PUBLISHED_PREVIOUS ) ),
        'version'   => (string) ( raw_rows( $veto_post, Form_Post_Types::META_VERSION )[0]['meta_value'] ?? 'x' ),
        'status'    => (string) get_post_status( $veto_post ),
    );

    // --- discard ---------------------------------------------------------
    // A discard needs something to discard to, so publish for real first.
    $veto_state3   = Form_Definition::get( $veto_form );
    $veto_real_pub = Form_Definition::publish( $veto_form, is_wp_error( $veto_state3 ) ? 0 : (int) $veto_state3['revision'], 0 );

    $veto_state4      = Form_Definition::get( $veto_form );
    $veto_edit        = Form_Definition::update_working(
        $veto_form,
        array(
            'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
            'name'           => 'Vetoed discard ' . $token,
            'fields'         => fields( ' (to discard)' ),
            'settings'       => Form_Schema::settings_defaults(),
        ),
        is_wp_error( $veto_state4 ) ? 0 : (int) $veto_state4['revision'],
        0
    );
    $veto_before_discard = raw_rows( $veto_post, Form_Post_Types::META_WORKING );

    $veto_form_discarded = audit_veto( 'form_discarded' );
    add_filter( Form_Audit::VETO_FILTER, $veto_form_discarded, 10, 2 );
    $veto_discard = Form_Definition::discard( $veto_form, 0 );
    remove_filter( Form_Audit::VETO_FILTER, $veto_form_discarded, 10 );

    $veto_after_discard = raw_rows( $veto_post, Form_Post_Types::META_WORKING );

    // --- and, with the veto removed, the same publication succeeds --------
    $veto_state5  = Form_Definition::get( $veto_form );
    $veto_retried = Form_Definition::publish( $veto_form, is_wp_error( $veto_state5 ) ? 0 : (int) $veto_state5['revision'], 0 );

    $veto_probe['create_refused']   = is_wp_error( $veto_create ) && 'form_audit_failed' === $veto_create->get_error_code();
    $veto_probe['create_code']      = is_wp_error( $veto_create ) ? $veto_create->get_error_code() : '';
    $veto_probe['no_form_left']     = $forms_before === $forms_after;
    $veto_probe['update_refused']   = is_wp_error( $veto_update ) && 'form_audit_failed' === $veto_update->get_error_code();
    $veto_probe['update_code']      = is_wp_error( $veto_update ) ? $veto_update->get_error_code() : '';
    $veto_probe['update_unchanged'] = $veto_working_row && $veto_working_after
        && $veto_working_row[0]['meta_value'] === $veto_working_after[0]['meta_value'];
    $veto_probe['slug_refused']     = is_wp_error( $veto_slug ) && 'form_audit_failed' === $veto_slug->get_error_code();
    $veto_probe['slug_code']        = is_wp_error( $veto_slug ) ? $veto_slug->get_error_code() : '';
    $veto_probe['slug_unchanged']   = (string) ( raw_rows( $veto_post, Form_Post_Types::META_SLUG )[0]['meta_value'] ?? '' ) === (string) $veto_made['slug'];
    $veto_probe['publish_refused']  = is_wp_error( $veto_publish ) && 'form_audit_failed' === $veto_publish->get_error_code();
    $veto_probe['publish_code']     = is_wp_error( $veto_publish ) ? $veto_publish->get_error_code() : '';
    $veto_probe['publish_nothing_written'] = 0 === $veto_after_refusal['published']
        && 0 === $veto_after_refusal['previous']
        && '0' === $veto_after_refusal['version'];
    $veto_probe['publish_left_draft'] = 'draft' === $veto_after_refusal['status'];
    $veto_probe['discard_refused']  = is_wp_error( $veto_discard ) && 'form_audit_failed' === $veto_discard->get_error_code();
    $veto_probe['discard_code']     = is_wp_error( $veto_discard ) ? $veto_discard->get_error_code() : '';
    $veto_probe['discard_unchanged'] = $veto_before_discard && $veto_after_discard
        && $veto_before_discard[0]['meta_value'] === $veto_after_discard[0]['meta_value'];
    $veto_probe['real_publish_ok']  = ! is_wp_error( $veto_real_pub );
    $veto_probe['edit_ok']          = ! is_wp_error( $veto_edit );
    $veto_probe['retry_ok']         = ! is_wp_error( $veto_retried );
    $veto_probe['retry_code']       = is_wp_error( $veto_retried ) ? $veto_retried->get_error_code() : '';
}

probe( 'p19_audit_veto', $veto_probe );

/* ---------------------------------------------------------------------------
 * P20 — a table set that cannot roll back refuses a required transaction
 *
 * Proven against the real server rather than a fake: a MyISAM table is created
 * for the duration of the probe, the engine probe is asked about it, and the
 * two modes are compared. The callback must not run in required mode, and must
 * run in optional mode.
 * ------------------------------------------------------------------------ */

$myisam_table = $wpdb->prefix . 'agentshell_forms_probe_myisam';
$wpdb->query( "DROP TABLE IF EXISTS {$myisam_table}" );
$myisam_created = false !== $wpdb->query( "CREATE TABLE {$myisam_table} ( id INT NOT NULL ) ENGINE=MyISAM" );

$engine_myisam = $myisam_created ? Form_DB::engine_for( array( $myisam_table ) ) : 'untested';
$engine_innodb = Form_DB::engine_for( Form_DB::definition_tables() );

$required_ran = false;
$required     = Form_DB::run(
    array( $myisam_table ),
    function () use ( &$required_ran ) {
        $required_ran = true;
        return 'written';
    }
);

$optional_ran = false;
$optional     = Form_DB::run(
    array( $myisam_table ),
    function () use ( &$optional_ran ) {
        $optional_ran = true;
        return 'written';
    },
    Form_DB::TRANSACTION_OPTIONAL
);

$wpdb->query( "DROP TABLE IF EXISTS {$myisam_table}" );

probe(
    'p20_engine',
    array(
        'myisam_created'      => $myisam_created,
        'myisam_engine'       => $engine_myisam,
        'definition_engine'   => $engine_innodb,
        'tables'              => array_values( Form_DB::definition_tables() ),
        'options_included'    => in_array( $wpdb->options, Form_DB::definition_tables(), true ),
        'required_is_error'   => is_wp_error( $required ),
        'required_code'       => is_wp_error( $required ) ? $required->get_error_code() : '',
        'required_reason'     => is_wp_error( $required ) && is_array( $required->get_error_data() ) ? (string) ( $required->get_error_data()['reason'] ?? '' ) : '',
        'required_ran'        => $required_ran,
        'optional_ran'        => $optional_ran,
        'optional_result'     => is_string( $optional ) ? $optional : '',
        'table_dropped'       => null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $myisam_table ) ),
    )
);

/* ---------------------------------------------------------------------------
 * P21 — cleanup: every disposable record is gone
 * ------------------------------------------------------------------------ */

$deleted = array();
foreach ( array_unique( $mine ) as $id ) {
    $deleted[ $id ] = (bool) wp_delete_post( (int) $id, true );
}

$leftovers = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_title LIKE %s",
        Form_Post_Types::FORM,
        Form_Post_Types::ENTRY,
        '%' . $wpdb->esc_like( $token ) . '%'
    )
);

$stray_meta = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
          WHERE p.ID IS NULL AND m.meta_key LIKE %s",
        $wpdb->esc_like( '_agentshell_form' ) . '%'
    )
);

// The audit ring is plugin bookkeeping, not content; leave it empty so a later
// suite starts from a known state rather than from this run's history.
Form_Audit::clear();

probe(
    'p21_cleanup',
    array(
        'leftovers'  => array_map( 'intval', $leftovers ),
        'stray_meta' => $stray_meta,
    )
);

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
exit( 0 );
