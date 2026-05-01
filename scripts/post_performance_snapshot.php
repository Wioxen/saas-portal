<?php
/**
 * post_performance_snapshot — cron diário (B2 Frente B Inteligência Viral).
 *
 * Pra cada site, captura snapshot do GSC nas 3 surfaces (web, discover, googleNews) das
 * últimas 24h pra todos os posts publicados nos últimos 30 dias. Append em JSONL mensal.
 *
 * Sem isso, mês 1 do deploy é cego — não dá pra otimizar prompt sem feedback.
 *
 * Uso:
 *   php scripts/post_performance_snapshot.php                     → todos sites, dia=hoje-3
 *   php scripts/post_performance_snapshot.php --site=cursosenac
 *   php scripts/post_performance_snapshot.php --dia=2026-04-25    → snapshot de dia específico (backfill)
 *   php scripts/post_performance_snapshot.php --janela=60         → 60d de posts elegíveis
 *   php scripts/post_performance_snapshot.php --max-posts=50      → limita por site
 *   php scripts/post_performance_snapshot.php --dry-run           → não escreve JSONL
 *   php scripts/post_performance_snapshot.php --quiet
 *
 * Cron sugerido (diário, 5:30am — após GSC processar dado do dia anterior):
 *   30 5 * * * /usr/bin/php /var/www/clonais/scripts/post_performance_snapshot.php --quiet >> /var/log/clonais/perf_snapshot.log 2>&1
 *
 * Exit codes: 0 = ok · 1 = lock falhou · 2 = erro fatal
 */

set_time_limit(0);
ini_set('memory_limit', '512M');
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverSearchConsole.php';
require_once $ROOT . '/lib/PostPerformanceLog.php';
require_once $ROOT . '/_site_helper.php';

// Args
$siteArg   = '';
$diaAlvo   = null;
$janela    = 30;
$maxPosts  = 200;
$dryRun    = false;
$quiet     = false;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m))     $siteArg  = $m[1];
    elseif (preg_match('/^--dia=(.+)$/', $a, $m)) $diaAlvo  = $m[1];
    elseif (preg_match('/^--janela=(\d+)$/', $a, $m)) $janela = (int)$m[1];
    elseif (preg_match('/^--max-posts=(\d+)$/', $a, $m)) $maxPosts = (int)$m[1];
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet')   $quiet  = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[perf_snapshot] {$m}\n"; }

// Lock anti-overlap (cron diário pode atrasar)
$lockNome = 'perf_snapshot' . ($siteArg !== '' ? '_' . preg_replace('/[^a-z0-9_-]+/', '', $siteArg) : '');
$lock = new CronLock($lockNome);
if (!$lock->aquirir()) {
    log_msg('outra instância rodando — saindo', $quiet);
    exit(1);
}

$cfgBase = require $ROOT . '/config.php';
$sites = sitesDisponiveis();
$alvosSites = $siteArg !== '' ? [$siteArg => $sites[$siteArg] ?? null] : $sites;

$db = new DiscoverDb();
$gsc = new DiscoverSearchConsole();
$log = new PostPerformanceLog();

$totalEntries = 0;
$totalPosts = 0;
$errosGlobais = [];

foreach ($alvosSites as $slug => $cfgSite) {
    if (!is_array($cfgSite)) {
        log_msg("skip {$slug}: cfg ausente", $quiet);
        continue;
    }
    $cfgMesclado = $cfgBase;
    aplicarSite($cfgMesclado, $sites, $slug);

    $opts = [
        'janela_d'  => $janela,
        'max_posts' => $maxPosts,
    ];
    if ($diaAlvo !== null) $opts['dia_alvo'] = $diaAlvo;

    if ($dryRun) {
        log_msg("[dry-run] {$slug}: sem snapshot", $quiet);
        continue;
    }

    try {
        $r = $log->snapshot($slug, $cfgMesclado, $db, $gsc, $opts);
        if (!empty($r['ok'])) {
            $linha = sprintf(
                "%s: %d posts, %d entries, surfaces=%d, dia=%s",
                $slug,
                (int)($r['posts_processados'] ?? 0),
                (int)($r['entries_logadas'] ?? 0),
                (int)($r['surfaces_consultadas'] ?? 0),
                (string)($r['dia_alvo'] ?? '?')
            );
            log_msg($linha, $quiet);
            $totalEntries += (int)($r['entries_logadas'] ?? 0);
            $totalPosts += (int)($r['posts_processados'] ?? 0);
            if (!empty($r['erros'])) {
                foreach ($r['erros'] as $e) log_msg("  warn: {$e}", $quiet);
                $errosGlobais = array_merge($errosGlobais, $r['erros']);
            }
        } else {
            log_msg("ERRO {$slug}: " . ($r['erro'] ?? '?'), $quiet);
            $errosGlobais[] = $slug . ': ' . ($r['erro'] ?? '?');
        }
    } catch (Throwable $e) {
        log_msg("EXCEPTION {$slug}: " . $e->getMessage(), $quiet);
        $errosGlobais[] = $slug . ': ' . $e->getMessage();
    }
}

log_msg(sprintf("TOTAL: %d posts, %d entries gravadas%s",
    $totalPosts, $totalEntries, $dryRun ? ' (dry-run)' : ''), $quiet);

// Alerta se TODAS as surfaces falharam (= GSC quebrado)
if (!empty($errosGlobais) && count($errosGlobais) >= count($alvosSites)) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        HealthWebhook::erro('post_performance_snapshot: GSC falhou em todos os sites', [
            'erros'         => array_slice($errosGlobais, 0, 5),
            'sites'         => count($alvosSites),
        ]);
    }
}

$lock->liberar();
exit(0);
