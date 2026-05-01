<?php
/**
 * Refresh de posts existentes — melhora ranqueamento via atualização de conteúdo + data.
 *
 * Fluxo:
 *  Fase 1: usuário escolhe "dias sem atualização" → lista candidatos (mais desatualizados primeiro)
 *  Fase 2: usuário seleciona posts + formato + blocos de prompt → dispara refresh em lote
 *
 * Para cada post selecionado:
 *   1. Busca HTML atual do WP
 *   2. Roda Serper + Scraper novamente (fontes frescas do ano vigente)
 *   3. Claude::atualizarPost() — rewrite preservando essência, bumpando datas, adicionando seção nova
 *   4. LandingBuilder OU content_html + aplicação de Pretty Links + injeção decision_block/vs_comparisons
 *   5. WP PUT /posts/{id} com novo content + título + meta — bump automático de `modified`
 *   6. Opcional: bump `date` (republicação forçada pra voltar ao topo do feed)
 */
require_once __DIR__ . '/lib/Serper.php';
require_once __DIR__ . '/lib/Scraper.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/Wordpress.php';
require_once __DIR__ . '/lib/LandingBuilder.php';
require_once __DIR__ . '/lib/PrettyLinks.php';
require_once __DIR__ . '/lib/InstantIndexing.php';
require_once __DIR__ . '/_backlinks_preservar.php';

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/_site_helper.php';
$sites    = sitesDisponiveis();
$siteSlug = siteAtivoSlug($sites);
aplicarSite($cfg, $sites, $siteSlug);

$posts        = [];
$resultados   = [];
$erro         = null;
$fase         = $_POST['fase'] ?? 'listar';
$unidade      = $_POST['unidade'] ?? $_GET['unidade'] ?? 'dias';
$quantidade   = (int)($_POST['quantidade'] ?? $_GET['quantidade'] ?? 45);
$dias         = $unidade === 'horas' ? 0 : max(1, $quantidade);
$horas        = $unidade === 'horas' ? max(1, $quantidade) : 0;
$tagFilter    = $_POST['tag_filter'] ?? $_GET['tag_filter'] ?? 'todos';
$ordem        = $_POST['ordem'] ?? $_GET['ordem'] ?? 'asc';
$pagina       = max(1, (int)($_POST['pagina'] ?? $_GET['pagina'] ?? 1));
$dataDe       = trim($_POST['data_de'] ?? $_GET['data_de'] ?? '');
$dataAte      = trim($_POST['data_ate'] ?? $_GET['data_ate'] ?? '');
$modoFiltro   = ($dataDe !== '' && $dataAte !== '') ? 'datas' : 'periodo';
$porPagina    = 5;
$processado   = false;

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// Fase 1: listar candidatos
if ($fase === 'listar') {
    try {
        $posts = $wp->listarPostsParaAtualizar(max(1, $dias), $porPagina, $tagFilter, $horas, $ordem, $pagina, $dataDe, $dataAte);
    } catch (Throwable $e) {
        if ($pagina > 1 && stripos($e->getMessage(), 'page') !== false) {
            $posts = [];
        } else {
            $erro = 'Erro ao listar posts: ' . $e->getMessage();
        }
    }
}

// Fase 2b: gerar tags sem atualizar conteúdo
if ($fase === 'tags_only') {
    $processado = true;
    set_time_limit(0);
    ini_set('memory_limit', '256M');

    // Default ON pra Instant Indexing (Discover precisa de indexação rápida pra viralizar).
    $autoIndex = !isset($_POST['auto_index']) ? true : ($_POST['auto_index'] !== '0' && !empty($_POST['auto_index']));
    $selected  = json_decode($_POST['selected_json'] ?? '[]', true) ?: [];
    if (empty($selected)) {
        $erro = 'Selecione pelo menos 1 post.';
    } else {
        $claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
        $idxApi = $autoIndex ? new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']) : null;

        foreach ($selected as $i => $sel) {
            $pid    = (int)($sel['id'] ?? 0);
            $titulo = $sel['title'] ?? '';
            $r = ['id' => $pid, 'title' => $titulo, 'ok' => false, 'msg' => '', 'edit' => ''];
            if ($pid <= 0) { $r['msg'] = 'ID inválido'; $resultados[] = $r; continue; }

            try {
                $postData = $wp->getPost($pid);
                $html = $postData['content']['raw'] ?? $postData['content']['rendered'] ?? '';
                if ($html === '') throw new RuntimeException('Post sem conteúdo');

                $tagNames = $claude->gerarTags($titulo, $html);
                if (empty($tagNames)) throw new RuntimeException('Nenhuma tag gerada');

                $tagIds = $wp->resolverTags($tagNames);
                if (empty($tagIds)) throw new RuntimeException('Falha ao resolver tags no WP');

                // Atualiza APENAS o campo tags — não mexe em conteúdo/título/datas
                $resp = $wp->atualizarPost($pid, ['tags' => $tagIds]);

                $r['ok']    = true;
                $r['msg']   = count($tagIds) . ' tags aplicadas: ' . implode(', ', array_slice($tagNames, 0, 5));
                $r['edit']  = rtrim($cfg['wp_url'], '/') . "/wp-admin/post.php?post={$pid}&action=edit";

                // Indexação automática
                if ($idxApi) {
                    $link = $resp['link'] ?? ($postData['link'] ?? '');
                    if ($link) {
                        try {
                            $ix = $idxApi->indexar($link, 'URL_UPDATED');
                            $r['msg'] .= $ix['success'] ? ' · 📤 indexado (' . ($ix['method'] ?? '?') . ')' : ' · ⚠️ indexação falhou';
                        } catch (Throwable $e) { $r['msg'] .= ' · ⚠️ index: ' . $e->getMessage(); }
                    }
                }
            } catch (Throwable $e) {
                $r['msg'] = $e->getMessage();
            }
            $resultados[] = $r;
            if ($i < count($selected) - 1) usleep(800000); // 0.8s entre chamadas
        }
    }
}

// Fase 2: executar refresh
if ($fase === 'refresh') {
    $processado = true;
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $selected  = json_decode($_POST['selected_json'] ?? '[]', true) ?: [];
    $formato   = $_POST['formato'] ?? 'seo';
    $bumpDate  = !empty($_POST['bump_date']);
    // Default ON pra Instant Indexing (Discover precisa de indexação rápida pra viralizar).
    $autoIndex = !isset($_POST['auto_index']) ? true : ($_POST['auto_index'] !== '0' && !empty($_POST['auto_index']));

    // Blocos de prompt universais
    $blocos = [];
    for ($b = 1; $b <= 8; $b++) $blocos[] = trim($_POST["bloco{$b}"] ?? '');

    // Tags via IA (checkbox) — se marcado, usa as tags geradas pelo Claude
    $aiTags = !empty($_POST['ai_tags']);

    if (empty($selected)) {
        $erro = 'Selecione pelo menos 1 post.';
    } else {
        $serper  = new Serper($cfg['serper_api_key']);
        $scraper = new Scraper($cfg['user_agent'], $cfg['scrape_timeout']);
        $claude  = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);
        $builder = new LandingBuilder($cfg['site_name'] ?? 'Como Comprar', $cfg['wp_url'] ?? '', [
            'number' => $cfg['whatsapp_number'] ?? '',
            'group_url' => $cfg['whatsapp_group_url'] ?? '',
            'cta_text' => $cfg['whatsapp_cta_text'] ?? '',
        ]);
        $idxApi = $autoIndex ? new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']) : null;

        foreach ($selected as $i => $sel) {
            $pid = (int)($sel['id'] ?? 0);
            $titulo = $sel['title'] ?? '';
            $r = ['id' => $pid, 'title' => $titulo, 'ok' => false, 'msg' => '', 'edit' => ''];

            if ($pid <= 0) { $r['msg'] = 'ID inválido'; $resultados[] = $r; continue; }

            try {
                // 1. Busca post atual
                $postData = $wp->getPost($pid);
                $slug     = $postData['slug'] ?? null;
                $htmlAntigo = $postData['content']['raw'] ?? $postData['content']['rendered'] ?? '';
                $keyword = $titulo; // usa título como keyword base (simples e confiável)

                if ($htmlAntigo === '') throw new RuntimeException('Post sem conteúdo');

                // 2. Fontes frescas via Serper
                $fontes = [];
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

                if (empty($fontes)) throw new RuntimeException('Sem fontes frescas');

                // 3. Claude refresh
                $artigo = $claude->atualizarPost($keyword, $htmlAntigo, $fontes, $formato, $blocos);

                // 4. Renderiza HTML novo (mesmo pipeline do gerarPost)
                $plInstance = null;
                if (!empty($cfg['pretty_links'])) {
                    try { $plInstance = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']); } catch (Throwable $e) {}
                }

                $contentFinal = '';
                $hasProducts = !empty($artigo['products']);
                $hasContentHtml = !empty($artigo['content_html']);

                if ($hasProducts) {
                    // Reaproveita o pipeline de products → LandingBuilder
                    // Upload imagens novas (WebP + WP)
                    foreach ($artigo['products'] as &$prod) {
                        $imgUrl = $prod['image'] ?? '';
                        if ($imgUrl && preg_match('#^https?://#', $imgUrl)) {
                            try { $mid = $wp->uploadImagemPorUrl($imgUrl, $prod['name'] ?? ''); if ($mid) { $m = $wp->getMedia($mid); $prod['image'] = $m['source_url'] ?? $imgUrl; $prod['wp_media_id'] = $mid; } } catch (Throwable $e) {}
                        }
                    }
                    unset($prod);

                    // Pretty links nos products
                    if ($plInstance) {
                        $prefix = $cfg['pretty_links_prefix'] ?? 'go';
                        foreach ($artigo['products'] as &$prod) {
                            $a = $prod['affiliate_url'] ?? '';
                            if ($a !== '' && preg_match('#^https?://#', $a)) {
                                $sl = PrettyLinks::slugify($prod['name'] ?? 'produto', $prefix);
                                try { $pu = $plInstance->criarOuBuscar($a, $sl, $prod['name'] ?? '', true, '301'); if ($pu) $prod['affiliate_url'] = $pu; } catch (Throwable $e) {}
                            }
                        }
                        unset($prod);
                        if (!empty($artigo['decision_block']['picks'])) {
                            foreach ($artigo['decision_block']['picks'] as &$pk) {
                                $pu = $pk['affiliate_url'] ?? '';
                                if ($pu !== '' && preg_match('#^https?://#', $pu)) {
                                    $ps = PrettyLinks::slugify($pk['product_name'] ?? 'pick', $prefix);
                                    try { $pp = $plInstance->criarOuBuscar($pu, $ps, $pk['product_name'] ?? '', true, '301'); if ($pp) $pk['affiliate_url'] = $pp; } catch (Throwable $e) {}
                                }
                            }
                            unset($pk);
                        }
                    }

                    $contentFinal = $builder->buildHtml($artigo);
                    $contentFinal .= $builder->buildFaqHtml($artigo['faq'] ?? []);
                    $contentFinal .= $builder->buildSchemas($artigo);
                } elseif ($hasContentHtml) {
                    $contentFinal = '';
                    if (!empty($artigo['decision_block']['picks']) && $plInstance) {
                        $prefix = $cfg['pretty_links_prefix'] ?? 'go';
                        foreach ($artigo['decision_block']['picks'] as &$pk) {
                            $pu = $pk['affiliate_url'] ?? '';
                            if ($pu !== '' && preg_match('#^https?://#', $pu)) {
                                $ps = PrettyLinks::slugify($pk['product_name'] ?? 'pick', $prefix);
                                try { $pp = $plInstance->criarOuBuscar($pu, $ps, $pk['product_name'] ?? '', true, '301'); if ($pp) $pk['affiliate_url'] = $pp; } catch (Throwable $e) {}
                            }
                        }
                        unset($pk);
                    }
                    if (!empty($artigo['decision_block']['picks'])) {
                        $contentFinal .= '<div class="cc-content">' . $builder->buildDecisionBlock($artigo['decision_block']) . '</div>';
                    }
                    $contentFinal .= $artigo['content_html'];
                    if (!empty($artigo['vs_comparisons'])) {
                        $contentFinal .= '<div class="cc-content">' . $builder->buildVsComparisons($artigo['vs_comparisons']) . '</div>';
                    }
                    $contentFinal .= $builder->buildFaqHtml($artigo['faq'] ?? []);
                    // pretty links inline (mesmo que Maquina faz)
                    // Reuso simples via regex — sem hospedar novamente, só reescreve externos
                    if ($plInstance) {
                        $contentFinal = preg_replace_callback(
                            '#<a\s+([^>]*?)href=(["\'])(https?://[^"\']+)\2([^>]*)>(.*?)</a>#is',
                            function ($m) use ($plInstance, $cfg) {
                                $host = parse_url($m[3], PHP_URL_HOST) ?: '';
                                $siteHost = parse_url($cfg['wp_url'] ?? '', PHP_URL_HOST) ?: '';
                                if ($siteHost && stripos($host, $siteHost) !== false) return $m[0];
                                $anchor = trim(strip_tags($m[5]));
                                $slugL = PrettyLinks::slugify($anchor !== '' ? $anchor : $host, $cfg['pretty_links_prefix'] ?? 'go');
                                try { $pretty = $plInstance->criarOuBuscar($m[3], $slugL, $anchor ?: $host, true, '301'); } catch (Throwable $e) { $pretty = null; }
                                if (!$pretty) return $m[0];
                                return '<a ' . $m[1] . 'href=' . $m[2] . htmlspecialchars($pretty, ENT_QUOTES) . $m[2] . $m[4] . '>' . $m[5] . '</a>';
                            },
                            $contentFinal
                        ) ?: $contentFinal;
                    }
                } else {
                    throw new RuntimeException('Claude não retornou products nem content_html');
                }

                // 4.5. Preserva backlinks do antigo + enriquece com relacionados do WP pela keyword
                //      Internos: 3 posições estratégicas (após 1º, meio, antes do fechamento)
                //      Externos: sempre no final, bloco "Fontes e referências"
                $relacionadosWp = [];
                try {
                    $focus = $artigo['focus_keyword'] ?? $keyword;
                    $rel = $wp->buscarRelacionados($focus, 6, $pid);
                    foreach ($rel as $rp) {
                        if (!empty($rp['link']) && !empty($rp['title'])) {
                            $relacionadosWp[] = ['url' => $rp['link'], 'anchor' => html_entity_decode($rp['title'], ENT_QUOTES, 'UTF-8')];
                        }
                    }
                } catch (Throwable $e) { /* sem relacionados, segue */ }
                $contentFinal = atualizar_preservar_backlinks($htmlAntigo, $contentFinal, $cfg['wp_url'] ?? '', $relacionadosWp);

                // 5. Monta payload de update
                $novoTitulo = $artigo['title'] ?? $titulo;
                $payload = [
                    'title'   => $novoTitulo,
                    'content' => $contentFinal,
                    'excerpt' => $artigo['excerpt'] ?? '',
                    'meta'    => [
                        'rank_math_title'                => $artigo['meta_title'] ?? $novoTitulo,
                        'rank_math_description'          => $artigo['meta_description'] ?? '',
                        'rank_math_focus_keyword'        => $artigo['focus_keyword'] ?? $keyword,
                        'rank_math_facebook_title'       => $artigo['meta_title'] ?? $novoTitulo,
                        'rank_math_facebook_description' => $artigo['meta_description'] ?? '',
                        'rank_math_twitter_title'        => $artigo['meta_title'] ?? $novoTitulo,
                        'rank_math_twitter_description'  => $artigo['meta_description'] ?? '',
                    ],
                ];
                // NÃO mexemos em slug — preserva URL e link equity
                if ($bumpDate) {
                    $payload['date'] = (new DateTime('now'))->format('Y-m-d\TH:i:s');
                }

                // Tags — se marcado "gerar via IA", usa o array tags retornado pelo Claude
                if ($aiTags && !empty($artigo['tags']) && is_array($artigo['tags'])) {
                    try {
                        $tagNames = array_values(array_filter(array_map('strval', $artigo['tags'])));
                        if (!empty($tagNames)) $payload['tags'] = $wp->resolverTags($tagNames);
                    } catch (Throwable $e) {}
                }

                // 6. PUT no WP — bumpa `modified` automaticamente
                $resp = $wp->atualizarPost($pid, $payload);

                $r['ok']    = true;
                $r['msg']   = $bumpDate ? 'Atualizado + republicado (data bumpada)' : 'Atualizado (modified bumpado)';
                $r['title'] = $novoTitulo;
                $r['edit']  = rtrim($cfg['wp_url'], '/') . "/wp-admin/post.php?post={$pid}&action=edit";
                $r['link']  = $resp['link'] ?? ($postData['link'] ?? '');

                // Indexação automática
                if ($idxApi && $r['link']) {
                    try {
                        $ix = $idxApi->indexar($r['link'], 'URL_UPDATED');
                        $r['msg'] .= $ix['success'] ? ' · 📤 indexado (' . ($ix['method'] ?? '?') . ')' : ' · ⚠️ indexação falhou';
                    } catch (Throwable $e) { $r['msg'] .= ' · ⚠️ index: ' . $e->getMessage(); }
                }
            } catch (Throwable $e) {
                $r['msg'] = $e->getMessage();
            }
            $resultados[] = $r;
            if ($i < count($selected) - 1) sleep(3);
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
<title>Atualizar posts — Refresh de conteúdo</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1115;color:#e0e0e0;margin:0;padding:24px;line-height:1.5}
.container{max-width:1100px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}
.sub{color:#666;margin-bottom:20px;font-size:14px}
.box{background:#1a1d23;border:1px solid #2a2e38;padding:22px;border-radius:10px;margin-bottom:16px}
.box h2{margin-top:0;font-size:18px}
label{display:block;font-weight:600;margin:10px 0 6px;font-size:13px;color:#bbb}
input[type=number],input[type=text]{width:140px;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:15px}
input:focus,textarea:focus{outline:none;border-color:#6366f1}
button,.btn{padding:13px 22px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;display:inline-block;text-decoration:none}
.btn-refresh{background:linear-gradient(135deg,#f59e0b,#ef4444);width:100%;margin-top:14px;padding:16px}
.erro{background:#3b1818;border-left:4px solid #ef4444;padding:14px;border-radius:6px;margin-bottom:16px;color:#fca5a5}
.row{display:flex;gap:14px;align-items:end;flex-wrap:wrap}
.hint{font-size:11px;color:#444;margin-top:4px}
/* Tabela posts */
.p-table{width:100%;border-collapse:collapse;margin:12px 0;font-size:13px}
.p-table th{text-align:left;padding:8px 10px;background:#0f1115;color:#888;font-size:11px;text-transform:uppercase;border-bottom:2px solid #2a2e38}
.p-table td{padding:8px 10px;border-bottom:1px solid #1e2230;vertical-align:top}
.p-table tr:hover{background:#1e2230}
.p-title{font-weight:700;color:#e0e0e0;font-size:13px}
.age{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:800}
.age-old{background:#3b1818;color:#fca5a5}
.age-med{background:#3a2e1a;color:#fbbf24}
.age-new{background:#1a2e1a;color:#4ade80}
/* Formatos */
.fmt-bar{display:flex;gap:10px;margin-top:8px;flex-wrap:wrap}
.fmt-radio{display:flex;align-items:center;gap:6px;background:#0f1115;border:2px solid #2a2e38;border-radius:8px;padding:10px 16px;cursor:pointer}
.fmt-radio input{accent-color:#f59e0b}
.fmt-radio span{font-size:13px;color:#ccc;font-weight:600}
/* Resultados */
.result{background:#111318;border:1px solid #2a2e38;border-radius:8px;padding:12px 16px;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;font-size:13px}
.result.ok{border-left:4px solid #22c55e}
.result.fail{border-left:4px solid #ef4444}
.resumo{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:14px 0}
.resumo-item{text-align:center;background:#0f1115;padding:14px;border-radius:6px}
.resumo-item strong{display:block;font-size:24px;color:#f59e0b}
.resumo-item span{font-size:11px;color:#666}
.check-ctrl{display:flex;gap:8px;margin:8px 0}
.check-ctrl button{padding:6px 12px;font-size:12px;background:#1e2230;border:1px solid #2a2e38;border-radius:6px;color:#ccc}
a{color:#a78bfa;text-decoration:none}a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="container">
  <h1>🔄 Refresh de posts — atualização inteligente</h1>
  <p class="sub">Traga os posts antigos de volta ao topo: rewrite com fontes frescas + bump de data + preserva URL e backlinks.</p>

  <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <?php if ($processado && !empty($resultados)): ?>
    <div class="box">
      <h2>Resultado do refresh</h2>
      <div class="resumo">
        <div class="resumo-item"><strong><?= $totalOk ?></strong><span>atualizados</span></div>
        <div class="resumo-item"><strong><?= $totalErr ?></strong><span>erros</span></div>
      </div>
      <?php foreach ($resultados as $r): ?>
        <div class="result <?= $r['ok'] ? 'ok' : 'fail' ?>">
          <div>
            <span style="font-size:11px;color:#666">#<?= $r['id'] ?></span>
            <strong style="margin-left:6px"><?= htmlspecialchars($r['title']) ?></strong>
            <span style="color:#888;margin-left:8px;font-size:12px"><?= htmlspecialchars($r['msg']) ?></span>
          </div>
          <?php if ($r['ok'] && !empty($r['edit'])): ?>
            <a href="<?= htmlspecialchars($r['edit']) ?>" target="_blank">Editar →</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <p style="margin-top:14px"><a href="atualizar.php?quantidade=<?= $quantidade ?>&unidade=<?= $unidade ?>" class="btn">← Listar outros posts</a></p>
    </div>
  <?php endif; ?>

  <?php if ($fase === 'listar' && empty($resultados)): ?>
    <form method="POST">
      <input type="hidden" name="fase" value="listar">
      <?php include __DIR__ . '/_site_select.php'; ?>
      <div class="box">
        <h2>1. Quais posts atualizar?</h2>

        <div style="display:flex;gap:0;margin-bottom:14px">
          <button type="button" class="filtro-tab <?= $modoFiltro === 'periodo' ? 'active' : '' ?>" onclick="switchFiltro('periodo')" style="padding:10px 20px;font-size:13px;font-weight:600;border:2px solid #2a2e38;border-radius:8px 0 0 8px;cursor:pointer;background:<?= $modoFiltro === 'periodo' ? '#1a1d23' : '#0f1115' ?>;color:<?= $modoFiltro === 'periodo' ? '#a78bfa' : '#666' ?>">Por período</button>
          <button type="button" class="filtro-tab <?= $modoFiltro === 'datas' ? 'active' : '' ?>" onclick="switchFiltro('datas')" style="padding:10px 20px;font-size:13px;font-weight:600;border:2px solid #2a2e38;border-left:none;border-radius:0 8px 8px 0;cursor:pointer;background:<?= $modoFiltro === 'datas' ? '#1a1d23' : '#0f1115' ?>;color:<?= $modoFiltro === 'datas' ? '#a78bfa' : '#666' ?>">Por datas</button>
        </div>

        <div id="filtro-periodo" style="display:<?= $modoFiltro === 'periodo' ? 'flex' : 'none' ?>;gap:14px;align-items:end;flex-wrap:wrap">
          <div>
            <label>Sem atualização há</label>
            <div style="display:flex;gap:6px">
              <input type="number" name="quantidade" min="1" max="8760" value="<?= htmlspecialchars((string)$quantidade) ?>" style="width:100px">
              <select name="unidade" style="padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:15px;width:100px">
                <option value="horas" <?= $unidade === 'horas' ? 'selected' : '' ?>>horas</option>
                <option value="dias"  <?= $unidade === 'dias'  ? 'selected' : '' ?>>dias</option>
              </select>
            </div>
          </div>
        </div>

        <div id="filtro-datas" style="display:<?= $modoFiltro === 'datas' ? 'flex' : 'none' ?>;gap:14px;align-items:end;flex-wrap:wrap">
          <div>
            <label>Modificado de</label>
            <input type="date" name="data_de" value="<?= htmlspecialchars($dataDe) ?>" style="width:170px;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px;color-scheme:dark">
          </div>
          <div>
            <label>Até</label>
            <input type="date" name="data_ate" value="<?= htmlspecialchars($dataAte) ?>" style="width:170px;padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:14px;color-scheme:dark">
          </div>
        </div>

        <div class="row" style="margin-top:14px">
          <div>
            <label>Filtro de tags</label>
            <select name="tag_filter" style="padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:15px;min-width:160px">
              <option value="todos"    <?= $tagFilter === 'todos'    ? 'selected' : '' ?>>Todos os posts</option>
              <option value="sem_tags" <?= $tagFilter === 'sem_tags' ? 'selected' : '' ?>>Apenas sem tags</option>
              <option value="com_tags" <?= $tagFilter === 'com_tags' ? 'selected' : '' ?>>Apenas com tags</option>
            </select>
          </div>
          <div>
            <label>Ordenar por</label>
            <select name="ordem" style="padding:12px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#fff;font-size:15px;min-width:180px">
              <option value="asc"  <?= $ordem === 'asc'  ? 'selected' : '' ?>>Mais antigos primeiro</option>
              <option value="desc" <?= $ordem === 'desc' ? 'selected' : '' ?>>Mais recentes primeiro</option>
            </select>
          </div>
          <button type="submit">Listar candidatos →</button>
        </div>
        <p class="hint">Use <strong>Por período</strong> para filtrar posts sem update há X horas/dias. Use <strong>Por datas</strong> para buscar posts modificados entre duas datas específicas.</p>
      </div>
    </form>

    <?php if (!empty($posts)): ?>
      <?php
        $semTags = count(array_filter($posts, fn($p) => ($p['tag_count'] ?? 0) === 0));
      ?>
      <form method="POST" id="refreshForm">
        <input type="hidden" name="fase" id="fase-input" value="refresh">
        <input type="hidden" name="quantidade" value="<?= $quantidade ?>">
        <input type="hidden" name="unidade" value="<?= htmlspecialchars($unidade) ?>">
        <input type="hidden" name="data_de" value="<?= htmlspecialchars($dataDe) ?>">
        <input type="hidden" name="data_ate" value="<?= htmlspecialchars($dataAte) ?>">
        <input type="hidden" name="tag_filter" value="<?= htmlspecialchars($tagFilter) ?>">
        <input type="hidden" name="ordem" value="<?= htmlspecialchars($ordem) ?>">
        <input type="hidden" name="site" value="<?= htmlspecialchars($siteSlug) ?>">
        <input type="hidden" name="selected_json" id="sel-json" value="">

        <div class="box" style="padding:10px 18px;background:#0f1115">
          <span style="font-size:12px;color:#666">🌐 Site: <strong style="color:#a78bfa"><?= htmlspecialchars($sites[$siteSlug]['name'] ?? $siteSlug) ?></strong> — <?= htmlspecialchars(preg_replace('#^https?://#', '', $sites[$siteSlug]['wp_url'] ?? '')) ?></span>
        </div>

        <div class="box">
          <h2>2. <?= count($posts) ?> posts candidatos
            <small style="font-weight:normal;color:#666;font-size:13px">
              (<?php if ($modoFiltro === 'datas'): ?>de <?= htmlspecialchars($dataDe) ?> a <?= htmlspecialchars($dataAte) ?><?php else: ?>≥<?= $quantidade ?> <?= $unidade ?> sem update<?php endif; ?> ·
              <?php if ($tagFilter === 'sem_tags'): ?>só sem tags
              <?php elseif ($tagFilter === 'com_tags'): ?>só com tags
              <?php else: ?>todos · <?= $semTags ?> sem tags<?php endif; ?>)
            </small>
          </h2>
          <div class="check-ctrl">
            <button type="button" onclick="toggleAll(true)">Marcar todos</button>
            <button type="button" onclick="toggleAll(false)">Desmarcar</button>
            <?php if ($tagFilter !== 'sem_tags' && $semTags > 0): ?>
              <button type="button" onclick="toggleSemTags()">Marcar só sem tags</button>
            <?php endif; ?>
          </div>
          <table class="p-table">
            <thead><tr><th>✓</th><th>Título</th><th>Tags</th><th>Idade</th><th>Última atualização</th></tr></thead>
            <tbody>
              <?php foreach ($posts as $i => $p):
                $ageDays  = $p['age_days'];
                $ageHours = $p['age_hours'] ?? ($ageDays * 24);
                $cls = $ageDays >= 180 ? 'age-old' : ($ageDays >= 60 ? 'age-med' : 'age-new');
                if ($ageDays < 1) { $ageLabel = $ageHours . 'h'; $cls = 'age-new'; }
                elseif ($ageDays < 7) { $ageLabel = $ageDays . 'd ' . ($ageHours % 24) . 'h'; }
                else { $ageLabel = $ageDays . 'd'; }
                $mod = $p['modified'] ? date('d/m/Y H:i', strtotime($p['modified'])) : '—';
                $tagCount = $p['tag_count'] ?? 0;
                $tagCls = $tagCount === 0 ? 'age-old' : 'age-new';
              ?>
                <tr>
                  <td><input type="checkbox" class="p-check" data-idx="<?= $i ?>" data-tags="<?= $tagCount ?>"></td>
                  <td><div class="p-title"><?= htmlspecialchars($p['title']) ?></div>
                      <a href="<?= htmlspecialchars($p['link']) ?>" target="_blank" style="font-size:11px">abrir ↗</a></td>
                  <td><span class="age <?= $tagCls ?>"><?= $tagCount ?> tag<?= $tagCount === 1 ? '' : 's' ?></span></td>
                  <td><span class="age <?= $cls ?>"><?= $ageLabel ?></span></td>
                  <td style="color:#666;font-size:12px"><?= $mod ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php
            $queryParams = [
              'quantidade' => $quantidade,
              'unidade'    => $unidade,
              'tag_filter' => $tagFilter,
              'ordem'      => $ordem,
            ];
            if ($dataDe !== '')  $queryParams['data_de']  = $dataDe;
            if ($dataAte !== '') $queryParams['data_ate'] = $dataAte;
            $queryBase = http_build_query($queryParams);
            $temProx = count($posts) >= $porPagina;
          ?>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
            <div>
              <?php if ($pagina > 1): ?>
                <a href="atualizar.php?<?= $queryBase ?>&pagina=<?= $pagina - 1 ?>" class="btn" style="padding:8px 16px;font-size:13px">← Anterior</a>
              <?php endif; ?>
            </div>
            <span style="color:#666;font-size:12px">Página <?= $pagina ?> · <?= count($posts) ?> post<?= count($posts) !== 1 ? 's' : '' ?></span>
            <div>
              <?php if ($temProx): ?>
                <a href="atualizar.php?<?= $queryBase ?>&pagina=<?= $pagina + 1 ?>" class="btn" style="padding:8px 16px;font-size:13px">Próximo →</a>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="box">
          <h2>3. Formato do refresh</h2>
          <p class="hint" style="margin-bottom:10px">Escolha o teor que o post deve assumir após a atualização (muda o tom/estrutura).</p>
          <div class="fmt-bar">
            <?php foreach ($fmtInfo as $key => $f): ?>
              <label class="fmt-radio">
                <input type="radio" name="formato" value="<?= $key ?>" <?= $key === 'seo' ? 'checked' : '' ?>>
                <span><?= $f['nome'] ?> <small style="color:#666">— <?= $f['estilo'] ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
          <label style="margin-top:16px;display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="bump_date" value="1">
            <span style="font-weight:600;color:#ccc">Também bumpar a <code>date</code> (republicar agora — aparece como novo no feed/Discover)</span>
          </label>
          <p class="hint">Por padrão só o <code>modified</code> é bumpado (mais conservador). Marque se quiser empurrar pro topo dos canais de aquisição.</p>

          <label style="margin-top:12px;display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="auto_index" value="1" checked>
            <span style="font-weight:600;color:#ccc">📤 Solicitar indexação automática após o update (Rank Math Instant Indexing / IndexNow)</span>
          </label>
          <p class="hint">Dispara o endpoint <code>cc/v1/indexar</code> por URL atualizada. Requer plugin <code>cc-instant-indexing-api</code> ativo no site.</p>
        </div>

        <div class="box">
          <h2>4. Tags do post <small style="font-weight:normal;color:#555;font-size:12px">(opcional)</small></h2>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:8px 0">
            <input type="checkbox" name="ai_tags" value="1" checked style="width:18px;height:18px;accent-color:#f59e0b">
            <span style="font-weight:600;color:#ccc">🤖 Gerar tags automaticamente com a IA</span>
          </label>
          <p class="hint">Quando marcado, usa as tags que o Claude já gera no schema do artigo (5 tags relevantes baseadas no conteúdo) e <strong>substitui</strong> as tags atuais do post. Tags são criadas automaticamente no WP se não existirem. Desmarque para manter as tags atuais intactas.</p>
        </div>

        <?php include __DIR__ . '/_blocos_inputs.php'; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px">
          <button type="submit" class="btn-refresh" style="margin-top:0" onclick="setFaseERun('refresh')">🔄 Atualizar posts (completo)</button>
          <button type="submit" class="btn-refresh" style="margin-top:0;background:linear-gradient(135deg,#10b981,#059669)" onclick="setFaseERun('tags_only')">🏷️ Gerar só tags (rápido)</button>
        </div>
        <p class="hint" style="text-align:center;margin-top:8px">
          <strong>Atualizar completo</strong>: ~60-120s/post — fontes frescas + Claude refresh + WP update. Preserva URL e backlinks.<br>
          <strong>Só tags</strong>: ~3-5s/post — só chama o Claude para gerar tags novas e atualiza o campo. Não toca no conteúdo, título ou data.
        </p>
      </form>

      <script>
      const postsData = <?= json_encode($posts, JSON_UNESCAPED_UNICODE) ?>;
      function toggleAll(v) {
        document.querySelectorAll('.p-check').forEach(cb => cb.checked = v);
      }
      function toggleSemTags() {
        document.querySelectorAll('.p-check').forEach(cb => {
          cb.checked = parseInt(cb.dataset.tags || '0', 10) === 0;
        });
      }
      function setFaseERun(fase) {
        document.getElementById('fase-input').value = fase;
        prepareJson();
      }
      function prepareJson() {
        const sel = [];
        document.querySelectorAll('.p-check:checked').forEach(cb => {
          const idx = parseInt(cb.dataset.idx);
          const p = postsData[idx];
          sel.push({id: p.id, title: p.title});
        });
        document.getElementById('sel-json').value = JSON.stringify(sel);
      }
      </script>
    <?php endif; ?>
  <?php endif; ?>

  <script>
  function switchFiltro(modo) {
    const periodo = document.getElementById('filtro-periodo');
    const datas = document.getElementById('filtro-datas');
    if (!periodo || !datas) return;
    if (modo === 'datas') {
      periodo.style.display = 'none';
      datas.style.display = 'flex';
      periodo.querySelectorAll('input,select').forEach(el => el.disabled = true);
      datas.querySelectorAll('input').forEach(el => el.disabled = false);
    } else {
      periodo.style.display = 'flex';
      datas.style.display = 'none';
      periodo.querySelectorAll('input,select').forEach(el => el.disabled = false);
      datas.querySelectorAll('input').forEach(el => el.disabled = true);
    }
    document.querySelectorAll('.filtro-tab').forEach(btn => {
      const isModo = btn.textContent.trim().includes(modo === 'datas' ? 'datas' : 'período');
      btn.style.background = isModo ? '#1a1d23' : '#0f1115';
      btn.style.color = isModo ? '#a78bfa' : '#666';
    });
  }
  if (document.getElementById('filtro-datas')) {
    const modo = '<?= $modoFiltro ?>';
    if (modo === 'periodo') {
      const datas = document.getElementById('filtro-datas');
      datas.querySelectorAll('input').forEach(el => el.disabled = true);
    } else {
      const periodo = document.getElementById('filtro-periodo');
      periodo.querySelectorAll('input,select').forEach(el => el.disabled = true);
    }
  }
  </script>

  <p style="text-align:center;color:#333;font-size:12px;margin-top:24px">
    <a href="maquina.php">Máquina</a> · <a href="massa.php">Em massa</a> · <a href="categorias.php">Categorias</a> · <a href="trending.php">Trending</a>
  </p>
</div>
</body>
</html>
