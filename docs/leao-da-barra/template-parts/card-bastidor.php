<?php
/**
 * Template Part: Bastidor Card
 * 
 * @package LeaoDaBarra
 */
?>
<article class="ldb-card ldb-card-bastidor" <?php post_class(); ?>>
    <a href="<?php the_permalink(); ?>" class="ldb-card-link">
        <div class="ldb-img-container ldb-ratio-4-3">
            <?php if (has_post_thumbnail()) : ?>
                <?php the_post_thumbnail('ldb-card-medium', ['loading' => 'lazy']); ?>
            <?php else : ?>
                <div class="ldb-card-placeholder">
                    <span>Bastidores</span>
                </div>
            <?php endif; ?>
            <div class="ldb-card-overlay">
                <span class="ldb-card-cat cat-vitoria">Bastidores</span>
            </div>
        </div>
        <div class="ldb-card-body">
            <h3 class="ldb-card-title"><?php the_title(); ?></h3>
            <p class="ldb-card-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 15); ?></p>
            <div class="ldb-card-meta">
                <span class="ldb-card-time"><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
            </div>
        </div>
    </a>
</article>
