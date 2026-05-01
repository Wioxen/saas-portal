<?php
/**
 * tests/classificacao.php
 *
 * 49 casos-ouro que verificam a classificação de cluster está correta
 * para cada trend-exemplo em data/fixtures/trends_amostra.json.
 * Cobertura: 13 clusters + 3 bugs históricos + casos de disambiguação
 * (santos fc vs silvio santos, oscar schmidt vs premio oscar, etc.).
 *
 * Runner: /c/xampp/php/php.exe tests/classificacao.php
 * Exit code: 0 = tudo passou. 1 = alguma falha (falhas listadas).
 *
 * Uso em CI:
 *   - Rodar após qualquer mudança em TrendsTaxonomia::$clusters (keywords, categoria_ids)
 *   - Se uma correção legítima quebra um caso, atualizar o fixture com justificativa
 */

require_once __DIR__ . '/../lib/DiscoverClusterMatcher.php';

$fixturesPath = __DIR__ . '/../data/fixtures/trends_amostra.json';
if (!is_file($fixturesPath)) {
    fwrite(STDERR, "ERRO: fixture não encontrado em {$fixturesPath}\n");
    exit(2);
}

$data = json_decode((string)file_get_contents($fixturesPath), true);
$casos = $data['casos'] ?? [];
if (!$casos) {
    fwrite(STDERR, "ERRO: fixture vazio ou inválido\n");
    exit(2);
}

$total = count($casos);
$passaram = 0;
$falhas = [];
$porCluster = []; // cobertura

foreach ($casos as $caso) {
    $id       = (string)($caso['id'] ?? '?');
    $termo    = (string)($caso['termo'] ?? '');
    $esperado = (string)($caso['cluster_esperado'] ?? '');
    $porCluster[$esperado] = ($porCluster[$esperado] ?? 0) + 1;

    $res = DiscoverClusterMatcher::detectar([
        'termo'         => $termo,
        'categoria_ids' => $caso['categoria_ids'] ?? [],
        'relacionados'  => $caso['relacionados'] ?? [],
    ]);
    $obtido = $res['key'] ?? '';
    $score  = $res['score_detect'] ?? 0;

    if ($obtido === $esperado) {
        $passaram++;
    } else {
        $falhas[] = [
            'id'       => $id,
            'termo'    => $termo,
            'esperado' => $esperado,
            'obtido'   => $obtido,
            'score'    => $score,
        ];
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  tests/classificacao.php — {$total} casos\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Cobertura por cluster
echo "Cobertura por cluster:\n";
ksort($porCluster);
foreach ($porCluster as $cluster => $n) {
    echo sprintf("  • %-28s %d caso%s\n", $cluster, $n, $n === 1 ? '' : 's');
}
echo "\n";

if ($falhas) {
    echo "═══ FALHAS ═══════════════════════════════════════════════════\n";
    foreach ($falhas as $f) {
        echo sprintf(
            "  [%s] %s\n    esperado: %s\n    obtido:   %s (score=%d)\n\n",
            $f['id'], $f['termo'], $f['esperado'], $f['obtido'], $f['score']
        );
    }
}

echo "─── Resumo ─────────────────────────────────────────────────────\n";
echo sprintf("  Passaram: %d / %d (%.1f%%)\n", $passaram, $total, ($passaram / $total) * 100);
echo sprintf("  Falharam: %d\n", count($falhas));
echo "\n";

if ($passaram === $total) {
    echo "  ✓ Todos os casos passaram.\n";
    exit(0);
}
echo "  ✗ " . count($falhas) . " casos falharam. Revise o matcher ou atualize o fixture.\n";
exit(1);
