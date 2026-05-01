<?php
/**
 * anomaly_detect — detecta queda anômala de tráfego (F1).
 *
 * Cron daily compara métricas do PostPerformanceLog últimas 24h vs média 7d anterior.
 * Quando site OU cluster cai >50% (com mínimo de 100 impressões na baseline pra
 * proteção estatística), dispara webhook.
 *
 * Útil pra:
 *   - Detectar penalização Discover silenciosa (tráfego cai sem motivo aparente)
 *   - Pegar problema de indexação (sitemap quebrado, robots.txt desativando bot)
 *   - Identificar cluster que parou de funcionar (fonte caiu, padrão saturou)
 *
 * Uso:
 *   php scripts/anomaly_detect.php
 *   php scripts/anomaly_detect.php --threshold=70   # menos sensível (alerta só >70%)
 *   php scripts/anomaly_detect.php --dry-run        # só lista, não dispara webhook
 *
 * Cron: 0 8 * * * /usr/bin/php /var/www/clonais/scripts/anomaly_detect.php --quiet
 *
 * NÃO substitui Saude::checar (que é health pontual). Anomaly = degradação progressiva.
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/PostPerformanceLog.php';

$threshold = 50; // % queda
$dryRun = false;
$quiet = false;
$minBaseline = 100; // mínimo impressões na baseline (anti-ruído)
foreach ($argv as $a) {
    if (preg_match('/^--threshold=(\d+)$/', $a, $m)) $threshold = (int)$m[1];
    elseif (preg_match('/^--min=(\d+)$/', $a, $m))    $minBaseline = (int)$m[1];
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet')   $quiet  = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[anomaly_detect] {$m}\n"; }

$lock = new CronLock('anomaly_detect');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

// Carrega 9 dias de dados (mês atual + anterior cobre)
$mesAtual    = date('Y-m');
$mesAnterior = date('Y-m', strtotime('-1 month'));
$entries = array_merge(
    PostPerformanceLog::lerLog($mesAtual),
    PostPerformanceLog::lerLog($mesAnterior)
);

if (empty($entries)) {
    log_msg('sem dados em PostPerformanceLog', $quiet);
    $lock->liberar();
    exit(0);
}

// Filtra surface=discover (foco do Discover; Search e News são complementares)
$discoverEntries = array_values(array_filter($entries, fn($e) => ($e['surface'] ?? '') === 'discover'));

// Janelas: ÚLTIMAS 24h (ts >= today-3d, dia mais recente disponível) vs 7d anteriores
$tsAgora = time();
$cutoffAtual    = strtotime('-3 days');
$cutoffBaseline = strtotime('-10 days');

$atual = []; // site|cluster => [clicks, impressions]
$baseline = [];
foreach ($discoverEntries as $e) {
    $ts = strtotime($e['ts'] ?? '') ?: 0;
    if ($ts === 0) continue;
    $key = ($e['site'] ?? '?') . '|' . ($e['surface'] ?? '?');
    $imp = (int)($e['impressions'] ?? 0);
    $clk = (int)($e['clicks'] ?? 0);
    if ($ts >= $cutoffAtual) {
        $atual[$key]['imp']  = ($atual[$key]['imp']  ?? 0) + $imp;
        $atual[$key]['clk']  = ($atual[$key]['clk']  ?? 0) + $clk;
    } elseif ($ts >= $cutoffBaseline) {
        $baseline[$key]['imp'] = ($baseline[$key]['imp'] ?? 0) + $imp;
        $baseline[$key]['clk'] = ($baseline[$key]['clk'] ?? 0) + $clk;
    }
}

$anomalias = [];
foreach ($baseline as $key => $base) {
    $impBase = (int)($base['imp'] ?? 0);
    if ($impBase < $minBaseline) continue; // ruído

    $impAtual = (int)($atual[$key]['imp'] ?? 0);
    $clkBase  = (int)($base['clk'] ?? 0);
    $clkAtual = (int)($atual[$key]['clk'] ?? 0);

    // Baseline é 7 dias; atual é 1-3 dias. Normaliza pra "por dia"
    // Baseline / 7 dias vs Atual / 3 dias
    $impBasePorDia  = $impBase / 7.0;
    $impAtualPorDia = $impAtual / 3.0;
    $deltaImpPct = $impBasePorDia > 0 ? round(100 * ($impAtualPorDia - $impBasePorDia) / $impBasePorDia, 1) : 0;

    if ($deltaImpPct < -$threshold) {
        [$site, $surface] = explode('|', $key, 2);
        $anomalias[] = [
            'site'                 => $site,
            'surface'              => $surface,
            'impressions_baseline' => $impBase,
            'impressions_atual'    => $impAtual,
            'delta_impressions_pct'=> $deltaImpPct,
            'clicks_baseline'      => $clkBase,
            'clicks_atual'         => $clkAtual,
        ];
    }
}

usort($anomalias, fn($a, $b) => $a['delta_impressions_pct'] <=> $b['delta_impressions_pct']);

log_msg(sprintf("Análise: %d sites com baseline ≥%d impr · %d ANOMALIAS detectadas (>%d%% queda)",
    count($baseline), $minBaseline, count($anomalias), $threshold), $quiet);

foreach ($anomalias as $a) {
    log_msg(sprintf("  ⚠ [%s|%s] %s%%: %d → %d impr (-%d%% vs baseline)",
        $a['site'], $a['surface'],
        ($a['delta_impressions_pct'] > 0 ? '+' : '') . $a['delta_impressions_pct'],
        $a['impressions_baseline'], $a['impressions_atual'],
        abs((int)$a['delta_impressions_pct'])
    ), $quiet);
}

// Webhook: dispara 1x se há anomalias
if (!$dryRun && !empty($anomalias)) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        $top = array_slice($anomalias, 0, 5);
        HealthWebhook::aviso(
            'Anomaly detect: ' . count($anomalias) . ' sites com queda em Discover',
            [
                'threshold_pct' => $threshold,
                'top_quedas'    => array_map(fn($a) =>
                    sprintf("%s: %s%% (%d→%d)",
                        $a['site'],
                        $a['delta_impressions_pct'],
                        $a['impressions_baseline'],
                        $a['impressions_atual']
                    ), $top),
                'detalhe'       => 'verificar GSC + sitemap + indexação',
            ]
        );
        log_msg('webhook disparado', $quiet);
    }
}

$lock->liberar();
exit(0);
