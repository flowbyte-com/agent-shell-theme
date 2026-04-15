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
        // Sanitize key for CSS context (alphanumeric + hyphens only)
        $safe_name = sanitize_key( $name );
        // Sanitize value: strip anything not valid in a CSS declaration value
        $safe_value = preg_replace( '/[^a-zA-Z0-9#.,_%()-]/', '', $value );
        if ( $safe_name && $safe_value ) {
            $vars .= "  --color-{$safe_name}: {$safe_value};\n";
        }
    }
    if ( ! empty( $typography['baseSize'] ) ) {
        $safe_size = preg_replace( '/[^0-9pxemrem%]/', '', $typography['baseSize'] );
        if ( $safe_size ) {
            $vars .= "  --type-base: {$safe_size};\n";
        }
    }
    if ( ! empty( $typography['scale'] ) ) {
        $vars .= "  --type-scale: " . floatval( $typography['scale'] ) . ";\n";
    }
    if ( ! empty( $typography['fontFamily'] ) ) {
        // Strip braces and semicolons from font family
        $safe_font = preg_replace( '/[;{}]/', '', $typography['fontFamily'] );
        if ( $safe_font ) {
            $vars .= "  --font-family: {$safe_font};\n";
        }
    }
    $vars .= "}\n";

    return "<style id='agentshell-design-css'>\n{$vars}</style>\n";
}

/**
 * Render a single navigation menu from the navigation config.
 *
 * Nav items support two URL strategies:
 * 1. "post_id" (optional) — resolved via get_permalink() for slug-safe links.
 *    If the post exists and get_permalink succeeds, that URL is used.
 * 2. "url" — a hardcoded URL, used as fallback or when no post_id is given.
 *
 * @param array $items Array of nav items [{label, url, post_id, children[]}]
 * @return string HTML <nav> with nested <ul>
 */
function agentshell_render_nav( array $items ) {
    $html = '<nav class="shell-nav"><ul>';
    foreach ( $items as $item ) {
        $label    = esc_html( $item['label'] ?? '' );
        $url      = '#';

        if ( ! empty( $item['post_id'] ) ) {
            $permalink = get_permalink( (int) $item['post_id'] );
            if ( $permalink ) {
                $url = $permalink;
            }
        }
        if ( $url === '#' && ! empty( $item['url'] ) ) {
            $url = $item['url'];
        }

        $url = esc_url( $url );
        $has_kids = ! empty( $item['children'] ) && is_array( $item['children'] );

        $html .= '<li>';
        $html .= '<a href="' . $url . '">' . $label . '</a>';
        if ( $has_kids ) {
            $html .= '<ul class="sub-menu">';
            foreach ( $item['children'] as $child ) {
                $child_url = '#';
                if ( ! empty( $child['post_id'] ) ) {
                    $child_permalink = get_permalink( (int) $child['post_id'] );
                    if ( $child_permalink ) {
                        $child_url = $child_permalink;
                    }
                }
                if ( $child_url === '#' && ! empty( $child['url'] ) ) {
                    $child_url = $child['url'];
                }
                $html .= '<li><a href="' . esc_url( $child_url ) . '">' . esc_html( $child['label'] ?? '' ) . '</a></li>';
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
 * @param array $mapping e.g. [source => "wp_loop"], [source => "wp_widget_area", id => "..."]
 * @return string HTML content
 */
function agentshell_render_zone( array $mapping ) {
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
            /**
             * json_block HTML is sanitized with wp_kses_post().
             *
             * IMPORTANT: wp_kses_post() strips inline event handlers
             * (onclick, onerror, onload, etc.) and <script> tags.
             * Agent outputs must use class-based JavaScript (event
             * delegation via document-level listeners) or semantic HTML
             * instead of inline JS. Inline style attributes are also
             * stripped. Use CSS class-based styling instead.
             *
             * @see https://developer.wordpress.org/reference/functions/wp_kses_post/
             */
            $html = $mapping['html'] ?? '';
            return wp_kses_post( $html );

        default:
            return '';
    }
}

/**
 * Render the complete Shell (CSS vars + layout CSS + zone grid).
 *
 * Zone order is determined by the "zones" whitelist in config root.
 * This makes the zone list self-documenting and lets the JS configurator
 * iterate a strict list rather than parsing layout arrays.
 *
 * @param array $config Full shell config array
 * @return string Complete HTML for the shell
 */
function agentshell_render_shell( array $config ) {
    $design  = $config['design']   ?? array();
    $layout  = $config['layout']   ?? array();
    $nav     = $config['navigation'] ?? array();
    $mapping = $config['content_mapping'] ?? array();
    $zones   = $config['zones']   ?? array();

    $output = agentshell_render_css_vars( $design );
    $output .= agentshell_get_layout_css( $layout, $design['breakpoints'] ?? array() );

    // Open shell grid container
    $output .= '<div class="shell-grid">' . "\n";

    // Render zones in the order defined by the zones whitelist
    foreach ( $zones as $zone_name ) {
        $zone_class = 'shell-zone zone--' . esc_attr( $zone_name );
        $zone_html  = agentshell_render_zone( $mapping[ $zone_name ] ?? array() );

        // Inject nav for header zone
        if ( $zone_name === 'header' && ! empty( $nav['primary'] ) ) {
            $zone_html = agentshell_render_nav( $nav['primary'] ) . $zone_html;
        }
        // Inject nav for footer zone
        if ( $zone_name === 'footer' && ! empty( $nav['footer_links'] ) ) {
            $zone_html .= agentshell_render_nav( $nav['footer_links'] );
        }

        $output .= '<div class="' . $zone_class . '">' . $zone_html . '</div>' . "\n";
    }

    $output .= '</div>' . "\n";
    return $output;
}