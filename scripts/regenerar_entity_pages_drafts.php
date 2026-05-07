<?php
/**
 * Re-renderiza os 5 Entity Pages piloto em DRAFT no cursosenacgratuito.com.br
 * pra aplicar melhorias do EntityHubBuilder (negrito em sigla/fullname/programas/leis/datas).
 *
 * NÃO duplica páginas — atualiza via WP REST mantendo IDs originais.
 *
 * Uso:
 *   docker exec ... php /app/scripts/regenerar_entity_pages_drafts.php
 *
 * Custo: ~$0.10 (5 sumários Sonnet × ~$0.02 cada).
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
echo "  Regerar Entity Pages Piloto — cursosenacgratuito.com.br\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// IDs reais dos drafts criados em 2026-05-06
$entidades = [
    [
        'page_id'        => 5141,
        'nome'           => 'IFSP',
        'fullname'       => 'Instituto Federal de São Paulo',
        'tipo_org'       => 'GovernmentOrganization (autarquia federal de educação)',
        'slug'           => 'ifsp',
        'url_oficial'    => 'https://www.ifsp.edu.br',
        'aliases'        => ['Instituto Federal São Paulo', 'IF SP', 'Instituto Federal de SP'],
        'descricao_seed' => 'Autarquia federal vinculada ao MEC, criada em 2008 pela Lei 11.892. Oferta cursos técnicos integrados, subsequentes, graduação tecnológica, licenciaturas, bacharelados, especializações e mestrado profissional. Possui campi distribuídos no estado de São Paulo, com modalidades presencial e EAD. Reitoria em São Paulo (capital).',
    ],
    [
        'page_id'        => 5142,
        'nome'           => 'Senac',
        'fullname'       => 'Serviço Nacional de Aprendizagem Comercial',
        'tipo_org'       => 'EducationalOrganization (sistema S de educação profissional)',
        'slug'           => 'senac',
        'url_oficial'    => 'https://www.senac.br',
        'aliases'        => ['SENAC', 'Senac Brasil', 'Senac EAD'],
        'descricao_seed' => 'Instituição privada de educação profissional sem fins lucrativos, integrante do Sistema S. Criada em 1946 pelo Decreto-Lei 8.621 e mantida pela Confederação Nacional do Comércio (CNC). Atua em comércio, serviços e turismo, oferecendo cursos livres, técnicos, graduação tecnológica, pós-graduação, EAD e formação inicial. Presente em todo território nacional.',
    ],
    [
        'page_id'        => 5143,
        'nome'           => 'MEC',
        'fullname'       => 'Ministério da Educação',
        'tipo_org'       => 'GovernmentOrganization (ministério federal)',
        'slug'           => 'mec',
        'url_oficial'    => 'https://www.gov.br/mec',
        'aliases'        => ['Ministério Educação'],
        'descricao_seed' => 'Órgão da administração pública federal brasileira responsável por formular, executar e avaliar políticas públicas educacionais. Criado em 1930. Coordena o sistema federal de ensino, regula instituições privadas de ensino superior, executa programas como Pé-de-Meia, ProUni, Fies, Sisu, Enem, e mantém autarquias como Inep, Capes, FNDE.',
    ],
    [
        'page_id'        => 5144,
        'nome'           => 'Fundeb',
        'fullname'       => 'Fundo de Manutenção e Desenvolvimento da Educação Básica',
        'tipo_org'       => 'GovernmentService (fundo federal contábil)',
        'slug'           => 'fundeb',
        'url_oficial'    => 'https://www.gov.br/fnde/pt-br/acesso-a-informacao/acoes-e-programas/financiamento/fundeb',
        'aliases'        => ['FUNDEB', 'Fundo da Educação Básica'],
        'descricao_seed' => 'Fundo contábil de natureza pública criado pela Emenda Constitucional 53/2006 e regulamentado pela Lei 11.494/2007, tornado permanente pela EC 108/2020. Financia a educação básica brasileira (creche, pré-escola, fundamental, médio) com recursos de estados, DF e municípios, complementados pela União. Distribuição proporcional ao número de matrículas e fatores de ponderação por etapa/modalidade.',
    ],
    [
        'page_id'        => 5145,
        'nome'           => 'Inep',
        'fullname'       => 'Instituto Nacional de Estudos e Pesquisas Educacionais Anísio Teixeira',
        'tipo_org'       => 'GovernmentOrganization (autarquia federal)',
        'slug'           => 'inep',
        'url_oficial'    => 'https://www.gov.br/inep',
        'aliases'        => ['INEP', 'Instituto Anísio Teixeira'],
        'descricao_seed' => 'Autarquia federal vinculada ao MEC, criada em 1937. Responsável por avaliações e estatísticas educacionais do país: Enem, Encceja, Saeb, Censo Escolar, Censo da Educação Superior, Enade. Produz indicadores como Ideb e divulga microdados públicos para pesquisa. Sede em Brasília.',
    ],
];

$resultados = [];
foreach ($entidades as $i => $ent) {
    $n = $i + 1;
    $pageId = (int)$ent['page_id'];
    echo "→ [$n/5] {$ent['nome']} (page #{$pageId}) — regerando sumário + html...\n";
    try {
        $r = $builder->atualizarPara($ent, $pageId);
        $resultados[] = [$ent['nome'], $r];
        echo "   ✓ OK — page #{$r['id']} | {$r['posts_relacionados']} posts relacionados\n";
        if (!empty($r['link'])) echo "     {$r['link']}\n";
        echo "\n";
    } catch (Throwable $e) {
        echo "   ✗ FAIL: " . $e->getMessage() . "\n\n";
        $resultados[] = [$ent['nome'], ['erro' => $e->getMessage()]];
    }
}

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  RESUMO\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
foreach ($resultados as [$nome, $r]) {
    if (isset($r['erro'])) {
        echo "  ✗ $nome — " . $r['erro'] . "\n";
    } else {
        echo "  ✓ $nome — page #{$r['id']} | {$r['posts_relacionados']} posts\n";
    }
}
echo "\nLog detalhado:\n";
foreach ($builder->getLog() as $l) echo "  · $l\n";
echo "\n";
echo "Status: TODAS continuam draft. Revise visualmente em wp-admin pra conferir o negrito.\n";
