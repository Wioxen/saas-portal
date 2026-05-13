<?php
/**
 * Archive Template
 * 
 * @package LeaoDaBarra
 */

get_header();
?>

<div class="ldb-container">
    <div class="ldb-archive-header" style="padding: 24px 0 0;">
        <?php if (is_category()) : ?>
            <h1 class="ldb-section-title"><?php single_cat_title(); ?></h1>
            <?php if (category_description()) : ?>
                <p class="ldb-archive-desc" style="color: var(--ldb-muted); margin-bottom: 16px;">
                    <?php echo category_description(); ?>
                </p>
            <?php endif; ?>
        <?php elseif (is_tag()) : ?>
            <h1 class="ldb-section-title">#<?php single_tag_title(); ?></h1>
        <?php elseif (is_tax()) : ?>
            <h1 class="ldb-section-title"><?php single_term_title(); ?></h1>
        <?php elseif (is_post_type_archive()) : ?>
            <h1 class="ldb-section-title"><?php post_type_archive_title(); ?></h1>
        <?php elseif (is_author()) : ?>
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                <?php echo get_avatar(get_the_author_meta('ID'), 56, '', '', ['style' => 'border-radius:50%;']); ?>
                <div>
                    <h1 class="ldb-section-title" style="margin-bottom:4px;"><?php the_author(); ?></h1>
                    <p style="color:var(--ldb-muted);font-size:14px;"><?php the_author_meta('description'); ?></p>
                </div>
            </div>
        <?php elseif (is_date()) : ?>
            <h1 class="ldb-section-title">
                <?php
                if (is_year()) echo get_the_date('Y');
                elseif (is_month()) echo get_the_date('F Y');
                elseif (is_day()) echo get_the_date('d F Y');
                ?>
            </h1>
        <?php endif; ?>
    </div>

    <div class="ldb-main-grid">
        <div class="ldb-content">
            <?php if (have_posts()) : ?>
                <div class="ldb-posts-grid">
                    <?php
                    $count = 0;
                    while (have_posts()) : the_post();
                        if ($count === 0) {
                            get_template_part('template-parts/card', 'featured');
                        } else {
                            get_template_part('template-parts/card', 'post');
                        }
                        $count++;
                    endwhile;
                    ?>
                </div>

                <?php ldb_pagination(); ?>
            <?php else : ?>
                <?php get_template_part('template-parts/content', 'none'); ?>
            <?php endif; ?>
        </div>

        <aside class="ldb-sidebar">
            <?php get_sidebar(); ?>
        </aside>
    </div>
</div>

<?php get_footer(); ?>
