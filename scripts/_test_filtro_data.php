<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/Serper.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'leaodabarra');

$serper = new Serper($cfg['serper_api_key']);
$queries = [
    "Vitória Flamengo 14 de maio Barradão Copa do Brasil volta",
    "Vitória x Flamengo escalação 14 maio jogo de volta",
    "Vitória Flamengo Copa do Brasil oitavas volta Barradão",
];

$urls = [];
foreach ($queries as $q) {
    try {
        $r = $serper->search($q, 6);
        foreach (($r['organic'] ?? []) as $o) {
            if (!empty($o['link'])) $urls[$o['link']] = ($o['title'] ?? '');
        }
    } catch (Throwable $e) {}
}
echo "URLs candidatas: " . count($urls) . "\n\n";

$scraper = new Scraper($cfg['user_agent'], 15);
$cutoff = time() - (7 * 86400);
$aprovadas = 0; $rejeitadas = 0; $semData = 0;
foreach (array_slice(array_keys($urls), 0, 12) as $url) {
    try {
        $sc = $scraper->fetch($url);
        $publishedRaw = (string)($sc['meta']['published'] ?? '');
        $publishedTs = $publishedRaw ? strtotime($publishedRaw) : 0;
        $src = 'meta';
        if ($publishedTs === 0 && preg_match('#/(20\d{2})/(\d{2})/(\d{2})/#', $url, $um)) {
            $publishedTs = strtotime("{$um[1]}-{$um[2]}-{$um[3]}");
            $src = 'url';
        }
        $human = $publishedTs ? date('Y-m-d', $publishedTs) : '?';
        if ($publishedTs > 0 && $publishedTs < $cutoff) {
            $dias = round((time() - $publishedTs) / 86400);
            echo "  [SKIP {$dias}d via {$src}] {$human} {$url}\n";
            $rejeitadas++;
        } elseif ($publishedTs > 0) {
            echo "  [OK pub {$human} via {$src}] {$url}\n";
            $aprovadas++;
        } else {
            echo "  [WARN sem data] {$url}\n";
            $semData++;
        }
    } catch (Throwable $e) {
        echo "  [erro] {$url} {$e->getMessage()}\n";
    }
}
echo "\nResumo: aprovadas={$aprovadas} rejeitadas={$rejeitadas} sem-data={$semData}\n";
