<?php
/**
 * Header template — Config-Driven Zone Registry
 *
 * The shell has fixed structural elements (header brand/nav, footer copyright).
 * The zone registry controls content zones (main, sidebar) and allows agents
 * to add/remove/reorder zones via REST API.
 *
 * The grid layout is handled entirely by CSS (style.css) — no PHP grid generation.
 */
$sidebar_enabled = ! empty( agentshell_get_config()['sidebar_enabled'] );
$zones = agentshell_get_zones();

// Index zones by id for easy lookup
$zone_by_id = array();
foreach ( $zones as $z ) {
    $zone_by_id[ $z['id'] ] = $z;
}

function agentshell_render_zone_by_id( $zone_id, &$zone_by_id ) {
    if ( ! isset( $zone_by_id[ $zone_id ] ) ) {
        return '';
    }
    return agentshell_render_zone( $zone_by_id[ $zone_id ] );
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
    <?php
    // Widget registry scoped CSS (outputs directly into <head>)
    get_template_part( 'template-parts/widgets' );
    ?>
</head>
<body <?php body_class( $sidebar_enabled ? 'sidebar-enabled' : '' ); ?>>
<?php wp_body_open(); ?>

<div id="agentshell-root">

    <header id="zone-header" class="shell-zone" data-zone="header">
        <?php
        // Render via zone registry — falls back to default brand/nav if zone has no custom content
        $header_zone = $zone_by_id['header'] ?? array();
        if ( ! empty( $header_zone['source'] ) && $header_zone['source'] !== 'wp_loop' ) {
            echo agentshell_render_zone( $header_zone );
        } else {
            // Default header content
            if ( function_exists( 'the_custom_logo' ) ) the_custom_logo(); ?>
            <h1 class="site-title"><?php bloginfo( 'name' ); ?></h1>
            <nav id="zone-nav" class="shell-nav">
                <?php wp_nav_menu( array(
                    'theme_location' => 'primary',
                    'container'       => false,
                    'fallback_cb'     => 'wp_page_menu',
                    'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>',
                ) ); ?>
            </nav><?php
        }
        ?>
    </header>

    <main id="zone-main" class="shell-zone" data-zone="main">
        <?php
        $main_zone = $zone_by_id['main'] ?? array();
        if ( ! empty( $main_zone['source'] ) && $main_zone['source'] !== 'wp_loop' ) {
            echo agentshell_render_zone( $main_zone );
        } elseif ( have_posts() ) {
            while ( have_posts() ) : the_post();
                the_content();
            endwhile;
        } else {
            echo '<p>' . esc_html__( 'No content found.', 'agentshell' ) . '</p>';
        }
        ?>
    </main>

    <?php if ( $sidebar_enabled ) : ?>
    <aside id="zone-sidebar" class="shell-zone" data-zone="sidebar">
        <?php
        $sidebar_zone = $zone_by_id['sidebar'] ?? array();
        if ( ! empty( $sidebar_zone['source'] ) && $sidebar_zone['source'] !== 'wp_widget_area' ) {
            echo agentshell_render_zone( $sidebar_zone );
        } else {
            dynamic_sidebar( $sidebar_zone['widget_area_id'] ?? 'primary-sidebar' );
        }
        ?>
    </aside>
    <?php endif; ?>

    <footer id="zone-footer" class="shell-zone" data-zone="footer">
        <?php
        $footer_zone = $zone_by_id['footer'] ?? array();
        if ( ! empty( $footer_zone['source'] ) && $footer_zone['source'] !== 'wp_loop' ) {
            echo agentshell_render_zone( $footer_zone );
        } else {
            echo '<p>&copy; ' . date('Y') . ' ' . get_bloginfo( 'name' ) . '</p>';
        }
        ?>
    </footer>

</div>
