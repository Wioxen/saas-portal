<?php
/**
 * Search Results Template
 *
 * @package ComoComprar
 */

get_header();
?>

<section class="cc-section">
    <div class="cc-container">
        <?php cc_breadcrumbs(); ?>

        <header class="cc-section__header" style="margin-bottom:1.5rem;">
            <h1 class="cc-section__title">
                <?php printf(esc_html__('Resultados para: "%s"', 'comocomprar'), get_search_query()); ?>
            </h1>
        </header>

        <div class="cc-grid cc-grid--main">
            <div>
                <div class="cc-post-grid">
                    <?php
                    if (have_posts()) :
                        while (have_posts()) : the_post();
                            get_template_part('template-parts/card', 'horizontal');
                        endwhile;
                    else : ?>
                        <div style="text-align:center;padding:3rem 1rem;">
                            <h2 style="font-size:1.25rem;margin-bottom:.75rem;"><?php esc_html_e('Nenhum resultado encontrado', 'comocomprar'); ?></h2>
                            <p style="color:var(--cc-gray-500);"><?php esc_html_e('Tente buscar com termos diferentes.', 'comocomprar'); ?></p>
                            <div style="margin-top:1rem;">
                                <?php get_search_form(); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php get_template_part('template-parts/pagination'); ?>
            </div>

            <aside class="cc-sidebar" role="complementary">
                <?php get_template_part('template-parts/sidebar', 'content'); ?>
            </aside>
        </div>
    </div>
</section>

<?php get_footer(); ?>
