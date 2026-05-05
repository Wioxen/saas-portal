<?php
declare(strict_types=1);

// Simula a entrada CRUA do Sonnet: JSON com newlines reais dentro de strings (inválido)
$json_invalido = "{\"html\":\"<p>oi</p>\n\n<h3>como?</h3>\"}";

echo "INPUT bytes: " . bin2hex($json_invalido) . "\n";
echo "  (0a 0a entre </p> e <h3> = newline real)\n\n";

$j1 = json_decode($json_invalido, true);
echo "Tentativa 1 (direto): " . ($j1 ? "OK" : "FAIL: " . json_last_error_msg()) . "\n";

// Camada 2 do fixJsonString: regex callback
$fixed = preg_replace_callback(
    '/"((?:[^"\\\\]|\\\\.)*)"/s',
    function ($m) {
        $inner = $m[1];
        $inner = str_replace("\r\n", "\\n", $inner);
        $inner = str_replace("\r", "\\n", $inner);
        $inner = str_replace("\n", "\\n", $inner);
        $inner = str_replace("\t", "\\t", $inner);
        return '"' . $inner . '"';
    },
    $json_invalido
);
echo "\nApós Camada 2 (regex):\n  $fixed\n";
echo "  bytes: " . bin2hex($fixed) . "\n";
$j2 = json_decode($fixed, true);
echo "Tentativa 2 (regex): " . ($j2 ? "OK" : "FAIL: " . json_last_error_msg()) . "\n";
if ($j2) {
    echo "  html bytes: " . bin2hex($j2['html']) . "\n";
    echo "  html visual: '" . $j2['html'] . "'\n";
    echo "  contém \\n literal? " . (strpos($j2['html'], '\\n') !== false ? "SIM (BUG)" : "não") . "\n";
}

// Camada 3: bruto
$bruto = str_replace(["\r\n", "\r", "\n", "\t"], ["\\n", "\\n", "\\n", "\\t"], $json_invalido);
echo "\nApós Camada 3 (bruto):\n  $bruto\n";
$j3 = json_decode($bruto, true);
echo "Tentativa 3 (bruto): " . ($j3 ? "OK" : "FAIL") . "\n";
if ($j3) {
    echo "  html bytes: " . bin2hex($j3['html']) . "\n";
    echo "  contém \\n literal? " . (strpos($j3['html'], '\\n') !== false ? "SIM (BUG)" : "não") . "\n";
}

// Agora simula situação adversária: JSON tem `\\n` literal NO SOURCE (Sonnet escapou demais)
echo "\n\n═══ CENÁRIO 2: Sonnet retornou \\\\n DUPLO no source ═══\n";
$json_double = '{"html":"<p>oi</p>\\\\n\\\\n<h3>como?</h3>"}';
echo "INPUT: $json_double\n";
echo "  bytes: " . bin2hex($json_double) . "\n";
$j4 = json_decode($json_double, true);
if ($j4) {
    echo "  html bytes: " . bin2hex($j4['html']) . "\n";
    echo "  contém \\n literal? " . (strpos($j4['html'], '\\n') !== false ? "SIM (BUG)" : "não") . "\n";
}
