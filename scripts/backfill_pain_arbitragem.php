<?php
/**
 * scripts/backfill_pain_arbitragem.php
 *
 * Popula `pain`, `cluster_detect`, `arbitragem` nos trends antigos em data/discover_trends.json
 * que foram salvos ANTES dessa classificação existir.
 *
 * Idempotente: pula registros que já têm os 3 campos populados.
 * Dry-run por padrão. Passar `--apply` pra persistir.
 *
 * Uso:
 *   /c/xampp/php/php.exe scripts/backfill_pain_arbitragem.php          # dry-run
 *   /c/xampp/php/php.exe scripts/backfill_pain_arbitragem.php --apply  # grava
 *   /c/xampp/php/php.exe scripts/backfill_pain_arbitragem.php --force  # recalcula tudo (ignora idempotência)
 */

require_once __DIR__ . '/../lib/DiscoverDb.php';
require_once __DIR__ . '/../lib/DiscoverSinaisEditoriais.php';

$apply  = in_array('--apply', $argv, true);
$force  = in_array('--force', $argv, true);

$db = new DiscoverDb();
$records = $db->all();

$total    = count($records);
$puladosJaTem = 0;
$atualizados = 0;
$erros       = 0;
$exemplos    = [];

echo "=== Backfill pain/cluster_detect/arbitragem ===\n";
echo "Total de registros: $total\n";
echo "Modo: " . ($apply ? 'APPLY (vai gravar)' : 'DRY-RUN (só simula)') . ($force ? ' + FORCE (recalcula mesmo se já tem)' : '') . "\n\n";

foreach ($records as $rec) {
    try {
        $jaTem = isset($rec['pain'], $rec['cluster_detect'], $rec['arbitragem'])
              && is_array($rec['pain']) && is_array($rec['cluster_detect']) && is_array($rec['arbitragem']);

        if ($jaTem && !$force) {
            $puladosJaTem++;
            continue;
        }

        $sinais = DiscoverSinaisEditoriais::calcular($rec, (string)($rec['angulo'] ?? ''));

        if ($apply) {
            $db->updateStatus((int)$rec['id'], (string)($rec['status'] ?? 'novo'), [
                'pain'           => $sinais['pain'],
                'cluster_detect' => $sinais['cluster_detect'],
                'arbitragem'     => $sinais['arbitragem'],
            ]);
        }

        $atualizados++;
        if (count($exemplos) < 5) {
            $exemplos[] = sprintf(
                '  id=%d termo=%s → cluster=%s pain=%s arb=%s (R$%s/mil)',
                (int)$rec['id'],
                mb_substr((string)$rec['termo'], 0, 40, 'UTF-8'),
                $sinais['cluster_detect']['nome'] ?? '?',
                $sinais['pain']['dominante']     ?? '?',
                $sinais['arbitragem']['ranking'] ?? '?',
                $sinais['arbitragem']['rpm_ajustado'] ?? '?'
            );
        }
    } catch (Throwable $e) {
        $erros++;
        fwrite(STDERR, "ERRO id=" . ($rec['id'] ?? '?') . ": " . $e->getMessage() . "\n");
    }
}

echo "\n--- Resumo ---\n";
echo "Total:       $total\n";
echo "Já tinham:   $puladosJaTem\n";
echo "Atualizados: $atualizados " . ($apply ? '(gravados)' : '(só simulação)') . "\n";
echo "Erros:       $erros\n";

if ($exemplos) {
    echo "\nExemplos:\n" . implode("\n", $exemplos) . "\n";
}

if (!$apply && $atualizados > 0) {
    echo "\n>> Dry-run OK. Rode com --apply pra gravar.\n";
}
