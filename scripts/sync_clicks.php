<?php
/**
 * sync_clicks — pull diário de clicks de cada site (C1 da Frente B).
 *
 * Pra cada site configurado em sites.php, consulta /wp-json/cc/v1/clicks/recent?since=ID
 * (estado em data/click_log/_state.json) e append em data/click_log/{YYYY-MM}.jsonl.
 *
 * Idempotente: usa `since` incremental por site (last_id sincronizado). Re-execução não
 * duplica events.
 *
 * Pré-requisito: plugin cc-click-logger ativo em cada site WP.
 *
 * Uso:
 *   php scripts/sync_clicks.php                       → todos sites
 *   php scripts/sync_clicks.php --site=cursosenac
 *   php scripts/sync_clicks.php --max-batches=20      → mais agressivo
 *   php scripts/sync_clicks.php --quiet
 *
 * Cron sugerido (a cada 4h — clicks são frequentes mas não tempo-real):
 *   0 *\/4 * * * /usr/bin/php /var/www/clonais/scripts/sync_clicks.php --quiet >> /var/log/clonais/sync_clicks.log 2>&1
 *
 * Exit codes: 0 = OK · 1 = lock falhou · 2 = erros em todos os sites
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/ClickLog.php';
require_once $ROOT . '/_site_helper.php';

// Args
$siteArg     = '';
$maxBatches  = 10;
$quiet       = false;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m))         $siteArg = $m[1];
    elseif (preg_match('/^--max-batches=(\d+)$/', $a, $m)) $maxBatches = (int)$m[1];
    elseif ($a === '--quiet') $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[sync_clicks] {$m}\n"; }

$lockNome = 'sync_clicks' . ($siteArg !== '' ? '_' . preg_replace('/[^a-z0-9_-]+/', '', $siteArg) : '');
$lock = new CronLock($lockNome);
if (!$lock->aquirir()) {
    log_msg('outra instância rodando — saindo', $quiet);
    exit(1);
}

$cfgBase = require $ROOT . '/config.php';
$sites = sitesDisponiveis();
$alvosSites = $siteArg !== '' ? [$siteArg => $sites[$siteArg] ?? null] : $sites;

$cl = new ClickLog();
$totalNovos = 0;
$totalSites = 0;
$errosGlobais = [];

foreach ($alvosSites as $slug => $cfgSite) {
    if (!is_array($cfgSite)) {
        log_msg("skip {$slug}: cfg ausente", $quiet);
        continue;
    }
    $cfgMesclado = $cfgBase;
    aplicarSite($cfgMesclado, $sites, $slug);

    try {
        $r = $cl->sincronizar($slug, $cfgMesclado, $maxBatches);
        $totalSites++;
        if (!empty($r['ok'])) {
            log_msg(sprintf("%s: %d novos clicks (last_id=%d, %d páginas)",
                $slug, (int)($r['novos'] ?? 0), (int)($r['last_id'] ?? 0), (int)($r['paginas'] ?? 0)), $quiet);
            $totalNovos += (int)($r['novos'] ?? 0);
        } else {
            log_msg(sprintf("ERRO %s: %s", $slug, $r['erro'] ?? '?'), $quiet);
            $errosGlobais[] = $slug . ': ' . ($r['erro'] ?? '?');
        }
        if (!empty($r['erros'])) {
            foreach ($r['erros'] as $e) {
                log_msg("  warn: {$e}", $quiet);
                $errosGlobais[] = $slug . ': ' . $e;
            }
        }
    } catch (Throwable $e) {
        log_msg("EXCEPTION {$slug}: " . $e->getMessage(), $quiet);
        $errosGlobais[] = $slug . ': ' . $e->getMessage();
    }
}

log_msg(sprintf("TOTAL: %d novos clicks em %d sites", $totalNovos, $totalSites), $quiet);

// Alerta se TODOS os sites falharam (= plugin/REST quebrado em massa)
if (count($errosGlobais) >= count($alvosSites) && count($alvosSites) > 0) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        HealthWebhook::erro('sync_clicks: falhou em todos os sites', [
            'erros' => array_slice($errosGlobais, 0, 5),
            'sites' => count($alvosSites),
            'detalhe' => 'plugin cc-click-logger ativo em todos? Application Password OK?',
        ]);
    }
    $lock->liberar();
    exit(2);
}

$lock->liberar();
exit(0);
