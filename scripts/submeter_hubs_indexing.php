<?php
/**
 * submeter_hubs_indexing.php — submete URLs dos hubs entity/concept + KG ao Google
 * Indexing API (via Rank Math Instant Indexing) + IndexNow (Bing/Yandex).
 *
 * Lê aliases.json de cada site (hubs publicados) + a URL canônica de /knowledge-graph/.
 * Pra cada URL, chama InstantIndexing::indexar().
 *
 * Uso:
 *   php scripts/submeter_hubs_indexing.php                       # todos os 6 sites
 *   php scripts/submeter_hubs_indexing.php --site=cursosenac     # só um site
 *   php scripts/submeter_hubs_indexing.php --dry-run             # lista URLs sem submeter
 *
 * Espaça 1s entre chamadas pra não estourar rate limit.
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteFiltro = (string)($args['site'] ?? '');
$dryRun = !empty($args['dry-run']);

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/InstantIndexing.php';

$sitesGlobais = sitesDisponiveis();

// Sites elegíveis: têm entity_pages_enabled
$sitesAlvo = [];
foreach ($sitesGlobais as $slug => $cfgSite) {
    if (empty($cfgSite['entity_pages_enabled'])) continue;
    if ($siteFiltro !== '' && $siteFiltro !== $slug) continue;
    $sitesAlvo[$slug] = $cfgSite;
}

if (empty($sitesAlvo)) {
    fwrite(STDERR, "✗ Nenhum site elegível encontrado\n");
    exit(1);
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Submeter URLs ao Google Indexing API + IndexNow\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

$resultadoGeral = [
    'inicio' => date('c'),
    'sites' => [],
    'total_urls' => 0,
    'total_ok' => 0,
    'total_fail' => 0,
];

foreach ($sitesAlvo as $slug => $cfgSite) {
    echo "═══ {$slug} ({$cfgSite['name']}) ═══\n";

    $aplicado = $cfg;
    aplicarSite($aplicado, $sitesGlobais, $slug);

    $wp = new Wordpress($aplicado['wp_url'], $aplicado['wp_user'], $aplicado['wp_app_password']);
    $idx = new InstantIndexing($aplicado['wp_url'], $aplicado['wp_user'], $aplicado['wp_app_password']);

    $urls = [];

    // 1. Hubs do aliases.json
    $aliasesPath = __DIR__ . "/../data/entity_pages_cache/{$slug}_aliases.json";
    if (file_exists($aliasesPath)) {
        $aliases = json_decode((string)file_get_contents($aliasesPath), true);
        if (is_array($aliases)) {
            foreach ($aliases as $pageId => $info) {
                $tipo = (string)($info['tipo'] ?? 'entity');
                $parent = $tipo === 'concept' ? 'conceito' : 'entidade';
                $entitySlug = (string)($info['slug'] ?? '');
                if ($entitySlug === '') continue;
                $urls[] = rtrim($cfgSite['wp_url'], '/') . "/{$parent}/{$entitySlug}/";
            }
        }
    }

    // 2. KG page
    $urls[] = rtrim($cfgSite['wp_url'], '/') . "/knowledge-graph/";

    // 3. Parents (entidade, conceito)
    $urls[] = rtrim($cfgSite['wp_url'], '/') . "/entidade/";
    $urls[] = rtrim($cfgSite['wp_url'], '/') . "/conceito/";

    $urls = array_values(array_unique($urls));
    echo "URLs alvo: " . count($urls) . "\n";

    $siteRes = ['urls' => count($urls), 'ok' => 0, 'fail' => 0, 'detalhes' => []];

    if ($dryRun) {
        foreach ($urls as $u) echo "  · {$u}\n";
        echo "\n";
        $resultadoGeral['sites'][$slug] = $siteRes;
        continue;
    }

    foreach ($urls as $i => $url) {
        echo sprintf("  [%2d/%d] %s ... ", $i + 1, count($urls), $url);
        try {
            $r = $idx->indexar($url, 'URL_UPDATED');
            if (!empty($r['success'])) {
                echo "✓ ok ({$r['method']})\n";
                $siteRes['ok']++;
            } else {
                echo "⚠ {$r['error']}\n";
                $siteRes['fail']++;
            }
            $siteRes['detalhes'][] = ['url' => $url, 'success' => $r['success'], 'method' => $r['method'], 'error' => $r['error']];
        } catch (Throwable $e) {
            echo "✗ EXCEÇÃO: " . $e->getMessage() . "\n";
            $siteRes['fail']++;
            $siteRes['detalhes'][] = ['url' => $url, 'success' => false, 'error' => $e->getMessage()];
        }
        // Pausa 1s entre chamadas pra não estourar rate limit
        if ($i < count($urls) - 1) usleep(1_000_000);
    }

    $resultadoGeral['sites'][$slug] = $siteRes;
    $resultadoGeral['total_urls'] += $siteRes['urls'];
    $resultadoGeral['total_ok'] += $siteRes['ok'];
    $resultadoGeral['total_fail'] += $siteRes['fail'];

    echo "\n  resumo {$slug}: ok={$siteRes['ok']} | fail={$siteRes['fail']} | urls={$siteRes['urls']}\n\n";
}

$resultadoGeral['fim'] = date('c');

if (!$dryRun) {
    $logDir = __DIR__ . '/../data/indexing_runs';
    @mkdir($logDir, 0775, true);
    $logPath = "{$logDir}/" . date('Y-m-d_His') . "_submeter_hubs.json";
    file_put_contents($logPath, json_encode($resultadoGeral, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "═══ RESUMO GERAL ═══\n";
    echo "  total URLs: " . $resultadoGeral['total_urls'] . "\n";
    echo "  ok:         " . $resultadoGeral['total_ok'] . "\n";
    echo "  fail:       " . $resultadoGeral['total_fail'] . "\n";
    echo "  log:        {$logPath}\n";
}
