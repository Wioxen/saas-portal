<?php
/**
 * Smoke ROI extra — A (WebP) + B (lazy load) + C (Amazon tag).
 */

declare(strict_types=1);
$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/DiscoverImagemPerformance.php';
require_once $rootDir . '/lib/DiscoverAmazonTag.php';
require_once $rootDir . '/lib/ImagemOptimizer.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== A: WebP fallback nativo via GD ===\n";
$opt = new ImagemOptimizer();

// Cria imagem PNG mínima em memória (10x10 vermelho)
$img = imagecreatetruecolor(10, 10);
imagefilledrectangle($img, 0, 0, 9, 9, imagecolorallocate($img, 255, 0, 0));
ob_start(); imagepng($img); $pngBin = ob_get_clean();
imagedestroy($img);

// Tenta converter pra WebP
$webp = $opt->reencode($pngBin, 'webp', 82);
if (function_exists('imagewebp')) {
    check("ImagemOptimizer reencode → WebP via GD",
        $webp !== null && substr((string)$webp, 8, 4) === 'WEBP',
        'len=' . (is_string($webp) ? strlen($webp) : 'null'));
} else {
    check("imagewebp não disponível neste PHP — fallback gracioso", $webp === null);
}

// Wordpress.php agora chama 'webp' antes de JPEG (smoke regex no source)
$srcWp = file_get_contents($rootDir . '/lib/Wordpress.php');
check("Wordpress.php tenta WebP via GD antes de JPEG fallback",
    strpos($srcWp, "\$opt->reencode(\$bin, 'webp'") !== false);
check("Wordpress.php fallback final ainda é JPEG",
    strpos($srcWp, "\$opt->reencode(\$bin, 'jpeg'") !== false);

// ─────────────────────────────────────────────
echo "\n=== B: DiscoverImagemPerformance (lazy + fetchpriority) ===\n";
$html = '<h1>Título</h1>'
     . '<img src="img1.jpg" alt="primeira">'
     . '<p>Texto</p>'
     . '<img src="img2.jpg" alt="segunda">'
     . '<p>Mais</p>'
     . '<img src="img3.jpg" alt="terceira">';

$out = DiscoverImagemPerformance::otimizar($html);
check("HTML modificado (cresceu)", strlen($out) > strlen($html));

// 1ª imagem: fetchpriority=high + loading=eager + decoding=sync
preg_match_all('#<img[^>]*>#', $out, $matches);
$imgs = $matches[0];
check("3 imgs ainda presentes", count($imgs) === 3);

check("img 1: fetchpriority=high",
    strpos($imgs[0], 'fetchpriority="high"') !== false,
    'img1=' . $imgs[0]);
check("img 1: loading=eager", strpos($imgs[0], 'loading="eager"') !== false);
check("img 1: decoding=sync", strpos($imgs[0], 'decoding="sync"') !== false);

check("img 2: loading=lazy", strpos($imgs[1], 'loading="lazy"') !== false);
check("img 2: decoding=async", strpos($imgs[1], 'decoding="async"') !== false);
check("img 2: NÃO tem fetchpriority=high",
    strpos($imgs[1], 'fetchpriority="high"') === false);

check("img 3: loading=lazy", strpos($imgs[2], 'loading="lazy"') !== false);

// Idempotência
$out2 = DiscoverImagemPerformance::otimizar($out);
preg_match_all('#data-perf-opt#', $out2, $m2);
check("idempotência: marker NÃO duplica",
    count($m2[0]) === count($imgs),
    'count=' . count($m2[0]));

// HTML sem img → no-op
check("HTML sem <img>: inalterado",
    DiscoverImagemPerformance::otimizar('<p>sem img</p>') === '<p>sem img</p>');

// Loading explícito não é sobrescrito pra LAZY (mas 1ª ainda recebe priority hints)
$htmlComLoading = '<img src="x.jpg" loading="eager"><img src="y.jpg" loading="eager">';
$outComLoading = DiscoverImagemPerformance::otimizar($htmlComLoading);
check("loading=eager preservado em img 2 (não força lazy)",
    substr_count($outComLoading, 'loading="eager"') === 2);

// ─────────────────────────────────────────────
echo "\n=== C: DiscoverAmazonTag ===\n";

// Sem tag → no-op
$h1 = '<a href="https://amazon.com.br/dp/B0CXYZ123">Produto</a>';
check("sem tag: HTML inalterado",
    DiscoverAmazonTag::aplicar($h1, '', 0) === $h1);

// Com tag base + post_id → injeta tag-{postId}
$h2 = DiscoverAmazonTag::aplicar(
    '<p>Compre em <a href="https://amazon.com.br/dp/B0XYZ123">link</a></p>',
    'meutag',
    1234
);
check("injeta ?tag=meutag-1234",
    strpos($h2, 'tag=meutag-1234') !== false,
    'out=' . substr($h2, 0, 200));

// Idempotência: URL com ?tag= já existente NÃO é modificada
$h3 = '<a href="https://amazon.com.br/dp/X?tag=outratag">link</a>';
check("URL com ?tag= existente não modificada",
    DiscoverAmazonTag::aplicar($h3, 'meutag', 5) === $h3);

// amzn.to é skipado (tag controlada na config Amazon)
$h4 = DiscoverAmazonTag::aplicar(
    '<a href="https://amzn.to/4ckOgUc">link</a>',
    'meutag',
    99
);
check("amzn.to skipado (não duplica tag)",
    strpos($h4, 'tag=') === false);

// URL com query existente: usa &tag=
$h5 = DiscoverAmazonTag::aplicar(
    '<a href="https://amazon.com.br/dp/X?ref=foo">link</a>',
    'meutag',
    7
);
check("URL com query: anexa &tag",
    strpos($h5, '&tag=meutag-7') !== false);

// Múltiplos URLs no HTML
$h6 = DiscoverAmazonTag::aplicar(
    '<p><a href="https://amazon.com.br/dp/A">a</a> e <a href="https://amazon.com.br/dp/B">b</a></p>',
    'meutag', 1
);
check("múltiplos URLs: ambos recebem tag",
    substr_count($h6, 'tag=meutag-1') === 2);

// Tag sem post_id (genérico) — só base
$h7 = DiscoverAmazonTag::aplicar(
    '<a href="https://amazon.com.br/dp/X">link</a>',
    'meutag', 0
);
check("post_id=0: usa só tag base sem sufixo",
    strpos($h7, 'tag=meutag&') === false &&
    strpos($h7, 'tag=meutag') !== false);

// ─────────────────────────────────────────────
echo "\n=== Wire em DiscoverPostProcess ===\n";
$srcPp = file_get_contents($rootDir . '/lib/DiscoverPostProcess.php');
check("PostProcess require DiscoverImagemPerformance",
    strpos($srcPp, 'DiscoverImagemPerformance.php') !== false);
check("PostProcess chama DiscoverImagemPerformance::otimizar",
    strpos($srcPp, 'DiscoverImagemPerformance::otimizar') !== false);
// AmazonTag foi superseded por AfiliadoBR (cobre Amazon + Magalu + ML + Shopee)
check("PostProcess require DiscoverAfiliadoBR (Amazon + 3 redes BR)",
    strpos($srcPp, 'DiscoverAfiliadoBR.php') !== false);
check("PostProcess chama DiscoverAfiliadoBR::aplicar",
    strpos($srcPp, 'DiscoverAfiliadoBR::aplicar') !== false);

// ─────────────────────────────────────────────
echo "\n=== sites.php template campos ===\n";
$sites = require $rootDir . '/sites.php';
check("comocomprar tem amazon_associates_tag", isset($sites['comocomprar']['amazon_associates_tag']));
check("ondecompraragora tem amazon_associates_tag", isset($sites['ondecompraragora']['amazon_associates_tag']));

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[ROI EXTRA] OK\n" : "\n[ROI EXTRA] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
