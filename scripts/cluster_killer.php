<?php
/**
 * cluster_killer — análise semanal de clusters por (site × cluster_key) (B5).
 *
 * Lê PostPerformanceLog dos últimos 30d, agrega por (site × cluster), e pausa clusters
 * com clicks Discover <10 E CTR <0.5% E ≥5 posts (proteção estatística).
 *
 * Pausa = grava em data/cluster_paused.json. PrePublishLint lê e rejeita trends pausadas.
 *
 * Uso:
 *   php scripts/cluster_killer.php                   → análise + apply (default)
 *   php scripts/cluster_killer.php --dry-run         → só lista (não pausa)
 *   php scripts/cluster_killer.php --janela=14
 *   php scripts/cluster_killer.php --min-clicks=20
 *
 * Cron sugerido (semanal, segunda 6:30am — após gsc_aprender):
 *   30 6 * * 1 /usr/bin/php /var/www/clonais/scripts/cluster_killer.php --quiet >> /var/log/clonais/cluster_killer.log 2>&1
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/ClusterKiller.php';

$dryRun = false;
$quiet  = false;
$janela = ClusterKiller::JANELA_DIAS;
$maxClicks = ClusterKiller::MAX_CLICKS_PARA_PAUSAR;

foreach ($argv as $a) {
    if ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet') $quiet = true;
    elseif (preg_match('/^--janela=(\d+)$/', $a, $m)) $janela = (int)$m[1];
    elseif (preg_match('/^--min-clicks=(\d+)$/', $a, $m)) $maxClicks = (int)$m[1];
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[cluster_killer] {$m}\n"; }

$lock = new CronLock('cluster_killer');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

$db = new DiscoverDb();
$killer = new ClusterKiller();
$res = $killer->analisar($db, ['janela_dias' => $janela, 'max_clicks' => $maxClicks]);

log_msg(sprintf("Análise: %d clusters · %d candidatos a pausa · janela %dd",
    $res['total_clusters'], $res['total_pausados'], $res['janela_dias']), $quiet);

foreach ($res['analise'] as $a) {
    $marca = $a['pausar'] ? '⛔' : ' ';
    log_msg(sprintf("  %s [%s|%s] posts=%d clicks=%d impr=%d ctr=%.3f%% %s",
        $marca, $a['site'], $a['cluster_key'],
        $a['posts'], $a['clicks'], $a['impressions'], $a['ctr'] * 100,
        $a['pausar'] ? "→ PAUSAR" : ''), $quiet);
}

if ($dryRun) {
    log_msg('[dry-run] sem aplicar', $quiet);
} else {
    $aplicado = $killer->aplicar($res);
    log_msg("Aplicado: {$aplicado['pausados']} pausados em {$aplicado['arquivo']}", $quiet);

    // Webhook se número de pausados mudou bastante
    if ($aplicado['pausados'] >= 3) {
        $hwPath = $ROOT . '/lib/HealthWebhook.php';
        if (is_file($hwPath)) {
            require_once $hwPath;
            HealthWebhook::aviso('cluster_killer pausou 3+ clusters', [
                'pausados'    => $aplicado['pausados'],
                'janela_dias' => $janela,
                'detalhe'     => 'verificar performance — possível problema de fonte/persona/cluster',
            ]);
        }
    }
}

$lock->liberar();
exit(0);
