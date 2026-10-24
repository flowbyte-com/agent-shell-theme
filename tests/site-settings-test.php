<?php
/**
 * Site identity and reading settings — unit tests.
 *
 * The surface is bounded on purpose: the site title and the reading
 * options are writable, and the options that decide where the site lives
 * and who may register on it are not. The tests below are mostly about
 * the *edges* of that boundary, because a boundary that is only ever
 * tested on its happy path is not a boundary.
 *
 * What is asserted here
 * ---------------------
 * 1. The allowlist contains no sensitive option.
 * 2. Every allowlisted key validates, and the values that must not be
 *    accepted are refused by name.
 * 3. A page reference must be an existing, published page.
 * 4. The front page and the posts page must differ, and `show_on_front:
 *    page` needs a front page.
 * 5. The projection applies only the settings that changed, and only the
 *    ones on the allowlist.
 * 6. The projection writes the WordPress option the mapping names.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../agentshell-mcp/includes/class-site-settings.php';

use AgentShell_MCP\Site_Settings;

echo "site settings tests\n";

// ── 1. the bounded surface ─────────────────────────────────────────────────
Agentshell_Test::run(
    'the allowlist contains no sensitive option',
    function () {
        $schema = Site_Settings::schema();
        foreach ( Site_Settings::protected_options() as $option ) {
            foreach ( $schema as $key => $rule ) {
                Agentshell_Test::assert_not_equals(
                    $option,
                    $rule['option'],
                    'the protected option "' . $option . '" must not be writable'
                );
            }
        }
    }
);

Agentshell_Test::run(
    'the allowlist is short, and every key maps to an option',
    function () {
        $schema = Site_Settings::schema();
        Agentshell_Test::assert_true(
            count( $schema ) >= 10 && count( $schema ) <= 20,
            'the surface must stay small enough to read: ' . count( $schema ) . ' settings'
        );
        foreach ( $schema as $key => $rule ) {
            Agentshell_Test::assert_true(
                '' !== (string) $rule['option'],
                'the setting "' . $key . '" must name an option'
            );
        }
    }
);

// ── 2. validation ──────────────────────────────────────────────────────────
Agentshell_Test::run(
    'a title, a tagline and the reading options validate',
    function () {
        $check = Site_Settings::validate( array(
            'title'                  => 'A site',
            'tagline'                => 'A tagline',
            'show_on_front'          => 'posts',
            'posts_per_page'         => 10,
            'default_comment_status' => 'closed',
            'default_ping_status'    => 'open',
            'comment_registration'   => true,
            'date_format'            => 'F j, Y',
            'time_format'            => 'g:i a',
            'start_of_week'          => 1,
        ) );
        Agentshell_Test::assert_true( $check['ok'], 'a valid set must be accepted: ' . implode( ' ', $check['errors'] ) );
        Agentshell_Test::assert_equals( 'A site', $check['values']['title'] );
        Agentshell_Test::assert_equals( 10, $check['values']['posts_per_page'] );
        Agentshell_Test::assert_equals( true, $check['values']['comment_registration'] );
    }
);

Agentshell_Test::run(
    'an unknown key is refused by name, with the allowed list',
    function () {
        $check = Site_Settings::validate( array( 'siteurl' => 'https://elsewhere.example' ) );
        Agentshell_Test::assert_false( $check['ok'] );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'siteurl' ),
            'the refusal must name the offending key: ' . $check['errors'][0]
        );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'title' ),
            'the refusal must name the accepted keys: ' . $check['errors'][0]
        );
    }
);

Agentshell_Test::run(
    'an out-of-range posts_per_page is refused',
    function () {
        $check = Site_Settings::validate( array( 'posts_per_page' => 5000 ) );
        Agentshell_Test::assert_false( $check['ok'] );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'between 1 and 100' ),
            'the refusal must state the range: ' . $check['errors'][0]
        );
    }
);

Agentshell_Test::run(
    'a start_of_week outside 0..6 is refused',
    function () {
        $check = Site_Settings::validate( array( 'start_of_week' => 9 ) );
        Agentshell_Test::assert_false( $check['ok'] );
    }
);

Agentshell_Test::run(
    'an invalid enum value is refused',
    function () {
        $check = Site_Settings::validate( array( 'default_comment_status' => 'maybe' ) );
        Agentshell_Test::assert_false( $check['ok'] );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'open, closed' ),
            'the refusal must state the accepted values: ' . $check['errors'][0]
        );
    }
);

Agentshell_Test::run(
    'an over-long title is refused',
    function () {
        $check = Site_Settings::validate( array( 'title' => str_repeat( 'x', 500 ) ) );
        Agentshell_Test::assert_false( $check['ok'] );
    }
);

Agentshell_Test::run(
    'an empty title is accepted — it clears the title',
    function () {
        $check = Site_Settings::validate( array( 'title' => '' ) );
        Agentshell_Test::assert_true( $check['ok'], 'an empty title is a legitimate value' );
        Agentshell_Test::assert_equals( '', $check['values']['title'] );
    }
);

// ── 3. page references ─────────────────────────────────────────────────────
Agentshell_Test::run(
    'a front page must be an existing, published page',
    function () {
        // No such page in the fixture store.
        $check = Site_Settings::validate( array( 'front_page' => 4242 ) );
        Agentshell_Test::assert_false( $check['ok'] );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'not a page' ),
            'the refusal must say what is wrong: ' . $check['errors'][0]
        );
    }
);

Agentshell_Test::run(
    'a draft page is refused as a front page',
    function () {
        $GLOBALS['_agentshell_test_posts'][77] = (object) array(
            'ID'          => 77,
            'post_type'   => 'page',
            'post_status' => 'draft',
        );
        $check = Site_Settings::validate( array( 'front_page' => 77 ) );
        unset( $GLOBALS['_agentshell_test_posts'][77] );
        Agentshell_Test::assert_false( $check['ok'] );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'draft' ),
            'the refusal must name the status: ' . $check['errors'][0]
        );
    }
);

Agentshell_Test::run(
    'a published page is accepted as a front page',
    function () {
        $GLOBALS['_agentshell_test_posts'][78] = (object) array(
            'ID'          => 78,
            'post_type'   => 'page',
            'post_status' => 'publish',
        );
        $check = Site_Settings::validate( array( 'front_page' => 78 ) );
        unset( $GLOBALS['_agentshell_test_posts'][78] );
        Agentshell_Test::assert_true( $check['ok'], 'a published page is valid: ' . implode( ' ', $check['errors'] ) );
        Agentshell_Test::assert_equals( 78, $check['values']['front_page'] );
    }
);

Agentshell_Test::run(
    'zero clears a page reference',
    function () {
        $check = Site_Settings::validate( array( 'front_page' => 0 ) );
        Agentshell_Test::assert_true( $check['ok'] );
        Agentshell_Test::assert_equals( 0, $check['values']['front_page'] );
    }
);

// ── 4. cross-checks ────────────────────────────────────────────────────────
Agentshell_Test::run(
    'show_on_front=page requires a front page',
    function () {
        $check = Site_Settings::validate( array( 'show_on_front' => 'page' ) );
        Agentshell_Test::assert_false( $check['ok'] );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'front_page' ),
            'the refusal must name the missing setting: ' . $check['errors'][0]
        );
    }
);

Agentshell_Test::run(
    'the front page and the posts page must differ',
    function () {
        $GLOBALS['_agentshell_test_posts'][79] = (object) array(
            'ID'          => 79,
            'post_type'   => 'page',
            'post_status' => 'publish',
        );
        $check = Site_Settings::validate( array( 'front_page' => 79, 'posts_page' => 79 ) );
        unset( $GLOBALS['_agentshell_test_posts'][79] );
        Agentshell_Test::assert_false( $check['ok'] );
        Agentshell_Test::assert_true(
            false !== strpos( $check['errors'][0], 'different pages' ),
            'the refusal must explain: ' . $check['errors'][0]
        );
    }
);

Agentshell_Test::run(
    'the cross-checks see the settings already in force',
    function () {
        // The front page is already set; the new request turns on a static
        // front page without naming one.
        $check = Site_Settings::validate(
            array( 'show_on_front' => 'page' ),
            array( 'front_page' => 0 )
        );
        Agentshell_Test::assert_false( $check['ok'] );

        $GLOBALS['_agentshell_test_posts'][80] = (object) array(
            'ID'          => 80,
            'post_type'   => 'page',
            'post_status' => 'publish',
        );
        $check = Site_Settings::validate(
            array( 'show_on_front' => 'page' ),
            array( 'front_page' => 80 )
        );
        unset( $GLOBALS['_agentshell_test_posts'][80] );
        Agentshell_Test::assert_true( $check['ok'], 'the existing front page satisfies the check' );
    }
);

// ── 5. the projection ──────────────────────────────────────────────────────
Agentshell_Test::run(
    'the projection applies only the settings that changed',
    function () {
        $GLOBALS['_agentshell_test_options'] = array();
        update_option( 'blogname', 'Old title' );
        update_option( 'blogdescription', 'Old tagline' );

        $applied = Site_Settings::project(
            array( 'site' => array( 'title' => 'Old title' ) ),
            array( 'site' => array( 'title' => 'New title' ) )
        );

        Agentshell_Test::assert_equals( array( 'title' => 'New title' ), $applied );
        Agentshell_Test::assert_equals( 'New title', get_option( 'blogname' ) );
        Agentshell_Test::assert_equals( 'Old tagline', get_option( 'blogdescription' ), 'an unchanged setting must not be rewritten' );
    }
);

Agentshell_Test::run(
    'the projection writes the option the mapping names',
    function () {
        $GLOBALS['_agentshell_test_options'] = array();
        update_option( 'page_on_front', 0 );

        Site_Settings::project( array(), array( 'site' => array( 'front_page' => 12 ) ) );

        Agentshell_Test::assert_equals( 12, get_option( 'page_on_front' ) );
    }
);

Agentshell_Test::run(
    'the projection ignores a setting that is not on the allowlist',
    function () {
        $GLOBALS['_agentshell_test_options'] = array();
        $applied = Site_Settings::project(
            array(),
            array( 'site' => array( 'siteurl' => 'https://elsewhere.example', 'admin_email' => 'x@example.test' ) )
        );
        Agentshell_Test::assert_equals( array(), $applied );
        Agentshell_Test::assert_equals( false, get_option( 'siteurl' ) );
        Agentshell_Test::assert_equals( false, get_option( 'admin_email' ) );
    }
);

Agentshell_Test::run(
    'the projection does nothing when the configuration has no site section',
    function () {
        $GLOBALS['_agentshell_test_options'] = array();
        update_option( 'blogname', 'Untouched' );
        $applied = Site_Settings::project( array(), array( 'design' => array( 'colors' => array( 'text' => '#000000' ) ) ) );
        Agentshell_Test::assert_equals( array(), $applied );
        Agentshell_Test::assert_equals( 'Untouched', get_option( 'blogname' ) );
    }
);

Agentshell_Test::run(
    'the projection does not rewrite a value WordPress already holds',
    function () {
        $GLOBALS['_agentshell_test_options'] = array();
        update_option( 'blogname', 'Same title' );
        $applied = Site_Settings::project( array(), array( 'site' => array( 'title' => 'Same title' ) ) );
        Agentshell_Test::assert_equals( array(), $applied );
    }
);
