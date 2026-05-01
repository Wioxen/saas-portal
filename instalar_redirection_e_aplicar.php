<?php
/**
 * instalar_redirection_e_aplicar.php — fluxo completo via REST:
 *
 *   1. Instala plugin "Redirection" (John Godley) via /wp/v2/plugins
 *   2. Ativa o plugin
 *   3. Lista grupos pra pegar o default
 *   4. Cria cada redirect via /wp-json/redirection/v1/redirect
 *   5. Valida cada redirect com HEAD request
 *
 * Lê os redirects do CSV gerado por mapear_html_legacy.php.
 *
 * Uso:
 *   php instalar_redirection_e_aplicar.php cursosenac
 */

set_time_limit(180);
require_once __DIR__ . '/_site_helper.php';

$slug = $argv[1] ?? '';
if ($slug === '') { fwrite(STDERR, "Uso: php instalar_redirection_e_aplicar.php <site-slug>\n"); exit(1); }

$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { fwrite(STDERR, "Site '{$slug}' não cadastrado em sites.php\n"); exit(1); }

$site = $sites[$slug];
$wpUrl = rtrim($site['wp_url'], '/');
$wpUser = $site['wp_user'];
$wpPass = $site['wp_app_password'];

$csvFile = __DIR__ . "/data/redirects_{$slug}.csv";
if (!file_exists($csvFile)) { fwrite(STDERR, "CSV não encontrado: {$csvFile}\n"); exit(1); }

echo "\n=== Install + Apply Redirects via REST ({$slug}) ===\n";
echo "WP: {$wpUrl}\n\n";

// ─── HELPERS ───
function wpRest(string $method, string $url, array $payload, string $user, string $pass, int $timeout = 20): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true) && !empty($payload)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);
    return ['code' => $code, 'body' => is_array($decoded) ? $decoded : $body];
}

// ─── 1. CHECA SE PLUGIN JÁ ESTÁ INSTALADO ───
echo "[1/5] Verificando se plugin 'Redirection' já está instalado...\n";
$pluginPath = 'redirection/redirection';
$check = wpRest('GET', "{$wpUrl}/wp-json/wp/v2/plugins/{$pluginPath}", [], $wpUser, $wpPass, 10);

$pluginInstalado = false;
$pluginAtivo = false;

if ($check['code'] === 200 && is_array($check['body'])) {
    $pluginInstalado = true;
    $pluginAtivo = ($check['body']['status'] ?? '') === 'active';
    echo "  ✓ Já instalado · status: " . ($check['body']['status'] ?? '?') . "\n";
} elseif ($check['code'] === 404) {
    echo "  ✗ Não instalado — vou instalar agora\n";
} else {
    echo "  ⚠ HTTP {$check['code']} ao verificar — tentando instalar mesmo assim\n";
}

// ─── 2. INSTALA SE NECESSÁRIO ───
if (!$pluginInstalado) {
    echo "\n[2/5] Instalando plugin Redirection do repositório WordPress.org...\n";
    $install = wpRest('POST', "{$wpUrl}/wp-json/wp/v2/plugins", [
        'slug'   => 'redirection',
        'status' => 'active',
    ], $wpUser, $wpPass, 60);

    if ($install['code'] >= 200 && $install['code'] < 300) {
        echo "  ✓ Instalado e ativado\n";
        $pluginInstalado = true;
        $pluginAtivo = true;
    } else {
        $msg = is_array($install['body']) ? json_encode($install['body']) : $install['body'];
        fwrite(STDERR, "  ✗ Falha ao instalar: HTTP {$install['code']} — " . mb_substr($msg, 0, 300) . "\n");
        fwrite(STDERR, "\nProvavel: usuário não tem capability install_plugins. Tente caminho B (MU-plugin) ou C (.htaccess).\n");
        exit(1);
    }
}

// ─── 3. ATIVA SE NÃO ESTIVER ATIVO ───
if (!$pluginAtivo) {
    echo "\n[3/5] Ativando plugin...\n";
    $activate = wpRest('PUT', "{$wpUrl}/wp-json/wp/v2/plugins/{$pluginPath}", [
        'status' => 'active',
    ], $wpUser, $wpPass, 30);
    if ($activate['code'] >= 200 && $activate['code'] < 300) {
        echo "  ✓ Ativado\n";
        $pluginAtivo = true;
    } else {
        $msg = is_array($activate['body']) ? json_encode($activate['body']) : $activate['body'];
        fwrite(STDERR, "  ✗ Falha ao ativar: HTTP {$activate['code']} — " . mb_substr($msg, 0, 300) . "\n");
        exit(1);
    }
} else {
    echo "\n[3/5] Plugin já ativo, pulando ativação.\n";
}

// Plugin recém-ativado precisa de inicialização (cria tabelas)
sleep(2);

// ─── 4a. CHECA ESTADO DO PLUGIN + RODA SETUP/FIX SE NECESSÁRIO ───
echo "\n[4a/6] Verificando estado do plugin Redirection...\n";
$pluginStat = wpRest('GET', "{$wpUrl}/wp-json/redirection/v1/plugin", [], $wpUser, $wpPass, 15);
if ($pluginStat['code'] === 200) {
    $statusPl = is_array($pluginStat['body']) ? ($pluginStat['body']['status'] ?? 'unknown') : 'unknown';
    $needInstall = is_array($pluginStat['body']) && !empty($pluginStat['body']['need_install']);
    $needUpdate  = is_array($pluginStat['body']) && !empty($pluginStat['body']['need_update']);
    echo "  Status: {$statusPl} · need_install: " . ($needInstall ? 'SIM' : 'não') . " · need_update: " . ($needUpdate ? 'SIM' : 'não') . "\n";

    // Roda /plugin/fix com payload correto pra criar tabelas (reason=install, current="")
    // Plugin pode precisar múltiplas chamadas pra completar todas as etapas do upgrade
    echo "  Rodando /plugin/fix pra criar tabelas (multi-stage upgrade)...\n";
    $maxFix = 8;
    $currentVer = '';
    for ($f = 1; $f <= $maxFix; $f++) {
        $fix = wpRest('POST', "{$wpUrl}/wp-json/redirection/v1/plugin/fix", [
            'reason'  => 'install',
            'current' => $currentVer,
        ], $wpUser, $wpPass, 30);
        echo "    Fix tentativa {$f}: HTTP {$fix['code']}";
        if (is_array($fix['body'])) {
            $st = $fix['body']['status'] ?? '';
            $next = $fix['body']['next'] ?? '';
            $reason = $fix['body']['reason'] ?? '';
            echo " · status={$st} · next={$next}";
            if ($st === 'ok' || $st === 'complete') { echo "\n    ✓ Setup completo\n"; break; }
            if ($st === 'need-update' && $next !== '') { $currentVer = $next; echo "\n"; sleep(1); continue; }
        }
        echo "\n";
        if ($fix['code'] >= 400) break;
        sleep(1);
    }

    // Finaliza setup wizard
    echo "  Chamando /plugin/finish pra finalizar wizard...\n";
    $finish = wpRest('POST', "{$wpUrl}/wp-json/redirection/v1/plugin/finish", [], $wpUser, $wpPass, 30);
    echo "  Finish HTTP: {$finish['code']}\n";
    sleep(2);

    // Re-verifica status
    $pluginStat2 = wpRest('GET', "{$wpUrl}/wp-json/redirection/v1/plugin", [], $wpUser, $wpPass, 15);
    if ($pluginStat2['code'] === 200) {
        $statusPl2 = is_array($pluginStat2['body']) ? ($pluginStat2['body']['status'] ?? 'unknown') : 'unknown';
        echo "  Status pós-setup: {$statusPl2}\n";
    }
} else {
    echo "  ⚠ /plugin endpoint HTTP {$pluginStat['code']} — tentando criar redirects mesmo assim\n";
}
sleep(1);

// ─── 4b. LISTA GRUPOS PRA PEGAR DEFAULT ───
echo "\n[4b/6] Verificando grupos do Redirection...\n";
$groupsResp = wpRest('GET', "{$wpUrl}/wp-json/redirection/v1/group", [], $wpUser, $wpPass, 15);
$groupId = 1; // default fallback
if ($groupsResp['code'] === 200 && is_array($groupsResp['body']) && !empty($groupsResp['body']['items'])) {
    foreach ($groupsResp['body']['items'] as $g) {
        if (!empty($g['id'])) { $groupId = (int)$g['id']; break; }
    }
    echo "  ✓ Grupo default: #{$groupId}\n";
} else {
    echo "  ⚠ Sem grupos retornados (HTTP {$groupsResp['code']}) — usando ID 1\n";
}

// ─── 5. APLICA REDIRECTS DO CSV ───
echo "\n[5/5] Aplicando redirects do CSV...\n";
$linhas = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($linhas); // header
$redirects = [];
foreach ($linhas as $l) {
    $cols = str_getcsv($l);
    if (count($cols) < 6) continue;
    $redirects[] = [
        'source' => trim($cols[0]),
        'destination' => trim($cols[1]),
        'header_code' => (int)$cols[5],
    ];
}
echo "  Total a aplicar: " . count($redirects) . "\n\n";

$ok = 0; $fail = 0;
foreach ($redirects as $i => $rd) {
    $payload = [
        'url'         => $rd['source'],
        'match_url'   => $rd['source'],
        'match_type'  => 'url',
        'action_type' => 'url',
        'action_data' => ['url' => $rd['destination']],
        'action_code' => $rd['header_code'],
        'group_id'    => $groupId,
        'title'       => 'AdSense legacy redirect',
        'regex'       => false,
        'position'    => 0,
    ];
    $r = wpRest('POST', "{$wpUrl}/wp-json/redirection/v1/redirect", $payload, $wpUser, $wpPass, 20);
    $idx = $i + 1;
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $newId = is_array($r['body']) ? ($r['body']['item']['id'] ?? $r['body']['id'] ?? '?') : '?';
        echo "  [{$idx}] ✓ {$rd['source']} → {$rd['destination']} ({$rd['header_code']}) #{$newId}\n";
        $ok++;
    } else {
        $msg = is_array($r['body']) ? json_encode($r['body']) : (string)$r['body'];
        echo "  [{$idx}] ✗ {$rd['source']} → HTTP {$r['code']} — " . mb_substr($msg, 0, 200) . "\n";
        $fail++;
    }
}

echo "\n=== RESULTADO ===\n";
echo "✓ Criados: {$ok}\n";
echo "✗ Falhas: {$fail}\n";

// ─── 6. VALIDAÇÃO ───
if ($ok > 0) {
    echo "\n[6/6] Validando redirects (HEAD requests)...\n";
    foreach ($redirects as $rd) {
        $url = $wpUrl . $rd['source'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false, // queremos ver o 301 antes de seguir
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $h = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = '';
        if (preg_match('/^location:\s*(.+)$/im', (string)$h, $mL)) $location = trim($mL[1]);
        curl_close($ch);
        if ($code === 301 || $code === 302) {
            echo "  ✓ {$rd['source']} → HTTP {$code} → {$location}\n";
        } else {
            echo "  ⚠ {$rd['source']} → HTTP {$code} (esperava 301)\n";
        }
    }
}

echo "\n🎉 Processo completo!\n\n";
echo "Próximos passos:\n";
echo "  1. Rank Math → Sitemap Settings → Save (regenera sitemap)\n";
echo "  2. Re-rodar audit: php auditar_adsense.php {$wpUrl}\n";
echo "  3. Esperar 5-7 dias e reaplicar AdSense\n";
