<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/DbConnection.php';
require_once __DIR__ . '/../lib/PostFinishing.php';
require_once __DIR__ . '/../lib/WebStoryEnhancer.php';

$cfgRoot = require __DIR__ . '/../config.php';

function boxCtaOf(string $url, string $cta, string $rotulo): string {
    $u = htmlspecialchars($url, ENT_QUOTES); $r = htmlspecialchars($rotulo, ENT_QUOTES); $c = htmlspecialchars($cta, ENT_QUOTES);
    return "<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#eef6ff;border-left:4px solid #1f6feb;border-radius:6px;'><p style='margin:0 0 8px;font-size:13px;font-weight:600;color:#1f6feb;text-transform:uppercase;letter-spacing:0.5px;'>📋 {$r}</p><a href='{$u}' target='_blank' rel='noopener nofollow' style='display:inline-block;background:#1f6feb;color:#fff;font-weight:600;padding:11px 22px;border-radius:5px;text-decoration:none;font-size:15px;'>{$c} →</a></div>";
}

$keys = ['leaodabarra' => '', 'cursosenac' => '1e655367236b47c0bdcc882fdd7a0b4e', 'vagasebeneficios' => '438de8951bab40709409042e6b7800ef', 'guiadoscursos' => 'c2d30eab950e41b5aa33decb5ab626c5'];

function publicarComPipeline(array $cfgRoot, string $site, array $job, array $keys): int {
    $cfg = $cfgRoot;
    aplicarSite($cfg, sitesDisponiveis(), $site);
    $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    $cm = new CategoryMatcher($wp, 70.0);
    $base = rtrim($cfg['wp_url'], '/');

    echo "\n══ [$site] {$job['titulo']}\n";
    $featuredId = 0;
    if (!empty($job['og_image'])) {
        $featuredId = (int)$wp->uploadImagemPorUrl916($job['og_image'], $job['titulo'], $job['slug']);
        if ($featuredId > 0) {
            $wp->atualizarMedia($featuredId, ['caption' => $job['legenda'], 'title' => $job['titulo'], 'alt_text' => $job['titulo']]);
            echo "  ✓ featured #$featuredId (9:16)\n";
        }
    }
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch([$job['categoria']]))));
    $tagIds = $wp->resolverTags($job['tags']);

    $contentFinal = $job['html'] . "\n<script type=\"application/ld+json\" data-schema=\"" . $job['schema_tipo'] . "\">\n" . json_encode($job['schema'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";
    $payload = ['title' => $job['titulo'], 'slug' => $job['slug'], 'content' => $contentFinal, 'status' => 'publish', 'meta' => $job['meta'], 'categories' => $catIds, 'tags' => $tagIds];
    if ($featuredId > 0) $payload['featured_media'] = $featuredId;
    if (!empty($cfg['default_post_author_id'])) $payload['author'] = (int)$cfg['default_post_author_id'];

    $resp = PostFinishing::criarPostFinalizando($wp, $payload, [$job['kw']]);
    $postId = (int)$resp['id']; $linkPub = (string)$resp['link'];
    echo "  ✓ post #$postId: $linkPub\n";

    // Web Story via lib (criar + Serper + decode)
    try {
        $enh = new WebStoryEnhancer($site, $cfg);
        $r = $enh->criarComSerper($postId, [
            'keyword' => $job['kw'],
            'resposta_direta' => $job['rd'],
            'queries_especificas' => $job['queries_ws'] ?? [],
        ]);
        if ($r['ok']) echo "  ✓ WS #{$r['ws_novo']} ({$r['imagens_count']} imgs Serper)\n";
        else echo "  ⚠ WS: {$r['motivo']}\n";
    } catch (Throwable $e) { echo "  ⚠ WS exception: " . $e->getMessage() . "\n"; }

    // IndexNow
    if (!empty($keys[$site])) {
        $host = parse_url($linkPub, PHP_URL_HOST);
        $body = ['host' => $host, 'key' => $keys[$site], 'keyLocation' => "https://{$host}/{$keys[$site]}.txt", 'urlList' => [$linkPub]];
        $ch = curl_init('https://api.indexnow.org/IndexNow');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_TIMEOUT => 15]);
        curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        echo "  IndexNow HTTP $c\n";
    }

    return $postId;
}

// ════════════════════════════════════════════════════════════════════
// 1. leaodabarra · CBF Copa do Nordeste semis Vitória x ABC
// ════════════════════════════════════════════════════════════════════
$cbfHtml = <<<'HTML'
<p>A CBF publicou nesta segunda-feira (11) a tabela detalhada das semifinais da Copa do Nordeste 2026. O Vitória enfrenta o ABC em dois jogos: <strong>ida no dia 20 de maio, terça-feira, às 21h no Barradão</strong>, e volta no dia <strong>27 de maio, quarta-feira, às 21h30 na Arena das Dunas</strong>, em Natal. Quem passar disputa a final contra o vencedor de Fortaleza x Sport, segundo confirmou a redação do Leão da Barra.</p>

<h2>Jogos das semifinais: tudo confirmado pela CBF</h2>

<p>A Copa do Nordeste 2026 chega à fase decisiva com quatro times definidos: Vitória, ABC, Fortaleza e Sport. Os jogos de ida acontecem todos em mesma data (20/05) e horários simultâneos (21h, horário de Brasília), dobrando a tensão do torcedor que segue mais de uma equipe:</p>

<ul>
  <li><strong>Ida:</strong> Vitória x ABC, terça-feira 20/05, 21h, Barradão (Salvador)</li>
  <li><strong>Ida:</strong> Fortaleza x Sport, terça-feira 20/05, 21h, Arena Castelão (Fortaleza)</li>
  <li><strong>Volta:</strong> ABC x Vitória, terça-feira 27/05, 21h30, Arena das Dunas (Natal)</li>
  <li><strong>Volta:</strong> Sport x Fortaleza, terça-feira 27/05, 21h30, Ilha do Retiro (Recife)</li>
</ul>

<p>Os finalistas saem dos cruzamentos. A grande final tem datas a serem confirmadas pela CBF, mas costuma acontecer no primeiro fim de semana de junho.</p>

<h2>Como o Vitória chegou às semifinais</h2>

<p>O Leão da Barra eliminou o Ceará nas quartas de final em duelo equilibrado. A campanha do Vitória até aqui inclui uma fase de grupos sólida e classificação no Grupo A, somando vitórias importantes contra rivais tradicionais do Nordeste. O time de Jair Ventura tem o Barradão como trunfo histórico, com bom retrospecto recente em casa.</p>

<p>Pelo lado do ABC, classificação veio em jogo decidido na noite de quarta-feira contra adversário tradicional do Norte. O time potiguar tem campanha de underdog na competição, mas costuma surpreender em formato eliminatório.</p>

<h2>O cronograma apertado do Vitória em maio</h2>

<p>Antes da semifinal contra o ABC, o Vitória ainda joga 3 partidas decisivas:</p>

<ul>
  <li><strong>14 de maio (quinta):</strong> Vitória x Flamengo, 21h30, Barradão — volta Copa do Brasil 5ª fase</li>
  <li><strong>17 de maio (domingo):</strong> Bragantino x Vitória, 18h30, Bragança Paulista — Brasileirão 16ª rodada</li>
  <li><strong>20 de maio (terça):</strong> Vitória x ABC, 21h, Barradão — semi Copa do Nordeste (ida)</li>
  <li><strong>27 de maio (terça):</strong> ABC x Vitória, 21h30, Natal — semi Copa do Nordeste (volta)</li>
</ul>

<p>A semana entre o jogo contra o Flamengo e o ABC é curta. Jair Ventura terá que rodar o elenco pra evitar desgaste excessivo dos titulares antes da fase decisiva.</p>

<h2>Os times rivais nas outras semifinais</h2>

<p>O outro lado da chave traz dois times do Pernambuco/Ceará com rivalidade histórica:</p>

<ul>
  <li><strong>Fortaleza:</strong> tradição recente em finais regionais, atual vice-campeão da Copa do Nordeste</li>
  <li><strong>Sport:</strong> retorno forte ao Nordestão depois de campanha sólida na fase de grupos</li>
</ul>

<p>O vencedor desse confronto enfrenta na final quem passar entre Vitória e ABC. A torcida rubro-negra acredita em decisão contra um dos dois cearenses ou pernambucanos, em jogo único da final.</p>

<h2>O que está em jogo</h2>

<p>Além do título do Nordeste, a Copa do Nordeste 2026 distribui:</p>

<ul>
  <li><strong>Vaga na pré-Libertadores 2027</strong> para o campeão</li>
  <li><strong>Premiação em dinheiro</strong> que ajuda o caixa dos clubes médios da região</li>
  <li><strong>Prestígio regional</strong> em competição que mobiliza torcida de 10 estados</li>
</ul>

<p>Pro Vitória, conquistar o Nordestão é objetivo declarado da temporada — único título regional que falta nas vitrines recentes do clube. O ABC é o último obstáculo antes da final.</p>

<h2>Detalhes da semifinal Vitória x ABC</h2>

<ul>
  <li><strong>Competição:</strong> Copa do Nordeste 2026 — semifinal</li>
  <li><strong>Ida:</strong> 20 de maio (terça), 21h, Estádio Manoel Barradas (Barradão)</li>
  <li><strong>Volta:</strong> 27 de maio (terça), 21h30, Arena das Dunas, Natal/RN</li>
  <li><strong>Outras semis:</strong> Fortaleza x Sport (Castelão / Ilha do Retiro)</li>
  <li><strong>Final:</strong> data a confirmar (primeira semana de junho, geralmente)</li>
  <li><strong>Transmissão prevista:</strong> SporTV + Premiere (confirmação pela emissora oficial dias antes)</li>
</ul>
HTML;
$cbfSchema = ['@context' => 'https://schema.org', '@type' => 'SportsEvent', 'name' => 'Vitória x ABC — Semifinal Copa do Nordeste 2026 (ida)', 'startDate' => '2026-05-20T21:00:00-03:00', 'endDate' => '2026-05-20T23:00:00-03:00', 'eventStatus' => 'https://schema.org/EventScheduled', 'location' => ['@type' => 'Place', 'name' => 'Estádio Manoel Barradas', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Salvador', 'addressRegion' => 'BA', 'addressCountry' => 'BR']], 'competitor' => [['@type' => 'SportsTeam', 'name' => 'Esporte Clube Vitória'], ['@type' => 'SportsTeam', 'name' => 'ABC FC']], 'sport' => 'Football', 'organizer' => ['@type' => 'Organization', 'name' => 'CBF', 'url' => 'https://www.cbf.com.br/', 'sameAs' => 'https://www.cbf.com.br/']];

publicarComPipeline($cfgRoot, 'leaodabarra', [
    'titulo' => 'Copa do Nordeste 2026: Vitória x ABC tem datas e horários confirmados pela CBF',
    'slug' => 'vitoria-abc-copa-do-nordeste-semifinal-datas-horarios-cbf-2026',
    'og_image' => 'https://stcbfsiteprdimgbrs.blob.core.windows.net/img-site/cdn/IMG_8866.JPG',
    'legenda' => 'CBF divulgou tabela das semifinais da Copa do Nordeste 2026 (Foto: CBF/Divulgação)',
    'categoria' => 'Copa do Nordeste',
    'tags' => ['Copa do Nordeste 2026', 'Vitória x ABC', 'Semifinal', 'Barradão', 'Arena das Dunas', 'CBF', 'Jair Ventura', 'Esporte Clube Vitória', 'ABC FC'],
    'html' => $cbfHtml, 'schema' => $cbfSchema, 'schema_tipo' => 'sportsevent',
    'kw' => 'Vitória ABC Copa Nordeste', 'rd' => 'A CBF confirmou: Vitória x ABC nas semifinais da Copa do Nordeste 2026. Ida no Barradão dia 20 de maio às 21h, volta na Arena das Dunas dia 27 às 21h30.',
    'queries_ws' => ['Copa do Nordeste 2026 troféu', 'Barradão estádio Vitória noturno', 'ABC FC time Natal RN', 'Arena das Dunas estádio Natal', 'CBF logo Copa do Nordeste', 'Vitória semifinal comemoração', 'Jair Ventura técnico Vitória'],
    'meta' => ['rank_math_title' => 'Vitória x ABC Copa do Nordeste 2026: datas e horários', 'rank_math_description' => 'CBF confirma Vitória x ABC nas semis da Copa do Nordeste 2026. Ida 20/05 21h no Barradão, volta 27/05 21h30 em Natal. Tabela completa.', 'rank_math_focus_keyword' => 'vitoria abc copa do nordeste'],
], $keys);

// ════════════════════════════════════════════════════════════════════
// 2. leaodabarra · Renê Arábia
// ════════════════════════════════════════════════════════════════════
$reneHtml = <<<'HTML'
<p>O atacante Renê, 22 anos, está despertando interesse de clubes da Arábia Saudita após início arrasador pelo Vitória. Em 11 jogos com a camisa rubro-negra, o atleta soma <strong>6 gols e 1 assistência</strong>, números que repetem desempenho semelhante ao apresentado na Portuguesa-SP antes da contratação. Nenhuma proposta formal foi apresentada até agora, mas a diretoria do Leão acompanha a movimentação com atenção, segundo apurou a redação do Leão da Barra.</p>

<h2>Os números de Renê pelo Vitória até aqui</h2>

<p>O atacante chegou ao Barradão no início da temporada 2026 e rapidamente caiu nas graças da torcida pela combinação de finalização rápida, mobilidade e jogo aéreo competitivo. Em 11 partidas oficiais:</p>

<ul>
  <li><strong>6 gols marcados</strong> (média superior a 0,5 gol por jogo)</li>
  <li><strong>1 assistência</strong> em jogada-chave do Campeonato Baiano</li>
  <li><strong>Atuações decisivas</strong> contra Bahia e Sport pelo Nordestão</li>
  <li><strong>Versatilidade tática</strong>: atua como camisa 9 fixo, segundo atacante ou aberto pela esquerda</li>
</ul>

<p>O destaque numérico desperta interesse fora do Brasil, principalmente em mercados que costumam pagar caro por atacantes jovens com bom histórico de finalização: Arábia Saudita, Catar e países do Leste Europeu.</p>

<h2>Trajetória antes do Vitória</h2>

<p>Antes de assinar com o Leão, Renê ganhou destaque defendendo a Portuguesa-SP no Campeonato Paulista 2026. Em 11 partidas, o atacante marcou 7 gols e deu 2 assistências, números que repetem o ritmo mantido no Vitória. Esse desempenho consistente entre 2 clubes diferentes no mesmo ano calendário é o que faz scouts internacionais ligarem o nome dele.</p>

<p>A passagem pela Portuguesa também serviu pra elevar o valor de mercado, que praticamente dobrou no curto período entre a transferência e os primeiros gols pelo Vitória. Especialistas em transferências estimam o valor de mercado atual entre 4 e 6 milhões de euros, dependendo da fonte.</p>

<h2>Por que clubes árabes estão de olho</h2>

<p>O mercado saudita virou destino importante pra atacantes brasileiros jovens nas últimas 3 janelas. A combinação de salários elevados, baixa carga tributária e ligas com bom nível técnico tem atraído jogadores que antes só pensavam na Europa. Renê encaixa exatamente no perfil que clubes como Al-Hilal, Al-Nassr e Al-Ittihad procuram:</p>

<ul>
  <li>Idade abaixo de 25 anos (valor de revenda futuro)</li>
  <li>Histórico recente de gols em campeonatos nacionais consistentes</li>
  <li>Sem passagem internacional ainda (pode ser primeira experiência fora)</li>
  <li>Custo de transferência relativamente baixo comparado a europeus</li>
</ul>

<p>Quando os clubes árabes batem nessa porta, costumam apresentar propostas duplas: pra o jogador (salário 3-5x maior que no Brasil) e pra o clube vendedor (valor de mercado + premium). Difícil recusar.</p>

<h2>A postura da diretoria do Vitória</h2>

<p>Internamente, o departamento de futebol do Vitória monitora o cenário com cautela. Não há intenção declarada de segurar o atleta a qualquer custo — se vier proposta financeiramente irrecusável, a venda pode acontecer. Mas a diretoria também sabe que perder Renê no meio da temporada, especialmente com semifinais da Copa do Nordeste e mata-mata da Copa do Brasil em andamento, comprometeria a campanha.</p>

<p>O contrato atual de Renê com o Vitória vai até o fim de 2027, o que dá ao clube poder de barganha. Eventual transferência precisaria envolver multa rescisória robusta — pelo menos R$ 30 milhões em valores atuais, segundo padrão do mercado pra atacantes desse perfil.</p>

<h2>Próximos passos: depende de proposta concreta</h2>

<p>Até agora, o interesse árabe é apenas observacional. Scouts assistem aos jogos do Vitória, registram desempenho de Renê e fazem relatórios pra diretorias dos clubes interessados. Pra avançar pra negociação real, é preciso que algum clube saudita apresente proposta formal por escrito, normalmente via agente do jogador.</p>

<p>A próxima janela internacional de transferências abre em julho. Até lá, o Vitória tenta o título da Copa do Nordeste e da Copa do Brasil, dois objetivos onde Renê tem papel central no esquema tático do técnico Jair Ventura.</p>

<h2>Detalhes da situação</h2>

<ul>
  <li><strong>Atleta:</strong> Renê (atacante, 22 anos)</li>
  <li><strong>Clube atual:</strong> Esporte Clube Vitória</li>
  <li><strong>Números 2026 (Vitória):</strong> 11 jogos, 6 gols, 1 assistência</li>
  <li><strong>Números 2026 (Portuguesa-SP):</strong> 11 jogos, 7 gols, 2 assistências</li>
  <li><strong>Interesse:</strong> Clubes da Arábia Saudita (sem proposta formal até o momento)</li>
  <li><strong>Contrato Vitória:</strong> Até dezembro de 2027</li>
  <li><strong>Próxima janela internacional:</strong> Julho de 2026</li>
</ul>
HTML;
$reneSchema = ['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => 'Renê desperta interesse de clubes da Arábia após início arrasador pelo Vitória', 'datePublished' => date('c'), 'inLanguage' => 'pt-BR'];

publicarComPipeline($cfgRoot, 'leaodabarra', [
    'titulo' => 'Vitória liga alerta: Renê desperta interesse de clubes da Arábia após 6 gols em 11 jogos',
    'slug' => 'rene-atacante-vitoria-interesse-clubes-arabia-saudita-2026',
    'og_image' => 'https://meuvitoria.com.br/wp-content/uploads/2026/03/O-Esporte-Clube-Vitoria-anuncia-a-contratacao-do-atacante-Rene.jpg',
    'legenda' => 'Atacante Renê do Esporte Clube Vitória (Foto: ECV/Divulgação)',
    'categoria' => 'Mercado da Bola',
    'tags' => ['Renê', 'Vitória', 'Arábia Saudita', 'Mercado da Bola', 'Transferência', 'Jair Ventura', 'Atacante', 'Esporte Clube Vitória', 'Portuguesa-SP'],
    'html' => $reneHtml, 'schema' => $reneSchema, 'schema_tipo' => 'newsarticle',
    'kw' => 'Renê Vitória Arábia', 'rd' => 'O atacante Renê, do Vitória, desperta interesse de clubes da Arábia após marcar 6 gols em 11 jogos. Diretoria do Leão acompanha o cenário sem propostas oficiais ainda.',
    'queries_ws' => ['Renê atacante Vitória comemoração', 'Esporte Clube Vitória ataque', 'Barradão jogadores Vitória', 'Arábia Saudita futebol Al-Hilal', 'mercado da bola brasileiro', 'atacante brasileiro 22 anos'],
    'meta' => ['rank_math_title' => 'Renê do Vitória interessa clubes da Arábia: 6 gols em 11 jogos', 'rank_math_description' => 'Atacante Renê desperta interesse de clubes árabes após 6 gols em 11 partidas pelo Vitória. Saiba a posição da diretoria e os números do jogador.', 'rank_math_focus_keyword' => 'rene vitoria arabia'],
], $keys);

// ════════════════════════════════════════════════════════════════════
// 3. cursosenac · IFF Design Gráfico com diploma
// ════════════════════════════════════════════════════════════════════
$iffUrl = 'https://portal.iff.edu.br/';
$iffBox = boxCtaOf($iffUrl, 'Acessar Instituto Federal Fluminense', 'Inscrição oficial IFF');
$iffHtml = <<<HTML
<p>O Instituto Federal Fluminense (IFF) abriu inscrições para curso técnico gratuito de <strong>Design Gráfico</strong> na modalidade subsequente, com emissão de diploma reconhecido pelo MEC. As oportunidades são distribuídas em câmpus do estado do Rio de Janeiro e atendem candidatos que já concluíram o ensino médio. A seleção é por processo seletivo simplificado, conforme apurou a redação do CursoSenac Gratuito.</p>

<h2>O que é o curso Técnico em Design Gráfico do IFF</h2>

<p>O curso é regular do IFF, com carga horária total compatível com a base nacional de educação técnica (1.200 a 1.600 horas, dependendo do câmpus). A formação prepara o estudante pra atuar com:</p>

<ul>
  <li>Diagramação de impressos (revistas, jornais, livros)</li>
  <li>Identidade visual de marcas (logo, manual de marca, papelaria)</li>
  <li>Design digital (interfaces web, redes sociais, e-mail marketing)</li>
  <li>Tratamento e edição de imagens (Photoshop, Lightroom)</li>
  <li>Ilustração vetorial (Illustrator)</li>
  <li>Pré-impressão e produção gráfica</li>
</ul>

<p>Ao concluir, o aluno recebe diploma de Técnico em Design Gráfico, com validade nacional pelo MEC. O diploma habilita pra trabalhar com carteira assinada em agências, gráficas, editoras, departamentos de marketing e como freelancer com nota fiscal de prestador de serviço.</p>

{$iffBox}

<h2>Quem pode se inscrever no IFF</h2>

<p>O curso é na modalidade <strong>subsequente</strong>, ou seja, para quem já tem o ensino médio completo. Os pré-requisitos cumulativos:</p>

<ul>
  <li>Concluir o ensino médio em escola pública ou privada (apresentar certificado/histórico na matrícula)</li>
  <li>Não há limite de idade</li>
  <li>Sem exigência de prova de habilidade artística prévia (qualquer pessoa pode se inscrever)</li>
  <li>Sem custo de matrícula nem mensalidade durante o curso</li>
</ul>

<p>O processo seletivo é gratuito ou cobra taxa reduzida (varia por edital). Candidatos em situação de vulnerabilidade socioeconômica podem solicitar isenção da taxa, se houver. As regras específicas estão no edital publicado no portal do IFF.</p>

<h2>Como se inscrever no IFF Design Gráfico</h2>

<ol>
  <li>Acessar o portal oficial do IFF em <a href="https://portal.iff.edu.br/" target="_blank" rel="noopener nofollow">portal.iff.edu.br</a></li>
  <li>Localizar o edital do processo seletivo 2026/2 — cursos técnicos subsequentes</li>
  <li>Verificar em quais câmpus o curso de Design Gráfico está sendo ofertado</li>
  <li>Preencher o formulário online com dados pessoais, escolaridade e câmpus de preferência</li>
  <li>Anexar documentação digitalizada (RG, CPF, certificado de conclusão do ensino médio)</li>
  <li>Pagar a taxa de inscrição (se houver) ou solicitar isenção dentro do prazo</li>
  <li>Aguardar o resultado da seleção, geralmente por sorteio público ou análise de histórico</li>
</ol>

<p>O ingresso é pra o segundo semestre de 2026, com aulas começando geralmente em agosto ou setembro. A duração do curso varia entre 18 e 24 meses, divididos em 3 ou 4 semestres letivos.</p>

<h2>O que esperar do mercado de Design Gráfico em 2026</h2>

<p>O mercado de design no Brasil tem crescimento consistente desde a digitalização acelerada pós-pandemia. Profissionais com formação técnica reconhecida costumam ganhar:</p>

<ul>
  <li><strong>Júnior (até 2 anos de experiência):</strong> R\$ 1.800 a R\$ 3.000</li>
  <li><strong>Pleno (2-5 anos):</strong> R\$ 3.000 a R\$ 5.500</li>
  <li><strong>Sênior (5+ anos):</strong> R\$ 5.500 a R\$ 9.000</li>
  <li><strong>Freelancer:</strong> Varia muito (R\$ 50 a R\$ 300/hora dependendo da especialidade)</li>
</ul>

<p>Áreas em alta: UX/UI Design (interfaces digitais), motion design (animação), branding e design de redes sociais. Quem soma a formação técnica com habilidade em ferramentas específicas (Figma, After Effects, Adobe Suite) consegue se posicionar acima da média salarial inicial.</p>

<h2>Por que escolher IFF em vez de curso pago</h2>

<p>O IFF oferece vantagens estruturais que cursos privados pagos não conseguem competir:</p>

<ul>
  <li><strong>Gratuidade total</strong> — economia de R\$ 6.000 a R\$ 15.000 em comparação a cursos técnicos privados</li>
  <li><strong>Diploma reconhecido pelo MEC</strong> — aceito em concursos públicos e empresas grandes</li>
  <li><strong>Estrutura de laboratório</strong> — softwares Adobe, máquinas com configuração profissional</li>
  <li><strong>Estágio supervisionado</strong> — IFF mantém convênios com empresas para estágios remunerados</li>
  <li><strong>Iniciação científica</strong> — projetos de pesquisa em design, com bolsas pra alunos participantes</li>
</ul>

<p>A única desvantagem comparada a cursos pagos é a duração: cursos técnicos online de design costumam ser concluídos em 6 meses, enquanto o IFF leva 18-24 meses. O trade-off é qualidade da formação e reconhecimento profissional.</p>

<h2>Detalhes da oportunidade</h2>

<ul>
  <li><strong>Instituição:</strong> Instituto Federal Fluminense (IFF)</li>
  <li><strong>Curso:</strong> Técnico em Design Gráfico (modalidade subsequente)</li>
  <li><strong>Pré-requisito:</strong> Ensino médio completo</li>
  <li><strong>Custo:</strong> Gratuito (apenas taxa de inscrição se houver, com isenção para vulnerabilidade)</li>
  <li><strong>Duração:</strong> 18 a 24 meses (3 a 4 semestres)</li>
  <li><strong>Modalidade:</strong> Presencial (câmpus específicos do IFF no Rio de Janeiro)</li>
  <li><strong>Diploma:</strong> Técnico em Design Gráfico (reconhecido pelo MEC)</li>
  <li><strong>Início das aulas:</strong> Segundo semestre de 2026</li>
  <li><strong>Forma de inscrição:</strong> Portal oficial portal.iff.edu.br</li>
</ul>
HTML;
$iffSchema = ['@context' => 'https://schema.org', '@type' => 'Course', 'name' => 'Técnico em Design Gráfico Gratuito — Instituto Federal Fluminense (IFF)', 'description' => 'Curso técnico gratuito de Design Gráfico do IFF na modalidade subsequente. Diploma reconhecido pelo MEC. Voltado a quem concluiu o ensino médio.', 'inLanguage' => 'pt-BR', 'isAccessibleForFree' => true, 'educationalCredentialAwarded' => 'Diploma de Técnico em Design Gráfico', 'provider' => ['@type' => 'EducationalOrganization', 'name' => 'Instituto Federal Fluminense', 'sameAs' => 'https://portal.iff.edu.br/'], 'offers' => ['@type' => 'Offer', 'category' => 'Free', 'price' => 0, 'priceCurrency' => 'BRL']];

publicarComPipeline($cfgRoot, 'cursosenac', [
    'titulo' => 'IFF abre curso técnico gratuito de Design Gráfico com diploma reconhecido pelo MEC',
    'slug' => 'iff-design-grafico-curso-tecnico-gratuito-diploma-mec-2026',
    'og_image' => 'https://portal.iff.edu.br/sobre/comunicacao/imagens/2018/marca_iff.jpg',
    'legenda' => 'IFF oferece curso técnico gratuito de Design Gráfico (Foto: IFF/Divulgação)',
    'categoria' => 'Cursos Técnicos',
    'tags' => ['IFF', 'Instituto Federal Fluminense', 'Design Gráfico', 'Curso Técnico Gratuito', 'Rio de Janeiro', 'Diploma MEC', 'Subsequente', 'Educação Profissional'],
    'html' => $iffHtml, 'schema' => $iffSchema, 'schema_tipo' => 'course',
    'kw' => 'IFF Design Gráfico gratuito', 'rd' => 'O Instituto Federal Fluminense abre vagas no curso técnico gratuito de Design Gráfico, com diploma reconhecido pelo MEC. Subsequente (ensino médio completo). Inscrições no portal.iff.edu.br.',
    'queries_ws' => ['Instituto Federal Fluminense IFF campus', 'curso Design Gráfico aluno notebook', 'designer gráfico trabalhando computador', 'IFF Rio de Janeiro fachada', 'Adobe Photoshop tela', 'estudante design ensino técnico', 'designer brasileira trabalho criativo'],
    'meta' => ['rank_math_title' => 'IFF Design Gráfico 2026: curso técnico gratuito com diploma MEC', 'rank_math_description' => 'IFF abre curso técnico de Design Gráfico gratuito, diploma MEC, modalidade subsequente. Para quem tem ensino médio completo. Sem mensalidade.', 'rank_math_focus_keyword' => 'iff design grafico gratuito'],
], $keys);

// ════════════════════════════════════════════════════════════════════
// 4. comocomprar · Panelas Pressão Electrolux + Mondial
// ════════════════════════════════════════════════════════════════════
$ctaAmazonMeio = "<div class='cta-afiliado' style='text-align:center;margin:32px 0;padding:24px;background:#fff8e7;border:2px dashed #ff9900;border-radius:8px;'><p style='margin:0 0 14px;font-size:16px;color:#333;'><strong>Encontrou o modelo certo?</strong></p><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:17px;padding:14px 28px;border-radius:6px;text-decoration:none;'>🛒 Ver oferta na Amazon</a><p style='margin:12px 0 0;font-size:12px;color:#888;'>Link de afiliado: apoia o portal sem custo adicional pra você</p></div>";
$ctaAmazonFim = "<div class='cta-afiliado cta-fim' style='text-align:center;margin:32px 0;padding:20px;background:#fff8e7;border:2px solid #ff9900;border-radius:8px;'><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:18px;padding:16px 32px;border-radius:6px;text-decoration:none;'>🛒 Comprar agora na Amazon</a></div>";
$panelaHtml = <<<HTML
<p>Duas panelas de pressão elétricas entraram em oferta nesta segunda-feira (12) com preços que cortam até 50% do valor original. A <strong>Electrolux PCE15 Rita Lobo</strong> saiu de R\$ 539,90 para R\$ 272,90, e a <strong>Mondial Master Cooker PE-40</strong> caiu de R\$ 629,90 para R\$ 349,79. Os dois modelos atendem perfis distintos: a Electrolux foca em compactos e segurança, a Mondial em capacidade maior e visual robusto, segundo apurou a redação do Como Comprar.</p>

<h2>Por que panela de pressão elétrica vale a pena</h2>

<p>O eletrodoméstico se popularizou nos últimos anos por entregar 3 benefícios concretos no dia a dia:</p>

<ul>
  <li><strong>Não exige monitoramento</strong> — diferente da panela a gás, não precisa ficar olhando pra pressão</li>
  <li><strong>Cozimento mais rápido</strong> — pressão a vapor reduz tempo de preparo de feijão, carne de panela, sopas, em até 60%</li>
  <li><strong>Segurança alta</strong> — modelos modernos têm 9 a 12 travas que impedem abertura com pressão interna</li>
</ul>

<p>O contra principal é o consumo de energia (maior que a panela a gás), mas considerando o tempo economizado e a praticidade, costuma compensar pra famílias que cozinham 4-5 dias por semana.</p>

<h2>1. Electrolux PCE15 Rita Lobo — R\$ 272,90 (de R\$ 539,90)</h2>

<p>A Electrolux PCE15 Rita Lobo é o modelo compacto da linha. Os destaques:</p>

<ul>
  <li><strong>Capacidade:</strong> 3 litros — ideal pra 1 a 3 pessoas</li>
  <li><strong>9 travas de segurança</strong> — impedem abertura sob pressão</li>
  <li><strong>Funcionamento silencioso</strong> — sem o barulho típico da panela tradicional</li>
  <li><strong>Programas pré-definidos</strong> — feijão, carne, arroz, sopa, vapor</li>
  <li><strong>Painel digital</strong> — controle simples por botões</li>
</ul>

<p>O tamanho de 3L é o ponto mais limitante: não comporta grandes quantidades. Mas pra solteiros, casais e famílias de 2-3 pessoas, atende bem o cotidiano. A linha Rita Lobo, da Electrolux, foca em receitas brasileiras (feijão, carne de panela, arroz integral) com configurações pré-otimizadas.</p>

{$ctaAmazonMeio}

<h2>2. Mondial Master Cooker PE-40 — R\$ 349,79 (de R\$ 629,90)</h2>

<p>A Mondial Master Cooker PE-40 é o modelo maior e mais versátil. Os destaques:</p>

<ul>
  <li><strong>Capacidade:</strong> 4 litros — atende famílias de 4 a 6 pessoas</li>
  <li><strong>Cor preta</strong> — visual moderno, combina com cozinhas planejadas</li>
  <li><strong>Modos múltiplos</strong> — pressão, cozimento lento, vapor, refogado</li>
  <li><strong>Timer programável</strong> — agenda cozimento pra quando você chegar</li>
  <li><strong>Antiaderente interno</strong> — facilita limpeza</li>
</ul>

<p>Vale R\$ 77 a mais que a Electrolux justamente pela maior capacidade e versatilidade. Pra quem tem família grande ou recebe convidados com frequência, é a escolha mais sensata. O design preto também é diferencial estético importante pra cozinhas com bancadas de mármore ou granito escuro.</p>

<h2>Qual escolher entre as duas</h2>

<p>A decisão depende basicamente de 2 fatores: tamanho da casa e orçamento.</p>

<ul>
  <li><strong>Compre Electrolux 3L se:</strong> mora sozinho, casal sem filhos, prioriza espaço na bancada (ela é menor), orçamento de R\$ 250-280</li>
  <li><strong>Compre Mondial 4L se:</strong> família 4+ pessoas, recebe convidados, quer cozinhar grandes quantidades, orçamento de R\$ 330-360</li>
</ul>

<p>Em qualidade de cozimento, as duas são equivalentes — entregam pressão similar e tempo de preparo parecido pra receitas básicas. A diferença é capacidade e visual.</p>

<h2>Quando vale a pena trocar a panela a gás pela elétrica</h2>

<p>Pra quem ainda usa panela de pressão tradicional a gás, vale fazer a conta antes de migrar:</p>

<ul>
  <li><strong>Frequência:</strong> se cozinha 1-2x por semana, panela a gás compensa (custo zero de energia elétrica). 4+ vezes por semana, a elétrica economiza tempo</li>
  <li><strong>Família:</strong> em casa com idosos ou crianças, a elétrica é mais segura (sem chama, sem risco de explosão)</li>
  <li><strong>Orçamento de energia:</strong> elétrica gasta mais energia, mas menos gás — o saldo costuma ser neutro a positivo</li>
  <li><strong>Espaço:</strong> elétrica ocupa bancada permanente; a gás é guardada no armário</li>
</ul>

<p>Pra quem mora em apartamento sem gás encanado, a elétrica é praticamente obrigatória — economia de gás de cozinha cobre o gasto extra de energia em 6-8 meses.</p>

<h2>O que evitar na hora da compra</h2>

<p>Três armadilhas comuns em panela de pressão elétrica:</p>

<ul>
  <li><strong>Capacidade abaixo de 3L</strong> — modelos menores frustram em pouco tempo (não cabem 1kg de feijão completo)</li>
  <li><strong>Menos de 7 travas de segurança</strong> — modelos mais antigos têm 5-6 travas, considerados defasados pelos padrões atuais</li>
  <li><strong>Marcas desconhecidas sem garantia BR</strong> — peças de reposição (anel de borracha da tampa, válvula) são impossíveis de achar depois</li>
</ul>

<p>Selo Inmetro + garantia mínima de 1 ano são fundamentais. Tanto Electrolux quanto Mondial atendem esses requisitos.</p>

{$ctaAmazonFim}

<h2>Detalhes das ofertas</h2>

<ul>
  <li><strong>Electrolux PCE15 Rita Lobo:</strong> R\$ 272,90 (de R\$ 539,90 — 49% off)</li>
  <li><strong>Mondial Master Cooker PE-40:</strong> R\$ 349,79 (de R\$ 629,90 — 44% off)</li>
  <li><strong>Disponibilidade:</strong> Amazon Brasil (links de afiliado nos botões acima)</li>
  <li><strong>Garantia:</strong> 1 ano de fabricante (padrão)</li>
  <li><strong>Frete:</strong> Grátis pra Prime, valor variável pra não-assinantes</li>
</ul>
HTML;
$panelaSchema = ['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => 'Panelas pressão elétricas Electrolux PCE15 e Mondial PE-40 em oferta', 'datePublished' => date('c'), 'inLanguage' => 'pt-BR'];

publicarComPipeline($cfgRoot, 'comocomprar', [
    'titulo' => 'Panelas de pressão elétricas em oferta: Electrolux Rita Lobo por R\$ 272,90 e Mondial PE-40 por R\$ 349,79',
    'slug' => 'panelas-pressao-eletricas-electrolux-mondial-oferta',
    'og_image' => 'https://img.odcdn.com.br/wp-content/uploads/2026/05/Design-sem-nome-2026-05-12T132656.090.png',
    'legenda' => 'Panelas de pressão elétricas em oferta: Electrolux e Mondial (Foto: divulgação)',
    'categoria' => 'Cozinha e Eletrodomésticos',
    'tags' => ['Electrolux', 'Mondial', 'Panela Pressão Elétrica', 'Rita Lobo', 'Master Cooker', 'Cozinha', 'Eletrodomésticos', 'Amazon', 'Ofertas'],
    'html' => $panelaHtml, 'schema' => $panelaSchema, 'schema_tipo' => 'newsarticle',
    'kw' => 'panela pressão elétrica oferta', 'rd' => 'Duas panelas de pressão elétricas em oferta hoje: Electrolux PCE15 Rita Lobo R\$ 272,90 e Mondial PE-40 R\$ 349,79. Veja diferenças e qual escolher.',
    'queries_ws' => ['panela pressão elétrica preta cozinha', 'Electrolux Rita Lobo panela', 'Mondial Master Cooker', 'cozinhar feijão panela pressão', 'painel digital panela elétrica', 'cozinha brasileira moderna', 'oferta eletrodoméstico Amazon'],
    'meta' => ['rank_math_title' => 'Panelas pressão Electrolux + Mondial em oferta: a partir de R\$ 272,90', 'rank_math_description' => 'Panela pressão elétrica Electrolux PCE15 Rita Lobo R\$ 272,90 e Mondial PE-40 R\$ 349,79. Comparativo, segurança, qual escolher. CTAs Amazon afiliado.', 'rank_math_focus_keyword' => 'panela pressao eletrica oferta'],
], $keys);

// Limpa filas + marca trends
echo "\n═══ Limpa filas ═══\n";
$arquivos = [
    '/app/data/queue_gerar/leaodabarra/18066.json',
    '/app/data/queue_gerar/leaodabarra/17354.json',
    '/app/data/queue_gerar/cursosenac/17710.json',
    '/app/data/queue_gerar/comocomprar/17505.json',
];
foreach ($arquivos as $f) {
    if (is_file($f)) { @unlink($f); echo "  ✓ " . basename($f) . "\n"; }
}
$pdo = DbConnection::pdo();
$st = $pdo->prepare("UPDATE trends SET status='publicado' WHERE id IN (18066, 17354, 17710, 17505)");
$st->execute();
echo "  ✓ " . $st->rowCount() . " trends marcadas publicadas\n";
echo "\nFEITO\n";
