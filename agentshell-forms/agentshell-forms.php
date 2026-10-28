<?php
/**
 * Plugin Name: AgentShell Forms
 * Description: Native WordPress forms — definitions, storage, protocol state and the accessible front-end renderer. Theme-independent and agent-transport-independent.
 * Version: 0.3.0
 *
 * The header and `AGENTSHELL_FORMS_VERSION` are kept equal. The constant is
 * what assets are enqueued with, so it must change whenever the stylesheet or
 * the script does — and a header that disagreed with it would report a version
 * to WordPress that is not the version being served.
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: AgentShell
 * License: GPL-2.0-or-later
 * Text Domain: agentshell-forms
 *
 * ## What this plugin is, and what it is not
 *
 * This is **Phases 1, 2 and 3** of the forms system described in
 * `docs/AGENTSHELL-FORMS-DESIGN.md` (§13.2). Phase 1 is the plugin
 * foundation: the authoritative schema, the two post types, the storage
 * invariants, the validation and the concurrency controls. Phase 2 is
 * rendering and insertion: the server-side renderer, the `[agentshell_form]`
 * shortcode, the widget, the accessible markup and the cache-safe challenge
 * provisioning that lets a published form live on a cached page. Phase 3 is
 * functional submissions: the public submission pipeline, server-side
 * validation, the challenge claim, rate limits, the honeypot, Turnstile,
 * storage, administrator notifications with retries, retention, the privacy
 * exporter and eraser, and the submission screens.
 *
 * It deliberately does **not** contain:
 *
 *   - a visual form builder (Phase 4);
 *   - any MCP tool (Phase 5) — the documented tool count is unchanged;
 *   - any AgentShell zone integration (Phase 6).
 *
 * ## The one dependency direction
 *
 * The plugin depends on **nothing** in AgentShell. It works on plain
 * WordPress with any theme, and it must keep working when the AgentShell
 * theme and both AgentShell plugins are absent. The seams run one way only:
 * a shortcode, a widget and a zone block type are added by later phases, and
 * nothing here reads AgentShell's configuration, options or classes.
 *
 * That independence is not a preference. An earlier architecture option put
 * forms inside the AgentShell configuration (§3.2 of the design), which would
 * have made every form depend on a theme being active and on the theme's
 * revision ring and transaction machinery. A form must outlive a theme
 * change, so it cannot live in the theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENTSHELL_FORMS_VERSION', '0.3.0' );
define( 'AGENTSHELL_FORMS_FILE', __FILE__ );
define( 'AGENTSHELL_FORMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSHELL_FORMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The definition schema version this build understands.
 *
 * The schema carries its own version so a later build can recognise a
 * definition written by an earlier one and migrate it, rather than guessing
 * from the shape of the document.
 */
define( 'AGENTSHELL_FORMS_SCHEMA_VERSION', 1 );

/**
 * The option holding the installed database/schema revision.
 *
 * Separate from the plugin's `Version:` header on purpose: the header changes
 * when the code changes, this changes when the *storage* changes, and only
 * the second should trigger a migration.
 */
define( 'AGENTSHELL_FORMS_DB_VERSION_OPTION', 'agentshell_forms_db_version' );
define( 'AGENTSHELL_FORMS_DB_VERSION', '1' );

require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-db.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-audit.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-install.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-capabilities.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-post-types.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-field-types.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-schema.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-definition.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-challenges.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-rate-limits.php';

/*
 * Phase 2 — rendering and insertion.
 *
 * The renderer is required before the two front doors that delegate to it, and
 * the challenge endpoint before the renderer needs its route constants. Order
 * matters only for readability here: PHP resolves class references at call
 * time, not at include time.
 */
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-renderer.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-shortcode.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-widget.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-challenge-endpoint.php';

/*
 * Phase 3 — functional submissions.
 *
 * The order is dependency order for readability: the rejection log and the
 * Turnstile client have no dependencies; the store needs the post types; the
 * notifier needs the store; the handler needs all of them; the admin screens
 * need the store and the capabilities; the privacy hooks need the store.
 */
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-rejections.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-turnstile.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-submission-store.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-notifier.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-submission-handler.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-privacy.php';
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/class-form-admin.php';

// The documented PHP API (§4.3). Loaded last, because it is the only global
// surface and it delegates to the classes above.
require_once AGENTSHELL_FORMS_PLUGIN_DIR . 'includes/functions.php';

/*
 * Activation and deactivation.
 *
 * Activation creates the plugin's own tables and registers the capabilities.
 * It does **not** grant the submissions capability to any role (§8.3), and it
 * does not create content.
 *
 * Deactivation touches no data at all: it unschedules the prune job and
 * nothing else. Both post types are registered with `rewrite => false`, so
 * deactivation has no rewrite rules to flush either. A deactivated plugin
 * whose forms disappeared would be a deactivation that silently destroyed
 * content, which is the exact failure the design's data-retention rule
 * forbids.
 */
register_activation_hook( __FILE__, array( 'AgentShell_Forms\\Form_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AgentShell_Forms\\Form_Install', 'deactivate' ) );

/**
 * Register the post types on every request, and keep the storage revision
 * honest.
 *
 * `init` is the only correct place to register a post type. The upgrade check
 * runs first on `plugins_loaded` so a plugin updated over an existing
 * installation creates any missing table before a request tries to use it.
 */
add_action( 'plugins_loaded', array( 'AgentShell_Forms\\Form_Install', 'maybe_upgrade' ), 5 );
add_action( 'init', array( 'AgentShell_Forms\\Form_Post_Types', 'register' ), 5 );

/**
 * The two extra cron schedules.
 *
 * `five_minutes` drives the notification worker (a retry that waits an hour for
 * the next hourly tick would make the one-minute backoff meaningless), and
 * `daily` drives retention. Both are registered on `cron_schedules` so
 * `wp_schedule_event()` can name them.
 */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			return $schedules;
		}

		if ( ! isset( $schedules['agentshell_forms_five_minutes'] ) ) {
			$schedules['agentshell_forms_five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Every five minutes (AgentShell Forms notifications)', 'agentshell-forms' ),
			);
		}

		if ( ! isset( $schedules['agentshell_forms_daily'] ) ) {
			$schedules['agentshell_forms_daily'] = array(
				'interval' => DAY_IN_SECONDS,
				'display'  => __( 'Once daily (AgentShell Forms retention)', 'agentshell-forms' ),
			);
		}

		return $schedules;
	}
);

/**
 * The hourly prune of ephemeral protocol state.
 *
 * Correctness never depends on this firing — every read re-checks expiry
 * itself (§6.3) — so a missed cron run costs disk, not correctness.
 */
add_action( 'agentshell_forms_prune', array( 'AgentShell_Forms\\Form_Challenges', 'prune' ) );
add_action( 'agentshell_forms_prune', array( 'AgentShell_Forms\\Form_Rate_Limits', 'prune' ) );

/**
 * The notification worker.
 *
 * It attempts every due record, reconciles claims older than the claim TTL,
 * and stops. There is no second scheduler: the reconciler and the retry worker
 * are this one job, so they cannot disagree about a record.
 */
add_action( 'agentshell_forms_notifications', array( 'AgentShell_Forms\\Form_Notifier', 'reconcile' ) );
add_action(
	'agentshell_forms_notifications',
	function () {
		\AgentShell_Forms\Form_Notifier::run_due( 'admin' );
		\AgentShell_Forms\Form_Notifier::run_due( 'confirmation' );
	}
);

/**
 * The daily retention purge.
 *
 * A hard delete, because a retention period that leaves data recoverable is
 * not a retention period (§6.5). Correctness does not depend on it firing at a
 * particular minute; a missed run leaves data a day longer, never longer than
 * the period plus the gap.
 */
add_action( 'agentshell_forms_retention', array( 'AgentShell_Forms\\Submission_Store', 'purge_expired' ) );

/**
 * The per-user submissions-access grant and revocation (§8.3a).
 *
 * Both are `admin_post` handlers rather than REST routes, deliberately: a REST
 * route guarded by a capability check alone is reachable by any administrator
 * principal, including the MCP transport's application password. The handlers
 * additionally require a cookie-authenticated session and a nonce minted by
 * the administrative screen, which an application-password principal cannot
 * obtain.
 */
add_action( 'admin_post_agentshell_forms_grant_submissions', array( 'AgentShell_Forms\\Form_Capabilities', 'handle_grant' ) );
add_action( 'admin_post_agentshell_forms_revoke_submissions', array( 'AgentShell_Forms\\Form_Capabilities', 'handle_revoke' ) );

/*
 * The three insertion seams (Phase 2).
 *
 * Each is registered here, in the bootstrap, and each delegates to
 * `Form_Renderer::render()`. There is no second renderer and no second set of
 * rules.
 *
 * The shortcode is registered on `init` because that is when WordPress expects
 * it and when every plugin's post types exist, so a shortcode that resolves a
 * form by slug is not racing the registration of the post type it queries.
 */
add_action( 'init', array( 'AgentShell_Forms\\Form_Shortcode', 'register' ), 10 );
add_action( 'widgets_init', 'AgentShell_Forms\\agentshell_forms_register_widget' );

/*
 * The challenge route.
 *
 * A separate, public route, not a submission route: it mints a token per
 * visitor for a page that may have been served from a cache, and it does nothing
 * else (§"Cache-safe challenge design"). Phase 3 adds the submission route next
 * to it.
 */
add_action( 'rest_api_init', array( 'AgentShell_Forms\\Form_Challenge_Endpoint', 'register' ) );

/*
 * The submission route (Phase 3).
 *
 * Public by design, and separate from the mint route so that the surface which
 * issues state and the surface which accepts a submission are reasoned about,
 * rate-limited and audited independently.
 */
add_action( 'rest_api_init', array( 'AgentShell_Forms\\Submission_Handler', 'register' ) );

/**
 * The submission screens and the personal-data hooks.
 *
 * `Form_Admin::register()` attaches `admin_menu` and the `admin_post`
 * handlers; `Form_Privacy::register()` attaches the exporter and the eraser.
 * Neither runs on a front-end request unless WordPress asks for its hook.
 */
\AgentShell_Forms\Form_Admin::register();
\AgentShell_Forms\Form_Privacy::register();
