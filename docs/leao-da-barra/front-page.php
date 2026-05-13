<?php
/**
 * Front Page Template
 * 
 * 5 hero cards → CTA próximos jogos (lazy) → categorias →
 * feed 2-col desktop / single-col mobile with inline table after 5th
 * 
 * @package LeaoDaBarra
 */

get_header();

// 5 primeiros posts (hero)
$hero_query = new WP_Query(['posts_per_page' => 5, 'post_status' => 'publish']);
$hero_data = [];
$all_exclude = [];

if ($hero_query->have_posts()) {
    while ($hero_query->have_posts()) {
        $hero_query->the_post();
        $cats = get_the_category();
        $hero_data[] = [
            'id'        => get_the_ID(),
            'title'     => get_the_title(),
            'excerpt'   => wp_trim_words(get_the_excerpt(), 15),
            'url'       => get_permalink(),
            'thumbnail' => get_the_post_thumbnail_url(get_the_ID(), 'ldb-hero'),
            'thumb_md'  => get_the_post_thumbnail_url(get_the_ID(), 'ldb-card-medium'),
            'category'  => $cats ? $cats[0]->name : '',
            'cat_class' => $cats ? ldb_escopo_class($cats[0]->name) : '',
            'time_ago'  => ldb_time_ago(get_the_date('Y-m-d H:i:s')),
        ];
        $all_exclude[] = get_the_ID();
    }
    wp_reset_postdata();
}

$all_categories = get_categories(['hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']);

$feed_query = new WP_Query([
    'posts_per_page' => 10,
    'post_status'    => 'publish',
    'post__not_in'   => $all_exclude,
]);
?>

<!-- 1. HERO CAROUSEL -->
<?php if (!empty($hero_data)) : ?>
<section class="g1-carousel" aria-label="Destaques">
    <div class="g1-carousel-track" id="g1-carousel-track">
        <?php foreach ($hero_data as $i => $p) : ?>
            <a href="<?php echo esc_url($p['url']); ?>" class="g1-carousel-slide <?php echo $i === 0 ? 'active' : ''; ?>">
                <div class="g1-carousel-img">
                    <?php if ($p['thumbnail'] || $p['thumb_md']) : ?>
                        <img src="<?php echo esc_url($p['thumb_md'] ?: $p['thumbnail']); ?>"
                             srcset="<?php echo esc_url($p['thumb_md']); ?> 600w, <?php echo esc_url($p['thumbnail'] ?: $p['thumb_md']); ?> 1200w"
                             sizes="(max-width: 640px) 100vw, 1080px"
                             alt="<?php echo esc_attr($p['title']); ?>"
                             width="1200" height="675"
                             <?php echo $i === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"'; ?>
                             decoding="async">
                    <?php else : ?>
                        <div class="g1-carousel-placeholder"></div>
                    <?php endif; ?>
                    <div class="g1-carousel-gradient"></div>
                </div>
                <div class="g1-carousel-caption">
                    <?php if ($p['category']) : ?>
                        <span class="g1-carousel-cat <?php echo esc_attr($p['cat_class']); ?>"><?php echo esc_html($p['category']); ?></span>
                    <?php endif; ?>
                    <h2 class="g1-carousel-title"><?php echo esc_html($p['title']); ?></h2>
                    <p class="g1-carousel-excerpt"><?php echo esc_html($p['excerpt']); ?></p>
                    <span class="g1-carousel-time"><?php echo esc_html($p['time_ago']); ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <!-- Progress bar -->
    <div class="g1-carousel-progress">
        <div class="g1-carousel-progress-bar" id="g1-progress-bar"></div>
    </div>
    <button class="g1-carousel-arrow g1-arrow-prev" id="g1-prev" aria-label="Anterior">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M13 4L7 10L13 16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <button class="g1-carousel-arrow g1-arrow-next" id="g1-next" aria-label="Próximo">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M7 4L13 10L7 16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
</section>
<?php endif; ?>

<div class="g1-container">

    <!-- 2. CTA PRÓXIMOS JOGOS (lazy - só carrega ao clicar) -->
    <div class="g1-fixtures-cta" id="g1-fixtures-cta">
        <button class="g1-fixtures-btn" id="g1-fixtures-btn" aria-expanded="false">
            <span class="g1-fixtures-btn-icon">&#9917;</span>
            <span class="g1-fixtures-btn-text">Ver próximos jogos do Vitória</span>
            <span class="g1-fixtures-btn-arrow" id="g1-fixtures-arrow">&#9660;</span>
        </button>
        <div class="g1-fixtures-panel" id="g1-fixtures-panel" style="display:none;">
            <div id="g1-fixtures-content">
                <div class="ldb-loading"><div class="ldb-spinner"></div><span>Carregando jogos...</span></div>
            </div>
        </div>
    </div>

    <!-- 3. CATEGORIAS -->
    <div class="g1-categories-bar">
        <div class="g1-cat-scroll">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="g1-cat-pill g1-cat-active">Todas</a>
            <?php $cat_i = 0; foreach ($all_categories as $cat) : ?>
                <a href="<?php echo esc_url(get_category_link($cat->term_id)); ?>" class="g1-cat-pill <?php echo $cat_i % 2 === 0 ? 'g1-cat-black' : 'g1-cat-red'; ?>">
                    <?php echo esc_html($cat->name); ?>
                </a>
            <?php $cat_i++; endforeach; ?>
        </div>
    </div>

    <!-- 4. FEED -->
    <div class="g1-feed" id="g1-feed">
        <div class="g1-feed-layout">
            <div class="g1-feed-list" id="g1-feed-list">
                <?php
                $post_count = 0;
                if ($feed_query->have_posts()) :
                    while ($feed_query->have_posts()) : $feed_query->the_post();
                        $post_count++;
                        $cats = get_the_category();
                        $cat_name = $cats ? $cats[0]->name : '';
                        $cat_class = $cats ? ldb_escopo_class($cats[0]->name) : '';
                        $all_exclude[] = get_the_ID();
                        $thumb_sm = get_the_post_thumbnail_url(get_the_ID(), 'ldb-card-small');
                        $thumb_md = get_the_post_thumbnail_url(get_the_ID(), 'ldb-card-medium');
                        ?>
                        <article class="g1-fullcard">
                            <a href="<?php the_permalink(); ?>" class="g1-fullcard-link">
                                <?php if ($thumb_md || $thumb_sm) : ?>
                                    <div class="g1-fullcard-img">
                                        <img src="<?php echo esc_url($thumb_sm ?: $thumb_md); ?>"
                                             srcset="<?php if ($thumb_sm) echo esc_url($thumb_sm) . ' 300w, '; ?><?php echo esc_url($thumb_md ?: $thumb_sm); ?> 600w"
                                             sizes="(max-width: 640px) 100vw, 180px"
                                             alt="<?php echo esc_attr(get_the_title()); ?>"
                                             width="300" height="200"
                                             loading="lazy" decoding="async">
                                    </div>
                                <?php endif; ?>
                                <div class="g1-fullcard-body">
                                    <?php if ($cat_name) : ?>
                                        <span class="g1-fullcard-cat <?php echo esc_attr($cat_class); ?>"><?php echo esc_html($cat_name); ?></span>
                                    <?php endif; ?>
                                    <h3 class="g1-fullcard-title"><?php the_title(); ?></h3>
                                    <div class="g1-fullcard-meta">
                                        <span><?php echo ldb_time_ago(get_the_date('Y-m-d H:i:s')); ?></span>
                                        <?php if ($cat_name) : ?>
                                            <span class="g1-fullcard-sep">·</span>
                                            <span>Em <?php echo esc_html($cat_name); ?></span>
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

            <!-- Sidebar desktop -->
            <aside class="g1-feed-sidebar">
                <div class="g1-sidebar-widget">
                    <div class="g1-widget-header">
                        <h3 class="g1-widget-title">Classificação</h3>
                        <a href="<?php echo esc_url(home_url('/tabela/')); ?>" class="g1-widget-link">Completa →</a>
                    </div>
                    <div id="g1-inline-tabela">
                        <div class="ldb-loading"><div class="ldb-spinner"></div></div>
                    </div>
                </div>
            </aside>
        </div>

        <div class="g1-load-more" id="g1-load-more">
            <div class="g1-spinner"></div>
            <span>Carregando mais notícias...</span>
        </div>
        <div id="g1-scroll-sentinel" style="height:1px;"></div>
    </div>

</div>

<script>
window.ldbFeedConfig = {
    ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
    nonce: '<?php echo wp_create_nonce('ldb_api_nonce'); ?>',
    exclude: <?php echo wp_json_encode($all_exclude); ?>
};
</script>

<?php get_footer(); ?>
