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
        $safe_name  = preg_replace( '/[^a-z0-9_]/', '', strtolower( $name ) );
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
 * 1. "post_id" — resolved via get_permalink() for slug-safe links.
 * 2. "url" — hardcoded fallback.
 *
 * @param array $items Array of nav items [{label, url, post_id, children[]}]
 * @return string HTML <nav> with nested <ul>
 */
function agentshell_render_nav( array $items ) {
    $html = '<nav class="shell-nav"><ul>';
    foreach ( $items as $item ) {
        $label = esc_html( $item['label'] ?? '' );
        $url   = '#';

        if ( ! empty( $item['post_id'] ) ) {
            $permalink = get_permalink( (int) $item['post_id'] );
            if ( $permalink ) {
                $url = $permalink;
            }
        }
        if ( $url === '#' && ! empty( $item['url'] ) ) {
            $url = $item['url'];
        }

        $url      = esc_url( $url );
        $has_kids = ! empty( $item['children'] ) && is_array( $item['children'] );

        $html .= '<li><a href="' . $url . '">' . $label . '</a>';
        if ( $has_kids ) {
            $html .= '<ul class="sub-menu">';
            foreach ( $item['children'] as $child ) {
                $child_url = '#';
                if ( ! empty( $child['post_id'] ) ) {
                    $cp = get_permalink( (int) $child['post_id'] );
                    if ( $cp ) {
                        $child_url = $cp;
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
            if ( ! $id ) {
                return '';
            }
            ob_start();
            dynamic_sidebar( $id );
            return ob_get_clean();

        case 'json_block':
            /**
             * json_block HTML sanitized with wp_kses_post().
             *
             * IMPORTANT: wp_kses_post() strips inline event handlers
             * (onclick, onerror, onload), <script> tags, and
             * inline style attributes. Agents must use class-based
             * CSS and event delegation instead of inline JS.
             *
             * @see https://developer.wordpress.org/reference/functions/wp_kses_post/
             */
            return wp_kses_post( $mapping['html'] ?? '' );

        default:
            return '';
    }
}
