<?php
/**
 * Smoke ROI — valida 5 entregas de otimização:
 *   1. Anthropic prompt caching (montarSystemPayload)
 *   2. Serper cache 24h
 *   3. CostTracker resumo
 *   4. QuoteEnrichment extrai + injeta
 *   5. VisionAlt (mock OpenAI)
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/Claude.php';
require_once $rootDir . '/lib/CostTracker.php';
require_once $rootDir . '/lib/DiscoverQuoteEnrichment.php';
require_once $rootDir . '/lib/DiscoverVisionAlt.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: Claude::montarSystemPayload ===\n";

$pequeno = 'system pequeno';
$out1 = Claude::montarSystemPayload($pequeno);
check("system pequeno → string direta (sem cache)", is_string($out1) && $out1 === $pequeno);

$grande = str_repeat('a', 3000);
$out2 = Claude::montarSystemPayload($grande);
check("system >2000 chars → array com cache_control",
    is_array($out2) && isset($out2[0]['cache_control']) && $out2[0]['cache_control']['type'] === 'ephemeral');

// Marker explícito
$comMarker = "PARTE ESTÁVEL\n<!--CACHE_BREAK-->\nparte variável";
$out3 = Claude::montarSystemPayload($comMarker);
check("marker → split em 2 blocos", is_array($out3) && count($out3) === 2);
check("bloco estável tem cache_control",
    is_array($out3) && isset($out3[0]['cache_control']));
check("bloco variável NÃO tem cache_control",
    is_array($out3) && !isset($out3[1]['cache_control']));
check("conteúdo dos blocos preservado",
    is_array($out3) && trim($out3[0]['text']) === 'PARTE ESTÁVEL'
        && trim($out3[1]['text']) === 'parte variável');

// Marker no início (parte estável vazia)
$markerNoInicio = "<!--CACHE_BREAK-->\nsó variável";
$out4 = Claude::montarSystemPayload($markerNoInicio);
check("marker no início → 1 bloco sem cache",
    is_array($out4) && count($out4) === 1 && !isset($out4[0]['cache_control']));

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: Claude::logCacheStats grava JSONL ===\n";
$logFile = $rootDir . '/data/cost_tracker/llm_calls.jsonl';
$linesBefore = is_file($logFile) ? count(file($logFile)) : 0;

Claude::logCacheStats([
    'input_tokens'                => 1000,
    'cache_creation_input_tokens' => 5000,
    'cache_read_input_tokens'     => 6000,
    'output_tokens'               => 800,
]);
clearstatcache();
$linesAfter = is_file($logFile) ? count(file($logFile)) : 0;
check("logCacheStats append em llm_calls.jsonl", $linesAfter > $linesBefore);

// Última linha tem campos esperados
$last = trim((string)@file_get_contents($logFile));
$ultimaLinha = explode("\n", $last);
$ultima = json_decode(end($ultimaLinha), true);
check("registro tem cache_hit_ratio computado",
    isset($ultima['cache_hit_ratio']) && abs($ultima['cache_hit_ratio'] - (6000 / 7000)) < 0.01);

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: Serper cache (key + path) ===\n";
require_once $rootDir . '/lib/Serper.php';

$rfSer = new ReflectionClass('Serper');
$mKey = $rfSer->getMethod('cacheKey');
$mKey->setAccessible(true);
$mPath = $rfSer->getMethod('cacheFilePath');
$mPath->setAccessible(true);

$key1 = $mKey->invoke(null, '/search', ['q' => 'enem', 'num' => 10]);
$key2 = $mKey->invoke(null, '/search', ['num' => 10, 'q' => 'enem']);
check("cacheKey é determinístico (ordem keys irrelevante)", $key1 === $key2);

$key3 = $mKey->invoke(null, '/search', ['q' => 'fies', 'num' => 10]);
check("cacheKey muda quando query muda", $key1 !== $key3);

$path = $mPath->invoke(null, $key1);
check("cacheFilePath gera path com sub-dir 2 chars",
    is_string($path) && preg_match('#/serper/[a-f0-9]{2}/[a-f0-9]+\.json$#', $path) === 1,
    'path=' . $path);

// Endpoints sem cache
$rfNoCache = $rfSer->getReflectionConstant('NO_CACHE_PATHS');
check("/news está em NO_CACHE_PATHS",
    in_array('/news', $rfNoCache->getValue(), true));

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: CostTracker resumo ===\n";
// Os logs já existem do teste 2 + serper cache events anteriores
$resumo = CostTracker::resumoDoDia();
check("resumoDoDia retorna array com 'total'", isset($resumo['total']));
check("resumo tem llm.calls", isset($resumo['llm']['calls']));
check("resumo tem serper.hit_ratio", isset($resumo['serper']['hit_ratio']));
check("resumo tem total.custo_usd", isset($resumo['total']['custo_usd']));

// CostTracker::logManual
CostTracker::logManual('teste_smoke', ['valor' => 42, 'tipo' => 'roi_test']);
$logTeste = $rootDir . '/data/cost_tracker/teste_smoke_calls.jsonl';
check("logManual cria arquivo correto", is_file($logTeste));
@unlink($logTeste);

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: QuoteEnrichment ===\n";
$fontesMock = [
    [
        'url' => 'https://www.gov.br/inep/pt-br/noticia',
        'fonte' => [
            'meta' => ['title' => 'Inep divulga edital'],
            'content' => [
                'paragraphs' => [
                    'Texto introdutório aqui.',
                    'O Inep informou em nota oficial: "A inscrição para o ENEM 2026 começa nesta segunda e seguirá até o dia 14 de junho."',
                    'Mais detalhes seguem.',
                ],
            ],
        ],
    ],
];

$htmlIn = '<h1>Título</h1>'
       . '<p>Intro.</p>'
       . '<h2>Seção 1</h2>'
       . '<p>Conteúdo 1.</p>'
       . '<h2>Seção 2</h2>'
       . '<p>Conteúdo 2.</p>';

$out = DiscoverQuoteEnrichment::aplicar($htmlIn, $fontesMock, ['titulo' => 'ENEM']);

check("quote injetada (HTML cresceu)",
    strlen($out) > strlen($htmlIn));
check("quote contém marker data-cc-quote",
    strpos($out, 'data-cc-quote="1"') !== false);
check("quote contém URL fonte oficial",
    strpos($out, 'gov.br') !== false);
check("quote contém badge OFICIAL (fonte .gov.br)",
    strpos($out, 'OFICIAL') !== false);

// Idempotência
$out2 = DiscoverQuoteEnrichment::aplicar($out, $fontesMock, ['titulo' => 'ENEM']);
check("idempotência: 2× aplicar não duplica",
    substr_count($out2, 'data-cc-quote="1"') === 1);

// Sem fontes → no-op
$semFonte = DiscoverQuoteEnrichment::aplicar($htmlIn, [], []);
check("sem fontes: HTML inalterado", $semFonte === $htmlIn);

// Fontes sem quote elegível
$fontesPobres = [['url' => 'https://x.com', 'fonte' => ['content' => ['paragraphs' => ['Texto curto.']]]]];
$outPobre = DiscoverQuoteEnrichment::aplicar($htmlIn, $fontesPobres, []);
check("sem quote elegível: HTML inalterado", $outPobre === $htmlIn);

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: VisionAlt sem credencial ===\n";
$alt = DiscoverVisionAlt::gerar('https://x/imagem.jpg', 'tema', []);
check("sem openai_api_key → null", $alt === null);

// URL inválida
$alt2 = DiscoverVisionAlt::gerar('caminho/local.jpg', 'tema', ['openai_api_key' => 'k']);
check("URL não-http → null", $alt2 === null);

// ─────────────────────────────────────────────
echo "\n=== TESTE 7: DiscoverImagemSEO aceita imageUrl + cfg ===\n";
require_once $rootDir . '/lib/DiscoverImagemSEO.php';
$rfImg = new ReflectionMethod('DiscoverImagemSEO', 'gerar');
$params = $rfImg->getParameters();
$paramNames = array_map(fn($p) => $p->getName(), $params);
check("DiscoverImagemSEO::gerar tem param 'imageUrl'", in_array('imageUrl', $paramNames, true));
check("DiscoverImagemSEO::gerar tem param 'cfg'", in_array('cfg', $paramNames, true));

// Sem imageUrl → fallback gerarAltText (já existente, comportamento legacy)
$res = DiscoverImagemSEO::gerar('Título Teste', 'enem 2026', '');
check("DiscoverImagemSEO retorna alt_via_vision=false sem imageUrl",
    isset($res['alt_via_vision']) && $res['alt_via_vision'] === false);
check("DiscoverImagemSEO retorna alt_text não-vazio",
    !empty($res['alt_text']));

// ─────────────────────────────────────────────
echo "\n=== TESTE 8: Wire em DiscoverGerador ===\n";
$srcGer = file_get_contents($rootDir . '/lib/DiscoverGerador.php');
check("DiscoverGerador passa fontes em metaPos",
    strpos($srcGer, "\$metaPos['fontes'] = \$fontesOk") !== false);
check("DiscoverGerador passa imgUrl + cfgTrend pra DiscoverImagemSEO",
    strpos($srcGer, "\$imgUrl, \$cfgTrend") !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 9: Wire em DiscoverPostProcess ===\n";
$srcPp = file_get_contents($rootDir . '/lib/DiscoverPostProcess.php');
check("PostProcess require DiscoverQuoteEnrichment",
    strpos($srcPp, 'DiscoverQuoteEnrichment.php') !== false);
check("PostProcess chama DiscoverQuoteEnrichment::aplicar",
    strpos($srcPp, 'DiscoverQuoteEnrichment::aplicar') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 10: saude.php?stats=1 (CLI subprocess) ===\n";
require_once $rootDir . '/lib/Saude.php';
$stats = Saude::stats();
check("Saude::stats retorna array", is_array($stats));
check("stats.ok=true", !empty($stats['ok']));
check("stats.hoje + mes_atual presentes",
    isset($stats['hoje']) && isset($stats['mes_atual']));

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[ROI] OK\n" : "\n[ROI] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
