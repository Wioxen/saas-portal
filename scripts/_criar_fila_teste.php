<?php
/**
 * [TEMPORÁRIO — pode apagar depois] Cria fila de teste com 1 trend específico.
 * Zero custo (não chama API). Suporta rollback.
 *
 * Uso:
 *   php scripts/_criar_fila_teste.php --site=cursosenac --id=507
 *     → muda status do trend pra 'aprovado' (se for 'novo') + cria fila com 1 item
 *
 *   php scripts/_criar_fila_teste.php --site=cursosenac --id=507 --rollback
 *     → reverte: limpa fila + volta status pra 'novo'
 */

require_once __DIR__ . '/../lib/DiscoverDb.php';
require_once __DIR__ . '/../lib/DiscoverFila.php';

$site     = null;
$trendId  = 0;
$rollback = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site=')) $site = substr($arg, 7);
    elseif (str_starts_with($arg, '--id=')) $trendId = (int)substr($arg, 5);
    elseif ($arg === '--rollback') $rollback = true;
}

if (!$site || $trendId <= 0) {
    fwrite(STDERR, "Uso: php scripts/_criar_fila_teste.php --site=X --id=N [--rollback]\n");
    exit(1);
}

$db = new DiscoverDb();
$rec = $db->get($trendId);
if (!$rec) {
    fwrite(STDERR, "[ERRO] trend #{$trendId} não existe no DB\n");
    exit(1);
}
if (($rec['site'] ?? '') !== $site) {
    fwrite(STDERR, "[ERRO] trend #{$trendId} é do site '{$rec['site']}', não '{$site}'\n");
    exit(1);
}

$fila = new DiscoverFila($site);

if ($rollback) {
    echo "[ROLLBACK] revertendo trend #{$trendId} e limpando fila de '{$site}'\n";
    // Volta status pra 'novo' (assumindo que estava assim antes)
    $db->updateStatus($trendId, 'novo', []);
    $fila->limpar();
    echo "  ✓ trend voltou pra status='novo'\n";
    echo "  ✓ fila limpa\n";
    exit(0);
}

// Forward: aprovar + criar fila
echo "Trend selecionado:\n";
printf("  #%-5d site=%s  status atual=%s\n", $rec['id'], $rec['site'], $rec['status'] ?? '?');
printf("  termo: %s\n", $rec['termo']);
printf("  score: %.1f  categoria: %s\n", (float)($rec['score_discover'] ?? 0), $rec['categoria'] ?? '-');

if (($rec['status'] ?? '') === 'aprovado') {
    echo "  (já está 'aprovado' — pulando mudança de status)\n";
} else {
    $db->updateStatus($trendId, 'aprovado', []);
    echo "  ✓ status mudado para 'aprovado'\n";
    $rec = $db->get($trendId); // re-lê pós-update
}

// Verifica se já há fila ativa
$status = $fila->status();
if (!empty($status['existe'])) {
    echo "\n[AVISO] já há fila ativa em '{$site}' (batch_id={$status['batch_id']}, total={$status['total']}).\n";
    echo "Vou sobrescrever (DiscoverFila::criar() limpa e recria).\n";
}

$novo = $fila->criar([$rec], 'discover');
echo "\nFila criada:\n";
printf("  batch_id=%s\n", $novo['batch_id']);
printf("  total=%d  formato=%s\n", $novo['total'], $novo['formato']);
echo "\nProximo passo:\n";
echo "  php scripts/tick_filas.php --site={$site} --max=1\n";
