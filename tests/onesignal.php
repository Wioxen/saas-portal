<?php
/**
 * tests/onesignal.php
 *
 * Testes da lógica deveEnviar() do DiscoverOneSignal — determinística.
 * O enviar() real depende de rede + subscribers reais, então é testado manualmente.
 *
 * Runner: /c/xampp/php/php.exe tests/onesignal.php
 */

require_once __DIR__ . '/../lib/DiscoverOneSignal.php';

$cfgBase = [
    'onesignal_app_id'       => 'abcd1234-5678-90ef-abcd-ef1234567890',
    'onesignal_rest_api_key' => 'os_v2_app_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'onesignal_enabled'      => 1,
    'onesignal_roi_min'      => 5.0,
    'onesignal_site_target'  => 'cursosenac',
];

$casos = [
    // [desc, override_cfg, clusterKey, siteAtual, esperado]
    ['ROI 10 (finanças) + site=cursosenac → envia',
        [], 'negocios_financas', 'cursosenac', true],
    ['ROI 7.6 (saúde) + site=cursosenac → envia',
        [], 'saude_bem_estar', 'cursosenac', true],
    ['ROI 5.7 (notícia) + site=cursosenac → envia',
        [], 'noticias_info_critica', 'cursosenac', true],
    ['ROI 4.8 (viagem) com min=5.0 → NÃO envia',
        [], 'viagem_transporte', 'cursosenac', false],
    ['ROI 4.8 (viagem) com min=4.0 → envia',
        ['onesignal_roi_min' => 4.0], 'viagem_transporte', 'cursosenac', true],
    ['ROI 10 (finanças) no site errado → NÃO envia',
        [], 'negocios_financas', 'comocomprar', false],
    ['ROI 1.4 (esporte) no site certo → NÃO envia',
        [], 'esportes', 'cursosenac', false],
    ['enabled=0 → NÃO envia nunca',
        ['onesignal_enabled' => 0], 'negocios_financas', 'cursosenac', false],
    ['app_id vazio → NÃO envia',
        ['onesignal_app_id' => ''], 'negocios_financas', 'cursosenac', false],
    ['api_key vazio → NÃO envia',
        ['onesignal_rest_api_key' => ''], 'negocios_financas', 'cursosenac', false],
    ['site_target vazio → envia em qualquer site',
        ['onesignal_site_target' => ''], 'negocios_financas', 'qualquersite', true],
    ['site_target vazio + site vazio → envia',
        ['onesignal_site_target' => ''], 'negocios_financas', '', true],
];

$total = count($casos);
$pass = 0;
$falhas = [];

foreach ($casos as $c) {
    [$desc, $ovr, $ck, $siteAtual, $esperado] = $c;
    $cfgTeste = array_merge($cfgBase, $ovr);
    $obtido = DiscoverOneSignal::deveEnviar($cfgTeste, $ck, $siteAtual);
    if ($obtido === $esperado) {
        $pass++;
    } else {
        $falhas[] = ['desc' => $desc, 'esperado' => $esperado, 'obtido' => $obtido];
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  tests/onesignal.php — {$total} casos (deveEnviar decision logic)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if (!empty($falhas)) {
    foreach ($falhas as $f) {
        echo sprintf("  FAIL %s\n    esperado: %s · obtido: %s\n",
            $f['desc'], var_export($f['esperado'], true), var_export($f['obtido'], true));
    }
    echo "\n";
}

printf("  Passaram: %d / %d (%.1f%%)\n", $pass, $total, ($pass / $total) * 100);

if ($pass === $total) {
    echo "  ✓ Todos os casos passaram.\n";
    exit(0);
}
echo "  ✗ " . count($falhas) . " casos falharam.\n";
exit(1);
