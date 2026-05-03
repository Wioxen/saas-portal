<?php
declare(strict_types=1);
/**
 * Corrige RankMath SEO de um post existente:
 *   1. Deriva focus_keyword do título (2-4 palavras-chave principais)
 *   2. Reescreve meta_description pra conter a keyword (140-160 chars)
 *   3. Encurta slug se >75 chars (mantendo a keyword)
 *   4. Atualiza alt text da featured image pra incluir a keyword
 *
 * NÃO mexe no body do post (manter conteúdo intacto).
 *
 * Uso:
 *   php scripts/corrigir_seo_post.php --site=leaodabarra --post-id=877 --dry-run
 *   php scripts/corrigir_seo_post.php --site=leaodabarra --post-id=877 --confirm
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/RankMathSeoValidator.php';

$opts = getopt('', ['site::', 'post-id::', 'dry-run', 'confirm']);
$site = (string)($opts['site'] ?? '');
$pid  = (int)($opts['post-id'] ?? 0);
$dryRun = isset($opts['dry-run']) || !isset($opts['confirm']);
if ($site === '' || $pid <= 0) { fwrite(STDERR, "uso: --site=SLUG --post-id=N [--confirm]\n"); exit(2); }

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $site);
$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode("{$cfg['wp_user']}:{$cfg['wp_app_password']}");

function wpReq(string $m, string $u, string $a, ?array $p = null): array {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $a, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($p !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($p, JSON_UNESCAPED_UNICODE));
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($c >= 400) throw new RuntimeException("HTTP {$c}: " . substr((string)$b, 0, 200));
    return json_decode((string)$b, true) ?: [];
}

$post = wpReq('GET', "{$base}/posts/{$pid}?context=edit&_fields=id,title,slug,content,meta,featured_media", $auth);
$titulo = trim(html_entity_decode(strip_tags((string)($post['title']['raw'] ?? $post['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'));
$slug   = (string)($post['slug'] ?? '');
$html   = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');
$metaAtual = $post['meta'] ?? [];

echo "═══ Post #{$pid} · {$site} ═══\n";
echo "Título: {$titulo}\n";
echo "Slug atual ({" . strlen($slug) . "} chars): {$slug}\n\n";

// 1. Deriva focus keyword do título
$kwNova = RankMathSeoValidator::derivarKeywordDoTitulo($titulo);
$kwAtual = (string)($metaAtual['rank_math_focus_keyword'] ?? '');
echo "Focus keyword:\n";
echo "  atual: '{$kwAtual}'\n";
echo "  nova:  '{$kwNova}'\n\n";

// 2. Meta description: garantir keyword presente + 140-160 chars
$descAtual = (string)($metaAtual['rank_math_description'] ?? '');
$temKwNaDesc = mb_stripos($descAtual, $kwNova) !== false;
echo "Meta description ({$temKwNaDesc} keyword presente):\n";
echo "  atual ({" . strlen($descAtual) . "}c): {$descAtual}\n";

$descNova = $descAtual;
if (!$temKwNaDesc || strlen($descAtual) < 100 || strlen($descAtual) > 165) {
    // Pega 1ª frase do conteúdo + insere keyword se não tiver
    $primeiraFrase = strip_tags($html);
    $primeiraFrase = preg_split('/\.\s+/', $primeiraFrase, 2)[0] ?? '';
    $primeiraFrase = mb_substr(trim($primeiraFrase), 0, 145);
    if (mb_stripos($primeiraFrase, $kwNova) === false) {
        $descNova = ucfirst($kwNova) . ': ' . $primeiraFrase;
    } else {
        $descNova = $primeiraFrase;
    }
    if (mb_strlen($descNova) > 158) $descNova = mb_substr($descNova, 0, 155) . '...';
    echo "  nova  ({" . mb_strlen($descNova) . "}c): {$descNova}\n";
}

// 3. Meta title: garantir keyword no início + número
$titAtual = (string)($metaAtual['rank_math_title'] ?? $titulo);
$kwNoInicio = mb_stripos(mb_substr($titAtual, 0, mb_strlen($kwNova) + 5), $kwNova) !== false;
$temNumero = preg_match('/\d/', $titAtual) === 1;
echo "\nMeta title:\n";
echo "  atual ({" . mb_strlen($titAtual) . "}c, kw_inicio={$kwNoInicio}, num={$temNumero}): {$titAtual}\n";

// 4. Slug: encurtar se >75
$slugNovo = $slug;
if (strlen($slug) > 75) {
    // Tenta gerar novo slug a partir da keyword
    $slugBase = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($kwNova, 'UTF-8'));
    $slugBase = transliterator_transliterate('Any-Latin; Latin-ASCII', $slugBase) ?: $slugBase;
    $slugBase = trim($slugBase, '-');
    $slugNovo = $slugBase;
    echo "\nSlug:\n  encurtar: {$slug} → {$slugNovo}\n";
}

// 5. Validador final (preview de score)
$ftMedia = null;
if (!empty($post['featured_media'])) {
    try {
        $fm = wpReq('GET', "{$base}/media/{$post['featured_media']}?_fields=alt_text", $auth);
        $ftMedia = (string)($fm['alt_text'] ?? '');
    } catch (Throwable $e) {}
}

$scoreNovo = RankMathSeoValidator::validar($html, [
    'titulo' => $titulo,
    'meta_title' => $titAtual,
    'meta_desc' => $descNova,
    'slug' => $slugNovo,
    'focus_keyword' => $kwNova,
    'featured_alt' => $ftMedia ?? '',
]);
echo "\n═══ Score RankMath previsto: {$scoreNovo['score']}/100 ({$scoreNovo['passes']}/{$scoreNovo['total']} checks) ═══\n";
foreach ($scoreNovo['fails'] as $f) {
    echo "  ✗ {$f['titulo']}" . ($f['detalhe'] ? " ({$f['detalhe']})" : '') . "\n";
}

if ($dryRun) { echo "\n[dry-run] sem aplicar\n"; exit(0); }

// 6. Aplica updates
$updates = [];
$metaUpdates = [
    'rank_math_focus_keyword' => $kwNova,
    'rank_math_description'   => $descNova,
];
if ($slugNovo !== $slug) $updates['slug'] = $slugNovo;
$updates['meta'] = $metaUpdates;

wpReq('POST', "{$base}/posts/{$pid}", $auth, $updates);
echo "\n✓ post #{$pid} atualizado: " . implode(', ', array_keys($updates)) . "\n";
