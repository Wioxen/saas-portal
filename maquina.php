<?php
/**
 * Máquina de conteúdo — cada formato em sua aba com configuração própria.
 * Checkboxes permitem gerar vários formatos de uma vez.
 * Cada aba tem 8 blocos de prompt independentes.
 */
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/Maquina.php';
require_once __DIR__ . '/lib/GoogleTrends.php';

$cfg = require_once __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$resultado = null;          // resultado de execução simples
$resultadosBatch = null;    // resultados de execução em batch (trends)
$erro = null;
$trendsQueries = [];        // queries retornadas do Google Trends Explore

$fmtInfo = Claude::$formatos;
// Labels + defaults universais dos 8 blocos (mesmo padrão do _blocos_inputs.php)
require __DIR__ . '/_blocos_data.php';

const MAQUINA_TRENDS_CAP = 3; // cap rígido de trends por execução

// Valores preservados entre submissões do modo trends
$trendsTermo    = trim($_POST['trends_termo'] ?? '');
$trendsPeriodo  = $_POST['trends_periodo'] ?? 'now 1-H';
$trendsSelected = $_POST['trends_selected'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['action'] ?? 'gerar';

    // ── Fase: buscar trends (não roda pipeline) ──
    if ($acao === 'search_trends') {
        if ($trendsTermo === '') {
            $erro = 'Informe o termo para buscar trends.';
        } else {
            try {
                $gt = new GoogleTrends($cfg['user_agent']);
                $trendsQueries = $gt->explorarTermo($trendsTermo, $trendsPeriodo, 'BR');
                if (empty($trendsQueries)) {
                    $erro = "Nenhuma query relacionada retornada para '{$trendsTermo}' no período selecionado.";
                }
            } catch (Throwable $e) {
                $erro = 'Erro ao buscar trends: ' . $e->getMessage();
            }
        }
    } else {
        // ── Fase: gerar (single ou batch) ──
        $keyword  = trim($_POST['keyword'] ?? '');
        $urlsRaw  = trim($_POST['urls'] ?? '');
        $formatos = $_POST['formatos'] ?? [];

        // Modos mutuamente exclusivos: trends > keyword/urls
        $modoTrends = !empty($trendsSelected);

        if (!$modoTrends && $keyword === '' && $urlsRaw === '') {
            $erro = 'Preencha pelo menos um: palavra-chave SERP, URLs para scrapear ou selecione trends.';
        } elseif (empty($formatos)) {
            $erro = 'Selecione pelo menos um formato.';
        } else {
            try {
                $serper  = new Serper($cfg['serper_api_key']);
                $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
                $claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
                $wp      = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
                $maq     = new Maquina($serper, $scraper, $claude, $wp, $cfg);

                set_time_limit(600);

                // Coleta blocos por formato (comum aos dois modos)
                $blocosPorFormato = [];
                foreach ($formatos as $fmt) {
                    $b = [];
                    for ($i = 1; $i <= 8; $i++) {
                        $b[] = trim($_POST["bloco_{$fmt}_{$i}"] ?? '');
                    }
                    if (implode('', $b) === '') {
                        for ($i = 1; $i <= 8; $i++) {
                            $b[$i - 1] = trim($_POST["bloco_global_{$i}"] ?? '');
                        }
                    }
                    $blocosPorFormato[$fmt] = $b;
                }

                if ($modoTrends) {
                    // Normaliza + aplica cap
                    $trendsSelected = array_values(array_unique(array_filter(array_map('trim', $trendsSelected))));
                    if (count($trendsSelected) > MAQUINA_TRENDS_CAP) {
                        $trendsSelected = array_slice($trendsSelected, 0, MAQUINA_TRENDS_CAP);
                    }
                    $resultadosBatch = [];
                    foreach ($trendsSelected as $idx => $kw) {
                        try {
                            $r = $maq->rodarComBlocosPorFormato($kw, $formatos, $blocosPorFormato, []);
                            $resultadosBatch[] = $r;
                        } catch (Throwable $e) {
                            $resultadosBatch[] = [
                                'keyword'    => $kw,
                                'fontes'     => 0,
                                'resultados' => [],
                                'log'        => ['❌ ' . $e->getMessage()],
                                'erro'       => $e->getMessage(),
                            ];
                        }
                        if ($idx < count($trendsSelected) - 1) sleep(2);
                    }
                } else {
                    // Fluxo single (keyword SERP + URLs)
                    $urls = [];
                    if ($urlsRaw !== '') {
                        $urls = array_filter(array_map('trim', preg_split('/[\r\n]+/', $urlsRaw)));
                    }
                    $resultado = $maq->rodarComBlocosPorFormato($keyword, $formatos, $blocosPorFormato, $urls);
                }
            } catch (Throwable $e) {
                $erro = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Máquina de Conteúdo</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1020px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:24px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px;color:#e0e0e0}
label{display:block;font-weight:bold;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=text]{width:100%;padding:13px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:16px}
input[type=text]:focus,textarea:focus{outline:none;border-color:#6366f1}
textarea{width:100%;padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:12px;font-family:inherit;min-height:58px;resize:vertical}

/* Checkboxes no topo */
.formatos-bar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.fmt-check{display:flex;align-items:center;gap:6px;background:#0f1115;border:2px solid #2a2e38;border-radius:8px;padding:10px 16px;cursor:pointer;transition:all .2s;user-select:none}
.fmt-check:hover{border-color:#6366f1}
.fmt-check input{accent-color:#6366f1;width:16px;height:16px}
.fmt-check input:checked ~ .fmt-name{color:#fff}
.fmt-check .fmt-name{font-weight:700;font-size:14px;color:#888;transition:color .2s}
.fmt-check .fmt-sub{font-size:11px;color:#555;margin-left:4px}
.fmt-check-seo input:checked ~ .fmt-name{color:#60a5fa}
.fmt-check-seo:has(input:checked){border-color:#60a5fa;background:#0f1a2e}
.fmt-check-discover input:checked ~ .fmt-name{color:#fb923c}
.fmt-check-discover:has(input:checked){border-color:#fb923c;background:#1a1208}
.fmt-check-news input:checked ~ .fmt-name{color:#4ade80}
.fmt-check-news:has(input:checked){border-color:#4ade80;background:#0a1a0e}
.fmt-check-serp input:checked ~ .fmt-name{color:#c084fc}
.fmt-check-serp:has(input:checked){border-color:#c084fc;background:#1a0f2e}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:2px solid #2a2e38;margin-bottom:0}
.tab{padding:10px 20px;cursor:pointer;font-size:14px;font-weight:600;color:#666;border:2px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;transition:all .15s;user-select:none;background:transparent}
.tab:hover{color:#bbb}
.tab.active{color:#fff;background:#1a1d23;border-color:#2a2e38;margin-bottom:-2px;border-bottom:2px solid #1a1d23}
.tab-seo.active{color:#60a5fa}
.tab-discover.active{color:#fb923c}
.tab-news.active{color:#4ade80}
.tab-serp.active{color:#c084fc}
.tab-global.active{color:#a78bfa}
.tab-panel{display:none;background:#1a1d23;border:1px solid #2a2e38;border-top:none;border-radius:0 0 10px 10px;padding:20px}
.tab-panel.active{display:block}
.blocos-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.bloco-header{display:flex;justify-content:space-between;align-items:center}
.bloco-header small{color:#444;font-weight:normal;font-size:11px}

button[type=submit]{margin-top:16px;padding:14px 28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;width:100%;transition:opacity .2s}
button[type=submit]:hover{opacity:0.9}

/* Resultados */
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5}
.resultado-card{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:16px;margin-bottom:10px}
.resultado-card.ok{border-left:4px solid #22c55e}
.resultado-card.fail{border-left:4px solid #ef4444}
.resultado-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.badge{font-size:11px;font-weight:bold;padding:3px 10px;border-radius:12px;text-transform:uppercase}
.badge-seo{background:#1e3a5f;color:#60a5fa}
.badge-discover{background:#4a2210;color:#fb923c}
.badge-news{background:#1a3a2a;color:#4ade80}
.badge-serp{background:#3b1f5e;color:#c084fc}
.stats{display:flex;gap:20px;margin:10px 0;font-size:13px;color:#888}
.stats strong{color:#e0e0e0}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
.log{background:#0a0c10;border:1px solid #2a2e38;padding:14px;border-radius:6px;font-family:monospace;font-size:11px;color:#a1a1aa;max-height:400px;overflow:auto;white-space:pre-wrap;margin-top:10px}
.resumo{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:14px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:24px;color:#a78bfa}
.resumo-item span{font-size:11px;color:#666}
.hint{font-size:11px;color:#444;margin-top:6px}
</style>
</head>
<body>
<div class="container">
  <h1>Máquina de Conteúdo</h1>
  <p class="sub">SERP → Scrape top 5 → Claude gera artigo por formato → Draft no WP. Cada formato com seus próprios prompts.</p>

  <?php if ($erro): ?>
    <div class="erro"><?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <?php if ($resultadosBatch): ?>
    <div class="box">
      <h2>Batch Trends concluído — <?= count($resultadosBatch) ?> keyword(s)</h2>
      <?php foreach ($resultadosBatch as $bR): ?>
        <div style="border:1px solid #2a2e38;border-radius:8px;padding:14px;margin-bottom:12px;background:#111318">
          <h3 style="margin:0 0 10px;color:#a78bfa;font-size:15px">"<?= htmlspecialchars($bR['keyword']) ?>"</h3>
          <?php if (!empty($bR['erro'])): ?>
            <p style="color:#fca5a5;font-size:13px;margin:0">Falhou: <?= htmlspecialchars($bR['erro']) ?></p>
          <?php else: ?>
            <div class="resumo" style="grid-template-columns:repeat(3,1fr);margin:4px 0 10px">
              <div class="resumo-item"><strong><?= $bR['fontes'] ?></strong><span>fontes</span></div>
              <div class="resumo-item"><strong><?= count(array_filter($bR['resultados'], fn($r) => $r['ok'] ?? false)) ?></strong><span>posts ok</span></div>
              <div class="resumo-item"><strong><?= count($bR['resultados']) ?></strong><span>formatos</span></div>
            </div>
            <?php foreach ($bR['resultados'] as $r): ?>
              <div class="resultado-card <?= ($r['ok'] ?? false) ? 'ok' : 'fail' ?>">
                <div class="resultado-header">
                  <div>
                    <span class="badge badge-<?= $r['formato'] ?>"><?= htmlspecialchars($r['nome']) ?></span>
                    <?php if ($r['ok'] ?? false): ?>
                      <strong style="margin-left:8px"><?= htmlspecialchars($r['titulo'] ?? '') ?></strong>
                    <?php endif; ?>
                  </div>
                  <?php if ($r['ok'] ?? false): ?>
                    <span style="color:#22c55e;font-size:13px">Draft #<?= $r['post_id'] ?></span>
                  <?php else: ?>
                    <span style="color:#ef4444;font-size:13px">Falhou</span>
                  <?php endif; ?>
                </div>
                <?php if ($r['ok'] ?? false): ?>
                  <div class="stats">
                    <span><strong><?= $r['palavras'] ?></strong> palavras</span>
                    <span><strong><?= $r['fontes'] ?></strong> fontes</span>
                    <span>
                      <a href="<?= htmlspecialchars($r['edit_url'] ?? '') ?>" target="_blank">Editar</a>
                      <?php if (!empty($r['preview'])): ?> · <a href="<?= htmlspecialchars($r['preview']) ?>" target="_blank">Preview</a><?php endif; ?>
                    </span>
                  </div>
                <?php else: ?>
                  <p style="color:#fca5a5;font-size:13px"><?= htmlspecialchars($r['erro'] ?? 'Erro desconhecido') ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <details>
              <summary style="cursor:pointer;color:#a78bfa;margin-top:8px;font-size:12px">Ver log</summary>
              <div class="log"><?= htmlspecialchars(implode("\n", $bR['log'])) ?></div>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($resultado): ?>
    <div class="box">
      <h2>Pipeline concluído — "<?= htmlspecialchars($resultado['keyword']) ?>"</h2>
      <div class="resumo">
        <div class="resumo-item"><strong><?= $resultado['fontes'] ?></strong><span>fontes scrapeadas</span></div>
        <div class="resumo-item"><strong><?= count(array_filter($resultado['resultados'], fn($r) => $r['ok'] ?? false)) ?></strong><span>posts criados</span></div>
        <div class="resumo-item"><strong><?= count($resultado['resultados']) ?></strong><span>formatos</span></div>
      </div>
      <?php foreach ($resultado['resultados'] as $r): ?>
        <div class="resultado-card <?= ($r['ok'] ?? false) ? 'ok' : 'fail' ?>">
          <div class="resultado-header">
            <div>
              <span class="badge badge-<?= $r['formato'] ?>"><?= htmlspecialchars($r['nome']) ?></span>
              <?php if ($r['ok'] ?? false): ?>
                <strong style="margin-left:8px"><?= htmlspecialchars($r['titulo'] ?? '') ?></strong>
              <?php endif; ?>
            </div>
            <?php if ($r['ok'] ?? false): ?>
              <span style="color:#22c55e;font-size:13px">Draft #<?= $r['post_id'] ?></span>
            <?php else: ?>
              <span style="color:#ef4444;font-size:13px">Falhou</span>
            <?php endif; ?>
          </div>
          <?php if ($r['ok'] ?? false): ?>
            <div class="stats">
              <span><strong><?= $r['palavras'] ?></strong> palavras</span>
              <span><strong><?= $r['fontes'] ?></strong> fontes</span>
              <span>
                <a href="<?= htmlspecialchars($r['edit_url'] ?? '') ?>" target="_blank">Editar</a>
                <?php if (!empty($r['preview'])): ?> · <a href="<?= htmlspecialchars($r['preview']) ?>" target="_blank">Preview</a><?php endif; ?>
              </span>
            </div>
          <?php else: ?>
            <p style="color:#fca5a5;font-size:13px"><?= htmlspecialchars($r['erro'] ?? 'Erro desconhecido') ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <details>
        <summary style="cursor:pointer;color:#a78bfa;margin-top:12px">Ver log</summary>
        <div class="log"><?= htmlspecialchars(implode("\n", $resultado['log'])) ?></div>
      </details>
    </div>
  <?php endif; ?>

  <form method="POST">
    <?php include __DIR__ . '/_site_select.php'; ?>
    <!-- Fontes -->
    <div class="box">
      <h2>Fontes de dados</h2>
      <p style="color:#555;font-size:13px;margin-bottom:10px">Preencha pelo menos um. Pode usar ambos — o scrape das URLs + SERP são combinados.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label style="margin-top:0">Keyword SERP</label>
          <input name="keyword" type="text" placeholder="ex: melhores celulares ate 1500 reais 2026" value="<?= htmlspecialchars($_POST['keyword'] ?? '') ?>">
          <p style="color:#444;font-size:11px;margin-top:4px">Busca no Google e scrapeia os top 5 resultados.</p>
        </div>
        <div>
          <label style="margin-top:0">URLs para scrapear</label>
          <textarea name="urls" style="min-height:80px;font-family:'JetBrains Mono','Fira Code',monospace;font-size:12px" placeholder="https://exemplo.com/review-fones
https://outro-site.com/melhores-celulares"><?= htmlspecialchars($_POST['urls'] ?? '') ?></textarea>
          <p style="color:#444;font-size:11px;margin-top:4px">Uma URL por linha. Scrapeia direto, sem Serper.</p>
        </div>
      </div>
    </div>

    <!-- Google Trends (opcional, mutuamente exclusivo com keyword/urls) -->
    <div class="box" style="border-left:4px solid #f59e0b">
      <h2 style="margin-top:0">🔥 Google Trends Explore <span style="font-weight:normal;color:#555;font-size:12px">(opcional — ignora keyword/URLs acima se usar)</span></h2>
      <p style="color:#555;font-size:13px;margin-bottom:10px">Busque queries relacionadas a um termo em alta. Selecione até <strong><?= MAQUINA_TRENDS_CAP ?></strong> — cada uma vira um artigo próprio (scrape individual por keyword, sem contaminação cruzada).</p>
      <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:10px;align-items:end">
        <div>
          <label style="margin-top:0">Termo de pesquisa</label>
          <input name="trends_termo" type="text" placeholder="melhores, barato, promoção, comprar..." value="<?= htmlspecialchars($trendsTermo) ?>">
        </div>
        <div>
          <label style="margin-top:0">Período</label>
          <select name="trends_periodo" style="width:100%;padding:13px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px">
            <?php foreach (GoogleTrends::$periodos as $pk => $pn): ?>
              <option value="<?= htmlspecialchars($pk) ?>" <?= $trendsPeriodo === $pk ? 'selected' : '' ?>><?= htmlspecialchars($pn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="action" value="search_trends" style="margin:0;width:auto;padding:13px 20px;background:linear-gradient(135deg,#f59e0b,#f97316);font-size:14px">Buscar trends</button>
      </div>

      <?php if (!empty($trendsQueries)): ?>
        <p style="color:#888;font-size:12px;margin:14px 0 6px"><?= count($trendsQueries) ?> queries encontradas para "<?= htmlspecialchars($trendsTermo) ?>" · <?= htmlspecialchars(GoogleTrends::$periodos[$trendsPeriodo] ?? $trendsPeriodo) ?></p>
        <div style="max-height:340px;overflow:auto;border:1px solid #2a2e38;border-radius:6px;padding:8px 12px;background:#0f1115">
          <?php foreach ($trendsQueries as $tq): $sel = in_array($tq['query'], (array)$trendsSelected, true); ?>
            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer">
              <input type="checkbox" name="trends_selected[]" value="<?= htmlspecialchars($tq['query']) ?>" <?= $sel ? 'checked' : '' ?> class="tr-sel">
              <span style="flex:1"><?= htmlspecialchars($tq['query']) ?></span>
              <span style="font-size:10px;color:<?= $tq['type'] === 'rising' ? '#4ade80' : '#a78bfa' ?>;text-transform:uppercase;font-weight:700"><?= $tq['type'] ?></span>
              <span style="font-size:11px;color:#666;min-width:50px;text-align:right"><?= htmlspecialchars($tq['formattedValue'] ?: (string)$tq['value']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <p style="color:#666;font-size:11px;margin-top:6px">Cap: até <?= MAQUINA_TRENDS_CAP ?> selecionados serão processados. Extras são ignorados.</p>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          const cap = <?= MAQUINA_TRENDS_CAP ?>;
          document.querySelectorAll('.tr-sel').forEach(cb => {
            cb.addEventListener('change', () => {
              const marcados = document.querySelectorAll('.tr-sel:checked').length;
              if (marcados > cap) { cb.checked = false; alert('Máximo de ' + cap + ' trends por execução.'); }
            });
          });
        });
        </script>
      <?php endif; ?>
    </div>

    <!-- Checkboxes de formato -->
    <div class="box">
      <h2>Formatos</h2>
      <p style="color:#555;font-size:13px;margin-bottom:10px">Marque os formatos desejados. Cada um vira um draft separado. Configure os prompts nas abas abaixo.</p>
      <div class="formatos-bar">
        <?php foreach ($fmtInfo as $key => $f): ?>
          <label class="fmt-check fmt-check-<?= $key ?>">
            <input type="checkbox" name="formatos[]" value="<?= $key ?>"
              <?= in_array($key, $_POST['formatos'] ?? ['seo']) ? 'checked' : '' ?>>
            <span class="fmt-name"><?= $f['nome'] ?></span>
            <span class="fmt-sub"><?= $f['estilo'] ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Abas de configuração -->
    <div class="box" style="padding:0;overflow:hidden">
      <div class="tabs" style="padding:16px 16px 0">
        <div class="tab tab-global active" onclick="switchTab('global')">Global</div>
        <?php foreach ($fmtInfo as $key => $f): ?>
          <div class="tab tab-<?= $key ?>" onclick="switchTab('<?= $key ?>')"><?= $f['nome'] ?></div>
        <?php endforeach; ?>
      </div>

      <!-- Aba Global -->
      <div id="panel-global" class="tab-panel active">
        <p style="color:#888;font-size:13px;margin-bottom:12px">
          Prompts globais — usados por qualquer formato que <strong>não tenha seus próprios prompts preenchidos</strong>.
        </p>
        <div class="blocos-grid">
          <?php for ($i = 1; $i <= 8; $i++): $bl = $blocoLabels[$i]; ?>
            <div>
              <div class="bloco-header"><label>Bloco <?= $i ?> — <?= $bl[0] ?></label><small><?= $bl[1] ?></small></div>
              <textarea name="bloco_global_<?= $i ?>"><?= htmlspecialchars($_POST["bloco_global_{$i}"] ?? $blocoDefaults[$i]) ?></textarea>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <!-- Aba por formato -->
      <?php foreach ($fmtInfo as $key => $f): ?>
        <div id="panel-<?= $key ?>" class="tab-panel">
          <p style="color:#888;font-size:13px;margin-bottom:4px">
            <strong style="color:#e0e0e0"><?= $f['nome'] ?></strong> — <?= $f['estilo'] ?>
          </p>
          <p style="color:#555;font-size:12px;margin-bottom:12px">
            Prompts específicos para <?= $f['nome'] ?>. Se todos vazios, usa os prompts da aba Global.
          </p>
          <div class="blocos-grid">
            <?php for ($i = 1; $i <= 8; $i++): $bl = $blocoLabels[$i]; ?>
              <div>
                <div class="bloco-header"><label>Bloco <?= $i ?> — <?= $bl[0] ?></label><small><?= $bl[1] ?></small></div>
                <textarea name="bloco_<?= $key ?>_<?= $i ?>" placeholder="Deixe vazio para herdar do Global"><?= htmlspecialchars($_POST["bloco_{$key}_{$i}"] ?? '') ?></textarea>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <button type="submit">Rodar pipeline</button>
    <p class="hint" style="text-align:center">~30-90s por formato: Serper + scrape (1x) + Claude + WP por formato marcado.</p>
  </form>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:20px">
    <a href="landing.php">Gerador de páginas</a> · <a href="gerar.php">Gerador estático</a> · <a href="sitemap.php">Sitemap</a>
  </p>
</div>

<script>
function switchTab(id) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelector('.tab-' + id).classList.add('active');
  document.getElementById('panel-' + id).classList.add('active');
}
</script>
</body>
</html>
