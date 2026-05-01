<?php
/**
 * Smoke da revisão P0 — valida fixes pré-escala:
 *   P0-1: DiscoverDb janela + arquivar + persist preserva
 *   P0-3: termos_canibal normalização (acentos + plural + word-boundary anti-FP)
 *   P0-5: ClickLog dedupe TZ Brasil
 *   P0-6: CircuitBreaker backoff exponencial
 *   P0-7: ClickLog::sincronizar idempotência (state só atualiza no fim)
 *   P0-2: sitesDisponiveis() cache (multi-call < tempo de require)
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/JsonStore.php';
require_once $rootDir . '/lib/DiscoverDb.php';
require_once $rootDir . '/lib/PrePublishLint.php';
require_once $rootDir . '/lib/ClickLog.php';
require_once $rootDir . '/lib/CircuitBreaker.php';
require_once $rootDir . '/_site_helper.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== P0-1: DiscoverDb janela + arquivar ===\n";
$tmpFile = sys_get_temp_dir() . '/test_discover_db_' . uniqid() . '.json';

// Setup: cria DB com mistura de records antigos + novos, ativos + terminais
$db1 = new DiscoverDb($tmpFile, 0); // janela=0 = carrega tudo
$db1->upsert(['site' => 'X', 'termo' => 'ativo recente', 'status' => 'aprovado',
              'data_detectada' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
$db1->upsert(['site' => 'X', 'termo' => 'ativo antigo', 'status' => 'aprovado',
              'data_detectada' => date('Y-m-d H:i:s', strtotime('-200 days'))]);
$db1->upsert(['site' => 'X', 'termo' => 'terminal recente', 'status' => 'publicado',
              'data_detectada' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
$db1->upsert(['site' => 'X', 'termo' => 'terminal antigo', 'status' => 'publicado',
              'data_detectada' => date('Y-m-d H:i:s', strtotime('-200 days'))]);
$totalDisco = count($db1->all());
check("disco tem 4 records (todos)", $totalDisco === 4);
unset($db1);

// Janela 60d
$db2 = new DiscoverDb($tmpFile, 60);
$lista = $db2->all();
check("janela=60d: ativos sempre carregam (independente da idade)",
    count(array_filter($lista, fn($r) => $r['status'] === 'aprovado')) === 2);
check("janela=60d: terminal recente carrega (10d < 60d)",
    count(array_filter($lista, fn($r) => $r['termo'] === 'terminal recente')) === 1);
check("janela=60d: terminal antigo NÃO carrega (200d > 60d)",
    count(array_filter($lista, fn($r) => $r['termo'] === 'terminal antigo')) === 0);

// Persist preserva terminais antigos NO DISCO mesmo se não carregados em memória
$db2->upsert(['site' => 'X', 'termo' => 'novo recente', 'status' => 'aprovado',
              'data_detectada' => date('Y-m-d H:i:s')]); // persist agora
unset($db2);

$db3 = new DiscoverDb($tmpFile, 0);
check("após persist: terminal antigo PRESERVADO no disco",
    count(array_filter($db3->all(), fn($r) => $r['termo'] === 'terminal antigo')) === 1);
check("após persist: novo recente também salvo",
    count(array_filter($db3->all(), fn($r) => $r['termo'] === 'novo recente')) === 1);
check("após persist: total disco = 5",
    count($db3->all()) === 5);
unset($db3);

// Arquivar
$db4 = new DiscoverDb($tmpFile, 0);
$archiveDir = dirname($tmpFile) . '/discover_trends_archive';
foreach (glob($archiveDir . '/*.json') as $f) @unlink($f); // limpa se sobrou
$res = $db4->arquivarTerminais(6);
check("arquivar(6m): arquivados >= 1 (terminal_antigo tem 200d > 6m)",
    $res['arquivados'] >= 1, 'arquivados=' . $res['arquivados']);
check("arquivar: criou ao menos 1 partição",
    $res['particoes_criadas'] >= 1);

// Após arquivar, terminal antigo SOME do principal
$db5 = new DiscoverDb($tmpFile, 0);
$apos = $db5->all();
check("após arquivar: terminal antigo NÃO está mais no principal",
    count(array_filter($apos, fn($r) => $r['termo'] === 'terminal antigo')) === 0);
check("após arquivar: ativos antigos PERMANECEM (status protege)",
    count(array_filter($apos, fn($r) => $r['termo'] === 'ativo antigo')) === 1);

// Cleanup
@unlink($tmpFile);
foreach (glob($tmpFile . '.bak.*') as $f) @unlink($f);
foreach (glob($archiveDir . '/*.json') as $f) @unlink($f);
@rmdir($archiveDir);

// ─────────────────────────────────────────────
echo "\n=== P0-3: termos_canibal anti-FP (word-boundary) ===\n";
$cfgX = [
    '_site_slug' => 'cursosenac',
    'empresa' => ['nome' => 'Sistema 2'],
    'termos_canibal' => ['inss', 'fies'],
];
$fontes = [['fonte' => ['content' => ['paragraphs' => [str_repeat('texto ', 200)]]]]];

// "inss" como bloqueio NÃO deve bater "inscricoes" (FP do bug original)
$r = PrePublishLint::avaliar([
    'termo' => 'enem 2026 abre inscrições com isenção',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'N'],
], $fontes, null, 50, $cfgX);
check("'inss' canibal NÃO bate 'inscrições' (word-boundary)",
    $r['aprovado'], 'motivos=' . json_encode($r['motivos']));

// "INSS" (uppercase) DEVE bater "inss" canibal
$r = PrePublishLint::avaliar([
    'termo' => 'INSS libera revisão de aposentadoria',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'N'],
], $fontes, null, 50, $cfgX);
check("'INSS' (case) BATE 'inss' canibal", !$r['aprovado'] && in_array('canibal_cruzado', $r['motivos']));

// "fies 2026" DEVE bater "fies"
$r = PrePublishLint::avaliar([
    'termo' => 'fies 2026 abre inscrições',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'N'],
], $fontes, null, 50, $cfgX);
check("'fies 2026' BATE 'fies' canibal", !$r['aprovado'] && in_array('canibal_cruzado', $r['motivos']));

// Plural simples: "cursos" bate "curso"
$cfgY = $cfgX;
$cfgY['termos_canibal'] = ['curso senac'];
$r = PrePublishLint::avaliar([
    'termo' => 'cursos senac abertos em Salvador',
    'site'  => 'cursosenac',
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'N'],
], $fontes, null, 50, $cfgY);
check("plural 'cursos senac' BATE singular 'curso senac' canibal",
    !$r['aprovado'] && in_array('canibal_cruzado', $r['motivos']));

// ─────────────────────────────────────────────
echo "\n=== P0-5: ClickLog dedupe TZ Brasil ===\n";
// Simula 2 clicks do mesmo IP separados por 1h (mesmo dia BRT, dias UTC diferentes)
// Click 1: 23:30 BRT = 02:30 UTC dia X+1
// Click 2: 00:30 BRT (dia X+1) = 03:30 UTC dia X+1
// Ambos no mesmo dia BRT → dedupe deve contar 1 (não 2)

$tsClick1 = strtotime('2026-04-26 23:30:00 America/Sao_Paulo');
$tsClick2 = strtotime('2026-04-27 00:30:00 America/Sao_Paulo');
$entries = [
    ['ts' => $tsClick1, 'post_id' => 99, 'ip_hash' => 'aaa'],
    ['ts' => $tsClick2, 'post_id' => 99, 'ip_hash' => 'aaa'],
];
$cont = ClickLog::clicksPorPost($entries, true, 'America/Sao_Paulo');
check("click 23:30 + 00:30 BRT do mesmo IP → 2 (DIAS BRT diferentes)",
    ($cont[99] ?? 0) === 2,
    'cont=' . json_encode($cont));

// Mesma hora BRT, 5 horas depois = mesmo dia BRT
$tsClick1 = strtotime('2026-04-26 18:00:00 America/Sao_Paulo');
$tsClick2 = strtotime('2026-04-26 23:00:00 America/Sao_Paulo');
$entries = [
    ['ts' => $tsClick1, 'post_id' => 99, 'ip_hash' => 'aaa'],
    ['ts' => $tsClick2, 'post_id' => 99, 'ip_hash' => 'aaa'],
];
$cont = ClickLog::clicksPorPost($entries, true, 'America/Sao_Paulo');
check("2 clicks no mesmo dia BRT do mesmo IP → 1 (dedupe)",
    ($cont[99] ?? 0) === 1);

// ─────────────────────────────────────────────
echo "\n=== P0-6: CircuitBreaker backoff exponencial ===\n";
$cbName = 'test_p0_' . bin2hex(random_bytes(3));
$cb = new CircuitBreaker($cbName, 1, 60, 1); // threshold 1, base 1s

$cb->falha('a');
$st = $cb->status();
check("1ª abertura: cooldown=1 (×1 base)", $st['cooldown_aplicado_s'] === 1);

sleep(2);
$cb->status(); // promove half-open
$cb->falha('b');
$st = $cb->status();
check("2ª consecutiva: cooldown=3 (×3)", $st['cooldown_aplicado_s'] === 3);

sleep(4);
$cb->status();
$cb->falha('c');
$st = $cb->status();
check("3ª consecutiva: cooldown=6 (×6)", $st['cooldown_aplicado_s'] === 6);

sleep(7);
$cb->status();
$cb->sucesso();
$st = $cb->status();
check("recovery zera consecutivas", $st['consecutivas_aberturas'] === 0);

$cb->falha('d');
$st = $cb->status();
check("após recovery, próxima abertura volta ao base (×1)", $st['cooldown_aplicado_s'] === 1);

@unlink($rootDir . '/data/circuit/' . $cbName . '.json');

// ─────────────────────────────────────────────
echo "\n=== P0-7: ClickLog::sincronizar idempotência (state preservado em falha) ===\n";
$tmpDir = sys_get_temp_dir() . '/clicktest_p0_' . uniqid();
@mkdir($tmpDir, 0777, true);
$cl = new ClickLog($tmpDir);

// Mock: força falha no httpGet usando endpoint inválido
$cfgFalho = ['wp_url' => 'http://localhost:1', 'wp_user' => 'x', 'wp_app_password' => 'y'];
$res = $cl->sincronizar('site_test', $cfgFalho, 5);
check("sincronia em endpoint falho: ok=false",
    !$res['ok']);
check("last_id permanece 0 (não atualiza state em falha total)",
    $res['last_id'] === 0);
check("erros não-vazio", !empty($res['erros']));

// Verifica state file: se últimos clicks falharam, state arquivo NÃO deve ter sido escrito
$stateFile = $tmpDir . '/_state.json';
check("state file NÃO criado pra site_test (falha total não persiste)",
    !is_file($stateFile) || !isset((json_decode(file_get_contents($stateFile), true) ?? [])['site_test']));

foreach (glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);

// ─────────────────────────────────────────────
echo "\n=== P0-2: sitesDisponiveis() cache APCu ===\n";
sitesCacheInvalidar(); // reset

$inicio = microtime(true);
$s1 = sitesDisponiveis();
$primeiraChamada = microtime(true) - $inicio;

$inicio = microtime(true);
for ($i = 0; $i < 100; $i++) sitesDisponiveis();
$cemChamadas = microtime(true) - $inicio;

check("sitesDisponiveis() retorna array", is_array($s1) && !empty($s1));
check("100 chamadas < 1ms total (cache static-var)",
    $cemChamadas < 0.001,
    sprintf('100x = %.3fms (1ª = %.3fms)', $cemChamadas * 1000, $primeiraChamada * 1000));

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[REVISÃO P0] OK\n" : "\n[REVISÃO P0] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
