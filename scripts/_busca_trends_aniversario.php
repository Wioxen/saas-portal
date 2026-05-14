<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DbConnection.php';
date_default_timezone_set('America/Sao_Paulo');
$pdo = DbConnection::pdo();

echo "=== Trends leaodabarra DETECTADAS HOJE (todas) ===\n";
$st = $pdo->query("SELECT id, status, score_discover, origem, data_detectada, pingo_link, SUBSTRING(titulo,1,140) titulo, SUBSTRING(termo,1,140) termo FROM trends WHERE site='leaodabarra' AND DATE(data_detectada) = CURDATE() ORDER BY score_discover DESC, data_detectada DESC LIMIT 50");
$nAll = 0;
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $nAll++;
    echo "#{$r['id']} | s={$r['score_discover']} | {$r['status']} | {$r['origem']} | {$r['data_detectada']}\n";
    echo "  TIT: " . ($r['titulo'] ?: '-') . "\n";
    echo "  TRM: " . ($r['termo'] ?: '-') . "\n";
    echo "  URL: " . ($r['pingo_link'] ?: '-') . "\n\n";
}
echo "Total leaodabarra hoje: {$nAll}\n";

echo "\n=== Trends QUALQUER SITE mencionando aniversário/127/fundação Vitória (últimos 7 dias) ===\n";
$st = $pdo->query("SELECT id, site, status, score_discover, data_detectada, pingo_link, SUBSTRING(titulo,1,140) titulo, SUBSTRING(termo,1,140) termo FROM trends WHERE data_detectada >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND (termo LIKE '%127 anos%' OR termo LIKE '%aniversário%Vit%' OR termo LIKE '%aniversario%Vit%' OR titulo LIKE '%127 anos%' OR titulo LIKE '%aniversário%Vit%' OR titulo LIKE '%fundação%Vit%' OR titulo LIKE '%13 de maio%') ORDER BY score_discover DESC, data_detectada DESC LIMIT 30");
$nMatch = 0;
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $nMatch++;
    echo "#{$r['id']} | {$r['site']} | s={$r['score_discover']} | {$r['status']} | {$r['data_detectada']}\n";
    echo "  TIT: " . ($r['titulo'] ?: '-') . "\n";
    echo "  TRM: " . ($r['termo'] ?: '-') . "\n";
    echo "  URL: " . ($r['pingo_link'] ?: '-') . "\n\n";
}
echo "Total match aniversário: {$nMatch}\n";
