<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_set_custom_css — set the free-form stylesheet.
 *
 * The custom stylesheet is first-class in the configuration — it is
 * rendered into a `<style id='agentshell-custom-css'>` element on every
 * page — and until this tool existed the only way to set it was to
 * import a whole theme package. An agent that wanted to change one
 * rule had to export the theme, edit the manifest, and import it back,
 * which skipped the transaction staging for everything else in the
 * package and produced a diff nobody could read.
 *
 * The change is staged like every other configuration change, and it is
 * subject to the same size limit every other path is (256 KiB, measured
 * in bytes — AGENTS.md §6.6). The limit is enforced by the staging path
 * itself, so an oversized stylesheet is refused before anything
 * persistent changes.
 *
 * The response reports the size of the stylesheet and does not echo it
 * back: a stylesheet can be a quarter of a megabyte, and a response
 * that carried it would carry it into every transcript that quoted it.
 * `agentshell_get_config( include_custom_css: true )` returns the body
 * when it is actually wanted.
 */
class Set_Custom_Css extends Base_Tool {
    public function get_name() { return 'agentshell_set_custom_css'; }
    public function get_description() { return 'Set the free-form custom CSS: a stylesheet rendered in a <style> element on every page. Staged in the transaction like every other configuration change. The limit is 256 KiB (262,144 bytes). The response reports the size, not the stylesheet; read it back with agentshell_get_config(include_custom_css=true).'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'css' => array(
                    'type'        => 'string',
                    'description' => 'The stylesheet. Rendered verbatim inside a <style> element, so it may contain any CSS; a literal </style in the payload cannot close the element early. Use agentshell_set_css_var for a single custom property instead.',
                ),
            ),
            'required'   => array( 'css' ),
        );
    }

    public function execute( array $arguments ) {
        // Presence, not emptiness: an empty stylesheet is a legitimate
        // thing to set — it removes the custom CSS — so it is not
        // refused as a missing argument.
        $css = array_key_exists( 'css', $arguments ) ? (string) $arguments['css'] : '';

        $config = $this->get_agentshell_config();
        $config['custom_css'] = $css;

        // The staging path enforces the size limit and refuses before
        // anything persistent changes, so an oversized stylesheet is
        // rejected here with the size, the limit and the excess.
        $this->update_agentshell_config( $config );

        $bytes = strlen( $css );
        return array(
            'custom_css_bytes'   => $bytes,
            'custom_css_present' => '' !== trim( $css ),
            'limit_bytes'        => \AgentShell_MCP\Transaction_Manager::max_custom_css_bytes(),
            'note'               => 'The stylesheet is staged, not live. It becomes visible when the transaction is committed. Read it back with agentshell_get_config(include_custom_css=true).',
        );
    }
}
