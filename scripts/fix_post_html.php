<?php
declare(strict_types=1);
/**
 * Limpa HTML de post existente (strip h1, normaliza), mantém schema JSON-LD.
 * Útil pra reparar posts que vieram com h1 duplicado ou data errada do Sonnet.
 *
 * Uso:
 *   php scripts/fix_post_html.php --site=cursosenac --post-id=4575 --dry-run
 *   php scripts/fix_post_html.php --site=cursosenac --post-id=4575
 *   php scripts/fix_post_html.php --site=cursosenac --post-id=4575 --replace="04 de agosto/04 de maio"
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';

$opts = getopt('', ['site::', 'post-id::', 'dry-run', 'replace::']);
$siteSlug = (string)($opts['site'] ?? '');
$postId   = (int)($opts['post-id'] ?? 0);
$dryRun   = isset($opts['dry-run']);
$replace  = (string)($opts['replace'] ?? '');  // formato "antigo/novo"
if ($siteSlug === '' || $postId <= 0) { fwrite(STDERR, "uso: --site=SLUG --post-id=N [--replace='antigo/novo']\n"); exit(2); }

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

$post = wpReq('GET', "{$base}/posts/{$postId}?context=edit", $auth);
$titulo = trim(html_entity_decode(strip_tags((string)($post['title']['raw'] ?? $post['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'));
$content = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');

echo "[post] #{$postId} '{$titulo}'\n";
echo "[len] original: " . strlen($content) . " bytes\n";

$mudancas = [];

// 1. Strip H1
$h1Count = preg_match_all('#<h1\b[^>]*>(.*?)</h1>#is', $content, $matchesH1);
if ($h1Count > 0) {
    foreach (($matchesH1[1] ?? []) as $h1Texto) {
        echo "  · H1 encontrado: '" . trim(strip_tags($h1Texto)) . "'\n";
    }
    $content = preg_replace('#<h1\b[^>]*>.*?</h1>\s*#is', '', $content) ?? $content;
    $mudancas[] = "removido(s) {$h1Count} H1";
}

// 2. Replace texto custom (--replace='antigo/novo')
if ($replace !== '' && str_contains($replace, '/')) {
    [$antigo, $novo] = array_map('trim', explode('/', $replace, 2));
    $countRepl = substr_count($content, $antigo);
    if ($countRepl > 0) {
        $content = str_replace($antigo, $novo, $content);
        $mudancas[] = "replace '{$antigo}' → '{$novo}' ({$countRepl}x)";
    }
}

if (empty($mudancas)) {
    echo "[ok] nenhuma mudança necessária\n";
    exit(0);
}

echo "[mudancas]\n";
foreach ($mudancas as $m) echo "  · {$m}\n";
echo "[len] novo: " . strlen($content) . " bytes\n";

if ($dryRun) { echo "\n[dry-run] sem gravar\n"; exit(0); }

wpReq('POST', "{$base}/posts/{$postId}", $auth, ['content' => $content]);
echo "[ok] post #{$postId} atualizado\n";
