<?php
/**
 * Testa o novo RPC DqDTgb — descobrir shape real dos args e se a posição 3 é category.
 *
 * Payload capturado do DevTools:
 *   f.req=[[["DqDTgb","[\"pt\",1,0]",null,"generic"]]]
 *
 * 3 args: [locale, ?, category]. Testamos category=0 (baseline), 3 (Negócios), 11 (Esportes).
 */

function chamar(array $args, string $label): array
{
    $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fReq = json_encode([[
        ['DqDTgb', $argsJson, null, 'generic']
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $url = 'https://trends.google.com.br/_/TrendsUi/data/batchexecute'
         . '?rpcids=DqDTgb'
         . '&source-path=%2Ftrending'
         . '&f.sid=-1'
         . '&bl=boq_trends-boq-servers-frontend_20260421.02_p0'
         . '&hl=pt'
         . '&_reqid=' . rand(100000, 999999)
         . '&rt=c';

    $body = 'f.req=' . urlencode($fReq);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/147.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Accept: */*',
            'Accept-Language: pt-BR,pt;q=0.9',
            'Origin: https://trends.google.com.br',
            'Referer: https://trends.google.com.br/',
            'X-Same-Domain: 1',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $code >= 400) {
        return ['ok' => false, 'code' => $code, 'raw' => substr((string)$resp, 0, 300)];
    }

    // Strip XSSI prefix
    $clean = preg_replace('/^\)\]\}\'\s*/', '', $resp);

    // Extrai o JSON string do wrb.fr DqDTgb
    if (!preg_match('/\["wrb\.fr","DqDTgb","(.+?)",null,null,null,"generic"\]/s', $clean, $m)) {
        return ['ok' => false, 'code' => $code, 'raw' => substr($clean, 0, 400), 'err' => 'no wrb.fr match'];
    }
    $innerRaw = $m[1];
    // Unescape (o JSON do wrb.fr vem escapado como string)
    $inner = json_decode('"' . $innerRaw . '"');
    $decoded = json_decode($inner, true);

    if (!is_array($decoded)) {
        return ['ok' => false, 'code' => $code, 'raw' => substr((string)$inner, 0, 400), 'err' => 'decode fail'];
    }

    // Extrai termos — estrutura pode ter mudado, vou explorar
    $termos = [];
    pescar($decoded, $termos);
    return ['ok' => true, 'termos' => array_slice($termos, 0, 10), 'size' => strlen($inner)];
}

function pescar($node, array &$out, int $depth = 0): void
{
    if ($depth > 8 || count($out) >= 50) return;
    if (is_string($node)) {
        if (strlen($node) >= 3 && strlen($node) <= 80 && preg_match('/[a-zA-Zà-ÿÀ-Ÿ]/u', $node)
            && !preg_match('/^(generic|wrb\.fr|DqDTgb|pt|af\.httprm|di)$/', $node)
            && !preg_match('/^[\d\-+]+$/', $node)) {
            $out[] = $node;
        }
        return;
    }
    if (is_array($node)) {
        foreach ($node as $v) pescar($v, $out, $depth + 1);
    }
}

foreach ([
    'baseline [pt,1,0]'   => ['pt', 1, 0],
    'category=3 Negócios' => ['pt', 1, 3],
    'category=11 Esportes' => ['pt', 1, 11],
    'category=15 Leis/gov' => ['pt', 1, 15],
    'category=20 Saúde'    => ['pt', 1, 20],
] as $label => $args) {
    $r = chamar($args, $label);
    if (!$r['ok']) {
        echo "[FAIL] $label → code={$r['code']} " . ($r['err'] ?? '') . "\n  raw: " . ($r['raw'] ?? '') . "\n";
    } else {
        echo "[OK]   $label (size=" . $r['size'] . ")\n";
        foreach (array_slice($r['termos'], 0, 8) as $t) {
            echo "       · " . mb_substr($t, 0, 60, 'UTF-8') . "\n";
        }
    }
    echo "\n";
    sleep(1);
}
