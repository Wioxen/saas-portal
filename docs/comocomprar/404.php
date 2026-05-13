<?php
/**
 * 404 Template
 *
 * @package ComoComprar
 */

get_header();
?>

<section class="cc-section" style="text-align:center;padding:4rem 0;">
    <div class="cc-container">
        <h1 style="font-size:4rem;color:var(--cc-blue);margin-bottom:.5rem;">404</h1>
        <h2 style="margin-bottom:1rem;"><?php esc_html_e('Página não encontrada', 'comocomprar'); ?></h2>
        <p style="color:var(--cc-gray-500);margin-bottom:1.5rem;max-width:480px;margin-left:auto;margin-right:auto;">
            <?php esc_html_e('A página que você procura pode ter sido removida ou está temporariamente indisponível.', 'comocomprar'); ?>
        </p>

        <a href="<?php echo esc_url(home_url('/')); ?>" class="cc-btn cc-btn--primary">
            <?php esc_html_e('Voltar ao Início', 'comocomprar'); ?>
        </a>

        <div style="margin-top:2.5rem;">
            <h3 style="font-size:1.125rem;margin-bottom:1rem;"><?php esc_html_e('Artigos Populares', 'comocomprar'); ?></h3>
            <div class="cc-post-grid cc-post-grid--3" style="text-align:left;">
                <?php
                $popular = cc_get_popular_posts(3);
                if ($popular->have_posts()) :
                    while ($popular->have_posts()) : $popular->the_post();
                        get_template_part('template-parts/card', 'vertical');
                    endwhile;
                    wp_reset_postdata();
                endif;
                ?>
            </div>
        </div>
    </div>
</section>

<?php get_footer(); ?>
