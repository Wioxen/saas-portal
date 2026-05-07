<?php
/**
 * Gera 3 Concept Pages piloto no cursosenacgratuito.com.br:
 *   /conceito/curso-tecnico/
 *   /conceito/ead/
 *   /conceito/vestibular/
 *
 * Status: draft (user revisa antes de publicar).
 *
 * Uso:
 *   docker exec ... php /app/scripts/gerar_concept_pages_piloto.php
 *
 * Custo: ~$0.06 (3 sumários Sonnet × ~$0.02 cada).
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/EntityHubBuilder.php';

aplicarSite($cfg, sitesDisponiveis(), 'cursosenac');

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$sonnet = new Claude($cfg['anthropic_api_key'], 'claude-sonnet-4-6');
$builder = new EntityHubBuilder($wp, $sonnet, 'cursosenac');

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Concept Pages Piloto — cursosenacgratuito.com.br\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// 1. Garante Page parent /conceito/
echo "→ Criando/buscando Page parent /conceito/ ... ";
$parentExist = $wp->buscarPaginaPorSlug('conceito');
if ($parentExist && !empty($parentExist['id'])) {
    $parentId = (int)$parentExist['id'];
    echo "OK (id=$parentId, já existe)\n\n";
} else {
    $r = $wp->criarPagina([
        'title'   => 'Conceitos',
        'slug'    => 'conceito',
        'status'  => 'publish',
        'content' => '<p>Guias completos sobre conceitos e modalidades educacionais brasileiras: educação a distância, vestibular, cursos técnicos, bolsas de estudo e financiamento. Cada página agrega cobertura editorial recente, perguntas frequentes e fontes oficiais.</p>',
    ]);
    $parentId = (int)($r['id'] ?? 0);
    if ($parentId === 0) { echo "FAIL\n"; exit(1); }
    echo "CRIADO (id=$parentId)\n\n";
}

// 2. 3 conceitos piloto
$conceitos = [
    [
        'tipo'           => 'concept',
        'fullname'       => 'Curso Técnico',
        'slug'           => 'curso-tecnico',
        'aliases'        => ['cursos técnicos', 'técnico', 'ensino técnico', 'formação técnica', 'qualificação profissional', 'curso profissionalizante'],
        'descricao_seed' => 'Modalidade de educação profissional de nível médio regulamentada pela LDB e pelo Catálogo Nacional de Cursos Técnicos do MEC. Forma profissionais para o mercado em até 2 anos, com habilitação reconhecida nacionalmente. Pode ser cursado de forma integrada ao ensino médio, subsequente (após o médio) ou concomitante. Oferecido por institutos federais (IFs), Senac, Senai, Etecs, escolas estaduais e instituições privadas. Áreas como Informática, Enfermagem, Administração, Eletrotécnica, Segurança do Trabalho.',
    ],
    [
        'tipo'           => 'concept',
        'fullname'       => 'EAD (Educação a Distância)',
        'slug'           => 'ead',
        'aliases'        => ['EAD', 'ensino a distância', 'educação a distância', 'educação à distância', 'curso online', 'curso a distância', 'à distância'],
        'descricao_seed' => 'Modalidade educacional regulamentada pelo Decreto 9.057/2017 e pela Portaria MEC 2.117/2019. Permite o ensino mediado por tecnologias digitais, com tutoria, ambiente virtual de aprendizagem (AVA) e momentos presenciais obrigatórios em determinadas atividades. Reconhecida pelo MEC para cursos livres, técnicos, graduação tecnológica, bacharelados, licenciaturas e pós-graduação. Oferecida por universidades públicas (UAB, UFRJ, UFSC), Senac EAD, Senai EAD, e instituições privadas. Avaliada pelo Inep via Enade.',
    ],
    [
        'tipo'           => 'concept',
        'fullname'       => 'Vestibular',
        'slug'           => 'vestibular',
        'aliases'        => ['vestibular', 'processo seletivo', 'ingresso na faculdade', 'prova de ingresso', 'prova de vestibular', 'concurso vestibular'],
        'descricao_seed' => 'Processo seletivo para ingresso no ensino superior brasileiro, regulamentado pelo MEC. Pode ser tradicional (provas próprias da instituição como Fuvest, Unicamp, Unesp) ou unificado pelo Enem (Sisu, ProUni, Fies). Avalia conhecimentos do ensino médio em prova objetiva, redação e, em alguns casos, prova específica. Resultado define classificação para vagas em cursos de graduação (bacharelado, licenciatura, tecnólogo). Calendário concentrado entre outubro e fevereiro, com janelas de inscrição em junho-agosto.',
    ],
];

$resultados = [];
foreach ($conceitos as $i => $cfgC) {
    $n = $i + 1;
    echo "→ [$n/3] {$cfgC['fullname']} — buscando posts + gerando sumário...\n";
    try {
        $r = $builder->gerarPara($cfgC, $parentId);
        $resultados[] = [$cfgC['fullname'], $r];
        echo "   ✓ OK — page #{$r['id']} | {$r['posts_relacionados']} posts relacionados\n";
        echo "     {$r['link']}\n\n";
    } catch (Throwable $e) {
        echo "   ✗ FAIL: " . $e->getMessage() . "\n\n";
        $resultados[] = [$cfgC['fullname'], ['erro' => $e->getMessage()]];
    }
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  RESUMO\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
foreach ($resultados as [$nome, $r]) {
    if (isset($r['erro'])) echo "  ✗ $nome — " . $r['erro'] . "\n";
    else echo "  ✓ $nome — page #{$r['id']} | {$r['posts_relacionados']} posts\n";
}
echo "\nLog detalhado:\n";
foreach ($builder->getLog() as $l) echo "  · $l\n";
echo "\n";
echo "Status: TODAS draft. Revise visualmente em wp-admin antes de publicar.\n";
echo "URL pattern: https://cursosenacgratuito.com.br/conceito/{slug}/\n";
