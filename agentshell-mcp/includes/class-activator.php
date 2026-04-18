<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Activator {
    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'agentshell_mcp_audit_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            tool_name varchar(100) NOT NULL DEFAULT '',
            arguments longtext NOT NULL,
            result_status varchar(20) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY tool_name (tool_name),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'agentshell_mcp_version', AGENTSHELL_MCP_VERSION );
    }
}
