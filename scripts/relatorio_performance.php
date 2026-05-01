<?php
/**
 * relatorio_performance — consolida JSONL de PostPerformanceLog em ranking semanal.
 *
 * Lê data/post_performance/{YYYY-MM}.jsonl (mês corrente + anterior se cruzar limite),
 * agrega por (post_id × surface) e gera 5 rankings:
 *   1. TOP 10 — viralizou em Discover (mais clicks discover últimos 7d)
 *   2. TOP 10 — caiu (delta clicks > 50% queda comparando 7d×7d anteriores)
 *   3. TOP 10 — sem tração (publicou >7d, <50 impressões totais)
 *   4. Médias por cluster (clicks/post, CTR Discover)
 *   5. Médias por site (clicks/post, CTR Discover, % com tração)
 *
 * Output:
 *   - stdout em texto (com --table) ou JSON
 *   - opcional: --webhook → manda resumo via HealthWebhook (Discord/Telegram)
 *   - opcional: --salvar=path.json → grava report
 *
 * Uso:
 *   php scripts/relatorio_performance.php
 *   php scripts/relatorio_performance.php --site=cursosenac
 *   php scripts/relatorio_performance.php --janela=14
 *   php scripts/relatorio_performance.php --json
 *   php scripts/relatorio_performance.php --webhook
 *   php scripts/relatorio_performance.php --salvar=/tmp/report.json
 *
 * Cron sugerido (semanal, segunda 7am — após gsc_aprender):
 *   0 7 * * 1 /usr/bin/php /var/www/clonais/scripts/relatorio_performance.php --webhook --quiet
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/PostPerformanceLog.php';
require_once $ROOT . '/lib/ClickLog.php';
require_once $ROOT . '/lib/DiscoverDb.php';

// Args
$siteArg   = '';
$janela    = 7;
$jsonOut   = false;
$webhook   = false;
$salvar    = '';
$quiet     = false;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m))         $siteArg = $m[1];
    elseif (preg_match('/^--janela=(\d+)$/', $a, $m))  $janela  = max(1, (int)$m[1]);
    elseif (preg_match('/^--salvar=(.+)$/', $a, $m))   $salvar  = $m[1];
    elseif ($a === '--json')    $jsonOut = true;
    elseif ($a === '--webhook') $webhook = true;
    elseif ($a === '--quiet')   $quiet   = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo $m . "\n"; }

// Carrega JSONL — mês corrente + anterior (cobre janela cruzando virada)
$mesAtual    = date('Y-m');
$mesAnterior = date('Y-m', strtotime('-1 month'));
$entries = array_merge(
    PostPerformanceLog::lerLog($mesAtual),
    PostPerformanceLog::lerLog($mesAnterior)
);
if ($siteArg !== '') {
    $entries = array_values(array_filter($entries, fn($e) => ($e['site'] ?? '') === $siteArg));
}

// Carrega clicks (mesmos meses) — pode estar vazio se cc-click-logger ainda não rodou
$clickEntries = array_merge(
    ClickLog::lerLog($mesAtual),
    ClickLog::lerLog($mesAnterior)
);
if ($siteArg !== '') {
    $clickEntries = array_values(array_filter($clickEntries, fn($e) => ($e['site'] ?? '') === $siteArg));
}

if (empty($entries) && empty($clickEntries)) {
    if ($jsonOut) {
        echo json_encode(['ok' => false, 'erro' => 'sem dados', 'janela_d' => $janela], JSON_PRETTY_PRINT);
    } else {
        log_msg("[relatorio] Sem dados em post_performance/click_log. Crons já rodaram?", $quiet);
    }
    exit(0);
}

// Filtra janela: últimos N dias (baseado em ts da entrada)
$cutoffTs = strtotime("-{$janela} days");
$janelaEntries = array_values(array_filter($entries, fn($e) => strtotime($e['ts'] ?? '') >= $cutoffTs));

// Janela anterior (pra calcular queda)
$cutoffAnteriorIni = strtotime("-" . (2 * $janela) . " days");
$anterioresEntries = array_values(array_filter($entries, function ($e) use ($cutoffTs, $cutoffAnteriorIni) {
    $t = strtotime($e['ts'] ?? '');
    return $t >= $cutoffAnteriorIni && $t < $cutoffTs;
}));

// Clicks na janela atual (dedupe ip×dia)
$clicksAtual = array_values(array_filter($clickEntries, fn($e) => (int)($e['ts'] ?? 0) >= $cutoffTs));
$clicksAnterior = array_values(array_filter($clickEntries, function ($e) use ($cutoffTs, $cutoffAnteriorIni) {
    $t = (int)($e['ts'] ?? 0);
    return $t >= $cutoffAnteriorIni && $t < $cutoffTs;
}));
$clicksPorPostAtual = ClickLog::clicksPorPost($clicksAtual, true);
$clicksPorPostAnterior = ClickLog::clicksPorPost($clicksAnterior, true);

// ─── 1. TOP 10 viralizou em Discover ───
$discoverAtual = array_filter($janelaEntries, fn($e) => ($e['surface'] ?? '') === 'discover');
$porPost = [];
foreach ($discoverAtual as $e) {
    $pid = (int)($e['post_id'] ?? 0);
    if ($pid === 0) continue;
    if (!isset($porPost[$pid])) {
        $porPost[$pid] = [
            'post_id' => $pid, 'site' => $e['site'] ?? '', 'url' => $e['url'] ?? '',
            'clicks' => 0, 'impressions' => 0,
        ];
    }
    $porPost[$pid]['clicks']      += (int)($e['clicks'] ?? 0);
    $porPost[$pid]['impressions'] += (int)($e['impressions'] ?? 0);
}
usort($porPost, fn($a, $b) => $b['clicks'] <=> $a['clicks']);
$topViralizou = array_slice($porPost, 0, 10);
// Anexa clicks afiliado + CTR_afiliado_pct
foreach ($topViralizou as &$p) {
    $p['clicks_afiliado'] = (int)($clicksPorPostAtual[$p['post_id']] ?? 0);
    $p['ctr_afiliado_pct'] = $p['clicks'] > 0
        ? round(100 * $p['clicks_afiliado'] / $p['clicks'], 2)
        : 0;
}
unset($p);

// ─── 2. TOP 10 caiu (delta clicks atual vs anterior, todas surfaces) ───
$clicksAtualPorPost = [];
foreach ($janelaEntries as $e) {
    $pid = (int)($e['post_id'] ?? 0); if ($pid === 0) continue;
    $clicksAtualPorPost[$pid]['clicks'] = ($clicksAtualPorPost[$pid]['clicks'] ?? 0) + (int)($e['clicks'] ?? 0);
    $clicksAtualPorPost[$pid]['site']   = $e['site'] ?? '';
    $clicksAtualPorPost[$pid]['url']    = $e['url'] ?? '';
}
$clicksAnteriorPorPost = [];
foreach ($anterioresEntries as $e) {
    $pid = (int)($e['post_id'] ?? 0); if ($pid === 0) continue;
    $clicksAnteriorPorPost[$pid] = ($clicksAnteriorPorPost[$pid] ?? 0) + (int)($e['clicks'] ?? 0);
}
$caiu = [];
foreach ($clicksAtualPorPost as $pid => $info) {
    $cAnt  = $clicksAnteriorPorPost[$pid] ?? 0;
    $cAtual = $info['clicks'];
    if ($cAnt < 5) continue; // ruído estatístico
    $delta = $cAtual - $cAnt;
    $deltaPct = $cAnt > 0 ? round(($delta / $cAnt) * 100, 1) : 0;
    if ($deltaPct < -50) {
        $caiu[] = [
            'post_id' => $pid,
            'site'    => $info['site'],
            'url'     => $info['url'],
            'antes'   => $cAnt, 'agora' => $cAtual,
            'delta_pct' => $deltaPct,
        ];
    }
}
usort($caiu, fn($a, $b) => $a['delta_pct'] <=> $b['delta_pct']);
$topCaiu = array_slice($caiu, 0, 10);

// ─── 3. Sem tração (>7d publicado, <50 impressões totais) ───
$semTracao = [];
foreach ($clicksAtualPorPost as $pid => $info) {
    // Pega impressões totais de qualquer surface
    $imprTotal = 0;
    foreach ($janelaEntries as $e) {
        if ((int)($e['post_id'] ?? 0) !== $pid) continue;
        $imprTotal += (int)($e['impressions'] ?? 0);
    }
    if ($imprTotal < 50 && $info['clicks'] === 0) {
        $semTracao[] = [
            'post_id' => $pid,
            'site'    => $info['site'],
            'url'     => $info['url'],
            'impressions' => $imprTotal,
        ];
    }
}
$topSemTracao = array_slice($semTracao, 0, 10);

// ─── 3b. TOP 10 clicks afiliado (revenue proxy) ───
arsort($clicksPorPostAtual);
$topClicksAfiliado = [];
$rank = 0;
foreach ($clicksPorPostAtual as $pid => $clicksAfil) {
    if ($clicksAfil <= 0) continue;
    // Lookup metadata em janelaEntries
    $info = null;
    foreach ($janelaEntries as $e) {
        if ((int)($e['post_id'] ?? 0) === $pid) {
            $info = $e;
            break;
        }
    }
    $clicksAnterior = (int)($clicksPorPostAnterior[$pid] ?? 0);
    $deltaPct = $clicksAnterior > 0
        ? round(100 * ($clicksAfil - $clicksAnterior) / $clicksAnterior, 1)
        : null;
    $topClicksAfiliado[] = [
        'post_id'        => $pid,
        'site'           => $info['site'] ?? '?',
        'url'            => $info['url']  ?? '',
        'clicks_afiliado'=> $clicksAfil,
        'clicks_anterior'=> $clicksAnterior,
        'delta_pct'      => $deltaPct,
        'gsc_clicks'     => (int)($clicksAtualPorPost[$pid]['clicks'] ?? 0),
    ];
    if (++$rank >= 10) break;
}

// ─── 4 + 5. Médias por site (cluster requer DB lookup, fica pra depois) ───
$porSite = [];
foreach ($janelaEntries as $e) {
    $s = $e['site'] ?? '';
    if ($s === '') continue;
    $surface = $e['surface'] ?? '';
    $porSite[$s] ??= [
        'posts_unicos' => [], 'clicks_total' => 0, 'impressions_total' => 0,
        'discover_clicks' => 0, 'discover_impr' => 0,
    ];
    $porSite[$s]['posts_unicos'][(int)($e['post_id'] ?? 0)] = true;
    $porSite[$s]['clicks_total']      += (int)($e['clicks'] ?? 0);
    $porSite[$s]['impressions_total'] += (int)($e['impressions'] ?? 0);
    if ($surface === 'discover') {
        $porSite[$s]['discover_clicks'] += (int)($e['clicks'] ?? 0);
        $porSite[$s]['discover_impr']   += (int)($e['impressions'] ?? 0);
    }
}
// Clicks afiliado por site (somatório com dedupe)
$clicksAfilPorSite = [];
foreach ($clicksAtual as $e) {
    $s = $e['site'] ?? '';
    if ($s === '') continue;
    $clicksAfilPorSite[$s] = ($clicksAfilPorSite[$s] ?? 0) + 1;
}

$mediasSite = [];
foreach ($porSite as $s => $info) {
    $nPosts = count($info['posts_unicos']);
    $clicksAfil = (int)($clicksAfilPorSite[$s] ?? 0);
    $mediasSite[] = [
        'site'              => $s,
        'posts_com_dados'   => $nPosts,
        'clicks_por_post'   => $nPosts > 0 ? round($info['clicks_total'] / $nPosts, 1) : 0,
        'discover_ctr_pct'  => $info['discover_impr'] > 0
            ? round(100 * $info['discover_clicks'] / $info['discover_impr'], 2) : 0,
        'discover_clicks'   => $info['discover_clicks'],
        'clicks_afiliado'   => $clicksAfil,
        'cvr_afiliado_pct'  => $info['discover_clicks'] > 0
            ? round(100 * $clicksAfil / $info['discover_clicks'], 2) : 0,
    ];
}
usort($mediasSite, fn($a, $b) => $b['discover_clicks'] <=> $a['discover_clicks']);

// ─── Compose ───
$report = [
    'gerado_em'   => date('c'),
    'janela_d'    => $janela,
    'site_filtro' => $siteArg ?: 'todos',
    'totais'      => [
        'entries'                 => count($janelaEntries),
        'posts_unicos'            => count($clicksAtualPorPost),
        'sites_com_dado'          => count($porSite),
        'click_entries'           => count($clicksAtual),
        'posts_com_clicks_afiliado' => count(array_filter($clicksPorPostAtual, fn($c) => $c > 0)),
    ],
    'top_viralizou_discover' => $topViralizou,
    'top_caiu'               => $topCaiu,
    'top_sem_tracao'         => $topSemTracao,
    'top_clicks_afiliado'    => $topClicksAfiliado,
    'medias_por_site'        => $mediasSite,
];

// Salvar arquivo
if ($salvar !== '') {
    @file_put_contents($salvar, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    log_msg("[relatorio] salvo em {$salvar}", $quiet);
}

// Webhook (resumo curto)
if ($webhook) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        $totalAfil = array_sum($clicksPorPostAtual);
        HealthWebhook::info('Relatório semanal performance', [
            'janela_d'           => $janela,
            'posts_unicos'       => $report['totais']['posts_unicos'],
            'top_viral'          => count($topViralizou) > 0 ? $topViralizou[0]['post_id'] . ' (' . $topViralizou[0]['clicks'] . ' clicks Discover, ' . $topViralizou[0]['clicks_afiliado'] . ' clicks afiliado)' : 'nenhum',
            'top_revenue'        => count($topClicksAfiliado) > 0 ? $topClicksAfiliado[0]['post_id'] . ' (' . $topClicksAfiliado[0]['clicks_afiliado'] . ' clicks afiliado)' : 'nenhum',
            'total_clicks_afil'  => $totalAfil,
            'caiu_count'         => count($topCaiu),
            'sem_tracao_count'   => count($topSemTracao),
            'sites'              => count($mediasSite),
        ]);
        log_msg("[relatorio] webhook enviado", $quiet);
    }
}

// Output
if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "═══ Relatório de performance · janela {$janela}d ═══\n";
    echo "Total entries GSC: {$report['totais']['entries']} · clicks afiliado: {$report['totais']['click_entries']}\n";
    echo "Posts únicos: {$report['totais']['posts_unicos']} · com clicks afiliado: {$report['totais']['posts_com_clicks_afiliado']}\n\n";

    echo "▶ TOP " . count($topViralizou) . " viralizou em Discover\n";
    foreach ($topViralizou as $i => $p) {
        echo sprintf("  %d. [%s] post_id=%d · %d clicks Discover · %d impr · %d clicks afiliado (%.2f%% CTR_afil)\n     %s\n",
            $i + 1, $p['site'], $p['post_id'], $p['clicks'], $p['impressions'],
            $p['clicks_afiliado'], $p['ctr_afiliado_pct'], $p['url']);
    }

    echo "\n▶ TOP " . count($topClicksAfiliado) . " clicks afiliado (revenue proxy)\n";
    foreach ($topClicksAfiliado as $i => $p) {
        $delta = $p['delta_pct'] !== null ? sprintf(' (%s%%)', ($p['delta_pct'] > 0 ? '+' : '') . $p['delta_pct']) : '';
        echo sprintf("  %d. [%s] post_id=%d · %d clicks afiliado%s · GSC %d clicks · %s\n",
            $i + 1, $p['site'], $p['post_id'], $p['clicks_afiliado'], $delta,
            $p['gsc_clicks'], $p['url']);
    }

    echo "\n▶ TOP " . count($topCaiu) . " caiu vs janela anterior\n";
    foreach ($topCaiu as $i => $p) {
        echo sprintf("  %d. [%s] post_id=%d · %d → %d (%s%%) · %s\n",
            $i + 1, $p['site'], $p['post_id'], $p['antes'], $p['agora'],
            ($p['delta_pct'] > 0 ? '+' : '') . $p['delta_pct'], $p['url']);
    }

    echo "\n▶ TOP " . count($topSemTracao) . " sem tração (>7d, <50 impressões, 0 cliques)\n";
    foreach ($topSemTracao as $i => $p) {
        echo sprintf("  %d. [%s] post_id=%d · %d impressões · %s\n",
            $i + 1, $p['site'], $p['post_id'], $p['impressions'], $p['url']);
    }

    echo "\n▶ Médias por site\n";
    foreach ($mediasSite as $m) {
        echo sprintf("  %-20s · %3d posts · %5.1f cliques/post · %5.2f%% CTR Discover · %5d Discover · %4d afiliado · %5.2f%% CVR\n",
            $m['site'], $m['posts_com_dados'], $m['clicks_por_post'],
            $m['discover_ctr_pct'], $m['discover_clicks'],
            $m['clicks_afiliado'], $m['cvr_afiliado_pct']);
    }
    echo "\n";
}

exit(0);
