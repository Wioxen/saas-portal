<?php
/**
 * contradiction_detector.php — cron diário que detecta contradições factuais
 * entre posts da mesma entidade publicados em janela curta (default 7 dias).
 *
 * Uso:
 *   php scripts/contradiction_detector.php --site=cursosenac                  # dry-run
 *   php scripts/contradiction_detector.php --site=cursosenac --confirm        # cria draft alerta
 *   php scripts/contradiction_detector.php --site=cursosenac --confirm --quiet --janela=7
 *
 * Pipeline:
 *   1. Lê data/entity_pages_cache/{site}_aliases.json (entidades configuradas)
 *   2. Pra cada entidade: posts dos últimos N dias → EntityExtractor → diff
 *   3. Log JSON em data/contradictions/{date}_{site}.json
 *   4. (--confirm) cria post draft no WP listando contradições
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? '');
$dryRun = empty($args['confirm']);
$janela = (int)($args['janela'] ?? 7);
$quiet = !empty($args['quiet']);

if ($siteSlug === '') {
    fwrite(STDERR, "uso: php contradiction_detector.php --site=SLUG [--confirm] [--janela=7] [--quiet]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/EntityExtractor.php';
require_once __DIR__ . '/../lib/ContradictionDetector.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$say = function (string $msg) use ($quiet) {
    if (!$quiet) echo $msg . "\n";
};

$say("═══ Contradiction Detector — site={$siteSlug} | janela={$janela}d | " . ($dryRun ? 'DRY-RUN' : 'EXECUTAR') . " ═══");

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$detector = new ContradictionDetector($wp, $siteSlug, $janela);
$resultado = $detector->detectar();

if (!empty($resultado['erro'])) {
    $say('✗ ' . $resultado['erro']);
    exit(0);
}

$say("entidades_analisadas: " . $resultado['entidades_analisadas']);
$say("posts_analisados: " . $resultado['posts_analisados']);
$say("contradições encontradas: " . count($resultado['contradicoes']));

// Log JSON
$logDir = __DIR__ . '/../data/contradictions';
@mkdir($logDir, 0775, true);
$logPath = "{$logDir}/" . date('Y-m-d') . "_{$siteSlug}.json";
file_put_contents($logPath, json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$say("log: $logPath");

if (empty($resultado['contradicoes'])) {
    $say('✓ sem contradições');
    exit(0);
}

if (!$quiet) {
    foreach ($resultado['contradicoes'] as $c) {
        $say("  ⚠ [{$c['entidade']}] {$c['tipo']} — contexto: " . implode(', ', $c['contexto']));
        $say("     A) #{$c['post_a']['id']} — " . mb_substr($c['post_a']['titulo'], 0, 60) . " :: " . json_encode($c['fatos_a'], JSON_UNESCAPED_UNICODE));
        $say("     B) #{$c['post_b']['id']} — " . mb_substr($c['post_b']['titulo'], 0, 60) . " :: " . json_encode($c['fatos_b'], JSON_UNESCAPED_UNICODE));
    }
}

if ($dryRun) {
    $say("\n[dry-run] não criou WP draft. Use --confirm pra publicar alerta.");
    exit(0);
}

// Cria post draft de alerta no WP
$titAudit = '⚠️ AUDIT — ' . count($resultado['contradicoes']) . " contradição(ões) factuais detectadas " . date('d/m/Y');
$html = "<p><strong>Janela:</strong> últimos {$janela} dias · <strong>Entidades analisadas:</strong> {$resultado['entidades_analisadas']} · <strong>Posts analisados:</strong> {$resultado['posts_analisados']}</p>\n<hr>\n";

foreach ($resultado['contradicoes'] as $c) {
    $html .= "<h3>[{$c['entidade']}] {$c['tipo']} — contexto: " . htmlspecialchars(implode(', ', $c['contexto'])) . "</h3>\n";
    $html .= "<ul>\n";
    $html .= "<li><strong>Post A #{$c['post_a']['id']}</strong> — <a href=\"{$c['post_a']['link']}\">" . htmlspecialchars($c['post_a']['titulo']) . "</a><br>"
           . "Fatos: <code>" . htmlspecialchars(json_encode($c['fatos_a'], JSON_UNESCAPED_UNICODE)) . "</code><br>"
           . "<a href=\"{$cfg['wp_url']}/wp-admin/post.php?post={$c['post_a']['id']}&action=edit\">[editar]</a></li>\n";
    $html .= "<li><strong>Post B #{$c['post_b']['id']}</strong> — <a href=\"{$c['post_b']['link']}\">" . htmlspecialchars($c['post_b']['titulo']) . "</a><br>"
           . "Fatos: <code>" . htmlspecialchars(json_encode($c['fatos_b'], JSON_UNESCAPED_UNICODE)) . "</code><br>"
           . "<a href=\"{$cfg['wp_url']}/wp-admin/post.php?post={$c['post_b']['id']}&action=edit\">[editar]</a></li>\n";
    $html .= "</ul>\n";
}
$html .= "<hr><p><small>Gerado por contradiction_detector.php · " . date('c') . " · Janela {$janela}d</small></p>";

try {
    $auditPost = $wp->criarPost([
        'title'   => $titAudit,
        'content' => $html,
        'status'  => 'draft',
    ]);
    $auditId = (int)($auditPost['id'] ?? 0);
    $say("✓ AUDIT POST criado #{$auditId} (status=draft)");
    $say("  edit: {$cfg['wp_url']}/wp-admin/post.php?post={$auditId}&action=edit");
} catch (Throwable $e) {
    fwrite(STDERR, "⚠️ falha ao criar audit post: " . $e->getMessage() . "\n");
    exit(1);
}
