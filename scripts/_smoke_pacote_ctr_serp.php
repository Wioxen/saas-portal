<?php
/**
 * Smoke: PACOTE B — CTR + SERP (Featured Snippet Hijacker + og:title + meta A/B)
 *  - SerpAnalyzer detecta featured snippet (paragraph/list/table) + emite diretiva
 *  - DiscoverMetaTags gera og_title + meta_description + 2 variantes
 *  - aplicarNoWp seta meta keys de Yoast + RankMath + SEOPress
 *  - DiscoverMetaSwapper: A/B sequencial via GSC (espera Title/P1 esgotarem)
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/DiscoverSerpAnalyzer.php';
require_once $ROOT . '/lib/DiscoverMetaTags.php';
require_once $ROOT . '/lib/DiscoverMetaSwapper.php';

$ok = 0; $fail = 0;
function check(string $nome, $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [OK]   {$nome}\n"; }
    else       { $fail++; echo "  [FAIL] {$nome}\n"; }
}

// ════════════════════════════════════════════════════════════
echo "\n=== 1: SerpAnalyzer · Featured Snippet detect ===\n";

class MockSerperFS {
    public array $resp;
    public function search(string $termo, int $limit) { return $this->resp; }
}

$serp = new MockSerperFS();
// Cenário 1: snippet tipo paragraph (resposta direta)
$serp->resp = [
    'answerBox' => [
        'title' => 'O que é ENEM?',
        'snippet' => 'O Exame Nacional do Ensino Médio é uma prova aplicada pelo Inep que avalia o conhecimento dos estudantes do ensino médio no Brasil.',
        'link' => 'https://gov.br/inep/enem-x',
    ],
    'organic' => [
        ['link' => 'https://gov.br/inep/enem-x', 'title' => 'ENEM oficial Inep 2024', 'snippet' => 'Sobre o ENEM', 'date' => '2024-08-01'],
        ['link' => 'https://outro.com/enem',     'title' => 'Tudo sobre ENEM',         'snippet' => 'Guia completo', 'date' => '2023-09-15'],
    ],
];
$intel1 = DiscoverSerpAnalyzer::analisar('o que é enem 2026 ' . uniqid(), $serp);
check('detecta featured snippet presente', !empty($intel1['featured_snippet']['tem']));
check('tipo = paragraph', ($intel1['featured_snippet']['tipo'] ?? '') === 'paragraph');
check('captura dono_dominio',                ($intel1['featured_snippet']['dono_dominio'] ?? '') === 'gov.br');
$bloco1 = DiscoverSerpAnalyzer::paraPromptContext($intel1);
check('bloco menciona POSITION 0', strpos($bloco1, 'POSITION 0') !== false || strpos($bloco1, 'position 0') !== false || strpos($bloco1, 'FEATURED SNIPPET') !== false);
check('bloco com diretiva paragraph (40-60 palavras)', strpos($bloco1, '40-60 palavras') !== false);

// Cenário 2: snippet tipo list (passos numerados)
$serp->resp = [
    'answerBox' => [
        'title' => 'Como se inscrever no ENEM',
        'snippet' => "1. Acesse o portal\n2. Faça login com gov.br\n3. Preencha o formulário\n4. Pague a taxa\n5. Imprima o comprovante",
        'link' => 'https://outro-site.com/enem-passos',
    ],
    'organic' => [
        ['link' => 'https://outro-site.com/enem-passos', 'title' => 'Passo a passo ENEM', 'snippet' => 'Como', 'date' => '2024-01-01'],
    ],
];
$intel2 = DiscoverSerpAnalyzer::analisar('como se inscrever enem ' . uniqid(), $serp);
check('detecta tipo = list', ($intel2['featured_snippet']['tipo'] ?? '') === 'list');
$bloco2 = DiscoverSerpAnalyzer::paraPromptContext($intel2);
check('bloco list pede <ol>', strpos($bloco2, '<ol>') !== false);
check('bloco list pede passos numerados', strpos($bloco2, 'numerada') !== false || strpos($bloco2, 'passos') !== false);

// Cenário 3: SEM snippet (oportunidade livre)
$serp->resp = [
    'organic' => [
        ['link' => 'https://x.com/y', 'title' => 'Algum post', 'snippet' => 'Sem answer box aqui', 'date' => '2024-01-01'],
    ],
];
$intel3 = DiscoverSerpAnalyzer::analisar('termo super raro 12345 ' . uniqid(), $serp);
check('SEM featured snippet → tem=false', empty($intel3['featured_snippet']['tem']));
$bloco3 = DiscoverSerpAnalyzer::paraPromptContext($intel3);
check('bloco "OPORTUNIDADE LIVRE" emitido', strpos($bloco3, 'OPORTUNIDADE LIVRE') !== false);
check('bloco oportunidade livre instrui criar candidate',
    strpos($bloco3, 'PRIMEIRA seção') !== false || strpos($bloco3, 'resposta DIRETA') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== 2: DiscoverMetaTags ===\n";

class MockLlmMeta {
    public string $response;
    public function __construct(string $r) { $this->response = $r; }
    public function ask(string $prompt, array $opts = []) { return $this->response; }
}

$resp = "OG_TITLE: ENEM 2026: 5 mil vagas extras e prazo curto pra inscrição\n"
      . "META_A: ENEM 2026 abriu 5 mil vagas com prazo de inscrição de apenas 8 dias úteis. Veja o passo a passo pra garantir a sua participação\n"
      . "META_B: Cinco mil novas vagas no ENEM 2026; quem perde o prazo de 8 dias fica fora do ciclo. Confira aqui como se inscrever rápido\n"
      . "META_C: Com 5 mil vagas extras, ENEM 2026 abre inscrição até 24 de maio com taxa de 85 reais; saiba como participar do exame\n";

$tags = DiscoverMetaTags::gerar('ENEM 2026 abre 5 mil vagas com prazo curto', 'P1 do post', 'enem 2026', '', new MockLlmMeta($resp));
check('og_title gerado',           !empty($tags['og_title']));
check('og_title <= 90 chars',      mb_strlen($tags['og_title'] ?? '', 'UTF-8') <= 90);
check('meta_description gerada',   !empty($tags['meta_description']));
check('meta_description 110-155 chars', mb_strlen($tags['meta_description'] ?? '', 'UTF-8') >= 110 && mb_strlen($tags['meta_description'] ?? '', 'UTF-8') <= 155);
check('2 variantes alternativas',  count($tags['meta_description_variantes'] ?? []) === 2);

// Validação: meta longa > 155 chars deve ser rejeitada
$respLonga = "OG_TITLE: Curto ok demais\nMETA_A: " . str_repeat('a', 200) . "\nMETA_B: " . str_repeat('b', 200) . "\n";
$tagsLong = DiscoverMetaTags::gerar('Título', 'P1', 'termo', '', new MockLlmMeta($respLonga));
check('meta > 155 chars → descartada', empty($tagsLong['meta_description']));

// Clickbait detect
$respCb = "OG_TITLE: ENEM 2026 incrível: vagas surpreendentes\n"
        . "META_A: O ENEM 2026 trouxe novidades absolutamente incríveis e surpreendentes que você não vai acreditar quando ler\n"
        . "META_B: Confira o melhor guia ENEM 2026 com dicas práticas pra fazer a sua inscrição em poucos minutos hoje mesmo\n";
$tagsCb = DiscoverMetaTags::gerar('Título', 'P1', 'enem', '', new MockLlmMeta($respCb));
check('og_title clickbait → rejeitado', empty($tagsCb['og_title']));
check('meta_A clickbait → rejeitada',   empty($tagsCb['meta_description']));

// ════════════════════════════════════════════════════════════
echo "\n=== 3: aplicarNoWp (Yoast + RankMath + SEOPress meta keys) ===\n";

class MockWpMeta {
    public array $calls = [];
    public function atualizarPost(int $postId, array $data): array {
        $this->calls[] = ['post_id' => $postId, 'data' => $data];
        return ['ok' => true];
    }
}

$wpM = new MockWpMeta();
$tags2 = [
    'og_title'         => 'ENEM 2026: 5 mil vagas extras e prazo curto',
    'meta_description' => 'ENEM 2026 abriu 5 mil vagas com prazo de inscrição de 8 dias úteis. Veja o passo a passo pra garantir a sua',
];
$ok2 = DiscoverMetaTags::aplicarNoWp($wpM, 555, $tags2);
check('aplicarNoWp retorna true', $ok2 === true);
check('chamou WP atualizarPost',  count($wpM->calls) === 1);
$meta = $wpM->calls[0]['data']['meta'] ?? [];
check('seta _yoast_wpseo_metadesc',         isset($meta['_yoast_wpseo_metadesc']));
check('seta _yoast_wpseo_opengraph-title',  isset($meta['_yoast_wpseo_opengraph-title']));
check('seta rank_math_description',         isset($meta['rank_math_description']));
check('seta rank_math_facebook_title',      isset($meta['rank_math_facebook_title']));
check('seta _seopress_titles_desc',         isset($meta['_seopress_titles_desc']));
check('excerpt fallback setado',            !empty($wpM->calls[0]['data']['excerpt']));

// Tags vazias → não chama WP
$wpM2 = new MockWpMeta();
$ok3 = DiscoverMetaTags::aplicarNoWp($wpM2, 555, []);
check('tags vazias → não chama WP', $ok3 === false && empty($wpM2->calls));

// ════════════════════════════════════════════════════════════
echo "\n=== 4: DiscoverMetaSwapper ===\n";

class MockDbMeta {
    public array $records = [];
    public function updateStatus(int $id, string $status, array $extra = []): bool {
        if (!isset($this->records[$id])) $this->records[$id] = ['id' => $id];
        $this->records[$id]['status'] = $status;
        foreach ($extra as $k => $v) $this->records[$id][$k] = $v;
        return true;
    }
    public function get(int $id): ?array { return $this->records[$id] ?? null; }
}

$dbM = new MockDbMeta();
$wpM3 = new MockWpMeta();
$dbM->records[300] = [
    'id' => 300, 'post_id' => 999, 'status' => 'publicado',
    'meta_tags' => [
        'og_title' => 'OG atual',
        'meta_description' => 'Descrição A original com 110+ chars suficientes pra atender a regra mínima de tamanho aplicada na validação',
        'meta_description_variantes' => [
            'Variante B com ângulo diferente da meta principal e tamanho dentro do limite Yoast/RankMath ainda OK',
            'Variante C com terceiro ângulo, tamanho dentro do limite mínimo de 110 chars pra passar a validação aqui',
        ],
    ],
    'publicado_em' => date('Y-m-d H:i:s', strtotime('-15 days')),
];

// Cenário 1: tudo OK → swap
$stats = ['ctr_pct' => 0.5, 'impressions' => 200, 'clicks' => 1, 'position' => 7];
$r = DiscoverMetaSwapper::tentarSwap($dbM->records[300], $stats, [], $dbM, $wpM3);
check('elegível → swap', ($r['acao'] ?? '') === 'swap');
check('WP recebeu update meta', count($wpM3->calls) === 1);
check('meta principal trocada', strpos((string)$dbM->records[300]['meta_tags']['meta_description'], 'Variante B') === 0);
check('histórico salvo', !empty($dbM->records[300]['meta_swap_history']));

// Cenário 2: title swap pendente → meta espera
$dbM->records[301] = $dbM->records[300];
$dbM->records[301]['id'] = 301;
$dbM->records[301]['titulo_variantes'] = ['variante T1', 'variante T2'];
$dbM->records[301]['title_swap_history'] = [];
$dbM->records[301]['meta_swap_history'] = [];
$rT = DiscoverMetaSwapper::tentarSwap($dbM->records[301], $stats, [], $dbM, $wpM3);
check('title pendente → meta skip', ($rT['acao'] ?? '') === 'skip');

// Cenário 3: P1 swap pendente → meta espera
$dbM->records[302] = $dbM->records[300];
$dbM->records[302]['id'] = 302;
$dbM->records[302]['p1_variantes'] = ['v1', 'v2'];
$dbM->records[302]['p1_swap_history'] = [];
$dbM->records[302]['meta_swap_history'] = [];
$rP = DiscoverMetaSwapper::tentarSwap($dbM->records[302], $stats, [], $dbM, $wpM3);
check('p1 pendente → meta skip', ($rP['acao'] ?? '') === 'skip');

// Cenário 4: idade < 7d → skip
$dbM->records[303] = $dbM->records[300];
$dbM->records[303]['id'] = 303;
$dbM->records[303]['publicado_em'] = date('Y-m-d H:i:s', strtotime('-3d'));
$dbM->records[303]['meta_swap_history'] = [];
$rD = DiscoverMetaSwapper::tentarSwap($dbM->records[303], $stats, [], $dbM, $wpM3);
check('idade < 7d → skip', ($rD['acao'] ?? '') === 'skip');

// ════════════════════════════════════════════════════════════
echo "\n=== Wires ===\n";

$g = file_get_contents($ROOT . '/lib/DiscoverGerador.php');
check('Gerador require DiscoverMetaTags',  strpos($g, 'DiscoverMetaTags') !== false);
check('Gerador chama MetaTags::gerar',     strpos($g, 'DiscoverMetaTags::gerar') !== false);
check('Gerador chama MetaTags::aplicarNoWp', strpos($g, 'DiscoverMetaTags::aplicarNoWp') !== false);
check('Gerador persiste meta_tags',        strpos($g, "'meta_tags'") !== false);

$gsc = file_get_contents($ROOT . '/scripts/gsc_aprender.php');
check('gsc_aprender require MetaSwapper',     strpos($gsc, 'DiscoverMetaSwapper') !== false);
check('gsc_aprender chama MetaSwapper::tentarSwap', strpos($gsc, 'DiscoverMetaSwapper::tentarSwap') !== false);

$sa = file_get_contents($ROOT . '/lib/DiscoverSerpAnalyzer.php');
check('SerpAnalyzer com detectarFeaturedSnippet',  strpos($sa, 'detectarFeaturedSnippet') !== false);
check('SerpAnalyzer com blocoHijackingFeaturedSnippet', strpos($sa, 'blocoHijackingFeaturedSnippet') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n";
echo "  FAIL: {$fail}\n";

if ($fail > 0) { echo "\n[CTR + SERP] FAIL\n"; exit(1); }
echo "\n[CTR + SERP] OK\n";
exit(0);
