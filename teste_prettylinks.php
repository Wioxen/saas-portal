<?php
/**
 * Teste Pretty Links via plugin cc-prettylinks-api.
 * Acesse: http://localhost/apiclaudephp/teste_prettylinks.php
 */
require_once __DIR__ . '/lib/PrettyLinks.php';
$cfg = require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Teste Pretty Links (via REST plugin) ===\n\n";

// 1. Testa endpoint
$endpoint = rtrim($cfg['wp_url'], '/') . '/wp-json/cc/v1/pretty-link';
echo "1. Endpoint: {$endpoint}\n";

$pl = new PrettyLinks($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// 2. Cria link de teste
echo "\n2. Criando link de teste...\n";
try {
    $url = $pl->criar(
        'https://www.amazon.com.br/dp/B0BTY976CH',
        'go/teste-api',
        'Teste API PrettyLinks',
        true,
        '301'
    );
    if ($url) {
        echo "   ✅ SUCESSO! Link: {$url}\n";
    } else {
        echo "   ❌ Retornou null.\n";
        echo "   Verifique:\n";
        echo "   1. Plugin cc-prettylinks-api.php está ativo no WP?\n";
        echo "   2. Pretty Links está ativo no WP?\n";
        echo "   3. Application Password está correto?\n";
    }
} catch (Throwable $e) {
    echo "   ❌ Erro: " . $e->getMessage() . "\n";
}

// 3. Busca link criado
echo "\n3. Buscando link criado...\n";
try {
    $link = $pl->buscarPorSlug('go/teste-api');
    if ($link) {
        echo "   ✅ Encontrado!\n";
        echo "   URL: " . ($link['url'] ?? '?') . "\n";
        echo "   Target: " . ($link['target'] ?? '?') . "\n";
        echo "   ID: " . ($link['id'] ?? '?') . "\n";
    } else {
        echo "   ❌ Não encontrado.\n";
    }
} catch (Throwable $e) {
    echo "   ❌ Erro: " . $e->getMessage() . "\n";
}

// 4. Tenta criar mesmo slug (deve retornar existente)
echo "\n4. Criando mesmo slug novamente (deve retornar existente)...\n";
try {
    $url2 = $pl->criarOuBuscar(
        'https://www.amazon.com.br/dp/B0BTY976CH',
        'go/teste-api',
        'Teste Duplicata'
    );
    if ($url2) {
        echo "   ✅ Retornou: {$url2} (sem duplicar)\n";
    }
} catch (Throwable $e) {
    echo "   ❌ Erro: " . $e->getMessage() . "\n";
}

echo "\n=== Fim ===\n";
echo "\nSe deu erro 404 no endpoint, instale o plugin:\n";
echo "  1. Copie: apiclaudephp/plugin/cc-prettylinks-api.php\n";
echo "  2. Cole em: wp-content/plugins/cc-prettylinks-api.php\n";
echo "  3. Ative no WP Admin > Plugins\n";
