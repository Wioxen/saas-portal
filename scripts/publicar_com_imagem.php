<?php
declare(strict_types=1);
/**
 * Publica um post WP definindo featured_media + atualizando metadados da imagem
 * (title, caption, description, alt) baseado no título do post.
 *
 * Uso:
 *   php scripts/publicar_com_imagem.php \
 *     --site=leaodabarra --post-id=810 \
 *     --image-url=https://leaodabarra.com.br/wp-content/uploads/2026/05/x.webp
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$opts = getopt('', ['site::', 'post-id::', 'image-url::', 'caption::', 'dry-run', 'keep-draft']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$postId   = (int)($opts['post-id'] ?? 0);
$imageUrl = (string)($opts['image-url'] ?? '');
$captionExtra = (string)($opts['caption'] ?? '');
$dryRun   = isset($opts['dry-run']);
$keepDraft = isset($opts['keep-draft']);

if ($postId <= 0 || $imageUrl === '') {
    fwrite(STDERR, "uso: --post-id=N --image-url=URL\n"); exit(2);
}

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);
$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode("{$cfg['wp_user']}:{$cfg['wp_app_password']}");

function wpReq(string $method, string $url, string $auth, ?array $payload = null): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Basic ' . $auth, 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new RuntimeException("HTTP {$code} em {$method} {$url}: " . substr((string)$body, 0, 300));
    return json_decode((string)$body, true) ?: [];
}

// 1. Lê post
$post = wpReq('GET', "{$base}/posts/{$postId}?context=edit", $auth);
$tituloPost = (string)($post['title']['raw'] ?? $post['title']['rendered'] ?? '');
$tituloPost = trim(html_entity_decode(strip_tags($tituloPost), ENT_QUOTES, 'UTF-8'));
echo "[post] #{$postId} título='{$tituloPost}'\n";

// 2. Localiza media pelo slug
$slugFile = basename(parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl);
$slug = preg_replace('/\.(webp|jpg|jpeg|png)$/i', '', $slugFile);
echo "[media] buscando slug='{$slug}'\n";

$mediaList = wpReq('GET', "{$base}/media?slug=" . urlencode((string)$slug) . "&per_page=5", $auth);
if (empty($mediaList) || empty($mediaList[0]['id'])) {
    echo "[media] tentando search='{$slug}'...\n";
    $mediaList = wpReq('GET', "{$base}/media?search=" . urlencode((string)$slug) . "&per_page=5", $auth);
}

if (empty($mediaList) || empty($mediaList[0]['id'])) {
    // Não existe media registrada — fazer sideload completo da URL pra criar attachment
    echo "[media] não registrada — fazendo sideload via Wordpress::uploadImagemPorUrl()\n";
    $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    $mediaId = $wp->uploadImagemPorUrl($imageUrl, $tituloPost, $slug);
    if (!$mediaId) {
        fwrite(STDERR, "[erro] sideload falhou. URL acessível?\n"); exit(3);
    }
    echo "[media] sideload ok id={$mediaId}\n";
} else {
    $media = $mediaList[0];
    $mediaId = (int)$media['id'];
    echo "[media] encontrada id={$mediaId} url={$media['source_url']}\n";
}

// 3. Compõe metadata
$cap = $captionExtra !== '' ? $captionExtra : $tituloPost;
$desc = "Imagem ilustrativa da matéria: {$tituloPost}";
$alt = $tituloPost;
$title = $tituloPost;

echo "[media] aplicando metadata...\n  · title: {$title}\n  · caption: {$cap}\n  · description: {$desc}\n  · alt: {$alt}\n";
if ($dryRun) { echo "\n[dry-run] sem aplicar\n"; exit(0); }

wpReq('POST', "{$base}/media/{$mediaId}", $auth, [
    'title'       => $title,
    'caption'     => $cap,
    'description' => $desc,
    'alt_text'    => $alt,
]);
echo "[media] metadata atualizada\n";

// 4. Atualiza post: featured_media (+ status=publish se não --keep-draft)
$payload = ['featured_media' => $mediaId];
if (!$keepDraft) $payload['status'] = 'publish';

$postUpd = wpReq('POST', "{$base}/posts/{$postId}", $auth, $payload);
$statusFinal = $keepDraft ? 'draft (mantido)' : 'publish';
echo "[post] #{$postId} status={$statusFinal}, featured_media={$mediaId}\n";
if ($keepDraft) {
    echo "[ok] DRAFT: {$cfg['wp_url']}/wp-admin/post.php?post={$postId}&action=edit\n";
} else {
    echo "[ok] LIVE: " . ($postUpd['link'] ?? "{$cfg['wp_url']}/?p={$postId}") . "\n";
}
