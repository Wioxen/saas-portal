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
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/EntityPageLinker.php';
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

// Pré-definição do contexto por site (precisa estar ANTES do systemPrompt pra injetar marca)
$contextoSitePre = match ($siteSlug) {
    'cursosenac'       => ['marca' => 'CursoSenac Gratuito', 'tom' => 'jornalismo factual de serviço educacional, frases curtas, sem sensacionalismo'],
    'guiadoscursos'    => ['marca' => 'Guia dos Cursos',     'tom' => 'jornalismo educacional informativo'],
    'vagasebeneficios' => ['marca' => 'Vagas e Benefícios',  'tom' => 'jornalismo de serviço sobre vagas e auxílios, frases curtas'],
    'comocomprar'      => ['marca' => 'Como Comprar',        'tom' => 'jornalismo de consumo, foco em utilidade ao leitor'],
    'ondecompraragora' => ['marca' => 'Onde Comprar Agora',  'tom' => 'jornalismo de consumo direto, foco em onde comprar'],
    default            => ['marca' => 'Leão da Barra',       'tom' => 'jornalismo esportivo factual, frases curtas, sem clichê de torcida'],
};
$marcaPrompt = $contextoSitePre['marca'];
$tomPrompt = $contextoSitePre['tom'];

$systemPrompt = <<<EOT
Você é redator do {$marcaPrompt}. Tom: {$tomPrompt}. Acentuação portuguesa completa.

═══ REGRA ÚNICA E ABSOLUTA ═══
Cada FATO mencionado (nome de pessoa, decisão, valor, data, local) DEVE estar EXPLICITAMENTE escrito nas FONTES SCRAPEDAS abaixo.

═══ ATRIBUIÇÃO — VOZ DE AUTORIDADE PRÓPRIA ═══
NÓS somos o {$marcaPrompt}. As fontes scrapedas são INSUMOS internos da nossa apuração — NÃO citar veículos por nome no corpo.

PROIBIDO: "Segundo o [veículo] / Lance / Terra / G1 / ge.globo / [qualquer portal externo]"
USAR: "Apuração da nossa redação aponta que..." / "Levantamento da equipe do {$marcaPrompt} mostra..." / "A redação confirmou que..." / "Conforme apurado pela redação..." / "Segundo nosso acompanhamento..."

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

// Contexto por site (multi-site aware)
$contextoSite = match ($siteSlug) {
    'cursosenac' => [
        'tema' => 'cursos gratuitos, vagas em universidades e oportunidades educacionais no Brasil',
        'tom' => 'jornalismo de serviço educacional, foco em prazo de inscrição, requisitos e benefícios',
        'voz_marca' => 'redação CursoSenac Gratuito',
        'query_imagem' => 'universidade brasileira sala aula formatura',
        'cat_principal' => 'Cursos Gratuitos',
    ],
    'guiadoscursos' => [
        'tema' => 'guia de cursos, MEC, ENEM e formação acadêmica',
        'tom' => 'jornalismo educacional informativo',
        'voz_marca' => 'redação Guia dos Cursos',
        'query_imagem' => 'aluno universitário brasil',
        'cat_principal' => 'Cursos',
    ],
    'vagasebeneficios' => [
        'tema' => 'vagas de emprego, benefícios sociais e oportunidades de renda no Brasil',
        'tom' => 'jornalismo de serviço, foco em quem pode receber, como solicitar, valor',
        'voz_marca' => 'redação Vagas e Benefícios',
        'query_imagem' => 'trabalhador brasileiro escritório',
        'cat_principal' => 'Vagas',
    ],
    'comocomprar' => [
        'tema' => 'produtos, ofertas, comparativos e dicas de compra para o consumidor brasileiro',
        'tom' => 'jornalismo de consumo prático, foco em utilidade, preço justo e onde comprar',
        'voz_marca' => 'redação Como Comprar',
        'query_imagem' => 'produto consumidor compra',
        'cat_principal' => 'Ofertas',
    ],
    'ondecompraragora' => [
        'tema' => 'onde encontrar produtos, lojas confiáveis, preço comparado e prazo de entrega no Brasil',
        'tom' => 'jornalismo de consumo direto, foco em local de compra, preço e segurança',
        'voz_marca' => 'redação Onde Comprar Agora',
        'query_imagem' => 'loja online compras brasil',
        'cat_principal' => 'Onde Comprar',
    ],
    default => [ // leaodabarra
        'tema' => 'Esporte Clube Vitória, Brasileirão e Copa do Nordeste',
        'tom' => 'jornalismo esportivo factual',
        'voz_marca' => 'redação Leão da Barra',
        'query_imagem' => 'Esporte Clube Vitoria',
        'cat_principal' => 'Esporte Clube Vitória',
    ],
};

$userPrompt = "TÍTULO DA TREND CAPTURADA: {$tituloTrend}\n\n"
    . "FONTES SCRAPEDAS (cada parágrafo deve ser atribuível):\n\n"
    . $briefingFontes
    . "Escreva uma notícia sobre {$contextoSite['tema']} seguindo as regras. Saída só HTML.";

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

// Video embed automático: se título sugere vídeo, busca via Serper Videos
$videoEmbed = '';
$gatilhosVideo = ['assista', 'veja', 'vídeo', 'video', 'imagens', 'reproduz', 'gravação', 'flagra'];
$tituloLow = mb_strtolower($tituloTrend);
$querMostrarVideo = false;
foreach ($gatilhosVideo as $g) {
    if (mb_stripos($tituloLow, $g) !== false) { $querMostrarVideo = true; break; }
}
if ($querMostrarVideo && !empty($cfg['serper_api_key'])) {
    echo "→ Título sugere vídeo, buscando Serper /videos\n";
    try {
        $queryV = preg_replace('/:?\s*o que saber agora.*$/iu', '', $tituloTrend);
        $ch = curl_init('https://google.serper.dev/videos');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['q' => $queryV, 'gl' => 'br', 'hl' => 'pt-br', 'num' => 10]),
            CURLOPT_HTTPHEADER => ['X-API-KEY: ' . $cfg['serper_api_key'], 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $best = null;
        foreach (($resp['videos'] ?? []) as $v) {
            $link = (string)($v['link'] ?? '');
            if (preg_match('|youtube\.com/watch\?v=([\w-]+)|', $link, $m) || preg_match('|youtu\.be/([\w-]+)|', $link, $m)) {
                $best = ['video_id' => $m[1], 'title' => $v['title'] ?? '', 'channel' => $v['channel'] ?? '', 'link' => $link];
                break;
            }
        }
        if ($best) {
            $tit = htmlspecialchars($best['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $videoEmbed = "\n<h2>Assista ao vídeo</h2>\n"
                . "<div class='video-highlights' style='position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:20px 0;'>"
                . "<iframe src='https://www.youtube.com/embed/{$best['video_id']}' "
                . "style='position:absolute;top:0;left:0;width:100%;height:100%;border:0;' "
                . "frameborder='0' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture' "
                . "allowfullscreen title='{$tit}'></iframe></div>\n";
            echo "   ✓ Vídeo: [{$best['channel']}] {$best['title']}\n";
        } else {
            echo "   ⊘ Sem vídeo YouTube em /videos\n";
        }
    } catch (Throwable $e) { echo "   ⚠ Serper Videos: {$e->getMessage()}\n"; }
}
// Injeta embed após primeiro </p> do contentHtml (depois do lead)
if ($videoEmbed) {
    $contentHtml = preg_replace('|(</p>)|', "$1" . $videoEmbed, $contentHtml, 1) ?: $contentHtml;
}

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
// Limpa sufixos genéricos que pingo cola (esportivos pra leaodabarra reciclados pra outros sites)
$sufixosPingo = [
    '/:?\s*o que saber agora.*$/iu',
    '/:?\s*onde assistir,?\s*horário e escalação.*$/iu',
    '/:?\s*onde assistir,?\s*horário.*$/iu',
    '/:?\s*o que aconteceu e por que.*$/iu',
    '/:?\s*passo a passo.*$/iu',
    '/:?\s*qual opç[aã]o comprar.*$/iu',
    '/\s*\(atualizado\)\s*$/iu',
    '/:?\s*como participar.*?:\s*/iu', // remove prefixo "Como participar de" no início
];
foreach ($sufixosPingo as $rx) {
    $titulo = trim(preg_replace($rx, '', $titulo) ?? $titulo);
}
$titulo = mb_substr($titulo, 0, 150);
$slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $titulo))), '-');
$slug = substr($slug, 0, 70);

if ($fidelityWarn && !$asDraft) {
    echo "⚠ FIDELITY FAIL — forçando draft\n";
    $asDraft = true;
}

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// Featured upload + caption + description (rastreia URL pra evitar dupla no inline)
$featuredId = 0;
$featuredUrl = '';
$featuredFonte = '';
$featuredCredito = 'divulgação';
if (!$dryRun) {
    foreach ($ogImagesCandidatos as $ogU) {
        if (!filter_var($ogU, FILTER_VALIDATE_URL)) continue;
        $mid = (int)$wp->uploadImagemPorUrl($ogU, $titulo, '');
        if ($mid > 0) { $featuredId = $mid; $featuredUrl = $ogU; $featuredFonte = 'og'; echo "   ✓ Featured: media_id={$mid} fonte=og\n"; break; }
    }
    if ($featuredId === 0 && !empty($cfg['serper_api_key'])) {
        try {
            $sx = new SerperImages($cfg['serper_api_key']);
            $img = $sx->melhor($titulo . ' ' . $contextoSite['query_imagem'], ['min_w' => 800, 'min_h' => 400, 'credito_generico' => false]);
            if ($img) {
                $mid = (int)$wp->uploadImagemPorUrl((string)$img['imageUrl'], $titulo, '');
                if ($mid > 0) { $featuredId = $mid; $featuredUrl = (string)$img['imageUrl']; $featuredFonte = 'serper-images'; $featuredCredito = (string)($img['credito'] ?? 'divulgação'); echo "   ✓ Featured: media_id={$mid} fonte=serper-images credito={$img['credito']} score={$img['score']}\n"; }
            }
        } catch (Throwable $e) { echo "   ⚠ serper images: {$e->getMessage()}\n"; }
    }

    // Caption + description + title do attachment (SEO + acessibilidade)
    if ($featuredId > 0) {
        try {
            $captionTxt = "{$titulo} (Foto: {$featuredCredito})";
            $descTxt = "Imagem ilustrativa da matéria '{$titulo}' publicada no portal {$marcaPrompt}. " . mb_substr(strip_tags($contentHtml), 0, 200);
            $wp->atualizarMedia($featuredId, [
                'caption' => $captionTxt,
                'description' => $descTxt,
                'title' => $titulo,
                'alt_text' => $titulo,
            ]);
            echo "   ✓ Featured caption + description setados\n";
        } catch (Throwable $e) { echo "   ⚠ atualizarMedia falhou: {$e->getMessage()}\n"; }
    }
}

// Categoria — resolve via CategoryMatcher (fuzzy anti-fragmentacao)
$categoryIds = [];
if (!$dryRun) {
    $catsPropostas = [$contextoSite['cat_principal']];
    // Categorias contextuais detectadas no título (por site)
    $tlow = mb_strtolower($titulo);
    if ($siteSlug === 'leaodabarra') {
        if (mb_stripos($tlow, 'copa do brasil') !== false) $catsPropostas[] = 'Copa do Brasil';
        if (mb_stripos($tlow, 'copa do nordeste') !== false || mb_stripos($tlow, 'nordestão') !== false) $catsPropostas[] = 'Copa do Nordeste';
        if (mb_stripos($tlow, 'brasileir') !== false || mb_stripos($tlow, 'série a') !== false) $catsPropostas[] = 'Brasileirão';
        if (mb_stripos($tlow, 'stjd') !== false) $catsPropostas[] = 'STJD';
        if (mb_stripos($tlow, 'arbitr') !== false || mb_stripos($tlow, 'árbitro') !== false) $catsPropostas[] = 'Arbitragem';
    } elseif ($siteSlug === 'cursosenac') {
        if (mb_stripos($tlow, 'mestrado') !== false || mb_stripos($tlow, 'doutorado') !== false) $catsPropostas[] = 'Pós-graduação';
        if (mb_stripos($tlow, 'fies') !== false) $catsPropostas[] = 'Fies';
        if (mb_stripos($tlow, 'enem') !== false) $catsPropostas[] = 'Enem';
        if (mb_stripos($tlow, 'mec') !== false) $catsPropostas[] = 'MEC';
        if (mb_stripos($tlow, 'capes') !== false) $catsPropostas[] = 'Capes';
        if (mb_stripos($tlow, 'vestibular') !== false) $catsPropostas[] = 'Vestibular';
        if (mb_stripos($tlow, 'bolsa') !== false || mb_stripos($tlow, 'gratui') !== false) $catsPropostas[] = 'Bolsas e Gratuidade';
        if (mb_stripos($tlow, 'professor') !== false || mb_stripos($tlow, 'docente') !== false) $catsPropostas[] = 'Professores';
        if (mb_stripos($tlow, 'inscriç') !== false || mb_stripos($tlow, 'inscriçao') !== false) $catsPropostas[] = 'Inscrições Abertas';
        if (mb_stripos($tlow, 'ead') !== false || mb_stripos($tlow, 'online') !== false || mb_stripos($tlow, 'distância') !== false) $catsPropostas[] = 'EAD';
    } elseif ($siteSlug === 'vagasebeneficios') {
        if (mb_stripos($tlow, 'inss') !== false) $catsPropostas[] = 'INSS';
        if (mb_stripos($tlow, 'bolsa fam') !== false || mb_stripos($tlow, 'auxílio') !== false) $catsPropostas[] = 'Auxílios';
        if (mb_stripos($tlow, 'concurso') !== false) $catsPropostas[] = 'Concursos';
        if (mb_stripos($tlow, 'vaga') !== false) $catsPropostas[] = 'Vagas de Emprego';
    }
    try {
        $cm = new CategoryMatcher($wp, 70.0);
        $resolvido = $cm->resolverComMatch($catsPropostas);
        $categoryIds = array_values(array_filter(array_map('intval', $resolvido)));
        echo "   ✓ Categorias: " . implode(',', $categoryIds) . " (" . implode(', ', $catsPropostas) . ")\n";
    } catch (Throwable $e) { echo "   ⚠ categoria falhou: {$e->getMessage()}\n"; }
}

$payload = [
    'title' => $titulo,
    'slug' => $slug,
    'content' => $contentComSchema,
    'status' => $asDraft ? 'draft' : 'publish',
    'meta' => [
        'rank_math_title' => "{$titulo} | {$marcaPrompt}",
        'rank_math_description' => mb_substr(strip_tags($contentHtml), 0, 155),
    ],
];
if ($featuredId > 0) $payload['featured_media'] = $featuredId;
if (!empty($categoryIds)) $payload['categories'] = $categoryIds;
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

// Pós-publish: 4 enriquecimentos (inline ≠ featured + entity links + relacionados)
$htmlFinal = $contentComSchema;

// (1) Entity links: backlinks pra hubs do site (entidades)
try {
    $linker = new EntityPageLinker($wp, $siteSlug, ['entidade', 'conceito'], 3, 'publish');
    $resLinker = $linker->injetar($htmlFinal);
    if (!empty($resLinker['html']) && $resLinker['html'] !== $htmlFinal) {
        $htmlFinal = $resLinker['html'];
        $logL = $linker->getLog();
        echo "   ✓ Entity links: " . ($logL['links_inseridos'] ?? 0) . " inseridos\n";
    }
} catch (Throwable $e) { echo "   ⚠ entity links: {$e->getMessage()}\n"; }

// (2) Inline image — exclui URL da featured pra não duplicar
$urlsParaInline = array_values(array_filter($urlsScrapedasOk, function($u) use ($featuredUrl) {
    return $u !== $featuredUrl;
}));
try {
    $resInline = InlineImageInjector::injetar($htmlFinal, $urlsParaInline, $wp, 1, $titulo, $cfg);
    if (($resInline['log']['inseridas'] ?? 0) > 0) {
        $htmlFinal = $resInline['html'];
        echo "   ✓ Inline image: " . $resInline['log']['inseridas'] . " inserida (≠ featured)\n";
    } else {
        echo "   ⊘ Inline: 0 inseridas (candidatas={$resInline['log']['candidatas_encontradas']}, aprovadas={$resInline['log']['aprovadas']})\n";
    }
} catch (Throwable $e) { echo "   ⚠ inline: {$e->getMessage()}\n"; }

// (3) Posts relacionados — bloco "Veja também" no fim
try {
    // Keyword CURTA: WP search bate melhor com 1-2 palavras significativas
    $kwBusca = 'Vitória';
    if (preg_match_all('/\b([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]{3,})\b/u', $titulo, $mm)) {
        $palavras = array_values(array_filter($mm[1], fn($p) => !in_array(mb_strtolower($p), ['vitória', 'leão', 'flamengo', 'fluminense'])));
        if (!empty($palavras)) $kwBusca = (string)$palavras[0];
    }
    $kwBusca = $kwBusca ?: 'Vitória';
    $relacionados = $wp->buscarRelacionados($kwBusca, 6, $postId);
    if (count($relacionados) >= 2) {
        $blocoRel = "\n<aside class='posts-relacionados' aria-label='Posts relacionados'>\n";
        $blocoRel .= "  <h2>Veja também</h2>\n  <ul>\n";
        foreach (array_slice($relacionados, 0, 4) as $rel) {
            $titRel = htmlspecialchars(html_entity_decode((string)$rel['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $linkRel = htmlspecialchars((string)$rel['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $blocoRel .= "    <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
        }
        $blocoRel .= "  </ul>\n</aside>\n";
        // Insere antes do </script> data-newsarticle (se existir) ou no fim
        if (preg_match('/<script[^>]*data-newsarticle/', $htmlFinal)) {
            $htmlFinal = preg_replace('/(<script[^>]*data-newsarticle)/', $blocoRel . "$1", $htmlFinal, 1);
        } else {
            $htmlFinal .= $blocoRel;
        }
        echo "   ✓ Posts relacionados: " . min(4, count($relacionados)) . " links\n";
    } else {
        echo "   ⊘ Posts relacionados: " . count($relacionados) . " achados (mín 2)\n";
    }
} catch (Throwable $e) { echo "   ⚠ relacionados: {$e->getMessage()}\n"; }

// Update post WP com tudo enriquecido
if ($htmlFinal !== $contentComSchema) {
    $wp->atualizarPost($postId, ['content' => $htmlFinal]);
}

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
