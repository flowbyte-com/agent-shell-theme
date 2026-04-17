<?php
/**
 * Parses layout arrays into CSS grid-template-areas strings
 *
 * @param array $areas e.g. ["header header", "main sidebar", "footer footer"]
 * @return string CSS grid-template-areas value
 */
function agentshell_parse_grid_areas( array $areas ) {
    $css = '';
    foreach ( $areas as $row ) {
        $cells = preg_split( '/\s+/', trim( $row ) );
        foreach ( $cells as $cell ) {
            $css .= '"' . esc_attr( $cell ) . '" ';
        }
        $css .= "\n";
    }
    return trim( $css );
}

/**
 * Generates a full CSS block for a breakpoint
 *
 * @param array  $layout      e.g. ["header header", "main sidebar", "footer footer"]
 * @param string $query        Media query string, e.g. "(min-width: 1024px)"
 * @param string $zone_prefix  CSS class prefix for zones
 * @return string Complete CSS block
 */
function agentshell_generate_grid_css( array $layout, $query, $zone_prefix = 'zone--' ) {
    // Determine column count from the first non-empty row.
    // All rows are expected to have the same number of cells.
    $cols = 1;
    foreach ( $layout as $row ) {
        $cells = array_filter( preg_split( '/\s+/', trim( $row ) ) );
        if ( count( $cells ) > 1 ) {
            $cols = count( $cells );
            break;
        }
    }

    $opening = $query ? "@media $query {\n" : "";

    $css  = $opening;
    $css .= "  .shell-grid {\n";
    $css .= "    grid-template-areas:\n";
    foreach ( $layout as $row ) {
        $cells = preg_split( '/\s+/', trim( $row ) );
        foreach ( $cells as $cell ) {
            $css .= '      "' . esc_attr( $cell ) . '" ';
        }
        $css .= "\n";
    }
    $css .= "    grid-template-rows: auto;\n";
    if ( $cols > 1 ) {
        $css .= "    grid-template-columns: repeat( {$cols}, 1fr );\n";
    }
    $css .= "  }\n";

    // Zone class rules — only for zones that appear in this breakpoint
    $seen_zones = array();
    foreach ( $layout as $row ) {
        $cells = preg_split( '/\s+/', trim( $row ) );
        foreach ( $cells as $cell ) {
            if ( ! isset( $seen_zones[ $cell ] ) ) {
                $seen_zones[ $cell ] = true;
                $css .= "  .{$zone_prefix}{$cell} { grid-area: {$cell}; }\n";
            }
        }
    }

    if ( $query ) {
        $css .= "}\n";
    }
    return $css;
}

/**
 * Generate all breakpoint CSS from layout config
 *
 * @param array  $zones       All zone names, e.g. ["header", "main", "sidebar", "footer"]
 * @param array  $layout      Per-breakpoint row arrays, e.g. { mobile: [...], desktop: [...] }
 * @param array  $breakpoints e.g. { mobile: "0px", tablet: "768px", desktop: "1024px" }
 * @param string $gap          Grid gap, e.g. "1rem"
 * @param string $padding      Container padding, e.g. "2rem"
 * @return string Complete CSS string
 */
function agentshell_get_layout_css( array $zones, array $layout, array $breakpoints, $gap = '1rem', $padding = '2rem' ) {
    $css = "<style id='agentshell-layout-css'>\n";

    // Normalize: each breakpoint value must be an array of row strings.
    // If a string is stored (e.g. from manual REST edits), split by newline.
    foreach ( $layout as $name => $value ) {
        if ( is_string( $value ) ) {
            $layout[ $name ] = array_filter(
                array_map( 'trim', explode( "\n", $value ) )
            );
        }
    }

    // Base rules — outside any media query, applies to all breakpoints
    $css .= "  .shell-wrapper {\n";
    $css .= "    display: grid;\n";
    $css .= "    place-content: center;\n";
    $css .= "    padding: 0 " . esc_attr( $padding ) . ";\n";
    $css .= "  }\n";
    $css .= "  .shell-grid {\n";
    $css .= "    display: grid;\n";
    $css .= "    gap: " . esc_attr( $gap ) . ";\n";
    $css .= "    max-width: 1200px;\n";
    $css .= "    margin: 0 auto;\n";
    $css .= "    width: 100%;\n";
    $css .= "  }\n";

    // Emit a grid-area rule for EVERY zone, so any zone that appears in
    // HTML (header.php renders all zones from the `zones` whitelist) is
    // always accounted for even if absent from a particular breakpoint layout.
    foreach ( $zones as $zone ) {
        $css .= "  .zone--{$zone} { grid-area: {$zone}; }\n";
    }

    // Breakpoint-specific rules
    foreach ( $breakpoints as $name => $threshold ) {
        if ( ! isset( $layout[ $name ] ) || ! isset( $threshold ) ) {
            continue;
        }
        $layout_value = is_array( $layout[ $name ] ) ? $layout[ $name ] : array();
        $query = $threshold === '0px' ? '' : "(min-width: {$threshold})";
        $css .= agentshell_generate_grid_css( $layout_value, $query );
    }

    $css .= "</style>\n";
    return $css;
}
