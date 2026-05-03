<?php
declare(strict_types=1);
/**
 * Lista todas as categorias do site (paginado). Usa pra mapear categorias
 * existentes → internal_link_glossary do sites.php.
 *
 * Uso:
 *   php scripts/listar_categorias.php --site=cursosenac
 *   php scripts/listar_categorias.php --site=cursosenac --com-count  (mostra # posts por cat)
 */
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';

$opts = getopt('', ['site::', 'com-count']);
$siteSlug = (string)($opts['site'] ?? '');
$comCount = isset($opts['com-count']);
if ($siteSlug === '') { fwrite(STDERR, "uso: --site=SLUG [--com-count]\n"); exit(2); }

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$todasCats = [];
$page = 1;
do {
    $url = "{$base}/categories?per_page=100&page={$page}&_fields=id,name,slug,count,parent";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) break;
    $cats = json_decode((string)$resp, true) ?: [];
    if (empty($cats)) break;
    $todasCats = array_merge($todasCats, $cats);
    $page++;
    if ($page > 10) break;
} while (count($cats) === 100);

usort($todasCats, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

echo "═══ Categorias · {$siteSlug} (" . count($todasCats) . " total) ═══\n\n";
echo sprintf("%-5s %-40s %-30s %s\n", 'ID', 'NOME', 'SLUG', 'POSTS');
echo str_repeat('─', 90) . "\n";
foreach ($todasCats as $c) {
    $name = mb_strimwidth((string)($c['name'] ?? '?'), 0, 38);
    $slug = mb_strimwidth((string)($c['slug'] ?? '?'), 0, 28);
    $count = (int)($c['count'] ?? 0);
    if ($count === 0) continue;  // pula vazias
    echo sprintf("%-5d %-40s %-30s %d\n", (int)$c['id'], $name, $slug, $count);
}

echo "\n═══ Sugestão pra sites.php['{$siteSlug}']['internal_link_glossary'] ═══\n";
echo "'internal_link_glossary' => [\n";
foreach (array_slice($todasCats, 0, 20) as $c) {
    if (($c['count'] ?? 0) === 0) continue;
    $nome = (string)($c['name'] ?? '');
    $slug = (string)($c['slug'] ?? '');
    if ($nome === '' || $slug === '') continue;
    echo "    '{$nome}' => '/category/{$slug}/',\n";
}
echo "],\n";
