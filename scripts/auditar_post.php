<?php
declare(strict_types=1);
/**
 * Auditoria completa de um post: estrutura HTML, datas, termos suspeitos,
 * schema.org, categorias, featured image, links externos. Pra revisar
 * antes de publicar OU detectar problemas em post draft.
 *
 * Uso:
 *   php scripts/auditar_post.php --site=cursosenac --post-id=4575
 *   php scripts/auditar_post.php --site=cursosenac --post-id=4575 --termos-suspeitos=vitoria,esporte,barradao
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';

$opts = getopt('', ['site::', 'post-id::', 'termos-suspeitos::']);
$siteSlug = (string)($opts['site'] ?? '');
$postId   = (int)($opts['post-id'] ?? 0);
$termosSusp = trim((string)($opts['termos-suspeitos'] ?? ''));
if ($siteSlug === '' || $postId <= 0) { fwrite(STDERR, "uso: --site=SLUG --post-id=N\n"); exit(2); }

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);
$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode("{$cfg['wp_user']}:{$cfg['wp_app_password']}");

function wpReq(string $m, string $u, string $a): array {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $a],
        CURLOPT_TIMEOUT => 30,
    ]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($c >= 400) throw new RuntimeException("HTTP {$c}: " . substr((string)$b, 0, 300));
    return json_decode((string)$b, true) ?: [];
}

$post = wpReq('GET', "{$base}/posts/{$postId}?context=edit&_embed=wp:featuredmedia,wp:term", $auth);
$titulo = trim(html_entity_decode(strip_tags((string)($post['title']['raw'] ?? $post['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'));
$content = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');
$status = (string)($post['status'] ?? '?');
$datePub = (string)($post['date'] ?? '');
$slug = (string)($post['slug'] ?? '');
$featuredId = (int)($post['featured_media'] ?? 0);
$featuredUrl = $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';

echo str_repeat('═', 70) . "\n";
echo " AUDITORIA · post #{$postId} · site={$siteSlug}\n";
echo str_repeat('═', 70) . "\n\n";

echo "TÍTULO   : {$titulo}\n";
echo "STATUS   : {$status}\n";
echo "SLUG     : {$slug}\n";
echo "DATA PUB : {$datePub}\n";
echo "URL      : {$cfg['wp_url']}/?p={$postId}\n";
echo "EDIT     : {$cfg['wp_url']}/wp-admin/post.php?post={$postId}&action=edit\n";
echo "FEATURED : " . ($featuredId > 0 ? "#{$featuredId} ({$featuredUrl})" : "❌ AUSENTE") . "\n";

// Categorias
$categorias = $post['_embedded']['wp:term'][0] ?? [];
$catNames = array_map(fn($c) => $c['name'], (array)$categorias);
echo "CATS     : " . (empty($catNames) ? '❌ AUSENTE' : implode(', ', $catNames)) . "\n\n";

// Estrutura HTML
echo "── ESTRUTURA HTML ──────────────────────────────────────────────\n";
$h1 = preg_match_all('#<h1\b[^>]*>(.*?)</h1>#is', $content, $h1m);
$h2 = preg_match_all('#<h2\b[^>]*>(.*?)</h2>#is', $content, $h2m);
$h3 = preg_match_all('#<h3\b[^>]*>(.*?)</h3>#is', $content, $h3m);
$ul = preg_match_all('#<ul\b#i', $content);
$ol = preg_match_all('#<ol\b#i', $content);
$tab = preg_match_all('#<table\b#i', $content);
$strong = preg_match_all('#<strong\b#i', $content);

echo "  H1: {$h1} " . ($h1 > 0 ? "🚨 (WP duplica — deve ser 0)" : "✓") . "\n";
foreach (($h1m[1] ?? []) as $i => $t) echo "      [{$i}] " . trim(strip_tags($t)) . "\n";
echo "  H2: {$h2}\n";
foreach (array_slice(($h2m[1] ?? []), 0, 6) as $i => $t) echo "      · " . trim(strip_tags($t)) . "\n";
echo "  H3: {$h3} · UL: {$ul} · OL: {$ol} · TAB: {$tab} · STRONG: {$strong}\n";

$plain = strip_tags($content);
$palavras = str_word_count($plain);
echo "  Palavras: {$palavras}\n";
if ($palavras < 400) echo "  ⚠️ curto demais (<400)\n";
if ($palavras > 1500) echo "  ⚠️ longo demais (>1500)\n";

// Datas mencionadas no texto
echo "\n── DATAS NO TEXTO ──────────────────────────────────────────────\n";
preg_match_all('/\b(\d{1,2})\s+de\s+(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:\s+de\s+(\d{4}))?\b/iu', $plain, $datas);
$datasUnicas = array_unique($datas[0] ?? []);
foreach ($datasUnicas as $d) echo "  · {$d}\n";
if (empty($datasUnicas)) echo "  (nenhuma)\n";

// Termos suspeitos (default: contaminação esporte/Vitória pra sites não-esporte)
$termosDefault = $siteSlug === 'leaodabarra'
    ? []  // leaodabarra é esporte, não suspeito
    : ['Vitória', 'Esporte Clube', 'Barradão', 'Maracanã', 'goleiro', 'atacante', 'zagueiro', 'CBF', 'Brasileirão', 'gol', 'estádio', 'rubro-negro', 'Leão da Barra'];
$termos = $termosSusp !== '' ? array_filter(array_map('trim', explode(',', $termosSusp))) : $termosDefault;

echo "\n── TERMOS SUSPEITOS (contaminação de outro nicho) ──────────────\n";
$achados = [];
foreach ($termos as $t) {
    $count = preg_match_all('/\b' . preg_quote($t, '/') . '\b/i', $plain);
    if ($count > 0) $achados[$t] = $count;
}
if (empty($achados)) echo "  ✓ nenhum termo suspeito\n";
else foreach ($achados as $t => $c) echo "  🚨 '{$t}' aparece {$c}x\n";

// Schema.org JSON-LD
echo "\n── SCHEMA.ORG JSON-LD ──────────────────────────────────────────\n";
$schemas = preg_match_all('#<script type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $content, $schemaM);
echo "  Blocos JSON-LD: {$schemas}\n";
foreach (($schemaM[1] ?? []) as $i => $sj) {
    $j = json_decode(trim($sj), true);
    if (is_array($j)) {
        $tipo = $j['@type'] ?? '?';
        echo "  [{$i}] @type={$tipo}";
        if ($tipo === 'NewsArticle') echo " (✓ adequado pra notícia)";
        elseif ($tipo === 'SportsEvent' && $siteSlug !== 'leaodabarra') echo " (🚨 INADEQUADO — site não é esporte)";
        echo "\n";
    } else echo "  [{$i}] ⚠️ JSON inválido\n";
}

// Links externos
echo "\n── LINKS EXTERNOS ──────────────────────────────────────────────\n";
preg_match_all('/<a\s+[^>]*href=["\']?(https?:\/\/[^"\'\s>]+)["\']?/i', $content, $linksM);
$dominioBase = preg_replace('#^https?://#', '', (string)$cfg['wp_url']);
$externos = [];
foreach (($linksM[1] ?? []) as $u) {
    $h = parse_url($u, PHP_URL_HOST) ?: '';
    if ($h !== '' && stripos($h, $dominioBase) === false) $externos[] = $h;
}
$externos = array_unique($externos);
foreach ($externos as $h) echo "  · {$h}\n";
if (empty($externos)) echo "  (nenhum)\n";

// Resumo problemas
echo "\n" . str_repeat('═', 70) . "\n";
echo " PROBLEMAS DETECTADOS\n";
echo str_repeat('═', 70) . "\n";
$probs = [];
if ($h1 > 0) $probs[] = "🚨 H1 no conteúdo ({$h1}x) — WP duplica";
if ($featuredId === 0) $probs[] = "❌ Featured image ausente";
if (empty($catNames)) $probs[] = "❌ Sem categoria atribuída";
if (!empty($achados)) $probs[] = "🚨 Termos suspeitos: " . implode(', ', array_keys($achados));
if ($palavras < 400) $probs[] = "⚠️ Conteúdo curto ({$palavras} palavras)";
if ($palavras > 1500) $probs[] = "⚠️ Conteúdo longo ({$palavras} palavras)";
if ($schemas === 0) $probs[] = "⚠️ Sem schema.org JSON-LD";

if (empty($probs)) echo "✓ NENHUM problema crítico\n";
else foreach ($probs as $p) echo "  · {$p}\n";
