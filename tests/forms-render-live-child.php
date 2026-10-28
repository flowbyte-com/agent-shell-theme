<?php
/**
 * Child half of the forms rendering suite. Runs inside a real WordPress and
 * reports facts as JSON; the parent asserts them.
 *
 * Everything it creates is disposable, carries a per-run marker, and is deleted
 * before the child exits.
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

use AgentShell_Forms\Form_Challenges;
use AgentShell_Forms\Form_Challenge_Endpoint;
use AgentShell_Forms\Form_Definition;
use AgentShell_Forms\Form_Install;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Rate_Limits;
use AgentShell_Forms\Form_Renderer;
use AgentShell_Forms\Form_Shortcode;
use AgentShell_Forms\Form_Widget;

global $wpdb;

$token = 'ASr1' . substr( md5( (string) ( time() . getmypid() . random_int( 1, 1000000000 ) ) ), 0, 8 );
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
    $mine[] = (int) $id;
    return (int) $id;
}

/**
 * Every `id` attribute in a document.
 *
 * @param string $html Markup.
 * @return array<int,string>
 */
function ids_in( $html ) {
    preg_match_all( '/\sid="([^"]*)"/', (string) $html, $m );
    return $m[1];
}

// ── the plugin's own state ──────────────────────────────────────────────────
Form_Install::activate();
Form_Install::maybe_upgrade();
Form_Post_Types::register();
Form_Shortcode::register();
Form_Challenge_Endpoint::register();
// `widgets_init` fired during `wp-load.php`, before this file existed, so the
// plugin's own hook never ran here. Registering the widget explicitly is what
// a normal request does for us.
\AgentShell_Forms\agentshell_forms_register_widget();

// The REST server must exist before a route is registered against it.
rest_get_server();

probe(
    'environment',
    array(
        'wp_version'      => get_bloginfo( 'version' ),
        'php_version'     => PHP_VERSION,
        'shortcode_added' => shortcode_exists( Form_Shortcode::TAG ),
        'widget_class'    => class_exists( 'AgentShell_Forms\\Form_Widget' ),
        'route_registered' => isset(
            rest_get_server()->get_routes()[ '/' . Form_Challenge_Endpoint::REST_NAMESPACE . Form_Challenge_Endpoint::ROUTE ]
        ),
    )
);

// ══ the definitions ═════════════════════════════════════════════════════════
// All ten field types, published. Built from the same fixture the unit suite
// and the browser harness use, so the three suites cannot disagree about what a
// form contains.

$definition = agentshell_render_live_definition( $token );

$created = Form_Definition::create(
    array(
        'name'       => 'Render probe ' . $token,
        'slug'       => 'render-probe-' . strtolower( $token ),
        'definition' => $definition,
    ),
    1
);

if ( is_wp_error( $created ) ) {
    probe( 'fatal', array( 'where' => 'create', 'code' => $created->get_error_code(), 'message' => $created->get_error_message() ) );
    file_put_contents( $report, wp_json_encode( $f ) );
    exit( 0 );
}

$form_a_id   = (string) $created['form_id'];
$form_a_post = track( (int) $created['post_id'] );
$slug_a      = (string) $created['slug'];

$published = Form_Definition::publish( $form_a_id, (int) $created['revision'], 1 );

if ( is_wp_error( $published ) ) {
    probe( 'fatal', array( 'where' => 'publish', 'code' => $published->get_error_code(), 'message' => $published->get_error_message() ) );
    file_put_contents( $report, wp_json_encode( $f ) );
    exit( 0 );
}

$second = Form_Definition::create(
    array(
        'name'       => 'Second probe ' . $token,
        'slug'       => 'second-probe-' . strtolower( $token ),
        'definition' => agentshell_render_live_second_definition(),
    ),
    1
);

if ( ! is_wp_error( $second ) ) {
    $second_published = Form_Definition::publish( (string) $second['form_id'], (int) $second['revision'], 1 );
    $form_b_id        = (string) $second['form_id'];
    $form_b_post      = track( (int) $second['post_id'] );
} else {
    $form_b_id   = '';
    $form_b_post = 0;
}

/**
 * The ten-field definition used by every renderer suite.
 *
 * @param string $token Per-run marker.
 * @return array
 */
function agentshell_render_live_definition( $token ) {
    return array(
        'description' => 'Probe form ' . $token,
        'fields'      => array(
            array(
                'key'      => 'full_name',
                'type'     => 'text',
                'label'    => 'Your name',
                'placeholder' => 'Ada Lovelace',
                'required' => true,
                'settings' => array( 'max_length' => 80, 'min_length' => 2, 'autocomplete' => 'name' ),
            ),
            array(
                'key'      => 'email',
                'type'     => 'email',
                'label'    => 'Email address',
                'required' => true,
                'settings' => array( 'max_length' => 254, 'autocomplete' => 'email' ),
            ),
            array(
                'key'      => 'phone',
                'type'     => 'tel',
                'label'    => 'Phone',
                'required' => false,
                'settings' => array( 'max_length' => 32, 'autocomplete' => 'tel' ),
            ),
            array(
                'key'      => 'budget',
                'type'     => 'number',
                'label'    => 'Budget',
                'required' => false,
                'settings' => array( 'min' => 1, 'max' => 250, 'decimals' => 1 ),
            ),
            array(
                'key'      => 'message',
                'type'     => 'textarea',
                'label'    => 'Message',
                'required' => true,
                'settings' => array( 'rows' => 5, 'max_length' => 2000, 'min_length' => 10 ),
            ),
            array(
                'key'      => 'topic',
                'type'     => 'select',
                'label'    => 'Topic',
                'required' => true,
                'options'  => array(
                    array( 'value' => 'new-project', 'label' => 'A new project' ),
                    array( 'value' => 'support', 'label' => 'Support' ),
                ),
                'settings' => array(),
            ),
            array(
                'key'      => 'contact_method',
                'type'     => 'radio',
                'label'    => 'How should we reply?',
                'required' => true,
                'options'  => array(
                    array( 'value' => 'email', 'label' => 'By email' ),
                    array( 'value' => 'phone', 'label' => 'By phone' ),
                ),
                'settings' => array(),
            ),
            array(
                'key'      => 'topics',
                'type'     => 'checkbox',
                'label'    => 'Topics that interest you',
                'required' => false,
                'options'  => array(
                    array( 'value' => 'design', 'label' => 'Design' ),
                    array( 'value' => 'development', 'label' => 'Development' ),
                ),
                'settings' => array(),
            ),
            array(
                'key'      => 'consent',
                'type'     => 'consent',
                'label'    => 'I agree to the privacy notice',
                'required' => true,
                'settings' => array( 'privacy_url' => 'https://example.test/privacy' ),
            ),
            array(
                'key'      => 'source',
                'type'     => 'hidden',
                'label'    => 'Source',
                'required' => false,
                'settings' => array( 'value' => 'render-probe' ),
            ),
        ),
        'settings'    => array( 'submit_label' => 'Send message' ),
    );
}

/**
 * A second form, deliberately different.
 *
 * @return array
 */
function agentshell_render_live_second_definition() {
    return array(
        'fields'   => array(
            array(
                'key'      => 'email',
                'type'     => 'email',
                'label'    => 'Your email',
                'required' => true,
                'settings' => array( 'max_length' => 254, 'autocomplete' => 'email' ),
            ),
        ),
        'settings' => array( 'submit_label' => 'Subscribe' ),
    );
}

// ══ the shortcode ══════════════════════════════════════════════════════════
$by_slug = do_shortcode( '[agentshell_form slug="' . $slug_a . '"]' );
$by_id   = do_shortcode( '[agentshell_form id="' . $form_a_id . '"]' );
$by_both = do_shortcode( '[agentshell_form id="' . $form_a_id . '" slug="' . $slug_a . '"]' );

probe(
    'shortcode_by_slug',
    array(
        'html'          => $by_slug,
        'has_form'      => false !== strpos( $by_slug, '<form' ),
        'has_form_id'   => false !== strpos( $by_slug, 'data-form-id="' . $form_a_id . '"' ),
        'field_types'   => agentshell_render_live_control_types( $by_slug ),
        'has_action'    => false !== strpos( $by_slug, 'action=' ),
        'submit_action' => preg_match( '/<form[^>]*\saction="([^"]*)"/', $by_slug, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '',
        'submit_endpoint' => preg_match( '/data-submit-endpoint="([^"]*)"/', $by_slug, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '',
        'submit_url'    => \AgentShell_Forms\Submission_Handler::submit_url(),
        'has_novalidate' => false !== strpos( $by_slug, 'novalidate' ),
        'challenge_empty' => (bool) preg_match( '/name="agentshell_form_challenge" value=""/', $by_slug ),
        'ids'           => ids_in( $by_slug ),
        'version'       => (int) ( Form_Definition::get_by_post_id( $form_a_post )['version'] ?? 0 ),
    )
);

probe(
    'shortcode_by_id',
    array(
        'identical_to_slug_render' => agentshell_render_live_normalise_ids( $by_id ) === agentshell_render_live_normalise_ids( $by_slug ),
        'has_form'                 => false !== strpos( $by_id, '<form' ),
    )
);

probe(
    'shortcode_by_both_agreeing',
    array( 'has_form' => false !== strpos( $by_both, '<form' ) )
);

/**
 * The control types present in a render, by field key.
 *
 * @param string $html Markup.
 * @return array<string,string>
 */
function agentshell_render_live_control_types( $html ) {
    $types = array();
    if ( preg_match_all( '/data-field-type="([a-z]+)"/', (string) $html, $m ) ) {
        foreach ( $m[1] as $type ) {
            $types[ $type ] = true;
        }
    }
    return array_keys( $types );
}

/**
 * A render with its per-request instance numbers normalised.
 *
 * Two renders of one version differ only in the instance counter, which is a
 * per-page decision and not part of the form's content.
 *
 * @param string $html Markup.
 * @return string
 */
function agentshell_render_live_normalise_ids( $html ) {
    $html = preg_replace( '/agentshell-form-([a-f0-9]{32})-\d+/', 'agentshell-form-$1-N', (string) $html );
    $html = preg_replace( '/data-form-instance="\d+"/', 'data-form-instance="N"', $html );
    $html = preg_replace( '/name="agentshell_form_instance" value="\d+"/', 'name="agentshell_form_instance" value="N"', $html );
    $html = preg_replace( '/agentshell-forms--instance-\d+/', 'agentshell-forms--instance-N', $html );

    return $html;
}

// Two instances of the same form on one page.
$twice = do_shortcode( '[agentshell_form slug="' . $slug_a . '"][agentshell_form slug="' . $slug_a . '"]' );
$twice_ids = ids_in( $twice );

probe(
    'two_instances_of_one_form',
    array(
        'form_count'   => substr_count( $twice, '<form' ),
        'id_count'     => count( $twice_ids ),
        'unique_ids'   => count( array_unique( $twice_ids ) ),
        'prefixes'     => agentshell_render_live_prefixes( $twice ),
        'names_kept'   => substr_count( $twice, 'name="fields[contact_method]"' ),
    )
);

/**
 * The instance prefixes present in a render.
 *
 * @param string $html Markup.
 * @return array<int,string>
 */
function agentshell_render_live_prefixes( $html ) {
    preg_match_all( '/\sid="(agentshell-form-[a-f0-9]{32}-\d+)-\w/', (string) $html, $m );
    return array_values( array_unique( $m[1] ) );
}

// Two different forms on one page.
$two_forms = do_shortcode(
    '[agentshell_form slug="' . $slug_a . '"][agentshell_form slug="' . ( $form_b_id ? Form_Definition::get_by_post_id( $form_b_post )['slug'] : 'none' ) . '"]'
);

$two_form_ids = ids_in( $two_forms );

probe(
    'two_different_forms',
    array(
        'form_count' => substr_count( $two_forms, '<form' ),
        'unique_ids' => count( $two_form_ids ) === count( array_unique( $two_form_ids ) ),
        'form_a_id'  => false !== strpos( $two_forms, 'data-form-id="' . $form_a_id . '"' ),
        'form_b_id'  => '' !== $form_b_id && false !== strpos( $two_forms, 'data-form-id="' . $form_b_id . '"' ),
    )
);

// ══ refusals ═══════════════════════════════════════════════════════════════
$unpublished = Form_Definition::create(
    array(
        'name'       => 'Unpublished probe ' . $token,
        'slug'       => 'unpublished-probe-' . strtolower( $token ),
        'definition' => agentshell_render_live_second_definition(),
    ),
    1
);

$unpublished_id   = is_wp_error( $unpublished ) ? '' : (string) $unpublished['form_id'];
$unpublished_post = is_wp_error( $unpublished ) ? 0 : track( (int) $unpublished['post_id'] );

$trashed = Form_Definition::create(
    array(
        'name'       => 'Trashed probe ' . $token,
        'slug'       => 'trashed-probe-' . strtolower( $token ),
        'definition' => agentshell_render_live_second_definition(),
    ),
    1
);

$trashed_id   = is_wp_error( $trashed ) ? '' : (string) $trashed['form_id'];
$trashed_post = is_wp_error( $trashed ) ? 0 : track( (int) $trashed['post_id'] );

if ( is_wp_error( $trashed ) === false ) {
    Form_Definition::publish( $trashed_id, (int) $trashed['revision'], 1 );
    wp_trash_post( $trashed_post );
}

// A visitor sees nothing.
wp_set_current_user( 0 );

$visitor_unpublished = do_shortcode( '[agentshell_form id="' . $unpublished_id . '"]' );
$visitor_trashed     = do_shortcode( '[agentshell_form id="' . $trashed_id . '"]' );
$visitor_unknown     = do_shortcode( '[agentshell_form slug="no-such-form-' . strtolower( $token ) . '"]' );
$visitor_missing_arg = do_shortcode( '[agentshell_form]' );
$visitor_ambiguous   = do_shortcode( '[agentshell_form id="' . $form_a_id . '" slug="' . ( $form_b_id ? Form_Definition::get_by_post_id( $form_b_post )['slug'] : 'none' ) . '"]' );

// A form manager sees the reason. `Form_Install::activate()` granted
// `manage_agentshell_forms` to the administrator role above, which is what
// makes this the same capability an operator would hold.
wp_set_current_user( 1 );

$editor_unpublished = do_shortcode( '[agentshell_form id="' . $unpublished_id . '"]' );
$editor_unknown     = do_shortcode( '[agentshell_form slug="no-such-form-' . strtolower( $token ) . '"]' );
$editor_missing_arg = do_shortcode( '[agentshell_form]' );
$editor_ambiguous   = do_shortcode( '[agentshell_form id="' . $form_a_id . '" slug="' . ( $form_b_id ? Form_Definition::get_by_post_id( $form_b_post )['slug'] : 'none' ) . '"]' );
$editor_not_cacheable = defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;

probe(
    'refusals',
    array(
        'visitor_unpublished'   => $visitor_unpublished,
        'visitor_trashed'       => $visitor_trashed,
        'visitor_unknown'       => $visitor_unknown,
        'visitor_missing_arg'   => $visitor_missing_arg,
        'visitor_ambiguous'     => $visitor_ambiguous,
        'editor_unpublished_has_diagnostic' => false !== strpos( $editor_unpublished, 'data-agentshell-forms-diagnostic' ),
        'editor_unpublished_code'           => agentshell_render_live_diagnostic_code( $editor_unpublished ),
        'editor_unknown_code'               => agentshell_render_live_diagnostic_code( $editor_unknown ),
        'editor_missing_arg_code'           => agentshell_render_live_diagnostic_code( $editor_missing_arg ),
        'editor_ambiguous_code'             => agentshell_render_live_diagnostic_code( $editor_ambiguous ),
        'editor_diagnostic_marks_uncacheable' => (bool) $editor_not_cacheable,
        'visitor_sees_no_diagnostic_text'     => false === strpos( $visitor_unknown, 'diagnostic' ),
    )
);

/**
 * The error code in a rendered diagnostic, or ''.
 *
 * @param string $html Markup.
 * @return string
 */
function agentshell_render_live_diagnostic_code( $html ) {
    if ( preg_match( '/data-agentshell-forms-diagnostic="([^"]*)"/', (string) $html, $m ) ) {
        return $m[1];
    }
    return '';
}

// ══ published versus working ═══════════════════════════════════════════════
$state   = Form_Definition::get_by_post_id( $form_a_post );
$working = $state['working'];

$working['name']                = 'WORKING ONLY ' . $token;
$working['fields'][0]['label']  = 'WORKING LABEL ' . $token;
$working['fields'][]            = array(
    'key'      => 'secret_draft_field',
    'type'     => 'text',
    'label'    => 'DRAFT ONLY FIELD',
    'required' => false,
    'settings' => array( 'max_length' => 20 ),
);

$updated = Form_Definition::update_working( $form_a_id, $working, (int) $state['revision'], 1 );

if ( is_wp_error( $updated ) ) {
    probe( 'fatal', array( 'where' => 'update_working', 'code' => $updated->get_error_code(), 'message' => $updated->get_error_message() ) );
    file_put_contents( $report, wp_json_encode( $f ) );
    exit( 0 );
}

$after_edit = do_shortcode( '[agentshell_form slug="' . $slug_a . '"]' );

$published_again = Form_Definition::publish( $form_a_id, (int) $updated['revision'], 1 );
$after_publish   = do_shortcode( '[agentshell_form slug="' . $slug_a . '"]' );

probe(
    'published_only',
    array(
        'working_has_draft_field'     => true,
        'after_edit_has_draft_field'  => false !== strpos( $after_edit, 'DRAFT ONLY FIELD' ),
        'after_edit_has_draft_label'  => false !== strpos( $after_edit, 'WORKING LABEL' ),
        'after_edit_has_draft_name'   => false !== strpos( $after_edit, 'WORKING ONLY' ),
        'after_edit_still_published'  => false !== strpos( $after_edit, 'Your name' ),
        'publish_ok'                  => ! is_wp_error( $published_again ),
        'after_publish_has_field'     => false !== strpos( $after_publish, 'DRAFT ONLY FIELD' ),
        'version_before'              => 1,
        'version_after'               => is_wp_error( $published_again ) ? 0 : (int) $published_again['version'],
    )
);

// ══ escaping ═══════════════════════════════════════════════════════════════
$evil = '<script>alert("x")</script>&"\'';

$evil_definition = agentshell_render_live_second_definition();
$evil_definition['name']                          = 'EVILNAME ' . $evil;
$evil_definition['fields'][0]['label']            = 'EVIL LABEL ' . $evil;
$evil_definition['fields'][0]['description']      = 'EVIL DESCRIPTION ' . $evil;
$evil_definition['fields'][0]['placeholder']      = 'EVIL PLACEHOLDER ' . $evil;
$evil_definition['settings']['submit_label']      = 'EVIL SUBMIT ' . $evil;

$evil_form = Form_Definition::create(
    array(
        'name'       => 'Evil probe ' . $token,
        'slug'       => 'evil-probe-' . strtolower( $token ),
        'definition' => $evil_definition,
    ),
    1
);

if ( is_wp_error( $evil_form ) ) {
    probe(
        'escaping',
        array( 'refused' => true, 'code' => $evil_form->get_error_code(), 'message' => $evil_form->get_error_message() )
    );
} else {
    $evil_id   = (string) $evil_form['form_id'];
    $evil_post = track( (int) $evil_form['post_id'] );
    Form_Definition::publish( $evil_id, (int) $evil_form['revision'], 1 );

    $evil_html = do_shortcode( '[agentshell_form id="' . $evil_id . '"]' );

    probe(
        'escaping',
        array(
            'refused'          => false,
            'has_script_tag'   => false !== strpos( $evil_html, '<script' ),
            'has_escaped'      => false !== strpos( $evil_html, '&lt;script&gt;' ),
            'has_raw_amp'      => (bool) preg_match( '/EVIL LABEL [^<]*&(?!amp;|lt;|gt;|quot;|#039;)/', $evil_html ),
            'has_quote_escape' => false !== strpos( $evil_html, '&quot;' ),
        )
    );
}

// ══ the widget ═════════════════════════════════════════════════════════════
$widget_facts = array( 'registered' => false, 'html' => '', 'empty_for_unpublished' => null );

if ( class_exists( 'AgentShell_Forms\\Form_Widget' ) ) {
    $factory             = $GLOBALS['wp_widget_factory'] ?? null;
    $widget_facts['registered'] = is_object( $factory )
        && isset( $factory->widgets['AgentShell_Forms\\Form_Widget'] );

    $widget = new Form_Widget();

    ob_start();
    $widget->widget(
        array( 'before_widget' => '<section class="widget">', 'after_widget' => '</section>' ),
        array( 'form_id' => $form_a_id, 'title' => 'show', 'heading_level' => 3, 'class' => 'widget-probe' )
    );
    $widget_html = (string) ob_get_clean();

    // A visitor must see nothing at all — not an empty wrapper, and not a
    // diagnostic. The editor's diagnostic is recorded separately, because it is
    // the same rule the shortcode follows.
    wp_set_current_user( 0 );

    ob_start();
    $widget->widget(
        array( 'before_widget' => '<section>', 'after_widget' => '</section>' ),
        array( 'form_id' => $unpublished_id, 'title' => 'show', 'heading_level' => 3 )
    );
    $unpublished_widget_html = (string) ob_get_clean();

    wp_set_current_user( 1 );

    ob_start();
    $widget->widget(
        array( 'before_widget' => '<section>', 'after_widget' => '</section>' ),
        array( 'form_id' => $unpublished_id, 'title' => 'show', 'heading_level' => 3 )
    );
    $unpublished_widget_editor_html = (string) ob_get_clean();

    $widget_facts['html']                   = $widget_html;
    $widget_facts['has_form']               = false !== strpos( $widget_html, '<form' );
    $widget_facts['has_before_widget']      = false !== strpos( $widget_html, '<section class="widget">' );
    $widget_facts['has_heading_level']      = (bool) preg_match( '/<h3 class="agentshell-forms__title"/', $widget_html );
    $widget_facts['has_extra_class']        = false !== strpos( $widget_html, 'widget-probe' );
    $widget_facts['empty_for_unpublished']  = '' === $unpublished_widget_html;
    $widget_facts['diagnostic_for_editor']  = false !== strpos( $unpublished_widget_editor_html, 'data-agentshell-forms-diagnostic' );
    $widget_facts['no_wrapper_for_visitor'] = false === strpos( $unpublished_widget_html, '<section>' );

    $widget_facts['update_sanitises'] = $widget->update(
        array( 'form_id' => 'not-a-real-form-id', 'title' => 'nonsense', 'heading_level' => 99, 'class' => '  spaced  ' ),
        array()
    );
}

probe( 'widget', $widget_facts );

// ══ the documented API ═════════════════════════════════════════════════════
$api_by_id   = agentshell_forms_render( $form_a_id );
$api_by_slug = agentshell_forms_render( $slug_a );
$api_by_post = agentshell_forms_render( $form_a_post );

// The API is a public seam, so what an unknown form returns depends on who is
// asking — the same rule the shortcode follows. Both halves are recorded.
wp_set_current_user( 0 );
$api_visitor_unknown = agentshell_forms_render( 'no-such-form-' . strtolower( $token ) );
wp_set_current_user( 1 );
$api_editor_unknown = agentshell_forms_render( 'no-such-form-' . strtolower( $token ) );

probe(
    'api',
    array(
        'by_id_has_form'   => false !== strpos( $api_by_id, '<form' ),
        'by_slug_has_form' => false !== strpos( $api_by_slug, '<form' ),
        'by_post_has_form' => false !== strpos( $api_by_post, '<form' ),
        'unknown_is_empty' => '' === $api_visitor_unknown,
        'unknown_diagnostic_for_editor' => false !== strpos( $api_editor_unknown, 'data-agentshell-forms-diagnostic' ),
        'published_definition_visible' => is_array( agentshell_forms_get_published( $form_a_id ) ),
    )
);

// ══ cache safety ═══════════════════════════════════════════════════════════
// The same page, rendered twice, must be byte-identical — and must contain no
// challenge token at all — or a page cache would serve one visitor's token to
// the next.

Form_Renderer::render_definition( array(), array( 'instance' => 500 ) ); // no-op, keeps the counter warm

$page_one = do_shortcode( '[agentshell_form slug="' . $slug_a . '"]' );
$page_two = do_shortcode( '[agentshell_form slug="' . $slug_a . '"]' );

$challenge_rows_before = (int) $wpdb->get_var(
    $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $form_a_id )
);

probe(
    'cache_safety',
    array(
        'identical'          => agentshell_render_live_normalise_ids( $page_one ) === agentshell_render_live_normalise_ids( $page_two ),
        'has_empty_challenge' => false !== strpos( $page_one, 'name="agentshell_form_challenge" value=""' ),
        'has_any_challenge_value' => (bool) preg_match( '/name="agentshell_form_challenge" value="[^"]+"/', $page_one ),
        'has_nonce'          => false !== strpos( $page_one, 'nonce' ),
        'has_timestamp_field' => (bool) preg_match( '/name="[^"]*time[^"]*"/i', $page_one ),
        'challenge_rows_unchanged_by_render' => $challenge_rows_before === (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $form_a_id )
        ),
        'endpoint_attribute' => false !== strpos( $page_one, 'data-challenge-endpoint="' ),
    )
);

// ══ the challenge route ════════════════════════════════════════════════════
/**
 * POST the challenge route as a given source address.
 *
 * @param string $form_id Form identifier.
 * @param string $address Source address to present.
 * @return array
 */
function agentshell_render_live_mint( $form_id, $address ) {
    $_SERVER['REMOTE_ADDR'] = $address;

    $request = new WP_REST_Request( 'POST', '/' . Form_Challenge_Endpoint::REST_NAMESPACE . Form_Challenge_Endpoint::ROUTE );
    $request->set_param( 'form_id', $form_id );

    $response = rest_do_request( $request );
    $data     = $response->get_data();

    return array(
        'status'  => (int) $response->get_status(),
        'body'    => $data,
        'token'   => is_array( $data ) && isset( $data['challenge'] ) ? (string) $data['challenge'] : '',
        'version' => is_array( $data ) && isset( $data['form_version'] ) ? (int) $data['form_version'] : -1,
        'headers' => $response->get_headers(),
        'code'    => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
    );
}

$visitor_one = agentshell_render_live_mint( $form_a_id, '203.0.113.11' );
$visitor_two = agentshell_render_live_mint( $form_a_id, '203.0.113.22' );
$same_again  = agentshell_render_live_mint( $form_a_id, '203.0.113.11' );

$unpublished_mint = agentshell_render_live_mint( $unpublished_id, '203.0.113.11' );
$invalid_mint     = agentshell_render_live_mint( 'not-an-identifier', '203.0.113.11' );

$get_request  = new WP_REST_Request( 'GET', '/' . Form_Challenge_Endpoint::REST_NAMESPACE . Form_Challenge_Endpoint::ROUTE );
$get_response = rest_do_request( $get_request );

// The rows the two mints created, and the versions they name.
$rows = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT token_hash, form_id, form_version, subject_hash, consumed_at FROM ' . Form_Challenges::table() . ' WHERE form_id = %s ORDER BY id ASC',
        $form_a_id
    ),
    ARRAY_A
);

$published_version = (int) ( Form_Definition::get_by_post_id( $form_a_post )['version'] ?? 0 );

probe(
    'challenge_route',
    array(
        'visitor_one_status'     => $visitor_one['status'],
        'visitor_two_status'     => $visitor_two['status'],
        'same_again_status'      => $same_again['status'],
        'one_token_length'       => strlen( $visitor_one['token'] ),
        'tokens_differ'          => '' !== $visitor_one['token'] && '' !== $visitor_two['token'] && $visitor_one['token'] !== $visitor_two['token'],
        'same_visitor_gets_new'  => '' !== $visitor_one['token'] && $visitor_one['token'] !== $same_again['token'],
        'minted_version'         => $visitor_one['version'],
        'published_version'      => $published_version,
        'cache_control'          => isset( $visitor_one['headers']['Cache-Control'] ) ? (string) $visitor_one['headers']['Cache-Control'] : '',
        'unpublished_status'     => $unpublished_mint['status'],
        'unpublished_code'       => $unpublished_mint['code'],
        'unpublished_token'      => $unpublished_mint['token'],
        'invalid_status'         => $invalid_mint['status'],
        'get_status'             => (int) $get_response->get_status(),
        'row_count'              => count( $rows ),
        'row_versions'           => array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'form_version' ) ) ) ),
        'distinct_subjects'      => count( array_unique( wp_list_pluck( $rows, 'subject_hash' ) ) ),
        'all_unconsumed'         => 0 === count( array_filter( wp_list_pluck( $rows, 'consumed_at' ), function ( $v ) { return null !== $v && '' !== $v; } ) ),
        'tokens_not_stored'      => 0 === count( array_filter( wp_list_pluck( $rows, 'token_hash' ), function ( $h ) use ( $visitor_one, $visitor_two ) {
            return $h === $visitor_one['token'] || $h === $visitor_two['token'];
        } ) ),
        'token_hash_matches'     => in_array( hash( 'sha256', $visitor_one['token'] ), wp_list_pluck( $rows, 'token_hash' ), true ),
    )
);

// A trashed form keeps its meta rows, so it still has a published definition.
// The renderer refuses to render one; the mint route must refuse to issue a
// challenge for one, and it must not create a row while refusing.
$trashable = Form_Definition::create(
    array(
        'name'       => 'Trashable probe ' . $token,
        'slug'       => 'trashable-probe-' . strtolower( $token ),
        'definition' => agentshell_render_live_second_definition(),
    ),
    1
);

$trash_facts = array( 'created' => ! is_wp_error( $trashable ) );

if ( ! is_wp_error( $trashable ) ) {
    $trash_id   = (string) $trashable['form_id'];
    $trash_post = track( (int) $trashable['post_id'] );
    $trash_rows = function () use ( $wpdb, $trash_id ) {
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $trash_id )
        );
    };

    Form_Definition::publish( $trash_id, (int) $trashable['revision'], 1 );

    $before_trash   = agentshell_render_live_mint( $trash_id, '203.0.113.44' );
    $rows_published = $trash_rows();

    wp_trash_post( $trash_post );

    $after_trash    = agentshell_render_live_mint( $trash_id, '203.0.113.44' );
    $rows_trashed   = $trash_rows();
    $status_trashed = (string) get_post_status( $trash_post );

    $trash_facts += array(
        'before_status'    => $before_trash['status'],
        'before_token_len' => strlen( $before_trash['token'] ),
        'rows_published'   => $rows_published,
        'post_status'      => $status_trashed,
        'after_status'     => $after_trash['status'],
        'after_code'       => $after_trash['code'],
        'after_token'      => $after_trash['token'],
        'rows_after_trash' => $rows_trashed,
    );

    $wpdb->query(
        $wpdb->prepare( 'DELETE FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $trash_id )
    );
    $wpdb->query(
        $wpdb->prepare( 'DELETE FROM ' . Form_Rate_Limits::table() . ' WHERE form_id = %s', $trash_id )
    );
}

probe( 'trashed_challenge', $trash_facts );

// The rate limit: the same source may not mint without bound.
$limited_statuses = array();
for ( $i = 0; $i < Form_Challenge_Endpoint::RATE_LIMIT + 3; $i++ ) {
    $result = agentshell_render_live_mint( $form_a_id, '203.0.113.99' );
    $limited_statuses[] = $result['status'];
    if ( 429 === $result['status'] ) {
        $limited = $result;
        break;
    }
}

probe(
    'rate_limit',
    array(
        'statuses'        => $limited_statuses,
        'first_refusal'   => isset( $limited ) ? $limited['code'] : '',
        'retry_after'     => isset( $limited, $limited['headers']['Retry-After'] ) ? (string) $limited['headers']['Retry-After'] : '',
        'admitted_before_refusal' => count( array_filter( $limited_statuses, function ( $s ) { return 200 === $s; } ) ),
        'limit'           => Form_Challenge_Endpoint::RATE_LIMIT,
    )
);

// ══ cleanup ════════════════════════════════════════════════════════════════
$deleted = array();

foreach ( array_unique( $mine ) as $post_id ) {
    $deleted[ (int) $post_id ] = (bool) wp_delete_post( (int) $post_id, true );
}

$wpdb->query(
    $wpdb->prepare( 'DELETE FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $form_a_id )
);
$wpdb->query(
    $wpdb->prepare( 'DELETE FROM ' . Form_Rate_Limits::table() . ' WHERE form_id = %s', $form_a_id )
);

$remaining = 0;
foreach ( array_unique( $mine ) as $post_id ) {
    if ( get_post( (int) $post_id ) ) {
        $remaining++;
    }
}

probe(
    'cleanup',
    array(
        'deleted'        => $deleted,
        'remaining_posts' => $remaining,
        'remaining_challenges' => (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Form_Challenges::table() . ' WHERE form_id = %s', $form_a_id )
        ),
    )
);

file_put_contents( $report, wp_json_encode( $f ) );
