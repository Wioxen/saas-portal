<?php
/**
 * Interligação automática — varre TODOS os posts publicados do WP
 * e injeta links internos pra posts relacionados.
 *
 * Uso:
 *   php interligar.php                 → interliga todos os posts
 *   php interligar.php --limit=50      → só os 50 mais recentes
 *   php interligar.php --dry-run       → mostra o que faria sem alterar
 *   php interligar.php --force         → reinjeta mesmo se já tem links
 *
 * Estratégia:
 *  - Pra cada post, busca 3-5 posts por keyword (focus keyword do RankMath ou título)
 *  - Injeta bloco de links antes da FAQ (ou no final)
 *  - Não duplica: pula posts que já têm "Conteúdo relacionado"
 */

set_time_limit(0);

require_once __DIR__ . '/lib/Wordpress.php';
$cfg = require_once __DIR__ . '/config.php';

$limit = 100;
$dryRun = false;
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) $limit = (int)substr($arg, 8);
    elseif ($arg === '--dry-run') $dryRun = true;
    elseif ($arg === '--force') $force = true;
}

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

echo "🔗 Interligação automática — buscando posts...\n";

// Busca posts publicados (paginado)
$page = 1;
$allPosts = [];
while (count($allPosts) < $limit) {
    $perPage = min(100, $limit - count($allPosts));
    try {
        $batch = $wp->listarPosts($page, $perPage);
    } catch (Throwable $e) {
        break;
    }
    if (empty($batch)) break;
    $allPosts = array_merge($allPosts, $batch);
    $page++;
}

echo "  ✓ " . count($allPosts) . " posts encontrados\n\n";

$interligados = 0;
$pulados = 0;

foreach ($allPosts as $i => $post) {
    $pid = $post['id'];
    $titulo = $post['title']['rendered'] ?? '';
    $tituloLimpo = strip_tags(html_entity_decode($titulo));
    $n = $i + 1;

    echo "[{$n}/" . count($allPosts) . "] #{$pid} {$tituloLimpo}\n";

    try {
        // Busca conteúdo raw
        $full = $wp->getPost($pid);
        $content = $full['content']['raw'] ?? '';

        // Já tem interligação?
        if (!$force && str_contains($content, 'Conteúdo relacionado')) {
            echo "  ⏭️  Já interligado, pulando\n";
            $pulados++;
            continue;
        }

        // Keyword: tenta focus keyword do RankMath, senão usa título
        $focusKw = $full['meta']['rank_math_focus_keyword'] ?? '';
        $searchTerm = $focusKw !== '' ? $focusKw : $tituloLimpo;

        // Busca relacionados
        $relacionados = $wp->buscarRelacionados($searchTerm, 5, $pid);
        if (empty($relacionados)) {
            echo "  ℹ️  Sem relacionados encontrados\n";
            continue;
        }

        echo "  🔗 " . count($relacionados) . " relacionados: ";
        echo implode(', ', array_map(fn($r) => strip_tags(html_entity_decode($r['title'])), array_slice($relacionados, 0, 3)));
        echo "\n";

        if ($dryRun) {
            echo "  → DRY RUN\n";
            continue;
        }

        // Monta bloco
        $linksHtml = '<h3>Conteúdo relacionado</h3><ul>';
        foreach ($relacionados as $rel) {
            $t = htmlspecialchars(strip_tags(html_entity_decode($rel['title'])));
            $l = htmlspecialchars($rel['link']);
            $linksHtml .= "<li><a href=\"{$l}\">{$t}</a></li>";
        }
        $linksHtml .= '</ul>';

        // Injeta antes da FAQ ou no final
        if (str_contains($content, '<h2>Perguntas frequentes</h2>')) {
            $content = str_replace('<h2>Perguntas frequentes</h2>', $linksHtml . '<h2>Perguntas frequentes</h2>', $content);
        } elseif (str_contains($content, 'Leia também')) {
            $content = str_replace('<h2>Leia também</h2>', $linksHtml . '<h2>Leia também</h2>', $content);
        } else {
            $content .= $linksHtml;
        }

        $wp->atualizarPost($pid, ['content' => $content]);
        $interligados++;
        echo "  ✅ Interligado\n";

    } catch (Throwable $e) {
        echo "  ❌ " . $e->getMessage() . "\n";
    }
}

echo "\n════════════════════════════════════════════\n";
echo "RESUMO: {$interligados} interligados, {$pulados} pulados\n";
echo "════════════════════════════════════════════\n";
