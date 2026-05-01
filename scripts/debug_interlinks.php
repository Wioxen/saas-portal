<?php
/**
 * Debug: mostra por que DiscoverInternalLinks não está achando candidatos.
 * Itera termos do artigo, busca no WP, mostra o que retornou e o que foi filtrado.
 */
require __DIR__ . '/../lib/Wordpress.php';
require __DIR__ . '/../lib/DiscoverInternalLinks.php';
require __DIR__ . '/../lib/DiscoverClusterMatcher.php';

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();

$siteSlug = $argv[1] ?? '';
$postId   = (int)($argv[2] ?? 0);
if (!$siteSlug || !$postId) {
    echo "Uso: php scripts/debug_interlinks.php <site_slug> <post_id>\n";
    exit(1);
}

$site = $sites[$siteSlug];
$wp   = new Wordpress($site['wp_url'], $site['wp_user'], $site['wp_app_password']);
$p    = $wp->getPost($postId);
$raw  = $p['content']['raw'] ?? '';
$titulo = $p['title']['raw'] ?? '';

echo "Post: {$titulo}\n\n";

// Extrai termos
$cluster = DiscoverClusterMatcher::detectar(['termo' => $titulo]);
$termos = DiscoverInternalLinks::extrairTermos($raw, [
    'termo'       => $titulo,
    'cluster_key' => $cluster['key'] ?? null,
]);

echo "Cluster: {$cluster['nome']}\n";
echo "Termos candidatos a buscar: " . count($termos) . "\n";
echo str_repeat('─', 60) . "\n";

$jaLinkados = 0;
foreach (array_slice($termos, 0, 20) as $i => $termo) {
    echo "\n[#{$i}] TERMO: '{$termo}'\n";
    try {
        $candidatos = $wp->buscarRelacionados($termo, 3, $postId);
    } catch (Throwable $e) {
        echo "     ❌ erro WP: {$e->getMessage()}\n";
        continue;
    }
    if (empty($candidatos)) {
        echo "     · 0 candidatos retornados\n";
        continue;
    }
    foreach ($candidatos as $c) {
        $pid  = (int)($c['id'] ?? 0);
        $ttl  = (string)($c['title'] ?? '');
        echo "     · #{$pid}: {$ttl}\n";
    }
}
