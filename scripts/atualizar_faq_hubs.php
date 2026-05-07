<?php
/**
 * atualizar_faq_hubs.php — cron que atualiza FAQ+posts dos entity/concept hubs.
 * Preserva sumário existente (zero custo Anthropic). Cresce FAQ ao longo do tempo.
 *
 * Uso:
 *   php scripts/atualizar_faq_hubs.php --site=cursosenac
 *   php scripts/atualizar_faq_hubs.php --site=cursosenac --quiet
 *   php scripts/atualizar_faq_hubs.php --site=cursosenac --limite=50
 *
 * Lê data/entity_pages_cache/{site}_aliases.json (mapping mantido pelo EntityHubBuilder).
 * Pra cada page: re-busca posts via WP REST + atualiza FAQ (limite 50 por hub).
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? '');
$limite = (int)($args['limite'] ?? 50);
$quiet = !empty($args['quiet']);

if ($siteSlug === '') {
    fwrite(STDERR, "uso: php atualizar_faq_hubs.php --site=SLUG [--limite=50] [--quiet]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/EntityHubBuilder.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$say = function (string $msg) use ($quiet) {
    if (!$quiet) echo $msg . "\n";
};

$say("═══ FAQ Hubs Updater — site={$siteSlug} | limite_faq={$limite} ═══");

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
// Sonnet só pra construtor — não vai ser chamado em atualizarFaqEPostsApenas
$sonnet = new Claude((string)$cfg['anthropic_api_key'], (string)$cfg['anthropic_model']);
$builder = new EntityHubBuilder($wp, $sonnet, $siteSlug);

$aliasesPath = __DIR__ . "/../data/entity_pages_cache/{$siteSlug}_aliases.json";
if (!file_exists($aliasesPath)) {
    fwrite(STDERR, "✗ aliases.json não encontrado: {$aliasesPath}\n   Rode os scripts piloto antes pra criar entity/concept pages.\n");
    exit(1);
}
$aliasesMap = json_decode((string)file_get_contents($aliasesPath), true);
if (!is_array($aliasesMap) || empty($aliasesMap)) {
    fwrite(STDERR, "✗ aliases.json vazio ou inválido\n");
    exit(1);
}

$say("aliases.json: " . count($aliasesMap) . " hubs configurados");

$resultados = [
    'inicio' => date('c'),
    'site' => $siteSlug,
    'limite' => $limite,
    'hubs' => count($aliasesMap),
    'ok' => 0,
    'falhas' => 0,
    'updates' => [],
];

foreach ($aliasesMap as $pageId => $info) {
    $pid = (int)$pageId;
    $rotulo = trim((string)($info['nome'] ?? '')) !== ''
        ? (string)$info['nome']
        : (trim((string)($info['fullname'] ?? '')) !== '' ? (string)$info['fullname'] : "page#{$pid}");
    $say("→ #{$pid} {$rotulo} ...");

    $cfgHub = [
        'tipo' => $info['tipo'] ?? 'entity',
        'nome' => $info['nome'] ?? '',
        'fullname' => $info['fullname'] ?? '',
        'slug' => $info['slug'] ?? '',
        'aliases' => $info['aliases'] ?? [],
    ];

    try {
        $r = $builder->atualizarFaqEPostsApenas($cfgHub, $pid, $limite);
        $delta = $r['faq_delta'];
        $arrow = $delta > 0 ? "+{$delta}" : ($delta < 0 ? "{$delta}" : '0');
        $say("   ✓ posts={$r['posts']} | FAQ={$r['faq_perguntas']} ({$arrow})");
        $resultados['ok']++;
        $resultados['updates'][] = [
            'page_id' => $pid,
            'rotulo' => $rotulo,
            'posts' => $r['posts'],
            'faq_perguntas' => $r['faq_perguntas'],
            'faq_delta' => $delta,
            'link' => $r['link'],
        ];
    } catch (Throwable $e) {
        $say("   ✗ FAIL: " . $e->getMessage());
        $resultados['falhas']++;
        $resultados['updates'][] = [
            'page_id' => $pid,
            'rotulo' => $rotulo,
            'erro' => $e->getMessage(),
        ];
    }
}

$resultados['fim'] = date('c');

$logDir = __DIR__ . '/../data/faq_hubs_runs';
@mkdir($logDir, 0775, true);
$logPath = "{$logDir}/" . date('Y-m-d_His') . "_{$siteSlug}.json";
file_put_contents($logPath, json_encode($resultados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (!$quiet) {
    echo "\n═══ RESUMO ═══\n";
    echo "  hubs:    " . $resultados['hubs'] . "\n";
    echo "  ok:      " . $resultados['ok'] . "\n";
    echo "  falhas:  " . $resultados['falhas'] . "\n";
    echo "  log:     {$logPath}\n";
}
