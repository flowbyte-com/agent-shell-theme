<?php
/**
 * Generate CSS grid layout from layout config.
 *
 * Supports two config formats for $layout areas:
 *
 * Format A — Row strings (legacy):
 *   [ "header header", "main sidebar", "footer footer" ]
 *   → CSS: grid-template-areas: "header header" "main sidebar" "footer footer";
 *
 * Format B — Flat array (new, agents-friendly):
 *   [ "header", "header", "main", "sidebar", "footer", "footer" ]
 *   → chunked into rows of $cols → same CSS as Format A.
 *
 * The $layout array is indexed by breakpoint name (mobile, tablet, desktop).
 * Each value is either a flat array or already row strings.
 *
 * @param array  $zones       Zone names, e.g. ["header", "main", "sidebar", "footer"]
 * @param array  $layout      Per-breakpoint areas, e.g. { mobile: [...], desktop: [...] }
 * @param array  $breakpoints e.g. { mobile: "0px", tablet: "768px", desktop: "1024px" }
 * @param string $gap         Grid gap, e.g. "1rem"
 * @param string $padding     Container padding, e.g. "2rem"
 * @return string Complete <style> block
 */
function agentshell_get_layout_css( array $zones, array $layout, array $breakpoints, $gap = '1rem', $padding = '2rem' ) {
    $css = "<style id='agentshell-layout-css'>\n";

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
    $css .= "  #agentshell-root.sidebar-enabled {\n";
    $css .= "    grid-template-columns: 1fr 320px;\n";
    $css .= "  }\n";

    // Breakpoint-specific rules
    foreach ( $breakpoints as $name => $threshold ) {
        if ( ! isset( $layout[ $name ] ) ) {
            continue;
        }
        $raw_areas = $layout[ $name ];

        // Normalize each breakpoint's areas to Format A (row strings)
        if ( is_string( $raw_areas ) ) {
            // Plain newline-separated string
            $rows = array_filter( array_map( 'trim', explode( "\n", $raw_areas ) ) );
        } elseif ( is_array( $raw_areas ) && isset( $raw_areas[0] ) && is_string( $raw_areas[0] ) && strpos( $raw_areas[0], ' ' ) !== false ) {
            // Format A already: ["header header", "main sidebar", ...]
            $rows = array_values( array_filter( array_map( 'trim', $raw_areas ), fn( $v ) => $v !== '' ) );
        } elseif ( is_array( $raw_areas ) ) {
            // Format B: flat array — detect column count then chunk
            $count = count( $raw_areas );
            // Infer columns: if we have 3 items → 1 col (header/main/footer rows)
            //                if we have 6 items → 2 cols (header×2, main+sidebar, footer×2)
            $cols  = $count <= 3 ? 1 : 2;
            $chunked = array_chunk( array_map( 'trim', $raw_areas ), $cols );
            $rows    = array_map(
                fn( $row ) => implode( ' ', array_map( 'esc_attr', $row ) ),
                $chunked
            );
        } else {
            continue;
        }

        // Determine column count from the first row's cell count
        $first_cells = array_filter( preg_split( '/ +/', $rows[0] ?? '' ) );
        $cols = count( $first_cells );

        // Build the areas CSS string: each row quoted, separated by spaces
        $areas_css = implode( ' ', array_map(
            fn( $row ) => '"' . $row . '"',
            $rows
        ) );

        $query = $threshold === '0px' ? '' : "(min-width: {$threshold})";

        $css .= $query ? "@media {$query} {\n" : '';
        $css .= "  #agentshell-root {\n";
        $css .= "    grid-template-areas: {$areas_css};\n";
        $css .= "    grid-template-rows: auto;\n";
        if ( $cols > 1 ) {
            $css .= "    grid-template-columns: repeat( {$cols}, 1fr );\n";
        }
        $css .= "  }\n";
        if ( $query ) {
            $css .= "}\n";
        }
    }

    $css .= "</style>\n";
    return $css;
}
