<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'leaodabarra');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// Categoria: só Copa do Brasil (competição)
$cm = new CategoryMatcher($wp, 70.0);
$cats = $cm->resolverComMatch(['Copa do Brasil']);
$catIds = array_values(array_filter(array_map('intval', $cats)));

// Tags: entidades específicas
$tagsIds = $wp->resolverTags(['Flamengo', 'Raphael Claus', 'Arbitragem', 'Esporte Clube Vitória', 'Anderson Daronco', 'VAR']);

$wp->atualizarPost(1297, ['categories' => $catIds, 'tags' => $tagsIds]);
echo "#1297 atualizado:\n";
echo "  cats: " . implode(',', $catIds) . " (Copa do Brasil)\n";
echo "  tags: " . implode(',', $tagsIds) . " (Flamengo, Raphael Claus, Arbitragem, ECV, Anderson Daronco, VAR)\n";
