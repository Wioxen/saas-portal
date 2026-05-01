<?php
/**
 * probe_rest.php — sonda TODAS as rotas REST disponíveis no WP target.
 * Procura: rank-math, redirections, file manager, snippets, anything writeable.
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { fwrite(STDERR, "Uso: php probe_rest.php <slug>\n"); exit(1); }
$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');

$ch = curl_init("{$wpUrl}/wp-json/");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $s['wp_user'] . ':' . $s['wp_app_password'],
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 15,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) { echo "HTTP {$code}\n"; exit(1); }
$json = json_decode($body, true);
if (!is_array($json) || empty($json['routes'])) { echo "Sem routes na resposta\n"; exit(1); }

echo "=== TODAS as rotas REST de {$wpUrl} ===\n\n";
$routes = array_keys($json['routes']);
sort($routes);

// Filtros úteis
$rankmath = array_filter($routes, fn($r) => stripos($r, 'rank') !== false);
$redirect = array_filter($routes, fn($r) => stripos($r, 'redirect') !== false);
$file     = array_filter($routes, fn($r) => stripos($r, 'file') !== false);
$snippets = array_filter($routes, fn($r) => stripos($r, 'snippet') !== false || stripos($r, 'code') !== false);
$plugins  = array_filter($routes, fn($r) => stripos($r, 'plugin') !== false);
$custom   = array_filter($routes, fn($r) => stripos($r, 'clonais') !== false);

echo "Total de rotas: " . count($routes) . "\n\n";
echo "--- Rank Math: " . count($rankmath) . " ---\n";
foreach ($rankmath as $r) echo "  {$r}\n";
echo "\n--- Redirect: " . count($redirect) . " ---\n";
foreach ($redirect as $r) echo "  {$r}\n";
echo "\n--- File manager: " . count($file) . " ---\n";
foreach ($file as $r) echo "  {$r}\n";
echo "\n--- Code snippets: " . count($snippets) . " ---\n";
foreach ($snippets as $r) echo "  {$r}\n";
echo "\n--- Plugins management: " . count($plugins) . " ---\n";
foreach ($plugins as $r) echo "  {$r}\n";
echo "\n--- Custom Clonais: " . count($custom) . " ---\n";
foreach ($custom as $r) echo "  {$r}\n";
