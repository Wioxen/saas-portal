<?php
/**
 * scripts/status_trends.php
 *
 * Lista status de trends por ID. Útil pra debug rápido sem ter que escrever
 * php -r com quoting de shell que quebra fácil.
 *
 * Uso:
 *   php scripts/status_trends.php 1325 1309 1331
 *   php scripts/status_trends.php --site=leaodabarra      # últimos 20 do site
 *   php scripts/status_trends.php --site=leaodabarra --status=publicado
 */

$siteFiltro   = '';
$statusFiltro = '';
$ids = [];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if (preg_match('/^--site=(.+)$/', $a, $m))   { $siteFiltro = $m[1]; continue; }
    if (preg_match('/^--status=(.+)$/', $a, $m)) { $statusFiltro = $m[1]; continue; }
    if (ctype_digit((string)$a)) $ids[] = (int)$a;
}

require_once __DIR__ . '/../lib/DbConnection.php';
$pdo = DbConnection::pdo();

$where = [];
$params = [];
if (!empty($ids)) {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "id IN ({$place})";
    foreach ($ids as $id) $params[] = $id;
}
if ($siteFiltro !== '') {
    $where[] = "site = ?";
    $params[] = $siteFiltro;
}
if ($statusFiltro !== '') {
    $where[] = "status = ?";
    $params[] = $statusFiltro;
}

$sql = "SELECT id, site, status, post_id, score_discover, ultimo_update,
               SUBSTRING(termo, 1, 70) AS termo_curto
        FROM trends";
if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY ultimo_update DESC LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) { echo "Nenhum trend encontrado.\n"; exit(0); }

printf("%-6s  %-18s  %-30s  %-7s  %-6s  %s\n", 'ID', 'SITE', 'STATUS', 'POST', 'SCORE', 'TERMO');
printf("%s\n", str_repeat('-', 120));
foreach ($rows as $row) {
    printf("%-6d  %-18s  %-30s  %-7d  %-6.2f  %s\n",
        (int)$row['id'],
        $row['site'],
        $row['status'],
        (int)$row['post_id'],
        (float)$row['score_discover'],
        $row['termo_curto']
    );
}
