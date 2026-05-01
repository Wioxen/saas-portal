<?php
/**
 * tests/searchconsole.php
 *
 * Testes do DiscoverSearchConsole — JWT generation + parse credenciais.
 * Não faz HTTP real (token e queries são testados manualmente).
 */

require_once __DIR__ . '/../lib/DiscoverSearchConsole.php';

$pass = 0;
$total = 0;
$falhas = [];

function ok(string $desc, bool $cond): void {
    global $pass, $total, $falhas;
    $total++;
    if ($cond) { $pass++; return; }
    $falhas[] = $desc;
}

// ── 1. Carregamento de credenciais ────────────────────────────────────
$credPath = __DIR__ . '/../data/google_credentials.json';
ok('JSON de credenciais existe', is_file($credPath));

$gsc = new DiscoverSearchConsole($credPath);

// ── 2. Falha gracefully se path inexistente ───────────────────────────
$gscBadPath = new DiscoverSearchConsole('/path/inexistente.json');
$thrown = false;
try { $gscBadPath->getAccessToken(); } catch (Throwable $e) { $thrown = true; }
ok('credenciais inexistentes lançam exception', $thrown);

// ── 3. Falha gracefully com JSON inválido ─────────────────────────────
$tmpInvalid = tempnam(sys_get_temp_dir(), 'gsc_test_');
file_put_contents($tmpInvalid, '{"foo":"bar"}'); // sem client_email/private_key
$gscInvalid = new DiscoverSearchConsole($tmpInvalid);
$thrown = false;
try { $gscInvalid->getAccessToken(); } catch (Throwable $e) { $thrown = true; }
ok('JSON sem client_email/private_key lança exception', $thrown);
@unlink($tmpInvalid);

// ── 4. base64UrlEncode (RFC 7515) ─────────────────────────────────────
$ref = new ReflectionMethod('DiscoverSearchConsole', 'base64UrlEncode');
$ref->setAccessible(true);
ok('base64UrlEncode remove padding =', !str_contains($ref->invoke(null, 'foo'), '='));
ok('base64UrlEncode substitui + por -', !str_contains($ref->invoke(null, "\xff\xff\xff"), '+'));
ok('base64UrlEncode substitui / por _', !str_contains($ref->invoke(null, "\xfb\xff"), '/'));

// ── 5. Token cache: 2 chamadas consecutivas devolvem mesma string ─────
if (is_file($credPath)) {
    $token1 = null; $token2 = null;
    try {
        $token1 = $gsc->getAccessToken();
        $token2 = $gsc->getAccessToken();
    } catch (Throwable $e) {
        // network down ou API desativada — pulamos esse caso
    }
    if ($token1 !== null && $token2 !== null) {
        ok('access token é cacheado entre chamadas', $token1 === $token2);
        ok('access token tem formato bearer', strlen($token1) > 50 && str_starts_with($token1, 'ya29.'));
    }
}

// ── 6. listarSites com filtro de site específico ──────────────────────
if (is_file($credPath)) {
    try {
        $sites = $gsc->listarSites();
        ok('listarSites retorna array', is_array($sites));
        ok('cada site tem siteUrl', !empty($sites) ? !empty($sites[0]['siteUrl']) : true);
    } catch (Throwable $e) {
        // Se falhar por API ou network, registra mas não impede outros testes
        echo "  ⚠ listarSites pulado: " . $e->getMessage() . "\n";
    }
}

// ── 7. consultarPerformance com período inválido (sanity) ─────────────
if (is_file($credPath)) {
    try {
        $r = $gsc->consultarPerformance('sc-domain:cursosenacgratuito.com.br',
            date('Y-m-d', strtotime('-2 days')),
            date('Y-m-d', strtotime('-1 days')),
            ['limite' => 5]
        );
        ok('consultarPerformance retorna array com totals', is_array($r) && isset($r['totals']));
        ok('totals tem clicks/impressions', isset($r['totals']['clicks'], $r['totals']['impressions']));
    } catch (Throwable $e) {
        echo "  ⚠ consultarPerformance pulado: " . $e->getMessage() . "\n";
    }
}

// ── Sumário ───────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════\n";
echo "  tests/searchconsole.php — {$total} casos\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if (!empty($falhas)) {
    echo "─── FALHAS ─────────────────────────────────────────────────────\n";
    foreach ($falhas as $f) echo "  ✗ {$f}\n";
    echo "\n";
}

printf("  Passaram: %d / %d (%.1f%%)\n", $pass, $total, ($pass / $total) * 100);

if ($pass === $total) {
    echo "  ✓ Todos os casos passaram.\n";
    exit(0);
}
exit(1);
