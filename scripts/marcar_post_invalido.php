<?php
/**
 * scripts/marcar_post_invalido.php
 *
 * Marca um post como inválido: move pro trash no WP + atualiza trends.status no DB.
 * Útil quando geração saiu com alucinação grave e não pode ser publicada.
 *
 * Uso:
 *   php scripts/marcar_post_invalido.php --site=SLUG --trend-id=N [--motivo='alucinacao_nomes']
 *
 * Sem motivo → default 'reprovado_qualidade'.
 */

$siteArg = '';
$trendId = 0;
$motivo  = 'reprovado_qualidade';
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--trend-id=(\d+)$/', $a, $m)) $trendId = (int)$m[1];
    if (preg_match('/^--motivo=(.+)$/', $a, $m)) $motivo = $m[1];
}
if ($siteArg === '' || $trendId <= 0) {
    fwrite(STDERR, "Uso: php scripts/marcar_post_invalido.php --site=SLUG --trend-id=N [--motivo=X]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();
if (!isset($sites[$siteArg])) {
    fwrite(STDERR, "Site '{$siteArg}' não existe.\n");
    exit(2);
}
aplicarSite($cfg, $sites, $siteArg);

require_once __DIR__ . '/../lib/DiscoverDb.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$db = new DiscoverDb();
$trend = $db->get($trendId);
if (!$trend) { fwrite(STDERR, "Trend #{$trendId} não encontrado.\n"); exit(2); }
if (($trend['site'] ?? '') !== $siteArg) {
    fwrite(STDERR, "Trend #{$trendId} pertence a '{$trend['site']}', não a '{$siteArg}'.\n");
    exit(2);
}

$postId = (int)($trend['post_id'] ?? 0);
echo "Trend: #{$trendId} · termo: {$trend['termo']}\n";
echo "Post WP: #{$postId}\n";
echo "Status atual: {$trend['status']}\n\n";

if ($postId > 0) {
    try {
        $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $wp->atualizarPost($postId, ['status' => 'trash']);
        echo "✓ Post #{$postId} movido pro trash no WP\n";
    } catch (Throwable $e) {
        echo "✗ Falha ao trashar post no WP: " . $e->getMessage() . "\n";
        echo "  (continuando mesmo assim — vai atualizar status no DB)\n";
    }
}

$ok = $db->updateStatus($trendId, $motivo, [
    'erro_geracao' => "marcado manualmente: {$motivo}",
    'invalidado_em' => date('c'),
]);
echo $ok ? "✓ trends.status atualizado pra '{$motivo}'\n" : "✗ falha ao atualizar status do trend\n";

echo "\nFim.\n";
