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

$siteArg   = '';
$trendId   = 0;
$postIdArg = 0;
$motivo    = 'reprovado_qualidade';
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--trend-id=(\d+)$/', $a, $m)) $trendId = (int)$m[1];
    if (preg_match('/^--post-id=(\d+)$/', $a, $m)) $postIdArg = (int)$m[1];
    if (preg_match('/^--motivo=(.+)$/', $a, $m)) $motivo = $m[1];
}
if ($siteArg === '' || ($trendId <= 0 && $postIdArg <= 0)) {
    fwrite(STDERR, "Uso: php scripts/marcar_post_invalido.php --site=SLUG (--trend-id=N | --post-id=N) [--motivo=X]\n");
    fwrite(STDERR, "  --trend-id pra trashar via lookup do DB de trends.\n");
    fwrite(STDERR, "  --post-id sozinho pra trashar SÓ no WP (quando trend não existe mais ou foi deletado).\n");
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

// Modo 1: trend existe → atualiza status + trasha post se houver
// Modo 2: trend não existe / só --post-id → trasha SÓ no WP
$trend = null;
if ($trendId > 0) {
    $trend = $db->get($trendId);
    if (!$trend) {
        if ($postIdArg <= 0) {
            fwrite(STDERR, "Trend #{$trendId} não encontrado e sem --post-id.\n");
            exit(2);
        }
        echo "ℹ️  Trend #{$trendId} não encontrado no DB (já foi deletado?). Trashando só no WP.\n";
    } elseif (($trend['site'] ?? '') !== $siteArg) {
        fwrite(STDERR, "Trend #{$trendId} pertence a '{$trend['site']}', não a '{$siteArg}'.\n");
        exit(2);
    }
}

$postId = (int)($trend['post_id'] ?? 0);
if ($postId === 0 && $postIdArg > 0) $postId = $postIdArg;
if ($trend) {
    echo "Trend: #{$trendId} · termo: {$trend['termo']}\n";
}
echo "Post WP: #{$postId}" . ($postIdArg > 0 && $postId === $postIdArg ? " (override via --post-id)" : "") . "\n";
if ($trend) echo "Status atual: {$trend['status']}\n";
echo "\n";

if ($postId > 0) {
    try {
        $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
        $wp->trashPost($postId, false);  // force=false = move pro trash, não delete permanente
        echo "✓ Post #{$postId} movido pro trash no WP\n";
    } catch (Throwable $e) {
        echo "✗ Falha ao trashar post no WP: " . $e->getMessage() . "\n";
    }
}

if ($trend && $trendId > 0) {
    $ok = $db->updateStatus($trendId, $motivo, [
        'erro_geracao' => "marcado manualmente: {$motivo}",
        'invalidado_em' => date('c'),
    ]);
} else {
    $ok = true;  // sem trend pra atualizar
}
if ($trend) {
    echo $ok ? "✓ trends.status atualizado pra '{$motivo}'\n" : "✗ falha ao atualizar status do trend\n";
}

echo "\nFim.\n";
