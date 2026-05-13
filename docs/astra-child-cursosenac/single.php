<?php
/**
 * Astra Child Cursos SENAC Gratuito — single.php
 * Com TOC automático, Save for Later, Next Post slide-in, Author Box.
 */
if (!defined('ABSPATH')) exit;
get_header();

if (have_posts()): the_post();
    $pid = get_the_ID();
    $cat = csg_get_cat($pid);
    $cat_slug = csg_get_cat_slug($pid);
    $cat_id = csg_get_cat_id($pid);
    $is_new = csg_is_new(get_the_date('Y-m-d H:i:s'));
    $trending_cats = csg_trending_cats();
    $is_hot = in_array($cat_id, $trending_cats, true);
    $reading = csg_reading_time($pid);
    $share_url = get_permalink();
    $share_title = get_the_title();
    $share_text = $share_title . ' ' . $share_url;

    $published_ts = strtotime(get_the_date('Y-m-d H:i:s'));
    $modified_ts = strtotime(get_the_modified_date('Y-m-d H:i:s'));
    $show_modified = ($modified_ts - $published_ts) > 3600;

    /* Próximo post (mais antigo) */
    $next_post = get_adjacent_post(false, '', false);
    if (!$next_post) {
        global $wpdb;
        $next_post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND ID!=%d ORDER BY post_date DESC LIMIT 1",
            $pid
        ));
        if ($next_post) $next_post = get_post($next_post->ID);
    }

    /* Continue Lendo */
    global $wpdb;
    $continue_posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID,post_title,post_excerpt,post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND ID!=%d ORDER BY post_date DESC LIMIT 8",
        $pid
    ));
    $total_continue = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND ID!=%d",
        $pid
    ));
    $continue_has_more = $total_continue > 8;
?>

<main id="content" class="container">

<article class="post-article" itemscope itemtype="https://schema.org/Article">

<div class="post-meta-row">
    <span class="cat-badge" style="background:<?php echo esc_attr(csg_cat_color($cat_slug)); ?>"><?php echo esc_html($cat); ?></span>
    <?php if ($is_new): ?><span class="new-badge">NOVO</span><?php endif; ?>
    <?php if ($is_hot): ?><span class="hot-badge">EM ALTA</span><?php endif; ?>
</div>

<h1 class="post-title" itemprop="headline"><?php the_title(); ?></h1>

<div class="post-meta">
    <span class="author">Por <?php the_author(); ?></span>
    <span class="meta-dot">·</span>
    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>" itemprop="datePublished"><?php echo esc_html(get_the_date('d \d\e M, Y')); ?></time>
    <span class="meta-dot">·</span>
    <span class="reading-time"><?php echo (int)$reading; ?> min de leitura</span>
    <?php if ($show_modified): ?>
    <span class="meta-dot">·</span>
    <span class="modified-date" title="Última atualização">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
        Atualizado em <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>" itemprop="dateModified"><?php echo esc_html(get_the_modified_date('d \d\e M, Y')); ?></time>
    </span>
    <?php endif; ?>
</div>

<?php if (has_post_thumbnail()):
    $thumb_caption = get_the_post_thumbnail_caption();
?>
<figure class="post-thumbnail" itemprop="image" itemscope itemtype="https://schema.org/ImageObject">
    <?php the_post_thumbnail('large', ['fetchpriority'=>'high','decoding'=>'async','itemprop'=>'url']); ?>
    <?php if ($thumb_caption): ?>
    <figcaption class="post-thumbnail-caption" itemprop="caption"><?php echo wp_kses_post($thumb_caption); ?></figcaption>
    <?php endif; ?>
</figure>
<?php endif; ?>

<!-- TABLE OF CONTENTS (JS popula) -->
<aside class="post-toc" id="postToc" hidden>
    <div class="post-toc-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        Neste artigo
        <button type="button" class="post-toc-toggle" aria-label="Mostrar/Esconder índice">Esconder ▲</button>
    </div>
    <ul class="toc-list" id="tocList"></ul>
</aside>

<!-- SHARE BAR -->
<div class="post-share-bar" role="group" aria-label="Compartilhar este post">
    <span class="share-label">Compartilhar:</span>
    <a class="share-btn share-wa" href="https://api.whatsapp.com/send?text=<?php echo rawurlencode($share_text); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.72.45 3.41 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01-1.87-1.87-4.36-2.91-7.01-2.91M12.05 3.67c2.2 0 4.26.86 5.82 2.42 1.56 1.56 2.41 3.62 2.41 5.83 0 4.54-3.7 8.23-8.24 8.23-1.48 0-2.93-.39-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.32c-.84-1.31-1.27-2.83-1.27-4.39 0-4.54 3.69-8.23 8.26-8.23M8.53 7.33c-.16 0-.43.06-.66.31-.22.25-.87.86-.87 2.07 0 1.22.89 2.39 1 2.56.14.17 1.76 2.67 4.25 3.73.59.27 1.05.42 1.41.53.59.19 1.13.16 1.56.1.48-.07 1.46-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.07-.1-.23-.16-.48-.27"/></svg>
        <span>WhatsApp</span>
    </a>
    <a class="share-btn share-tg" href="https://t.me/share/url?url=<?php echo rawurlencode($share_url); ?>&text=<?php echo rawurlencode($share_title); ?>" target="_blank" rel="noopener noreferrer" aria-label="Telegram">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
        <span>Telegram</span>
    </a>
    <a class="share-btn share-tw" href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode($share_url); ?>&text=<?php echo rawurlencode($share_title); ?>" target="_blank" rel="noopener noreferrer" aria-label="Compartilhar no X">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        <span>X</span>
    </a>
    <button type="button" class="share-btn share-copy" data-share-url="<?php echo esc_url($share_url); ?>" data-share-title="<?php echo esc_attr($share_title); ?>" aria-label="Copiar link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        <span>Copiar</span>
    </button>
    <button type="button" class="share-btn share-save" id="saveForLaterBtn" aria-label="Salvar para depois">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        <span>Salvar</span>
    </button>
</div>

<div class="post-content" itemprop="articleBody">
    <?php the_content(); ?>
</div>

<?php
$tags = get_the_tags();
if ($tags && !is_wp_error($tags)): ?>
<div class="post-tags">
    <span class="label">Tags:</span>
    <?php foreach ($tags as $t): ?>
    <a href="<?php echo esc_url(get_tag_link($t->term_id)); ?>"><?php echo esc_html($t->name); ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</article>

<!-- AUTHOR BOX -->
<aside class="author-box" itemscope itemtype="https://schema.org/Person">
    <div class="author-avatar"><?php echo get_avatar(get_the_author_meta('ID'), 88, '', get_the_author(), ['class'=>'']); ?></div>
    <div class="author-info">
        <span class="author-label">Autor</span>
        <h3 class="author-name" itemprop="name"><?php the_author(); ?></h3>
        <?php $bio = get_the_author_meta('description'); if ($bio): ?>
        <p class="author-bio" itemprop="description"><?php echo esc_html($bio); ?></p>
        <?php endif; ?>
        <a class="author-archive-link" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>">Ver todos os posts deste autor →</a>
    </div>
</aside>

<!-- VISTO POR ÚLTIMO -->
<section class="smart-section" id="recentSection" style="display:none" aria-labelledby="recent-h">
    <div class="smart-section-hdr">
        <h2 id="recent-h"><span aria-hidden="true">&#128338;</span> Visto por Último</h2>
        <button type="button" class="clear-history" id="clearHistory" aria-label="Limpar histórico">Limpar</button>
    </div>
    <div class="smart-grid" id="recentGrid"></div>
</section>

<!-- POSTS RELACIONADOS -->
<?php
$related_cats = wp_get_post_categories($pid);
if (!empty($related_cats)):
    $related = get_posts([
        'category__in' => $related_cats,
        'post__not_in' => [$pid],
        'posts_per_page' => 4,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
    ]);
    if ($related): ?>
    <section class="related-section">
        <div class="section-hdr"><h2><span aria-hidden="true">&#128218;</span> Posts Relacionados</h2></div>
        <div class="cards-grid">
            <?php foreach ($related as $rp) csg_render_card($rp); ?>
        </div>
    </section>
    <?php endif;
endif;
?>

<!-- NEWSLETTER -->
<section class="newsletter">
<h2>Receba os melhores cursos no seu e-mail</h2>
<p>Fique por dentro de cursos gratuitos, vestibulares e oportunidades de carreira.</p>
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

<!-- CONTINUE LENDO (infinite scroll) -->
<?php if (!empty($continue_posts)): ?>
<section class="continue-section" aria-labelledby="continue-h">
    <div class="section-hdr"><h2 id="continue-h"><span aria-hidden="true">&#128214;</span> Continue Lendo</h2><span class="section-hdr-sub"><?php echo (int)$total_continue; ?> publicações</span></div>
    <div class="cards-grid" id="allGrid" data-done="<?php echo $continue_has_more ? '0' : '1'; ?>">
        <?php foreach ($continue_posts as $cp) csg_render_card($cp); ?>
    </div>
    <?php if ($continue_has_more): ?>
    <div id="scroll-sentinel" aria-hidden="true"></div>
    <div class="loader" id="loader"><div class="spinner" role="status" aria-label="Carregando"></div></div>
    <?php endif; ?>
    <p class="no-more" id="noMore" <?php if (!$continue_has_more) echo 'style="display:block"'; ?>>Você viu todas as publicações.</p>
</section>
<?php endif; ?>

<?php
if (comments_open() || get_comments_number()): ?>
<div class="comments-area">
    <?php comments_template(); ?>
</div>
<?php endif; ?>

</main>

<?php if ($next_post): $np_thumb = get_the_post_thumbnail_url($next_post->ID, 'medium'); ?>
<a href="<?php echo esc_url(get_permalink($next_post->ID)); ?>" class="next-post-slide" id="nextPostSlide" aria-label="Próximo post sugerido">
    <div class="next-post-slide-img"><img src="<?php echo esc_url($np_thumb ?: csg_fallback_img()); ?>" alt="" loading="lazy" decoding="async"></div>
    <div class="next-post-slide-body">
        <span class="next-post-slide-label">Próximo →</span>
        <p class="next-post-slide-title"><?php echo esc_html(get_the_title($next_post->ID)); ?></p>
    </div>
    <button type="button" class="next-dismiss" aria-label="Dispensar">&times;</button>
</a>
<?php endif; ?>

<?php
endif;
get_footer();
