<?php
/**
 * status_site — relatório de estado por site (CLI).
 *
 * Mostra:
 *   - Distribuição de trends por status (aprovado/ignorado/novo/etc) no DB
 *   - Top 10 aprovados por score
 *   - Top 10 ignorados por score (perto do threshold)
 *   - Estado da fila atual (pending/running/done/failed)
 *   - Últimos 20 ticks processados (do log_tick.log)
 *
 * Uso:
 *   php scripts/status_site.php --site=vagasebeneficios
 *   php scripts/status_site.php --site=vagasebeneficios --tail=50    (mais ticks)
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

$site = null;
$tail = 20;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site=')) $site = substr($arg, 7);
    elseif (str_starts_with($arg, '--tail=')) $tail = max(5, (int)substr($arg, 7));
}
if (!$site) {
    fwrite(STDERR, "Uso: php scripts/status_site.php --site=<slug>\n");
    exit(1);
}

require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverFila.php';
require $ROOT . '/config.php';

$db = new DiscoverDb();

$bar = str_repeat('═', 70);
echo "\n{$bar}\n  STATUS DO SITE: {$site}\n{$bar}\n\n";

// ── 1. Distribuição por status ──
$todos = $db->all(['site' => $site]);
$porStatus = [];
foreach ($todos as $t) {
    $s = $t['status'] ?? 'desconhecido';
    $porStatus[$s] = ($porStatus[$s] ?? 0) + 1;
}
echo "▸ Trends no DB ({" . count($todos) . "} total):\n";
foreach ($porStatus as $s => $n) echo sprintf("    %-15s %d\n", $s, $n);

// ── 2. Top aprovados ──
echo "\n▸ Top 10 APROVADOS por score (sem post ainda):\n";
$aprovados = array_filter($todos, fn($t) => ($t['status'] ?? '') === 'aprovado' && empty($t['post_id']));
usort($aprovados, fn($a, $b) => (float)($b['score_discover'] ?? 0) <=> (float)($a['score_discover'] ?? 0));
foreach (array_slice($aprovados, 0, 10) as $t) {
    echo sprintf("    %5.1f · %s\n", (float)($t['score_discover'] ?? 0), substr($t['termo'], 0, 60));
}
if (empty($aprovados)) echo "    (nenhum)\n";

// ── 3. Aprovados que JÁ viraram post ──
$publicados = array_filter($todos, fn($t) => ($t['status'] ?? '') === 'aprovado' && !empty($t['post_id']));
echo "\n▸ Aprovados JÁ PUBLICADOS: " . count($publicados) . "\n";
foreach (array_slice($publicados, 0, 5) as $t) {
    echo sprintf("    post #%d · %s\n", (int)$t['post_id'], substr($t['termo'], 0, 60));
}

// ── 4. Top ignorados (perto do threshold — candidatos a manual override) ──
echo "\n▸ Top 10 IGNORADOS por score (mais próximos de aprovar):\n";
$ignorados = array_filter($todos, fn($t) => ($t['status'] ?? '') === 'ignorado');
usort($ignorados, fn($a, $b) => (float)($b['score_discover'] ?? 0) <=> (float)($a['score_discover'] ?? 0));
foreach (array_slice($ignorados, 0, 10) as $t) {
    echo sprintf("    %5.1f · %s\n", (float)($t['score_discover'] ?? 0), substr($t['termo'], 0, 60));
}
if (empty($ignorados)) echo "    (nenhum)\n";

// ── 5. Estado da fila atual ──
echo "\n{$bar}\n  FILA ATUAL ({$site})\n{$bar}\n\n";
$fila = new DiscoverFila($site);
$st = $fila->status();
if (empty($st['existe'])) {
    echo "  (fila vazia — nenhum batch ativo)\n";
} else {
    echo "  Batch: {$st['batch_id']} · criado {$st['created_at']}\n";
    echo "  Total: {$st['total']}\n";
    foreach ($st['counts'] as $s => $n) echo sprintf("    %-10s %d\n", $s, $n);
    echo "\n  Items:\n";
    foreach ($st['items'] as $it) {
        echo sprintf("    [%-8s] %s%s\n",
            $it['status'],
            substr($it['termo'], 0, 55),
            !empty($it['post_id']) ? " → post #{$it['post_id']}" : ''
        );
    }
}

// ── 6. Últimos ticks ──
echo "\n{$bar}\n  ÚLTIMOS {$tail} TICKS (log_tick.log)\n{$bar}\n\n";
$logTick = $ROOT . '/data/fila/log_tick.log';
if (is_file($logTick)) {
    $linhas = file($logTick, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $relevantes = array_filter($linhas, fn($l) => str_contains($l, $site) || str_contains($l, '=== tick'));
    $ult = array_slice($relevantes, -$tail);
    foreach ($ult as $l) echo "  {$l}\n";
} else {
    echo "  (log_tick.log não existe ainda)\n";
}

echo "\n{$bar}\n\n";
