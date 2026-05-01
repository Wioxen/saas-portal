<?php
/**
 * gerar_hubs — cron diário gera/atualiza hub pages por (site, cluster).
 *
 * Pra cada site, identifica clusters elegíveis (≥5 posts publicados) e gera/atualiza
 * uma "página hub" linkando todos os posts. Topical authority compound.
 *
 * Uso:
 *   php scripts/gerar_hubs.php
 *   php scripts/gerar_hubs.php --site=cursosenac
 *   php scripts/gerar_hubs.php --cluster=educacao
 *   php scripts/gerar_hubs.php --dry-run
 *
 * Cron sugerido (diário 7h, depois do auto_refresh):
 *   0 7 * * * /usr/bin/php /var/www/clonais/scripts/gerar_hubs.php --quiet >> /var/log/clonais/hubs.log 2>&1
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverHubPages.php';
require_once $ROOT . '/_site_helper.php';

$cfg = require $ROOT . '/config.php';

$soSite = null;
$soCluster = null;
$dryRun = false;
$quiet = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--site='))     $soSite = substr($a, 7);
    elseif (str_starts_with($a, '--cluster=')) $soCluster = substr($a, 10);
    elseif ($a === '--dry-run')             $dryRun = true;
    elseif ($a === '--quiet')               $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo '[' . date('Y-m-d H:i:s') . "] {$m}\n"; }

$lock = new CronLock('gerar_hubs');
if (!$lock->aquirir()) { log_msg('outra instância rodando — saindo', $quiet); exit(0); }

$db = new DiscoverDb();
$sites = sitesDisponiveis();
if ($soSite !== null) {
    if (!isset($sites[$soSite])) { fwrite(STDERR, "Site '{$soSite}' não existe\n"); exit(2); }
    $sites = [$soSite => $sites[$soSite]];
}

$totalCriados = 0; $totalAtualizados = 0; $totalSkipados = 0; $totalErros = 0;

foreach ($sites as $slug => $siteCfg) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slug);
    $clusters = DiscoverHubPages::clustersElegiveis($slug, $db);
    if ($soCluster !== null) {
        $clusters = isset($clusters[$soCluster]) ? [$soCluster => $clusters[$soCluster]] : [];
    }

    if (empty($clusters)) {
        log_msg("[{$slug}] nenhum cluster elegível (≥" . DiscoverHubPages::MIN_POSTS_HUB . " posts publicados)", $quiet);
        continue;
    }

    log_msg("[{$slug}] " . count($clusters) . " cluster(s) elegível(is): " . implode(',', array_keys($clusters)), $quiet);

    foreach ($clusters as $clusterKey => $nPosts) {
        try {
            $r = DiscoverHubPages::gerarHub($clusterKey, $slug, $cfgSite, $db, $dryRun);
        } catch (Throwable $e) {
            log_msg("  [{$clusterKey}] EXCEPTION: {$e->getMessage()}", $quiet);
            $totalErros++;
            continue;
        }

        if (!$r['ok']) {
            log_msg(sprintf('  [%s] skipped: %s', $clusterKey, $r['motivo'] ?? $r['erro'] ?? '?'), $quiet);
            $totalSkipados++;
            continue;
        }

        log_msg(sprintf('  [%s] %s · %d posts · %s',
            $clusterKey,
            $r['action'] ?? '?',
            $r['posts_count'] ?? 0,
            $r['page_url'] ?? '?'
        ), $quiet);

        if (($r['action'] ?? '') === 'created') $totalCriados++;
        elseif (($r['action'] ?? '') === 'updated') $totalAtualizados++;
    }
}

log_msg(sprintf('RESUMO: %d criados, %d atualizados, %d skipados, %d erros',
    $totalCriados, $totalAtualizados, $totalSkipados, $totalErros), $quiet);

if ($totalErros > 0) {
    require_once $ROOT . '/lib/HealthWebhook.php';
    HealthWebhook::aviso('gerar_hubs: erros', ['erros' => $totalErros]);
}

$lock->liberar();
exit(0);
