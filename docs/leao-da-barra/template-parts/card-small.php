<?php
/**
 * Template Part: Small Card (related, sidebar)
 * 
 * @package LeaoDaBarra
 */
?>
<article class="ldb-card ldb-card-small" <?php post_class(); ?>>
    <a href="<?php the_permalink(); ?>" class="ldb-card-link">
        <div class="ldb-card-thumb-sm">
            <?php if (has_post_thumbnail()) : ?>
                <?php the_post_thumbnail('ldb-card-small', ['loading' => 'lazy']); ?>
            <?php endif; ?>
        </div>
        <h4 class="ldb-card-title ldb-card-title-sm"><?php the_title(); ?></h4>
        <span class="ldb-card-time"><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
    </a>
</article>
