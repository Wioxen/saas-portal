<?php
/**
 * Astra Child — front-page.php
 * Homepage refatorada — usa header.php / footer.php compartilhados.
 */
if (!defined('ABSPATH')) exit;
get_header();

global $wpdb;
$featured_main = $wpdb->get_row("SELECT ID,post_title,post_excerpt,post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 1");
if (!$featured_main) {
    echo '<main id="content" class="container"><div style="padding:4rem 1rem;text-align:center"><h1>Nenhum conteúdo publicado ainda.</h1></div></main>';
    get_footer();
    return;
}
$featured_secondary = $wpdb->get_results($wpdb->prepare("SELECT ID,post_title,post_excerpt,post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND ID!=%d ORDER BY post_date DESC LIMIT 4", $featured_main->ID));
$all_posts_page1 = $wpdb->get_results("SELECT ID,post_title,post_excerpt,post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 8");
$total_posts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'");
$has_more = $total_posts > 8;
$most_read = veb_most_read_week();
$all_categories = veb_all_cats();
$trending_cats = veb_trending_cats();

$lcp_id = get_post_thumbnail_id($featured_main->ID);
$lcp_src = $lcp_id ? wp_get_attachment_image_url($lcp_id,'full') : '';
$lcp_srcset = $lcp_id ? wp_get_attachment_image_srcset($lcp_id,'full') : '';
$fcat = veb_get_cat($featured_main->ID);
$fcslug = veb_get_cat_slug($featured_main->ID);
$fcid = veb_get_cat_id($featured_main->ID);
$fexc = $featured_main->post_excerpt ? wp_trim_words($featured_main->post_excerpt, 25, '…') : wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $featured_main->ID)), 25, '…');
?>

<main id="content" class="container">

<!-- 1. HERO -->
<section class="hero" aria-label="Destaques principais">
<a href="<?php echo esc_url(get_permalink($featured_main->ID)); ?>" class="hero-main" data-pid="<?php echo (int)$featured_main->ID; ?>" data-cat="<?php echo esc_attr($fcat); ?>" data-cat-slug="<?php echo esc_attr($fcslug); ?>" data-thumb="<?php echo esc_url($lcp_src ?: veb_fallback_img()); ?>" data-title="<?php echo esc_attr($featured_main->post_title); ?>">
    <div class="hero-main-body">
        <div class="badges">
            <span class="cat-badge" style="background:<?php echo esc_attr(veb_cat_color($fcslug)); ?>"><?php echo esc_html($fcat); ?></span>
            <?php if (veb_is_new($featured_main->post_date)): ?><span class="new-badge">NOVO</span><?php endif; ?>
            <?php if (in_array($fcid, $trending_cats, true)): ?><span class="hot-badge">EM ALTA</span><?php endif; ?>
        </div>
        <h1><?php echo esc_html($featured_main->post_title); ?></h1>
        <p class="hero-main-excerpt"><?php echo esc_html($fexc); ?></p>
    </div>
    <div class="hero-main-img"><img src="<?php echo esc_url($lcp_src ?: veb_fallback_img()); ?>" <?php if($lcp_srcset):?>srcset="<?php echo esc_attr($lcp_srcset); ?>" sizes="(max-width:768px) 100vw, 62vw"<?php endif;?> alt="<?php echo esc_attr($featured_main->post_title); ?>" width="800" height="450" fetchpriority="high" decoding="sync"></div>
    <div class="hero-main-footer">
        <time datetime="<?php echo esc_attr(date('c', strtotime($featured_main->post_date))); ?>"><?php echo esc_html(veb_time_ago($featured_main->post_date)); ?></time>
        <span class="dot">·</span>
        <span class="reading-time"><?php echo (int)veb_reading_time($featured_main->ID); ?> min de leitura</span>
    </div>
</a>
<div class="hero-sidebar"><h2 class="sr-only">Mais destaques</h2>
<?php foreach ($featured_secondary as $i => $p):
    $pt = get_the_post_thumbnail_url($p->ID,'medium');
    $pl = get_permalink($p->ID);
    $pc = veb_get_cat($p->ID);
    $pcsl = veb_get_cat_slug($p->ID);
    $pcid = veb_get_cat_id($p->ID);
    $sexc = veb_get_excerpt($p);
    $isn = veb_is_new($p->post_date);
    $ish = in_array($pcid, $trending_cats, true);
?>
<a href="<?php echo esc_url($pl); ?>" class="hero-card" data-pid="<?php echo (int)$p->ID; ?>" data-cat="<?php echo esc_attr($pc); ?>" data-cat-slug="<?php echo esc_attr($pcsl); ?>" data-thumb="<?php echo esc_url($pt ?: veb_fallback_img()); ?>" data-title="<?php echo esc_attr($p->post_title); ?>">
    <div class="hero-card-body">
        <div class="badges">
            <span class="cat-badge" style="background:<?php echo esc_attr(veb_cat_color($pcsl)); ?>"><?php echo esc_html($pc); ?></span>
            <?php if ($isn): ?><span class="new-badge">NOVO</span><?php endif; ?>
            <?php if ($ish): ?><span class="hot-badge">EM ALTA</span><?php endif; ?>
        </div>
        <h3><?php echo esc_html(wp_trim_words($p->post_title, 10)); ?></h3>
        <p class="hero-card-excerpt"><?php echo esc_html($sexc); ?></p>
    </div>
    <div class="hero-card-img"><img src="<?php echo esc_url($pt ?: veb_fallback_img()); ?>" alt="<?php echo esc_attr(wp_trim_words($p->post_title, 6)); ?>" width="400" height="225" loading="<?php echo $i<2?'eager':'lazy'; ?>" decoding="async"></div>
    <div class="hero-card-footer"><time datetime="<?php echo esc_attr(date('c', strtotime($p->post_date))); ?>"><?php echo esc_html(veb_time_ago($p->post_date)); ?></time></div>
</a>
<?php endforeach; ?>
</div>
</section>

<!-- 2. MAIS LIDOS DA SEMANA -->
<?php if (!empty($most_read)): ?>
<section class="smart-section" aria-labelledby="popular-h">
    <div class="smart-section-hdr"><h2 id="popular-h"><span aria-hidden="true">&#128293;</span> Mais Lidos da Semana</h2></div>
    <div class="smart-grid">
        <?php foreach ($most_read as $idx => $mp):
            $mt = get_the_post_thumbnail_url($mp->ID,'medium');
            $ml = get_permalink($mp->ID);
            $mc = veb_get_cat($mp->ID);
            $mcs = veb_get_cat_slug($mp->ID);
        ?>
        <a class="smart-card" href="<?php echo esc_url($ml); ?>" data-pid="<?php echo (int)$mp->ID; ?>" data-cat="<?php echo esc_attr($mc); ?>" data-cat-slug="<?php echo esc_attr($mcs); ?>" data-thumb="<?php echo esc_url($mt ?: veb_fallback_img()); ?>" data-title="<?php echo esc_attr($mp->post_title); ?>">
            <span class="smart-card-rank"><?php echo (int)($idx+1); ?></span>
            <div class="smart-card-img"><img src="<?php echo esc_url($mt ?: veb_fallback_img()); ?>" alt="" loading="lazy" decoding="async" width="400" height="225"></div>
            <div class="smart-card-body"><span class="smart-card-cat" style="background:<?php echo esc_attr(veb_cat_color($mcs)); ?>"><?php echo esc_html($mc); ?></span><h4><?php echo esc_html(wp_trim_words($mp->post_title, 12)); ?></h4></div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- 3. VISTO POR ÚLTIMO (JS popula) -->
<section class="smart-section" id="recentSection" style="display:none" aria-labelledby="recent-h">
    <div class="smart-section-hdr">
        <h2 id="recent-h"><span aria-hidden="true">&#128338;</span> Visto por Último</h2>
        <button type="button" class="clear-history" id="clearHistory" aria-label="Limpar histórico">Limpar</button>
    </div>
    <div class="smart-grid" id="recentGrid"></div>
</section>

<!-- 4. CATEGORIAS (CARROSSEL) -->
<?php if (!empty($all_categories)): ?>
<nav class="quick-links" aria-label="Navegação por categorias">
<h2 class="quick-links-title"><span aria-hidden="true">&#9889;</span> Navegue por Categorias</h2>
<div class="quick-grid-wrap">
    <button type="button" class="quick-nav prev" aria-label="Anterior"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
    <div class="quick-grid">
        <?php foreach ($all_categories as $cat): $is_hot = in_array((int)$cat->term_id, $trending_cats, true); ?>
        <a href="<?php echo esc_url(get_category_link($cat->term_id)); ?>" class="quick-tag" data-cat-slug="<?php echo esc_attr($cat->slug); ?>" style="background:<?php echo esc_attr(veb_cat_color($cat->slug)); ?>">
            <?php echo esc_html($cat->name); ?> <span class="quick-tag-count">(<?php echo esc_html($cat->count); ?>)</span>
            <?php if ($is_hot): ?><span class="hot-dot" aria-label="em alta"></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <button type="button" class="quick-nav next" aria-label="Próximo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
</div>
</nav>
<?php endif; ?>

<!-- 5. NEWSLETTER -->
<section class="newsletter">
<h2>Receba as melhores vagas no seu e-mail</h2>
<p>Fique por dentro de vagas de emprego, benefícios e direitos trabalhistas atualizados.</p>
<form class="newsletter-form" id="nlForm" novalidate>
<div class="newsletter-row">
    <input type="email" id="nlEmail" placeholder="Digite seu melhor e-mail" required aria-label="Seu e-mail">
    <button type="submit" id="nlBtn">Inscrever-se</button>
</div>
<div class="captcha-row">
    <span class="captcha-label" id="cQ"></span>
    <input type="text" class="captcha-input" id="cA" placeholder="?" autocomplete="off" inputmode="numeric" aria-label="Resposta" required>
    <button type="button" class="captcha-refresh" id="cR" aria-label="Nova pergunta">&#8635;</button>
</div>
<p class="captcha-error" id="cErr">Resposta incorreta. Tente novamente.</p>
<p class="newsletter-success" id="nlOk">&#10003; Inscrito com sucesso!</p>
</form>
</section>

<!-- 6. TODOS OS POSTS + INFINITE SCROLL -->
<section aria-labelledby="sec-all">
<div class="section-hdr"><h2 id="sec-all"><span aria-hidden="true">&#128188;</span> Todos os Posts</h2><span class="section-hdr-sub"><?php echo (int)$total_posts; ?> publicações</span></div>
<div class="cards-grid" id="allGrid" data-done="<?php echo $has_more ? '0' : '1'; ?>"><?php foreach ($all_posts_page1 as $p) veb_render_card($p); ?></div>
<?php if ($has_more): ?>
<div id="scroll-sentinel" aria-hidden="true"></div>
<div class="loader" id="loader"><div class="spinner" role="status" aria-label="Carregando"></div></div>
<?php endif; ?>
<p class="no-more" id="noMore" <?php if (!$has_more) echo 'style="display:block"'; ?>>Você viu todas as publicações.</p>
</section>

</main>

<?php get_footer(); ?>
