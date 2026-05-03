<?php
declare(strict_types=1);
/**
 * Remove TODO bloco <script type="application/ld+json"> do content do post.
 * Útil quando RankMath está handling schemas no <head> e o content tem
 * schemas legados injetados antes da flag rankmath_handles_schemas existir.
 *
 * Uso:
 *   php scripts/clean_schemas_from_post.php --site=leaodabarra --post-id=810 [--dry-run]
 *   php scripts/clean_schemas_from_post.php --site=leaodabarra --post-ids=810,811
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';

$opts = getopt('', ['site::', 'post-id::', 'post-ids::', 'dry-run']);
$siteSlug = (string)($opts['site'] ?? '');
$postId   = (int)($opts['post-id'] ?? 0);
$postIds  = (string)($opts['post-ids'] ?? '');
$dryRun   = isset($opts['dry-run']);

$ids = [];
if ($postId > 0) $ids[] = $postId;
if ($postIds !== '') {
    foreach (explode(',', $postIds) as $i) {
        $i = (int)trim($i);
        if ($i > 0) $ids[] = $i;
    }
}
if (empty($ids) || $siteSlug === '') {
    fwrite(STDERR, "uso: --site=SLUG --post-id=N OR --post-ids=N1,N2,...\n"); exit(2);
}

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);
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
    if ($c >= 400) throw new RuntimeException("HTTP {$c}: " . substr((string)$b, 0, 300));
    return json_decode((string)$b, true) ?: [];
}

foreach ($ids as $pid) {
    $post = wpReq('GET', "{$base}/posts/{$pid}?context=edit", $auth);
    $titulo = trim(html_entity_decode(strip_tags((string)($post['title']['raw'] ?? $post['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'));
    $content = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');

    $count = preg_match_all('#<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>#is', $content, $m);
    echo "[#{$pid}] '{$titulo}'\n  · JSON-LD blocos no content: {$count}\n";
    if ($count > 0) {
        foreach (($m[0] ?? []) as $i => $bloco) {
            $jsonStr = preg_replace('#</?script[^>]*>#i', '', $bloco);
            $j = json_decode(trim($jsonStr ?? ''), true);
            $tipo = is_array($j) ? ($j['@type'] ?? '?') : '?';
            echo "    [{$i}] @type={$tipo} (" . strlen($bloco) . " bytes)\n";
        }
    }

    if ($count === 0) { echo "  → nada a remover\n\n"; continue; }
    if ($dryRun) { echo "  [dry-run] sem aplicar\n\n"; continue; }

    $contentLimpo = preg_replace('#\s*<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>\s*#is', "\n", $content) ?? $content;
    $contentLimpo = trim($contentLimpo);
    wpReq('POST', "{$base}/posts/{$pid}", $auth, ['content' => $contentLimpo]);
    echo "  ✓ removidos {$count} blocos JSON-LD do content\n  ✓ post #{$pid} atualizado\n\n";
}
