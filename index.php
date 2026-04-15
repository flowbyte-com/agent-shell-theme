<?php
/**
 * Fallback template - renders main zone with WP Loop
 */
get_header();
?>
<div class="shell-grid">
<main class="shell-zone zone--main">
    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <?php the_content(); ?>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer();
