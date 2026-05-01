<?php
/**
 * Astra Child Guia dos Cursos — archive.php
 */
if (!defined('ABSPATH')) exit;
get_header();

global $wp_query;
$total = $wp_query->found_posts;
$max_pages = max(1, (int)$wp_query->max_num_pages);

$archive_title = '';
$archive_desc = '';
$archive_color = '#1e40af';
$archive_cat_id = 0;

if (is_category()) {
    $cat_obj = get_queried_object();
    $archive_title = single_cat_title('', false);
    $archive_desc = category_description();
    $archive_color = gdc_cat_color($cat_obj->slug);
    $archive_cat_id = (int)$cat_obj->term_id;
} elseif (is_tag()) {
    $archive_title = 'Tag: ' . single_tag_title('', false);
    $archive_desc = tag_description();
} elseif (is_search()) {
    $archive_title = 'Busca por: ' . get_search_query();
    $archive_desc = sprintf('%d resultado%s encontrado%s.', $total, $total !== 1 ? 's' : '', $total !== 1 ? 's' : '');
} elseif (is_author()) {
    $archive_title = 'Posts de ' . get_the_author();
} elseif (is_date()) {
    if (is_year()) $archive_title = 'Arquivo: ' . get_the_date('Y');
    elseif (is_month()) $archive_title = 'Arquivo: ' . get_the_date('F \d\e Y');
    elseif (is_day()) $archive_title = 'Arquivo: ' . get_the_date('d \d\e F \d\e Y');
    else $archive_title = get_the_archive_title();
} else {
    $archive_title = wp_strip_all_tags(get_the_archive_title());
    if (!$archive_title) $archive_title = 'Publicações';
    $archive_desc = wp_strip_all_tags(get_the_archive_description());
}
?>

<main id="content" class="container">

<header class="archive-header" style="border-left-color:<?php echo esc_attr($archive_color); ?>">
    <div class="archive-header-text">
        <h1><?php echo esc_html($archive_title); ?></h1>
        <?php if ($archive_desc): ?><div class="archive-desc"><?php echo wp_kses_post($archive_desc); ?></div><?php endif; ?>
        <div class="archive-meta">
            <span><?php echo (int)$total; ?> publicaç<?php echo $total === 1 ? 'ão' : 'ões'; ?></span>
            <?php if ($max_pages > 1 && get_query_var('paged') > 1): ?>
            <span class="dot">·</span>
            <span>Página <?php echo (int)get_query_var('paged'); ?> de <?php echo (int)$max_pages; ?></span>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if (have_posts()): ?>

<section aria-label="Lista de publicações">
<div class="cards-grid" id="allGrid" data-done="<?php echo $max_pages <= 1 ? '1' : '0'; ?>">
<?php while (have_posts()): the_post(); $p = get_post(); gdc_render_card($p); endwhile; ?>
</div>

<?php if ($max_pages > 1): ?>
<div id="scroll-sentinel" aria-hidden="true"></div>
<div class="loader" id="loader"><div class="spinner" role="status" aria-label="Carregando"></div></div>
<p class="no-more" id="noMore">Você viu todas as publicações desta seção.</p>

<nav class="pagination" aria-label="Paginação">
<?php
echo paginate_links(['mid_size'=>2,'prev_text'=>'←','next_text'=>'→','type'=>'plain']);
?>
</nav>
<?php endif; ?>

</section>

<?php else: ?>
<p class="no-posts">Nenhuma publicação encontrada nesta seção.</p>
<?php endif; ?>

<!-- VISTO POR ÚLTIMO -->
<section class="smart-section" id="recentSection" style="display:none" aria-labelledby="recent-h">
    <div class="smart-section-hdr">
        <h2 id="recent-h"><span aria-hidden="true">&#128338;</span> Visto por Último</h2>
        <button type="button" class="clear-history" id="clearHistory" aria-label="Limpar histórico">Limpar</button>
    </div>
    <div class="smart-grid" id="recentGrid"></div>
</section>

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

</main>

<?php get_footer(); ?>
