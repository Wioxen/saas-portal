<?php
/**
 * Gerador de Categorias Completas.
 *
 * Você diz o nicho (ex: "fones de ouvido") e ele:
 *  1. Gera 30+ variações de keywords automaticamente
 *  2. Permite editar/remover antes de gerar
 *  3. Gera tudo em massa (post ou página)
 *  4. Interliga automaticamente
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

$keywords = [];
$resultados = [];
$erro = null;
$fase = $_POST['fase'] ?? 'gerar_keywords';
$nicho = trim($_POST['nicho'] ?? '');

// Templates de keywords por tipo
function gerarKeywords(string $nicho, string $ano): array
{
    $n = $nicho;
    $ns = rtrim($nicho, 's'); // singular aproximado

    $kws = [];

    // Melhores + faixas de preço
    $faixas = [50, 100, 150, 200, 300, 500, 1000, 1500, 2000, 3000, 5000];
    foreach ($faixas as $f) {
        $kws[] = ['kw' => "melhores {$n} até {$f} reais {$ano}", 'tipo' => 'page', 'formato' => 'seo', 'cat' => 'Faixa de preço'];
    }

    // Melhores + uso/perfil
    $usos = [
        'para corrida', 'para academia', 'para home office', 'para jogos',
        'para viagem', 'para estudar', 'para trabalhar', 'para dormir',
        'para presente', 'para criança', 'para idoso', 'para iniciantes',
    ];
    foreach ($usos as $u) {
        $kws[] = ['kw' => "melhores {$n} {$u} {$ano}", 'tipo' => 'page', 'formato' => 'seo', 'cat' => 'Uso/perfil'];
    }

    // Melhores + atributo
    $attrs = [
        'bluetooth', 'sem fio', 'com fio', 'com cancelamento de ruído',
        'com microfone', 'custo benefício', 'baratos', 'premium',
        'importados', 'nacionais', 'mais vendidos', 'profissionais',
    ];
    foreach ($attrs as $a) {
        $kws[] = ['kw' => "melhores {$n} {$a} {$ano}", 'tipo' => 'page', 'formato' => 'seo', 'cat' => 'Atributo'];
    }

    // Melhores + tipo/formato (genérico)
    $kws[] = ['kw' => "melhores {$n} {$ano}", 'tipo' => 'page', 'formato' => 'serp', 'cat' => 'Principal'];
    $kws[] = ['kw' => "melhores {$n} {$ano} qual comprar", 'tipo' => 'page', 'formato' => 'seo', 'cat' => 'Principal'];
    $kws[] = ['kw' => "top 10 {$n} {$ano}", 'tipo' => 'page', 'formato' => 'discover', 'cat' => 'Principal'];
    $kws[] = ['kw' => "ranking {$n} {$ano}", 'tipo' => 'post', 'formato' => 'discover', 'cat' => 'Principal'];

    // Informacionais / "é bom?"
    $kws[] = ['kw' => "como escolher {$ns}", 'tipo' => 'post', 'formato' => 'seo', 'cat' => 'Guia'];
    $kws[] = ['kw' => "o que observar antes de comprar {$ns}", 'tipo' => 'post', 'formato' => 'seo', 'cat' => 'Guia'];
    $kws[] = ['kw' => "{$ns} barato é bom", 'tipo' => 'post', 'formato' => 'discover', 'cat' => 'Guia'];
    $kws[] = ['kw' => "{$ns} caro vale a pena", 'tipo' => 'post', 'formato' => 'discover', 'cat' => 'Guia'];
    $kws[] = ['kw' => "quanto custa um bom {$ns} em {$ano}", 'tipo' => 'post', 'formato' => 'seo', 'cat' => 'Guia'];

    // VS comparativos
    $kws[] = ['kw' => "melhor marca de {$n} {$ano}", 'tipo' => 'post', 'formato' => 'news', 'cat' => 'Comparativo'];

    // Remove duplicatas
    $seen = [];
    $unique = [];
    foreach ($kws as $k) {
        $key = mb_strtolower($k['kw']);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $k;
        }
    }

    return $unique;
}

// Fase 2: gerar os posts/páginas
if ($fase === 'publicar' && !empty($_POST['keywords_json'])) {
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $keywords = json_decode($_POST['keywords_json'], true) ?: [];

    // Blocos de prompt (opcionais)
    $blocos = [];
    for ($b = 1; $b <= 8; $b++) {
        $blocos[] = trim($_POST["bloco{$b}"] ?? '');
    }

    $serper  = new Serper($cfg['serper_api_key']);
    $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
    $claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
    $wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    $builder = new LandingBuilder($cfg['site_name'] ?? 'Como Comprar', $cfg['wp_url'] ?? '', ['number' => $cfg['whatsapp_number'] ?? '', 'group_url' => $cfg['whatsapp_group_url'] ?? '', 'cta_text' => $cfg['whatsapp_cta_text'] ?? '']);

    $gerados = [];

    foreach ($keywords as $i => $item) {
        $kw = $item['kw'] ?? '';
        $tipo = $item['tipo'] ?? 'post';
        if ($kw === '') continue;

        $formato = $item['formato'] ?? 'seo';
        $r = ['kw' => $kw, 'tipo' => $tipo, 'formato' => $formato, 'ok' => false, 'msg' => '', 'id' => null, 'edit' => ''];

        try {
            if ($tipo === 'page') {
                // Landing page
                $fontes = [];
                try {
                    $serp = $serper->search($kw, $cfg['scrape_max_try']);
                    foreach (($serp['organic'] ?? []) as $org) {
                        if (count($fontes) >= $cfg['scrape_top_n']) break;
                        $u = $org['link'] ?? '';
                        if (!$u) continue;
                        try { $d = $scraper->fetch($u); if (count($d['content']['paragraphs']) >= 3) $fontes[] = $d; } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) {}
                if (empty($fontes)) throw new RuntimeException('Sem fontes');

                $landing = $claude->gerarLanding($kw, [], $fontes, $blocos);

                // Upload imagens
                foreach (($landing['products'] ?? []) as &$prod) {
                    $imgUrl = $prod['image'] ?? '';
                    if ($imgUrl && preg_match('#^https?://#', $imgUrl)) {
                        try { $mid = $wp->uploadImagemPorUrl($imgUrl, $prod['name'] ?? ''); if ($mid) { $media = $wp->getMedia($mid); $prod['image'] = $media['source_url'] ?? $imgUrl; $prod['wp_media_id'] = $mid; } } catch (Throwable $e) {}
                    }
                }
                unset($prod);

                // Pretty Links — products + decision_block + alt_stores
                if (!empty($cfg['pretty_links'])) {
                    try {
                        $pl = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
                        $prefix = $cfg['pretty_links_prefix'] ?? 'go';

                        // Products
                        foreach (($landing['products'] ?? []) as &$prod) {
                            $affUrl = $prod['affiliate_url'] ?? '';
                            if ($affUrl !== '' && preg_match('#^https?://#', $affUrl)) {
                                $slug = PrettyLinks::slugify($prod['name'] ?? 'produto', $prefix);
                                try { $purl = $pl->criarOuBuscar($affUrl, $slug, $prod['name'] ?? ''); if ($purl) $prod['affiliate_url'] = $purl; } catch (Throwable $e) {}
                            }
                            // Alt stores
                            if (!empty($prod['alt_stores'])) {
                                foreach ($prod['alt_stores'] as &$alt) {
                                    $altUrl = $alt['url'] ?? '';
                                    if ($altUrl !== '' && preg_match('#^https?://#', $altUrl)) {
                                        $altSlug = PrettyLinks::slugify(($prod['name'] ?? '') . ' ' . ($alt['store'] ?? ''), $prefix);
                                        try { $ap = $pl->criarOuBuscar($altUrl, $altSlug, ''); if ($ap) $alt['url'] = $ap; } catch (Throwable $e) {}
                                    }
                                }
                                unset($alt);
                            }
                        }
                        unset($prod);

                        // Decision block
                        if (!empty($landing['decision_block']['picks'])) {
                            foreach ($landing['decision_block']['picks'] as &$pick) {
                                $pickUrl = $pick['affiliate_url'] ?? '';
                                if ($pickUrl !== '' && preg_match('#^https?://#', $pickUrl)) {
                                    $pickSlug = PrettyLinks::slugify($pick['product_name'] ?? '', $prefix);
                                    try { $pp = $pl->criarOuBuscar($pickUrl, $pickSlug, $pick['product_name'] ?? ''); if ($pp) $pick['affiliate_url'] = $pp; } catch (Throwable $e) {}
                                }
                            }
                            unset($pick);
                        }
                    } catch (Throwable $e) {}
                }

                $content = $builder->buildHtml($landing);
                $content .= $builder->buildFaqHtml($landing['faq'] ?? []);
                $content .= $builder->buildSchemas($landing);

                $featuredId = null;
                foreach (($landing['products'] ?? []) as $lp) { if (!empty($lp['wp_media_id'])) { $featuredId = $lp['wp_media_id']; break; } }

                $titulo = $landing['title'] ?? $kw;
                $payload = ['title' => $titulo, 'slug' => $landing['slug'] ?? null, 'content' => $content, 'excerpt' => $landing['excerpt'] ?? '', 'status' => 'draft',
                    'meta' => ['rank_math_title' => $landing['meta_title'] ?? $titulo, 'rank_math_description' => $landing['meta_description'] ?? '', 'rank_math_focus_keyword' => $landing['focus_keyword'] ?? $kw]];
                if ($featuredId) $payload['featured_media'] = $featuredId;
                $page = $wp->criarPagina($payload);
                $r['id'] = $page['id'] ?? null;
                $r['ok'] = true;
                $r['msg'] = "Página #{$r['id']}";
                $r['edit'] = rtrim($cfg['wp_url'], '/') . "/wp-admin/post.php?post={$r['id']}&action=edit";

            } else {
                // Post
                $maq = new Maquina($serper, $scraper, $claude, $wp, $cfg);
                $res = $maq->rodar($kw, [$formato], $blocos);
                $primeiro = $res['resultados'][0] ?? null;
                if ($primeiro && ($primeiro['ok'] ?? false)) {
                    $r['ok'] = true;
                    $r['id'] = $primeiro['post_id'];
                    $r['msg'] = "Post #{$r['id']}";
                    $r['edit'] = $primeiro['edit_url'] ?? '';
                } else {
                    throw new RuntimeException($primeiro['erro'] ?? 'Erro');
                }
            }

            $gerados[] = ['id' => $r['id'], 'keyword' => $kw];

        } catch (Throwable $e) {
            $r['msg'] = $e->getMessage();
        }

        $resultados[] = $r;
        if ($i < count($keywords) - 1) sleep(3);
    }

    // Interligação
    $interligados = 0;
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
            } else { $content .= $linksHtml; }
            $wp->atualizarPost($pid, ['content' => $content]);
            $interligados++;
        } catch (Throwable $e) {}
    }
}

// Fase 1: gerar keywords
if ($fase === 'gerar_keywords' && $nicho !== '') {
    $keywords = gerarKeywords($nicho, date('Y'));
}

$fmtInfo = Claude::$formatos;
$totalOk = count(array_filter($resultados, fn($r) => $r['ok']));
$totalErr = count($resultados) - $totalOk;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Gerador de Categorias</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1020px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:24px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px}
label{display:block;font-weight:bold;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=text]{width:100%;padding:13px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:16px}
input:focus{outline:none;border-color:#6366f1}
button{padding:14px 28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;width:100%;margin-top:16px}
button:hover{opacity:0.9}
.btn-red{background:linear-gradient(135deg,#ef4444,#dc2626)}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.hint{font-size:11px;color:#444;margin-top:4px}
/* Keywords table */
.kw-table{width:100%;border-collapse:collapse;margin:14px 0;font-size:14px}
.kw-table th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:12px;text-transform:uppercase;border-bottom:2px solid #2a2e38}
.kw-table td{padding:8px 10px;border-bottom:1px solid #1e2230}
.kw-table tr:hover{background:#1e2230}
.kw-cat{font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;white-space:nowrap}
.cat-preco{background:#1a2e1a;color:#4ade80}
.cat-uso{background:#1a1d3a;color:#818cf8}
.cat-attr{background:#2a1a1a;color:#fb923c}
.cat-principal{background:#1a2e2e;color:#22d3ee}
.cat-guia{background:#2a2a1a;color:#fbbf24}
.cat-comp{background:#2a1a2a;color:#e879f9}
.tipo-badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px}
.tipo-page{background:#1e3a5f;color:#60a5fa}
.tipo-post{background:#3b1f5e;color:#c084fc}
.resumo{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:14px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:24px;color:#a78bfa}
.resumo-item span{font-size:11px;color:#666}
.result{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:10px 14px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;font-size:13px}
.result.ok{border-left:4px solid #22c55e}
.result.fail{border-left:4px solid #ef4444}
.formatos-bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.fmt-check{display:flex;align-items:center;gap:6px;background:#0f1115;border:2px solid #2a2e38;border-radius:8px;padding:8px 14px;cursor:pointer}
.fmt-check input{accent-color:#6366f1}
.fmt-check span{font-size:13px;color:#888;font-weight:600}
.stats{color:#888;font-size:14px;margin:10px 0}
</style>
</head>
<body>
<div class="container">
  <h1>🏗️ Gerador de Categorias</h1>
  <p class="sub">Digite o nicho → gera 30+ keywords → publica tudo em massa → interliga automaticamente.</p>

  <?php if (!empty($resultados)): ?>
    <div class="box">
      <h2>Resultado</h2>
      <div class="resumo">
        <div class="resumo-item"><strong><?= $totalOk ?></strong><span>gerados</span></div>
        <div class="resumo-item"><strong><?= $totalErr ?></strong><span>erros</span></div>
        <div class="resumo-item"><strong><?= $interligados ?? 0 ?></strong><span>interligados</span></div>
      </div>
      <?php foreach ($resultados as $r): ?>
        <div class="result <?= $r['ok'] ? 'ok' : 'fail' ?>">
          <div>
            <span class="tipo-badge tipo-<?= $r['tipo'] ?>"><?= $r['tipo'] ?></span>
            <span style="font-size:11px;color:#888;margin:0 4px"><?= strtoupper($r['formato'] ?? 'seo') ?></span>
            <strong style="margin-left:2px"><?= htmlspecialchars($r['kw']) ?></strong>
            <span style="color:#666;margin-left:8px"><?= htmlspecialchars($r['msg']) ?></span>
          </div>
          <?php if ($r['ok'] && $r['edit']): ?>
            <a href="<?= htmlspecialchars($r['edit']) ?>" target="_blank">Editar →</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($keywords) && empty($resultados)): ?>
    <!-- Fase 2: revisar e publicar -->
    <form method="POST">
      <input type="hidden" name="fase" value="publicar">
      <input type="hidden" name="nicho" value="<?= htmlspecialchars($nicho) ?>">
      <?php include __DIR__ . '/_site_select.php'; ?>

      <div class="box">
        <h2>📋 <?= count($keywords) ?> keywords geradas para "<?= htmlspecialchars($nicho) ?>"</h2>
        <p class="stats">Revise, remova as que não quiser, e clique em Publicar.</p>

        <table class="kw-table">
          <thead><tr><th>✓</th><th>Keyword</th><th>Categoria</th><th>Tipo</th><th>Formato</th></tr></thead>
          <tbody>
            <?php foreach ($keywords as $i => $k):
              $catClass = match($k['cat']) {
                'Faixa de preço' => 'cat-preco', 'Uso/perfil' => 'cat-uso', 'Atributo' => 'cat-attr',
                'Principal' => 'cat-principal', 'Guia' => 'cat-guia', 'Comparativo' => 'cat-comp', default => ''
              };
            ?>
              <tr>
                <td><input type="checkbox" class="kw-check" data-idx="<?= $i ?>" checked></td>
                <td><?= htmlspecialchars($k['kw']) ?></td>
                <td><span class="kw-cat <?= $catClass ?>"><?= $k['cat'] ?></span></td>
                <td>
                  <select class="kw-tipo" data-idx="<?= $i ?>" style="padding:4px 8px;background:#0f1115;border:1px solid #2a2e38;border-radius:4px;color:#ddd;font-size:12px">
                    <option value="page" <?= $k['tipo'] === 'page' ? 'selected' : '' ?>>page</option>
                    <option value="post" <?= $k['tipo'] === 'post' ? 'selected' : '' ?>>post</option>
                  </select>
                </td>
                <td>
                  <select class="kw-fmt" data-idx="<?= $i ?>" style="padding:4px 8px;background:#0f1115;border:1px solid #2a2e38;border-radius:4px;color:#ddd;font-size:12px">
                    <?php foreach ($fmtInfo as $fkey => $fval): ?>
                      <option value="<?= $fkey ?>" <?= ($k['formato'] ?? 'seo') === $fkey ? 'selected' : '' ?>><?= $fval['nome'] ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <input type="hidden" name="keywords_json" id="kw-json" value="">
      </div>

      <?php include __DIR__ . '/_blocos_inputs.php'; ?>

      <button type="submit" class="btn-red" onclick="prepareJson()">🚀 Publicar <?= count($keywords) ?> páginas/posts</button>
      <p class="hint" style="text-align:center">~30-90s por keyword. Pode demorar bastante pra listas grandes.</p>
    </form>

    <script>
    function prepareJson() {
      const all = <?= json_encode($keywords, JSON_UNESCAPED_UNICODE) ?>;
      const result = [];
      document.querySelectorAll('.kw-check:checked').forEach(cb => {
        const idx = parseInt(cb.dataset.idx);
        const item = {...all[idx]};
        const tipoSel = document.querySelector('.kw-tipo[data-idx="'+idx+'"]');
        const fmtSel = document.querySelector('.kw-fmt[data-idx="'+idx+'"]');
        if (tipoSel) item.tipo = tipoSel.value;
        if (fmtSel) item.formato = fmtSel.value;
        result.push(item);
      });
      document.getElementById('kw-json').value = JSON.stringify(result);
    }
    </script>

  <?php else: ?>
    <!-- Fase 1: digitar nicho -->
    <form method="POST">
      <input type="hidden" name="fase" value="gerar_keywords">
      <?php include __DIR__ . '/_site_select.php'; ?>
      <div class="box">
        <h2>Qual o nicho?</h2>
        <input type="text" name="nicho" placeholder="fones de ouvido, celulares, notebooks, perfumes, smartwatches..." value="<?= htmlspecialchars($nicho) ?>" required autofocus>
        <p class="hint">Digite o nome do produto no plural. O sistema gera 30+ variações automaticamente.</p>
      </div>
      <button type="submit">Gerar keywords →</button>
    </form>
  <?php endif; ?>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:20px">
    <a href="maquina.php">Máquina</a> · <a href="landing.php">Landing</a> · <a href="massa.php">Em massa</a> · <a href="youtube.php">YouTube</a>
  </p>
</div>
</body>
</html>
