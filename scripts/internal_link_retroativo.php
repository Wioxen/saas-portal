<?php
/**
 * internal_link_retroativo — injeta links pros novos posts em posts antigos do mesmo cluster.
 *
 * Cron 15min (ou 30min). Pra cada post publicado nos últimos 30min com cluster_key:
 *   - Acha 1-3 posts antigos do mesmo cluster (sim >= 40%, < 95%)
 *   - Injeta bloco "Veja também" no fim de cada
 *   - Idempotente (skipa se URL já está no antigo)
 *
 * Ganho: cada novo post recebe 1-3 backlinks INTERNOS contextuais novos →
 * autoridade tópica + UX mais rica em posts antigos (ainda relevantes).
 *
 * Uso:
 *   php scripts/internal_link_retroativo.php
 *   php scripts/internal_link_retroativo.php --janela=60   (lookback minutos)
 *   php scripts/internal_link_retroativo.php --quiet
 *
 * Cron: *\/15 * * * * /usr/bin/php /var/www/clonais/scripts/internal_link_retroativo.php --quiet
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverInternalLinkRetro.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/_site_helper.php';

$janelaMin = 30;
$quiet = false;
foreach ($argv as $a) {
    if (preg_match('/^--janela=(\d+)$/', $a, $m)) $janelaMin = (int)$m[1];
    elseif ($a === '--quiet') $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[retrolink] {$m}\n"; }

$lock = new CronLock('internal_link_retroativo');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

$cfgBase = require $ROOT . '/config.php';
$sites = sitesDisponiveis();
$db = new DiscoverDb();

// Push janela pro DB — só posts publicados recentemente. Usa idx_publicado_em.
$cutoff = time() - ($janelaMin * 60);
$publicados = $db->all([
    'status'         => 'publicado',
    'publicado_apos' => $cutoff,
    'order_by'       => 'publicado_desc',
]);

$totalProcessados = 0;
$totalLinkados = 0;
foreach ($publicados as $p) {
    $siteSlug = (string)($p['site'] ?? '');
    $clusterKey = (string)($p['cluster_detect']['key'] ?? '');
    $url = (string)($p['url_post'] ?? '');
    $titulo = (string)($p['titulo'] ?? '');
    $postId = (int)($p['post_id'] ?? 0);
    if ($siteSlug === '' || $clusterKey === '' || $url === '' || $postId === 0) continue;
    if (!isset($sites[$siteSlug])) continue;

    $cfgMesclado = $cfgBase;
    aplicarSite($cfgMesclado, $sites, $siteSlug);

    try {
        $wp = new Wordpress($cfgMesclado['wp_url'], $cfgMesclado['wp_user'], $cfgMesclado['wp_app_password']);
        $r = DiscoverInternalLinkRetro::injetar($postId, $clusterKey, $titulo, $url, $cfgMesclado, $db, $wp);
        $totalProcessados++;
        $totalLinkados += (int)($r['linkados'] ?? 0);
        if (($r['linkados'] ?? 0) > 0) {
            log_msg("[{$siteSlug}|{$clusterKey}] post {$postId} → {$r['linkados']} links em posts antigos", $quiet);
        }
    } catch (Throwable $e) {
        log_msg("erro {$siteSlug} post {$postId}: " . $e->getMessage(), $quiet);
    }
}

log_msg(sprintf("processados=%d · links injetados=%d", $totalProcessados, $totalLinkados), $quiet);
$lock->liberar();
exit(0);
