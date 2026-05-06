<?php
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
aplicarSite($cfg, sitesDisponiveis(), 'cursosenac');
$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode($cfg['wp_user'] . ':' . $cfg['wp_app_password']);

$ch = curl_init($base . '/posts/5091?_fields=content&context=edit');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
    CURLOPT_TIMEOUT => 30,
]);
$post = json_decode((string)curl_exec($ch), true);
curl_close($ch);
$h = (string)($post['content']['rendered'] ?? '');

$novaLegenda = 'Aplicação dos recursos do Fundeb na manutenção de espaços públicos urbanos · Crédito: pexels.com';
$novoAlt = 'Aplicação dos recursos do Fundeb em manutenção urbana';

$h = preg_replace(
    '#<figcaption([^>]*)>[^<]+</figcaption>#i',
    '<figcaption$1>' . htmlspecialchars($novaLegenda, ENT_QUOTES) . '</figcaption>',
    $h,
    1
);
$h = preg_replace(
    '#(<img[^>]*alt=["\'])[^"\']+(["\'])#i',
    '$1' . $novoAlt . '$2',
    $h,
    1
);

$ch = curl_init($base . '/posts/5091');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['content' => $h]),
    CURLOPT_TIMEOUT => 30,
]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "WP HTTP: $code\n";
