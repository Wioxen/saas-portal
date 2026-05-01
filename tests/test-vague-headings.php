<?php
/* Testa detecção de vague_promise em H1/H2/H3 individualmente */
require __DIR__ . '/../lib/AntiAIValidator.php';

$casos = [
    'caso_vago_h1' => '<h1>O filtro que está barrando inscrições na Semana S</h1><p>Texto curto.</p><h2>Como participar</h2><p>Detalhes aqui.</p>',
    'caso_vago_h2' => '<h1>Inscrições da Semana S 2026 abertas</h1><p>Texto.</p><h2>O erro que elimina candidatos</h2><p>Detalhes.</p>',
    'caso_vago_multiplo' => '<h1>O filtro que barra candidatos</h1><p>Texto.</p><h2>O erro que elimina inscritos</h2><p>Mais texto.</p><h3>Um detalhe que muda tudo</h3>',
    'caso_qualificado' => '<h1>Filtro de cargo da Polícia-RS barra candidatos sem CNH-D</h1><p>Texto.</p><h2>Erro no preenchimento do CPF elimina candidatos</h2><p>Mais texto.</p>',
    'caso_misto' => '<h1>Inscrições Semana S 2026 abertas</h1><h2>O filtro que está barrando</h2><h3>Erro de senha bloqueia acesso ao Portal Senac</h3>',
];

$v = new AntiAIValidator();
foreach ($casos as $label => $html) {
    echo "==== {$label} ====\n";
    $r = $v->validate($html);
    echo "OK: " . ($r['ok']?'true':'false') . " | severity={$r['severity']} | violações={$r['total_phrase_violations']} | structural=" . count($r['structural']) . "\n";
    if (!empty($r['structural'])) {
        echo "Issues estruturais detectadas:\n";
        foreach ($r['structural'] as $s) echo "  ✗ {$s}\n";
    }
    if (!empty($r['violations'])) {
        echo "Frases banidas:\n";
        foreach ($r['violations'] as $viol) echo "  ✗ \"{$viol['phrase']}\" x{$viol['count']} ({$viol['category']})\n";
    }
    echo "\n";
}
