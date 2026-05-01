<?php
/**
 * Indexa retroativamente todos os posts publicados que estão SEM indexing_info gravado.
 *
 * Hoje (auditoria 2026-04-26) só 1/28 posts publicados tem indexing_info — os outros 27 foram
 * publicados antes da feature de auto-indexação. IndexNow é grátis e o canal recupera presença
 * desses URLs no Bing/Google.
 *
 * Por que isso importa:
 *  - Posts antigos provavelmente já foram crawleados, mas re-indexação refresh acelera Discover
 *  - Custo zero (IndexNow gratuito; Rank Math/Google Indexing também grátis se configurado)
 *  - Idempotente: não dói re-indexar URL já indexada
 *
 * Uso:
 *   php scripts/indexar_retroativo.php                    → todos os posts sem indexing_info
 *   php scripts/indexar_retroativo.php --site=cursosenac  → só 1 site
 *   php scripts/indexar_retroativo.php --dry-run          → mostra o que faria
 *   php scripts/indexar_retroativo.php --max=N            → limita a N posts
 *   php scripts/indexar_retroativo.php --force            → re-indexa MESMO os que já têm indexing_info
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/InstantIndexing.php';

$forceSite = null;
$dryRun = false;
$max = 0; // 0 = sem limite
$forceReindex = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site=')) $forceSite = substr($arg, 7);
    elseif ($arg === '--dry-run')         $dryRun = true;
    elseif (str_starts_with($arg, '--max=')) $max = (int)substr($arg, 6);
    elseif ($arg === '--force')           $forceReindex = true;
}

$cfg = require $ROOT . '/config.php';
$sites = require $ROOT . '/sites.php';
$db = new DiscoverDb();

echo "Indexação Retroativa — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('═', 80) . "\n";

// Coleta candidatos: posts publicados com url_post válida + sem indexing_info
$candidatos = [];
foreach ($db->all() as $r) {
    $status = (string)($r['status'] ?? '');
    if (!in_array($status, ['publicado', 'suspeita'], true)) continue;
    $site = (string)($r['site'] ?? '');
    if (!isset($sites[$site])) continue; // site removido/inválido
    if ($forceSite !== null && $site !== $forceSite) continue;

    $urlPost = (string)($r['url_post'] ?? '');
    if ($urlPost === '') continue;

    // Skip se já tem indexing_info OK (a menos que --force)
    $ii = $r['indexing_info'] ?? null;
    if (!$forceReindex && is_array($ii) && !empty($ii['post_url']['success'])) continue;

    // Extrai URL pública do link de admin (post.php?post=NNN)
    $urlPublica = '';
    if (preg_match('/[?&]post=(\d+)/', $urlPost, $m)) {
        // Tem ID do post no URL admin → buscar via WP REST?
        // Pra simplificar: assume que o slug está no DB (alguns têm) ou monta via título
        // (nada disso é confiável — vamos buscar via WP REST por ID)
        $candidatos[] = [
            'trend_id'   => (int)($r['id'] ?? 0),
            'site'       => $site,
            'wp_post_id' => (int)$m[1],
            'termo'      => (string)($r['termo'] ?? ''),
            'url_admin'  => $urlPost,
        ];
    }
}

// Agrupa por site (otimização: 1 InstantIndexing por site)
$porSite = [];
foreach ($candidatos as $c) {
    $porSite[$c['site']][] = $c;
}

$totalCandidatos = count($candidatos);
echo "Candidatos: {$totalCandidatos} posts " .
    ($forceReindex ? "(--force ativo, re-indexa todos)" : "(sem indexing_info gravado)") .
    "\n\n";

if (empty($candidatos)) {
    echo "Nenhum post pra indexar. Saindo.\n";
    exit(0);
}

require_once $ROOT . '/lib/Wordpress.php';

$totalOk = 0;
$totalErro = 0;
$totalSkip = 0;
$totalPlugin404 = 0;

foreach ($porSite as $site => $items) {
    echo "─── {$site} (" . count($items) . " posts) ───\n";
    $siteCfg = $sites[$site];

    try {
        $wp = new Wordpress($siteCfg['wp_url'], $siteCfg['wp_user'], $siteCfg['wp_app_password']);
        $idx = new InstantIndexing($siteCfg['wp_url'], $siteCfg['wp_user'], $siteCfg['wp_app_password']);
    } catch (Throwable $e) {
        echo "  ✗ ERRO setup: " . $e->getMessage() . "\n";
        $totalErro += count($items);
        continue;
    }

    foreach ($items as $i => $c) {
        if ($max > 0 && ($totalOk + $totalErro + $totalSkip) >= $max) {
            echo "  [limite] atingiu --max={$max}\n";
            break 2;
        }

        // Resolve URL pública via WP REST (post_id → link)
        $urlPublica = '';
        try {
            $post = $wp->getPost($c['wp_post_id']);
            $urlPublica = (string)($post['link'] ?? '');
        } catch (Throwable $e) {
            echo "  [skip] post #{$c['wp_post_id']} — getPost falhou: " . $e->getMessage() . "\n";
            $totalSkip++;
            continue;
        }

        if ($urlPublica === '') {
            echo "  [skip] post #{$c['wp_post_id']} sem link\n";
            $totalSkip++;
            continue;
        }

        $termoCurto = mb_substr($c['termo'], 0, 50);
        if ($dryRun) {
            echo "  [dry] indexaria: {$urlPublica} ({$termoCurto})\n";
            $totalOk++;
            continue;
        }

        try {
            $r = $idx->indexar($urlPublica, 'URL_UPDATED');
            if (!empty($r['success'])) {
                echo "  ✓ #{$c['wp_post_id']} {$termoCurto} ({$r['method']})\n";
                $totalOk++;
                // Persiste resultado no DB pra audit
                $existente = $db->get($c['trend_id']);
                if ($existente) {
                    $ii = $existente['indexing_info'] ?? [];
                    if (!is_array($ii)) $ii = [];
                    $ii['post_url'] = $r;
                    $ii['retroativo_em'] = date('Y-m-d H:i:s');
                    $db->updateStatus($c['trend_id'], (string)$existente['status'], ['indexing_info' => $ii]);
                }
            } else {
                $erro = (string)($r['error'] ?? 'desconhecido');
                if (str_contains(strtolower($erro), 'rest_no_route') || str_contains(strtolower($erro), '404')) {
                    $totalPlugin404++;
                    echo "  ✗ #{$c['wp_post_id']} — plugin cc-instant-indexing-api não instalado neste site (HTTP 404)\n";
                    break; // Não adianta tentar mais nesse site
                }
                echo "  ✗ #{$c['wp_post_id']} — {$erro}\n";
                $totalErro++;
            }
        } catch (Throwable $e) {
            echo "  ✗ #{$c['wp_post_id']} exception: " . $e->getMessage() . "\n";
            $totalErro++;
        }
    }
    echo "\n";
}

echo str_repeat('═', 80) . "\n";
printf("RESUMO: %d indexados · %d falhas · %d skipped · %d sites sem plugin\n",
    $totalOk, $totalErro, $totalSkip, $totalPlugin404);

if ($totalPlugin404 > 0) {
    echo "\n⚠️  Sites sem plugin: instale 'plugin/cc-instant-indexing-api.php' nos sites afetados\n";
    echo "    via Plugins → Add New → Upload OU copiando o arquivo pra wp-content/plugins/.\n";
}
if ($dryRun) echo "\n(modo dry-run — nada foi indexado)\n";
