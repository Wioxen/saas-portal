<?php
/**
 * Gerador de Páginas WP (landing/review) — alta conversão.
 *
 * 4 fontes de dados (todas opcionais, pelo menos 1 obrigatória):
 *  1. URLs diretas → scrapeia e extrai conteúdo
 *  2. Keyword SERP → busca no Google, scrapeia top 5
 *  3. JSON de produtos → dados manuais
 *  4. Keyword de contexto → direciona o Claude (sem buscar nada)
 *
 * Saída: página WP como draft, HTML semântico puro (sem CSS).
 */
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
require_once __DIR__ . '/lib/PrettyLinks.php';

$cfg = require_once __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$resultado = null;
$erro = null;
$logLines = [];

function addLog(string $msg, array &$log): void { $log[] = $msg; }

/** Monta "Leia também" usando cc-card--horizontal do tema. */
function montarRelacionados(array $posts): string
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keyword     = trim($_POST['keyword'] ?? '');
    $urlsRaw     = trim($_POST['urls'] ?? '');
    $serpKeyword = trim($_POST['serp_keyword'] ?? '');
    $jsonRaw     = trim($_POST['produtos_json'] ?? '');
    $blocos      = [];
    for ($i = 1; $i <= 8; $i++) $blocos[] = trim($_POST["bloco{$i}"] ?? '');

    // Precisa de pelo menos 1 fonte
    if ($urlsRaw === '' && $serpKeyword === '' && $jsonRaw === '' && $keyword === '') {
        $erro = 'Preencha pelo menos um campo: URL, keyword SERP, JSON de produtos ou keyword de contexto.';
    } else {
        try {
            set_time_limit(600);
            $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
            $claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
            $wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

            $fontes   = [];
            $produtos = [];

            // ── Fonte 1: URLs diretas ──
            if ($urlsRaw !== '') {
                $urls = preg_split('/[\r\n]+/', $urlsRaw);
                $urls = array_filter(array_map('trim', $urls));
                addLog('🔗 ' . count($urls) . ' URL(s) para scrapear diretamente', $logLines);

                foreach ($urls as $url) {
                    if (!preg_match('#^https?://#', $url)) {
                        addLog("  ⚠️ URL inválida, pulando: $url", $logLines);
                        continue;
                    }
                    addLog("📥 Scrapeando: $url", $logLines);
                    try {
                        $dados = $scraper->fetch($url);
                        $nParagrafos = count($dados['content']['paragraphs']);
                        if ($nParagrafos < 2) {
                            addLog("  ⚠️ Pouco conteúdo ($nParagrafos parágrafos), pulando", $logLines);
                            continue;
                        }
                        $fontes[] = $dados;
                        addLog("  ✓ ok ({$nParagrafos} parágrafos, " . count($dados['content']['headings']) . " headings)", $logLines);
                    } catch (Throwable $e) {
                        addLog('  ✗ ' . $e->getMessage(), $logLines);
                    }
                }
            }

            // ── Fonte 2: SERP keyword ──
            if ($serpKeyword !== '') {
                addLog("🔍 Buscando '$serpKeyword' no Google via Serper...", $logLines);
                $serper = new Serper($cfg['serper_api_key']);
                $serp = $serper->search($serpKeyword, $cfg['scrape_max_try']);
                $organicos = $serp['organic'] ?? [];
                addLog('  ✓ ' . count($organicos) . ' resultados orgânicos', $logLines);

                $alvo = $cfg['scrape_top_n'];
                foreach ($organicos as $r) {
                    if (count($fontes) >= $alvo + count(preg_split('/[\r\n]+/', $urlsRaw) ?: [])) break;
                    $url = $r['link'] ?? '';
                    if (!$url) continue;
                    addLog("📥 Scrapeando: $url", $logLines);
                    try {
                        $dados = $scraper->fetch($url);
                        if (count($dados['content']['paragraphs']) < 3) {
                            addLog('  ⚠️ pouco conteúdo, pulando', $logLines);
                            continue;
                        }
                        $fontes[] = $dados;
                        addLog('  ✓ ok', $logLines);
                    } catch (Throwable $e) {
                        addLog('  ✗ ' . $e->getMessage(), $logLines);
                    }
                }
                addLog('✅ Total de fontes scrapeadas: ' . count($fontes), $logLines);
            }

            // ── Fonte 3: JSON manual ──
            if ($jsonRaw !== '') {
                $produtos = json_decode($jsonRaw, true);
                if (!is_array($produtos)) {
                    throw new RuntimeException('JSON de produtos inválido. Verifique a sintaxe.');
                }
                addLog('📦 ' . count($produtos) . ' produtos do JSON manual', $logLines);
            }

            // ── Gerar dados estruturados com Claude ──
            addLog('🤖 Gerando dados com Claude...', $logLines);
            $landing = $claude->gerarLanding($keyword, $produtos, $fontes, $blocos);
            $nProducts = count($landing['products'] ?? []);
            addLog("  ✓ {$nProducts} produtos + intro + guia + FAQ", $logLines);

            // ── Upload imagens dos produtos pro WP ──
            if ($nProducts > 0) {
                addLog("🖼️ Uploadando imagens dos produtos pro WordPress...", $logLines);
                foreach ($landing['products'] as $idx => &$prod) {
                    $imgUrl = $prod['image'] ?? '';
                    if ($imgUrl === '' || !preg_match('#^https?://#', $imgUrl)) continue;
                    try {
                        $alt = $prod['name'] ?? "Produto " . ($idx + 1);
                        $mediaId = $wp->uploadImagemPorUrl($imgUrl, $alt);
                        if ($mediaId) {
                            // Pega URL da imagem no WP
                            $media = $wp->getMedia($mediaId);
                            $wpImgUrl = $media['source_url'] ?? $imgUrl;
                            $prod['image'] = $wpImgUrl;
                            $prod['wp_media_id'] = $mediaId;
                            addLog("  ✓ #{$idx}: {$alt} → media id {$mediaId}", $logLines);
                        }
                    } catch (Throwable $e) {
                        addLog("  ✗ #{$idx}: " . $e->getMessage(), $logLines);
                    }
                }
                unset($prod);
            }

            // ── Pretty Links (cria redirecionamentos pra cada produto) ──
            if (!empty($cfg['pretty_links']) && $nProducts > 0) {
                addLog('🔗 Criando Pretty Links...', $logLines);
                try {
                    $pl = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
                    $prefix = $cfg['pretty_links_prefix'] ?? 'go';
                    foreach ($landing['products'] as &$prod) {
                        $affUrl = $prod['affiliate_url'] ?? '';
                        if ($affUrl === '' || !preg_match('#^https?://#', $affUrl)) continue;
                        $slug = PrettyLinks::slugify($prod['name'] ?? 'produto', $prefix);
                        try {
                            $prettyUrl = $pl->criarOuBuscar($affUrl, $slug, $prod['name'] ?? '', true, '301');
                            if ($prettyUrl) {
                                $prod['affiliate_url'] = $prettyUrl;
                                addLog("  ✓ {$slug} → " . mb_substr($affUrl, 0, 50), $logLines);
                            }
                        } catch (Throwable $e) {
                            addLog("  ✗ {$slug}: " . $e->getMessage(), $logLines);
                        }
                        // Alt stores
                        if (!empty($prod['alt_stores'])) {
                            foreach ($prod['alt_stores'] as &$alt) {
                                $altUrl = $alt['url'] ?? '';
                                if ($altUrl === '' || !preg_match('#^https?://#', $altUrl)) continue;
                                $altStore = mb_strtolower($alt['store'] ?? 'loja');
                                $altSlug = PrettyLinks::slugify(($prod['name'] ?? '') . ' ' . $altStore, $prefix);
                                try {
                                    $altPretty = $pl->criarOuBuscar($altUrl, $altSlug, ($prod['name'] ?? '') . ' - ' . ($alt['store'] ?? ''), true, '301');
                                    if ($altPretty) $alt['url'] = $altPretty;
                                } catch (Throwable $e) {}
                            }
                            unset($alt);
                        }
                    }
                    unset($prod);
                    // Decision block picks
                    if (!empty($landing['decision_block']['picks'])) {
                        foreach ($landing['decision_block']['picks'] as &$pick) {
                            $pickUrl = $pick['affiliate_url'] ?? '';
                            if ($pickUrl === '' || !preg_match('#^https?://#', $pickUrl)) continue;
                            $pickName = $pick['product_name'] ?? 'produto';
                            $pickSlug = PrettyLinks::slugify($pickName, $prefix);
                            try {
                                $pickPretty = $pl->criarOuBuscar($pickUrl, $pickSlug, $pickName, true, '301');
                                if ($pickPretty) $pick['affiliate_url'] = $pickPretty;
                            } catch (Throwable $e) {}
                        }
                        unset($pick);
                    }
                } catch (Throwable $e) {
                    addLog('  ✗ PrettyLinks: ' . $e->getMessage(), $logLines);
                }
            }

            // ── Builder: monta HTML + Schemas a partir dos dados ──
            $builder = new LandingBuilder($cfg['site_name'] ?? 'Como Comprar', $cfg['wp_url'] ?? '', ['number' => $cfg['whatsapp_number'] ?? '', 'group_url' => $cfg['whatsapp_group_url'] ?? '', 'cta_text' => $cfg['whatsapp_cta_text'] ?? '']);

            $contentFinal = $builder->buildHtml($landing);

            // Posts relacionados — entre guia e FAQ
            $searchTerm = $landing['focus_keyword'] ?? $keyword;
            if ($searchTerm !== '') {
                addLog("🔗 Buscando posts relacionados: '$searchTerm'", $logLines);
                try {
                    $relacionados = $wp->buscarRelacionados($searchTerm, 6);
                    if (!empty($relacionados)) {
                        addLog('  ✓ ' . count($relacionados) . ' posts encontrados', $logLines);
                        $contentFinal .= montarRelacionados($relacionados);
                    }
                } catch (Throwable $e) {
                    addLog('  ✗ Relacionados: ' . $e->getMessage(), $logLines);
                }
            }

            // FAQ
            $contentFinal .= $builder->buildFaqHtml($landing['faq'] ?? []);

            // Schemas JSON-LD (Product + ItemList + FAQPage — construídos em PHP, não pelo Claude)
            $contentFinal .= $builder->buildSchemas($landing);
            addLog('  ✓ Schemas: ItemList + Product/Review/Offer/AggregateRating + FAQPage', $logLines);

            // ── Featured image (reusa media ID do primeiro produto já upado) ──
            $featuredId = null;
            foreach (($landing['products'] ?? []) as $lp) {
                if (!empty($lp['wp_media_id'])) { $featuredId = $lp['wp_media_id']; break; }
            }
            // Fallback: og:image das fontes (se não upou nenhum produto)
            if (!$featuredId) {
                $heroUrl = null;
                foreach ($fontes as $f) {
                    if (!empty($f['meta']['og_image'])) { $heroUrl = $f['meta']['og_image']; break; }
                }
                if ($heroUrl) {
                    try {
                        $featuredId = $wp->uploadImagemPorUrl($heroUrl, $landing['hero_alt'] ?? $keyword ?: 'imagem');
                    } catch (Throwable $e) { /* silencia */ }
                }
            }

            // ── Criar página no WP ──
            $titulo = $landing['title'] ?? $keyword ?: 'Nova Página';
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
                    'rank_math_facebook_title'  => $landing['meta_title'] ?? $titulo,
                    'rank_math_facebook_description' => $landing['meta_description'] ?? '',
                    'rank_math_twitter_title'   => $landing['meta_title'] ?? $titulo,
                    'rank_math_twitter_description'  => $landing['meta_description'] ?? '',
                ],
            ];
            if ($featuredId) $payload['featured_media'] = $featuredId;

            addLog('📤 Criando página (draft) no WordPress...', $logLines);
            $page = $wp->criarPagina($payload);
            $pid  = $page['id'] ?? null;
            addLog("  ✅ Página #$pid criada!", $logLines);

            $resultado = [
                'page_id'  => $pid,
                'edit_url' => rtrim($cfg['wp_url'], '/') . "/wp-admin/post.php?post=$pid&action=edit",
                'preview'  => $page['link'] ?? null,
                'titulo'   => $titulo,
                'palavras' => str_word_count(strip_tags($contentFinal)),
                'fontes'   => count($fontes),
                'produtos' => count($produtos),
                'log'      => $logLines,
            ];
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
}

$exemploJson = json_encode([
    [
        'nome'       => 'Samsung Galaxy S24 FE',
        'preco'      => 'R$ 2.699',
        'nota'       => 9.1,
        'melhor_para'=> 'Melhor geral',
        'imagem'     => 'https://url-da-imagem.jpg',
        'link'       => 'https://link-de-afiliado.com/galaxy-s24-fe',
        'loja'       => 'Amazon',
        'pros'       => ['Tela AMOLED 120Hz', 'Câmera excelente'],
        'contras'    => ['Sem carregador na caixa'],
        'por_que'    => 'Melhor equilíbrio nesta faixa.',
        'specs'      => ['Tela' => '6.7" AMOLED', 'RAM' => '8GB'],
        'para_quem'  => 'Quem quer flagship sem pagar premium.',
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Gerador de Páginas</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0a0a0f;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:960px;margin:0 auto}
h1{color:#fff;margin:0 0 4px;font-size:28px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#12141a;border:1px solid #1e2230;padding:24px;border-radius:12px;margin-bottom:16px;position:relative}
.box h2{margin-top:0;font-size:17px;color:#e0e0e0;display:flex;align-items:center;gap:8px}
.box-tag{position:absolute;top:12px;right:16px;font-size:10px;font-weight:bold;padding:3px 10px;border-radius:10px;text-transform:uppercase;letter-spacing:.5px}
.tag-opcional{background:#1a2e1a;color:#4ade80}
label{display:block;font-weight:600;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=text]{width:100%;padding:13px;background:#0a0a0f;border:1px solid #1e2230;border-radius:8px;color:#fff;font-size:15px}
input[type=text]:focus,textarea:focus{outline:none;border-color:#6366f1}
textarea{width:100%;padding:12px;background:#0a0a0f;border:1px solid #1e2230;border-radius:8px;color:#ddd;font-size:13px;font-family:inherit;resize:vertical}
.url-area{min-height:100px;font-family:'JetBrains Mono','Fira Code',monospace}
.json-area{min-height:180px;font-family:'JetBrains Mono','Fira Code',monospace}
.prompt-area{min-height:70px}
.fontes-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.blocos-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.bloco-full{grid-column:1/-1}
.bloco-header{display:flex;justify-content:space-between;align-items:center}
.bloco-header small{color:#444;font-weight:normal;font-size:11px}
button[type=submit]{margin-top:16px;padding:16px 28px;background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:bold;cursor:pointer;width:100%;transition:all .2s}
button[type=submit]:hover{transform:translateY(-1px);box-shadow:0 4px 20px rgba(239,68,68,.3)}
.erro{background:#2d1010;border:1px solid #7f1d1d;padding:14px 18px;border-radius:8px;margin-bottom:16px;color:#fca5a5}
.resultado{background:#0d1a0d;border:1px solid #166534;border-radius:12px;padding:24px;margin-bottom:16px}
.resultado h2{margin-top:0;color:#4ade80}
.resumo{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0}
.resumo-item{text-align:center;background:#0a0a0f;padding:14px;border-radius:8px;border:1px solid #1e2230}
.resumo-item strong{display:block;font-size:26px;color:#f59e0b}
.resumo-item span{font-size:11px;color:#666}
.actions{display:flex;gap:12px;margin-top:14px}
.actions a{flex:1;text-align:center;padding:12px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:14px}
.btn-edit{background:#1e3a5f;color:#60a5fa}
.btn-preview{background:#1a2e1a;color:#4ade80}
.btn-edit:hover,.btn-preview:hover{opacity:.85}
.log{background:#060810;border:1px solid #1e2230;padding:14px;border-radius:8px;font-family:monospace;font-size:11px;color:#666;max-height:350px;overflow:auto;white-space:pre-wrap;margin-top:10px}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.hint{font-size:11px;color:#444;margin-top:4px}
@media(max-width:768px){.fontes-grid{grid-template-columns:1fr}.blocos-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="container">
  <h1>🎯 Gerador de Páginas</h1>
  <p class="sub">Scrapeia URLs ou SERP, gera página WP melhor que a fonte. HTML semântico puro (seu tema cuida do CSS).</p>

  <?php if ($erro): ?>
    <div class="erro"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <?php if ($resultado): ?>
    <div class="resultado">
      <h2>Página criada como draft</h2>
      <p style="font-size:18px;color:#fff;margin:4px 0"><?= htmlspecialchars($resultado['titulo']) ?></p>
      <div class="resumo">
        <div class="resumo-item"><strong>#<?= $resultado['page_id'] ?></strong><span>Page ID</span></div>
        <div class="resumo-item"><strong><?= $resultado['palavras'] ?></strong><span>palavras</span></div>
        <div class="resumo-item"><strong><?= $resultado['fontes'] ?></strong><span>fontes scrapeadas</span></div>
        <div class="resumo-item"><strong><?= $resultado['produtos'] ?></strong><span>produtos JSON</span></div>
      </div>
      <div class="actions">
        <a class="btn-edit" href="<?= htmlspecialchars($resultado['edit_url']) ?>" target="_blank">Editar no WordPress</a>
        <?php if ($resultado['preview']): ?>
          <a class="btn-preview" href="<?= htmlspecialchars($resultado['preview']) ?>" target="_blank">Preview</a>
        <?php endif; ?>
      </div>
      <details style="margin-top:14px">
        <summary style="cursor:pointer;color:#a78bfa;font-size:13px">Ver log do pipeline</summary>
        <div class="log"><?= htmlspecialchars(implode("\n", $resultado['log'])) ?></div>
      </details>
    </div>
  <?php endif; ?>

  <form method="POST">
    <?php include __DIR__ . '/_site_select.php'; ?>

    <!-- Fontes de dados -->
    <div class="box">
      <span class="box-tag tag-opcional">preencha pelo menos 1</span>
      <h2>Fontes de dados</h2>
      <p style="color:#555;font-size:13px;margin-bottom:14px">Preencha quantas quiser. O Claude cruza tudo e gera uma página melhor que qualquer fonte.</p>

      <div class="fontes-grid">
        <!-- URLs diretas -->
        <div>
          <label>URLs para scrapear</label>
          <textarea name="urls" class="url-area" placeholder="https://exemplo.com/melhores-celulares
https://outro-site.com/review-galaxy
https://blog.com/top-smartphones-2026"><?= htmlspecialchars($_POST['urls'] ?? '') ?></textarea>
          <p class="hint">Uma URL por linha. Scrapeia título, conteúdo, headings, imagens.</p>
        </div>

        <!-- SERP keyword -->
        <div>
          <label>Keyword SERP</label>
          <input name="serp_keyword" type="text"
                 placeholder="melhores celulares ate 1500"
                 value="<?= htmlspecialchars($_POST['serp_keyword'] ?? '') ?>">
          <p class="hint">Busca no Google e scrapeia os top 5 resultados automaticamente.</p>
        </div>

        <!-- JSON manual -->
        <div>
          <label>Produtos em JSON</label>
          <textarea name="produtos_json" class="json-area" placeholder='<?= htmlspecialchars($exemploJson) ?>'><?= htmlspecialchars($_POST['produtos_json'] ?? '') ?></textarea>
          <p class="hint">Array com nome, preco, pros, contras, specs, link, etc.</p>
        </div>
      </div>
    </div>

    <!-- Keyword de contexto -->
    <div class="box">
      <span class="box-tag tag-opcional">opcional</span>
      <h2>Keyword de contexto</h2>
      <input name="keyword" type="text"
             placeholder="ex: melhores celulares ate 1500 reais 2026"
             value="<?= htmlspecialchars($_POST['keyword'] ?? '') ?>">
      <p class="hint">Direciona o foco do artigo: headline, focus keyword do RankMath, tom. Se vazio, o Claude infere do conteúdo scrapeado.</p>
    </div>

    <?php include __DIR__ . '/_blocos_inputs.php'; ?>

    <button type="submit">Gerar Página</button>
    <p style="text-align:center;font-size:12px;color:#444;margin-top:8px">
      ~30-120s dependendo das fontes selecionadas
    </p>
  </form>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:24px">
    <a href="maquina.php">Máquina de artigos</a> · <a href="gerar.php">Gerador estático</a> · <a href="sitemap.php">Sitemap</a>
  </p>
</div>
</body>
</html>
