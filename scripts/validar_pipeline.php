<?php
/**
 * scripts/validar_pipeline.php
 *
 * Dry-run completo do pipeline Discover. NÃO gera artigo, NÃO chama Claude/GPT,
 * NÃO dispara Web Story. Só valida estado estrutural e conectividade:
 *
 *   1. Config via .env — todas chaves presentes
 *   2. TrendsTaxonomia + DiscoverClusterMatcher carregam sem erro
 *   3. DiscoverDb lê registros (usa JSON local)
 *   4. DiscoverAfiliados catálogo íntegro, matchmaker funciona com trend sintético
 *   5. DiscoverWebStory::deveGerar decide corretamente por cluster
 *   6. WP REST API responde (GET /wp-json/)
 *   7. Plugin wsai endpoint responde (HEAD/OPTIONS /wp-wsai/v1/create-story)
 *   8. Serper + Anthropic + OpenAI keys têm formato válido
 *   9. Catálogo tem ≥ 1 trend "aprovado" de alto ROI pronto pra gerar
 *
 * Uso: /c/xampp/php/php.exe scripts/validar_pipeline.php
 * Exit 0 = tudo verde · 1 = alguma falha que impede gerar com segurança.
 */

$cfg = require __DIR__ . '/../config.php';

// ─── Parse argv: --site=slug opcional ──────────────────────────
$siteArg = '';
foreach ($argv as $a) {
    if (preg_match('/^--site=(.+)$/', $a, $m)) { $siteArg = $m[1]; break; }
}
if ($siteArg !== '') {
    require_once __DIR__ . '/../_site_helper.php';
    $sites = sitesDisponiveis();
    if (!isset($sites[$siteArg])) {
        fwrite(STDERR, "Site '{$siteArg}' não existe. Disponíveis: " . implode(', ', array_keys($sites)) . "\n");
        exit(2);
    }
    aplicarSite($cfg, $sites, $siteArg);
    echo "→ Validando site: \033[36m{$siteArg}\033[0m (" . ($sites[$siteArg]['wp_url'] ?? '?') . ")\n\n";
} else {
    echo "→ Validando site \033[36mdefault\033[0m (" . $cfg['wp_url'] . ")\n";
    echo "  Use --site=SLUG para validar outro site. Disponíveis: ";
    require_once __DIR__ . '/../_site_helper.php';
    echo implode(', ', array_keys(sitesDisponiveis())) . "\n\n";
}

function check(string $label, callable $fn, bool $critical = true): bool {
    echo sprintf("  %-56s ", $label);
    try {
        $res = $fn();
        if ($res === true || (is_string($res) && $res === '')) {
            echo "\033[32mOK\033[0m\n";
            return true;
        }
        if (is_array($res) && !empty($res['ok'])) {
            echo "\033[32mOK\033[0m";
            if (!empty($res['info'])) echo "  \033[90m" . $res['info'] . "\033[0m";
            echo "\n";
            return true;
        }
        $msg = is_string($res) ? $res : (is_array($res) ? ($res['erro'] ?? 'falhou') : 'false');
        echo ($critical ? "\033[31mFAIL\033[0m" : "\033[33mWARN\033[0m") . "  {$msg}\n";
        return !$critical;
    } catch (Throwable $e) {
        echo ($critical ? "\033[31mEXCEPTION\033[0m" : "\033[33mWARN\033[0m") . "  " . $e->getMessage() . "\n";
        return !$critical;
    }
}

$ok = true;
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Pipeline Discover — dry-run\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

echo "── 1. Config & env ─────────────────────────────────────────────────────\n";
$ok &= check('Arquivo .env existe', fn() => is_file(__DIR__ . '/../.env') ?: 'crie via cp .env.example .env');
$ok &= check('config.php retorna array', fn() => is_array($cfg));
$ok &= check('ANTHROPIC_API_KEY formato (sk-ant-)', fn() => str_starts_with((string)($cfg['anthropic_api_key'] ?? ''), 'sk-ant-') ?: 'chave inválida ou vazia');
$ok &= check('OPENAI_API_KEY formato (sk-)', fn() => str_starts_with((string)($cfg['openai_api_key'] ?? ''), 'sk-') ?: 'chave inválida ou vazia');
$ok &= check('SERPER_API_KEY presente', fn() => !empty($cfg['serper_api_key']) ?: 'vazia');
$ok &= check('WP_URL definido', fn() => !empty($cfg['wp_url']) ?: 'vazio');
$ok &= check('WP_APP_PASSWORD presente e ≥ 16 chars', fn() => (strlen((string)($cfg['wp_app_password'] ?? '')) >= 16) ?: 'vazio ou muito curto');
$ok &= check('DEFAULT_LLM é claude ou openai', fn() => in_array($cfg['default_llm'] ?? '', ['claude','openai'], true));
$ok &= check('WEBSTORY_ROI_MIN >= 0', fn() => (float)($cfg['webstory_roi_min'] ?? -1) >= 0);

echo "\n── 2. Classes core ─────────────────────────────────────────────────────\n";
$ok &= check('TrendsTaxonomia::validar() → 0 problemas', function() {
    require_once __DIR__ . '/../lib/TrendsTaxonomia.php';
    $p = TrendsTaxonomia::validar();
    return empty($p) ? true : 'problemas: ' . count($p);
});
$ok &= check('DiscoverClusterMatcher detecta trend sintético', function() {
    require_once __DIR__ . '/../lib/DiscoverClusterMatcher.php';
    $c = DiscoverClusterMatcher::detectar(['termo' => 'concurso inss 2026']);
    return ($c['key'] === 'noticias_info_critica') ?: 'cluster errado: ' . $c['key'];
});
$ok &= check('DiscoverScore calcula sem erro', function() {
    require_once __DIR__ . '/../lib/DiscoverScore.php';
    $s = DiscoverScore::calcular(['termo' => 'teste', 'volume_num' => 10000, 'growth_pct' => 100]);
    return isset($s['final']);
});
$ok &= check('DiscoverAfiliados carrega catálogo', function() {
    require_once __DIR__ . '/../lib/DiscoverAfiliados.php';
    $lista = DiscoverAfiliados::listar();
    return ['ok' => count($lista) > 0, 'info' => count($lista) . ' ofertas'];
});
$ok &= check('DiscoverWebStory::deveGerar decide correto', function() {
    require_once __DIR__ . '/../lib/DiscoverWebStory.php';
    global $cfg;
    $fin = DiscoverWebStory::deveGerar($cfg, 'negocios_financas');
    $esp = DiscoverWebStory::deveGerar($cfg, 'esportes');
    return ($fin && !$esp) ?: "finanças=$fin esportes=$esp (esperado: true, false)";
});

echo "\n── 3. Dados & DB ───────────────────────────────────────────────────────\n";
$registrosTotais = 0;
$trendsAltoRoi = [];
$ok &= check('DiscoverDb lê registros', function() use (&$registrosTotais) {
    require_once __DIR__ . '/../lib/DiscoverDb.php';
    $db = new DiscoverDb();
    $all = $db->all();
    $registrosTotais = count($all);
    return ['ok' => $registrosTotais > 0, 'info' => $registrosTotais . ' registros'];
});
$ok &= check('Há ≥ 1 trend "aprovado" pronto pra gerar' . ($siteArg ? " (site={$siteArg})" : ''), function() use (&$trendsAltoRoi, $siteArg) {
    require_once __DIR__ . '/../lib/DiscoverDb.php';
    require_once __DIR__ . '/../lib/DiscoverSinaisEditoriais.php';
    $db = new DiscoverDb();
    $filtro = $siteArg !== '' ? ['site' => $siteArg] : [];
    $all = $db->all($filtro);
    $aprovados = array_filter($all, fn($r) => ($r['status'] ?? '') === 'aprovado');
    foreach ($aprovados as $r) {
        $s = DiscoverSinaisEditoriais::ler($r);
        $ck = $s['cluster_detect']['key'] ?? 'curiosidades_geral';
        $roi = TrendsTaxonomia::roiEditorial($ck);
        if ($roi >= 5.0) $trendsAltoRoi[] = $r;
    }
    return ['ok' => count($trendsAltoRoi) > 0, 'info' => count($trendsAltoRoi) . ' aprovados com ROI ≥ 5'];
});

echo "\n── 4. Matchmaker em trend real ─────────────────────────────────────────\n";
$ok &= check('DiscoverAfiliados matchea 1 trend real', function() use ($trendsAltoRoi) {
    require_once __DIR__ . '/../lib/DiscoverAfiliados.php';
    if (empty($trendsAltoRoi)) return 'sem trends pra testar';
    $r = $trendsAltoRoi[0];
    $s = DiscoverSinaisEditoriais::ler($r);
    $m = DiscoverAfiliados::matchear([
        'termo' => $r['termo'],
        'cluster_detect' => $s['cluster_detect'],
        'pain' => $s['pain'],
        'relacionados' => $r['relacionados'] ?? [],
    ]);
    if ($m === null) return ['ok' => true, 'info' => 'sem match para este trend — esperado para alguns clusters'];
    return ['ok' => true, 'info' => 'match: ' . $m['oferta']['slug'] . ' (score ' . $m['score'] . ')'];
}, false);

echo "\n── 5. Conectividade externa ────────────────────────────────────────────\n";
$wpUrl = rtrim((string)$cfg['wp_url'], '/');
$auth = 'Basic ' . base64_encode($cfg['wp_user'] . ':' . $cfg['wp_app_password']);

$ok &= check('WP REST API responde (GET /wp-json/)', function() use ($wpUrl) {
    $ch = curl_init($wpUrl . '/wp-json/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return "HTTP {$code}";
    $j = json_decode((string)$body, true);
    return isset($j['name']) ? ['ok' => true, 'info' => 'site: ' . $j['name']] : 'JSON inválido';
});

$ok &= check('WP auth funciona (GET /wp/v2/users/me)', function() use ($wpUrl, $auth) {
    $ch = curl_init($wpUrl . '/wp-json/wp/v2/users/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $auth],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return "HTTP {$code} (credenciais inválidas?)";
    $j = json_decode((string)$body, true);
    return isset($j['id']) ? ['ok' => true, 'info' => 'logado como #' . $j['id'] . ' (' . ($j['name'] ?? '?') . ')'] : 'resposta inesperada';
});

$ok &= check('Plugin wsai endpoint (/wp-wsai/v1/create-story)', function() use ($wpUrl, $auth) {
    // POST vazio: rota existente retorna 400 "param ausente"; rota inexistente retorna 404
    $urls = [$wpUrl . '/wp-json/wp-wsai/v1/create-story', $wpUrl . '/?rest_route=/wp-wsai/v1/create-story'];
    foreach ($urls as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => '{}',
            CURLOPT_TIMEOUT       => 10,
            CURLOPT_SSL_VERIFYPEER=> false,
            CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Authorization: ' . $auth],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (in_array($code, [200, 400, 405, 422], true)) {
            return ['ok' => true, 'info' => "HTTP {$code} (rota ativa)"];
        }
        if ($code === 404) continue;
        return "HTTP {$code}";
    }
    return 'rota não encontrada — plugin wp-web-stories-ai ativo?';
}, false);

$ok &= check('Plugin Google Web Stories (namespace /web-stories/v1)', function() use ($wpUrl) {
    // Confere via lista de namespaces em /wp-json/ — o plugin registra web-stories/v1
    $ch = curl_init($wpUrl . '/wp-json/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$body, true);
    $ns = $j['namespaces'] ?? [];
    if (in_array('web-stories/v1', $ns, true)) return ['ok' => true, 'info' => 'namespace ativo'];
    return 'web-stories/v1 não listado em namespaces REST';
}, false);

echo "\n── 6. Sugestão de trend pra geração real ──────────────────────────────\n";
if (!empty($trendsAltoRoi)) {
    usort($trendsAltoRoi, fn($a, $b) => ($b['score_discover'] ?? 0) <=> ($a['score_discover'] ?? 0));
    echo "\n  Top 3 candidatos pra V1.2 (geração real):\n";
    foreach (array_slice($trendsAltoRoi, 0, 3) as $r) {
        $s = DiscoverSinaisEditoriais::ler($r);
        $ck = $s['cluster_detect']['key'] ?? '?';
        echo sprintf("    #%-4d  %-40s  cluster=%-12s ROI=%.1f  score=%.2f\n",
            (int)$r['id'],
            mb_substr($r['termo'], 0, 40, 'UTF-8'),
            TrendsTaxonomia::labelCurto($ck),
            TrendsTaxonomia::roiEditorial($ck),
            (float)($r['score_discover'] ?? 0));
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════════\n";
if ($ok) {
    echo "  ✓ \033[32mTUDO VERDE\033[0m — pipeline pronto pra gerar 1 artigo real (V1.2).\n";
    echo "═══════════════════════════════════════════════════════════════════════════\n";
    exit(0);
}
echo "  ✗ \033[31mFALHAS DETECTADAS\033[0m — corrija antes de gerar.\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
exit(1);
