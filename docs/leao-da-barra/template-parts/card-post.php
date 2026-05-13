<?php
/**
 * Template Part: Post Card
 * 
 * @package LeaoDaBarra
 */
?>
<article class="ldb-card ldb-card-horizontal" <?php post_class(); ?>>
    <a href="<?php the_permalink(); ?>" class="ldb-card-link">
        <div class="ldb-card-thumb">
            <?php if (has_post_thumbnail()) : ?>
                <?php the_post_thumbnail('ldb-card-small', ['loading' => 'lazy']); ?>
            <?php else : ?>
                <div class="ldb-card-placeholder-sm"></div>
            <?php endif; ?>
        </div>
        <div class="ldb-card-body">
            <?php
            $cats = get_the_category();
            if ($cats) :
                $cat = $cats[0];
                ?>
                <span class="ldb-card-cat ldb-card-cat-sm <?php echo esc_attr(ldb_escopo_class($cat->name)); ?>">
                    <?php echo esc_html($cat->name); ?>
                </span>
            <?php endif; ?>
            <h3 class="ldb-card-title"><?php the_title(); ?></h3>
            <div class="ldb-card-meta">
                <span class="ldb-card-time"><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
            </div>
        </div>
    </a>
</article>
