<?php
/**
 * scripts/reaplicar_postprocess.php
 *
 * Re-aplica DiscoverPostProcess::processar num post WP já criado.
 * Útil quando a geração original descartou o resultado por causa dos checks
 * de tamanho/estrutura — o conteúdo bruto do LLM ficou no WP sem limpeza.
 *
 * Uso:
 *   php scripts/reaplicar_postprocess.php --site=SLUG --post-id=N [--dry-run]
 *
 * --dry-run: não atualiza WP, só mostra diff.
 */

$siteArg = '';
$postId  = 0;
$dryRun  = false;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--post-id=(\d+)$/', $a, $m)) $postId = (int)$m[1];
    if ($a === '--dry-run') $dryRun = true;
}
if ($siteArg === '' || $postId <= 0) {
    fwrite(STDERR, "Uso: php scripts/reaplicar_postprocess.php --site=SLUG --post-id=N [--dry-run]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();
if (!isset($sites[$siteArg])) { fwrite(STDERR, "Site inválido.\n"); exit(2); }
aplicarSite($cfg, $sites, $siteArg);

require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/DiscoverPostProcess.php';

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$post = $wp->getPost($postId);
$titulo = $post['title']['raw'] ?? $post['title']['rendered'] ?? '';
$antes = $post['content']['raw'] ?? $post['content']['rendered'] ?? '';

if ($antes === '') { fwrite(STDERR, "Post #{$postId} sem conteúdo.\n"); exit(2); }

echo "═══ Post #{$postId} · {$titulo} ═══\n";
echo "Antes: " . strlen($antes) . " bytes · " . substr_count($antes, "\xE2\x80\x94") . " em-dash · "
     . substr_count($antes, "\xE2\x80\x93") . " en-dash\n";

$depois = DiscoverPostProcess::processar($antes);

echo "Depois: " . strlen($depois) . " bytes · " . substr_count($depois, "\xE2\x80\x94") . " em-dash · "
     . substr_count($depois, "\xE2\x80\x93") . " en-dash\n";

$diff = strlen($antes) - strlen($depois);
$pct  = strlen($antes) > 0 ? round($diff / strlen($antes) * 100, 1) : 0;
echo "Diff: {$diff} bytes ({$pct}%)\n\n";

if ($depois === $antes) { echo "✓ Sem mudanças.\n"; exit(0); }
if (strlen($depois) < strlen($antes) * 0.5) {
    echo "⚠️ Resultado tem <50% do tamanho original — atenção (provavelmente foi por isso que o pipeline descartou)\n";
}

if ($dryRun) {
    echo "(dry-run — não atualizou WP)\n";
    exit(0);
}

try {
    $wp->atualizarPost($postId, ['content' => $depois]);
    echo "✓ Post #{$postId} atualizado no WP\n";
} catch (Throwable $e) {
    echo "✗ Falha ao atualizar: " . $e->getMessage() . "\n";
    exit(1);
}
