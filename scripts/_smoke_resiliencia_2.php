<?php
/**
 * Smoke test de resiliência fase 2 (A2 CacheManager + D1 saude.php).
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/CacheManager.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: CacheManager (byAge / bySize / byCount / dryRun) ===\n";

$tmpDir = sys_get_temp_dir() . '/cache_test_' . uniqid();
@mkdir($tmpDir, 0777, true);

// Cria 10 arquivos JSON com mtimes variados
for ($i = 1; $i <= 10; $i++) {
    $f = $tmpDir . "/file_{$i}.json";
    file_put_contents($f, str_repeat("x", 1024 * 100)); // 100 KB cada
    @touch($f, time() - $i * 86400); // file_1=1d atrás, file_10=10d atrás
}

// Stats inicial
$st = CacheManager::stats($tmpDir);
check("stats: 10 arquivos antes", $st['arquivos'] === 10);
check("stats: ~1 MB total", $st['mb'] >= 0.9 && $st['mb'] <= 1.1, "mb={$st['mb']}");

// 1.1 byAge dry-run: 5 dias → apaga 5 arquivos (file_5..10)
$res = CacheManager::prune($tmpDir, ['byAge' => 5], true);
check("byAge dry-run: 5 marcados pra apagar", $res['arquivos_apagados'] === 5,
    'apagados=' . $res['arquivos_apagados']);
$st = CacheManager::stats($tmpDir);
check("dry-run NÃO apaga (ainda 10)", $st['arquivos'] === 10);

// 1.2 byAge real: aplica
$res = CacheManager::prune($tmpDir, ['byAge' => 5], false);
check("byAge real: 5 apagados", $res['arquivos_apagados'] === 5);
$st = CacheManager::stats($tmpDir);
check("após byAge: 5 arquivos restantes", $st['arquivos'] === 5);

// 1.3 bySize: força limite 0.2 MB → apaga até voltar
$res = CacheManager::prune($tmpDir, ['bySize' => 0]); // zerar = apaga tudo
check("bySize=0: todos apagados (forçando limite zero)",
    $res['arquivos_apagados'] === 5);

// 1.4 byCount
for ($i = 1; $i <= 8; $i++) {
    $f = $tmpDir . "/k_{$i}.json";
    file_put_contents($f, "{}");
    @touch($f, time() - $i * 100);
}
$res = CacheManager::prune($tmpDir, ['byCount' => 3]);
check("byCount=3: 5 mais antigos apagados", $res['arquivos_apagados'] === 5);
check("após byCount: 3 restantes", CacheManager::stats($tmpDir)['arquivos'] === 3);

// 1.5 Whitelist de extensões — NÃO apaga arquivos sem extensão
file_put_contents($tmpDir . '/state_file', 'xxx'); // sem extensão
file_put_contents($tmpDir . '/lockfile.lock', 'xxx');
@touch($tmpDir . '/state_file', time() - 30 * 86400);
@touch($tmpDir . '/lockfile.lock', time() - 30 * 86400);
$res = CacheManager::prune($tmpDir, ['byAge' => 5]);
check("não apaga arquivos sem extensão (state files)", file_exists($tmpDir . '/state_file'));
check("não apaga .lock", file_exists($tmpDir . '/lockfile.lock'));

// Cleanup
foreach (glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: cache_eviction.php script ===\n";
$cmd = '"C:\xampp\php\php.exe" "' . $rootDir . '/scripts/cache_eviction.php" --dry-run --quiet';
exec($cmd, $output, $rc);
check("cache_eviction dry-run roda sem erro", $rc === 0, 'rc=' . $rc);

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: Saude::checar (lib testável) ===\n";
require_once $rootDir . '/lib/Saude.php';

// 3.1 Sem token (summary)
$json = Saude::checar(false, false);
check("Saude::checar retorna array", is_array($json));
check("tem campo 'ok'", isset($json['ok']));
check("tem campo 'severidade' válido", isset($json['severidade']) && in_array($json['severidade'], ['ok', 'warning', 'error']));
check("tem campo 'timestamp'", isset($json['timestamp']));
check("retorna 'summary' (sem token)", isset($json['summary']));
check("NÃO retorna 'checks' (sem token)", !isset($json['checks']));
check("summary.db boolean", isset($json['summary']['db']));
check("summary.circuits boolean", isset($json['summary']['circuits']));
check("summary.locks boolean", isset($json['summary']['locks']));
check("summary.disk boolean", isset($json['summary']['disk']));
check("summary.sites count >= 6", isset($json['summary']['sites']) && $json['summary']['sites'] >= 6);

// 3.2 Com detalhado
$jsonD = Saude::checar(true, false);
check("detalhado: tem 'checks'", isset($jsonD['checks']));
check("detalhado: NÃO tem 'summary'", !isset($jsonD['summary']));
check("detalhado: checks.app", isset($jsonD['checks']['app']));
check("detalhado: checks.db", isset($jsonD['checks']['db']));
check("detalhado: checks.circuits.estados (3 LLMs)",
    isset($jsonD['checks']['circuits']['estados']) && count($jsonD['checks']['circuits']['estados']) === 3);
check("detalhado: checks.disk.used_pct (int)",
    isset($jsonD['checks']['disk']['used_pct']) && is_int($jsonD['checks']['disk']['used_pct']));

// 3.3 Severidade reflete estado real (em ambiente limpo, deveria ser ok ou warning)
check("severidade != 'error' em ambiente limpo (DB acessível)",
    $json['severidade'] !== 'error', 'sev=' . $json['severidade']);

// 3.4 saude.php (HTTP shim) roda sem fatal via subprocess
$cmd = '"C:\xampp\php\php.exe" "' . $rootDir . '/saude.php"';
exec($cmd . ' 2>&1', $out, $rc);
$stdout = implode("\n", $out);
$jsonShim = json_decode($stdout, true);
check("saude.php (subprocess) retorna JSON",
    is_array($jsonShim) && isset($jsonShim['ok']),
    'rc=' . $rc . ' first=' . substr($stdout, 0, 100));

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[A2+D1] OK · todos checks passaram\n" : "\n[A2+D1] FALHOU · {$fail} checks falharam\n";
exit($fail === 0 ? 0 : 1);
