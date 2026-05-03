<?php
declare(strict_types=1);
/**
 * Busca via Serper fontes pra um jogo específico do Vitória.
 * Output: lista ordenada por SourceTrustScore (Tier S/A primeiro).
 */
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/SourceTrustScore.php';

$query = $argv[1] ?? 'Vitória 4x1 Coritiba olé Brasileirão';
$serper = new Serper($cfg['serper_api_key']);
$resp = $serper->search($query, 15);

$urls = [];
foreach ($resp['organic'] ?? [] as $r) {
    $u = (string)($r['link'] ?? '');
    if ($u !== '') $urls[] = ['url' => $u, 'title' => $r['title'] ?? ''];
}
$urls = SourceTrustScore::ordenarPorTier($urls);

echo "QUERY: {$query}\n\n";
foreach ($urls as $u) {
    $tier = SourceTrustScore::tierUrl($u['url']);
    printf("[%s] %s\n     %s\n\n", $tier, substr($u['title'], 0, 80), $u['url']);
}
