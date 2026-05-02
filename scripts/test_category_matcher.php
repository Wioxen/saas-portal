<?php
/**
 * test_category_matcher — diagnostica CategoryMatcher num site específico.
 *
 * Mostra step-by-step:
 *   1. Loading dos requires
 *   2. sites.php carrega
 *   3. Site target existe + creds preenchidos
 *   4. Wordpress instancia
 *   5. WP REST retorna lista de categorias (verifica auth)
 *   6. CategoryMatcher resolve nomes propostos → IDs
 *
 * Uso:
 *   php scripts/test_category_matcher.php --site=leaodabarra
 *   php scripts/test_category_matcher.php --site=leaodabarra --nomes=Esportes,Brasileirão
 */

set_time_limit(60);
$ROOT = dirname(__DIR__);

$site = null;
$nomes = ['Esportes', 'Brasileirão'];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site='))  $site = substr($arg, 7);
    elseif (str_starts_with($arg, '--nomes=')) $nomes = array_filter(array_map('trim', explode(',', substr($arg, 8))));
}
if (!$site) {
    fwrite(STDERR, "Uso: php scripts/test_category_matcher.php --site=<slug> [--nomes=Nome1,Nome2]\n");
    exit(1);
}

try {
    require $ROOT . '/config.php';
    require $ROOT . '/lib/Wordpress.php';
    require $ROOT . '/lib/CategoryMatcher.php';
    echo "1. Requires OK\n";

    $sites = require $ROOT . '/sites.php';
    echo "2. sites.php OK · " . count($sites) . " sites\n";

    if (!isset($sites[$site])) {
        echo "ERRO: site '{$site}' não está em sites.php\n";
        echo "Disponíveis: " . implode(', ', array_keys($sites)) . "\n";
        exit(1);
    }
    $s = $sites[$site];
    echo "3. {$site} OK\n";
    echo "   wp_url=" . ($s['wp_url'] ?? 'VAZIO') . "\n";
    echo "   wp_user=" . ($s['wp_user'] ?? 'VAZIO') . "\n";
    echo "   wp_app_password=" . (empty($s['wp_app_password']) ? 'VAZIA' : 'preenchida (' . strlen($s['wp_app_password']) . ' chars)') . "\n";

    if (empty($s['wp_url']) || empty($s['wp_user']) || empty($s['wp_app_password'])) {
        echo "ERRO: credenciais incompletas. Verifica sites.php + .env (WP_PASS_*)\n";
        exit(1);
    }

    $wp = new Wordpress($s['wp_url'], $s['wp_user'], $s['wp_app_password']);
    echo "4. Wordpress instanciado\n";

    $cats = $wp->listarTodasCategorias(50, 5);
    echo "5. WP retornou " . count($cats) . " categorias:\n";
    foreach ($cats as $c) {
        echo sprintf("   #%-4d · %-30s · slug=%s · count=%d\n",
            $c['id'] ?? 0,
            $c['name'] ?? '?',
            $c['slug'] ?? '?',
            $c['count'] ?? 0
        );
    }

    $cm = new CategoryMatcher($wp, 70.0);
    echo "\n6. Tentando resolver: " . json_encode($nomes, JSON_UNESCAPED_UNICODE) . "\n";

    $ids = $cm->resolverComMatch($nomes);
    echo "   IDs retornados: " . json_encode($ids) . "\n";

    if (!empty($cm->log)) {
        echo "\n7. Log do matcher:\n";
        foreach ($cm->log as $l) {
            echo "   " . json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } else {
        echo "\n7. Matcher sem log (estranho — método pode não estar populando)\n";
    }

    echo "\n═══ FIM ═══\n";
} catch (Throwable $e) {
    echo "\nEXCEPTION " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  em: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace (top 5):\n";
    foreach (array_slice($e->getTrace(), 0, 5) as $i => $t) {
        echo sprintf("  #%d %s:%d %s%s%s()\n",
            $i,
            $t['file'] ?? '?',
            $t['line'] ?? 0,
            $t['class'] ?? '',
            $t['type'] ?? '',
            $t['function'] ?? '?'
        );
    }
    exit(2);
}
