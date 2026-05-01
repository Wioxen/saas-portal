<?php
/**
 * scripts/validar_taxonomia.php
 *
 * Valida a integridade de lib/TrendsTaxonomia.php:
 *  - todos os 13 clusters têm os 13 campos obrigatórios
 *  - rpm e threshold em faixas plausíveis
 *  - grupo_editorial válido (8 permitidos)
 *  - regex de termos_proibidos compilam
 *  - categoria_ids referenciam IDs existentes em CATEGORIAS_GOOGLE
 *  - curiosidades_geral é o último (fallback)
 *
 * Uso: /c/xampp/php/php.exe scripts/validar_taxonomia.php
 * Exit 0 = ok; 1 = problemas detectados.
 */

require_once __DIR__ . '/../lib/TrendsTaxonomia.php';

$problemas = TrendsTaxonomia::validar();
$chaves    = TrendsTaxonomia::chaves();
$totalCats = count(TrendsTaxonomia::CATEGORIAS_GOOGLE);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  scripts/validar_taxonomia.php\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "Clusters definidos:       " . count($chaves) . "\n";
echo "Categorias Google:        {$totalCats}\n";
echo "Problemas encontrados:    " . count($problemas) . "\n\n";

if ($problemas) {
    echo "═══ PROBLEMAS ═════════════════════════════════════════════════\n";
    foreach ($problemas as $p) {
        echo "  ✗ {$p}\n";
    }
    echo "\n";
    exit(1);
}

// Relatório sumarizado por cluster
echo "─── Resumo por cluster ────────────────────────────────────────\n";
echo sprintf("  %-28s %-16s %6s %6s %s\n", 'cluster', 'grupo', 'rpm', 'threshold', 'categoria_ids');
echo "  " . str_repeat('─', 70) . "\n";
foreach ($chaves as $key) {
    $c = TrendsTaxonomia::cluster($key);
    echo sprintf(
        "  %-28s %-16s %6d %8.1f   %s\n",
        $key,
        $c['grupo_editorial'],
        $c['rpm'],
        $c['threshold'],
        implode(',', $c['categoria_ids'] ?? [])
    );
}
echo "\n";
echo "  ✓ Taxonomia válida.\n";
exit(0);
