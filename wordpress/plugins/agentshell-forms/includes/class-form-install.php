<?php
/**
 * Installation, upgrade and lifecycle.
 *
 * ## What activation creates
 *
 *   - the plugin's two tables, via `dbDelta()`;
 *   - the two capabilities, with `manage_agentshell_forms` granted to the
 *     administrator role and `manage_agentshell_forms_submissions` granted to
 *     **no role at all**;
 *   - the installed-storage-revision option;
 *   - the hourly prune job.
 *
 * ## What activation and deactivation do NOT do
 *
 * Neither touches content. Deactivation unschedules the prune job and nothing
 * else. A plugin whose deactivation deleted forms would be a deactivation that
 * silently destroyed content, which the design's data-retention rule forbids.
 *
 * Permanent removal is a separate, explicitly opted-in decision, implemented in
 * `uninstall.php`.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table creation and the plugin lifecycle.
 */
class Form_Install {

	/**
	 * The challenge table.
	 *
	 * @return string
	 */
	public static function challenges_table() {
		global $wpdb;
		return $wpdb->prefix . 'agentshell_form_challenges';
	}

	/**
	 * The rate-limit table.
	 *
	 * @return string
	 */
	public static function rate_limits_table() {
		global $wpdb;
		return $wpdb->prefix . 'agentshell_form_rate_limits';
	}

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		Form_Capabilities::register();

		update_option( AGENTSHELL_FORMS_DB_VERSION_OPTION, AGENTSHELL_FORMS_DB_VERSION, false );

		self::schedule_events();
	}

	/**
	 * Deactivate the plugin.
	 *
	 * Touches no data. The tables, the meta and the capabilities all remain, so
	 * reactivating restores the previous state exactly.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'agentshell_forms_prune' );
		wp_clear_scheduled_hook( 'agentshell_forms_notifications' );
		wp_clear_scheduled_hook( 'agentshell_forms_retention' );
	}

	/**
	 * Schedule the plugin's jobs, idempotently.
	 *
	 * Called from activation **and** from every `maybe_upgrade` run, so an
	 * installation that predates a job gets it without having to be
	 * deactivated and reactivated. The schedule names are registered on
	 * `cron_schedules` in the bootstrap; a filter that removes them leaves the
	 * job unscheduled rather than scheduling something that cannot run.
	 *
	 * @return void
	 */
	public static function schedule_events() {
		if ( function_exists( 'wp_get_schedules' ) ) {
			$schedules = wp_get_schedules();

			if ( isset( $schedules['agentshell_forms_five_minutes'] ) && ! wp_next_scheduled( 'agentshell_forms_notifications' ) ) {
				wp_schedule_event( time() + 300, 'agentshell_forms_five_minutes', 'agentshell_forms_notifications' );
			}

			if ( isset( $schedules['agentshell_forms_daily'] ) && ! wp_next_scheduled( 'agentshell_forms_retention' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'agentshell_forms_daily', 'agentshell_forms_retention' );
			}
		}

		if ( ! wp_next_scheduled( 'agentshell_forms_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'agentshell_forms_prune' );
		}
	}

	/**
	 * Create or upgrade the storage when the plugin's storage revision moved.
	 *
	 * Runs on `plugins_loaded`, before `init` registers the post types, so a
	 * request never reaches a table that has not been created yet.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( AGENTSHELL_FORMS_DB_VERSION_OPTION, '' );

		if ( AGENTSHELL_FORMS_DB_VERSION === $installed ) {
			// The storage is current, but a job added by a later build still
			// needs scheduling. This is three option reads, not a migration.
			self::schedule_events();
			return;
		}

		self::create_tables();
		Form_Capabilities::register();
		self::schedule_events();

		update_option( AGENTSHELL_FORMS_DB_VERSION_OPTION, AGENTSHELL_FORMS_DB_VERSION, false );
	}

	/**
	 * Create both tables with `dbDelta()`.
	 *
	 * `dbDelta()` is idempotent: it compares the declared schema to the live
	 * one and adds what is missing. It is the only supported way for a plugin
	 * to own a table in WordPress, because it handles the charset, the collation
	 * and the index differences between MySQL versions.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$challenges      = self::challenges_table();
		$rate_limits     = self::rate_limits_table();

		/*
		 * The challenge table.
		 *
		 * `consumed_at` is nullable on purpose: a claimed row keeps its claim
		 * time, and a NULL is "not yet claimed". `submission_id` is 0 until the
		 * submission exists, which is what lets a retry be recognised as a
		 * retry rather than as a replayed token.
		 *
		 * `token_hash` is UNIQUE — that is the atomicity the claim relies on.
		 * Only the SHA-256 is stored, so a database disclosure does not yield
		 * usable tokens.
		 */
		$sql_challenges = "CREATE TABLE {$challenges} (
			id bigint(20) unsigned NOT NULL auto_increment,
			token_hash char(64) NOT NULL,
			form_id char(32) NOT NULL,
			form_version int(10) unsigned NOT NULL default 0,
			subject_hash char(64) NOT NULL default '',
			consumed_at datetime DEFAULT NULL,
			submission_id bigint(20) unsigned NOT NULL default 0,
			created_at datetime NOT NULL default '0000-00-00 00:00:00',
			expires_at datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY form_id (form_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		/*
		 * The rate-limit table.
		 *
		 * The UNIQUE key on the whole bucket is what makes the reserve
		 * statement atomic: `ON DUPLICATE KEY UPDATE` can only identify one row
		 * because the bucket is unique, and the limit is inside the statement
		 * so the counter can never pass it.
		 */
		$sql_rate_limits = "CREATE TABLE {$rate_limits} (
			id bigint(20) unsigned NOT NULL auto_increment,
			scope varchar(16) NOT NULL,
			subject_hash char(64) NOT NULL,
			form_id char(32) NOT NULL default '',
			window_start int(10) unsigned NOT NULL default 0,
			hits int(10) unsigned NOT NULL default 0,
			created_at datetime NOT NULL default '0000-00-00 00:00:00',
			expires_at datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY bucket (scope,subject_hash,form_id,window_start),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql_challenges );
		dbDelta( $sql_rate_limits );
	}

	/**
	 * Whether both tables exist.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		foreach ( array( self::challenges_table(), self::rate_limits_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop the plugin's tables.
	 *
	 * Only ever called by the explicitly opted-in uninstall path.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::challenges_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::rate_limits_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
