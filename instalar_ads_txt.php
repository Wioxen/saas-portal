<?php
/**
 * Instala plugin "Ads.txt Manager" (10up) e configura conteúdo via REST.
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$pubId = $argv[2] ?? ''; // pub-XXXXXXXXXXXXXXXX

if ($slug === '' || $pubId === '') {
    echo "Uso: php instalar_ads_txt.php <slug> <pub-XXXX>\n";
    echo "Exemplo: php instalar_ads_txt.php vagasebeneficios pub-1234567890123456\n";
    exit(1);
}

$sites = sitesDisponiveis();
$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');

function rest($method, $url, $payload, $u, $p) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERPWD => "{$u}:{$p}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if (in_array(strtoupper($method), ['POST', 'PUT'], true) && !empty($payload)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$body, true) ?: $body];
}

echo "=== Instalando Ads.txt Manager ({$slug}) ===\n";

$plg = 'ads-txt/ads-txt';
$check = rest('GET', "{$wpUrl}/wp-json/wp/v2/plugins/{$plg}", [], $s['wp_user'], $s['wp_app_password']);
if ($check['code'] !== 200) {
    $i = rest('POST', "{$wpUrl}/wp-json/wp/v2/plugins", ['slug' => 'ads-txt', 'status' => 'active'], $s['wp_user'], $s['wp_app_password']);
    if ($i['code'] >= 200 && $i['code'] < 300) {
        echo "✓ Instalado e ativado\n";
    } else {
        echo "✗ HTTP {$i['code']}: " . json_encode($i['body']) . "\n";
        exit(1);
    }
} else {
    echo "Plugin já instalado\n";
}

// Cria/atualiza ads.txt — 10up plugin armazena como CPT 'app-ads-txt' ou similar
// Tenta via CPT padrão e via option direto
$adsTxtContent = "google.com, {$pubId}, DIRECT, f08c47fec0942fa0\n";

// Tenta como CPT 'ads-txt'
$cptResp = rest('GET', "{$wpUrl}/wp-json/wp/v2/types", [], $s['wp_user'], $s['wp_app_password']);
$cptSlug = null;
if (is_array($cptResp['body'])) {
    foreach (['ads-txt', 'ads_txt', 'app-ads-txt'] as $tryCpt) {
        if (isset($cptResp['body'][$tryCpt])) { $cptSlug = $tryCpt; break; }
    }
}

if ($cptSlug) {
    echo "Encontrado CPT: {$cptSlug}\n";
    // Cria/atualiza um post desse CPT com o conteúdo
    $listResp = rest('GET', "{$wpUrl}/wp-json/wp/v2/{$cptSlug}", [], $s['wp_user'], $s['wp_app_password']);
    $existId = is_array($listResp['body']) && !empty($listResp['body'][0]['id']) ? (int)$listResp['body'][0]['id'] : 0;
    if ($existId > 0) {
        $u = rest('POST', "{$wpUrl}/wp-json/wp/v2/{$cptSlug}/{$existId}",
            ['content' => $adsTxtContent, 'status' => 'publish'], $s['wp_user'], $s['wp_app_password']);
        echo "Update post #{$existId}: HTTP {$u['code']}\n";
    } else {
        $c = rest('POST', "{$wpUrl}/wp-json/wp/v2/{$cptSlug}",
            ['title' => 'ads.txt', 'content' => $adsTxtContent, 'status' => 'publish'], $s['wp_user'], $s['wp_app_password']);
        echo "Create post: HTTP {$c['code']}\n";
        if (is_array($c['body']) && !empty($c['body']['id'])) echo "  ID: " . $c['body']['id'] . "\n";
    }
}

// Valida via HTTP direto
sleep(2);
echo "\nValidando ads.txt acessível...\n";
$ch = curl_init("{$wpUrl}/ads.txt");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP {$code}\n";
if ($code === 200) {
    echo "Conteúdo: " . trim((string)$body) . "\n";
    echo "✓ ads.txt funcionando!\n";
} else {
    echo "⚠ Não acessível ainda — pode precisar visita admin pra ativar:\n";
    echo "   {$wpUrl}/wp-admin/options-general.php?page=adstxt-settings\n";
}
