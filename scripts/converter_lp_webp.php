<?php
/**
 * Converter imagens da LP para WebP via api.gogleads.com.br
 *
 * Uso CLI:
 *   php scripts/converter_lp_webp.php <pasta-img>
 *
 * Default: lp/comocomprar/potes-vidro-hermeticos/img
 */

$dir = $argv[1] ?? __DIR__ . '/../lp/comocomprar/potes-vidro-hermeticos/img';
$dir = rtrim($dir, '/\\');

if (!is_dir($dir)) {
    fwrite(STDERR, "Pasta nao encontrada: $dir\n");
    exit(1);
}

function converterParaWebp(string $bin, string $nome): ?string {
    $tmp = tempnam(sys_get_temp_dir(), 'webp_');
    if ($tmp === false) return null;
    file_put_contents($tmp, $bin);

    $ch = curl_init('https://api.gogleads.com.br/Convert/image/webp');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($tmp, '', $nome)],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);

    if ($resp === false || $code >= 400 || $resp === '') {
        fwrite(STDERR, "  ERRO HTTP $code | ct=$ct | err=$err\n");
        return null;
    }

    if (stripos($ct, 'application/json') !== false) {
        $json = json_decode($resp, true);
        if (is_array($json)) {
            if (!empty($json['data']) && is_string($json['data'])) {
                $decoded = base64_decode($json['data'], true);
                return $decoded !== false ? $decoded : $json['data'];
            }
            if (!empty($json['url'])) {
                return file_get_contents($json['url']);
            }
        }
        return null;
    }
    return $resp;
}

$jpgs = glob($dir . '/*.jpg');
sort($jpgs);

if (!$jpgs) {
    fwrite(STDERR, "Nenhum .jpg encontrado em $dir\n");
    exit(1);
}

echo "Convertendo " . count($jpgs) . " imagens em $dir\n\n";

$totalJpg = 0;
$totalWebp = 0;
$ok = 0;
$fail = 0;

foreach ($jpgs as $jpg) {
    $base = pathinfo($jpg, PATHINFO_FILENAME);
    $webp = $dir . '/' . $base . '.webp';
    $bin = file_get_contents($jpg);
    $jpgSize = strlen($bin);
    $totalJpg += $jpgSize;

    echo "  $base.jpg (" . round($jpgSize/1024, 1) . " KB) -> ";

    $out = converterParaWebp($bin, basename($jpg));
    if ($out === null) {
        echo "FALHOU\n";
        $fail++;
        continue;
    }
    file_put_contents($webp, $out);
    $webpSize = strlen($out);
    $totalWebp += $webpSize;
    $delta = $jpgSize > 0 ? round((1 - $webpSize/$jpgSize) * 100, 1) : 0;
    echo "$base.webp (" . round($webpSize/1024, 1) . " KB) | -$delta%\n";
    $ok++;
}

echo "\n";
echo "Total: $ok ok | $fail falha(s)\n";
if ($totalJpg > 0) {
    echo "JPG total:  " . round($totalJpg/1024, 1) . " KB\n";
    echo "WebP total: " . round($totalWebp/1024, 1) . " KB\n";
    echo "Economia:   " . round((1 - $totalWebp/$totalJpg) * 100, 1) . "%\n";
}
