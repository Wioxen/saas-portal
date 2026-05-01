<?php
/**
 * ajustar_threshold_fontes — calibra auto_aprovar_score_min por site.
 *
 * Resolve gap descoberto em prod (2026-05-01): RSS items raramente passam
 * o threshold default 7.0 porque scoreTrend (30% peso) precisa de volume_num
 * que RSS não fornece. Score máximo prático com vol=0 é ~6.5-7.0.
 *
 * Faz 2 ações idempotentes:
 *   1. FONTES (futuros): atualiza auto_aprovar_score_min em todas fontes
 *      com site_target=<slug> no data/fontes_pingo.json
 *   2. TRENDS (existentes): re-classifica trends do site:
 *      - status='novo' AND score >= novo_threshold → 'aprovado'
 *      - status='aprovado' AND score < novo_threshold → mantém (não derruba post potencial)
 *
 * Uso:
 *   php scripts/ajustar_threshold_fontes.php --site=leaodabarra --threshold=5.5
 *   php scripts/ajustar_threshold_fontes.php --site=leaodabarra --threshold=5.5 --dry-run
 *
 * Idempotente. Pode rodar múltiplas vezes.
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

$site = null;
$threshold = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site='))      $site = substr($arg, 7);
    elseif (str_starts_with($arg, '--threshold=')) $threshold = (float)substr($arg, 12);
    elseif ($arg === '--dry-run')               $dryRun = true;
}
if (!$site || $threshold === null) {
    fwrite(STDERR, "Uso: php scripts/ajustar_threshold_fontes.php --site=<slug> --threshold=<float> [--dry-run]\n");
    exit(1);
}
if ($threshold < 3.0 || $threshold > 9.0) {
    fwrite(STDERR, "Threshold {$threshold} fora de faixa sensata (3.0 - 9.0)\n");
    exit(2);
}

require_once $ROOT . '/lib/DiscoverDb.php';
require $ROOT . '/config.php';

echo "═══ Ajustar threshold de aprovação · {$site} → {$threshold} ═══\n\n";

// ─── 1) FONTES (futuros) ───────────────────────────────────────────────────
$fontesPath = $ROOT . '/data/fontes_pingo.json';
if (!is_file($fontesPath)) {
    echo "  ⚠ {$fontesPath} não existe — pulando ajuste de fontes\n";
} else {
    $j = json_decode(file_get_contents($fontesPath), true);
    if (!is_array($j) || !isset($j['fontes'])) {
        echo "  ⚠ JSON inválido — pulando fontes\n";
    } else {
        $alteradas = 0;
        $jaIguais = 0;
        foreach ($j['fontes'] as &$f) {
            if (($f['site_target'] ?? '') !== $site) continue;
            $atual = (float)($f['auto_aprovar_score_min'] ?? 7.0);
            if (abs($atual - $threshold) < 0.01) {
                $jaIguais++;
                continue;
            }
            $alteradas++;
            if (!$dryRun) {
                $f['auto_aprovar_score_min'] = $threshold;
            }
            echo sprintf("  %s [%d] %-30s  %.2f → %.2f\n",
                $dryRun ? '[dry]' : 'ALT  ',
                (int)$f['id'],
                mb_substr($f['nome'], 0, 30),
                $atual,
                $threshold
            );
        }
        unset($f);

        if (!$dryRun && $alteradas > 0) {
            @copy($fontesPath, $fontesPath . '.bak');
            $tmp = $fontesPath . '.tmp.' . bin2hex(random_bytes(4));
            $json = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($tmp, $json, LOCK_EX) === false) {
                fwrite(STDERR, "  ✗ falha gravando {$tmp}\n");
                exit(3);
            }
            if (!@rename($tmp, $fontesPath)) {
                @unlink($tmp);
                fwrite(STDERR, "  ✗ falha movendo {$tmp} → {$fontesPath}\n");
                exit(4);
            }
        }
        echo sprintf("  Fontes alteradas: %d · já estavam corretas: %d\n", $alteradas, $jaIguais);
    }
}

// ─── 2) TRENDS EXISTENTES (re-classificação) ───────────────────────────────
echo "\n═══ Re-classificando trends existentes ═══\n\n";

$db = new DiscoverDb();
$todos = $db->all(['site' => $site]);

$promovidos = 0;
$paramantos = 0;
$jaAprovados = 0;
$ignorados = 0;
$amostraPromovidos = [];

foreach ($todos as $t) {
    $score = (float)($t['score_discover'] ?? 0);
    $status = (string)($t['status'] ?? '');

    if ($status === 'novo' && $score >= $threshold) {
        if (count($amostraPromovidos) < 5) {
            $amostraPromovidos[] = sprintf('%5.2f · %s', $score, mb_substr($t['termo'], 0, 60));
        }
        if (!$dryRun) {
            $db->updateStatus((int)$t['id'], 'aprovado');
        }
        $promovidos++;
    } elseif ($status === 'novo') {
        $ignorados++;
    } elseif ($status === 'aprovado') {
        $jaAprovados++;
    } else {
        $paramantos++;
    }
}

echo "  Promovidos novo→aprovado: {$promovidos}\n";
foreach ($amostraPromovidos as $a) echo "      {$a}\n";
echo "  Ainda 'novo' (score < {$threshold}): {$ignorados}\n";
echo "  Já estavam aprovados: {$jaAprovados}\n";
if ($paramantos > 0) echo "  Outros status (publicado, descartado, etc): {$paramantos}\n";

if ($dryRun) {
    echo "\n[DRY-RUN] Nada foi alterado. Re-rode sem --dry-run pra aplicar.\n";
}
echo "\n";
