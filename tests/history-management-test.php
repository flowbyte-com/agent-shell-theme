<?php
/**
 * History management: descriptive revisions, individual and bulk deletion,
 * tombstones, audit integrity, snapshot deletion, and the guarantee that
 * removing history never changes the committed configuration.
 *
 * The store is exercised directly. The REST routes that call it are thin
 * argument checks, and the live acceptance script exercises them against a
 * real WordPress.
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['_agentshell_test_login'] = 'owner';

require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-content-write-guard.php';
require_once dirname( __DIR__ ) . '/agentshell-mcp/includes/class-store.php';

use AgentShell_MCP\Store;

echo "history management (descriptions, deletion, tombstones, audit)\n";

function history_config( $overrides = array() ) {
    $config = array(
        '--theme-bg'     => '#fafaf9',
        '--theme-accent' => '#e87a3e',
        '--theme-text'   => '#1f2937',
        'custom_css'     => '',
        'custom_js'      => '',
        'zones'          => array(
            array(
                'id'    => 'header',
                'label' => 'Header',
                'slots' => array(
                    'middle-center' => array( array( 'type' => 'wp_core', 'id' => 'site_title' ) ),
                ),
            ),
            array(
                'id'    => 'footer',
                'label' => 'Footer',
                'slots' => array(
                    'middle-center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
                ),
            ),
        ),
    );
    return array_merge( $config, $overrides );
}

function history_seed_revisions( $count = 3 ) {
    $config = history_config();
    $ids    = array();
    for ( $i = 1; $i <= $count; $i++ ) {
        $before = $config;
        $config = history_config( array( '--theme-accent' => '#e87a3e' . $i ) );
        Store::record_mutation( $before, $config, 'config_update', 'transaction: configurator save' );
        $items = Store::revisions()['items'];
        $ids[] = $items[0]['id'];
    }
    return $ids;
}

function history_audit_count() {
    return count( (array) Store::audit_log() );
}

Agentshell_Test::run(
    'a single token change is described by the token, not by the operation',
    function () {
        $before = history_config();
        $after  = history_config( array( '--theme-accent' => '#123456' ) );
        Agentshell_Test::assert_equals( 'Updated accent colour', Store::describe_change( $before, $after ) );
    }
);

Agentshell_Test::run(
    'replacing navigation in a zone is described as navigation',
    function () {
        $before = history_config();
        $after  = history_config();
        $after['zones'][1]['slots']['middle-center'] = array( array( 'type' => 'wp_core', 'id' => 'site_title' ) );
        Agentshell_Test::assert_equals( 'Changed Footer navigation', Store::describe_change( $before, $after ) );
    }
);

Agentshell_Test::run(
    'changing other blocks in a zone is described as layout',
    function () {
        $before = history_config();
        $after  = history_config();
        $after['zones'][0]['slots']['middle-center'][] = array( 'type' => 'wp_core', 'id' => 'search_form' );
        Agentshell_Test::assert_equals( 'Changed Header layout', Store::describe_change( $before, $after ) );
    }
);

Agentshell_Test::run(
    'custom CSS and multiple tokens are described conservatively',
    function () {
        $before = history_config();
        $after  = history_config( array( 'custom_css' => 'body { color: red; }' ) );
        Agentshell_Test::assert_equals( 'Updated custom CSS', Store::describe_change( $before, $after ) );

        $after = history_config( array( '--theme-accent' => '#111111', '--theme-bg' => '#222222' ) );
        Agentshell_Test::assert_equals( 'Updated 2 design settings', Store::describe_change( $before, $after ) );
    }
);

Agentshell_Test::run(
    'a change it cannot identify returns no description rather than inventing one',
    function () {
        $before = history_config( array( 'layout' => array( 'mystery' => 1 ) ) );
        $after  = history_config( array( 'layout' => array( 'mystery' => 2 ) ) );
        // A flat key outside the CSS-token namespace is not a reliable signal.
        Agentshell_Test::assert_equals( '', Store::describe_change( $before, $after ) );
        Agentshell_Test::assert_equals( '', Store::describe_change( $before, $before ) );
    }
);

Agentshell_Test::run(
    'a generic label is replaced, and an explicit label is preserved',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';

        $before = history_config();
        $after  = history_config( array( '--theme-accent' => '#123456' ) );
        Store::record_mutation( $before, $after, 'config_update', 'transaction: configurator save' );
        $item = Store::revisions()['items'][0];
        Agentshell_Test::assert_equals( 'Updated accent colour', $item['label'] );
        Agentshell_Test::assert_equals( 'Updated accent colour', $item['description'] );
        Agentshell_Test::assert_equals( 'config_update', $item['operation'] );

        Store::record_mutation( $after, $before, 'config_update', 'restore snapshot launch-day' );
        $item = Store::revisions()['items'][0];
        Agentshell_Test::assert_equals( 'restore snapshot launch-day', $item['label'], 'an explicit label is not overwritten' );
        Agentshell_Test::assert_equals( 'Updated accent colour', $item['description'], 'the description is still recorded' );
    }
);

Agentshell_Test::run(
    'deleting a revision keeps the lineage and the audit trail',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        $GLOBALS['_agentshell_test_options']['agentshell_config'] = history_config();
        $committed_before = $GLOBALS['_agentshell_test_options']['agentshell_config'];

        $ids = history_seed_revisions( 3 ); // r1, r2, r3; r3 is newest.
        $audit_before = history_audit_count();
        $parent_links = array();
        foreach ( Store::revisions()['items'] as $item ) {
            $parent_links[ $item['id'] ] = $item['parent'];
        }

        $result = Store::delete_revisions( array( 'r2' ), 'cleanup' );

        Agentshell_Test::assert_equals( array( 'r2' ), $result['deleted'] );
        Agentshell_Test::assert_equals( array(), $result['missing'] );
        Agentshell_Test::assert_true( $result['audit_recorded'] );

        $remaining = Store::revisions()['items'];
        Agentshell_Test::assert_equals( 2, count( $remaining ) );
        Agentshell_Test::assert_equals( array( 'r3', 'r1' ), array_column( $remaining, 'id' ) );

        $by_id = array();
        foreach ( $remaining as $item ) { $by_id[ $item['id'] ] = $item; }
        Agentshell_Test::assert_equals( $parent_links['r3'], $by_id['r3']['parent'], 'the child still points at the removed id' );
        Agentshell_Test::assert_equals( 'r2', $by_id['r3']['parent'], 'the recorded parent is not silently rewritten' );
        Agentshell_Test::assert_equals( null, $by_id['r1']['parent'] );

        $tombstones = Store::revision_tombstones();
        Agentshell_Test::assert_true( isset( $tombstones['r2'] ) );
        Agentshell_Test::assert_equals( 'owner', $tombstones['r2']['actor'] );
        Agentshell_Test::assert_equals( 'cleanup', $tombstones['r2']['reason'] );

        Agentshell_Test::assert_equals( $audit_before + 1, history_audit_count(), 'one deletion audit row is added' );
        $found = null;
        foreach ( Store::audit_log() as $entry ) {
            if ( 'revision_deleted' === ( $entry['operation'] ?? '' ) ) { $found = $entry; break; }
        }
        Agentshell_Test::assert_true( is_array( $found ) );
        Agentshell_Test::assert_equals( array( 'r2' ), $found['changed']['ids'] );
        Agentshell_Test::assert_equals( 'owner', $found['actor'] );

        // The audit rows that mention the removed revision are still there.
        $still_referenced = false;
        foreach ( Store::audit_log() as $entry ) {
            if ( 'r2' === ( $entry['revision_id'] ?? null ) ) { $still_referenced = true; break; }
        }
        Agentshell_Test::assert_true( $still_referenced, 'existing audit rows are not erased' );

        Agentshell_Test::assert_equals( $committed_before, $GLOBALS['_agentshell_test_options']['agentshell_config'], 'the committed configuration is unchanged' );
    }
);

Agentshell_Test::run(
    'bulk deletion, missing ids, and the bounded request',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        history_seed_revisions( 4 );

        $result = Store::delete_revisions( array( 'r1', 'r3', 'r99' ) );
        Agentshell_Test::assert_equals( array( 'r3', 'r1' ), $result['deleted'] );
        Agentshell_Test::assert_equals( array( 'r99' ), $result['missing'] );
        Agentshell_Test::assert_equals( 2, count( Store::revisions()['items'] ) );

        // Deleting an already removed id is idempotent and reported.
        $again = Store::delete_revisions( array( 'r1' ) );
        Agentshell_Test::assert_equals( array(), $again['deleted'] );
        Agentshell_Test::assert_equals( array( 'r1' ), $again['missing'] );

        $too_many = array();
        for ( $i = 1; $i <= 101; $i++ ) { $too_many[] = 'r' . $i; }
        $threw = false;
        try { Store::delete_revisions( $too_many ); } catch ( \InvalidArgumentException $e ) { $threw = true; }
        Agentshell_Test::assert_true( $threw, 'more than 100 ids is refused' );

        $malformed_threw = false;
        try { Store::delete_revisions( array( 'not-an-id', '../r1' ) ); } catch ( \InvalidArgumentException $e ) { $malformed_threw = true; }
        Agentshell_Test::assert_true( $malformed_threw, 'a malformed id is refused, not silently ignored' );
    }
);

Agentshell_Test::run(
    'deleting a snapshot is audited and missing names are reported',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        $GLOBALS['_agentshell_test_options']['agentshell_config'] = history_config();
        Store::create_snapshot( 'launch-day', 'Launch day' );
        Store::create_snapshot( 'before-header', 'Before the header change' );

        $audit_before = history_audit_count();
        $result = Store::delete_snapshots( array( 'launch-day', 'ghost' ), 'cleanup' );
        Agentshell_Test::assert_equals( array( 'launch-day' ), $result['deleted'] );
        Agentshell_Test::assert_equals( array( 'ghost' ), $result['missing'] );
        Agentshell_Test::assert_true( $result['audit_recorded'] );

        Agentshell_Test::assert_true( null === Store::get_snapshot( 'launch-day' ) );
        Agentshell_Test::assert_true( null !== Store::get_snapshot( 'before-header' ) );
        Agentshell_Test::assert_equals( $audit_before + 1, history_audit_count() );

        $found = null;
        foreach ( Store::audit_log() as $entry ) {
            if ( 'snapshot_deleted' === ( $entry['operation'] ?? '' ) ) { $found = $entry; break; }
        }
        Agentshell_Test::assert_true( is_array( $found ) );
        Agentshell_Test::assert_equals( array( 'launch-day' ), $found['changed']['names'] );

        Agentshell_Test::assert_false( Store::delete_snapshot( 'launch-day' ), 'an already-deleted snapshot reports false' );
        Agentshell_Test::assert_equals( $GLOBALS['_agentshell_test_options']['agentshell_config'], history_config(), 'the committed configuration is unchanged' );

        $bad_name_threw = false;
        try { Store::delete_snapshots( array( '../before-header' ) ); } catch ( \InvalidArgumentException $e ) { $bad_name_threw = true; }
        Agentshell_Test::assert_true( $bad_name_threw, 'a name that sanitises to something else is refused' );
        Agentshell_Test::assert_true( null !== Store::get_snapshot( 'before-header' ), 'the near-miss record is untouched' );
    }
);

Agentshell_Test::run(
    'a failed audit write leaves the history alone',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        history_seed_revisions( 3 );

        // The failure is injected at the first write of the operation: the
        // audit record itself. Nothing may be deleted, because the record of
        // the deletion could not be written.
        $GLOBALS['_agentshell_test_history_audit_fail'] = true;
        $result = Store::delete_revisions( array( 'r2' ) );
        unset( $GLOBALS['_agentshell_test_history_audit_fail'] );

        Agentshell_Test::assert_equals( array(), $result['deleted'], 'nothing was deleted' );
        Agentshell_Test::assert_true( in_array( $result['outcome'], array( 'failed', 'unchanged' ), true ), 'the outcome is a confirmed non-deletion' );
        $ids = array_column( Store::revisions()['items'], 'id' );
        Agentshell_Test::assert_true( in_array( 'r2', $ids, true ), 'the revision is still there' );
        Agentshell_Test::assert_equals( 3, count( $ids ), 'no revision was removed' );

        $found = false;
        foreach ( Store::audit_log() as $entry ) {
            if ( 'revision_deleted' === ( $entry['operation'] ?? '' ) ) { $found = true; break; }
        }
        Agentshell_Test::assert_false( $found, 'no deletion was recorded' );
    }
);

Agentshell_Test::run(
    'a failure between the deletion and the audit confirmation is reconciled',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        history_seed_revisions( 3 );

        // The failure is injected at the third write: the confirmation that
        // the deletion happened. The deletion itself has already landed, so
        // the outcome must not be reported as a failure.
        $GLOBALS['_agentshell_test_history_finalise_fail'] = true;
        $result = Store::delete_revisions( array( 'r2' ) );
        unset( $GLOBALS['_agentshell_test_history_finalise_fail'] );

        Agentshell_Test::assert_equals( 'deleted', $result['outcome'], 'the deletion is reported as a deletion' );
        Agentshell_Test::assert_equals( array( 'r2' ), $result['deleted'] );
        $ids = array_column( Store::revisions()['items'], 'id' );
        Agentshell_Test::assert_false( in_array( 'r2', $ids, true ), 'the revision is gone' );

        // The audit log must say what happened — either the row says the
        // deletion happened, or the row says it is still to be confirmed.
        // What must never happen is no row at all.
        $row = null;
        foreach ( Store::audit_log() as $entry ) {
            if ( 'revision_deleted' === ( $entry['operation'] ?? '' ) ) { $row = $entry; break; }
        }
        Agentshell_Test::assert_true( is_array( $row ), 'the deletion is recorded' );
        Agentshell_Test::assert_true( in_array( $row['changed']['state'] ?? '', array( 'deleted', 'pending' ), true ),
            'the record states what is known: ' . ( $row['changed']['state'] ?? 'none' ) );
        Agentshell_Test::assert_equals( array( 'r2' ), $row['changed']['ids'] ?? array() );
    }
);

Agentshell_Test::run(
    'a failure at the history write leaves the history and the record in step',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        history_seed_revisions( 3 );

        $GLOBALS['_agentshell_test_history_cas_fail'] = true;
        $result = Store::delete_revisions( array( 'r2' ) );
        unset( $GLOBALS['_agentshell_test_history_cas_fail'] );

        Agentshell_Test::assert_equals( array(), $result['deleted'], 'nothing was deleted' );
        $ids = array_column( Store::revisions()['items'], 'id' );
        Agentshell_Test::assert_true( in_array( 'r2', $ids, true ), 'the revision is still there' );

        // The intent row, if it survived, must not claim the deletion
        // happened.
        $row = null;
        foreach ( Store::audit_log() as $entry ) {
            if ( 'revision_deleted' === ( $entry['operation'] ?? '' ) ) { $row = $entry; break; }
        }
        if ( is_array( $row ) ) {
            Agentshell_Test::assert_equals( 'failed', $row['changed']['state'] ?? '', 'the record says the deletion did not complete' );
        }
    }
);

Agentshell_Test::run(
    'a revision recorded while the deletion is being applied is not lost',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        history_seed_revisions( 3 );

        // A concurrent writer appends a revision between the read and the
        // write. The deletion must not remove it.
        $GLOBALS['_agentshell_test_history_cas_fail'] = true;
        $before = Store::revisions();
        $items  = $before['items'];
        $items[] = array(
            'id'        => 'r99',
            'parent'    => $items[0]['id'],
            'timestamp' => time(),
            'actor'     => 'someone-else',
            'label'     => 'recorded concurrently',
            'config'    => history_config(),
        );
        $before['items'] = $items;
        $GLOBALS['_agentshell_test_options']['agentshell_revisions'] = $before;

        $result = Store::delete_revisions( array( 'r2' ) );
        unset( $GLOBALS['_agentshell_test_history_cas_fail'] );

        $ids = array_column( Store::revisions()['items'], 'id' );
        Agentshell_Test::assert_true( in_array( 'r99', $ids, true ), 'the concurrent revision survived' );
        Agentshell_Test::assert_true( in_array( 'r2', $ids, true ), 'the requested revision was not deleted either, because the write was refused' );
    }
);

Agentshell_Test::run(
    'deleting history never touches the committed configuration',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        $GLOBALS['_agentshell_test_options']['agentshell_config'] = history_config();
        $before = $GLOBALS['_agentshell_test_options']['agentshell_config'];
        history_seed_revisions( 2 );
        Store::create_snapshot( 'astb-test', 'A test snapshot' );

        Store::delete_revisions( array( 'r1' ) );
        Store::delete_snapshots( array( 'astb-test' ) );

        Agentshell_Test::assert_equals( $before, $GLOBALS['_agentshell_test_options']['agentshell_config'] );
    }
);

Agentshell_Test::run(
    'the deletion reports what it may be believed about',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        history_seed_revisions( 2 );

        $result = Store::delete_revisions( array( 'r1' ) );
        Agentshell_Test::assert_true( isset( $result['atomicity']['database_transaction'] ), 'the result carries an atomicity block' );
        Agentshell_Test::assert_true( isset( $result['atomicity']['on_failure'] ), 'the block says what a failure would do' );
        Agentshell_Test::assert_equals( 'deleted', $result['outcome'] );
        Agentshell_Test::assert_equals( 'deleted', $result['audit_state'] );

        // Nothing to delete is a different answer from a failure.
        $again = Store::delete_revisions( array( 'r1' ) );
        Agentshell_Test::assert_equals( 'unchanged', $again['outcome'] );
        Agentshell_Test::assert_equals( array( 'r1' ), $again['missing'] );
    }
);

Agentshell_Test::run(
    'remaining revisions are still restorable data after deletion',
    function () {
        agentshell_test_reset();
        $GLOBALS['_agentshell_test_login'] = 'owner';
        history_seed_revisions( 3 );
        Store::delete_revisions( array( 'r2' ) );

        $remaining = Store::revisions()['items'];
        $target = null;
        foreach ( $remaining as $item ) {
            if ( 'r1' === $item['id'] ) { $target = $item; break; }
        }
        Agentshell_Test::assert_true( is_array( $target ) );
        Agentshell_Test::assert_true( isset( $target['config'] ), 'a remaining revision still carries its full configuration' );
        Agentshell_Test::assert_equals( '#e87a3e1', $target['config']['--theme-accent'] );
    }
);
