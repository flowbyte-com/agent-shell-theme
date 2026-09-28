<?php
/**
 * AgentShell MCP uninstall handler.
 *
 * Fires when an admin deletes the plugin from WordPress. We remove:
 *   - The bounded-retention cron event.
 *   - The opportunistic prune counter option.
 *
 * We deliberately do NOT drop the wp_agentshell_mcp_audit_log table
 * here. Operators frequently reinstall after upgrades; dropping the
 * table would destroy security audit history that may still be needed.
 * To drop the table deliberately, remove it via SQL or a separate
 * admin tool. This is a conservative default consistent with WP's
 * own "leave data behind on uninstall" guidance for tables that hold
 * security-relevant content.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

require_once __DIR__ . '/includes/class-audit-log.php';

\AgentShell_MCP\Agentshell_Audit_Log::unschedule();
delete_option( 'agentshell_audit_log_prune_counter' );