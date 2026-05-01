<?php
/**
 * CLI: reprocessa posts específicos aplicando:
 *   1. DiscoverPostProcess (FAQ dedupe, authority, cluster cleanup, travessão, etc)
 *   2. DiscoverLinkValidator (remove URLs alucinadas)
 *   3. DiscoverInternalLinks (injeta backlinks internos reais do WP)
 *
 * Uso:
 *   php scripts/reprocessar_posts.php <site_slug> <post_id1> [post_id2] ...
 *   php scripts/reprocessar_posts.php vagasebeneficios 1120 1125 1106 1104
 */

require __DIR__ . '/../lib/Wordpress.php';
require __DIR__ . '/../lib/DiscoverPostProcess.php';
require __DIR__ . '/../lib/DiscoverLinkValidator.php';
require __DIR__ . '/../lib/DiscoverInternalLinks.php';
require __DIR__ . '/../lib/DiscoverClusterMatcher.php';

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();

$args = $argv;
array_shift($args);
$siteSlug = array_shift($args);
if (!$siteSlug || !isset($sites[$siteSlug])) {
    echo "Uso: php scripts/reprocessar_posts.php <site_slug> <post_id...>\n";
    echo "Sites disponíveis: " . implode(', ', array_keys($sites)) . "\n";
    exit(1);
}

$site = $sites[$siteSlug];
$wpUrl = $site['wp_url'];
$wp = new Wordpress($wpUrl, $site['wp_user'], $site['wp_app_password']);

$postIds = array_map('intval', $args);
if (empty($postIds)) {
    echo "Informe ao menos 1 post_id.\n";
    exit(1);
}

echo "Site: {$site['name']}\n";
echo "Posts a reprocessar: " . implode(', ', $postIds) . "\n\n";

foreach ($postIds as $postId) {
    echo str_repeat('═', 70) . "\n";
    echo "POST #{$postId}\n";
    echo str_repeat('═', 70) . "\n";

    try {
        $p = $wp->getPost($postId);
    } catch (Throwable $e) {
        echo "  ❌ Erro ao buscar: {$e->getMessage()}\n\n";
        continue;
    }

    $raw    = $p['content']['raw'] ?? '';
    $titulo = $p['title']['raw'] ?? $p['title']['rendered'] ?? '';
    $slug   = $p['slug'] ?? '';
    if ($raw === '') { echo "  ⚠️  conteúdo vazio, pulando\n\n"; continue; }

    $sizeBefore = strlen($raw);
    echo "  Título: {$titulo}\n";
    echo "  Chars: {$sizeBefore}\n";

    // === 0. Reset de interlinks internos — permite redistribuição total ===
    $rawReset = DiscoverPostProcess::resetInterlinksInternos($raw);
    $nResetados = preg_match_all('/data-internal-link/', $raw) - preg_match_all('/data-internal-link/', $rawReset);
    if ($nResetados > 0) echo "  → Reset: {$nResetados} interlinks antigos removidos\n";

    // === 1. PostProcess ===
    $step1 = DiscoverPostProcess::processar($rawReset, [
        'titulo' => $titulo,
        'url'    => $wpUrl . '/' . $slug . '/',
    ]);
    $deltaPP = strlen($step1) - $sizeBefore;
    echo "  → PostProcess: Δ{$deltaPP} chars\n";

    // === 2. LinkValidator ===
    $valR = DiscoverLinkValidator::validar($step1, $wpUrl, $wp);
    $step2 = $valR['html'];
    $removidos = count($valR['removidos'] ?? []);
    echo "  → LinkValidator: removidos={$removidos}, preservados={$valR['preservados']}\n";
    foreach ($valR['removidos'] as $r) {
        echo "      ✗ '{$r['url']}' (texto: '{$r['texto']}')\n";
    }

    // === 3. InternalLinks ===
    $cluster = DiscoverClusterMatcher::detectar(['termo' => $titulo]);
    $termoKw = $titulo; // usa título como keyword (pode refinar)
    $termos = DiscoverInternalLinks::extrairTermos($step2, [
        'termo'       => $termoKw,
        'cluster_key' => $cluster['key'] ?? null,
        'relacionados'=> [],
    ]);
    echo "  → Cluster: {$cluster['nome']} | termos buscar: " . count($termos) . "\n";
    $aplicados = 0;
    if (!empty($termos)) {
        $linker = new DiscoverInternalLinks($wp, 5);
        $linker->setKeywordAncora($termoKw);
        $termosSeguros = !empty($cluster['key'])
            ? DiscoverClusterMatcher::termosSemanticos($cluster['key'])
            : [];
        $termosSeguros = array_merge($termosSeguros, DiscoverInternalLinks::extrairNgramasSignificativos($step2));
        $linker->setTermosSemanticos($termosSeguros);
        $r = $linker->injetar($step2, $termos, [], $postId);
        $aplicados = $r['aplicados'] ?? 0;
        $step3 = $r['html'];
        if (!empty($r['termos_linkados'])) {
            foreach ($r['termos_linkados'] as $tl) {
                echo "      ✓ '{$tl['termo']}' → post #{$tl['post_id']} ({$tl['titulo']})\n";
            }
        }
    } else {
        $step3 = $step2;
    }
    echo "  → InternalLinks: aplicados={$aplicados}\n";

    // === Salva ===
    if ($step3 !== $raw) {
        try {
            $wp->atualizarPost($postId, ['content' => $step3]);
            $sizeAfter = strlen($step3);
            echo "  ✅ Atualizado ({$sizeBefore} → {$sizeAfter} chars)\n\n";
        } catch (Throwable $e) {
            echo "  ❌ Erro ao salvar: {$e->getMessage()}\n\n";
        }
    } else {
        echo "  — Sem mudanças\n\n";
    }
}

echo "Concluído.\n";
