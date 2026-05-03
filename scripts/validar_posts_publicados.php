<?php
declare(strict_types=1);
/**
 * scripts/validar_posts_publicados.php
 *
 * Validação automática diária de posts publicados nas últimas N horas.
 * Roda AntiAIValidator + checks estruturais + duplicação de schemas no front.
 * Se detectar problemas → cria WP draft "🚨 AUDIT QA posts {site} {data}".
 *
 * Cron sugerido (1x/dia 09:00 BR = 12:00 UTC):
 *   0 12 * * * root php /app/scripts/validar_posts_publicados.php --site=leaodabarra >> /var/log/qa_posts.log 2>&1
 *   5 12 * * * root php /app/scripts/validar_posts_publicados.php --site=cursosenac >> /var/log/qa_posts.log 2>&1
 *
 * Modos:
 *   --site=SLUG          obrigatório
 *   --horas=N            janela retroativa (default 24)
 *   --max-posts=N        máximo por execução (default 20)
 *   --dry-run            só relata, não cria draft
 *   --force-draft        cria draft mesmo sem alertas (daily health check)
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/AntiAIValidator.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$opts = getopt('', ['site::', 'horas::', 'max-posts::', 'dry-run', 'force-draft']);
$siteSlug = (string)($opts['site'] ?? '');
$horas    = (int)($opts['horas'] ?? 24);
$maxPosts = (int)($opts['max-posts'] ?? 20);
$dryRun   = isset($opts['dry-run']);
$forceDraft = isset($opts['force-draft']);
if ($siteSlug === '') { fwrite(STDERR, "uso: --site=SLUG [--horas=24] [--max-posts=20]\n"); exit(2); }

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode("{$cfg['wp_user']}:{$cfg['wp_app_password']}");
$rankmathOn = !empty($cfg['rankmath_handles_schemas']);

function wpReq(string $m, string $u, string $a): array {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $a],
        CURLOPT_TIMEOUT => 30,
    ]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($c >= 400) return [];
    return json_decode((string)$b, true) ?: [];
}

// Busca posts publicados últimas N horas
$after = gmdate('c', time() - $horas * 3600);
$url = "{$base}/posts?per_page={$maxPosts}&orderby=date&order=desc&after={$after}&status=publish&_fields=id,title,link,content,date";
$posts = wpReq('GET', $url, $auth);

echo "═══ QA AUTOMÁTICO · {$siteSlug} · janela {$horas}h · " . date('Y-m-d H:i') . " ═══\n\n";
echo "Posts publicados na janela: " . count($posts) . "\n\n";

if (empty($posts)) {
    echo "Nenhum post publicado — saindo.\n";
    exit(0);
}

$validator = new AntiAIValidator();
$relatorio = ['site' => $siteSlug, 'janela_horas' => $horas, 'data' => date('c'), 'posts' => []];
$totalAlertas = 0;

foreach ($posts as $p) {
    $pid = (int)$p['id'];
    $titulo = trim(html_entity_decode(strip_tags((string)($p['title']['rendered'] ?? '')), ENT_QUOTES, 'UTF-8'));
    $content = (string)($p['content']['rendered'] ?? '');
    $linkPub = (string)($p['link'] ?? '');

    $issues = [];

    // 1. AntiAIValidator
    $ai = $validator->validate($content);
    if ($ai['severity'] !== 'ok') {
        foreach (array_slice($ai['violations'] ?? [], 0, 5) as $v) {
            $issues[] = "anti-ai: [{$v['category']}] '{$v['phrase']}' x{$v['count']}";
        }
        foreach (array_slice($ai['structural'] ?? [], 0, 3) as $s) {
            $issues[] = "estrutural: {$s}";
        }
    }

    // 2. H1 no content (WP duplica)
    if (preg_match_all('#<h1\b[^>]*>#i', $content, $h1m)) {
        $issues[] = "H1 duplicado no content (" . count($h1m[0]) . "x)";
    }

    // 3. Duplicação de schemas no front-end (curl + count tipos)
    $ch = curl_init($linkPub);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 QA-bot',
    ]);
    $front = curl_exec($ch);
    curl_close($ch);
    if ($front && preg_match_all('/"@type":"([A-Za-z]+)"/', $front, $typeM)) {
        $counts = array_count_values($typeM[1]);
        foreach (['NewsArticle', 'Article', 'BreadcrumbList', 'WebPage', 'Organization', 'Person', 'PostalAddress'] as $tipo) {
            $n = $counts[$tipo] ?? 0;
            if ($n > 1) $issues[] = "schema duplicado: {$tipo} aparece {$n}x no front";
        }
    }

    $relatorio['posts'][] = [
        'id' => $pid, 'titulo' => $titulo, 'link' => $linkPub,
        'severity' => $ai['severity'] ?? '?',
        'issues' => $issues,
    ];
    $totalAlertas += count($issues);

    $marker = empty($issues) ? '✓' : '🚨';
    echo "{$marker} #{$pid} · {$titulo}\n";
    foreach (array_slice($issues, 0, 3) as $iss) echo "    · {$iss}\n";
    if (count($issues) > 3) echo "    · ... +" . (count($issues) - 3) . " issues\n";
    echo "\n";
}

echo "─── Resumo ───\n";
echo "Posts validados: " . count($posts) . "\n";
echo "Total de issues: {$totalAlertas}\n";

if ($dryRun) { echo "[dry-run] sem criar draft\n"; exit(0); }

// Cria WP draft de auditoria SE houver alertas (ou --force-draft)
if ($totalAlertas === 0 && !$forceDraft) {
    echo "Tudo limpo — sem alerta, sem draft criado.\n";
    exit(0);
}

$dataLabel = date('d/m');
$titAudit = ($totalAlertas > 0 ? "🚨" : "📊") . " QA posts {$siteSlug} {$dataLabel} · {$totalAlertas} issues";

$html = "<h2>Auditoria automática de posts publicados</h2>\n";
$html .= "<p><strong>Site:</strong> {$siteSlug} · <strong>Janela:</strong> {$horas}h · <strong>Posts validados:</strong> " . count($posts) . " · <strong>Issues totais:</strong> {$totalAlertas}</p>\n";

foreach ($relatorio['posts'] as $r) {
    $marker = empty($r['issues']) ? '✓' : '🚨';
    $editLink = "{$cfg['wp_url']}/wp-admin/post.php?post={$r['id']}&action=edit";
    $html .= "<h3>{$marker} #{$r['id']} — " . htmlspecialchars($r['titulo'], ENT_QUOTES, 'UTF-8') . "</h3>\n";
    $html .= "<p>Severity: <strong>{$r['severity']}</strong> · <a href='{$editLink}'>Editar post</a> · <a href='{$r['link']}'>Ver no front</a></p>\n";
    if (!empty($r['issues'])) {
        $html .= "<ul>\n";
        foreach ($r['issues'] as $i) $html .= "<li>" . htmlspecialchars($i, ENT_QUOTES, 'UTF-8') . "</li>\n";
        $html .= "</ul>\n";
    }
}
$html .= "<hr><p><small>Gerado por validar_posts_publicados.php · " . date('c') . "</small></p>";

try {
    $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    $auditPost = $wp->criarPost([
        'title'   => $titAudit,
        'content' => $html,
        'status'  => 'draft',
    ]);
    $auditId = (int)($auditPost['id'] ?? 0);
    echo "✓ AUDIT POST criado #{$auditId} (status=draft)\n";
    echo "  Edit: {$cfg['wp_url']}/wp-admin/post.php?post={$auditId}&action=edit\n";
} catch (Throwable $e) {
    echo "⚠️ falha ao criar audit post: " . $e->getMessage() . "\n";
}
