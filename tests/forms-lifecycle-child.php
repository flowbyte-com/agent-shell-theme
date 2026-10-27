<?php
/**
 * Child half of the forms lifecycle suite.
 *
 * Each mode performs one lifecycle step and reports what the site looks like
 * afterwards. The steps are separate processes because an uninstall is
 * genuinely a different request from the one that activated the plugin — and
 * because `uninstall.php` is written to run without the plugin's classes
 * loaded, which is a condition worth reproducing.
 *
 * argv: 1 wp_root  2 mode  3 report path  4 token
 */

$wp_root = $argv[1] ?? '';
$mode    = $argv[2] ?? '';
$report  = $argv[3] ?? '';
$token   = $argv[4] ?? 'ASlife0';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

require_once __DIR__ . '/agentshell-test-bed.php';
agentshell_destructive_child_identity_guard( $wp_root, __FILE__ );

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

use AgentShell_Forms\Form_Audit;
use AgentShell_Forms\Form_Capabilities;
use AgentShell_Forms\Form_Challenges;
use AgentShell_Forms\Form_Install;
use AgentShell_Forms\Form_Post_Types;
use AgentShell_Forms\Form_Rate_Limits;

global $wpdb;

/**
 * Every probe post, found by the run token in its title.
 *
 * @return array<int,int>
 */
function probe_posts() {
    global $wpdb, $token;
    return array_map(
        'intval',
        (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_title LIKE %s",
                Form_Post_Types::FORM,
                Form_Post_Types::ENTRY,
                '%' . $wpdb->esc_like( $token ) . '%'
            )
        )
    );
}

/**
 * The state of everything the lifecycle can affect.
 *
 * @return array
 */
function site_state() {
    global $wpdb, $token;

    $counts = array();
    foreach ( array( 'agentshell_form', 'agentshell_entry' ) as $type ) {
        $counts[ $type ] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type )
        );
    }

    $admin = get_role( 'administrator' );

    return array(
        'tables_exist'      => Form_Install::tables_exist(),
        'challenge_table'   => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Form_Challenges::table() ) ),
        'rate_table'        => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Form_Rate_Limits::table() ) ),
        'form_type'         => post_type_exists( 'agentshell_form' ),
        'entry_type'        => post_type_exists( 'agentshell_entry' ),
        'posts'             => $counts,
        'probe_posts'       => probe_posts(),
        'db_version_option' => get_option( AGENTSHELL_FORMS_DB_VERSION_OPTION, '__absent__' ),
        'audit_option'      => false !== get_option( Form_Audit::OPTION, false ),
        'cron_scheduled'    => false !== wp_next_scheduled( 'agentshell_forms_prune' ),
        'admin_has_forms'   => $admin instanceof WP_Role ? $admin->has_cap( Form_Capabilities::CAP_FORMS ) : null,
        'admin_has_submissions' => $admin instanceof WP_Role ? $admin->has_cap( Form_Capabilities::CAP_SUBMISSIONS ) : null,
        // Every meta row belonging to a probe post, whatever its key. This is
        // the "the data is still there" measurement, so it must not be
        // narrowed to a key the uninstall happens to touch.
        'probe_meta_rows'   => (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} m
                   INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                  WHERE p.post_title LIKE %s",
                '%' . $wpdb->esc_like( $token ) . '%'
            )
        ),
    );
}

$out = array( 'mode' => $mode );

switch ( $mode ) {

    case 'seed':
        // One form and one submission, each with definition metadata, so that
        // "the data survived" is a fact about rows rather than about a title.
        $form_id = (int) wp_insert_post(
            array(
                'post_type'    => Form_Post_Types::FORM,
                'post_status'  => 'draft',
                'post_title'   => 'Lifecycle probe form ' . $token,
                'post_content' => '',
            ),
            true
        );
        $entry_id = (int) wp_insert_post(
            array(
                'post_type'    => Form_Post_Types::ENTRY,
                'post_status'  => 'draft',
                'post_title'   => 'Lifecycle probe entry ' . $token,
                'post_content' => '',
            ),
            true
        );

        if ( $form_id > 0 ) {
            add_post_meta( $form_id, Form_Post_Types::META_FORM_ID, str_repeat( 'cd', 16 ) );
            add_post_meta( $form_id, Form_Post_Types::META_WORKING, '{"probe":true}' );
        }
        if ( $entry_id > 0 ) {
            add_post_meta( $entry_id, '_agentshell_entry_form_id', str_repeat( 'cd', 16 ) );
        }

        Form_Audit::record( 'lifecycle_probe', array( 'token' => $token ), 0 );

        $out['form_id'] = $form_id;
        $out['entry_id'] = $entry_id;
        $out['state']   = site_state();
        break;

    case 'deactivate':
        $before = site_state();
        Form_Install::deactivate();
        $out['before'] = $before;
        $out['after']  = site_state();
        break;

    case 'reactivate':
        $out['before'] = site_state();
        Form_Install::activate();
        $out['after']  = site_state();
        break;

    case 'uninstall_default':
        // No constants: the uninstall must remove nothing at all.
        $before = site_state();
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'agentshell-forms/agentshell-forms.php' );
        }
        $ran = include dirname( __DIR__ ) . '/agentshell-forms/uninstall.php';
        $out['include_returned'] = $ran;
        $out['before'] = $before;
        $out['after']  = site_state();
        break;

    case 'uninstall_data':
        // Bookkeeping only: the tables and the plugin's options go, the
        // operator's content stays.
        $before = site_state();
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'agentshell-forms/agentshell-forms.php' );
        }
        if ( ! defined( 'AGENTSHELL_FORMS_REMOVE_DATA_ON_UNINSTALL' ) ) {
            define( 'AGENTSHELL_FORMS_REMOVE_DATA_ON_UNINSTALL', true );
        }
        include dirname( __DIR__ ) . '/agentshell-forms/uninstall.php';
        $out['before'] = $before;
        $out['after']  = site_state();
        break;

    case 'uninstall_forms_only':
        // Fail-closed: the content decision without the bookkeeping decision
        // is an under-specified destructive request, and it must remove
        // nothing at all.
        $before = site_state();
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'agentshell-forms/agentshell-forms.php' );
        }
        if ( ! defined( 'AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL' ) ) {
            define( 'AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL', true );
        }
        include dirname( __DIR__ ) . '/agentshell-forms/uninstall.php';
        $out['before'] = $before;
        $out['after']  = site_state();
        break;

    case 'uninstall_all':
        // Bookkeeping AND content: the explicitly destructive decision.
        $before = site_state();
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'agentshell-forms/agentshell-forms.php' );
        }
        if ( ! defined( 'AGENTSHELL_FORMS_REMOVE_DATA_ON_UNINSTALL' ) ) {
            define( 'AGENTSHELL_FORMS_REMOVE_DATA_ON_UNINSTALL', true );
        }
        if ( ! defined( 'AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL' ) ) {
            define( 'AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL', true );
        }
        include dirname( __DIR__ ) . '/agentshell-forms/uninstall.php';
        $out['before'] = $before;
        $out['after']  = site_state();
        break;

    case 'restore':
        // Put the bed back the way the rest of the suite expects to find it.
        Form_Install::activate();
        $out['state'] = site_state();
        break;

    case 'sweep':
        $ids = probe_posts();
        $deleted = array();
        foreach ( $ids as $id ) {
            $deleted[ $id ] = (bool) wp_delete_post( (int) $id, true );
        }
        Form_Challenges::delete_all();
        Form_Rate_Limits::delete_all();
        Form_Audit::clear();
        $out['deleted']  = $deleted;
        $out['state']    = site_state();
        break;

    case 'state':
    default:
        $out['state'] = site_state();
        break;
}

file_put_contents( $report, wp_json_encode( $out ) );
echo "reported\n";
exit( 0 );
