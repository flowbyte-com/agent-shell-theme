<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Update the slot grid for a structural zone (header or footer).
 *
 * The canonical schema is nine areas: three rows of three.
 *
 * {
 *   "zone_id": "header",
 *   "slots": {
 *     "top-left":      [],
 *     "top-center":    [],
 *     "top-right":     [ { "type": "wp_core", "id": "search_form" } ],
 *     "middle-left":   [ { "type": "wp_core", "id": "site_logo" } ],
 *     "middle-center": [ { "type": "wp_core", "id": "nav_menu" } ],
 *     "middle-right":  [],
 *     "bottom-left":   [],
 *     "bottom-center": [],
 *     "bottom-right":  []
 *   },
 *   "slot_align": { "top-right": "end" },
 *   "row_align":  { "middle": "center" }
 * }
 *
 * The historical three-slot payload is still accepted and means the middle
 * row: `left` → `middle-left`, `center` → `middle-center`, `right` →
 * `middle-right`. A caller written against the old contract keeps working and
 * gets the same stored layout, in the canonical shape.
 *
 * Semantics are unchanged from the three-slot version: this tool *replaces*
 * the slot grid. An area that is not named is emptied. Alignment maps are
 * replaced only when they are supplied.
 */
class Update_Zone_Slots extends Base_Tool {
    public function get_name() { return 'agentshell_update_zone_slots'; }
    public function get_description() {
        return 'Set or replace the slot grid for a structural zone (header or footer). Nine areas, named by row then column: '
            . 'top-left, top-center, top-right, middle-left, middle-center, middle-right, bottom-left, bottom-center, bottom-right. '
            . 'Each area is an array of blocks in render order. The historical keys left/center/right are accepted and mean the middle row. '
            . 'Optional slot_align (per area: start|center|end|stretch) and row_align (per row: start|center|end) control alignment. '
            . 'Areas not named are emptied — send the whole grid you want.';
    }

    public function get_input_schema() {
        $slot_ids = \AgentShell_MCP\Doctor::slot_ids();
        $aliases  = \AgentShell_MCP\Doctor::slot_aliases();

        $slot_properties = array();
        foreach ( array_merge( $slot_ids, array_keys( $aliases ) ) as $slot_id ) {
            $slot_properties[ $slot_id ] = array( 'type' => 'array', 'items' => array( 'type' => 'object' ) );
        }

        $align_properties = array();
        foreach ( array_merge( $slot_ids, array_keys( $aliases ) ) as $slot_id ) {
            $align_properties[ $slot_id ] = array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end', 'stretch' ) );
        }

        $row_properties = array();
        foreach ( array_keys( \AgentShell_MCP\Doctor::slot_rows() ) as $row ) {
            $row_properties[ $row ] = array( 'type' => 'string', 'enum' => array( 'start', 'center', 'end' ) );
        }

        return array(
            'type'       => 'object',
            'properties' => array(
                'zone_id' => array(
                    'type'        => 'string',
                    'enum'        => array( 'header', 'footer' ),
                    'description' => 'Zone ID: header or footer (only structural zones have a slot grid)',
                ),
                'slots' => array(
                    'type'                 => 'object',
                    'properties'           => $slot_properties,
                    'additionalProperties' => false,
                ),
                'slot_align' => array(
                    'type'                 => 'object',
                    'properties'           => $align_properties,
                    'additionalProperties' => false,
                    'description'          => 'Horizontal alignment per area. Defaults by column: start / center / end.',
                ),
                'row_align' => array(
                    'type'                 => 'object',
                    'properties'           => $row_properties,
                    'additionalProperties' => false,
                    'description'          => 'Vertical alignment per row. Defaults to center, which is the historical behaviour.',
                ),
            ),
            'required' => array( 'zone_id', 'slots' ),
        );
    }

    public function execute( array $arguments ) {
        $this->validate_required( $arguments, array( 'zone_id', 'slots' ) );

        $zone_id = $arguments['zone_id'];
        $slots   = $arguments['slots'];

        if ( ! in_array( $zone_id, array( 'header', 'footer' ), true ) ) {
            throw new \InvalidArgumentException( "slots are only supported on header and footer zones. For main zone use agentshell_update_zone_composition." );
        }

        if ( ! is_array( $slots ) ) {
            throw new \InvalidArgumentException( 'slots must be an object keyed by area name.' );
        }

        // Canonicalise keys first: a legacy payload is a legal payload, and it
        // must not be stored as a second shape that the reader then has to
        // translate. Refusing an unknown area happens before anything is
        // written, and names the alternatives.
        $slot_ids = \AgentShell_MCP\Doctor::slot_ids();
        $canonical = array_fill_keys( $slot_ids, array() );

        foreach ( $slots as $key => $items ) {
            $slot_id = \AgentShell_MCP\Doctor::canonical_slot_id( $key );
            if ( ! in_array( $slot_id, $slot_ids, true ) ) {
                throw new \InvalidArgumentException(
                    "Invalid slot key '$key'. Areas are: " . implode( ', ', $slot_ids )
                    . ' (the historical keys ' . implode( ', ', array_keys( \AgentShell_MCP\Doctor::slot_aliases() ) ) . ' mean the middle row).'
                );
            }
            if ( ! is_array( $items ) ) {
                throw new \InvalidArgumentException( "slots[$key] must be an array of block objects." );
            }
            $canonical[ $slot_id ] = $this->validate_blocks( $items, "slots[$key]" );
        }

        $config = $this->get_agentshell_config();

        if ( ! isset( $config['zones'] ) || ! is_array( $config['zones'] ) ) {
            $config['zones'] = array();
        }

        $slot_align = $this->validate_align_map( $arguments['slot_align'] ?? null, $slot_ids );
        $row_align  = $this->validate_row_align_map( $arguments['row_align'] ?? null );

        $found = false;
        foreach ( $config['zones'] as &$z ) {
            if ( ( $z['id'] ?? '' ) === $zone_id ) {
                $z['slots'] = $canonical;
                // A structural zone has one content model. Clear a legacy
                // composition only when one is actually present — adding an
                // empty key here would make the stored bytes differ from the
                // same edit made through the configurator, for no gain.
                if ( isset( $z['composition'] ) ) {
                    $z['composition'] = array();
                }
                if ( null !== $slot_align ) {
                    $z['slot_align'] = $slot_align;
                }
                if ( null !== $row_align ) {
                    $z['row_align'] = $row_align;
                }
                $found = true;
                break;
            }
        }
        unset( $z );

        if ( ! $found ) {
            $zone = array(
                'id'    => $zone_id,
                'slots' => $canonical,
            );
            if ( null !== $slot_align ) {
                $zone['slot_align'] = $slot_align;
            }
            if ( null !== $row_align ) {
                $zone['row_align'] = $row_align;
            }
            $config['zones'][] = $zone;
        }

        $this->update_agentshell_config( $config );

        return array(
            'zone_id'    => $zone_id,
            'slots'      => $canonical,
            'slot_align' => is_array( $slot_align ) ? $slot_align : array(),
            'row_align'  => is_array( $row_align ) ? $row_align : array(),
            'layout'     => $this->layout_mode( $canonical ),
        );
    }

    /**
     * Validate the blocks in one area, returning them normalised.
     *
     * The block vocabulary is the canonical one (§3 of AGENTS.md): the
     * validator here is the same set the doctor enforces, so a payload this
     * tool accepts cannot be one the doctor later rejects.
     */
    private function validate_blocks( array $items, $where ) {
        $valid_types = array( 'wp_core', 'wp_loop', 'widget', 'json_block', 'wp_widget_area' );
        $out         = array();

        foreach ( $items as $i => $block ) {
            if ( ! is_array( $block ) ) {
                throw new \InvalidArgumentException( "{$where}[{$i}]: block must be an object." );
            }
            $type = $block['type'] ?? '';
            if ( ! in_array( $type, $valid_types, true ) ) {
                throw new \InvalidArgumentException( "{$where}[{$i}]: type must be one of: " . implode( ', ', $valid_types ) . ". For wp_core blocks, also include the 'id' field." );
            }
            if ( $type === 'wp_core' ) {
                if ( empty( $block['id'] ) ) {
                    throw new \InvalidArgumentException( "{$where}[{$i}]: wp_core block requires 'id' field (e.g. site_logo, nav_menu, search_form, site_title, site_tagline)." );
                }
                if ( ! in_array( $block['id'], \AgentShell_MCP\Doctor::ALLOWED_CORE, true ) ) {
                    throw new \InvalidArgumentException( "{$where}[{$i}]: unknown wp_core id '{$block['id']}'. Known: " . implode( ', ', \AgentShell_MCP\Doctor::ALLOWED_CORE ) . '.' );
                }
            }
            if ( ( $type === 'widget' || $type === 'wp_widget_area' ) && empty( $block['id'] ) ) {
                throw new \InvalidArgumentException( "{$where}[{$i}]: {$type} block requires 'id' field." );
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * Validate an alignment map. Returns null when the caller did not supply
     * one, so "not supplied" and "supplied empty" stay distinguishable.
     */
    private function validate_align_map( $align, array $slot_ids ) {
        if ( null === $align ) {
            return null;
        }
        if ( ! is_array( $align ) ) {
            throw new \InvalidArgumentException( 'slot_align must be an object keyed by area name.' );
        }
        $values = array( 'start', 'center', 'end', 'stretch' );
        $out    = array();
        foreach ( $align as $key => $value ) {
            $slot_id = \AgentShell_MCP\Doctor::canonical_slot_id( $key );
            if ( ! in_array( $slot_id, $slot_ids, true ) ) {
                throw new \InvalidArgumentException( "slot_align names an unknown area: $key" );
            }
            if ( ! in_array( $value, $values, true ) ) {
                throw new \InvalidArgumentException( "slot_align[$key] must be one of: " . implode( ', ', $values ) );
            }
            $out[ $slot_id ] = $value;
        }
        return $out;
    }

    /** Validate a row alignment map; null when not supplied. */
    private function validate_row_align_map( $align ) {
        if ( null === $align ) {
            return null;
        }
        if ( ! is_array( $align ) ) {
            throw new \InvalidArgumentException( 'row_align must be an object keyed by row (top, middle, bottom).' );
        }
        $values = array( 'start', 'center', 'end' );
        $out    = array();
        foreach ( $align as $row => $value ) {
            if ( ! array_key_exists( (string) $row, \AgentShell_MCP\Doctor::slot_rows() ) ) {
                throw new \InvalidArgumentException( "row_align names an unknown row: $row (expected top, middle or bottom)" );
            }
            if ( ! in_array( $value, $values, true ) ) {
                throw new \InvalidArgumentException( "row_align[$row] must be one of: " . implode( ', ', $values ) );
            }
            $out[ $row ] = $value;
        }
        return $out;
    }

    /**
     * The layout this grid renders through. Reported so a caller can see that
     * an area outside the middle row is what switched the zone to nine areas.
     */
    private function layout_mode( array $slots ) {
        if ( function_exists( 'agentshell_zone_is_expanded' ) ) {
            return agentshell_zone_is_expanded( array( 'slots' => $slots ) ) ? 'expanded' : 'standard';
        }
        foreach ( $slots as $slot_id => $blocks ) {
            $row = \AgentShell_MCP\Doctor::slot_row( $slot_id );
            if ( 'middle' !== $row && ! empty( $blocks ) ) {
                return 'expanded';
            }
        }
        return 'standard';
    }
}
