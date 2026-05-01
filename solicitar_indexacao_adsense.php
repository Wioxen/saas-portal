<?php
/**
 * solicitar_indexacao_adsense.php — pinga URLs no Google Indexing API + IndexNow
 * pra acelerar re-crawl após criar redirects 301.
 *
 * Lê as URLs source/destination do CSV de redirects gerado por mapear_html_legacy.php
 * e pinga TODAS via /wp-json/cc/v1/indexar (plugin cc-instant-indexing-api).
 *
 * Por que pingar as 3 ações:
 *   - URL antiga (com 301) → URL_UPDATED → Google re-crawla, vê 301, atualiza índice
 *   - URL destino (categoria) → URL_UPDATED → garante indexação da página viva
 *   - Sitemap → não suportado por Indexing API (sitemap é submetido via Search Console)
 *
 * Uso:
 *   php solicitar_indexacao_adsense.php cursosenac
 */

require_once __DIR__ . '/_site_helper.php';
require_once __DIR__ . '/lib/InstantIndexing.php';

$slug = $argv[1] ?? '';
if ($slug === '') { fwrite(STDERR, "Uso: php solicitar_indexacao_adsense.php <site-slug>\n"); exit(1); }

$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { fwrite(STDERR, "Site '{$slug}' não cadastrado\n"); exit(1); }

$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');

$csvFile = __DIR__ . "/data/redirects_{$slug}.csv";
if (!file_exists($csvFile)) { fwrite(STDERR, "CSV não encontrado: {$csvFile}\n"); exit(1); }

echo "\n=== Solicitar Indexação ({$slug}) ===\n";
echo "WP: {$wpUrl}\n";
echo "Plugin REST: {$wpUrl}/wp-json/cc/v1/indexar\n\n";

// Lê CSV
$linhas = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($linhas);
$urlsAntigas = [];
$urlsDestino = [];
foreach ($linhas as $l) {
    $cols = str_getcsv($l);
    if (count($cols) < 6) continue;
    $src = trim($cols[0]); // /old-path
    $dst = trim($cols[1]); // /new-path
    if ($src !== '') $urlsAntigas[] = $wpUrl . $src;
    if ($dst !== '' && !in_array($wpUrl . $dst, $urlsDestino, true)) $urlsDestino[] = $wpUrl . $dst;
}

$total = count($urlsAntigas) + count($urlsDestino);
echo "URLs a pingar: {$total} (" . count($urlsAntigas) . " antigas + " . count($urlsDestino) . " destino única)\n\n";

$idx = new InstantIndexing($wpUrl, $s['wp_user'], $s['wp_app_password']);
$ok = 0; $fail = 0;

echo "▶ URLs ANTIGAS (sinaliza Google pra re-crawlar e ver os 301):\n";
foreach ($urlsAntigas as $url) {
    $r = $idx->indexar($url, 'URL_UPDATED');
    $simbolo = $r['success'] ? '✓' : '✗';
    $extra = $r['success'] ? "via {$r['method']}" : ($r['error'] ?? 'erro');
    echo "  {$simbolo} {$url} — {$extra}\n";
    $r['success'] ? $ok++ : $fail++;
}

echo "\n▶ URL DESTINO (garante indexação da categoria):\n";
foreach ($urlsDestino as $url) {
    $r = $idx->indexar($url, 'URL_UPDATED');
    $simbolo = $r['success'] ? '✓' : '✗';
    $extra = $r['success'] ? "via {$r['method']}" : ($r['error'] ?? 'erro');
    echo "  {$simbolo} {$url} — {$extra}\n";
    $r['success'] ? $ok++ : $fail++;
}

echo "\n=== RESULTADO ===\n";
echo "✓ Sucesso: {$ok}\n";
echo "✗ Falha:   {$fail}\n";

if ($ok > 0) {
    echo "\n🎉 Indexação solicitada. Google geralmente re-crawla em algumas horas (até 24-48h).\n";
    echo "   Acompanhe em: https://search.google.com/search-console/inspect?resource_id=" . urlencode($wpUrl) . "\n";
}
