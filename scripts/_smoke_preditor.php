<?php
/**
 * Smoke test do B4 PingoPreditor.
 * Valida classificação new/rising/stable/declining + state persistence + boost score.
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/PingoPreditor.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// State em arquivo temporário
$stateFile = sys_get_temp_dir() . '/preditor_test_' . uniqid() . '.json';

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: Termo NOVO (sem histórico) ===\n";
$preditor = new PingoPreditor($stateFile);
$out = $preditor->classificar([
    ['termo' => 'enem 2026 isencao', 'traffic' => 5000, 'ts' => time() - 3600],
]);
check("primeira aparição → label='new'",
    ($out[0]['predictor_label'] ?? '') === 'new', 'label=' . ($out[0]['predictor_label'] ?? '?'));
check("momentum_pct=0 (sem snapshot anterior)",
    ($out[0]['momentum_pct'] ?? -1) === 0);
check("snapshot_anterior=null", array_key_exists('snapshot_anterior', $out[0]) && $out[0]['snapshot_anterior'] === null);

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: Termo RISING (cresceu >50% vs snapshot anterior) ===\n";
// 2ª aparição 30min depois (dentro de JANELA_MIN..MAX), traffic cresceu de 5k pra 9k = +80%
$out = $preditor->classificar([
    ['termo' => 'enem 2026 isencao', 'traffic' => 9000, 'ts' => time() - 1800],
]);
check("2ª aparição com +80% → label='rising'",
    ($out[0]['predictor_label'] ?? '') === 'rising',
    'label=' . ($out[0]['predictor_label'] ?? '?') . ' momentum=' . ($out[0]['momentum_pct'] ?? '?'));
check("momentum_pct = 80",
    abs(($out[0]['momentum_pct'] ?? 0) - 80) < 0.5,
    'momentum=' . ($out[0]['momentum_pct'] ?? '?'));
check("snapshot_anterior preenchido com traffic=5000",
    isset($out[0]['snapshot_anterior']) && (int)$out[0]['snapshot_anterior']['traffic'] === 5000);

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: Termo STABLE (delta <50%) ===\n";
// 3ª aparição: traffic 10k vs 9k = +11%
$out = $preditor->classificar([
    ['termo' => 'enem 2026 isencao', 'traffic' => 10000, 'ts' => time()],
]);
check("delta +11% → label='stable'",
    ($out[0]['predictor_label'] ?? '') === 'stable',
    'label=' . ($out[0]['predictor_label'] ?? '?'));

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: Termo DECLINING (caiu >20%) ===\n";
$preditor2 = new PingoPreditor(sys_get_temp_dir() . '/preditor_test2_' . uniqid() . '.json');
// Primeiro snapshot
$preditor2->classificar([['termo' => 'pico antigo', 'traffic' => 10000, 'ts' => time() - 3600]]);
// Segundo snapshot (40min depois): traffic 6000 = -40%
$out2 = $preditor2->classificar([
    ['termo' => 'pico antigo', 'traffic' => 6000, 'ts' => time() - 1200],
]);
check("delta -40% → label='declining'",
    ($out2[0]['predictor_label'] ?? '') === 'declining',
    'label=' . ($out2[0]['predictor_label'] ?? '?') . ' momentum=' . ($out2[0]['momentum_pct'] ?? '?'));

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: Snapshot fora da janela (>2h) — não classifica como rising ===\n";
$preditor3 = new PingoPreditor(sys_get_temp_dir() . '/preditor_test3_' . uniqid() . '.json');
// snapshot 3h atrás
$preditor3->classificar([['termo' => 'velho', 'traffic' => 1000, 'ts' => time() - 10800]]);
// agora — delta enorme mas snapshot fora da janela
$out3 = $preditor3->classificar([
    ['termo' => 'velho', 'traffic' => 5000, 'ts' => time()],
]);
check("snapshot 3h atrás (>JANELA_MAX) → não classifica como rising",
    ($out3[0]['predictor_label'] ?? '') !== 'rising',
    'label=' . ($out3[0]['predictor_label'] ?? '?'));

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: Persistência do state entre instâncias ===\n";
$statePersist = sys_get_temp_dir() . '/preditor_persist_' . uniqid() . '.json';
$p1 = new PingoPreditor($statePersist);
$p1->classificar([['termo' => 'persistente', 'traffic' => 3000, 'ts' => time() - 2400]]);
unset($p1);

$p2 = new PingoPreditor($statePersist);
$out4 = $p2->classificar([
    ['termo' => 'persistente', 'traffic' => 6000, 'ts' => time()],
]);
check("nova instância vê snapshot da anterior → rising (+100%)",
    ($out4[0]['predictor_label'] ?? '') === 'rising',
    'label=' . ($out4[0]['predictor_label'] ?? '?'));

// ─────────────────────────────────────────────
echo "\n=== TESTE 7: stats() retorna métricas ===\n";
$st = $p2->stats();
check("stats: termos_no_state >= 1", isset($st['termos_no_state']) && $st['termos_no_state'] >= 1);
check("stats: snapshots_total >= 2", isset($st['snapshots_total']) && $st['snapshots_total'] >= 2);

// ─────────────────────────────────────────────
echo "\n=== TESTE 8: boostScoreDiscover ===\n";
check("rising → 12.0", PingoPreditor::boostScoreDiscover('rising', 10.0) === 12.0);
check("declining → 7.0", PingoPreditor::boostScoreDiscover('declining', 10.0) === 7.0);
check("stable → 10.0 (sem boost)", PingoPreditor::boostScoreDiscover('stable', 10.0) === 10.0);
check("new → 10.0 (sem boost)", PingoPreditor::boostScoreDiscover('new', 10.0) === 10.0);

// ─────────────────────────────────────────────
echo "\n=== TESTE 9: SpikeDetector wire (source check) ===\n";
$src = file_get_contents($rootDir . '/lib/SpikeDetector.php');
check("SpikeDetector require_once PingoPreditor",
    strpos($src, "require_once __DIR__ . '/PingoPreditor.php'") !== false);
check("SpikeDetector chama \$preditor->classificar",
    strpos($src, '$preditor->classificar') !== false);
check("SpikeDetector inclui predictor_label no registro",
    strpos($src, "'predictor_label'") !== false);
check("SpikeDetector usa boostScoreDiscover",
    strpos($src, 'boostScoreDiscover') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 10: GC remove termos antigos (>24h sem aparecer) ===\n";
$pGc = new PingoPreditor(sys_get_temp_dir() . '/preditor_gc_' . uniqid() . '.json');
$pGc->classificar([['termo' => 'antigo', 'traffic' => 1000, 'ts' => time() - 90000]]); // 25h atrás
$st1 = $pGc->stats();
$pGc->classificar([['termo' => 'novo', 'traffic' => 2000, 'ts' => time()]]);
$st2 = $pGc->stats();
check("GC remove termo >24h", $st2['termos_no_state'] === 1,
    'antes=' . ($st1['termos_no_state'] ?? '?') . ' depois=' . ($st2['termos_no_state'] ?? '?'));

// Cleanup
foreach (glob(sys_get_temp_dir() . '/preditor_*.json') as $f) @unlink($f);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[PREDITOR B4] OK\n" : "\n[PREDITOR B4] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
