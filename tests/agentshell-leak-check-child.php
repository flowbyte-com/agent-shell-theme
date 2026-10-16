<?php
/**
 * End-of-suite leak check. Runs inside a real WordPress install (the
 * test bed) and reports any rows that look like a per-run fixture that
 * the suites forgot to clean up. Writes the report to argv[2] as JSON.
 *
 * The check is conservative: it counts and surfaces, but does not
 * delete. A leaked row is a finding for the operator to investigate,
 * not an automatic repair.
 *
 * argv: 1 WordPress root  2 report path
 */

$wp_root = $argv[1] ?? '';
$report_path = $argv[2] ?? '';

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
    fwrite( STDERR, "no wp-load.php at {$wp_root}\n" );
    exit( 2 );
}

define( 'ABSPATH', rtrim( $wp_root, '/' ) . '/' );
define( 'WP_USE_THEMES', false );
require_once ABSPATH . 'wp-load.php';

global $wpdb;

// The four per-run prefixes the live suites use, plus the
// baseline-snapshot row for the agentshell_site_mode sentinel.
$title_prefixes = array( 'ASTB', 'ASjr', 'AScw', 'ASNav' );
$menu_prefix    = 'ASTB ';
$meta_key       = '_agentshell_journal_op';

$like_sql = array();
$like_args = array();
foreach ( $title_prefixes as $p ) {
    $like_sql[]  = 'post_title LIKE %s';
    $like_args[] = $p . '%';
}
$title_where = '(' . implode( ' OR ', $like_sql ) . ')';

$posts_with_fixture_title = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $title_where", $like_args )
);
$sample_titles = $wpdb->get_col(
    $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE $title_where LIMIT 10", $like_args )
) ?: array();

$posts_with_journal_marker = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
    $meta_key
) );

// `_agentshell_journal_op` lives on `wp_postmeta`, so a post with the
// marker has a row there even if the post itself is gone (a partial
// delete). The post count above (post_title LIKE) catches posts the
// test created and left; the meta count catches the marker rows the
// journal wrote that nothing cleaned up. Both are leaks.

$nav_menus_table = $wpdb->term_taxonomy;
$nav_terms_table = $wpdb->terms;
$nav_menus_with_fixture_prefix = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM $nav_terms_table t
       JOIN $nav_menus_table tt ON tt.term_id = t.term_id
      WHERE tt.taxonomy = 'nav_menu' AND t.name LIKE %s",
    $menu_prefix . '%'
) );
$sample_menu_names = $wpdb->get_col( $wpdb->prepare(
    "SELECT t.name FROM $nav_terms_table t
       JOIN $nav_menus_table tt ON tt.term_id = t.term_id
      WHERE tt.taxonomy = 'nav_menu' AND t.name LIKE %s LIMIT 10",
    $menu_prefix . '%'
) ) ?: array();

$report = array(
    'posts_with_fixture_title'        => $posts_with_fixture_title,
    'posts_with_journal_marker'       => $posts_with_journal_marker,
    'nav_menus_with_fixture_prefix'   => $nav_menus_with_fixture_prefix,
    'sample_post_titles'              => array_values( $sample_titles ),
    'sample_menu_names'               => array_values( $sample_menu_names ),
    'db_name'                         => (string) $wpdb->dbname,
    'checked_at'                      => time(),
);

file_put_contents( $report_path, json_encode( $report ) );
echo "leak check reported\n";
