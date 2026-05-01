<?php
/**
 * Smoke: TITLE A/B + FAQ ENRICHER
 *  - DiscoverTitleVariantes (mock LLM): gera variantes válidas, rejeita inválidas
 *  - DiscoverTitleSwapper: cenários elegível/inelegível, idempotência, max swaps
 *  - DiscoverFaqEnricher: injeta seção FAQ do PAA quando HTML não tem
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/DiscoverTitleVariantes.php';
require_once $ROOT . '/lib/DiscoverTitleSwapper.php';
require_once $ROOT . '/lib/DiscoverFaqEnricher.php';

$ok = 0; $fail = 0;
function check(string $nome, $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [OK]   {$nome}\n"; }
    else       { $fail++; echo "  [FAIL] {$nome}\n"; }
}

// ════════════════════════════════════════════════════════════
echo "\n=== 1: DiscoverTitleVariantes (mock LLM) ===\n";

class MockLlmOk {
    public string $response = "1. ENEM 2026 abre prazo de inscrição em maio com 5 mil vagas\n2. Inscrição ENEM 2026 começa em maio e vai por 24 dias só\n";
    public function ask(string $prompt, array $opts = []) { return $this->response; }
}
class MockLlmCurto {
    public function ask(string $prompt, array $opts = []) { return "1. Curto\n2. Outro curto demais\n"; }
}
class MockLlmClickbait {
    public function ask(string $prompt, array $opts = []) {
        return "1. Você não vai acreditar no que aconteceu com o ENEM 2026 incrível\n2. ENEM 2026 chocante: nova regra surpreendente que muda tudo agora\n";
    }
}
class MockLlmFalha {
    public function ask(string $prompt, array $opts = []) { throw new RuntimeException('llm down'); }
}

$tituloOk = 'ENEM 2026 abre 5 milhões de vagas; inscrição até maio';
$variantes = DiscoverTitleVariantes::gerar($tituloOk, 'enem 2026', 'inscrições abertas em maio', new MockLlmOk());
check('LLM OK retorna 2 variantes', count($variantes) === 2);
check('1ª variante tem 50-70 chars', mb_strlen($variantes[0] ?? '') >= 50 && mb_strlen($variantes[0] ?? '') <= 70);

$variantesCurto = DiscoverTitleVariantes::gerar($tituloOk, 'enem 2026', '', new MockLlmCurto());
check('LLM com variantes < 50 chars → rejeita todas', empty($variantesCurto));

$variantesCb = DiscoverTitleVariantes::gerar($tituloOk, 'enem 2026', '', new MockLlmClickbait());
check('LLM com clickbait → rejeita todas', empty($variantesCb));

$variantesNull = DiscoverTitleVariantes::gerar($tituloOk, 'enem 2026', '', new MockLlmFalha());
check('LLM falha → retorna []', $variantesNull === []);

$variantesVazio = DiscoverTitleVariantes::gerar('', 'x', '', new MockLlmOk());
check('título vazio → retorna []', $variantesVazio === []);

// ════════════════════════════════════════════════════════════
echo "\n=== 2: DiscoverTitleSwapper ===\n";

class MockWpSwap {
    public array $calls = [];
    public function atualizarPost(int $postId, array $data): array {
        $this->calls[] = ['post_id' => $postId, 'data' => $data];
        return ['ok' => true];
    }
}
class MockDbSwap {
    public array $records = [];
    public function updateStatus(int $id, string $status, array $extra = []): bool {
        if (!isset($this->records[$id])) $this->records[$id] = ['id' => $id];
        $this->records[$id]['status'] = $status;
        foreach ($extra as $k => $v) $this->records[$id][$k] = $v;
        return true;
    }
    public function get(int $id): ?array { return $this->records[$id] ?? null; }
}

$db = new MockDbSwap();
$wp = new MockWpSwap();
$db->records[100] = [
    'id' => 100, 'post_id' => 555, 'status' => 'publicado',
    'titulo' => 'Título A original com tamanho razoável aqui',
    'titulo_variantes' => [
        'Variante B com ângulo de urgência diferente do A original',
        'Variante C totalmente diferente das duas anteriores aqui',
    ],
    'publicado_em' => date('Y-m-d H:i:s', strtotime('-15 days')),
];

// Cenário 1: tudo OK → swap
$stats = ['ctr_pct' => 0.5, 'impressions' => 200, 'clicks' => 1, 'position' => 6.5];
$r = DiscoverTitleSwapper::tentarSwap($db->records[100], $stats, [], $db, $wp);
check('elegível → swap', ($r['acao'] ?? '') === 'swap');
check('WP recebeu update do title', count($wp->calls) === 1 && isset($wp->calls[0]['data']['title']));
check('histórico atualizado no DB', !empty($db->records[100]['title_swap_history']));
check('título atual = variante B', strpos((string)$db->records[100]['titulo'], 'Variante B') === 0);

// Cenário 2: CTR alto → skip
$db->records[101] = $db->records[100];
$db->records[101]['id'] = 101;
$db->records[101]['title_swap_history'] = []; // reset
$rOk = DiscoverTitleSwapper::tentarSwap($db->records[101], ['ctr_pct' => 5.0, 'impressions' => 200, 'clicks' => 10, 'position' => 5], [], $db, $wp);
check('CTR alto → skip', ($rOk['acao'] ?? '') === 'skip');

// Cenário 3: posição > 10 → skip
$rPos = DiscoverTitleSwapper::tentarSwap($db->records[101], ['ctr_pct' => 0.3, 'impressions' => 500, 'clicks' => 1, 'position' => 25], [], $db, $wp);
check('posição > 10 → skip', ($rPos['acao'] ?? '') === 'skip');

// Cenário 4: idade < 7d → skip
$db->records[102] = $db->records[100];
$db->records[102]['id'] = 102;
$db->records[102]['publicado_em'] = date('Y-m-d H:i:s', strtotime('-3 days'));
$db->records[102]['title_swap_history'] = [];
$rIdade = DiscoverTitleSwapper::tentarSwap($db->records[102], $stats, [], $db, $wp);
check('idade < 7d → skip', ($rIdade['acao'] ?? '') === 'skip');

// Cenário 5: sem variantes → skip
$db->records[103] = $db->records[100];
$db->records[103]['id'] = 103;
unset($db->records[103]['titulo_variantes']);
$rSemVar = DiscoverTitleSwapper::tentarSwap($db->records[103], $stats, [], $db, $wp);
check('sem variantes → skip', ($rSemVar['acao'] ?? '') === 'skip');

// Cenário 6: max swaps
$db->records[104] = $db->records[100];
$db->records[104]['id'] = 104;
$db->records[104]['title_swap_history'] = [
    ['de' => 'A', 'para' => 'B', 'em' => date('Y-m-d H:i:s', strtotime('-15d'))],
    ['de' => 'B', 'para' => 'C', 'em' => date('Y-m-d H:i:s', strtotime('-8d'))],
];
$rMax = DiscoverTitleSwapper::tentarSwap($db->records[104], $stats, [], $db, $wp);
check('max swaps (2) → skip', ($rMax['acao'] ?? '') === 'skip');

// ════════════════════════════════════════════════════════════
echo "\n=== 3: DiscoverFaqEnricher ===\n";

$paaMock = [
    ['question' => 'Como me inscrever no ENEM 2026', 'answer_snippet' => 'Acesse o site oficial enem.inep.gov.br no período de inscrição e preencha o formulário com seus dados pessoais e CPF.'],
    ['question' => 'Qual a taxa de inscrição do ENEM 2026', 'answer_snippet' => 'A taxa atual é R$ 85, podendo ser isenta para estudantes de baixa renda mediante comprovação no sistema.'],
    ['question' => 'Quando começam as provas do ENEM 2026', 'answer_snippet' => 'As provas serão aplicadas em dois domingos consecutivos no mês de novembro segundo o cronograma oficial.'],
];

// Cenário 1: HTML simples, sem FAQ + PAA disponível → injeta
$html = "<h1>Título</h1><p>Texto qualquer aqui sem FAQ.</p>";
$enriquecido = DiscoverFaqEnricher::aplicar($html, ['paa' => $paaMock]);
check('injeta seção FAQ quando ausente', strpos($enriquecido, 'Perguntas frequentes') !== false);
check('injeta tags <details>', substr_count($enriquecido, '<details') >= 3);
check('marker idempotência presente', strpos($enriquecido, 'data-cc-faq-enriched') !== false);

// Cenário 2: idempotência
$enriquecido2 = DiscoverFaqEnricher::aplicar($enriquecido, ['paa' => $paaMock]);
check('idempotente: 2ª chamada não duplica', $enriquecido === $enriquecido2);

// Cenário 3: HTML já tem FAQ section → no-op
$htmlFaq = "<h1>X</h1><h2>Perguntas frequentes</h2><details><summary>Q1?</summary><p>A1</p></details>";
$enriquecidoFaq = DiscoverFaqEnricher::aplicar($htmlFaq, ['paa' => $paaMock]);
check('HTML com FAQ → no-op', $enriquecidoFaq === $htmlFaq);

// Cenário 4: HTML com 2+ details → no-op
$htmlDetails = "<details><summary>Q1?</summary><p>A1</p></details><details><summary>Q2?</summary><p>A2</p></details>";
$enriquecidoDetails = DiscoverFaqEnricher::aplicar($htmlDetails, ['paa' => $paaMock]);
check('HTML com 2+ details → no-op', $enriquecidoDetails === $htmlDetails);

// Cenário 5: PAA insuficiente → no-op
$paaPouco = [['question' => 'Q?', 'answer_snippet' => 'curto']];
$enriquecidoPouco = DiscoverFaqEnricher::aplicar($html, ['paa' => $paaPouco]);
check('PAA < 3 perguntas → no-op', $enriquecidoPouco === $html);

// Cenário 6: respostas curtas filtradas
$paaInval = [
    ['question' => 'Q1?', 'answer_snippet' => 'x'],
    ['question' => 'Q2?', 'answer_snippet' => 'y'],
    ['question' => 'Q3?', 'answer_snippet' => 'z'],
];
$enriquecidoInval = DiscoverFaqEnricher::aplicar($html, ['paa' => $paaInval]);
check('respostas < 20 chars → todas filtradas → no-op', $enriquecidoInval === $html);

// Cenário 7: trend payload em vez de meta
$enriquecidoTrend = DiscoverFaqEnricher::aplicar($html, [], ['paa' => $paaMock]);
check('PAA via $trend funciona também', strpos($enriquecidoTrend, 'Perguntas frequentes') !== false);

// Cenário 8: PAA aninhado em ctr_intel.paa
$enriquecidoAn = DiscoverFaqEnricher::aplicar($html, [], ['ctr_intel' => ['paa' => $paaMock]]);
check('PAA via $trend.ctr_intel.paa funciona', strpos($enriquecidoAn, 'Perguntas frequentes') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== Wire em DiscoverGerador / gsc_aprender ===\n";

$gerador = file_get_contents($ROOT . '/lib/DiscoverGerador.php');
check('DiscoverGerador require DiscoverTitleVariantes', strpos($gerador, 'DiscoverTitleVariantes') !== false);
check('DiscoverGerador persiste titulo_variantes',     strpos($gerador, "'titulo_variantes'") !== false || strpos($gerador, '"titulo_variantes"') !== false);
check('DiscoverGerador propaga paa via metaPos', strpos($gerador, "metaPos['paa']") !== false);

$aprender = file_get_contents($ROOT . '/scripts/gsc_aprender.php');
check('gsc_aprender require DiscoverTitleSwapper',       strpos($aprender, 'DiscoverTitleSwapper') !== false);
check('gsc_aprender chama tentarSwap antes do Reviewer', strpos($aprender, 'tentarSwap') !== false);

$postProc = file_get_contents($ROOT . '/lib/DiscoverPostProcess.php');
check('PostProcess require DiscoverFaqEnricher', strpos($postProc, 'DiscoverFaqEnricher') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n";
echo "  FAIL: {$fail}\n";

if ($fail > 0) { echo "\n[TITLE A/B + FAQ] FAIL\n"; exit(1); }
echo "\n[TITLE A/B + FAQ] OK\n";
exit(0);
