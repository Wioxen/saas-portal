<?php
/**
 * fix_redirection_db.php — última tentativa de forçar criação de tabelas via REST.
 *
 * Estratégia: deactivate + reactivate o plugin Redirection. Activation hook DEVERIA
 * criar tabelas. Se mesmo assim não funcionar, plugin precisa visita manual ao painel.
 */
require_once __DIR__ . '/_site_helper.php';
$slug = $argv[1] ?? '';
$sites = sitesDisponiveis();
if (!isset($sites[$slug])) { exit(1); }
$s = $sites[$slug];

function rest($method, $url, $payload, $user, $pass) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERPWD => "{$user}:{$pass}",
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if (in_array(strtoupper($method), ['POST', 'PUT'], true) && !empty($payload)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$body, true) ?: $body];
}

$wpUrl = rtrim($s['wp_url'], '/');
$user = $s['wp_user'];
$pass = $s['wp_app_password'];

echo "Inspecionando estado completo do plugin Redirection...\n\n";
$st = rest('GET', "{$wpUrl}/wp-json/redirection/v1/plugin", [], $user, $pass);
echo "Status code: {$st['code']}\n";
echo "Body completo:\n";
echo json_encode($st['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Tentando deactivate + reactivate pra forçar activation hook...\n";
$d = rest('PUT', "{$wpUrl}/wp-json/wp/v2/plugins/redirection/redirection", ['status' => 'inactive'], $user, $pass);
echo "  Deactivate HTTP: {$d['code']}\n";
sleep(1);
$a = rest('PUT', "{$wpUrl}/wp-json/wp/v2/plugins/redirection/redirection", ['status' => 'active'], $user, $pass);
echo "  Reactivate HTTP: {$a['code']}\n";
sleep(2);

echo "\nTentando /plugin/fix novamente...\n";
$f = rest('POST', "{$wpUrl}/wp-json/redirection/v1/plugin/fix", [], $user, $pass);
echo "  Fix HTTP: {$f['code']}\n";
echo "  Body: " . json_encode($f['body']) . "\n";
sleep(2);

echo "\nVerificando se tabelas foram criadas (via /group):\n";
$g = rest('GET', "{$wpUrl}/wp-json/redirection/v1/group", [], $user, $pass);
echo "  /group HTTP: {$g['code']}\n";
echo "  Body: " . json_encode($g['body']) . "\n";
