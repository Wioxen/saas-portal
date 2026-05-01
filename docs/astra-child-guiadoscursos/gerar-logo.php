<?php
/**
 * Gera o logo do Guia dos Cursos em PNG alta resolução + SVG.
 * Renderiza em 2048px e downsample pra reduzir aliasing das bordas/letra.
 *
 * Uso: php gerar-logo.php
 * Saída: guiadoscursos-logo-{16,32,64,128,180,192,256,512,1024}.png + .svg
 */
declare(strict_types=1);

$baseDir = __DIR__;
$render  = 2048;             // canvas grande pra antialiasing perfeito
$radiusPct = 0.18;           // 18% — combina com o original (cantos arredondados)
$blue    = [30, 58, 138];    // #1e3a8a
$letter  = 'G';

/* Localiza fonte serif Bold disponível */
$fontCandidates = [
    'C:/Windows/Fonts/georgiab.ttf',   // Georgia Bold
    'C:/Windows/Fonts/timesbd.ttf',    // Times New Roman Bold
    'C:/Windows/Fonts/cambriab.ttf',   // Cambria Bold
    '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
];
$font = null;
foreach ($fontCandidates as $f) if (file_exists($f)) { $font = $f; break; }
if (!$font) { fwrite(STDERR, "Nenhuma fonte serif Bold encontrada\n"); exit(1); }
echo "Fonte: {$font}\n";

/* Função: retângulo arredondado */
function roundedRect($im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void {
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

/* Master canvas em alta resolução */
$im = imagecreatetruecolor($render, $render);
imagesavealpha($im, true);
$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $transparent);

$cBlue  = imagecolorallocate($im, $blue[0], $blue[1], $blue[2]);
$cWhite = imagecolorallocate($im, 255, 255, 255);

$radius = (int)($render * $radiusPct);
roundedRect($im, 0, 0, $render - 1, $render - 1, $radius, $cBlue);

/* Calcula tamanho da fonte e centraliza */
$fontSize = (int)($render * 0.58);
$bbox = imagettfbbox($fontSize, 0, $font, $letter);
$textW = $bbox[2] - $bbox[0];
$textH = abs($bbox[7]) + abs($bbox[1]);
$x = (int)(($render - $textW) / 2 - $bbox[0]);
$y = (int)(($render + $textH) / 2 - abs($bbox[1]) - $render * 0.02); // ajuste fino vertical

imagettftext($im, $fontSize, 0, $x, $y, $cWhite, $font, $letter);

/* Salva versões */
$sizes = [16, 32, 64, 128, 180, 192, 256, 512, 1024];
foreach ($sizes as $s) {
    $out = imagecreatetruecolor($s, $s);
    imagesavealpha($out, true);
    $tr = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefill($out, 0, 0, $tr);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $s, $s, $render, $render);
    $path = "{$baseDir}/guiadoscursos-logo-{$s}.png";
    imagepng($out, $path, 9);
    imagedestroy($out);
    echo "OK  {$s}x{$s}  →  guiadoscursos-logo-{$s}.png  (".filesize($path)." bytes)\n";
}
imagedestroy($im);

/* Salva SVG vetorizado (texto SVG renderizado pelo navegador) */
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img"><title>Guia dos Cursos</title>'.
       '<rect width="512" height="512" rx="92" ry="92" fill="#1e3a8a"/>'.
       '<text x="256" y="375" font-family="\'Georgia\', \'Merriweather\', \'Times New Roman\', serif" font-weight="900" font-size="350" fill="#ffffff" text-anchor="middle">G</text>'.
       '</svg>';
file_put_contents("{$baseDir}/guiadoscursos-logo.svg", $svg);
echo "OK  SVG          →  guiadoscursos-logo.svg  (".strlen($svg)." bytes)\n";

echo "\nPronto. Use guiadoscursos-logo-512.png pra subir em Aparência → Personalizar → Identidade do Site → Ícone do Site.\n";
