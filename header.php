<?php
/**
 * Header template — Agent-Optimized HTML Shell
 *
 * Every zone has an explicit id so agents can target them by DOM id.
 * The grid layout is handled entirely by CSS (style.css) — no PHP grid generation.
 * Sidebar state is read directly from wp_options via get_option().
 */
$sidebar_enabled = ! empty( agentshell_get_config()['sidebar_enabled'] );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title( '|', true, 'right' ); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class( $sidebar_enabled ? 'sidebar-enabled' : '' ); ?>>
<?php wp_body_open(); ?>

<div id="agentshell-root">

    <header id="zone-header" class="shell-zone">
        <div class="shell-brand">
            <?php if ( function_exists( 'the_custom_logo' ) ) the_custom_logo(); ?>
            <h1 class="site-title"><?php bloginfo( 'name' ); ?></h1>
        </div>
        <nav id="zone-nav" class="shell-nav">
            <?php wp_nav_menu( array(
                'theme_location' => 'primary',
                'container'       => false,
                'fallback_cb'     => false,
            ) ); ?>
        </nav>
    </header>

    <main id="zone-main" class="shell-zone">
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
    <aside id="zone-sidebar" class="shell-zone">
        <?php dynamic_sidebar( 'primary-sidebar' ); ?>
    </aside>
    <?php endif; ?>

    <footer id="zone-footer" class="shell-zone">
        <p>&copy; <?php echo date('Y'); ?> <?php bloginfo( 'name' ); ?></p>
    </footer>

</div>
