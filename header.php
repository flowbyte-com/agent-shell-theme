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
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="agentshell-root">
    <header id="zone-header" class="shell-zone" data-zone="header">
        <?php echo agentshell_render_zone( agentshell_get_zone_config( 'header' ) ); ?>
    </header>

    <main id="zone-main" class="shell-zone" data-zone="main">
        <?php echo agentshell_render_zone( agentshell_get_zone_config( 'main' ) ); ?>
    </main>

    <footer id="zone-footer" class="shell-zone" data-zone="footer">
        <?php echo agentshell_render_zone( agentshell_get_zone_config( 'footer' ) ); ?>
    </footer>
</div>
