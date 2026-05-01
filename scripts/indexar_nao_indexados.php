<?php
/**
 * Indexa páginas NÃO-indexadas de um site (limite 200/dia — quota Indexing API).
 *
 * Pipeline:
 *   1. Lista todos os posts publicados via WP REST (paginado)
 *   2. Pra cada URL, consulta urlInspection no Search Console
 *   3. Filtra os NÃO-indexados (coverageState ≠ "Submitted and indexed")
 *   4. Indexa até MAX/dia via InstantIndexing (cc-instant-indexing-api plugin no WP)
 *   5. Restante salva em data/fila/indexar_pendente_{site}.json pra rodar amanhã
 *
 * Uso:
 *   php scripts/indexar_nao_indexados.php --site=cursosenac
 *   php scripts/indexar_nao_indexados.php --site=cursosenac --max=150
 *   php scripts/indexar_nao_indexados.php --site=cursosenac --dry-run
 *   php scripts/indexar_nao_indexados.php --site=cursosenac --usar-fila  (pula inspeção, usa fila pendente)
 *
 * Quota:
 *   - urlInspection: 2.000/dia/site (Search Console API)
 *   - Indexing API: ~200/dia/site (oficial Google)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
set_time_limit(0);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/lib/DiscoverSearchConsole.php';
require_once $ROOT . '/lib/InstantIndexing.php';

// ====================================================================
// Args
// ====================================================================
$siteSlug = '';
$maxIndexar = 200;
$dryRun = false;
$usarFila = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site=')) $siteSlug = trim(substr($arg, 7));
    elseif (str_starts_with($arg, '--max=')) $maxIndexar = (int)substr($arg, 6);
    elseif ($arg === '--dry-run') $dryRun = true;
    elseif ($arg === '--usar-fila') $usarFila = true;
}
if ($siteSlug === '') { fwrite(STDERR, "ERRO: --site=<slug> obrigatório\n"); exit(1); }
$maxIndexar = max(1, min(200, $maxIndexar));

$sites = require $ROOT . '/sites.php';
if (!isset($sites[$siteSlug])) {
    fwrite(STDERR, "ERRO: site '{$siteSlug}' não cadastrado.\n");
    exit(2);
}
$cfgSite = $sites[$siteSlug];
$wpUrl = rtrim($cfgSite['wp_url'], '/') . '/';

// Domínio puro (sem protocolo/www) pra resolver siteUrl no Search Console depois
$dominioPuro = preg_replace('#^https?://#', '', $cfgSite['wp_url']) ?? '';
$dominioPuro = preg_replace('#^www\.#', '', $dominioPuro) ?? $dominioPuro;
$dominioPuro = rtrim($dominioPuro, '/');

echo "🎯 Site: {$cfgSite['name']} ({$wpUrl})\n";
echo "📊 Limite: {$maxIndexar}/dia\n\n";

$filaPath = $ROOT . '/data/fila/indexar_pendente_' . $siteSlug . '.json';

// ====================================================================
// Fonte das URLs: fila pendente OU lista WP + inspecionar
// ====================================================================
$urlsParaIndexar = [];

if ($usarFila) {
    if (!file_exists($filaPath)) {
        echo "⚠️ Sem fila pendente em {$filaPath}. Saindo.\n";
        exit(0);
    }
    $fila = json_decode(file_get_contents($filaPath), true);
    if (!is_array($fila) || empty($fila['urls'])) {
        echo "⚠️ Fila vazia.\n";
        exit(0);
    }
    $urlsParaIndexar = $fila['urls'];
    echo "📋 Usando fila pendente: " . count($urlsParaIndexar) . " URLs\n\n";
} else {
    // 1. Lista TODOS posts publicados via WP REST
    echo "📥 Listando posts publicados via WP REST (paginado)...\n";
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    $todosUrls = [];
    $pagina = 1;
    while (true) {
        try {
            $batch = $wp->listarPosts($pagina, 100);
        } catch (Throwable $e) {
            // Última página retorna erro 400 quando não tem mais posts
            break;
        }
        if (empty($batch)) break;
        foreach ($batch as $p) {
            $link = (string)($p['link'] ?? '');
            if ($link !== '') {
                $todosUrls[] = [
                    'id'    => (int)($p['id'] ?? 0),
                    'url'   => $link,
                    'title' => is_array($p['title']) ? ($p['title']['rendered'] ?? '') : (string)$p['title'],
                ];
            }
        }
        echo "  · página {$pagina}: " . count($batch) . " posts (acumulado " . count($todosUrls) . ")\n";
        if (count($batch) < 100) break; // última página
        $pagina++;
        if ($pagina > 50) break; // safety: max 5000 posts
    }
    echo "  ✓ Total: " . count($todosUrls) . " posts publicados\n\n";

    if (empty($todosUrls)) {
        echo "Nenhum post pra processar.\n";
        exit(0);
    }

    // 2. Inspeciona cada URL via Search Console urlInspection
    echo "🔍 Inspecionando URLs no Search Console...\n";
    try {
        $sc = new DiscoverSearchConsole();
    } catch (Throwable $e) {
        fwrite(STDERR, "ERRO Search Console: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Verifique data/google_credentials.json\n");
        exit(3);
    }

    // Resolve siteUrl correto (URL-prefix vs sc-domain). Sem isso, urlInspection retorna 403
    // mesmo com permissão (porque o payload precisa do siteUrl EXATO da property).
    $siteUrl = $sc->resolverSiteUrl($dominioPuro);
    if ($siteUrl === null) {
        fwrite(STDERR, "ERRO: nenhuma property no Search Console cobre o domínio '{$dominioPuro}'.\n");
        fwrite(STDERR, "  Adicione a property em https://search.google.com/search-console e dê Owner\n");
        fwrite(STDERR, "  pra cursosenacgratuito@gentle-post-454920-n2.iam.gserviceaccount.com\n");
        exit(3);
    }
    echo "  Property: {$siteUrl}\n\n";

    $naoIndexados = [];
    $indexados = 0;
    $erros = 0;
    foreach ($todosUrls as $i => $item) {
        $u = $item['url'];
        try {
            $r = $sc->inspecionarUrl($siteUrl, $u);
        } catch (Throwable $e) {
            $erros++;
            echo "  ✗ #{$item['id']} erro: " . substr($e->getMessage(), 0, 80) . "\n";
            continue;
        }
        if (!$r['ok']) {
            $erros++;
            echo "  ✗ #{$item['id']} HTTP {$r['http_code']}: " . substr((string)$r['error'], 0, 80) . "\n";
            // Se erro de quota/permissão, para tudo
            if ($r['http_code'] === 403 || $r['http_code'] === 429) {
                echo "  ⚠️ Quota ou permissão — interrompendo inspeção\n";
                break;
            }
            continue;
        }
        if ($r['indexed']) {
            $indexados++;
        } else {
            $naoIndexados[] = $item + ['coverage' => $r['coverageState']];
            echo "  ❌ #{$item['id']} NÃO-indexado: " . ($r['coverageState'] ?: '?') . " — " . substr($u, 0, 70) . "\n";
        }
        // Pacing leve pra não estourar rate (200ms)
        usleep(200000);
    }

    echo "\n📊 RESUMO INSPEÇÃO:\n";
    echo "  Total: " . count($todosUrls) . "\n";
    echo "  ✓ Indexados: {$indexados}\n";
    echo "  ❌ NÃO-indexados: " . count($naoIndexados) . "\n";
    echo "  ⚠️ Erros: {$erros}\n\n";

    // Falha real: inspeção quebrou em 100% dos posts (provável permissão Search Console)
    if ($indexados === 0 && empty($naoIndexados) && $erros > 0) {
        fwrite(STDERR, "❌ FALHA: inspeção falhou em todos os posts (permissão ausente?).\n");
        fwrite(STDERR, "   Adicione a service account como 'Owner' ou 'Full User' em\n");
        fwrite(STDERR, "   https://search.google.com/search-console (Settings → Users)\n");
        exit(4);
    }

    if (empty($naoIndexados)) {
        echo "✅ Todos os posts já estão indexados. Nada a fazer.\n";
        if (file_exists($filaPath)) @unlink($filaPath);
        exit(0);
    }

    $urlsParaIndexar = $naoIndexados;
}

// ====================================================================
// 3. Particiona: hoje vs amanhã (limite max)
// ====================================================================
$total = count($urlsParaIndexar);
$hoje = array_slice($urlsParaIndexar, 0, $maxIndexar);
$amanha = array_slice($urlsParaIndexar, $maxIndexar);

echo "⚡ INDEXAR HOJE: " . count($hoje) . " · 📦 PARA AMANHÃ: " . count($amanha) . "\n\n";

if ($dryRun) {
    echo "(modo --dry-run — nada será indexado)\n";
    foreach ($hoje as $i => $item) {
        echo "  [dry] " . ($i+1) . ". #{$item['id']} {$item['url']}\n";
    }
    exit(0);
}

// ====================================================================
// 4. Indexa via InstantIndexing
// ====================================================================
$idx = new InstantIndexing($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

$ok = 0;
$falhas = 0;
$pluginAusente = false;
foreach ($hoje as $i => $item) {
    $u = $item['url'];
    $tituloCurto = mb_substr(html_entity_decode(strip_tags((string)($item['title'] ?? '')), ENT_QUOTES, 'UTF-8'), 0, 50);
    try {
        $r = $idx->indexar($u, 'URL_UPDATED');
        if (!empty($r['success'])) {
            $ok++;
            echo "  ✓ " . ($i+1) . ". #{$item['id']} {$tituloCurto} ({$r['method']})\n";
        } else {
            $falhas++;
            $err = (string)($r['error'] ?? '?');
            echo "  ✗ " . ($i+1) . ". #{$item['id']} — " . substr($err, 0, 80) . "\n";
            if (str_contains(strtolower($err), 'rest_no_route') || str_contains(strtolower($err), '404')) {
                $pluginAusente = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $falhas++;
        echo "  ✗ " . ($i+1) . ". exception: " . substr($e->getMessage(), 0, 80) . "\n";
    }
    usleep(150000); // 150ms entre chamadas
}

// ====================================================================
// 5. Salva fila pendente (se sobrou) ou limpa
// ====================================================================
if (!empty($amanha)) {
    @mkdir(dirname($filaPath), 0775, true);
    file_put_contents($filaPath, json_encode([
        'site'           => $siteSlug,
        'criado_em'      => date('c'),
        'total_pendente' => count($amanha),
        'urls'           => array_values($amanha),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "\n💾 Fila pendente salva: {$filaPath}\n";
    echo "   Próxima rodada: php scripts/indexar_nao_indexados.php --site={$siteSlug} --usar-fila\n";
} else {
    if (file_exists($filaPath)) @unlink($filaPath);
    echo "\n✅ Sem pendência — fila limpa.\n";
}

echo "\n" . str_repeat('═', 70) . "\n";
echo "RESUMO FINAL ({$siteSlug})\n";
echo "  ⚡ Indexados hoje: {$ok}\n";
echo "  ✗ Falhas: {$falhas}\n";
if (!empty($amanha)) {
    echo "  📦 Para amanhã: " . count($amanha) . " URLs (rodar com --usar-fila)\n";
}
if ($pluginAusente) {
    echo "\n⚠️  Plugin cc-instant-indexing-api ausente neste site.\n";
    echo "    Instale: plugin/cc-instant-indexing-api.php\n";
}
