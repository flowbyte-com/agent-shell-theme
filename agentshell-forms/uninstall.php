<?php
/**
 * Uninstall.
 *
 * ## Nothing is removed by default
 *
 * Deactivating a plugin must never destroy data, and **uninstalling** is not
 * obviously different: an operator who deletes the plugin to try a different
 * one, or reinstalls it, has not thereby decided that every form and every
 * submission should be gone.
 *
 * So the default is to leave everything in place. Removing the plugin's own
 * tables and options requires an explicit constant:
 *
 *     define( 'AGENTSHELL_FORMS_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * and removing the **form records themselves** — which is the destructive part,
 * because a form is content — requires a second, separate constant:
 *
 *     define( 'AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL', true );
 *
 * Two constants rather than one flag, because they are two different decisions.
 * The first removes the plugin's *bookkeeping*; the second removes the
 * operator's *content*. A single switch would make it possible to destroy forms
 * while intending only to tidy up after the plugin, and that is exactly the kind
 * of quiet destruction the design's data-retention rule exists to prevent.
 *
 * The two are **nested, not independent**: removing content requires the
 * bookkeeping decision as well. That asymmetry is deliberate and it fails
 * closed. An operator who sets only the second constant is asking to delete the
 * forms while keeping the tables, the audit log and the stored schema revision
 * that describe them — a state with no useful purpose, and one in which the
 * options left behind would refer to data that is gone. So the second constant
 * alone removes **nothing**: the conservative reading of an under-specified
 * destructive request. (Both constants set removes both; neither set removes
 * neither.)
 *
 * ## What is never touched, whatever the constants say
 *
 *   - users and their capabilities;
 *   - posts of any other type;
 *   - options belonging to anything else.
 *
 * ## Submissions
 *
 * Submissions are `agentshell_entry` posts, and they are covered by the second
 * constant along with the forms. Personal data is not left behind as an orphan
 * by a removal that claims to remove the plugin's data — and it is not destroyed
 * by a removal that was only meant to tidy up bookkeeping.
 *
 * @package AgentShell_Forms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$agentshell_forms_remove_data = defined( 'AGENTSHELL_FORMS_REMOVE_DATA_ON_UNINSTALL' )
	&& AGENTSHELL_FORMS_REMOVE_DATA_ON_UNINSTALL;

// Fails closed. `AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL` on its own is an
// under-specified destructive request, and the conservative reading of one is
// to do nothing — see the nesting rule in the file docblock.
if ( ! $agentshell_forms_remove_data ) {
	return;
}

global $wpdb;

$agentshell_forms_prefix = $wpdb->prefix;

// The plugin's own tables.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $agentshell_forms_prefix . 'agentshell_form_challenges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $agentshell_forms_prefix . 'agentshell_form_rate_limits' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// The plugin's own options.
delete_option( 'agentshell_forms_db_version' );
delete_option( 'agentshell_forms_audit' );
delete_option( 'agentshell_forms_audit_dropped' );
delete_option( 'agentshell_forms_personal_data_policy' );

// The scheduled job.
wp_clear_scheduled_hook( 'agentshell_forms_prune' );

// The capabilities, from every role.
//
// This loop is written out here rather than called from `Form_Capabilities`,
// because `uninstall.php` runs in a request where the plugin's classes are not
// loaded — that is what an uninstall request is. The capability *names* are
// therefore repeated, and they are the only thing repeated: everything else
// about them lives in the class.
//
// Per-user grants are stored on the user records; they are removed with the
// users, and removing them here would mean rewriting every user on the site
// during an uninstall.
$agentshell_forms_roles = wp_roles();
if ( $agentshell_forms_roles instanceof WP_Roles ) {
	foreach ( array_keys( $agentshell_forms_roles->roles ) as $agentshell_forms_role_name ) {
		$agentshell_forms_role = get_role( $agentshell_forms_role_name );
		if ( $agentshell_forms_role instanceof WP_Role ) {
			$agentshell_forms_role->remove_cap( 'manage_agentshell_forms' );
			$agentshell_forms_role->remove_cap( 'manage_agentshell_forms_submissions' );
		}
	}
}

/*
 * The destructive part: the form and submission records.
 *
 * A separate constant, because it is a separate decision.
 */
$agentshell_forms_remove_forms = defined( 'AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL' )
	&& AGENTSHELL_FORMS_REMOVE_FORMS_ON_UNINSTALL;

if ( ! $agentshell_forms_remove_forms ) {
	return;
}

foreach ( array( 'agentshell_form', 'agentshell_entry' ) as $agentshell_forms_post_type ) {
	$agentshell_forms_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			$agentshell_forms_post_type
		)
	);

	if ( ! is_array( $agentshell_forms_ids ) ) {
		continue;
	}

	foreach ( $agentshell_forms_ids as $agentshell_forms_id ) {
		// A true delete: the trash would leave the rows behind, and an uninstall
		// that leaves content in the trash has not removed it.
		wp_delete_post( (int) $agentshell_forms_id, true );
	}
}
