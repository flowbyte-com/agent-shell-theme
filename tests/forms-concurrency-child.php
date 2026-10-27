<?php
/**
 * Child half of the forms concurrency suite — one contender per process.
 *
 * Concurrency cannot be demonstrated inside a single PHP process: `$wpdb` is
 * one connection, `Form_DB`'s transaction depth is one static, and a named
 * advisory lock taken twice in the same connection is re-entrant. The only
 * honest test is separate operating-system processes, each with its own MySQL
 * connection, released at the same instant by a file barrier.
 *
 * argv: 1 wp_root  2 mode  3 report path  4 ready path  5 go path  6.. mode args
 *
 * Every mode follows the same shape — boot WordPress, announce readiness,
 * wait for the barrier, then perform exactly one operation — so the parent can
 * release every contender within the same millisecond.
 */

$wp_root = $argv[1] ?? '';
$mode    = $argv[2] ?? '';
$report  = $argv[3] ?? '';
$ready   = $argv[4] ?? '';
$go      = $argv[5] ?? '';
$args    = array_slice( $argv, 6 );

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
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Rate_Limits;
use AgentShell_Forms\Form_Schema;

global $wpdb;

$out = array( 'mode' => $mode, 'pid' => getmypid(), 'args' => $args );

/**
 * Publish the report and stop.
 *
 * @param array $data Report.
 * @return void
 */
function done( array $data ) {
    global $report;
    file_put_contents( $report, wp_json_encode( $data ) );
    echo "reported\n";
    exit( 0 );
}

/**
 * Announce readiness and wait until the parent opens the barrier.
 *
 * @return bool False when the barrier never opened.
 */
function wait_for_go() {
    global $ready, $go;

    if ( '' !== $ready ) {
        file_put_contents( $ready, '1' );
    }

    $deadline = microtime( true ) + 120;
    while ( microtime( true ) < $deadline ) {
        clearstatcache( true, $go );
        if ( '' !== $go && is_file( $go ) ) {
            return true;
        }
        usleep( 2000 );
    }
    return false;
}

/**
 * The field list every fixture uses.
 *
 * @param string $suffix Label suffix.
 * @return array
 */
function f_fields( $suffix = '' ) {
    return array(
        array( 'key' => 'name', 'type' => 'text', 'label' => 'Your name' . $suffix, 'required' => true ),
        array( 'key' => 'email', 'type' => 'email', 'label' => 'Your email', 'required' => true ),
    );
}

/**
 * A complete, valid definition document.
 *
 * @param string $form_id        Internal identifier.
 * @param string $slug           Slug.
 * @param string $name           Form name.
 * @param string $field_suffix   Label suffix.
 * @return array
 */
function f_doc( $form_id, $slug, $name, $field_suffix = '' ) {
    $doc = Form_Schema::defaults( (string) $form_id, (string) $slug, (string) $name );
    $doc['fields']   = f_fields( $field_suffix );
    $doc['settings'] = Form_Schema::settings_defaults();
    return $doc;
}

/**
 * Every raw row for a key on a post.
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

switch ( $mode ) {

    /* ---------------------------------------------------------------------
     * Preparation — runs alone, before any race.
     * ------------------------------------------------------------------ */

    case 'prepare':
        $token = (string) ( $args[0] ?? 'AScx0' );
        $slug  = 'cx-' . strtolower( $token );

        // 1. A form for the update race, at revision 0.
        $update_form = Form_Definition::create(
            array( 'name' => 'Concurrency update ' . $token, 'slug' => $slug . '-update', 'fields' => f_fields() ),
            0
        );
        if ( is_wp_error( $update_form ) ) {
            done( array( 'error' => 'update_form_create', 'code' => $update_form->get_error_code() ) );
        }

        // 2. A form for the publication race, at revision 0, never published.
        $publish_form = Form_Definition::create(
            array( 'name' => 'Concurrency publish ' . $token, 'slug' => $slug . '-publish', 'fields' => f_fields() ),
            0
        );
        if ( is_wp_error( $publish_form ) ) {
            done( array( 'error' => 'publish_form_create', 'code' => $publish_form->get_error_code() ) );
        }

        // 3. A bare form post with no definition rows at all, for the
        //    authoritative-row creation race. It is a real form post so the
        //    post row itself is not the thing under test.
        $bare_id = (int) wp_insert_post(
            array(
                'post_type'   => Form_Post_Types::FORM,
                'post_status' => 'draft',
                'post_title'  => 'Concurrency bare ' . $token,
            ),
            true
        );
        if ( $bare_id < 1 ) {
            done( array( 'error' => 'bare_post_create' ) );
        }

        // 4. A challenge token for the claim race. It is minted for a real
        //    published form so a claim is a realistic operation.
        $challenge_form = Form_Definition::create(
            array( 'name' => 'Concurrency challenge ' . $token, 'slug' => $slug . '-challenge', 'fields' => f_fields() ),
            0
        );
        if ( is_wp_error( $challenge_form ) ) {
            done( array( 'error' => 'challenge_form_create', 'code' => $challenge_form->get_error_code() ) );
        }
        $challenge_publish = Form_Definition::publish( (string) $challenge_form['form_id'], (int) $challenge_form['revision'], 0 );
        if ( is_wp_error( $challenge_publish ) ) {
            done( array( 'error' => 'challenge_form_publish', 'code' => $challenge_publish->get_error_code() ) );
        }

        // 6. Four independent forms, one per contender, for the audit-integrity
        //    race: concurrent appends must not lose one another's entries.
        $audit_forms = array();
        for ( $i = 0; $i < 4; $i++ ) {
            $made = Form_Definition::create(
                array( 'name' => 'Concurrency audit ' . $token . ' #' . $i, 'slug' => $slug . '-audit-' . $i, 'fields' => f_fields() ),
                0
            );
            if ( is_wp_error( $made ) ) {
                done( array( 'error' => 'audit_form_create', 'code' => $made->get_error_code() ) );
            }
            $audit_forms[] = array(
                'post_id'  => (int) $made['post_id'],
                'form_id'  => (string) $made['form_id'],
                'revision' => (int) $made['revision'],
            );
        }

        // 7. A form for the slug race, at revision 0, never published.
        $slug_race_form = Form_Definition::create(
            array( 'name' => 'Concurrency slug ' . $token, 'slug' => $slug . '-slugrace', 'fields' => f_fields() ),
            0
        );
        if ( is_wp_error( $slug_race_form ) ) {
            done( array( 'error' => 'slug_form_create', 'code' => $slug_race_form->get_error_code() ) );
        }

        // 8. Two forms for the paused-publisher race, never published, so the
        //    version a refused publication must not create is version 1.
        $pause_forms = array();
        for ( $i = 0; $i < 2; $i++ ) {
            $made = Form_Definition::create(
                array( 'name' => 'Concurrency paused ' . $token . ' #' . $i, 'slug' => $slug . '-paused-' . $i, 'fields' => f_fields() ),
                0
            );
            if ( is_wp_error( $made ) ) {
                done( array( 'error' => 'pause_form_create', 'code' => $made->get_error_code() ) );
            }
            $pause_forms[] = array(
                'post_id'  => (int) $made['post_id'],
                'form_id'  => (string) $made['form_id'],
                'revision' => (int) $made['revision'],
                'version'  => (int) $made['version'],
            );
        }

        // 9. One more form for the same-form audit race.
        $shared_audit = Form_Definition::create(
            array( 'name' => 'Concurrency shared audit ' . $token, 'slug' => $slug . '-audit-shared', 'fields' => f_fields() ),
            0
        );
        if ( is_wp_error( $shared_audit ) ) {
            done( array( 'error' => 'shared_audit_create', 'code' => $shared_audit->get_error_code() ) );
        }
        $audit_forms[] = array(
            'post_id'  => (int) $shared_audit['post_id'],
            'form_id'  => (string) $shared_audit['form_id'],
            'revision' => (int) $shared_audit['revision'],
        );

        $token_value = Form_Challenges::mint(
            (string) $challenge_form['form_id'],
            (int) $challenge_publish['version'],
            '',
            Form_Challenges::DEFAULT_TTL
        );
        if ( is_wp_error( $token_value ) ) {
            done( array( 'error' => 'challenge_mint', 'code' => $token_value->get_error_code() ) );
        }

        done(
            array(
                'token'   => $token,
                'update'  => array(
                    'post_id'  => (int) $update_form['post_id'],
                    'form_id'  => (string) $update_form['form_id'],
                    'revision' => (int) $update_form['revision'],
                ),
                'publish' => array(
                    'post_id'  => (int) $publish_form['post_id'],
                    'form_id'  => (string) $publish_form['form_id'],
                    'revision' => (int) $publish_form['revision'],
                    'version'  => (int) $publish_form['version'],
                ),
                'bare'    => array(
                    'post_id' => $bare_id,
                ),
                'challenge' => array(
                    'form_id' => (string) $challenge_form['form_id'],
                    'version' => (int) $challenge_publish['version'],
                    'token'   => (string) $token_value,
                ),
                'audit'   => $audit_forms,
                'slug_race' => array(
                    'post_id'  => (int) $slug_race_form['post_id'],
                    'form_id'  => (string) $slug_race_form['form_id'],
                    'slug'     => (string) $slug_race_form['slug'],
                    'revision' => (int) $slug_race_form['revision'],
                ),
                'pause'   => $pause_forms,
                'post_ids' => array_merge(
                    array(
                        (int) $update_form['post_id'],
                        (int) $publish_form['post_id'],
                        $bare_id,
                        (int) $challenge_form['post_id'],
                        (int) $slug_race_form['post_id'],
                    ),
                    array_map(
                        function ( $row ) {
                            return (int) $row['post_id'];
                        },
                        array_merge( $audit_forms, $pause_forms )
                    )
                ),
            )
        );

    /* ---------------------------------------------------------------------
     * Race A — two creators, the same slug.
     * ------------------------------------------------------------------ */

    case 'create_same_slug':
        $slug  = (string) ( $args[0] ?? 'cx-same-slug' );
        $token = (string) ( $args[1] ?? '' );

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $result = Form_Definition::create(
            array( 'name' => 'Same slug contender ' . $token, 'slug' => $slug, 'fields' => f_fields() ),
            0
        );

        if ( is_wp_error( $result ) ) {
            done(
                array(
                    'created'    => false,
                    'code'       => $result->get_error_code(),
                    'slug'       => $slug,
                    'posts_with_slug' => array_map(
                        'intval',
                        Form_Definition::find_post_ids_by_meta( Form_Post_Types::META_SLUG, $slug )
                    ),
                )
            );
        }

        done(
            array(
                'created'   => true,
                'post_id'   => (int) $result['post_id'],
                'form_id'   => (string) $result['form_id'],
                'slug'      => (string) $result['slug'],
                'row_counts' => row_counts( (int) $result['post_id'] ),
                'posts_with_slug' => array_map(
                    'intval',
                    Form_Definition::find_post_ids_by_meta( Form_Post_Types::META_SLUG, $slug )
                ),
            )
        );

    /* ---------------------------------------------------------------------
     * Race B — two writers creating the same authoritative row.
     * ------------------------------------------------------------------ */

    case 'ensure_row':
        $post_id  = (int) ( $args[0] ?? 0 );
        $value    = (string) ( $args[1] ?? '' );

        $before = Form_DB::read_one( $post_id, Form_Post_Types::META_WORKING );

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $result = Form_Definition::ensure_row( $post_id, Form_Post_Types::META_WORKING, $value );
        $rows   = raw_rows( $post_id, Form_Post_Types::META_WORKING );

        done(
            array(
                'saw_absent'      => 'absent' === $before['status'],
                'is_error'        => is_wp_error( $result ),
                'code'            => is_wp_error( $result ) ? $result->get_error_code() : '',
                'meta_id'         => is_wp_error( $result ) ? 0 : (int) $result,
                'row_count'       => count( $rows ),
                'meta_ids'        => array_map( function ( $r ) { return (int) $r['meta_id']; }, $rows ),
                'stored_values'   => array_map( function ( $r ) { return (string) $r['meta_value']; }, $rows ),
            )
        );

    /* ---------------------------------------------------------------------
     * Race C — two editors, the same expected revision.
     * ------------------------------------------------------------------ */

    case 'update':
        $form_id  = (string) ( $args[0] ?? '' );
        $revision = (int) ( $args[1] ?? 0 );
        $marker   = (string) ( $args[2] ?? '' );
        $started  = time();

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $result = Form_Definition::update_working(
            $form_id,
            array(
                'schema_version' => AGENTSHELL_FORMS_SCHEMA_VERSION,
                'name'           => $marker,
                'fields'         => f_fields( ' ' . $marker ),
                'settings'       => Form_Schema::settings_defaults(),
            ),
            $revision,
            0
        );

        done(
            array(
                'marker'     => $marker,
                'ok'         => ! is_wp_error( $result ),
                'code'       => is_wp_error( $result ) ? $result->get_error_code() : '',
                'revision'   => is_wp_error( $result ) ? -1 : (int) $result['revision'],
                'working_name' => is_wp_error( $result ) ? '' : (string) ( $result['working']['name'] ?? '' ),
                'started_at' => $started,
                'finished_at' => time(),
            )
        );

    /* ---------------------------------------------------------------------
     * Race D — two publishers, the same expected revision.
     * ------------------------------------------------------------------ */

    case 'publish':
        $form_id  = (string) ( $args[0] ?? '' );
        $revision = (int) ( $args[1] ?? 0 );

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $result = Form_Definition::publish( $form_id, $revision, 0 );

        done(
            array(
                'ok'      => ! is_wp_error( $result ),
                'code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
                'version' => is_wp_error( $result ) ? -1 : (int) $result['version'],
            )
        );

    /* ---------------------------------------------------------------------
     * Race E — many claims of one challenge token.
     * ------------------------------------------------------------------ */

    case 'challenge_claim':
        $token_value = (string) ( $args[0] ?? '' );

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $claim = Form_Challenges::claim( $token_value );

        done(
            array(
                'status'        => (string) ( $claim['status'] ?? '' ),
                'form_id'       => (string) ( $claim['form_id'] ?? '' ),
                'form_version'  => (int) ( $claim['form_version'] ?? 0 ),
                'submission_id' => (int) ( $claim['submission_id'] ?? 0 ),
            )
        );

    /* ---------------------------------------------------------------------
     * Race F — many reservations against one rate-limit bucket.
     * ------------------------------------------------------------------ */

    case 'rate_reserve':
        $scope   = (string) ( $args[0] ?? Form_Rate_Limits::SCOPE_FORM );
        $subject = (string) ( $args[1] ?? '' );
        $form_id = (string) ( $args[2] ?? '' );
        $limit   = (int) ( $args[3] ?? 5 );
        $window  = (int) ( $args[4] ?? 300 );
        $time    = (int) ( $args[5] ?? time() );

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $result = Form_Rate_Limits::reserve( $scope, $subject, $form_id, $limit, $window, $time );

        done(
            array(
                'admitted'     => (bool) ( $result['admitted'] ?? false ),
                'hits'         => (int) ( $result['hits'] ?? 0 ),
                'limit'        => (int) ( $result['limit'] ?? 0 ),
                'window_start' => (int) ( $result['window_start'] ?? 0 ),
                'error'        => is_wp_error( $result['error'] ?? null ) ? $result['error']->get_error_code() : '',
            )
        );

    /* ---------------------------------------------------------------------
     * Race G — a publisher paused between its read and its lock.
     *
     * The publisher announces what it read, then waits. While it waits, a
     * separate process commits an edit to the same form with the same actor.
     * The publisher's `expected_revision` check has already passed and cannot
     * help it; only the verification *inside* the transaction can refuse the
     * publication. What it must never do is commit the definition it read.
     * ------------------------------------------------------------------ */

    case 'publish_paused':
        $form_id  = (string) ( $args[0] ?? '' );
        $revision = (int) ( $args[1] ?? 0 );
        $paused   = (string) ( $args[2] ?? '' );
        $resume   = (string) ( $args[3] ?? '' );
        $started  = time();

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $read_revision = -1;
        $read_name     = '';

        $handler = function ( $fid, $rev, $state ) use ( $form_id, $paused, $resume, &$read_revision, &$read_name ) {
            if ( (string) $fid !== $form_id ) {
                return;
            }

            $read_revision = (int) ( $state['revision'] ?? -1 );
            $read_name     = is_array( $state['working'] ) ? (string) ( $state['working']['name'] ?? '' ) : '';

            if ( '' !== $paused ) {
                file_put_contents(
                    $paused,
                    wp_json_encode(
                        array(
                            'revision'  => $read_revision,
                            'name'      => $read_name,
                            'at'        => time(),
                            'pid'       => getmypid(),
                        )
                    )
                );
            }

            $deadline = microtime( true ) + 90;
            while ( '' !== $resume && microtime( true ) < $deadline ) {
                clearstatcache( true, $resume );
                if ( is_file( $resume ) ) {
                    return;
                }
                usleep( 2000 );
            }
        };

        add_action( 'agentshell_forms_before_publish_write', $handler, 10, 3 );
        $result = Form_Definition::publish( $form_id, $revision, 0 );
        remove_action( 'agentshell_forms_before_publish_write', $handler, 10 );

        $published_doc = is_wp_error( $result ) || ! is_array( $result['published'] )
            ? null
            : $result['published'];

        done(
            array(
                'ok'             => ! is_wp_error( $result ),
                'code'           => is_wp_error( $result ) ? $result->get_error_code() : '',
                'version'        => is_wp_error( $result ) ? -1 : (int) $result['version'],
                'published_name' => is_array( $published_doc ) ? (string) ( $published_doc['name'] ?? '' ) : '',
                'published_slug' => is_array( $published_doc ) ? (string) ( $published_doc['slug'] ?? '' ) : '',
                'read_revision'  => $read_revision,
                'read_name'      => $read_name,
                'started_at'     => $started,
                'finished_at'    => time(),
            )
        );

    /* ---------------------------------------------------------------------
     * The concurrent editor, in two shapes: one that writes a different
     * definition, and one that writes the identical document so that only the
     * revision row can tell the publisher anything changed.
     * ------------------------------------------------------------------ */

    case 'update_identical':
        $form_id  = (string) ( $args[0] ?? '' );
        $revision = (int) ( $args[1] ?? 0 );

        $before = Form_Definition::get( $form_id );
        if ( is_wp_error( $before ) ) {
            done( array( 'error' => $before->get_error_code() ) );
        }

        $before_rows = raw_rows( (int) $before['post_id'], Form_Post_Types::META_WORKING );
        $before_bytes = $before_rows ? (string) $before_rows[0]['meta_value'] : '';
        $started = time();

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $result = Form_Definition::update_working( $form_id, $before['working'], $revision, 0 );

        $after_rows  = raw_rows( (int) $before['post_id'], Form_Post_Types::META_WORKING );
        $after_bytes = $after_rows ? (string) $after_rows[0]['meta_value'] : '';

        done(
            array(
                'ok'            => ! is_wp_error( $result ),
                'code'          => is_wp_error( $result ) ? $result->get_error_code() : '',
                'revision'      => is_wp_error( $result ) ? -1 : (int) $result['revision'],
                'bytes_changed' => $before_bytes !== $after_bytes,
                'started_at'    => $started,
                'finished_at'   => time(),
            )
        );

    /* ---------------------------------------------------------------------
     * Race H — a slug change against an editor that read the same revision.
     * ------------------------------------------------------------------ */

    case 'set_slug':
        $form_id = (string) ( $args[0] ?? '' );
        $slug    = (string) ( $args[1] ?? '' );

        if ( ! wait_for_go() ) {
            done( array( 'barrier' => 'timeout' ) );
        }

        $result = Form_Definition::set_slug( $form_id, $slug, 0 );

        done(
            array(
                'ok'    => ! is_wp_error( $result ),
                'code'  => is_wp_error( $result ) ? $result->get_error_code() : '',
                'slug'  => is_wp_error( $result ) ? '' : (string) $result['slug'],
            )
        );

    /* ---------------------------------------------------------------------
     * Verification and cleanup — runs alone, after the races.
     * ------------------------------------------------------------------ */

    case 'verify_update':
        $form_id = (string) ( $args[0] ?? '' );
        $state   = Form_Definition::get( $form_id );
        if ( is_wp_error( $state ) ) {
            done( array( 'error' => $state->get_error_code() ) );
        }
        $rows = raw_rows( (int) $state['post_id'], Form_Post_Types::META_WORKING );
        done(
            array(
                'revision'   => (int) $state['revision'],
                'working_name' => (string) ( $state['working']['name'] ?? '' ),
                'working_bytes' => $rows ? (string) $rows[0]['meta_value'] : '',
                'row_counts' => row_counts( (int) $state['post_id'] ),
            )
        );

    case 'verify_publish':
        $form_id = (string) ( $args[0] ?? '' );
        $state   = Form_Definition::get( $form_id );
        if ( is_wp_error( $state ) ) {
            done( array( 'error' => $state->get_error_code() ) );
        }
        $post_id = (int) $state['post_id'];
        $pub     = raw_rows( $post_id, Form_Post_Types::META_PUBLISHED );
        $ver     = raw_rows( $post_id, Form_Post_Types::META_VERSION );
        $prev    = raw_rows( $post_id, Form_Post_Types::META_PUBLISHED_PREVIOUS );
        $work    = raw_rows( $post_id, Form_Post_Types::META_WORKING );
        $revs    = wp_get_post_revisions( $post_id, array( 'numberposts' => 20 ) );
        $pub_doc = $pub ? json_decode( (string) $pub[0]['meta_value'], true ) : null;
        $work_doc = $work ? json_decode( (string) $work[0]['meta_value'], true ) : null;

        done(
            array(
                'version'            => (int) $state['version'],
                'revision'           => (int) $state['revision'],
                'has_been_published' => (bool) $state['has_been_published'],
                'published_rows'     => count( $pub ),
                'version_rows'       => count( $ver ),
                'previous_rows'      => count( $prev ),
                'published_bytes'    => $pub ? (string) $pub[0]['meta_value'] : '',
                'working_bytes'      => $work ? (string) $work[0]['meta_value'] : '',
                'published_version'  => is_array( $state['published'] ) ? (int) ( $state['published']['version'] ?? -1 ) : -1,
                'published_name'     => is_array( $pub_doc ) ? (string) ( $pub_doc['name'] ?? '' ) : '',
                'working_name'       => is_array( $work_doc ) ? (string) ( $work_doc['name'] ?? '' ) : '',
                'published_fields'   => is_array( $pub_doc ) && isset( $pub_doc['fields'] ) ? count( $pub_doc['fields'] ) : -1,
                'working_fields'     => is_array( $work_doc ) && isset( $work_doc['fields'] ) ? count( $work_doc['fields'] ) : -1,
                'revision_count'     => count( (array) $revs ),
                'post_status'        => (string) get_post_status( $post_id ),
            )
        );

    case 'verify_slug':
        $slug = (string) ( $args[0] ?? '' );
        done(
            array(
                'posts_with_slug' => array_map( 'intval', Form_Definition::find_post_ids_by_meta( Form_Post_Types::META_SLUG, $slug ) ),
            )
        );

    case 'verify_slug_state':
        $form_id = (string) ( $args[0] ?? '' );
        $state   = Form_Definition::get( $form_id );
        if ( is_wp_error( $state ) ) {
            done( array( 'error' => $state->get_error_code() ) );
        }

        $post_id     = (int) $state['post_id'];
        $slug_rows   = raw_rows( $post_id, Form_Post_Types::META_SLUG );
        $work_rows   = raw_rows( $post_id, Form_Post_Types::META_WORKING );
        $work_doc    = $work_rows ? json_decode( (string) $work_rows[0]['meta_value'], true ) : null;

        done(
            array(
                'slug_row'       => $slug_rows ? (string) $slug_rows[0]['meta_value'] : '',
                'working_slug'   => is_array( $work_doc ) ? (string) ( $work_doc['slug'] ?? '' ) : '',
                'state_slug'     => (string) $state['slug'],
                'revision'       => (int) $state['revision'],
                'slug_consistent' => true === ( $state['slug_consistent'] ?? null ),
                'row_counts'     => row_counts( $post_id ),
            )
        );

    case 'clear_audit':
        Form_Audit::clear();
        done( array( 'cleared' => true, 'entries' => count( Form_Audit::entries() ) ) );

    case 'verify_audit':
        // Args: the run token, then one form id per contender.
        $run_token = (string) ( $args[0] ?? '' );
        $form_ids  = array_slice( $args, 1 );

        $entries = Form_Audit::entries();
        $per_form = array();
        $per_event = array();
        $well_formed = 0;
        $tokens_found = 0;

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( isset( $entry['event'], $entry['at'], $entry['actor'], $entry['data'] ) ) {
                $well_formed++;
            }
            $fid   = is_array( $entry['data'] ?? null ) ? (string) ( $entry['data']['form_id'] ?? '' ) : '';
            $event = (string) ( $entry['event'] ?? '' );
            if ( '' !== $fid && in_array( $fid, $form_ids, true ) ) {
                $per_form[ $fid ] = (int) ( $per_form[ $fid ] ?? 0 ) + 1;
                if ( ! isset( $per_event[ $fid ] ) || ! is_array( $per_event[ $fid ] ) ) {
                    $per_event[ $fid ] = array();
                }
                $per_event[ $fid ][ $event ] = (int) ( $per_event[ $fid ][ $event ] ?? 0 ) + 1;
            }
            if ( false !== strpos( (string) wp_json_encode( $entry ), $run_token ) ) {
                $tokens_found++;
            }
        }

        done(
            array(
                'ring_is_array'  => is_array( $entries ),
                'ring_size'      => count( $entries ),
                'well_formed'    => $well_formed,
                'tokens_found'   => $tokens_found,
                'per_form'       => $per_form,
                'per_event'      => $per_event,
                'form_ids'       => $form_ids,
            )
        );

    case 'verify_challenge':
        $token_value = (string) ( $args[0] ?? '' );
        global $wpdb;
        $table = Form_Challenges::table();
        $hash  = hash( 'sha256', $token_value );
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT consumed_at, submission_id FROM {$table} WHERE token_hash = %s", $hash ),
            ARRAY_A
        );
        $again = Form_Challenges::claim( $token_value );
        $row2  = $wpdb->get_row(
            $wpdb->prepare( "SELECT consumed_at FROM {$table} WHERE token_hash = %s", $hash ),
            ARRAY_A
        );
        done(
            array(
                'exists'          => is_array( $row ),
                'consumed_at'     => is_array( $row ) ? (string) $row['consumed_at'] : '',
                'second_claim'    => (string) ( $again['status'] ?? '' ),
                'consumed_at_after' => is_array( $row2 ) ? (string) $row2['consumed_at'] : '',
            )
        );

    case 'verify_rate_limit':
        $scope   = (string) ( $args[0] ?? Form_Rate_Limits::SCOPE_FORM );
        $subject = (string) ( $args[1] ?? '' );
        $form_id = (string) ( $args[2] ?? '' );
        $window  = (int) ( $args[3] ?? 300 );
        $time    = (int) ( $args[4] ?? time() );
        global $wpdb;
        $table = Form_Rate_Limits::table();
        $start = Form_Rate_Limits::window_start( $window, $time );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT scope, subject_hash, form_id, window_start, hits FROM {$table} WHERE form_id = %s",
                $form_id
            ),
            ARRAY_A
        );
        done(
            array(
                'hits'         => Form_Rate_Limits::hits( $scope, $subject, $form_id, $window, $time ),
                'rows'         => is_array( $rows ) ? $rows : array(),
                'window_start' => $start,
            )
        );

    case 'cleanup':
        $token = (string) ( $args[0] ?? '' );
        $ids   = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_title LIKE %s",
                Form_Post_Types::FORM,
                Form_Post_Types::ENTRY,
                '%' . $wpdb->esc_like( $token ) . '%'
            )
        );
        $deleted = array();
        foreach ( (array) $ids as $id ) {
            $deleted[ (int) $id ] = (bool) wp_delete_post( (int) $id, true );
        }

        // Ephemeral protocol rows and the audit ring are bookkeeping, not
        // content; this suite leaves neither behind.
        Form_Challenges::delete_all();
        Form_Rate_Limits::delete_all();

        $stray = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
                  WHERE p.ID IS NULL AND m.meta_key LIKE %s",
                $wpdb->esc_like( '_agentshell_form' ) . '%'
            )
        );

        $left = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_title LIKE %s",
                Form_Post_Types::FORM,
                Form_Post_Types::ENTRY,
                '%' . $wpdb->esc_like( $token ) . '%'
            )
        );

        done(
            array(
                'deleted'    => $deleted,
                'leftovers'  => array_map( 'intval', (array) $left ),
                'stray_meta' => $stray,
            )
        );

    default:
        done( array( 'error' => 'unknown_mode', 'mode' => $mode ) );
}
