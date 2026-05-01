<?php
/**
 * Smoke do pacote "fórmula viral fechada" (8h):
 *   E1 — Pingo paralelo curl_multi (lint + presença)
 *   A2 — Composite score
 *   A1 — Calendário preditivo sazonal
 *   B1 — TL;DR / AI Overview
 *   B2 — Internal linking Hub-Spoke profundo
 *   B3 — Update transparency badge
 *   D1 — Refresh preços (existência cron)
 *   F1 — Anomaly detection (existência cron)
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/DiscoverScoreComposto.php';
require_once $rootDir . '/lib/DiscoverAiOverview.php';
require_once $rootDir . '/lib/DiscoverUpdateBadge.php';
require_once $rootDir . '/lib/DiscoverPreditorSazonal.php';
require_once $rootDir . '/lib/DiscoverHubAutoUpdate.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== E1: Pingo paralelo (curl_multi) ===\n";
$src = file_get_contents($rootDir . '/lib/DiscoverPingo.php');
check("Pingo tem método fetchXmlMulti",
    strpos($src, 'private function fetchXmlMulti') !== false);
check("Pingo usa curl_multi_init",
    strpos($src, 'curl_multi_init()') !== false);
check("Pingo tem rodarFonteComXml (XML pré-fetched)",
    strpos($src, 'rodarFonteComXml') !== false);
check("Pingo separa fontesAFetchar antes do fetch paralelo",
    strpos($src, '$fontesAFetchar') !== false);

// ─────────────────────────────────────────────
echo "\n=== A2: Composite score ===\n";
$res = DiscoverScoreComposto::calcular([
    'termo' => 'Teste',
    'data_detectada' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
    'cluster_detect' => ['key' => 'noticias_info_critica', 'score' => 5, 'nome' => 'N'],
], [
    'fontes_confirmadas' => 2,
    'predictor_label'    => 'rising',
    'subtipo_nicho'      => 'cursos técnicos',
]);
check("calcular retorna array com 'score'", isset($res['score']));
check("score > base (5.0) com fatores positivos", $res['score'] > 5.0);
check("retorna 5 fatores", count($res['fatores']) === 5);

// freshness < 30min = 2.0x; multi=2 fontes = 1.4x; predictor rising = 1.6x; cluster score=5 = 1.4x; auth match? 'Teste' vs 'cursos técnicos' = false → 0.8
// 5.0 * 2.0 * 1.4 * 1.6 * 1.4 * 0.8 = ~25
check("score com TODOS os boosts ~ 25", $res['score'] > 20 && $res['score'] < 30,
    'score=' . $res['score']);

// Caso ruim (declining + cluster fraco)
$resRuim = DiscoverScoreComposto::calcular([
    'termo' => 'Teste',
    'data_detectada' => date('Y-m-d H:i:s', strtotime('-2 days')),
    'cluster_detect' => ['key' => 'curiosidades_geral', 'score' => 1],
], ['predictor_label' => 'declining']);
check("score baixo com declining + cluster fraco", $resRuim['score'] < 3.0);

// ─────────────────────────────────────────────
echo "\n=== A1: Calendário preditivo sazonal ===\n";
$preditor = new DiscoverPreditorSazonal();

// Mock site simulando estrutura sites.php
$sitesMock = [
    'cursosenac' => ['persona' => ['clusters_foco' => ['noticias_info_critica']]],
    'comocomprar' => ['persona' => ['clusters_foco' => ['lifestyle_consumo', 'tecnologia']]],
];

// dry-run pra não tocar DB real
$res = $preditor->rodar($sitesMock, 365, true);  // janela larga pra capturar algum evento
check("rodar dry-run retorna array com 'eventos'", isset($res['eventos']));
check("score boost progressivo: ≤3d = 15.0",
    DiscoverPreditorSazonal::scorePorProximidade(2) === 15.0);
check("score boost: 14d = 9.0",
    DiscoverPreditorSazonal::scorePorProximidade(14) === 9.0);
check("score boost: 30d = 6.5",
    DiscoverPreditorSazonal::scorePorProximidade(30) === 6.5);

// Templates conhecidos
check("templates contém Black Friday",
    isset(DiscoverPreditorSazonal::TEMPLATES_TERMO['Black Friday']));
check("templates contém ENEM",
    isset(DiscoverPreditorSazonal::TEMPLATES_TERMO['ENEM']));

// ─────────────────────────────────────────────
echo "\n=== B1: AI Overview optimizer ===\n";
// Caso 1: P1 já está ready (tem número + entidade + temporal + verbo)
$htmlReady = '<h1>Título</h1><p>O ENEM 2026 abriu inscrições nesta segunda com 5 milhões de vagas previstas.</p>';
$out1 = DiscoverAiOverview::aplicar($htmlReady, ['titulo' => 'ENEM 2026', 'url' => 'https://x/y']);
// Match no DIV visual (não no JSON do Speakable que cita ai-overview-tldr como cssSelector)
$temBlocoTldr = fn($html) => strpos($html, 'class="ai-overview-tldr"') !== false;
check("ready: NÃO injeta bloco TL;DR visual (já está bom)",
    !$temBlocoTldr($out1));
check("ready: AINDA assim adiciona Speakable schema",
    strpos($out1, 'data-speakable="1"') !== false);

// Caso 2: P1 vago (sem número/entidade/temporal)
$htmlVago = '<h1>Curso</h1><p>Para você que quer fazer um curso, isto é importante saber e considerar.</p>';
$out2 = DiscoverAiOverview::aplicar($htmlVago, ['titulo' => 'Curso de PHP', 'url' => 'https://x/y']);
check("vago: INJETA bloco TL;DR visual",
    $temBlocoTldr($out2));
check("TL;DR aparece ANTES do P1",
    strpos($out2, 'class="ai-overview-tldr"') < strpos($out2, '<p>'));

// Idempotência
$out3 = DiscoverAiOverview::aplicar($out2, ['titulo' => 'Curso', 'url' => 'https://x/y']);
check("idempotência: 2× aplicar não duplica bloco visual",
    substr_count($out3, 'class="ai-overview-tldr"') === substr_count($out2, 'class="ai-overview-tldr"'));

// Meta description otimizada
$desc = DiscoverAiOverview::metaDescription(
    'ENEM 2026',
    'O Inep abriu nesta segunda as inscrições do ENEM 2026 com 5 milhões de vagas. O prazo termina em 14 dias úteis e a isenção pode ser pedida pelo gov.br.'
);
check("metaDescription <= 155 chars", mb_strlen($desc) <= 155);

// ─────────────────────────────────────────────
echo "\n=== B2: Hub-Spoke profundo ===\n";
$srcRel = file_get_contents($rootDir . '/lib/DiscoverRelatedLinks.php');
check("DiscoverRelatedLinks calcula score com boost de recência",
    strpos($srcRel, 'boostRecencia') !== false);
check("DiscoverRelatedLinks tem score_final composto",
    strpos($srcRel, "score_final") !== false);

check("DiscoverHubAutoUpdate::adicionarSpoke existe",
    method_exists('DiscoverHubAutoUpdate', 'adicionarSpoke'));

check("script incrementar_hubs.php existe",
    is_file($rootDir . '/scripts/incrementar_hubs.php'));

// ─────────────────────────────────────────────
echo "\n=== B3: Update transparency badge ===\n";
$htmlSemBadge = '<h1>Título</h1><p>Conteúdo</p>';
$comBadge = DiscoverUpdateBadge::aplicar($htmlSemBadge, time(), 'revisão editorial');
check("badge aplicado contém data-update-badge",
    strpos($comBadge, 'data-update-badge=') !== false);
check("badge contém time itemprop=dateModified",
    strpos($comBadge, 'itemprop="dateModified"') !== false);
check("badge contém o motivo passado",
    strpos($comBadge, 'revisão editorial') !== false);
check("badge fica APÓS o </h1>",
    strpos($comBadge, '</h1>') < strpos($comBadge, 'cc-update-badge'));

// Idempotência (substitui antigo)
$comBadge2 = DiscoverUpdateBadge::aplicar($comBadge, time() + 60, 'novo motivo');
check("idempotência: 2 aplicar = 1 badge no output",
    substr_count($comBadge2, 'data-update-badge="') === 1);

// badgeRecente
check("badgeRecente: badge do agora é recente", DiscoverUpdateBadge::badgeRecente($comBadge2));
$htmlVelho = '<div data-update-badge="' . (time() - 90 * 86400) . '" class="cc-update-badge"></div>';
check("badgeRecente: badge de 90d atrás NÃO é recente",
    !DiscoverUpdateBadge::badgeRecente($htmlVelho, 86400));

// Wire em DiscoverReviewer
$srcReviewer = file_get_contents($rootDir . '/lib/DiscoverReviewer.php');
check("Reviewer aplica DiscoverUpdateBadge antes de salvar",
    strpos($srcReviewer, 'DiscoverUpdateBadge::aplicar') !== false);

// ─────────────────────────────────────────────
echo "\n=== D1: Refresh preços (script existe) ===\n";
check("scripts/refresh_precos.php existe",
    is_file($rootDir . '/scripts/refresh_precos.php'));
check("script tem CronLock", strpos(file_get_contents($rootDir . '/scripts/refresh_precos.php'), 'CronLock') !== false);
check("script filtra cluster shopping/tech",
    strpos(file_get_contents($rootDir . '/scripts/refresh_precos.php'), 'lifestyle_consumo') !== false);

// ─────────────────────────────────────────────
echo "\n=== F1: Anomaly detection ===\n";
check("scripts/anomaly_detect.php existe",
    is_file($rootDir . '/scripts/anomaly_detect.php'));
$srcAno = file_get_contents($rootDir . '/scripts/anomaly_detect.php');
check("anomaly compara baseline vs atual normalizado por dia",
    strpos($srcAno, 'impBasePorDia') !== false);
check("anomaly filtra surface=discover",
    strpos($srcAno, "'discover'") !== false);
check("anomaly aplica minBaseline (anti-ruído)",
    strpos($srcAno, '$minBaseline') !== false);

// ─────────────────────────────────────────────
echo "\n=== Wire em DiscoverPostProcess (B1) ===\n";
$srcPp = file_get_contents($rootDir . '/lib/DiscoverPostProcess.php');
check("DiscoverPostProcess require DiscoverAiOverview",
    strpos($srcPp, 'DiscoverAiOverview.php') !== false);
check("DiscoverPostProcess chama DiscoverAiOverview::aplicar",
    strpos($srcPp, 'DiscoverAiOverview::aplicar') !== false);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[PACOTE 8H] OK\n" : "\n[PACOTE 8H] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
