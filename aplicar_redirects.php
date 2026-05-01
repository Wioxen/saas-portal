<?php
/**
 * aplicar_redirects.php — aplica redirects no WP via REST API.
 *
 * Tenta 2 caminhos em ordem:
 *   1. Rank Math REST nativo (/wp-json/rankmath/v1/redirections)
 *   2. MU-plugin custom (/wp-json/clonais/v1/redirect) — precisa upload 1×
 *
 * Lê os redirects do CSV gerado por mapear_html_legacy.php (data/redirects_<slug>.csv)
 * e POSTa cada um via REST com Application Password do WP.
 *
 * Uso:
 *   php aplicar_redirects.php cursosenac
 *
 * Se nenhum endpoint REST disponível, imprime instruções pra upload do MU-plugin.
 */

set_time_limit(180);
require_once __DIR__ . '/_site_helper.php';

$slug = $argv[1] ?? '';
if ($slug === '') { fwrite(STDERR, "Uso: php aplicar_redirects.php <site-slug>\n"); exit(1); }

$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { fwrite(STDERR, "Site '{$slug}' não cadastrado em sites.php\n"); exit(1); }

$site = $sites[$slug];
$wpUrl = rtrim($site['wp_url'], '/');
$wpUser = $site['wp_user'];
$wpPass = $site['wp_app_password'];

$csvFile = __DIR__ . "/data/redirects_{$slug}.csv";
if (!file_exists($csvFile)) { fwrite(STDERR, "CSV não encontrado: {$csvFile}\nRode primeiro: php mapear_html_legacy.php {$slug}\n"); exit(1); }

echo "\n=== Aplicando redirects via REST ({$slug}) ===\n";
echo "WP: {$wpUrl}\n";
echo "CSV: " . basename($csvFile) . "\n\n";

// ─── PROBE: descobre quais endpoints REST existem ───
echo "Detectando endpoint REST disponível...\n";

// Probe 1: Rank Math nativo — endpoint correto é /updateRedirection (verbo, não /redirections)
// Confirmado via probe_rest.php: /wp-json/rankmath/v1/updateRedirection existe
$endpointAtivo = null;
$tipoEndpoint = null;
$ep = "{$wpUrl}/wp-json/rankmath/v1/updateRedirection";
$r = wpRest('OPTIONS', $ep, [], $wpUser, $wpPass, 8);
// OPTIONS retorna 200 se rota existe (mesmo sem permissão GET)
if ($r['code'] >= 200 && $r['code'] < 405) {
    $endpointAtivo = $ep; $tipoEndpoint = 'rankmath-updateRedirection';
}

// Probe 2: MU-plugin custom
if ($endpointAtivo === null) {
    $epCustom = "{$wpUrl}/wp-json/clonais/v1/redirect";
    $r = wpRest('GET', $epCustom . '?ping=1', [], $wpUser, $wpPass, 8);
    // MU-plugin retorna 200 no GET ping (vamos checar abaixo)
    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($r['body']) && !empty($r['body']['ok'])) {
        $endpointAtivo = $epCustom; $tipoEndpoint = 'mu-plugin';
    }
}

if ($endpointAtivo === null) {
    echo "✗ Nenhum endpoint REST disponível pra criar redirects.\n\n";
    echo "─── INSTRUÇÕES — UPLOAD DO MU-PLUGIN (1× só, 30 segundos) ───\n\n";
    echo "1. Vou gerar arquivo: clonais-redirects-mu.php\n";
    echo "2. Você sobe ele em: <SEU-WP>/wp-content/mu-plugins/clonais-redirects-mu.php\n";
    echo "   (criar pasta mu-plugins se não existir)\n";
    echo "3. Re-rode este script: php aplicar_redirects.php {$slug}\n\n";

    $muPluginCode = gerarMuPlugin();
    $muPath = __DIR__ . '/data/clonais-redirects-mu.php';
    file_put_contents($muPath, $muPluginCode);
    echo "✓ MU-plugin gerado em: {$muPath}\n";
    echo "  Faça upload via FTP/cPanel pra: /wp-content/mu-plugins/clonais-redirects-mu.php\n\n";
    exit(0);
}

echo "✓ Endpoint detectado: {$endpointAtivo} (tipo: {$tipoEndpoint})\n\n";

// ─── LÊ CSV ───
$linhas = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($linhas); // remove header
$redirects = [];
foreach ($linhas as $l) {
    $cols = str_getcsv($l);
    if (count($cols) < 6) continue;
    $redirects[] = [
        'source'      => trim($cols[0]),
        'destination' => trim($cols[1]),
        'header_code' => (int)$cols[5],
    ];
}
echo "Redirects a aplicar: " . count($redirects) . "\n\n";

// ─── APLICA CADA ─────
$ok = 0; $fail = 0; $skip = 0;
foreach ($redirects as $i => $rd) {
    $payload = [
        'sources'     => [['pattern' => $rd['source'], 'comparison' => 'exact', 'ignore' => 'case']],
        'url_to'      => $rd['destination'],
        'header_code' => $rd['header_code'],
        'status'      => 'active',
    ];
    $r = wpRest('POST', $endpointAtivo, $payload, $wpUser, $wpPass);
    $idx = $i + 1;
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $bodyMsg = '';
        if (is_array($r['body'])) {
            if (!empty($r['body']['updated'])) { $bodyMsg = ' (atualizado #' . ($r['body']['id'] ?? '?') . ')'; }
            elseif (!empty($r['body']['created'])) { $bodyMsg = ' (criado #' . ($r['body']['id'] ?? '?') . ')'; }
            elseif (!empty($r['body']['id'])) { $bodyMsg = ' (#' . $r['body']['id'] . ')'; }
        }
        echo "  [{$idx}] ✓ {$rd['source']} → {$rd['destination']} ({$rd['header_code']}){$bodyMsg}\n";
        $ok++;
    } else {
        $msg = is_array($r['body']) ? json_encode($r['body']) : (string)$r['body'];
        $msg = mb_substr($msg, 0, 200);
        echo "  [{$idx}] ✗ {$rd['source']} → HTTP {$r['code']} — {$msg}\n";
        $fail++;
    }
}

echo "\n=== RESULTADO ===\n";
echo "✓ Criados/atualizados: {$ok}\n";
echo "✗ Falhas: {$fail}\n";
if ($fail === 0) {
    echo "\n🎉 Todos os redirects aplicados com sucesso!\n\n";
    echo "Próximos passos:\n";
    echo "  1. Validar: curl -I {$wpUrl}" . ($redirects[0]['source'] ?? '') . "\n";
    echo "     (deve retornar HTTP 301 + Location)\n";
    echo "  2. Rank Math → Sitemap Settings → Save (regenera sitemap)\n";
    echo "  3. Re-rodar audit: php auditar_adsense.php {$wpUrl}\n";
    echo "  4. Esperar 5-7 dias e reaplicar AdSense\n";
}

// ─── HELPERS ───
function wpRest(string $method, string $url, array $payload, string $user, string $pass, int $timeout = 12): array {
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
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);
    return ['code' => $code, 'body' => is_array($decoded) ? $decoded : $body];
}

function gerarMuPlugin(): string {
    return <<<'PHP'
<?php
/**
 * Plugin Name: Clonais Redirect API
 * Description: REST endpoint custom pra criar redirects no Rank Math via WP REST API com app password.
 *              Necessário porque Rank Math Free não expõe REST de redirections.
 * Version: 1.0
 * Author: Clonais
 *
 * UPLOAD: este arquivo deve ficar em /wp-content/mu-plugins/clonais-redirects-mu.php
 *         (criar pasta mu-plugins se não existir — WordPress carrega automaticamente, sem ativação)
 *
 * Endpoint:
 *   GET  /wp-json/clonais/v1/redirect?ping=1  → ping pra detectar endpoint vivo
 *   POST /wp-json/clonais/v1/redirect          → cria/atualiza redirect (auth: WP App Password)
 *     Payload: { "sources": [{"pattern": "/old", "comparison": "exact"}], "url_to": "/new/", "header_code": 301 }
 *
 * Após uso, pode deletar o arquivo (mu-plugins não exigem desativação).
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    register_rest_route('clonais/v1', '/redirect', [
        [
            'methods'             => 'GET',
            'callback'            => 'clonais_ping_redirect',
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'clonais_create_redirect',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ],
    ]);
});

function clonais_ping_redirect(WP_REST_Request $request) {
    global $wpdb;
    $table = $wpdb->prefix . 'rank_math_redirections';
    $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    return [
        'ok'           => true,
        'plugin'       => 'clonais-redirects-mu v1.0',
        'rank_math_table' => $tableExists,
        'table_name'   => $table,
    ];
}

function clonais_create_redirect(WP_REST_Request $request) {
    global $wpdb;
    $table = $wpdb->prefix . 'rank_math_redirections';

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
        return new WP_Error('no_table',
            'Tabela rank_math_redirections não existe. Ative o módulo Redirections em Rank Math → Dashboard.',
            ['status' => 500]
        );
    }

    $sources     = $request->get_param('sources');
    $url_to      = (string)$request->get_param('url_to');
    $header_code = (int)($request->get_param('header_code') ?: 301);
    $status      = $request->get_param('status') ?: 'active';

    if (empty($sources) || !is_array($sources)) {
        return new WP_Error('bad_request', 'sources obrigatório (array de objetos com pattern+comparison)', ['status' => 400]);
    }

    // Verifica se já existe redirect com mesmo primeiro pattern
    $primeiroPattern = $sources[0]['pattern'] ?? '';
    if ($primeiroPattern === '') {
        return new WP_Error('bad_request', 'sources[0].pattern obrigatório', ['status' => 400]);
    }

    $existentes = $wpdb->get_results($wpdb->prepare(
        "SELECT id, sources FROM {$table} WHERE sources LIKE %s LIMIT 50",
        '%' . $wpdb->esc_like($primeiroPattern) . '%'
    ));
    foreach ($existentes as $row) {
        $unser = maybe_unserialize($row->sources);
        if (!is_array($unser)) continue;
        foreach ($unser as $u) {
            if (($u['pattern'] ?? '') === $primeiroPattern) {
                // Atualiza
                $wpdb->update(
                    $table,
                    [
                        'sources'     => maybe_serialize($sources),
                        'url_to'      => $url_to,
                        'header_code' => $header_code,
                        'status'      => $status,
                        'updated'     => current_time('mysql'),
                    ],
                    ['id' => (int)$row->id]
                );
                return [
                    'updated' => true,
                    'id'      => (int)$row->id,
                    'source'  => $primeiroPattern,
                    'url_to'  => $url_to,
                ];
            }
        }
    }

    // Insere novo
    $now = current_time('mysql');
    $inserted = $wpdb->insert($table, [
        'sources'       => maybe_serialize($sources),
        'url_to'        => $url_to,
        'header_code'   => $header_code,
        'status'        => $status,
        'hits'          => 0,
        'created'       => $now,
        'updated'       => $now,
        'last_accessed' => '0000-00-00 00:00:00',
    ]);

    if ($inserted === false) {
        return new WP_Error('insert_failed',
            'Falha ao inserir: ' . $wpdb->last_error,
            ['status' => 500]
        );
    }

    return [
        'created' => true,
        'id'      => (int)$wpdb->insert_id,
        'source'  => $primeiroPattern,
        'url_to'  => $url_to,
    ];
}
PHP;
}
