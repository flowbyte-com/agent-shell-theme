<?php
/**
 * Header template — Config-Driven FSE Layout
 *
 * Three zones: header, main, footer.
 * Zone content is driven entirely by the composition array in config.
 * Theme is purely structural; blocks plugin owns interactive components.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#zone-main"><?php esc_html_e( 'Skip to content', 'agentshell' ); ?></a>

<div id="agentshell-root">
    <?php
    // Resolve each structural zone once: the layout modifier and the render
    // are two questions about the same zone, and asking the configuration
    // twice is how they end up answering from different states.
    //
    // The nine-area modifier is present only when the zone actually has
    // content outside its middle row. An unchanged site emits the same
    // `class="shell-zone"` it has always emitted, so nothing about its
    // layout, spacing or mobile behaviour moves.
    $agentshell_header_zone  = agentshell_get_zone_config( 'header' );
    $agentshell_footer_zone  = agentshell_get_zone_config( 'footer' );
    $agentshell_header_class = trim( 'shell-zone ' . agentshell_zone_layout_class( $agentshell_header_zone ) );
    $agentshell_footer_class = trim( 'shell-zone ' . agentshell_zone_layout_class( $agentshell_footer_zone ) );
    ?>
    <header id="zone-header" class="<?php echo esc_attr( $agentshell_header_class ); ?>" data-zone="header">
        <?php echo agentshell_render_zone( $agentshell_header_zone ); ?>
    </header>

    <main id="zone-main" class="shell-zone" data-zone="main">
        <?php echo agentshell_render_zone( agentshell_get_zone_config( 'main' ) ); ?>
    </main>

    <footer id="zone-footer" class="<?php echo esc_attr( $agentshell_footer_class ); ?>" data-zone="footer">
        <?php echo agentshell_render_zone( $agentshell_footer_zone ); ?>
    </footer>
</div>
