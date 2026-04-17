<?php
/**
 * Parses layout arrays into CSS grid-template-areas strings
 *
 * @param array $areas e.g. ["header header", "main sidebar", "footer footer"]
 * @return string grid-template-areas CSS value
 */
function agentshell_parse_grid_areas( array $areas ) {
    // Each row becomes one line in grid-template-areas, with cells separated by spaces.
    // e.g. ["header header", "main sidebar", "footer footer"]
    //   → "header header\nmain sidebar\nfooter footer"
    return implode( "\n", array_map( 'trim', $areas ) );
}

/**
 * Generates a full CSS block for a breakpoint
 *
 * @param array  $areas      Row strings, e.g. ["header header", "main sidebar", "footer footer"]
 * @param string $query      Media query string, e.g. "(min-width: 1024px)"
 * @param int    $cols        Column count
 * @return string Complete CSS block
 */
function agentshell_generate_grid_css( array $areas, $query, $cols = 1 ) {
    $opening = $query ? "@media $query {\n" : '';

    $css  = $opening;
    $css .= "  #agentshell-root {\n";
    // Single string for grid-template-areas, no per-row semicolons
    $css .= '    grid-template-areas: "' . agentshell_parse_grid_areas( $areas ) . '";' . "\n";
    $css .= "    grid-template-rows: auto;\n";
    if ( $cols > 1 ) {
        $css .= "    grid-template-columns: repeat( {$cols}, 1fr );\n";
    }
    $css .= "  }\n";

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
 * @param string $gap         Grid gap, e.g. "1rem"
 * @param string $padding     Container padding, e.g. "2rem"
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

    // Base rules — outside any media query
    $css .= "  #agentshell-root {\n";
    $css .= "    display: grid;\n";
    $css .= "    gap: " . esc_attr( $gap ) . ";\n";
    $css .= "    max-width: 1200px;\n";
    $css .= "    margin: 0 auto;\n";
    $css .= "    width: 100%;\n";
    $css .= "  }\n";

    // Zone grid-area rules — always emit so every zone is accounted for
    foreach ( $zones as $zone ) {
        $css .= "  #zone-{$zone} { grid-area: {$zone}; }\n";
    }

    // Conditional sidebar column — body.sidebar-enabled drives the 2-column layout
    $css .= "  .sidebar-enabled #agentshell-root {\n";
    $css .= "    grid-template-columns: 1fr 320px;\n";
    $css .= "  }\n";

    // Breakpoint-specific rules
    foreach ( $breakpoints as $name => $threshold ) {
        if ( ! isset( $layout[ $name ] ) || ! isset( $threshold ) ) {
            continue;
        }
        $layout_value = is_array( $layout[ $name ] ) ? $layout[ $name ] : array();
        $query = $threshold === '0px' ? '' : "(min-width: {$threshold})";

        // Determine column count from this layout's row strings
        $cols = 1;
        foreach ( $layout_value as $row ) {
            $cells = array_filter( preg_split( '/ +/', trim( $row ) ) );
            if ( count( $cells ) > 1 ) {
                $cols = count( $cells );
                break;
            }
        }

        $css .= agentshell_generate_grid_css( $layout_value, $query, $cols );
    }

    $css .= "</style>\n";
    return $css;
}
