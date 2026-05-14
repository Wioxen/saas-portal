<?php
declare(strict_types=1);
/**
 * Refresca o post #1110 (pré-jogo Vitória x Flamengo, volta Copa do Brasil 14/05/2026 21:30)
 * com fatos verificados via Serper + scrape:
 *   - Placar da ida confirmado: Flamengo 2 x 1 Vitória (22/04 no Maracanã, fonte ge.globo)
 *   - Transmissão: SporTV (TV fechada) + Premiere (pay-per-view) (fontes: CNN Esportes, Veja, mktesportivo)
 *   - Técnicos: Jair Ventura (VIT) e Leonardo Jardim (FLA) (fonte Veja)
 *   - Sem LLM — conteúdo escrito por Opus em sessão Claude Code.
 *
 * Também atualiza data/jogos_vitoria.json com transmissao + placar_ida.
 *
 * Uso:
 *   php scripts/_refresh_pre_jogo_vitfla.php
 */

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';

$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, 'leaodabarra');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// ──────────────────────────────────────────────────────────────────
// NOVO CONTEÚDO (substitui inteiramente o body do post #1110)
// ──────────────────────────────────────────────────────────────────
$html = <<<'HTML'
<p><strong><a href="https://leaodabarra.com.br/entidade/ec-vitoria/" data-entity-link="1">Vitória</a> x Flamengo</strong> se enfrentam nesta quinta-feira, 14 de maio de 2026, às 21h30 (horário de Brasília), no <a href="https://leaodabarra.com.br/conceito/barradao/" data-entity-link="1">Estádio Manoel Barradas</a>, o Barradão, em Salvador. A partida é válida pelo jogo de volta da quinta fase da <a href="https://leaodabarra.com.br/conceito/copa-do-brasil/" data-entity-link="1">Copa do Brasil</a>. A transmissão é do SporTV (TV fechada) e do Premiere (pay-per-view).</p>

<p>Na ida, em 22 de abril no Maracanã, o Flamengo venceu por 2 a 1. O Vitória precisa devolver o placar para avançar.</p>

<h2>Onde assistir Vitória x Flamengo ao vivo — Copa do Brasil</h2>

<p>A partida tem transmissão do <strong>SporTV</strong> na TV fechada e do <strong>Premiere</strong> no pay-per-view, segundo confirmação dos canais oficiais e cobertura de Veja, CNN Esportes e mktesportivo.</p>

<p>A Globo aberta não transmite esta fase da Copa do Brasil. Para acompanhar online, a alternativa oficial é o aplicativo do Premiere, disponível por assinatura.</p>

<h2>O que o Vitória precisa para avançar</h2>

<p>Com o 2 a 1 do Flamengo no Maracanã, o Vitória entra no Barradão precisando reverter a desvantagem. Os cenários são três.</p>

<ul>
  <li><strong>Vitória por 2 ou mais gols de diferença:</strong> classifica direto às oitavas (ex: 2 a 0, 3 a 1, 4 a 2);</li>
  <li><strong>Vitória por 1 gol de diferença:</strong> empate no placar agregado, decisão vai aos pênaltis (ex: 1 a 0, 2 a 1, 3 a 2);</li>
  <li><strong>Empate ou derrota:</strong> Vitória eliminado, Flamengo segue na competição.</li>
</ul>

<p>A Copa do Brasil 2026 não tem mais o critério de gol qualificado fora de casa. Em caso de igualdade no agregado, a decisão é nos pênaltis no próprio Barradão.</p>

<h2>Escalação provável do Vitória contra o Flamengo</h2>

<p>O técnico Jair Ventura comanda o Vitória nesta volta da Copa do Brasil. A definição da escalação titular costuma sair em coletiva na véspera ou no aquecimento da partida.</p>

<p>Esta seção será atualizada com a escalação confirmada assim que houver divulgação oficial pelo clube ou pelas fontes de cobertura editorial (ge.globo, A Tarde, Bahia Notícias).</p>

<h2>Escalação provável do Flamengo no Barradão</h2>

<p>O técnico Leonardo Jardim treina o Flamengo na temporada. Na ida no Maracanã, a escalação base entrou com Rossi; Royal, Danilo, Vitão e Ayrton Lucas; Evertton Araújo, Jorginho e De la Cruz; Luiz Araújo, Cebolinha e Bruno Henrique, segundo registro do Veja.</p>

<p>O técnico costuma rodar elenco entre Copa do Brasil e Brasileirão, então mudanças no Barradão são esperadas. O atualizado oficial sai em coletiva na véspera ou no aquecimento.</p>

<h2>Como chegam Vitória e Flamengo para o jogo de volta</h2>

<p>O Vitória ocupa atualmente a 10ª colocação do Brasileirão Série A, com 19 pontos. O time de Jair Ventura busca segurar a estabilidade na elite e usa a Copa do Brasil como possibilidade de título nacional após o acesso de 2023.</p>

<p>O Flamengo, comandado por Leonardo Jardim, chegou à ida embalado em boa fase no Brasileirão. A vantagem do Maracanã reforça o favoritismo, mas o histórico do Barradão em jogos decisivos é a aposta da torcida rubro-negra baiana.</p>

<h2>O Barradão como casa em jogo de mata-mata</h2>

<p>O Barradão é a fortaleza do Vitória em decisões. O estádio próprio, inaugurado em 1986, virou divisor de águas na história do clube e é onde o Leão acumula a maior parte das suas conquistas estaduais e regionais.</p>

<p>A torcida esgotou os ingressos do confronto. A expectativa é de casa cheia para empurrar o time em busca da virada, com mosaico previsto e festa rubro-negra no entorno do estádio antes do apito inicial.</p>

<details class='faq-discover'>
<summary><strong>Que horas começa Vitória x Flamengo nesta quinta?</strong></summary>
<p>Vitória x Flamengo começa às 21h30 (horário de Brasília) desta quinta-feira, 14 de maio de 2026, no Estádio Manoel Barradas, o Barradão, em Salvador.</p>
</details>

<details class='faq-discover'>
<summary><strong>Onde assistir Vitória x Flamengo na Copa do Brasil?</strong></summary>
<p>A partida tem transmissão do SporTV na TV fechada e do Premiere no pay-per-view, pelas plataformas oficiais da Globo. A Globo aberta não transmite esta fase. Para assistir online, a alternativa é o aplicativo do Premiere por assinatura.</p>
</details>

<details class='faq-discover'>
<summary><strong>Qual foi o placar do jogo de ida entre Vitória e Flamengo?</strong></summary>
<p>O Flamengo venceu por 2 a 1 no jogo de ida, disputado em 22 de abril de 2026 no Maracanã, pela quinta fase da Copa do Brasil. Com isso, o Vitória precisa devolver o placar no Barradão para avançar.</p>
</details>

<details class='faq-discover'>
<summary><strong>O que o Vitória precisa para classificar nas oitavas?</strong></summary>
<p>O Vitória precisa vencer por 2 gols ou mais de diferença para classificar direto. Se vencer por 1 gol de diferença, a decisão vai aos pênaltis. Empate ou derrota eliminam o Leão da competição.</p>
</details>

<details class='faq-discover'>
<summary><strong>Quem é o técnico do Vitória e do Flamengo nesta partida?</strong></summary>
<p>O Vitória é comandado pelo técnico Jair Ventura, enquanto o Flamengo é dirigido por Leonardo Jardim. Ambos confirmados nos respectivos cargos para esta volta da Copa do Brasil.</p>
</details>

<p><em>Atualizado em 13 de maio de 2026. Fontes consultadas: ge.globo (placar da ida), Veja (transmissão e escalação Flamengo na ida), CNN Esportes (transmissão), mktesportivo (confirmação da volta).</em></p>
HTML;

// ──────────────────────────────────────────────────────────────────
// SCHEMA BroadcastEvent + SportsEvent atualizados (com broadcaster)
// ──────────────────────────────────────────────────────────────────
$schemaBroadcast = [
    '@context' => 'https://schema.org',
    '@type' => 'BroadcastEvent',
    'name' => 'Live: Esporte Clube Vitória x Flamengo ao vivo — Copa do Brasil 2026 (volta da 5ª fase)',
    'description' => 'Acompanhe a transmissão ao vivo de Esporte Clube Vitória x Flamengo pela Copa do Brasil, no Barradão, em 14/05/2026 às 21h30 (horário de Brasília). Transmissão: SporTV e Premiere.',
    'startDate' => '2026-05-14T21:30:00-03:00',
    'endDate'   => '2026-05-14T23:40:00-03:00',
    'isLiveBroadcast' => true,
    'videoFormat' => 'HD',
    'inLanguage' => 'pt-BR',
    'broadcastOfEvent' => [
        '@type' => 'SportsEvent',
        'name' => 'Esporte Clube Vitória x Flamengo — Copa do Brasil 2026 (volta da 5ª fase)',
        'startDate' => '2026-05-14T21:30:00-03:00',
        'endDate'   => '2026-05-14T23:40:00-03:00',
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/MixedEventAttendanceMode',
        'location' => [
            '@type' => 'StadiumOrArena',
            'name' => 'Estádio Manoel Barradas (Barradão)',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Salvador',
                'addressRegion' => 'BA',
                'addressCountry' => 'BR',
            ],
        ],
        'homeTeam' => [
            '@type' => 'SportsTeam',
            'name' => 'Esporte Clube Vitória',
        ],
        'awayTeam' => [
            '@type' => 'SportsTeam',
            'name' => 'Flamengo',
        ],
        'sport' => 'Football (Soccer)',
    ],
    'publishedOn' => [
        ['@type' => 'BroadcastService', 'name' => 'SporTV', 'broadcastDisplayName' => 'SporTV'],
        ['@type' => 'BroadcastService', 'name' => 'Premiere', 'broadcastDisplayName' => 'Premiere (pay-per-view)'],
    ],
];

$schemaFaq = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [
        ['@type' => 'Question', 'name' => 'Que horas começa Vitória x Flamengo nesta quinta?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Vitória x Flamengo começa às 21h30 (horário de Brasília) desta quinta-feira, 14 de maio de 2026, no Estádio Manoel Barradas, o Barradão, em Salvador.']],
        ['@type' => 'Question', 'name' => 'Onde assistir Vitória x Flamengo na Copa do Brasil?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A partida tem transmissão do SporTV na TV fechada e do Premiere no pay-per-view, pelas plataformas oficiais da Globo. A Globo aberta não transmite esta fase.']],
        ['@type' => 'Question', 'name' => 'Qual foi o placar do jogo de ida entre Vitória e Flamengo?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Flamengo venceu por 2 a 1 no jogo de ida, disputado em 22 de abril de 2026 no Maracanã, pela quinta fase da Copa do Brasil.']],
        ['@type' => 'Question', 'name' => 'O que o Vitória precisa para classificar nas oitavas?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Vitória precisa vencer por 2 gols ou mais de diferença para classificar direto. Se vencer por 1 gol de diferença, a decisão vai aos pênaltis. Empate ou derrota eliminam o Leão.']],
        ['@type' => 'Question', 'name' => 'Quem é o técnico do Vitória e do Flamengo nesta partida?',
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O Vitória é comandado pelo técnico Jair Ventura. O Flamengo é dirigido por Leonardo Jardim.']],
    ],
];

// Preserva o aside 'posts-relacionados' já existente no post (não recria)
$post = $wp->getPost(1110);
$rawAntigo = $post['content']['raw'] ?? '';
$aside = '';
if (preg_match('/<aside[^>]+class=[\x27\x22]posts-relacionados.*?<\/aside>/s', $rawAntigo, $m)) {
    $aside = "\n" . $m[0] . "\n";
}

$contentFinal = $html
    . $aside
    . "\n<script type=\"application/ld+json\" data-broadcast-event=\"1\">\n"
    . json_encode($schemaBroadcast, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n</script>\n"
    . "<script type=\"application/ld+json\" data-faqpage=\"1\">\n"
    . json_encode($schemaFaq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

$r = $wp->atualizarPost(1110, ['content' => $contentFinal]);
echo "Post #1110 atualizado. Status: " . ($r['status'] ?? '?') . "\n";
echo "Link: " . ($r['link'] ?? '?') . "\n";
echo "Content novo: " . strlen($contentFinal) . " chars (era " . strlen($rawAntigo) . ")\n";

// ──────────────────────────────────────────────────────────────────
// Atualiza data/jogos_vitoria.json — transmissao + placar_ida
// ──────────────────────────────────────────────────────────────────
$jsonPath = __DIR__ . '/../data/jogos_vitoria.json';
$dados = json_decode((string)file_get_contents($jsonPath), true);
$found = false;
foreach ($dados['jogos'] as $i => $j) {
    if (($j['id'] ?? '') !== '2026-05-14-vit-fla') continue;
    $dados['jogos'][$i]['transmissao'] = ['canais' => ['SporTV', 'Premiere'], 'tipo' => 'fechada+ppv'];
    $dados['jogos'][$i]['placar_ida'] = ['mando_ida' => 'fora', 'vitoria' => 1, 'adversario' => 2, 'data_ida' => '2026-04-22', 'local_ida' => 'Maracanã'];
    $dados['jogos'][$i]['fase'] = 'Volta da 5ª fase';  // correção (era 'Oitavas de final - Volta', mas é 5ª fase = round of 32)
    $dados['jogos'][$i]['scraped_at'] = date('c');
    $dados['_meta']['atualizado_em'] = date('c');
    $found = true;
    break;
}
if ($found) {
    file_put_contents($jsonPath, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "✅ data/jogos_vitoria.json atualizado (vit-fla recebeu transmissao + placar_ida + fase corrigida)\n";
} else {
    echo "⚠ Jogo 2026-05-14-vit-fla não encontrado no calendar\n";
}
