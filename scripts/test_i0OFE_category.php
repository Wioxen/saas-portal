<?php
/**
 * Testa i0OFE com category em várias posições dos args.
 * Args atuais: [null, null, geo, sort, null, hours]
 * Vamos tentar category em cada slot.
 */

function chamar(array $args, string $label): array
{
    $argsJson = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fReq = json_encode([[
        ['i0OFE', $argsJson, null, 'generic']
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $url = 'https://trends.google.com.br/_/TrendsUi/data/batchexecute'
         . '?rpcids=i0OFE'
         . '&source-path=%2Ftrending'
         . '&f.sid=-1'
         . '&bl=boq_trends-boq-servers-frontend_20260421.02_p0'
         . '&hl=pt'
         . '&gl=BR'
         . '&soc-app=162&soc-platform=1&soc-device=1'
         . '&_reqid=' . rand(100000, 999999)
         . '&rt=c';

    $body = 'f.req=' . urlencode($fReq);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/147.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Origin: https://trends.google.com.br',
            'Referer: https://trends.google.com.br/trending?geo=BR',
            'X-Same-Domain: 1',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $code >= 400) return ['ok' => false, 'raw' => substr((string)$resp, 0, 200)];
    $body = preg_replace('/^\)\]\}\'\s*/', '', $resp);
    if (!preg_match('/\["wrb\.fr","i0OFE","(.+?)",null,null,null,"generic"\]/s', $body, $m)) {
        return ['ok' => false, 'raw' => substr($body, 0, 300)];
    }
    $inner = json_decode('"' . $m[1] . '"');
    $decoded = json_decode($inner, true);
    if (!is_array($decoded)) return ['ok' => false, 'err' => 'decode'];
    $list = $decoded[1] ?? [];
    $termos = [];
    foreach ($list as $t) {
        if (is_array($t) && isset($t[0])) $termos[] = [$t[0], $t[10] ?? null, $t[6] ?? null];
        if (count($termos) >= 10) break;
    }
    return ['ok' => true, 'termos' => $termos, 'total' => count($list)];
}

$shapes = [
    'baseline'         => [null, null, 'BR', 0, null, 168],
    'pos4=9'           => [null, null, 'BR', 0, 9, 168],    // cat em pos 4 (era null)
    'pos1=9'           => [9, null, 'BR', 0, null, 168],
    'pos1=[9]'         => [[9], null, 'BR', 0, null, 168],
    'pos4=[9]'         => [null, null, 'BR', 0, [9], 168],
    'pos6=9'           => [null, null, 'BR', 0, null, 168, 9],
    'pos7=9'           => [null, null, 'BR', 0, null, 168, null, 9],
];

foreach ($shapes as $label => $args) {
    $r = chamar($args, $label);
    echo "\n=== $label ===\n";
    if (!$r['ok']) {
        echo "FAIL: " . substr($r['raw'] ?? $r['err'] ?? '', 0, 150) . "\n";
        continue;
    }
    echo "total trends: {$r['total']}\n";
    foreach ($r['termos'] as $t) {
        $cats = is_array($t[1]) ? implode(',', $t[1]) : '?';
        echo "  [$cats] vol={$t[2]} → {$t[0]}\n";
    }
    sleep(1);
}
