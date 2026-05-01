<?php
/**
 * Script one-shot para limpar instalações duplicadas do wp-web-stories-ai.
 * Uso: upload na raiz do WP e acessar via navegador → lista pastas → deleta todas.
 * APAGAR ESTE ARQUIVO DEPOIS DE USAR.
 */
$base = __DIR__ . '/wp-content/plugins/';
if (!is_dir($base)) die('Não é raiz do WP. Coloque este arquivo na mesma pasta do wp-config.php');

$pastas = glob($base . 'wp-web-stories-ai*', GLOB_ONLYDIR);
echo '<h2>Pastas encontradas:</h2><ul>';
foreach ($pastas as $p) echo '<li>' . htmlspecialchars(basename($p)) . '</li>';
echo '</ul>';

if (!isset($_GET['confirm'])) {
    echo '<p><a href="?confirm=1" style="background:#c00;color:#fff;padding:10px 20px;text-decoration:none">APAGAR TODAS</a></p>';
    exit;
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $dir . '/' . $f;
        if (is_dir($full)) rrmdir($full); else @unlink($full);
    }
    @rmdir($dir);
}

foreach ($pastas as $p) {
    rrmdir($p);
    echo '<p>✅ Apagado: ' . htmlspecialchars(basename($p)) . '</p>';
}
echo '<p><strong>APAGUE ESTE ARQUIVO (limpar-wsai.php) DO SERVIDOR AGORA POR SEGURANÇA.</strong></p>';
