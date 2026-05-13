<?php
/**
 * 404 Template
 * 
 * @package LeaoDaBarra
 */

get_header();
?>

<div class="ldb-container">
    <section class="ldb-404">
        <div class="ldb-404-inner">
            <span class="ldb-404-code">404</span>
            <h1 class="ldb-404-title">Página não encontrada</h1>
            <p class="ldb-404-text">A página que você está procurando não existe ou foi movida.</p>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ldb-btn ldb-btn-primary">Voltar ao Início</a>
        </div>

        <div class="ldb-404-recent">
            <h2 class="ldb-section-title">Últimas Notícias</h2>
            <div class="ldb-posts-grid">
                <?php
                $recent = new WP_Query(['posts_per_page' => 4]);
                if ($recent->have_posts()) :
                    while ($recent->have_posts()) : $recent->the_post();
                        get_template_part('template-parts/card', 'post');
                    endwhile;
                    wp_reset_postdata();
                endif;
                ?>
            </div>
        </div>
    </section>
</div>

<?php get_footer(); ?>
