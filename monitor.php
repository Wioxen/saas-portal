<?php
/**
 * monitor.php — entry HTTP que serve o dashboard de operação com basic auth.
 *
 * Diferente de dashboard.php (hub de ferramentas). Esse aqui é monitoramento
 * READ-ONLY da operação multi-site: trends/posts/hubs/contradições/last_runs.
 *
 * Fluxo:
 *   1. Auth via Env DASHBOARD_USER + DASHBOARD_PASS
 *   2. Lê HTML pré-gerado em data/dashboard/index.html
 *   3. Se HTML antigo (>2h) ou ausente, regera on-the-fly
 *
 * Acesse: https://sistema3-saasportal.o8a7pc.easypanel.host/monitor.php
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/Env.php';
Env::load(__DIR__ . '/.env');

// ── Auth ──────────────────────────────────────────────────────────────────
$expectedUser = Env::get('DASHBOARD_USER', 'admin');
$expectedPass = Env::get('DASHBOARD_PASS', '');

if ($expectedPass === '') {
    http_response_code(503);
    echo 'Monitor desabilitado: defina DASHBOARD_PASS no .env do servidor (e DASHBOARD_USER se quiser mudar de admin).';
    exit;
}

$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';

if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
    header('WWW-Authenticate: Basic realm="Monitor de Operação"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Auth required.';
    exit;
}

// ── Serve HTML ────────────────────────────────────────────────────────────
$htmlPath = __DIR__ . '/data/dashboard/index.html';
$maxAge = 2 * 3600; // 2h — força regen on-the-fly se cache muito antigo

$precisaGerar = !file_exists($htmlPath) || (time() - filemtime($htmlPath)) > $maxAge;

if ($precisaGerar) {
    @passthru('php ' . escapeshellarg(__DIR__ . '/scripts/gerar_dashboard.php') . ' --quiet 2>&1', $rc);
    if (!file_exists($htmlPath)) {
        http_response_code(500);
        echo 'Falha ao gerar dashboard. Veja log do servidor.';
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, max-age=300');
readfile($htmlPath);
