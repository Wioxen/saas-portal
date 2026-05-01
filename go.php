<?php
/**
 * go.php — Endpoint de redirect rastreado para links de afiliado.
 *
 * URL pública: /go.php?s={slug}&t={trend_id}&p={post_id}
 *   s = slug da oferta (obrigatório)
 *   t = trend_id (opcional — rastreia qual trend originou)
 *   p = post_id WP (opcional — rastreia qual post gerou o clique)
 *
 * Fluxo:
 *   1. Valida slug
 *   2. Registra clique em data/afiliados_clicks.json (anonimizado)
 *   3. Redireciona 302 para url_afiliado
 *
 * Se slug inválido → 404. Se oferta inativa → 404 (prevenção: link morto vira link morto).
 *
 * Opcional: .htaccess pode reescrever /go/{slug} → /go.php?s={slug} para URLs mais limpas.
 */

require_once __DIR__ . '/lib/DiscoverAfiliados.php';

$slug = trim((string)($_GET['s'] ?? ''));
if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Link inválido.";
    exit;
}

try {
    $oferta = DiscoverAfiliados::porSlug($slug);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro interno.";
    exit;
}

if (!$oferta || empty($oferta['ativo']) || empty($oferta['url_afiliado'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Oferta não disponível.";
    exit;
}

// Registra clique (idempotente, falha silencioso se arquivo bloqueado por concorrência)
try {
    DiscoverAfiliados::rastrearClique($slug, [
        'trend_id' => (int)($_GET['t'] ?? 0),
        'post_id'  => (int)($_GET['p'] ?? 0),
    ]);
} catch (Throwable $e) {
    // não bloqueia redirect por causa de tracking
}

// Redirect 302 (preserva caching do browser para mudanças futuras de oferta)
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . $oferta['url_afiliado'], true, 302);
exit;
