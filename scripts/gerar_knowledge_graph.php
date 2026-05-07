<?php
/**
 * gerar_knowledge_graph.php — gera Schema.org Knowledge Graph e cria/atualiza
 * page WP /knowledge-graph/ com HTML legível + JSON-LD inline.
 *
 * Uso:
 *   php scripts/gerar_knowledge_graph.php --site=cursosenac
 *   php scripts/gerar_knowledge_graph.php --site=cursosenac --quiet
 *
 * Gera arquivo backup em data/knowledge_graph/{site}.json + atualiza page WP.
 * Cron semanal: cresce com novos hubs.
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? '');
$quiet = !empty($args['quiet']);

if ($siteSlug === '') {
    fwrite(STDERR, "uso: php gerar_knowledge_graph.php --site=SLUG [--quiet]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/KnowledgeGraphBuilder.php';
require_once __DIR__ . '/../lib/CrossSiteKgBuilder.php';

$sitesGlobais = sitesDisponiveis();
aplicarSite($cfg, $sitesGlobais, $siteSlug);

$say = function (string $msg) use ($quiet) {
    if (!$quiet) echo $msg . "\n";
};

$say("═══ Knowledge Graph Builder — site={$siteSlug} ═══");

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$siteName = (string)($cfg['site_name'] ?? $cfg['name'] ?? $siteSlug);
$siteUrl = (string)$cfg['wp_url'];

$builder = new KnowledgeGraphBuilder($wp, $siteSlug, $siteUrl, $siteName);
$kg = $builder->montar();

if (empty($kg['jsonld'])) {
    fwrite(STDERR, "✗ KG vazio: " . ($kg['humano']['erro'] ?? 'sem entidades') . "\n");
    exit(1);
}

$say("entidades: " . count($kg['humano']['entities']));
$say("conceitos: " . count($kg['humano']['concepts']));
$say("total hubs: " . $kg['humano']['total']);

// Backup local
$backupDir = __DIR__ . '/../data/knowledge_graph';
@mkdir($backupDir, 0775, true);
$backupPath = "{$backupDir}/{$siteSlug}.json";
file_put_contents($backupPath, json_encode($kg['jsonld'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$say("backup local: {$backupPath}");

// Cria/atualiza page WP /knowledge-graph/
$html = $builder->renderizarHtml($kg['jsonld'], $kg['humano']);

// Anexa Cross-Site KG (Schema.org Organization+owns dos sites irmãos da editora)
// Usa o cfg raw do site no $sitesGlobais (aplicarSite não copia empresa.nome pro $cfg).
$cfgRawDoSite = $sitesGlobais[$siteSlug] ?? $cfg;
$crossBuilder = new CrossSiteKgBuilder($siteSlug, $cfgRawDoSite, $sitesGlobais);
$crossJsonld = $crossBuilder->montar();
if ($crossJsonld) {
    $html .= $crossBuilder->renderizarScript($crossJsonld);
    $say("cross-site KG: " . count($crossJsonld['owns']) . " sites irmãos sob '{$crossJsonld['name']}'");
}

$tituloPage = "Mapa de Conhecimento — {$siteName}";

$pageExist = $wp->buscarPaginaPorSlug('knowledge-graph');
$pageId = 0;

try {
    if ($pageExist && !empty($pageExist['id'])) {
        $pageId = (int)$pageExist['id'];
        $r = $wp->atualizarPagina($pageId, [
            'title' => $tituloPage,
            'content' => $html,
            'status' => 'publish',
            'meta' => [
                'rank_math_focus_keyword' => 'mapa de conhecimento',
                'rank_math_title' => "Mapa de Conhecimento — {$siteName}",
                'rank_math_description' => "Estrutura semântica completa do portal: " . $kg['humano']['total'] . " hubs entre entidades educacionais e conceitos transversais. Atualizado periodicamente.",
            ],
        ]);
        $say("✓ page existente atualizada — #{$pageId}");
    } else {
        $r = $wp->criarPagina([
            'title' => $tituloPage,
            'slug' => 'knowledge-graph',
            'status' => 'publish',
            'content' => $html,
            'meta' => [
                'rank_math_focus_keyword' => 'mapa de conhecimento',
                'rank_math_title' => "Mapa de Conhecimento — {$siteName}",
                'rank_math_description' => "Estrutura semântica completa do portal: " . $kg['humano']['total'] . " hubs entre entidades educacionais e conceitos transversais.",
            ],
        ]);
        $pageId = (int)($r['id'] ?? 0);
        $say("✓ page criada — #{$pageId}");
    }
    $say("  link: " . (string)($r['link'] ?? ''));
} catch (Throwable $e) {
    fwrite(STDERR, "✗ falha WP: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    echo "\n═══ RESUMO ═══\n";
    echo "  hubs no KG:   " . $kg['humano']['total'] . "\n";
    echo "  entities:     " . count($kg['humano']['entities']) . "\n";
    echo "  concepts:     " . count($kg['humano']['concepts']) . "\n";
    echo "  page WP:      #{$pageId}\n";
}
