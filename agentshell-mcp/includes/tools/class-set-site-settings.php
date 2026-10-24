<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_set_site_settings — set the site's identity and reading settings.
 *
 * The bounded surface: the site title, the tagline, the front page, the
 * posts page, and the reading options. Everything else in WordPress's
 * options table — the site URL, the administrator email, the registration
 * policy, the active plugins — is refused by name. The point is that a
 * tool an agent can call is a tool an agent can get wrong, and the
 * options that decide where the site lives and who may register on it are
 * not the ones an agent should be able to change by accident.
 *
 * The change is staged in the configuration, like every other
 * configuration change: inside a transaction it appears in the preview and
 * `agentshell_rollback_transaction` discards it; outside one it is
 * immediate. When the configuration is written, the settings are projected
 * onto WordPress's own options — the title in `blogname`, the front page
 * in `page_on_front` — inside the same guarded write, so the site either
 * has the new settings or has the old ones.
 *
 * A page reference must be an existing, published page. WordPress accepts
 * a reference to a page that does not exist and then quietly shows the
 * blog index instead, which is not a state anybody asked for.
 */
class Set_Site_Settings extends Base_Tool {
    public function get_name() { return 'agentshell_set_site_settings'; }
    public function get_description() { return 'Set the site identity and reading settings: title, tagline, front page, posts page, and the reading options (posts per page, default comment and ping status, comment registration, date and time format, start of week). Staged in the transaction like every other configuration change; the front page and posts page must be existing published pages. The site URL, administrator email and registration policy are not writable here.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'title'                  => array( 'type' => 'string',  'description' => 'The site title (WordPress: blogname). An empty string is a legitimate value — it clears the title.' ),
                'tagline'                => array( 'type' => 'string',  'description' => 'The site tagline (WordPress: blogdescription).' ),
                'show_on_front'          => array( 'type' => 'string',  'enum' => array( 'posts', 'page' ), 'description' => 'What the front page shows: the blog posts, or a static page.' ),
                'front_page'             => array( 'type' => 'integer', 'description' => 'The id of the published page to use as the front page (WordPress: page_on_front). 0 clears it.' ),
                'posts_page'             => array( 'type' => 'integer', 'description' => 'The id of the published page to use as the blog posts page (WordPress: page_for_posts). 0 clears it.' ),
                'posts_per_page'         => array( 'type' => 'integer', 'description' => 'How many posts a blog page shows, 1 to 100.' ),
                'default_comment_status' => array( 'type' => 'string',  'enum' => array( 'open', 'closed' ), 'description' => 'Whether comments are open by default.' ),
                'default_ping_status'    => array( 'type' => 'string',  'enum' => array( 'open', 'closed' ), 'description' => 'Whether pingbacks are open by default.' ),
                'comment_registration'   => array( 'type' => 'boolean', 'description' => 'Whether visitors must log in to comment.' ),
                'date_format'            => array( 'type' => 'string',  'description' => 'The date format, e.g. "F j, Y".' ),
                'time_format'            => array( 'type' => 'string',  'description' => 'The time format, e.g. "g:i a".' ),
                'start_of_week'          => array( 'type' => 'integer', 'description' => 'The day the week starts on, 0 (Sunday) to 6 (Saturday).' ),
            ),
        );
    }

    public function execute( array $arguments ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            throw new \InvalidArgumentException(
                'agentshell_set_site_settings requires the manage_options capability.'
            );
        }

        if ( empty( $arguments ) ) {
            throw new \InvalidArgumentException(
                'agentshell_set_site_settings was called with no settings. Pass at least one of: '
                . implode( ', ', \AgentShell_MCP\Site_Settings::allowed_keys() ) . '.'
            );
        }

        $config = $this->get_agentshell_config();
        $section = isset( $config[ \AgentShell_MCP\Site_Settings::CONFIG_SECTION ] ) && is_array( $config[ \AgentShell_MCP\Site_Settings::CONFIG_SECTION ] )
            ? $config[ \AgentShell_MCP\Site_Settings::CONFIG_SECTION ]
            : array();

        $check = \AgentShell_MCP\Site_Settings::validate( $arguments, $section );
        if ( ! $check['ok'] ) {
            throw new \InvalidArgumentException(
                'The site settings were refused: ' . implode( ' ', $check['errors'] )
            );
        }

        // The previous values, so the change is legible and reversible.
        $before = array();
        foreach ( array_keys( $check['values'] ) as $key ) {
            $before[ $key ] = array_key_exists( $key, $section ) ? $section[ $key ] : null;
        }

        $config[ \AgentShell_MCP\Site_Settings::CONFIG_SECTION ] = array_merge( $section, $check['values'] );

        // The staging path enforces the transaction contract: staged while a
        // transaction is open, written straight through when there is none.
        $this->update_agentshell_config( $config );

        $tx = \AgentShell_MCP\Transaction_Manager::instance();

        return array(
            'changed'  => $check['values'],
            'before'   => $before,
            'settings' => \AgentShell_MCP\Site_Settings::effective_values(),
            'staged'   => $tx->is_active(),
            'note'     => $tx->is_active()
                ? 'The settings are staged, not live. They reach WordPress when the transaction is committed.'
                : 'The settings were written immediately and projected onto the WordPress options.',
        );
    }
}
