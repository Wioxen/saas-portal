<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/AntiAIPostProcessor.php';

$h = file_get_contents(__DIR__ . '/4982_raw.html');
echo "ANTES:\n";
echo "  strong com ?: " . preg_match_all('#<strong[^>]*>[^<]*\?</strong>#i', $h) . "\n";
echo "  <details>: " . preg_match_all('#<details\b#i', $h) . "\n";
echo "  <a> total: " . preg_match_all('#<a\b#i', $h) . "\n";
$sem = preg_match_all('#<a\b#i', $h) - preg_match_all('#<a\s+[^>]*href=#i', $h);
echo "  <a> sem href: $sem\n";

$r = AntiAIPostProcessor::limpar($h, 'Como comprimir PDF');
$out = $r['html'];

echo "\nDEPOIS:\n";
echo "  strong com ?: " . preg_match_all('#<strong[^>]*>[^<]*\?</strong>#i', $out) . "\n";
echo "  <details>: " . preg_match_all('#<details\b#i', $out) . "\n";
$total_a_d = preg_match_all('#<a\b#i', $out);
$com_href_d = preg_match_all('#<a\s+[^>]*href=#i', $out);
echo "  <a> total: $total_a_d\n";
echo "  <a> sem href: " . ($total_a_d - $com_href_d) . "\n";

echo "\nLOG relevante:\n";
foreach (['strong_perguntas_movidas_pro_faq','links_sem_href_removidos','faq_h3_removidos','h3_perguntas_movidas_pro_faq','faq_perguntas_removidas'] as $k) {
    if (isset($r['log'][$k])) echo "  $k = " . $r['log'][$k] . "\n";
}

echo "\nSummaries dos details DEPOIS:\n";
if (preg_match_all('#<details\b[^>]*>\s*<summary[^>]*>(.+?)</summary>#is', $out, $sm)) {
    foreach ($sm[1] as $i => $s) echo "  [".($i+1)."] " . trim(strip_tags(html_entity_decode($s, ENT_QUOTES, 'UTF-8'))) . "\n";
}

file_put_contents(__DIR__ . '/4982_clean.html', $out);
echo "\nSalvo: tests/4982_clean.html (" . strlen($out) . " bytes)\n";
