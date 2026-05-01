<?php
/**
 * backup_offsite — sync data/ pra storage S3-compatible (cron daily).
 *
 * Lê config do .env (BACKUP_S3_*). Se desabilitado, pula com nota.
 *
 * Uso:
 *   php scripts/backup_offsite.php             # roda
 *   php scripts/backup_offsite.php --dry-run   # lista o que enviaria
 *   php scripts/backup_offsite.php --quiet
 *
 * Cron sugerido (diário 5:30am):
 *   30 5 * * * /usr/bin/php /var/www/clonais/scripts/backup_offsite.php --quiet
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/BackupOffsite.php';

$dryRun = false;
$quiet = false;
foreach ($argv as $a) {
    if ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet') $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[backup_offsite] {$m}\n"; }

$lock = new CronLock('backup_offsite');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

$cfg = BackupOffsite::configFromEnv();
if ($cfg === null) {
    log_msg('BACKUP_OFFSITE_ENABLED desligado ou .env incompleto — skipping', $quiet);
    $lock->liberar();
    exit(0);
}

log_msg("destino: {$cfg['endpoint']}/{$cfg['bucket']}/{$cfg['prefix']}", $quiet);

$arquivos = BackupOffsite::listarArquivos();
log_msg(count($arquivos) . ' arquivos críticos a sincronizar', $quiet);

$res = BackupOffsite::sync($cfg, null, $dryRun);
log_msg(sprintf(
    "ok=%s · enviados=%d · falhas=%d · bytes=%.2fMB%s",
    !empty($res['ok']) ? 'sim' : 'não',
    (int)($res['enviados'] ?? 0),
    (int)($res['falhas'] ?? 0),
    ($res['bytes_total'] ?? 0) / 1024 / 1024,
    $dryRun ? ' (DRY-RUN)' : ''
), $quiet);

// Webhook se houve falhas (importante — backup é defesa final)
if (empty($res['ok']) && !$dryRun) {
    $hwPath = $ROOT . '/lib/HealthWebhook.php';
    if (is_file($hwPath)) {
        require_once $hwPath;
        HealthWebhook::erro('backup_offsite: falhas detectadas', [
            'enviados' => $res['enviados'],
            'falhas'   => $res['falhas'],
            'detalhe'  => 'backup off-site não completou — risco de perda de dados se VPS cair',
        ]);
    }
}

$lock->liberar();
exit(empty($res['ok']) ? 1 : 0);
