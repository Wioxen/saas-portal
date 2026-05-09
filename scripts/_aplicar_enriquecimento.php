<?php
declare(strict_types=1);

/**
 * _aplicar_enriquecimento.php — aplica enriquecimentos pós-geração nos posts já criados.
 *
 * Cobre os 4 problemas levantados em 2026-05-09:
 *   1. Categoria correta (CategoryMatcher fuzzy)
 *   2. Caption + description na featured image (atualizarMedia)
 *   3. Entity links pra hubs (EntityPageLinker)
 *   4. Bloco "Veja também" com posts relacionados (buscarRelacionados)
 *
 * Uso:
 *   php scripts/_aplicar_enriquecimento.php --site=leaodabarra --post-ids=1120,1124,1128,1132,1136
 */

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? 'leaodabarra');
$postIdsRaw = (string)($args['post-ids'] ?? '');
if ($postIdsRaw === '') { fwrite(STDERR, "uso: --post-ids=1,2,3 [--site=SLUG]\n"); exit(2); }
$postIds = array_filter(array_map('intval', explode(',', $postIdsRaw)));

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/EntityPageLinker.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

foreach ($postIds as $pid) {
    echo "\n=== Post #{$pid} ===\n";
    try {
        $p = $wp->getPost($pid);
    } catch (Throwable $e) {
        echo "   ✗ getPost falhou: {$e->getMessage()}\n";
        continue;
    }

    $titulo = html_entity_decode((string)($p['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = (string)($p['content']['raw'] ?? '');
    $featuredId = (int)($p['featured_media'] ?? 0);
    $catsAtuais = $p['categories'] ?? [];
    echo "   título: " . substr($titulo, 0, 80) . "\n";
    echo "   featured_media: {$featuredId}, categorias atuais: " . count($catsAtuais) . "\n";

    // 1. CATEGORIA (resolve via CategoryMatcher)
    $catsPropostas = ['Esporte Clube Vitória'];
    $tlow = mb_strtolower($titulo);
    if (mb_stripos($tlow, 'copa do brasil') !== false) $catsPropostas[] = 'Copa do Brasil';
    if (mb_stripos($tlow, 'copa do nordeste') !== false || mb_stripos($tlow, 'nordestão') !== false) $catsPropostas[] = 'Copa do Nordeste';
    if (mb_stripos($tlow, 'brasileir') !== false || mb_stripos($tlow, 'série a') !== false) $catsPropostas[] = 'Brasileirão';
    if (mb_stripos($tlow, 'stjd') !== false || mb_stripos($tlow, 'puni') !== false) $catsPropostas[] = 'STJD';
    if (mb_stripos($tlow, 'arbitr') !== false || mb_stripos($tlow, 'árbitro') !== false) $catsPropostas[] = 'Arbitragem';
    if (mb_stripos($tlow, 'fluminense') !== false) $catsPropostas[] = 'Fluminense';
    if (mb_stripos($tlow, 'flamengo') !== false) $catsPropostas[] = 'Flamengo';
    try {
        $cm = new CategoryMatcher($wp, 70.0);
        $resolvido = $cm->resolverComMatch($catsPropostas);
        // Retorno é array indexado com IDs como values (não chave 'ids')
        $newCatIds = array_values(array_filter(array_map('intval', $resolvido)));
        if (!empty($newCatIds)) {
            $wp->atualizarPost($pid, ['categories' => $newCatIds]);
            echo "   ✓ Categorias setadas: " . implode(',', $newCatIds) . " (" . implode(', ', $catsPropostas) . ")\n";
        } else {
            echo "   ⚠ Categorias vazias retornadas\n";
        }
    } catch (Throwable $e) { echo "   ⚠ categoria: {$e->getMessage()}\n"; }

    // 2. CAPTION + DESCRIPTION na featured
    if ($featuredId > 0) {
        try {
            $captionTxt = "{$titulo} (Foto: divulgação)";
            $descTxt = "Imagem ilustrativa da matéria '{$titulo}' publicada no portal Leão da Barra.";
            $wp->atualizarMedia($featuredId, [
                'caption' => $captionTxt,
                'description' => $descTxt,
                'title' => $titulo,
                'alt_text' => $titulo,
            ]);
            echo "   ✓ Featured caption + description setados\n";
        } catch (Throwable $e) { echo "   ⚠ media: {$e->getMessage()}\n"; }
    }

    // 3 + 4. ENRIQUECIMENTO HTML (entity links + posts relacionados)
    $htmlNovo = $html;

    // Entity links
    try {
        $linker = new EntityPageLinker($wp, $siteSlug, ['entidade', 'conceito'], 3, 'publish');
        $resL = $linker->injetar($htmlNovo);
        if (!empty($resL['html']) && $resL['html'] !== $htmlNovo) {
            $htmlNovo = $resL['html'];
            $logL = $linker->getLog();
            echo "   ✓ Entity links: " . ($logL['links_inseridos'] ?? 0) . " inseridos\n";
        } else {
            echo "   ⊘ Entity links: nenhum match\n";
        }
    } catch (Throwable $e) { echo "   ⚠ entity links: {$e->getMessage()}\n"; }

    // Posts relacionados
    try {
        // Remove bloco anterior se existir (idempotência)
        $htmlNovo = preg_replace("|<aside class='posts-relacionados'[^>]*>.*?</aside>|s", '', $htmlNovo);
        // Keyword CURTA: pega 1-2 palavras significativas (WP search funciona melhor)
        // Se título tem "Vitória" -> usa "Vitória" só. Senão pega 2 primeiras maiusc/significativas
        $kwBusca = 'Vitória';
        if (preg_match_all('/\b([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]{3,})\b/u', $titulo, $mm)) {
            $palavras = array_values(array_filter($mm[1], fn($p) => !in_array(mb_strtolower($p), ['vitória', 'leão', 'flamengo', 'fluminense'])));
            if (!empty($palavras)) $kwBusca = (string)$palavras[0];
        }
        $kwBusca = $kwBusca ?: 'Vitória';
        $relacionados = $wp->buscarRelacionados($kwBusca, 6, $pid);
        if (count($relacionados) >= 2) {
            $blocoRel = "\n<aside class='posts-relacionados' aria-label='Posts relacionados'>\n";
            $blocoRel .= "  <h2>Veja também</h2>\n  <ul>\n";
            foreach (array_slice($relacionados, 0, 4) as $rel) {
                $titRel = htmlspecialchars(html_entity_decode((string)$rel['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $linkRel = htmlspecialchars((string)$rel['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $blocoRel .= "    <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
            }
            $blocoRel .= "  </ul>\n</aside>\n";
            // Antes do <script data-newsarticle> ou data-broadcast-event ou no fim
            if (preg_match('/<script[^>]*data-(newsarticle|broadcast-event|cluster-jogo)/', $htmlNovo)) {
                $htmlNovo = preg_replace('/(<script[^>]*data-(newsarticle|broadcast-event|cluster-jogo))/', $blocoRel . "$1", $htmlNovo, 1);
            } else {
                $htmlNovo .= $blocoRel;
            }
            echo "   ✓ Relacionados: " . min(4, count($relacionados)) . " links\n";
        } else {
            echo "   ⊘ Relacionados: " . count($relacionados) . " (min 2)\n";
        }
    } catch (Throwable $e) { echo "   ⚠ relacionados: {$e->getMessage()}\n"; }

    if ($htmlNovo !== $html) {
        try {
            $wp->atualizarPost($pid, ['content' => $htmlNovo]);
            echo "   ✓ Post atualizado\n";
        } catch (Throwable $e) { echo "   ⚠ update post: {$e->getMessage()}\n"; }
    }
}
echo "\n═══ done ═══\n";
