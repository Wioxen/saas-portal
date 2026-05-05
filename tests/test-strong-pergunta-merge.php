<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/AntiAIPostProcessor.php';

$html = <<<'HTML'
<h1>Como comprimir PDF</h1>
<p>Intro do post.</p>

<h2>Sobre o tema</h2>
<p>Texto.</p>

<p><strong>É seguro comprimir PDF online com documentos pessoais?</strong></p>
<p>Plataformas confiáveis usam HTTPS e excluem após 1h. Mas evite enviar dados sensíveis.</p>

<p><strong>Posso comprimir vários PDFs ao mesmo tempo?</strong></p>
<p>Smallpdf e WPS Office permitem batch processing.</p>

<h2>Perguntas frequentes</h2>
<details><summary>Como otimizar PDFs sem perder qualidade?</summary><p>Use compressão moderada.</p></details>
<details><summary>É seguro comprimir PDF com documentos pessoais em sites online?</summary><p>Use HTTPS.</p></details>

<p>Texto final com <a href="https://example.com">link valido</a> e <a>link sem href</a> e <a href="">vazio</a> e <a href="#">hash</a> e <a href="javascript:void(0)">js</a>.</p>
HTML;

$r = AntiAIPostProcessor::limpar($html, 'Como comprimir PDF');

echo "=== HTML LIMPO ===\n";
echo $r['html'] . "\n\n";

echo "=== LOG ===\n";
foreach ($r['log'] as $k => $v) {
    if (is_scalar($v)) echo "  $k = $v\n";
    elseif (is_array($v) && !empty($v)) echo "  $k = " . count($v) . " items\n";
}

echo "\n=== ASSERTS ===\n";
$out = $r['html'];
$strong_perg = preg_match_all('#<strong>[^<]*\?</strong>#i', $out);
echo "  strong-perguntas remanescentes: $strong_perg (esperado 0)\n";

$a_sem_href = preg_match_all('#<a\b(?![^>]*href)[^>]*>#i', $out);
echo "  <a> sem href: $a_sem_href (esperado 0)\n";

$a_href_vazio = preg_match_all('#<a\s+[^>]*href=[\'"]\s*[\'"]#i', $out);
echo "  <a href='' vazio: $a_href_vazio (esperado 0)\n";

$a_href_hash = preg_match_all('#<a\s+[^>]*href=[\'"]\#[\'"]#i', $out);
echo "  <a href='#': $a_href_hash (esperado 0)\n";

$a_href_js = preg_match_all('#<a\s+[^>]*href=[\'"]javascript:#i', $out);
echo "  <a href='javascript:': $a_href_js (esperado 0)\n";

$a_validos = preg_match_all('#<a\s+[^>]*href=[\'"]https?://#i', $out);
echo "  <a> com URL valido: $a_validos (esperado 1, o link example.com)\n";

$details_count = preg_match_all('#<details\b#i', $out);
echo "  <details> count: $details_count (esperado >=2 — FAQ original + migracoes)\n";
