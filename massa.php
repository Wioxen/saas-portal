<?php
/**
 * Gerador em massa — versão web.
 * Recebe keywords via textarea, gera posts/páginas em sequência.
 * Após gerar, roda interligação automática.
 */
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/Maquina.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
require_once __DIR__ . '/lib/PrettyLinks.php';

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

/** Monta "Leia também" ricos (cc-card horizontal) a partir dos relacionados do WP. */
function montarRelacionadosMassa(array $posts): string
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

$resultados = [];
$erro = null;
$totalGerados = 0;
$totalErros = 0;
$interligados = 0;
$processado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $processado = true;
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $raw = trim($_POST['keywords'] ?? '');
    $formato = $_POST['formato'] ?? 'seo';
    $tipo = $_POST['tipo'] ?? 'post';
    $formatos = $_POST['formatos'] ?? [$formato];

    // Blocos de prompt (8 universais)
    $blocos = [];
    for ($b = 1; $b <= 8; $b++) $blocos[] = trim($_POST["bloco{$b}"] ?? '');

    if ($raw === '') {
        $erro = 'Cole pelo menos uma keyword.';
    } else {
        $linhas = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));

        $serper  = new Serper($cfg['serper_api_key']);
        $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
        $claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
        $wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $builder = new LandingBuilder($cfg['site_name'] ?? 'Como Comprar', $cfg['wp_url'] ?? '', ['number' => $cfg['whatsapp_number'] ?? '', 'group_url' => $cfg['whatsapp_group_url'] ?? '', 'cta_text' => $cfg['whatsapp_cta_text'] ?? '']);

        $gerados = [];

        foreach ($linhas as $i => $linha) {
            $parts = array_map('trim', explode('|', $linha));
            $keyword = $parts[0];
            $urls = array_filter(array_slice($parts, 1));

            $r = ['keyword' => $keyword, 'ok' => false, 'msg' => '', 'id' => null, 'titulo' => ''];

            try {
                if ($tipo === 'page') {
                    // Landing page
                    $fontes = [];
                    foreach ($urls as $url) {
                        if (!preg_match('#^https?://#', $url)) continue;
                        try {
                            $dados = $scraper->fetch($url);
                            if (count($dados['content']['paragraphs']) >= 2) $fontes[] = $dados;
                        } catch (Throwable $e) {}
                    }
                    if ($keyword !== '') {
                        try {
                            $serp = $serper->search($keyword, $cfg['scrape_max_try']);
                            foreach (($serp['organic'] ?? []) as $org) {
                                if (count($fontes) >= $cfg['scrape_top_n']) break;
                                $u = $org['link'] ?? '';
                                if (!$u) continue;
                                try {
                                    $d = $scraper->fetch($u);
                                    if (count($d['content']['paragraphs']) >= 3) $fontes[] = $d;
                                } catch (Throwable $e) {}
                            }
                        } catch (Throwable $e) {}
                    }
                    if (empty($fontes)) throw new RuntimeException('Nenhuma fonte');

                    $landing = $claude->gerarLanding($keyword, [], $fontes, $blocos);

                    // Upload imagens
                    foreach (($landing['products'] ?? []) as $idx => &$prod) {
                        $imgUrl = $prod['image'] ?? '';
                        if ($imgUrl === '' || !preg_match('#^https?://#', $imgUrl)) continue;
                        try {
                            $mid = $wp->uploadImagemPorUrl($imgUrl, $prod['name'] ?? '');
                            if ($mid) { $media = $wp->getMedia($mid); $prod['image'] = $media['source_url'] ?? $imgUrl; $prod['wp_media_id'] = $mid; }
                        } catch (Throwable $e) {}
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
                        } catch (Throwable $e) {}
                    }

                    $content = $builder->buildHtml($landing);

                    // Posts relacionados ricos (cc-card) — entre builder e FAQ
                    $searchTerm = $landing['focus_keyword'] ?? $keyword;
                    if ($searchTerm !== '') {
                        try {
                            $relacionados = $wp->buscarRelacionados($searchTerm, 6);
                            if (!empty($relacionados)) $content .= montarRelacionadosMassa($relacionados);
                        } catch (Throwable $e) {}
                    }

                    $content .= $builder->buildFaqHtml($landing['faq'] ?? []);
                    $content .= $builder->buildSchemas($landing);

                    // Featured image — primeiro produto upado, fallback og:image das fontes
                    $featuredId = null;
                    foreach (($landing['products'] ?? []) as $lp) { if (!empty($lp['wp_media_id'])) { $featuredId = $lp['wp_media_id']; break; } }
                    if (!$featuredId) {
                        $heroUrl = null;
                        foreach ($fontes as $f) {
                            if (!empty($f['meta']['og_image'])) { $heroUrl = $f['meta']['og_image']; break; }
                        }
                        if ($heroUrl) {
                            try { $featuredId = $wp->uploadImagemPorUrl($heroUrl, $landing['hero_alt'] ?? $keyword ?: 'imagem'); } catch (Throwable $e) {}
                        }
                    }

                    $titulo = $landing['title'] ?? $keyword;
                    $payload = [
                        'title'   => $titulo,
                        'slug'    => $landing['slug'] ?? null,
                        'content' => $content,
                        'excerpt' => $landing['excerpt'] ?? '',
                        'status'  => 'draft',
                        'meta'    => [
                            'rank_math_title'                => $landing['meta_title'] ?? $titulo,
                            'rank_math_description'          => $landing['meta_description'] ?? '',
                            'rank_math_focus_keyword'        => $landing['focus_keyword'] ?? $keyword,
                            'rank_math_facebook_title'       => $landing['meta_title'] ?? $titulo,
                            'rank_math_facebook_description' => $landing['meta_description'] ?? '',
                            'rank_math_twitter_title'        => $landing['meta_title'] ?? $titulo,
                            'rank_math_twitter_description'  => $landing['meta_description'] ?? '',
                        ],
                    ];
                    if ($featuredId) $payload['featured_media'] = $featuredId;

                    $page = $wp->criarPagina($payload);
                    $r['ok'] = true;
                    $r['id'] = $page['id'] ?? null;
                    $r['titulo'] = $titulo;
                    $r['msg'] = "Página #{$r['id']} criada";
                    $r['edit'] = rtrim($cfg['wp_url'], '/') . "/wp-admin/post.php?post={$r['id']}&action=edit";

                } else {
                    // Post via Maquina
                    $maq = new Maquina($serper, $scraper, $claude, $wp, $cfg);
                    $res = $maq->rodar($keyword, $formatos, $blocos, $urls);
                    $primeiro = $res['resultados'][0] ?? null;
                    if ($primeiro && ($primeiro['ok'] ?? false)) {
                        $r['ok'] = true;
                        $r['id'] = $primeiro['post_id'];
                        $r['titulo'] = $primeiro['titulo'];
                        $r['msg'] = "Post #{$r['id']} criado";
                        $r['edit'] = $primeiro['edit_url'] ?? '';
                    } else {
                        throw new RuntimeException($primeiro['erro'] ?? 'Erro desconhecido');
                    }
                }

                $totalGerados++;
                if ($r['id']) $gerados[] = ['id' => $r['id'], 'keyword' => $keyword, 'title' => $r['titulo']];

            } catch (Throwable $e) {
                $r['msg'] = $e->getMessage();
                $totalErros++;
            }

            $resultados[] = $r;
            if ($i < count($linhas) - 1) sleep(3);
        }

        // Interligação automática
        if (!empty($gerados)) {
            foreach ($gerados as $g) {
                try {
                    $pid = $g['id'];
                    $postData = $wp->getPost($pid);
                    $content = $postData['content']['raw'] ?? '';
                    if ($content === '' || str_contains($content, 'Conteúdo relacionado')) continue;

                    $relacionados = $wp->buscarRelacionados($g['keyword'], 5, (int)$pid);
                    if (empty($relacionados)) continue;

                    $linksHtml = '<h3>Conteúdo relacionado</h3><ul>';
                    foreach ($relacionados as $rel) {
                        $t = htmlspecialchars(strip_tags(html_entity_decode($rel['title'])));
                        $l = htmlspecialchars($rel['link']);
                        $linksHtml .= "<li><a href=\"{$l}\">{$t}</a></li>";
                    }
                    $linksHtml .= '</ul>';

                    if (str_contains($content, '<h2>Perguntas frequentes</h2>')) {
                        $content = str_replace('<h2>Perguntas frequentes</h2>', $linksHtml . '<h2>Perguntas frequentes</h2>', $content);
                    } else {
                        $content .= $linksHtml;
                    }

                    $wp->atualizarPost($pid, ['content' => $content]);
                    $interligados++;
                } catch (Throwable $e) {}
            }
        }
    }
}

$fmtInfo = Claude::$formatos;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Gerador em Massa</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:960px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:24px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px;color:#e0e0e0}
label{display:block;font-weight:bold;margin:10px 0 6px;font-size:13px;color:#bbb}
textarea{width:100%;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:13px;font-family:'JetBrains Mono',monospace;min-height:200px;resize:vertical}
textarea:focus{outline:none;border-color:#6366f1}
select{padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:14px}
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:end}
.row>div{flex:1;min-width:150px}
button[type=submit]{margin-top:16px;padding:14px 28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;width:100%}
button[type=submit]:hover{opacity:0.9}
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5}
.resumo{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:14px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:24px;color:#a78bfa}
.resumo-item span{font-size:11px;color:#666}
.result{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:12px 16px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.result.ok{border-left:4px solid #22c55e}
.result.fail{border-left:4px solid #ef4444}
.result-info{flex:1}
.result-kw{font-weight:700;color:#e0e0e0;font-size:14px}
.result-msg{font-size:12px;color:#888;margin-top:2px}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.hint{font-size:11px;color:#444;margin-top:4px}
.formatos-bar{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.fmt-check{display:flex;align-items:center;gap:6px;background:#0f1115;border:2px solid #2a2e38;border-radius:8px;padding:8px 14px;cursor:pointer}
.fmt-check input{accent-color:#6366f1}
.fmt-check span{font-size:13px;color:#888;font-weight:600}
</style>
</head>
<body>
<div class="container">
  <h1>Gerador em Massa</h1>
  <p class="sub">Cole keywords (uma por linha), escolha formato e tipo. Gera tudo + interliga automaticamente.</p>

  <?php if ($erro): ?>
    <div class="erro"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <?php if ($processado && !$erro): ?>
    <div class="box">
      <h2>Resultado</h2>
      <div class="resumo">
        <div class="resumo-item"><strong><?= $totalGerados ?></strong><span>gerados</span></div>
        <div class="resumo-item"><strong><?= $totalErros ?></strong><span>erros</span></div>
        <div class="resumo-item"><strong><?= $interligados ?></strong><span>interligados</span></div>
      </div>
      <?php foreach ($resultados as $r): ?>
        <div class="result <?= $r['ok'] ? 'ok' : 'fail' ?>">
          <div class="result-info">
            <div class="result-kw"><?= htmlspecialchars($r['keyword']) ?></div>
            <div class="result-msg"><?= htmlspecialchars($r['msg']) ?><?php if (!empty($r['titulo']) && $r['titulo'] !== $r['keyword']): ?> — <?= htmlspecialchars($r['titulo']) ?><?php endif; ?></div>
          </div>
          <?php if ($r['ok'] && !empty($r['edit'])): ?>
            <a href="<?= htmlspecialchars($r['edit']) ?>" target="_blank" style="font-size:13px">Editar →</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST">
    <?php include __DIR__ . '/_site_select.php'; ?>
    <div class="box">
      <h2>Keywords</h2>
      <p class="hint" style="margin-bottom:8px">Uma por linha. Formato: <code>keyword</code> ou <code>keyword|url1|url2</code></p>
      <textarea name="keywords" placeholder="melhores fones de ouvido bluetooth 2026
melhores celulares ate 1500 reais
melhores notebooks para estudar|https://site.com/review
melhor smartwatch custo beneficio
melhores perfumes masculinos ate 200"><?= htmlspecialchars($_POST['keywords'] ?? '') ?></textarea>
    </div>

    <div class="box">
      <h2>Configuração</h2>
      <div class="row">
        <div>
          <label>Tipo de saída</label>
          <select name="tipo">
            <option value="post" <?= ($_POST['tipo'] ?? '') === 'post' ? 'selected' : '' ?>>Post (artigo)</option>
            <option value="page" <?= ($_POST['tipo'] ?? '') === 'page' ? 'selected' : '' ?>>Página (landing)</option>
          </select>
        </div>
      </div>
      <label style="margin-top:14px">Formatos (pra posts)</label>
      <div class="formatos-bar">
        <?php foreach ($fmtInfo as $key => $f): ?>
          <label class="fmt-check">
            <input type="checkbox" name="formatos[]" value="<?= $key ?>" <?= in_array($key, $_POST['formatos'] ?? ['seo']) ? 'checked' : '' ?>>
            <span><?= $f['nome'] ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Pra páginas (landing), o formato é ignorado — usa o pipeline de review.</p>
    </div>

    <?php include __DIR__ . '/_blocos_inputs.php'; ?>

    <button type="submit">Gerar tudo</button>
    <p class="hint" style="text-align:center;margin-top:8px">~30-90s por keyword. Interligação roda automaticamente no final.</p>
  </form>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:20px">
    <a href="maquina.php">Máquina unitária</a> · <a href="landing.php">Landing page</a> · <a href="interligar.php" target="_blank">Interligar (CLI)</a>
  </p>
</div>
</body>
</html>
