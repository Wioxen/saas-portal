<?php
/**
 * scripts/limpar_trends_site.php
 *
 * DELETE definitivo de trends de um site específico. Útil em pivots de nicho
 * (caso leaodabarra 2026-05-02: trends esportes gerais → tudo lixo após pivot).
 *
 * Uso:
 *   # Preview (dry-run mostra count + breakdown por status, NÃO deleta)
 *   php scripts/limpar_trends_site.php --site=SLUG
 *
 *   # Executar DELETE de verdade
 *   php scripts/limpar_trends_site.php --site=SLUG --confirm
 *
 *   # DELETE só de status específico
 *   php scripts/limpar_trends_site.php --site=SLUG --status=fora_escopo_pivot --confirm
 *
 * IMPORTANTE: DELETE é IRREVERSÍVEL. Posts já publicados no WP NÃO são afetados
 * (referência fica órfã, mas o post WP continua existindo). Se precisar
 * preservar histórico de trends publicados, use --status=novo,aprovado pra
 * deletar só os não-publicados.
 */

$siteArg = '';
$confirm = false;
$statusFiltro = '';
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--status=(.+)$/', $a, $m)) $statusFiltro = $m[1];
    if ($a === '--confirm') $confirm = true;
}
if ($siteArg === '') {
    fwrite(STDERR, "Uso: php scripts/limpar_trends_site.php --site=SLUG [--status=X,Y,Z] [--confirm]\n");
    fwrite(STDERR, "  Sem --confirm: dry-run (mostra count, não deleta)\n");
    exit(2);
}

require_once __DIR__ . '/../lib/DbConnection.php';
$pdo = DbConnection::pdo();

// 1. Conta total antes
$where = ['site = ?'];
$params = [$siteArg];
if ($statusFiltro !== '') {
    $statusList = array_filter(array_map('trim', explode(',', $statusFiltro)));
    if (!empty($statusList)) {
        $place = implode(',', array_fill(0, count($statusList), '?'));
        $where[] = "status IN ({$place})";
        foreach ($statusList as $s) $params[] = $s;
    }
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM trends WHERE {$whereSql}");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

if ($total === 0) {
    echo "Nenhum trend encontrado pra site={$siteArg}" . ($statusFiltro ? " status IN ({$statusFiltro})" : '') . "\n";
    exit(0);
}

echo "═══ Trends a DELETAR — site={$siteArg}" . ($statusFiltro ? " · status={$statusFiltro}" : '') . " ═══\n";
echo "Total: {$total}\n\n";

// 2. Breakdown por status
$stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM trends WHERE {$whereSql} GROUP BY status ORDER BY c DESC");
$stmt->execute($params);
echo "Breakdown por status:\n";
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    printf("  %-30s %d\n", $row['status'], $row['c']);
}
echo "\n";

// 3. Sample 5 termos (pra confirmar visualmente)
$stmt = $pdo->prepare("SELECT id, status, SUBSTRING(termo, 1, 70) t FROM trends WHERE {$whereSql} ORDER BY id DESC LIMIT 5");
$stmt->execute($params);
echo "Amostra (5 mais recentes):\n";
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    printf("  #%d [%s] %s\n", $row['id'], $row['status'], $row['t']);
}
echo "\n";

if (!$confirm) {
    echo "🟡 DRY-RUN — NÃO deletou nada.\n";
    echo "   Pra executar de verdade, rode com --confirm:\n";
    echo "   php scripts/limpar_trends_site.php --site={$siteArg}" . ($statusFiltro ? " --status={$statusFiltro}" : '') . " --confirm\n";
    exit(0);
}

// 4. DELETE confirmado
$stmt = $pdo->prepare("DELETE FROM trends WHERE {$whereSql}");
$stmt->execute($params);
$deletados = $stmt->rowCount();

echo "✓ DELETADOS: {$deletados} trends\n";
echo "  Posts WP já publicados NÃO foram afetados (referências ficam órfãs).\n";
