<?php
/**
 * scripts/inspect_imagem_post.php
 *
 * Inspeciona origem da imagem de um post: Pexels, DALL-E, og:image ou outro.
 * Lê: featured_media do WP + payload do trend (se associado).
 *
 * Uso:
 *   php scripts/inspect_imagem_post.php --site=SLUG --post-id=N [--trend-id=N]
 */

$siteArg  = '';
$postId   = 0;
$trendId  = 0;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--post-id=(\d+)$/', $a, $m)) $postId = (int)$m[1];
    if (preg_match('/^--trend-id=(\d+)$/', $a, $m)) $trendId = (int)$m[1];
}
if ($siteArg === '' || $postId <= 0) {
    fwrite(STDERR, "Uso: php scripts/inspect_imagem_post.php --site=SLUG --post-id=N [--trend-id=N]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
aplicarSite($cfg, sitesDisponiveis(), $siteArg);

require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/DbConnection.php';

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

echo "═══ Inspeção de imagem — post #{$postId} ═══\n\n";

try {
    $post = $wp->getPost($postId);
} catch (Throwable $e) {
    fwrite(STDERR, "Erro WP: " . $e->getMessage() . "\n"); exit(2);
}

$mediaId = (int)($post['featured_media'] ?? 0);
echo "Featured Media ID (WP): {$mediaId}\n";

if ($mediaId > 0) {
    try {
        $media = $wp->getMedia($mediaId);
        echo "URL local (WP): " . ($media['source_url'] ?? '?') . "\n";
        echo "Title:          " . ($media['title']['rendered'] ?? '?') . "\n";
        echo "Alt:            " . ($media['alt_text'] ?? '?') . "\n";
        echo "Caption:        " . trim(strip_tags($media['caption']['rendered'] ?? '')) . "\n";
        echo "Description:    " . trim(strip_tags($media['description']['rendered'] ?? '')) . "\n";
    } catch (Throwable $e) {
        echo "✗ getMedia falhou: " . $e->getMessage() . "\n";
    }
}

if ($trendId > 0) {
    echo "\n--- Payload do trend #{$trendId} (procurando metadata da imagem) ---\n";
    $pdo = DbConnection::pdo();
    $row = $pdo->prepare("SELECT payload FROM trends WHERE id=?");
    $row->execute([$trendId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $p = json_decode($r['payload'], true) ?: [];
        // Procura chaves relacionadas a imagem
        $imgKeys = ['imagem', 'imagem_meta', 'imagem_featured', 'imagem_seo', 'featured_media', 'hero_image'];
        foreach ($imgKeys as $k) {
            if (isset($p[$k])) {
                echo "\n[{$k}]\n";
                print_r($p[$k]);
            }
        }
        // Procura no objeto top-level por menções a fonte
        $jsonStr = json_encode($p, JSON_UNESCAPED_UNICODE);
        if (preg_match_all('/(pexels|dalle|dall-e|openai|og_image|og-image|fonte_imagem)[^"]*"[^"]*/i', $jsonStr, $hits)) {
            echo "\nMenções no payload:\n";
            foreach (array_unique($hits[0]) as $h) {
                echo "  - " . substr($h, 0, 120) . "\n";
            }
        } else {
            echo "\n(sem menções a pexels/dalle/og no payload)\n";
        }
    }
}

echo "\n═══ FIM ═══\n";
