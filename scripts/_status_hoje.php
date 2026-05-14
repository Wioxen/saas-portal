<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DbConnection.php';

date_default_timezone_set('America/Sao_Paulo');
$pdo = DbConnection::pdo();

$hoje = date('Y-m-d');
echo "=== STATUS {$hoje} (TZ America/Sao_Paulo) ===\n\n";

// 1) Posts publicados HOJE (por site)
echo "--- POSTS PUBLICADOS HOJE ---\n";
$st = $pdo->query("SELECT site, COUNT(*) c FROM trends WHERE DATE(publicado_em) = CURDATE() AND status='publicado' GROUP BY site ORDER BY c DESC");
$totalPub = 0;
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad($r['site'], 22) . ' ' . str_pad((string)$r['c'], 4, ' ', STR_PAD_LEFT) . " post(s)\n";
    $totalPub += (int)$r['c'];
}
echo "TOTAL PUBLICADO HOJE: {$totalPub}\n\n";

// 1b) Detalhe dos posts de hoje
echo "--- DETALHE POSTS HOJE ---\n";
$st = $pdo->query("SELECT id, site, post_id, score_discover, publicado_em, SUBSTRING(titulo,1,80) titulo FROM trends WHERE DATE(publicado_em) = CURDATE() AND status='publicado' ORDER BY publicado_em DESC LIMIT 30");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad((string)$r['id'], 6) . ' | ' . str_pad($r['site'], 20) . ' | post#' . str_pad((string)($r['post_id'] ?? '-'), 6) . ' | s=' . str_pad((string)$r['score_discover'], 5) . ' | ' . $r['publicado_em'] . ' | ' . ($r['titulo'] ?? '') . "\n";
}

// 2) Trends detectadas HOJE por status
echo "\n--- TRENDS DETECTADAS HOJE (por site×status) ---\n";
$st = $pdo->query("SELECT site, status, COUNT(*) c FROM trends WHERE DATE(data_detectada) = CURDATE() GROUP BY site, status ORDER BY site, c DESC");
$cur = null;
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    if ($cur !== $r['site']) { echo "\n[{$r['site']}]\n"; $cur = $r['site']; }
    echo '  ' . str_pad($r['status'], 26) . ' ' . str_pad((string)$r['c'], 4, ' ', STR_PAD_LEFT) . "\n";
}

// 3) Top trends prontas pra publicar (status="novo" ou em_fila), ordenadas por score
echo "\n--- TOP 30 TRENDS DISPONÍVEIS (novo / em_fila_geracao) ---\n";
$st = $pdo->query("SELECT id, site, status, score_discover, data_detectada, SUBSTRING(termo,1,90) termo FROM trends WHERE status IN ('novo','em_fila_geracao') AND ativo=1 ORDER BY score_discover DESC LIMIT 30");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad((string)$r['id'], 6) . ' | ' . str_pad($r['site'], 20) . ' | ' . str_pad($r['status'], 18) . ' | s=' . str_pad((string)$r['score_discover'], 5) . ' | ' . $r['data_detectada'] . ' | ' . $r['termo'] . "\n";
}

// 4) Snapshot global por status
echo "\n--- SNAPSHOT GLOBAL (todos status, ativo=1) ---\n";
$st = $pdo->query("SELECT status, COUNT(*) c FROM trends WHERE ativo=1 GROUP BY status ORDER BY c DESC");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad($r['status'], 28) . ' ' . str_pad((string)$r['c'], 6, ' ', STR_PAD_LEFT) . "\n";
}
