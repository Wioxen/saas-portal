<?php
declare(strict_types=1);

/**
 * gerar_post_trend.php — Gera post a partir de 1 trend do pingo (notícia genérica).
 *
 * Diferente de gerar_pre_jogo (que usa BroadcastEvent + cluster), este produz
 * notícia esportiva simples a partir de uma trend capturada pelo pingo.
 * Aplica a mesma regra V4 estrita: zero invenção, zero menção a veículos no corpo,
 * voz "redação do Leão da Barra", featured Serper Images, filtro de data.
 *
 * Uso:
 *   php scripts/gerar_post_trend.php --trend-id=9546 [--draft] [--site=leaodabarra]
 *   php scripts/gerar_post_trend.php --trend-id=9546 --max-age-fontes-dias=7 --draft
 */

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$trendId = (int)($args['trend-id'] ?? 0);
$dryRun = !empty($args['dry-run']);
$siteSlug = (string)($args['site'] ?? 'leaodabarra');
$asDraft = !empty($args['draft']);
$maxAge = (int)($args['max-age-fontes-dias'] ?? 7);

if ($trendId <= 0) {
    fwrite(STDERR, "uso: php gerar_post_trend.php --trend-id=N [--draft] [--site=SLUG]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/SourceFidelityValidator.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';
require_once __DIR__ . '/../lib/SerperImages.php';
require_once __DIR__ . '/../lib/InlineImageInjector.php';
require_once __DIR__ . '/../lib/DiscoverImagemFeatured.php';
require_once __DIR__ . '/../lib/DbConnection.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Post Trend (V4 estrito) — site={$siteSlug} trend=#{$trendId}\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ── 1. Lê trend ────────────────────────────────────────────────────────────
$pdo = DbConnection::pdo();
$st = $pdo->prepare('SELECT id, titulo, pingo_link, payload, score_discover, status, categoria FROM trends WHERE id = ?');
$st->execute([$trendId]);
$trend = $st->fetch(PDO::FETCH_ASSOC);
if (!$trend) { fwrite(STDERR, "✗ trend #{$trendId} não encontrada\n"); exit(1); }

$tituloTrend = (string)$trend['titulo'];
$urlPrimaria = (string)$trend['pingo_link'];
echo "→ [1/7] Trend #{$trendId} [{$trend['score_discover']}]: {$tituloTrend}\n";
echo "  URL primária: {$urlPrimaria}\n\n";

// ── 2. Coleta URLs adicionais via Serper ───────────────────────────────────
echo "→ [2/7] Buscando fontes adicionais via Serper\n";
$urls = [];
if ($urlPrimaria) $urls[$urlPrimaria] = ['titulo' => $tituloTrend, 'q' => 'pingo'];
try {
    $serper = new Serper($cfg['serper_api_key'] ?? '');
    // Query: extrair termos chave do título da trend (corta sufixos comuns)
    $queryBase = preg_replace('/:?\s*o que saber agora.*$/iu', '', $tituloTrend);
    $r = $serper->search($queryBase, 8);
    foreach (($r['organic'] ?? []) as $o) {
        $u = (string)($o['link'] ?? '');
        if ($u !== '' && !isset($urls[$u])) {
            $urls[$u] = ['titulo' => $o['title'] ?? '', 'q' => 'serper'];
        }
    }
} catch (Throwable $e) {
    echo "   ⚠ Serper falhou: " . $e->getMessage() . "\n";
}
echo "   ✓ " . count($urls) . " URLs candidatas\n\n";

// ── 3. Scrape com filtro de data ───────────────────────────────────────────
echo "→ [3/7] Scrape (filtro {$maxAge}d)\n";
$scraper = new Scraper($cfg['user_agent'] ?? 'Mozilla/5.0', 15);
$cutoff = time() - ($maxAge * 86400);
$scraped = [];
$ogImagesCandidatos = [];
$urlsScrapedasOk = [];

foreach (array_slice(array_keys($urls), 0, 8) as $url) {
    try {
        $sc = $scraper->fetch($url);
        $titulo = (string)($sc['meta']['title'] ?? '');
        $publishedRaw = (string)($sc['meta']['published'] ?? '');
        $publishedTs = $publishedRaw ? strtotime($publishedRaw) : 0;
        $src = 'meta';
        if ($publishedTs === 0 && preg_match('#/(20\d{2})/(\d{2})/(\d{2})/#', $url, $um)) {
            $publishedTs = strtotime("{$um[1]}-{$um[2]}-{$um[3]}");
            $src = 'url-ymd';
        }
        if ($publishedTs === 0 && preg_match('#/(\d{2})-(\d{2})-(20\d{2})[/.]#', $url, $um)) {
            $publishedTs = strtotime("{$um[3]}-{$um[2]}-{$um[1]}");
            $src = 'url-dmy';
        }
        $publishedHuman = $publishedTs ? date('Y-m-d', $publishedTs) : '?';

        if ($publishedTs > 0 && $publishedTs < $cutoff) {
            $diasAtras = round((time() - $publishedTs) / 86400);
            echo "   · scrape SKIP (obsoleto {$diasAtras}d): {$url}\n";
            continue;
        }
        // Pra trend genérica, sem-data passa (notícia recente do pingo)
        $paragraphs = $sc['content']['paragraphs'] ?? [];
        $textoTopo = trim(implode("\n", array_slice($paragraphs, 0, 8)));
        if (mb_strlen($textoTopo) < 100) continue;

        $scraped[] = ['url' => $url, 'titulo' => $titulo, 'texto' => $textoTopo, 'pub' => $publishedHuman];
        $urlsScrapedasOk[] = $url;
        $og = (string)($sc['meta']['og_image'] ?? '');
        if ($og && filter_var($og, FILTER_VALIDATE_URL)) $ogImagesCandidatos[] = $og;
        echo "   · scrape OK [{$publishedHuman} via {$src}]: {$url}\n";
    } catch (Throwable $e) {
        echo "   · scrape erro: {$url} — " . $e->getMessage() . "\n";
    }
}
if (empty($scraped)) { fwrite(STDERR, "✗ zero fontes válidas após filtro\n"); exit(3); }
echo "   Total: " . count($scraped) . " fontes válidas\n\n";

// ── 4. Sonnet (V4 estrito + voz própria) ──────────────────────────────────
echo "→ [4/7] Sonnet gerando notícia\n";
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model'] ?? 'claude-sonnet-4-5-20251022');

$briefingFontes = '';
foreach ($scraped as $i => $s) {
    $briefingFontes .= "FONTE " . ($i + 1) . " [pub={$s['pub']}]: {$s['titulo']}\nURL: {$s['url']}\n{$s['texto']}\n\n---\n\n";
}

$systemPrompt = <<<EOT
Você é redator do Leão da Barra. Tom: jornalismo factual de serviço, frases curtas, sem clichê de torcida. Acentuação portuguesa completa.

═══ REGRA ÚNICA E ABSOLUTA ═══
Cada FATO mencionado (nome de pessoa, decisão, valor, data, local) DEVE estar EXPLICITAMENTE escrito nas FONTES SCRAPEDAS abaixo.

═══ ATRIBUIÇÃO — VOZ DE AUTORIDADE PRÓPRIA ═══
NÓS somos o Leão da Barra. As fontes scrapedas são INSUMOS internos da nossa apuração — NÃO citar veículos por nome no corpo.

PROIBIDO: "Segundo o ge.globo / Lance / Terra / [qualquer veículo]"
USAR: "Apuração da nossa redação aponta que..." / "Levantamento da equipe do Leão da Barra mostra..." / "A redação confirmou que..." / "Conforme apurado pela redação..." / "Segundo nosso acompanhamento..."

═══ PROIBIDO ═══
✗ Inventar nomes/números/datas que não aparecem nas fontes
✗ Especular ou inferir além do que as fontes trouxeram
✗ Citar veículos externos por nome

═══ PERMITIDO ═══
✓ Reescrever fato da fonte em PT-BR jornalístico
✓ Conectar fatos das fontes ("com X confirmado e Y declarado, ...")
✓ Conteúdo CURTO + FIDEDIGNO > longo inventado

ESTRUTURA SUGERIDA (~300-500 palavras):
1. <p> Lead 3-4 frases com o gancho principal
2. <h2>[H2 com keyword principal — não abstrato!]</h2> + parágrafos
3. <h2>[H2 contextual com 2ª keyword]</h2> + parágrafos
4. <h2>[H2 final: o que vem agora ou impacto]</h2> + parágrafos

═══ H2/H3 SEO ═══
✓ H2 deve conter PALAVRAS-CHAVE de busca real
✗ PROIBIDO H2 abstratos: "Próximos passos", "O que se sabe", "Por que está em alta", "Entenda", "O que muda"

Saída: APENAS HTML limpo (sem markdown ```). Use <p>, <h2>, <ul>, <li>, <strong>.
EOT;

$userPrompt = "TÍTULO DA TREND CAPTURADA: {$tituloTrend}\n\n"
    . "FONTES SCRAPEDAS (cada parágrafo deve ser atribuível):\n\n"
    . $briefingFontes
    . "Escreva uma notícia esportiva sobre o Esporte Clube Vitória seguindo as regras. Saída só HTML.";

$resposta = $claude->callPublic([['role' => 'user', 'content' => $userPrompt]], $systemPrompt, 2500);
$contentHtml = trim((string)($resposta['content'][0]['text'] ?? ''));
if ($contentHtml === '') { fwrite(STDERR, "✗ Sonnet vazio\n"); exit(4); }
echo "   ✓ " . str_word_count(strip_tags($contentHtml)) . " palavras\n\n";

// ── 5. Validação fidelity ──────────────────────────────────────────────────
echo "→ [5/7] Source fidelity\n";
$validator = new SourceFidelityValidator();
$fontesTexto = array_map(fn($s) => $s['texto'], $scraped);
$res = $validator->validar($contentHtml, $fontesTexto);
$fidelityWarn = ($res['severity'] ?? '') === 'fail';
echo "   severity=" . ($res['severity'] ?? '?') . " | nomes_alucinados=" . count($res['nomes_alucinados'] ?? []) . "\n\n";

// ── 6. Schema NewsArticle simples ──────────────────────────────────────────
$schemaNews = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => $tituloTrend,
    'datePublished' => date('c'),
    'inLanguage' => 'pt-BR',
];
$contentComSchema = $contentHtml . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
    . json_encode($schemaNews, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n</script>\n";

// ── 7. Featured (og→Serper→Pexels) + publish + inline ──────────────────────
$titulo = $tituloTrend;
// Limpa sufixo padrão "o que saber agora..." que pingo cola
$titulo = trim(preg_replace('/:?\s*o que saber agora.*$/iu', '', $titulo));
$titulo = mb_substr($titulo, 0, 150);
$slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $titulo))), '-');
$slug = substr($slug, 0, 70);

if ($fidelityWarn && !$asDraft) {
    echo "⚠ FIDELITY FAIL — forçando draft\n";
    $asDraft = true;
}

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$featuredId = 0;
if (!$dryRun) {
    foreach ($ogImagesCandidatos as $ogU) {
        if (!filter_var($ogU, FILTER_VALIDATE_URL)) continue;
        $mid = (int)$wp->uploadImagemPorUrl($ogU, $titulo, '');
        if ($mid > 0) { $featuredId = $mid; echo "   ✓ Featured: media_id={$mid} fonte=og\n"; break; }
    }
    if ($featuredId === 0 && !empty($cfg['serper_api_key'])) {
        try {
            $sx = new SerperImages($cfg['serper_api_key']);
            $img = $sx->melhor($titulo . ' Esporte Clube Vitoria', ['min_w' => 800, 'min_h' => 400, 'credito_generico' => false]);
            if ($img) {
                $mid = (int)$wp->uploadImagemPorUrl((string)$img['imageUrl'], $titulo, '');
                if ($mid > 0) { $featuredId = $mid; echo "   ✓ Featured: media_id={$mid} fonte=serper-images credito={$img['credito']} score={$img['score']}\n"; }
            }
        } catch (Throwable $e) { echo "   ⚠ serper images: {$e->getMessage()}\n"; }
    }
}

$payload = [
    'title' => $titulo,
    'slug' => $slug,
    'content' => $contentComSchema,
    'status' => $asDraft ? 'draft' : 'publish',
    'meta' => [
        'rank_math_title' => "{$titulo} | Leão da Barra",
        'rank_math_description' => mb_substr(strip_tags($contentHtml), 0, 155),
    ],
];
if ($featuredId > 0) $payload['featured_media'] = $featuredId;
if (!empty($cfg['default_post_author_id'])) $payload['author'] = (int)$cfg['default_post_author_id'];

if ($dryRun) {
    echo "→ [7/7] DRY-RUN\n  Título: {$titulo}\n  Status: {$payload['status']}\n";
    echo "\n--- HTML preview (1000 chars) ---\n" . substr($contentComSchema, 0, 1000) . "...\n";
    exit(0);
}

echo "→ [7/7] Publicando\n";
try {
    $r = $wp->criarPost($payload);
    $postId = (int)($r['id'] ?? 0);
    $linkPub = (string)($r['link'] ?? '');
    if ($postId === 0) throw new RuntimeException('post não criado');
    echo "   ✓ Post #{$postId} status={$payload['status']} link={$linkPub}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✗ falha publicar: {$e->getMessage()}\n");
    exit(5);
}

// Inline image
try {
    $resInline = InlineImageInjector::injetar($contentComSchema, $urlsScrapedasOk, $wp, 1, $titulo, $cfg);
    if (($resInline['log']['inseridas'] ?? 0) > 0) {
        $wp->atualizarPost($postId, ['content' => $resInline['html']]);
        echo "   ✓ Inline image: " . $resInline['log']['inseridas'] . " inserida\n";
    }
} catch (Throwable $e) { echo "   ⚠ inline: {$e->getMessage()}\n"; }

// Indexing API se publish
if ($payload['status'] === 'publish' && $linkPub) {
    try {
        $idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
        $r = $idx->notifyUrl($linkPub, 'URL_UPDATED');
        echo "   ✓ Indexing API HTTP " . ($r['http_code'] ?? '?') . "\n";
    } catch (Throwable $e) { echo "   ⚠ idx: {$e->getMessage()}\n"; }
}

// Marca trend como publicado
try {
    $st = $pdo->prepare('UPDATE trends SET status = ?, post_id = ?, url_post = ? WHERE id = ?');
    $st->execute(['publicado', $postId, $linkPub, $trendId]);
    echo "   ✓ Trend #{$trendId} marcada como publicada\n";
} catch (Throwable $e) { echo "   ⚠ update trend falhou: {$e->getMessage()}\n"; }

echo "\n═══ RESUMO ═══\n";
echo "  trend_id: {$trendId}\n";
echo "  post_id:  {$postId}\n";
echo "  status:   {$payload['status']}\n";
echo "  link:     {$linkPub}\n";
echo "  fontes:   " . count($scraped) . "\n";
echo "  fidelity: " . ($fidelityWarn ? 'fail' : 'ok') . "\n";
exit(0);
