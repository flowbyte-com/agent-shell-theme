<?php
/**
 * Rendering and insertion against a real WordPress.
 *
 * The renderer's pure half is `tests/forms-render-test.php`. This suite is the
 * other half: the shortcode, the widget, the documented API and the public
 * challenge route, in a WordPress that has really registered a post type,
 * really published a definition and really answered a REST request.
 *
 * It is destructive: it creates forms, publishes them, trashes one, mints
 * challenges and reserves rate-limit counters. Everything it creates is
 * removed before the child exits, and the parent asserts that it was.
 *
 * The two claims worth reading the assertions for:
 *
 *   1. **A working definition never reaches a visitor.** The child edits the
 *      working definition, re-renders, and the page still shows the published
 *      one — then publishes and sees the change appear.
 *   2. **A cached page cannot share one challenge between two visitors.** The
 *      rendered page contains no token, two renders are byte-identical, and two
 *      mint requests from different sources produce different, unconsumed rows.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms rendering (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'forms rendering suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-render-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-render-live-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms rendering: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

$p = isset( $f['probes'] ) && is_array( $f['probes'] ) ? $f['probes'] : array();

if ( isset( $p['fatal'] ) ) {
    Agentshell_Test::run(
        'forms rendering: the child reached its subjects',
        function () use ( $p ) {
            Agentshell_Test::assert_true(
                false,
                'the child could not set up: ' . $p['fatal']['where'] . ' — ' . $p['fatal']['code'] . ': ' . $p['fatal']['message']
            );
        }
    );
    Agentshell_Test::summary();
    return;
}

/**
 * One probe's field, with a clear failure when the child did not report.
 *
 * @param array  $probes All probes.
 * @param string $probe  Probe name.
 * @param string $key    Field.
 * @return mixed
 */
function asr( array $probes, $probe, $key ) {
    if ( ! isset( $probes[ $probe ] ) ) {
        throw new Exception( "the child did not report the probe \"{$probe}\"" );
    }
    if ( ! array_key_exists( $key, $probes[ $probe ] ) ) {
        throw new Exception( "the probe \"{$probe}\" has no field \"{$key}\"" );
    }
    return $probes[ $probe ][ $key ];
}

Agentshell_Test::run(
    'the plugin registered a post type, a shortcode, a widget and a route',
    function () use ( $p ) {
        Agentshell_Test::assert_true( '' !== asr( $p, 'environment', 'wp_version' ), 'a WordPress version must be reported' );
        Agentshell_Test::assert_true( asr( $p, 'environment', 'shortcode_added' ), 'the shortcode must be registered' );
        Agentshell_Test::assert_true( asr( $p, 'environment', 'widget_class' ), 'the widget class must exist' );
        Agentshell_Test::assert_true( asr( $p, 'environment', 'route_registered' ), 'the challenge route must be registered' );
    }
);

Agentshell_Test::run(
    'the shortcode renders a published form with every field type',
    function () use ( $p ) {
        $types = asr( $p, 'shortcode_by_slug', 'field_types' );

        foreach ( array( 'text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox', 'consent', 'hidden' ) as $type ) {
            Agentshell_Test::assert_true( in_array( $type, $types, true ), "the rendered form is missing the {$type} field" );
        }

        Agentshell_Test::assert_true( asr( $p, 'shortcode_by_slug', 'has_form' ), 'the shortcode must render a form' );
        Agentshell_Test::assert_true( asr( $p, 'shortcode_by_slug', 'has_form_id' ), 'the form must carry the internal identifier' );
        Agentshell_Test::assert_true( asr( $p, 'shortcode_by_slug', 'challenge_empty' ), 'the challenge must be rendered empty' );

        $ids = asr( $p, 'shortcode_by_slug', 'ids' );
        Agentshell_Test::assert_equals( count( $ids ), count( array_unique( $ids ) ), 'ids within one instance must be unique' );
    }
);

Agentshell_Test::run(
    'the shortcode renders no submission action and does not disable validation',
    function () use ( $p ) {
        Agentshell_Test::assert_false( asr( $p, 'shortcode_by_slug', 'has_action' ), 'Phase 2 must not point at a submission route' );
        Agentshell_Test::assert_false( asr( $p, 'shortcode_by_slug', 'has_novalidate' ), 'native validation must stay on' );
    }
);

Agentshell_Test::run(
    'an identifier and a slug that name the same form produce the same render',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr( $p, 'shortcode_by_id', 'has_form' ), 'the id form of the shortcode must render' );
        Agentshell_Test::assert_true(
            asr( $p, 'shortcode_by_id', 'identical_to_slug_render' ),
            'the same form addressed two ways must render the same markup'
        );
        Agentshell_Test::assert_true( asr( $p, 'shortcode_by_both_agreeing', 'has_form' ), 'agreeing id and slug must render' );
    }
);

Agentshell_Test::run(
    'the same form twice on one page keeps every id unique and every name shared',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 2, asr( $p, 'two_instances_of_one_form', 'form_count' ), 'two shortcodes, two forms' );
        Agentshell_Test::assert_equals(
            asr( $p, 'two_instances_of_one_form', 'id_count' ),
            asr( $p, 'two_instances_of_one_form', 'unique_ids' ),
            'no DOM id may be shared between two instances'
        );
        Agentshell_Test::assert_equals( 2, count( asr( $p, 'two_instances_of_one_form', 'prefixes' ) ), 'each instance needs its own prefix' );
        Agentshell_Test::assert_equals( 4, asr( $p, 'two_instances_of_one_form', 'names_kept' ), 'two instances, two radios each, same names' );
    }
);

Agentshell_Test::run(
    'two different forms on one page do not collide',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 2, asr( $p, 'two_different_forms', 'form_count' ), 'both forms must render' );
        Agentshell_Test::assert_true( asr( $p, 'two_different_forms', 'unique_ids' ), 'ids must be unique across forms' );
        Agentshell_Test::assert_true( asr( $p, 'two_different_forms', 'form_a_id' ), 'the first form must be present' );
        Agentshell_Test::assert_true( asr( $p, 'two_different_forms', 'form_b_id' ), 'the second form must be present' );
    }
);

Agentshell_Test::run(
    'an unpublished, trashed, unknown or ambiguous form renders nothing to a visitor',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( '', asr( $p, 'refusals', 'visitor_unpublished' ), 'an unpublished form must be invisible' );
        Agentshell_Test::assert_equals( '', asr( $p, 'refusals', 'visitor_trashed' ), 'a trashed form must be invisible' );
        Agentshell_Test::assert_equals( '', asr( $p, 'refusals', 'visitor_unknown' ), 'an unknown form must be invisible' );
        Agentshell_Test::assert_equals( '', asr( $p, 'refusals', 'visitor_missing_arg' ), 'a shortcode with no reference must be invisible' );
        Agentshell_Test::assert_equals( '', asr( $p, 'refusals', 'visitor_ambiguous' ), 'a contradictory shortcode must be invisible' );
    }
);

Agentshell_Test::run(
    'a form manager is told why a shortcode rendered nothing, and the page is uncacheable',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr( $p, 'refusals', 'editor_unpublished_has_diagnostic' ), 'an editor must see a diagnostic' );
        Agentshell_Test::assert_equals( 'form_not_published', asr( $p, 'refusals', 'editor_unpublished_code' ), 'the reason must be named' );
        Agentshell_Test::assert_equals( 'form_not_found', asr( $p, 'refusals', 'editor_unknown_code' ), 'a missing form must be named' );
        Agentshell_Test::assert_equals(
            'form_shortcode_missing_reference',
            asr( $p, 'refusals', 'editor_missing_arg_code' ),
            'a shortcode with no reference must say so'
        );
        Agentshell_Test::assert_equals(
            'form_shortcode_ambiguous',
            asr( $p, 'refusals', 'editor_ambiguous_code' ),
            'two attributes naming different forms must be refused by name'
        );
        Agentshell_Test::assert_true(
            asr( $p, 'refusals', 'editor_diagnostic_marks_uncacheable' ),
            'a page carrying a diagnostic must not be cached'
        );
    }
);

Agentshell_Test::run(
    'an uncommitted edit never reaches the page, and publishing makes it appear',
    function () use ( $p ) {
        Agentshell_Test::assert_false(
            asr( $p, 'published_only', 'after_edit_has_draft_field' ),
            'a field that only exists in the working definition must not render'
        );
        Agentshell_Test::assert_false(
            asr( $p, 'published_only', 'after_edit_has_draft_label' ),
            'an edited label must not render before it is published'
        );
        Agentshell_Test::assert_false(
            asr( $p, 'published_only', 'after_edit_has_draft_name' ),
            'an edited name must not render before it is published'
        );
        Agentshell_Test::assert_true(
            asr( $p, 'published_only', 'after_edit_still_published' ),
            'the published version must still render'
        );
        Agentshell_Test::assert_true( asr( $p, 'published_only', 'publish_ok' ), 'publishing must succeed' );
        Agentshell_Test::assert_true(
            asr( $p, 'published_only', 'after_publish_has_field' ),
            'after publishing, the new field must render'
        );
        Agentshell_Test::assert_equals(
            asr( $p, 'published_only', 'version_before' ) + 1,
            asr( $p, 'published_only', 'version_after' ),
            'publishing must increment the version'
        );
    }
);

Agentshell_Test::run(
    'a label, description or option containing markup is rendered as text',
    function () use ( $p ) {
        if ( asr( $p, 'escaping', 'refused' ) ) {
            Agentshell_Test::assert_true(
                false,
                'the fixture definition was refused: ' . asr( $p, 'escaping', 'code' )
                . ' — ' . asr( $p, 'escaping', 'message' )
            );
        }

        Agentshell_Test::assert_false( asr( $p, 'escaping', 'has_script_tag' ), 'no operator string may become a script element' );
        Agentshell_Test::assert_true( asr( $p, 'escaping', 'has_escaped' ), 'the marker must survive as text' );
        Agentshell_Test::assert_false( asr( $p, 'escaping', 'has_raw_amp' ), 'an ampersand must be escaped' );
        Agentshell_Test::assert_true( asr( $p, 'escaping', 'has_quote_escape' ), 'a quote must be escaped' );
    }
);

Agentshell_Test::run(
    'the widget renders the same form, inside the theme wrapper, and renders nothing unpublished',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr( $p, 'widget', 'registered' ), 'the widget must be registered with WordPress' );
        Agentshell_Test::assert_true( asr( $p, 'widget', 'has_form' ), 'the widget must render the form' );
        Agentshell_Test::assert_true( asr( $p, 'widget', 'has_before_widget' ), 'the widget must respect the theme wrapper' );
        Agentshell_Test::assert_true( asr( $p, 'widget', 'has_heading_level' ), 'the widget must honour its heading level' );
        Agentshell_Test::assert_true( asr( $p, 'widget', 'has_extra_class' ), 'the widget must honour its extra classes' );
        Agentshell_Test::assert_true( asr( $p, 'widget', 'empty_for_unpublished' ), 'an unpublished form must render nothing to a visitor' );
        Agentshell_Test::assert_true(
            asr( $p, 'widget', 'no_wrapper_for_visitor' ),
            'a widget that renders nothing must not emit the theme wrapper either'
        );
        Agentshell_Test::assert_true(
            asr( $p, 'widget', 'diagnostic_for_editor' ),
            'an editor must be told why the widget is empty'
        );

        $saved = asr( $p, 'widget', 'update_sanitises' );
        Agentshell_Test::assert_equals( '', $saved['form_id'], 'a stale form id must be cleared' );
        Agentshell_Test::assert_equals( 'show', $saved['title'], 'an unrecognised title setting must fall back' );
        Agentshell_Test::assert_equals( 3, $saved['heading_level'], 'an out-of-range heading level must fall back' );
        Agentshell_Test::assert_equals( 'spaced', $saved['class'], 'the class must be sanitised' );
    }
);

Agentshell_Test::run(
    'the documented API renders by identifier, slug and post id',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr( $p, 'api', 'by_id_has_form' ), 'agentshell_forms_render() must accept the identifier' );
        Agentshell_Test::assert_true( asr( $p, 'api', 'by_slug_has_form' ), 'agentshell_forms_render() must accept the slug' );
        Agentshell_Test::assert_true( asr( $p, 'api', 'by_post_has_form' ), 'agentshell_forms_render() must accept the post id' );
        Agentshell_Test::assert_true( asr( $p, 'api', 'unknown_is_empty' ), 'agentshell_forms_render() must return "" for an unknown form' );
        Agentshell_Test::assert_true( asr( $p, 'api', 'published_definition_visible' ), 'the published reader must return the definition' );
    }
);

Agentshell_Test::run(
    'the rendered page is cacheable and carries no per-visitor value',
    function () use ( $p ) {
        Agentshell_Test::assert_true( asr( $p, 'cache_safety', 'identical' ), 'two renders of one version must be byte-identical' );
        Agentshell_Test::assert_true( asr( $p, 'cache_safety', 'has_empty_challenge' ), 'the challenge input must be empty in the HTML' );
        Agentshell_Test::assert_false( asr( $p, 'cache_safety', 'has_any_challenge_value' ), 'no challenge value may be rendered into a page' );
        Agentshell_Test::assert_false( asr( $p, 'cache_safety', 'has_nonce' ), 'a nonce would make the page uncacheable' );
        Agentshell_Test::assert_false( asr( $p, 'cache_safety', 'has_timestamp_field' ), 'a timestamp would make the page uncacheable' );
        Agentshell_Test::assert_true(
            asr( $p, 'cache_safety', 'challenge_rows_unchanged_by_render' ),
            'rendering must not mint a challenge'
        );
        Agentshell_Test::assert_true( asr( $p, 'cache_safety', 'endpoint_attribute' ), 'the page must name the challenge route' );
    }
);

Agentshell_Test::run(
    'two visitors loading the same cached page receive different challenges',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 200, asr( $p, 'challenge_route', 'visitor_one_status' ), 'the first visitor must be served' );
        Agentshell_Test::assert_equals( 200, asr( $p, 'challenge_route', 'visitor_two_status' ), 'the second visitor must be served' );
        Agentshell_Test::assert_equals( 64, asr( $p, 'challenge_route', 'one_token_length' ), 'a token is 32 bytes, hex-encoded' );
        Agentshell_Test::assert_true( asr( $p, 'challenge_route', 'tokens_differ' ), 'two visitors must not share a challenge' );
        Agentshell_Test::assert_true( asr( $p, 'challenge_route', 'same_visitor_gets_new' ), 'a reload must mint a fresh challenge' );
        Agentshell_Test::assert_equals(
            asr( $p, 'challenge_route', 'published_version' ),
            asr( $p, 'challenge_route', 'minted_version' ),
            'a challenge must name the published version'
        );
        Agentshell_Test::assert_true(
            false !== strpos( (string) asr( $p, 'challenge_route', 'cache_control' ), 'no-store' ),
            'the challenge response must not be cached: ' . var_export( asr( $p, 'challenge_route', 'cache_control' ), true )
        );
        Agentshell_Test::assert_true( asr( $p, 'challenge_route', 'token_hash_matches' ), 'the stored row must be the SHA-256 of the token' );
        Agentshell_Test::assert_true( asr( $p, 'challenge_route', 'tokens_not_stored' ), 'the token itself must never be stored' );
        Agentshell_Test::assert_true( asr( $p, 'challenge_route', 'all_unconsumed' ), 'a freshly minted challenge must be unconsumed' );
        Agentshell_Test::assert_equals(
            2,
            asr( $p, 'challenge_route', 'distinct_subjects' ),
            'three mints came from two sources, so two subject buckets'
        );
        Agentshell_Test::assert_equals(
            array( asr( $p, 'challenge_route', 'published_version' ) ),
            asr( $p, 'challenge_route', 'row_versions' ),
            'every row must name the published version'
        );
    }
);

Agentshell_Test::run(
    'the challenge route refuses what it should not mint for',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 404, asr( $p, 'challenge_route', 'unpublished_status' ), 'an unpublished form must not mint' );
        Agentshell_Test::assert_equals( 'form_unavailable', asr( $p, 'challenge_route', 'unpublished_code' ), 'the refusal must be named' );
        Agentshell_Test::assert_equals( '', asr( $p, 'challenge_route', 'unpublished_token' ), 'no token may be returned' );
        Agentshell_Test::assert_equals( 400, asr( $p, 'challenge_route', 'invalid_status' ), 'a malformed identifier must be refused' );
        Agentshell_Test::assert_equals( 404, asr( $p, 'challenge_route', 'get_status' ), 'the route must not answer a GET' );
    }
);

Agentshell_Test::run(
    'the challenge route is rate-limited per source and form',
    function () use ( $p ) {
        Agentshell_Test::assert_equals(
            asr( $p, 'rate_limit', 'limit' ),
            asr( $p, 'rate_limit', 'admitted_before_refusal' ),
            'exactly the limit must be admitted before the refusal'
        );
        Agentshell_Test::assert_equals(
            'form_challenge_rate_limited',
            asr( $p, 'rate_limit', 'first_refusal' ),
            'the refusal must be named'
        );
        Agentshell_Test::assert_true(
            (int) asr( $p, 'rate_limit', 'retry_after' ) > 0,
            'a refusal must say when to retry'
        );
    }
);

Agentshell_Test::run(
    'the suite left nothing behind',
    function () use ( $p ) {
        Agentshell_Test::assert_equals( 0, asr( $p, 'cleanup', 'remaining_posts' ), 'every created form must be deleted' );
        Agentshell_Test::assert_equals( 0, asr( $p, 'cleanup', 'remaining_challenges' ), 'every minted challenge must be deleted' );

        foreach ( asr( $p, 'cleanup', 'deleted' ) as $post_id => $deleted ) {
            Agentshell_Test::assert_true( $deleted, "post {$post_id} was not deleted" );
        }
    }
);

Agentshell_Test::summary();
