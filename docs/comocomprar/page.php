<?php
/**
 * Page Template
 *
 * Uses same centered 70% layout as single posts.
 *
 * @package ComoComprar
 */

get_header();
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('cc-single'); ?>>
    <div class="cc-single__container">
        <?php cc_breadcrumbs(); ?>

        <header class="cc-article__header">
            <h1 class="cc-article__title"><?php the_title(); ?></h1>
        </header>

        <div class="cc-content">
            <?php the_content(); ?>
        </div>

        <?php
        wp_link_pages([
            'before' => '<nav class="cc-pagination">',
            'after'  => '</nav>',
        ]);
        ?>

        <?php if (comments_open() || get_comments_number()) : ?>
            <div class="cc-comments">
                <?php comments_template(); ?>
            </div>
        <?php endif; ?>
    </div>
</article>

<?php get_footer(); ?>
