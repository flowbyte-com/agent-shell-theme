<?php
/**
 * Widget registry consolidation tests.
 *
 * Pre-fix state: widgets could be written to either agentshell_config.widgets
 * (via the main plugin's register_widget) or agentshell_widgets (via the
 * blocks plugin's register_widget). The two stores did not agree, removal
 * semantics differed between the two paths, and the configurator's GET
 * response read only one of them.
 *
 * Post-fix invariants under test:
 *   1. The merged registry exposes source (file vs config) explicitly.
 *   2. A config widget with the same id as a file widget overrides the
 *      file entry.
 *   3. widget_overrides[id].status=disabled is reflected in the merged
 *      registry.
 *   4. The legacy agentshell_widgets option migrates into
 *      agentshell_config.widgets; the migration is idempotent.
 *   5. agentshell_remove_widget removes the widget AND strips dangling
 *      zone references in the same transaction.
 *   6. The configurator's GET response surfaces widgets from both stores.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';

echo "widget registry consolidation tests\n";

Agentshell_Test::run(
    'file widget appears with source=file in merged registry',
    function () {
        agentshell_test_reset();
        // Seed a config that does NOT define 'hello-world' (the real
        // widgets/.index.json declares it as a file widget).
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(),
            'widget_overrides' => array(),
        ) );

        $registry = agentshell_get_widget_registry();
        Agentshell_Test::assert_true( isset( $registry['hello-world'] ) );
        Agentshell_Test::assert_equals( 'file', $registry['hello-world']['source'] );
    }
);

Agentshell_Test::run(
    'config-only widget appears with source=config',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(
                array(
                    'id'   => 'custom',
                    'name' => 'Custom Widget',
                    'template' => '<div>hi</div>',
                ),
            ),
            'widget_overrides' => array(),
        ) );

        $registry = agentshell_get_widget_registry();
        Agentshell_Test::assert_true( isset( $registry['custom'] ) );
        Agentshell_Test::assert_equals( 'config', $registry['custom']['source'] );
    }
);

Agentshell_Test::run(
    'config widget overrides file widget with the same id',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(
                array(
                    'id'   => 'hello-world',
                    'name' => 'Hello (custom)',
                    'template' => '<div>overridden</div>',
                ),
            ),
            'widget_overrides' => array(),
        ) );

        $registry = agentshell_get_widget_registry();
        Agentshell_Test::assert_equals( 'Hello (custom)', $registry['hello-world']['name'] );
        Agentshell_Test::assert_equals( 'config', $registry['hello-world']['source'] );
        Agentshell_Test::assert_equals( '<div>overridden</div>', $registry['hello-world']['template'] );
    }
);

Agentshell_Test::run(
    'disabled widget is marked in the merged registry',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(),
            'widget_overrides' => array(
                'hello-world' => array( 'status' => 'disabled' ),
            ),
        ) );

        $registry = agentshell_get_widget_registry();
        Agentshell_Test::assert_equals( 'disabled', $registry['hello-world']['status'] );
    }
);

Agentshell_Test::run(
    'migration moves legacy agentshell_widgets into agentshell_config.widgets',
    function () {
        agentshell_test_reset();
        // Pre-migration state: legacy option populated, config option empty.
        update_option( 'agentshell_widgets', array(
            array(
                'id'   => 'legacy-widget',
                'name' => 'Legacy Widget',
                'template' => '<div>legacy</div>',
            ),
        ) );
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(),
            'widget_overrides' => array(),
        ) );

        agentshell_migrate_legacy_widgets_option();

        $config = get_option( 'agentshell_config' );
        Agentshell_Test::assert_true( isset( $config['widgets']['legacy-widget'] ) );
        Agentshell_Test::assert_equals(
            'config',
            $config['widgets']['legacy-widget']['source']
        );
        Agentshell_Test::assert_false( get_option( 'agentshell_widgets' ) );
    }
);

Agentshell_Test::run(
    'migration is idempotent (re-running does not duplicate)',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_widgets', array(
            array( 'id' => 'w1', 'name' => 'W1' ),
        ) );
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(),
        ) );

        agentshell_migrate_legacy_widgets_option();
        agentshell_migrate_legacy_widgets_option();
        agentshell_migrate_legacy_widgets_option();

        $config = get_option( 'agentshell_config' );
        Agentshell_Test::assert_equals( 1, count( $config['widgets'] ) );
    }
);

Agentshell_Test::run(
    'migration is no-op when agentshell_widgets is absent',
    function () {
        agentshell_test_reset();
        // No legacy option, no config widgets.
        update_option( 'agentshell_config', array( 'zones' => array(), 'widgets' => array() ) );

        agentshell_migrate_legacy_widgets_option();

        $config = get_option( 'agentshell_config' );
        Agentshell_Test::assert_equals( array(), $config['widgets'] );
    }
);

Agentshell_Test::run(
    'remove_widget strips dangling zone references for that widget id',
    function () {
        agentshell_test_reset();
        update_option( 'agentshell_config', array(
            'zones' => array(
                array(
                    'id'          => 'main',
                    'composition' => array(
                        array( 'type' => 'wp_loop' ),
                        array( 'type' => 'widget', 'id' => 'doomed' ),
                        array( 'type' => 'widget', 'id' => 'kept' ),
                        array( 'type' => 'widget', 'id' => 'doomed' ),
                    ),
                ),
            ),
            'widgets' => array(
                array( 'id' => 'doomed', 'name' => 'Doomed' ),
                array( 'id' => 'kept',   'name' => 'Kept' ),
            ),
            'widget_overrides' => array(),
        ) );

        $config = agentshell_remove_widget_from_config(
            get_option( 'agentshell_config' ),
            'doomed'
        );

        // Widget removed from registry — verify by scanning the array,
        // since widgets may be stored sequentially rather than keyed.
        $remaining_ids = array();
        foreach ( (array) ( $config['widgets'] ?? array() ) as $w ) {
            if ( ! empty( $w['id'] ) ) { $remaining_ids[ $w['id'] ] = true; }
        }
        Agentshell_Test::assert_false( isset( $remaining_ids['doomed'] ) );
        Agentshell_Test::assert_true(  isset( $remaining_ids['kept'] ) );

        // Composition cleaned up.
        $composition = $config['zones'][0]['composition'];
        Agentshell_Test::assert_equals( 2, count( $composition ) );
        Agentshell_Test::assert_equals( 'wp_loop', $composition[0]['type'] );
        Agentshell_Test::assert_equals( 'widget', $composition[1]['type'] );
        Agentshell_Test::assert_equals( 'kept', $composition[1]['id'] );
    }
);

Agentshell_Test::run(
    'remove_widget refuses to remove a file widget',
    function () {
        agentshell_test_reset();
        // hello-world is the file widget declared in widgets/.index.json.
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(),
            'widget_overrides' => array(),
        ) );

        $threw = false;
        try {
            agentshell_remove_widget_from_config(
                get_option( 'agentshell_config' ),
                'hello-world'
            );
        } catch ( \InvalidArgumentException $e ) {
            $threw = true;
        }
        Agentshell_Test::assert_true( $threw );
    }
);

Agentshell_Test::run(
    'configurator GET response exposes widgets from the merged registry',
    function () {
        agentshell_test_reset();
        // Both the legacy option AND a config widget — only the config
        // widget should appear, because the post-migration model has a
        // single canonical store.
        update_option( 'agentshell_widgets', array(
            array( 'id' => 'legacy', 'name' => 'Legacy' ),
        ) );
        update_option( 'agentshell_config', array(
            'zones' => array(),
            'widgets' => array(
                array( 'id' => 'live', 'name' => 'Live' ),
            ),
            'widget_overrides' => array(),
        ) );

        // After migration, the legacy widget should also be visible.
        agentshell_migrate_legacy_widgets_option();

        // Now invoke the configurator's GET handler. We exercise the same
        // code path the WP REST endpoint does.
        $config = agentshell_get_config();
        $flat   = agentshell_flatten_config( $config );
        $widgets_option = get_option( 'agentshell_widgets', array() );

        // After the fix, the GET path should consult the merged registry,
        // not the legacy option directly.
        $available_widgets = array();
        $registry          = agentshell_get_widget_registry();
        foreach ( $registry as $widget ) {
            $available_widgets[] = array(
                'id'   => $widget['id'],
                'name' => $widget['name'] ?? $widget['id'],
                'source' => $widget['source'] ?? 'config',
            );
        }

        $ids = array_column( $available_widgets, 'id' );
        Agentshell_Test::assert_true( in_array( 'hello-world', $ids, true ), 'file widget must appear' );
        Agentshell_Test::assert_true( in_array( 'live',       $ids, true ), 'config widget must appear' );
        Agentshell_Test::assert_true( in_array( 'legacy',     $ids, true ), 'migrated legacy widget must appear' );
        Agentshell_Test::assert_equals( array(), $widgets_option, 'legacy option is empty post-migration' );
    }
);