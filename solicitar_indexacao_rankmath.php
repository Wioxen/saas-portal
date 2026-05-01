<?php
/**
 * solicitar_indexacao_rankmath.php — usa endpoint Rank Math /in/submitUrls
 * pra pingar Google Indexing API direto (mais rápido que IndexNow pra Google).
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { exit(1); }
$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');

$csv = __DIR__ . "/data/redirects_{$slug}.csv";
$linhas = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($linhas);
$urls = [];
foreach ($linhas as $l) {
    $cols = str_getcsv($l);
    if (count($cols) < 6) continue;
    if (trim($cols[0]) !== '') $urls[] = $wpUrl . trim($cols[0]);
    if (trim($cols[1]) !== '' && !in_array($wpUrl . trim($cols[1]), $urls, true)) $urls[] = $wpUrl . trim($cols[1]);
}

echo "URLs pra submeter: " . count($urls) . "\n";

// Probe estado do Indexing API no Rank Math
$ch = curl_init("{$wpUrl}/wp-json/rankmath/v1/in/getLog");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $s['wp_user'].':'.$s['wp_app_password'],
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 10,
]);
$probeBody = curl_exec($ch);
$probeCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Probe /in/getLog HTTP: {$probeCode}\n";
if ($probeCode >= 400) {
    echo "Body: " . substr((string)$probeBody, 0, 300) . "\n";
}

// Tenta submitUrls — payload espera string com URLs separadas por \n
$ch = curl_init("{$wpUrl}/wp-json/rankmath/v1/in/submitUrls");
$payload = json_encode(['urls' => implode("\n", $urls), 'method' => 'publish']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_USERPWD => $s['wp_user'].':'.$s['wp_app_password'],
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nPOST /in/submitUrls HTTP: {$code}\n";
echo "Body: " . substr((string)$body, 0, 500) . "\n";
