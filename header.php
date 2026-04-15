<?php
/**
 * Header template
 */
require_once get_template_directory() . '/template-parts/shell-render.php';
require_once get_template_directory() . '/template-parts/grid-areas.php';

$config = agentshell_get_config();

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
    <?php
    // CSS vars + layout CSS — rendered once here in <head>
    // (shell-render.php's agentshell_render_css_vars() is NOT called again in body)
    echo agentshell_render_css_vars( $config['design'] ?? array() );
    echo agentshell_get_layout_css( $config['layout'] ?? array(), $config['design']['breakpoints'] ?? array() );
    ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="shell-wrapper">
    <?php
    $nav     = $config['navigation']    ?? array();
    $mapping = $config['content_mapping'] ?? array();
    $zones   = $config['zones']   ?? array();
    ?>
    <div class="shell-grid">
    <?php
    // Render zones in zones whitelist order — determinism over layout.row-order
    foreach ( $zones as $zone_name ) {
        $zone_class = 'shell-zone zone--' . esc_attr( $zone_name );
        $zone_html  = agentshell_render_zone( $mapping[ $zone_name ] ?? array() );

        if ( $zone_name === 'header' && ! empty( $nav['primary'] ) ) {
            $zone_html = agentshell_render_nav( $nav['primary'] ) . $zone_html;
        }
        if ( $zone_name === 'footer' && ! empty( $nav['footer_links'] ) ) {
            $zone_html .= agentshell_render_nav( $nav['footer_links'] );
        }

        printf( '<%s class="%s">%s</%s>',
            $zone_name === 'header' ? 'header' : ( $zone_name === 'footer' ? 'footer' : 'div' ),
            esc_attr( $zone_class ),
            $zone_html,
            $zone_name === 'header' ? 'header' : ( $zone_name === 'footer' ? 'footer' : 'div' )
        );
    }
    ?>
    </div><!-- .shell-grid -->
</div><!-- .shell-wrapper -->
