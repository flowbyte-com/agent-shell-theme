<?php
/**
 * Render the header and footer zones in both layouts, for the mobile
 * visibility test.
 *
 * The mobile rules in style.css work on two different markup shapes: the
 * historical three-slot zone (`slot-center`) and the nine-area grid
 * (`data-slot="middle-center"`). The rules have to cover both, and they
 * have to cover the header and the footer differently — the header's
 * centre collapses on a narrow viewport so the hamburger has something
 * to reveal, and the footer's centre is content that must survive.
 *
 * This script prints the real markup, produced by the theme's own
 * renderer, so the browser test measures the real thing rather than a
 * hand-written copy of it.
 *
 * Usage: php tests/render-mobile-zones.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../template-parts/shell-render.php';

/**
 * A block the renderer can render without any WordPress.
 */
function mobile_zone_block( $label ) {
    return array( 'type' => 'json_block', 'content' => '<span class="probe">' . $label . '</span>' );
}

/**
 * The historical three-slot zone: the middle row only.
 */
function mobile_zone_legacy( $id ) {
    return array(
        'id'    => $id,
        'label' => ucfirst( $id ),
        'slots' => array(
            'left'   => array( mobile_zone_block( 'L' ) ),
            'center' => array( mobile_zone_block( 'C' ) ),
            'right'  => array( mobile_zone_block( 'R' ) ),
        ),
    );
}

/**
 * The nine-area grid: all nine cells, so the zone is expanded.
 */
function mobile_zone_expanded( $id ) {
    $slots = array();
    foreach ( array(
        'top-left', 'top-center', 'top-right',
        'middle-left', 'middle-center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ) as $slot ) {
        $slots[ $slot ] = array( mobile_zone_block( $slot ) );
    }
    return array(
        'id'    => $id,
        'label' => ucfirst( $id ),
        'slots' => $slots,
    );
}

echo json_encode( array(
    'legacy' => array(
        'header' => agentshell_render_zone( mobile_zone_legacy( 'header' ) ),
        'footer' => agentshell_render_zone( mobile_zone_legacy( 'footer' ) ),
    ),
    'expanded' => array(
        'header' => agentshell_render_zone( mobile_zone_expanded( 'header' ) ),
        'footer' => agentshell_render_zone( mobile_zone_expanded( 'footer' ) ),
    ),
) );
