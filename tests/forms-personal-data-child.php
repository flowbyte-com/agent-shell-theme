<?php
/**
 * Child half of the personal-data suite: the Phase 5 authority matrix against
 * a real WordPress.
 *
 * argv: 1 WordPress root  2 report path
 *
 * Three principals, one disposable bed:
 *
 *   - B: an administrator (manage_options and the form capability through
 *     the role, but NOT the submissions capability). Every agent principal
 *     looks like B, because the transport requires manage_options.
 *   - C: a subscriber with the form capability and the submissions
 *     capability granted directly, but WITHOUT manage_options. C proves the
 *     tools check the named capabilities and never consult manage_options
 *     in either direction.
 *   - D: an administrator with the submissions capability granted per user.
 *     D is the only principal that may read, export, trash and delete.
 *
 * The child exercises all twelve tools through their real execute() paths,
 * records the raw outcomes as probes, and removes everything it created.
 * The parent asserts the matrix. Submitted sentinel values never leave this
 * process except inside its own assertions: probes carry booleans, never
 * values.
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

// User administration lives in an admin include that a plain wp-load does
// not load — the precedent of the capabilities and submission children.
require_once ABSPATH . 'wp-admin/includes/user.php';

require_once dirname( __DIR__ ) . '/wordpress/plugins/agentshell-forms/agentshell-forms.php';

$tools_dir = dirname( __DIR__ ) . '/wordpress/plugins/agentshell-forms/includes/tools/';
require_once $tools_dir . 'class-base-tool.php';
foreach ( array(
	'class-list-forms.php',
	'class-get-form.php',
	'class-create-form.php',
	'class-update-form.php',
	'class-validate-form.php',
	'class-publish-form.php',
	'class-discard-form-changes.php',
	'class-list-submissions.php',
	'class-get-submission.php',
	'class-export-submissions.php',
	'class-trash-submission.php',
	'class-delete-submission.php',
) as $file ) {
	require_once $tools_dir . $file;
}

use AgentShell_Forms\Form_Admin;
use AgentShell_Forms\Form_Audit;
use AgentShell_Forms\Form_Capabilities;
use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Policy;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Tools;
use AgentShell_Forms\Submission_Store;

global $wpdb;

$token = 'ASpd' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array( 'posts' => array(), 'users' => array() );

function probe( $name, array $data ) {
	global $f;
	$f['probes'][ $name ] = $data;
}

wp_set_current_user( 1 );

// The ring is shared with every suite that ran before us. Clear it first so
// the audit assertions below are deterministic — the precedent of the other
// forms suites, which isolate their own audit chain the same way.
Form_Audit::clear();

/* ── originals, to restore ─────────────────────────────────────────────── */

$policy_had      = array_key_exists( Form_Policy::OPTION, wp_load_alloptions() ) || null !== get_option( Form_Policy::OPTION, null );
$policy_original = get_option( Form_Policy::OPTION, null );
$secret_had      = array_key_exists( 'agentshell_forms_turnstile_secret', wp_load_alloptions() ) || null !== get_option( 'agentshell_forms_turnstile_secret', null );
$secret_original = get_option( 'agentshell_forms_turnstile_secret', null );

/* ── principals ────────────────────────────────────────────────────────── */

function pd_user( $tag, $role ) {
	global $token, $mine;
	$login = strtolower( $tag . '-' . $token );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => $login . '@example.invalid',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	$mine['users'][] = (int) $id;
	return (int) $id;
}

$b = pd_user( 'pdb', 'administrator' );
$d = pd_user( 'pdd', 'administrator' );
$c = pd_user( 'pdc', 'subscriber' );

$cu = $c > 0 ? get_user_by( 'id', $c ) : null;
if ( $cu instanceof WP_User ) {
	$cu->add_cap( Form_Capabilities::CAP_FORMS, true );
}
Form_Capabilities::grant_submissions( $c, 1 );
Form_Capabilities::grant_submissions( $d, 1 );

probe(
	'principals',
	array(
		'b'                => $b,
		'c'                => $c,
		'd'                => $d,
		'b_manage_options' => $b > 0 && user_can( $b, 'manage_options' ),
		'b_form_cap'       => Form_Capabilities::can_manage_forms( $b ),
		'b_sub_cap'        => Form_Capabilities::can_access_submissions( $b ),
		'c_manage_options' => $c > 0 && user_can( $c, 'manage_options' ),
		'c_form_cap'       => Form_Capabilities::can_manage_forms( $c ),
		'c_sub_cap'        => Form_Capabilities::can_access_submissions( $c ),
		'd_sub_cap'        => Form_Capabilities::can_access_submissions( $d ),
		'd_can_delete_any' => $d > 0 && user_can( $d, 'delete_posts' ),
	)
);

/* ── form and submissions ──────────────────────────────────────────────── */

$slug = 'pd-' . strtolower( substr( md5( $token ), 0, 10 ) );

$created = Form_Definition::create(
	array(
		'name'   => 'Personal data ' . $token,
		'slug'   => $slug,
		'fields' => array(
			array(
				'key'      => 'name',
				'type'     => 'text',
				'label'    => 'Name',
				'required' => true,
				'settings' => array( 'max_length' => 120 ),
				'options'  => array(),
			),
			array(
				'key'      => 'email',
				'type'     => 'email',
				'label'    => 'Email',
				'required' => false,
				'settings' => array(),
				'options'  => array(),
			),
		),
	),
	1
);

if ( is_wp_error( $created ) ) {
	Form_Capabilities::revoke_submissions( $c, 0 );
	Form_Capabilities::revoke_submissions( $d, 0 );
	foreach ( $mine['users'] as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	probe( 'fatal', array( 'where' => 'create', 'message' => $created->get_error_message() ) );
	file_put_contents( $report, wp_json_encode( $f ) );
	echo "reported\n";
	exit( 0 );
}

$mine['posts'][] = (int) $created['post_id'];
$form_id         = (string) $created['form_id'];
$revision        = (int) $created['revision'];

$published = Form_Definition::publish( $form_id, $revision, 1 );
$version   = is_wp_error( $published ) ? 0 : (int) $published['version'];

$sentinel_name  = 'Sentinel Name ' . $token;
$sentinel_email = 'sentinel-' . strtolower( $token ) . '@example.invalid';

function pd_submit( $form_id, $version, $name, $email ) {
	$entry = Submission_Store::create(
		array(
			'form_id'      => (string) $form_id,
			'form_version' => (int) $version,
			'form_name'    => 'Personal data',
			'fields'       => array( 'name' => (string) $name, 'email' => (string) $email ),
			'spam_status'  => 'clean',
			'meta'         => array( 'received_at' => gmdate( 'c' ) ),
			'challenge'    => '',
		)
	);
	return is_wp_error( $entry ) ? 0 : (int) ( $entry['post_id'] ?? 0 );
}

$s1 = pd_submit( $form_id, $version, $sentinel_name, $sentinel_email );
$s2 = pd_submit( $form_id, $version, 'Second ' . $token, 'second-' . strtolower( $token ) . '@example.invalid' );

probe( 'fixtures', array( 'form' => $form_id, 's1' => $s1, 's2' => $s2, 'version' => $version ) );

function tool( $name ) {
	static $map = array(
		'agentshell_list_forms'           => 'AgentShell_Forms\\Tools\\List_Forms',
		'agentshell_get_form'             => 'AgentShell_Forms\\Tools\\Get_Form',
		'agentshell_create_form'          => 'AgentShell_Forms\\Tools\\Create_Form',
		'agentshell_update_form'          => 'AgentShell_Forms\\Tools\\Update_Form',
		'agentshell_validate_form'        => 'AgentShell_Forms\\Tools\\Validate_Form',
		'agentshell_publish_form'         => 'AgentShell_Forms\\Tools\\Publish_Form',
		'agentshell_discard_form_changes' => 'AgentShell_Forms\\Tools\\Discard_Form_Changes',
		'agentshell_list_submissions'     => 'AgentShell_Forms\\Tools\\List_Submissions',
		'agentshell_get_submission'       => 'AgentShell_Forms\\Tools\\Get_Submission',
		'agentshell_export_submissions'   => 'AgentShell_Forms\\Tools\\Export_Submissions',
		'agentshell_trash_submission'     => 'AgentShell_Forms\\Tools\\Trash_Submission',
		'agentshell_delete_submission'    => 'AgentShell_Forms\\Tools\\Delete_Submission',
	);
	$class = $map[ $name ];
	return new $class();
}

function code_of( $result ) {
	return is_array( $result ) && array_key_exists( 'ok', $result )
		? ( $result['ok'] ? 'OK' : (string) ( $result['code'] ?? 'no-code' ) )
		: 'not-a-result';
}

/* ── A. default off: four refusals, metadata still available ───────────── */

delete_option( Form_Policy::OPTION );
wp_set_current_user( $b );

$off = array();
foreach ( array(
	'agentshell_get_submission'     => array( 'submission_id' => $s1, 'authorize_personal_data' => true ),
	'agentshell_export_submissions' => array( 'form_id' => $form_id, 'authorize_personal_data' => true ),
	'agentshell_trash_submission'   => array( 'submission_id' => $s1, 'authorize_personal_data' => true, 'confirm' => true ),
	'agentshell_delete_submission'  => array( 'submission_id' => $s1, 'authorize_personal_data' => true, 'confirm' => true ),
) as $name => $args ) {
	$r = tool( $name )->execute( $args );
	$off[ $name ] = array(
		'code'   => code_of( $r ),
		'policy' => is_array( $r ) ? ( $r['policy'] ?? null ) : null,
	);
}

$list_forms = tool( 'agentshell_list_forms' )->execute( array() );
$list_subs  = tool( 'agentshell_list_submissions' )->execute( array( 'form_id' => $form_id ) );
$list_json  = wp_json_encode( $list_subs );

$list_meta_ok = is_array( $list_subs ) && true === ( $list_subs['ok'] ?? false );
$list_rows    = $list_meta_ok ? (array) ( $list_subs['submissions'] ?? array() ) : array();
$list_pure    = true;
foreach ( $list_rows as $row ) {
	if ( ! is_array( $row ) || array_key_exists( 'values', $row ) ) {
		$list_pure = false;
		break;
	}
}

$s1_intact = ! is_wp_error( Submission_Store::get( $s1, false ) );

probe(
	'off_policy',
	array(
		'refusals'        => $off,
		'list_forms_ok'   => is_array( $list_forms ) && true === ( $list_forms['ok'] ?? false ),
		'list_subs_ok'    => $list_meta_ok,
		'list_count'      => count( $list_rows ),
		'list_pure'       => $list_pure,
		'list_leaks_name' => false !== strpos( (string) $list_json, $sentinel_name ),
		's1_intact'       => $s1_intact,
	)
);

/* ── B. capability policy, admin without the submissions cap ────────────── */

update_option( Form_Policy::OPTION, Form_Policy::CAPABILITY );
wp_set_current_user( $b );

$capref = array();
foreach ( array(
	'agentshell_get_submission'     => array( 'submission_id' => $s1, 'authorize_personal_data' => true ),
	'agentshell_export_submissions' => array( 'form_id' => $form_id, 'authorize_personal_data' => true ),
	'agentshell_trash_submission'   => array( 'submission_id' => $s1, 'authorize_personal_data' => true, 'confirm' => true ),
	'agentshell_delete_submission'  => array( 'submission_id' => $s1, 'authorize_personal_data' => true, 'confirm' => true ),
) as $name => $args ) {
	$r = tool( $name )->execute( $args );
	$capref[ $name ] = code_of( $r );
}

probe(
	'capability_refusals',
	array(
		'refusals'            => $capref,
		'b_manage_options'    => user_can( $b, 'manage_options' ),
		'b_sub_cap'           => Form_Capabilities::can_access_submissions( $b ),
		's1_intact'           => ! is_wp_error( Submission_Store::get( $s1, false ) ),
	)
);

/* ── C. acknowledgement and confirmation, as the capable subscriber ─────── */

wp_set_current_user( $c );

$noack = tool( 'agentshell_get_submission' )->execute( array( 'submission_id' => $s1 ) );
$nocon = tool( 'agentshell_trash_submission' )->execute( array( 'submission_id' => $s1, 'authorize_personal_data' => true ) );
$cread = tool( 'agentshell_get_submission' )->execute( array( 'submission_id' => $s1, 'authorize_personal_data' => true ) );

probe(
	'gates',
	array(
		'noack'            => code_of( $noack ),
		'noconfirm'        => code_of( $nocon ),
		'c_read_ok'        => is_array( $cread ) && true === ( $cread['ok'] ?? false ),
		'c_read_name'      => is_array( $cread ) && isset( $cread['submission']['values']['name'] ) ? (string) $cread['submission']['values']['name'] === $sentinel_name : false,
		'c_delete'         => code_of( tool( 'agentshell_delete_submission' )->execute( array( 'submission_id' => $s2, 'authorize_personal_data' => true, 'confirm' => true ) ) ),
		's2_intact'        => ! is_wp_error( Submission_Store::get( $s2, false ) ),
	)
);

/* ── D. the fully authorised administrator ─────────────────────────────── */

wp_set_current_user( $d );

$dget = tool( 'agentshell_get_submission' )->execute( array( 'submission_id' => $s1, 'authorize_personal_data' => true ) );
$dexp = tool( 'agentshell_export_submissions' )->execute( array( 'form_id' => $form_id, 'format' => 'csv', 'authorize_personal_data' => true ) );
$dexj = tool( 'agentshell_export_submissions' )->execute( array( 'form_id' => $form_id, 'format' => 'json', 'authorize_personal_data' => true ) );

$dget_ok    = is_array( $dget ) && true === ( $dget['ok'] ?? false );
$dexp_ok    = is_array( $dexp ) && true === ( $dexp['ok'] ?? false );
$dexp_body  = $dexp_ok ? (string) ( $dexp['content'] ?? '' ) : '';
$dexj_ok    = is_array( $dexj ) && true === ( $dexj['ok'] ?? false );
$dexj_count = $dexj_ok ? (int) ( $dexj['count'] ?? -1 ) : -1;

probe(
	'authorised',
	array(
		'get_ok'          => $dget_ok,
		'get_values'      => $dget_ok && $sentinel_name === (string) ( $dget['submission']['values']['name'] ?? null ) && $sentinel_email === (string) ( $dget['submission']['values']['email'] ?? null ),
		'export_ok'       => $dexp_ok,
		'export_count'    => $dexp_ok ? (int) ( $dexp['count'] ?? -1 ) : -1,
		'export_has_name' => $dexp_ok && false !== strpos( $dexp_body, $sentinel_name ),
		'export_json_ok'  => $dexj_ok,
		'export_json_n'   => $dexj_count,
	)
);

/* ── E. form tools end to end, and the compare-and-swap races ───────────── */

wp_set_current_user( $b );

$tcreate = tool( 'agentshell_create_form' )->execute( array( 'name' => 'Second ' . $token ) );
$tcreate_ok   = is_array( $tcreate ) && true === ( $tcreate['ok'] ?? false );
$tcreate_code = is_array( $tcreate ) ? (string) ( $tcreate['code'] ?? '' ) : 'not-a-result';
$tcreate_msg  = is_array( $tcreate ) ? (string) ( $tcreate['message'] ?? '' ) : '';
$tform        = $tcreate_ok ? (string) ( $tcreate['form']['form_id'] ?? '' ) : '';
$tform_post   = $tcreate_ok ? (int) ( $tcreate['form']['post_id'] ?? 0 ) : 0;
if ( $tform_post > 0 ) {
	$mine['posts'][] = $tform_post;
}

$tget = $tform ? tool( 'agentshell_get_form' )->execute( array( 'form_id' => $tform ) ) : array();
$tget_ok = is_array( $tget ) && true === ( $tget['ok'] ?? false );

$main_state = Form_Definition::get( $form_id );
$main_rev   = is_wp_error( $main_state ) ? -1 : (int) $main_state['revision'];

$stale_update = tool( 'agentshell_update_form' )->execute(
	array(
		'form_id'           => $form_id,
		'expected_revision' => $main_rev + 99,
		'definition'        => array(),
	)
);

$work = is_wp_error( $main_state ) || ! is_array( $main_state['working'] ) ? array() : $main_state['working'];
$work['description'] = 'Edited by the tool ' . $token;
$cur_update = tool( 'agentshell_update_form' )->execute(
	array(
		'form_id'           => $form_id,
		'expected_revision' => $main_rev,
		'definition'        => $work,
	)
);
$cur_update_ok  = is_array( $cur_update ) && true === ( $cur_update['ok'] ?? false );
$cur_update_rev = $cur_update_ok ? (int) ( $cur_update['form']['revision'] ?? -1 ) : -1;

$stale_publish = tool( 'agentshell_publish_form' )->execute( array( 'form_id' => $form_id, 'expected_revision' => $main_rev ) );

$after_update = Form_Definition::get( $form_id );
$after_rev    = is_wp_error( $after_update ) ? -1 : (int) $after_update['revision'];
$cur_publish  = tool( 'agentshell_publish_form' )->execute( array( 'form_id' => $form_id, 'expected_revision' => $after_rev ) );
$cur_publish_ok = is_array( $cur_publish ) && true === ( $cur_publish['ok'] ?? false );

$after_publish = Form_Definition::get( $form_id );
$discard_target_rev = is_wp_error( $after_publish ) ? -1 : (int) $after_publish['revision'];
$stale_discard = tool( 'agentshell_discard_form_changes' )->execute( array( 'form_id' => $form_id, 'expected_revision' => $discard_target_rev + 7 ) );
$cur_discard   = tool( 'agentshell_discard_form_changes' )->execute( array( 'form_id' => $form_id, 'expected_revision' => $discard_target_rev ) );
$cur_discard_ok = is_array( $cur_discard ) && true === ( $cur_discard['ok'] ?? false );

$vbad  = tool( 'agentshell_validate_form' )->execute( array( 'definition' => array( 'bogus_key' => 1, 'fields' => array() ) ) );
$vgood = $tform ? tool( 'agentshell_validate_form' )->execute( array( 'form_id' => $tform, 'definition' => is_array( $tget ) && isset( $tget['working'] ) ? $tget['working'] : array() ) ) : array();

probe(
	'form_tools',
	array(
		'create_ok'        => $tcreate_ok,
		'create_code'      => $tcreate_code,
		'create_msg'       => $tcreate_msg,
		'get_ok'           => $tget_ok,
		'get_valid'        => $tget_ok && true === ( $tget['validation']['ok'] ?? false ),
		'stale_update'     => code_of( $stale_update ),
		'stale_mismatch'   => isset( $stale_update['expected_revision'], $stale_update['actual_revision'] ),
		'current_update'   => $cur_update_ok,
		'rev_advanced'     => $cur_update_rev > $main_rev,
		'stale_publish'    => code_of( $stale_publish ),
		'current_publish'  => $cur_publish_ok,
		'stale_discard'    => code_of( $stale_discard ),
		'current_discard'  => $cur_discard_ok,
		'validate_bad_ok'  => is_array( $vbad ) && false === ( $vbad['ok'] ?? true ) && ! empty( $vbad['errors'] ),
		'validate_good_ok' => is_array( $vgood ) && true === ( $vgood['ok'] ?? false ),
	)
);

/* ── F. destructive tools, fully authorised ─────────────────────────────── */

wp_set_current_user( $d );

$trash = tool( 'agentshell_trash_submission' )->execute( array( 'submission_id' => $s1, 'authorize_personal_data' => true, 'confirm' => true ) );
$trash_ok = is_array( $trash ) && true === ( $trash['ok'] ?? false );
$trash_status = '';
$after_trash = Submission_Store::get( $s1, false );
if ( ! is_wp_error( $after_trash ) ) {
	$trash_status = (string) ( $after_trash['status'] ?? '' );
}

$delete = tool( 'agentshell_delete_submission' )->execute( array( 'submission_id' => $s1, 'authorize_personal_data' => true, 'confirm' => true ) );
$delete_ok = is_array( $delete ) && true === ( $delete['ok'] ?? false );
$gone = Submission_Store::get( $s1, false );
$gone_code = is_wp_error( $gone ) ? $gone->get_error_code() : 'still-there';

probe(
	'destructive',
	array(
		'trash_ok'     => $trash_ok,
		'trash_status' => $trash_status,
		'delete_ok'    => $delete_ok,
		'gone_code'    => $gone_code,
	)
);

/* ── G. secrets never appear in tool results ────────────────────────────── */

update_option( 'agentshell_forms_turnstile_secret', 'sk-live-sentinel-' . $token );
wp_set_current_user( $d );

$g2  = tool( 'agentshell_get_submission' )->execute( array( 'submission_id' => $s2, 'authorize_personal_data' => true ) );
$l2  = tool( 'agentshell_list_submissions' )->execute( array( 'form_id' => $form_id ) );
$e2  = tool( 'agentshell_export_submissions' )->execute( array( 'form_id' => $form_id, 'authorize_personal_data' => true ) );
$gf2 = tool( 'agentshell_get_form' )->execute( array( 'form_id' => $form_id ) );
$lf2 = tool( 'agentshell_list_forms' )->execute( array() );

$combined = wp_json_encode( array( $g2, $l2, $e2, $gf2, $lf2 ) );

probe(
	'secrets',
	array(
		'leaks' => false !== strpos( (string) $combined, 'sk-live-sentinel-' . $token ),
	)
);

/* ── H. audit rows and the unchanged authority ──────────────────────────── */

$events = array();
foreach ( Form_Audit::entries() as $entry ) {
	if ( ! is_array( $entry ) ) {
		continue;
	}
	$events[] = array(
		'event'      => (string) ( $entry['event'] ?? '' ),
		'form_id'    => (string) ( $entry['data']['form_id'] ?? '' ),
		'submission' => (int) ( $entry['data']['submission'] ?? 0 ),
		'tool'       => (string) ( $entry['data']['tool'] ?? '' ),
		'reason'     => (string) ( $entry['data']['reason'] ?? '' ),
		'data_json'  => wp_json_encode( $entry['data'] ),
	);
}

function audit_has( $events, $event, $key, $value ) {
	foreach ( $events as $e ) {
		if ( $e['event'] === $event && (string) ( $e[ $key ] ?? '' ) === (string) $value ) {
			return true;
		}
	}
	return false;
}

$refused_rows = 0;
$refused_reasons = array();
foreach ( $events as $e ) {
	if ( 'form_submission_access_refused' !== $e['event'] ) {
		continue;
	}
	// The ring is shared with every suite that ran before us: a refusal is
	// ours only when it names our form or one of our submissions.
	$ours = ( '' !== $e['form_id'] && $e['form_id'] === $form_id )
		|| in_array( $e['submission'], array( $s1, $s2 ), true );
	if ( ! $ours ) {
		continue;
	}
	$refused_rows++;
	$refused_reasons[ $e['reason'] ] = true;
}

$admin_role = get_role( 'administrator' );

probe(
	'audit',
	array(
		'created'        => audit_has( $events, 'form_created', 'form_id', $form_id ),
		'updated'        => audit_has( $events, 'form_updated', 'form_id', $form_id ),
		'published'      => audit_has( $events, 'form_published', 'form_id', $form_id ),
		'discarded'      => audit_has( $events, 'form_discarded', 'form_id', $form_id ),
		'inspected'      => audit_has( $events, 'form_submission_inspected', 'submission', $s1 ),
		'exported'       => audit_has( $events, 'form_submission_exported', 'form_id', $form_id ),
		'trashed'        => audit_has( $events, 'form_submission_trashed', 'submission', $s1 ),
		'deleted'        => audit_has( $events, 'form_submission_deleted', 'submission', $s1 ),
		'refused_rows'   => $refused_rows,
		'refused_off'    => isset( $refused_reasons['policy_off'] ),
		'refused_cap'    => isset( $refused_reasons['missing_submissions_capability'] ),
		'refused_ack'    => isset( $refused_reasons['missing_acknowledgement'] ),
		'refused_con'    => isset( $refused_reasons['missing_confirmation'] ),
		'refused_forbid' => isset( $refused_reasons['form_submission_delete_forbidden'] ),
		'values_in_audit' => ( function () use ( $events, $sentinel_name ) {
			foreach ( $events as $e ) {
				if ( false !== strpos( (string) $e['data_json'], $sentinel_name ) ) {
					return true;
				}
			}
			return false;
		} )(),
		'b_still_clean'  => ! Form_Capabilities::can_access_submissions( $b ),
		'role_clean'     => $admin_role instanceof WP_Role ? ! $admin_role->has_cap( Form_Capabilities::CAP_SUBMISSIONS ) : false,
		'policy_kept'    => Form_Policy::CAPABILITY === get_option( Form_Policy::OPTION ),
	)
);

/* ── cleanup ────────────────────────────────────────────────────────────── */

foreach ( array( $s1, $s2 ) as $sid ) {
	if ( $sid > 0 ) {
		Submission_Store::delete_permanently( (int) $sid, 0, true );
	}
}

foreach ( $mine['posts'] as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

Form_Capabilities::revoke_submissions( $c, 0 );
Form_Capabilities::revoke_submissions( $d, 0 );

foreach ( $mine['users'] as $user_id ) {
	wp_delete_user( (int) $user_id );
}

if ( $policy_had ) {
	update_option( Form_Policy::OPTION, $policy_original );
} else {
	delete_option( Form_Policy::OPTION );
}

if ( $secret_had ) {
	update_option( 'agentshell_forms_turnstile_secret', $secret_original );
} else {
	delete_option( 'agentshell_forms_turnstile_secret' );
}

$gone_posts = 0;
foreach ( $mine['posts'] as $post_id ) {
	if ( null === get_post( (int) $post_id ) ) {
		$gone_posts++;
	}
}
$gone_entries = 0;
foreach ( array( $s1, $s2 ) as $sid ) {
	if ( $sid > 0 && is_wp_error( Submission_Store::get( (int) $sid, false ) ) ) {
		$gone_entries++;
	}
}

probe(
	'cleanup',
	array(
		'tracked_posts'    => count( $mine['posts'] ),
		'tracked_gone'     => $gone_posts,
		'tracked_users'    => count( $mine['users'] ),
		'users_gone'       => count(
			array_filter(
				$mine['users'],
				function ( $user_id ) {
					return false === get_user_by( 'id', (int) $user_id );
				}
			)
		),
		'entries_gone'     => $gone_entries,
		'policy_restored'  => $policy_had ? get_option( Form_Policy::OPTION, null ) === $policy_original : false === get_option( Form_Policy::OPTION, false ),
		'secret_restored'  => $secret_had ? get_option( 'agentshell_forms_turnstile_secret', null ) === $secret_original : false === get_option( 'agentshell_forms_turnstile_secret', false ),
		'c_cap_revoked'    => ! Form_Capabilities::can_access_submissions( $c ),
		'd_cap_revoked'    => ! Form_Capabilities::can_access_submissions( $d ),
	)
);

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
