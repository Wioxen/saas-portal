<?php
/**
 * tests/webstory.php
 *
 * Testes do DiscoverWebStory::deveGerar() — a única parte determinística.
 * O HTTP call real (gerar()) é testado manualmente em stage/prod porque
 * depende do plugin wp-web-stories-ai vivo no WP.
 *
 * Runner: /c/xampp/php/php.exe tests/webstory.php
 * Exit 0 = ok · 1 = falhas.
 */

require_once __DIR__ . '/../lib/DiscoverWebStory.php';

$casos = [
    // [desc, cfg, clusterKey, esperado]
    ['ROI 10 (finanças) com enabled=1 → gera',      ['webstory_enabled' => 1, 'webstory_roi_min' => 5.0], 'negocios_financas',      true],
    ['ROI 7.6 (saúde) com enabled=1 → gera',        ['webstory_enabled' => 1, 'webstory_roi_min' => 5.0], 'saude_bem_estar',        true],
    ['ROI 5.7 (notícia) com enabled=1 → gera',      ['webstory_enabled' => 1, 'webstory_roi_min' => 5.0], 'noticias_info_critica',  true],
    ['ROI 4.8 (viagem) com min=5.0 → NÃO gera',     ['webstory_enabled' => 1, 'webstory_roi_min' => 5.0], 'viagem_transporte',      false],
    ['ROI 4.8 (viagem) com min=4.0 → gera',         ['webstory_enabled' => 1, 'webstory_roi_min' => 4.0], 'viagem_transporte',      true],
    ['ROI 3.8 (tech) com min=5.0 → NÃO gera',       ['webstory_enabled' => 1, 'webstory_roi_min' => 5.0], 'tecnologia',             false],
    ['ROI 1.4 (esporte) com min=5.0 → NÃO gera',    ['webstory_enabled' => 1, 'webstory_roi_min' => 5.0], 'esportes',               false],
    ['ROI 10 (finanças) com enabled=0 → NÃO gera',  ['webstory_enabled' => 0, 'webstory_roi_min' => 5.0], 'negocios_financas',      false],
    ['enabled ausente (default 0) → NÃO gera',       [],                                                  'negocios_financas',      false],
    ['cluster fallback (curiosidades) ROI 1.2 → NÃO', ['webstory_enabled' => 1, 'webstory_roi_min' => 5.0], 'curiosidades_geral',    false],
];

$total = count($casos);
$pass = 0;
$falhas = [];

foreach ($casos as $c) {
    [$desc, $cfg, $ck, $esperado] = $c;
    $obtido = DiscoverWebStory::deveGerar($cfg, $ck);
    if ($obtido === $esperado) {
        $pass++;
    } else {
        $falhas[] = ['desc' => $desc, 'esperado' => $esperado, 'obtido' => $obtido];
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  tests/webstory.php — {$total} casos (deveGerar decision logic)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($falhas) {
    foreach ($falhas as $f) {
        echo sprintf("  FAIL %s\n    esperado: %s · obtido: %s\n",
            $f['desc'], var_export($f['esperado'], true), var_export($f['obtido'], true));
    }
    echo "\n";
}

echo sprintf("  Passaram: %d / %d (%.1f%%)\n", $pass, $total, ($pass / $total) * 100);

if ($pass === $total) {
    echo "  ✓ Todos os casos passaram.\n";
    exit(0);
}
echo "  ✗ " . count($falhas) . " casos falharam.\n";
exit(1);
