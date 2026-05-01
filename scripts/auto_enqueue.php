<?php
/**
 * auto_enqueue — cron que enfileira sozinho trends aprovados sem post.
 *
 * Substitui o clique manual em "Gerar N pendentes" no portal.php.
 *
 * Lógica conservadora:
 *   - Pra cada site, busca trends com status='aprovado' AND post_id IS NULL
 *   - SÓ cria novo batch se a fila atual estiver vazia ou com 100% terminal
 *     (done/failed/canceled). Evita destruir batch em andamento.
 *   - Se há items 'pending' ou 'running' → pula esse site (tick_filas ainda processa)
 *
 * Trade-off: entre batches a fila fica vazia por ~minutos até o próximo auto_enqueue.
 * Pra volume típico (~20-50 aprovados/dia/site), isso é ok. Se virar gargalo,
 * adicionar método appendItems() em DiscoverFila.
 *
 * Uso:
 *   php scripts/auto_enqueue.php                  → todos os sites
 *   php scripts/auto_enqueue.php --site=X         → site específico
 *   php scripts/auto_enqueue.php --max=30         → no máximo 30 items por site
 *   php scripts/auto_enqueue.php --threshold=8    → só score >= 8.0 (override do threshold do cluster)
 *   php scripts/auto_enqueue.php --dry-run        → mostra o que faria
 *   php scripts/auto_enqueue.php --quiet          → sem stdout
 *
 * Cron recomendado: a cada 15min (formato: STAR/15 STAR STAR STAR STAR)
 *   schedule = "ASTERISK SLASH 15 ASTERISK ASTERISK ASTERISK ASTERISK"
 *   command  = php /app/scripts/auto_enqueue.php --quiet
 *
 * Logs: data/fila/log_auto_enqueue.log
 */

set_time_limit(0);
ini_set('memory_limit', '256M');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__);

// ── parse args ──
$dryRun    = false;
$quiet     = false;
$forceSite = null;
$maxPerSite = 0; // 0 = sem limite
$thresholdOverride = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')                    $dryRun = true;
    elseif ($arg === '--quiet')                  $quiet = true;
    elseif (str_starts_with($arg, '--site='))    $forceSite = substr($arg, 7);
    elseif (str_starts_with($arg, '--max='))     $maxPerSite = (int)substr($arg, 6);
    elseif (str_starts_with($arg, '--threshold=')) $thresholdOverride = (float)substr($arg, 12);
}

require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverFila.php';

$cfg = require $ROOT . '/config.php';
require $ROOT . '/_site_helper.php';
$sites = sitesDisponiveis();
$db    = new DiscoverDb();

// Kill switch via .env
if ((int)($_ENV['PIPELINE_PAUSED'] ?? getenv('PIPELINE_PAUSED') ?: 0) === 1) {
    if (!$quiet) echo "[skip] PIPELINE_PAUSED=1 no .env\n";
    exit(0);
}

// Lock global (evita 2 auto_enqueue rodando ao mesmo tempo)
$lockFile = $ROOT . '/data/fila/.auto_enqueue.lock';
@mkdir(dirname($lockFile), 0775, true);
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if (!$quiet) echo "[skip] lock global ocupado (outro auto_enqueue rodando)\n";
    exit(0);
}

$logFile = $ROOT . '/data/fila/log_auto_enqueue.log';
$startedAt = date('Y-m-d H:i:s');
$logLine = function (string $msg) use ($logFile, $quiet): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    if (!$quiet) echo $line . "\n";
};

$logLine("=== auto_enqueue start {$startedAt} · PID " . getmypid() . " · dry-run=" . ($dryRun ? 'sim' : 'não') . " ===");

$sitesAlvo = $forceSite ? [$forceSite] : array_keys($sites);
$totalEnfileirados = 0;
$totalPulados = 0;

foreach ($sitesAlvo as $slug) {
    if (!isset($sites[$slug])) {
        $logLine("[{$slug}] [skip] site não existe em sites.php");
        continue;
    }

    // Verifica fila atual
    $fila = new DiscoverFila($slug);
    $st = $fila->status();
    $existe = !empty($st['existe']);
    $counts = $st['counts'] ?? [];
    $pendingOuRunning = ($counts['pending'] ?? 0) + ($counts['running'] ?? 0);

    if ($existe && $pendingOuRunning > 0) {
        $logLine("[{$slug}] [skip] fila ainda tem {$pendingOuRunning} item(s) pending/running — aguardando tick processar");
        $totalPulados++;
        continue;
    }

    // Busca aprovados sem post
    $todos = $db->all(['site' => $slug]);
    $candidatos = array_filter($todos, function ($r) {
        $temPost = !empty($r['post_id']) && (int)$r['post_id'] > 0;
        return ($r['status'] ?? '') === 'aprovado' && !$temPost;
    });

    // Override de threshold (opcional)
    if ($thresholdOverride !== null) {
        $candidatos = array_filter($candidatos, fn($r) => (float)($r['score_discover'] ?? 0) >= $thresholdOverride);
    }

    // Ordena por score desc — gera os melhores primeiro
    usort($candidatos, fn($a, $b) => (float)($b['score_discover'] ?? 0) <=> (float)($a['score_discover'] ?? 0));

    if ($maxPerSite > 0) {
        $candidatos = array_slice($candidatos, 0, $maxPerSite);
    }

    $qtd = count($candidatos);
    if ($qtd === 0) {
        $logLine("[{$slug}] nenhum aprovado pendente");
        continue;
    }

    if ($dryRun) {
        $logLine("[{$slug}] [dry-run] enfileiraria {$qtd} item(s)");
        foreach (array_slice($candidatos, 0, 5) as $c) {
            $logLine("           · {$c['termo']} (score=" . round((float)($c['score_discover'] ?? 0), 1) . ")");
        }
        if ($qtd > 5) $logLine("           · ... e mais " . ($qtd - 5));
        continue;
    }

    try {
        $r = $fila->criar(array_values($candidatos), 'discover');
        $logLine("[{$slug}] [ok] enfileirados {$r['total']} · batch={$r['batch_id']}");
        $totalEnfileirados += $r['total'];
    } catch (Throwable $e) {
        $logLine("[{$slug}] [erro] {$e->getMessage()}");
    }
}

$logLine("=== auto_enqueue end · enfileirados={$totalEnfileirados} · sites_pulados={$totalPulados} ===");

flock($lockFp, LOCK_UN);
fclose($lockFp);
exit(0);
