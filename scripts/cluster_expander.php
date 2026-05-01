<?php
/**
 * cluster_expander — expande trends de score alto em silos topicos (3-5 posts cada).
 *
 * Roda a cada 30min. Pra cada trend com:
 *   - status='publicado' (mãe já saiu)
 *   - score_discover >= 8 (vale expandir — Sonnet vai gastar)
 *   - origem != cluster_expander:* (não expande recursivamente)
 *   - data_detectada <= 24h (silo de assuntos atuais)
 *   - ainda não foi expandido (verifica origem dos filhos)
 * → cria 3-5 filhos.
 *
 * Idempotente: se já tem N filhos pra essa mãe, skipa.
 *
 * Uso:
 *   php scripts/cluster_expander.php                 # auto, top trends do dia
 *   php scripts/cluster_expander.php --termo="X"     # expande termo específico
 *   php scripts/cluster_expander.php --site=X
 *   php scripts/cluster_expander.php --dry-run
 *   php scripts/cluster_expander.php --max=10        # limita expansões por execução
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverClusterExpander.php';
require_once $ROOT . '/lib/Serper.php';
require_once $ROOT . '/lib/Env.php';

$termoArg = '';
$siteArg = '';
$dryRun = false;
$quiet = false;
$max = 5;
foreach ($argv as $a) {
    if (preg_match('/^--termo=(.+)$/', $a, $m)) $termoArg = $m[1];
    elseif (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    elseif (preg_match('/^--max=(\d+)$/', $a, $m)) $max = (int)$m[1];
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet') $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[cluster_expander] {$m}\n"; }

$lock = new CronLock('cluster_expander');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

Env::load($ROOT . '/.env');
$apiKey = (string)Env::get('SERPER_API_KEY', '');
if ($apiKey === '') { log_msg('SERPER_API_KEY ausente — skipa', $quiet); $lock->liberar(); exit(0); }

$serper = new Serper($apiKey);
$db = new DiscoverDb();

// Push janela + score pro DB. Quando termoArg setado, ignora janela 24h.
$cutoff24h = strtotime('-24 hours');
$filtros = [
    'status'    => 'publicado',
    'score_min' => 8.0,
    'order_by'  => 'score_desc',
];
if ($siteArg !== '') $filtros['site'] = $siteArg;
if ($termoArg === '') $filtros['data_apos'] = $cutoff24h;

$todosPublicados = $db->all($filtros);

if ($termoArg !== '') {
    $todosPublicados = array_filter($todosPublicados, fn($t) => strcasecmp(trim((string)($t['termo'] ?? '')), $termoArg) === 0);
}

$elegiveis = array_filter($todosPublicados, function ($t) {
    $orig = (string)($t['origem'] ?? '');
    if (strpos($orig, 'cluster_expander:') === 0) return false;
    if (strpos($orig, 'sazonal_preditivo:') === 0) return false; // sazonal já é silo
    return true;
});

if (empty($elegiveis)) {
    log_msg('sem trends elegíveis pra expandir', $quiet);
    $lock->liberar();
    exit(0);
}

// Ordena por score desc + data desc (mais recentes primeiro)
usort($elegiveis, function ($a, $b) {
    $sa = (float)($a['score_discover'] ?? 0);
    $sb = (float)($b['score_discover'] ?? 0);
    if ($sa !== $sb) return $sb <=> $sa;
    $da = strtotime($a['data_detectada'] ?? '') ?: 0;
    $db_ = strtotime($b['data_detectada'] ?? '') ?: 0;
    return $db_ <=> $da;
});

$elegiveis = array_slice($elegiveis, 0, $max);

$totalFilhos = 0;
$totalMaes = 0;
foreach ($elegiveis as $mae) {
    try {
        $r = DiscoverClusterExpander::expandir($mae, $serper, $db, ['dry_run' => $dryRun]);
        $totalMaes++;
        $totalFilhos += (int)($r['filhos_criados'] ?? 0);
        log_msg(sprintf("[%s] '%s' → %d filhos%s",
            (string)($mae['site'] ?? '?'),
            mb_substr((string)($mae['termo'] ?? '?'), 0, 60),
            (int)($r['filhos_criados'] ?? 0),
            !empty($r['ja_existiam']) ? " ({$r['ja_existiam']} já existiam)" : ''
        ), $quiet);
    } catch (Throwable $e) {
        log_msg("ERRO em " . ($mae['termo'] ?? '?') . ": " . $e->getMessage(), $quiet);
    }
}

log_msg(sprintf("TOTAL: %d mães processadas, %d filhos criados%s",
    $totalMaes, $totalFilhos, $dryRun ? ' (dry-run)' : ''), $quiet);

$lock->liberar();
exit(0);
