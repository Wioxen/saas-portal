<?php
/**
 * Smoke do pacote hardening (~5h):
 *   SAST check, E2E pipeline (já rodado), BackupOffsite estrutura, DR runbook presente
 */

declare(strict_types=1);
$rootDir = dirname(__DIR__);

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: SAST executa sem error fatal ===\n";
$cmd = '"C:\xampp\php\php.exe" "' . $rootDir . '/scripts/_sast_check.php" --json 2>nul';
exec($cmd, $out, $rc);
$rep = json_decode(implode("\n", $out), true);
check("SAST roda e retorna JSON", is_array($rep));
check("SAST analisou ≥150 arquivos", isset($rep['arquivos_analisados']) && $rep['arquivos_analisados'] >= 150);
check("SAST 0 errors", isset($rep['errors']) && $rep['errors'] === 0,
    'errors=' . ($rep['errors'] ?? '?'));

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: E2E smoke verde (re-execução) ===\n";
$cmd = '"C:\xampp\php\php.exe" "' . $rootDir . '/scripts/_smoke_e2e.php" 2>&1';
exec($cmd, $outE2E, $rcE2E);
check("E2E smoke exit 0", $rcE2E === 0);
$lastLines = implode("\n", array_slice($outE2E, -3));
check("E2E reporta OK final", strpos($lastLines, '[E2E] OK') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: BackupOffsite estrutura ===\n";
require_once $rootDir . '/lib/BackupOffsite.php';

check("BackupOffsite tem método configFromEnv", method_exists('BackupOffsite', 'configFromEnv'));
check("BackupOffsite tem método listarArquivos", method_exists('BackupOffsite', 'listarArquivos'));
check("BackupOffsite tem método sync", method_exists('BackupOffsite', 'sync'));

// configFromEnv sem .env → null
$cfg = BackupOffsite::configFromEnv();
check("configFromEnv sem BACKUP_OFFSITE_ENABLED → null", $cfg === null);

// listarArquivos retorna array (mesmo que vazio em ambiente teste)
$arquivos = BackupOffsite::listarArquivos();
check("listarArquivos retorna array", is_array($arquivos));

// PADROES_CRITICOS tem itens críticos esperados
$rfBackup = new ReflectionClass('BackupOffsite');
$padroes = $rfBackup->getReflectionConstant('PADROES_CRITICOS')->getValue();
check("PADROES inclui discover_trends.json",
    in_array('discover_trends.json', $padroes, true));
check("PADROES inclui click_log/*.jsonl",
    in_array('click_log/*.jsonl', $padroes, true));
check("PADROES inclui post_performance/*.jsonl",
    in_array('post_performance/*.jsonl', $padroes, true));

// sync com cfg fictícia + dryRun
$cfgFake = [
    'endpoint' => 'https://s3.example.com',
    'region'   => 'us-east-1',
    'bucket'   => 'test',
    'key'      => 'k',
    'secret'   => 's',
    'prefix'   => 'clonais/',
];
$res = BackupOffsite::sync($cfgFake, $rootDir . '/data', true);
check("sync dry-run retorna estrutura completa",
    isset($res['ok']) && isset($res['enviados']) && isset($res['bytes_total']));

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: backup_offsite.php script ===\n";
check("scripts/backup_offsite.php existe",
    is_file($rootDir . '/scripts/backup_offsite.php'));
$cmd = '"C:\xampp\php\php.exe" -l "' . $rootDir . '/scripts/backup_offsite.php" 2>&1';
exec($cmd, $outLint, $rcLint);
check("backup_offsite.php sintaxe OK", $rcLint === 0);

// Roda script (sem .env BACKUP_OFFSITE_ENABLED → skipa graciosamente)
$cmd = '"C:\xampp\php\php.exe" "' . $rootDir . '/scripts/backup_offsite.php" --quiet 2>&1';
exec($cmd, $outRun, $rcRun);
check("backup_offsite.php sem env: exit 0 (skip silencioso)", $rcRun === 0);

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: DR Runbook documentado ===\n";
$drFile = $rootDir . '/docs/DR_RUNBOOK.md';
check("docs/DR_RUNBOOK.md existe", is_file($drFile));
$drSrc = (string)file_get_contents($drFile);
check("DR cobre cenário A (VPS morto)", strpos($drSrc, 'Cenário A') !== false);
check("DR cobre cenário B (data corrompida)", strpos($drSrc, 'Cenário B') !== false);
check("DR cobre cenário C (MariaDB perdida)", strpos($drSrc, 'Cenário C') !== false);
check("DR menciona BACKUP_OFFSITE_ENABLED", strpos($drSrc, 'BACKUP_OFFSITE_ENABLED') !== false);
check("DR tem checklist de pré-requisitos",
    strpos($drSrc, 'Pré-requisitos') !== false || strpos($drSrc, 'CHECKLIST') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: Signature V4 implementada (regex sanity) ===\n";
$bkSrc = file_get_contents($rootDir . '/lib/BackupOffsite.php');
check("BackupOffsite usa AWS4-HMAC-SHA256", strpos($bkSrc, 'AWS4-HMAC-SHA256') !== false);
check("BackupOffsite calcula signing key (kDate→kRegion→kService→kSigning)",
    strpos($bkSrc, '$kDate') !== false &&
    strpos($bkSrc, '$kRegion') !== false &&
    strpos($bkSrc, '$kSigning') !== false);
check("BackupOffsite suporta endpoint custom (não só AWS)",
    strpos($bkSrc, 'BACKUP_S3_ENDPOINT') !== false);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[HARDENING] OK\n" : "\n[HARDENING] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
