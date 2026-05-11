<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

$verifica = [
    'comocomprar' => [3115, 3118],
    'cursosenac'  => [5590, 5594],
];

foreach ($verifica as $slug => $ids) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slug);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);
    echo "\n=== $slug ===\n";
    foreach ($ids as $pid) {
        try {
            $p = $wp->getPost($pid);
            $h = $p['content']['raw'] ?? '';
            $tit = $p['title']['rendered'] ?? '';
            $contLeao = substr_count($h, 'Leão da Barra') + substr_count($h, 'leão da barra');
            $contVit  = substr_count($h, 'Vitória');
            $contSenac = substr_count($h, 'Senac');
            $contComoComprar = substr_count($h, 'Como Comprar') + substr_count($h, 'como comprar');
            echo sprintf("  #%d %-30s Leão=%d Vitória=%d Senac=%d ComoComprar=%d\n",
                $pid, mb_substr($tit, 0, 28), $contLeao, $contVit, $contSenac, $contComoComprar);
            if ($contLeao > 0) {
                preg_match('/[^.]*Leão da Barra[^.]*\./', strip_tags($h), $m);
                echo "     ! Trecho: " . trim($m[0] ?? '?') . "\n";
            }
        } catch (Throwable $e) {
            echo "  #$pid err: " . $e->getMessage() . "\n";
        }
    }
}
