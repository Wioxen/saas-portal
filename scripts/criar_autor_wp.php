<?php
/**
 * criar_autor_wp.php — cria wp_user com role Editor + perfil rico (bio, URL, sociais).
 *
 * Uso:
 *   php scripts/criar_autor_wp.php \
 *     --site=guiadoscursos \
 *     --username=ivan.alves \
 *     --display="Ivan Alves" \
 *     --email=contato@guiadoscursos.com \
 *     --url=https://www.linkedin.com/in/ivan-alves-05a185176/ \
 *     --bio="Bio do autor..."
 *
 * Idempotente: se username/email já existe, retorna ID existente sem erro.
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? '');
$username = (string)($args['username'] ?? '');
$display = (string)($args['display'] ?? '');
$email = (string)($args['email'] ?? '');
$url = (string)($args['url'] ?? '');
$bio = (string)($args['bio'] ?? '');

if ($siteSlug === '' || $username === '' || $email === '') {
    fwrite(STDERR, "uso: php criar_autor_wp.php --site=SLUG --username=ID --display='Nome' --email=X --url=Y --bio='...'\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode("{$cfg['wp_user']}:{$cfg['wp_app_password']}");

echo "═══ Criar autor WP — site={$siteSlug} ═══\n";
echo "username: {$username}\n";
echo "display:  {$display}\n";
echo "email:    {$email}\n";
echo "url:      {$url}\n\n";

// 1. Checa se username/email já existe
function wp_request(string $method, string $url, string $auth, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic {$auth}",
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$resp, true) ?: ['_raw' => $resp]];
}

echo "→ Verificando se usuário '{$username}' já existe...\n";
$check = wp_request('GET', "{$base}/users?search=" . urlencode($username) . "&per_page=20&context=edit", $auth);
$existente = null;
if ($check['code'] === 200 && is_array($check['body'])) {
    foreach ($check['body'] as $u) {
        if (($u['username'] ?? $u['slug'] ?? '') === $username) { $existente = $u; break; }
        if (($u['email'] ?? '') === $email) { $existente = $u; break; }
    }
}

if ($existente) {
    $userId = (int)($existente['id'] ?? 0);
    echo "⚠ Usuário já existe — ID #{$userId}. Atualizando perfil...\n";
} else {
    // 2. Cria usuário
    echo "→ Criando usuário novo...\n";
    $payload = [
        'username' => $username,
        'name' => $display,
        'first_name' => explode(' ', $display, 2)[0] ?? $display,
        'last_name' => explode(' ', $display, 2)[1] ?? '',
        'email' => $email,
        'password' => bin2hex(random_bytes(16)),
        'roles' => ['editor'],
        'url' => $url,
        'description' => $bio,
    ];
    $r = wp_request('POST', "{$base}/users", $auth, $payload);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $userId = (int)($r['body']['id'] ?? 0);
        echo "✓ Criado — ID #{$userId}\n";
    } else {
        fwrite(STDERR, "✗ Falha (HTTP {$r['code']}): " . json_encode($r['body'], JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
}

// 3. Atualiza perfil (idempotente — sempre garante bio/URL atualizados)
echo "→ Atualizando perfil (bio + URL + nome)...\n";
$updPayload = [
    'name' => $display,
    'description' => $bio,
    'url' => $url,
    'first_name' => explode(' ', $display, 2)[0] ?? $display,
    'last_name' => explode(' ', $display, 2)[1] ?? '',
];
$upd = wp_request('POST', "{$base}/users/{$userId}", $auth, $updPayload);
if ($upd['code'] >= 200 && $upd['code'] < 300) {
    echo "✓ Perfil atualizado\n";
} else {
    fwrite(STDERR, "⚠ Falha ao atualizar perfil: " . json_encode($upd['body']) . "\n");
}

echo "\n═══ RESUMO ═══\n";
echo "  user_id:   {$userId}\n";
echo "  username:  {$username}\n";
echo "  display:   {$display}\n";
echo "  url:       {$url}\n";
echo "\n  Próximos passos:\n";
echo "  1. Atualize sites.php: persona.autor = '{$display}' no site {$siteSlug}\n";
echo "  2. Configure default_post_author no gerador (sites.php campo `default_post_author_id` => {$userId})\n";
echo "  3. (Opcional) Definir display_name no wp-admin se for diferente\n";
