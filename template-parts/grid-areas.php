<?php
/**
 * Parses layout arrays into CSS grid-template-areas strings
 *
 * @param array  $areas  e.g. ["header header", "main sidebar", "footer footer"]
 * @param string $prefix Optional class prefix
 * @return string CSS grid-template-areas value
 */
function agentshell_parse_grid_areas( array $areas, $prefix = 'zone--' ) {
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
 * @param array  $layout  e.g. ["header header", "main sidebar", "footer footer"]
 * @param string $query   Media query string, e.g. "(min-width: 1024px)"
 * @param string $zone_prefix  CSS class prefix for zones
 * @return string Complete CSS block
 */
function agentshell_generate_grid_css( array $layout, $query, $zone_prefix = 'zone--' ) {
    $css  = $query ? "@media $query {\n" : "";
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
    $css .= "  }\n";

    // Generate zone class rules
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
 * @param array $layout   e.g. { mobile: [...], tablet: [...], desktop: [...] }
 * @param array $breakpoints e.g. { mobile: "0px", tablet: "768px", desktop: "1024px" }
 * @return string Complete CSS string
 */
function agentshell_get_layout_css( array $layout, array $breakpoints ) {
    $css = "<style id='agentshell-layout-css'>\n";

    foreach ( $breakpoints as $name => $threshold ) {
        if ( isset( $layout[ $name ] ) && isset( $threshold ) ) {
            $query = $threshold === '0px' ? '' : "(min-width: {$threshold})";
            $css .= agentshell_generate_grid_css( $layout[ $name ], $query );
        }
    }

    // Base grid container
    $css .= "  .shell-grid {\n";
    $css .= "    display: grid;\n";
    $css .= "    gap: 1rem;\n";
    $css .= "  }\n";

    $css .= "</style>\n";
    return $css;
}