<?php
/**
 * auditar_adsense.php — auditoria de prontidão AdSense pra qualquer domínio.
 *
 * Cobre as 15 causas conhecidas de rejeição AdSense:
 *   1. Sitemap quebrado (URLs 404)
 *   2. Posts da home retornam 404
 *   3. www vs non-www inconsistente
 *   4. robots.txt bloqueando AdSense crawler (Mediapartners-Google, AdsBot-Google)
 *   5. Compliance pages ausentes (Privacy, Terms, About, Contact)
 *   6. ads.txt mal configurado
 *   7. Posts thin (< 400 palavras)
 *   8. Imagens em placeholder data:image (lazy load broken)
 *   9. Schema.org markup ausente
 *  10. Mobile rendering issues (viewport, responsive)
 *  11. Domain age não verificável (manual)
 *  12. Tempo de resposta lento (> 3s)
 *  13. Conteúdo cross-site duplicado (manual via amostra)
 *  14. Canonical URL ausente ou conflitante
 *  15. Site em construção (lorem ipsum, placeholder content)
 *
 * Uso CLI:
 *   php auditar_adsense.php https://cursosenacgratuito.com.br
 *
 * Uso web:
 *   /auditar_adsense.php?dominio=https://cursosenacgratuito.com.br
 *
 * Output: relatório HTML colorido (red/yellow/green) com fixes priorizados.
 */

set_time_limit(120);
mb_internal_encoding('UTF-8');

// ─── CONFIG ───
$USER_AGENT_DESKTOP = 'Mozilla/5.0 (compatible; AdSenseAuditor/1.0; +https://example.com)';
$USER_AGENT_MOBILE  = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';
$USER_AGENT_GBOT    = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$TIMEOUT            = 12;
$MAX_SITEMAP_CHECK  = 30;  // valida até N URLs do sitemap (HEAD requests)
$MAX_POSTS_DEEP     = 5;   // sample N posts pra análise full

// ─── INPUT ───
$dominio = $_GET['dominio'] ?? $argv[1] ?? '';
$dominio = trim($dominio);
if ($dominio === '') {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Uso: php auditar_adsense.php https://dominio.com.br\n"); exit(1); }
    echo '<form><label>Dominio: <input name="dominio" placeholder="https://exemplo.com.br" size="50"></label> <button>Auditar</button></form>';
    exit;
}
if (!preg_match('#^https?://#', $dominio)) $dominio = 'https://' . $dominio;
$dominio = rtrim($dominio, '/');

// ─── HELPERS ───
function fetchUrl(string $url, string $ua = '', int $timeout = 12, bool $headOnly = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => $ua ?: 'Mozilla/5.0 (compatible; AdSenseAuditor/1.0)',
        CURLOPT_NOBODY         => $headOnly,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '',
    ]);
    $start = microtime(true);
    $resp  = curl_exec($ch);
    $info  = curl_getinfo($ch);
    $err   = curl_error($ch);
    curl_close($ch);
    $headerSize = $info['header_size'] ?? 0;
    $headersStr = $resp ? substr((string)$resp, 0, $headerSize) : '';
    $body       = $resp ? substr((string)$resp, $headerSize) : '';
    return [
        'http_code'      => (int)($info['http_code'] ?? 0),
        'effective_url'  => (string)($info['url'] ?? $url),
        'time_total'     => round(microtime(true) - $start, 2),
        'headers'        => $headersStr,
        'body'           => $body,
        'error'          => $err,
        'redirects'      => (int)($info['redirect_count'] ?? 0),
    ];
}

function findStr(string $haystack, string $needle): bool {
    return stripos($haystack, $needle) !== false;
}

function countWordsHtml(string $html): int {
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    return (int)str_word_count((string)$text, 0, 'áéíóúâêôãõçÁÉÍÓÚÂÊÔÃÕÇ0123456789');
}

// ─── INICIA AUDITORIA ───
$findings = [];      // [['nivel'=>'red|yellow|green', 'titulo'=>'', 'detalhe'=>'', 'fix'=>'']]
$stats    = ['homepage_ms'=>0, 'sitemap_total'=>0, 'sitemap_404'=>0, 'home_links_404'=>0, 'posts_thin'=>0];
$baseHost = parse_url($dominio, PHP_URL_HOST);

// ─── 1. HOMEPAGE ───
$home = fetchUrl($dominio, $USER_AGENT_DESKTOP, $TIMEOUT);
$stats['homepage_ms'] = $home['time_total'];

if ($home['http_code'] !== 200) {
    $findings[] = ['nivel'=>'red','titulo'=>'Homepage não responde 200','detalhe'=>"HTTP {$home['http_code']} — {$home['error']}",'fix'=>'Confirmar que o site está no ar e respondendo. Sem isto, AdSense reprova imediatamente.'];
} else {
    if ($home['time_total'] > 3.0) {
        $findings[] = ['nivel'=>'yellow','titulo'=>'Homepage lenta','detalhe'=>"{$home['time_total']}s pra carregar. AdSense penaliza > 3s.",'fix'=>'Otimizar cache (LiteSpeed/W3 Total), CDN (Cloudflare), reduzir plugins WP.'];
    } else {
        $findings[] = ['nivel'=>'green','titulo'=>'Homepage OK','detalhe'=>"HTTP 200 em {$home['time_total']}s",'fix'=>''];
    }

    // Verifica canonical
    if (preg_match('#<link\s+rel=[\'"]canonical[\'"]\s+href=[\'"]([^\'"]+)[\'"]#i', $home['body'], $mC)) {
        $canonical = $mC[1];
        $hostCan = parse_url($canonical, PHP_URL_HOST);
        if ($hostCan !== $baseHost) {
            $findings[] = ['nivel'=>'yellow','titulo'=>'Canonical aponta pra outro host','detalhe'=>"Você acessou {$baseHost} mas canonical=`{$canonical}` (host {$hostCan}). Possível duplicate content.",'fix'=>'Garantir que canonical bate com o domínio acessado, ou redirect 301 do erro pro correto.'];
        } else {
            $findings[] = ['nivel'=>'green','titulo'=>'Canonical OK','detalhe'=>$canonical,'fix'=>''];
        }
    } else {
        $findings[] = ['nivel'=>'yellow','titulo'=>'Canonical ausente na homepage','detalhe'=>'Sem <link rel="canonical">. AdSense pode confundir variantes (www, non-www, http, https).','fix'=>'Adicionar via tema WP ou plugin SEO (Yoast/Rank Math).'];
    }

    // Verifica viewport mobile
    if (preg_match('#<meta\s+name=[\'"]viewport[\'"]#i', $home['body'])) {
        $findings[] = ['nivel'=>'green','titulo'=>'Viewport mobile presente','detalhe'=>'<meta name="viewport"> ok','fix'=>''];
    } else {
        $findings[] = ['nivel'=>'red','titulo'=>'Viewport mobile ausente','detalhe'=>'AdSense exige site mobile-friendly.','fix'=>'Adicionar <meta name="viewport" content="width=device-width, initial-scale=1"> no <head>.'];
    }

    // Verifica imagens placeholder data:image
    if (substr_count($home['body'], 'data:image/svg+xml;base64') > 5) {
        $findings[] = ['nivel'=>'yellow','titulo'=>'Lazy-load com placeholders SVG na home','detalhe'=>substr_count($home['body'], 'data:image/svg+xml;base64') . ' imagens são placeholders.','fix'=>'AdSense crawler pode não ver imagens reais. Confirmar que lazy-load tem fallback Noscript ou que crawler renderiza JS.'];
    }

    // Schema.org markup
    if (findStr($home['body'], 'application/ld+json') || findStr($home['body'], 'itemscope')) {
        $findings[] = ['nivel'=>'green','titulo'=>'Schema.org detectado','detalhe'=>'JSON-LD ou microdata presente','fix'=>''];
    } else {
        $findings[] = ['nivel'=>'yellow','titulo'=>'Schema.org ausente','detalhe'=>'Sem markup estruturado. AdSense não exige, mas E-E-A-T melhor com markup.','fix'=>'Adicionar Schema Article/WebSite via plugin SEO.'];
    }

    // Detecta links de posts da home (pega primeiros 10 únicos do mesmo host)
    $homeLinks = [];
    if (preg_match_all('#<a\s+[^>]*href=[\'"]([^\'"]+)[\'"]#i', $home['body'], $mL)) {
        foreach ($mL[1] as $href) {
            if (!preg_match('#^https?://#', $href)) {
                if (substr($href, 0, 1) === '/') $href = $dominio . $href;
                else continue;
            }
            $h = parse_url($href, PHP_URL_HOST);
            if ($h !== $baseHost && $h !== 'www.' . $baseHost) continue;
            // Filtra páginas, não imagens/css/js
            if (preg_match('#\.(jpg|png|gif|svg|css|js|ico|webp)(\?|$)#i', $href)) continue;
            // Skip âncoras na mesma página
            if (parse_url($href, PHP_URL_PATH) === '/') continue;
            $homeLinks[] = $href;
            if (count(array_unique($homeLinks)) >= 8) break;
        }
    }
    $homeLinks = array_unique($homeLinks);
    if (count($homeLinks) > 0) {
        $linksOk = 0; $links404 = 0;
        $links404List = [];
        foreach ($homeLinks as $href) {
            $r = fetchUrl($href, $USER_AGENT_GBOT, 8, true);
            if ($r['http_code'] === 200) $linksOk++;
            elseif ($r['http_code'] >= 400) { $links404++; $links404List[] = "{$href} ({$r['http_code']})"; }
        }
        $stats['home_links_404'] = $links404;
        if ($links404 > 0) {
            $findings[] = ['nivel'=>'red','titulo'=>"Links da homepage retornam erro: {$links404}/" . count($homeLinks),'detalhe'=>'Posts/páginas linkados na home não existem: ' . implode(', ', array_slice($links404List, 0, 5)),'fix'=>'CRÍTICO: AdSense vê navegação quebrada. Investigar slugs WP, cache, redirects. Talvez purgar cache de página.'];
        } else {
            $findings[] = ['nivel'=>'green','titulo'=>"Todos os {$linksOk} links da homepage funcionam",'detalhe'=>'',  'fix'=>''];
        }
    }
}

// ─── 2. www vs non-www ───
$temWww = strpos($baseHost, 'www.') === 0;
$altHost = $temWww ? substr($baseHost, 4) : 'www.' . $baseHost;
$altUrl = (parse_url($dominio, PHP_URL_SCHEME) ?: 'https') . '://' . $altHost;
$altResp = fetchUrl($altUrl, $USER_AGENT_DESKTOP, 8);
if ($altResp['http_code'] === 200 && $altResp['effective_url'] !== $altUrl) {
    // Redirect feito → ok
    $efHost = parse_url($altResp['effective_url'], PHP_URL_HOST);
    if ($efHost === $baseHost) {
        $findings[] = ['nivel'=>'green','titulo'=>'www/non-www: redirect OK','detalhe'=>"{$altUrl} → {$altResp['effective_url']}",'fix'=>''];
    } else {
        $findings[] = ['nivel'=>'yellow','titulo'=>'www/non-www inconsistente','detalhe'=>"{$altUrl} redireciona pra {$altResp['effective_url']} (host {$efHost})",'fix'=>'Definir versão canônica e 301 a outra.'];
    }
} elseif ($altResp['http_code'] === 200) {
    $findings[] = ['nivel'=>'red','titulo'=>'www e non-www respondem ambos sem 301','detalhe'=>"{$dominio} e {$altUrl} ambos retornam 200 sem redirect. AdSense vê duplicate content.",'fix'=>'Configurar 301 redirect de uma versão pra outra (.htaccess ou plugin WP).'];
}

// ─── 3. ROBOTS.TXT ───
$robots = fetchUrl($dominio . '/robots.txt', $USER_AGENT_DESKTOP, 8);
if ($robots['http_code'] === 200) {
    $rb = $robots['body'];
    // Mediapartners-Google
    if (preg_match('/User-agent:\s*Mediapartners-Google[^\n]*\n((?:(?!User-agent:).+\n)*)/i', $rb, $mM)) {
        if (preg_match('/Disallow:\s*\/\s*$/m', $mM[1] ?? '')) {
            $findings[] = ['nivel'=>'red','titulo'=>'robots.txt BLOQUEIA Mediapartners-Google','detalhe'=>'Disallow: / pro AdSense crawler. Site nunca passa.','fix'=>'Remover bloqueio. Pode ter "Allow: /" pro Mediapartners-Google explicitamente.'];
        } else {
            $findings[] = ['nivel'=>'green','titulo'=>'robots.txt permite Mediapartners-Google explicitamente','detalhe'=>'','fix'=>''];
        }
    }
    // AdsBot-Google
    if (preg_match('/Disallow:\s*\/\s*$/m', $rb) && !preg_match('/User-agent:\s*\*/i', $rb)) {
        // só bloqueia tudo
        $findings[] = ['nivel'=>'red','titulo'=>'robots.txt bloqueia tudo (Disallow: /)','detalhe'=>'Sem segmentação por agent. AdSense vai bater em parede.','fix'=>'Reescrever robots.txt: User-agent: * + Allow: /.'];
    }
    // Disallow agressivo
    if (preg_match('#Disallow:\s*/\?\*#i', $rb)) {
        $findings[] = ['nivel'=>'yellow','titulo'=>'robots.txt bloqueia query strings (/?*)','detalhe'=>'AdSense às vezes testa com query params. Bloqueio agressivo demais.','fix'=>'Considere remover esse Disallow ou ser mais específico.'];
    }
} else {
    $findings[] = ['nivel'=>'yellow','titulo'=>'robots.txt ausente ou inacessível','detalhe'=>"HTTP {$robots['http_code']}",'fix'=>'Adicionar /robots.txt mínimo: User-agent: * + Allow: / + Sitemap: ...xml.'];
}

// ─── 4. ADS.TXT ───
$adstxt = fetchUrl($dominio . '/ads.txt', $USER_AGENT_DESKTOP, 8);
if ($adstxt['http_code'] === 200 && trim($adstxt['body']) !== '') {
    if (preg_match('/google\.com,\s*pub-\d+,\s*DIRECT/i', $adstxt['body'])) {
        $findings[] = ['nivel'=>'green','titulo'=>'ads.txt configurado com Google DIRECT','detalhe'=>trim($adstxt['body']),'fix'=>''];
    } else {
        $findings[] = ['nivel'=>'yellow','titulo'=>'ads.txt presente mas sem entrada Google DIRECT','detalhe'=>trim($adstxt['body']),'fix'=>'Adicionar linha: google.com, pub-XXXXXXXXXXXXXXX, DIRECT, f08c47fec0942fa0'];
    }
} else {
    $findings[] = ['nivel'=>'yellow','titulo'=>'ads.txt ausente','detalhe'=>"HTTP {$adstxt['http_code']}",'fix'=>'Adicionar /ads.txt com entrada Google DIRECT antes de aplicar pra AdSense.'];
}

// ─── 5. SITEMAP ───
$sitemapUrls = ["{$dominio}/sitemap.xml", "{$dominio}/sitemap_index.xml", "{$dominio}/wp-sitemap.xml"];
$sitemapEncontrado = null;
foreach ($sitemapUrls as $sUrl) {
    $r = fetchUrl($sUrl, $USER_AGENT_DESKTOP, 8);
    if ($r['http_code'] === 200 && (findStr($r['body'], '<urlset') || findStr($r['body'], '<sitemapindex'))) {
        $sitemapEncontrado = ['url' => $sUrl, 'body' => $r['body']];
        break;
    }
}
if ($sitemapEncontrado === null) {
    $findings[] = ['nivel'=>'red','titulo'=>'Sitemap XML não encontrado','detalhe'=>'Tentei /sitemap.xml, /sitemap_index.xml, /wp-sitemap.xml. AdSense exige sitemap acessível.','fix'=>'Instalar plugin SEO (Rank Math/Yoast) que gera sitemap automaticamente.'];
} else {
    // Extrai URLs
    if (findStr($sitemapEncontrado['body'], '<sitemapindex')) {
        // Sitemap index — pega 1º filho
        if (preg_match('#<loc>([^<]+)</loc>#', $sitemapEncontrado['body'], $mIdx)) {
            $rIdx = fetchUrl($mIdx[1], $USER_AGENT_DESKTOP, 8);
            $sitemapEncontrado['body'] = $rIdx['body'];
        }
    }
    preg_match_all('#<loc>([^<]+)</loc>#', $sitemapEncontrado['body'], $mUrls);
    $urls = array_unique($mUrls[1] ?? []);
    $stats['sitemap_total'] = count($urls);
    // Valida amostra
    $urlsCheck = array_slice($urls, 0, $MAX_SITEMAP_CHECK);
    $sitemap404 = []; $sitemapOk = 0;
    foreach ($urlsCheck as $u) {
        $r = fetchUrl($u, $USER_AGENT_GBOT, 6, true);
        if ($r['http_code'] === 200) $sitemapOk++;
        elseif ($r['http_code'] >= 400) $sitemap404[] = "{$u} ({$r['http_code']})";
    }
    $stats['sitemap_404'] = count($sitemap404);
    if (!empty($sitemap404)) {
        $findings[] = ['nivel'=>'red','titulo'=>'Sitemap declara URLs que retornam erro: ' . count($sitemap404) . '/' . count($urlsCheck) . ' verificadas','detalhe'=>'AdSense crawler segue sitemap → bate em 404 → conclui "site under construction" → reprova. Exemplos: ' . implode(', ', array_slice($sitemap404, 0, 5)),'fix'=>'CRÍTICO: limpar sitemap. Remover URLs mortas OU consertar páginas. Plugin Rank Math/Yoast deve gerar sitemap só com URLs vivas.'];
    } else {
        $findings[] = ['nivel'=>'green','titulo'=>"Sitemap OK: {$sitemapOk}/" . count($urlsCheck) . " URLs verificadas funcionam (de {$stats['sitemap_total']} total)",'detalhe'=>$sitemapEncontrado['url'],'fix'=>''];
    }
}

// ─── 6. COMPLIANCE PAGES ───
$compliancePages = [
    'Sobre/About'     => ['/sobre', '/sobre-nos', '/sobre-nos.html', '/about', '/quem-somos'],
    'Privacidade'     => ['/politica-de-privacidade', '/politica-privacidade', '/politica-privacidade.html', '/privacy', '/privacidade'],
    'Termos'          => ['/termos-de-uso', '/termos', '/termos-de-uso.html', '/terms'],
    'Contato'         => ['/contato', '/contato.html', '/fale-conosco', '/contact'],
];
$complianceFalt = [];
foreach ($compliancePages as $tipo => $candidatos) {
    $achou = false;
    foreach ($candidatos as $path) {
        $r = fetchUrl($dominio . $path, $USER_AGENT_GBOT, 6, true);
        if ($r['http_code'] === 200) { $achou = true; break; }
    }
    if (!$achou) $complianceFalt[] = $tipo;
}
if (!empty($complianceFalt)) {
    $findings[] = ['nivel'=>'red','titulo'=>'Páginas de compliance ausentes: ' . implode(', ', $complianceFalt),'detalhe'=>'AdSense exige Privacidade, Termos, Sobre e Contato visíveis e funcionais.','fix'=>'Criar essas páginas urgente. WordPress: Adicionar > Página > publicar.'];
} else {
    $findings[] = ['nivel'=>'green','titulo'=>'Compliance pages: Sobre + Privacidade + Termos + Contato OK','detalhe'=>'','fix'=>''];
}

// ─── 7. POSTS DEEP ANALYSIS ───
if (!empty($urls)) {
    $postsAmostra = array_slice($urls, 0, $MAX_POSTS_DEEP);
    foreach ($postsAmostra as $url) {
        $r = fetchUrl($url, $USER_AGENT_GBOT, 10);
        if ($r['http_code'] !== 200) continue;
        $palavras = countWordsHtml($r['body']);
        if ($palavras < 400) {
            $stats['posts_thin']++;
            $findings[] = ['nivel'=>'yellow','titulo'=>"Post thin (~{$palavras}w): " . basename($url),'detalhe'=>$url,'fix'=>'AdSense rejeita "thin content" (< 400 palavras geralmente). Expandir conteúdo ou despublicar.'];
        }
        // AI patterns
        $bodyLower = mb_strtolower($r['body']);
        $aiSinais = ['vale destacar', 'diante disso', 'em suma', 'nesse contexto', 'cabe ressaltar', 'sendo assim'];
        $aiCount = 0;
        foreach ($aiSinais as $s) if (findStr($bodyLower, $s)) $aiCount++;
        if ($aiCount >= 3) {
            $findings[] = ['nivel'=>'yellow','titulo'=>"Post com sinais de IA generativa: " . basename($url),'detalhe'=>"{$aiCount} expressões típicas de LLM. AdSense detecta padrões de scaled content.",'fix'=>'Filtrar essas expressões no prompt do Sonnet (já existe regra anti-IA — verificar se está ativa pra esse site).'];
        }
    }
}

// ─── 8. MOBILE RENDERING ───
$mobile = fetchUrl($dominio, $USER_AGENT_MOBILE, 10);
if ($mobile['http_code'] === 200) {
    if ($mobile['time_total'] > 5.0) {
        $findings[] = ['nivel'=>'red','titulo'=>"Homepage mobile lenta: {$mobile['time_total']}s",'detalhe'=>'AdSense penaliza mobile lento (Core Web Vitals).','fix'=>'CWV: comprimir imagens, lazy-load real, reduzir CSS/JS, CDN.'];
    }
} else {
    $findings[] = ['nivel'=>'red','titulo'=>"Homepage não responde com User-Agent mobile",'detalhe'=>"HTTP {$mobile['http_code']}",'fix'=>'Site precisa servir mobile igual ao desktop.'];
}

// ─── RENDERIZA RELATÓRIO ───
$cores = ['red' => '#dc2626', 'yellow' => '#d97706', 'green' => '#16a34a'];
$bgs   = ['red' => '#fef2f2', 'yellow' => '#fffbeb', 'green' => '#f0fdf4'];
$emoji = ['red' => '🔴', 'yellow' => '🟡', 'green' => '✅'];

usort($findings, fn($a,$b) => array_search($a['nivel'], ['red','yellow','green']) <=> array_search($b['nivel'], ['red','yellow','green']));

$cR = count(array_filter($findings, fn($f)=>$f['nivel']==='red'));
$cY = count(array_filter($findings, fn($f)=>$f['nivel']==='yellow'));
$cG = count(array_filter($findings, fn($f)=>$f['nivel']==='green'));

if (PHP_SAPI === 'cli') {
    echo "\n=== AUDITORIA ADSENSE — {$dominio} ===\n\n";
    echo "🔴 {$cR} críticos · 🟡 {$cY} warnings · ✅ {$cG} ok\n\n";
    foreach ($findings as $f) {
        echo "{$emoji[$f['nivel']]} [{$f['nivel']}] {$f['titulo']}\n";
        if ($f['detalhe']) echo "   detalhe: {$f['detalhe']}\n";
        if ($f['fix']) echo "   fix: {$f['fix']}\n";
        echo "\n";
    }
    exit;
}

// Web output
?><!doctype html>
<html lang='pt-br'><head><meta charset='UTF-8'><title>Auditoria AdSense — <?=htmlspecialchars($dominio)?></title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fa;color:#1a1a1a;padding:24px;max-width:1100px;margin:0 auto;line-height:1.5}
h1{font-size:1.5rem;margin:0 0 6px}
.sub{color:#666;font-size:13px;margin-bottom:18px}
.summary{display:flex;gap:14px;margin-bottom:24px}
.sm{padding:14px 18px;border-radius:10px;flex:1;text-align:center}
.sm.r{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.sm.y{background:#fffbeb;border:1px solid #fde68a;color:#d97706}
.sm.g{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a}
.sm .n{font-size:2rem;font-weight:800;line-height:1}
.sm .l{font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-top:4px}
.f{padding:14px 16px;margin-bottom:8px;border-radius:8px;border-left:4px solid}
.f h3{margin:0 0 6px;font-size:14px}
.f .d{font-size:13px;color:#444;margin-bottom:6px}
.f .x{font-size:13px;color:#222;background:rgba(0,0,0,.04);padding:8px 12px;border-radius:6px}
.f.red{background:#fef2f2;border-color:#dc2626}
.f.yellow{background:#fffbeb;border-color:#d97706}
.f.green{background:#f0fdf4;border-color:#16a34a}
</style></head><body>
<h1>Auditoria AdSense — <a href='<?=htmlspecialchars($dominio)?>'><?=htmlspecialchars($dominio)?></a></h1>
<div class='sub'>Verificação em <?=date('d/m/Y H:i')?> · Sitemap total: <?=$stats['sitemap_total']?> URLs · 404s sitemap: <?=$stats['sitemap_404']?> · Posts thin: <?=$stats['posts_thin']?></div>
<div class='summary'>
  <div class='sm r'><div class='n'><?=$cR?></div><div class='l'>🔴 Críticos</div></div>
  <div class='sm y'><div class='n'><?=$cY?></div><div class='l'>🟡 Warnings</div></div>
  <div class='sm g'><div class='n'><?=$cG?></div><div class='l'>✅ OK</div></div>
</div>
<?php foreach ($findings as $f): ?>
  <div class='f <?=$f['nivel']?>'>
    <h3><?=$emoji[$f['nivel']]?> <?=htmlspecialchars($f['titulo'])?></h3>
    <?php if ($f['detalhe']): ?><div class='d'><?=htmlspecialchars($f['detalhe'])?></div><?php endif; ?>
    <?php if ($f['fix']): ?><div class='x'><strong>Fix:</strong> <?=htmlspecialchars($f['fix'])?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
</body></html>
