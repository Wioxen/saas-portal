<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'cursosenac');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$p = $wp->getPost(5594);
$h = $p['content']['raw'] ?? '';
$h = str_replace(
    ['Levantamento da equipe do Leão da Barra', 'redação do Leão da Barra', 'portal Leão da Barra', 'da equipe do Leão da Barra', 'Leão da Barra'],
    ['Levantamento da equipe do CursoSenac Gratuito', 'redação do CursoSenac Gratuito', 'portal CursoSenac Gratuito', 'da equipe do CursoSenac Gratuito', 'CursoSenac Gratuito'],
    $h
);
$wp->atualizarPost(5594, ['content' => $h]);
echo "#5594 marca corrigida\n";
echo "Restos 'Leão': " . substr_count($h, 'Leão') . "\n";
