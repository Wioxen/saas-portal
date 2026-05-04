<?php
/**
 * test-gatilho-batido.php — valida detector de gatilho-batido-discover.
 *
 * Casos cobertos:
 *  - "perde quem deixa pra última hora" — fail force-regen
 *  - "vagas voam" — fail
 *  - "última chamada" — fail
 *  - P1 com gatilho específico (CEP de BH) — passa
 *  - P1 com ocupação rara (operador hidrojato) — passa
 *
 * Run: php tests/test-gatilho-batido.php
 */

require_once __DIR__ . '/../lib/AntiAIValidator.php';

$v = new AntiAIValidator();

$casos = [
    'perde_quem_deixa_pra_ultima_hora' => [
        'html' => '<p>Senai Autonomia Renda abriu 5 mil vagas com bolsa de R$ 700, mas o prazo curto até 12 de maio elimina interessados que deixam pra última hora.</p>'
                . '<p>Segundo o programa, são 14 ocupações disponíveis em diferentes regiões do país.</p>'
                . '<p>O processo de inscrição é online, pelo portal autonomiaerenda.com.br.</p>'
                . '<h2>H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'gatilho-batido-discover',
    ],
    'vagas_voam' => [
        'html' => '<p>Programa abre 200 vagas em curso técnico, mas as vagas voam nas primeiras horas, segundo histórico.</p>'
                . '<p>O Senac informou que o processo dura 6 meses.</p>'
                . '<p>Inscrições pelo site oficial até 30 de maio.</p>'
                . '<h2>H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'gatilho-batido-discover',
    ],
    'ultima_chamada' => [
        'html' => '<p>Curso técnico do MEC tem vagas remanescentes e essa é a última chamada antes do encerramento do edital.</p>'
                . '<p>O programa cobre 5 estados do Sudeste com 1.200 bolsas integrais.</p>'
                . '<p>Cadastro pelo portal único do MEC ate sexta.</p>'
                . '<h2>H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'gatilho-batido-discover',
    ],
    'gatilho_especifico_ocupacao_rara' => [
        /* v2 do post #2126 — frases curtas (≤20 palavras), tom guia, ocupação rara como gatilho */
        'html' => '<p>São R$ 700 por mês no Senai Autonomia Renda pra quem topar aprender uma profissão da indústria pesada.</p>'
                . '<p>O catálogo de 2026 abre operador de hidrojato e pedreiro refratarista, funções raras em editais grátis brasileiros.</p>'
                . '<p>Antes de correr pro site, vale conferir qual vaga é a mais acessível pra começar.</p>'
                . '<h2>H2</h2>',
        'esperado_severity'   => 'ok',
        'esperado_nao_contem' => 'gatilho-batido',
    ],
    'gatilho_especifico_CEP' => [
        /* v5 do post #2125 — frases curtas, tom guia, CEP de BH como gatilho */
        'html' => '<p>Aprendiz MRS SENAI Horto pode receber até R$ 2.826 por mês na bolsa do programa.</p>'
                . '<p>O sistema do portal Eureca filtra automaticamente o CEP de Belo Horizonte na pré-inscrição.</p>'
                . '<p>Quem mora em Contagem ou Betim não passa dessa etapa, segundo a regra do edital.</p>'
                . '<h2>H2</h2>',
        'esperado_severity'   => 'ok',
        'esperado_nao_contem' => 'gatilho-batido',
    ],
];

$pass = 0;
$fail = 0;
foreach ($casos as $nome => $c) {
    $r = $v->validate($c['html']);
    $sev = $r['severity'];
    $struct = $r['structural'] ?? [];
    $allIssues = implode(' || ', $struct);

    $okSev = ($sev === $c['esperado_severity']);
    $okCon = true;
    if (!empty($c['esperado_contem'])) {
        $okCon = (stripos($allIssues, $c['esperado_contem']) !== false);
    }
    if (!empty($c['esperado_nao_contem']) && $okCon) {
        $okCon = (stripos($allIssues, $c['esperado_nao_contem']) === false);
    }

    $status = ($okSev && $okCon) ? 'PASS' : 'FAIL';
    if ($status === 'PASS') $pass++; else $fail++;
    echo "[{$status}] {$nome} — sev={$sev}";
    if (!$okSev) echo " (esperado {$c['esperado_severity']})";
    if (!$okCon) echo " (issues=" . substr($allIssues, 0, 200) . ")";
    echo PHP_EOL;
}

echo PHP_EOL . "Resumo: {$pass} pass, {$fail} fail" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
