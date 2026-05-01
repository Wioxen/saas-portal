<?php
/**
 * scripts/gerar_teste_e2e.php
 *
 * Teste end-to-end: gera 1 artigo real através de DiscoverGerador e relata
 * cada etapa do pipeline em detalhe. Usa o melhor trend disponível (alto ROI,
 * status=aprovado) do site indicado.
 *
 * Uso:
 *   /c/xampp/php/php.exe scripts/gerar_teste_e2e.php --site=comocomprar [--id=44] [--confirm]
 *
 * Sem --confirm: mostra só o trend escolhido e custo estimado (dry-run final).
 * Com --confirm: gera de verdade (gasta ~$0.08-0.20 em API).
 */

$siteArg = '';
$idArg = 0;
$confirm = false;
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) $siteArg = $m[1];
    if (preg_match('/^--id=(\d+)$/', $a, $m))  $idArg = (int)$m[1];
    if ($a === '--confirm') $confirm = true;
}
if ($siteArg === '') {
    fwrite(STDERR, "Uso: php scripts/gerar_teste_e2e.php --site=SLUG [--id=TREND_ID] [--confirm]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
$sites = sitesDisponiveis();
if (!isset($sites[$siteArg])) {
    fwrite(STDERR, "Site '{$siteArg}' não existe.\n");
    exit(2);
}
aplicarSite($cfg, $sites, $siteArg);

require_once __DIR__ . '/../lib/DiscoverDb.php';
require_once __DIR__ . '/../lib/DiscoverSinaisEditoriais.php';
require_once __DIR__ . '/../lib/TrendsTaxonomia.php';
require_once __DIR__ . '/../lib/DiscoverScore.php';
require_once __DIR__ . '/../lib/DiscoverAfiliados.php';
require_once __DIR__ . '/../lib/DiscoverWebStory.php';

$db = new DiscoverDb();

// Escolhe o trend
if ($idArg > 0) {
    $trend = $db->get($idArg);
    if (!$trend) { fwrite(STDERR, "Trend #{$idArg} não encontrado.\n"); exit(2); }
    if (($trend['site'] ?? '') !== $siteArg) {
        fwrite(STDERR, "Trend #{$idArg} pertence a '{$trend['site']}', não a '{$siteArg}'.\n");
        exit(2);
    }
} else {
    $all = $db->all(['site' => $siteArg]);
    $aprovados = array_values(array_filter($all, fn($r) => ($r['status'] ?? '') === 'aprovado'));
    // Ordena por ROI desc + score desc
    usort($aprovados, function($a, $b) {
        $sa = DiscoverSinaisEditoriais::ler($a);
        $sb = DiscoverSinaisEditoriais::ler($b);
        $roiA = TrendsTaxonomia::roiEditorial($sa['cluster_detect']['key'] ?? 'curiosidades_geral');
        $roiB = TrendsTaxonomia::roiEditorial($sb['cluster_detect']['key'] ?? 'curiosidades_geral');
        if ($roiA !== $roiB) return $roiB <=> $roiA;
        return ($b['score_discover'] ?? 0) <=> ($a['score_discover'] ?? 0);
    });
    if (empty($aprovados)) { fwrite(STDERR, "Nenhum trend aprovado em '{$siteArg}'.\n"); exit(2); }
    $trend = $aprovados[0];
}

$sinais = DiscoverSinaisEditoriais::ler($trend);
$clusterKey = $sinais['cluster_detect']['key'] ?? 'curiosidades_geral';
$roi = TrendsTaxonomia::roiEditorial($clusterKey);

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Geração E2E — site={$siteArg}" . ($confirm ? " · \033[33mCONFIRMADO\033[0m (vai gastar API)" : " · \033[36mpreview\033[0m (sem gastar)") . "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

echo "Trend selecionado:\n";
echo sprintf("  ID:       #%d\n", (int)$trend['id']);
echo sprintf("  Termo:    %s\n", $trend['termo']);
echo sprintf("  Cluster:  %s (%s) · ROI %.1f/10 · RPM R\$ %d/mil\n",
    TrendsTaxonomia::labelCurto($clusterKey),
    $clusterKey,
    $roi,
    TrendsTaxonomia::rpm($clusterKey));
echo sprintf("  Score:    %.2f (threshold %.1f)\n", (float)$trend['score_discover'], TrendsTaxonomia::threshold($clusterKey));
echo sprintf("  Volume:   %s · growth: +%d%%\n", $trend['volume_label'] ?? '?', (int)($trend['growth_pct'] ?? 0));
echo sprintf("  Dor:      %s (peso %d)\n", $sinais['pain']['dominante'] ?? '?', (int)($sinais['pain']['peso_total'] ?? 0));
echo sprintf("  Ângulo:   %s\n", $trend['angulo'] ?? '?');
if (!empty($trend['relacionados'])) {
    echo sprintf("  Relac.:   %s\n", implode(' · ', array_slice($trend['relacionados'], 0, 4)));
}

// Preview das decisões que o pipeline vai tomar
// Persona do site
$persona = $cfg['persona'] ?? null;
if (is_array($persona) && !empty($persona['autor'])) {
    echo "\nPersona editorial:\n";
    echo "  🎭 Autor:     " . $persona['autor'] . "\n";
    echo "  🗣 Voz:       " . ($persona['voz'] ?? '?') . "\n";
    echo "  🎯 Audiência: " . ($persona['audiencia'] ?? '?') . "\n";
} else {
    echo "\nPersona editorial: (não configurada — usará voz genérica)\n";
}

echo "\nPipeline simulado:\n";

// 1. Afiliado
$match = DiscoverAfiliados::matchear([
    'termo' => $trend['termo'],
    'cluster_detect' => $sinais['cluster_detect'],
    'pain' => $sinais['pain'],
    'relacionados' => $trend['relacionados'] ?? [],
]);
if ($match) {
    echo sprintf("  🎯 Afiliado: MATCH → %s (score %d)\n", $match['oferta']['slug'], $match['score']);
    echo sprintf("     Motivos: %s\n", implode(', ', $match['motivos']));
} else {
    echo "  🎯 Afiliado: sem match — bloco CTA NÃO será injetado\n";
}

// 2. Web Story
$deveGerarWs = DiscoverWebStory::deveGerar($cfg, $clusterKey);
echo sprintf("  📽️ Web Story: %s (ROI %.1f %s %.1f limite)\n",
    $deveGerarWs ? 'vai disparar' : 'pular (ROI baixo ou desabilitado)',
    $roi,
    $roi >= $cfg['webstory_roi_min'] ? '≥' : '<',
    $cfg['webstory_roi_min'] ?? 5.0);

// 3. Cluster interlink
$temEvento = !empty($trend['evento_fonte']);
echo sprintf("  🔗 Cluster interlink: %s\n",
    $temEvento ? "tentará (evento: {$trend['evento_fonte']})" : 'não aplicável (sem evento_fonte)');

// 4. LLM
echo sprintf("  🤖 LLM principal: %s\n", $cfg['default_llm'] === 'openai' ? 'OpenAI (GPT)' : 'Claude');
echo sprintf("  🤖 Fallback: %s (auto se Claude falhar com erro transitório)\n",
    $cfg['default_llm'] === 'openai' ? 'Claude' : 'OpenAI (GPT)');

echo "\nCusto estimado:\n";
echo "  Serper (2 calls):  ~\$0.04\n";
echo "  Claude sonnet-4-6: ~\$0.08-0.15\n";
echo "  WP REST (6 calls): \$0\n";
if ($deveGerarWs) echo "  Web Story (plugin wsai → GPT-4o-mini): ~\$0.001 (Pexels grátis)\n";
echo "  Tempo estimado:    3-5 min\n";

if (!$confirm) {
    echo "\n───────────────────────────────────────────────────────────────────────────\n";
    echo "  ✋ Preview apenas. Para gerar de verdade: adicione --confirm\n";
    echo "───────────────────────────────────────────────────────────────────────────\n";
    exit(0);
}

echo "\n═══ GERANDO ═══\n\n";
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/OpenAI.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/GoogleNewsRss.php';
require_once __DIR__ . '/../lib/TrendsArticles.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Maquina.php';
require_once __DIR__ . '/../lib/DiscoverGerador.php';

$t0 = microtime(true);
$gen = new DiscoverGerador($cfg, $db);
$res = $gen->gerar($trend, 'discover');
$tTotal = round(microtime(true) - $t0, 1);

echo "\n═══ RESULTADO ═══\n";
echo "Tempo total: {$tTotal}s · Status: " . ($res['ok'] ? "\033[32mOK\033[0m" : "\033[31mFAIL\033[0m") . "\n\n";

if (!$res['ok']) {
    echo "Erro: " . ($res['erro'] ?? '?') . "\n";
    if (!empty($res['claude_erro_original'])) echo "Claude erro original: " . $res['claude_erro_original'] . "\n";
    if (!empty($res['gpt_erro'])) echo "GPT erro: " . $res['gpt_erro'] . "\n";
    exit(1);
}

echo sprintf("Post ID:     #%d\n", (int)$res['post_id']);
echo sprintf("Título:      %s\n", $res['titulo']);
echo sprintf("Edit URL:    %s\n", $res['edit_url']);
echo sprintf("LLM usado:   %s%s\n",
    $res['provedor'] ?? '?',
    !empty($res['llm_fallback']) ? " \033[33m(fallback → {$res['llm_fallback']})\033[0m" : '');
echo sprintf("Fontes:      %d/%d scrapeadas · %d chars\n",
    $res['fontes_usadas'], $res['fontes_tentadas'], $res['chars_fontes']);
echo sprintf("Quality:     %s (%s)\n",
    $res['quality']['score'] ?? '?',
    $res['quality']['status'] ?? '?');
echo sprintf("Auditoria:   %s%s\n",
    !empty($res['auditoria']['ok']) ? 'OK' : 'SUSPEITA',
    !empty($res['auditoria']['nomes_suspeitos']) ? ' (' . count($res['auditoria']['nomes_suspeitos']) . ' nomes)' : '');

echo "\nPipeline extras:\n";
$af = $res['afiliado'] ?? null;
if ($af && !empty($af['injetado'])) {
    echo sprintf("  🎯 Afiliado:         INJETADO → %s (score %d)\n", $af['slug'], $af['score']);
} elseif ($af && !empty($af['erro'])) {
    echo sprintf("  🎯 Afiliado:         erro (%s)\n", $af['erro']);
} else {
    echo "  🎯 Afiliado:         não aplicável\n";
}

$ws = $res['web_story'] ?? null;
if ($ws && !empty($ws['ok'])) {
    echo sprintf("  📽️ Web Story:        CRIADA #%d (%d cenas, %dms)\n", $ws['story_id'], $ws['scenes'], $ws['tempo_ms']);
    if (!empty($ws['view_url'])) echo sprintf("     URL:             %s\n", $ws['view_url']);
} elseif ($ws && !empty($ws['pulado'])) {
    echo "  📽️ Web Story:        pulado (decisão consciente)\n";
} elseif ($ws && !empty($ws['erro'])) {
    echo sprintf("  📽️ Web Story:        FALHOU → %s (HTTP %d) \033[33m← esperado se plugin não está no WP\033[0m\n", $ws['erro'], $ws['http_code'] ?? 0);
} else {
    echo "  📽️ Web Story:        não aplicável\n";
}

$il = $res['cluster_interlink'] ?? null;
if ($il && !empty($il['evento'])) {
    echo sprintf("  🔗 Cluster interlink: %s (%d/%d posts)\n", $il['evento'], $il['atualizados'], $il['total']);
} else {
    echo "  🔗 Cluster interlink: não aplicável\n";
}

echo sprintf("  🔗 Links internos:   %d aplicados\n", $res['internal_links_count'] ?? 0);
echo sprintf("  🔗 Links autoridade: %d\n", $res['authority_links_count'] ?? 0);

echo "\n───────────────────────────────────────────────────────────────────────────\n";
echo "  ✓ Geração completa. Edite/publique em:\n";
echo "    {$res['edit_url']}\n";
echo "───────────────────────────────────────────────────────────────────────────\n";
exit(0);
