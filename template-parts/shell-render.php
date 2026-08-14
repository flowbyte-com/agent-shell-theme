<?php
/**
 * Shell renderer — reads zone config, iterates over composition array, fires appropriate renderers.
 *
 * Each zone has a composition[] array. Each item in the array is a block with a "type":
 *   { "type": "wp_loop" }
 *   { "type": "widget", "id": "widget-id" }
 *   { "type": "json_block", "content": "<p>Hello</p>" }
 *   { "type": "wp_widget_area", "id": "primary-sidebar" }
 *
 * Theme fires do_action('agentshell_render_widget', $id) for widget types.
 * agentshell-blocks plugin intercepts via its hook.
 */

/**
 * Render a single block within a zone composition.
 *
 * @param array $block e.g. [ "type" => "wp_loop" ] or [ "type" => "widget", "id" => "..." ]
 * @return string HTML
 */
function agentshell_render_block( array $block ) {
    $type = $block['type'] ?? '';

    switch ( $type ) {
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
            $id = $block['id'] ?? '';
            if ( ! $id ) {
                return '';
            }
            ob_start();
            dynamic_sidebar( $id );
            return ob_get_clean();

        case 'json_block':
            $html = $block['content'] ?? '';
            // Strip <style> tags and style="" attributes — agents must use class-based CSS
            $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );
            $html = preg_replace( '/\s+style="[^"]*"/', '', $html );
            return wp_kses_post( $html );

        case 'widget':
            $id = $block['id'] ?? '';
            if ( ! $id ) {
                return '';
            }
            // Delegate to blocks plugin via action hook.
            // If plugin is inactive, this is a no-op — zone gracefully skips the widget.
            ob_start();
            do_action( 'agentshell_render_widget', $id );
            return ob_get_clean();

        case 'wp_core':
            $id = $block['id'] ?? '';
            if ( ! $id ) {
                return '';
            }
            return '<div class="wp-core-component wp-core-' . esc_attr( $id ) . '">'
                . agentshell_render_core_component( $id )
                . '</div>';

        default:
            return '';
    }
}

/**
 * Render a full zone by its config array.
 * Checks for 'slots' (tri-slot zones like header/footer) first,
 * then falls back to legacy 'composition' array.
 *
 * @param array $zone e.g. [ "id" => "main", "composition" => [...] ]
 * @return string HTML
 */
function agentshell_render_zone( array $zone ) {
    $slots = $zone['slots'] ?? null;

    // Tri-slot zones: header, footer with left/center/right slots
    if ( is_array( $slots ) ) {
        $slot_keys = array( 'left', 'center', 'right' );
        $html = '';
        foreach ( $slot_keys as $slot_key ) {
            $slot_items = $slots[ $slot_key ] ?? array();
            $slot_html  = '';
            foreach ( $slot_items as $block ) {
                $slot_html .= agentshell_render_block( $block );
            }
            $html .= sprintf(
                '<div class="zone-slot slot-%s">%s</div>',
                esc_attr( $slot_key ),
                $slot_html
            );
        }
        return $html;
    }

    // Legacy composition array (main zone, vertical stacking)
    $composition = $zone['composition'] ?? array();

    // Safety net: if composition was accidentally stored as a single flattened block
    // (e.g. ['type' => 'wp_loop'] instead of [['type' => 'wp_loop']]), wrap it.
    if ( isset( $composition['type'] ) ) {
        $composition = array( $composition );
    }

    // Invalid or empty composition — fall back to default wp_loop
    if ( empty( $composition ) || ! is_array( $composition ) ) {
        $composition = array( array( 'type' => 'wp_loop' ) );
    }

    $html = '';
    foreach ( $composition as $block ) {
        $html .= agentshell_render_block( $block );
    }
    return $html;
}

/**
 * Get a zone config from the saved config by zone ID.
 * Falls back to a default zone if not found.
 *
 * @param string $zone_id
 * @return array
 */
function agentshell_get_zone_config( $zone_id ) {
    $defaults = array(
        'header' => array(
            'id'    => 'header',
            'label' => 'Header',
            'slots' => array(
                'left'   => array( array( 'type' => 'wp_core', 'id' => 'site_logo' ) ),
                'center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
                'right'  => array( array( 'type' => 'wp_core', 'id' => 'search_form' ) ),
            ),
        ),
        'main'   => array(
            'id'          => 'main',
            'label'       => 'Main',
            'composition' => array( array( 'type' => 'wp_loop' ) ),
        ),
        'footer' => array(
            'id'    => 'footer',
            'label' => 'Footer',
            'slots' => array(
                'left'   => array( array( 'type' => 'wp_core', 'id' => 'site_title' ) ),
                'center' => array( array( 'type' => 'wp_core', 'id' => 'nav_menu' ) ),
                'right'  => array(),
            ),
        ),
    );

    $config  = agentshell_get_config();
    $saved   = $config['zones'] ?? array();

    // Find saved zone by id
    foreach ( $saved as $z ) {
        if ( ( $z['id'] ?? '' ) === $zone_id ) {
            return $z;
        }
    }

    return $defaults[ $zone_id ] ?? array();
}
