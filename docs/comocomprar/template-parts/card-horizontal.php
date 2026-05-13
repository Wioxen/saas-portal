<?php
/**
 * Card - Horizontal (search results, sidebar)
 *
 * @package ComoComprar
 */
?>
<article class="cc-card cc-card--horizontal cc-fade-in">
    <a href="<?php the_permalink(); ?>" class="cc-card__thumb" aria-hidden="true" tabindex="-1">
        <?php if (has_post_thumbnail()) : ?>
            <?php the_post_thumbnail('cc-thumb', ['loading' => 'lazy', 'decoding' => 'async']); ?>
        <?php endif; ?>
    </a>
    <div class="cc-card__body">
        <h3 class="cc-card__title">
            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
        </h3>
        <?php cc_post_meta(['show_author' => false, 'show_time' => false, 'show_updated' => false]); ?>
    </div>
</article>
