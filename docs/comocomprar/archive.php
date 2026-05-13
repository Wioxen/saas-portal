<?php
/**
 * Archive Template (Category, Tag, Date, Author)
 *
 * @package ComoComprar
 */

get_header();
?>

<section class="cc-section">
    <div class="cc-container">
        <?php cc_breadcrumbs(); ?>

        <header class="cc-section__header" style="margin-bottom:1.5rem;">
            <div>
                <h1 class="cc-section__title">
                    <?php
                    if (is_category()) {
                        single_cat_title();
                    } elseif (is_tag()) {
                        printf(esc_html__('Tag: %s', 'comocomprar'), single_tag_title('', false));
                    } elseif (is_author()) {
                        printf(esc_html__('Autor: %s', 'comocomprar'), get_the_author());
                    } elseif (is_date()) {
                        esc_html_e('Arquivo', 'comocomprar');
                    } else {
                        the_archive_title();
                    }
                    ?>
                </h1>
                <?php if (is_category() && category_description()) : ?>
                    <p style="font-size:.9375rem;color:var(--cc-gray-500);margin-top:.5rem;"><?php echo category_description(); ?></p>
                <?php endif; ?>
            </div>
        </header>

        <div class="cc-grid cc-grid--main">
            <div>
                <div class="cc-post-grid">
                    <?php
                    if (have_posts()) :
                        while (have_posts()) : the_post();
                            get_template_part('template-parts/card', 'vertical');
                        endwhile;
                    else :
                        get_template_part('template-parts/content', 'none');
                    endif;
                    ?>
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
