<?php
/**
 * Page Template
 * 
 * @package LeaoDaBarra
 */

get_header();
?>

<div class="ldb-container">
    <div class="ldb-page-content">
        <?php while (have_posts()) : the_post(); ?>
            <article id="page-<?php the_ID(); ?>" <?php post_class('ldb-page'); ?>>
                <h1 class="ldb-page-title"><?php the_title(); ?></h1>
                
                <?php if (has_post_thumbnail()) : ?>
                    <div class="ldb-img-container ldb-ratio-hero">
                        <?php the_post_thumbnail('ldb-hero', ['loading' => 'eager', 'fetchpriority' => 'high']); ?>
                    </div>
                <?php endif; ?>

                <div class="ldb-page-body">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
</div>

<?php get_footer(); ?>
