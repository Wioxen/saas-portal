<?php
/**
 * Card - Vertical (standard card)
 *
 * @package ComoComprar
 */
?>
<article class="cc-card cc-fade-in">
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
        <p class="cc-card__excerpt"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php cc_post_meta(['show_author' => false, 'show_category' => false, 'show_updated' => true]); ?>
        <?php cc_share_buttons('card'); ?>
    </div>
</article>
