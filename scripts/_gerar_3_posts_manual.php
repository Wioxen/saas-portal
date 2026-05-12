<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/SerperImages.php';
require_once __DIR__ . '/../lib/DbConnection.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

// ──────────────────────────────────────────────────────────────────
// POST 1: cursosenacgratuito — #15185 CNE regulamenta IA em escolas
// ──────────────────────────────────────────────────────────────────
$titulo1 = 'CNE aprova regulamentação sobre uso de IA em escolas e universidades';
$slug1 = 'cne-aprova-regulamentacao-ia-escolas-universidades-mec';
$html1 = <<<'HTML'
<p>O Conselho Nacional de Educação (CNE) aprovou parecer com diretrizes para o uso de inteligência artificial em escolas e universidades do Brasil. O documento, fruto de um ano e meio de trabalho conjunto entre o governo federal, a Unesco e especialistas do setor, agora segue para consulta pública e abrange desde a educação básica até o ensino superior. Conforme apurado pela redação do CursoSenac Gratuito, o texto define como instituições públicas e privadas devem incorporar a IA sem substituir o papel do professor.</p>

<h2>O que diz a regulamentação do CNE sobre IA em sala de aula</h2>

<p>Levantamento da equipe do CursoSenac Gratuito mostra que o parecer aprovado tem como eixo central o princípio da supervisão humana efetiva: a IA pode ser usada como apoio pedagógico, mas a decisão final sobre o processo de aprendizagem permanece com o docente. O texto barra expressamente a automação plena de atividades pedagógicas, especialmente em avaliações dissertativas e formativas, mantendo o professor como único responsável pela análise qualitativa dos estudantes.</p>

<p>Entre as principais diretrizes aprovadas, destacam-se:</p>

<ul>
  <li>Exigência de supervisão humana em todos os processos educacionais que usem IA;</li>
  <li>Transparência e explicabilidade dos sistemas tecnológicos adotados nas escolas;</li>
  <li>Cumprimento rigoroso das leis de proteção de dados pessoais de estudantes e profissionais;</li>
  <li>Valorização do trabalho docente e da formação inicial e continuada de professores;</li>
  <li>Estímulo a ecossistemas de inovação abertos e colaborativos, comprometidos com o interesse público.</li>
</ul>

<h2>Referencial do MEC orienta uso responsável de IA na educação</h2>

<p>Paralelo ao parecer do CNE, o Ministério da Educação publicou o "Referencial para o Uso e Desenvolvimento Responsáveis de Inteligência Artificial na Educação", elaborado pela Secretaria de Gestão da Informação, Inovação e Avaliação de Políticas Educacionais (SEGAPE). A redação confirmou que o documento se destina a instituições, educadores, gestores e formuladores de políticas públicas, com recomendações práticas para todos os níveis de ensino — da educação infantil à pós-graduação.</p>

<p>O Referencial reforça que a inteligência artificial deve servir como ferramenta de inclusão e equidade, e não como mecanismo gerador de novas desigualdades entre grupos com condições diferentes de ensinar e aprender. Para redes de ensino, o documento funciona como guia para elaboração de planos estratégicos, definição de princípios éticos institucionais e investimento em infraestrutura e formação pedagógica.</p>

<h2>84% dos alunos já usam IA na educação, mas a maioria sem orientação</h2>

<p>Pesquisa da Fundação Itaú citada por especialistas mostra que 84% dos alunos e 79% dos professores no Brasil já utilizaram ferramentas de IA generativa — como ChatGPT, Gemini e Copilot — em atividades educacionais. O dado mais preocupante: 73% dos alunos afirmam que a escola nunca discutiu o uso de IA em sala de aula, e apenas 12% dos professores receberam algum tipo de formação específica sobre a tecnologia.</p>

<p>Relatório "Teaching the AI Native Generation", da Oxford University Press, complementa o cenário ao apontar que oito em cada dez adolescentes entre 13 e 18 anos já usam IA em atividades escolares. Apuração nossa indica que a expectativa é de que as novas diretrizes do CNE alterem essa realidade ainda no segundo semestre de 2026, exigindo políticas institucionais claras nas redes públicas e privadas.</p>

<h2>O que muda na formação de professores e na avaliação de alunos</h2>

<p>O parecer aprovado também trata da reestruturação da formação docente. Conforme apurado pela redação, redes de ensino e cursos de licenciatura deverão preparar os profissionais para lidar com análise de dados educacionais e atuar em ambientes híbridos com senso crítico e ética. Para os alunos, o objetivo passa a ser o letramento digital — compreensão sobre o funcionamento dos modelos de IA, seus benefícios e riscos associados.</p>

<p>No campo da avaliação, o documento abre caminho para reformulação dos modelos tradicionais de provas. Detectores de IA atuais apresentam taxas de falso positivo entre 10% e 25%, o que torna seu uso como prova única bastante questionável. A tendência apurada nas diretrizes é a migração para formatos de avaliação que a IA não consegue replicar facilmente, como apresentações orais e produções colaborativas presenciais.</p>
HTML;

// ──────────────────────────────────────────────────────────────────
// POST 2: comocomprar — #16446 Smart TVs TCL + Britânia
// ──────────────────────────────────────────────────────────────────
$titulo2 = 'Smart TVs em oferta hoje: TCL QLED 40" sai por R$ 1.379 e Britânia Roku 43" por R$ 1.175';
$slug2 = 'smart-tvs-oferta-tcl-qled-40-britania-roku-43';
$html2 = <<<'HTML'
<p>Duas Smart TVs aparecem com descontos significativos nesta segunda-feira (12) em varejistas online: a TCL 40S5K QLED 40" caiu de R$ 2.389 para R$ 1.379 e a Britânia B43KRA Roku TV 43" foi reduzida de R$ 1.599 para R$ 1.175,03. Conforme apurado pela redação do Como Comprar, ambos os modelos entregam recursos modernos — QLED, HDR10 e Dolby Audio — por preços competitivos dentro das suas categorias.</p>

<h2>Smart TV TCL 40S5K QLED 40" com Google TV por R$ 1.379</h2>

<p>Levantamento da equipe do Como Comprar mostra que o modelo TCL 40S5K combina três tecnologias que justificam a categoria: tela QLED para cores mais vibrantes e maior brilho que painéis LED convencionais, resolução Full HD nativa e suporte ao formato HDR10. O áudio fica por conta do sistema Dolby Audio integrado.</p>

<p>O sistema operacional é o Google TV, plataforma que facilita o acesso aos principais aplicativos de streaming e oferece integração nativa com dispositivos Android. Para quem assiste filmes, séries ou joga em consoles, o conjunto QLED + HDR10 + Dolby Audio entrega uma experiência visualmente superior aos modelos LED de entrada na mesma faixa de preço.</p>

<ul>
  <li><strong>Tela:</strong> QLED 40 polegadas Full HD</li>
  <li><strong>HDR:</strong> HDR10</li>
  <li><strong>Áudio:</strong> Dolby Audio</li>
  <li><strong>Sistema:</strong> Google TV (acesso a Netflix, Prime Video, YouTube, Globoplay e outros)</li>
  <li><strong>Preço:</strong> R$ 1.379 (de R$ 2.389)</li>
</ul>

<div class='cta-afiliado' style='text-align:center;margin:32px 0;padding:24px;background:#fff8e7;border:2px dashed #ff9900;border-radius:8px;'><p style='margin:0 0 14px;font-size:16px;color:#333;'><strong>Encontrou o produto certo?</strong></p><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:17px;padding:14px 28px;border-radius:6px;text-decoration:none;letter-spacing:0.3px;'>🛒 Veja a oferta na Amazon</a><p style='margin:12px 0 0;font-size:12px;color:#888;'>Link de afiliado — apoia o portal sem custo adicional pra você</p></div>

<h2>Smart TV Britânia B43KRA Roku TV 43" por R$ 1.175,03</h2>

<p>A Britânia B43KRA é a alternativa para quem quer tela maior dentro da categoria de entrada. A redação confirmou que o modelo de 43 polegadas opera com sistema Roku TV — conhecido pela interface leve, simples e intuitiva, com acesso rápido aos principais serviços de streaming.</p>

<p>Em recursos de imagem e som, a Britânia mantém HDR10 e Dolby Audio. Não chega ao patamar visual de um QLED como a TCL, mas a tela maior compensa para quem prioriza dimensão sobre tecnologia de painel. É opção sólida para sala de estar de tamanho médio ou quarto principal.</p>

<ul>
  <li><strong>Tela:</strong> LED 43 polegadas</li>
  <li><strong>HDR:</strong> HDR10</li>
  <li><strong>Áudio:</strong> Dolby Audio</li>
  <li><strong>Sistema:</strong> Roku TV (interface enxuta com aplicativos de streaming)</li>
  <li><strong>Preço:</strong> R$ 1.175,03 (de R$ 1.599)</li>
</ul>

<h2>TCL QLED ou Britânia Roku: qual escolher</h2>

<p>Segundo nosso acompanhamento, a TCL 40S5K compensa para quem prioriza qualidade de imagem — o painel QLED entrega cores mais vivas e melhor desempenho em ambientes iluminados. Já a Britânia B43KRA é a escolha para quem quer tela maior pagando menos, especialmente em quartos ou ambientes secundários, onde o tamanho importa mais que a tecnologia avançada de painel.</p>

<p>A diferença de preço entre as duas é de cerca de R$ 200, e a polegada extra da Britânia compensa visualmente em distâncias maiores de assistir. Ambas têm Wi-Fi integrado, entradas HDMI suficientes para console e set-top box e bom suporte aos principais serviços de streaming em alta definição.</p>

<div class='cta-afiliado cta-fim' style='text-align:center;margin:32px 0;padding:20px;background:#fff8e7;border:2px solid #ff9900;border-radius:8px;'><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:18px;padding:16px 32px;border-radius:6px;text-decoration:none;'>🛒 Comprar agora na Amazon</a></div>
HTML;

// ──────────────────────────────────────────────────────────────────
// POST 3: leaodabarra — #14823 Vitória derrota Justiça Lucas Braga
// ──────────────────────────────────────────────────────────────────
$titulo3 = 'Vitória sofre nova derrota na Justiça e segue sem reverter liberação de Lucas Braga';
$slug3 = 'vitoria-derrota-justica-lucas-braga-trt-5-recurso-rejeitado';
$html3 = <<<'HTML'
<p>O Esporte Clube Vitória sofreu mais um revés na Justiça do Trabalho no processo envolvendo o atacante Lucas Braga Ribeiro. O Tribunal Regional do Trabalho da 5ª Região (TRT-5) rejeitou o recurso do clube e manteve a decisão liminar que liberou o jogador do vínculo com o Rubro-Negro. Conforme apurado pela redação do Leão da Barra, com a nova decisão, o atacante segue livre no mercado para assinar com outro clube na próxima janela de transferências.</p>

<h2>O que o TRT-5 decidiu sobre o caso Lucas Braga</h2>

<p>Levantamento da equipe do Leão da Barra mostra que o Vitória havia ingressado com um Mandado de Segurança contra a decisão liminar da 18ª Vara do Trabalho de Salvador, na tentativa de derrubar a liberação concedida ao atleta. O pedido foi indeferido pelo TRT-5, mantendo válida a decisão anterior.</p>

<p>No centro da disputa está um acordo extrajudicial firmado entre as partes. O Vitória defendia que o pacto possuía validade plena. O tribunal, no entanto, entendeu que o próprio documento condicionava a eficácia total à homologação integral da Câmara Nacional de Resolução de Disputas (CNRD), órgão da FIFA responsável por mediar disputas trabalhistas no futebol. Como a homologação ocorreu com ressalvas, a tese do clube não foi aceita.</p>

<h2>Argumento de "hipersuficiência" do atleta também foi rejeitado</h2>

<p>O Vitória também tentou afastar algumas proteções trabalhistas alegando que Lucas Braga teria condição de "hipersuficiente" — categoria do direito trabalhista aplicada a profissionais com remuneração elevada, que admite flexibilização de regras. A redação confirmou que o entendimento do tribunal foi de que direitos básicos, como FGTS e demais garantias trabalhistas, independem do salário recebido pelo profissional.</p>

<p>Os principais pontos da decisão foram:</p>

<ul>
  <li>Recurso do clube indeferido pelo TRT-5;</li>
  <li>Acordo extrajudicial com ressalvas da CNRD não validado integralmente;</li>
  <li>Tese de "hipersuficiência" do atleta rejeitada;</li>
  <li>Liminar da 18ª Vara do Trabalho de Salvador mantida;</li>
  <li>Lucas Braga segue liberado para assinar com outro clube.</li>
</ul>

<h2>Como o caso chegou até a Justiça do Trabalho</h2>

<p>Apuração nossa indica que o atacante entrou com ação trabalhista alegando atrasos envolvendo salários, direitos de imagem, 13º salário e outras garantias trabalhistas. A Justiça acatou a solicitação do jogador em primeira instância, concedendo liminar de liberação. Foi contra essa decisão que o Vitória recorreu — sem sucesso, agora confirmado pelo TRT-5.</p>

<h2>O que vem agora para Lucas Braga no mercado da bola</h2>

<p>Segundo nosso acompanhamento, Lucas Braga já esteve perto de assinar com o Fortaleza em fevereiro deste ano, com aval do então técnico Thiago Carpini. O acordo estava encaminhado, mas o atacante foi reprovado nos exames médicos por causa de um problema cardíaco, e a negociação foi interrompida.</p>

<p>A redação apurou que o problema de saúde está praticamente resolvido e o atleta agora deseja assinar com um novo clube na janela de transferências do meio do ano. Com a decisão do TRT-5 mantida, Lucas Braga pode negociar livremente sem precisar da anuência do Vitória, configurando o segundo grande revés do clube baiano no caso em poucos meses.</p>
HTML;

// ──────────────────────────────────────────────────────────────────
// Publicação automatizada
// ──────────────────────────────────────────────────────────────────

$posts = [
    [
        'slug_site' => 'cursosenac',
        'trend_id' => 15185,
        'titulo' => $titulo1,
        'slug' => $slug1,
        'html' => $html1,
        'cat' => 'Cursos Gratuitos', // gancho MEC/regulamentação não tem cat perfeita; default
        'tags' => ['MEC', 'CNE', 'Inteligência Artificial', 'Unesco', 'Educação', 'Professores'],
        'og_fallback' => 'https://admin.cnnbrasil.com.br/wp-content/uploads/sites/12/2026/02/inteligencia-artificial-sala-de-aula.jpg?w=1200&h=630&crop=1',
        'query_img' => 'inteligência artificial sala de aula escola brasil',
        'meta_desc' => 'CNE aprova diretrizes para uso de IA em escolas e universidades. Texto segue para consulta pública. Saiba o que muda na formação docente e avaliação.',
        'focus_kw' => 'cne regulamentação ia escolas',
    ],
    [
        'slug_site' => 'comocomprar',
        'trend_id' => 16446,
        'titulo' => $titulo2,
        'slug' => $slug2,
        'html' => $html2,
        'cat' => 'TVs',
        'tags' => ['TCL', 'Britânia', 'QLED', 'Roku', 'Google TV', 'Amazon', 'Smart TV'],
        'og_fallback' => 'https://img.odcdn.com.br/wp-content/uploads/2026/05/Design-sem-nome-2026-05-12T082151.043.png',
        'query_img' => 'smart tv qled sala estar moderna',
        'meta_desc' => 'TCL QLED 40" por R$ 1.379 e Britânia Roku 43" por R$ 1.175. Compare especificações e veja onde comprar com o melhor preço.',
        'focus_kw' => 'smart tv tcl qled britania roku oferta',
    ],
    [
        'slug_site' => 'leaodabarra',
        'trend_id' => 14823,
        'titulo' => $titulo3,
        'slug' => $slug3,
        'html' => $html3,
        'cat' => 'Mercado da Bola',
        'tags' => ['Esporte Clube Vitória', 'Lucas Braga', 'TRT-5', 'Justiça do Trabalho', 'Fortaleza', 'CNRD'],
        'og_fallback' => 'https://www.bnews.com.br/media/_versions/2026/05/vitoria-antecipa-receitas-por-50-anos-e-ignora-saf0136820300202511121256-scaledownproportional_pt145245801_widexl.jpg',
        'query_img' => 'Lucas Braga atacante Vitoria futebol',
        'meta_desc' => 'TRT-5 rejeita recurso do Vitória e mantém liberação de Lucas Braga. Atacante segue livre para assinar com outro clube. Veja a decisão completa.',
        'focus_kw' => 'vitoria lucas braga justica trt',
    ],
];

$resultados = [];
foreach ($posts as $info) {
    echo "\n══════ {$info['slug_site']} — #{$info['trend_id']} ══════\n";
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $info['slug_site']);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    // Featured: og_fallback OR Serper Images
    $featuredId = 0;
    if ($info['og_fallback']) {
        $mid = (int)$wp->uploadImagemPorUrl($info['og_fallback'], $info['titulo'], '');
        if ($mid > 0) { $featuredId = $mid; echo "Featured og: $mid\n"; }
    }
    if ($featuredId === 0 && !empty($cfgSite['serper_api_key'])) {
        try {
            $sx = new SerperImages($cfgSite['serper_api_key']);
            $img = $sx->melhor($info['query_img'], ['min_w' => 800, 'min_h' => 400, 'credito_generico' => true]);
            if ($img) {
                $mid = (int)$wp->uploadImagemPorUrl((string)$img['imageUrl'], $info['titulo'], '');
                if ($mid > 0) { $featuredId = $mid; echo "Featured Serper: $mid\n"; }
            }
        } catch (Throwable $e) {}
    }
    if ($featuredId > 0) {
        $wp->atualizarMedia($featuredId, [
            'caption' => "{$info['titulo']} (Foto: divulgação)",
            'description' => "Imagem ilustrativa da matéria '{$info['titulo']}'.",
            'title' => $info['titulo'],
            'alt_text' => $info['titulo'],
        ]);
    }

    // Categoria
    $cm = new CategoryMatcher($wp, 70.0);
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch([$info['cat']]))));

    // Tags
    $tagIds = $wp->resolverTags($info['tags']);

    // Schema
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $info['titulo'],
        'datePublished' => date('c'),
        'inLanguage' => 'pt-BR',
    ];
    $contentFinal = $info['html'] . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
        . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n</script>\n";

    $payload = [
        'title' => $info['titulo'],
        'slug' => $info['slug'],
        'content' => $contentFinal,
        'status' => 'draft',
        'meta' => [
            'rank_math_title' => "{$info['titulo']} | " . ucfirst($info['slug_site']),
            'rank_math_description' => $info['meta_desc'],
            'rank_math_focus_keyword' => $info['focus_kw'],
        ],
        'categories' => $catIds,
        'tags' => $tagIds,
    ];
    if ($featuredId > 0) $payload['featured_media'] = $featuredId;
    if (!empty($cfgSite['default_post_author_id'])) $payload['author'] = (int)$cfgSite['default_post_author_id'];

    $r = $wp->criarPost($payload);
    $postId = (int)$r['id'];
    $link = (string)$r['link'];
    echo "Post #{$postId} criado: $link\n";

    // Posts relacionados
    try {
        $kw = explode(' ', $info['focus_kw'])[0];
        $rel = $wp->buscarRelacionados($kw, 4, $postId);
        if (count($rel) >= 2) {
            $bloco = "\n<aside class='posts-relacionados'>\n<h2>Veja também</h2>\n<ul>\n";
            foreach (array_slice($rel, 0, 4) as $r2) {
                $titRel = htmlspecialchars(html_entity_decode((string)$r2['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $linkRel = htmlspecialchars((string)$r2['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $bloco .= "  <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
            }
            $bloco .= "</ul>\n</aside>\n";
            $p2 = $wp->getPost($postId);
            $wp->atualizarPost($postId, ['content' => $p2['content']['raw'] . $bloco]);
            echo "Posts relacionados: " . min(4, count($rel)) . "\n";
        }
    } catch (Throwable $e) {}

    // Marca trend
    $pdo = DbConnection::pdo();
    $pdo->prepare("UPDATE trends SET status='publicado', post_id=?, url_post=? WHERE id=?")->execute([$postId, $link, $info['trend_id']]);

    $resultados[] = "  {$info['slug_site']}: #{$postId} → $link";
}

echo "\n═══ RESUMO ═══\n" . implode("\n", $resultados) . "\n";
