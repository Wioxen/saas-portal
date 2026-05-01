<?php
/**
 * mapear_html_legacy.php — mapeia URLs .html legacy do sitemap antigo pra equivalentes WP
 * e tenta criar redirects 301 no Rank Math (com fallback CSV).
 *
 * Fluxo:
 *   1. Carrega config do site via slug (sites.php) — pega WP creds + URL público
 *   2. Fetch sitemap.xml — extrai todas URLs declaradas
 *   3. Pra cada URL: HEAD check → 200 (vivo, manter) ou 404 (morto, mapear)
 *   4. Pra mortas:
 *      - Tokeniza o slug (ex: 'curso-programacao-senac' → ['curso','programacao','senac'])
 *      - Busca WP REST /posts e /pages via ?search=topterm
 *      - Calcula similaridade slug-vs-slug; se >= 65, propõe 301 → wp-equivalente
 *      - Senão: marca 410 Gone (URL deletada permanente)
 *   5. Tenta criar cada redirect via Rank Math REST API (/wp-json/rankmath/v1/redirections)
 *   6. SEMPRE gera CSV (formato Rank Math Import) + .htaccess como fallback
 *
 * Uso:
 *   php mapear_html_legacy.php cursosenac
 *
 * Output:
 *   - CSV: data/redirects_<slug>.csv (importar via Rank Math → Redirections → Import)
 *   - HTACCESS: data/redirects_<slug>.htaccess (colar em .htaccess do Apache)
 *   - Tela: relatório de quantos foram criados via API vs caíram no CSV
 */

set_time_limit(300);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/_site_helper.php';
require_once __DIR__ . '/lib/Wordpress.php';

$slug = $argv[1] ?? '';
if ($slug === '') {
    fwrite(STDERR, "Uso: php mapear_html_legacy.php <site-slug>\nExemplo: php mapear_html_legacy.php cursosenac\n");
    exit(1);
}

$sites = sitesDisponiveis();
if (!isset($sites[$slug])) {
    fwrite(STDERR, "Slug '{$slug}' não encontrado em sites.php. Disponíveis: " . implode(', ', array_keys($sites)) . "\n");
    exit(1);
}

$site = $sites[$slug];
$wpUrl = rtrim($site['wp_url'], '/');
$wpUser = $site['wp_user'];
$wpPass = $site['wp_app_password'];

echo "\n=== Mapeamento HTML legacy → WP ({$slug}) ===\n";
echo "WP: {$wpUrl}\n";

// ─── 1. FETCH SITEMAP ───
$sitemapUrls = ["{$wpUrl}/sitemap.xml", "{$wpUrl}/sitemap_index.xml", "{$wpUrl}/wp-sitemap.xml"];
$sitemapBody = '';
$sitemapEncontrado = '';
foreach ($sitemapUrls as $sUrl) {
    $r = fetchHttp($sUrl);
    if ($r['code'] === 200 && (strpos($r['body'], '<urlset') !== false || strpos($r['body'], '<sitemapindex') !== false)) {
        $sitemapBody = $r['body'];
        $sitemapEncontrado = $sUrl;
        break;
    }
}
if ($sitemapBody === '') { fwrite(STDERR, "Sitemap não encontrado. Tentei: " . implode(', ', $sitemapUrls) . "\n"); exit(1); }
echo "Sitemap: {$sitemapEncontrado}\n";

// Sitemap index → segue o primeiro filho
if (strpos($sitemapBody, '<sitemapindex') !== false) {
    if (preg_match('#<loc>([^<]+)</loc>#', $sitemapBody, $mIdx)) {
        $r = fetchHttp($mIdx[1]);
        $sitemapBody = $r['body'];
        echo "Sitemap index → primeiro: {$mIdx[1]}\n";
    }
}

preg_match_all('#<loc>([^<]+)</loc>#', $sitemapBody, $mUrls);
$todasUrls = array_unique($mUrls[1] ?? []);
$totalUrls = count($todasUrls);
echo "URLs no sitemap: {$totalUrls}\n\n";

// ─── 2. CHECK CADA URL (HEAD) ───
echo "Verificando URLs (HEAD checks)...\n";
$mortas = []; $vivas = [];
foreach ($todasUrls as $url) {
    $r = fetchHttp($url, true);
    if ($r['code'] === 200) {
        $vivas[] = $url;
        echo "  ✓ {$url}\n";
    } elseif ($r['code'] >= 400) {
        $mortas[] = $url;
        echo "  ✗ {$url} ({$r['code']})\n";
    }
}
$tVivas = count($vivas); $tMortas = count($mortas);
echo "\nResumo: {$tVivas} vivas, {$tMortas} mortas\n";

if ($tMortas === 0) {
    echo "\n✅ Sitemap está 100% válido. Nenhum redirect necessário.\n";
    exit(0);
}

// ─── 3. PRA CADA MORTA, BUSCA EQUIVALENTE NO WP ───
echo "\nBuscando equivalentes WP pras URLs mortas...\n";
$wp = new Wordpress($wpUrl, $wpUser, $wpPass);
$mapeamento = []; // [['old' => url, 'new' => url|null, 'sim' => 0-100, 'action' => '301'|'410']]

foreach ($mortas as $oldUrl) {
    $oldPath = parse_url($oldUrl, PHP_URL_PATH) ?: '';
    $oldSlug = basename($oldPath, '.html');
    $oldSlug = trim($oldSlug, '/');

    // Tokeniza pra usar como search
    $tokens = preg_split('/[-_]+/', $oldSlug);
    $stopwords = ['e','de','da','do','para','no','com','o','a','um','uma','que','dos','das','em'];
    $tokens = array_filter($tokens, fn($t) => mb_strlen($t) >= 3 && !in_array($t, $stopwords, true));
    $searchTerm = implode(' ', array_slice($tokens, 0, 3));

    $melhorMatch = null;
    $melhorScore = 0;

    if ($searchTerm !== '') {
        try {
            // Busca em posts
            $resp = httpGetWp($wpUrl . '/wp-json/wp/v2/posts?per_page=10&search=' . urlencode($searchTerm), $wpUser, $wpPass);
            foreach ($resp as $p) {
                $score = scoreSimilaridade($oldSlug, (string)($p['slug'] ?? ''));
                if ($score > $melhorScore) {
                    $melhorScore = $score;
                    $melhorMatch = ['title' => $p['title']['rendered'] ?? '', 'link' => $p['link'] ?? '', 'slug' => $p['slug'] ?? ''];
                }
            }
            // Busca em pages (compliance, etc)
            $resp2 = httpGetWp($wpUrl . '/wp-json/wp/v2/pages?per_page=10&search=' . urlencode($searchTerm), $wpUser, $wpPass);
            foreach ($resp2 as $p) {
                $score = scoreSimilaridade($oldSlug, (string)($p['slug'] ?? ''));
                if ($score > $melhorScore) {
                    $melhorScore = $score;
                    $melhorMatch = ['title' => $p['title']['rendered'] ?? '', 'link' => $p['link'] ?? '', 'slug' => $p['slug'] ?? ''];
                }
            }
            // Busca em categorias (caso seja categoria deletada)
            $resp3 = httpGetWp($wpUrl . '/wp-json/wp/v2/categories?per_page=10&search=' . urlencode($searchTerm), $wpUser, $wpPass);
            foreach ($resp3 as $c) {
                $score = scoreSimilaridade($oldSlug, (string)($c['slug'] ?? ''));
                if ($score > $melhorScore) {
                    $melhorScore = $score;
                    $melhorMatch = ['title' => $c['name'] ?? '', 'link' => $c['link'] ?? '', 'slug' => $c['slug'] ?? ''];
                }
            }
        } catch (Throwable $e) {
            // Falha de busca — segue, vira 410
        }
    }

    // Decisão: ≥65 → 301 pro WP, senão → 410 (URL morta permanente)
    if ($melhorMatch && $melhorScore >= 65) {
        $mapeamento[] = ['old' => $oldUrl, 'old_path' => $oldPath, 'new' => $melhorMatch['link'], 'new_path' => parse_url($melhorMatch['link'], PHP_URL_PATH) ?: '/', 'sim' => $melhorScore, 'action' => '301', 'titulo_destino' => strip_tags($melhorMatch['title'])];
        echo "  → {$oldPath} → {$melhorMatch['link']} ({$melhorScore}%)\n";
    } else {
        $mapeamento[] = ['old' => $oldUrl, 'old_path' => $oldPath, 'new' => null, 'new_path' => null, 'sim' => $melhorScore, 'action' => '410', 'titulo_destino' => ''];
        echo "  ✗ {$oldPath} → 410 Gone (sem equivalente; melhor match {$melhorScore}%)\n";
    }
}

// ─── 4. TENTAR CRIAR VIA RANK MATH REST API ───
echo "\nTentando criar redirects via Rank Math REST API...\n";
$rmEndpoint = $wpUrl . '/wp-json/rankmath/v1/redirections';
$apiCriados = 0; $apiFalhou = 0;
$apiDisponivel = false;

// Test endpoint primeiro
$testResp = httpGetWpRaw($rmEndpoint . '?per_page=1', $wpUser, $wpPass);
if ($testResp['code'] >= 200 && $testResp['code'] < 300) {
    $apiDisponivel = true;
    echo "  ✓ Rank Math REST API disponível\n";
} else {
    echo "  ✗ Rank Math REST API indisponível (HTTP {$testResp['code']}). Indo direto pro CSV.\n";
}

if ($apiDisponivel) {
    foreach ($mapeamento as &$m) {
        if ($m['action'] === '410') {
            // Cria como 410 (Rank Math suporta)
            $payload = [
                'sources'      => [['pattern' => $m['old_path'], 'comparison' => 'exact']],
                'url_to'       => '',
                'header_code'  => 410,
                'status'       => 'active',
            ];
        } else {
            $payload = [
                'sources'      => [['pattern' => $m['old_path'], 'comparison' => 'exact']],
                'url_to'       => $m['new_path'],
                'header_code'  => 301,
                'status'       => 'active',
            ];
        }
        $resp = httpPostWp($rmEndpoint, $payload, $wpUser, $wpPass);
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            $apiCriados++;
            $m['_api_status'] = 'OK';
        } else {
            $apiFalhou++;
            $m['_api_status'] = "FALHOU ({$resp['code']})";
        }
    }
    unset($m);
    echo "  ✓ {$apiCriados} criados via API\n";
    if ($apiFalhou > 0) echo "  ⚠ {$apiFalhou} falharam → vão pro CSV\n";
}

// ─── 5. SEMPRE GERA CSV + HTACCESS ───
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

$csvFile      = "{$dataDir}/redirects_{$slug}.csv";
$htaccessFile = "{$dataDir}/redirects_{$slug}.htaccess";

// CSV no formato Rank Math (importável via Redirections → Import)
$csvLines = ['sources,destinations,types,statuses,enabled,header_codes'];
foreach ($mapeamento as $m) {
    $src = $m['old_path'];
    $dst = $m['action'] === '301' ? $m['new_path'] : '';
    $code = $m['action'];
    $csvLines[] = "\"{$src}\",\"{$dst}\",exact,active,yes,{$code}";
}
file_put_contents($csvFile, implode("\n", $csvLines));

// .htaccess
$htLines = ['# Redirects gerados por mapear_html_legacy.php em ' . date('Y-m-d H:i')];
$htLines[] = '# Cole estas linhas DENTRO do bloco <IfModule mod_rewrite.c> ou no topo do .htaccess';
foreach ($mapeamento as $m) {
    if ($m['action'] === '301') {
        $htLines[] = "Redirect 301 {$m['old_path']} {$m['new_path']}";
    } else {
        $htLines[] = "Redirect gone {$m['old_path']}";
    }
}
file_put_contents($htaccessFile, implode("\n", $htLines));

// ─── 6. RELATÓRIO FINAL ───
echo "\n=== RELATÓRIO ===\n";
echo "Total URLs sitemap: {$totalUrls}\n";
echo "Vivas (200): {$tVivas}\n";
echo "Mortas mapeadas: {$tMortas}\n";
$c301 = count(array_filter($mapeamento, fn($m) => $m['action'] === '301'));
$c410 = count(array_filter($mapeamento, fn($m) => $m['action'] === '410'));
echo "  - 301 (redirect pro WP): {$c301}\n";
echo "  - 410 (URL morta permanente, sem equivalente): {$c410}\n";
echo "\nVia Rank Math API:\n";
echo "  - Criados: {$apiCriados}\n";
echo "  - Falharam: {$apiFalhou}\n";
echo "\nArquivos gerados:\n";
echo "  - CSV: {$csvFile} (importar em Rank Math → Redirections → Import)\n";
echo "  - HTACCESS: {$htaccessFile} (alternativa via Apache)\n";
echo "\nPróximos passos:\n";
if (!$apiDisponivel || $apiFalhou > 0) {
    echo "  1. Importar CSV no Rank Math: https://cursosenacgratuito.com.br/wp-admin/admin.php?page=rank-math-redirections (botão Import)\n";
}
echo "  2. Forçar regeneração do sitemap em Rank Math → Sitemap Settings → Save\n";
echo "  3. Re-rodar audit: php auditar_adsense.php https://cursosenacgratuito.com.br\n";
echo "  4. Esperar 5-7 dias e reaplicar AdSense\n";

// ─── HELPERS ───
function fetchHttp(string $url, bool $headOnly = false, int $timeout = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_NOBODY         => $headOnly,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body ?: ''];
}

function httpGetWp(string $url, string $user, string $pass): array {
    $r = httpGetWpRaw($url, $user, $pass);
    return is_array($r['body']) ? $r['body'] : (json_decode($r['body'] ?? '', true) ?: []);
}

function httpGetWpRaw(string $url, string $user, string $pass, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);
    return ['code' => $code, 'body' => is_array($decoded) ? $decoded : $body];
}

function httpPostWp(string $url, array $payload, string $user, string $pass, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

function scoreSimilaridade(string $a, string $b): float {
    $a = mb_strtolower(trim($a));
    $b = mb_strtolower(trim($b));
    if ($a === '' || $b === '') return 0;
    $a = preg_replace('/[^a-z0-9]+/', ' ', $a);
    $b = preg_replace('/[^a-z0-9]+/', ' ', $b);
    if ($a === $b) return 100;
    similar_text($a, $b, $pct);
    // Bônus: tokens em comum
    $tokA = array_unique(preg_split('/\s+/', $a, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $tokB = array_unique(preg_split('/\s+/', $b, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $stop = ['de','da','do','para','no','com','o','a','um','uma','que','e'];
    $tokA = array_diff($tokA, $stop);
    $tokB = array_diff($tokB, $stop);
    if (!empty($tokA) && !empty($tokB)) {
        $inter = count(array_intersect($tokA, $tokB));
        $uniao = count(array_unique(array_merge($tokA, $tokB)));
        $jaccard = $uniao > 0 ? ($inter / $uniao) : 0;
        return min(100, $pct + ($jaccard * 30));
    }
    return $pct;
}
