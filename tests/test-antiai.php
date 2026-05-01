<?php
require __DIR__ . '/../lib/AntiAIValidator.php';

$cases = [
    'caso_violador' => '<h1>Teste</h1><h2>Como funciona</h2><p>Vale destacar que o INSS abriu inscrições. Diante disso, é importante destacar o prazo. Em suma, nesse contexto, todos podem participar.</p><h2>Como aplicar</h2><p>Além disso, dessa forma, portanto, o cadastro é simples. Além disso, vale lembrar.</p><h2>Como acessar</h2><p>Curto.</p><h2>Como cadastrar</h2><p>Médio aqui que tem mais palavras pra simular um padrão real.</p>',

    'caso_limpo' => '<h1>Teste limpo</h1><p>O INSS liberou 15 vagas em São Paulo nesta quinta. O prazo fecha sexta.</p><p class="resposta-direta">São 15 vagas pra Auxiliar Administrativo, com salário de R$ 2.450, e inscrições até 30/04 pelo Meu INSS.</p><h2>Quem pode participar</h2><p>Cidadãos com ensino médio. A inscrição é grátis.</p><h2>Como acessar o cadastro</h2><p>Pelo aplicativo do Meu INSS ou no site oficial. O processo leva 5 minutos.</p>',
];

$v = new AntiAIValidator();
foreach ($cases as $label => $html) {
    echo "==== {$label} ====\n";
    $r = $v->validate($html);
    echo "OK: " . ($r['ok'] ? 'true' : 'false') . "\n";
    echo "Severity: {$r['severity']}\n";
    echo "Total violations: {$r['total_phrase_violations']}\n";
    if (!empty($r['violations'])) {
        echo "Phrases:\n";
        foreach ($r['violations'] as $viol) echo "  - \"{$viol['phrase']}\" x{$viol['count']} ({$viol['category']})\n";
    }
    if (!empty($r['structural'])) {
        echo "Structural:\n";
        foreach ($r['structural'] as $s) echo "  - {$s}\n";
    }
    echo "\nLog line: " . $v->reportToLogLine($r) . "\n\n";
}
