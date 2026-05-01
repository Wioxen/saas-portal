<?php
/**
 * [TESTE] Valida DiscoverImagemViral::variante1080x1350 — gera variante 4:5 pra IG Feed.
 *
 * Uso:
 *   php scripts/_testar_ig_variante.php <url-imagem-16x9>
 *
 * Salva resultado em /tmp/ig_variante_NNNN.jpg pra inspeção visual.
 */

require_once __DIR__ . '/../lib/DiscoverImagemViral.php';

$url = $argv[1] ?? '';
if ($url === '') {
    fwrite(STDERR, "Uso: php scripts/_testar_ig_variante.php <url-imagem>\n");
    exit(1);
}

$ini = microtime(true);
$bytes = DiscoverImagemViral::variante1080x1350($url);
$dur = round((microtime(true) - $ini) * 1000);

if ($bytes === null) {
    fwrite(STDERR, "FALHA — variante1080x1350 retornou null. Verifique GD instalada e URL acessível.\n");
    exit(2);
}

$path = sys_get_temp_dir() . '/ig_variante_' . time() . '.jpg';
@file_put_contents($path, $bytes);

$dim = @getimagesize($path);
echo "OK — {$dur}ms · " . round(strlen($bytes) / 1024, 1) . " KB · {$dim[0]}x{$dim[1]} · {$path}\n";
echo "Aspect ratio: " . round($dim[0] / $dim[1], 3) . " (esperado 0.800 = 4:5)\n";
