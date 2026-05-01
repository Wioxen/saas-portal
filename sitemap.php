<?php
/**
 * Sitemap XML dinâmico — varre /pages e lista tudo.
 * Aponte o Search Console e o Google News pra esta URL.
 */
$cfg = require __DIR__ . '/config.php';
header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

$arquivos = glob($cfg['pages_dir'] . '/*.html') ?: [];
foreach ($arquivos as $arq) {
    $nome = basename($arq);
    $url  = htmlspecialchars($cfg['pages_url'] . '/' . $nome);
    $mod  = date('c', filemtime($arq));
    echo "  <url>\n";
    echo "    <loc>{$url}</loc>\n";
    echo "    <lastmod>{$mod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
