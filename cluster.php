<?php
/**
 * Cluster — UI multi-URL + RSS para o gerarpost.php.
 *
 * Modos de entrada:
 *  1. Linhas manuais: cada linha = 1 site + 1 URL + 1 formato
 *  2. RSS (Google News ou similar): URL do feed + site/formato globais → aplica a todos os items marcados
 *
 * No submit, os items marcados (RSS) + linhas manuais são combinados em cluster_items_json
 * e postados em gerarpost.php action=cluster. A keyword/slug é derivada do título scrapeado
 * (pre-fetch) para garantir que os backlinks cruzados batam com os slugs reais publicados.
 */
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/GoogleNewsRss.php';
require_once __DIR__ . '/lib/Triagem.php';
require_once __DIR__ . '/lib/PostMatcher.php';
require_once __DIR__ . '/lib/PillarDetector.php';
require_once __DIR__ . '/lib/Wordpress.php';

$cfgBase = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites = sitesDisponiveis();

$fmtInfo = Claude::$formatos;
require __DIR__ . '/_blocos_data.php';

$rssItems = [];
$rssUrlValor = $_POST['rss_url'] ?? '';
$erroRss = null;

// Carregar RSS via AJAX/POST
if (($_POST['action'] ?? '') === 'load_rss') {
    try {
        $rssUrl = trim($rssUrlValor);
        if ($rssUrl === '') throw new RuntimeException('URL do RSS vazia');
        $serperRss = null;
        try { $serperRss = new Serper($cfgBase['serper_api_key']); } catch (Throwable $e) {}
        $gn = new GoogleNewsRss($cfgBase['user_agent'] ?? '', 20, $serperRss);
        $rssItems = $gn->parseRss($rssUrl, 15);
    } catch (Throwable $e) { $erroRss = 'Erro ao ler RSS: ' . $e->getMessage(); }
}

// Resolve links criptografados (Google News) via Serper + fallback
$itemsResolvidos = [];
if (!empty($rssItems)) {
    $serperResolver = null;
    try { $serperResolver = new Serper($cfgBase['serper_api_key']); } catch (Throwable $e) {}
    $gnResolver = new GoogleNewsRss($cfgBase['user_agent'] ?? '', 20, $serperResolver);
    foreach ($rssItems as $item) {
        $resolvido = null;
        if ($serperResolver !== null) {
            $resolvido = $gnResolver->resolverViaTitulo($item['title'], $item['source'] ?? '');
        }
        if ($resolvido === null) $resolvido = $gnResolver->resolverLink($item['link']);
        if ($resolvido === null) $resolvido = $item['link'];
        $itemsResolvidos[] = array_merge($item, ['link_resolvido' => $resolvido]);
    }
}

// TRIAGEM com Haiku — pontua 0-10 antes do user gastar Sonnet nos items.
// Roda só se: triagem habilitada (default ON) + chave Anthropic + items existem.
// Falha silenciosa: se Haiku der erro, items ficam sem nota/motivo (UI degrada limpo).
$triagemAtiva = !isset($_POST['skip_triagem']);
$siteSlugPosts = $_POST['rss_site_global'] ?? $_POST['triagem_site'] ?? '';
if (!empty($itemsResolvidos) && $triagemAtiva && !empty($cfgBase['anthropic_api_key'])) {
    $siteCtx = [];
    if ($siteSlugPosts !== '' && isset($sites[$siteSlugPosts])) {
        $s = $sites[$siteSlugPosts];
        $siteCtx = [
            'nome'      => $s['name'] ?? $siteSlugPosts,
            'nicho'     => $s['subtipo_nicho'] ?? '',
            'audiencia' => $s['persona']['audiencia'] ?? '',
            'canibal'   => $s['termos_canibal'] ?? [],
        ];
    }
    try {
        $triagem = new Triagem($cfgBase['anthropic_api_key']);
        $scores = $triagem->pontuar($itemsResolvidos, $siteCtx);
        foreach ($itemsResolvidos as $i => &$it) {
            $it['nota']   = $scores[$i]['nota']   ?? 5;
            $it['motivo'] = $scores[$i]['motivo'] ?? '';
        }
        unset($it);
    } catch (Throwable $e) {
        error_log('[cluster.php] Triagem falhou: ' . $e->getMessage());
    }
}

// POSTMATCHER — detecta posts existentes no WP que dão match com items novos.
// Em temas recorrentes (Bolsa Família, PIS, Pé-de-Meia mensais), o site provavelmente já
// cobriu — refresh do post existente preserva URL+autoridade vs criar duplicata.
// Roda só se: matcher habilitado + site escolhido + credenciais WP. Falha silenciosa.
$matcherAtivo = !isset($_POST['skip_matcher']);
$pillarDetectado = null; // ['topico' => string, 'pillar' => array|null]
if (!empty($itemsResolvidos) && $matcherAtivo && $siteSlugPosts !== '' && isset($sites[$siteSlugPosts])) {
    $sM = $sites[$siteSlugPosts];
    if (!empty($sM['wp_url']) && !empty($sM['wp_user']) && !empty($sM['wp_app_password'])) {
        try {
            $wpAlvo = new Wordpress($sM['wp_url'], $sM['wp_user'], $sM['wp_app_password']);
            $matcher = new PostMatcher($wpAlvo, 55.0);
            foreach ($itemsResolvidos as $i => &$it) {
                // Skip items com nota muito baixa (user não vai processá-los mesmo)
                if (isset($it['nota']) && (int)$it['nota'] < 5) continue;
                $kw = (string)($it['title'] ?? '');
                $match = $matcher->encontrarMatch($kw);
                if ($match !== null) {
                    $it['existe'] = $match;
                }
            }
            unset($it);

            // PILLAR DETECTOR — detecta tópico umbrella + procura pillar existente.
            // Topical authority: linkar todos os artigos do cluster pra um pillar centraliza
            // o sinal de autoridade no Google ("este site é fonte sobre X").
            // Roda só se cluster ≥ 2 items + chave Anthropic. Falha silenciosa.
            if (count($itemsResolvidos) >= 2 && !isset($_POST['skip_pillar']) && !empty($cfgBase['anthropic_api_key'])) {
                try {
                    $pillarDetector = new PillarDetector($cfgBase['anthropic_api_key'], $wpAlvo);
                    $pillarDetectado = $pillarDetector->detectar($itemsResolvidos);
                } catch (Throwable $eP) {
                    error_log('[cluster.php] PillarDetector falhou: ' . $eP->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[cluster.php] PostMatcher falhou: ' . $e->getMessage());
        }
    }
}

$nLinhas = max(3, count($_POST['row_site'] ?? []));
?>
<!DOCTYPE html>
<html lang='pt-br'>
<head>
<meta charset='UTF-8'>
<title>Cluster SEO — Multi-URL + RSS</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1200px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:22px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px;color:#e0e0e0}
label{display:block;font-weight:bold;margin:8px 0 6px;font-size:12px;color:#bbb}
input[type=text],input[type=url],select{width:100%;padding:11px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px}
input:focus,select:focus{outline:none;border-color:#6366f1}
.row{display:grid;grid-template-columns:1.3fr 3fr 1.1fr 40px;gap:10px;margin-bottom:10px;align-items:end}
.row .rm{background:#3b1818;color:#fca5a5;border:1px solid #5a2a2a;border-radius:6px;cursor:pointer;font-size:18px;height:41px}
.row .rm:hover{background:#5a2020}
.add-row{background:#1e293b;color:#93c5fd;border:1px dashed #3b4a5e;padding:12px;border-radius:8px;cursor:pointer;width:100%;font-size:13px;font-weight:600}
.add-row:hover{background:#243449}
button[type=submit].go{margin-top:16px;padding:14px 28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer;width:100%}
button[type=submit].go:hover{opacity:.9}
.btn-rss{padding:10px 18px;background:#0f1a2e;border:1px solid #2a3a5e;color:#93c5fd;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}
.btn-rss:hover{background:#142340}
.hint{font-size:11px;color:#444;margin-top:6px}
.aviso{background:#1a2332;border-left:4px solid #3b82f6;padding:14px;border-radius:6px;margin-bottom:16px;color:#93c5fd;font-size:13px}
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5;font-size:13px}
.globais{display:grid;grid-template-columns:1.5fr 1fr;gap:12px;margin-bottom:14px}
.rss-item{display:flex;gap:12px;align-items:flex-start;background:#0f1115;border:1px solid #2a2e38;border-radius:8px;padding:12px 14px;margin-bottom:6px;cursor:pointer;position:relative}
.rss-item:hover{border-color:#3b4a5e}
.rss-item.lo{opacity:.55}
.rss-title{font-weight:700;color:#fff;font-size:14px;margin-bottom:4px}
.rss-meta{font-size:11px;color:#666;margin-bottom:6px}
.rss-desc{font-size:12px;color:#888;margin-bottom:6px}
.rss-link{font-size:11px;color:#a78bfa;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nota-badge{position:absolute;top:10px;right:12px;font-size:11px;font-weight:800;padding:4px 9px;border-radius:12px;letter-spacing:.3px;display:inline-flex;align-items:center;gap:4px;cursor:help}
.nota-badge.b9{background:rgba(34,197,94,.18);color:#86efac;border:1px solid rgba(34,197,94,.4)}
.nota-badge.b7{background:rgba(99,102,241,.18);color:#a5b4fc;border:1px solid rgba(99,102,241,.4)}
.nota-badge.b5{background:rgba(234,179,8,.16);color:#fde68a;border:1px solid rgba(234,179,8,.35)}
.nota-badge.b3{background:rgba(107,114,128,.18);color:#9ca3af;border:1px solid rgba(107,114,128,.35)}
.nota-badge.b0{background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.4)}
.triagem-resumo{font-size:12px;color:#888;margin:6px 0 10px;padding:8px 10px;background:#0f1115;border-radius:6px;display:flex;gap:14px;flex-wrap:wrap}
.triagem-resumo strong{color:#a5b4fc}
.match-existente{margin:8px 0 0;padding:9px 11px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:6px;font-size:12px}
.match-toggle{display:flex;align-items:flex-start;gap:8px;cursor:pointer;color:#fde68a;line-height:1.5}
.match-toggle input{margin-top:2px;width:15px;height:15px;accent-color:#f59e0b;flex-shrink:0}
.match-toggle strong{color:#fcd34d;font-weight:700}
.match-preview{margin:5px 0 0 23px;color:#a98c4a;font-size:11.5px}
.match-preview a{color:#fcd34d;text-decoration:underline;text-decoration-color:rgba(252,211,77,.4)}
.match-sim-pill{display:inline-block;background:rgba(245,158,11,.25);color:#fcd34d;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;margin-right:6px}

.cost-preview{background:linear-gradient(135deg,#0f1a2e 0%,#1a1d23 100%);border:1px solid #2a3a5e;border-radius:10px;padding:16px 20px;margin-top:18px}
.cost-preview h3{margin:0 0 10px;font-size:14px;color:#93c5fd;font-weight:700;display:flex;align-items:center;gap:8px}
.cost-preview h3 .pill{font-size:10px;background:rgba(34,197,94,.2);color:#86efac;padding:2px 8px;border-radius:8px;font-weight:600;letter-spacing:.4px}
.cost-grid{display:grid;grid-template-columns:1fr auto;gap:6px 16px;font-size:13px;color:#bbb;margin-bottom:10px}
.cost-grid .cost-line{display:contents}
.cost-grid .cost-label{color:#999}
.cost-grid .cost-value{color:#e0e0e0;text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
.cost-grid .cost-value.muted{color:#666;font-weight:400}
.cost-total{display:flex;justify-content:space-between;align-items:center;padding-top:10px;margin-top:6px;border-top:1px solid #2a3a5e}
.cost-total .lbl{color:#a5b4fc;font-weight:700;font-size:13px}
.cost-total .vbrl{color:#fff;font-size:18px;font-weight:800;font-variant-numeric:tabular-nums}
.cost-total .vusd{color:#666;font-size:12px;margin-left:8px;font-weight:400}
.cost-time{margin-top:8px;font-size:12px;color:#888;display:flex;align-items:center;gap:6px}
.cost-time strong{color:#fde68a}
.cost-empty{color:#555;font-size:12px;font-style:italic;text-align:center;padding:14px 0}

.pillar-box{background:linear-gradient(135deg,#1e1a2e 0%,#1a1d23 100%);border:1px solid #4c1d95;border-radius:10px;padding:14px 18px;margin:14px 0}
.pillar-box h3{margin:0 0 8px;font-size:13px;color:#c4b5fd;font-weight:700;display:flex;align-items:center;gap:8px}
.pillar-box .topico-pill{display:inline-block;background:rgba(139,92,246,.25);color:#c4b5fd;padding:2px 9px;border-radius:8px;font-size:11px;font-weight:700;letter-spacing:.4px}
.pillar-found{font-size:13px;color:#ddd;line-height:1.55}
.pillar-found a{color:#a78bfa;font-weight:600}
.pillar-toggle{display:flex;align-items:center;gap:8px;margin-top:8px;cursor:pointer;color:#a78bfa;font-size:12px}
.pillar-toggle input{width:14px;height:14px;accent-color:#8b5cf6}
.pillar-no-match{font-size:12px;color:#888;font-style:italic}
.pillar-no-match strong{color:#bbb}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class='container'>
  <h1>🕸️ Cluster SEO — Multi-URL + RSS</h1>
  <p class='sub'>Carregue URLs manualmente OU cole um feed RSS (Google News, etc). A keyword/slug é extraída do título real de cada página — backlinks cruzados apontam pros slugs verdadeiros.</p>

  <div class='aviso'>
    <strong>Fluxo:</strong> carregue o RSS → marque os items desejados → escolha site + formato globais → (opcional) adicione linhas manuais → Rodar cluster. Google News criptografado é resolvido via Serper automaticamente.
  </div>

  <?php if ($erroRss): ?>
    <div class='erro'><?= htmlspecialchars($erroRss) ?></div>
  <?php endif; ?>

  <!-- FORM PRINCIPAL: posta pro gerarpost.php no submit normal, OU pro próprio cluster.php via formaction do botão Carregar RSS -->
  <form method='POST' action='gerarpost.php' onsubmit='return antesDeSubmeter(event)'>
    <input type='hidden' name='action' value='cluster'>
    <input type='hidden' name='cluster_items_json' id='cluster_items_json' value=''>
    <input type='hidden' name='formatos[]' value='discover'>
    <input type='hidden' name='auto_index' value='1'>

    <!-- TOGGLE: gerar imagem destacada via OpenAI + Web Story -->
    <div class='box' style='padding:14px 18px'>
      <label style='display:flex;align-items:center;gap:10px;cursor:pointer;margin:0'>
        <input type='checkbox' name='gerar_imagem_ia' value='1'>
        <span style='font-weight:600;color:#ccc'>🎨 Gerar imagem destacada via OpenAI (dall-e-3, 16:9) — ignora og:image da fonte</span>
      </label>
      <p class='hint' style='margin:4px 0 0 24px'>~$0.12 por artigo. Se falhar, cai silenciosamente no og:image original.</p>

      <label style='display:flex;align-items:center;gap:10px;cursor:pointer;margin:10px 0 0'>
        <input type='checkbox' name='queimar_overlay' value='1'>
        <span style='font-weight:600;color:#ccc'>🔥 Queimar frase chamativa SOBRE a imagem (estilo Netflix, no pixel)</span>
      </label>
      <p class='hint' style='margin:4px 0 0 24px'>Texto fica na imagem (og:image também). Funciona em qualquer tema. <strong>Atenção:</strong> Discover não recomenda imagens com texto — use só em testes ou em sites onde branding visual &gt; preview limpo.</p>

      <label style='display:flex;align-items:center;gap:10px;cursor:pointer;margin:12px 0 0'>
        <input type='checkbox' name='gerar_webstory' value='1'>
        <span style='font-weight:600;color:#ccc'>📽️ Criar Web Story automaticamente após cada post (5-9 cenas, Pexels + CTA)</span>
      </label>
      <p class='hint' style='margin:4px 0 0 24px'>Chama plugin <strong>wp-web-stories-ai</strong>. Narrativa: Hook → Desenvolvimento → Ação Final. Pexels busca imagens por cena. Silent failure.</p>

      <div style='margin-top:14px;padding-top:12px;border-top:1px solid #2a2e38'>
        <div style='font-weight:600;color:#ccc;margin-bottom:8px'>Social (posta após cada artigo publicar)</div>
        <label style='display:flex;align-items:center;gap:10px;cursor:pointer;margin:6px 0'>
          <input type='checkbox' name='post_fb' value='1'>
          <span style='color:#ccc;font-size:13px'>📘 Facebook Page (foto + link)</span>
        </label>
        <label style='display:flex;align-items:center;gap:10px;cursor:pointer;margin:6px 0'>
          <input type='checkbox' name='ig_feed_unico' value='1'>
          <span style='color:#ccc;font-size:13px'>📷 Instagram Feed (imagem única, reusa featured)</span>
        </label>
        <label style='display:flex;align-items:center;gap:10px;cursor:pointer;margin:6px 0'>
          <input type='checkbox' name='post_ig' value='1'>
          <span style='color:#ccc;font-size:13px'>🎠 Instagram Carrossel (tópicos do artigo)</span>
        </label>
        <div style='margin:4px 0 0 26px;display:flex;flex-direction:column;gap:4px'>
          <label style='display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;color:#bbb'>
            <input type='checkbox' name='ig_carrossel' value='1' checked>
            <span>Ativar geração de slides</span>
          </label>
          <label style='display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#bbb'>
            <input type='radio' name='carrossel_estilo' value='fotografico' checked>
            <span>🖼️ Fotográfico (featured + bottom gradient + CAIXA ALTA)</span>
          </label>
          <label style='display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#bbb'>
            <input type='radio' name='carrossel_estilo' value='tipografico'>
            <span>📰 Tipográfico (fundo liso + texto)</span>
          </label>
        </div>
      </div>
    </div>

    <!-- SEÇÃO RSS -->
    <div class='box'>
      <h2>📰 RSS (opcional — carregue múltiplas URLs de uma vez)</h2>

      <!-- Site selector pra contexto da Triagem (Haiku usa nicho/audiência/canibal pra pontuar) -->
      <div style='display:grid;grid-template-columns:1.4fr 1fr;gap:10px;margin-bottom:10px'>
        <div>
          <label style='font-size:11px;color:#888;margin-bottom:4px'>Site alvo (contexto pra triagem)</label>
          <select name='triagem_site' id='triagem-site'>
            <option value=''>— sem contexto (genérico) —</option>
            <?php
              $triagemSitePrev = $_POST['triagem_site'] ?? $_POST['rss_site_global'] ?? '';
              foreach ($sites as $slug => $s): ?>
              <option value='<?= htmlspecialchars($slug) ?>' <?= $slug===$triagemSitePrev?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style='font-size:11px;color:#888;margin-bottom:4px'>Triagem IA + Detector duplicatas</label>
          <div style='display:flex;flex-direction:column;gap:6px'>
            <label style='display:flex;align-items:center;gap:8px;background:#0f1115;border:1px solid #2a2e38;padding:8px 11px;border-radius:6px;cursor:pointer;font-size:12px;color:#ccc'>
              <input type='checkbox' name='skip_triagem' value='1' <?= isset($_POST['skip_triagem'])?'checked':'' ?>>
              <span>Pular triagem (não pontuar)</span>
            </label>
            <label style='display:flex;align-items:center;gap:8px;background:#0f1115;border:1px solid #2a2e38;padding:8px 11px;border-radius:6px;cursor:pointer;font-size:12px;color:#ccc'>
              <input type='checkbox' name='skip_matcher' value='1' <?= isset($_POST['skip_matcher'])?'checked':'' ?>>
              <span>Pular detector de duplicatas no WP</span>
            </label>
            <label style='display:flex;align-items:center;gap:8px;background:#0f1115;border:1px solid #2a2e38;padding:8px 11px;border-radius:6px;cursor:pointer;font-size:12px;color:#ccc'>
              <input type='checkbox' name='skip_pillar' value='1' <?= isset($_POST['skip_pillar'])?'checked':'' ?>>
              <span>Pular detector de pillar (topical authority)</span>
            </label>
          </div>
        </div>
      </div>

      <div style='display:flex;gap:10px;align-items:stretch'>
        <input type='url' name='rss_url' value='<?= htmlspecialchars($rssUrlValor) ?>' placeholder='https://news.google.com/rss/search?q=...' style='flex:1'>
        <button type='submit' class='btn-rss' formaction='cluster.php' formnovalidate name='action' value='load_rss'>Carregar RSS</button>
      </div>
      <p class='hint'>Cole a URL do feed RSS. Exemplos: Google News search RSS, RSS de portal, feeds específicos. O sistema extrai até 15 items. Triagem ativa custa ~\$0.001 por carga (Haiku) e auto-marca os items 7+.</p>
    </div>

    <?php if (!empty($itemsResolvidos)): ?>
      <!-- Seletores GLOBAIS aplicados aos items do RSS marcados -->
      <div class='box'>
        <h2>Site + formato globais (aplicam aos items do RSS marcados)</h2>
        <div class='globais'>
          <div>
            <label>Site de destino</label>
            <select name='rss_site_global' id='rss-site-global'>
              <option value=''>— escolha —</option>
              <?php
                $globalSitePrev = $_POST['rss_site_global'] ?? $_POST['triagem_site'] ?? '';
                foreach ($sites as $slug => $s): ?>
                <option value='<?= htmlspecialchars($slug) ?>' <?= $slug===$globalSitePrev?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Formato</label>
            <select name='rss_formato_global' id='rss-formato-global'>
              <?php foreach ($fmtInfo as $k => $f): ?>
                <option value='<?= $k ?>' <?= $k==='discover'?'selected':'' ?>><?= htmlspecialchars($f['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class='box'>
        <h2>📰 <?= count($itemsResolvidos) ?> items do RSS — marque os que quer no cluster</h2>

        <?php
          // Resumo da triagem (só renderiza se algum item teve nota atribuída)
          $temTriagem = false;
          $contNotas = ['b9'=>0,'b7'=>0,'b5'=>0,'b3'=>0,'b0'=>0];
          foreach ($itemsResolvidos as $it) {
              if (isset($it['nota'])) {
                  $temTriagem = true;
                  $n = (int)$it['nota'];
                  if ($n >= 9)      $contNotas['b9']++;
                  elseif ($n >= 7)  $contNotas['b7']++;
                  elseif ($n >= 5)  $contNotas['b5']++;
                  elseif ($n >= 3)  $contNotas['b3']++;
                  else              $contNotas['b0']++;
              }
          }
          // Ordena descendente por nota pra os melhores aparecerem primeiro (mantém i original via data-idx)
          $itemsOrdenados = $itemsResolvidos;
          if ($temTriagem) {
              uasort($itemsOrdenados, fn($a, $b) => ($b['nota'] ?? 5) <=> ($a['nota'] ?? 5));
          }
        ?>

        <?php if ($temTriagem): ?>
          <div class='triagem-resumo'>
            <span>🎯 Triagem Haiku ativa:</span>
            <?php if ($contNotas['b9']): ?><span><strong><?= $contNotas['b9'] ?></strong> 🔥 (9-10) auto-marcados</span><?php endif; ?>
            <?php if ($contNotas['b7']): ?><span><strong><?= $contNotas['b7'] ?></strong> 💎 (7-8) auto-marcados</span><?php endif; ?>
            <?php if ($contNotas['b5']): ?><span><strong><?= $contNotas['b5'] ?></strong> ⚡ (5-6)</span><?php endif; ?>
            <?php if ($contNotas['b3']): ?><span><strong><?= $contNotas['b3'] ?></strong> 🔘 (3-4)</span><?php endif; ?>
            <?php if ($contNotas['b0']): ?><span><strong><?= $contNotas['b0'] ?></strong> ❌ (0-2)</span><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($pillarDetectado) && !empty($pillarDetectado['topico'])): ?>
          <div class='pillar-box'>
            <h3>📚 Pillar / Topical Authority <span class='topico-pill'><?= htmlspecialchars($pillarDetectado['topico']) ?></span></h3>
            <?php if (!empty($pillarDetectado['pillar'])):
              $p = $pillarDetectado['pillar']; ?>
              <div class='pillar-found'>
                <strong>✅ Pillar detectado no WP:</strong>
                <a href='<?= htmlspecialchars($p['link']) ?>' target='_blank'><?= htmlspecialchars($p['title']) ?> ↗</a>
                <?php if (!empty($p['is_pillar_pattern'])): ?>
                  <span style='color:#a78bfa;font-size:11px;margin-left:6px'>(padrão "guia/tudo sobre" detectado)</span>
                <?php endif; ?>
                <br>
                <small style='color:#888'>Cada artigo do cluster vai linkar pra esse pillar com anchor text natural relacionado ao seu ângulo único — sinaliza ao Google que <em><?= htmlspecialchars($pillarDetectado['topico']) ?></em> é território do site.</small>
              </div>
              <input type='hidden' name='cluster_pillar_id'    value='<?= (int)$p['id'] ?>'>
              <input type='hidden' name='cluster_pillar_link'  value='<?= htmlspecialchars($p['link'], ENT_QUOTES) ?>'>
              <input type='hidden' name='cluster_pillar_title' value='<?= htmlspecialchars($p['title'], ENT_QUOTES) ?>'>
              <input type='hidden' name='cluster_pillar_topico' value='<?= htmlspecialchars($pillarDetectado['topico'], ENT_QUOTES) ?>'>
              <label class='pillar-toggle'>
                <input type='checkbox' name='skip_pillar_link' value='1'>
                <span>Pular linking pra pillar (não injetar internal link nos artigos)</span>
              </label>
            <?php else: ?>
              <div class='pillar-no-match'>
                Tópico umbrella detectado, mas <strong>nenhum pillar encontrado</strong> no WP. Posso criar um agora — fica como o post central de autoridade sobre <em><?= htmlspecialchars($pillarDetectado['topico']) ?></em>, e todos os artigos do cluster vão linkar pra ele.
              </div>
              <input type='hidden' name='cluster_pillar_topico' value='<?= htmlspecialchars($pillarDetectado['topico'], ENT_QUOTES) ?>'>
              <label class='pillar-toggle' style='margin-top:12px;background:rgba(139,92,246,.1);padding:8px 12px;border-radius:6px;border:1px solid rgba(139,92,246,.3)'>
                <input type='checkbox' name='create_pillar' value='1'>
                <strong style='color:#c4b5fd;font-size:13px'>✨ Criar pillar agora antes do cluster</strong>
                <span style='color:#888;font-size:11px;margin-left:auto'>+~$0.30 · ~90s extras</span>
              </label>
              <p style='margin:6px 0 0 22px;font-size:11px;color:#666;line-height:1.4'>
                Pillar evergreen 2000-3500 palavras com ToC, FAQ extenso e Schema Article+FAQPage. Será publicado <strong>antes</strong> dos cluster posts pra eles linkarem pra ele já existente.
              </p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div style='display:flex;gap:8px;margin:8px 0'>
          <button type='button' onclick='rssToggleAll(true)' class='btn-rss' style='padding:6px 12px;font-size:12px'>Marcar todos</button>
          <button type='button' onclick='rssToggleAll(false)' class='btn-rss' style='padding:6px 12px;font-size:12px'>Desmarcar</button>
          <?php if ($temTriagem): ?>
            <button type='button' onclick='rssMarcarPorNota(7)' class='btn-rss' style='padding:6px 12px;font-size:12px'>Só nota 7+</button>
            <button type='button' onclick='rssMarcarPorNota(9)' class='btn-rss' style='padding:6px 12px;font-size:12px'>Só nota 9+</button>
          <?php endif; ?>
          <span id='rss-count' style='margin-left:auto;font-size:12px;color:#888;align-self:center'>0 selecionados</span>
        </div>
        <div style='max-height:500px;overflow-y:auto'>
          <?php foreach ($itemsOrdenados as $i => $item):
            $nota = $item['nota'] ?? null;
            $motivo = $item['motivo'] ?? '';
            $autoMarcado = ($nota !== null && $nota >= 7);
            $classeBaixa = ($nota !== null && $nota < 5) ? ' lo' : '';
            $emoji = '';
            $bClass = '';
            if ($nota !== null) {
                if ($nota >= 9)      { $emoji = '🔥'; $bClass = 'b9'; }
                elseif ($nota >= 7)  { $emoji = '💎'; $bClass = 'b7'; }
                elseif ($nota >= 5)  { $emoji = '⚡'; $bClass = 'b5'; }
                elseif ($nota >= 3)  { $emoji = '🔘'; $bClass = 'b3'; }
                else                  { $emoji = '❌'; $bClass = 'b0'; }
            }
          ?>
            <label class='rss-item<?= $classeBaixa ?>'>
              <input type='checkbox' class='rss-check' data-idx='<?= $i ?>' data-nota='<?= $nota !== null ? (int)$nota : -1 ?>' onchange='rssUpdateCount();costPreview()' style='margin-top:4px;width:18px;height:18px;accent-color:#6366f1' <?= $autoMarcado ? 'checked' : '' ?>>
              <div style='flex:1;min-width:0;padding-right:<?= $nota!==null?'70px':'0' ?>'>
                <div class='rss-title'><?= htmlspecialchars($item['title']) ?></div>
                <div class='rss-meta'><?= htmlspecialchars($item['source'] ?? '') ?> · <?= htmlspecialchars($item['pubDate'] ?? '') ?></div>
                <?php if (!empty($item['description'])): ?>
                  <div class='rss-desc'><?= htmlspecialchars(mb_substr($item['description'], 0, 200)) ?>…</div>
                <?php endif; ?>
                <a href='<?= htmlspecialchars($item['link_resolvido']) ?>' target='_blank' class='rss-link'><?= htmlspecialchars($item['link_resolvido']) ?> ↗</a>
                <?php if (!empty($item['existe'])): ?>
                  <div class='match-existente'>
                    <label class='match-toggle' onclick='event.stopPropagation()'>
                      <input type='checkbox' class='rss-update-check' data-idx='<?= $i ?>' data-postid='<?= (int)$item['existe']['id'] ?>' data-posttitle='<?= htmlspecialchars($item['existe']['title'], ENT_QUOTES) ?>' onchange='costPreview()'>
                      <span><span class='match-sim-pill'><?= round((float)$item['existe']['similarity']) ?>%</span>🔄 Já existe post similar — <strong>atualizar em vez de criar novo</strong> (preserva URL + autoridade)</span>
                    </label>
                    <div class='match-preview'>→ <a href='<?= htmlspecialchars($item['existe']['link']) ?>' target='_blank' onclick='event.stopPropagation()'><?= htmlspecialchars($item['existe']['title']) ?> ↗</a></div>
                  </div>
                <?php endif; ?>
              </div>
              <?php if ($nota !== null): ?>
                <span class='nota-badge <?= $bClass ?>' title='<?= htmlspecialchars($motivo) ?>'><?= $emoji ?> <?= (int)$nota ?>/10</span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- LINHAS MANUAIS -->
    <div class='box'>
      <h2>Linhas manuais (opcional — adicionar URLs avulsas)</h2>
      <p style='color:#555;font-size:12px;margin-bottom:12px'>Use junto com o RSS ou sozinho. Cada linha = 1 post. Mínimo 2 items no total (RSS + manuais) para formar cluster.</p>
      <div id='rows'>
        <?php for ($i = 0; $i < $nLinhas; $i++):
            $selSite = $_POST['row_site'][$i]    ?? '';
            $selUrl  = $_POST['row_url'][$i]     ?? '';
            $selFmt  = $_POST['row_formato'][$i] ?? 'discover';
        ?>
          <div class='row'>
            <div>
              <?php if ($i === 0): ?><label>Site</label><?php endif; ?>
              <select name='row_site[]' class='inp-site'>
                <option value=''>— escolha —</option>
                <?php foreach ($sites as $slug => $s): ?>
                  <option value='<?= htmlspecialchars($slug) ?>' <?= $slug===$selSite?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <?php if ($i === 0): ?><label>URL para scraping</label><?php endif; ?>
              <input type='url' name='row_url[]' class='inp-url' value='<?= htmlspecialchars($selUrl) ?>' placeholder='https://... (URL final do portal)'>
            </div>
            <div>
              <?php if ($i === 0): ?><label>Formato</label><?php endif; ?>
              <select name='row_formato[]' class='inp-fmt'>
                <?php foreach ($fmtInfo as $k => $f): ?>
                  <option value='<?= $k ?>' <?= $k===$selFmt?'selected':'' ?>><?= htmlspecialchars($f['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><?php if ($i === 0): ?><label>&nbsp;</label><?php endif; ?><button type='button' class='rm' onclick='rmRow(this)'>×</button></div>
          </div>
        <?php endfor; ?>
      </div>
      <button type='button' class='add-row' onclick='addRow()'>+ Adicionar linha manual</button>
    </div>

    <?php include __DIR__ . '/_blocos_inputs.php'; ?>

    <!-- COST PREVIEW: atualiza em tempo real conforme user marca/desmarca itens e toggles -->
    <div class='cost-preview' id='cost-preview'>
      <h3>💰 Custo estimado <span class='pill' id='cost-mode-pill'>0 itens</span></h3>
      <div id='cost-body'>
        <div class='cost-empty'>Marque pelo menos 1 item do RSS ou preencha 1 linha manual pra ver o custo</div>
      </div>
    </div>

    <button type='submit' class='go'>Rodar cluster</button>
    <p class='hint' style='text-align:center'>~60-120s por item. Items do RSS marcados + linhas manuais com URL preenchida entram no cluster.</p>
  </form>

  <p style='text-align:center;color:#333;font-size:12px;margin-top:20px'>
    <a href='gerarpost.php'>Gerar post</a> · <a href='landing.php'>Landing</a> · <a href='maquina.php'>Máquina</a> · <a href='categorias.php'>Categorias</a> · <a href='trending.php'>Trending</a>
  </p>
</div>

<script>
const fmtOptions  = <?= json_encode(array_map(fn($k,$f)=>['k'=>$k,'n'=>$f['nome']], array_keys($fmtInfo), $fmtInfo)) ?>;
const siteOptions = <?= json_encode(array_map(fn($slug,$s)=>['k'=>$slug,'n'=>$s['name']], array_keys($sites), $sites)) ?>;
const rssData     = <?= json_encode($itemsResolvidos ?? [], JSON_UNESCAPED_UNICODE) ?>;

function addRow() {
  const wrap = document.getElementById('rows');
  const div  = document.createElement('div');
  div.className = 'row';
  const siteOpts = ['<option value="">— escolha —</option>'].concat(siteOptions.map(o => `<option value="${o.k}">${o.n}</option>`)).join('');
  const fmtOpts  = fmtOptions.map(o => `<option value="${o.k}"${o.k==='discover'?' selected':''}>${o.n}</option>`).join('');
  div.innerHTML = `
    <div><select name='row_site[]' class='inp-site'>${siteOpts}</select></div>
    <div><input type='url' name='row_url[]' class='inp-url' placeholder='https://... (URL final do portal)'></div>
    <div><select name='row_formato[]' class='inp-fmt'>${fmtOpts}</select></div>
    <div><button type='button' class='rm' onclick='rmRow(this)'>×</button></div>`;
  wrap.appendChild(div);
}

function rmRow(btn) {
  const rows = document.querySelectorAll('#rows .row');
  if (rows.length <= 1) return;
  btn.closest('.row').remove();
}

function rssToggleAll(v) {
  document.querySelectorAll('.rss-check').forEach(cb => cb.checked = v);
  rssUpdateCount();
  costPreview();
}

function rssMarcarPorNota(min) {
  document.querySelectorAll('.rss-check').forEach(cb => {
    const n = parseInt(cb.dataset.nota || '-1', 10);
    cb.checked = (n >= min);
  });
  rssUpdateCount();
  costPreview();
}

// COST PREVIEW — calcula em real-time conforme user marca/desmarca itens e toggles
const COST = {
  brl_per_usd:        5.50,   // taxa atualizada manualmente quando relevante
  per_create_sonnet:  0.18,   // Sonnet 4.6 com cache: ~$0.18/article (12k out + 4k in)
  per_refresh_sonnet: 0.11,   // atualizarPost: ~$0.11 (prompt menor, ~6k out)
  per_dalle_hd_1792:  0.12,   // DALL-E 3 HD 16:9
  per_carrossel:      0.03,   // gpt-4o-mini gera texto carrossel (~$0.03)
  per_tags:           0.005,  // gerarTags Sonnet pequeno
  allocator_fixed:    0.006,  // 1× Haiku batch por cluster (8 items)
  per_pillar:         0.30,   // Sonnet pillar evergreen ~3000 palavras output
  triagem_paid:       true,   // já pago no carregamento RSS
  seconds_per_item:   90,     // 60-120s média por item
};

function costPreview() {
  const allRssChecks = document.querySelectorAll('.rss-check:checked');
  let createCount = 0;
  let refreshCount = 0;
  allRssChecks.forEach(cb => {
    const idx = cb.dataset.idx;
    const updCb = document.querySelector('.rss-update-check[data-idx="' + idx + '"]');
    if (updCb && updCb.checked) refreshCount++;
    else createCount++;
  });
  // Linhas manuais com site+URL preenchidos entram como criação nova
  document.querySelectorAll('#rows .row').forEach(r => {
    const site = r.querySelector('.inp-site')?.value.trim() || '';
    const url  = r.querySelector('.inp-url')?.value.trim()  || '';
    if (site && url) createCount++;
  });

  const total = createCount + refreshCount;
  const body = document.getElementById('cost-body');
  const pill = document.getElementById('cost-mode-pill');
  if (total === 0) {
    body.innerHTML = '<div class="cost-empty">Marque pelo menos 1 item do RSS ou preencha 1 linha manual pra ver o custo</div>';
    pill.textContent = '0 itens';
    return;
  }

  const dalle  = !!document.querySelector('input[name="gerar_imagem_ia"]')?.checked;
  const story  = !!document.querySelector('input[name="gerar_webstory"]')?.checked;
  const carr   = !!document.querySelector('input[name="ig_carrossel"]')?.checked
              && !!document.querySelector('input[name="post_ig"]')?.checked;
  const igFeed = !!document.querySelector('input[name="ig_feed_unico"]')?.checked
              && !!document.querySelector('input[name="post_ig"]')?.checked;
  const fb     = !!document.querySelector('input[name="post_fb"]')?.checked;

  // Cálculos em USD
  let usd = 0;
  const lines = [];

  const createCost = createCount * COST.per_create_sonnet;
  if (createCount > 0) {
    usd += createCost;
    lines.push({lbl: createCount + '× criação nova (Sonnet 4.6)', val: '$' + createCost.toFixed(3)});
  }
  const refreshCost = refreshCount * COST.per_refresh_sonnet;
  if (refreshCount > 0) {
    usd += refreshCost;
    lines.push({lbl: refreshCount + '× refresh (atualizarPost)', val: '$' + refreshCost.toFixed(3)});
  }
  // Allocator (1×)
  if (total >= 2) {
    usd += COST.allocator_fixed;
    lines.push({lbl: 'ClusterAngleAllocator (Haiku 1×)', val: '$' + COST.allocator_fixed.toFixed(3)});
  }
  // Pillar criação (Sonnet pillar gerado antes do cluster)
  const createPillar = !!document.querySelector('input[name="create_pillar"]')?.checked;
  if (createPillar) {
    usd += COST.per_pillar;
    lines.push({lbl: '1× pillar (Sonnet evergreen ~3000w)', val: '$' + COST.per_pillar.toFixed(3)});
  }
  // Tags (cada criação nova)
  if (createCount > 0) {
    const tagsCost = createCount * COST.per_tags;
    usd += tagsCost;
    lines.push({lbl: createCount + '× tags (Sonnet pequeno)', val: '$' + tagsCost.toFixed(3)});
  }
  // DALL-E (só nas criações novas — refresh preserva imagem)
  if (dalle && createCount > 0) {
    const dalleCost = createCount * COST.per_dalle_hd_1792;
    usd += dalleCost;
    lines.push({lbl: createCount + '× imagem DALL-E 3 HD 16:9', val: '$' + dalleCost.toFixed(3)});
  } else if (dalle && createCount === 0) {
    lines.push({lbl: 'Imagem DALL-E (skip — só refreshes)', val: '$0.00', muted: true});
  }
  // Carrossel IG (cada item)
  if (carr) {
    const carrCost = total * COST.per_carrossel;
    usd += carrCost;
    lines.push({lbl: total + '× carrossel IG (gpt-4o-mini)', val: '$' + carrCost.toFixed(3)});
  }
  // Items grátis (Web Story Pexels, FB, IG feed simples)
  const gratuitos = [];
  if (story)  gratuitos.push('Web Story (Pexels grátis)');
  if (fb)     gratuitos.push('Facebook post');
  if (igFeed) gratuitos.push('IG feed único');
  if (gratuitos.length) lines.push({lbl: '+ ' + gratuitos.join(' + '), val: '$0.00', muted: true});

  const brl = usd * COST.brl_per_usd;
  const minutes = Math.round((total * COST.seconds_per_item) / 60);

  // Renderiza
  let html = '<div class="cost-grid">';
  lines.forEach(l => {
    html += '<div class="cost-line"><div class="cost-label">' + l.lbl + '</div>'
         + '<div class="cost-value' + (l.muted ? ' muted' : '') + '">' + l.val + '</div></div>';
  });
  html += '</div>';
  html += '<div class="cost-total"><div class="lbl">TOTAL ESTIMADO</div>'
       + '<div><span class="vbrl">R$ ' + brl.toFixed(2).replace('.', ',') + '</span>'
       + '<span class="vusd">~$' + usd.toFixed(3) + '</span></div></div>';
  html += '<div class="cost-time">⏱ ~<strong>' + minutes + ' min</strong> total · '
       + '<strong>' + Math.round(COST.seconds_per_item) + 's/item</strong> média</div>';
  body.innerHTML = html;

  let pillTxt = total + ' iten' + (total === 1 ? '' : 's');
  if (refreshCount > 0) pillTxt += ' (' + createCount + ' novo + ' + refreshCount + ' refresh)';
  pill.textContent = pillTxt;
}

function rssUpdateCount() {
  const n = document.querySelectorAll('.rss-check:checked').length;
  const el = document.getElementById('rss-count');
  if (el) el.textContent = n + ' selecionado' + (n === 1 ? '' : 's');
}

function antesDeSubmeter(e) {
  // Se o submit veio do botão "Carregar RSS" (action=load_rss), NÃO valida — só posta pro cluster.php
  const submitter = e && e.submitter;
  if (submitter && submitter.name === 'action' && submitter.value === 'load_rss') return true;

  const items = [];

  // 1) Items marcados do RSS (usam site/formato globais)
  const siteGlobal = (document.getElementById('rss-site-global')?.value || '').trim();
  const fmtGlobal  = (document.getElementById('rss-formato-global')?.value || 'discover').trim();
  const rssChecks = document.querySelectorAll('.rss-check:checked');
  if (rssChecks.length > 0) {
    if (!siteGlobal) {
      alert('Escolha o Site global (para items do RSS) ou desmarque todos antes de submeter.');
      return false;
    }
    rssChecks.forEach(cb => {
      const idx = parseInt(cb.dataset.idx, 10);
      const it  = rssData[idx];
      if (!it) return;
      // Modo refresh: se user marcou o toggle do match existente, manda update_post_id
      const updCb = document.querySelector('.rss-update-check[data-idx="' + idx + '"]');
      const updateId = (updCb && updCb.checked) ? parseInt(updCb.dataset.postid, 10) : 0;
      items.push({
        site: siteGlobal,
        keyword: '',
        url: it.link_resolvido || it.link || '',
        formato: fmtGlobal,
        formatoNome: (fmtOptions.find(o => o.k === fmtGlobal)?.n) || fmtGlobal,
        update_post_id: updateId > 0 ? updateId : null
      });
    });
  }

  // 2) Linhas manuais (cada uma com site + formato próprio)
  document.querySelectorAll('#rows .row').forEach(r => {
    const site = r.querySelector('.inp-site')?.value.trim() || '';
    const url  = r.querySelector('.inp-url')?.value.trim()  || '';
    const fmt  = r.querySelector('.inp-fmt')?.value.trim()  || 'discover';
    if (!site || !url) return;
    items.push({
      site: site,
      keyword: '',
      url: url,
      formato: fmt,
      formatoNome: (fmtOptions.find(o => o.k === fmt)?.n) || fmt
    });
  });

  if (items.length < 2) {
    alert('Adicione pelo menos 2 items válidos (RSS marcados + linhas manuais com site/URL preenchidos).');
    return false;
  }

  document.getElementById('cluster_items_json').value = JSON.stringify(items);
  return true;
}

rssUpdateCount();
costPreview();

// Hook costPreview em todos os toggles relevantes (delegação global)
document.addEventListener('change', function(e) {
  const t = e.target;
  if (!t || t.tagName !== 'INPUT' && t.tagName !== 'SELECT') return;
  // Triggers que afetam o cálculo de custo
  const triggers = ['gerar_imagem_ia', 'gerar_webstory', 'post_fb', 'post_ig', 'ig_carrossel', 'ig_feed_unico', 'carrossel_estilo', 'create_pillar', 'skip_pillar_link'];
  if (triggers.includes(t.name) || t.classList.contains('inp-site') || t.classList.contains('inp-url')) {
    costPreview();
  }
});

// Hook em addRow/rmRow pra recalcular quando user adiciona/remove linha manual
const _origAddRow = addRow;
addRow = function() { _origAddRow(); costPreview(); };
const _origRmRow = rmRow;
rmRow = function(btn) { _origRmRow(btn); costPreview(); };
</script>
</body>
</html>
