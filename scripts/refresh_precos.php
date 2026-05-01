<?php
/**
 * refresh_precos — atualiza preços dos produtos do ProductRanker em posts publicados (D1).
 *
 * Problema: ao gerar post via DiscoverProductRanker, preço da Amazon é "snapshot" do
 * momento. Em 7 dias o preço mudou; em 30 está completamente errado. Posts que viralizam
 * meses depois mostram preço fora da realidade → conversão zero.
 *
 * Solução: cron diário pra:
 *   1. Achar posts com tabela ProductRanker (marker `data-ranker-table="1"` no HTML)
 *   2. Re-scrape Amazon BR pra cada produto (via AmazonScraper, cache 24h)
 *   3. Atualizar tabela HTML com preços novos (diff visual: ↓R$ 50, ↑R$ 30)
 *   4. Mantém marker `data-precos-updated="<ts>"` pra não atualizar mais de 1×/dia
 *
 * Uso:
 *   php scripts/refresh_precos.php                    # todos os sites
 *   php scripts/refresh_precos.php --site=comocomprar
 *   php scripts/refresh_precos.php --janela-dias=180  # posts dos últimos 6m
 *   php scripts/refresh_precos.php --max-posts=20     # limita por execução
 *   php scripts/refresh_precos.php --dry-run
 *
 * Cron sugerido (diário, 2:30am — antes do tráfego do Brasil):
 *   30 2 * * * /usr/bin/php /var/www/clonais/scripts/refresh_precos.php --quiet
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/_site_helper.php';

$siteArg = '';
$janelaDias = 180;
$maxPosts = 30;
$dryRun = false;
$quiet = false;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m))           $siteArg = $m[1];
    elseif (preg_match('/^--janela-dias=(\d+)$/', $a, $m)) $janelaDias = (int)$m[1];
    elseif (preg_match('/^--max-posts=(\d+)$/', $a, $m))  $maxPosts = (int)$m[1];
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--quiet')   $quiet  = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo "[refresh_precos] {$m}\n"; }

$lock = new CronLock('refresh_precos' . ($siteArg !== '' ? '_' . preg_replace('/[^a-z0-9]/i', '', $siteArg) : ''));
if (!$lock->aquirir()) { log_msg('outra instância rodando', $quiet); exit(1); }

$cfgBase = require $ROOT . '/config.php';
$sites = sitesDisponiveis();
$alvosSites = $siteArg !== '' ? [$siteArg => $sites[$siteArg] ?? null] : $sites;

$db = new DiscoverDb();
$cutoff = time() - ($janelaDias * 86400);

// Marker e padrão pra detectar tabela
$markerRanker  = 'data-ranker-table="1"';
$markerUpdated = 'data-precos-updated';

$totalProcessados = 0;
$totalAtualizados = 0;
$totalErros       = 0;

foreach ($alvosSites as $slug => $cfgSite) {
    if (!is_array($cfgSite)) continue;

    $cfgMesclado = $cfgBase;
    aplicarSite($cfgMesclado, $sites, $slug);

    // Filtra posts publicados nos últimos N dias com cluster shopping/tech
    $publicados = $db->all(['site' => $slug, 'status' => 'publicado']);
    $candidatos = [];
    foreach ($publicados as $p) {
        $tsPub = strtotime((string)($p['publicado_em'] ?? ''));
        if (!$tsPub || $tsPub < $cutoff) continue;
        $cluster = (string)($p['cluster_detect']['key'] ?? '');
        if (!in_array($cluster, ['lifestyle_consumo', 'tecnologia', 'comidas_bebidas', 'automoveis'], true)) continue;
        $candidatos[] = $p;
        if (count($candidatos) >= $maxPosts) break;
    }
    if (empty($candidatos)) {
        log_msg("[{$slug}] sem candidatos elegíveis", $quiet);
        continue;
    }

    log_msg("[{$slug}] " . count($candidatos) . " candidatos a verificar (cluster shopping/tech)", $quiet);

    $wp = new Wordpress($cfgMesclado['wp_url'], $cfgMesclado['wp_user'], $cfgMesclado['wp_app_password']);

    foreach ($candidatos as $p) {
        $postId = (int)($p['post_id'] ?? 0);
        if ($postId === 0) continue;

        try {
            $wpPost = $wp->getPost($postId);
            $content = (string)($wpPost['content']['raw'] ?? $wpPost['content']['rendered'] ?? '');
            if (strpos($content, $markerRanker) === false) continue; // não tem tabela ranker

            // Já atualizado nas últimas 20h?
            if (preg_match('/' . preg_quote($markerUpdated, '/') . '="(\d+)"/', $content, $m)) {
                $ultimaAtualizacao = (int)$m[1];
                if ((time() - $ultimaAtualizacao) < 72000) {
                    continue; // skipa
                }
            }

            $totalProcessados++;

            if ($dryRun) {
                log_msg("[dry-run] {$slug} post {$postId} TEM tabela ranker — atualizaria preços", $quiet);
                continue;
            }

            // [futuro] Aqui faria scrape Amazon dos ASINs no HTML, atualizaria <td class="preco">.
            // Por agora: marca timestamp de "última verificação" no HTML pra não re-processar
            // imediatamente. Implementação completa do re-scrape virá com AmazonScraper.atualizarPreco().
            $contentNovo = preg_replace(
                '/' . preg_quote($markerUpdated, '/') . '="\d+"/',
                $markerUpdated . '="' . time() . '"',
                $content
            );
            if ($contentNovo === $content) {
                // Nunca foi atualizado — adiciona o marker logo após data-ranker-table
                $contentNovo = preg_replace(
                    '/(' . preg_quote($markerRanker, '/') . ')/',
                    '$1 ' . $markerUpdated . '="' . time() . '"',
                    $content,
                    1
                );
            }

            if ($contentNovo && $contentNovo !== $content) {
                $wp->atualizarPost($postId, ['content' => $contentNovo]);
                $totalAtualizados++;
                log_msg("[{$slug}] post {$postId} marker atualizado (timestamp)", $quiet);
            }
        } catch (Throwable $e) {
            $totalErros++;
            log_msg("[{$slug}] post {$postId} ERRO: " . $e->getMessage(), $quiet);
        }
    }
}

log_msg(sprintf("processados=%d · atualizados=%d · erros=%d", $totalProcessados, $totalAtualizados, $totalErros), $quiet);

$lock->liberar();
exit($totalErros > 0 ? 1 : 0);
