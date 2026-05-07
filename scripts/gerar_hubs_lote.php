<?php
/**
 * gerar_hubs_lote.php — cria entity+concept hubs em lote para qualquer site.
 *
 * Lê config JSON de hubs em `data/hubs_lote/{site}.json`. Cada hub vira draft.
 * Se config tem campo `_lote_id`, atua como label (não vai pro builder).
 *
 * Uso:
 *   php scripts/gerar_hubs_lote.php --site=cursosenac --config=cursosenac_lote2.json
 *   php scripts/gerar_hubs_lote.php --site=vagasebeneficios
 *
 * Default: data/hubs_lote/{site}.json
 *
 * Custo: ~$0.02 por hub (Sonnet sumário).
 *
 * Estrutura do JSON:
 * {
 *   "_lote_id": "vagasebeneficios_lote1",
 *   "parent_entidade_slug": "entidade",
 *   "parent_conceito_slug": "conceito",
 *   "hubs": [
 *     {"tipo": "entity", "nome": "INSS", "fullname": "...", "tipo_org": "...", "slug": "inss", "url_oficial": "...", "aliases": [...], "descricao_seed": "..."},
 *     {"tipo": "concept", "fullname": "Concurso Público", "slug": "concurso-publico", "aliases": [...], "descricao_seed": "..."}
 *   ]
 * }
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? '');
$configFile = (string)($args['config'] ?? "{$siteSlug}.json");

if ($siteSlug === '') {
    fwrite(STDERR, "uso: php gerar_hubs_lote.php --site=SLUG [--config=arquivo.json]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/EntityHubBuilder.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$configPath = __DIR__ . "/../data/hubs_lote/{$configFile}";
if (!file_exists($configPath)) {
    fwrite(STDERR, "✗ config não encontrada: {$configPath}\n   Crie o arquivo JSON antes.\n");
    exit(1);
}
$loteConfig = json_decode((string)file_get_contents($configPath), true);
if (!is_array($loteConfig) || empty($loteConfig['hubs'])) {
    fwrite(STDERR, "✗ config inválida ou sem 'hubs'\n");
    exit(1);
}

$hubs = (array)$loteConfig['hubs'];
$loteId = (string)($loteConfig['_lote_id'] ?? "{$siteSlug}_" . date('Ymd'));
$parentEntidadeSlug = (string)($loteConfig['parent_entidade_slug'] ?? 'entidade');
$parentConceitoSlug = (string)($loteConfig['parent_conceito_slug'] ?? 'conceito');

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$sonnet = new Claude($cfg['anthropic_api_key'], 'claude-sonnet-4-6');
$builder = new EntityHubBuilder($wp, $sonnet, $siteSlug);

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Hubs em Lote — site={$siteSlug} · lote={$loteId}\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// Resolve/cria parents conforme tipos presentes no lote
$temEntity = false;
$temConcept = false;
foreach ($hubs as $h) {
    $t = (string)($h['tipo'] ?? 'entity');
    if ($t === 'entity') $temEntity = true;
    elseif ($t === 'concept') $temConcept = true;
}

$pidEntidade = 0;
$pidConceito = 0;

if ($temEntity) {
    $pe = $wp->buscarPaginaPorSlug($parentEntidadeSlug);
    if ($pe && !empty($pe['id'])) {
        $pidEntidade = (int)$pe['id'];
    } else {
        $r = $wp->criarPagina([
            'title' => 'Entidades',
            'slug' => $parentEntidadeSlug,
            'status' => 'publish',
            'content' => '<p>Guias completos sobre as principais instituições, órgãos e entidades cobertas pelo portal. Cada página agrega cobertura editorial recente, perguntas frequentes e fontes oficiais.</p>',
        ]);
        $pidEntidade = (int)($r['id'] ?? 0);
    }
    echo "→ parent /{$parentEntidadeSlug}/ id={$pidEntidade}\n";
}

if ($temConcept) {
    $pc = $wp->buscarPaginaPorSlug($parentConceitoSlug);
    if ($pc && !empty($pc['id'])) {
        $pidConceito = (int)$pc['id'];
    } else {
        $r = $wp->criarPagina([
            'title' => 'Conceitos',
            'slug' => $parentConceitoSlug,
            'status' => 'publish',
            'content' => '<p>Guias completos sobre conceitos, modalidades e categorias transversais cobertas pelo portal.</p>',
        ]);
        $pidConceito = (int)($r['id'] ?? 0);
    }
    echo "→ parent /{$parentConceitoSlug}/ id={$pidConceito}\n";
}
echo "\n";

$total = count($hubs);
$resultados = [];
foreach ($hubs as $i => $hubCfg) {
    $n = $i + 1;
    $tipo = (string)($hubCfg['tipo'] ?? 'entity');
    $rotulo = $hubCfg['nome'] ?? $hubCfg['fullname'] ?? "hub{$n}";
    $parentId = $tipo === 'concept' ? $pidConceito : $pidEntidade;
    if ($parentId === 0) {
        echo "→ [$n/$total] {$tipo} — {$rotulo} ... ✗ parent inexistente\n\n";
        $resultados[] = [$rotulo, ['erro' => 'parent inexistente']];
        continue;
    }

    echo "→ [$n/$total] {$tipo} — {$rotulo} ...\n";
    try {
        $r = $builder->gerarPara($hubCfg, $parentId);
        $resultados[] = [$rotulo, $r];
        echo "   ✓ #{$r['id']} | {$r['posts_relacionados']} posts | {$r['link']}\n\n";
    } catch (Throwable $e) {
        $resultados[] = [$rotulo, ['erro' => $e->getMessage()]];
        echo "   ✗ FAIL: " . $e->getMessage() . "\n\n";
    }
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  RESUMO — lote {$loteId}\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
$ok = 0;
$fail = 0;
foreach ($resultados as [$nome, $r]) {
    if (isset($r['erro'])) { echo "  ✗ {$nome} — {$r['erro']}\n"; $fail++; }
    else { echo "  ✓ {$nome} — #{$r['id']} | {$r['posts_relacionados']} posts\n"; $ok++; }
}
echo "\n  ok: {$ok} / {$total} · falhas: {$fail}\n";
echo "\nTodas em STATUS=DRAFT. Revise antes de publicar.\n";
echo "Pra publicar: php scripts/publicar_entity_concept_pages.php --site={$siteSlug}\n";
