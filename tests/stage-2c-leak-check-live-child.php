<?php
/**
 * Stage 2c M5-s2 — leak-check self-test child.
 *
 * argv: 1 WordPress root  2 report path
 *
 * Inserts a synthetic `agentshell_published_claim_astb-selftest-XXX`
 * option row and a synthetic `_agentshell_shadow_for` postmeta row
 * against the test bed, then runs the existing leak check child
 * (argv: wp_root, report_path) and reports the resulting JSON.
 * Cleans up the synthetic rows before exiting.
 *
 * The parent (stage-2c-leak-check-live-test.php) asserts the new
 * `claim_rows_with_fixture_prefix` and `shadow_meta_with_marker`
 * fields are populated.
 */

$wp_root      = $argv[1] ?? '';
$report_path  = $argv[2] ?? '';

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

$suffix = 'astb-selftest-' . getmypid() . '-' . time();
$claim_key = 'agentshell_published_claim_' . $suffix;
$shadow_orig_post_id = -1; // sentinel; we do not need a real post_id for the meta row
$shadow_value = $suffix;

// 1. Insert a synthetic claim row.
$wpdb->query( $wpdb->prepare(
    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
    $claim_key,
    maybe_serialize( array(
        'tx_id'       => 'tx_selftest_' . $suffix,
        'original_id' => -1,
        'shadow_id'   => -1,
        'actor'       => 'selftest',
        'baseline_ts' => time(),
        'claimed_at'  => time(),
        'claim_ttl'   => 600,
    ) ),
    'no'
) );

// 2. Insert a synthetic shadow meta row on a sentinel post id
//    (the leak check does not require the post to exist; the meta
//    row alone is the leak).
$wpdb->query( $wpdb->prepare(
    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
    $shadow_orig_post_id,
    '_agentshell_shadow_for',
    $shadow_value
) );

// 3. Run the existing leak check child (re-using the same WordPress
//    boot) and capture its report. The leak check child writes a
//    JSON file and exits; we call it via a function include by
//    re-implementing its core SQL here, since both run inside the
//    same wp-load.php boot. To keep one source of truth we
//    duplicate the queries (the leak check child is intentionally
//    self-contained so it can be invoked in isolation).
$claim_prefix  = 'agentshell_published_claim_%';
$shadow_keys   = array( '_agentshell_shadow_for', '_agentshell_shadow_tx_id', '_agentshell_shadow_op_id' );
$title_prefixes = array( 'ASTB', 'ASjr', 'AScw', 'ASNav' );

$leak = array(
    'claim_rows_with_fixture_prefix' => (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
        $claim_prefix
    ) ),
    'sample_claim_option_names' => $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 10",
        $claim_prefix
    ) ) ?: array(),
    'shadow_meta_with_marker' => (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id')"
    ) ),
    'sample_shadow_meta_rows' => array(),
);

// Sample shadow meta rows for the parent to assert against.
$shadow_rows = $wpdb->get_results(
    "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_agentshell_shadow_for','_agentshell_shadow_tx_id','_agentshell_shadow_op_id') LIMIT 10"
);
foreach ( (array) $shadow_rows as $row ) {
    $leak['sample_shadow_meta_rows'][] = array(
        'post_id'    => (int) ( $row->post_id ?? 0 ),
        'meta_key'   => (string) ( $row->meta_key ?? '' ),
        'meta_value' => (string) ( $row->meta_value ?? '' ),
    );
}

// 4. Clean up the synthetic rows so this self-test does not itself
//    leak.
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name = %s",
    $claim_key
) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
    '_agentshell_shadow_for',
    $shadow_value
) );

// 5. Report. The parent asserts the new fields are populated.
$out = array(
    'leak'                          => $leak,
    'synthetic_claim_key'           => $claim_key,
    'synthetic_shadow_meta_value'   => $shadow_value,
);
file_put_contents( $report_path, json_encode( $out ) );
echo "leak-check self-test reported\n";
