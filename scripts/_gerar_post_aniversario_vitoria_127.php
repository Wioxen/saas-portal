<?php
declare(strict_types=1);
/**
 * Gera post celebrando 127 anos do EC Vitória (13/05/1899 -> 13/05/2026).
 *
 * Fonte: trend #18568 capturado pelo Pingo via fonte RSS 'A Tarde (atarde.com.br)'.
 * URL: https://atarde.com.br/esportes/ecvitoria/127-anos-de-vitoria-relembre-a-historia-e-os-grandes-momentos-do-leao-1388684
 * Autor da fonte: Gustavo Nascimento (A Tarde).
 *
 * Conteúdo reescrito por Opus em sessão Claude Code (sem chamada LLM API)
 * com base no scraping do A Tarde (validado) + manifesto editorial leaodabarra.
 *
 * Publica como DRAFT em leaodabarra.com.br via WP REST.
 * Featured image: og:image do A Tarde (foto real Victor Ferreira | EC Vitória),
 * conforme política 'imagem_featured_estrategia=og_only' do leaodabarra.
 *
 * Uso:
 *   php scripts/_gerar_post_aniversario_vitoria_127.php
 *
 * O trend #18568 NÃO é atualizado pelo script (DB remoto). Após publicar,
 * rodar manualmente via SSH:
 *   UPDATE trends SET status='publicado', post_id=<id>, url_post='<link>' WHERE id=18568;
 */

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();

$slugSite = 'leaodabarra';
$trendId  = 18568;
$fonteUrl = 'https://atarde.com.br/esportes/ecvitoria/127-anos-de-vitoria-relembre-a-historia-e-os-grandes-momentos-do-leao-1388684';
$ogImg    = 'https://cdn.atarde.com.br/img/Artigo-Destaque/1380000/127-anos-de-vitoria-relembre-a-historia-e-os-grand0138868400202605122330.jpg?xid=7075575';

$titulo   = 'Vitória completa 127 anos hoje, dia de jogo decisivo contra o Flamengo no Barradão';
$slug     = 'vitoria-127-anos-aniversario-flamengo-copa-brasil-barradao-13-maio-2026';
$metaDesc = 'O Esporte Clube Vitória completa 127 anos em 13 de maio de 2026 e enfrenta o Flamengo às 21h30, no Barradão, pela Copa do Brasil. Veja a história do Leão da Barra.';
$focusKw  = 'vitoria 127 anos aniversario';

// ──────────────────────────────────────────────────────────────────
// CONTEÚDO (escrito por Opus com base no scraping do A Tarde)
// ──────────────────────────────────────────────────────────────────
$html = <<<'HTML'
<p>O Esporte Clube Vitória completa <strong>127 anos</strong> nesta quarta-feira, 13 de maio de 2026. A data marca a fundação do clube em 1899, no Corredor da Vitória, em Salvador, conforme registro histórico do jornal A Tarde.</p>

<p>O 127º aniversário cai na véspera de noite decisiva no Barradão. Nesta quinta-feira (14), às 21h30, o Leão recebe o Flamengo pela volta dos 16 avos da Copa do Brasil, em jogo que pode classificar o rubro-negro às oitavas.</p>

<h2>A fundação em 13 de maio de 1899: o pioneirismo do Leão</h2>

<p>O Vitória nasceu como Club de Cricket Victoria, fundado pelos irmãos Artur e Artêmio Valente no casarão da família, no bairro nobre da Vitória. O nome veio do próprio bairro onde os fundadores moravam.</p>

<p>Mais do que um clube esportivo, o Vitória foi <strong>o primeiro clube social do Brasil constituído integralmente por brasileiros</strong>. Também foi <strong>o primeiro clube de futebol do Nordeste</strong>, segundo registro do jornal A Tarde.</p>

<p>O pioneirismo seguiu na criação da Liga Bahiana de Sports Terrestres, organização que realizou a primeira edição do Campeonato Baiano de Futebol em 1905.</p>

<h2>1908 e 1909: os primeiros títulos baianos do Vitória</h2>

<p>Os primeiros troféus estaduais vieram em 1908 e 1909, consecutivos. Depois desses dois títulos, o Leão entrou em longa seca: foram mais de quatro décadas sem conquistas no futebol baiano.</p>

<p>Durante o período, o clube manteve-se ativo em outros esportes e na vida social de Salvador. Mas no campo principal, esperou até a década de 1950 para retomar protagonismo.</p>

<h2>1953: a profissionalização do futebol rubro-negro</h2>

<p>O Vitória só se profissionalizou como clube de futebol em 1953, sob a presidência de Luiz Martins Catharino Gordilho. A partir daquele momento, o time passou a mandar seus jogos na Fonte Nova, segundo apuração do A Tarde.</p>

<p>Na época, o clube tinha apenas dois títulos do Campeonato Baiano. Estava atrás do Galícia (4), do Botafogo-BA (7), do Ypiranga (10) e do Bahia (13), seu maior rival até hoje.</p>

<p>A virada veio com o tempo: o Vitória se consolidou como uma das grandes potências do futebol baiano e nordestino nas décadas seguintes.</p>

<h2>1986: o Barradão muda o patamar do Leão</h2>

<p>A inauguração do Estádio Manoel Barradas, em novembro de 1986, virou divisor de águas. O Vitória escolheu Canabrava para erguer seu estádio próprio, batizado em homenagem a uma das maiores figuras da história rubro-negra.</p>

<p>O número escancara o impacto. Da fundação em 1899 até 1986, o clube havia conquistado <strong>10 títulos baianos em 87 anos</strong>, média de uma taça a cada nove anos. Após o Barradão, foram <strong>20 troféus em 39 edições do estadual</strong>.</p>

<p>O estádio também participou da revitalização de Canabrava, segundo o A Tarde. O Vitória se tornou agente de desenvolvimento socioeconômico do bairro nas décadas seguintes.</p>

<h2>Década de 1990: Bebeto, Dida e Vampeta no vice de 1993</h2>

<p>Os anos 1990 foram a fase mais gloriosa do Vitória. O clube se organizou administrativamente e suas categorias de base viraram referência nacional, revelando craques como Bebeto, Dida e Vampeta, todos com passagem por seleções campeãs mundiais.</p>

<p>No campo, a melhor campanha da história saiu em 1993. O Leão foi vice-campeão brasileiro da Série A, parado apenas pelo Palmeiras na final, com Alex Alves e Rodrigo Chagas como protagonistas.</p>

<p>O time ainda chegou às semifinais do Brasileirão de 1999. No mesmo período, conquistou seis títulos do Baianão e duas Copas do Nordeste (1997 e 1999).</p>

<h2>Anos 2000: tetra do Baianão e final da Copa do Brasil em 2010</h2>

<p>O domínio regional se expandiu nos anos 2000. O Vitória foi tetracampeão baiano em dois ciclos: 2002-2005 e 2007-2010, segundo registro do A Tarde.</p>

<p>A Copa do Nordeste teve mais duas conquistas, em 2003 e 2010. No mesmo ano de 2010, o Leão chegou à final da Copa do Brasil, sendo derrotado pelo Santos de Neymar.</p>

<p>Em 2013, veio a melhor campanha do clube no Brasileirão de pontos corridos: 5º lugar geral, a maior colocação de um time nordestino nesse formato.</p>

<h2>Queda e redenção: do rebaixamento de 2018 ao acesso de 2023</h2>

<p>A segunda metade dos anos 2010 foi cruel com o rubro-negro. Após o 5º lugar de 2013, o Vitória foi rebaixado em 2014 e voltou em 2015. Em 2018, caiu de novo para a Série B.</p>

<p>O fundo do poço chegou em 2021. O time foi rebaixado para a Série C, indo pela segunda vez na história à terceira divisão. Em 2022, conseguiu acesso histórico após quase cair para a Série D.</p>

<p>A redenção saiu em 2023. O Vitória foi campeão da Série B do Brasileirão, primeiro título nacional da história do clube, e voltou à elite com festa no Barradão.</p>

<h2>O Leão em 2026: terceiro ano consecutivo na Série A</h2>

<p>O Vitória vive seu terceiro ano seguido na Série A do Brasileirão, com estabilidade administrativa e financeira que não via há décadas. Sob comando do técnico Jair Ventura, o time está atualmente em 10º lugar, com 19 pontos.</p>

<p>O Baianão de 2024 reforçou o momento, com o Leão superando o Bahia para retomar o título estadual, jejum interrompido desde 2017.</p>

<p>No campo institucional, o clube anunciou o projeto da nova Arena Barradão, com capacidade para até 60 mil pessoas e investimento previsto na casa dos R$ 500 milhões. A maquete já estará exposta na Copa do Mundo dos Estados Unidos.</p>

<h2>Vitória x Flamengo nesta quinta (14): o presente do aniversário</h2>

<p>O 127º aniversário tem sequência imediata. Às 21h30 desta quinta-feira, 14 de maio, o Vitória recebe o Flamengo no Barradão, pela volta dos 16 avos da Copa do Brasil.</p>

<p>A torcida esgotou os ingressos do confronto. A expectativa é de casa cheia para empurrar o time em busca da classificação às oitavas, fechando a semana de celebração rubro-negra.</p>

<details class='faq-discover'>
<summary><strong>Quando foi fundado o Esporte Clube Vitória?</strong></summary>
<p>O Esporte Clube Vitória foi fundado em 13 de maio de 1899, no bairro da Vitória, em Salvador. Os fundadores foram os irmãos Artur e Artêmio Valente. O nome inicial foi Club de Cricket Victoria.</p>
</details>

<details class='faq-discover'>
<summary><strong>Por que o Vitória é considerado pioneiro no futebol brasileiro?</strong></summary>
<p>O Vitória foi o primeiro clube de futebol do Nordeste e o primeiro clube social do Brasil constituído integralmente por brasileiros, segundo registro do jornal A Tarde. O Leão também participou da fundação da Liga Bahiana de Sports Terrestres, que organizou o primeiro Campeonato Baiano de Futebol em 1905.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quando o Vitória se profissionalizou no futebol?</strong></summary>
<p>O Vitória se profissionalizou como clube de futebol em 1953, sob a presidência de Luiz Martins Catharino Gordilho. A partir daquele ano, o time passou a mandar seus jogos na Fonte Nova.</p>
</details>

<details class='faq-discover'>
<summary><strong>Que craques o Vitória revelou nas categorias de base?</strong></summary>
<p>O Vitória revelou nomes como Bebeto, Dida e Vampeta, todos com passagem por seleções brasileiras campeãs em Copas do Mundo. Alex Alves e Rodrigo Chagas também se destacaram na campanha do vice brasileiro de 1993.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quantos títulos do Campeonato Baiano o Vitória conquistou?</strong></summary>
<p>O Vitória soma 30 títulos do Campeonato Baiano. Foram 10 títulos do estadual da fundação até 1986, ano da inauguração do Barradão, e 20 títulos em 39 edições disputadas após a inauguração do estádio próprio.</p>
</details>

<p><strong>Fonte:</strong> reportagem de Gustavo Nascimento publicada em A Tarde em 13 de maio de 2026. <a href='https://atarde.com.br/esportes/ecvitoria/127-anos-de-vitoria-relembre-a-historia-e-os-grandes-momentos-do-leao-1388684' target='_blank' rel='noopener'>Veja a matéria original</a>.</p>

<p><em>Atualizado em 13 de maio de 2026.</em></p>
HTML;

// ──────────────────────────────────────────────────────────────────
// SCHEMAS
// ──────────────────────────────────────────────────────────────────
$schemaNews = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => $titulo,
    'datePublished' => date('c'),
    'dateModified' => date('c'),
    'inLanguage' => 'pt-BR',
    'about' => [
        '@type' => 'SportsTeam',
        'name' => 'Esporte Clube Vitória',
        'sport' => 'Futebol',
        'location' => [
            '@type' => 'Place',
            'name' => 'Salvador, Bahia, Brasil',
        ],
        'foundingDate' => '1899-05-13',
        'sameAs' => 'https://pt.wikipedia.org/wiki/Esporte_Clube_Vit%C3%B3ria',
    ],
    'citation' => [
        '@type' => 'NewsArticle',
        'headline' => '127 anos de Vitória: relembre a história e os grandes momentos do Leão',
        'url' => $fonteUrl,
        'publisher' => ['@type' => 'NewsMediaOrganization', 'name' => 'A Tarde'],
        'author' => ['@type' => 'Person', 'name' => 'Gustavo Nascimento'],
    ],
];

$schemaFaq = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [
        [
            '@type' => 'Question',
            'name' => 'Quando foi fundado o Esporte Clube Vitória?',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Esporte Clube Vitória foi fundado em 13 de maio de 1899, no bairro da Vitória, em Salvador, pelos irmãos Artur e Artêmio Valente. O nome inicial foi Club de Cricket Victoria.'],
        ],
        [
            '@type' => 'Question',
            'name' => 'Por que o Vitória é considerado pioneiro no futebol brasileiro?',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Vitória foi o primeiro clube de futebol do Nordeste e o primeiro clube social do Brasil constituído integralmente por brasileiros. O Leão também participou da fundação da Liga Bahiana de Sports Terrestres, que organizou o primeiro Campeonato Baiano em 1905.'],
        ],
        [
            '@type' => 'Question',
            'name' => 'Quando o Vitória se profissionalizou no futebol?',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Vitória se profissionalizou em 1953, sob a presidência de Luiz Martins Catharino Gordilho. A partir daquele ano, passou a mandar seus jogos na Fonte Nova.'],
        ],
        [
            '@type' => 'Question',
            'name' => 'Que craques o Vitória revelou nas categorias de base?',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Vitória revelou nomes como Bebeto, Dida e Vampeta, todos com passagem por seleções brasileiras campeãs em Copas do Mundo. Alex Alves e Rodrigo Chagas se destacaram na campanha do vice brasileiro de 1993.'],
        ],
        [
            '@type' => 'Question',
            'name' => 'Quantos títulos do Campeonato Baiano o Vitória conquistou?',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Vitória soma 30 títulos do Campeonato Baiano. Foram 10 títulos do estadual da fundação até 1986, ano da inauguração do Barradão, e 20 títulos em 39 edições disputadas após a inauguração do estádio próprio.'],
        ],
    ],
];

$contentFinal = $html
    . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
    . json_encode($schemaNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n"
    . "<script type=\"application/ld+json\" data-faqpage=\"1\">\n"
    . json_encode($schemaFaq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

// ──────────────────────────────────────────────────────────────────
// PUBLICAÇÃO via WP REST
// ──────────────────────────────────────────────────────────────────
echo "═══════ {$slugSite} — Aniversário 127 anos EC Vitória (via pingo #{$trendId}) ═══════\n";
echo "Fonte: A Tarde (Gustavo Nascimento)\n";
echo "URL fonte: {$fonteUrl}\n";
echo "OG image: {$ogImg}\n\n";

$cfgSite = $cfg;
aplicarSite($cfgSite, $sites, $slugSite);
$wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

// Featured via og:image do A Tarde (foto real: Victor Ferreira | EC Vitória)
// Usa 16:9 crop pra cumprir requisito Discover (1200x675 landscape).
$featuredId = 0;
try {
    $featuredId = (int)($wp->uploadImagemPorUrl169($ogImg, $titulo, $slug) ?? 0);
    if ($featuredId > 0) echo "✅ Featured 16:9 enviada: media #{$featuredId}\n";
} catch (Throwable $e) {
    echo "uploadImagemPorUrl169 falhou: " . $e->getMessage() . "\n";
}
if ($featuredId === 0) {
    try {
        $featuredId = (int)($wp->uploadImagemPorUrl($ogImg, $titulo, $slug) ?? 0);
        if ($featuredId > 0) echo "✅ Featured original enviada: media #{$featuredId}\n";
    } catch (Throwable $e) {
        echo "uploadImagemPorUrl fallback falhou: " . $e->getMessage() . "\n";
    }
}
if ($featuredId > 0) {
    $wp->atualizarMedia($featuredId, [
        'caption'     => 'Esporte Clube Vitória completa 127 anos em 13 de maio de 2026 (Foto: Victor Ferreira | EC Vitória).',
        'description' => "Imagem ilustrativa da matéria '{$titulo}'.",
        'title'       => $titulo,
        'alt_text'    => 'Esporte Clube Vitória 127 anos: Leão da Barra fundado em 13 de maio de 1899',
    ]);
}

// Categoria + tags
$cm = new CategoryMatcher($wp, 70.0);
$catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['História do Vitória']))));
if (empty($catIds)) {
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Vitória']))));
}

$tags = ['Esporte Clube Vitória', 'Aniversário do Vitória', 'História do Vitória', 'Leão da Barra', 'Barradão', '127 anos', 'Salvador', 'Bahia', 'Copa do Brasil', 'Flamengo'];
$tagIds = $wp->resolverTags($tags);

$payload = [
    'title'   => $titulo,
    'slug'    => $slug,
    'content' => $contentFinal,
    'status'  => 'draft',
    'meta'    => [
        'rank_math_title'         => "Vitória 127 anos hoje + jogo contra o Flamengo no Barradão | Leão da Barra",
        'rank_math_description'   => $metaDesc,
        'rank_math_focus_keyword' => $focusKw,
    ],
    'categories' => $catIds,
    'tags'       => $tagIds,
];
if ($featuredId > 0) $payload['featured_media'] = $featuredId;
if (!empty($cfgSite['default_post_author_id'])) {
    $payload['author'] = (int)$cfgSite['default_post_author_id'];
}

$r = $wp->criarPost($payload);
$postId = (int)($r['id'] ?? 0);
$link   = (string)($r['link'] ?? '');

if ($postId === 0) {
    echo "ERRO: criarPost não retornou ID. Resposta:\n";
    var_export($r);
    exit(1);
}

echo "\n✅ Post #{$postId} criado como DRAFT\n";
echo "Link: {$link}\n";
echo "Admin: {$cfgSite['wp_url']}/wp-admin/post.php?post={$postId}&action=edit\n";

// Posts relacionados (best effort)
try {
    $rel = $wp->buscarRelacionados('vitória', 4, $postId);
    if (is_array($rel) && count($rel) >= 2) {
        $bloco = "\n<aside class='posts-relacionados'>\n<h2>Veja também</h2>\n<ul>\n";
        foreach (array_slice($rel, 0, 4) as $r2) {
            $titRel = htmlspecialchars(html_entity_decode((string)$r2['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $linkRel = htmlspecialchars((string)$r2['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $bloco .= "  <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
        }
        $bloco .= "</ul>\n</aside>\n";
        $p2 = $wp->getPost($postId);
        $contentRaw = $p2['content']['raw'] ?? $contentFinal;
        $wp->atualizarPost($postId, ['content' => $contentRaw . $bloco]);
        echo "✅ Posts relacionados anexados: " . min(4, count($rel)) . "\n";
    }
} catch (Throwable $e) {
    echo "Posts relacionados: erro silencioso (" . $e->getMessage() . ")\n";
}

echo "\n══════ PRÓXIMOS PASSOS ═══════\n";
echo "1. Revisar: {$cfgSite['wp_url']}/wp-admin/post.php?post={$postId}&action=edit\n";
echo "2. PUBLICAR (status já tem featured image + categoria + tags + 2 schemas)\n";
echo "3. Atualizar trend #{$trendId} no DB remoto via SSH:\n";
echo "   UPDATE trends SET status='publicado', post_id={$postId}, url_post='{$link}' WHERE id={$trendId};\n";
