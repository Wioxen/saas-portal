<?php
/**
 * Template Part: Featured Card (hero news card)
 * 
 * @package LeaoDaBarra
 */
?>
<article class="ldb-card ldb-card-featured" <?php post_class(); ?>>
    <a href="<?php the_permalink(); ?>" class="ldb-card-link">
        <div class="ldb-img-container ldb-ratio-16-9">
            <?php if (has_post_thumbnail()) : ?>
                <?php the_post_thumbnail('ldb-card-large', [
                    'loading'       => 'eager',
                    'fetchpriority' => 'high',
                ]); ?>
            <?php else : ?>
                <div class="ldb-card-placeholder">
                    <span>EC VITÓRIA</span>
                </div>
            <?php endif; ?>
        </div>
        <div class="ldb-card-body">
            <?php
            $cats = get_the_category();
            if ($cats) :
                $cat = $cats[0];
                ?>
                <span class="ldb-card-cat <?php echo esc_attr(ldb_escopo_class($cat->name)); ?>">
                    <?php echo esc_html($cat->name); ?>
                </span>
            <?php endif; ?>
            <h2 class="ldb-card-title ldb-card-title-lg"><?php the_title(); ?></h2>
            <p class="ldb-card-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 25); ?></p>
            <div class="ldb-card-meta">
                <span class="ldb-card-time"><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
                <span class="ldb-card-author"><?php the_author(); ?></span>
            </div>
        </div>
    </a>
</article>
