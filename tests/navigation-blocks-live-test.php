<?php
/**
 * Live navigation-block regressions, against a real WordPress.
 *
 * The unit suite proves the selection logic and the markup contract with
 * stubs. This proves the part only WordPress can: that `wp_nav_menu()` with a
 * menu term emits that menu's items and its nested lists, that the same menu
 * placed twice gets two different list ids, that a location resolves through
 * the assignment map, and that a missing menu yields nothing for a visitor and
 * a diagnostic for an editor — with no substitution anywhere.
 *
 * Skips cleanly when no verified test bed is configured.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agentshell-test-bed.php';
require_once __DIR__ . '/wp-live.php';

echo "live navigation blocks (real WordPress)\n";

$config = agentshell_wp_live_config_destructive();
if ( null === $config ) {
    Agentshell_Test::run(
        'live navigation block suite',
        function () {
            Agentshell_Test::assert_true( true, 'skipped: no test-bed config (destructive suites require tests/config/test-bed.json)' );
        }
    );
    echo "  (skipped: destructive suites require a test-bed config; tests/config/test-bed.json not present or invalid)\n";
    return;
}

$report_file = sys_get_temp_dir() . '/as-nav-blocks-live-' . getmypid() . '.json';
@unlink( $report_file );

$run = agentshell_wp_live_run(
    $config,
    __DIR__ . '/navigation-blocks-live-child.php',
    array( $config['wp_root'], $report_file )
);

$facts = json_decode( (string) @file_get_contents( $report_file ), true );
@unlink( $report_file );

if ( ! is_array( $facts ) ) {
    Agentshell_Test::run(
        'live navigation block child produced a report',
        function () use ( $run ) {
            Agentshell_Test::assert_true( false, 'child produced no report: ' . substr( (string) ( $run['output'] ?? '' ), 0, 600 ) );
        }
    );
    return;
}

function nb_live( $key, $default = null ) {
    return $GLOBALS['nb_live_facts'][ $key ] ?? $default;
}

$GLOBALS['nb_live_facts'] = $facts;

Agentshell_Test::run(
    'the child ran without errors and cleaned up after itself',
    function () {
        Agentshell_Test::assert_equals( array(), nb_live( 'errors', array() ) );
        Agentshell_Test::assert_equals( array(), nb_live( 'leftover_menus', array() ), 'no fixture menus survive the run' );
        Agentshell_Test::assert_true( nb_live( 'locations_restored' ), 'nav_menu_locations is put back exactly as found' );
    }
);

Agentshell_Test::run(
    'the legacy block renders the primary location menu',
    function () {
        $html = (string) nb_live( 'legacy_html' );
        Agentshell_Test::assert_true( nb_live( 'legacy_has_primary_label' ), 'aria-label="Primary", as before' );
        Agentshell_Test::assert_true( nb_live( 'legacy_has_a_home' ), 'the menu assigned to primary is rendered' );
        Agentshell_Test::assert_false( nb_live( 'legacy_has_b_docs' ), 'no other menu leaks in' );
        Agentshell_Test::assert_false( nb_live( 'legacy_has_mode_class' ), 'the legacy path carries no mode class' );
        Agentshell_Test::assert_true( strpos( $html, 'wp-core-nav' ) !== false );
    }
);

Agentshell_Test::run(
    'a named menu renders that menu, with its nested structure',
    function () {
        Agentshell_Test::assert_true( nb_live( 'named_has_b_docs' ) );
        Agentshell_Test::assert_false( nb_live( 'named_has_a_home' ), 'the primary menu is not substituted' );
        Agentshell_Test::assert_true( nb_live( 'named_label' ), 'the accessible name is the block label' );
        Agentshell_Test::assert_true( nb_live( 'named_mode_class' ) );
        Agentshell_Test::assert_true( nb_live( 'named_nested' ), 'WordPress emits menu-item-has-children and a sub-menu list' );
    }
);

Agentshell_Test::run(
    'the same menu placed twice gets unique list ids',
    function () {
        $ids = (array) nb_live( 'twice_ids', array() );
        Agentshell_Test::assert_equals( 2, (int) nb_live( 'twice_id_count' ), 'two lists were rendered' );
        Agentshell_Test::assert_true( nb_live( 'twice_ids_unique' ), 'ids are unique: ' . implode( ', ', $ids ) );
        Agentshell_Test::assert_equals( 2, (int) nb_live( 'twice_occurrences' ), 'both placements rendered the same items' );
    }
);

Agentshell_Test::run(
    'a registered location resolves to the menu assigned to it',
    function () {
        Agentshell_Test::assert_true( nb_live( 'location_has_b_docs' ) );
        Agentshell_Test::assert_true( nb_live( 'location_mode_class' ) );
        Agentshell_Test::assert_true( nb_live( 'location_has_extra_label' ), 'the location label is the accessible name' );
    }
);

Agentshell_Test::run(
    'a missing menu renders nothing for a visitor and a diagnostic for an editor',
    function () {
        Agentshell_Test::assert_equals( '', (string) nb_live( 'missing_visitor' ) );
        Agentshell_Test::assert_true( nb_live( 'missing_editor_has_code' ) );
    }
);

Agentshell_Test::run(
    'an unassigned location is diagnosed, not guessed',
    function () {
        Agentshell_Test::assert_equals( 'navigation_location_unassigned', (string) nb_live( 'unassigned_code' ) );
    }
);

Agentshell_Test::run(
    'the legacy block keeps WordPress\'s own fallback when primary is unassigned',
    function () {
        // The historical path does not pass `fallback_cb`, so WordPress applies
        // its default (wp_page_menu). That is the behaviour existing sites have
        // and this PR does not change it; what matters is that no *menu* is
        // substituted.
        $html = (string) nb_live( 'legacy_unassigned' );
        Agentshell_Test::assert_true( '' !== $html, 'something rendered' );
        Agentshell_Test::assert_true( strpos( $html, 'A Home' ) === false, 'no fixture menu was substituted' );
        Agentshell_Test::assert_true( strpos( $html, 'B Docs' ) === false, 'and not the other one either' );
    }
);
