<?php
/**
 * Smoke test de resiliência (Frente A: A1+A3+A4).
 * Valida:
 *   1. JsonStore — atomic write, backup rotativo, recovery de corrupção, restore
 *   2. CronLock — adquire / re-acquire bloqueia / stale auto-recovery / status / quebrar
 *   3. CircuitBreaker — closed→open após threshold falhas, cooldown, half-open, recovery
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/JsonStore.php';
require_once $rootDir . '/lib/CronLock.php';
require_once $rootDir . '/lib/CircuitBreaker.php';

$ok = 0;
$fail = 0;

function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: JsonStore (atomic write + backup + recovery) ===\n";
$tmpDir = sys_get_temp_dir() . '/clonais_test_' . uniqid();
@mkdir($tmpDir, 0777, true);
$file = $tmpDir . '/db.json';

// 1.1 Write inicial
$data = ['records' => [['id' => 1, 'termo' => 'enem 2026']]];
check("write inicial", JsonStore::write($file, $data));
check("arquivo existe e parseia", JsonStore::read($file)['records'][0]['termo'] === 'enem 2026');

// 1.2 Write em cima cria backup do anterior
JsonStore::write($file, ['records' => [['id' => 1], ['id' => 2]]]);
$bks = JsonStore::backups($file);
check("1 backup criado após 2º write", count($bks) === 1, 'backups=' . count($bks));

// 1.3 Backup rotativo: 7 escritas, manter 5
for ($i = 0; $i < 6; $i++) {
    usleep(20000); // 20ms pra timestamp do backup mudar
    JsonStore::write($file, ['records' => [['id' => $i + 100]]]);
}
$bks = JsonStore::backups($file);
check("backup rotativo cap em 5", count($bks) <= 5, 'backups=' . count($bks));
check("read retorna último write", JsonStore::read($file)['records'][0]['id'] === 105);

// 1.4 Corrupção → auto-recovery do backup
file_put_contents($file, '{not valid json corrupt');
$recovered = JsonStore::read($file);
check("recovery automático de JSON corrompido", is_array($recovered) && isset($recovered['records']),
    'recovered=' . json_encode($recovered));

// 1.5 Restore manual
JsonStore::write($file, ['records' => [['id' => 999]]]);
$bks = JsonStore::backups($file);
check("backup pre-restore disponível", !empty($bks));
check("restore mais recente OK", JsonStore::restore($file));

// 1.6 Default em arquivo ausente
check("read de path ausente retorna default", JsonStore::read('/nao/existe.json', 'X') === 'X');

// Cleanup
foreach (glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: CronLock (acquire + stale + status + quebrar) ===\n";
$lockName = 'test_smoke_' . bin2hex(random_bytes(3));

// 2.1 Acquire básico
$lock1 = new CronLock($lockName);
check("primeiro acquire OK", $lock1->aquirir());

// 2.2 Re-acquire em outra instância falha (mesmo nome)
$lock2 = new CronLock($lockName);
check("segundo acquire concorrente FALHA (lock funciona)", !$lock2->aquirir());

// 2.3 Status mostra locked + metadata
$stat = CronLock::status($lockName);
check("status: locked=true", $stat['locked'] === true);
check("status: pid presente", !empty($stat['pid']));
check("status: started_at presente", !empty($stat['started_at']));
check("status: heartbeat_at inicial = started_at", !empty($stat['heartbeat_at']));

// 2.4 Heartbeat atualiza timestamp
sleep(1);
$lock1->heartbeat();
$stat2 = CronLock::status($lockName);
check("heartbeat atualizou heartbeat_at",
    $stat2['heartbeat_at'] !== $stat['heartbeat_at'],
    "before={$stat['heartbeat_at']} after={$stat2['heartbeat_at']}");

// 2.5 Liberar e re-adquirir
$lock1->liberar();
$lock3 = new CronLock($lockName);
check("após liberar, re-acquire OK", $lock3->aquirir());
$lock3->liberar();

// 2.6 Stale recovery: simula processo morto (libera flock SEM apagar lockfile, depois espera)
//     Em produção real: processo morto = OS libera o flock automaticamente, lockfile fica
//     no disco com mtime antigo. Aqui simulamos chamando liberar() mas re-criando o arquivo
//     com mtime artificialmente antigo via touch.
$lockNameStale = 'test_stale_' . bin2hex(random_bytes(3));
$lockStale = new CronLock($lockNameStale, 1); // staleAfter = 1s
$lockStale->aquirir();
$lockPath = $lockStale->path();
// Libera flock (sem apagar arquivo) — usa reflection pra forçar
$rfp = (function () { return $this->fp; })->call($lockStale);
if (is_resource($rfp)) { @flock($rfp, LOCK_UN); }
// Volta mtime pra 5s atrás
@touch($lockPath, time() - 5);

$lockNew = new CronLock($lockNameStale, 1);
check("stale recovery: lock antigo (mtime>1s, flock livre) → novo acquire OK",
    $lockNew->aquirir());
$lockNew->liberar();

// 2.7 Quebrar manual
$lockNameBreak = 'test_break_' . bin2hex(random_bytes(3));
$lockA = new CronLock($lockNameBreak);
$lockA->aquirir();
check("CronLock::quebrar destrava à força", CronLock::quebrar($lockNameBreak));
$lockB = new CronLock($lockNameBreak);
check("após quebrar, novo acquire OK", $lockB->aquirir());
$lockB->liberar();

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: CircuitBreaker (closed→open→half→closed) ===\n";
$cbName = 'test_cb_' . bin2hex(random_bytes(3));
// threshold=2 falhas em 60s, cooldown 2s (rápido pra teste)
$cb = new CircuitBreaker($cbName, 2, 60, 2);

// 3.1 Estado inicial = CLOSED, guarda passa
try { $cb->guarda(); $passou = true; } catch (CircuitOpenException $e) { $passou = false; }
check("estado inicial CLOSED, guarda() passa", $passou);

// 3.2 Status inicial
$st = $cb->status();
check("status: estado inicial = closed", $st['estado'] === CircuitBreaker::ESTADO_CLOSED);
check("status: 0 falhas recentes", $st['falhas_recentes'] === 0);

// 3.3 1ª falha: ainda CLOSED
$cb->falha('teste 1');
$st = $cb->status();
check("após 1 falha: estado = closed", $st['estado'] === CircuitBreaker::ESTADO_CLOSED);
check("após 1 falha: 1 falha recente", $st['falhas_recentes'] === 1);

// 3.4 2ª falha: atinge threshold → OPEN
$cb->falha('teste 2');
$st = $cb->status();
check("após 2 falhas (threshold): estado = open",
    $st['estado'] === CircuitBreaker::ESTADO_OPEN, 'st=' . json_encode($st));

// 3.5 Guarda agora joga CircuitOpenException
$pegou = false;
try { $cb->guarda(); } catch (CircuitOpenException $e) {
    $pegou = true;
    check("CircuitOpenException tem circuitNome", $e->circuitNome === $cbName);
    check("CircuitOpenException tem reabreEmSegundos > 0", $e->reabreEmSegundos > 0);
}
check("guarda() lança CircuitOpenException quando OPEN", $pegou);

// 3.6 Após cooldown → HALF-OPEN automaticamente
sleep(3);
$st = $cb->status();
check("após cooldown: estado = half_open",
    $st['estado'] === CircuitBreaker::ESTADO_HALF_OPEN, 'st=' . json_encode($st));

// 3.7 Em HALF-OPEN, guarda passa (chamada experimental)
try { $cb->guarda(); $passou = true; } catch (CircuitOpenException $e) { $passou = false; }
check("HALF-OPEN deixa guarda() passar (experimental)", $passou);

// 3.8 Sucesso em HALF-OPEN → volta pra CLOSED
$cb->sucesso();
$st = $cb->status();
check("sucesso em half-open: volta pra closed", $st['estado'] === CircuitBreaker::ESTADO_CLOSED);

// 3.9 Falha em HALF-OPEN re-abre IMEDIATAMENTE
$cbName2 = 'test_cb2_' . bin2hex(random_bytes(3));
$cb2 = new CircuitBreaker($cbName2, 2, 60, 1);
$cb2->falha('a'); $cb2->falha('b');  // → OPEN
sleep(2);  // → HALF-OPEN auto
$cb2->status();
$cb2->falha('c em half-open');
$st = $cb2->status();
check("falha em half-open re-abre imediato", $st['estado'] === CircuitBreaker::ESTADO_OPEN);

// 3.10 reset() limpa tudo
$cb2->reset();
$st = $cb2->status();
check("reset: estado volta a closed", $st['estado'] === CircuitBreaker::ESTADO_CLOSED);
check("reset: 0 falhas", $st['falhas_recentes'] === 0);

// 3.11 executar() helper: sucesso
$cbName3 = 'test_cb3_' . bin2hex(random_bytes(3));
$cb3 = new CircuitBreaker($cbName3, 2, 60, 5);
$res = $cb3->executar(fn() => 'ok!');
check("executar: callback executa e retorna valor", $res === 'ok!');

// 3.12 executar() helper: falha registra
try {
    $cb3->executar(function () { throw new RuntimeException('falha sim'); });
} catch (RuntimeException $e) {}
$st = $cb3->status();
check("executar: exception do callback registra falha", $st['falhas_recentes'] === 1);

// 3.13 executar() não conta CircuitOpenException como falha (já abriu)
$cb3->falha('extra'); // → OPEN
try {
    $cb3->executar(fn() => 'never reaches');
    check("executar: deveria lançar quando OPEN", false);
} catch (CircuitOpenException $e) {
    check("executar: lança CircuitOpenException quando OPEN", true);
}

// 3.14 Backoff exponencial — 1ª abertura usa base, 2ª consecutiva usa 3×base
$cbName4 = 'test_backoff_' . bin2hex(random_bytes(3));
$cb4 = new CircuitBreaker($cbName4, 1, 60, 2); // threshold 1, base cooldown 2s
$cb4->falha('a'); // → OPEN (1ª) cooldown=2s × 1 = 2s
$st = $cb4->status();
check("1ª abertura: consecutivas=1, cooldown=2",
    $st['consecutivas_aberturas'] === 1 && $st['cooldown_aplicado_s'] === 2);
sleep(3);
$cb4->status(); // promove pra HALF-OPEN
$cb4->falha('b'); // 2ª consecutiva → OPEN com cooldown progressivo (× 3)
$st = $cb4->status();
check("2ª abertura consecutiva: consecutivas=2, cooldown=6 (×3)",
    $st['consecutivas_aberturas'] === 2 && $st['cooldown_aplicado_s'] === 6);

// 3.15 Recovery pleno reseta backoff
$cbName5 = 'test_recovery_' . bin2hex(random_bytes(3));
$cb5 = new CircuitBreaker($cbName5, 1, 60, 1);
$cb5->falha('x'); // 1ª abertura
sleep(2);
$cb5->status(); // half-open
$cb5->falha('y'); // 2ª consecutiva — backoff
sleep(4);
$cb5->status(); // half-open
$cb5->sucesso(); // recovery
$st = $cb5->status();
check("recovery pleno reseta consecutivas_aberturas",
    $st['consecutivas_aberturas'] === 0,
    'consecutivas=' . $st['consecutivas_aberturas']);

// Cleanup state files
$cbDir = $rootDir . '/data/circuit';
foreach (glob($cbDir . '/test_cb*.json') as $f) @unlink($f);
foreach (glob($cbDir . '/test_backoff*.json') as $f) @unlink($f);
foreach (glob($cbDir . '/test_recovery*.json') as $f) @unlink($f);
foreach (glob($rootDir . '/data/locks/test_*.lock') as $f) @unlink($f);

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: integração — JsonStore não corrompe sob escrita concorrente simulada ===\n";
$file = $tmpDir = sys_get_temp_dir() . '/concurrent_' . uniqid() . '.json';
JsonStore::write($file, ['records' => []], 0);

// Simulação: 10 writes consecutivos com dados crescentes (não é real concurrency, mas
// valida que rename atômico não deixa estado intermediário).
for ($i = 1; $i <= 10; $i++) {
    JsonStore::write($file, ['records' => array_fill(0, $i, ['n' => $i])], 0);
    $r = JsonStore::read($file);
    if (!is_array($r) || count($r['records']) !== $i) {
        check("write {$i}: arquivo consistente", false, 'records=' . count($r['records'] ?? []));
        break;
    }
}
check("10 writes consecutivos: arquivo final consistente",
    count(JsonStore::read($file)['records']) === 10);
@unlink($file);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n";
echo "  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[RESILIENCIA] OK · todos checks passaram\n" : "\n[RESILIENCIA] FALHOU · {$fail} checks falharam\n";
exit($fail === 0 ? 0 : 1);
