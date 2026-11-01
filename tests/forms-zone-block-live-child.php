<?php
/**
 * Child half of the zone-block live suite: a published form, placed in a real
 * zone, rendered by the real theme renderer inside a real WordPress.
 *
 * argv: 1 WordPress root  2 WordPress theme directory  3 report path
 *
 * The unit suite proves the *contract* — the six-type vocabulary, the id
 * rules, the delegation and the `function_exists()` guard. This child proves
 * the *behaviour*: the form a visitor would actually receive, from the block
 * an operator actually saved.
 *
 * Everything it creates carries a per-run marker and is deleted before the
 * child exits.
 */

$wp_root    = $argv[1] ?? '';
$theme_dir  = $argv[2] ?? '';
$report     = $argv[3] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

require_once dirname( __DIR__ ) . '/agentshell-forms/agentshell-forms.php';

// The theme's renderer is the subject, so its two files are loaded directly
// rather than by switching the active theme: a test that changes the site's
// theme would leave the bed in a different state than it found it.
require_once rtrim( $theme_dir, '/' ) . '/functions.php';
require_once rtrim( $theme_dir, '/' ) . '/template-parts/shell-render.php';

use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Policy;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Schema;
use AgentShell_Forms\Submission_Store;

$token = 'ASz6' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
$f     = array( 'token' => $token, 'probes' => array() );
$mine  = array( 'posts' => array() );

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

wp_set_current_user( 1 );

/* ── fixtures: one published form, one draft ─────────────────────────────── */

/**
 * Create a form with one text field.
 *
 * @param string $name   Name.
 * @param string $suffix Slug suffix.
 * @return array|\WP_Error
 */
function zl_form( $name, $suffix ) {
	global $token;

	$definition = Form_Schema::defaults(
		Form_Definition::new_form_id(),
		'zl-' . $suffix . '-' . strtolower( substr( md5( $token . $suffix ), 0, 8 ) ),
		$name
	);

	$definition['fields'] = array(
		array(
			'key'      => 'message',
			'type'     => 'textarea',
			'label'    => 'Message',
			'required' => false,
			'settings' => array( 'rows' => 3, 'max_length' => 500, 'min_length' => 0 ),
			'options'  => array(),
		),
	);

	return Form_Definition::create(
		array(
			'name'       => $name . ' ' . $token,
			'slug'       => $definition['slug'],
			'definition' => $definition,
		),
		1
	);
}

$published = zl_form( 'Zone published', 'pub' );
$draft     = zl_form( 'Zone draft', 'draft' );

if ( is_wp_error( $published ) || is_wp_error( $draft ) ) {
	probe( 'fatal', array( 'where' => 'create', 'message' => is_wp_error( $published ) ? $published->get_error_message() : $draft->get_error_message() ) );
	file_put_contents( $report, wp_json_encode( $f ) );
	echo "reported\n";
	exit( 0 );
}

$mine['posts'][] = (int) $published['post_id'];
$mine['posts'][] = (int) $draft['post_id'];

$published_id = (string) $published['form_id'];
$draft_id     = (string) $draft['form_id'];

$promoted = Form_Definition::publish( $published_id, (int) $published['revision'], 1 );

probe(
	'fixtures',
	array(
		'published_ok'   => ! is_wp_error( $promoted ),
		'published_id'   => $published_id,
		'draft_id'       => $draft_id,
		'published_slug' => (string) $published['slug'],
	)
);

/* ── 1. A published form renders through the zone block ──────────────────── */

$block = array( 'type' => 'agentshell_form', 'id' => $published_id );

$slot_html = agentshell_render_zone(
	array(
		'id'    => 'header',
		'slots' => array( 'bottom-right' => array( $block ) ),
	)
);

$composition_html = agentshell_render_zone(
	array(
		'id'          => 'main',
		'composition' => array(
			array( 'type' => 'wp_loop' ),
			$block,
		),
	)
);

probe(
	'renders',
	array(
		// The forms renderer emits a form element with its own class and the
		// form's id in it. Asserting on the class and the id is asserting on
		// the plugin's markup, which is what "renders through the plugin"
		// means.
		'slot_has_form'        => false !== strpos( $slot_html, '<form' ),
		'slot_has_class'       => false !== strpos( $slot_html, 'agentshell-form' ),
		'slot_has_field'       => false !== strpos( $slot_html, 'message' ),
		'slot_cell_is_bottom_right' => false !== strpos( $slot_html, 'data-slot="bottom-right"' ),
		// Nine cells still, and the form in exactly one of them.
		'slot_cell_count'      => substr_count( $slot_html, 'class="zone-slot' ),
		'slot_form_occurrences' => substr_count( $slot_html, 'agentshell-form__' ) > 0 ? substr_count( $slot_html, '<form' ) : substr_count( $slot_html, '<form' ),
		'composition_has_form' => false !== strpos( $composition_html, '<form' ),
		'avoid_double_escape'  => false === strpos( $slot_html, '&lt;form' ),
		// The page must not carry a second, theme-authored copy of the form.
		'no_theme_form_markup' => false === strpos( $slot_html, 'as-zone-form' ),
	)
);

/* ── 2. Unpublished, unknown and deleted forms degrade safely ─────────────
 *
 * Two viewers, because the renderer deliberately answers them differently:
 *
 *   - a **visitor** gets nothing at all, so a missing form is not an
 *     information leak and not a visible error;
 *   - a **form manager** gets a short diagnostic instead — the editor-only
 *     behaviour the design requires, so an operator can see why a placement
 *     is blank.
 *
 * Neither may receive a `<form>`: a broken reference must never render a
 * half-built form a visitor could submit.
 */

$block_for = function ( $id ) {
	return array( 'type' => 'agentshell_form', 'id' => $id );
};

// A trashed form: trashing the record must not leave a rendering.
wp_trash_post( (int) $draft['post_id'] );

$visitor = array();
$manager = array();

foreach ( array(
	'draft'   => $draft_id,
	'unknown' => 'ffffffffffffffffffffffffffffffff',
	'post_id' => (string) $published['post_id'],
	'trashed' => $draft_id,
	'empty'   => '',
) as $label => $reference ) {
	wp_set_current_user( 0 );
	$visitor[ $label ] = (string) agentshell_render_block( $block_for( $reference ) );

	wp_set_current_user( 1 );
	$manager[ $label ] = (string) agentshell_render_block( $block_for( $reference ) );
}

wp_set_current_user( 1 );

$visitor_clean = true;
foreach ( $visitor as $html ) {
	if ( '' !== trim( $html ) ) {
		$visitor_clean = false;
	}
}

$manager_diagnostic = true;
$manager_no_form    = true;
foreach ( $manager as $html ) {
	if ( '' !== trim( $html ) && false === strpos( $html, 'data-agentshell-forms-diagnostic' ) ) {
		$manager_diagnostic = false;
	}
	if ( false !== strpos( $html, '<form' ) ) {
		$manager_no_form = false;
	}
}

probe(
	'degradation',
	array(
		// The visitor sees nothing, for every kind of unusable reference.
		'visitor_empty'       => $visitor_clean,
		// The manager sees why, and never a form.
		'manager_diagnostic'  => $manager_diagnostic,
		'manager_no_form'     => $manager_no_form,
		'draft_visitor_empty' => '' === trim( $visitor['draft'] ),
		'trashed_visitor_empty' => '' === trim( $visitor['trashed'] ),
		// A post id is not an internal id. It resolves to nothing rather than
		// to whichever form happens to own that row.
		'post_id_visitor_empty' => '' === trim( $visitor['post_id'] ),
		'empty_id_empty'      => '' === trim( $visitor['empty'] ) && '' === trim( $manager['empty'] ),
	)
);

/* ── 3. The catalog lists published forms only ───────────────────────────── */

$catalog = agentshell_forms_published_catalog();

$catalog_ids = array();
$catalog_keys = array();
foreach ( (array) $catalog as $entry ) {
	$catalog_ids[] = (string) ( $entry['form_id'] ?? '' );
	$catalog_keys = array_keys( $entry );
}

$entry_for_published = null;
foreach ( (array) $catalog as $entry ) {
	if ( (string) ( $entry['form_id'] ?? '' ) === $published_id ) {
		$entry_for_published = $entry;
	}
}

probe(
	'catalog',
	array(
		'has_published'   => in_array( $published_id, $catalog_ids, true ),
		'excludes_draft'  => ! in_array( $draft_id, $catalog_ids, true ),
		'keys'            => $catalog_keys,
		'name'            => is_array( $entry_for_published ) ? (string) $entry_for_published['name'] : '',
		'slug'            => is_array( $entry_for_published ) ? (string) $entry_for_published['slug'] : '',
		// Nothing private: the catalog is identifiers and labels only.
		'no_values_key'   => false === strpos( (string) wp_json_encode( $catalog ), '"values"' ),
		'no_settings_key' => false === strpos( (string) wp_json_encode( $catalog ), '"settings"' ),
		'no_secret'       => false === strpos( (string) wp_json_encode( $catalog ), 'turnstile' ),
	)
);

/* ── 4. The configuration response carries the catalog and nothing more ──── */

$response = agentshell_build_config_response( array( 'zones' => array() ) );

$forms_in_response = $response['available_forms'] ?? null;
$ids_in_response   = array();
foreach ( (array) $forms_in_response as $entry ) {
	$ids_in_response[] = (string) ( $entry['id'] ?? '' );
}

$response_json = (string) wp_json_encode( $response );

probe(
	'config_response',
	array(
		'flag'          => $response['forms_available'] ?? null,
		'has_published' => in_array( $published_id, $ids_in_response, true ),
		'excludes_draft' => ! in_array( $draft_id, $ids_in_response, true ),
		'entry_keys'    => isset( $forms_in_response[0] ) ? array_keys( (array) $forms_in_response[0] ) : array(),
		'builder_url'   => (string) ( $response['forms_builder_url'] ?? '' ),
		'no_secret'     => false === strpos( $response_json, 'turnstile' ),
		'backward_compatible' => array_key_exists( 'available_widgets', $response )
			&& array_key_exists( 'core_components', $response )
			&& array_key_exists( 'zones', $response ),
	)
);

/* ── 5. The validator and the doctor accept the stored configuration ─────── */

$config = array(
	'zones' => array(
		array(
			'id'    => 'header',
			'slots' => array( 'top-left' => array( $block ) ),
		),
		array(
			'id'          => 'main',
			'composition' => array( $block ),
		),
		array(
			'id'    => 'footer',
			'slots' => array( 'bottom-center' => array( $block ) ),
		),
	),
);

$validated = agentshell_validate_config( $config );

$doctor_errors = null;
if ( class_exists( 'AgentShell_MCP\\Doctor' ) ) {
	$reflection = new ReflectionClass( 'AgentShell_MCP\\Doctor' );
	$method     = $reflection->getMethod( 'check_zones' );
	$method->setAccessible( true );
	$errors   = array();
	$warnings = array();
	$method->invokeArgs( null, array( $config, &$errors, &$warnings ) );
	$doctor_errors = array();
	foreach ( (array) $errors as $error ) {
		$doctor_errors[] = $error['code'] ?? '';
	}
}

probe(
	'validation',
	array(
		'validated'     => ! is_wp_error( $validated ),
		'validate_error' => is_wp_error( $validated ) ? $validated->get_error_message() : '',
		'doctor_ok'     => null === $doctor_errors || array() === $doctor_errors,
		'doctor_errors' => $doctor_errors,
	)
);

/* ── 6. A stored reference that stops resolving degrades, not breaks ─────── */

// Publish, then trash the published record: the block is unchanged and the
// zone renders nothing rather than an error.
wp_trash_post( (int) $published['post_id'] );

$after_trash = agentshell_render_zone(
	array(
		'id'    => 'footer',
		'slots' => array( 'bottom-center' => array( $block ) ),
	)
);

probe(
	'stale_reference',
	array(
		'fatal_free'  => true,
		'empty_form'  => false === strpos( $after_trash, '<form' ),
		'cells_intact' => 9 === substr_count( $after_trash, 'class="zone-slot' ),
	)
);

/* ── cleanup ────────────────────────────────────────────────────────────── */

foreach ( $mine['posts'] as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

$remaining = 0;
foreach ( $mine['posts'] as $post_id ) {
	if ( null !== get_post( (int) $post_id ) ) {
		$remaining++;
	}
}

$catalog_after = agentshell_forms_published_catalog();
$leaked_after  = 0;
foreach ( (array) $catalog_after as $entry ) {
	if ( false !== strpos( (string) ( $entry['name'] ?? '' ), $token ) ) {
		$leaked_after++;
	}
}

probe(
	'cleanup',
	array(
		'posts_remaining'  => $remaining,
		'catalog_leaks'    => $leaked_after,
	)
);

file_put_contents( $report, wp_json_encode( $f ) );
echo "reported\n";
