<?php
/**
 * Single Post Template
 * 
 * Centered layout, no sidebar
 * Related: title → excerpt → full-width image → time · category
 * Desktop: 3 columns | Mobile: stacked full-width
 * 
 * @package LeaoDaBarra
 */

get_header();
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('g1-article'); ?> itemscope itemtype="https://schema.org/NewsArticle">

    <div class="g1-container">
        <div class="g1-article-header">
            <!-- Breadcrumb -->
            <nav class="g1-breadcrumb" aria-label="Breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Início</a>
                <?php $cats = get_the_category(); if ($cats) : $cat = $cats[0]; ?>
                    <span class="g1-sep">›</span>
                    <a href="<?php echo esc_url(get_category_link($cat->term_id)); ?>"><?php echo esc_html($cat->name); ?></a>
                <?php endif; ?>
                <span class="g1-sep">›</span>
                <span class="g1-current"><?php echo wp_trim_words(get_the_title(), 6); ?></span>
            </nav>

            <?php if ($cats) : ?>
                <span class="g1-article-cat <?php echo esc_attr(ldb_escopo_class($cat->name)); ?>">
                    <?php echo esc_html($cat->name); ?>
                </span>
            <?php endif; ?>

            <h1 class="g1-article-title" itemprop="headline"><?php the_title(); ?></h1>

            <div class="g1-article-meta">
                <?php echo get_avatar(get_the_author_meta('ID'), 36, '', '', ['class' => 'g1-avatar']); ?>
                <div class="g1-meta-info">
                    <span class="g1-meta-author" itemprop="author"><?php the_author(); ?></span>
                    <div class="g1-meta-date">
                        <time datetime="<?php echo get_the_date('c'); ?>" itemprop="datePublished">
                            <?php echo get_the_date('d \d\e F \d\e Y'); ?>
                        </time>
                        <span class="g1-meta-reading">
                            <?php echo max(1, ceil(str_word_count(strip_tags(get_the_content())) / 200)) . ' min de leitura'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (has_post_thumbnail()) : ?>
        <div class="g1-article-hero-img">
            <div class="g1-container">
                <div class="g1-hero-img-wrap">
                    <?php the_post_thumbnail('ldb-hero', [
                        'itemprop'      => 'image',
                        'loading'       => 'eager',
                        'fetchpriority' => 'high',
                    ]); ?>
                </div>
                <?php $caption = get_the_post_thumbnail_caption(); if ($caption) : ?>
                    <figcaption class="g1-img-caption"><?php echo esc_html($caption); ?></figcaption>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="g1-container">
        <div class="g1-article-body" itemprop="articleBody">
            <?php if (has_excerpt()) : ?>
                <p class="g1-lead"><?php echo get_the_excerpt(); ?></p>
            <?php endif; ?>

            <?php the_content(); ?>

            <?php $tags = get_the_tags(); if ($tags) : ?>
                <div class="g1-tags">
                    <?php foreach ($tags as $tag) : ?>
                        <a href="<?php echo esc_url(get_tag_link($tag->term_id)); ?>" class="g1-tag" rel="tag">#<?php echo esc_html($tag->name); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="g1-share">
                <span class="g1-share-label">Compartilhar:</span>
                <div class="g1-share-btns">
                    <a href="https://api.whatsapp.com/send?text=<?php echo urlencode(get_the_title() . ' ' . get_permalink()); ?>" target="_blank" rel="noopener" class="g1-share-btn g1-wa">WhatsApp</a>
                    <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(get_permalink()); ?>&text=<?php echo urlencode(get_the_title()); ?>" target="_blank" rel="noopener" class="g1-share-btn g1-tw">Twitter</a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(get_permalink()); ?>" target="_blank" rel="noopener" class="g1-share-btn g1-fb">Facebook</a>
                    <a href="https://t.me/share/url?url=<?php echo urlencode(get_permalink()); ?>&text=<?php echo urlencode(get_the_title()); ?>" target="_blank" rel="noopener" class="g1-share-btn g1-tg">Telegram</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Author Box -->
    <div class="g1-container">
        <div class="g1-author-box">
            <div class="g1-author-box-avatar">
                <?php echo get_avatar(get_the_author_meta('ID'), 72, '', get_the_author(), ['class' => 'g1-avatar-lg']); ?>
            </div>
            <div class="g1-author-box-info">
                <span class="g1-author-box-label">Escrito por</span>
                <h4 class="g1-author-box-name">
                    <a href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>">
                        <?php the_author(); ?>
                    </a>
                </h4>
                <?php if (get_the_author_meta('description')) : ?>
                    <p class="g1-author-box-bio"><?php echo esc_html(get_the_author_meta('description')); ?></p>
                <?php endif; ?>
                <div class="g1-author-box-meta">
                    <a href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" class="g1-author-box-link">
                        Ver todos os artigos →
                    </a>
                    <?php
                    $author_posts_count = count_user_posts(get_the_author_meta('ID'), 'post', true);
                    if ($author_posts_count > 0) :
                        ?>
                        <span class="g1-author-box-count"><?php echo $author_posts_count; ?> artigos publicados</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         LEIA TAMBÉM
         Layout: Título → Subtítulo → Imagem full-width → Data · Categoria
         Desktop: 3 colunas | Mobile: stacked
         ============================================================ -->
    <section class="g1-related">
        <div class="g1-container">
            <h3 class="g1-section-title"><span class="g1-title-dot"></span> Leia Também</h3>
            <div class="g1-related-grid">
                <?php
                $related = new WP_Query([
                    'posts_per_page' => 3,
                    'post__not_in'   => [get_the_ID()],
                    'category__in'   => wp_get_post_categories(get_the_ID()),
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ]);

                if ($related->have_posts()) :
                    while ($related->have_posts()) : $related->the_post();
                        $r_cats = get_the_category();
                        $r_cat_name = $r_cats ? $r_cats[0]->name : '';
                        $r_cat_class = $r_cats ? ldb_escopo_class($r_cats[0]->name) : '';
                        ?>
                        <article class="g1-related-card">
                            <a href="<?php the_permalink(); ?>" class="g1-related-link">
                                <div class="g1-related-text">
                                    <h4 class="g1-related-title"><?php the_title(); ?></h4>
                                    <p class="g1-related-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 12); ?></p>
                                </div>
                                <?php if (has_post_thumbnail()) : ?>
                                    <div class="g1-related-img">
                                        <img src="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'ldb-card-medium')); ?>"
                                             alt="<?php echo esc_attr(get_the_title()); ?>"
                                             width="600" height="340"
                                             loading="lazy" decoding="async">
                                    </div>
                                <?php endif; ?>
                                <div class="g1-related-footer">
                                    <span class="g1-related-time"><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
                                    <?php if ($r_cat_name) : ?>
                                        <span class="g1-related-sep">·</span>
                                        <span class="g1-related-in">Em <span class="<?php echo esc_attr($r_cat_class); ?>" style="font-weight:600;"><?php echo esc_html($r_cat_name); ?></span></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </article>
                        <?php
                    endwhile;
                    wp_reset_postdata();
                endif;
                ?>
            </div>
        </div>
    </section>

    <!-- ============================================================
         MAIS NOTÍCIAS (scroll infinito no single)
         ============================================================ -->
    <div class="g1-container">
        <div class="g1-single-feed">
            <h3 class="g1-section-title"><span class="g1-title-dot"></span> Mais Notícias</h3>
            <div class="g1-feed-list" id="g1-feed-list">
                <?php
                $more_posts = new WP_Query([
                    'posts_per_page' => 6,
                    'post_status'    => 'publish',
                    'post__not_in'   => array_merge([get_the_ID()], $related->posts ? wp_list_pluck($related->posts, 'ID') : []),
                ]);

                $single_exclude = [get_the_ID()];
                if ($related->posts) {
                    $single_exclude = array_merge($single_exclude, wp_list_pluck($related->posts, 'ID'));
                }

                if ($more_posts->have_posts()) :
                    while ($more_posts->have_posts()) : $more_posts->the_post();
                        $m_cats = get_the_category();
                        $m_cat_name = $m_cats ? $m_cats[0]->name : '';
                        $m_cat_class = $m_cats ? ldb_escopo_class($m_cats[0]->name) : '';
                        $single_exclude[] = get_the_ID();
                        ?>
                        <article class="g1-fullcard">
                            <a href="<?php the_permalink(); ?>" class="g1-fullcard-link">
                                <?php if (has_post_thumbnail()) : ?>
                                    <div class="g1-fullcard-img">
                                        <img src="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'ldb-card-medium')); ?>"
                                             alt="<?php echo esc_attr(get_the_title()); ?>"
                                             width="600" height="340"
                                             loading="lazy" decoding="async">
                                    </div>
                                <?php endif; ?>
                                <div class="g1-fullcard-body">
                                    <?php if ($m_cat_name) : ?>
                                        <span class="g1-fullcard-cat <?php echo esc_attr($m_cat_class); ?>"><?php echo esc_html($m_cat_name); ?></span>
                                    <?php endif; ?>
                                    <h3 class="g1-fullcard-title"><?php the_title(); ?></h3>
                                    <div class="g1-fullcard-meta">
                                        <span><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
                                        <?php if ($m_cat_name) : ?>
                                            <span class="g1-fullcard-sep">·</span>
                                            <span>Em <?php echo esc_html($m_cat_name); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        </article>
                        <?php
                    endwhile;
                    wp_reset_postdata();
                endif;
                ?>
            </div>

            <div class="g1-load-more" id="g1-load-more">
                <div class="g1-spinner"></div>
                <span>Carregando mais notícias...</span>
            </div>
            <div id="g1-scroll-sentinel" style="height:1px;"></div>
        </div>
    </div>

    <?php if (comments_open() || get_comments_number()) : ?>
        <div class="g1-container">
            <div class="g1-article-body">
                <?php comments_template(); ?>
            </div>
        </div>
    <?php endif; ?>

</article>

<script>
window.ldbFeedConfig = {
    ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
    nonce: '<?php echo wp_create_nonce('ldb_api_nonce'); ?>',
    exclude: <?php echo wp_json_encode($single_exclude); ?>,
    page: 2
};
</script>

<?php get_footer(); ?>
