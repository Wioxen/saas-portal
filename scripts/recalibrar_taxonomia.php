<?php
/**
 * scripts/recalibrar_taxonomia.php
 *
 * Lê cache do Search Console + estatísticas reais do DB e gera RELATÓRIO
 * (não aplica nada automaticamente) sugerindo ajustes de threshold/RPM
 * por cluster.
 *
 * Lógica:
 *   1. Para cada query top do GSC, classifica via DiscoverClusterMatcher
 *   2. Agrega CTR, impressões, cliques, posição média por cluster
 *   3. Compara com threshold/RPM atual da TrendsTaxonomia
 *   4. Sugere ajustes:
 *      - Cluster com CTR alto + posição ruim → reduzir threshold (capturar mais)
 *      - Cluster com volume zero → considerar desativar fonte de pingo
 *      - Cluster com CTR alto → manter agressivo
 *
 * Uso: php scripts/recalibrar_taxonomia.php [--site=cursosenac]
 */

$siteFiltro = '';
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteFiltro = $m[1];
}

require_once __DIR__ . '/../lib/TrendsTaxonomia.php';
require_once __DIR__ . '/../lib/DiscoverClusterMatcher.php';

$cacheDir = __DIR__ . '/../data/search_console_cache';
if (!is_dir($cacheDir)) {
    fwrite(STDERR, "Cache GSC não existe. Rode scripts/sync_search_console.php primeiro.\n");
    exit(2);
}

$arquivos = glob($cacheDir . '/*.json');
if (empty($arquivos)) {
    fwrite(STDERR, "Cache vazio. Rode scripts/sync_search_console.php primeiro.\n");
    exit(2);
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Recalibração baseada em GSC · " . date('Y-m-d H:i') . "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$agregadosPorCluster = [];
$totalQueries = 0;
$totalSites = 0;
$queriesNaoClassificadas = 0;

foreach ($arquivos as $arq) {
    $data = json_decode((string)@file_get_contents($arq), true);
    if (!is_array($data) || empty($data['queries']['rows'])) continue;
    $slug = (string)($data['site_slug'] ?? basename($arq, '.json'));
    if ($siteFiltro !== '' && $slug !== $siteFiltro) continue;

    $totalSites++;
    foreach ($data['queries']['rows'] as $row) {
        $termo = (string)($row['keys'][0] ?? '');
        if ($termo === '') continue;
        $totalQueries++;

        // Classifica via matcher
        $cluster = DiscoverClusterMatcher::detectar(['termo' => $termo]);
        $ck = $cluster['key'] ?? null;
        if (!$ck || ($cluster['score_detect'] ?? 0) === 0) {
            $queriesNaoClassificadas++;
            continue;
        }

        if (!isset($agregadosPorCluster[$ck])) {
            $agregadosPorCluster[$ck] = [
                'queries' => 0,
                'clicks'  => 0,
                'impr'    => 0,
                'pos_soma'=> 0,
                'ctr_top' => null,
                'top_queries' => [],
            ];
        }
        $agg =& $agregadosPorCluster[$ck];
        $agg['queries']++;
        $agg['clicks']  += (int)($row['clicks'] ?? 0);
        $agg['impr']    += (int)($row['impressions'] ?? 0);
        $agg['pos_soma']+= (float)($row['position'] ?? 0);
        if (count($agg['top_queries']) < 3 && (int)($row['clicks'] ?? 0) > 0) {
            $agg['top_queries'][] = $termo;
        }
        unset($agg);
    }
}

if ($totalQueries === 0) {
    echo "  ⚠ Nenhuma query encontrada no cache. Aguarde tráfego acumular ou rode sync.\n";
    exit(0);
}

echo "Estatísticas globais:\n";
echo sprintf("  Sites processados:        %d\n", $totalSites);
echo sprintf("  Queries totais:           %d\n", $totalQueries);
echo sprintf("  Queries classificadas:    %d (%.1f%%)\n",
    $totalQueries - $queriesNaoClassificadas,
    ($totalQueries - $queriesNaoClassificadas) * 100 / max(1, $totalQueries));
echo sprintf("  Não classificadas:        %d\n", $queriesNaoClassificadas);
echo "\n";

echo "Performance por cluster:\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";
printf("%-26s %4s %5s %6s %6s %5s   %s\n", 'cluster', 'qry', 'click', 'impr', 'CTR%', 'posM', 'thresh atual → sug.');
echo str_repeat('─', 90) . "\n";

uasort($agregadosPorCluster, fn($a, $b) => $b['impr'] <=> $a['impr']);

$sugestoes = [];
foreach ($agregadosPorCluster as $ck => $a) {
    $ctr = $a['impr'] > 0 ? round($a['clicks'] * 100 / $a['impr'], 2) : 0;
    $posMed = $a['queries'] > 0 ? round($a['pos_soma'] / $a['queries'], 1) : 0;
    $threshAtual = TrendsTaxonomia::threshold($ck);
    $rpm = TrendsTaxonomia::rpm($ck);

    // Lógica de sugestão (conservadora — sempre sugerir mudanças >= 0.5 pra evitar ruído):
    $threshSug = $threshAtual;
    $razao = '';
    if ($a['impr'] >= 100 && $ctr >= 5.0 && $posMed > 15) {
        $threshSug = max(4.0, $threshAtual - 0.5);
        $razao = "CTR {$ctr}% bom mas posição {$posMed} ruim — capturar mais trends";
    } elseif ($a['impr'] >= 100 && $ctr < 1.0) {
        $threshSug = min(8.5, $threshAtual + 0.5);
        $razao = "CTR {$ctr}% baixo — apertar filtro pra evitar conteúdo ruim";
    } elseif ($a['queries'] === 0) {
        $razao = "zero queries — considerar desativar pingo deste cluster";
    } else {
        $razao = "manter (volume insuficiente ou já calibrado)";
    }

    $mudanca = $threshSug !== $threshAtual ? sprintf("\033[33m%.1f → %.1f\033[0m", $threshAtual, $threshSug) : sprintf("%.1f", $threshAtual);
    printf("%-26s %4d %5d %6d %6.2f %5.1f   %s\n",
        TrendsTaxonomia::labelCurto($ck),
        $a['queries'],
        $a['clicks'],
        $a['impr'],
        $ctr,
        $posMed,
        $mudanca);
    if (!empty($a['top_queries'])) {
        echo "  ↳ top: " . implode(' · ', array_slice($a['top_queries'], 0, 3)) . "\n";
    }
    if ($razao) echo "  ↳ {$razao}\n";

    if ($threshSug !== $threshAtual) {
        $sugestoes[] = ['cluster' => $ck, 'atual' => $threshAtual, 'sugerido' => $threshSug, 'razao' => $razao];
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
if (empty($sugestoes)) {
    echo "  ✓ Nenhum ajuste sugerido — calibração atual coerente com dados.\n";
} else {
    echo "  📝 " . count($sugestoes) . " sugestões de ajuste:\n";
    foreach ($sugestoes as $s) {
        echo sprintf("     • %s: threshold %.1f → %.1f  (%s)\n",
            TrendsTaxonomia::labelCurto($s['cluster']),
            $s['atual'], $s['sugerido'], $s['razao']);
    }
    echo "\n  Aplicação manual: editar lib/TrendsTaxonomia.php e mudar 'threshold' do cluster.\n";
    echo "  (Não aplico automaticamente — ajustes editoriais devem ser revisados por humano.)\n";
}
echo "═══════════════════════════════════════════════════════════════════════════\n";
exit(0);
