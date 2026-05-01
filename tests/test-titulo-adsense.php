<?php
require __DIR__ . '/../lib/AntiAIValidator.php';

$casos = [
    /* Vague promise nos H1 (DEVEM FALHAR) */
    'O filtro que está barrando inscrições na Semana S em todo o país',
    'O erro que elimina candidatos antes da prova',
    'Um detalhe que muda tudo no edital',
    'Esse detalhe pode te eliminar',

    /* Versões qualificadas (DEVEM PASSAR) */
    'Filtro de cargo da Polícia-RS barra candidatos sem CNH-D',
    'Erro no preenchimento do CPF elimina candidatos antes da prova',
    'Cláusula 4.2 do edital de Araguaína muda quem garante a vaga',

    /* Originais do teste anterior */
    'O filtro de cargo que barra quem não leu o edital de Araguaína',
    'O que ninguém te conta sobre o concurso',
    'Você não vai acreditar nesse detalhe do edital',
    'O segredo da aprovação no concurso',
    'O que ninguém percebe',
    'Descubra agora antes que seja tarde',

    /* Títulos seguros sugeridos pelo Gemini (DEVEM PASSAR) */
    'Erro no edital de Araguaína elimina candidatos antes da prova',
    'Item 4.2 do edital pode eliminar candidatos em Araguaína',
    'A exigência de CNH categoria D que muitos candidatos de Araguaína ignoraram',
    'Concurso da Polícia Civil-RS abre 200 vagas com salário de R$ 8 mil',
    '70% dos candidatos perdem o Fies por não checar presença mínima',
    'MEC muda regra do Pé-de-Meia em maio de 2026',

    /* Edge cases */
    'Senac libera 15 vagas gratuitas em Pinheiros nesta quinta',
    'Curto',  /* muito curto */
    'Este título é tão exageradamente longo que claramente passa do limite máximo de 95 caracteres permitido pelo validador AdSense-safe',  /* muito longo */
];

$v = new AntiAIValidator();
foreach ($casos as $titulo) {
    $r = $v->validateTitle($titulo);
    $icon = $r['ok'] ? '✓' : ($r['severity']==='warn' ? '⚠' : '✗');
    echo "{$icon} [{$r['severity']}] " . str_pad("len={$r['length']}", 9) . " num=" . ($r['has_number']?'Y':'N') . " noun=" . ($r['has_proper_noun']?'Y':'N') . " | \"{$titulo}\"\n";
    foreach ($r['issues'] as $i) echo "      └─ [{$i['type']}] {$i['detail']}\n";
}
