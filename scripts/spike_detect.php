<?php
/**
 * spike_detect — cron a cada 10min capta picos do Google Trends realtime BR
 * e cria itens com score=10 (vão pro Sonnet) + status=aprovado direto na fila.
 *
 * Uso:
 *   php scripts/spike_detect.php
 *   php scripts/spike_detect.php --dry-run
 *   php scripts/spike_detect.php --min=500       # threshold de tráfego mais baixo
 *   php scripts/spike_detect.php --quiet
 *
 * Cron sugerido (a cada 10 min):
 *   *\/10 * * * * /usr/bin/php /var/www/clonais/scripts/spike_detect.php --quiet >> /var/log/clonais/spike.log 2>&1
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/SpikeDetector.php';

$dryRun = false;
$quiet = false;
$thresholdMin = SpikeDetector::thresholdPadrao();
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet') $quiet = true;
    elseif (preg_match('/^--min=(\d+)$/', $a, $m)) $thresholdMin = max(100, (int)$m[1]);
}

function log_msg(string $m, bool $q): void { if (!$q) echo '[' . date('Y-m-d H:i:s') . "] {$m}\n"; }

$lock = new CronLock('spike_detect');
if (!$lock->aquirir()) { log_msg('outra instância rodando — saindo', $quiet); exit(0); }

log_msg("Iniciando spike_detect (threshold={$thresholdMin}+, dry-run=" . ($dryRun ? 'sim' : 'nao') . ')', $quiet);

$res = SpikeDetector::detectar($thresholdMin, $dryRun);

if (!($res['ok'] ?? false)) {
    log_msg('FALHA: ' . ($res['erro'] ?? '?'), $quiet);
    require_once $ROOT . '/lib/HealthWebhook.php';
    HealthWebhook::erro('spike_detect: feed Trends indisponível', ['erro' => $res['erro'] ?? '?']);
    exit(2);
}

log_msg(sprintf('RESUMO: %d trends · %d criados · %d blocklist · %d duplicados · %d abaixo threshold',
    $res['trends_total'], count($res['criados']), $res['blocklist'], $res['duplicados'], $res['abaixo_threshold']
), $quiet);

foreach ($res['criados'] as $c) {
    log_msg(sprintf('  [%s] id=%-4d traffic=%-7d cluster=%-25s · %s',
        $dryRun ? 'DRY' : 'OK',
        $c['id'], $c['traffic'], $c['cluster'] ?? '?', $c['site'] . ' :: ' . substr($c['termo'], 0, 60)
    ), $quiet);
}

$lock->liberar();
exit(0);
