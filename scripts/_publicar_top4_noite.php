<?php
declare(strict_types=1);
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/DbConnection.php';
require_once __DIR__ . '/../lib/PostFinishing.php';

$cfgRoot = require __DIR__ . '/../config.php';

function boxCtaOf(string $url, string $cta, string $rotulo): string {
    $u = htmlspecialchars($url, ENT_QUOTES); $r = htmlspecialchars($rotulo, ENT_QUOTES); $c = htmlspecialchars($cta, ENT_QUOTES);
    return "<div class='cta-oficial' style='margin:24px 0;padding:18px 22px;background:#eef6ff;border-left:4px solid #1f6feb;border-radius:6px;'><p style='margin:0 0 8px;font-size:13px;font-weight:600;color:#1f6feb;text-transform:uppercase;letter-spacing:0.5px;'>📋 {$r}</p><a href='{$u}' target='_blank' rel='noopener nofollow' style='display:inline-block;background:#1f6feb;color:#fff;font-weight:600;padding:11px 22px;border-radius:5px;text-decoration:none;font-size:15px;'>{$c} →</a></div>";
}

function publicar(array $cfgRoot, string $site, array $job, string $indexNowKey): int {
    $cfg = $cfgRoot;
    aplicarSite($cfg, sitesDisponiveis(), $site);
    $wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    $cm = new CategoryMatcher($wp, 70.0);
    $base = rtrim($cfg['wp_url'], '/');
    $auth = base64_encode($cfg['wp_user'] . ':' . $cfg['wp_app_password']);

    echo "\n── [$site] {$job['titulo']}\n";
    $featuredId = 0;
    if (!empty($job['og_image'])) {
        $featuredId = (int)$wp->uploadImagemPorUrl($job['og_image'], $job['titulo'], $job['legenda']);
        if ($featuredId > 0) { $wp->atualizarMedia($featuredId, ['caption' => $job['legenda'], 'title' => $job['titulo'], 'alt_text' => $job['titulo']]); echo "  ✓ featured #$featuredId\n"; }
    }
    $catIds = array_values(array_filter(array_map('intval', $cm->resolverComMatch([$job['categoria']]))));
    $tagIds = $wp->resolverTags($job['tags']);
    echo "  ✓ cat=" . implode(',', $catIds) . " tags=" . count($tagIds) . "\n";

    $contentFinal = $job['html'] . "\n<script type=\"application/ld+json\" data-schema=\"" . $job['schema_tipo'] . "\">\n" . json_encode($job['schema'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";
    $payload = ['title' => $job['titulo'], 'slug' => $job['slug'], 'content' => $contentFinal, 'status' => 'publish', 'meta' => $job['meta'], 'categories' => $catIds, 'tags' => $tagIds];
    if ($featuredId > 0) $payload['featured_media'] = $featuredId;
    if (!empty($cfg['default_post_author_id'])) $payload['author'] = (int)$cfg['default_post_author_id'];

    $resp = PostFinishing::criarPostFinalizando($wp, $payload, [$job['kw']]);
    $postId = (int)$resp['id']; $linkPub = (string)$resp['link'];
    echo "  ✓ post #$postId: $linkPub\n";

    // Web Story
    $wsP = ['post_id' => $postId, 'min_scenes' => 5, 'max_scenes' => 9, 'keyword' => $job['kw'], 'resposta_direta' => $job['rd'], 'dna' => []];
    $ch = curl_init("{$base}/wp-json/wp-wsai/v1/create-story");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($wsP, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Basic ' . $auth], CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => false]);
    $body = curl_exec($ch); curl_close($ch);
    $d = json_decode((string)$body, true);
    if (!empty($d['success']) && !empty($d['story_id'])) {
        $wsId = (int)$d['story_id']; $wsView = (string)($d['view_url'] ?? ''); $wsScenes = (int)($d['scenes'] ?? 7);
        echo "  📽️ WS #$wsId ($wsScenes cenas)\n";
        if ($featuredId > 0) {
            $ch2 = curl_init("{$base}/wp-json/web-stories/v1/web-story/$wsId");
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['featured_media' => $featuredId]), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Basic ' . $auth], CURLOPT_TIMEOUT => 30]);
            curl_exec($ch2); curl_close($ch2);
        }
        $postFresh = $wp->getPost($postId);
        $ca = (string)($postFresh['content']['raw'] ?? '');
        $blocoWS = "\n\n<!-- WEBSTORY BLOCK -->\n<div class='clonais-webstory-block' style='margin:24px 0;background:linear-gradient(135deg,#0b57d0 0%,#1a73e8 100%);border-radius:12px;padding:18px 22px;'><a href='" . htmlspecialchars($wsView, ENT_QUOTES) . "' target='_blank' rel='noopener' style='display:flex;align-items:center;gap:14px;color:#fff;text-decoration:none;'><span style='font-size:32px;'>📽️</span><span style='flex:1;'><strong style='font-size:14px;display:block;margin-bottom:4px;color:#fff;'>Versão Web Story disponível</strong><span style='font-size:13px;opacity:.95;color:#fff;'>Veja em formato visual rápido para o celular ({$wsScenes} cenas)</span></span><span style='font-size:18px;color:#fff;'>→</span></a></div>\n";
        $pos = stripos($ca, '</p>');
        if ($pos !== false) { $wp->atualizarPost($postId, ['content' => substr($ca, 0, $pos + 4) . $blocoWS . substr($ca, $pos + 4)]); echo "  🔗 WS inserido\n"; }
    }
    // IndexNow
    $host = parse_url($linkPub, PHP_URL_HOST);
    $bIn = ['host' => $host, 'key' => $indexNowKey, 'keyLocation' => "https://{$host}/{$indexNowKey}.txt", 'urlList' => [$linkPub]];
    $ch = curl_init('https://api.indexnow.org/IndexNow');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($bIn, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_TIMEOUT => 15]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "  IndexNow HTTP $code\n";
    return $postId;
}

$keys = [
    'leaodabarra' => '', // sem chave indexnow cadastrada
    'cursosenac' => '1e655367236b47c0bdcc882fdd7a0b4e',
    'vagasebeneficios' => '438de8951bab40709409042e6b7800ef',
    'guiadoscursos' => 'c2d30eab950e41b5aa33decb5ab626c5',
];

// ════════════════════════════════════════════════════════════════════
// POST 1 · Vitória x Flamengo: onde assistir, escalações (leaodabarra)
// ════════════════════════════════════════════════════════════════════
$vfBoxIng = boxCtaOf('https://www.ecvitoria.com.br/', 'Ingressos no site do Vitória', 'Compra oficial');
$vfBoxTV = boxCtaOf('https://www.premiere.com.br/', 'Assista no Premiere', 'Transmissão oficial');
$vfHtml = <<<HTML
<p>O Vitória recebe o Flamengo nesta <strong>quinta-feira (14), às 21h30, no Barradão</strong>, pelo jogo de volta da 5ª fase da Copa do Brasil. O time de Jair Ventura precisa reverter o 2 a 1 sofrido no Maracanã para avançar às oitavas. Vencer por 1 gol leva a decisão para os pênaltis; por 2 ou mais gols, o Leão da Barra carimba a vaga direto, confirmou a redação do Leão da Barra.</p>

<h2>Onde assistir Vitória x Flamengo ao vivo</h2>

<p>A transmissão é exclusiva da TV por assinatura:</p>

<ul>
  <li><strong>Premiere:</strong> canal pay-per-view com cobertura completa do jogo</li>
  <li><strong>SporTV:</strong> também transmite ao vivo</li>
  <li><strong>Tempo Real do ge:</strong> lances em texto + vídeos no portal globo.com</li>
</ul>

<p>Não há transmissão aberta em TV gratuita. Quem não tem Premiere ou SporTV pode acompanhar pelo Tempo Real do ge, que costuma trazer gols em vídeo poucos minutos após acontecerem.</p>

{$vfBoxTV}

<h2>Escalações prováveis</h2>

<p>O Vitória chega com 2 baixas certas: <strong>Kayzer e Baralhas</strong> estão fora. Kayzer cumpriu suspensão (3º amarelo contra o Fluminense no fim de semana) e Baralhas segue em tratamento. Para a vaga de Kayzer, Jair Ventura deve manter Renê como referência única no ataque.</p>

<p>O time provável do Vitória:</p>

<ul>
  <li><strong>Gol:</strong> Lucas Arcanjo</li>
  <li><strong>Defesa:</strong> Cáceres, Edu, Lucas Halter, Jamerson</li>
  <li><strong>Meio:</strong> Pepê, Ronald, Matheuzinho</li>
  <li><strong>Ataque:</strong> Erick, Osvaldo, Renê</li>
</ul>

<p>Do outro lado, o Flamengo vem de 5 vitórias seguidas e a maior dúvida é se Leonardo Jardim vai poupar titulares. Paquetá segue lesionado e é desfalque. A tendência é uma escalação alternativa, com chances para jogadores menos utilizados — Flamengo joga pelo empate ou derrota por 1 gol de diferença.</p>

<h2>Como foi o jogo de ida no Maracanã</h2>

<p>O Vitória saiu na frente com gol de Erick, mas o Flamengo virou: <strong>Evertton Araujo</strong> empatou ainda na 1ª etapa e <strong>Pedro</strong> fez o segundo no 2º tempo. O 2 a 1 no agregado dá ao Flamengo o direito de empatar ou perder por 1 gol — qualquer outro resultado classifica o Vitória.</p>

<p>Em casa, o Leão tem retrospecto sólido em 2026: apenas 1 derrota no Barradão depois de 10 de fevereiro (quando perdeu para o próprio Flamengo). Foram 9 vitórias e 2 empates nesse intervalo.</p>

<h2>Como o Vitória chega para o jogo</h2>

<p>O time vem de empate em 2 a 2 com o Fluminense pelo Brasileirão, no Maracanã, no último sábado. Antes disso, classificou-se às semifinais da Copa do Nordeste eliminando o Ceará. O momento é positivo: 4 partidas sem perder fora do Barradão considerando todas as competições.</p>

<p>O time também tem motivação extra com a presença de <strong>Luciano Juba na pré-lista da Seleção</strong> para a Copa do Mundo de 2026 — primeira convocação de um jogador do Vitória em anos.</p>

<h2>Tudo sobre o jogo</h2>

<ul>
  <li><strong>Confronto:</strong> Vitória x Flamengo</li>
  <li><strong>Competição:</strong> Copa do Brasil 2026 — 5ª fase (jogo de volta)</li>
  <li><strong>Data:</strong> Quinta-feira, 14 de maio de 2026</li>
  <li><strong>Horário:</strong> 21h30 (horário de Brasília)</li>
  <li><strong>Local:</strong> Estádio Manoel Barradas (Barradão), Salvador (BA)</li>
  <li><strong>Transmissão:</strong> Premiere + SporTV</li>
  <li><strong>Acompanhamento online:</strong> ge.globo.com (Tempo Real)</li>
  <li><strong>Placar agregado:</strong> Flamengo 2 x 1 Vitória</li>
  <li><strong>O que o Vitória precisa:</strong> vencer por 2+ gols (classifica direto) ou por 1 (leva aos pênaltis)</li>
</ul>
HTML;
$vfSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'SportsEvent',
    'name' => 'Vitória x Flamengo — Copa do Brasil 2026 (volta da 5ª fase)',
    'description' => 'Vitória recebe o Flamengo no Barradão pela volta da 5ª fase da Copa do Brasil 2026. Flamengo venceu a ida por 2x1.',
    'startDate' => '2026-05-14T21:30:00-03:00',
    'endDate' => '2026-05-14T23:30:00-03:00',
    'eventStatus' => 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => 'https://schema.org/MixedEventAttendanceMode',
    'location' => [
        '@type' => 'Place',
        'name' => 'Estádio Manoel Barradas (Barradão)',
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Salvador', 'addressRegion' => 'BA', 'addressCountry' => 'BR'],
    ],
    'competitor' => [
        ['@type' => 'SportsTeam', 'name' => 'Esporte Clube Vitória', 'sameAs' => 'https://www.ecvitoria.com.br/'],
        ['@type' => 'SportsTeam', 'name' => 'Clube de Regatas do Flamengo', 'sameAs' => 'https://www.flamengo.com.br/'],
    ],
    'sport' => 'Football',
    'organizer' => ['@type' => 'Organization', 'name' => 'CBF — Copa do Brasil', 'url' => 'https://www.cbf.com.br/', 'sameAs' => 'https://www.cbf.com.br/'],
];

publicar($cfgRoot, 'leaodabarra', [
    'titulo' => 'Vitória x Flamengo: onde assistir, horário e escalações da volta da Copa do Brasil (14/05)',
    'slug' => 'vitoria-flamengo-onde-assistir-escalacoes-copa-brasil-14-maio-2026',
    'og_image' => 'https://cdn.atarde.com.br/img/Artigo-Destaque/1380000/vitoria-x-flamengo-onde-assistir-escalacoes-e-tudo-sobre-o-jogo.jpg',
    'legenda' => 'Vitória x Flamengo decide vaga nas oitavas da Copa do Brasil (Foto: ECV/Divulgação)',
    'categoria' => 'Copa do Brasil',
    'tags' => ['Vitória x Flamengo', 'Copa do Brasil', 'Barradão', 'Jair Ventura', 'Leonardo Jardim', 'Premiere', 'SporTV', 'Renê', 'Matheuzinho', 'Quartas de Final', 'Maio 2026'],
    'html' => $vfHtml,
    'schema' => $vfSchema,
    'schema_tipo' => 'sportsevent',
    'kw' => 'Vitória x Flamengo',
    'rd' => 'O Vitória recebe o Flamengo nesta quinta (14) às 21h30, no Barradão, pela volta da Copa do Brasil. Transmissão Premiere e SporTV. O Leão precisa vencer por 2 gols para classificar.',
    'meta' => [
        'rank_math_title' => 'Vitória x Flamengo 14/05: onde assistir, horário e escalações',
        'rank_math_description' => 'Vitória x Flamengo nesta quinta (14) 21h30 no Barradão pela Copa do Brasil. Transmissão Premiere/SporTV. Veja escalações, desfalques e o que o Leão precisa pra classificar.',
        'rank_math_focus_keyword' => 'vitoria x flamengo',
    ],
], $keys['leaodabarra']);

// ════════════════════════════════════════════════════════════════════
// POST 2 · Venda de ingressos Vitória x Flamengo (leaodabarra)
// ════════════════════════════════════════════════════════════════════
$ingBox = boxCtaOf('https://www.ingresso.com', 'Comprar pela Ingresso S.A', 'Compra oficial online');
$ingHtml = <<<HTML
<p>O Vitória iniciou a venda de ingressos para o duelo decisivo contra o Flamengo nesta quinta-feira (14), pela volta da 5ª fase da Copa do Brasil, no Barradão. A torcida rubro-negra pode garantir lugar pela plataforma Ingresso S.A ou nas lojas oficiais do clube. Sócios-torcedores precisam fazer check-in para confirmar presença, conforme apurou a redação do Leão da Barra.</p>

<h2>Quanto custa o ingresso para Vitória x Flamengo</h2>

<p>Os valores variam pelo setor do estádio e adicional de taxa quando a compra é online:</p>

<ul>
  <li><strong>Arquibancada (inteira):</strong> R\$ 200 + taxa R\$ 30 (online)</li>
  <li><strong>Arquibancada (meia-entrada):</strong> R\$ 100 + taxa R\$ 30 (online)</li>
  <li><strong>Cadeira (inteira):</strong> R\$ 280 + taxa R\$ 42 (online)</li>
  <li><strong>Cadeira (meia-entrada):</strong> R\$ 140 + taxa R\$ 42 (online)</li>
</ul>

<p>A meia-entrada é garantida pela lei federal para estudantes (com carteira válida), pessoas com deficiência (CIPTEA), idosos com 60+ anos, doadores regulares de sangue e jovens de baixa renda inscritos no ID Jovem. Documento original obrigatório na entrada do estádio.</p>

{$ingBox}

<h2>Como comprar pela internet (Ingresso S.A)</h2>

<ol>
  <li>Acessar o site <a href="https://www.ingresso.com" target="_blank" rel="noopener nofollow">ingresso.com</a> ou a página oficial do Vitória no portal de ingressos</li>
  <li>Buscar pela partida "Vitória x Flamengo — Copa do Brasil"</li>
  <li>Escolher o setor (arquibancada ou cadeira) e a quantidade de ingressos (máximo 4 por CPF, em geral)</li>
  <li>Informar dados pessoais + documento de cada ocupante (CPF obrigatório)</li>
  <li>Pagar via Pix (mais rápido), cartão de crédito ou débito online</li>
  <li>Receber o e-ticket no e-mail cadastrado — apresentar QR Code na entrada do estádio</li>
</ol>

<p>A compra online inclui taxa de conveniência que varia entre R\$ 30 e R\$ 42 conforme o setor. Para evitar essa taxa, é possível comprar presencialmente nas lojas oficiais.</p>

<h2>Lojas oficiais do Vitória para compra presencial</h2>

<p>Quem prefere comprar sem taxa de internet pode ir até uma das 3 lojas oficiais em Salvador:</p>

<ul>
  <li><strong>Shopping da Bahia</strong> (Iguatemi) — horário comercial</li>
  <li><strong>Salvador Shopping</strong> — horário comercial</li>
  <li><strong>Parque Shopping</strong> — horário comercial</li>
</ul>

<p>O pagamento presencial pode ser feito em dinheiro, cartão débito/crédito ou Pix. O ingresso é entregue impresso na hora. Vale levar documento original (RG ou CNH) e a comprovação da meia-entrada se aplicável.</p>

<h2>Sócio-torcedor: como fazer check-in</h2>

<p>Sócios-torcedores ativos do Vitória não compram o ingresso, mas precisam fazer o <strong>check-in</strong> para confirmar presença e garantir o lugar. O check-in é gratuito e é feito pelo app ou portal do Sócio Rubro-Negro.</p>

<ol>
  <li>Abrir o app oficial do Sócio Rubro-Negro ou acessar o portal pelo navegador</li>
  <li>Fazer login com CPF + senha cadastrada</li>
  <li>Localizar a partida "Vitória x Flamengo" e clicar em "fazer check-in"</li>
  <li>Selecionar o setor coberto pelo plano de sócio (arquibancada ou cadeira)</li>
  <li>Apresentar a carteirinha digital ou o app na entrada do estádio</li>
</ol>

<p>O check-in deve ser feito até algumas horas antes do início do jogo. Quem não confirma presença libera o assento para outros sócios em lista de espera.</p>

<h2>Torcida visitante (flamenguistas)</h2>

<p>Para flamenguistas que querem ir ao Barradão, a venda é exclusivamente pelo site oficial do Flamengo, em setor específico reservado para torcida visitante. Os valores são:</p>

<ul>
  <li><strong>Meia-entrada:</strong> R\$ 100</li>
  <li><strong>Inteira:</strong> R\$ 200</li>
</ul>

<p>O acesso ao estádio é por portaria específica e exclusiva da torcida visitante, com controle e separação física da torcida do Vitória — protocolo padrão de segurança em jogos da Copa do Brasil.</p>

<h2>O que está em jogo (relembrando)</h2>

<p>O Flamengo venceu a ida por 2 a 1 no Maracanã, com gols de Evertton Araujo e Pedro. O Vitória descontou com Erick. Para o jogo de volta no Barradão:</p>

<ul>
  <li>Vitória vence por <strong>1 gol</strong> → decisão nos pênaltis</li>
  <li>Vitória vence por <strong>2 ou mais gols</strong> → classifica direto às oitavas</li>
  <li>Vitória empata ou perde → Flamengo segue na competição</li>
</ul>

<p>O confronto vale vaga nas oitavas da Copa do Brasil 2026. Quem passar enfrenta o vencedor do confronto entre Atlético-MG e Maringá (oitavas).</p>
HTML;
$ingSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => 'Ingressos Vitória x Flamengo: preços e como comprar para o jogo no Barradão',
    'datePublished' => date('c'),
    'inLanguage' => 'pt-BR',
];

publicar($cfgRoot, 'leaodabarra', [
    'titulo' => 'Ingressos Vitória x Flamengo: arquibancada R\$ 200, cadeira R\$ 280 e como comprar online',
    'slug' => 'ingressos-vitoria-flamengo-copa-brasil-barradao-precos',
    'og_image' => 'https://meuvitoria.com.br/wp-content/uploads/2026/02/Victor-Ferreira-33623.jpg.jpeg',
    'legenda' => 'Venda de ingressos para Vitória x Flamengo iniciada (Foto: Victor Ferreira/ECV)',
    'categoria' => 'Copa do Brasil',
    'tags' => ['Ingressos', 'Vitória x Flamengo', 'Ingresso S.A', 'Sócio Rubro-Negro', 'Barradão', 'Copa do Brasil', 'Maio 2026'],
    'html' => $ingHtml,
    'schema' => $ingSchema,
    'schema_tipo' => 'newsarticle',
    'kw' => 'ingressos Vitória Flamengo',
    'rd' => 'Ingressos para Vitória x Flamengo já estão à venda. Arquibancada R$ 200, cadeira R$ 280 (mais taxa online). Sócio-torcedor faz check-in. Lojas oficiais nos 3 shoppings.',
    'meta' => [
        'rank_math_title' => 'Ingressos Vitória x Flamengo Copa do Brasil — preços e venda',
        'rank_math_description' => 'Ingressos Vitória x Flamengo: arquibancada R\$ 200, cadeira R\$ 280, sócio faz check-in. Venda online Ingresso S.A ou presencial nas lojas oficiais. Como comprar.',
        'rank_math_focus_keyword' => 'ingressos vitoria flamengo',
    ],
], $keys['leaodabarra']);

// ════════════════════════════════════════════════════════════════════
// POST 3 · Pré-jogo Vitória x Bragantino domingo (leaodabarra)
// ════════════════════════════════════════════════════════════════════
$brBox = boxCtaOf('https://www.ecvitoria.com.br/', 'Site oficial do Vitória', 'Acompanhamento oficial');
$brHtml = <<<HTML
<p>O Vitória terá 4 desfalques importantes para enfrentar o RB Bragantino neste domingo (17), às 18h30, no Estádio Cícero de Souza Marques, em Bragança Paulista, pela 16ª rodada do Brasileirão. Os zagueiros <strong>Nathan Mendes e Luan Cândido</strong> não podem jogar contra o time paulista por cláusula contratual, enquanto o lateral <strong>Ramon</strong> e o atacante <strong>Renato Kayzer</strong> cumprem suspensão pelo terceiro cartão amarelo, conforme apurou a redação do Leão da Barra.</p>

<h2>Por que Nathan Mendes e Luan Cândido estão fora</h2>

<p>Os dois zagueiros pertencem ao Bragantino e foram emprestados ao Vitória com cláusula impeditiva. Essa cláusula é padrão em empréstimos entre clubes da Série A: o jogador não pode atuar contra a equipe que detém seus direitos econômicos, evitando conflito de interesse. Na prática:</p>

<ul>
  <li><strong>Nathan Mendes:</strong> titular da defesa nas últimas rodadas, fora por cláusula</li>
  <li><strong>Luan Cândido:</strong> tem atuado como zagueiro improvisado (originalmente lateral-esquerdo), também fora por cláusula</li>
</ul>

<p>A dupla volta a ficar disponível na rodada seguinte, contra o Athletico-PR ou outro adversário. Para o jogo de quinta (14) contra o Flamengo, ambos estão liberados normalmente.</p>

<h2>Ramon e Kayzer suspensos pelo 3º amarelo</h2>

<p>Os dois levaram o terceiro cartão amarelo no empate em 2 a 2 com o Fluminense, no último sábado (9), no Maracanã. Pelas regras do STJD, o 3º amarelo gera suspensão automática na partida seguinte.</p>

<ul>
  <li><strong>Ramon</strong> (lateral-esquerdo): substituto natural é <strong>Jamerson</strong></li>
  <li><strong>Renato Kayzer</strong> (atacante): deve ser desfeita a dupla de ataque — Jair Ventura tende a manter apenas <strong>Renê</strong> como referência ofensiva</li>
</ul>

<p>Os dois retornam ao banco/titular na rodada seguinte do Brasileirão. Como o jogo contra o Flamengo é de quinta (Copa do Brasil), ambos jogarão o duelo da Copa antes de cumprir a suspensão no Brasileirão.</p>

{$brBox}

<h2>Retornos que aliviam a folha do Vitória</h2>

<p>Mesmo com 4 baixas, Jair Ventura ganha 2 reforços que estavam de fora nas últimas rodadas, ambos suspensos no jogo anterior:</p>

<ul>
  <li><strong>Matheuzinho</strong> (meia): volta a ficar à disposição. Deve ocupar o setor central do meio-campo</li>
  <li><strong>Erick</strong> (atacante): também retorna após cumprir suspensão. Forma dupla com Renê no ataque ou atua aberto pela direita</li>
</ul>

<p>O retorno dos dois titulares amortiza a perda de Kayzer no ataque e Ramon na lateral. O setor mais delicado é a zaga, com a perda de Nathan Mendes (titular) e Luan Cândido. Jair Ventura terá que improvisar com Edu, Lucas Halter ou usar Wagner Leonardo como zagueiro improvisado.</p>

<h2>Como o Vitória chega para o jogo</h2>

<p>O time vem de empate em 2 a 2 com o Fluminense pelo Brasileirão e prepara a sequência com o duelo decisivo contra o Flamengo pela Copa do Brasil na quinta-feira (14). A escalação contra o Bragantino só será definida após a partida da Copa, já que muitos jogadores serão poupados ou rotacionados.</p>

<p>Na tabela do Brasileirão, o Vitória vinha em ascensão antes do empate com o Fluminense. Uma vitória contra o Bragantino, fora de casa, seria importante para se distanciar da zona de rebaixamento e firmar a recuperação iniciada nas últimas 4 rodadas.</p>

<h2>Como o Bragantino chega</h2>

<p>O Bragantino tem vivido oscilação na temporada. Como mandante em Bragança Paulista, costuma ter retrospecto melhor que fora de casa. A campanha do clube paulista mistura jogos sólidos contra times de meio de tabela com derrotas pra adversários considerados mais fracos — comportamento típico de equipe em reconstrução.</p>

<h2>Detalhes da partida</h2>

<ul>
  <li><strong>Confronto:</strong> RB Bragantino x Vitória</li>
  <li><strong>Competição:</strong> Campeonato Brasileiro 2026 — 16ª rodada</li>
  <li><strong>Data:</strong> Domingo, 17 de maio de 2026</li>
  <li><strong>Horário:</strong> 18h30 (horário de Brasília)</li>
  <li><strong>Local:</strong> Estádio Cícero de Souza Marques, Bragança Paulista (SP)</li>
  <li><strong>Desfalques Vitória:</strong> Nathan Mendes, Luan Cândido (cláusula), Ramon, Kayzer (suspensão)</li>
  <li><strong>Retornos:</strong> Matheuzinho e Erick (cumpriram suspensão)</li>
  <li><strong>Técnico Vitória:</strong> Jair Ventura</li>
</ul>
HTML;
$brSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'SportsEvent',
    'name' => 'RB Bragantino x Vitória — Brasileirão 2026 (16ª rodada)',
    'startDate' => '2026-05-17T18:30:00-03:00',
    'endDate' => '2026-05-17T20:30:00-03:00',
    'eventStatus' => 'https://schema.org/EventScheduled',
    'location' => ['@type' => 'Place', 'name' => 'Estádio Cícero de Souza Marques', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Bragança Paulista', 'addressRegion' => 'SP', 'addressCountry' => 'BR']],
    'competitor' => [['@type' => 'SportsTeam', 'name' => 'RB Bragantino'], ['@type' => 'SportsTeam', 'name' => 'Esporte Clube Vitória']],
    'sport' => 'Football',
];

publicar($cfgRoot, 'leaodabarra', [
    'titulo' => 'Vitória contra Bragantino: 4 desfalques (Nathan Mendes, Luan Cândido, Ramon, Kayzer) para domingo (17)',
    'slug' => 'vitoria-bragantino-desfalques-nathan-mendes-luan-candido-kayzer-17-maio',
    'og_image' => 'https://cdn.atarde.com.br/img/Artigo-Destaque/1380000/vitoria-perde-dois-titulares-para-encarar-o-bragantino.jpg',
    'legenda' => 'Vitória perde Nathan Mendes e Luan Cândido por cláusula contratual (Foto: ECV/Divulgação)',
    'categoria' => 'Brasileirão',
    'tags' => ['Vitória x Bragantino', 'Brasileirão 2026', 'Jair Ventura', 'Nathan Mendes', 'Luan Cândido', 'Renato Kayzer', 'Matheuzinho', 'Erick', 'Desfalques', '16ª Rodada'],
    'html' => $brHtml,
    'schema' => $brSchema,
    'schema_tipo' => 'sportsevent',
    'kw' => 'Vitória Bragantino desfalques',
    'rd' => 'O Vitória tem 4 desfalques importantes contra o Bragantino neste domingo (17) às 18h30: Nathan Mendes e Luan Cândido por cláusula, Ramon e Kayzer suspensos. Matheuzinho e Erick voltam.',
    'meta' => [
        'rank_math_title' => 'Vitória x Bragantino 17/05: desfalques e retornos para o jogo',
        'rank_math_description' => 'Vitória terá 4 desfalques contra o Bragantino neste domingo (17/05): Nathan Mendes e Luan Cândido por cláusula contratual, Ramon e Kayzer suspensos. Matheuzinho e Erick voltam.',
        'rank_math_focus_keyword' => 'vitoria bragantino desfalques',
    ],
], $keys['leaodabarra']);

// ════════════════════════════════════════════════════════════════════
// POST 4 · IFMA 540 vagas Adm EAD (cursosenacgratuito)
// ════════════════════════════════════════════════════════════════════
$ifmaBox = boxCtaOf('https://estudenoifma.ifma.edu.br/tecnicoead/', 'Acessar IFMA EaD', 'Inscrição oficial');
$ifmaHtml = <<<HTML
<p>O Instituto Federal do Maranhão (IFMA) abriu inscrições para 540 vagas em <strong>curso técnico gratuito de Administração</strong> na modalidade EaD. As oportunidades fazem parte do Edital PRENAE nº 30/2026 e estão distribuídas entre 3 campi: Buriticupu, Rosário e São José de Ribamar. A seleção é por <strong>sorteio eletrônico</strong>, sem prova, e as inscrições seguem até 27 de maio de 2026, conforme apurou a redação do CursoSenac Gratuito.</p>

<h2>Quem pode se inscrever no Técnico em Administração do IFMA</h2>

<p>O ingresso é na modalidade subsequente, voltada para quem já concluiu o ensino médio. Os pré-requisitos cumulativos:</p>

<ul>
  <li>Ter <strong>concluído o ensino médio</strong> em escola pública ou privada (apresentar comprovante na matrícula)</li>
  <li>Não há limite de idade</li>
  <li>Não há exigência de residir em município específico — o candidato escolhe o polo presencial mais próximo</li>
  <li>Curso é totalmente gratuito: sem taxa de inscrição, sem mensalidade</li>
</ul>

<p>O curso tem duração de 18 meses divididos em 3 semestres letivos, com início no segundo semestre de 2026. A formação é EaD com atividades presenciais obrigatórias nos polos.</p>

{$ifmaBox}

<h2>Os 3 campi responsáveis pelos polos</h2>

<p>Cada campus gerencia polos de apoio presencial em diferentes municípios do Maranhão:</p>

<ul>
  <li><strong>Campus Buriticupu:</strong> atende o interior do estado (oeste maranhense)</li>
  <li><strong>Campus Rosário:</strong> cobre municípios da região leste e norte</li>
  <li><strong>Campus São José de Ribamar:</strong> serve a Grande São Luís e municípios próximos</li>
</ul>

<p>Os encontros presenciais ocorrem aos finais de semana (sexta e sábado, geralmente). É obrigatório comparecer ao polo escolhido para tutoria, avaliações e atividades práticas. A frequência presencial mínima exigida costuma ser de 75% — quem não atinge corre risco de reprovação.</p>

<h2>Como funciona a seleção por sorteio eletrônico</h2>

<p>Diferente de outros editais que aplicam prova, o IFMA usa sorteio público para selecionar os candidatos. Isso democratiza o acesso para quem está fora da escola há anos ou que tem dificuldade com prova escrita. O processo:</p>

<ol>
  <li>O candidato se inscreve no site oficial dentro do prazo</li>
  <li>O sistema gera um número de inscrição único por candidato</li>
  <li>Após o encerramento, o IFMA realiza sorteio eletrônico transmitido online</li>
  <li>Os primeiros sorteados ocupam as vagas regulares</li>
  <li>Os demais ficam em lista de espera, chamados se houver desistência</li>
</ol>

<p>Por ser sorteio aleatório, todos os candidatos válidos têm a mesma probabilidade. Não há critérios de cota além do edital prever vagas reservadas para PcD e cotistas conforme legislação federal.</p>

<h2>Como se inscrever no IFMA Técnico em Administração EAD</h2>

<ol>
  <li>Acessar o portal oficial <a href="https://estudenoifma.ifma.edu.br/tecnicoead/" target="_blank" rel="noopener nofollow">estudenoifma.ifma.edu.br/tecnicoead/</a> até 27 de maio de 2026</li>
  <li>Localizar o edital PRENAE nº 30/2026 e ler integralmente os requisitos</li>
  <li>Escolher o campus e o polo mais próximo (lista publicada no edital)</li>
  <li>Preencher o formulário online com dados pessoais, escolaridade e categoria (ampla concorrência, PcD ou cotista)</li>
  <li>Enviar documentação digitalizada (RG, CPF, certificado de conclusão do ensino médio, comprovante de residência)</li>
  <li>Confirmar a inscrição (gratuita)</li>
  <li>Aguardar o sorteio público após o encerramento</li>
</ol>

<p>Não há pagamento de taxa em momento algum. Quem é selecionado precisa apenas comparecer ao polo escolhido para matrícula presencial com os documentos originais.</p>

<h2>O que esperar do mercado para Técnico em Administração</h2>

<p>O curso de Técnico em Administração é um dos mais buscados do país por causa da versatilidade. O profissional atua em praticamente qualquer setor da economia:</p>

<ul>
  <li>Empresas privadas (rotinas administrativas, atendimento, financeiro)</li>
  <li>Órgãos públicos (auxiliar administrativo, técnico operacional)</li>
  <li>Comércio e varejo</li>
  <li>Saúde (administração hospitalar, secretaria)</li>
  <li>Agronegócio (gestão de fazendas, cooperativas)</li>
  <li>Educação (secretaria escolar, gestão de polos)</li>
</ul>

<p>A formação técnica é uma porta de entrada mais rápida ao mercado de trabalho que a graduação, com duração menor e reconhecimento imediato pelos empregadores. O salário inicial costuma ser entre R\$ 1.800 e R\$ 2.800, dependendo da região e do porte da empresa.</p>

<h2>Detalhes da oportunidade</h2>

<ul>
  <li><strong>Instituição:</strong> Instituto Federal do Maranhão (IFMA)</li>
  <li><strong>Curso:</strong> Técnico em Administração (modalidade subsequente)</li>
  <li><strong>Edital:</strong> PRENAE nº 30/2026</li>
  <li><strong>Vagas:</strong> 540 distribuídas entre 3 campi</li>
  <li><strong>Modalidade:</strong> EaD com atividades presenciais obrigatórias nos polos</li>
  <li><strong>Custo:</strong> Gratuito (sem taxa, sem mensalidade)</li>
  <li><strong>Pré-requisito:</strong> Ensino médio completo</li>
  <li><strong>Seleção:</strong> Sorteio eletrônico público</li>
  <li><strong>Inscrições:</strong> Até 27 de maio de 2026</li>
  <li><strong>Início das aulas:</strong> Segundo semestre de 2026</li>
  <li><strong>Duração:</strong> 18 meses (3 semestres)</li>
</ul>
HTML;
$ifmaSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Course',
    'name' => 'Técnico em Administração EaD IFMA 2026 (540 vagas gratuitas)',
    'description' => 'O IFMA oferece 540 vagas no curso Técnico em Administração modalidade EaD subsequente. Gratuito, sem prova (sorteio eletrônico). Inscrições até 27 de maio de 2026 em 3 campi.',
    'inLanguage' => 'pt-BR',
    'isAccessibleForFree' => true,
    'educationalCredentialAwarded' => 'Diploma de Técnico em Administração',
    'provider' => ['@type' => 'EducationalOrganization', 'name' => 'Instituto Federal do Maranhão', 'sameAs' => 'https://www.ifma.edu.br/'],
    'offers' => ['@type' => 'Offer', 'category' => 'Free', 'price' => 0, 'priceCurrency' => 'BRL', 'availabilityEnds' => '2026-05-27T23:59:00-03:00'],
    'hasCourseInstance' => [['@type' => 'CourseInstance', 'courseMode' => 'Blended', 'location' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressRegion' => 'MA', 'addressCountry' => 'BR']]]],
];

publicar($cfgRoot, 'cursosenac', [
    'titulo' => 'IFMA abre 540 vagas em curso técnico gratuito de Administração EaD: inscrições até 27 de maio',
    'slug' => 'ifma-540-vagas-tecnico-administracao-ead-2026',
    'og_image' => 'https://www.pebsp.com/wp-content/uploads/2026/05/O-IFMA-abriu-vagas-para-o-Curso-Tecnico-em-Administracao.jpg',
    'legenda' => 'IFMA abre 540 vagas no Técnico em Administração EaD (Foto: IFMA/Divulgação)',
    'categoria' => 'Cursos Técnicos',
    'tags' => ['IFMA', 'Instituto Federal Maranhão', 'Técnico em Administração', 'Curso Gratuito', 'EAD', 'Sorteio', 'Maranhão', 'Buriticupu', 'Rosário', 'São José de Ribamar', 'PRENAE 30/2026'],
    'html' => $ifmaHtml,
    'schema' => $ifmaSchema,
    'schema_tipo' => 'course',
    'kw' => 'IFMA técnico administração EAD',
    'rd' => 'IFMA abre 540 vagas no Técnico em Administração EaD gratuito em 3 campi do Maranhão. Seleção por sorteio eletrônico, sem prova. Inscrições até 27 de maio de 2026.',
    'meta' => [
        'rank_math_title' => 'IFMA 540 vagas Técnico em Administração EaD 2026 — inscrições até 27/05',
        'rank_math_description' => 'IFMA abre 540 vagas em Técnico em Administração EaD gratuito (Buriticupu, Rosário, São José de Ribamar). Sorteio eletrônico, sem prova. Inscrições até 27/05/2026.',
        'rank_math_focus_keyword' => 'ifma tecnico administracao ead',
    ],
], $keys['cursosenac']);

// ════════════════════════════════════════════════════════════════════
// Limpa filas + marca trends DB
// ════════════════════════════════════════════════════════════════════
echo "\n═══ Limpa filas ═══\n";
$arquivos = [
    '/app/data/queue_gerar/leaodabarra/17694.json',
    '/app/data/queue_gerar/leaodabarra/17353.json',
    '/app/data/queue_gerar/leaodabarra/17945.json',
    '/app/data/queue_gerar/cursosenac/17809.json',
    '/app/data/queue_jobs/vagasebeneficios/dc8f8ef87cb5e6ad.json', // velha
    '/app/data/queue_jobs/vagasebeneficios/e9e1a473e53acf15.json', // 2021 velha
];
foreach ($arquivos as $f) {
    if (is_file($f)) { @unlink($f); echo "  ✓ removido " . basename($f) . "\n"; }
}

// Marca trends no DB
$pdo = DbConnection::pdo();
$st = $pdo->prepare("UPDATE trends SET status='publicado' WHERE id IN (17694, 17353, 17945, 17809)");
$st->execute();
echo "  ✓ " . $st->rowCount() . " trends marcadas como publicado no DB\n";

// Adiciona dedup
$dedupVagas = '/app/data/queue_jobs/vagasebeneficios/.dedup_hashes.txt';
file_put_contents($dedupVagas, "dc8f8ef87cb5e6ad\ne9e1a473e53acf15\n", FILE_APPEND | LOCK_EX);
echo "\nFEITO\n";
