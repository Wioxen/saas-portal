<?php
/**
 * probe_htaccess.php — testa se .htaccess pode ser lido/editado via REST.
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { exit(1); }
$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');
$user = $s['wp_user'];
$pass = $s['wp_app_password'];

function rest($method, $url, $payload, $user, $pass) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => "{$user}:{$pass}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if (in_array(strtoupper($method), ['POST', 'PUT'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

echo "=== TESTE 1: .htaccess exposto via HTTP direto? ===\n";
$ch = curl_init("{$wpUrl}/.htaccess");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "  GET /.htaccess → HTTP {$code}\n";
if ($code === 200 && !empty($body)) {
    echo "  CONTEÚDO RECEBIDO ({$code} chars):\n";
    echo "  " . str_replace("\n", "\n  ", substr($body, 0, 500)) . "...\n";
} else {
    echo "  Não exposto (esperado).\n";
}

echo "\n=== TESTE 2: Rank Math toolsAction (várias variações) ===\n";
$actions = [
    'edit_htaccess', 'editHtaccess', 'getHtaccess', 'get_htaccess',
    'read_htaccess', 'load_htaccess', 'htaccess', 'htaccess_read',
    'editor', 'tools', 'file_editor',
];
foreach ($actions as $action) {
    $r = rest('POST', "{$wpUrl}/wp-json/rankmath/v1/toolsAction", ['action' => $action], $user, $pass);
    if ($r['code'] !== 400 || !preg_match('/Invalid action|action n[ãa]o/i', (string)$r['body'])) {
        echo "  POST action='{$action}' → HTTP {$r['code']}";
        if (strlen((string)$r['body']) < 300) echo " · " . trim((string)$r['body']);
        echo "\n";
    }
}

echo "\n=== TESTE 3: Endpoints alternativos pra .htaccess ===\n";
$paths = [
    '/wp-json/rankmath/v1/htaccess',
    '/wp-json/rankmath/v1/file/htaccess',
    '/wp-json/wp/v2/file/.htaccess',
    '/wp-json/wp-htaccess/v1/get',
];
foreach ($paths as $p) {
    $r = rest('GET', "{$wpUrl}{$p}", [], $user, $pass);
    if ($r['code'] !== 404) {
        echo "  GET {$p} → HTTP {$r['code']}\n";
    }
}

echo "\n=== TESTE 4: Plugins .htaccess editor instalados? ===\n";
$plugins = ['htaccess-editor/htaccess-editor', 'wp-htaccess-editor/wp-htaccess-editor', 'better-htaccess/better-htaccess'];
foreach ($plugins as $p) {
    $r = rest('GET', "{$wpUrl}/wp-json/wp/v2/plugins/{$p}", [], $user, $pass);
    if ($r['code'] === 200) echo "  ✓ Instalado: {$p}\n";
}

echo "\nFim do probe.\n";
