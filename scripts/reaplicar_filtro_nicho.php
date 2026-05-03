<?php
declare(strict_types=1);
/**
 * Reaplica nicho_required_terms + trend_scoring_threshold em trends já salvos.
 * Útil quando você AJUSTOU sites.php depois que trends já entraram.
 *
 * Marca status='fora_escopo_nicho' nos que não batem com nenhum termo de nicho.
 * Marca status='abaixo_threshold' nos que pontuam < trend_scoring_threshold.
 *
 * NÃO toca em trends já publicados ou em geração (status IN: enfileirado,
 * gerando, publicado, fora_escopo_nicho, abaixo_threshold).
 *
 * Uso:
 *   php scripts/reaplicar_filtro_nicho.php --site=cursosenac
 *   php scripts/reaplicar_filtro_nicho.php --site=cursosenac --dry-run
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/DbConnection.php';

$opts = getopt('', ['site::', 'dry-run']);
$siteSlug = (string)($opts['site'] ?? '');
$dryRun = isset($opts['dry-run']);
if ($siteSlug === '') { fwrite(STDERR, "uso: --site=SLUG [--dry-run]\n"); exit(2); }

$sites = require __DIR__ . '/../sites.php';
if (!isset($sites[$siteSlug])) { fwrite(STDERR, "site '{$siteSlug}' não existe\n"); exit(2); }
$cfg = $sites[$siteSlug];

$nichoTerms = (array)($cfg['nicho_required_terms'] ?? []);
$threshold = (float)($cfg['trend_scoring_threshold'] ?? 0);

if (empty($nichoTerms) && $threshold <= 0) {
    echo "site '{$siteSlug}' não tem nicho_required_terms nem trend_scoring_threshold — nada a reaplicar\n";
    exit(0);
}

echo "═══ Reaplicando filtros para '{$siteSlug}' ═══\n";
echo "  nicho_required_terms: " . count($nichoTerms) . " termos\n";
echo "  trend_scoring_threshold: {$threshold}\n";
echo "  modo: " . ($dryRun ? "DRY-RUN" : "APPLY") . "\n\n";

$pdo = DbConnection::pdo();

// Status que NÃO devem ser tocados
$statusIntocaveis = "'enfileirado','gerando','publicado','fora_escopo_nicho','abaixo_threshold','rejeitado_manual'";

$sql = "SELECT id, termo, payload, score_discover, status
        FROM trends
        WHERE site = :site
          AND status NOT IN ({$statusIntocaveis})
        ORDER BY id DESC
        LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute([':site' => $siteSlug]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Trends candidatos: " . count($rows) . "\n\n";

$reclassificados = ['fora_escopo' => 0, 'abaixo_threshold' => 0, 'mantidos' => 0];

foreach ($rows as $r) {
    $id = (int)$r['id'];
    $termo = (string)$r['termo'];
    $score = (float)($r['score_discover'] ?? 0);
    $payload = json_decode((string)($r['payload'] ?? '{}'), true) ?: [];
    $relacionados = (array)($payload['relacionados'] ?? $payload['related'] ?? []);

    // Check 1: threshold
    if ($threshold > 0 && $score > 0 && $score < $threshold) {
        $reclassificados['abaixo_threshold']++;
        if (!$dryRun) {
            $upd = $pdo->prepare("UPDATE trends SET status='abaixo_threshold' WHERE id=:id");
            $upd->execute([':id' => $id]);
        }
        echo "  ↓ #{$id} score={$score} < {$threshold} · {$termo}\n";
        continue;
    }

    // Check 2: nicho terms
    if (!empty($nichoTerms)) {
        $haystack = mb_strtolower($termo . ' ' . implode(' ', $relacionados), 'UTF-8');
        $bateu = false;
        foreach ($nichoTerms as $t) {
            $tNorm = mb_strtolower(trim((string)$t), 'UTF-8');
            if ($tNorm === '') continue;
            if (mb_strpos($haystack, $tNorm) !== false) { $bateu = true; break; }
        }
        if (!$bateu) {
            $reclassificados['fora_escopo']++;
            if (!$dryRun) {
                $upd = $pdo->prepare("UPDATE trends SET status='fora_escopo_nicho' WHERE id=:id");
                $upd->execute([':id' => $id]);
            }
            echo "  ✗ #{$id} fora_escopo · {$termo}\n";
            continue;
        }
    }

    $reclassificados['mantidos']++;
}

echo "\n═══ Resumo ═══\n";
echo "  ✓ mantidos:           {$reclassificados['mantidos']}\n";
echo "  ✗ fora_escopo_nicho:  {$reclassificados['fora_escopo']}\n";
echo "  ↓ abaixo_threshold:   {$reclassificados['abaixo_threshold']}\n";
if ($dryRun) echo "\n[DRY-RUN] nada gravado.\n";
