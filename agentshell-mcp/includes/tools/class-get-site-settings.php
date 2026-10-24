<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_site_settings — read the site's identity and reading settings.
 *
 * The bounded surface is the one `agentshell_set_site_settings` writes: the
 * site title, the tagline, the front page, the posts page, and the reading
 * settings a site needs to be a site. Nothing else in WordPress's options
 * table is reachable through this tool.
 *
 * The response separates three things, because they are three different
 * questions:
 *
 *   settings  the values that will be in force once the open transaction
 *             commits — the answer an agent needs while it is building
 *   live      the values WordPress holds right now — what a visitor sees
 *   managed   the values the configuration owns — what an agent has set
 *
 * `divergent` names the settings where the last two disagree, which is how
 * an administrator finds out that somebody changed the site title by hand
 * after an agent had set it.
 */
class Get_Site_Settings extends Base_Tool {
    public function get_name() { return 'agentshell_get_site_settings'; }
    public function get_description() { return 'Read the site identity and reading settings: title, tagline, front page, posts page, and the reading options (posts per page, default comment and ping status, comment registration, date and time format, start of week). Reports the values in force, the live WordPress values, and the values the configuration owns.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        $live    = \AgentShell_MCP\Site_Settings::live_values();
        $managed = \AgentShell_MCP\Site_Settings::managed_values();
        $current = \AgentShell_MCP\Site_Settings::effective_values();

        $divergent = array();
        foreach ( $managed as $key => $value ) {
            if ( ! array_key_exists( $key, $live ) ) {
                continue;
            }
            if ( (string) $live[ $key ] !== (string) $value ) {
                $divergent[] = $key;
            }
        }

        return array(
            'settings'  => $current,
            'live'      => $live,
            'managed'   => $managed,
            'divergent' => $divergent,
            'writable'  => \AgentShell_MCP\Site_Settings::allowed_keys(),
            'note'      => 'These settings are the site identity and reading options only. The site URL, the administrator email, the registration policy and the plugin set are not writable through MCP.',
        );
    }
}
