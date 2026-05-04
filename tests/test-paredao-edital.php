<?php
/**
 * test-paredao-edital.php — valida detectores paragrafo-paredao + tom-edital.
 *
 * Casos:
 *  - P1 com frase de 35 palavras (paredão) — fail force-regen
 *  - P1 com 2 frases de 18 palavras cada (curtas) — passa
 *  - "Segundo o edital divulgado pela MRS" — fail force-regen tom-edital
 *  - "Recomenda-se que" — fail
 *  - Tom guia amigo ("pela divulgação oficial", "vale juntar") — passa
 */
require_once __DIR__ . '/../lib/AntiAIValidator.php';

$v = new AntiAIValidator();

$casos = [
    'paredao_p1_35_palavras' => [
        'html' => '<p>Senai Autonomia Renda abriu cinco mil vagas gratuitas em qualificação industrial com bolsa mensal de setecentos reais voltada pra pessoas interessadas em atuar na indústria de óleo gás química e construção civil em diferentes regiões do país pelo edital deste ano.</p>'
                . '<p>Segundo o programa, são 14 ocupações disponíveis nas regiões.</p>'
                . '<p>O processo de inscrição é online pelo portal oficial até maio.</p>'
                . '<h2>H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'paragrafo-paredao',
    ],
    'frases_curtas_passa' => [
        'html' => '<p>Senai Autonomia Renda abre cinco mil vagas pagando setecentos reais por mês.</p>'
                . '<p>O programa cobre 14 ocupações da indústria pesada e construção civil.</p>'
                . '<p>Antes de correr pro site, vale conferir qual vaga é a mais acessível.</p>'
                . '<h2>H2</h2>',
        'esperado_severity'   => 'ok',
        'esperado_nao_contem' => 'paragrafo-paredao',
    ],
    'tom_edital_segundo_o_edital' => [
        'html' => '<p>O Senai abriu inscrições para curso técnico em Belo Horizonte.</p>'
                . '<p>Segundo o edital divulgado pela coordenação, os candidatos deverão preencher o formulário até maio.</p>'
                . '<p>O processo seletivo cobre cinco etapas.</p>'
                . '<h2>H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'tom-edital',
    ],
    'tom_edital_recomenda_se' => [
        'html' => '<p>O Senai abriu inscrições para curso técnico industrial em maio.</p>'
                . '<p>Recomenda-se que o aluno prepare documentação antes da etapa.</p>'
                . '<p>O programa paga bolsa mensal de R$ 700 ao aprovado.</p>'
                . '<h2>H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'tom-edital',
    ],
    'tom_guia_amigo_passa' => [
        'html' => '<p>O Senai abriu inscrições para curso técnico em Belo Horizonte com bolsa de setecentos reais.</p>'
                . '<p>Pela divulgação oficial, vale juntar documento de identidade e comprovante de residência antes da etapa de testes.</p>'
                . '<p>São cinco filtros sequenciais e o terceiro costuma travar quem não preparou bem.</p>'
                . '<h2>H2</h2>',
        'esperado_severity'   => 'ok',
        'esperado_nao_contem' => 'tom-edital',
    ],
];

$pass = 0; $fail = 0;
foreach ($casos as $nome => $c) {
    $r = $v->validate($c['html']);
    $sev = $r['severity'];
    $struct = $r['structural'] ?? [];
    $allIssues = implode(' || ', $struct);

    $okSev = ($sev === $c['esperado_severity']);
    $okCon = true;
    if (!empty($c['esperado_contem']))     $okCon = (stripos($allIssues, $c['esperado_contem']) !== false);
    if (!empty($c['esperado_nao_contem']) && $okCon) $okCon = (stripos($allIssues, $c['esperado_nao_contem']) === false);

    $status = ($okSev && $okCon) ? 'PASS' : 'FAIL';
    if ($status === 'PASS') $pass++; else $fail++;
    echo "[{$status}] {$nome} — sev={$sev}";
    if (!$okSev) echo " (esperado {$c['esperado_severity']})";
    if (!$okCon) echo " (issues=" . substr($allIssues, 0, 200) . ")";
    echo PHP_EOL;
}

echo PHP_EOL . "Resumo: {$pass} pass, {$fail} fail" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
