<?php
/**
 * Regenera a imagem destacada do ÚLTIMO post de um site e atualiza no WP.
 *
 * Uso:
 *   php scripts/regerar_imagem_ultimo.php --site=cursosenac
 *
 * Fluxo:
 *   1. Carrega config + sites.php → pega credenciais do site
 *   2. WP REST: pega último post publicado (orderby=date, per_page=1)
 *   3. Constrói prompt via construirPromptImagem (mesmo do gerarpost.php)
 *   4. Chama OpenAI gerarImagemDetalhado (vivid + anti-rewrite prefix)
 *   5. Re-encoda (strip metadata C2PA), opcional queima overlay
 *   6. Sobe binário pro WP (uploadImagemBinario)
 *   7. PATCH no post: featured_media = novo ID
 *   8. Loga prompt enviado, prompt revisado, URL nova
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/OpenAI.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/ImagemOptimizer.php';
require_once __DIR__ . '/../lib/ImagemLayoutHighCTR.php';
require_once __DIR__ . '/../lib/PromptImagem.php';

// ====================================================================
// 1. Parse args
// ====================================================================
$siteSlug = '';
$queimar = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--site=')) {
        $siteSlug = trim(substr($arg, 7));
    }
    if ($arg === '--queimar' || $arg === '--queimar=1') {
        $queimar = true;
    }
}

if ($siteSlug === '') {
    fwrite(STDERR, "ERRO: passe --site=<slug>. Ex: --site=cursosenac\n");
    exit(1);
}

// ====================================================================
// 2. Carrega config do site
// ====================================================================
$todosSites = require __DIR__ . '/../sites.php';
if (!isset($todosSites[$siteSlug])) {
    fwrite(STDERR, "ERRO: site '$siteSlug' não encontrado em sites.php. Disponíveis: " . implode(', ', array_keys($todosSites)) . "\n");
    exit(2);
}

$cfgSite = $todosSites[$siteSlug];
$cfgGlobal = $GLOBALS['cfg'] ?? require __DIR__ . '/../config.php';

if (empty($cfgGlobal['openai_api_key'])) {
    fwrite(STDERR, "ERRO: OPENAI_API_KEY não definida em .env\n");
    exit(3);
}

echo "🎯 Site: {$cfgSite['name']} ({$cfgSite['wp_url']})\n";

// ====================================================================
// 3. Conecta WP e busca último post
// ====================================================================
$wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

$ch = curl_init(rtrim($cfgSite['wp_url'], '/') . '/wp-json/wp/v2/posts?per_page=1&orderby=date&order=desc&_fields=id,title,slug,link,excerpt,meta,featured_media');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode($cfgSite['wp_user'] . ':' . $cfgSite['wp_app_password'])],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    fwrite(STDERR, "ERRO: WP REST retornou HTTP $code: " . substr($resp, 0, 200) . "\n");
    exit(4);
}

$posts = json_decode($resp, true);
if (!is_array($posts) || empty($posts[0])) {
    fwrite(STDERR, "ERRO: nenhum post encontrado\n");
    exit(5);
}

$post = $posts[0];
$postId = (int) $post['id'];
$postTitle = is_array($post['title']) ? ($post['title']['rendered'] ?? '') : (string)$post['title'];
$postTitle = html_entity_decode(strip_tags($postTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$postSlug = $post['slug'] ?? '';
$postLink = $post['link'] ?? '';
$postExcerpt = is_array($post['excerpt']) ? ($post['excerpt']['rendered'] ?? '') : (string)($post['excerpt'] ?? '');
$postExcerpt = trim(html_entity_decode(strip_tags($postExcerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$rankMathDesc = $post['meta']['rank_math_description'] ?? '';
$keyword = $post['meta']['rank_math_focus_keyword'] ?? '';
$overlayMeta = $post['meta']['_clonais_image_overlay'] ?? '';

echo "📝 Post #{$postId}: {$postTitle}\n";
echo "🔗 {$postLink}\n";
echo "🔑 Keyword: " . ($keyword ?: '(sem rank_math_focus_keyword)') . "\n";

// ====================================================================
// 4. Constrói prompt
// ====================================================================
$contextoMeta = $rankMathDesc !== '' ? $rankMathDesc : $postExcerpt;
$overlayChamativo = clonais_derivar_overlay($postTitle, $contextoMeta, '', $overlayMeta);

echo "🎯 Overlay (sticker): {$overlayChamativo}\n";

$promptImg = construirPromptImagem(
    $postTitle,
    $keyword,
    $contextoMeta,
    '', // imagem_prompt do Claude — não temos no post, deixa vazio (PHP gera persona automática)
    $overlayChamativo
);

echo "\n📜 PROMPT ENVIADO (" . mb_strlen($promptImg) . " chars):\n";
echo "----------------------------------------\n";
echo $promptImg . "\n";
echo "----------------------------------------\n\n";

// ====================================================================
// 5. Gera imagem via DALL-E
// ====================================================================
echo "🎨 Chamando DALL-E 3 (natural, hd, 1792x1024)...\n";
$openai = new OpenAI($cfgGlobal['openai_api_key']);
// style 'natural' = MAIS REALISTA, MENOS marketing-y. ChatGPT mistura natural+vivid contextualmente.
$res = $openai->gerarImagemDetalhado($promptImg, '1792x1024', 'hd', 'natural', 'dall-e-3');

if (!$res || empty($res['url'])) {
    fwrite(STDERR, "ERRO: DALL-E não retornou URL\n");
    exit(6);
}

echo "✓ Imagem gerada: " . substr($res['url'], 0, 80) . "...\n";
if (!empty($res['revised_prompt'])) {
    echo "\n🔁 REVISED_PROMPT (o que DALL-E REALMENTE usou):\n";
    echo "----------------------------------------\n";
    echo $res['revised_prompt'] . "\n";
    echo "----------------------------------------\n\n";
}

// ====================================================================
// 6. Baixa, opcional queima overlay, sobe pro WP
// ====================================================================
echo "⬇️ Baixando imagem...\n";
$ch = curl_init($res['url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_SSL_VERIFYPEER => false,
]);
$bin = curl_exec($ch);
$dlCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($bin === false || $dlCode >= 400) {
    fwrite(STDERR, "ERRO: download da imagem falhou (HTTP $dlCode)\n");
    exit(7);
}
echo "✓ Baixada (" . round(strlen($bin) / 1024, 1) . " KB)\n";

// === LAYOUT HIGH-CTR (sempre aplicado em modo hybrid) ===
echo "🎨 Aplicando layout high-CTR (sticker + sub-label + badges + banner)...\n";

$stickerLines = clonais_split_overlay_sticker($overlayChamativo);
$subLabel     = clonais_extrair_sublabel($postTitle, $contextoMeta);
$badges       = clonais_extrair_info_badges($postTitle, $contextoMeta);
$diasUrgent   = clonais_dias_restantes($postTitle, $contextoMeta);

echo "  Sticker: \"{$stickerLines['line1']}\" / \"{$stickerLines['line2']}\"\n";
echo "  Sub-label: " . ($subLabel ?: '(sem deadline detectado)') . "\n";
echo "  Badges: " . count($badges) . " detectado(s)\n";
foreach ($badges as $i => $b) {
    echo "    " . ($i+1) . ". {$b['icon']} | {$b['label']} → {$b['value']}\n";
}
echo "  Banner urgência: " . ($diasUrgent !== null ? "$diasUrgent dias" : '(prazo > 30 dias ou não detectado)') . "\n";

$layout = new ImagemLayoutHighCTR();
$bin2 = $layout->aplicar($bin, [
    'sticker_l1'  => $stickerLines['line1'],
    'sticker_l2'  => $stickerLines['line2'],
    'sub_label'   => $subLabel,
    'badges'      => $badges,
    'urgent_dias' => $diasUrgent,
], 88);

if ($bin2) {
    $bin = $bin2;
    echo "✓ Layout aplicado\n";
} else {
    echo "⚠️ Falha ao aplicar layout (segue sem)\n";
}

echo "⬆️ Subindo pro WordPress...\n";
$slugBase = ($postSlug ?: 'post-' . $postId) . '-v2';
$mediaId = $wp->uploadImagemBinario($bin, $slugBase, $postTitle, 'jpg');
if (!$mediaId) {
    fwrite(STDERR, "ERRO: upload pro WP falhou\n");
    exit(8);
}
echo "✓ Media #{$mediaId} criada\n";

// ====================================================================
// 7. PATCH no post: troca featured_media
// ====================================================================
echo "🔁 Atualizando featured_media do post #{$postId}...\n";

$ch = curl_init(rtrim($cfgSite['wp_url'], '/') . '/wp-json/wp/v2/posts/' . $postId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode(['featured_media' => $mediaId]),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Basic ' . base64_encode($cfgSite['wp_user'] . ':' . $cfgSite['wp_app_password']),
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    fwrite(STDERR, "ERRO: PATCH no post falhou (HTTP $code): " . substr($resp, 0, 200) . "\n");
    exit(9);
}

echo "\n✅ SUCESSO\n";
echo "   Post: {$postLink}\n";
echo "   Nova featured: media #{$mediaId}\n";
echo "   Prompt enviado: " . mb_strlen($res['prompt_enviado']) . " chars\n";
echo "   Style: {$res['style']} · Size: {$res['size']} · Quality: {$res['quality']}\n";
