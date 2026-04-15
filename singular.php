<?php
/**
 * Singular template - single post or page
 */
get_header();
?>
<div class="shell-grid">
<main class="shell-zone zone--main">
    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <header>
                <?php if ( is_single() ) : ?>
                    <h1><?php the_title(); ?></h1>
                <?php else : ?>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <?php endif; ?>
            </header>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer();
