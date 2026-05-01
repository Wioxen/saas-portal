<?php
/**
 * Teste: descobrir onde o category_id entra nos RPC args do i0OFE.
 * Tenta vários shapes comuns do Google.
 */

function testar(array $rpcArgs, string $label): array
{
    $geo = 'BR';
    $rpcArgsJson = json_encode($rpcArgs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fReq = json_encode([[
        ["i0OFE", $rpcArgsJson, null, "generic"]
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $url = 'https://trends.google.com.br/_/TrendsUi/data/batchexecute?rpcids=i0OFE&source-path=%2Ftrending&f.sid=-1&bl=boq_trends-frontend-ui_20241021.06_p0&hl=pt-BR&gl=' . $geo . '&soc-app=162&soc-platform=1&soc-device=1&_reqid=' . rand(100000, 999999) . '&rt=c';
    $body = 'f.req=' . urlencode($fReq);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Origin: https://trends.google.com.br',
            'Referer: https://trends.google.com.br/trending?geo=' . $geo . '&category=3',
            'X-Same-Domain: 1',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $code >= 400) return ['ok' => false, 'termos' => [], 'raw' => substr((string)$resp, 0, 200)];

    // Parse
    $body = preg_replace('/^\)\]\}\'\s*/', '', $resp);
    preg_match('/\["wrb\.fr","i0OFE","(.+?)",null,null,null,"generic"\]/s', $body, $m);
    $innerRaw = $m[1] ?? null;
    if (!$innerRaw) return ['ok' => false, 'termos' => [], 'raw' => substr($body, 0, 300)];

    // Unescape JSON string
    $inner = json_decode('"' . $innerRaw . '"');
    $decoded = json_decode($inner, true);
    if (!is_array($decoded)) return ['ok' => false, 'termos' => [], 'raw' => substr($inner, 0, 300)];

    // Extrai termos (posição [1] de cada trend)
    $termos = [];
    $list = $decoded[1] ?? $decoded[0][1] ?? [];
    foreach ($list as $t) {
        if (is_array($t) && isset($t[0])) {
            $termos[] = is_array($t[0]) ? ($t[0][0] ?? '?') : $t[0];
        }
        if (count($termos) >= 5) break;
    }
    return ['ok' => true, 'termos' => $termos];
}

// Shapes a testar — category=3 (Negócios) deveria trazer trends financeiros
$shapes = [
    'baseline-null'         => [null, null, 'BR', 0, null, 168],
    'pos4-int'              => [null, null, 'BR', 0, 3, 168],
    'pos4-string'           => [null, null, 'BR', 0, '3', 168],
    'pos4-arr'              => [null, null, 'BR', 0, [3], 168],
    'pos4-double-arr'       => [null, null, 'BR', 0, [[3]], 168],
    'pos1-int'              => [3, null, 'BR', 0, null, 168],
    'pos1-arr'              => [[3], null, 'BR', 0, null, 168],
    'extra-pos6'            => [null, null, 'BR', 0, null, 168, 3],
    'extra-pos6-arr'        => [null, null, 'BR', 0, null, 168, [3]],
];

foreach ($shapes as $label => $args) {
    $r = testar($args, $label);
    $status = $r['ok'] ? 'OK ' : 'ERR';
    $amostra = $r['ok'] ? implode(' | ', $r['termos']) : substr((string)($r['raw'] ?? ''), 0, 100);
    echo "[$status] " . str_pad($label, 22) . " → " . $amostra . "\n";
    sleep(1);
}
