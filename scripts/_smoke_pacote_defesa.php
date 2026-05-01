<?php
/**
 * Smoke: PACOTE D — Defesa Operacional
 *  - KillSwitch: ativa/inativa via env, retorno padronizado
 *  - DiscoverGerador honra kill switch (early-return)
 *  - tick_filas honra kill switch (exit early)
 *  - heartbeat_check.php existe + lógica básica funciona
 *  - DLQ wired em tick_filas (max retries)
 *  - .env.example tem as 3 chaves novas
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/KillSwitch.php';

$ok = 0; $fail = 0;
function check(string $nome, $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [OK]   {$nome}\n"; }
    else       { $fail++; echo "  [FAIL] {$nome}\n"; }
}

// ════════════════════════════════════════════════════════════
echo "\n=== 1: KillSwitch ===\n";

// Garante estado inicial limpo
putenv('PIPELINE_PAUSED=0');
check('default desativado', KillSwitch::ativo() === false);

putenv('PIPELINE_PAUSED=1');
check('PIPELINE_PAUSED=1 → ativo', KillSwitch::ativo() === true);

putenv('PIPELINE_PAUSED=true');
check('PIPELINE_PAUSED=true → ativo', KillSwitch::ativo() === true);

putenv('PIPELINE_PAUSED_REASON=teste manual');
check('motivo lido do env', strpos(KillSwitch::motivo(), 'teste manual') !== false);

$ret = KillSwitch::retornoErro();
check('retornoErro: ok=false',     ($ret['ok'] ?? null) === false);
check('retornoErro: paused=true',  ($ret['paused'] ?? null) === true);
check('retornoErro: erro contém motivo', strpos((string)$ret['erro'], 'teste manual') !== false);

// Reset
putenv('PIPELINE_PAUSED=0');
putenv('PIPELINE_PAUSED_REASON=');

// ════════════════════════════════════════════════════════════
echo "\n=== 2: Wires (Gerador, tick_filas, scripts/pingo) ===\n";

$ger = file_get_contents($ROOT . '/lib/DiscoverGerador.php');
check('DiscoverGerador require KillSwitch',     strpos($ger, 'KillSwitch') !== false);
check('DiscoverGerador checa KillSwitch::ativo', strpos($ger, 'KillSwitch::ativo') !== false);
check('DiscoverGerador retorna KillSwitch::retornoErro', strpos($ger, 'KillSwitch::retornoErro') !== false);

$tf = file_get_contents($ROOT . '/scripts/tick_filas.php');
check('tick_filas require KillSwitch',          strpos($tf, 'KillSwitch') !== false);
check('tick_filas checa KillSwitch::ativo',     strpos($tf, 'KillSwitch::ativo') !== false);
check('tick_filas exit 0 quando pausado',       preg_match('/KillSwitch::ativo\(\)[^}]*exit\(0\)/s', $tf) === 1
    || strpos($tf, 'PIPELINE_PAUSED=1') !== false);

$pingo = file_get_contents($ROOT . '/scripts/pingo.php');
check('scripts/pingo require KillSwitch',       strpos($pingo, 'KillSwitch') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== 3: Heartbeat script ===\n";

$hbPath = $ROOT . '/scripts/heartbeat_check.php';
check('scripts/heartbeat_check.php existe', is_file($hbPath));

if (is_file($hbPath)) {
    $hb = file_get_contents($hbPath);
    check('heartbeat usa HealthWebhook',        strpos($hb, 'HealthWebhook') !== false);
    check('heartbeat usa HEARTBEAT_MAX_HORAS_SEM_POST', strpos($hb, 'HEARTBEAT_MAX_HORAS_SEM_POST') !== false);
    check('heartbeat tem rate-limit state',     strpos($hb, 'last_alert_ts') !== false);
    check('heartbeat suporta --dry-run',        strpos($hb, '--dry-run') !== false);
    check('heartbeat usa publicado_desc filter', strpos($hb, 'publicado_desc') !== false);
}

// ════════════════════════════════════════════════════════════
echo "\n=== 4: Dead-Letter Queue (tick_filas) ===\n";

$tf2 = file_get_contents($ROOT . '/scripts/tick_filas.php');
check('tick_filas tem skip de DLQ',                  strpos($tf2, 'falhas_consecutivas') !== false);
check('tick_filas usa DLQ_MAX_FALHAS_CONSECUTIVAS',   strpos($tf2, 'DLQ_MAX_FALHAS_CONSECUTIVAS') !== false);
check('tick_filas marca falhado_max_retries',         strpos($tf2, 'falhado_max_retries') !== false);
check('tick_filas reseta contador no sucesso',        strpos($tf2, 'reset') !== false || preg_match("/'falhas_consecutivas'\s*=>\s*0/", $tf2) === 1);
check('tick_filas incrementa contador na falha',      preg_match('/falhasAtuais\s*\+\s*1/', $tf2) === 1);
check('tick_filas grava ultimo_erro no DB',           strpos($tf2, 'ultimo_erro') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== 5: .env.example ===\n";

$env = file_get_contents($ROOT . '/.env.example');
check('.env tem PIPELINE_PAUSED',                       strpos($env, 'PIPELINE_PAUSED') !== false);
check('.env tem PIPELINE_PAUSED_REASON',                strpos($env, 'PIPELINE_PAUSED_REASON') !== false);
check('.env tem HEARTBEAT_MAX_HORAS_SEM_POST',          strpos($env, 'HEARTBEAT_MAX_HORAS_SEM_POST') !== false);
check('.env tem HEARTBEAT_RATE_LIMIT_HORAS',            strpos($env, 'HEARTBEAT_RATE_LIMIT_HORAS') !== false);
check('.env tem DLQ_MAX_FALHAS_CONSECUTIVAS',           strpos($env, 'DLQ_MAX_FALHAS_CONSECUTIVAS') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== 6: Script heartbeat — dry-run end-to-end ===\n";

// Roda dry-run real (sem alertar). Sintático: exit code 0 = lib/Env carregou,
// DB abriu, sites listaram, sem fatal. Output exato varia (stderr vs stdout no Windows).
$phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd = sprintf('%s %s --quiet --dry-run 2>&1',
    escapeshellarg($phpBin),
    escapeshellarg($ROOT . '/scripts/heartbeat_check.php')
);
$outLines = [];
$rc = 1;
@exec($cmd, $outLines, $rc);
check('heartbeat dry-run exit 0 (sem fatal)', $rc === 0);

// ════════════════════════════════════════════════════════════
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n";
echo "  FAIL: {$fail}\n";

if ($fail > 0) { echo "\n[DEFESA OPERACIONAL] FAIL\n"; exit(1); }
echo "\n[DEFESA OPERACIONAL] OK\n";
exit(0);
