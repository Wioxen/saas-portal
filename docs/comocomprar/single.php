<?php
/**
 * Single Post Template
 *
 * Full-width layout without sidebar.
 * Content is centered with generous margins for readability.
 *
 * @package ComoComprar
 */

get_header();
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('cc-single'); ?>>
    <div class="cc-single__container">
        <!-- Breadcrumbs -->
        <?php cc_breadcrumbs(); ?>

        <!-- Article Header -->
        <header class="cc-article__header">
            <?php cc_category_badge(); ?>

            <h1 class="cc-article__title"><?php the_title(); ?></h1>

            <?php if (has_excerpt()) : ?>
                <p class="cc-article__subtitle"><?php echo esc_html(get_the_excerpt()); ?></p>
            <?php endif; ?>

            <div class="cc-article__meta">
                <div class="cc-article__author">
                    <?php echo get_avatar(get_the_author_meta('ID'), 32); ?>
                    <span><?php the_author(); ?></span>
                </div>

                <span>
                    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>">
                        <?php echo esc_html(get_the_date()); ?>
                    </time>
                </span>

                <span class="cc-article__reading-time">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd"/></svg>
                    <?php printf(esc_html__('%d min de leitura', 'comocomprar'), cc_reading_time()); ?>
                </span>

                <?php cc_updated_badge(); ?>
            </div>
        </header>

        <!-- Featured Image — LCP element, must NOT be lazy loaded -->
        <?php if (has_post_thumbnail()) :
            $thumb_id  = get_post_thumbnail_id();
            $caption   = wp_get_attachment_caption($thumb_id);
            $img_url   = wp_get_attachment_image_url($thumb_id, 'cc-hero');
            $img_srcset = wp_get_attachment_image_srcset($thumb_id, 'cc-hero');
            $img_alt   = get_post_meta($thumb_id, '_wp_attachment_image_alt', true) ?: get_the_title();
        ?>
            <figure class="cc-single__hero">
                <img
                    src="<?php echo esc_url($img_url); ?>"
                    <?php if ($img_srcset) : ?>srcset="<?php echo esc_attr($img_srcset); ?>"<?php endif; ?>
                    sizes="(max-width: 768px) 100vw, (max-width: 1024px) 80vw, 820px"
                    alt="<?php echo esc_attr($img_alt); ?>"
                    width="1200"
                    height="630"
                    fetchpriority="high"
                    decoding="async"
                >
                <?php if ($caption) : ?>
                    <figcaption class="cc-article__caption"><?php echo esc_html($caption); ?></figcaption>
                <?php endif; ?>
            </figure>
        <?php endif; ?>

        <!-- Content -->
        <div class="cc-content">
            <?php the_content(); ?>
        </div>

        <!-- Tags -->
        <?php
        $tags = get_the_tags();
        if ($tags) : ?>
            <div style="margin-top:1.5rem;display:flex;flex-wrap:wrap;gap:.375rem;">
                <?php foreach ($tags as $tag) : ?>
                    <a href="<?php echo esc_url(get_tag_link($tag)); ?>" class="cc-btn cc-btn--outline cc-btn--sm" style="font-size:.75rem;padding:.25rem .625rem;">
                        #<?php echo esc_html($tag->name); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Share Buttons -->
        <?php cc_share_buttons('article'); ?>

        <!-- Author Box -->
        <div class="cc-author-box">
            <div class="cc-author-box__avatar">
                <?php echo get_avatar(get_the_author_meta('ID'), 64); ?>
            </div>
            <div>
                <div class="cc-author-box__name"><?php the_author(); ?></div>
                <div class="cc-author-box__bio">
                    <?php echo esc_html(get_the_author_meta('description') ?: __('Redator do ComoComprar.com.br', 'comocomprar')); ?>
                </div>
            </div>
        </div>

        <!-- Related Posts (cards) -->
        <?php
        $related = cc_get_related_posts(get_the_ID(), 2);
        if ($related->have_posts()) : ?>
            <section class="cc-related">
                <div class="cc-section__header">
                    <h2 class="cc-section__title"><?php esc_html_e('Artigos Relacionados', 'comocomprar'); ?></h2>
                </div>
                <div class="cc-post-grid cc-post-grid--2">
                    <?php
                    while ($related->have_posts()) : $related->the_post();
                        get_template_part('template-parts/card', 'vertical');
                    endwhile;
                    wp_reset_postdata();
                    ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Comments -->
        <?php if (comments_open() || get_comments_number()) : ?>
            <div class="cc-comments">
                <?php comments_template(); ?>
            </div>
        <?php endif; ?>

        <!-- Post Navigation -->
        <nav class="cc-post-nav">
            <?php
            $prev = get_previous_post();
            $next = get_next_post();
            if ($prev) :
                printf(
                    '<a href="%s" class="cc-post-nav__link">&larr; %s</a>',
                    esc_url(get_permalink($prev)),
                    esc_html(wp_trim_words($prev->post_title, 8))
                );
            endif;
            if ($next) :
                printf(
                    '<a href="%s" class="cc-post-nav__link cc-post-nav__link--next">%s &rarr;</a>',
                    esc_url(get_permalink($next)),
                    esc_html(wp_trim_words($next->post_title, 8))
                );
            endif;
            ?>
        </nav>
    </div>
</article>

<?php
// ─── INLINE NEXT POST (continuous scroll) ────────────────
// Shows 1 full related post below so the user keeps scrolling.
// URL updates via History API, pageview fires for Analytics.
$inline_post = cc_get_related_posts(get_the_ID(), 1);
if ($inline_post->have_posts()) :
    $inline_post->the_post();
    $inline_url   = get_permalink();
    $inline_title = get_the_title() . ' - ' . get_bloginfo('name');
?>

<!-- Separator between posts -->
<div class="cc-next-post-separator">
    <span class="cc-next-post-separator__label"><?php esc_html_e('Continue lendo', 'comocomprar'); ?></span>
    <span class="cc-next-post-separator__line"></span>
</div>

<article id="post-<?php the_ID(); ?>" <?php post_class('cc-single cc-single--inline'); ?>
    data-url="<?php echo esc_url($inline_url); ?>"
    data-title="<?php echo esc_attr($inline_title); ?>">
    <div class="cc-single__container">

        <!-- Header -->
        <header class="cc-article__header">
            <?php cc_category_badge(); ?>
            <h2 class="cc-article__title" style="font-size:clamp(1.375rem,4.5vw,2rem);">
                <a href="<?php the_permalink(); ?>" style="color:inherit;"><?php the_title(); ?></a>
            </h2>

            <?php if (has_excerpt()) : ?>
                <p class="cc-article__subtitle"><?php echo esc_html(get_the_excerpt()); ?></p>
            <?php endif; ?>

            <div class="cc-article__meta">
                <div class="cc-article__author">
                    <?php echo get_avatar(get_the_author_meta('ID'), 32); ?>
                    <span><?php the_author(); ?></span>
                </div>
                <span>
                    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
                </span>
                <span class="cc-article__reading-time">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd"/></svg>
                    <?php printf(esc_html__('%d min de leitura', 'comocomprar'), cc_reading_time()); ?>
                </span>
                <?php cc_updated_badge(); ?>
            </div>
        </header>

        <!-- Featured image -->
        <?php if (has_post_thumbnail()) :
            $thumb_id = get_post_thumbnail_id();
            $caption  = wp_get_attachment_caption($thumb_id);
        ?>
            <figure class="cc-single__hero">
                <?php the_post_thumbnail('cc-hero', ['loading' => 'lazy', 'decoding' => 'async']); ?>
                <?php if ($caption) : ?>
                    <figcaption class="cc-article__caption"><?php echo esc_html($caption); ?></figcaption>
                <?php endif; ?>
            </figure>
        <?php endif; ?>

        <!-- Content -->
        <div class="cc-content">
            <?php the_content(); ?>
        </div>

        <!-- Share -->
        <?php cc_share_buttons('article'); ?>

        <!-- Author -->
        <div class="cc-author-box">
            <div class="cc-author-box__avatar">
                <?php echo get_avatar(get_the_author_meta('ID'), 64); ?>
            </div>
            <div>
                <div class="cc-author-box__name"><?php the_author(); ?></div>
                <div class="cc-author-box__bio">
                    <?php echo esc_html(get_the_author_meta('description') ?: __('Redator do ComoComprar.com.br', 'comocomprar')); ?>
                </div>
            </div>
        </div>

    </div>
</article>

<?php
    wp_reset_postdata();
endif;
?>

<?php get_footer(); ?>
