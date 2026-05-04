<?php
/**
 * test-intro-inflada.php — valida detector de intro inflada (>3 <p> antes do 1º H2).
 *
 * Casos:
 *  - 5 parágrafos = fail (caso reportado)
 *  - 4 parágrafos = warn
 *  - 3 parágrafos legítimos = ok (não regride)
 *  - 3 parágrafos + resposta-direta + snippet = ok (ORDEM FIXA correta)
 *  - Redundância P2↔P4 (paráfrase) = fail
 *
 * Run: php tests/test-intro-inflada.php
 */

require_once __DIR__ . '/../lib/AntiAIValidator.php';

$v = new AntiAIValidator();

$casos = [
    'caso_5_paragrafos_fail' => [
        'html' => '<p>O Senac-ES abriu quinze vagas gratuitas no curso técnico de Administração em Pinheiros nesta quinta-feira de manhã.</p>'
                . '<p>A direção regional informou que renda familiar abaixo dois salários mínimos comprovada pelo CadÚnico tem prioridade.</p>'
                . '<p>O recorte derruba inscritos sem cadastro atualizado, padrão observado também na turma anterior segundo levantamento.</p>'
                . '<p>Fundação Cesgranrio não participa desta seleção; processo é interno do Senac com banca própria.</p>'
                . '<p>Município reúne maior concentração de estudantes técnicos da região serrana norte capixaba neste ano.</p>'
                . '<h2>Primeiro H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'intro-inflada-forca-regen',
    ],
    'caso_4_paragrafos_fail' => [
        /* Caso real post 2102 (Senai 10 mil vagas): 4 parágrafos antes do 1º H2 com paráfrase
         * entre P1 e resposta-direta. Threshold subiu de 4=warn pra 4=fail force-regen porque
         * Sonnet com warn não regen e o post saía com lead diluído. */
        'html' => '<p>O Senac-ES abriu quinze vagas gratuitas no curso técnico de Administração em Pinheiros nesta quinta-feira.</p>'
                . '<p>A direção regional informou que renda familiar baixa comprovada pelo CadÚnico recebe prioridade.</p>'
                . '<p>O recorte derruba inscritos sem cadastro atualizado, padrão observado também na turma anterior.</p>'
                . '<p>Fundação Cesgranrio não participa desta seleção; processo é interno do Senac com banca própria.</p>'
                . '<h2>Primeiro H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'intro-inflada-forca-regen',
    ],
    'caso_3_paragrafos_ok' => [
        'html' => '<p>O Senac-ES abriu quinze vagas gratuitas no curso técnico de Administração em Pinheiros nesta quinta-feira.</p>'
                . '<p>A direção regional informou que renda familiar baixa comprovada pelo CadÚnico recebe prioridade na triagem.</p>'
                . '<p>O recorte derruba inscritos sem cadastro atualizado, padrão observado também na turma anterior segundo balanço.</p>'
                . '<h2>Primeiro H2</h2>',
        'esperado_severity'   => 'ok',
        'esperado_nao_contem' => 'intro-inflada',
    ],
    'caso_3p_snippet_h2_ok' => [
        /* ORDEM FIXA NOVA (2026-05-04): P1, P2, P3, snippet, H2 (RD vai pro fechamento) */
        'html' => '<p>O Senac-ES abriu quinze vagas gratuitas no curso técnico de Administração em Pinheiros nesta quinta-feira.</p>'
                . '<p>A direção regional informou que renda familiar baixa comprovada pelo CadÚnico recebe prioridade na triagem.</p>'
                . '<p>O recorte derruba inscritos sem cadastro atualizado, padrão observado também na turma anterior segundo balanço.</p>'
                . '<ul class="snippet-resumo"><li>bullet 1</li><li>bullet 2</li></ul>'
                . '<h2>Primeiro H2</h2>'
                . '<p>Conteúdo do corpo aqui.</p>'
                . '<p>Mais corpo.</p>'
                . "<p class='resposta-direta'>Senac-ES abriu 15 vagas em Pinheiros até 30 de abril pelo site oficial.</p>"
                . "<p style='font-size:13px;color:#666'>Fonte: site.com.br</p>",
        'esperado_severity'   => 'ok',
        'esperado_nao_contem' => 'intro-inflada',
    ],
    'caso_rd_na_intro_fail' => [
        /* RD ainda na posição antiga (antes do 1º H2) deve disparar rd-na-intro-forca-regen */
        'html' => '<p>P1 com vinte palavras pra forçar contagem suficiente no detector.</p>'
                . '<p>P2 com vinte palavras pra forçar contagem suficiente também aqui.</p>'
                . '<p>P3 com vinte palavras pra forçar contagem suficiente também aqui terceiro.</p>'
                . "<p class='resposta-direta'>RD ainda na intro deve disparar erro novo.</p>"
                . '<h2>H2</h2>',
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'rd-na-intro',
    ],
    'caso_p1_paraphrasea_resposta_direta' => [
        /* Caso real post 2102: P1 e resposta-direta começam idênticos
         * ("O Senai abriu 10 mil vagas em cursos técnicos gratuitos com auxílio de R$ 700") */
        'html' => "<p>O Senai abriu 10 mil vagas em cursos técnicos gratuitos com auxílio de R\$ 700 por mês, e boa parte dos candidatos nem chega à etapa de seleção.</p>"
                . "<p>O motivo não é falta de interesse: três critérios pouco divulgados eliminam inscrições antes da análise começar.</p>"
                . "<p>Documentação incompleta, perfil fora da faixa etária e cadastro divergente são os pontos que derrubam candidatos aptos nas triagens iniciais.</p>"
                . "<p class='resposta-direta'>O Senai abriu 10 mil vagas em cursos técnicos gratuitos com auxílio de R\$ 700 mensais, com inscrições pelo portal oficial.</p>"
                . "<h2>H2</h2>",
        'esperado_severity' => 'fail',
        'esperado_contem'   => 'redundancia-p1-resposta-direta',
    ],
    'caso_redundancia_p2_p4' => [
        /* P2 e P4 dizem a mesma coisa com palavras diferentes — bigrams compartilhados altos */
        'html' => '<p>O boato sobre concurso da Petrobras circulou em grupos de WhatsApp neste fim de semana.</p>'
                . '<p>A Petrobras informou que não há concurso aberto, edital publicado nem previsão lançamento.</p>'
                . '<p>A negativa veio em nota oficial da estatal divulgada à imprensa nesta segunda.</p>'
                . '<p>A Petrobras confirma que não há concurso aberto, edital publicado nem previsão lançamento.</p>'
                . '<h2>H2</h2>',
        'esperado_severity'   => 'fail',
        'esperado_contem'     => 'intro-redundancia',
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
