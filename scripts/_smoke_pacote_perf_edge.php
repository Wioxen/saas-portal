<?php
/**
 * Smoke: PACOTE PERF EDGE (Resource Hints + Cloudflare Purge defensivo)
 *  - DiscoverResourceHints detecta CDNs externas + emite preconnect/dns-prefetch
 *  - CloudflareCachePurge no-op silencioso sem token
 *  - Wordpress::atualizarPost aceita 3º param cfg pra purge automática
 *  - Wires em Title/P1/Meta swappers
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/DiscoverResourceHints.php';
require_once $ROOT . '/lib/CloudflareCachePurge.php';

$ok = 0; $fail = 0;
function check(string $nome, $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [OK]   {$nome}\n"; }
    else       { $fail++; echo "  [FAIL] {$nome}\n"; }
}

// ════════════════════════════════════════════════════════════
echo "\n=== 1: DiscoverResourceHints ===\n";

// Cenário 1: HTML com Pexels + Amazon + Google Fonts → 3 hints
$html1 = '<p>Veja foto: <img src="https://images.pexels.com/photos/123/foo.jpg"></p>
<p>Compre: <a href="https://amazon.com.br/dp/X1">Amazon</a></p>
<p>Font: <link href="https://fonts.googleapis.com/css?family=Open+Sans"></p>';
$h1 = DiscoverResourceHints::detectarHints($html1);
check('detecta Pexels',         isset($h1['images.pexels.com']));
check('detecta Amazon BR',      isset($h1['amazon.com.br']));
check('detecta Google Fonts',   isset($h1['fonts.googleapis.com']));
check('Pexels é preconnect',    ($h1['images.pexels.com']['type'] ?? '') === 'preconnect');
check('Amazon é dns-prefetch',  ($h1['amazon.com.br']['type'] ?? '') === 'dns-prefetch');

$resultado = DiscoverResourceHints::aplicar($html1);
check('injeta link rel=preconnect',     strpos($resultado, 'rel="preconnect"') !== false);
check('injeta link rel=dns-prefetch',   strpos($resultado, 'rel="dns-prefetch"') !== false);
check('preconnect tem crossorigin',     preg_match('/preconnect[^>]*crossorigin/', $resultado) === 1);
check('marker idempotência presente',   strpos($resultado, 'data-cc-resource-hints') !== false);

// Idempotência
$resultado2 = DiscoverResourceHints::aplicar($resultado);
check('idempotente: 2ª chamada não duplica', $resultado === $resultado2);

// Cenário 2: HTML sem domínios conhecidos → no-op
$html2 = '<p>Texto puro sem links externos relevantes.</p>';
$noop = DiscoverResourceHints::aplicar($html2);
check('sem domínios conhecidos → HTML inalterado', $noop === $html2);

// Cenário 3: HTML com domínio aleatório (não na whitelist) → ignorado
$html3 = '<a href="https://random-blog.example.com/post">link</a>';
$h3 = DiscoverResourceHints::detectarHints($html3);
check('domínio fora da whitelist → ignorado', empty($h3));

// Cenário 4: cap em 8 hints (não polui)
$bigHtml = '';
$bigHtml .= 'https://amazon.com.br/x https://amzn.to/y https://magazineluiza.com.br/p ';
$bigHtml .= 'https://shopee.com.br/x https://shope.ee/y https://produto.mercadolivre.com.br/x ';
$bigHtml .= 'https://images.pexels.com/x https://fonts.googleapis.com/x https://fonts.gstatic.com/x ';
$bigHtml .= 'https://www.googletagmanager.com/x https://cloudinary.com/x';
$hBig = DiscoverResourceHints::detectarHints($bigHtml);
check('cap em ≤8 hints', count($hBig) <= 8);

// ════════════════════════════════════════════════════════════
echo "\n=== 2: CloudflareCachePurge defensivo ===\n";

// Sem token → no-op silencioso (success, mas purged=0)
putenv('CLOUDFLARE_API_TOKEN=');
$r1 = CloudflareCachePurge::purgeUrl(['cloudflare_zone_id' => 'abc'], 'https://meu-site.com/post-x');
check('sem token → ok=true (no-op)',  ($r1['ok'] ?? false) === true);
check('sem token → purged=0',         ($r1['purged'] ?? -1) === 0);
check('sem token → motivo claro',     strpos((string)($r1['motivo'] ?? ''), 'sem CLOUDFLARE_API_TOKEN') !== false);

// Sem zone_id → no-op silencioso
putenv('CLOUDFLARE_API_TOKEN=fake-token-pra-teste');
$r2 = CloudflareCachePurge::purgeUrl([], 'https://meu-site.com/post-y');
check('sem zone_id → ok=true (no-op)',  ($r2['ok'] ?? false) === true);
check('sem zone_id → purged=0',         ($r2['purged'] ?? -1) === 0);
check('sem zone_id → motivo claro',     strpos((string)($r2['motivo'] ?? ''), 'sem cloudflare_zone_id') !== false);

// configurado() reflete corretamente
putenv('CLOUDFLARE_API_TOKEN=');
check('configurado() = false sem token',  CloudflareCachePurge::configurado(['cloudflare_zone_id' => 'x']) === false);
putenv('CLOUDFLARE_API_TOKEN=fake');
check('configurado() = false sem zone_id',CloudflareCachePurge::configurado([]) === false);
check('configurado() = true com ambos',   CloudflareCachePurge::configurado(['cloudflare_zone_id' => 'abc']) === true);

// URLs vazias → ok mesmo configurado
$r3 = CloudflareCachePurge::purgeUrls(['cloudflare_zone_id' => 'abc'], []);
check('lista vazia → ok=true',         ($r3['ok'] ?? false) === true);
check('lista vazia → purged=0',        ($r3['purged'] ?? -1) === 0);

// Cleanup
putenv('CLOUDFLARE_API_TOKEN=');

// ════════════════════════════════════════════════════════════
echo "\n=== 3: Wordpress::atualizarPost aceita cfg pra purge ===\n";

$wpSrc = file_get_contents($ROOT . '/lib/Wordpress.php');
check('Wordpress.php require CloudflareCachePurge dinâmico', strpos($wpSrc, 'CloudflareCachePurge') !== false);
check('atualizarPost aceita 3º param $cfgPurge',             strpos($wpSrc, '$cfgPurge = []') !== false);
check('purgeIfConfigured private method existe',             strpos($wpSrc, 'purgeIfConfigured') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== 4: Swappers passam cfg pro purge ===\n";

$ts = file_get_contents($ROOT . '/lib/DiscoverTitleSwapper.php');
check('TitleSwapper passa $cfg em atualizarPost',
    preg_match("/atualizarPost\([^)]*\['title'[^)]*\],\s*\\\$cfg\)/", $ts) === 1);

$ps = file_get_contents($ROOT . '/lib/DiscoverP1Swapper.php');
check('P1Swapper passa $cfg em atualizarPost',
    preg_match("/atualizarPost\([^)]*\['content'[^)]*\],\s*\\\$cfg\)/", $ps) === 1);

$ms = file_get_contents($ROOT . '/lib/DiscoverMetaSwapper.php');
check('MetaSwapper passa $cfg pra MetaTags::aplicarNoWp',
    preg_match("/aplicarNoWp\(\s*\\\$wp,\s*\\\$postId,\s*\\\$novaTags,\s*\\\$cfg\)/", $ms) === 1);

$mt = file_get_contents($ROOT . '/lib/DiscoverMetaTags.php');
check('MetaTags::aplicarNoWp aceita $cfg', strpos($mt, 'aplicarNoWp($wp, int $postId, array $tags, array $cfg = [])') !== false);
check('MetaTags::aplicarNoWp passa cfg pro WP atualizarPost',
    preg_match("/atualizarPost\([^)]*\\\$meta[^)]*\],\s*\\\$cfg\)/", $mt) === 1);

// ════════════════════════════════════════════════════════════
echo "\n=== 5: Wire em DiscoverPostProcess ===\n";

$pp = file_get_contents($ROOT . '/lib/DiscoverPostProcess.php');
check('PostProcess require DiscoverResourceHints',     strpos($pp, 'DiscoverResourceHints') !== false);
check('PostProcess chama DiscoverResourceHints::aplicar', strpos($pp, 'DiscoverResourceHints::aplicar') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== 6: .env.example + sites.php ===\n";

$env = file_get_contents($ROOT . '/.env.example');
check('.env tem CLOUDFLARE_API_TOKEN',  strpos($env, 'CLOUDFLARE_API_TOKEN') !== false);

$sitesSrc = file_get_contents($ROOT . '/sites.php');
check('sites.php tem cloudflare_zone_id', strpos($sitesSrc, 'cloudflare_zone_id') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n";
echo "  FAIL: {$fail}\n";

if ($fail > 0) { echo "\n[PERF EDGE] FAIL\n"; exit(1); }
echo "\n[PERF EDGE] OK\n";
exit(0);
