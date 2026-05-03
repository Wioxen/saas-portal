<?php
require_once __DIR__ . '/../lib/AntiAIValidator.php';

$samples = [
    'teaser_mas_tem_um_detalhe' => '<p>O Enem abre inscrições no dia 28.</p><p>Mas tem um detalhe.</p><p>Quem perde o prazo não consegue isenção.</p>',
    'teaser_spoiler' => '<p>O programa parece simples.</p><p>Spoiler: tem 3 critérios eliminatórios.</p>',
    'listas_3_perfeitas' => '<ul><li>A</li><li>B</li><li>C</li></ul><ul><li>X</li><li>Y</li><li>Z</li></ul><ul><li>1</li><li>2</li><li>3</li></ul>',
    'self_reference' => '<p>O programa abre 500 vagas. Veja a seguir como participar.</p><p>Confira abaixo os critérios.</p>',
    'densidade_conector' => '<p>Nesse contexto, a Sedu abre vagas.</p><p>Nesse contexto, o programa cresce.</p><p>Nesse contexto, há mais oportunidades.</p>',
    'limpo_humano' => '<p>O Enem abre inscrições no dia 28. Quem mora longe da capital tem 2 dias a mais.</p><p>O edital traz 3 mudanças importantes pra 2026.</p>',
];

foreach ($samples as $nome => $html) {
    echo "─── {$nome} ───\n";
    $r = (new AntiAIValidator())->validate($html);
    echo "  severity: {$r['severity']} | violations: {$r['total_phrase_violations']} | structural: " . count($r['structural']) . "\n";
    foreach (array_slice($r['violations'], 0, 3) as $v) echo "  · {$v['category']}: '{$v['phrase']}' x{$v['count']}\n";
    foreach (array_slice($r['structural'], 0, 3) as $s) echo "  · structural: {$s}\n";
    echo "\n";
}
