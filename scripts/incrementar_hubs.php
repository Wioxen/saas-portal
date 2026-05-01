<?php
/**
 * incrementar_hubs — atualiza hub topical com novos spokes publicados (B2).
 *
 * Roda a cada 15min. Pra cada post publicado nos últimos 30min que tem `cluster_key`,
 * adiciona o link no hub correspondente (`/hub-{cluster}`).
 *
 * Idempotente: skipa se URL já está no hub.
 *
 * Diferença vs gerar_hubs.php:
 *   - gerar_hubs (mensal): regenera hub completo (re-rank, re-formata)
 *   - este (15min): incremental, adiciona só novos
 *
 * Uso:
 *   php scripts/incrementar_hubs.php
 *   php scripts/incrementar_hubs.php --janela=60   (lookback em minutos)
 *
 * Cron: *\/15 * * * * /usr/bin/php /var/www/clonais/scripts/incrementar_hubs.php --quiet
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverHubAutoUpdate.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/_site_helper.php';

$janela = 30; // minutos
$quiet  = false;
foreach ($argv as $a) {
    if (preg_match('/^--janela=(\d+)$/', $a, $m)) $janela = (int)$m[1];
    elseif ($a === '--quiet') $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[incrementar_hubs] {$m}\n"; }

$lock = new CronLock('incrementar_hubs');
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

$cfgBase = require $ROOT . '/config.php';
$sites = sitesDisponiveis();
$db = new DiscoverDb();

$cutoff = time() - ($janela * 60);
$publicados = $db->all(['status' => 'publicado']);

$totalProcessados = 0;
$totalAtualizados = 0;
$totalJaContinha = 0;

foreach ($publicados as $p) {
    $tsPub = strtotime((string)($p['publicado_em'] ?? ''));
    if (!$tsPub || $tsPub < $cutoff) continue;

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
        $r = DiscoverHubAutoUpdate::adicionarSpoke($postId, $clusterKey, $titulo, $url, $cfgMesclado, $wp);
        $totalProcessados++;
        if (!empty($r['ok']) && !empty($r['mudou'])) {
            $totalAtualizados++;
            log_msg("[{$siteSlug}|{$clusterKey}] post {$postId} adicionado ao hub {$r['hub_post_id']}", $quiet);
        } elseif (!empty($r['ok'])) {
            $totalJaContinha++;
        }
    } catch (Throwable $e) {
        log_msg("erro {$siteSlug} post {$postId}: " . $e->getMessage(), $quiet);
    }
}

log_msg(sprintf("processados=%d · hubs atualizados=%d · ja continha=%d",
    $totalProcessados, $totalAtualizados, $totalJaContinha), $quiet);

$lock->liberar();
exit(0);
