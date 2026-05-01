<?php
/**
 * /saude.php — Health check público (shim HTTP).
 *
 * Lógica em lib/Saude.php (testável). Este arquivo só serializa a resposta.
 *
 * HTTP 200 se ok=true (warning aceito). HTTP 503 se ok=false (severidade=error).
 *
 * Acesso público sem token: `summary` mínimo (sem paths/credenciais).
 * Com token (`?token=XXX` onde XXX = SAUDE_TOKEN do .env): retorna `checks` detalhado.
 * Adicional `&wp=1` (com token): pinga cada wp_url (lento, 6+ requests).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

$ROOT = __DIR__;

require_once $ROOT . '/lib/Env.php';
@Env::load($ROOT . '/.env');
require_once $ROOT . '/lib/Saude.php';

$tokenEsperado = (string)Env::get('SAUDE_TOKEN', '');
$tokenRecebido = (string)($_GET['token'] ?? '');
$detalhado = $tokenEsperado !== '' && hash_equals($tokenEsperado, $tokenRecebido);
$incluirWp = $detalhado && !empty($_GET['wp']);
$incluirStats = $detalhado && !empty($_GET['stats']); // só com token (dados financeiros)

if ($incluirStats) {
    $resposta = Saude::stats();
    http_response_code($resposta['ok'] ? 200 : 503);
} else {
    $resposta = Saude::checar($detalhado, $incluirWp);
    http_response_code($resposta['ok'] ? 200 : 503);
}

echo json_encode($resposta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
