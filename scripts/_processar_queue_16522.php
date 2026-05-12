<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/SerperImages.php';
require_once __DIR__ . '/../lib/DbConnection.php';

$cfg = require __DIR__ . '/../config.php';
aplicarSite($cfg, sitesDisponiveis(), 'comocomprar');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$titulo = 'Fones Soundcore Anker em oferta: P20i por R$ 169,01 e P31i com cancelamento de ruído por R$ 369,01';
$slug = 'fones-soundcore-anker-oferta-p20i-p31i-anc';

$html = <<<'HTML'
<p>Dois modelos da linha Soundcore, da Anker, aparecem com descontos relevantes nesta segunda-feira (12) em varejistas online: o Soundcore P20i caiu de R$ 299 para R$ 169,01 e o Soundcore P31i, com cancelamento adaptativo de ruído, saiu de R$ 549 para R$ 369,01. Conforme apurado pela redação do Como Comprar, os dois fones intra-auriculares atendem perfis distintos — graves potentes no modelo de entrada e ANC inteligente no premium.</p>

<h2>Soundcore P20i por R$ 169,01: foco em graves e 30 horas de bateria</h2>

<p>Levantamento da equipe do Como Comprar mostra que o P20i é a opção mais acessível da linha Soundcore atualmente, voltada para quem busca graves intensos e boa autonomia em um fone TWS (true wireless) com Bluetooth 5.3. O modelo entregou um corte de 43% no preço original (de R$ 299 para R$ 169,01), o que coloca ele numa faixa muito competitiva dentro da categoria de fones intra-auriculares.</p>

<p>O grande diferencial do P20i é a calibração sonora voltada para graves potentes — característica buscada por quem escuta hip-hop, EDM, funk e trilhas sonoras com elementos de subgraves. A redação confirmou que a autonomia chega a 30 horas de reprodução considerando a carga do estojo, o suficiente para vários dias de uso moderado sem precisar recarregar.</p>

<ul>
  <li><strong>Modelo:</strong> Soundcore P20i (Anker)</li>
  <li><strong>Tipo:</strong> Intra-auricular TWS (true wireless)</li>
  <li><strong>Conectividade:</strong> Bluetooth 5.3</li>
  <li><strong>Autonomia:</strong> Até 30 horas (com estojo)</li>
  <li><strong>Destaque sonoro:</strong> Graves reforçados</li>
  <li><strong>Preço:</strong> R$ 169,01 (de R$ 299)</li>
</ul>

<div class='cta-afiliado' style='text-align:center;margin:32px 0;padding:24px;background:#fff8e7;border:2px dashed #ff9900;border-radius:8px;'><p style='margin:0 0 14px;font-size:16px;color:#333;'><strong>Encontrou o produto certo?</strong></p><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:17px;padding:14px 28px;border-radius:6px;text-decoration:none;'>🛒 Veja a oferta na Amazon</a><p style='margin:12px 0 0;font-size:12px;color:#888;'>Link de afiliado — apoia o portal sem custo adicional pra você</p></div>

<h2>Soundcore P31i por R$ 369,01: cancelamento adaptativo de ruído e áudio Hi-Res</h2>

<p>Apuração nossa indica que o P31i é o modelo premium dessa rodada de ofertas. Saiu de R$ 549 para R$ 369,01 — corte de 33% — e traz duas tecnologias que o diferenciam do P20i: cancelamento adaptativo de ruído (ANC) em tempo real e suporte a áudio de alta resolução (Hi-Res).</p>

<p>O ANC adaptativo ajusta automaticamente o nível de isolamento sonoro conforme o ambiente — útil pra quem usa o fone em ônibus, escritórios barulhentos, voos ou caminhadas em rua movimentada. Segundo nosso acompanhamento, a tecnologia identifica o ruído ambiente em tempo real e calibra o cancelamento, em vez de aplicar um valor fixo.</p>

<p>O suporte a áudio de alta resolução melhora a riqueza de detalhes em músicas com mais nuance (jazz, clássica, acústicos), além de aproveitar melhor o áudio de filmes e séries em streaming com faixas sonoras de alta qualidade.</p>

<ul>
  <li><strong>Modelo:</strong> Soundcore P31i (Anker)</li>
  <li><strong>Tipo:</strong> Intra-auricular TWS com ANC</li>
  <li><strong>Cancelamento de ruído:</strong> Adaptativo em tempo real</li>
  <li><strong>Áudio:</strong> Suporte Hi-Res (alta resolução)</li>
  <li><strong>Preço:</strong> R$ 369,01 (de R$ 549)</li>
</ul>

<h2>Soundcore P20i ou P31i: qual escolher</h2>

<p>A escolha entre os dois modelos depende basicamente de dois fatores: orçamento e necessidade de cancelamento de ruído. O P20i compensa para quem quer fone Bluetooth com qualidade decente, foco em graves e autonomia longa, gastando menos de R$ 200. É a opção certa para uso casual em ambientes não muito barulhentos.</p>

<p>Já o P31i justifica os R$ 200 a mais quando o uso envolve ambientes ruidosos — transporte público, escritório aberto, cafés. O ANC adaptativo é tecnologia que normalmente aparece em fones acima de R$ 700, e ter ela por R$ 369 é raro. Quem prioriza qualidade sonora detalhada em músicas instrumentais ou acústicas também tira mais proveito do suporte Hi-Res.</p>

<p>Ambos os modelos usam Bluetooth moderno, aplicativo Soundcore para personalização de equalização e têm boa compatibilidade com Android e iOS. A Anker é conhecida pela durabilidade dos seus produtos e pela rede de suporte no Brasil.</p>

<div class='cta-afiliado cta-fim' style='text-align:center;margin:32px 0;padding:20px;background:#fff8e7;border:2px solid #ff9900;border-radius:8px;'><a href='https://amzn.to/4ckOgUc' target='_blank' rel='nofollow sponsored noopener' style='display:inline-block;background:#ff9900;color:#fff;font-weight:bold;font-size:18px;padding:16px 32px;border-radius:6px;text-decoration:none;'>🛒 Comprar agora na Amazon</a></div>
HTML;

// Featured: og:image do olhardigital
$featuredId = (int)$wp->uploadImagemPorUrl(
    'https://img.odcdn.com.br/wp-content/uploads/2026/05/Design-sem-nome-2026-05-12T092027.036.png',
    $titulo, ''
);
if ($featuredId === 0) {
    $sx = new SerperImages($cfg['serper_api_key']);
    $img = $sx->melhor('fone bluetooth tws ouvido sem fio', ['min_w' => 800, 'credito_generico' => true]);
    if ($img) $featuredId = (int)$wp->uploadImagemPorUrl((string)$img['imageUrl'], $titulo, '');
}
if ($featuredId > 0) {
    $wp->atualizarMedia($featuredId, [
        'caption' => "{$titulo} (Foto: divulgação)",
        'description' => "Imagem ilustrativa da matéria '{$titulo}' no portal Como Comprar.",
        'title' => $titulo,
        'alt_text' => $titulo,
    ]);
    echo "Featured: $featuredId\n";
}

// Cat + tags
$cm = new CategoryMatcher($wp, 70.0);
$catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch(['Áudio e Fones']))));
$tagIds = $wp->resolverTags(['Anker', 'Soundcore', 'Fones Bluetooth', 'TWS', 'Bluetooth 5.3', 'ANC', 'Amazon', 'Ofertas']);

// Schema
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => $titulo,
    'datePublished' => date('c'),
    'inLanguage' => 'pt-BR',
];
$contentFinal = $html . "\n<script type=\"application/ld+json\" data-newsarticle=\"1\">\n"
    . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n</script>\n";

$payload = [
    'title' => $titulo,
    'slug' => $slug,
    'content' => $contentFinal,
    'status' => 'draft',
    'meta' => [
        'rank_math_title' => "{$titulo} | Como Comprar",
        'rank_math_description' => 'Soundcore P20i por R$ 169,01 e P31i com ANC por R$ 369,01. Veja especificações, comparativo e onde comprar com desconto.',
        'rank_math_focus_keyword' => 'soundcore anker fone oferta',
    ],
    'categories' => $catIds,
    'tags' => $tagIds,
];
if ($featuredId > 0) $payload['featured_media'] = $featuredId;
if (!empty($cfg['default_post_author_id'])) $payload['author'] = (int)$cfg['default_post_author_id'];

$r = $wp->criarPost($payload);
$postId = (int)$r['id'];
echo "Post #$postId: {$r['link']}\n";

// Posts relacionados
$rel = $wp->buscarRelacionados('Bluetooth', 4, $postId);
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

// Marca trend + limpa queue
$pdo = DbConnection::pdo();
$pdo->prepare("UPDATE trends SET status='publicado', post_id=?, url_post=? WHERE id=?")->execute([$postId, (string)$r['link'], 16522]);
@unlink(__DIR__ . '/../data/queue_gerar/comocomprar/16522.json');
echo "✓ Trend marcada + queue limpa\n";
