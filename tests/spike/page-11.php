<?php
/**
 * Spike page template: page-{ORIGINAL_ID}.php
 *
 * Disposability: this file is part of the Stage 2c preview feasibility spike
 * and exists only while the spike is running. The agent-shell theme's
 * production code path is unchanged; this file is removed when the spike
 * ends.
 *
 * It exists to prove that the template loader selects the original's
 * `page-{ID}.php` (where ID is the original's id) during a preview request,
 * not the shadow's id. The template is loaded only when the main query
 * resolves the original, so a `page-<?php echo get_queried_object_id(); ?>.php`
 * selector will only be found when queried_object_id is the original's id.
 *
 * The single distinguishing marker this template emits (and only this
 * template emits) is `STAGE2C_SPIKE_TEMPLATE_11` — the test asserts the
 * response body contains that string, which is impossible unless the
 * template loader found this file.
 */
?>
<!-- STAGE2C_SPIKE_TEMPLATE_11 -->
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="entry-header">
        <h1 class="entry-title"><?php the_title(); ?></h1>
    </header>
    <div class="entry-content">
        <?php the_content(); ?>
    </div>
    <div class="entry-excerpt">
        <?php the_excerpt(); ?>
    </div>
    <footer class="entry-footer">
        <span class="queried-object-id"><?php echo (int) get_queried_object_id(); ?></span>
        <span class="post-id"><?php echo (int) get_the_ID(); ?></span>
        <span class="post-parent"><?php echo (int) get_post()->post_parent; ?></span>
        <span class="post-ancestors"><?php echo esc_html( implode( ',', get_ancestors( get_the_ID(), 'page' ) ) ); ?></span>
        <span class="post-template"><?php echo esc_html( get_post_meta( get_the_ID(), '_wp_page_template', true ) ); ?></span>
        <span class="direct-get-post-id"><?php echo (int) get_post( get_the_ID() )->ID; ?></span>
        <span class="post-name"><?php echo esc_html( $post->post_name ); ?></span>
        <span class="permalink"><?php the_permalink(); ?></span>
    </footer>
</article>
