<?php
/**
 * [TEMPORÁRIO — pode apagar depois] Empacota plugin wp-web-stories-ai em ZIP versionado.
 *
 * Uso: php scripts/_empacotar_plugin_webstory.php [versao]
 *   versao default: lê de WP_WSAI_VERSION no entry point
 */

$src = dirname(__DIR__) . '/wp-content/plugins/wp-web-stories-ai';
if (!is_dir($src)) {
    fwrite(STDERR, "ERRO: $src não existe\n");
    exit(1);
}

// Extrai versão do entry point se não passada
$versao = $argv[1] ?? '';
if ($versao === '') {
    $entry = file_get_contents($src . '/wp-web-stories-ai.php');
    if (preg_match("/define\(\s*'WP_WSAI_VERSION',\s*'([^']+)'\s*\)/", $entry, $m)) {
        $versao = 'v' . $m[1];
    } else {
        $versao = 'v' . date('YmdHi');
    }
}

$dst = dirname(__DIR__) . "/wp-content/plugins/wp-web-stories-ai-{$versao}.zip";

if (file_exists($dst)) {
    echo "Sobrescrevendo {$dst}\n";
    unlink($dst);
}

$zip = new ZipArchive();
if ($zip->open($dst, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "ERRO: não consegui criar {$dst}\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
foreach ($it as $file) {
    $path = $file->getRealPath();
    $rel = 'wp-web-stories-ai/' . substr($path, strlen($src) + 1);
    $rel = str_replace('\\', '/', $rel);
    if ($file->isDir()) {
        $zip->addEmptyDir($rel);
    } else {
        $zip->addFile($path, $rel);
        $count++;
    }
}
$zip->close();

echo "✓ ZIP criado: {$dst}\n";
echo "  Arquivos: {$count}\n";
echo "  Tamanho:  " . round(filesize($dst) / 1024, 1) . " KB\n";

// Lista conteúdo pra verificação
echo "\nConteúdo:\n";
$zip = new ZipArchive();
$zip->open($dst);
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (substr($stat['name'], -1) === '/') continue; // diretório
    printf("  %-60s %5d B\n", $stat['name'], $stat['size']);
}
$zip->close();
