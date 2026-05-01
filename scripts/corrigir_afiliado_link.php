<?php
/**
 * scripts/corrigir_afiliado_link.php
 *
 * Corrige o bloco de afiliado em um post já publicado trocando o link
 * de /go.php?s=SLUG para o Pretty Link /{prefix}/{slug}.
 *
 * Uso:
 *   /c/xampp/php/php.exe scripts/corrigir_afiliado_link.php --site=SLUG --post=POST_ID
 */

$siteArg = '';
$postId = 0;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--post=(\d+)$/', $a, $m))  $postId = (int)$m[1];
}
if ($siteArg === '' || $postId <= 0) {
    fwrite(STDERR, "Uso: php scripts/corrigir_afiliado_link.php --site=SLUG --post=POST_ID\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteArg);
require_once __DIR__ . '/../lib/Wordpress.php';

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$post = $wp->getPost($postId);
$content = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';
if ($content === '') { fwrite(STDERR, "Post vazio ou inacessível.\n"); exit(1); }

$base   = rtrim($cfg['wp_url'], '/');
$prefix = trim((string)($cfg['pretty_links_prefix'] ?? 'go'), '/');

// Regex: /go.php?s=SLUG[&t=X][&p=Y] → /{prefix}/{slug}
$antes = $content;
$novo = preg_replace_callback(
    '#https?://[^/\s"\']+/go\.php\?s=([a-z0-9-]+)(?:&[^"\'\s]*)?#i',
    function($m) use ($base, $prefix) {
        return $base . '/' . $prefix . '/' . $m[1];
    },
    $content
);

// Também aceita /go.php relativo (sem host)
$novo = preg_replace_callback(
    '#(?<!:)/go\.php\?s=([a-z0-9-]+)(?:&[^"\'\s]*)?#',
    function($m) use ($base, $prefix) {
        return $base . '/' . $prefix . '/' . $m[1];
    },
    $novo
);

$mudancas = substr_count($antes, '/go.php?s=');
if ($mudancas === 0) {
    echo "Nenhum link /go.php?s= encontrado no post #{$postId}. Nada a corrigir.\n";
    exit(0);
}
echo "Corrigindo {$mudancas} ocorrência(s) de /go.php?s= no post #{$postId}...\n";

$r = $wp->atualizarPost($postId, ['content' => $novo]);
if (is_array($r) && !empty($r['id'])) {
    echo "✓ Post atualizado. Edit URL: " . $base . "/wp-admin/post.php?post={$postId}&action=edit\n";
    exit(0);
}
fwrite(STDERR, "Falha ao atualizar: " . json_encode($r) . "\n");
exit(1);
