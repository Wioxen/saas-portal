<?php
declare(strict_types=1);
/**
 * Revisa posts existentes via AutoRevisor (Haiku 4.5) — reescreve trechos
 * com padrões IA mantendo voz/persona/fatos. NÃO regera do zero.
 *
 * Uso:
 *   php scripts/revisar_posts_haiku.php --site=cursosenac --horas=72 --dry-run
 *   php scripts/revisar_posts_haiku.php --site=cursosenac --horas=72 --confirm
 *   php scripts/revisar_posts_haiku.php --site=leaodabarra --post-ids=810,811,817 --confirm
 *
 * Custo: ~$0.02 por post via Haiku 4.5 (10x mais barato que Sonnet).
 * Mantém slug + URL + indexação Google (não trash, só atualiza content).
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/AntiAIValidator.php';
require_once __DIR__ . '/../lib/AutoRevisor.php';

$opts = getopt('', ['site::', 'horas::', 'post-ids::', 'max-posts::', 'dry-run', 'confirm']);
$siteSlug = (string)($opts['site'] ?? '');
$horas    = (int)($opts['horas'] ?? 0);
$postIdsRaw = (string)($opts['post-ids'] ?? '');
$maxPosts = (int)($opts['max-posts'] ?? 20);
$dryRun   = isset($opts['dry-run']);
$confirm  = isset($opts['confirm']);

if ($siteSlug === '' || ($horas === 0 && $postIdsRaw === '')) {
    fwrite(STDERR, "uso: --site=SLUG (--horas=N OR --post-ids=X,Y) [--dry-run | --confirm]\n");
    exit(2);
}
if (!$dryRun && !$confirm) {
    fwrite(STDERR, "Pra aplicar use --confirm. Default é --dry-run.\n");
    $dryRun = true;
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
    if ($c >= 400) return ['_erro' => "HTTP {$c}: " . substr((string)$b, 0, 200)];
    return json_decode((string)$b, true) ?: [];
}

// Coleta posts a revisar
$postIds = [];
if ($postIdsRaw !== '') {
    foreach (explode(',', $postIdsRaw) as $i) {
        $i = (int)trim($i); if ($i > 0) $postIds[] = $i;
    }
} else {
    $after = date('Y-m-d\TH:i:s', time() - $horas * 3600);
    $list = wpReq('GET', "{$base}/posts?per_page={$maxPosts}&orderby=date&order=desc&after=" . urlencode($after) . "&status=publish&_fields=id", $auth);
    foreach ($list as $p) if (!empty($p['id'])) $postIds[] = (int)$p['id'];
}

if (empty($postIds)) { echo "Nenhum post pra revisar.\n"; exit(0); }

echo "═══ Revisão Haiku · {$siteSlug} · " . count($postIds) . " posts · " . ($dryRun ? 'DRY-RUN' : 'APLICAR') . " ═══\n\n";

$persona = (array)($cfg['persona'] ?? []);
$contexto = [
    'site_name'      => (string)($cfg['site_name'] ?? $siteSlug),
    'persona_autor'  => (string)($persona['autor'] ?? "Equipe " . ($cfg['site_name'] ?? '')),
    'persona_voz'    => (string)($persona['voz'] ?? 'jornalística direta'),
    'persona_tom'    => (string)($persona['tom'] ?? 'direto e factual'),
    'subtipo_nicho'  => (string)($cfg['subtipo_nicho'] ?? ''),
];

$validator = new AntiAIValidator();
$revisor = new AutoRevisor((string)$cfg['anthropic_api_key']);

$revisados = 0; $skipped = 0; $erros = 0;

foreach ($postIds as $i => $pid) {
    $post = wpReq('GET', "{$base}/posts/{$pid}?context=edit&_fields=id,title,content", $auth);
    if (!empty($post['_erro'])) { echo "[" . ($i+1) . "/" . count($postIds) . "] #{$pid} ✗ {$post['_erro']}\n"; $erros++; continue; }

    $titulo = trim(html_entity_decode(strip_tags((string)($post['title']['raw'] ?? $post['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'));
    $content = (string)($post['content']['raw'] ?? $post['content']['rendered'] ?? '');

    $r = $validator->validate($content);
    echo "[" . ($i+1) . "/" . count($postIds) . "] #{$pid} severity={$r['severity']} | " . substr($titulo, 0, 55) . "\n";

    if ($r['severity'] === 'ok') { echo "    ✓ já está ok — skip\n"; $skipped++; continue; }

    if ($dryRun) { echo "    [dry-run] revisaria " . $r['total_phrase_violations'] . " viol + " . count($r['structural']) . " estrutural\n"; continue; }

    $rev = $revisor->revisar($content, $contexto);
    if (!empty($rev['reescreveu']) && !empty($rev['html'])) {
        $upd = wpReq('POST', "{$base}/posts/{$pid}", $auth, ['content' => $rev['html']]);
        if (!empty($upd['_erro'])) { echo "    ✗ falha update WP: {$upd['_erro']}\n"; $erros++; continue; }
        $sevAntes = $rev['antes']['severity'] ?? '?';
        $sevDepois = $rev['depois']['severity'] ?? '?';
        echo "    ✓ revisado: {$sevAntes} → {$sevDepois}\n";
        $revisados++;
    } else {
        echo "    ✗ Haiku não devolveu html: " . ($rev['erro'] ?? '?') . "\n";
        $erros++;
    }
}

echo "\n─── Resumo ───\n";
echo "Revisados: {$revisados}  ·  Skipped (ok): {$skipped}  ·  Erros: {$erros}\n";
echo "Custo estimado: \$" . number_format($revisados * 0.02, 2) . "\n";
