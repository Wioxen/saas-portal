<?php
/**
 * Trending → Artigo → WordPress
 *
 * 1. Puxa trending topics do Google Trends BR (RSS)
 * 2. Mostra lista com checkbox pra selecionar
 * 3. Gera artigos pra cada selecionado
 * 4. Publica como draft + interliga
 */
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/Maquina.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
require_once __DIR__ . '/lib/PrettyLinks.php';
require_once __DIR__ . '/lib/GoogleTrends.php';

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$trends = [];
$explores = [];
$resultados = [];
$erro = null;
$aba = $_POST['aba'] ?? ($_GET['aba'] ?? 'trends');
$fase = $_POST['fase'] ?? ($_GET['fase'] ?? 'listar');
$trendCat = $_POST['trend_cat'] ?? ($_GET['cat'] ?? 'all');
$exploreQuery = trim($_POST['explore_q'] ?? ($_GET['q'] ?? ''));
$explorePeriodo = $_POST['explore_tbs'] ?? ($_GET['tbs'] ?? '');
$processado = false;

// Aba Trends
if ($aba === 'trends' && ($fase === 'listar' || $fase === '')) {
    try {
        $gt = new GoogleTrends($cfg['user_agent']);
        $trends = $gt->buscar('BR', $trendCat);
    } catch (Throwable $e) {
        $erro = 'Erro ao buscar trends: ' . $e->getMessage();
    }
}

// Aba Explorar
if ($aba === 'explorar' && $exploreQuery !== '' && $fase !== 'publicar') {
    try {
        $serper = new Serper($cfg['serper_api_key']);
        // Autocomplete
        $autoResp = $serper->autocomplete($exploreQuery);
        $suggestions = $autoResp['suggestions'] ?? [];
        // Related searches com filtro de período
        $relResult = $serper->relatedSearches($exploreQuery, $explorePeriodo);
        $related = $relResult['related'] ?? [];
        $paa = $relResult['paa'] ?? [];
        // Combina tudo
        foreach ($suggestions as $s) {
            $explores[] = ['kw' => $s['value'] ?? $s, 'source' => 'autocomplete'];
        }
        foreach ($related as $r) {
            $kw = $r['query'] ?? '';
            if ($kw !== '') $explores[] = ['kw' => $kw, 'source' => 'related'];
        }
        foreach ($paa as $p) {
            $kw = $p['question'] ?? '';
            if ($kw !== '') $explores[] = ['kw' => $kw, 'source' => 'people also ask'];
        }
        // Remove duplicatas
        $seen = [];
        $explores = array_values(array_filter($explores, function($e) use (&$seen) {
            $key = mb_strtolower($e['kw']);
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    } catch (Throwable $e) {
        $erro = 'Erro ao explorar: ' . $e->getMessage();
    }
}

// Fase 2: gerar artigos
if ($fase === 'publicar') {
    $processado = true;
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $selected = json_decode($_POST['selected_json'] ?? '[]', true) ?: [];

    // Blocos de prompt (opcionais)
    $blocos = [];
    for ($b = 1; $b <= 8; $b++) {
        $blocos[] = trim($_POST["bloco{$b}"] ?? '');
    }

    if (empty($selected)) {
        $erro = 'Selecione pelo menos um trend.';
    } else {
        $serper  = new Serper($cfg['serper_api_key']);
        $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
        $claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
        $wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

        $gerados = [];

        foreach ($selected as $i => $item) {
            $keyword = $item['title'] ?? '';
            $formato = $item['formato'] ?? 'discover';
            $tipo = $item['tipo'] ?? 'post';
            $newsUrl = $item['news_url'] ?? '';

            if ($keyword === '') continue;

            $r = ['kw' => $keyword, 'tipo' => $tipo, 'formato' => $formato, 'ok' => false, 'msg' => '', 'id' => null, 'edit' => ''];

            try {
                // URLs pra scrapear: notícia do trend + SERP
                $urls = [];
                if ($newsUrl !== '' && preg_match('#^https?://#', $newsUrl)) {
                    $urls[] = $newsUrl;
                }

                $maq = new Maquina($serper, $scraper, $claude, $wp, $cfg);
                $res = $maq->rodar($keyword, [$formato], $blocos, $urls);

                $primeiro = $res['resultados'][0] ?? null;
                if ($primeiro && ($primeiro['ok'] ?? false)) {
                    $r['ok'] = true;
                    $r['id'] = $primeiro['post_id'];
                    $r['msg'] = "Post #{$r['id']} — {$primeiro['titulo']}";
                    $r['edit'] = $primeiro['edit_url'] ?? '';
                    $gerados[] = ['id' => $r['id'], 'keyword' => $keyword];
                } else {
                    throw new RuntimeException($primeiro['erro'] ?? 'Erro desconhecido');
                }
            } catch (Throwable $e) {
                $r['msg'] = $e->getMessage();
            }

            $resultados[] = $r;
            if ($i < count($selected) - 1) sleep(3);
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
}

$fmtInfo = Claude::$formatos;
$totalOk = count(array_filter($resultados, fn($r) => $r['ok']));
$totalErr = count($resultados) - $totalOk;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Trending Topics → WordPress</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1020px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:24px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px}
button{padding:14px 28px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;width:100%;margin-top:16px}
button:hover{opacity:0.9}
.btn-reload{background:linear-gradient(135deg,#6366f1,#8b5cf6);width:auto;padding:10px 20px;font-size:14px;margin:0}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.hint{font-size:11px;color:#444;margin-top:4px}
/* Trends table */
.tr-table{width:100%;border-collapse:collapse;margin:14px 0;font-size:14px}
.tr-table th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:12px;text-transform:uppercase;border-bottom:2px solid #2a2e38}
.tr-table td{padding:10px;border-bottom:1px solid #1e2230;vertical-align:middle}
.tr-table tr:hover{background:#1e2230}
.tr-title{font-weight:700;font-size:15px;color:#fff}
.tr-traffic{display:inline-block;background:#1a2e1a;color:#4ade80;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px}
.tr-news{font-size:12px;color:#888;margin-top:3px;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.tr-source{font-size:11px;color:#555}
.tr-img{width:60px;height:40px;object-fit:cover;border-radius:4px;background:#2a2e38}
select{padding:4px 8px;background:#0f1115;border:1px solid #2a2e38;border-radius:4px;color:#ddd;font-size:12px}
/* Results */
.resumo{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:14px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:24px;color:#a78bfa}
.resumo-item span{font-size:11px;color:#666}
.result{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:10px 14px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;font-size:13px}
.result.ok{border-left:4px solid #22c55e}
.result.fail{border-left:4px solid #ef4444}
</style>
</head>
<body>
<div class="container">
  <h1>🔥 Trending &amp; Explorar → WordPress</h1>
  <p class="sub">Trends em alta + Explorar keywords do Google. Selecione, escolha formato e publique.</p>

  <!-- Abas -->
  <div style="display:flex;gap:0;margin-bottom:0">
    <a href="trending.php?aba=trends" style="padding:10px 24px;font-weight:700;font-size:14px;border:1px solid #2a2e38;border-bottom:none;border-radius:8px 8px 0 0;<?= $aba === 'trends' ? 'background:#1a1d23;color:#fff' : 'background:transparent;color:#666' ?>">🔥 Trends</a>
    <a href="trending.php?aba=explorar" style="padding:10px 24px;font-weight:700;font-size:14px;border:1px solid #2a2e38;border-bottom:none;border-radius:8px 8px 0 0;<?= $aba === 'explorar' ? 'background:#1a1d23;color:#fff' : 'background:transparent;color:#666' ?>">🔎 Explorar</a>
  </div>

  <?php if ($erro): ?>
    <div style="background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <?php if ($processado && !empty($resultados)): ?>
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
            <span style="font-size:11px;color:#888"><?= strtoupper($r['formato']) ?></span>
            <strong style="margin-left:4px"><?= htmlspecialchars($r['kw']) ?></strong>
            <span style="color:#666;margin-left:8px"><?= htmlspecialchars($r['msg']) ?></span>
          </div>
          <?php if ($r['ok'] && $r['edit']): ?>
            <a href="<?= htmlspecialchars($r['edit']) ?>" target="_blank">Editar →</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($aba === 'explorar'): ?>
    <!-- Explorar keyword -->
    <div class="box">
      <h2>🔎 Explorar keyword</h2>
      <form method="GET" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
        <input type="hidden" name="aba" value="explorar">
        <div style="flex:1;min-width:200px">
          <input type="text" name="q" placeholder="melhores celulares, fones bluetooth, notebooks..." value="<?= htmlspecialchars($exploreQuery) ?>" style="width:100%;padding:13px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:16px">
        </div>
        <select name="tbs" style="padding:13px 14px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:14px">
          <?php foreach (Serper::$periodos as $tbsKey => $tbsName): ?>
            <option value="<?= $tbsKey ?>" <?= $explorePeriodo === $tbsKey ? 'selected' : '' ?>><?= $tbsName ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" style="width:auto;margin:0;padding:13px 24px;font-size:14px">Explorar</button>
      </form>
    </div>

    <?php if (!empty($explores)): ?>
      <form method="POST">
        <input type="hidden" name="fase" value="publicar">
        <input type="hidden" name="aba" value="explorar">
        <input type="hidden" name="selected_json" id="sel-json" value="">
        <?php include __DIR__ . '/_site_select.php'; ?>

        <div class="box">
          <h2><?= count($explores) ?> keywords encontradas para "<?= htmlspecialchars($exploreQuery) ?>"</h2>
          <table class="tr-table">
            <thead><tr><th>✓</th><th>Keyword</th><th>Fonte</th><th>Tipo</th><th>Formato</th></tr></thead>
            <tbody>
              <?php foreach ($explores as $i => $e): ?>
                <tr>
                  <td><input type="checkbox" class="tr-check" data-idx="<?= $i ?>" checked></td>
                  <td><span class="tr-title"><?= htmlspecialchars($e['kw']) ?></span></td>
                  <td><span style="font-size:11px;color:#888"><?= $e['source'] ?></span></td>
                  <td>
                    <select class="tr-tipo" data-idx="<?= $i ?>">
                      <option value="post" selected>post</option>
                      <option value="page">page</option>
                    </select>
                  </td>
                  <td>
                    <select class="tr-fmt" data-idx="<?= $i ?>">
                      <option value="seo" selected>SEO</option>
                      <option value="discover">Discover</option>
                      <option value="news">News</option>
                      <option value="serp">SERP</option>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php include __DIR__ . '/_blocos_inputs.php'; ?>

        <button type="submit" onclick="prepareJsonExplore()">🚀 Gerar artigos dos selecionados</button>
      </form>

      <script>
      const exploreData = <?= json_encode($explores, JSON_UNESCAPED_UNICODE) ?>;
      function prepareJsonExplore() {
        const result = [];
        document.querySelectorAll('.tr-check:checked').forEach(cb => {
          const idx = parseInt(cb.dataset.idx);
          const e = exploreData[idx];
          result.push({
            title: e.kw,
            news_url: '',
            tipo: document.querySelector('.tr-tipo[data-idx="'+idx+'"]').value,
            formato: document.querySelector('.tr-fmt[data-idx="'+idx+'"]').value
          });
        });
        document.getElementById('sel-json').value = JSON.stringify(result);
      }
      </script>
    <?php elseif ($exploreQuery !== ''): ?>
      <div class="box"><p>Nenhuma sugestão encontrada para "<?= htmlspecialchars($exploreQuery) ?>"</p></div>
    <?php endif; ?>

  <?php elseif (!empty($trends)): ?>
    <form method="POST">
      <input type="hidden" name="fase" value="publicar">
      <input type="hidden" name="aba" value="trends">
      <input type="hidden" name="trend_cat" value="<?= htmlspecialchars($trendCat) ?>">
      <input type="hidden" name="selected_json" id="sel-json" value="">
      <?php include __DIR__ . '/_site_select.php'; ?>

      <div class="box">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap">
          <h2 style="margin:0"><?= count($trends) ?> trends em alta no Brasil</h2>
          <div style="display:flex;gap:8px;align-items:center">
            <select id="cat-select" onchange="window.location='trending.php?cat='+this.value" style="padding:8px 12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:13px;font-weight:600">
              <?php foreach (GoogleTrends::$categorias as $catKey => $catName): ?>
                <option value="<?= $catKey ?>" <?= $trendCat === $catKey ? 'selected' : '' ?>><?= $catName ?></option>
              <?php endforeach; ?>
            </select>
            <a href="trending.php?cat=<?= $trendCat ?>" style="display:inline-block;background:#6366f1;color:#fff;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;white-space:nowrap">Atualizar</a>
          </div>
        </div>

        <table class="tr-table">
          <thead>
            <tr><th>✓</th><th></th><th>Trending</th><th>Tráfego</th><th>Tipo</th><th>Formato</th></tr>
          </thead>
          <tbody>
            <?php foreach ($trends as $i => $t): ?>
              <tr>
                <td><input type="checkbox" class="tr-check" data-idx="<?= $i ?>" checked></td>
                <td>
                  <?php if ($t['image']): ?>
                    <img src="<?= htmlspecialchars($t['image']) ?>" class="tr-img" loading="lazy" alt="">
                  <?php endif; ?>
                </td>
                <td>
                  <div class="tr-title"><?= htmlspecialchars($t['title']) ?></div>
                  <?php if ($t['news_title']): ?>
                    <div class="tr-news"><?= htmlspecialchars($t['news_title']) ?></div>
                  <?php endif; ?>
                  <?php if ($t['news_source']): ?>
                    <div class="tr-source"><?= htmlspecialchars($t['news_source']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="tr-traffic"><?= htmlspecialchars($t['traffic'] ?: '100+') ?></span></td>
                <td>
                  <select class="tr-tipo" data-idx="<?= $i ?>">
                    <option value="post" selected>post</option>
                    <option value="page">page</option>
                  </select>
                </td>
                <td>
                  <select class="tr-fmt" data-idx="<?= $i ?>">
                    <option value="discover" selected>Discover</option>
                    <option value="news">News</option>
                    <option value="seo">SEO</option>
                    <option value="serp">SERP</option>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php include __DIR__ . '/_blocos_inputs.php'; ?>

      <button type="submit" onclick="prepareJson()">🔥 Gerar artigos dos selecionados</button>
      <p class="hint" style="text-align:center">~30-90s por trend. Scrape + Claude + WP + interligação.</p>
    </form>

    <script>
    const trendsData = <?= json_encode($trends, JSON_UNESCAPED_UNICODE) ?>;

    function prepareJson() {
      const result = [];
      document.querySelectorAll('.tr-check:checked').forEach(cb => {
        const idx = parseInt(cb.dataset.idx);
        const t = trendsData[idx];
        const tipo = document.querySelector('.tr-tipo[data-idx="'+idx+'"]').value;
        const fmt = document.querySelector('.tr-fmt[data-idx="'+idx+'"]').value;
        result.push({
          title: t.title,
          news_url: t.news_url || '',
          tipo: tipo,
          formato: fmt
        });
      });
      document.getElementById('sel-json').value = JSON.stringify(result);
    }
    </script>
  <?php elseif (!$processado): ?>
    <div class="box">
      <p>Nenhum trend encontrado. <a href="trending.php">Tentar novamente</a></p>
    </div>
  <?php endif; ?>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:20px">
    <a href="categorias.php">Categorias</a> · <a href="maquina.php">Máquina</a> · <a href="massa.php">Em massa</a> · <a href="youtube.php">YouTube</a>
  </p>
</div>
</body>
</html>
