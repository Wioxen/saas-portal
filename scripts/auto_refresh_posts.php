<?php
/**
 * auto_refresh_posts — cron diário (G10).
 *
 * Posts decaem em CTR após 3-7 dias se ficam estáticos. Este script:
 *   1) Pra cada site, consulta GSC (Discover, dimensão page) em 2 janelas adjacentes (7d vs 7d anteriores)
 *   2) Detecta posts com queda ≥ threshold% E ≥ minClicks na janela base (filtro de ruído)
 *   3) Mapeia URL pública → trend_id local (via DiscoverDb + WP slug)
 *   4) Pra cada candidato NÃO refreshado nos últimos 14d (cooldown anti-loop): chama DiscoverReviewer::revisar
 *   5) Persiste evento em data/auto_refresh_state.json
 *
 * Pré-requisitos:
 *   - data/google_credentials.json (Service Account com webmasters.readonly)
 *   - Service Account adicionada como "Restricted user" em cada Search Console property
 *   - Sites com ≥14 dias de histórico GSC (sem isso, janela anterior fica vazia)
 *
 * Uso:
 *   php scripts/auto_refresh_posts.php                            → roda em todos os 6 sites
 *   php scripts/auto_refresh_posts.php --site=cursosenac          → só 1 site
 *   php scripts/auto_refresh_posts.php --dry-run                  → detecta mas NÃO chama Reviewer
 *   php scripts/auto_refresh_posts.php --min-clicks=5             → reduz limiar (sites de baixo tráfego)
 *   php scripts/auto_refresh_posts.php --threshold=30             → queda mínima 30% (default 20)
 *   php scripts/auto_refresh_posts.php --max-por-site=3           → limita N refreshes/site/execução (default 5)
 *   php scripts/auto_refresh_posts.php --tipo=web                 → busca normal em vez de Discover
 *   php scripts/auto_refresh_posts.php --historico=7              → mostra últimos 7d de refreshes e SAI
 *
 * Cron sugerido (diário, 4h da manhã — após GSC processar dado do dia anterior):
 *   0 4 * * * /usr/bin/php /var/www/clonais/scripts/auto_refresh_posts.php --quiet >> /var/log/clonais/auto_refresh.log 2>&1
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/lib/AutoRefresh.php';
require_once $ROOT . '/lib/DiscoverReviewer.php';
require_once $ROOT . '/_site_helper.php';

$cfg = require $ROOT . '/config.php';

// ── parse args ──
$soSite     = null;
$dryRun     = false;
$minClicks  = 10;
$threshold  = 20;
$maxPorSite = 5;
$tipo       = 'discover';
$quiet      = false;
$historico  = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site='))            $soSite = substr($arg, 7);
    elseif ($arg === '--dry-run')                    $dryRun = true;
    elseif (str_starts_with($arg, '--min-clicks='))  $minClicks = max(1, (int)substr($arg, 13));
    elseif (str_starts_with($arg, '--threshold='))   $threshold = max(5, (int)substr($arg, 12));
    elseif (str_starts_with($arg, '--max-por-site=')) $maxPorSite = max(1, (int)substr($arg, 15));
    elseif (str_starts_with($arg, '--tipo='))        $tipo = substr($arg, 7);
    elseif ($arg === '--quiet')                      $quiet = true;
    elseif (str_starts_with($arg, '--historico='))   $historico = max(1, (int)substr($arg, 12));
}

function log_msg(string $msg, bool $quiet): void
{
    if ($quiet) return;
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// ── modo histórico (não roda nada, só lê state file) ──
if ($historico !== null) {
    $db = new DiscoverDb();
    $wpDummy = new Wordpress($cfg['wp_url'] ?? 'http://x', $cfg['wp_user'] ?? '', $cfg['wp_app_password'] ?? '');
    $ar = new AutoRefresh($cfg, $db, $wpDummy);
    $eventos = $ar->listarHistorico($historico);
    if (empty($eventos)) {
        echo "(sem eventos nos últimos {$historico} dias)\n";
        exit(0);
    }
    foreach ($eventos as $ev) {
        printf("%s | trend=%-5d | site=%-15s | delta=%+4d%% | %s\n",
            substr($ev['refreshed_at'] ?? '', 0, 10),
            $ev['trend_id'] ?? 0,
            $ev['site'] ?? '?',
            $ev['delta_clicks_pct'] ?? 0,
            !empty($ev['reviewer_ok']) ? 'OK' : ('ERRO: ' . ($ev['reviewer_erro'] ?? '?'))
        );
    }
    exit(0);
}

// Lock global — DESCONSIDERANDO modo --historico (só leitura).
// Cron diário 04h pode sobrepor se servidor estava lento ou restart próximo. Falha graciosa.
$arLock = new CronLock('auto_refresh_posts');
if (!$arLock->aquirir()) { log_msg('outro auto_refresh_posts já rodando — saindo.', $quiet); exit(0); }

// ── identifica sites a processar ──
$sites = sitesDisponiveis();
if ($soSite !== null) {
    if (!isset($sites[$soSite])) {
        fwrite(STDERR, "Site '{$soSite}' não encontrado em sites.php\n");
        exit(2);
    }
    $sites = [$soSite => $sites[$soSite]];
}

$totalDetectados = 0;
$totalRefreshed  = 0;
$totalPulados    = 0;
$totalFalhas     = 0;

foreach ($sites as $slug => $siteCfg) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slug);
    $wpUrl = rtrim((string)($cfgSite['wp_url'] ?? ''), '/');
    if ($wpUrl === '') {
        log_msg("[{$slug}] sem wp_url — pulando", $quiet);
        continue;
    }
    $gscUrl = (string)($cfgSite['gsc_site_url'] ?? ($wpUrl . '/'));

    log_msg("[{$slug}] consultando GSC tipo={$tipo} para {$gscUrl}...", $quiet);

    $db = new DiscoverDb();
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);
    $ar = new AutoRefresh($cfgSite, $db, $wp);

    try {
        $candidatos = $ar->detectarPostsEmQueda($gscUrl, 7, $minClicks, $threshold, $tipo);
    } catch (Throwable $e) {
        log_msg("[{$slug}] ERRO GSC: {$e->getMessage()}", $quiet);
        $totalFalhas++;
        continue;
    }

    if (empty($candidatos)) {
        log_msg("[{$slug}] nenhum post em queda (≥{$minClicks} clicks na janela base + queda ≥{$threshold}%)", $quiet);
        continue;
    }

    log_msg("[{$slug}] {$candidatos[0]['janela_anterior']} → {$candidatos[0]['janela_atual']}: " . count($candidatos) . " candidatos detectados", $quiet);
    $totalDetectados += count($candidatos);

    $processadosNesteSite = 0;
    foreach ($candidatos as $c) {
        if ($processadosNesteSite >= $maxPorSite) {
            log_msg("[{$slug}] limite max-por-site={$maxPorSite} atingido — restantes serão processados em execução futura", $quiet);
            break;
        }

        $trendId = $ar->mapearUrlParaTrendId($c['url'], $slug);
        if ($trendId === null) {
            log_msg(sprintf("[%s] ignorando %s (delta %+d%%) — URL não mapeia em DB local", $slug, $c['url'], $c['delta_clicks_pct']), $quiet);
            $totalPulados++;
            continue;
        }

        if ($ar->jaRefreshou($trendId)) {
            log_msg(sprintf("[%s] trend=%d (%s) já refreshado em janela cooldown — pulando", $slug, $trendId, $c['url']), $quiet);
            $totalPulados++;
            continue;
        }

        if ($dryRun) {
            log_msg(sprintf("[%s] [DRY] trend=%d (%s) delta=%+d%% — Reviewer NÃO chamado", $slug, $trendId, $c['url'], $c['delta_clicks_pct']), $quiet);
            $processadosNesteSite++;
            continue;
        }

        log_msg(sprintf("[%s] trend=%d (%s) delta=%+d%% → chamando Reviewer...", $slug, $trendId, $c['url'], $c['delta_clicks_pct']), $quiet);
        try {
            $reviewer = new DiscoverReviewer($cfgSite, $db);
            $resultado = $reviewer->revisar($trendId);
        } catch (Throwable $e) {
            $resultado = ['ok' => false, 'erro' => $e->getMessage()];
        }

        $ar->marcarRefresh($trendId, $slug, $c, $resultado);
        if (!empty($resultado['ok'])) {
            $totalRefreshed++;
            $processadosNesteSite++;
            log_msg(sprintf("[%s] trend=%d ✓ refreshed (%s → %s)", $slug, $trendId,
                substr((string)($resultado['titulo_antes'] ?? ''), 0, 50),
                substr((string)($resultado['titulo_depois'] ?? ''), 0, 50)
            ), $quiet);
        } else {
            $totalFalhas++;
            log_msg(sprintf("[%s] trend=%d ✗ Reviewer falhou: %s", $slug, $trendId, $resultado['erro'] ?? '?'), $quiet);
        }
    }
}

log_msg(sprintf("RESUMO: %d detectados | %d refreshed | %d pulados | %d falhas",
    $totalDetectados, $totalRefreshed, $totalPulados, $totalFalhas
), $quiet);

// Alerta reativo se houve falhas significativas
if ($totalFalhas > 0 && $totalRefreshed === 0) {
    require_once $ROOT . '/lib/HealthWebhook.php';
    HealthWebhook::erro('auto_refresh_posts: todas falharam', [
        'detectados' => $totalDetectados, 'falhas' => $totalFalhas, 'pulados' => $totalPulados,
    ]);
}

exit($totalFalhas > 0 && $totalRefreshed === 0 ? 3 : 0);
