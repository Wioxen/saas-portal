<?php
/**
 * [SMOKE TEST] Pré-deploy do SaaS.
 *
 * Valida que todos os componentes da Fase 2 carregam e respondem SEM gastar API LLM.
 * Roda em ~30s. Zero custo (não chama Sonnet/GPT/Serper).
 *
 * O que testa:
 *   1. Sintaxe PHP de arquivos críticos novos/modificados
 *   2. Require chain (lib carrega sem fatal)
 *   3. config.php + sites.php — keys obrigatórias
 *   4. Conexão REST WP dos 6 sites (`/wp-json/`)
 *   5. Endpoints custom dos plugins (cc-prettylinks-api, cc-instant-indexing-api, wp-wsai)
 *   6. Service Account GSC (se credentials.json existir) — ping `/sites`
 *   7. Cache Amazon (data/cache/amazon_bestsellers/) tem dados
 *   8. GD extension loaded (necessária pra IG variante 4:5)
 *
 * Uso:
 *   php scripts/_smoke_test.php
 *   php scripts/_smoke_test.php --site=comocomprar    # só 1 site
 *   php scripts/_smoke_test.php --skip-rest           # pula requests HTTP
 */

set_time_limit(120);
$ROOT = dirname(__DIR__);

// ── parse args ──
$soSite = null;
$skipRest = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--site=')) $soSite = substr($a, 7);
    elseif ($a === '--skip-rest')       $skipRest = true;
}

$ok = 0; $warn = 0; $fail = 0;
function check(string $label, $status, string $detail = ''): void
{
    global $ok, $warn, $fail;
    [$icon, $tag] = $status === true   ? ['✓', 'OK  ']
                  : ($status === 'warn' ? ['~', 'WARN']
                  : ['✗', 'FAIL']);
    if ($status === true) $ok++;
    elseif ($status === 'warn') $warn++;
    else $fail++;
    printf("  %s [%s] %-50s %s\n", $icon, $tag, $label, $detail);
}

function secao_smoke(string $titulo): void
{
    echo "\n═══ {$titulo} ═══\n";
}

// ─── 1. Sintaxe PHP ───
secao_smoke('1. Lint sintaxe (php -l)');
$arquivosCriticos = [
    'lib/AmazonScraper.php',
    'lib/DiscoverProductRanker.php',
    'lib/DiscoverImagemViral.php',
    'lib/AutoRefresh.php',
    'lib/CronLock.php',
    'lib/Pexels.php',
    'lib/DiscoverImagemFeatured.php',
    'lib/HealthWebhook.php',
    'lib/SpikeDetector.php',
    'lib/PrePublishLint.php',
    'lib/DiscoverHtmlValidator.php',
    'lib/HttpClient.php',
    'lib/DiscoverSchemas.php',
    'lib/DiscoverHubPages.php',
    'lib/DiscoverRelatedLinks.php',
    'lib/DiscoverTrustBlocks.php',
    'scripts/gerar_hubs.php',
    'plugin/cc-news-sitemap.php',
    'scripts/submeter_news_sitemaps.php',
    'scripts/spike_detect.php',
    'scripts/pruning_posts_antigos.php',
    'scripts/_submeter_sitemaps.php',
    'scripts/_empacotar_deploy.php',
    'scripts/gsc_aprender.php',
    'lib/DiscoverGerador.php',
    'lib/DiscoverGeradorGPT.php',
    'lib/DiscoverAfiliados.php',
    'lib/DiscoverPromptBuilder.php',
    'lib/DiscoverReviewer.php',
    'lib/DiscoverSearchConsole.php',
    'lib/Meta.php',
    'lib/Wordpress.php',
    'lib/PrettyLinks.php',
    'lib/Claude.php',
    'lib/OpenAI.php',
    'scripts/tick_filas.php',
    'scripts/auto_refresh_posts.php',
    'scripts/antecipar_sazonal.php',
    'scripts/pingo.php',
    'scripts/backup_state.php',
];
$phpBin = (PHP_OS_FAMILY === 'Windows') ? 'C:/xampp/php/php.exe' : 'php';
foreach ($arquivosCriticos as $rel) {
    $path = $ROOT . '/' . $rel;
    if (!is_file($path)) { check($rel, 'warn', '(arquivo ausente)'); continue; }
    $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $out = shell_exec($cmd) ?: '';
    if (strpos($out, 'No syntax errors') !== false) check($rel, true);
    else check($rel, false, trim(substr($out, 0, 100)));
}

// ─── 2. Require chain ───
secao_smoke('2. Carregamento de libs (require sem fatal)');
$libs = ['AmazonScraper', 'DiscoverProductRanker', 'AutoRefresh', 'DiscoverImagemViral', 'CronLock', 'Pexels', 'DiscoverImagemFeatured', 'HealthWebhook', 'SpikeDetector', 'PrePublishLint', 'DiscoverHtmlValidator', 'HttpClient', 'DiscoverSchemas', 'DiscoverHubPages', 'DiscoverRelatedLinks', 'DiscoverTrustBlocks'];
foreach ($libs as $lib) {
    try {
        require_once $ROOT . "/lib/{$lib}.php";
        check($lib, class_exists($lib));
    } catch (Throwable $e) { check($lib, false, $e->getMessage()); }
}

// ─── 3. Config ───
secao_smoke('3. config.php + sites.php');
try {
    $cfg = require $ROOT . '/config.php';
    check('config.php carrega', is_array($cfg));
    foreach (['anthropic_api_key', 'openai_api_key', 'serper_api_key', 'pexels_api_key'] as $k) {
        check("cfg.{$k}", !empty($cfg[$k] ?? null), empty($cfg[$k]) ? '(vazio)' : '(setado)');
    }
} catch (Throwable $e) { check('config.php', false, $e->getMessage()); $cfg = []; }

require_once $ROOT . '/_site_helper.php';
$sites = sitesDisponiveis();
check('sites.php carrega', is_array($sites) && count($sites) > 0, count($sites) . ' sites');
if ($soSite !== null) {
    if (!isset($sites[$soSite])) { check("--site={$soSite}", false, '(não existe)'); $sites = []; }
    else $sites = [$soSite => $sites[$soSite]];
}

// ─── 4. Conexão REST WP ───
if (!$skipRest) {
    secao_smoke('4. Conexão REST WP (/wp-json/)');
    foreach ($sites as $slug => $siteCfg) {
        $url = rtrim((string)($siteCfg['wp_url'] ?? ''), '/') . '/wp-json/';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 ClonaisSmoke/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && strlen((string)$body) > 100) {
            $j = json_decode((string)$body, true);
            check($slug, true, 'WP ' . ($j['routes']['/']['namespace'] ?? '?') . ' acessível');
        } else {
            check($slug, false, "HTTP {$code}");
        }
    }
}

// ─── 5. Endpoints custom dos plugins ───
if (!$skipRest) {
    secao_smoke('5. Plugins custom (cc-prettylinks-api, cc-instant-indexing-api)');
    foreach ($sites as $slug => $siteCfg) {
        $base = rtrim((string)($siteCfg['wp_url'] ?? ''), '/');
        $auth = base64_encode(($siteCfg['wp_user'] ?? '') . ':' . ($siteCfg['wp_app_password'] ?? ''));

        // PrettyLinks: GET /cc/v1/pretty-link/_smoke (deve retornar 404 ou 200 — não 401/500)
        $endpoints = [
            'pretty-link' => ['GET', $base . '/wp-json/cc/v1/pretty-link/_smoke'],
            'indexar'     => ['OPTIONS', $base . '/wp-json/cc/v1/indexar'],
        ];
        foreach ($endpoints as $nome => [$metodo, $url]) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo,
                CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // 200/404 = endpoint existe (404 = recurso não achado, mas rota OK); 401 = auth bug; outros = plugin off
            $estado = ($code === 200 || $code === 404) ? true : ($code === 401 ? 'warn' : false);
            check("{$slug}.{$nome}", $estado, "HTTP {$code}");
        }
    }
}

// ─── 6. GSC Service Account ───
secao_smoke('6. Google Search Console (Service Account)');
$credPath = $ROOT . '/data/google_credentials.json';
if (!is_file($credPath)) {
    check('credentials', 'warn', '(data/google_credentials.json ausente — auto-refresh não funciona)');
} else {
    require_once $ROOT . '/lib/DiscoverSearchConsole.php';
    try {
        $gsc = new DiscoverSearchConsole($credPath);
        $token = $gsc->getAccessToken();
        check('access_token', !empty($token), '(' . substr($token, 0, 12) . '...)');
        $sitesGsc = $gsc->listarSites();
        check('listar sites', is_array($sitesGsc), count($sitesGsc) . ' sites autorizados');
        if (count($sitesGsc) === 0) {
            check('autorização', 'warn', '(adicionar Service Account em cada GSC property)');
        }
    } catch (Throwable $e) { check('GSC', false, $e->getMessage()); }
}

// ─── 7. Cache Amazon ───
secao_smoke('7. Cache Amazon (data/cache/amazon_bestsellers)');
$dirCache = $ROOT . '/data/cache/amazon_bestsellers';
if (!is_dir($dirCache)) {
    check('diretório cache', 'warn', '(será criado no 1º uso do ProductRanker)');
} else {
    foreach (['electronics','home','toys','beauty','sports','books'] as $cat) {
        $f = $dirCache . '/' . $cat . '.json';
        if (!is_file($f)) { check($cat, 'warn', '(sem cache — primeira execução vai scrapar)'); continue; }
        $data = json_decode((string)@file_get_contents($f), true);
        $count = count($data['produtos'] ?? []);
        $idade = isset($data['fetched_at']) ? round((time() - (int)$data['fetched_at']) / 3600, 1) : null;
        check($cat, $count > 0, "{$count} produtos · {$idade}h atrás");
    }
}

// ─── 8. Extensões PHP ───
secao_smoke('8. Extensões PHP necessárias');
foreach (['curl', 'gd', 'mbstring', 'openssl', 'json', 'libxml'] as $ext) {
    check("ext: {$ext}", extension_loaded($ext));
}
// Específico GD: tem JPEG + PNG + WebP?
if (extension_loaded('gd')) {
    $gd = gd_info();
    check('GD JPEG support', !empty($gd['JPEG Support']));
    check('GD PNG support', !empty($gd['PNG Support']));
    check('GD WebP support', !empty($gd['WebP Support']), !empty($gd['WebP Support']) ? '' : '(IG variante usa JPG, ok ignorar)');
}

// ─── 9. State files ───
secao_smoke('9. State files (rotativos)');
foreach (['data/discover_trends.json', 'data/afiliados.json', 'data/fontes_pingo.json', 'data/pingo_filtros.json'] as $f) {
    $path = $ROOT . '/' . $f;
    if (!is_file($path)) { check($f, false, '(ausente — crítico)'); continue; }
    $j = json_decode((string)@file_get_contents($path), true);
    check($f, is_array($j), is_array($j) ? '(' . count($j) . ' chaves top-level)' : 'JSON inválido');
}

// ─── Resumo ───
echo "\n";
echo str_repeat('═', 60) . "\n";
printf("RESUMO: %d OK · %d WARN · %d FAIL\n", $ok, $warn, $fail);
echo str_repeat('═', 60) . "\n";

if ($fail > 0) {
    echo "\n⚠️  Corrigir os FAILs antes de subir o SaaS.\n";
    exit(1);
}
if ($warn > 0) {
    echo "\nWARN são informativos (cache não preenchido, GSC pendente, etc.) — não bloqueiam deploy.\n";
}
echo "\n✓ SaaS pronto pra subir.\n";
exit(0);
