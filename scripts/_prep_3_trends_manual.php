<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/DbConnection.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/Serper.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'leaodabarra'); // basta uma config

$pdo = DbConnection::pdo();
$scraper = new Scraper($cfg['user_agent'] ?? 'Mozilla/5.0', 15);
$serper = new Serper($cfg['serper_api_key']);

$trends = [
    15185 => 'CNE aprova regulamentação IA escolas universidades MEC Brasil',
    16446 => 'Smart TV TCL QLED 40 Britânia Roku 43 Amazon oferta',
    14823 => 'Vitória derrota Justiça Lucas Braga indenização Bahia',
];

$out = [];
foreach ($trends as $tid => $queryExtra) {
    $st = $pdo->prepare('SELECT id, titulo, pingo_link FROM trends WHERE id=?');
    $st->execute([$tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) continue;

    $urls = [$t['pingo_link']];
    try {
        $r = $serper->search($queryExtra, 5);
        foreach (($r['organic'] ?? []) as $o) $urls[] = $o['link'] ?? '';
    } catch (Throwable $e) {}
    $urls = array_unique(array_filter($urls));

    $fontes = [];
    foreach (array_slice($urls, 0, 5) as $u) {
        try {
            $sc = $scraper->fetch($u);
            $paras = $sc['content']['paragraphs'] ?? [];
            $txt = trim(implode("\n", array_slice($paras, 0, 12)));
            if (mb_strlen($txt) < 250) continue;
            $fontes[] = [
                'url' => $u,
                'titulo' => $sc['meta']['title'] ?? '',
                'pub' => $sc['meta']['published'] ?? '?',
                'og' => $sc['meta']['og_image'] ?? '',
                'texto' => mb_substr($txt, 0, 3000),
            ];
            if (count($fontes) >= 3) break;
        } catch (Throwable $e) {}
    }

    $out[$tid] = ['trend' => $t, 'fontes' => $fontes];
    echo "Trend #{$tid}: {$t['titulo']}\n";
    echo "  Fontes scraped: " . count($fontes) . "\n";
    foreach ($fontes as $f) echo "    · " . substr($f['url'], 0, 90) . " (" . mb_strlen($f['texto']) . " chars)\n";
    echo "\n";
}

file_put_contents('/tmp/3_trends_data.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Salvo /tmp/3_trends_data.json\n";
