<?php
declare(strict_types=1);
/**
 * Marca trends antigos (acima da janela trend_max_idade_horas do site) como
 * status='obsoleto'. Não deleta — só tira da fila de geração.
 *
 * Uso:
 *   php scripts/descartar_trends_antigos.php --site=cursosenac --dry-run
 *   php scripts/descartar_trends_antigos.php --site=cursosenac --confirm
 *   php scripts/descartar_trends_antigos.php --all-sites --confirm
 */
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/DbConnection.php';

$opts = getopt('', ['site::', 'all-sites', 'confirm', 'dry-run']);
$siteSlug = (string)($opts['site'] ?? '');
$allSites = isset($opts['all-sites']);
$confirm  = isset($opts['confirm']);
$dryRun   = isset($opts['dry-run']) || !$confirm;

if (!$allSites && $siteSlug === '') { fwrite(STDERR, "uso: --site=SLUG OR --all-sites [--confirm]\n"); exit(2); }

$sites = require __DIR__ . '/../sites.php';
$alvos = $allSites ? array_keys($sites) : [$siteSlug];

$pdo = DbConnection::pdo();
$totalGeral = 0;

foreach ($alvos as $slug) {
    if (!isset($sites[$slug])) continue;
    $maxH = (int)($sites[$slug]['trend_max_idade_horas'] ?? 168);
    $tsLimite = time() - ($maxH * 3600);
    $dtLimite = date('Y-m-d H:i:s', $tsLimite);

    // Busca trends novos/aprovados sem post + idade maior que limite
    // Idade: prefere pingo_pub_ts (do JSON payload? Não. Coluna direta? Vamos ver schema)
    // Default: usa data_detectada
    $sql = "SELECT id, status, score_discover, data_detectada, LEFT(termo,60) AS termo
            FROM trends
            WHERE site = :site
              AND status IN ('novo','aprovado')
              AND (post_id IS NULL OR post_id = 0)
              AND data_detectada < :lim
            ORDER BY data_detectada ASC
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':site' => $slug, ':lim' => $dtLimite]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "═══ {$slug} · max_idade={$maxH}h · limite data_detectada < {$dtLimite} ═══\n";
    echo "  trends candidatos a descarte: " . count($rows) . "\n";
    foreach (array_slice($rows, 0, 5) as $r) {
        echo "    #{$r['id']} [{$r['status']}] {$r['data_detectada']} score={$r['score_discover']} · {$r['termo']}\n";
    }
    if (count($rows) > 5) echo "    ... +" . (count($rows) - 5) . "\n";

    if ($dryRun) {
        echo "  [dry-run] nada gravado\n\n";
        $totalGeral += count($rows);
        continue;
    }

    if (count($rows) > 0) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_map('intval', $ids));
        $upd = $pdo->exec("UPDATE trends SET status='obsoleto' WHERE id IN ({$placeholders})");
        echo "  ✓ {$upd} trends marcados status='obsoleto'\n\n";
        $totalGeral += $upd;
    } else {
        echo "  ✓ nada a descartar\n\n";
    }
}

echo "TOTAL: {$totalGeral} trends descartados (modo: " . ($dryRun ? 'dry-run' : 'apply') . ")\n";
