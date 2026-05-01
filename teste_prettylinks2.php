<?php
require_once __DIR__ . '/lib/PrettyLinks.php';
$cfg = require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== Teste PHP PrettyLinks ===\n\n";

$pl = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

echo "Base URL: {$cfg['wp_url']}/wp-json/cc/v1\n";
echo "User: {$cfg['wp_user']}\n";
echo "Pass: " . substr($cfg['wp_app_password'], 0, 8) . "...\n\n";

// Teste direto com curl pra ver resposta
$url = rtrim($cfg['wp_url'], '/') . '/wp-json/cc/v1/pretty-link';
$auth = base64_encode($cfg['wp_user'] . ':' . $cfg['wp_app_password']);
$payload = json_encode(['target_url' => 'https://amazon.com.br/dp/teste-php', 'slug' => 'go/teste-php', 'name' => 'Teste PHP']);

echo "1. Chamando: POST {$url}\n";
echo "   Auth: Basic {$auth}\n\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_VERBOSE => true,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "   HTTP Code: {$code}\n";
echo "   cURL Error: " . ($err ?: 'nenhum') . "\n";
echo "   Effective URL: {$info['url']}\n";
echo "   Redirect URL: " . ($info['redirect_url'] ?: 'nenhum') . "\n";
echo "   Resposta: {$resp}\n\n";

// Agora via classe
echo "2. Via PrettyLinks::criar()...\n";
$result = $pl->criar('https://amazon.com.br/dp/teste-classe', 'go/teste-classe', 'Teste Classe');
echo "   Resultado: " . ($result ?: 'NULL') . "\n\n";

echo "Fim.\n";
