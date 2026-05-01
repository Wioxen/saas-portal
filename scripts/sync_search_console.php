<?php
/**
 * scripts/sync_search_console.php
 *
 * Sincroniza dados do Google Search Console pra todos os sites autorizados na SA.
 * Persiste em data/search_console_cache/{site_slug}.json.
 *
 * Coleta últimos 7 dias (ajustável via --dias=N) com 3 dimensões:
 *   - Top queries
 *   - Top páginas
 *   - Performance por dia
 *
 * Uso:
 *   php scripts/sync_search_console.php                  # 7 dias, todos sites
 *   php scripts/sync_search_console.php --dias=30        # 30 dias
 *   php scripts/sync_search_console.php --site=cursosenac # só um site (slug local)
 *   php scripts/sync_search_console.php --verbose
 *
 * Cron sugerido: 0 6 * * * /usr/bin/php scripts/sync_search_console.php >> data/gsc.log 2>&1
 */

$diasAtras = 7;
$siteFiltro = '';
$verbose = false;

foreach ($argv as $a) {
    if (preg_match('/^--dias=(\d+)$/', $a, $m)) $diasAtras = (int)$m[1];
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteFiltro = $m[1];
    if ($a === '--verbose') $verbose = true;
}

require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/DiscoverSearchConsole.php';

$cacheDir = __DIR__ . '/../data/search_console_cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

// FIX 9 — rotação: remove caches >30d para não crescer indefinidamente.
$cortoTtl = time() - (30 * 86400);
$rotacionados = 0;
foreach (glob($cacheDir . '/*.json') ?: [] as $f) {
    if (filemtime($f) < $cortoTtl) {
        @unlink($f);
        $rotacionados++;
    }
}
if ($rotacionados > 0) {
    echo "↺ Rotação: {$rotacionados} cache(s) >30d removidos.\n\n";
}

$sitesLocal = sitesDisponiveis();
$gsc = new DiscoverSearchConsole();

// Mapa wp_url → slug local
$urlParaSlug = [];
foreach ($sitesLocal as $slug => $info) {
    $url = rtrim($info['wp_url'] ?? '', '/');
    if ($url === '') continue;
    $urlParaSlug[$url] = $slug;
    $urlParaSlug[$url . '/'] = $slug;
    // Domain property: extrair host
    $host = parse_url($url, PHP_URL_HOST);
    if ($host) $urlParaSlug['sc-domain:' . $host] = $slug;
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Search Console Sync · período: últimos {$diasAtras} dias\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

try {
    $sitesGsc = $gsc->listarSites();
} catch (Throwable $e) {
    fwrite(STDERR, "✗ Falha listando sites: " . $e->getMessage() . "\n");
    exit(1);
}

$dataFim = date('Y-m-d', strtotime('-1 day'));
$dataIni = date('Y-m-d', strtotime("-{$diasAtras} days"));

$totalProcessados = 0;
$totalErros = 0;
$relSites = [];

foreach ($sitesGsc as $sg) {
    $siteUrl = (string)($sg['siteUrl'] ?? '');
    if ($siteUrl === '') continue;

    // Resolve slug local
    $slug = $urlParaSlug[$siteUrl] ?? '';
    if ($slug === '') {
        // Tenta match por host
        $host = parse_url($siteUrl, PHP_URL_HOST) ?? str_replace('sc-domain:', '', $siteUrl);
        foreach ($sitesLocal as $s => $info) {
            $h = parse_url($info['wp_url'] ?? '', PHP_URL_HOST);
            if ($h === $host) { $slug = $s; break; }
        }
    }

    if ($siteFiltro !== '' && $slug !== $siteFiltro) continue;

    $label = $slug !== '' ? "[{$slug}] " : '[?] ';
    echo "── {$label}{$siteUrl} ────────────────────────────────────────────\n";

    $dadosSite = [
        'site_url'    => $siteUrl,
        'site_slug'   => $slug,
        'permission'  => $sg['permissionLevel'] ?? '',
        'periodo'     => ['inicio' => $dataIni, 'fim' => $dataFim, 'dias' => $diasAtras],
        'sincronizado_em' => date('c'),
        'queries'     => null,
        'paginas'     => null,
        'por_dia'     => null,
        'discover'    => null,
    ];

    // 1. Top queries (web)
    try {
        $r = $gsc->consultarPerformance($siteUrl, $dataIni, $dataFim, [
            'dimensoes' => ['query'],
            'limite'    => 200,
            'tipo'      => 'web',
        ]);
        $dadosSite['queries'] = $r;
        echo sprintf("  Queries:  %d (total: %d clicks · %d impr · CTR %.2f%%)\n",
            count($r['rows']),
            $r['totals']['clicks'],
            $r['totals']['impressions'],
            $r['totals']['ctr'] * 100);
    } catch (Throwable $e) {
        echo "  Queries:  ✗ " . $e->getMessage() . "\n";
        $totalErros++;
    }

    // 2. Top páginas
    try {
        $r = $gsc->consultarPerformance($siteUrl, $dataIni, $dataFim, [
            'dimensoes' => ['page'],
            'limite'    => 100,
            'tipo'      => 'web',
        ]);
        $dadosSite['paginas'] = $r;
        echo sprintf("  Páginas:  %d (top page tem %d cliques)\n",
            count($r['rows']),
            $r['rows'][0]['clicks'] ?? 0);
    } catch (Throwable $e) {
        echo "  Páginas:  ✗ " . $e->getMessage() . "\n";
        $totalErros++;
    }

    // 3. Performance por dia
    try {
        $r = $gsc->consultarPerformance($siteUrl, $dataIni, $dataFim, [
            'dimensoes' => ['date'],
            'limite'    => $diasAtras + 5,
            'tipo'      => 'web',
        ]);
        $dadosSite['por_dia'] = $r;
        echo sprintf("  Por dia:  %d dias com dado\n", count($r['rows']));
    } catch (Throwable $e) {
        echo "  Por dia:  ✗ " . $e->getMessage() . "\n";
    }

    // 4. Discover (pode não ter dados — tratamos vazio gracefully)
    try {
        $r = $gsc->consultarPerformance($siteUrl, $dataIni, $dataFim, [
            'dimensoes' => ['query'],
            'limite'    => 100,
            'tipo'      => 'discover',
        ]);
        $dadosSite['discover'] = $r;
        if (!empty($r['rows'])) {
            echo sprintf("  Discover: %d queries · %d cliques\n",
                count($r['rows']), $r['totals']['clicks']);
        } else {
            echo "  Discover: sem dados (site novo ou sem volume Discover)\n";
        }
    } catch (Throwable $e) {
        echo "  Discover: ✗ " . $e->getMessage() . "\n";
    }

    // Persiste
    $arq = $cacheDir . '/' . ($slug !== '' ? $slug : md5($siteUrl)) . '.json';
    $tmp = $arq . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($dadosSite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    @rename($tmp, $arq);

    $relSites[$slug ?: $siteUrl] = $dadosSite;
    $totalProcessados++;
    echo "  ✓ persistido em " . basename($arq) . "\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Sincronizados: {$totalProcessados} sites · Erros: {$totalErros}\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
exit($totalErros > 0 ? 1 : 0);
