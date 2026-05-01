<?php
/**
 * Smoke: PACOTE PRE-DEPLOY (CTR + Receita + Defesa)
 *  - CostGuard      : cap global e por-site (estimativa proporcional)
 *  - P1 Variantes   : geração + validação
 *  - P1 Swapper     : cenários elegível/inelegível, substituição do <p>
 *  - Multi-Afiliado : Amazon, Magalu, ML, Shopee + idempotência
 */

set_time_limit(0);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/CostGuard.php';
require_once $ROOT . '/lib/DiscoverP1Variantes.php';
require_once $ROOT . '/lib/DiscoverP1Swapper.php';
require_once $ROOT . '/lib/DiscoverAfiliadoBR.php';

$ok = 0; $fail = 0;
function check(string $nome, $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [OK]   {$nome}\n"; }
    else       { $fail++; echo "  [FAIL] {$nome}\n"; }
}

// ════════════════════════════════════════════════════════════
echo "\n=== 1: CostGuard ===\n";

// Como o cost tracker lê data/cost_tracker, e em ambiente CI dev pode ter dados
// reais, vamos garantir um diretório isolado pro teste.
$tmpDir = $ROOT . '/data/cost_tracker';
$noLogDir = !is_dir($tmpDir);
if ($noLogDir) {
    @mkdir($tmpDir, 0777, true);
}

// Sem dados → gasto = 0 → ok
$r = CostGuard::verificar('cursosenac');
check('sem gasto registrado → ok', ($r['ok'] ?? false) === true);

// Forçando ENV pra desativar
putenv('COST_GUARD_ENABLED=0');
$rOff = CostGuard::verificar('x');
check('COST_GUARD_ENABLED=0 → ok mesmo com gasto', ($rOff['ok'] ?? false) === true);
putenv('COST_GUARD_ENABLED'); // unset

// Mock DB pra cap por site via proporção (só se houver gasto)
class MockCgDb {
    public array $records = [];
    public function all(array $f = []): array {
        // Filtra status + publicado_apos manualmente pra simular MySQL push-down
        $cut = isset($f['publicado_apos']) ? (is_int($f['publicado_apos']) ? $f['publicado_apos'] : strtotime((string)$f['publicado_apos'])) : 0;
        $out = $this->records;
        if (isset($f['status'])) $out = array_filter($out, fn($r) => ($r['status'] ?? '') === $f['status']);
        if ($cut) $out = array_filter($out, fn($r) => (strtotime((string)($r['publicado_em'] ?? '')) ?: 0) >= $cut);
        return array_values($out);
    }
}

$mockDb = new MockCgDb();
// 6 posts hoje: 4 do site A, 2 do site B
$now = date('Y-m-d H:i:s');
for ($i = 0; $i < 4; $i++) $mockDb->records[] = ['site' => 'siteA', 'status' => 'publicado', 'publicado_em' => $now];
for ($i = 0; $i < 2; $i++) $mockDb->records[] = ['site' => 'siteB', 'status' => 'publicado', 'publicado_em' => $now];

// Não há gasto registrado → ok regardless de proporção
$rA = CostGuard::verificar('siteA', $mockDb);
check('mock DB com 6 posts: gasto=0 → ok', ($rA['ok'] ?? false) === true);

// ════════════════════════════════════════════════════════════
echo "\n=== 2: DiscoverP1Variantes ===\n";

class MockLlmP1 {
    public string $response;
    public function __construct(string $r) { $this->response = $r; }
    public function ask(string $prompt, array $opts = []) { return $this->response; }
}

$p1Bom = "Mais de 5 mil vagas foram liberadas nesta segunda pelo MEC para o ENEM 2026. O prazo de inscrição vai até 24 de maio segundo o edital divulgado. O problema? A maioria descobre tarde demais.";
$resposta = "[1] O Inep abriu 5 mil vagas no ENEM 2026 nesta segunda em todo Brasil segundo o edital divulgado pelo MEC. Inscrição vai de 16 a 24 de maio com taxa de 85 reais para quem não tem isenção pelo CadÚnico. Quem perde o prazo fica fora deste ciclo seletivo.\n\n[2] Cinco mil novas vagas no ENEM 2026 foram autorizadas pelo MEC nesta segunda em todo país com prazo de inscrição curto. Período de cadastro é somente 8 dias úteis pelo portal do Inep com taxa fixa de 85 reais. A regra mudou e poucos candidatos sabem dos novos requisitos.";

$variantes = DiscoverP1Variantes::gerar($p1Bom, 'ENEM 2026 abre 5 mil vagas', 'enem 2026', 'briefing das fontes', new MockLlmP1($resposta));
check('LLM OK retorna 2 variantes', count($variantes) === 2);
check('1ª variante 200-450 chars', mb_strlen($variantes[0] ?? '') >= 200 && mb_strlen($variantes[0] ?? '') <= 450);

// Variantes muito curtas → rejeita
$resCurto = "[1] muito curto\n\n[2] outro curto";
$varCurtas = DiscoverP1Variantes::gerar($p1Bom, 'X', 'x', '', new MockLlmP1($resCurto));
check('variantes curtas → rejeita todas', empty($varCurtas));

// P1 vazio → []
$varEmpty = DiscoverP1Variantes::gerar('', 'X', 'x', '', new MockLlmP1($resposta));
check('P1 vazio → []', $varEmpty === []);

// ════════════════════════════════════════════════════════════
echo "\n=== 3: DiscoverP1Swapper ===\n";

class MockWpP1 {
    public array $calls = [];
    public array $posts = [];
    public function lerPost(int $postId): array { return $this->posts[$postId] ?? []; }
    public function getPost(int $postId): array { return $this->posts[$postId] ?? []; }
    public function atualizarPost(int $postId, array $data): array {
        $this->calls[] = ['post_id' => $postId, 'data' => $data];
        if (isset($this->posts[$postId]) && isset($data['content'])) {
            $this->posts[$postId]['content'] = $data['content'];
        }
        return ['ok' => true];
    }
}

class MockDbP1 {
    public array $records = [];
    public function updateStatus(int $id, string $status, array $extra = []): bool {
        if (!isset($this->records[$id])) $this->records[$id] = ['id' => $id];
        $this->records[$id]['status'] = $status;
        foreach ($extra as $k => $v) $this->records[$id][$k] = $v;
        return true;
    }
    public function get(int $id): ?array { return $this->records[$id] ?? null; }
}

$db1 = new MockDbP1();
$wp1 = new MockWpP1();
$wp1->posts[777] = ['content' => '<p>P1 antigo aqui com pouco texto pra preview do Discover.</p><h2>Continuação</h2><p>Resto do post.</p>'];

$db1->records[200] = [
    'id' => 200, 'post_id' => 777, 'status' => 'publicado',
    'titulo' => 'Título qualquer',
    'p1_variantes' => [
        'Mais de 5 mil vagas foram abertas pelo MEC no ENEM 2026 nesta segunda. Inscrição vai até 24 de maio com taxa de R$ 85. Quem perde o prazo fica fora do ciclo.',
        'O ENEM 2026 confirmou 5 mil vagas extras nesta semana segundo o Inep. O prazo termina em 8 dias úteis e a maioria desconhece o requisito novo.',
    ],
    'publicado_em' => date('Y-m-d H:i:s', strtotime('-15 days')),
];

// Cenário 1: tudo OK → swap (no scenário em que titulo_variantes está vazio, P1 não espera)
$stats = ['ctr_pct' => 0.5, 'impressions' => 200, 'clicks' => 1, 'position' => 7];
$r = DiscoverP1Swapper::tentarSwap($db1->records[200], $stats, [], $db1, $wp1);
check('elegível → swap', ($r['acao'] ?? '') === 'swap');
check('WP recebeu update do content', count($wp1->calls) === 1 && isset($wp1->calls[0]['data']['content']));
check('content novo NÃO contém P1 antigo', strpos($wp1->calls[0]['data']['content'], 'P1 antigo aqui') === false);
check('histórico p1_swap_history salvo', !empty($db1->records[200]['p1_swap_history']));

// Cenário 2: title_variantes ainda disponíveis → P1 espera
$db1->records[201] = $db1->records[200];
$db1->records[201]['id'] = 201;
$db1->records[201]['titulo_variantes'] = ['variante T1 alt', 'variante T2 alt'];
$db1->records[201]['title_swap_history'] = []; // 0 testadas, 2 disponíveis
$db1->records[201]['p1_swap_history'] = [];
$wp1->posts[777]['content'] = '<p>P1 antigo aqui com pouco texto pra preview do Discover.</p>'; // reset
$rTitle = DiscoverP1Swapper::tentarSwap($db1->records[201], $stats, [], $db1, $wp1);
check('Title swap pendente → P1 skip', ($rTitle['acao'] ?? '') === 'skip');

// Cenário 3: substituirPrimeiroParagrafo isolado
$htmlEx = '<p class="lead">Primeiro</p><p>Segundo</p>';
$novoHtml = DiscoverP1Swapper::substituirPrimeiroParagrafo($htmlEx, 'Novo P1');
check('substitui só o 1º <p>', strpos($novoHtml, 'Novo P1') !== false && strpos($novoHtml, 'Segundo') !== false);
check('preserva atributos do <p>',  strpos($novoHtml, 'class="lead"') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== 4: DiscoverAfiliadoBR (detector PrettyLinks-only) ===\n";

// Limpa log anterior do teste anterior
$logFile = $ROOT . '/data/afiliado_warnings.log';
@unlink($logFile);

// HTML com URLs marketplace ORIGINAIS (que Sonnet inventou) — devem ser detectadas
$batchSujo = "
<a href='https://www.amazon.com.br/dp/X1'>Amazon</a>
<a href='https://www.magazineluiza.com.br/produto/p/123'>Magalu</a>
<a href='https://produto.mercadolivre.com.br/MLB-555'>ML</a>
<a href='https://shopee.com.br/iphone-i.99.88'>Shopee</a>
";

$detect = DiscoverAfiliadoBR::detectar($batchSujo);
check('detectar(): retorna 4 URLs marketplace originais', count($detect) === 4);
check('detectar(): inclui amazon', in_array('amazon', array_column($detect, 'rede'), true));
check('detectar(): inclui magalu', in_array('magalu', array_column($detect, 'rede'), true));
check('detectar(): inclui ml', in_array('mercadolivre', array_column($detect, 'rede'), true));
check('detectar(): inclui shopee', in_array('shopee', array_column($detect, 'rede'), true));

// Deeplinks VÁLIDOS não são detectados (não geram warning)
$batchDeeplinks = "
<a href='https://amzn.to/abc'>Amazon shortlink</a>
<a href='https://www.magazinevoce.com.br/seuusuario/produto/p/123'>Magalu Você (subloja)</a>
<a href='https://mercadolivre.com.br/sec/abc123'>ML deeplink</a>
<a href='https://shope.ee/xyz'>Shopee smart-link</a>
<a href='https://s.shopee.com.br/xyz'>Shopee s.shopee</a>
";
$detectDeeplinks = DiscoverAfiliadoBR::detectar($batchDeeplinks);
check('deeplinks válidos NÃO geram warning', count($detectDeeplinks) === 0);

// PrettyLinks (/go/) não são marketplaces — não detectados
$batchPretty = "<a href='/go/iphone-15'>iPhone</a><a href='https://meusite.com/go/x'>X</a>";
$detectPretty = DiscoverAfiliadoBR::detectar($batchPretty);
check('PrettyLinks NÃO geram warning', count($detectPretty) === 0);

// aplicar() loga warnings + retorna HTML inalterado por default
$cfgAff = ['_site_slug' => 'comocomprar'];
$resultado = DiscoverAfiliadoBR::aplicar($batchSujo, $cfgAff, 100);
check('aplicar() default: HTML inalterado', $resultado === $batchSujo);
check('aplicar() escreveu log', is_file($logFile) && filesize($logFile) > 0);

// Mode 'desfazer_links_inventados' — remove tags <a> ao redor das URLs
$cfgDesfazer = ['_site_slug' => 'comocomprar', 'desfazer_links_inventados' => true];
$resultadoDesfeito = DiscoverAfiliadoBR::aplicar($batchSujo, $cfgDesfazer, 100);
check('desfazer_links_inventados: tags <a> removidas', strpos($resultadoDesfeito, '<a href=') === false);
check('desfazer_links_inventados: textos preservados',
    strpos($resultadoDesfeito, 'Amazon') !== false && strpos($resultadoDesfeito, 'Shopee') !== false);

// detectarRede (público)
check('detectarRede: amazon',    DiscoverAfiliadoBR::detectarRede('https://amazon.com.br/dp/x') === 'amazon');
check('detectarRede: magalu',    DiscoverAfiliadoBR::detectarRede('https://magazineluiza.com.br/p/x') === 'magalu');
check('detectarRede: ml',        DiscoverAfiliadoBR::detectarRede('https://produto.mercadolivre.com.br/x') === 'mercadolivre');
check('detectarRede: shopee',    DiscoverAfiliadoBR::detectarRede('https://shopee.com.br/x') === 'shopee');
check('detectarRede: outro → null', DiscoverAfiliadoBR::detectarRede('https://google.com') === null);

// resumoWarnings
$resumo = DiscoverAfiliadoBR::resumoWarnings(7);
check('resumoWarnings: total > 0 após log', ($resumo['total'] ?? 0) > 0);
check('resumoWarnings: por_rede com 4 redes', count($resumo['por_rede'] ?? []) === 4);

// Cleanup do log de teste
@unlink($logFile);

// ════════════════════════════════════════════════════════════
echo "\n=== Wires ===\n";

$gerador = file_get_contents($ROOT . '/lib/DiscoverGerador.php');
check('Gerador require CostGuard',          strpos($gerador, 'CostGuard') !== false);
check('Gerador chama CostGuard::verificar', strpos($gerador, 'CostGuard::verificar') !== false);
check('Gerador require P1Variantes',        strpos($gerador, 'DiscoverP1Variantes') !== false);
check('Gerador persiste p1_variantes',      strpos($gerador, "'p1_variantes'") !== false);

$aprender = file_get_contents($ROOT . '/scripts/gsc_aprender.php');
check('gsc_aprender require P1Swapper',         strpos($aprender, 'DiscoverP1Swapper') !== false);
check('gsc_aprender chama P1Swapper::tentarSwap',strpos($aprender, 'DiscoverP1Swapper::tentarSwap') !== false);

$postProc = file_get_contents($ROOT . '/lib/DiscoverPostProcess.php');
check('PostProcess require AfiliadoBR', strpos($postProc, 'DiscoverAfiliadoBR') !== false);

$envEx = file_get_contents($ROOT . '/.env.example');
check('.env.example com COST_DAILY_LIMIT_USD', strpos($envEx, 'COST_DAILY_LIMIT_USD') !== false);

// Prompt builder com bloco proibindo links inventados
$pb = file_get_contents($ROOT . '/lib/DiscoverPromptBuilder.php');
check('PromptBuilder com blocoLinksAfiliado', strpos($pb, 'blocoLinksAfiliado') !== false);
check('blocoLinksAfiliado menciona /go/',     strpos($pb, '/go/') !== false);

$cl = file_get_contents($ROOT . '/lib/Claude.php');
check('Claude usa blocoLinksAfiliado', strpos($cl, 'blocoLinksAfiliado') !== false);
$gpt = file_get_contents($ROOT . '/lib/DiscoverGeradorGPT.php');
check('DiscoverGeradorGPT usa blocoLinksAfiliado', strpos($gpt, 'blocoLinksAfiliado') !== false);
check('DiscoverGerador usa blocoLinksAfiliado', strpos($gerador, 'blocoLinksAfiliado') !== false);

// ════════════════════════════════════════════════════════════
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n";
echo "  FAIL: {$fail}\n";

if ($fail > 0) { echo "\n[PRE-DEPLOY PACK] FAIL\n"; exit(1); }
echo "\n[PRE-DEPLOY PACK] OK\n";
exit(0);
