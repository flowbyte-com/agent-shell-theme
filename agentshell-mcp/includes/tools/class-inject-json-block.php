<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Append a sanitised json_block to a zone's composition (or to its right
 * slot for tri-slot zones). Pure data transformation; usable from tests
 * without instantiating the MCP tool class.
 *
 * Defined in the global namespace (via `\function`) because it operates
 * on the global agentshell_config and is called by the namespaced tool
 * class below. The class calls it as `\agentshell_inject_json_block_into_zone`
 * so resolution is unambiguous from inside the namespace.
 *
 * @param array  $config  Existing agentshell_config array.
 * @param string $zone_id Target zone id (must exist in declared zones).
 * @param string $html    Raw HTML content (sanitised per $trusted).
 * @param bool   $trusted When true the bytes are stored verbatim. When
 *                        false, <style>, style="", and <script> are
 *                        stripped, then the result is run through
 *                        wp_kses_post for normal-HTML safety.
 * @return array  Updated config with a json_block appended.
 * @throws \InvalidArgumentException if the zone is not declared.
 */
function agentshell_inject_json_block_into_zone( array $config, $zone_id, $html, $trusted = true ) {
    if ( ! $trusted ) {
        // Unbreakable Grid: strip <style> blocks and inline style="" attrs so
        // a logged-in-but-not-trusted user cannot break the layout.
        $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );
        $html = preg_replace( '/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $html );
        // Strip scripts and run KSES for normal-HTML safety.
        $html = preg_replace( '/<\/?script\b[^>]*>/i', '', $html );
        $html = wp_kses_post( $html );
    }
    if ( ! isset( $config['zones'] ) || ! is_array( $config['zones'] ) ) {
        $config['zones'] = array();
    }

    // Verify the zone is declared (matches agentshell_get_zones defaults).
    // Effective: a zone staged earlier in this transaction is a declared zone
    // for the rest of the transaction.
    $declared = function_exists( 'agentshell_get_zones' )
        ? \agentshell_get_zones( $config )
        : array();
    $declared_ids = array();
    foreach ( $declared as $z ) {
        $declared_ids[ $z['id'] ?? '' ] = true;
    }
    if ( ! isset( $declared_ids[ $zone_id ] ) ) {
        // Fall back: also accept zones already present in the stored config,
        // so existing zones that have been removed from declared defaults
        // do not become un-targetable.
        foreach ( $config['zones'] as $z ) {
            if ( ( $z['id'] ?? '' ) === $zone_id ) {
                $declared_ids[ $zone_id ] = true;
                break;
            }
        }
    }
    if ( empty( $declared_ids[ $zone_id ] ) ) {
        throw new \InvalidArgumentException( "Zone not found: {$zone_id}" );
    }

    $block = array(
        'type'    => 'json_block',
        'content' => $html,
    );

    $found = false;
    foreach ( $config['zones'] as &$z ) {
        if ( ( $z['id'] ?? '' ) !== $zone_id ) { continue; }
        $found = true;
        if ( isset( $z['slots'] ) && is_array( $z['slots'] ) ) {
            // Structural zone: append to the middle-right area — the
            // canonical name for "the natural end of the row" in both the
            // nine-area grid and the historical left→center→right layout.
            // Callers that need a different area use
            // agentshell_update_zone_slots directly.
            $zone_shape = $z;
            if ( function_exists( 'agentshell_normalize_zone' ) ) {
                $zone_shape = agentshell_normalize_zone( $zone_shape );
            } elseif ( ! isset( $z['slots']['middle-right'] ) ) {
                $zone_shape['slots'] = is_array( $z['slots'] ) ? $z['slots'] : array();
                $legacy              = $z['slots']['right'] ?? array();
                $zone_shape['slots']['middle-right'] = is_array( $legacy ) ? $legacy : array();
            }
            if ( ! isset( $zone_shape['slots']['middle-right'] ) || ! is_array( $zone_shape['slots']['middle-right'] ) ) {
                $zone_shape['slots']['middle-right'] = array();
            }
            $zone_shape['slots']['middle-right'][] = $block;
            $z['slots'] = $zone_shape['slots'];
        } else {
            // Composition zone (e.g. main).
            if ( ! isset( $z['composition'] ) || ! is_array( $z['composition'] ) ) {
                $z['composition'] = array();
            }
            $z['composition'][] = $block;
        }
        break;
    }
    unset( $z );

    if ( ! $found ) {
        // Zone not yet in stored config — initialise from declared defaults
        // so the appended block lives in the right shape.
        $template = null;
        foreach ( $declared as $z ) {
            if ( ( $z['id'] ?? '' ) === $zone_id ) { $template = $z; break; }
        }
        if ( ! $template ) { $template = array( 'id' => $zone_id ); }
        if ( isset( $template['slots'] ) && is_array( $template['slots'] ) ) {
            if ( function_exists( 'agentshell_normalize_zone' ) ) {
                $template = agentshell_normalize_zone( $template );
            } else {
                $legacy = $template['slots']['right'] ?? array();
                $template['slots'] = array( 'middle-right' => is_array( $legacy ) ? $legacy : array() );
            }
            $template['slots']['middle-right'] = array( $block );
        } else {
            $template['composition']   = array( $block );
        }
        $config['zones'][] = $template;
    }

    return $config;
}

class Inject_Json_Block extends Base_Tool {
    public function get_name() { return 'agentshell_inject_json_block'; }
    public function get_description() { return 'Append a json_block to a zone. For main-style zones the block is appended to composition; for tri-slot header/footer zones it is appended to the right slot. Style tags, inline style attributes, and scripts are stripped for untrusted users (Unbreakable Grid); admins with unfiltered_html or manage_options keep <style>/<script> for custom Web Components.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id' => array( 'type' => 'string', 'description' => 'Target zone ID' ),
                'html'    => array( 'type' => 'string', 'description' => 'HTML content (max 10000 chars)', 'maxLength' => 10000 ),
            ),
            'required'   => array( 'zone_id', 'html' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'html' ) );

        $html = $arguments['html'];
        if ( strlen( $html ) > 10000 ) {
            throw new \InvalidArgumentException( 'html must be 10000 characters or less' );
        }

        // Capability gate. Trusted users (unfiltered_html or manage_options)
        // get full passthrough — no style stripping, no script stripping, no
        // KSES. This preserves custom Web Components that use
        // customElements.define.
        $trusted = current_user_can( 'unfiltered_html' ) || current_user_can( 'manage_options' );

        $config = $this->get_agentshell_config();

        $updated = \agentshell_inject_json_block_into_zone(
            $config,
            $arguments['zone_id'],
            $html,
            $trusted
        );
        $this->update_agentshell_config( $updated );

        return array(
            'zone_id'     => $arguments['zone_id'],
            'html_length' => strlen( $html ),
        );
    }
}
