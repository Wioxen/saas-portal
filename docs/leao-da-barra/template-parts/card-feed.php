<?php
/**
 * Template Part: Feed Card (G1 style)
 * 
 * Card horizontal para o feed com scroll infinito
 * Otimizado para mobile-first
 * 
 * @package LeaoDaBarra
 */

$cats = get_the_category();
$cat_name = $cats ? $cats[0]->name : '';
$cat_slug = $cats ? $cats[0]->slug : '';
$cat_class = $cats ? ldb_escopo_class($cats[0]->name) : '';
?>

<article class="g1-feed-card" data-category="<?php echo esc_attr($cat_slug); ?>">
    <a href="<?php the_permalink(); ?>" class="g1-feed-card-link">
        <?php if (has_post_thumbnail()) : ?>
            <div class="g1-feed-card-img">
                <img src="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'ldb-card-small')); ?>"
                     alt="<?php echo esc_attr(get_the_title()); ?>"
                     width="300" height="200"
                     loading="lazy" decoding="async">
            </div>
        <?php endif; ?>
        <div class="g1-feed-card-body">
            <?php if ($cat_name) : ?>
                <span class="g1-feed-card-cat <?php echo esc_attr($cat_class); ?>"><?php echo esc_html($cat_name); ?></span>
            <?php endif; ?>
            <h3 class="g1-feed-card-title"><?php the_title(); ?></h3>
            <p class="g1-feed-card-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 15); ?></p>
            <div class="g1-feed-card-meta">
                <span class="g1-feed-card-time"><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
                <span class="g1-feed-card-sep">·</span>
                <span class="g1-feed-card-author"><?php the_author(); ?></span>
            </div>
        </div>
    </a>
</article>
