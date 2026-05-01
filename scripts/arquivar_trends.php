<?php
/**
 * arquivar_trends — move records terminais antigos pra data/discover_trends_archive/{YYYY-MM}.json
 * (P0-1 da revisão: redução de footprint do arquivo principal).
 *
 * Records publicados/rejeitados/expirados há mais de N meses são MOVIDOS pra arquivo
 * mensal. O arquivo principal fica enxuto (= load mais rápido + persist mais barato).
 * Status ativos (novo/aprovado/processando/etc) NUNCA são arquivados, mesmo se velhos.
 *
 * Uso:
 *   php scripts/arquivar_trends.php                    → cutoff 6 meses (default)
 *   php scripts/arquivar_trends.php --cutoff-meses=3   → arquiva o que tem >3m
 *   php scripts/arquivar_trends.php --dry-run
 *
 * Cron sugerido (mensal, dia 1, 4am):
 *   0 4 1 * * /usr/bin/php /var/www/clonais/scripts/arquivar_trends.php --quiet
 *
 * Exit codes: 0 = ok · 1 = lock falhou
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';

$cutoffMeses = 6;
$dryRun = false;
$quiet = false;
foreach ($argv as $a) {
    if (preg_match('/^--cutoff-meses=(\d+)$/', $a, $m)) $cutoffMeses = (int)$m[1];
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet') $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[arquivar_trends] {$m}\n"; }
function fmt_bytes(int $b): string {
    if ($b < 1024) return $b . 'B';
    if ($b < 1024 * 1024) return round($b/1024, 1) . 'KB';
    return round($b/1024/1024, 2) . 'MB';
}

$lock = new CronLock('arquivar_trends');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

if ($dryRun) {
    log_msg("[dry-run] sem alterações — análise:", $quiet);
    // Carrega tudo (janela=0) e conta o que seria arquivado
    $db = new DiscoverDb(null, 0);
    $cutoff = strtotime("-{$cutoffMeses} months");
    $all = $db->all();
    $statusTerminal = ['publicado', 'rejeitado', 'rejeitado_lint', 'expirado', 'duplicado_alto'];
    $candidatos = 0;
    foreach ($all as $r) {
        if (!in_array($r['status'] ?? '', $statusTerminal, true)) continue;
        $det = strtotime((string)($r['data_detectada'] ?? '')) ?: 0;
        if ($det === 0 || $det >= $cutoff) continue;
        $candidatos++;
    }
    log_msg("seriam arquivados: {$candidatos} records (cutoff {$cutoffMeses} meses)", $quiet);
    $lock->liberar();
    exit(0);
}

// Carrega tudo (janela=0) pra incluir terminais antigos
$db = new DiscoverDb(null, 0);
$res = $db->arquivarTerminais($cutoffMeses);
log_msg(sprintf(
    "arquivados=%d em %d partições · bytes liberados=%s (de %s pra %s)",
    $res['arquivados'],
    $res['particoes_criadas'],
    fmt_bytes($res['bytes_liberados_principais']),
    fmt_bytes($res['bytes_principais_antes']),
    fmt_bytes($res['bytes_principais_depois'])
), $quiet);

// Webhook se arquivou MUITO (> 10k records — sintoma de pipeline acumulando)
if ($res['arquivados'] > 10000) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        HealthWebhook::aviso('arquivar_trends: volume alto', [
            'arquivados'  => $res['arquivados'],
            'particoes'   => $res['particoes_criadas'],
            'mb_liberados'=> round($res['bytes_liberados_principais'] / 1024 / 1024, 2),
        ]);
    }
}

$lock->liberar();
exit(0);
