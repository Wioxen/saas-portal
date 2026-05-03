<?php
declare(strict_types=1);
/**
 * Checa status de indexação de URLs específicas via GSC urlInspection.
 *
 * Uso:
 *   php scripts/check_indexacao_urls.php --site=leaodabarra --urls="url1,url2"
 *   php scripts/check_indexacao_urls.php --site=leaodabarra --urls="url1,url2" --resubmit
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/DiscoverSearchConsole.php';
require_once __DIR__ . '/../lib/InstantIndexing.php';

$opts = getopt('', ['site::', 'urls::', 'resubmit']);
$siteSlug = (string)($opts['site'] ?? '');
$urlsRaw  = (string)($opts['urls'] ?? '');
$resubmit = isset($opts['resubmit']);

if ($siteSlug === '' || $urlsRaw === '') {
    fwrite(STDERR, "uso: --site=SLUG --urls=u1,u2 [--resubmit]\n"); exit(2);
}

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

// Resolve siteUrl GSC (gsc_site_url custom ou auto-detect)
$gsc = new DiscoverSearchConsole();
$gscSiteUrl = (string)($cfg['gsc_site_url'] ?? '');
if ($gscSiteUrl === '') {
    $dominio = preg_replace('#^https?://#', '', (string)$cfg['wp_url']);
    $gscSiteUrl = $gsc->resolverSiteUrl($dominio);
}
if (!$gscSiteUrl) {
    fwrite(STDERR, "[erro] não conseguiu resolver siteUrl GSC pra '{$siteSlug}'\n"); exit(3);
}
echo "[gsc] siteUrl={$gscSiteUrl}\n\n";

$urls = array_filter(array_map('trim', explode(',', $urlsRaw)));
$ix = $resubmit ? new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']) : null;

$resultados = [];
foreach ($urls as $url) {
    echo "→ {$url}\n";
    $r = $gsc->inspecionarUrl($gscSiteUrl, $url);
    if (!($r['ok'] ?? true) || !empty($r['error'])) {
        echo "  ✗ erro: " . ($r['error'] ?? 'unknown') . "\n\n";
        $resultados[$url] = $r;
        continue;
    }
    echo "  · coverageState : " . ($r['coverageState']  ?? 'null') . "\n";
    echo "  · verdict       : " . ($r['verdict']        ?? 'null') . "\n";
    echo "  · lastCrawlTime : " . ($r['lastCrawlTime']  ?? 'null') . "\n";
    echo "  · indexed       : " . (isset($r['indexed']) ? ($r['indexed'] ? 'true' : 'false') : 'null') . "\n";
    if (!empty($r['raw']['indexStatusResult'])) {
        $isr = $r['raw']['indexStatusResult'];
        if (!empty($isr['robotsTxtState'])) echo "  · robotsTxt     : {$isr['robotsTxtState']}\n";
        if (!empty($isr['indexingState'])) echo "  · indexingState : {$isr['indexingState']}\n";
        if (!empty($isr['pageFetchState'])) echo "  · pageFetchState: {$isr['pageFetchState']}\n";
        if (!empty($isr['googleCanonical'])) echo "  · canonical     : {$isr['googleCanonical']}\n";
    }
    $resultados[$url] = $r;

    // Resubmit se ainda não foi crawleado
    if ($resubmit && empty($r['lastCrawlTime']) && $ix) {
        echo "  ⚡ resubmetendo via InstantIndexing...\n";
        $sr = $ix->indexar($url, 'URL_UPDATED');
        echo "  ⚡ " . json_encode($sr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo "\n";
}

// Tabela final
echo str_repeat('─', 80) . "\n";
echo sprintf("%-50s %-25s %-15s\n", 'URL (truncada)', 'coverageState', 'lastCrawl');
echo str_repeat('─', 80) . "\n";
foreach ($resultados as $url => $r) {
    $short = strlen($url) > 48 ? '…' . substr($url, -47) : $url;
    $cov = (string)($r['coverageState'] ?? '?');
    $last = (string)($r['lastCrawlTime'] ?? 'never');
    if ($last !== 'never') $last = substr($last, 0, 10);
    echo sprintf("%-50s %-25s %-15s\n", $short, $cov, $last);
}
