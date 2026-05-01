<?php
/**
 * backup_state — snapshot diário rotativo dos state files.
 *
 * Copia data/*.json (DBs em arquivo) pra data/backup/YYYY-MM-DD/.
 * Rotação: dirs com mais de RETENCAO_DIAS são deletados.
 *
 * Por que isso existe:
 *   data/discover_trends.json é o banco principal (563+ records). Uma escrita
 *   corrompida no cron tick (disk full, kill -9 mid-write, race) e perde tudo.
 *   Snapshot diário dá ponto de retorno.
 *
 * Uso:
 *   php scripts/backup_state.php                  → roda backup do dia
 *   php scripts/backup_state.php --listar         → lista snapshots existentes
 *   php scripts/backup_state.php --restaurar=YYYY-MM-DD → restaura snapshot pra data/
 *   php scripts/backup_state.php --quiet          → modo cron (sem stdout)
 *
 * Cron sugerido (diário às 2h):
 *   0 2 * * * /usr/bin/php /var/www/clonais/scripts/backup_state.php --quiet >> /var/log/clonais/backup.log 2>&1
 */

require_once __DIR__ . '/../lib/CronLock.php';

$ROOT = dirname(__DIR__);
$DIR_DATA   = $ROOT . '/data';
$DIR_BACKUP = $DIR_DATA . '/backup';
$RETENCAO_DIAS = 30;

// Arquivos críticos (top-level data/*.json) — DBs e state mutável
$ARQUIVOS_CRITICOS = [
    'discover_trends.json',     // banco principal (trends + posts publicados)
    'afiliados.json',            // catálogo de ofertas
    'afiliados_clicks.json',     // tracking de cliques
    'fontes_pingo.json',         // 54+ feeds ativos
    'pingo_filtros.json',        // config filtro 2 camadas
    'pingo_state.json',          // estado pingo (último processado por fonte)
    'auto_refresh_state.json',   // histórico G10 (anti-loop)
];

$listar = false; $restaurar = null; $quiet = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--listar') $listar = true;
    elseif (str_starts_with($a, '--restaurar=')) $restaurar = substr($a, 12);
    elseif ($a === '--quiet') $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo '[' . date('Y-m-d H:i:s') . "] {$m}\n"; }

if (!is_dir($DIR_BACKUP)) @mkdir($DIR_BACKUP, 0775, true);

// ── Modo --listar ──
if ($listar) {
    $dirs = glob($DIR_BACKUP . '/*', GLOB_ONLYDIR) ?: [];
    sort($dirs);
    if (empty($dirs)) { echo "(nenhum snapshot)\n"; exit(0); }
    echo "Snapshots em {$DIR_BACKUP}:\n";
    foreach ($dirs as $d) {
        $arquivos = glob($d . '/*.json') ?: [];
        $tamanho = array_sum(array_map('filesize', $arquivos));
        printf("  %s · %d arquivos · %s KB\n",
            basename($d), count($arquivos), number_format($tamanho / 1024, 1));
    }
    exit(0);
}

// ── Modo --restaurar ──
if ($restaurar !== null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $restaurar)) {
        fwrite(STDERR, "Formato inválido. Use --restaurar=YYYY-MM-DD\n"); exit(2);
    }
    $src = $DIR_BACKUP . '/' . $restaurar;
    if (!is_dir($src)) {
        fwrite(STDERR, "Snapshot {$restaurar} não existe. Liste com --listar.\n"); exit(2);
    }
    fwrite(STDERR, "⚠️  RESTAURAÇÃO: vai SOBRESCREVER os JSONs atuais com os de {$restaurar}.\n");
    fwrite(STDERR, "    Faça backup do estado atual ANTES (php scripts/backup_state.php).\n");
    fwrite(STDERR, "    Continuar? digite 'sim': ");
    $linha = trim(fgets(STDIN) ?: '');
    if (strtolower($linha) !== 'sim') { fwrite(STDERR, "Abortado.\n"); exit(0); }

    $restaurados = 0;
    foreach ($ARQUIVOS_CRITICOS as $f) {
        $srcPath = $src . '/' . $f;
        if (!is_file($srcPath)) continue;
        $dstPath = $DIR_DATA . '/' . $f;
        // Backup defensivo do atual antes de sobrescrever
        if (is_file($dstPath)) {
            @copy($dstPath, $dstPath . '.before_restore_' . time());
        }
        if (@copy($srcPath, $dstPath)) {
            $restaurados++;
            echo "  ✓ {$f}\n";
        } else {
            echo "  ✗ {$f} (falha ao copiar)\n";
        }
    }
    echo "Restaurados: {$restaurados} arquivos do snapshot {$restaurar}.\n";
    echo "Os arquivos atuais foram preservados em data/*.json.before_restore_TIMESTAMP\n";
    exit(0);
}

// ── Modo backup (default) ──
$lock = new CronLock('backup_state');
if (!$lock->aquirir()) {
    log_msg('outra instância já rodando — saindo.', $quiet);
    exit(0);
}

$hoje = date('Y-m-d');
$dirHoje = $DIR_BACKUP . '/' . $hoje;
if (!is_dir($dirHoje)) @mkdir($dirHoje, 0775, true);

$copiados = 0; $tamanhoTotal = 0; $faltando = [];
foreach ($ARQUIVOS_CRITICOS as $f) {
    $src = $DIR_DATA . '/' . $f;
    if (!is_file($src)) { $faltando[] = $f; continue; }
    $dst = $dirHoje . '/' . $f;
    if (@copy($src, $dst)) {
        $copiados++;
        $tamanhoTotal += filesize($dst);
    }
}
log_msg(sprintf('Backup %s: %d arquivos · %s KB · %d ausentes (%s)',
    $hoje, $copiados, number_format($tamanhoTotal / 1024, 1),
    count($faltando), implode(',', $faltando) ?: '-'), $quiet);

// Alerta se backup falhou completamente (nenhum arquivo copiado quando esperado)
if ($copiados === 0) {
    require_once __DIR__ . '/../lib/HealthWebhook.php';
    HealthWebhook::erro('backup_state: zero arquivos copiados', [
        'data' => $hoje, 'faltando' => count($faltando),
    ]);
}

// ── Rotação: remove dirs com mais de RETENCAO_DIAS ──
$cutoff = strtotime("-{$RETENCAO_DIAS} days");
$dirs = glob($DIR_BACKUP . '/*', GLOB_ONLYDIR) ?: [];
$removidos = 0;
foreach ($dirs as $d) {
    $nome = basename($d);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nome)) continue;
    if (strtotime($nome) >= $cutoff) continue;
    foreach ((glob($d . '/*') ?: []) as $f) @unlink($f);
    if (@rmdir($d)) $removidos++;
}
if ($removidos > 0) log_msg("Rotação: {$removidos} snapshots antigos removidos (>{$RETENCAO_DIAS}d)", $quiet);

$lock->liberar();
exit(0);
