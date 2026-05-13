<?php
/**
 * Search Results Template
 * 
 * @package LeaoDaBarra
 */

get_header();
?>

<div class="ldb-container">
    <div class="ldb-main-grid">
        <div class="ldb-content">
            <div class="ldb-archive-header">
                <h1 class="ldb-section-title">
                    Resultados para: "<?php echo esc_html(get_search_query()); ?>"
                </h1>
                <p style="color: var(--ldb-muted); margin-bottom: 20px;">
                    <?php
                    global $wp_query;
                    printf(
                        _n('%d resultado encontrado', '%d resultados encontrados', $wp_query->found_posts, 'leao-da-barra'),
                        $wp_query->found_posts
                    );
                    ?>
                </p>
            </div>

            <?php if (have_posts()) : ?>
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
