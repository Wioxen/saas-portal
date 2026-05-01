<?php
/**
 * [TEMPORÁRIO — pode apagar depois] Testa DiscoverImagemViral processando 1 imagem.
 * Salva resultado em /tmp pra inspeção visual.
 *
 * Uso:
 *   php scripts/_testar_imagem_viral.php <url-imagem>
 *   php scripts/_testar_imagem_viral.php <url-imagem> --tarja=URGENTE --cor=vermelho
 *   php scripts/_testar_imagem_viral.php <url-imagem> --dor=urgencia    # usa preset
 */

require_once __DIR__ . '/../lib/DiscoverImagemViral.php';

$url = '';
$opcoes = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--tarja=')) $opcoes['tarja_texto'] = substr($a, 8);
    elseif (str_starts_with($a, '--cor='))  $opcoes['tarja_cor'] = substr($a, 6);
    elseif (str_starts_with($a, '--dor=')) {
        $preset = DiscoverImagemViral::tarjaPorDor(['dominante' => substr($a, 6)]);
        $opcoes = array_merge($opcoes, $preset);
    }
    elseif ($url === '') $url = $a;
}

if ($url === '') {
    fwrite(STDERR, "Uso: php scripts/_testar_imagem_viral.php <url> [--tarja=TEXTO --cor=COR | --dor=urgencia|medo|dinheiro|oportunidade]\n");
    exit(1);
}

echo "Processando: {$url}\n";
echo "Opções: " . json_encode($opcoes) . "\n";

$t0 = microtime(true);
$bytes = DiscoverImagemViral::processar($url, $opcoes);
$ms = round((microtime(true) - $t0) * 1000);

if ($bytes === null) {
    fwrite(STDERR, "✗ FALHOU (null retornado — confira URL/GD/conectividade)\n");
    exit(1);
}

$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imagem_viral_' . time() . '.webp';
file_put_contents($out, $bytes);

printf("✓ OK em %dms — %d bytes (%.1f KB)\n", $ms, strlen($bytes), strlen($bytes) / 1024);
echo "Salvo em: {$out}\n";
echo "\nAbra no Explorer pra visualizar e validar saturação/contraste/tarja.\n";
