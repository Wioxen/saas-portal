<?php
/**
 * Verifica direto via HTML se as categorias têm noindex aplicado.
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { exit(1); }
$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');

function get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

function getCats($wpUrl, $u, $p) {
    $ch = curl_init("{$wpUrl}/wp-json/wp/v2/categories?per_page=100&hide_empty=false");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "{$u}:{$p}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$body, true) ?: [];
}

$cats = getCats($wpUrl, $s['wp_user'], $s['wp_app_password']);
$thin = array_filter($cats, fn($c) => ($c['count'] ?? 0) < 3 && !in_array(($c['slug'] ?? ''), ['uncategorized', 'sem-categoria'], true));

echo "Verificando " . count($thin) . " categorias thin (HEAD HTML pra ver meta robots)...\n\n";
$comNoindex = 0; $semNoindex = 0; $erros = 0; $exemplos = [];
foreach (array_slice($thin, 0, 25) as $c) {
    $url = $c['link'] ?? '';
    if ($url === '') continue;
    $r = get($url);
    if ($r['code'] !== 200) {
        $erros++;
        echo "  ✗ {$c['name']} HTTP {$r['code']}\n";
        continue;
    }
    $hasNoindex = (bool)preg_match('/<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex/i', $r['body']);
    if ($hasNoindex) {
        $comNoindex++;
        echo "  ✓ {$c['name']} → noindex aplicado\n";
    } else {
        $semNoindex++;
        $exemplos[] = $c['name'] . " ({$url})";
        echo "  ✗ {$c['name']} → AINDA INDEXÁVEL\n";
    }
}

echo "\n=== RESULTADO ===\n";
echo "Com noindex: {$comNoindex}\n";
echo "Sem noindex: {$semNoindex}\n";
echo "Erros HTTP: {$erros}\n";
