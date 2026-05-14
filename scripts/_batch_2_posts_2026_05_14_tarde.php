<?php
declare(strict_types=1);
/**
 * Batch 2 posts manuais — sessão Opus 14/05/2026 (tarde).
 * Trends de origem (Pingo via fontes Tier S/A):
 *   - cursosenac     #21109 IFSULDEMINAS 4 especializações EaD (Hora Brasil)
 *   - leaodabarra    #19868 Maquete Arena Barradão na Copa do Mundo EUA (A Tarde)
 *
 * Regra editorial 2026-05-14 cristalizada: AUTORIA = REDAÇÃO DO SITE.
 * Zero atribuição a portal jornalístico (g1, A Tarde, Metrópoles, Hora Brasil).
 * Falantes institucionais (Fábio Mota presidente VIT, IFSULDEMINAS) podem
 * ser citados com aspas literais. Conteúdo MELHOR que a fonte.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ════════════════════════════════════════════════════════════════════
// POST 1 — CURSOSENAC #21109 — IFSULDEMINAS 4 especializações
// ════════════════════════════════════════════════════════════════════
$post1 = [
    'slug_site' => 'cursosenac', 'trend_id' => 21109,
    'titulo'    => 'IFSULDEMINAS abre 4 especializações gratuitas EaD em maio: Engenharia de Segurança, Saúde, Web e Relações Étnico-Raciais',
    'slug'      => 'ifsuldeminas-2026-4-especializacoes-gratuitas-ead-engenharia-saude-web-etnico-raciais',
    'metaDesc'  => 'IFSULDEMINAS oferece 4 especializações lato sensu gratuitas em 2026: Engenharia de Segurança do Trabalho, Atenção Primária em Saúde, Desenvolvimento Web e Práticas Pedagógicas Étnico-Raciais. EaD com encontros presenciais. Veja vagas e exigências.',
    'focusKw'   => 'ifsuldeminas especializacao gratuita ead',
    'ogImage'   => 'https://www.horabrasil.com.br/wp-content/uploads/2026/05/77325-1.jpg',
    'categoria' => 'Especialização', 'tags' => ['IFSULDEMINAS', 'Especialização EAD', 'Pós-graduação Gratuita', 'Engenharia de Segurança do Trabalho', 'Atenção Primária em Saúde', 'Desenvolvimento Web', 'Educação Étnico-Racial', 'Lato Sensu', 'Sul de Minas Gerais'],
    'html' => <<<'HTML'
<p>O <strong>Instituto Federal de Educação, Ciência e Tecnologia do Sul de Minas Gerais (IFSULDEMINAS)</strong> abriu inscrições para <strong>4 cursos de pós-graduação lato sensu gratuitos</strong>, todos nas modalidades totalmente online ou EaD com encontros presenciais. A oferta cobre áreas estratégicas e atende profissionais já formados em ensino superior.</p>

<p>Os cursos incluem Engenharia de Segurança do Trabalho, Atenção Primária em Saúde e Atenção Psicossocial, Desenvolvimento Web e Práticas Pedagógicas em Relações Étnico-Raciais. Cada especialização tem requisitos próprios de formação prévia, carga horária e número de vagas.</p>

<p>Os 4 cursos seguem o modelo de pós-graduação lato sensu reconhecida pelo MEC, com diploma emitido pelo IFSULDEMINAS após cumprimento da carga horária e aprovação no Trabalho de Conclusão de Curso (TCC).</p>

<h2>Engenharia de Segurança do Trabalho: 50 vagas em Pouso Alegre</h2>

<p>A especialização em <strong>Engenharia de Segurança do Trabalho</strong> tem encontros presenciais no campus IFSULDEMINAS de Pouso Alegre. O curso oferece 50 vagas, com duração de 18 meses e carga horária total de 600 horas distribuídas em 13 disciplinas.</p>

<p>O pré-requisito é graduação completa em curso reconhecido pelo MEC nas áreas de Engenharias ou Arquitetura. A grade contempla disciplinas como:</p>

<ul>
  <li>Introdução à Engenharia de Segurança do Trabalho;</li>
  <li>Prevenção e Controle de Riscos em Máquinas, Equipamentos e Instalações (módulos I e II);</li>
  <li>Gerência de Riscos;</li>
  <li>Higiene do Trabalho (módulos I e II);</li>
  <li>Proteção do Meio Ambiente;</li>
  <li>Ergonomia;</li>
  <li>Proteção Contra Incêndios e Explosões;</li>
  <li>Psicologia na Engenharia de Segurança, Comunicação e Treinamento;</li>
  <li>Legislação e Normas Técnicas.</li>
</ul>

<p>É a única das 4 especializações com pré-requisito específico de graduação técnica, o que mantém o foco profissionalizante na área de segurança industrial.</p>

<h2>Práticas Pedagógicas em Relações Étnico-Raciais: 100 vagas em Três Corações</h2>

<p>A especialização em <strong>Práticas Pedagógicas em Relações Étnico-Raciais: história e cultura afro-brasileira e indígena na Educação Básica</strong> tem encontros presenciais no campus de Três Corações. São 100 vagas, com duração de 12 meses e carga horária de 360 horas.</p>

<p>O pré-requisito é ensino superior completo em qualquer área, desde que o curso seja reconhecido pelo MEC. Atende especialmente professores da educação básica que querem qualificar a abordagem das Leis 10.639/2003 e 11.645/2008 em sala de aula.</p>

<p>A grade inclui disciplinas como Práticas Pedagógicas para Relações Étnico-Raciais (módulos conceitual e produtos educacionais), Questão Racial no Brasil e a História das Ações Afirmativas, Decolonialidade e Reflexões Contemporâneas, Letramento Racial na Biblioteca Escolar, Relações de Gênero e Questões Étnico-Raciais, A Literatura Negra e Indígena como Ferramenta Pedagógica, entre outras.</p>

<h2>Desenvolvimento Web: 100 vagas totalmente online no campus Passos</h2>

<p>A especialização em <strong>Desenvolvimento WEB</strong> é totalmente online, ofertada pelo campus Passos do IFSULDEMINAS. Oferece 100 vagas com 60 horas de carga horária distribuídas em 6 disciplinas, ao longo de 1 ano.</p>

<p>O pré-requisito é graduação completa reconhecida pelo MEC em Ciência da Computação, Engenharia da Computação, Sistemas de Informação, Licenciatura em Computação, cursos de Tecnologia no eixo Informação e Comunicação (conforme catálogo nacional de tecnólogos), ou graduação em outras áreas com experiência comprovada em tecnologia da informação.</p>

<p>A grade técnica reflete a estrutura clássica de full-stack:</p>

<ul>
  <li>Requisitos e Projeto de Software Baseado em Padrões;</li>
  <li>Plataforma de Desenvolvimento em Software Livre e Servidores Web;</li>
  <li>Desenvolvimento Web Front-end;</li>
  <li>Metodologias Ágeis de Desenvolvimento;</li>
  <li>Banco de Dados Relacional e NoSQL;</li>
  <li>Desenvolvimento Web Back-end.</li>
</ul>

<p>É a especialização com menor carga horária do edital, voltada a qualificação rápida em desenvolvimento de aplicações web modernas.</p>

<h2>Atenção Primária em Saúde e Atenção Psicossocial: especialização totalmente online</h2>

<p>A especialização em <strong>Atenção Primária em Saúde e Atenção Psicossocial</strong> também é ofertada pelo campus Passos no formato totalmente online. Tem duração de 18 meses e 400 horas de carga horária divididas em mais de 20 componentes curriculares.</p>

<p>O conteúdo cobre desde o histórico da construção das políticas públicas de saúde até temas atuais como matriciamento em saúde mental, atenção psicossocial nas urgências, biossegurança e promoção da saúde por ciclo de vida (criança, mulher, homem, adulto, idoso, trabalhador).</p>

<p>É a especialização mais ampla em escopo entre as 4, atendendo profissionais de saúde que atuam ou pretendem atuar na rede SUS, especialmente em Estratégia Saúde da Família (ESF) e Centros de Atenção Psicossocial (CAPS).</p>

<h2>Como se inscrever no processo seletivo do IFSULDEMINAS</h2>

<p>As inscrições são feitas exclusivamente pela internet, no site oficial do IFSULDEMINAS, dentro do prazo do edital publicado para cada curso. Os passos básicos são:</p>

<ol>
  <li>Acessar o site oficial do IFSULDEMINAS (<a href='https://portal.ifsuldeminas.edu.br' target='_blank' rel='noopener'>portal.ifsuldeminas.edu.br</a>);</li>
  <li>Localizar o edital do curso desejado na seção de pós-graduação;</li>
  <li>Preencher o formulário de inscrição com dados pessoais e documentação de escolaridade;</li>
  <li>Pagar a taxa de inscrição quando aplicável (alguns editais isentam);</li>
  <li>Acompanhar o cronograma de seleção, que pode incluir análise de currículo, prova específica ou sorteio eletrônico conforme o curso.</li>
</ol>

<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#eef6f0;border-left:6px solid #1f8a4c;border-radius:6px;'><p style='margin:0 0 8px;font-size:17px;color:#1a2a1f;'><strong>Inscrição oficial IFSULDEMINAS</strong></p><p style='margin:0 0 12px;font-size:14px;color:#3a4a3f;'>Edital com prazos, vagas remanescentes e formas de seleção no portal institucional.</p><a href='https://portal.ifsuldeminas.edu.br' target='_blank' rel='noopener' style='display:inline-block;background:#1f8a4c;color:#fff;font-weight:600;font-size:15px;padding:11px 22px;border-radius:5px;text-decoration:none;'>Acessar portal.ifsuldeminas.edu.br</a></div>

<h2>Por que vale considerar uma pós-graduação gratuita em um IF</h2>

<p>Os Institutos Federais oferecem pós-graduação lato sensu gratuita reconhecida pelo MEC, com diploma de validade igual ao de cursos pagos em instituições privadas. A diferença está no custo zero e na infraestrutura pública qualificada.</p>

<p>Para profissionais já atuantes, a especialização lato sensu cumpre 3 funções principais: progressão de carreira em órgãos públicos (plano de carreira com bônus por titulação), qualificação para concursos que exigem pós-graduação, e atualização técnica em áreas com mudança rápida (TI, saúde, segurança do trabalho).</p>

<details class='faq-discover'>
<summary><strong>O IFSULDEMINAS cobra mensalidade nas pós-graduações?</strong></summary>
<p>Não. Os 4 cursos de pós-graduação lato sensu do IFSULDEMINAS divulgados são gratuitos. Não há cobrança de mensalidade nem taxas de matrícula durante a duração do curso.</p>
</details>

<details class='faq-discover'>
<summary><strong>Onde ficam os campi com encontros presenciais?</strong></summary>
<p>Os encontros presenciais acontecem em diferentes campi do IFSULDEMINAS: Engenharia de Segurança do Trabalho em Pouso Alegre, Práticas Pedagógicas em Relações Étnico-Raciais em Três Corações. Os cursos de Desenvolvimento Web e Atenção Primária em Saúde são totalmente online, vinculados ao campus Passos.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual o pré-requisito mínimo para se inscrever?</strong></summary>
<p>Ensino superior completo em curso reconhecido pelo MEC. Três cursos aceitam qualquer área de graduação (Atenção em Saúde, Práticas Étnico-Raciais e Desenvolvimento Web com restrição em TI). A especialização em Engenharia de Segurança do Trabalho exige graduação em Engenharias ou Arquitetura.</p>
</details>

<details class='faq-discover'>
<summary><strong>O diploma de especialização lato sensu vale para concursos?</strong></summary>
<p>Sim. A pós-graduação lato sensu reconhecida pelo MEC vale para progressão de carreira em órgãos públicos, contagem de pontos em concursos que pontuam títulos e qualificação profissional. O diploma é emitido pelo IFSULDEMINAS após cumprimento de carga horária mínima e aprovação no TCC.</p>
</details>

<details class='faq-discover'>
<summary><strong>É possível concorrer a mais de um curso simultaneamente?</strong></summary>
<p>Cada edital define suas próprias regras. Em geral, o candidato pode se inscrever em mais de um curso desde que atenda aos pré-requisitos específicos. A confirmação consta no edital de cada especialização publicado no portal do IFSULDEMINAS.</p>
</details>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// POST 2 — LEAODABARRA #19868 — Maquete Arena Barradão na Copa do Mundo
// ════════════════════════════════════════════════════════════════════
$post2 = [
    'slug_site' => 'leaodabarra', 'trend_id' => 19868,
    'titulo'    => 'Maquete da nova Arena Barradão será exposta na Copa do Mundo 2026 nos Estados Unidos, confirma Fábio Mota',
    'slug'      => 'maquete-arena-barradao-copa-do-mundo-2026-eua-fabio-mota-vitoria',
    'metaDesc'  => 'O presidente do Vitória, Fábio Mota, confirmou que a maquete oficial da nova Arena Barradão será apresentada durante a Copa do Mundo 2026 nos EUA. Projeto foi entregue à Prefeitura de Salvador nesta quarta (13).',
    'focusKw'   => 'arena barradao maquete copa do mundo 2026',
    'ogImage'   => 'https://cdn.atarde.com.br/img/Artigo-Destaque/1380000/conselho-do-vitoria-aprova-assinatura-do-projeto-d0138298600202603172312.jpg?xid=7076127',
    'categoria' => 'EC Vitória', 'tags' => ['Arena Barradão', 'Copa do Mundo 2026', 'Estados Unidos', 'Fábio Mota', 'Esporte Clube Vitória', 'SAF Vitória', 'Prefeitura de Salvador'],
    'html' => <<<'HTML'
<p>O <strong>Esporte Clube Vitória</strong> levará a maquete oficial da <strong>nova Arena Barradão</strong> para exposição internacional durante a <strong>Copa do Mundo 2026 nos Estados Unidos</strong>. A confirmação veio do presidente Fábio Mota durante a apresentação do projeto à Prefeitura de Salvador, nesta quarta-feira, 13 de maio, no Palácio Thomé de Souza.</p>

<p>O Mundial acontece entre 11 de junho e 19 de julho de 2026, em sedes nos Estados Unidos, Canadá e México. A vitrine americana posiciona o Vitória diante de investidores internacionais e jornalistas esportivos cobrindo o torneio, momento estratégico para a busca de capital pela nova SAF do clube.</p>

<p>Em entrevista coletiva após a reunião com a prefeitura, Fábio Mota detalhou o calendário de divulgação internacional e o vínculo do projeto com a constituição da Sociedade Anônima do Futebol (SAF) rubro-negra.</p>

<h2>O que Fábio Mota disse sobre a maquete na Copa do Mundo</h2>

<p>O presidente do Vitória foi direto ao detalhar a exposição internacional. A fala literal foi:</p>

<blockquote><p>"Quem for para a Copa do Mundo dos Estados Unidos vai ver a maquete da Arena Barradão, em uma área especificamente do projeto. A gente vai detalhar mais todos os tipos de serviços e o que mais tenha necessidade de acontecer depois da assinatura do contrato, que deve se dar hoje à tarde."</p></blockquote>

<p>A assinatura do contrato citada por Mota refere-se ao acordo final entre o clube e o grupo investidor responsável pela construção da nova arena, que avança em paralelo à apresentação do projeto à prefeitura.</p>

<h2>Arena Barradão e a SAF do Vitória: o vínculo estratégico</h2>

<p>Além de apresentar a maquete, Mota reforçou que a Arena Barradão tem peso direto na valoração do clube para futuros investidores na SAF. O presidente trabalha em conjunto com o escritório CSMV, comandado pelo advogado André Sica, na prospecção do investidor da Sociedade Anônima do Futebol rubro-negra.</p>

<p>Em outro trecho da entrevista, Mota explicou a lógica da agregação de valor:</p>

<blockquote><p>"A Arena Barradão agrega valor, ela pode ser e vai ser um agregador, isso vai aumentar a receita, vai melhorar o patrimônio do clube. Quem vier adquirir o Vitória no futuro, e eu espero que seja um futuro breve, já vai ter esse elemento a mais, que é a Arena Barradão. Agrega valor ao projeto. É uma preocupação a menos de gerir esse tipo de ativo."</p></blockquote>

<p>A frase carrega 3 mensagens estratégicas: o estádio aumenta receita recorrente, melhora o patrimônio contábil do clube, e descarrega o futuro investidor da preocupação operacional de tocar a obra desde o início.</p>

<h2>Por que a vitrine na Copa do Mundo importa para o Vitória</h2>

<p>A Copa do Mundo de 2026 reúne investidores institucionais, fundos esportivos internacionais, executivos de clubes e mídia global em torno das sedes principais. Para um clube em processo de constituição de SAF, a janela de visibilidade é única.</p>

<p>O Vitória usa o momento para 3 objetivos editoriais:</p>

<ul>
  <li><strong>Atrair olhar de fundos e investidores estrangeiros</strong> que avaliam ativos esportivos brasileiros pós-criação da Lei das SAFs;</li>
  <li><strong>Posicionar o projeto da arena como ativo de classe internacional</strong>, com tecnologia inédita na América Latina (aplicativo + sistema integrado já anunciados);</li>
  <li><strong>Marcar presença na cobertura midiática global</strong> do Mundial, ampliando o reconhecimento da marca rubro-negra fora do circuito tradicional brasileiro.</li>
</ul>

<h2>O cronograma do projeto Arena Barradão</h2>

<p>A nova Arena Barradão é um projeto de modernização do Estádio Manoel Barradas, com capacidade prevista de até 60 mil lugares e investimento estimado na casa dos R$ 500 milhões. O cronograma público conhecido inclui:</p>

<ul>
  <li><strong>13 de maio de 2026:</strong> apresentação oficial do projeto à Prefeitura de Salvador, no Palácio Thomé de Souza;</li>
  <li><strong>Tarde do mesmo dia:</strong> assinatura do contrato com o grupo investidor responsável pela construção;</li>
  <li><strong>11 de junho a 19 de julho de 2026:</strong> exposição da maquete oficial durante a Copa do Mundo nos Estados Unidos;</li>
  <li><strong>2026 (segunda metade):</strong> início da fase de obra, conforme cronograma anteriormente declarado pela diretoria;</li>
  <li><strong>Funcionamento previsto:</strong> manutenção dos jogos do Vitória no Barradão durante as obras, segundo declarações anteriores de Mota.</li>
</ul>

<h2>O contexto: ano do aniversário e momento de mudança</h2>

<p>A confirmação da maquete na Copa do Mundo acontece justamente no ano em que o Vitória completa 127 anos de fundação, comemorados em 13 de maio de 2026. A coincidência reforça o simbolismo institucional: o ano do aniversário consolida tanto a celebração histórica quanto a entrada do clube em nova fase administrativa, com a Arena Barradão como ativo central e a SAF como vetor de capitalização.</p>

<p>Para o torcedor rubro-negro, a expectativa imediata é ver a maquete do estádio renovado circulando entre as sedes da Copa do Mundo, levando a marca Esporte Clube Vitória a um patamar de visibilidade que raramente clubes do Nordeste alcançam em ano de Mundial.</p>

<details class='faq-discover'>
<summary><strong>Quando a maquete da Arena Barradão será exposta nos Estados Unidos?</strong></summary>
<p>A exposição acontece durante a Copa do Mundo de 2026, entre 11 de junho e 19 de julho. O presidente Fábio Mota confirmou que a maquete oficial estará disponível em uma área específica de exposição do projeto durante o Mundial.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual a capacidade prevista da nova Arena Barradão?</strong></summary>
<p>A Arena Barradão é projetada para até 60 mil lugares, com investimento estimado na casa dos R$ 500 milhões. O projeto modernizará o atual Estádio Manoel Barradas, mantendo a localização em Canabrava, Salvador.</p>
</details>

<details class='faq-discover'>
<summary><strong>O Vitória vai jogar onde durante as obras da Arena Barradão?</strong></summary>
<p>Segundo declarações anteriores da diretoria, o Vitória manterá seus jogos no próprio Barradão durante a obra, com construção planejada por fases para não interromper a operação esportiva do clube.</p>
</details>

<details class='faq-discover'>
<summary><strong>O que é a SAF do Vitória?</strong></summary>
<p>A Sociedade Anônima do Futebol (SAF) é o modelo de gestão previsto na Lei nº 14.193/2021 que permite a clubes brasileiros se transformarem em empresas, captando investimento de fundos e investidores. O Vitória trabalha com o escritório CSMV, do advogado André Sica, na prospecção do investidor para sua SAF.</p>
</details>

<details class='faq-discover'>
<summary><strong>A Arena Barradão recebe jogos da Copa do Mundo?</strong></summary>
<p>Não. A Copa do Mundo de 2026 será disputada nos Estados Unidos, Canadá e México, sem sedes no Brasil. A exposição da maquete brasileira nas sedes americanas é uma estratégia de divulgação institucional do Vitória, não vinculada à hospedagem de partidas.</p>
</details>
HTML,
];

// ════════════════════════════════════════════════════════════════════
// Publicação batch — sem citation (autoria = redação do site)
// ════════════════════════════════════════════════════════════════════
$resultados = [];
foreach ([$post1, $post2] as $info) {
    echo "\n══════ {$info['slug_site']} — trend #{$info['trend_id']} ══════\n";
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $info['slug_site']);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    $featuredId = 0;
    try {
        $featuredId = (int)($wp->uploadImagemPorUrl169($info['ogImage'], $info['titulo'], $info['slug']) ?? 0);
        if ($featuredId > 0) echo "✅ Featured 16:9: media #{$featuredId}\n";
    } catch (Throwable $e) {}
    if ($featuredId === 0) {
        try {
            $featuredId = (int)($wp->uploadImagemPorUrl($info['ogImage'], $info['titulo'], $info['slug']) ?? 0);
            if ($featuredId > 0) echo "✅ Featured original: media #{$featuredId}\n";
        } catch (Throwable $e) {}
    }
    if ($featuredId > 0) {
        $wp->atualizarMedia($featuredId, [
            'caption'     => "{$info['titulo']} (Foto: divulgação)",
            'description' => "Imagem ilustrativa da matéria '{$info['titulo']}'.",
            'title'       => $info['titulo'],
            'alt_text'    => $info['titulo'],
        ]);
    }

    // Schema NewsArticle SEM citation a portal — autor = redação do site
    $autorSite = ucfirst($info['slug_site']);
    $schemaNews = [
        '@context' => 'https://schema.org', '@type' => 'NewsArticle',
        'headline' => $info['titulo'],
        'datePublished' => date('c'), 'dateModified' => date('c'),
        'inLanguage' => 'pt-BR',
        'author' => ['@type' => 'Organization', 'name' => "Redação {$cfgSite['site_name']}", 'url' => $cfgSite['wp_url']],
        'publisher' => ['@type' => 'Organization', 'name' => $cfgSite['site_name'], 'url' => $cfgSite['wp_url']],
    ];
    $contentFinal = $info['html']
        . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
        . json_encode($schemaNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

    $cm = new CategoryMatcher($wp, 70.0);
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch([$info['categoria']]))));
    $tagIds = $wp->resolverTags($info['tags']);

    $payload = [
        'title' => $info['titulo'], 'slug' => $info['slug'], 'content' => $contentFinal,
        'status' => 'draft',
        'meta' => [
            'rank_math_title'         => $info['titulo'] . ' | ' . ucfirst($info['slug_site']),
            'rank_math_description'   => $info['metaDesc'],
            'rank_math_focus_keyword' => $info['focusKw'],
        ],
        'categories' => $catIds, 'tags' => $tagIds,
    ];
    if ($featuredId > 0) $payload['featured_media'] = $featuredId;
    if (!empty($cfgSite['default_post_author_id'])) $payload['author'] = (int)$cfgSite['default_post_author_id'];

    $r = $wp->criarPost($payload);
    $postId = (int)($r['id'] ?? 0);
    $link = (string)($r['link'] ?? '');
    if ($postId === 0) { echo "❌ ERRO\n"; continue; }
    echo "✅ Post #{$postId} DRAFT · {$link}\n";

    try {
        $kw = explode(' ', $info['focusKw'])[0];
        $rel = $wp->buscarRelacionados($kw, 4, $postId);
        if (is_array($rel) && count($rel) >= 2) {
            $bloco = "\n<aside class='posts-relacionados'>\n<h2>Veja também</h2>\n<ul>\n";
            foreach (array_slice($rel, 0, 4) as $r2) {
                $titRel = htmlspecialchars(html_entity_decode((string)$r2['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $linkRel = htmlspecialchars((string)$r2['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $bloco .= "  <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
            }
            $bloco .= "</ul>\n</aside>\n";
            $p2 = $wp->getPost($postId);
            $wp->atualizarPost($postId, ['content' => ($p2['content']['raw'] ?? $contentFinal) . $bloco]);
            echo "   Relacionados: " . min(4, count($rel)) . "\n";
        }
    } catch (Throwable $e) {}

    $resultados[] = "  {$info['slug_site']} #{$info['trend_id']} → post #{$postId}";
}

echo "\n══════ RESUMO ══════\n";
foreach ($resultados as $l) echo $l . "\n";
