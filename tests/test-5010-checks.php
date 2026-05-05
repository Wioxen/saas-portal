<?php
declare(strict_types=1);
$h = file_get_contents(__DIR__ . '/5010_raw.html');
echo "═══ Bateria · post #5010 ═══\n";

$strongP = preg_match_all('#<strong[^>]*>[^<]*\?</strong>#i', $h);
echo "[1] strong-perguntas: $strongP " . ($strongP === 0 ? '✓' : '✗') . "\n";

$h3P = preg_match_all('#<h3[^>]*>[^<]*\?</h3>#i', $h, $m2);
echo "[2] h3-perguntas: $h3P " . ($h3P === 0 ? '✓' : '✗') . "\n";
if ($h3P > 0) foreach ($m2[0] as $s) echo "    " . $s . "\n";

$h2P = preg_match_all('#<h2[^>]*>[^<]*\?</h2>#i', $h, $m22);
echo "[2b] h2-perguntas: $h2P " . ($h2P === 0 ? '✓' : '⚠') . "\n";
if ($h2P > 0) foreach ($m22[0] as $s) echo "    " . trim(strip_tags($s)) . "\n";

$total_a = preg_match_all('#<a\s[^>]*>#i', $h, $ma);
$invalidos = 0;
foreach ($ma[0] as $tag) {
    if (!preg_match('/href\s*=/i', $tag)) { $invalidos++; continue; }
    if (preg_match('/href\s*=\s*[\'"]([^\'"]*)[\'"]/i', $tag, $hm)) {
        $v = trim($hm[1]);
        if ($v === '' || $v === '#' || stripos($v, 'javascript:') === 0) $invalidos++;
    }
}
echo "[3] <a> total=$total_a, inválidos=$invalidos " . ($invalidos === 0 ? '✓' : '✗') . "\n";

// URLs em texto puro (fora de <a>)
$semA = preg_replace('#<a\b[^>]*>[\s\S]*?</a>#i', '', $h) ?? $h;
$semCode = preg_replace('#<(code|script|style)\b[^>]*>[\s\S]*?</\1>#i', '', $semA) ?? $semA;
$urls_texto = 0;
if (preg_match_all('#(?<![\'"=>/])(https?://[a-z0-9.-]+)#iu', $semCode, $mu)) $urls_texto += count($mu[0]);
echo "[4] URLs em texto puro: $urls_texto " . ($urls_texto === 0 ? '✓' : '⚠') . "\n";

$details = preg_match_all('#<details\b#i', $h);
$summary_perg = preg_match_all('#<summary[^>]*>[^<]*\?#i', $h);
echo "[5] details=$details · summaries com '?'=$summary_perg ✓\n";

$gov_g = preg_match_all('#href=[\'"]https?://(?:www\.)?gov\.br/?[\'"]#i', $h);
$gov_e = preg_match_all('#href=[\'"]https?://(?:www\.)?(?:[a-z0-9-]+\.)?gov\.br/[a-z0-9-]+#i', $h);
echo "[6] gov.br: genérico=$gov_g, específico=$gov_e " . ($gov_g === 0 ? '✓' : '✗') . "\n";

$howto = preg_match('#"@type"\s*:\s*"HowTo"#i', $h);
echo "[7] HowTo schema: " . ($howto ? 'SIM ✓' : 'não — ' . (preg_match('#<h2[^>]*>[^<]*[Pp]asso a passo#i', $h) ? 'tem H2 mas não casou' : 'não tem H2 tutorial')) . "\n";

$internal = preg_match_all('#data-internal-link=[\'"]1[\'"]#i', $h);
echo "[8] Backlinks internos (data-internal-link=1): $internal " . ($internal > 0 ? '✓' : '⚠') . "\n";
// Lista os internos
if (preg_match_all('#<a[^>]*data-internal-link=[\'"]1[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>([^<]+)</a>#i', $h, $im)) {
    foreach ($im[0] as $i => $tag) {
        echo "      [" . ($i+1) . "] " . $im[2][$i] . " → " . $im[1][$i] . "\n";
    }
}

$externos = 0;
if (preg_match_all('#href=[\'"]https?://([^\'"/]+)[^\'"]*[\'"]#i', $h, $em)) {
    foreach ($em[1] as $host) {
        if (!str_contains($host, 'cursosenacgratuito.com.br')) $externos++;
    }
}
echo "[9] Backlinks externos (não-cursosenac): $externos " . ($externos > 0 ? '✓' : '⚠') . "\n";

echo "\n═══ ESTRUTURA ═══\n";
$h2s = preg_match_all('#<h2[^>]*>(.*?)</h2>#is', $h, $hm);
echo "  H2 count: $h2s\n";
foreach ($hm[1] as $i => $h2) {
    $txt = trim(strip_tags(html_entity_decode($h2, ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($txt) > 80) $txt = mb_substr($txt, 0, 77) . '...';
    echo "    [" . ($i+1) . "] " . $txt . "\n";
}

echo "\n═══ HRefs únicos ═══\n";
if (preg_match_all('#href=[\'"]([^\'"]+)[\'"]#iu', $h, $am)) {
    foreach (array_unique($am[1]) as $u) echo "  - $u\n";
}

$txt_puro = strip_tags($h);
echo "\n═══ MÉTRICAS ═══\n";
echo "  Palavras: " . str_word_count($txt_puro) . "\n";
echo "  Tamanho HTML: " . strlen($h) . " bytes\n";
