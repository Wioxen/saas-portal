<?php
/**
 * [OPS] Submete sitemap dos 6 sites no Google Search Console via API.
 * Idempotente — re-submissão é aceita pelo Google sem erro.
 *
 * Pré-requisitos:
 *   - data/google_credentials.json (Service Account)
 *   - Service Account adicionada como "Restricted user" em cada GSC property
 *   - Cada site WP tem sitemap em /wp-sitemap.xml (Rank Math) ou /sitemap_index.xml
 *
 * Uso:
 *   php scripts/_submeter_sitemaps.php                     # todos os 6 sites
 *   php scripts/_submeter_sitemaps.php --site=cursosenac   # só 1 site
 *   php scripts/_submeter_sitemaps.php --listar            # mostra sitemaps já submetidos por site
 *
 * Pode ser rodado uma única vez (manual) OU agendado mensalmente como sanity check.
 */

require_once __DIR__ . '/../lib/DiscoverSearchConsole.php';
require_once __DIR__ . '/../_site_helper.php';

$cfg = require __DIR__ . '/../config.php';

$soSite = null;
$listar = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--site=')) $soSite = substr($a, 7);
    elseif ($a === '--listar') $listar = true;
}

$gsc = new DiscoverSearchConsole();
$sites = sitesDisponiveis();
if ($soSite !== null) {
    if (!isset($sites[$soSite])) { fwrite(STDERR, "Site '{$soSite}' não existe\n"); exit(1); }
    $sites = [$soSite => $sites[$soSite]];
}

// Caminhos comuns de sitemap (testa em ordem)
$candidatosSitemap = [
    '/wp-sitemap.xml',           // WP nativo (6.0+)
    '/sitemap_index.xml',        // Rank Math, Yoast
    '/sitemap.xml',              // genérico
];

foreach ($sites as $slug => $siteCfg) {
    $wpUrl = rtrim((string)($siteCfg['wp_url'] ?? ''), '/');
    if ($wpUrl === '') { echo "[{$slug}] sem wp_url — pulando\n"; continue; }
    $gscUrl = (string)($siteCfg['gsc_site_url'] ?? ($wpUrl . '/'));

    if ($listar) {
        echo "═══ {$slug} ({$gscUrl}) ═══\n";
        try {
            $maps = $gsc->listarSitemaps($gscUrl);
            if (empty($maps)) {
                echo "  (nenhum sitemap submetido)\n";
            } else {
                foreach ($maps as $m) {
                    printf("  %-60s · last=%s · errors=%d · warnings=%d\n",
                        substr($m['path'] ?? '', 0, 60),
                        $m['lastSubmitted'] ?? '?',
                        (int)($m['errors'] ?? 0),
                        (int)($m['warnings'] ?? 0)
                    );
                }
            }
        } catch (Throwable $e) {
            echo "  ERRO: {$e->getMessage()}\n";
        }
        continue;
    }

    // Descobre sitemap real testando candidatos via HEAD
    $sitemapUrl = '';
    foreach ($candidatosSitemap as $path) {
        $url = $wpUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) { $sitemapUrl = $url; break; }
    }
    if ($sitemapUrl === '') {
        echo "[{$slug}] ✗ nenhum sitemap encontrado em " . implode(',', $candidatosSitemap) . "\n";
        continue;
    }

    try {
        $ok = $gsc->submeterSitemap($gscUrl, $sitemapUrl);
        echo "[{$slug}] " . ($ok ? '✓' : '✗') . " {$sitemapUrl}\n";
    } catch (Throwable $e) {
        echo "[{$slug}] ✗ {$e->getMessage()}\n";
    }
}
