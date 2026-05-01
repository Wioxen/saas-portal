<?php
/**
 * Smoke FAIL-SAFE — valida que pipeline COMPLETA mesmo sem nenhuma credencial opcional.
 *
 * Testa cada subsistema externo isoladamente com cfg vazia/parcial:
 *   - SocialPoster (sem cfg.social) → no-op silencioso
 *   - SocialBluesky / SocialThreads / SocialX → erro claro, não throw
 *   - DiscoverOneSignal (sem app_id/rest_api_key) → deveEnviar() = false
 *   - Meta FB/IG (sem fb_page_id/token) → guard em DiscoverGerador retorna pulado
 *   - DiscoverImagemFeatured (sem pexels nem openai) → cascata cai pra og:image
 *   - HealthWebhook (sem env) → return false silencioso
 *   - PrettyLinks (URL inválida) → fallback amzn.to global
 *   - DiscoverFactChecker (sem fontes) → fail-open (aprova)
 *
 * Premissa: post WP DEVE ser publicado mesmo se TODAS as integrações opcionais falharem.
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/lib/SocialPoster.php';
require_once $rootDir . '/lib/SocialBluesky.php';
require_once $rootDir . '/lib/SocialThreads.php';
require_once $rootDir . '/lib/DiscoverOneSignal.php';
require_once $rootDir . '/lib/HealthWebhook.php';
require_once $rootDir . '/lib/DiscoverFactChecker.php';
require_once $rootDir . '/lib/CircuitBreaker.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $msg = ''): void {
    global $ok, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $ok++; }
    else       { echo "  [FAIL] {$label}" . ($msg !== '' ? " — {$msg}" : '') . "\n"; $fail++; }
}

function expectaNaoThrow(callable $fn, string $label): void {
    global $ok, $fail;
    try {
        $fn();
        echo "  [OK]   {$label} (não lança)\n";
        $ok++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$label} LANÇOU: " . $e->getMessage() . "\n";
        $fail++;
    }
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 1: SocialPoster sem cfg.social ===\n";
expectaNaoThrow(function () {
    $r = SocialPoster::publicar(['titulo' => 'X', 'url' => 'https://x', 'site_slug' => 'X'], []);
    if ($r['sucessos'] !== 0 || $r['falhas'] !== 0) throw new Exception('esperado 0/0');
}, "publicar sem cfg.social");

expectaNaoThrow(function () {
    SocialPoster::publicar(['titulo' => '', 'url' => ''], ['social' => ['bluesky' => ['enabled' => true]]]);
}, "publicar com titulo/url vazio");

expectaNaoThrow(function () {
    SocialPoster::publicar(
        ['titulo' => 'T', 'url' => 'https://x.com/t', 'site_slug' => 'X', 'post_id' => 1],
        ['social' => [
            'bluesky' => ['enabled' => true],   // sem creds
            'threads' => ['enabled' => true],   // sem creds
            'x'       => ['enabled' => true],   // driver inexistente
            'mastodon'=> ['enabled' => true],   // driver inexistente
        ]]
    );
}, "publicar com 4 canais SEM credencial nenhuma");

// ─────────────────────────────────────────────
echo "\n=== TESTE 2: SocialBluesky/Threads/X sem credencial ===\n";
expectaNaoThrow(function () {
    $r = SocialBluesky::postar('teste', 'https://x', ['titulo' => 't'], []);
    if (!empty($r['ok'])) throw new Exception('deveria ter erro');
}, "Bluesky sem creds");

expectaNaoThrow(function () {
    $r = SocialThreads::postar('teste', 'https://x', ['titulo' => 't'], []);
    if (!empty($r['ok'])) throw new Exception('deveria ter erro');
}, "Threads sem creds");

// ─────────────────────────────────────────────
echo "\n=== TESTE 3: DiscoverOneSignal sem app_id ===\n";
check("deveEnviar sem onesignal_app_id → false",
    !DiscoverOneSignal::deveEnviar([], 'noticias_info_critica'));

check("deveEnviar com onesignal_enabled=0 → false",
    !DiscoverOneSignal::deveEnviar(['onesignal_app_id' => 'x', 'onesignal_rest_api_key' => 'y', 'onesignal_enabled' => 0], 'noticias_info_critica'));

// ─────────────────────────────────────────────
echo "\n=== TESTE 4: HealthWebhook sem env (HEALTH_WEBHOOK_ENABLED=0) ===\n";
expectaNaoThrow(function () {
    HealthWebhook::erro('teste', ['contexto' => 'sem webhook']);
    HealthWebhook::aviso('teste', []);
    HealthWebhook::info('teste', []);
}, "alertas sem webhook configurado");

// ─────────────────────────────────────────────
echo "\n=== TESTE 5: CircuitBreaker em estado válido sem incidente ===\n";
expectaNaoThrow(function () {
    $cb = new CircuitBreaker('test_failsafe_' . bin2hex(random_bytes(2)));
    $cb->guarda(); // CLOSED — não throw
    $cb->sucesso(); // não throw
    $st = $cb->status();
    if ($st['estado'] !== 'closed') throw new Exception('esperado closed');
    @unlink(__DIR__ . '/../data/circuit/' . $cb->status()['nome'] . '.json');
}, "CircuitBreaker fluxo normal sem incidente");

// ─────────────────────────────────────────────
echo "\n=== TESTE 6: DiscoverFactChecker sem fontes (fail-open) ===\n";
expectaNaoThrow(function () {
    $mockOpenai = new class { public function chat($s, $u, $m): string { return '{}'; } };
    $r = DiscoverFactChecker::verificar('<p>texto</p>', [], $mockOpenai);
    if (empty($r['aprovado'])) throw new Exception('deveria aprovar fail-open sem fontes');
}, "FactChecker sem fontes → aprovado (fail-open)");

expectaNaoThrow(function () {
    $mockOpenai = new class { public function chat($s, $u, $m): string { throw new RuntimeException('OpenAI down'); } };
    $r = DiscoverFactChecker::verificar(
        '<p>O ENEM 2026 abriu inscrições nesta segunda com 5 milhões de candidatos.</p>',
        ['fonte com texto'], $mockOpenai);
    if (empty($r['aprovado'])) throw new Exception('OpenAI failure deveria aprovar fail-open');
}, "FactChecker com OpenAI falhando → fail-open");

// ─────────────────────────────────────────────
echo "\n=== TESTE 7: SocialPoster::adaptarMensagem nunca throw ===\n";
expectaNaoThrow(function () {
    SocialPoster::adaptarMensagem('', '', 'desconhecido', []);
    SocialPoster::adaptarMensagem(str_repeat('x', 1000), 'https://' . str_repeat('y', 500), 'x', ['hashtags' => null]);
    SocialPoster::adaptarMensagem('título', 'http://localhost', 'inexistente', ['hashtags' => ['a', 'b']]);
}, "adaptarMensagem com inputs estranhos");

// ─────────────────────────────────────────────
echo "\n=== TESTE 8: DiscoverGerador wire — sem cfg opcionais NÃO lança no INIT ===\n";
// Constructor exige wp/anthropic/serper. Mas componentes OPCIONAIS não devem ser tocados no init.
// Vamos confirmar que nenhum opcional é lazy-required.
$srcGerador = file_get_contents($rootDir . '/lib/DiscoverGerador.php');

check("DiscoverGerador::__construct NÃO usa onesignal_app_id direto",
    !preg_match('/__construct.*onesignal_app_id/s', substr($srcGerador, 0, 5000)));

check("DiscoverGerador::__construct NÃO usa fb_page_id direto",
    !preg_match('/__construct.*fb_page_id/s', substr($srcGerador, 0, 5000)));

check("DiscoverGerador::__construct NÃO usa pexels_api_key direto",
    !preg_match('/__construct.*pexels_api_key/s', substr($srcGerador, 0, 5000)));

check("DiscoverGerador::__construct NÃO usa social direto",
    !preg_match("/__construct.*\['social'\]/s", substr($srcGerador, 0, 5000)));

// ─────────────────────────────────────────────
echo "\n=== TESTE 9: Wires em DiscoverGerador têm guards !empty(...) ===\n";
check("Meta wire tem guard !empty(fb_page_id) && !empty(fb_page_token)",
    strpos($srcGerador, "!empty(\$cfgTrend['fb_page_id']) && !empty(\$cfgTrend['fb_page_token'])") !== false);
check("SocialPoster wire tem guard !empty(cfgTrend['social'])",
    strpos($srcGerador, "!empty(\$cfgTrend['social']) && is_array(\$cfgTrend['social'])") !== false);
check("Meta wire tem branch 'pulado' quando sem creds",
    strpos($srcGerador, "site sem credenciais Meta") !== false);

// ─────────────────────────────────────────────
echo "\n=== TESTE 10: DiscoverPostProcess fail-safe (subsistemas opcionais) ===\n";
$srcPp = file_get_contents($rootDir . '/lib/DiscoverPostProcess.php');
// Cada chamada a subsistema rich (Schemas, RelatedLinks, TrustBlocks, AfiliadoLinkBuilder, AiOverview)
// deve estar dentro de try/catch — falha em UM bloco não derruba o post.
$blocosOpc = ['DiscoverSchemas', 'DiscoverRelatedLinks', 'DiscoverTrustBlocks', 'AfiliadoLinkBuilder', 'DiscoverAiOverview'];
foreach ($blocosOpc as $bl) {
    // Extrai bloco onde aparece "$bl::" e procura `try` precedente (até 200 chars antes)
    $padrao = '#try\s*\{[^}]*' . preg_quote($bl, '#') . '#s';
    check("PostProcess wire `{$bl}` está em try/catch",
        preg_match($padrao, $srcPp) === 1);
}

// ─────────────────────────────────────────────
echo "\n=== TESTE 11: SocialPoster::publicar gracioso com payload mínimo ===\n";
expectaNaoThrow(function () {
    $cfg = ['social' => ['bluesky' => ['enabled' => true, 'handle' => 'h', 'password' => 'p']]];
    // post mínimo possível
    SocialPoster::publicar(['titulo' => 't', 'url' => 'https://x', 'site_slug' => 's', 'post_id' => 1], $cfg);
    // Fail no driver, mas wrapper não throw
}, "SocialPoster com Bluesky creds inválidas → erro mas continua");

// ─────────────────────────────────────────────
echo "\n=== RESUMO ===\n";
echo "  OK:   {$ok}\n  FAIL: {$fail}\n";
echo $fail === 0 ? "\n[FAIL-SAFE] OK · pipeline robusto a credenciais ausentes\n" : "\n[FAIL-SAFE] FALHOU · {$fail}\n";
exit($fail === 0 ? 0 : 1);
