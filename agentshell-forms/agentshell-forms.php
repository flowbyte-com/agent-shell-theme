<?php
/**
 * Plugin Name: AgentShell Forms
 * Description: Native WordPress forms — definitions, storage invariants and protocol state. Theme-independent and agent-transport-independent.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: AgentShell
 * License: GPL-2.0-or-later
 * Text Domain: agentshell-forms
 *
 * ## What this plugin is, and what it is not
 *
 * This is **Phase 1** of the forms system described in
 * `docs/AGENTSHELL-FORMS-DESIGN.md` (§13.2): the plugin foundation, the
 * authoritative schema, the two post types, the storage invariants, the
 * validation and the concurrency controls.
 *
 * It deliberately does **not** contain:
 *
 *   - a visual form builder (Phase 4);
 *   - public submission processing (Phase 3);
 *   - email notifications or Turnstile verification (Phase 3);
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

define( 'AGENTSHELL_FORMS_VERSION', '0.1.0' );
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
 * The hourly prune of ephemeral protocol state.
 *
 * Correctness never depends on this firing — every read re-checks expiry
 * itself (§6.3) — so a missed cron run costs disk, not correctness.
 */
add_action( 'agentshell_forms_prune', array( 'AgentShell_Forms\\Form_Challenges', 'prune' ) );
add_action( 'agentshell_forms_prune', array( 'AgentShell_Forms\\Form_Rate_Limits', 'prune' ) );

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
