<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DbConnection.php';
date_default_timezone_set('America/Sao_Paulo');
$pdo = DbConnection::pdo();

$sites = ['vagasebeneficios', 'cursosenac', 'comocomprar', 'ondecompraragora'];

foreach ($sites as $site) {
    echo "\n=== {$site} ===\n";
    // 1º Top aprovado (triagem já validou) — frescos das últimas 48h
    $st = $pdo->prepare("SELECT id, status, score_discover, origem, data_detectada, pingo_link, SUBSTRING(titulo,1,140) titulo, categoria FROM trends WHERE site=? AND status='aprovado' AND data_detectada >= DATE_SUB(NOW(), INTERVAL 48 HOUR) ORDER BY score_discover DESC LIMIT 5");
    $st->execute([$site]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo "APROVADOS (top 5 últimas 48h):\n";
        foreach ($rows as $r) {
            echo "  #{$r['id']} | s={$r['score_discover']} | {$r['categoria']} | {$r['data_detectada']}\n    {$r['titulo']}\n    URL: " . substr($r['pingo_link'] ?? '', 0, 140) . "\n";
        }
    } else {
        echo "(nenhum APROVADO 48h — caindo pra top NOVO)\n";
        $st = $pdo->prepare("SELECT id, status, score_discover, origem, data_detectada, pingo_link, SUBSTRING(titulo,1,140) titulo, categoria FROM trends WHERE site=? AND status='novo' AND data_detectada >= DATE_SUB(NOW(), INTERVAL 36 HOUR) AND ativo=1 ORDER BY score_discover DESC LIMIT 5");
        $st->execute([$site]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            echo "  #{$r['id']} | s={$r['score_discover']} | {$r['categoria']} | {$r['data_detectada']}\n    {$r['titulo']}\n    URL: " . substr($r['pingo_link'] ?? '', 0, 140) . "\n";
        }
    }
}
