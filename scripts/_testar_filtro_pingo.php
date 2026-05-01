<?php
/**
 * [TEMPORÁRIO — pode apagar depois] Testa o filtro do DiscoverPingo offline
 * contra trends já no DB. Zero custo de API.
 *
 * Uso:
 *   php scripts/_testar_filtro_pingo.php                 → testa todos os trends 'novo' do DB
 *   php scripts/_testar_filtro_pingo.php --site=X        → só de um site
 *   php scripts/_testar_filtro_pingo.php --status=novo   → filtra por status
 *   php scripts/_testar_filtro_pingo.php --verbose       → mostra cada decisão
 *   php scripts/_testar_filtro_pingo.php --so-rejeitados → mostra só os que cairiam
 */

require_once __DIR__ . '/../lib/DiscoverDb.php';
require_once __DIR__ . '/../lib/DiscoverPingo.php';

$site     = null;
$status   = 'novo';
$verbose  = false;
$soRejeitados = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site='))   $site = substr($arg, 7);
    elseif (str_starts_with($arg, '--status=')) $status = substr($arg, 9);
    elseif ($arg === '--verbose')          $verbose = true;
    elseif ($arg === '--so-rejeitados')    $soRejeitados = true;
}

$cfg = require __DIR__ . '/../config.php';
$db = new DiscoverDb();
$pingo = new DiscoverPingo($cfg, $db);

$filtros = ['status' => $status];
if ($site !== null) $filtros['site'] = $site;
$trends = $db->all($filtros);

if (empty($trends)) {
    echo "Nenhum trend encontrado com filtros: " . json_encode($filtros) . "\n";
    exit(0);
}

echo "TESTANDO FILTRO em " . count($trends) . " trends (status={$status}" .
     ($site ? ", site={$site}" : "") . ")\n";
echo str_repeat('─', 80) . "\n";

$stats = ['aprovado' => 0, 'rejeitado' => 0, 'bypass' => 0];
$porMotivo = [];
$rejeitados = [];

foreach ($trends as $t) {
    $termo = (string)($t['termo'] ?? '');
    if ($termo === '') continue;

    // Simula uma fonte com cluster_hint baseado nas categorias do trend
    // (na prática vem da config da fonte_pingo.json)
    $fonteSimulada = [
        'id' => 0,
        'nome' => $t['origem'] ?? 'manual',
        'cluster_hint' => 'curiosidades_geral',
    ];

    $r = $pingo->aplicarFiltro($termo, $fonteSimulada);
    $motivo = $r['motivo'];

    if (str_starts_with($motivo, 'bypass_')) {
        $stats['bypass']++;
    } elseif ($motivo === 'aprovado') {
        $stats['aprovado']++;
    } else {
        $stats['rejeitado']++;
        $porMotivo[$motivo] = ($porMotivo[$motivo] ?? 0) + 1;
        $rejeitados[] = [
            'id' => $t['id'],
            'termo' => $termo,
            'motivo' => $motivo,
            'pontos' => $r['pontos'] ?? 0,
            'detalhes' => $r['detalhes'] ?? [],
        ];
    }

    if ($verbose && (!$soRejeitados || $motivo !== 'aprovado')) {
        $icone = $motivo === 'aprovado' ? '✓' : (str_starts_with($motivo, 'bypass_') ? '◯' : '✗');
        printf("%s %-22s [%dpt] %s\n",
            $icone, $motivo, $r['pontos'] ?? 0,
            mb_substr($termo, 0, 60));
    }
}

echo "\n" . str_repeat('═', 80) . "\n";
echo "RESUMO\n";
echo str_repeat('═', 80) . "\n";
$total = array_sum($stats);
foreach ($stats as $k => $n) {
    $pct = $total > 0 ? round($n * 100 / $total) : 0;
    printf("  %-12s %4d  (%d%%)\n", $k, $n, $pct);
}

if (!empty($porMotivo)) {
    echo "\nREJEIÇÕES POR MOTIVO:\n";
    arsort($porMotivo);
    foreach ($porMotivo as $m => $n) {
        printf("  %-30s %4d\n", $m, $n);
    }
}

if (!empty($rejeitados) && !$verbose) {
    echo "\nAMOSTRA DE REJEITADOS (top 15):\n";
    foreach (array_slice($rejeitados, 0, 15) as $r) {
        printf("  ✗ [%s, %dpt] %s\n",
            $r['motivo'],
            $r['pontos'],
            mb_substr($r['termo'], 0, 65)
        );
    }
    if (count($rejeitados) > 15) {
        echo "  ... +" . (count($rejeitados) - 15) . " mais (use --verbose pra ver tudo)\n";
    }
}

echo "\nMODO ATIVO: " . ($pingo->statsFiltro['modo'] ?? 'warn') . "\n";
echo "(em modo 'warn' nada é rejeitado de verdade — apenas marcado pra revisão.\n";
echo " edite data/pingo_filtros.json e mude 'modo' pra 'block' depois de calibrar.)\n";
