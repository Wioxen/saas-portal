<?php
/**
 * Main Template (fallback for archives, categories, etc.)
 * 
 * @package LeaoDaBarra
 */

get_header();
?>

<div class="ldb-container">
    <div class="ldb-main-grid">
        <div class="ldb-content">
            <?php if (have_posts()) : ?>
                <div class="ldb-archive-header">
                    <?php if (is_category()) : ?>
                        <h1 class="ldb-section-title"><?php single_cat_title(); ?></h1>
                    <?php elseif (is_tag()) : ?>
                        <h1 class="ldb-section-title">#<?php single_tag_title(); ?></h1>
                    <?php elseif (is_tax()) : ?>
                        <h1 class="ldb-section-title"><?php single_term_title(); ?></h1>
                    <?php elseif (is_author()) : ?>
                        <h1 class="ldb-section-title"><?php the_author(); ?></h1>
                    <?php elseif (is_search()) : ?>
                        <h1 class="ldb-section-title">Resultados para: "<?php echo esc_html(get_search_query()); ?>"</h1>
                    <?php elseif (is_home()) : ?>
                        <h1 class="ldb-section-title">Todas as Notícias</h1>
                    <?php endif; ?>
                </div>

                <div class="ldb-posts-grid">
                    <?php while (have_posts()) : the_post(); ?>
                        <?php get_template_part('template-parts/card', 'post'); ?>
                    <?php endwhile; ?>
                </div>

                <?php ldb_pagination(); ?>
            <?php else : ?>
                <?php get_template_part('template-parts/content', 'none'); ?>
            <?php endif; ?>
        </div>

        <aside class="ldb-sidebar">
            <?php get_sidebar(); ?>
        </aside>
    </div>
</div>

<?php get_footer(); ?>
