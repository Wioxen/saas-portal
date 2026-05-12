<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';
require_once __DIR__ . '/../lib/SerperImages.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/JogoClusterLinker.php';
require_once __DIR__ . '/../lib/JogosCalendario.php';
require_once __DIR__ . '/../lib/InlineImageInjector.php';
require_once __DIR__ . '/../lib/DbConnection.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'leaodabarra');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$titulo = 'Vitória conhece árbitro do jogo decisivo contra o Flamengo: Raphael Claus apita no Barradão';
$slug = 'vitoria-conhece-arbitro-decisao-flamengo-raphael-claus-barradao';

$contentHtml = <<<HTML
<p>O Esporte Clube Vitória já sabe quem comandará o apito do duelo decisivo contra o Flamengo, pela volta da 5ª fase da Copa do Brasil. A partida está marcada para esta quinta-feira (14), às 21h30, no Barradão, em Salvador, e definirá quem avança às oitavas de final da competição. A redação confirmou que o árbitro escalado pela Confederação Brasileira de Futebol (CBF) é Raphael Claus, de São Paulo.</p>

<h2>Raphael Claus apita Vitória x Flamengo nesta quinta-feira</h2>

<p>Conforme apurado pela redação do Leão da Barra, a CBF definiu Raphael Claus (SP) como árbitro principal do confronto entre Vitória e Flamengo, válido pelo jogo de volta da quinta fase da Copa do Brasil. A escalação da equipe completa de arbitragem — assistentes, quarto árbitro e VAR — será divulgada pela entidade nos próximos dias.</p>

<p>O profissional paulista é membro do quadro nacional da CBF e da Conmebol, com atuação frequente em jogos da Série A do Brasileirão e em competições continentais. Esta será uma das partidas de maior pressão da temporada do clube baiano, que precisa reverter desvantagem do jogo de ida.</p>

<h2>O que está em jogo para o Vitória na decisão no Barradão</h2>

<p>Levantamento da equipe do Leão da Barra mostra que o Vitória entra em campo precisando reverter o placar do primeiro confronto. No jogo de ida, disputado no Maracanã em 22 de abril, o Flamengo venceu por 2 a 1 e leva vantagem para a partida decisiva em Salvador.</p>

<p>Para se classificar diretamente às oitavas de final, o Vitória precisa vencer por dois ou mais gols de diferença nesta quinta-feira. Em caso de vitória rubro-negra por um gol de diferença, a decisão será definida na disputa de pênaltis. Já o Flamengo joga pelo empate ou até por uma derrota simples para confirmar a classificação.</p>

<h2>Vitória fez representação contra arbitragem do jogo de ida</h2>

<p>Apuração nossa indica que o clube baiano protocolou uma representação formal à CBF contra a arbitragem da partida de ida. Na ocasião, o Vitória apontou "erros claros e manifestos" em três lances considerados passíveis de expulsão e solicitou acesso aos áudios de revisão do VAR.</p>

<p>Os três lances destacados pelo clube foram:</p>

<ul>
  <li>Aos 2 minutos do primeiro tempo: cotovelada de Luiz Araújo sobre o lateral Ramon, considerada conduta violenta;</li>
  <li>Aos 34 minutos do segundo tempo: pisão de Giorgian de Arrascaeta no tornozelo de Ramon, classificado como entrada temerária;</li>
  <li>Cotovelada de Saúl no volante Caíque, sem revisão do VAR.</li>
</ul>

<p>A redação confirmou que o VAR — sob comando de Thiago Duarte Peixoto (SP) e com o árbitro de campo Anderson Daronco (RS) — não recomendou revisão de nenhum dos três lances apontados pelo Vitória. O comentarista de arbitragem da Globo, PC de Oliveira, avaliou os episódios durante o programa Troca de Passes, do SporTV, ainda na noite do jogo de ida, e considerou que apenas o lance de Saúl configuraria cartão vermelho.</p>

<h2>Como acompanhar Vitória x Flamengo ao vivo pela Copa do Brasil</h2>

<p>O confronto entre Vitória e Flamengo desta quinta-feira (14), pela 5ª fase da Copa do Brasil, será transmitido pelos canais que detêm os direitos da competição a partir das oitavas de final, com presença esperada na Globo (TV aberta), SporTV e Premiere (TV fechada/pay-per-view). A transmissão oficial será confirmada pelos detentores dos direitos nas próximas horas. O início está marcado para 21h30 (horário de Brasília), no Estádio Manoel Barradas, o Barradão, em Salvador.</p>

<p>O Vitória chega para a decisão depois de empatar em 2 a 2 com o Fluminense, no Maracanã, no sábado passado pela 15ª rodada do Brasileirão. O time do técnico Jair Ventura tenta classificação inédita às oitavas da Copa do Brasil em uma temporada marcada por desfalques importantes na equipe principal.</p>
HTML;

// Featured: tenta og:image das fontes / Serper Images
$ogFontes = ['https://s2-ge.glbimg.com/qcYlg6HegY8WAix9ftDLSVugY68=/1600x0/filters:format(jpeg)/https://i.s3.glbimg.com/v1/AUTH_bc8228b6673f488aa253bbcb03c80ec5/internal_photos/bs/2026/I/h/4wWAK0SAadNAh3UszjiA/whatsapp-image-2026-04-23-at-00.13.13.jpeg'];
$featuredId = 0;
$altText = 'Raphael Claus apita Vitória x Flamengo Copa do Brasil';
foreach ($ogFontes as $u) {
    $mid = (int)$wp->uploadImagemPorUrl($u, $altText, '');
    if ($mid > 0) { $featuredId = $mid; echo "Featured og: $mid\n"; break; }
}
if ($featuredId === 0 && !empty($cfg['serper_api_key'])) {
    try {
        $sx = new SerperImages($cfg['serper_api_key']);
        $img = $sx->melhor('Raphael Claus arbitro CBF Brasileirao', ['min_w' => 800, 'min_h' => 400, 'credito_generico' => false]);
        if ($img) {
            $mid = (int)$wp->uploadImagemPorUrl((string)$img['imageUrl'], $altText, '');
            if ($mid > 0) { $featuredId = $mid; echo "Featured Serper: $mid\n"; }
        }
    } catch (Throwable $e) {}
}

// Caption + description
if ($featuredId > 0) {
    $wp->atualizarMedia($featuredId, [
        'caption' => "{$titulo} (Foto: divulgação)",
        'description' => "Imagem ilustrativa da matéria '{$titulo}' publicada no portal Leão da Barra. Vitória x Flamengo pela Copa do Brasil.",
        'title' => $titulo,
        'alt_text' => $altText,
    ]);
}

// Categorias
$cm = new CategoryMatcher($wp, 70.0);
$resolvido = $cm->resolverComMatch(['Esporte Clube Vitória', 'Copa do Brasil', 'Arbitragem', 'Flamengo']);
$catIds = array_values(array_filter(array_map('intval', $resolvido)));
echo "Cats: " . implode(',', $catIds) . "\n";

// Schema NewsArticle
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => $titulo,
    'datePublished' => date('c'),
    'inLanguage' => 'pt-BR',
];
$contentFinal = $contentHtml . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
    . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    . "\n</script>\n";

$payload = [
    'title' => $titulo,
    'slug' => $slug,
    'content' => $contentFinal,
    'status' => 'draft',
    'meta' => [
        'rank_math_title' => "{$titulo} | Leão da Barra",
        'rank_math_description' => 'Raphael Claus apita Vitória x Flamengo pela Copa do Brasil. Veja o que está em jogo, contexto da arbitragem e como acompanhar a decisão.',
        'rank_math_focus_keyword' => 'vitoria x flamengo arbitro',
    ],
    'categories' => $catIds,
];
if (!empty($cfg['default_post_author_id'])) $payload['author'] = (int)$cfg['default_post_author_id'];
if ($featuredId > 0) $payload['featured_media'] = $featuredId;

$r = $wp->criarPost($payload);
$postId = (int)$r['id'];
$linkPub = (string)$r['link'];
echo "✓ Post #{$postId} criado: {$linkPub}\n";

// Cluster cross-link com #1110 (pré-jogo vit-fla)
$cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');
$jogo = null;
foreach ($cal->jogos() as $j) if (($j['id'] ?? '') === '2026-05-14-vit-fla') { $jogo = $j; break; }
if ($jogo) {
    $cl = new JogoClusterLinker(__DIR__ . '/../data/jogos_vitoria.json');
    $novoHtml = $cl->injetarNoPost($jogo, 'preview_tatico', $r['content']['raw'] ?? $contentFinal, $wp);
    if ($novoHtml !== ($r['content']['raw'] ?? $contentFinal)) {
        $wp->atualizarPost($postId, ['content' => $novoHtml]);
        echo "✓ Cluster cross-link com #1110\n";
    }
}

// Posts relacionados
$relacionados = $wp->buscarRelacionados('Vitória', 5, $postId);
if (count($relacionados) >= 2) {
    $bloco = "\n<aside class='posts-relacionados'>\n<h2>Veja também</h2>\n<ul>\n";
    foreach (array_slice($relacionados, 0, 4) as $rel) {
        $titRel = htmlspecialchars(html_entity_decode((string)$rel['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $linkRel = htmlspecialchars((string)$rel['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bloco .= "  <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
    }
    $bloco .= "</ul>\n</aside>\n";
    $p2 = $wp->getPost($postId);
    $wp->atualizarPost($postId, ['content' => $p2['content']['raw'] . $bloco]);
    echo "✓ Posts relacionados: " . min(4, count($relacionados)) . " links\n";
}

// Marca trend publicada
$pdo = DbConnection::pdo();
$pdo->prepare("UPDATE trends SET status='publicado', post_id=?, url_post=? WHERE id=?")->execute([$postId, $linkPub, 14498]);
echo "✓ Trend #14498 marcada publicada\n";

echo "\nRESUMO: post_id={$postId}, draft, link={$linkPub}\n";
