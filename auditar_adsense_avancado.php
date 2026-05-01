<?php
/**
 * auditar_adsense_avancado.php — varredura profunda dos 5 pontos críticos avançados:
 *
 *   1. Consent banner LGPD (Cookie Notice, Complianz, Real Cookie Banner, OneTrust, etc)
 *   2. Sinais de IA generativa nos posts (anti-IA expressions, density)
 *   3. Author info presente (Schema Person, rel=author, byline)
 *   4. Categorias vazias indexáveis (count < 3 → candidatas a noindex)
 *   5. Schema.org completo (Author + BreadcrumbList + datePublished + FAQPage)
 *
 * Amostra 10 posts random do WP via REST e mede qualidade individual.
 *
 * Uso:
 *   php auditar_adsense_avancado.php cursosenac
 */

set_time_limit(180);
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/_site_helper.php';
require_once __DIR__ . '/lib/Wordpress.php';

$slug = $argv[1] ?? '';
if ($slug === '') { fwrite(STDERR, "Uso: php auditar_adsense_avancado.php <site-slug>\n"); exit(1); }

$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { fwrite(STDERR, "Site '{$slug}' não cadastrado\n"); exit(1); }

$s = $sites[$slug];
$wpUrl = rtrim($s['wp_url'], '/');
$wpUser = $s['wp_user'];
$wpPass = $s['wp_app_password'];

echo "\n=== AUDITORIA ADSENSE AVANÇADA ({$slug}) ===\n";
echo "WP: {$wpUrl}\n\n";

$findings = [];
function add(string $nivel, string $titulo, string $detalhe = '', string $fix = '') {
    global $findings;
    $findings[] = ['nivel' => $nivel, 'titulo' => $titulo, 'detalhe' => $detalhe, 'fix' => $fix];
}

// ─── HELPER ───
function fetchUrl(string $url, int $timeout = 15, string $ua = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $ua ?: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

function wpRest(string $url, string $user, string $pass, int $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERPWD => "{$user}:{$pass}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$body, true) ?: $body];
}

// ─── 1. CONSENT BANNER LGPD ───
echo "[1/5] Consent banner LGPD...\n";
$home = fetchUrl($wpUrl);
$bannerSinais = [
    'CookieYes'             => ['cookieyes', 'cky-banner', 'cli_settings'],
    'Cookie Notice'         => ['cookie-notice', 'cn-position', 'cn-button'],
    'Complianz'             => ['cmplz-', 'complianz', 'cmplz_settings'],
    'Real Cookie Banner'    => ['real-cookie-banner', 'rcb-banner', 'realcookiebanner'],
    'OneTrust'              => ['onetrust', 'optanon', 'ot-sdk'],
    'Iubenda'               => ['iubenda', 'iubenda_policy'],
    'Cookiebot'             => ['cookiebot', 'CybotCookiebot'],
    'GDPR Cookie Consent'   => ['gdpr_cookie_consent', 'cli-bar-message'],
    'Termly'                => ['termly', 'termly.io'],
    'WP Auto Terms'         => ['wp-auto-terms-cookie'],
];
$bannerEncontrado = null;
foreach ($bannerSinais as $nome => $sinais) {
    foreach ($sinais as $s) {
        if (stripos($home['body'], $s) !== false) { $bannerEncontrado = $nome; break 2; }
    }
}
if ($bannerEncontrado) {
    add('green', "Consent banner detectado: {$bannerEncontrado}", 'Plugin de cookie consent ativo na home', '');
} else {
    add('red', 'Consent banner LGPD ausente',
        'Nenhum plugin de cookie consent detectado (Cookie Notice, Complianz, Real Cookie Banner, OneTrust, etc).',
        'CRÍTICO PRA APROVAÇÃO BR: instalar plugin "Real Cookie Banner" ou "Complianz" (ambos têm versão free com LGPD compliance + categorias Marketing/Analytics). Sem banner = AdSense reprova automático em sites BR.'
    );
}

// ─── 2. AMOSTRA 10 POSTS — sinais de IA + qualidade ───
echo "[2/5] Amostragem de 10 posts pra qualidade...\n";
$resp = wpRest("{$wpUrl}/wp-json/wp/v2/posts?per_page=10&_embed=author&orderby=date&order=desc&_fields=id,title,content,link,featured_media,_links,_embedded", $wpUser, $wpPass);
$posts = is_array($resp['body']) ? $resp['body'] : [];
$cAi = 0; $cThin = 0; $cSemAuthor = 0; $cSemImg = 0; $totalPalavras = 0;
$expressoesAi = ['vale destacar', 'diante disso', 'em suma', 'nesse contexto', 'cabe ressaltar', 'sendo assim', 'é importante mencionar'];
foreach ($posts as $p) {
    $html = (string)($p['content']['rendered'] ?? '');
    $text = mb_strtolower(strip_tags($html));
    $palavras = str_word_count($text, 0, 'áéíóúâêôãõçÁÉÍÓÚÂÊÔÃÕÇ0123456789');
    $totalPalavras += $palavras;
    if ($palavras < 400) $cThin++;

    $aiHits = 0;
    foreach ($expressoesAi as $e) if (strpos($text, $e) !== false) $aiHits++;
    if ($aiHits >= 3) $cAi++;

    // Author info: tem nome real ou "admin"?
    $authorName = '';
    if (!empty($p['_embedded']['author'][0]['name'])) $authorName = mb_strtolower($p['_embedded']['author'][0]['name']);
    if ($authorName === '' || in_array($authorName, ['admin', 'administrator', 'editor', 'autor'], true)) $cSemAuthor++;

    // Imagens: conta IMGs reais NO CONTENT + featured_media via REST (tema renderiza featured fora do content)
    $totalImg = preg_match_all('#<img\b[^>]*>#i', $html, $mImg);
    $imgPlaceholder = preg_match_all('#<img\b[^>]*src=["\']data:image/(svg|gif)\+xml#i', $html, $mPlc);
    $imgsReaisContent = $totalImg - $imgPlaceholder;
    $temFeatured = (int)($p['featured_media'] ?? 0) > 0;
    if ($imgsReaisContent === 0 && !$temFeatured && $palavras > 200) $cSemImg++;
}
$amostraTotal = count($posts);
if ($amostraTotal === 0) {
    add('yellow', 'Não consegui amostrar posts via WP REST', "HTTP {$resp['code']}",
        'Verificar se /wp-json/wp/v2/posts está acessível e se as credenciais têm permissão.');
} else {
    $mediaPalavras = $amostraTotal > 0 ? round($totalPalavras / $amostraTotal) : 0;
    if ($cAi > $amostraTotal * 0.4) {
        add('red', "Sinais fortes de IA generativa: {$cAi}/{$amostraTotal} posts",
            "Posts contêm 3+ expressões 'anti-IA' (vale destacar, diante disso, em suma, etc). AdSense detecta scaled content.",
            "Filtrar essas expressões no prompt do Sonnet (regra anti-IA já existe — verificar se está ativa no fluxo). Reescrever 5-10 posts antigos manualmente pra reduzir density.");
    } elseif ($cAi > 0) {
        add('yellow', "Sinais leves de IA: {$cAi}/{$amostraTotal} posts",
            "Alguns posts com expressões típicas de LLM. Vigiar.",
            "Adicionar expressões na lista de 'palavras proibidas' do prompt anti-IA.");
    } else {
        add('green', "Posts sem sinais óbvios de IA generativa", "0/{$amostraTotal} amostrados", '');
    }
    if ($cThin > $amostraTotal * 0.3) {
        add('red', "Posts thin: {$cThin}/{$amostraTotal} com <400 palavras",
            "Média de palavras: {$mediaPalavras}. AdSense rejeita thin content.",
            "Expandir posts ou despublicar os curtos. Idealmente >800 palavras por post.");
    } elseif ($cThin > 0) {
        add('yellow', "Alguns posts thin: {$cThin}/{$amostraTotal}",
            "Média geral OK ({$mediaPalavras}w) mas há posts curtos.", '');
    } else {
        add('green', "Posts com tamanho saudável (média {$mediaPalavras}w)", '', '');
    }
    if ($cSemAuthor > $amostraTotal * 0.5) {
        add('yellow', "Posts sem author info real: {$cSemAuthor}/{$amostraTotal}",
            "Author = 'admin'/'administrator' ou vazio. Sinal de scaled content.",
            "Criar 1-2 user accounts com nomes reais (ex: 'Maria Editora') e atribuir como author dos posts. WP Admin → Users → Add New.");
    }
    if ($cSemImg > $amostraTotal * 0.3) {
        add('yellow', "Posts sem imagens reais: {$cSemImg}/{$amostraTotal}",
            "Posts longos sem nenhuma imagem (ou só placeholders SVG).",
            "Pipeline de geração deve sempre incluir featured image (DALL-E ou Pexels). Ver flag `gerar_imagem_ia` no cluster.");
    }
}

// ─── 3. CATEGORIAS VAZIAS / THIN (ignora as que já têm noindex) ───
echo "[3/5] Categorias com poucos posts (verificando noindex meta)...\n";
$cats = wpRest("{$wpUrl}/wp-json/wp/v2/categories?per_page=100&hide_empty=false", $wpUser, $wpPass);
if (is_array($cats['body'])) {
    $thinIndexavel = []; $thinComNoindex = 0; $totalCats = count($cats['body']);
    foreach ($cats['body'] as $c) {
        if (($c['count'] ?? 0) < 3 && !in_array(($c['slug'] ?? ''), ['uncategorized', 'sem-categoria'], true)) {
            // Verifica se já tem noindex via Rank Math meta (chamada extra por categoria — só pras suspeitas)
            $hasNoindex = false;
            try {
                $catFull = wpRest("{$wpUrl}/wp-json/wp/v2/categories/{$c['id']}?context=edit", $wpUser, $wpPass);
                if (is_array($catFull['body']) && !empty($catFull['body']['meta']['rank_math_robots'])) {
                    $robots = $catFull['body']['meta']['rank_math_robots'];
                    if (is_array($robots) && in_array('noindex', $robots, true)) $hasNoindex = true;
                    elseif (is_string($robots) && stripos($robots, 'noindex') !== false) $hasNoindex = true;
                }
            } catch (Throwable $e) {}
            if ($hasNoindex) {
                $thinComNoindex++;
            } else {
                $thinIndexavel[] = ($c['name'] ?? '?') . " (#{$c['id']}, {$c['count']}p)";
            }
        }
    }
    if (count($thinIndexavel) > 5) {
        add('yellow', "Categorias thin SEM noindex: " . count($thinIndexavel) . "/{$totalCats} (+{$thinComNoindex} já com noindex)",
            "Exemplos: " . implode(', ', array_slice($thinIndexavel, 0, 8)),
            "Aplicar noindex via Rank Math → Titles & Meta → Categories OU rodar fix_adsense_warnings.php pra aplicar via REST.");
    } elseif (count($thinIndexavel) > 0) {
        add('yellow', count($thinIndexavel) . " categorias ainda indexáveis (+{$thinComNoindex} com noindex)",
            "Exemplos: " . implode(', ', $thinIndexavel),
            "Vale aplicar noindex nessas também.");
    } else {
        if ($thinComNoindex > 0) {
            add('green', "Todas {$thinComNoindex} categorias thin têm noindex aplicado · " . ($totalCats - $thinComNoindex) . " demais com 3+ posts", '', '');
        } else {
            add('green', "Todas {$totalCats} categorias têm 3+ posts", '', '');
        }
    }
}

// ─── 4. SCHEMA RICO ───
echo "[4/5] Schema.org rico nos posts...\n";
if (!empty($posts)) {
    $temAuthor = 0; $temBreadcrumb = 0; $temDataPub = 0; $temFAQ = 0; $temArticle = 0;
    $checkados = 0;
    foreach (array_slice($posts, 0, 5) as $p) {
        $url = $p['link'] ?? '';
        if ($url === '') continue;
        $r = fetchUrl($url, 10);
        if ($r['code'] !== 200) continue;
        $checkados++;
        $h = $r['body'];
        if (preg_match('/<script[^>]*application\/ld\+json/i', $h)) {
            preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $h, $mJs);
            $allJsonStr = implode("\n", $mJs[1] ?? []);
            if (preg_match('/"@type"\s*:\s*"Article"/', $allJsonStr) || preg_match('/"@type"\s*:\s*"NewsArticle"/', $allJsonStr)) $temArticle++;
            if (preg_match('/"@type"\s*:\s*"Person"/', $allJsonStr) || preg_match('/"author"\s*:\s*\{[^\}]*"@type"\s*:\s*"Person"/', $allJsonStr)) $temAuthor++;
            if (preg_match('/"@type"\s*:\s*"BreadcrumbList"/', $allJsonStr)) $temBreadcrumb++;
            if (preg_match('/"datePublished"/', $allJsonStr)) $temDataPub++;
            if (preg_match('/"@type"\s*:\s*"FAQPage"/', $allJsonStr)) $temFAQ++;
        }
    }
    if ($checkados > 0) {
        $faltando = [];
        if ($temArticle < $checkados) $faltando[] = 'Article (' . $temArticle . "/{$checkados})";
        if ($temAuthor < $checkados) $faltando[] = 'Author (' . $temAuthor . "/{$checkados})";
        if ($temBreadcrumb < $checkados) $faltando[] = 'BreadcrumbList (' . $temBreadcrumb . "/{$checkados})";
        if ($temDataPub < $checkados) $faltando[] = 'datePublished (' . $temDataPub . "/{$checkados})";
        if (empty($faltando)) {
            add('green', "Schema.org completo (Article+Author+Breadcrumb+date) em {$checkados} posts amostrados", '', '');
        } elseif (count($faltando) <= 1) {
            add('yellow', "Schema parcial — falta: " . implode(', ', $faltando), '',
                'Rank Math gera maior parte automaticamente. Ativar Schema → General → Article type.');
        } else {
            add('red', "Schema incompleto em vários posts — falta: " . implode(', ', $faltando),
                'AdSense valoriza schema rico — falta sinaliza site amador.',
                'Rank Math → Titles & Meta → Posts → Schema Type: Article. Ativar Author, BreadcrumbList em Settings.');
        }
        // FAQ é opcional
        if ($temFAQ > 0) add('green', "FAQ Schema detectado em {$temFAQ}/{$checkados} posts", '', '');
    }
}

// ─── 5. SEARCH CONSOLE PROPERTY (verifica meta tag) ───
echo "[5/5] Verificação Google Search Console...\n";
if (preg_match('/<meta\s+name=["\']google-site-verification["\']\s+content=["\']([^"\']+)["\']/i', $home['body'], $mGV)) {
    add('green', "Google Search Console verificado", "Verification token: " . substr($mGV[1], 0, 20) . "...", '');
} else {
    // Talvez verificado via DNS ou Search Console direto (sem meta)
    add('yellow', "Meta tag de verificação Search Console não encontrada na home",
        'Pode estar verificado via DNS TXT (não detecta via HTML). Confirmar no painel.',
        'Se não verificado: Search Console → Add Property → seguir wizard. Submeter sitemap depois (importante pra AdSense).');
}

// ─── RELATÓRIO ───
echo "\n=== RELATÓRIO ===\n";
$cR = count(array_filter($findings, fn($f) => $f['nivel'] === 'red'));
$cY = count(array_filter($findings, fn($f) => $f['nivel'] === 'yellow'));
$cG = count(array_filter($findings, fn($f) => $f['nivel'] === 'green'));
echo "🔴 {$cR} críticos · 🟡 {$cY} warnings · ✅ {$cG} ok\n\n";

usort($findings, fn($a,$b) => array_search($a['nivel'], ['red','yellow','green']) <=> array_search($b['nivel'], ['red','yellow','green']));
$emoji = ['red' => '🔴', 'yellow' => '🟡', 'green' => '✅'];
foreach ($findings as $f) {
    echo "{$emoji[$f['nivel']]} [{$f['nivel']}] {$f['titulo']}\n";
    if ($f['detalhe']) echo "   detalhe: {$f['detalhe']}\n";
    if ($f['fix']) echo "   fix: {$f['fix']}\n";
    echo "\n";
}
