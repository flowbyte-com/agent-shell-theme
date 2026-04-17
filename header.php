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
        <div class="shell-brand">
            <?php if ( function_exists( 'the_custom_logo' ) ) the_custom_logo(); ?>
            <h1 class="site-title"><?php bloginfo( 'name' ); ?></h1>
        </div>
        <nav id="zone-nav" class="shell-nav">
            <?php wp_nav_menu( array(
                'theme_location' => 'primary',
                'container'       => false,
                'fallback_cb'     => 'wp_page_menu',
                'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>',
            ) ); ?>
        </nav>
    </header>

    <main id="zone-main" class="shell-zone" data-zone="main">
        <?php
        if ( have_posts() ) :
            while ( have_posts() ) : the_post();
                the_content();
            endwhile;
        else :
            echo '<p>' . esc_html__( 'No content found.', 'agentshell' ) . '</p>';
        endif;
        ?>
    </main>

    <?php if ( $sidebar_enabled ) : ?>
    <aside id="zone-sidebar" class="shell-zone" data-zone="sidebar">
        <?php dynamic_sidebar( 'primary-sidebar' ); ?>
    </aside>
    <?php endif; ?>

    <footer id="zone-footer" class="shell-zone" data-zone="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php bloginfo( 'name' ); ?></p>
    </footer>

</div>
