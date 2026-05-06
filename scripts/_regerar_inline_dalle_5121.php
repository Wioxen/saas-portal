<?php
/**
 * Regenera imagem inline DALL-E retroativa pro post #5121
 * Usado pra validar prompt DALL-E inline novo (jornalístico realista PT-BR).
 */
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/InlineImageInjector.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/OpenAI.php';

aplicarSite($cfg, sitesDisponiveis(), 'cursosenac');
$base = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2';
$auth = base64_encode($cfg['wp_user'] . ':' . $cfg['wp_app_password']);

// Pega post atual
$ch = curl_init($base . '/posts/5121?_fields=content,title&context=edit');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
    CURLOPT_TIMEOUT => 30,
]);
$post = json_decode((string)curl_exec($ch), true);
curl_close($ch);
$content = (string)($post['content']['rendered'] ?? '');
$titulo = (string)($post['title']['rendered'] ?? '');

// Força DALL-E inline
putenv('IMAGEM_INLINE_FORCE_DALLE=1');
$_ENV['IMAGEM_INLINE_FORCE_DALLE'] = '1';

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$urlsFontes = [
    'https://www.gov.br/mec/pt-br/assuntos/noticias/2026',
    'https://www.gov.br',
];
echo "Rodando InlineImageInjector com forceDalle=1...\n";
$iiRes = InlineImageInjector::injetar($content, $urlsFontes, $wp, 1, $titulo, $cfg);
echo "Log: " . json_encode($iiRes['log'], JSON_UNESCAPED_UNICODE) . "\n";

if (($iiRes['log']['inseridas'] ?? 0) > 0) {
    $ch = curl_init($base . '/posts/5121');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['content' => $iiRes['html']]),
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_exec($ch);
    echo "WP HTTP: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
    curl_close($ch);
} else {
    echo "Nenhuma imagem inserida.\n";
}
