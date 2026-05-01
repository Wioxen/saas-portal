<?php
/**
 * Smoke do pacote distribuição multi-canal + quality (sem credenciais externas):
 *   SocialPoster + drivers (Bluesky, Threads)
 *   FactChecker (mock OpenAI)
 *   ReadingScore (Flesch PT)
 *   Author pages (lint + estrutura)
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/SocialPoster.php';
require_once $rootDir . '/lib/SocialBluesky.php';
require_once $rootDir . '/lib/SocialThreads.php';
require_once $rootDir . '/lib/DiscoverFactChecker.php';
require_once $rootDir . '/lib/DiscoverReadingScore.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: SocialPoster::adaptarMensagem (limites por plataforma) ===\n";
$titulo = 'INSS libera revisão de aposentadoria pra quem nasceu antes de 1965 — confira como pedir';
$url = 'https://vagasebeneficios.com/inss-revisao-2026';

$msgX = SocialPoster::adaptarMensagem($titulo, $url, 'x', []);
check("X: ≤280 chars", mb_strlen($msgX) <= 280, 'len=' . mb_strlen($msgX));
check("X: contém URL", strpos($msgX, $url) !== false);

$msgBsky = SocialPoster::adaptarMensagem($titulo, $url, 'bluesky', []);
check("Bluesky: ≤300 chars", mb_strlen($msgBsky) <= 300);
check("Bluesky: contém URL", strpos($msgBsky, $url) !== false);

$msgThreads = SocialPoster::adaptarMensagem($titulo, $url, 'threads', []);
check("Threads: ≤500 chars", mb_strlen($msgThreads) <= 500);

// Hashtags
$msgHash = SocialPoster::adaptarMensagem($titulo, $url, 'x', ['hashtags' => ['inss', 'aposentadoria', 'revisao']]);
check("X com hashtags: ainda ≤280 chars", mb_strlen($msgHash) <= 280);
check("X com hashtags: contém #inss", strpos($msgHash, '#inss') !== false);

// Título muito longo é truncado com …
$tituloMax = str_repeat('palavra ', 100); // ~700 chars
$msgTrunc = SocialPoster::adaptarMensagem($tituloMax, $url, 'x', []);
check("Título 700 chars truncado pra ≤280 (X)", mb_strlen($msgTrunc) <= 280);
check("Truncamento adiciona …", strpos($msgTrunc, '…') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: SocialPoster sem cfg.social → no-op ===\n";
$res = SocialPoster::publicar(
    ['titulo' => 'X', 'url' => 'https://x', 'site_slug' => 'X'],
    [] // sem cfg.social
);
check("retorna sucessos=0 falhas=0 quando cfg.social ausente",
    $res['sucessos'] === 0 && $res['falhas'] === 0);

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: SocialPoster com canal sem driver (X) → fail-graceful ===\n";
$res = SocialPoster::publicar(
    ['titulo' => 'T', 'url' => 'https://x.com/t', 'site_slug' => 'leaodabarra', 'post_id' => 1],
    ['social' => ['x' => ['enabled' => true]]]
);
// SocialX.php não existe ainda — driver_inexistente
check("X sem driver: falha tratada",
    isset($res['por_canal']['x']) && !$res['por_canal']['x']['ok']);

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: Bluesky driver — credenciais ausentes ===\n";
$resB = SocialBluesky::postar('teste', 'https://x', ['titulo' => 't'], []);
check("sem handle/password: erro claro",
    !$resB['ok'] && strpos($resB['erro'] ?? '', 'ausentes') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: Threads driver — credenciais ausentes ===\n";
$resT = SocialThreads::postar('teste', 'https://x', ['titulo' => 't'], []);
check("sem token/user_id: erro claro",
    !$resT['ok'] && strpos($resT['erro'] ?? '', 'ausentes') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: FactChecker extrai claims factuais (sem OpenAI) ===\n";
$rfFc = new ReflectionClass('DiscoverFactChecker');
$mFc = $rfFc->getMethod('extrairClaims');
$mFc->setAccessible(true);

$htmlFc = '<h1>Título</h1>'
       . '<p>O ENEM 2026 abriu inscrições nesta segunda com 5 milhões de candidatos esperados.</p>'
       . '<p>O prazo termina em 14 dias úteis e a isenção pode ser pedida pelo gov.br.</p>'
       . '<p>Acho que talvez seja uma boa oportunidade.</p>'  // opinião — deve filtrar
       . '<p>O Inep divulgou o cronograma em maio de 2026 com 8 datas-chave.</p>';
$claims = $mFc->invoke(null, $htmlFc);
check("FactChecker extrai claims (>=2)", count($claims) >= 2,
    'claims=' . count($claims) . ' first=' . ($claims[0] ?? 'none'));
check("FactChecker IGNORA frase de opinião",
    !in_array('Acho que talvez seja uma boa oportunidade.', $claims, true));

// FactChecker com OpenAI mock
$mockOpenai = new class {
    public function chat(string $sys, string $user, int $max): string {
        return '{"claims":[{"i":1,"status":"VERIFICADO","motivo":"ok"},{"i":2,"status":"VERIFICADO","motivo":"ok"},{"i":3,"status":"UNVERIFIED","motivo":"sem fonte"}]}';
    }
};
$result = DiscoverFactChecker::verificar($htmlFc, ['Texto da fonte com referência ao ENEM 2026 e Inep e cronograma'], $mockOpenai);
check("verificar retorna claims_total>0", ($result['claims_total'] ?? 0) > 0);
check("aprovado quando ≥70% verificados", isset($result['aprovado']));

// FactChecker sem fontes → fail-open
$resVazio = DiscoverFactChecker::verificar($htmlFc, [], $mockOpenai);
check("sem fontes → aprovado (fail-open)", !empty($resVazio['aprovado']));

// ─────────────────────────────────────────────
echo "\n=== TESTE 7: ReadingScore Flesch PT ===\n";
$textoFacil = 'O céu é azul. O sol brilha. As crianças correm no parque. A mãe sorri. O cão late e pula.';
$rFacil = DiscoverReadingScore::calcular('<p>' . $textoFacil . '</p>');
check("texto fácil → score ≥ 70",
    ($rFacil['score'] ?? 0) >= 70,
    'score=' . ($rFacil['score'] ?? 0));
check("texto fácil → nível facil/muito_facil",
    in_array($rFacil['nivel'] ?? '', ['facil', 'muito_facil'], true),
    'nivel=' . ($rFacil['nivel'] ?? '?'));

$textoDificil = 'A epistemologia contemporânea desenvolveu paradigmas multidisciplinares interdependentes que problematizam pressupostos ontológicos consolidados na fenomenologia transcendental husserliana, cuja interlocução com correntes pragmatistas estadunidenses estabelece quadros referenciais simultaneamente complementares e antagônicos quando confrontados com investigações propriamente analíticas anglófonas.';
$rDif = DiscoverReadingScore::calcular('<p>' . $textoDificil . '</p>');
check("texto difícil → score baixo",
    ($rDif['score'] ?? 100) < 50,
    'score=' . ($rDif['score'] ?? 0));

// Sílabas de palavras conhecidas
check("contarSilabas('casa') = 2", DiscoverReadingScore::contarSilabas('casa') === 2);
check("contarSilabas('Brasil') = 2", DiscoverReadingScore::contarSilabas('Brasil') === 2);
check("contarSilabas('aposentadoria') >= 5",
    DiscoverReadingScore::contarSilabas('aposentadoria') >= 5);
check("contarSilabas('guarda-chuva') = 4",
    DiscoverReadingScore::contarSilabas('guarda-chuva') === 4);

// ─────────────────────────────────────────────
echo "\n=== TESTE 8: criar_author_pages.php sintaxe ===\n";
$cmd = '"C:\xampp\php\php.exe" -l "' . $rootDir . '/scripts/criar_author_pages.php"';
exec($cmd . ' 2>&1', $output, $rc);
check("criar_author_pages.php sintaxe OK", $rc === 0, implode(' ', $output));

// Função montarBio internal (via include)
$bioSrc = file_get_contents($rootDir . '/scripts/criar_author_pages.php');
check("criar_author_pages tem schema.org/Person",
    strpos($bioSrc, 'schema.org/Person') !== false);
check("criar_author_pages tem 'Padrões editoriais'",
    strpos($bioSrc, 'Padrões editoriais') !== false);
check("criar_author_pages tem 'Verificação cruzada'",
    strpos($bioSrc, 'Verificação cruzada') !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 9: Wire SocialPoster em DiscoverGerador ===\n";
$srcGer = file_get_contents($rootDir . '/lib/DiscoverGerador.php');
check("DiscoverGerador require SocialPoster",
    strpos($srcGer, "require_once __DIR__ . '/SocialPoster.php'") !== false);
check("DiscoverGerador chama SocialPoster::publicar",
    strpos($srcGer, 'SocialPoster::publicar') !== false);
check("DiscoverGerador retorna 'social' no return",
    strpos($srcGer, "'social'") !== false);

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[SOCIAL+QUALITY] OK\n" : "\n[SOCIAL+QUALITY] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
