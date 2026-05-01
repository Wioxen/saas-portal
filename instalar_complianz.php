<?php
/**
 * Instala plugin Complianz GDPR/LGPD via /wp/v2/plugins.
 * Complianz é compliance LGPD completo, free, popular (2M+ instalações).
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { exit(1); }
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

echo "=== Instalando Complianz LGPD ({$slug}) ===\n";
echo "WP: {$wpUrl}\n\n";

$plg = 'complianz-gdpr/complianz-gpdr';
$check = rest('GET', "{$wpUrl}/wp-json/wp/v2/plugins/{$plg}", [], $s['wp_user'], $s['wp_app_password']);
if ($check['code'] === 200) {
    echo "Plugin já instalado · status: " . ($check['body']['status'] ?? '?') . "\n";
    if (($check['body']['status'] ?? '') !== 'active') {
        $a = rest('PUT', "{$wpUrl}/wp-json/wp/v2/plugins/{$plg}", ['status' => 'active'], $s['wp_user'], $s['wp_app_password']);
        echo "Ativando: HTTP {$a['code']}\n";
    }
} else {
    echo "Instalando complianz-gdpr do repositório WordPress.org...\n";
    $i = rest('POST', "{$wpUrl}/wp-json/wp/v2/plugins", ['slug' => 'complianz-gdpr', 'status' => 'active'], $s['wp_user'], $s['wp_app_password']);
    if ($i['code'] >= 200 && $i['code'] < 300) {
        echo "✓ Instalado e ativado\n";
    } else {
        $msg = is_array($i['body']) ? json_encode($i['body']) : $i['body'];
        echo "✗ HTTP {$i['code']}: " . mb_substr((string)$msg, 0, 300) . "\n";
        exit(1);
    }
}

echo "\n=== Complianz instalado ===\n";
echo "ATENÇÃO: precisa setup wizard manual (1 visita admin):\n";
echo "  {$wpUrl}/wp-admin/admin.php?page=cmplz-wizard\n\n";
echo "No wizard, marque:\n";
echo "  - Region: Brazil (LGPD)\n";
echo "  - Cookies marketing/advertising: yes (pra AdSense)\n";
echo "  - Pode pular setup avançado e finalizar\n";
echo "Tempo: ~3 minutos.\n\n";
echo "Após setup, banner aparece no front-end e AdSense detecta.\n";
