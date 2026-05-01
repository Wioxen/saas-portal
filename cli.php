<?php
/**
 * CLI — Gerador em massa de posts/páginas.
 *
 * Uso:
 *   php cli.php keywords.csv              → gera posts (SEO) pra cada linha
 *   php cli.php keywords.csv --formato=discover
 *   php cli.php keywords.csv --tipo=page  → gera páginas (landing)
 *   php cli.php keywords.csv --dry-run    → só mostra o que faria, sem publicar
 *
 * CSV: uma keyword por linha, ou formato "keyword|url1|url2"
 *
 * Após gerar tudo, roda interligação automática.
 */

// Timeout ilimitado
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/Maquina.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
require_once __DIR__ . '/lib/PrettyLinks.php';
require_once __DIR__ . '/lib/InstantIndexing.php';

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites = sitesDisponiveis();

// ── Parse args ──
$args = $argv;
array_shift($args); // remove script name

$csvFile = null;
$formato = 'seo';
$tipo = 'post'; // post ou page
$dryRun = false;
$delay = 5; // segundos entre cada geração (evita rate limit)
$siteSlug = (string)array_key_first($sites);

foreach ($args as $arg) {
    if (str_starts_with($arg, '--formato=')) $formato = substr($arg, 10);
    elseif (str_starts_with($arg, '--tipo=')) $tipo = substr($arg, 7);
    elseif ($arg === '--dry-run') $dryRun = true;
    elseif (str_starts_with($arg, '--delay=')) $delay = (int)substr($arg, 8);
    elseif (str_starts_with($arg, '--site=')) $siteSlug = substr($arg, 7);
    elseif (!$csvFile) $csvFile = $arg;
}

aplicarSite($cfg, $sites, $siteSlug);

if (!$csvFile || !file_exists($csvFile)) {
    echo "Uso: php cli.php <arquivo.csv> [--formato=seo|discover|news|serp] [--tipo=post|page] [--dry-run] [--delay=5]\n";
    echo "CSV: uma keyword por linha, ou 'keyword|url1|url2'\n";
    exit(1);
}

// ── Lê CSV ──
$linhas = array_filter(array_map('trim', file($csvFile)));
$total = count($linhas);
echo "╔══════════════════════════════════════════╗\n";
echo "║  CLI — Gerador em massa                  ║\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  Arquivo: {$csvFile}\n";
echo "║  Keywords: {$total}\n";
echo "║  Formato: {$formato}\n";
echo "║  Tipo: {$tipo}\n";
echo "║  Dry run: " . ($dryRun ? 'SIM' : 'NÃO') . "\n";
echo "╚══════════════════════════════════════════╝\n\n";

$serper  = new Serper($cfg['serper_api_key']);
$scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
$claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
$wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$builder = new LandingBuilder($cfg['site_name'] ?? 'Como Comprar', $cfg['wp_url'] ?? '', ['number' => $cfg['whatsapp_number'] ?? '', 'group_url' => $cfg['whatsapp_group_url'] ?? '', 'cta_text' => $cfg['whatsapp_cta_text'] ?? '']);

$gerados = [];
$erros = [];

foreach ($linhas as $i => $linha) {
    $n = $i + 1;
    $parts = array_map('trim', explode('|', $linha));
    $keyword = $parts[0];
    $urls = array_filter(array_slice($parts, 1));

    echo "[{$n}/{$total}] {$keyword}";
    if (!empty($urls)) echo " + " . count($urls) . " URLs";
    echo "\n";

    if ($dryRun) {
        echo "  → DRY RUN: pulando\n\n";
        continue;
    }

    try {
        if ($tipo === 'page') {
            // Landing page
            $resultado = gerarLanding($keyword, $urls, $cfg, $serper, $scraper, $claude, $wp, $builder);
        } else {
            // Post via Maquina
            $maq = new Maquina($serper, $scraper, $claude, $wp, $cfg);
            $resultado = $maq->rodar($keyword, [$formato], [], $urls);
            $primeiro = $resultado['resultados'][0] ?? null;
            if ($primeiro && ($primeiro['ok'] ?? false)) {
                $resultado = $primeiro;
            } else {
                throw new RuntimeException($primeiro['erro'] ?? 'Erro desconhecido');
            }
        }

        $pid = $resultado['post_id'] ?? $resultado['page_id'] ?? '?';
        $titulo = $resultado['titulo'] ?? $keyword;
        echo "  ✅ #{$pid} — {$titulo}\n";

        // Instant Indexing — Discover ama frescor + indexação imediata.
        // Falha silenciosa (não bloqueia geração).
        $linkPost = $resultado['link'] ?? $resultado['url'] ?? null;
        if (!$linkPost && $pid !== '?') {
            try { $p = $wp->getPost((int)$pid); $linkPost = $p['link'] ?? null; } catch (Throwable $e) { /* skip */ }
        }
        if ($linkPost) {
            try {
                $idx = new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
                $ix = $idx->indexar($linkPost, 'URL_UPDATED');
                echo $ix['success']
                    ? "  📤 Indexado (" . ($ix['method'] ?? '?') . ")\n\n"
                    : "  ⚠️  Indexação falhou: " . ($ix['error'] ?? '?') . "\n\n";
            } catch (Throwable $e) {
                echo "  ⚠️  InstantIndexing exception: " . $e->getMessage() . "\n\n";
            }
        } else {
            echo "  (sem link pra indexar)\n\n";
        }

        $gerados[] = ['id' => $pid, 'keyword' => $keyword, 'title' => $titulo];

    } catch (Throwable $e) {
        echo "  ❌ " . $e->getMessage() . "\n\n";
        $erros[] = ['keyword' => $keyword, 'erro' => $e->getMessage()];
    }

    if ($n < $total) sleep($delay);
}

// ── Resumo ──
echo "\n════════════════════════════════════════════\n";
echo "RESUMO: " . count($gerados) . " gerados, " . count($erros) . " erros\n";
echo "════════════════════════════════════════════\n\n";

// ── Interligação automática ──
if (!empty($gerados) && !$dryRun) {
    echo "🔗 Rodando interligação automática...\n";
    $interligados = interligar($wp, $gerados);
    echo "  ✅ {$interligados} posts interligados\n";
}

echo "\nFinalizado.\n";

/* ══════════════════ Funções ══════════════════ */

function gerarLanding(string $keyword, array $urls, array $cfg, Serper $serper, Scraper $scraper, Claude $claude, Wordpress $wp, LandingBuilder $builder): array
{
    $fontes = [];

    // URLs diretas
    foreach ($urls as $url) {
        if (!preg_match('#^https?://#', $url)) continue;
        try {
            $dados = $scraper->fetch($url);
            if (count($dados['content']['paragraphs']) >= 2) $fontes[] = $dados;
        } catch (Throwable $e) { /* pula */ }
    }

    // SERP
    if ($keyword !== '') {
        try {
            $serp = $serper->search($keyword, $cfg['scrape_max_try']);
            foreach (($serp['organic'] ?? []) as $r) {
                if (count($fontes) >= $cfg['scrape_top_n']) break;
                $url = $r['link'] ?? '';
                if (!$url) continue;
                try {
                    $dados = $scraper->fetch($url);
                    if (count($dados['content']['paragraphs']) >= 3) $fontes[] = $dados;
                } catch (Throwable $e) { /* pula */ }
            }
        } catch (Throwable $e) { /* pula */ }
    }

    if (empty($fontes)) throw new RuntimeException('Nenhuma fonte obtida');

    $landing = $claude->gerarLanding($keyword, [], $fontes, []);

    // Upload imagens
    foreach (($landing['products'] ?? []) as $idx => &$prod) {
        $imgUrl = $prod['image'] ?? '';
        if ($imgUrl === '' || !preg_match('#^https?://#', $imgUrl)) continue;
        try {
            $mediaId = $wp->uploadImagemPorUrl($imgUrl, $prod['name'] ?? '');
            if ($mediaId) {
                $media = $wp->getMedia($mediaId);
                $prod['image'] = $media['source_url'] ?? $imgUrl;
                $prod['wp_media_id'] = $mediaId;
            }
        } catch (Throwable $e) { /* pula */ }
    }
    unset($prod);

    // Pretty Links (products + alt_stores + decision_block picks)
    if (!empty($cfg['pretty_links'])) {
        try {
            $pl = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
            $prefix = $cfg['pretty_links_prefix'] ?? 'go';
            foreach (($landing['products'] ?? []) as &$prod) {
                $affUrl = $prod['affiliate_url'] ?? '';
                if ($affUrl !== '' && preg_match('#^https?://#', $affUrl)) {
                    $slug = PrettyLinks::slugify($prod['name'] ?? 'produto', $prefix);
                    try { $purl = $pl->criarOuBuscar($affUrl, $slug, $prod['name'] ?? '', true, '301'); if ($purl) $prod['affiliate_url'] = $purl; } catch (Throwable $e) {}
                }
                if (!empty($prod['alt_stores'])) {
                    foreach ($prod['alt_stores'] as &$alt) {
                        $altUrl = $alt['url'] ?? '';
                        if ($altUrl !== '' && preg_match('#^https?://#', $altUrl)) {
                            $altSlug = PrettyLinks::slugify(($prod['name'] ?? '') . ' ' . ($alt['store'] ?? ''), $prefix);
                            try { $ap = $pl->criarOuBuscar($altUrl, $altSlug, ($prod['name'] ?? '') . ' - ' . ($alt['store'] ?? ''), true, '301'); if ($ap) $alt['url'] = $ap; } catch (Throwable $e) {}
                        }
                    }
                    unset($alt);
                }
            }
            unset($prod);
            if (!empty($landing['decision_block']['picks'])) {
                foreach ($landing['decision_block']['picks'] as &$pick) {
                    $pickUrl = $pick['affiliate_url'] ?? '';
                    if ($pickUrl !== '' && preg_match('#^https?://#', $pickUrl)) {
                        $pickSlug = PrettyLinks::slugify($pick['product_name'] ?? 'produto', $prefix);
                        try { $pp = $pl->criarOuBuscar($pickUrl, $pickSlug, $pick['product_name'] ?? '', true, '301'); if ($pp) $pick['affiliate_url'] = $pp; } catch (Throwable $e) {}
                    }
                }
                unset($pick);
            }
        } catch (Throwable $e) { /* silencia */ }
    }

    $contentFinal = $builder->buildHtml($landing);

    // Relacionados
    $searchTerm = $landing['focus_keyword'] ?? $keyword;
    if ($searchTerm !== '') {
        try {
            $relacionados = $wp->buscarRelacionados($searchTerm, 6);
            if (!empty($relacionados)) {
                $contentFinal .= montarRelacionadosCli($relacionados);
            }
        } catch (Throwable $e) { /* pula */ }
    }

    $contentFinal .= $builder->buildFaqHtml($landing['faq'] ?? []);
    $contentFinal .= $builder->buildSchemas($landing);

    $featuredId = null;
    foreach (($landing['products'] ?? []) as $lp) {
        if (!empty($lp['wp_media_id'])) { $featuredId = $lp['wp_media_id']; break; }
    }

    $titulo = $landing['title'] ?? $keyword;
    $payload = [
        'title'   => $titulo,
        'slug'    => $landing['slug'] ?? null,
        'content' => $contentFinal,
        'excerpt' => $landing['excerpt'] ?? '',
        'status'  => 'draft',
        'meta'    => [
            'rank_math_title'           => $landing['meta_title'] ?? $titulo,
            'rank_math_description'     => $landing['meta_description'] ?? '',
            'rank_math_focus_keyword'   => $landing['focus_keyword'] ?? $keyword,
        ],
    ];
    if ($featuredId) $payload['featured_media'] = $featuredId;

    $page = $wp->criarPagina($payload);

    return [
        'page_id' => $page['id'] ?? null,
        'titulo'  => $titulo,
    ];
}

function montarRelacionadosCli(array $posts): string
{
    if (empty($posts)) return '';
    $html = '<h2>Leia também</h2>';
    foreach ($posts as $p) {
        $titulo = htmlspecialchars($p['title']);
        $link   = htmlspecialchars($p['link']);
        $img    = htmlspecialchars($p['image']);
        $imgTag = $img !== '' ? "<img width=\"300\" height=\"225\" src=\"{$img}\" alt=\"{$titulo}\" loading=\"lazy\" decoding=\"async\">" : '';
        $html .= "<article class=\"cc-bento__side cc-card cc-card--horizontal cc-fade-in is-visible\">"
            . "<a href=\"{$link}\" class=\"cc-card__thumb\" aria-hidden=\"true\" tabindex=\"-1\">{$imgTag}</a>"
            . "<div class=\"cc-card__body\">"
            . "<h3 class=\"cc-card__title\"><a href=\"{$link}\">{$titulo}</a></h3>"
            . "</div></article>";
    }
    return $html;
}

/**
 * Interligação automática — pra cada post gerado, busca 3-5 posts
 * relacionados e injeta links no conteúdo.
 */
function interligar(Wordpress $wp, array $gerados): int
{
    $count = 0;
    foreach ($gerados as $post) {
        $pid = $post['id'];
        $keyword = $post['keyword'];

        try {
            // Busca posts relacionados (excluindo o próprio)
            $relacionados = $wp->buscarRelacionados($keyword, 5, (int)$pid);
            if (empty($relacionados)) continue;

            // Busca conteúdo atual do post
            $postData = $wp->getPost($pid);
            $content = $postData['content']['raw'] ?? $postData['content']['rendered'] ?? '';
            if ($content === '') continue;

            // Monta bloco de links internos
            $linksHtml = '<h3>Conteúdo relacionado</h3><ul>';
            foreach ($relacionados as $rel) {
                $t = htmlspecialchars($rel['title']);
                $l = htmlspecialchars($rel['link']);
                $linksHtml .= "<li><a href=\"{$l}\">{$t}</a></li>";
            }
            $linksHtml .= '</ul>';

            // Injeta antes do último </div> ou no final
            if (str_contains($content, '<h2>Perguntas frequentes</h2>')) {
                $content = str_replace('<h2>Perguntas frequentes</h2>', $linksHtml . '<h2>Perguntas frequentes</h2>', $content);
            } else {
                $content .= $linksHtml;
            }

            // Atualiza o post
            $wp->atualizarPost($pid, ['content' => $content]);
            $count++;

        } catch (Throwable $e) {
            // Silencia — interligação é best-effort
        }
    }
    return $count;
}
