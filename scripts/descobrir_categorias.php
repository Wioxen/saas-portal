<?php
/**
 * scripts/descobrir_categorias.php
 *
 * Scrapea trends do Brasil e agrupa por categoria_id, mostrando amostras.
 * Permite eyeball do que cada ID significa, construindo a tabela definitiva
 * sem depender de mapeamentos suspeitos.
 *
 * Uso:  /c/xampp/php/php.exe scripts/descobrir_categorias.php
 */

require_once __DIR__ . '/../lib/TrendsScraperWeb.php';

$s = new TrendsScraperWeb();
$trends = $s->buscar(168);  // baseline 7 dias = mais trends = mais categorias representadas

echo "Total trends scrapeados: " . count($trends) . "\n\n";

// Agrupa por categoria_id
$porCat = [];
foreach ($trends as $t) {
    foreach (($t['categoria_ids'] ?? []) as $cid) {
        $porCat[$cid] ??= ['count' => 0, 'samples' => []];
        $porCat[$cid]['count']++;
        if (count($porCat[$cid]['samples']) < 7) {
            $porCat[$cid]['samples'][] = $t['termo'];
        }
    }
}

ksort($porCat, SORT_NUMERIC);

echo str_repeat('=', 80) . "\n";
echo "CATEGORIAS PRESENTES NOS DADOS DO GOOGLE (por ID)\n";
echo str_repeat('=', 80) . "\n\n";

foreach ($porCat as $cid => $info) {
    echo sprintf("ID %-4d  (%d trends)\n", $cid, $info['count']);
    foreach ($info['samples'] as $i => $s) {
        echo sprintf("         %d. %s\n", $i + 1, mb_substr($s, 0, 60, 'UTF-8'));
    }
    echo "\n";
}

// Nosso map atual pra comparação
echo str_repeat('=', 80) . "\n";
echo "NOSSO MAP ATUAL (TrendsScraperWeb::\$categoriasMap) — pode estar errado\n";
echo str_repeat('=', 80) . "\n\n";

foreach (TrendsScraperWeb::$categoriasMap as $id => $nome) {
    $count = $porCat[$id]['count'] ?? 0;
    printf("  %2d → %-35s (%d trends nos dados)\n", $id, $nome, $count);
}

echo "\n";
echo "→ Compare: o ID que o Google usa no URL ?category=N precisa bater com esta tabela.\n";
echo "→ Exemplo: se o URL ?category=9 no Google UI mostra 'Empregos e educação',\n";
echo "  e o ID 9 aqui tem trends de concurso/enem/emprego, então 9 = Empregos.\n";
echo "  Se discorda, o nome no map está errado e corrigimos.\n";
