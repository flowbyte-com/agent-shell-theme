<?php
/**
 * Shell renderer - reads config, renders CSS variables, navigation, and content zones
 */

/**
 * Render CSS custom properties (design tokens) as an inline style block
 *
 * @param array $design  e.g. [colors => [...], typography => [...]]
 * @return string <style> tag with :root variables
 */
function agentshell_render_css_vars( array $design ) {
    $colors     = $design['colors']     ?? array();
    $typography = $design['typography'] ?? array();

    $vars = ":root {\n";
    foreach ( $colors as $name => $value ) {
        $vars .= "  --color-{$name}: " . esc_attr( $value ) . ";\n";
    }
    if ( ! empty( $typography['baseSize'] ) ) {
        $vars .= "  --type-base: " . esc_attr( $typography['baseSize'] ) . ";\n";
    }
    if ( ! empty( $typography['scale'] ) ) {
        $vars .= "  --type-scale: " . esc_attr( $typography['scale'] ) . ";\n";
    }
    if ( ! empty( $typography['fontFamily'] ) ) {
        $vars .= "  --font-family: " . esc_attr( $typography['fontFamily'] ) . ";\n";
    }
    $vars .= "}\n";

    return "<style id='agentshell-design-css'>\n{$vars}</style>";
}

/**
 * Render a single navigation menu from the navigation config
 *
 * @param array  $items  Array of nav items [{label, url, children[]}]
 * @return string HTML <nav> with nested <ul>
 */
function agentshell_render_nav( array $items ) {
    $html = '<nav class="shell-nav"><ul>';
    foreach ( $items as $item ) {
        $label    = esc_html( $item['label'] );
        $url      = esc_url( $item['url'] );
        $has_kids = ! empty( $item['children'] ) && is_array( $item['children'] );

        $html .= '<li>';
        $html .= '<a href="' . $url . '">' . $label . '</a>';
        if ( $has_kids ) {
            $html .= '<ul class="sub-menu">';
            foreach ( $item['children'] as $child ) {
                $html .= '<li><a href="' . esc_url( $child['url'] ) . '">' . esc_html( $child['label'] ) . '</a></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Render a content zone based on its mapping config
 *
 * @param string $zone_name  e.g. "header", "sidebar", "main"
 * @param array  $mapping    e.g. [source => "wp_loop"], [source => "wp_widget_area", id => "..."]
 * @return string HTML content
 */
function agentshell_render_zone( $zone_name, array $mapping ) {
    $source = $mapping['source'] ?? '';

    switch ( $source ) {
        case 'wp_loop':
            ob_start();
            if ( have_posts() ) {
                while ( have_posts() ) {
                    the_post();
                    the_content();
                }
            } else {
                echo '<p>' . esc_html__( 'No content found.', 'agentshell' ) . '</p>';
            }
            return ob_get_clean();

        case 'wp_widget_area':
            $id = $mapping['id'] ?? '';
            if ( ! $id ) return '';
            ob_start();
            dynamic_sidebar( $id );
            return ob_get_clean();

        case 'json_block':
            $html = $mapping['html'] ?? '';
            return wp_kses_post( $html );

        default:
            return '';
    }
}

/**
 * Render the complete Shell (CSS vars + layout CSS + zone grid)
 *
 * @param array $config Full shell config array
 * @return string Complete HTML for the shell
 */
function agentshell_render_shell( array $config ) {
    $design   = $config['design']   ?? array();
    $layout   = $config['layout']   ?? array();
    $nav      = $config['navigation'] ?? array();
    $mapping  = $config['content_mapping'] ?? array();

    $output = agentshell_render_css_vars( $design );
    $output .= agentshell_get_layout_css( $layout, $design['breakpoints'] ?? array() );

    // Open shell grid container
    $output .= '<div class="shell-grid">' . "\n";

    // Collect all zone names from all breakpoints
    $all_zones = array();
    foreach ( $layout as $breakpoint_layout ) {
        foreach ( $breakpoint_layout as $row ) {
            $cells = preg_split( '/\s+/', trim( $row ) );
            foreach ( $cells as $cell ) {
                $all_zones[ $cell ] = true;
            }
        }
    }

    // Render each zone in the order they first appear (mobile-first)
    $rendered = array();
    foreach ( $layout as $breakpoint_layout ) {
        foreach ( $breakpoint_layout as $row ) {
            $cells = preg_split( '/\s+/', trim( $row ) );
            foreach ( $cells as $cell ) {
                if ( isset( $rendered[ $cell ] ) ) continue;
                $rendered[ $cell ] = true;

                $zone_class = 'shell-zone zone--' . esc_attr( $cell );
                $zone_html  = agentshell_render_zone( $cell, $mapping[ $cell ] ?? array() );

                // Inject nav for header zone
                if ( $cell === 'header' && ! empty( $nav['primary'] ) ) {
                    $zone_html = agentshell_render_nav( $nav['primary'] ) . $zone_html;
                }
                // Inject nav for footer zone
                if ( $cell === 'footer' && ! empty( $nav['footer_links'] ) ) {
                    $zone_html .= agentshell_render_nav( $nav['footer_links'] );
                }

                $output .= '<div class="' . $zone_class . '">' . $zone_html . '</div>' . "\n";
            }
        }
    }

    $output .= '</div>' . "\n";
    return $output;
}