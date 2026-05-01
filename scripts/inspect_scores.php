<?php
/**
 * inspect_scores — diagnóstico de distribuição de scores por site.
 *
 * Útil pra calibrar auto_aprovar_score_min: mostra quantos trends caem em
 * cada faixa de score, e quais termos exemplificam cada faixa.
 *
 * Uso:
 *   php scripts/inspect_scores.php --site=leaodabarra
 *   php scripts/inspect_scores.php --site=leaodabarra --threshold=5.5  (simula novo threshold)
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

$site = null;
$thresholdSimulado = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site='))      $site = substr($arg, 7);
    elseif (str_starts_with($arg, '--threshold=')) $thresholdSimulado = (float)substr($arg, 12);
}
if (!$site) { fwrite(STDERR, "Uso: php scripts/inspect_scores.php --site=<slug>\n"); exit(1); }

require_once $ROOT . '/lib/DiscoverDb.php';
require $ROOT . '/config.php';

$db = new DiscoverDb();
$todos = $db->all(['site' => $site]);

if (empty($todos)) {
    echo "Nenhum trend pra {$site}\n";
    exit(0);
}

echo "\n═══ Distribuição de scores · {$site} (" . count($todos) . " trends) ═══\n";

$faixas = [
    '8.0+'    => 8.0,
    '7.0-7.9' => 7.0,
    '6.0-6.9' => 6.0,
    '5.0-5.9' => 5.0,
    '4.0-4.9' => 4.0,
    '<4.0'    => 0,
];

$contagem = array_fill_keys(array_keys($faixas), 0);
$exemplos = array_fill_keys(array_keys($faixas), []);

foreach ($todos as $t) {
    $s = (float)($t['score_discover'] ?? 0);
    foreach ($faixas as $label => $piso) {
        if ($s >= $piso) {
            $contagem[$label]++;
            if (count($exemplos[$label]) < 3) {
                $exemplos[$label][] = sprintf('%5.2f · %s', $s, mb_substr($t['termo'], 0, 60));
            }
            break;
        }
    }
}

foreach ($faixas as $label => $_) {
    echo sprintf("\n  %-9s %4d trends\n", $label, $contagem[$label]);
    foreach ($exemplos[$label] as $ex) echo "      {$ex}\n";
}

if ($thresholdSimulado !== null) {
    $passariam = 0;
    foreach ($todos as $t) {
        if ((float)($t['score_discover'] ?? 0) >= $thresholdSimulado) $passariam++;
    }
    echo "\n═══ Simulação threshold {$thresholdSimulado} ═══\n";
    echo "  Passariam: {$passariam} de " . count($todos) . " (" . round(100 * $passariam / count($todos), 1) . "%)\n";
}

echo "\n";
