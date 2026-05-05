<?php
declare(strict_types=1);

$h = file_get_contents(__DIR__ . '/4995_raw.html');
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Bateria 7 checks · post #4995\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

// Check 1: strong-perguntas no corpo
$strongP = preg_match_all('#<strong[^>]*>[^<]*\?</strong>#i', $h, $m1);
echo "\n[1] strong-perguntas no corpo: $strongP " . ($strongP === 0 ? '✓' : '✗ BUG #4982') . "\n";
if ($strongP > 0) foreach ($m1[0] as $s) echo "    " . $s . "\n";

// Check 2: h3-perguntas no corpo
$h3P = preg_match_all('#<h3[^>]*>[^<]*\?</h3>#i', $h, $m2);
echo "\n[2] h3-perguntas no corpo: $h3P " . ($h3P === 0 ? '✓' : '✗') . "\n";
if ($h3P > 0) foreach ($m2[0] as $s) echo "    " . $s . "\n";

// Check 3: <a> sem href / vazio / # / javascript
$total_a = preg_match_all('#<a\s[^>]*>#i', $h, $ma);
$com_href = 0;
$invalidos = [];
foreach ($ma[0] as $tag) {
    if (preg_match('/href\s*=\s*[\'"]([^\'"]*)[\'"]/i', $tag, $hm)) {
        $h_val = trim($hm[1]);
        if ($h_val === '' || $h_val === '#' || stripos($h_val, 'javascript:') === 0) {
            $invalidos[] = $tag;
        } else {
            $com_href++;
        }
    } else {
        $invalidos[] = $tag;
    }
}
echo "\n[3] <a> total=$total_a · href válidos=$com_href · inválidos=" . count($invalidos) . " " . (empty($invalidos) ? '✓' : '✗') . "\n";
foreach ($invalidos as $iv) echo "    INVÁLIDO: " . substr($iv, 0, 100) . "\n";

// Check 4: URLs em texto puro (fora de <a>)
// Mascara <a>...</a> e procura URL
$semA = preg_replace('#<a\b[^>]*>[\s\S]*?</a>#i', '', $h) ?? $h;
$semCode = preg_replace('#<(code|script|style)\b[^>]*>[\s\S]*?</\1>#i', '', $semA) ?? $semA;
$semAviso = preg_replace('#<li[^>]*>[\s\S]{0,30}?\[(?:url_path_alucinado|nome_alucinado|fato_nao_confirmado)\][\s\S]*?</li>#i', '', $semCode) ?? $semCode;
$urls_texto = 0;
if (preg_match_all('#(?<![\'"=>/])(https?://[a-z0-9.-]+(?:/[^\s<>"\']*)?)#iu', $semAviso, $mu)) $urls_texto += count($mu[0]);
if (preg_match_all('#(?<![\'"=>/@\w-])([a-z0-9-]+\.(?:com\.br|gov\.br|edu\.br|com|net|org)/[a-z0-9/_.\-]+)#iu', $semAviso, $mu2)) $urls_texto += count($mu2[0]);
echo "\n[4] URLs em texto puro (fora de <a>, ignora avisos): $urls_texto " . ($urls_texto === 0 ? '✓' : '⚠ revisar') . "\n";

// Check 5: details vs perguntas
$details = preg_match_all('#<details\b#i', $h);
$summary_perg = preg_match_all('#<summary[^>]*>[^<]*\?#i', $h);
echo "\n[5] <details>=$details · <summary> com '?'=$summary_perg " . ($details > 0 && $summary_perg > 0 ? '✓' : '⚠') . "\n";

// Check 6: gov.br genérico vs específicos
$gov_generico = preg_match_all('#href=[\'"]https?://(?:www\.)?gov\.br/?[\'"]#i', $h);
$gov_especifico = preg_match_all('#href=[\'"]https?://(?:www\.)?(?:[a-z0-9-]+\.)?(?:gov\.br/[a-z0-9-]+|inep|mec|capes|cnpq|caixa|inss)#i', $h);
echo "\n[6] gov.br genérico=$gov_generico · gov.br específico=$gov_especifico " . ($gov_generico === 0 ? '✓' : '✗') . "\n";

// Check 7: aviso editorial (alucinado/fidelity)
$aviso_bloco = preg_match('#\[(?:url_path|nome)_alucinado\]|cc-fidelity|fato_nao_confirmado#i', $h);
echo "\n[7] Aviso editorial (alucinado/fidelity) presente: " . ($aviso_bloco ? "SIM (post em draft)" : "não") . "\n";

// Summary geral
echo "\n═══ ESTRUTURA ═══\n";
$h2s = preg_match_all('#<h2[^>]*>(.*?)</h2>#is', $h, $hm);
echo "  H2 count: $h2s\n";
foreach ($hm[1] as $i => $h2) {
    $txt = trim(strip_tags(html_entity_decode($h2, ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($txt) > 80) $txt = mb_substr($txt, 0, 77) . '...';
    echo "    [" . ($i+1) . "] " . $txt . "\n";
}

echo "\n═══ <details> SUMMARIES ═══\n";
if (preg_match_all('#<details\b[^>]*>\s*<summary[^>]*>(.+?)</summary>#is', $h, $sm)) {
    foreach ($sm[1] as $i => $s) {
        $txt = trim(strip_tags(html_entity_decode($s, ENT_QUOTES, 'UTF-8')));
        echo "  [" . ($i+1) . "] " . $txt . "\n";
    }
}

// Word count
$txt_puro = strip_tags($h);
$palavras = str_word_count($txt_puro);
echo "\n═══ MÉTRICAS ═══\n";
echo "  Palavras (estimativa): " . $palavras . "\n";
echo "  Tamanho HTML: " . strlen($h) . " bytes\n";
