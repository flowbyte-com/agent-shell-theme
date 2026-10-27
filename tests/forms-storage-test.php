<?php
/**
 * The forms storage invariants, against a real WordPress and a real MySQL.
 *
 * The design rests every write on three mechanisms, and none of them is
 * observable in a stubbed array: an advisory lock for authoritative-row
 * creation, a row count on every read so a duplicate is *detected* rather than
 * silently resolved, and a value-guarded update scoped by the real primary key
 * `meta_id`. `wp_postmeta` has no uniqueness guarantee at all, so a test that
 * asserted against an in-memory map would be asserting the map.
 *
 * This suite therefore runs inside real WordPress, writes real rows, reads them
 * back with raw SQL, and leaves the site as it found it.
 *
 * The races between *processes* are `tests/forms-concurrency-test.php`; this
 * one proves the single-process invariants those races are built on.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "forms storage (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'forms storage suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require one)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-forms-storage-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/forms-storage-child.php',
    array( $config['wp_root'], $report_file )
);

$raw = @file_get_contents( $report_file );
@unlink( $report_file );

$f = json_decode( (string) $raw, true );

if ( ! is_array( $f ) ) {
    Agentshell_Test::run(
        'forms storage: the child reported',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'the child produced no report: ' . $run['output'] );
        }
    );
    return;
}

/**
 * One probe's facts.
 *
 * @param string $name Probe name.
 * @return array
 */
function fst( $name ) {
    $f = $GLOBALS['f'];
    return isset( $f['probes'][ $name ] ) && is_array( $f['probes'][ $name ] ) ? $f['probes'][ $name ] : array();
}

/**
 * The error codes a refusal carried.
 *
 * @param array $probe Probe facts.
 * @return array<int,string>
 */
function fst_codes( array $probe ) {
    return isset( $probe['error_codes'] ) && is_array( $probe['error_codes'] ) ? $probe['error_codes'] : array();
}

/* ---------------------------------------------------------------------------
 * The environment the rest of the suite depends on
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: the plugin loaded on plain WordPress with its types and tables',
    function () {
        $p = fst( 'precondition' );

        Agentshell_Test::assert_true( true === ( $p['plugin_constant'] ?? false ), 'the plugin must be loaded' );
        Agentshell_Test::assert_equals( '0.1.0', $p['version'] ?? null, 'the plugin version must be the one in the header' );
        Agentshell_Test::assert_equals( 1, (int) ( $p['schema_version'] ?? 0 ), 'the schema version must be 1' );
        Agentshell_Test::assert_true( true === ( $p['form_type'] ?? false ), 'agentshell_form must be registered' );
        Agentshell_Test::assert_true( true === ( $p['entry_type'] ?? false ), 'agentshell_entry must be registered' );
        Agentshell_Test::assert_true( true === ( $p['revisions_support'] ?? false ), 'the form type must support revisions' );
        Agentshell_Test::assert_true( true === ( $p['tables_exist'] ?? false ), 'both plugin tables must exist after activation' );
        Agentshell_Test::assert_equals( 'InnoDB', $p['engine'] ?? null, 'the definition tables must be transactional for publication' );
    }
);

/* ---------------------------------------------------------------------------
 * Creation
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: create writes exactly one row per definition key',
    function () {
        $p = fst( 'p1_create' );

        Agentshell_Test::assert_false( isset( $p['error'] ), 'a valid create must not fail: ' . ( $p['error'] ?? '' ) );
        Agentshell_Test::assert_true( (int) ( $p['post_id'] ?? 0 ) > 0, 'a form must have a post id' );
        Agentshell_Test::assert_true( true === ( $p['form_id_is_32_hex'] ?? false ), 'the internal identifier must be 32 lowercase hex characters' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['revision'] ?? -1 ), 'a new form starts at revision 0' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['version'] ?? -1 ), 'a new form starts at version 0' );
        Agentshell_Test::assert_false( (bool) ( $p['has_been_published'] ?? true ), 'a new form has never been published' );
        Agentshell_Test::assert_equals( 2, (int) ( $p['working_fields'] ?? -1 ), 'the initial field list must be stored' );
        Agentshell_Test::assert_equals( 'draft', $p['post_status'] ?? null, 'an unpublished form is a draft post' );
        Agentshell_Test::assert_equals( 'agentshell_form', $p['post_type'] ?? null, 'the post type must be the form type' );
        Agentshell_Test::assert_true( true === ( $p['published_absent'] ?? false ), 'a new form has no published definition' );
        Agentshell_Test::assert_true( true === ( $p['previous_absent'] ?? false ), 'a new form has no previous definition' );

        $counts = isset( $p['row_counts'] ) && is_array( $p['row_counts'] ) ? $p['row_counts'] : array();
        Agentshell_Test::assert_equals( 8, count( $counts ), 'every definition key must be read back' );

        // A new form has exactly one row for the keys that must exist, and
        // none for the two that only arrive with a publication. What must be
        // true of every key is that it never has more than one.
        $must_exist = array(
            '_agentshell_form_working',
            '_agentshell_form_revision',
            '_agentshell_form_id',
            '_agentshell_form_slug',
            '_agentshell_form_version',
            '_agentshell_form_meta',
        );
        foreach ( $counts as $key => $count ) {
            Agentshell_Test::assert_true( (int) $count <= 1, 'the key ' . $key . ' must never have more than one row' );
            if ( in_array( $key, $must_exist, true ) ) {
                Agentshell_Test::assert_equals( 1, (int) $count, 'the key ' . $key . ' must have exactly one row' );
            }
        }
        Agentshell_Test::assert_equals(
            0,
            (int) ( $counts[ '_agentshell_form_published' ] ?? -1 ),
            'a new form must have no published row'
        );
    }
);

Agentshell_Test::run(
    'forms storage: an invalid definition is refused before anything is written',
    function () {
        $p = fst( 'p2_create_invalid' );

        Agentshell_Test::assert_true( true === ( $p['is_error'] ?? false ), 'a form with no fields must be refused' );
        Agentshell_Test::assert_equals( 'form_invalid_definition', $p['code'] ?? null, 'the refusal must be named' );
        Agentshell_Test::assert_true(
            in_array( 'form_fields_count_invalid', fst_codes( $p ), true ),
            'the refusal must carry the validator code that caused it'
        );
        Agentshell_Test::assert_true(
            true === ( $p['no_post_created'] ?? false ),
            'a refused create must leave no form post behind (before=' . ( $p['posts_before'] ?? '?' ) . ', after=' . ( $p['posts_after'] ?? '?' ) . ')'
        );
    }
);

Agentshell_Test::run(
    'forms storage: lookup is by the immutable identifier, not by the title',
    function () {
        $p = fst( 'p3_lookup' );

        Agentshell_Test::assert_true( true === ( $p['by_id_ok'] ?? false ), 'a form must be found by its internal identifier' );
        Agentshell_Test::assert_equals( 1, count( $p['slug_ids'] ?? array() ), 'exactly one form may carry a slug' );
        Agentshell_Test::assert_true( true === ( $p['slug_taken'] ?? false ), 'the slug must report as taken to another form' );
        Agentshell_Test::assert_false( (bool) ( $p['slug_free_for_self'] ?? true ), 'a form must not collide with its own slug' );
        Agentshell_Test::assert_equals( 'form_id_invalid', $p['bad_id_code'] ?? null, 'a malformed id must be refused by name' );
        Agentshell_Test::assert_equals( 'form_not_found', $p['missing_id_code'] ?? null, 'an unknown id must be reported as not found' );
    }
);

/* ---------------------------------------------------------------------------
 * Update and the compare-and-swap
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: update rewrites the same row and advances the revision',
    function () {
        $p = fst( 'p4_update' );

        Agentshell_Test::assert_false( (bool) ( $p['is_error'] ?? true ), 'a valid update must succeed' );
        Agentshell_Test::assert_equals( 1, (int) ( $p['revision'] ?? -1 ), 'a successful update must advance the revision' );
        Agentshell_Test::assert_equals( 1, (int) ( $p['row_count_before'] ?? -1 ), 'there must be one working row before the update' );
        Agentshell_Test::assert_equals( 1, (int) ( $p['row_count_after'] ?? -1 ), 'the update must not create a second row' );
        Agentshell_Test::assert_true( true === ( $p['meta_id_stable'] ?? false ), 'an update must keep the same meta_id, never replace the row' );
        Agentshell_Test::assert_true( true === ( $p['bytes_changed'] ?? false ), 'a successful update must change the stored bytes' );
        Agentshell_Test::assert_true( true === ( $p['stored_has_edited'] ?? false ), 'the new definition must be the one stored' );
    }
);

Agentshell_Test::run(
    'forms storage: a stale revision is refused and the stored bytes are untouched',
    function () {
        $p = fst( 'p5_stale_update' );

        Agentshell_Test::assert_true( true === ( $p['is_error'] ?? false ), 'a stale revision must be refused' );
        Agentshell_Test::assert_equals( 'form_revision_mismatch', $p['code'] ?? null, 'the refusal must be named' );
        Agentshell_Test::assert_true( true === ( $p['bytes_unchanged'] ?? false ), 'a refused write must not change the working definition' );
        Agentshell_Test::assert_true( true === ( $p['revision_unchanged'] ?? false ), 'a refused write must not advance the revision' );
        Agentshell_Test::assert_true( true === ( $p['no_stale_marker'] ?? false ), 'the losing write must appear nowhere in the stored bytes' );
    }
);

Agentshell_Test::run(
    'forms storage: the compare-and-swap is guarded on the exact previous bytes',
    function () {
        $p = fst( 'p6_cas' );

        Agentshell_Test::assert_true( true === ( $p['wrong_old_refused'] ?? false ), 'a guard that does not match must change nothing' );
        Agentshell_Test::assert_true( true === ( $p['value_unchanged'] ?? false ), 'the value must survive a refused swap' );
        Agentshell_Test::assert_true( true === ( $p['same_value_accepted'] ?? false ), 'a matching guard must be accepted' );
    }
);

/* ---------------------------------------------------------------------------
 * Duplicate rows
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: a duplicated meta row is detected, refused and never overwritten',
    function () {
        $p = fst( 'p7_duplicate_meta' );

        Agentshell_Test::assert_equals( 2, (int) ( $p['row_count'] ?? -1 ), 'the probe must have created the corruption it is testing' );
        Agentshell_Test::assert_equals( 'form_meta_duplicate', $p['read_code'] ?? null, 'a read must refuse to choose between duplicate rows' );
        Agentshell_Test::assert_equals( 'form_meta_duplicate', $p['update_code'] ?? null, 'a write must refuse on duplicate rows too' );
        Agentshell_Test::assert_true( true === ( $p['both_rows_unchanged'] ?? false ), 'neither duplicate row may be rewritten' );
        Agentshell_Test::assert_true( true === ( $p['audited'] ?? false ), 'a detected duplicate must be recorded in the audit log' );
    }
);

/* ---------------------------------------------------------------------------
 * Publication
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: publish promotes the working definition and records a version',
    function () {
        $p = fst( 'p8_publish' );

        Agentshell_Test::assert_false( (bool) ( $p['is_error'] ?? true ), 'a valid publication must succeed: ' . ( $p['code'] ?? '' ) );
        Agentshell_Test::assert_equals( 1, (int) ( $p['version'] ?? -1 ), 'the first publication must be version 1' );
        Agentshell_Test::assert_true( true === ( $p['has_been_published'] ?? false ), 'the form must know it has been published' );
        Agentshell_Test::assert_equals( 'published', $p['published_status'] ?? null, 'the published definition must say it is published' );
        Agentshell_Test::assert_equals( 1, (int) ( $p['published_version'] ?? -1 ), 'the published definition must carry the version' );
        Agentshell_Test::assert_true( '' !== (string) ( $p['published_name'] ?? '' ), 'the published definition must be the working one' );
        Agentshell_Test::assert_true( true === ( $p['previous_absent'] ?? false ), 'a first publication has no previous definition' );
        Agentshell_Test::assert_equals( 'publish', $p['post_status'] ?? null, 'the post status records "has been published"' );

        $counts = isset( $p['row_counts'] ) && is_array( $p['row_counts'] ) ? $p['row_counts'] : array();
        foreach ( $counts as $key => $count ) {
            Agentshell_Test::assert_true( (int) $count <= 1, 'publication must not duplicate the row for ' . $key );
        }
        Agentshell_Test::assert_equals(
            1,
            (int) ( $counts[ '_agentshell_form_published' ] ?? -1 ),
            'a publication must create exactly one published row'
        );
    }
);

Agentshell_Test::run(
    'forms storage: a publication against a stale revision is refused',
    function () {
        $p = fst( 'p9_publish_stale' );

        Agentshell_Test::assert_true( true === ( $p['is_error'] ?? false ), 'a stale publication must be refused' );
        Agentshell_Test::assert_equals( 'form_revision_mismatch', $p['code'] ?? null, 'the refusal must be named' );
        Agentshell_Test::assert_true( true === ( $p['version_unchanged'] ?? false ), 'a refused publication must not bump the version' );
        Agentshell_Test::assert_true( true === ( $p['published_unchanged'] ?? false ), 'a refused publication must not change what is live' );
    }
);

Agentshell_Test::run(
    'forms storage: an invalid definition cannot be published',
    function () {
        $p = fst( 'p10_publish_invalid' );

        Agentshell_Test::assert_true( true === ( $p['is_error'] ?? false ), 'an invalid working definition must not be published' );
        Agentshell_Test::assert_equals( 'form_publish_invalid', $p['code'] ?? null, 'the refusal must be named' );
        Agentshell_Test::assert_true(
            in_array( 'form_unknown_key', fst_codes( $p ), true ),
            'the refusal must name the rule that was broken'
        );
        Agentshell_Test::assert_true( true === ( $p['published_absent'] ?? false ), 'a refused publication must leave no published definition' );
        Agentshell_Test::assert_true( true === ( $p['version_still_zero'] ?? false ), 'a refused publication must not record a version' );
    }
);

Agentshell_Test::run(
    'forms storage: the slug freezes after the first publication',
    function () {
        $p = fst( 'p11_slug_frozen' );

        Agentshell_Test::assert_true( true === ( $p['is_error'] ?? false ), 'a published form must not be renamed' );
        Agentshell_Test::assert_equals( 'form_slug_frozen', $p['code'] ?? null, 'the refusal must be named' );
        Agentshell_Test::assert_true( true === ( $p['slug_unchanged'] ?? false ), 'the slug must be exactly what it was' );
    }
);

Agentshell_Test::run(
    'forms storage: discard reverts the working definition to the published one',
    function () {
        $p = fst( 'p12_discard' );

        Agentshell_Test::assert_true( true === ( $p['edit_ok'] ?? false ), 'the probe must have staged an edit to discard' );
        Agentshell_Test::assert_true( true === ( $p['uncommitted_present'] ?? false ), 'the staged edit must really be in the working row' );
        Agentshell_Test::assert_false( (bool) ( $p['is_error'] ?? true ), 'discard must succeed on a published form: ' . ( $p['code'] ?? '' ) );
        Agentshell_Test::assert_true( true === ( $p['working_equals_published'] ?? false ), 'after a discard the working row must be byte-identical to the published row' );
        Agentshell_Test::assert_true( true === ( $p['revision_advanced'] ?? false ), 'a discard must advance the revision so a concurrent editor is not silently overwritten' );

        $u = fst( 'p12b_discard_unpublished' );
        Agentshell_Test::assert_true( true === ( $u['is_error'] ?? false ), 'a form that was never published has nothing to discard to' );
        Agentshell_Test::assert_equals( 'form_not_published', $u['code'] ?? null, 'the refusal must be named' );
    }
);

Agentshell_Test::run(
    'forms storage: a failure during publication leaves the form internally consistent',
    function () {
        $p = fst( 'p13_publication_failure' );

        Agentshell_Test::assert_true( true === ( $p['is_error'] ?? false ), 'the injected failure must refuse the publication' );
        Agentshell_Test::assert_true(
            in_array( (string) ( $p['code'] ?? '' ), array( 'form_state_changed', 'form_write_failed' ), true ),
            'the refusal must be the write that failed, not a silent success: ' . ( $p['code'] ?? '' )
        );

        // The writes that had already happened in the same transaction must be
        // gone. This is the whole point of wrapping publication in a guard.
        Agentshell_Test::assert_true( true === ( $p['published_absent'] ?? false ), 'no published definition may survive a failed publication' );
        Agentshell_Test::assert_true( true === ( $p['previous_absent'] ?? false ), 'no previous definition may survive a failed publication' );
        Agentshell_Test::assert_true( true === ( $p['version_row_restored'] ?? false ), 'the rolled-back row must still be exactly one row' );
        Agentshell_Test::assert_true( true === ( $p['version_unchanged'] ?? false ), 'the version must be exactly what it was before the failure' );

        $state = isset( $p['state_after'] ) && is_array( $p['state_after'] ) ? $p['state_after'] : array();
        Agentshell_Test::assert_false( (bool) ( $state['is_error'] ?? true ), 'the form must still be readable after the failure' );
        Agentshell_Test::assert_true( true === ( $state['published'] ?? false ), 'the form must have no published definition after the failure' );
        Agentshell_Test::assert_equals( 0, (int) ( $state['version'] ?? -1 ), 'the form must still be version 0' );
        Agentshell_Test::assert_false( (bool) ( $state['has_been_published'] ?? true ), 'the form must not claim to have been published' );
        Agentshell_Test::assert_equals( 'draft', $p['post_status'] ?? null, 'a failed first publication must leave the post a draft' );
    }
);

/* ---------------------------------------------------------------------------
 * Revisions
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: a native revision records the definition metadata',
    function () {
        $p = fst( 'p14_revisions' );

        Agentshell_Test::assert_true( (int) ( $p['count'] ?? 0 ) >= 1, 'a publication must leave at least one native revision' );
        Agentshell_Test::assert_equals( 'revision', $p['revision_type'] ?? null, 'the record must be a WordPress revision, not a custom row' );
        Agentshell_Test::assert_true( true === ( $p['parent_is_form'] ?? false ), 'the revision must be a child of the form' );
        Agentshell_Test::assert_true( true === ( $p['has_working_meta'] ?? false ), 'the revision must carry the working definition' );
        Agentshell_Test::assert_true( true === ( $p['has_published_meta'] ?? false ), 'the revision must carry the published definition' );
        Agentshell_Test::assert_true( true === ( $p['working_is_definition'] ?? false ), 'the revisionized working meta must be the definition document' );
        Agentshell_Test::assert_true( true === ( $p['published_is_definition'] ?? false ), 'the revisionized published meta must be the definition document' );
        Agentshell_Test::assert_true(
            true === ( $p['published_matches_live'] ?? false ),
            'the revision must record the definition that was actually published, byte for byte'
        );
        Agentshell_Test::assert_equals( 1, (int) ( $p['published_version'] ?? -1 ), 'the revision must carry the version it recorded' );
    }
);

Agentshell_Test::run(
    'forms storage: the documented PHP API reads the definitions the store wrote',
    function () {
        $p = fst( 'p14b_api' );

        Agentshell_Test::assert_true( true === ( $p['functions_exist'] ?? false ), 'the documented API functions must exist' );
        Agentshell_Test::assert_equals( 10, (int) ( $p['field_type_count'] ?? -1 ), 'the API must expose the ten MVP field types' );
        Agentshell_Test::assert_true( true === ( $p['working_is_array'] ?? false ), 'get_working must return the working definition' );
        Agentshell_Test::assert_true( true === ( $p['published_is_array'] ?? false ), 'get_published must return the published definition' );
        Agentshell_Test::assert_true( true === ( $p['working_matches_row'] ?? false ), 'get_working must be the stored document, not a re-serialised copy of something else' );
        Agentshell_Test::assert_true( true === ( $p['published_matches_row'] ?? false ), 'get_published must be the stored document' );
        Agentshell_Test::assert_true( true === ( $p['by_post_id_resolves'] ?? false ), 'the API must accept a post id as well as an internal id' );
        Agentshell_Test::assert_true( true === ( $p['unknown_is_null'] ?? false ), 'an unknown form must be null, not a guess' );
    }
);

/* ---------------------------------------------------------------------------
 * The protocol tables
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: the challenge and rate-limit tables carry the keys their atomicity relies on',
    function () {
        global $wpdb;
        $p = fst( 'p15_tables' );

        Agentshell_Test::assert_true( true === ( $p['challenges_exist'] ?? false ), 'the challenge table must exist' );
        Agentshell_Test::assert_true( true === ( $p['rate_limits_exist'] ?? false ), 'the rate-limit table must exist' );
        Agentshell_Test::assert_true( true === ( $p['token_hash_unique'] ?? false ), 'the challenge token hash must be UNIQUE — the claim depends on it' );
        Agentshell_Test::assert_true( true === ( $p['bucket_unique'] ?? false ), 'the rate-limit bucket must be UNIQUE — the counter depends on it' );

        // The tables are the plugin's own, in the site's prefix, and nothing else.
        $prefix = isset( $p['challenges_table'] ) ? (string) $p['challenges_table'] : '';
        Agentshell_Test::assert_true( 0 === strpos( $prefix, $wpdb->prefix ), 'the tables must live under the site prefix' );
        Agentshell_Test::assert_true( false !== strpos( $prefix, 'agentshell_form' ), 'the tables must be plugin-namespaced' );
    }
);

/* ---------------------------------------------------------------------------
 * The site is left as it was found
 * ------------------------------------------------------------------------ */

Agentshell_Test::run(
    'forms storage: the site is left exactly as it was found',
    function () {
        $p = fst( 'p16_cleanup' );

        Agentshell_Test::assert_equals( array(), $p['leftovers'] ?? null, 'every disposable form must be gone' );
        Agentshell_Test::assert_equals( 0, (int) ( $p['stray_meta'] ?? -1 ), 'no orphaned definition metadata may remain' );
    }
);
