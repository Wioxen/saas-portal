<?php
/**
 * Main Template - Homepage / Blog
 *
 * Layout inspired by top news portals (G1, TecMundo, The Verge).
 * Uses a Bento Grid pattern: 1 hero + 2 side cards on top,
 * then alternating rows of mixed card sizes for visual variety.
 *
 * @package ComoComprar
 */

get_header();
?>

<?php if (is_home() && !is_paged()) : ?>

    <!-- ════════════════════════════════════════════════════
         HERO BENTO: 1 big + 4 small (5 posts)
         ════════════════════════════════════════════════════ -->
    <?php
    $hero_query = new WP_Query([
        'posts_per_page'      => 5,
        'ignore_sticky_posts' => 0,
        'no_found_rows'       => true,
    ]);

    if ($hero_query->have_posts()) : ?>
        <section class="cc-hero">
            <div class="cc-container">
                <div class="cc-bento">
                    <?php
                    $i = 0;
                    while ($hero_query->have_posts()) : $hero_query->the_post();
                        if ($i === 0) : ?>
                            <!-- Main hero card (spans 2 rows on desktop) -->
                            <article class="cc-bento__main cc-card cc-fade-in">
                                <a href="<?php the_permalink(); ?>" class="cc-card__thumb" aria-hidden="true" tabindex="-1">
                                    <?php if (has_post_thumbnail()) : ?>
                                        <?php the_post_thumbnail('cc-hero', ['loading' => 'eager', 'fetchpriority' => 'high']); ?>
                                    <?php endif; ?>
                                    <?php cc_category_badge(); ?>
                                </a>
                                <div class="cc-card__body">
                                    <h2 class="cc-card__title" style="font-size:clamp(1.25rem,3.5vw,1.625rem);">
                                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                    </h2>
                                    <p class="cc-card__excerpt"><?php echo esc_html(get_the_excerpt()); ?></p>
                                    <?php cc_post_meta(['show_author' => false, 'show_category' => false, 'show_updated' => true]); ?>
                                </div>
                            </article>
                        <?php else : ?>
                            <!-- Side cards (horizontal on desktop) -->
                            <article class="cc-bento__side cc-card cc-card--horizontal cc-fade-in">
                                <a href="<?php the_permalink(); ?>" class="cc-card__thumb" aria-hidden="true" tabindex="-1">
                                    <?php if (has_post_thumbnail()) : ?>
                                        <?php the_post_thumbnail('cc-thumb', ['loading' => 'eager']); ?>
                                    <?php endif; ?>
                                    <?php cc_category_badge(); ?>
                                </a>
                                <div class="cc-card__body">
                                    <h3 class="cc-card__title">
                                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                    </h3>
                                    <?php cc_post_meta(['show_author' => false, 'show_time' => false, 'show_category' => false, 'show_updated' => false]); ?>
                                </div>
                            </article>
                        <?php endif;
                        $i++;
                    endwhile;
                    ?>
                </div>
            </div>
        </section>
    <?php
    endif;
    wp_reset_postdata();
    ?>

    <!-- ════════════════════════════════════════════════════
         TRENDING: Horizontal scroll strip (6 posts)
         ════════════════════════════════════════════════════ -->
    <?php
    $trending = new WP_Query([
        'posts_per_page' => 6,
        'offset'         => 5,
        'no_found_rows'  => true,
    ]);

    if ($trending->have_posts()) : ?>
        <section class="cc-section cc-section--compact">
            <div class="cc-container">
                <div class="cc-section__header">
                    <h2 class="cc-section__title"><?php esc_html_e('Em Alta', 'comocomprar'); ?></h2>
                </div>
                <div class="cc-scroll-strip">
                    <?php while ($trending->have_posts()) : $trending->the_post(); ?>
                        <article class="cc-scroll-strip__item cc-card cc-fade-in">
                            <a href="<?php the_permalink(); ?>" class="cc-card__thumb" aria-hidden="true" tabindex="-1">
                                <?php if (has_post_thumbnail()) : ?>
                                    <?php the_post_thumbnail('cc-card', ['loading' => 'lazy', 'decoding' => 'async']); ?>
                                <?php endif; ?>
                                <?php cc_category_badge(); ?>
                            </a>
                            <div class="cc-card__body">
                                <h3 class="cc-card__title">
                                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h3>
                                <?php cc_post_meta(['show_author' => false, 'show_time' => false, 'show_category' => false, 'show_updated' => false]); ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
            </div>
        </section>
    <?php
    endif;
    wp_reset_postdata();
    ?>

    <!-- ════════════════════════════════════════════════════
         LATEST POSTS: Main feed + sidebar
         ════════════════════════════════════════════════════ -->
    <section class="cc-section">
        <div class="cc-container">
            <div class="cc-section__header">
                <h2 class="cc-section__title"><?php esc_html_e('Últimas Publicações', 'comocomprar'); ?></h2>
            </div>

            <div class="cc-grid cc-grid--main">
                <div>
                    <!-- First row: 2 large cards -->
                    <div class="cc-post-grid cc-post-grid--2">
                        <?php
                        $main_query = new WP_Query([
                            'posts_per_page' => 12,
                            'offset'         => 11,
                            'no_found_rows'  => false,
                        ]);

                        $count = 0;
                        if ($main_query->have_posts()) :
                            while ($main_query->have_posts()) : $main_query->the_post();
                                // After first 2 cards, close grid and open 3-col grid
                                if ($count === 2) : ?>
                                    </div>
                                    <!-- Remaining: 3-col grid -->
                                    <div class="cc-post-grid cc-post-grid--3" style="margin-top:var(--cc-gap);">
                                <?php endif;

                                get_template_part('template-parts/card', 'vertical');
                                $count++;
                            endwhile;
                        endif;
                        wp_reset_postdata();
                        ?>
                    </div>

                    <!-- Load More -->
                    <div class="cc-load-more">
                        <button class="cc-btn cc-btn--outline" id="cc-load-more" data-page="2" data-max="<?php echo esc_attr($main_query->max_num_pages + 1); ?>">
                            <?php esc_html_e('Carregar mais artigos', 'comocomprar'); ?>
                        </button>
                    </div>
                </div>

                <!-- Sidebar -->
                <aside class="cc-sidebar" role="complementary">
                    <?php get_template_part('template-parts/sidebar', 'content'); ?>
                </aside>
            </div>
        </div>
    </section>

<?php else : ?>
    <!-- ════════════════════════════════════════════════════
         ARCHIVE / PAGED BLOG
         ════════════════════════════════════════════════════ -->
    <section class="cc-section">
        <div class="cc-container">
            <?php if (is_paged()) : ?>
                <div class="cc-section__header">
                    <h1 class="cc-section__title"><?php printf(esc_html__('Página %d', 'comocomprar'), get_query_var('paged')); ?></h1>
                </div>
            <?php endif; ?>

            <div class="cc-grid cc-grid--main">
                <div>
                    <div class="cc-post-grid cc-post-grid--2">
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
<?php endif; ?>

<?php get_footer(); ?>
