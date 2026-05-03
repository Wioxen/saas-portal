<?php
declare(strict_types=1);
/**
 * Submete URLs específicas no Google Indexing API via plugin cc-instant-indexing.
 * Pra capturar janela quente pós-publicação (ex: pós-jogo).
 *
 * Uso:
 *   php scripts/indexar_urls_adhoc.php --site=leaodabarra \
 *     --urls="https://leaodabarra.com.br/post1/,https://leaodabarra.com.br/post2/"
 */
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/InstantIndexing.php';

$opts = getopt('', ['site::', 'urls::']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$urlsRaw  = (string)($opts['urls'] ?? '');
if ($urlsRaw === '') { fwrite(STDERR, "uso: --urls=u1,u2\n"); exit(2); }

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

$urls = array_filter(array_map('trim', explode(',', $urlsRaw)));
$ix = new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

foreach ($urls as $url) {
    echo "→ {$url}\n";
    try {
        $r = $ix->indexar($url, 'URL_UPDATED');
        $ok = !empty($r['success']) || !empty($r['ok']);
        echo "  " . ($ok ? "✓" : "✗") . " " . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $e) {
        echo "  ✗ ERRO: {$e->getMessage()}\n";
    }
}
