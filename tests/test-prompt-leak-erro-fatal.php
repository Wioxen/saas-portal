<?php
/**
 * test-prompt-leak-erro-fatal.php — valida detector de prompt-leak da seção "Erro Fatal".
 *
 * Casos cobertos:
 *  - H2 do post real Sejuv Campo Grande (caso reportado 2026-05-03)
 *  - alerta-critico__titulo com frase do exemplo do prompt
 *  - H2 LEGÍTIMO (com qualificador) NÃO deve ser flaggado
 *  - severity=fail forçada quando prompt-leak presente
 *
 * Run: php tests/test-prompt-leak-erro-fatal.php
 */

require_once __DIR__ . '/../lib/AntiAIValidator.php';

$v = new AntiAIValidator();

$casos = [
    'caso_real_campo_grande' => [
        'html' => '<p>Curso grátis em Campo Grande.</p>'
                . '<h2>O erro que elimina a inscrição para os dias noturnos em Campo Grande</h2>'
                . '<p>Tem gente que perde a vaga.</p>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'prompt-leak-erro-fatal',
    ],
    'caso_alerta_critico_template' => [
        'html' => '<h2>Inscrições do Fies abrem em maio</h2>'
                . '<div class="alerta-critico"><p class="alerta-critico__titulo">Erro que derruba a inscrição</p>'
                . '<p class="alerta-critico__texto">Sem CadÚnico atualizado.</p></div>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'prompt-leak-alerta-critico',
    ],
    'caso_filtro_sem_qualificador' => [
        'html' => '<h2>O filtro que barra a inscrição da maioria</h2><p>Texto.</p>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'prompt-leak-erro-fatal',
    ],
    'caso_legitimo_qualificado' => [
        /* H2 nomeia o critério REAL — passa pelo detector de prompt-leak.
         * Pode ainda ser flagado por outros detectores; aqui só verifico que
         * o prompt-leak NÃO acionou. */
        'html' => '<h2>Comprovação de renda no CadÚnico reprova 18% das inscrições do Fies em 2026</h2>'
                . '<p>Segundo o MEC, o cruzamento de dados eliminou 31 mil candidatos no último ciclo.</p>',
        'esperado_severity' => 'ok_ou_warn',
        'esperado_nao_contem' => 'prompt-leak',
    ],
    'caso_legitimo_factual_curso' => [
        'html' => '<h2>4 dias noturnos em Campo Grande garantem certificado técnico de 20 horas</h2>'
                . '<p>Vagas por ordem de chegada. Cadastro abre 4 de maio.</p>',
        'esperado_severity' => 'ok_ou_warn',
        'esperado_nao_contem' => 'prompt-leak',
    ],
];

$pass = 0;
$fail = 0;
foreach ($casos as $nome => $c) {
    $report = $v->validate($c['html']);
    $sev = $report['severity'];
    $struct = $report['structural'] ?? [];
    $allIssues = is_array($struct) ? implode(' || ', $struct) : '';

    $okSev = false;
    if ($c['esperado_severity'] === 'ok_ou_warn') {
        $okSev = in_array($sev, ['ok', 'warn'], true);
    } else {
        $okSev = ($sev === $c['esperado_severity']);
    }

    $okCon = true;
    if (!empty($c['esperado_contem'])) {
        $okCon = (stripos($allIssues, $c['esperado_contem']) !== false);
    }
    if (!empty($c['esperado_nao_contem'])) {
        $okCon = (stripos($allIssues, $c['esperado_nao_contem']) === false);
    }

    $status = ($okSev && $okCon) ? 'PASS' : 'FAIL';
    if ($status === 'PASS') $pass++; else $fail++;
    echo "[{$status}] {$nome} — sev={$sev}";
    if (!$okSev) echo " (esperado {$c['esperado_severity']})";
    if (!$okCon) echo " (issues=" . substr($allIssues, 0, 200) . ")";
    echo PHP_EOL;
}

echo PHP_EOL;
echo "Resumo: {$pass} pass, {$fail} fail" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
