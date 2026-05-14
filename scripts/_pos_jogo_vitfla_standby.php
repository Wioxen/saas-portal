<?php
declare(strict_types=1);
/**
 * STANDBY pós-jogo Vitória x Flamengo (14/05/2026 21:30 Barradão).
 *
 * Fluxo previsto pra rodar DEPOIS do apito final (~23:30 14/05):
 *
 *   1. `php scripts/_pos_jogo_vitfla_standby.php --scrape` — só scrape:
 *      busca placar + lances em ge.globo + outras fontes + dumps fatos em
 *      data/pos_jogo_vitfla_scraped.json. Zero LLM, zero API paga.
 *
 *   2. Em sessão Claude Code com Opus, abro o JSON scrapado, escrevo o HTML
 *      manualmente substituindo o bloco $html abaixo. Salvar e rodar:
 *
 *   3. `php scripts/_pos_jogo_vitfla_standby.php --publish` — publica draft
 *      em leaodabarra.com.br, atualiza data/jogos_vitoria.json (posts_gerados
 *      [pos_jogo] + placar final) e atualiza o trend no DB remoto via SSH (separado).
 *
 * Sem LLM nas etapas 1 e 3. Etapa 2 é Opus em sessão Claude Code (gratuito).
 */

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Env.php';
Env::load(__DIR__ . '/../.env');

$opts = getopt('', ['scrape', 'publish']);
$modo = isset($opts['scrape']) ? 'scrape' : (isset($opts['publish']) ? 'publish' : 'help');

if ($modo === 'help') {
    echo "Uso:\n";
    echo "  --scrape    coleta placar+lances do ge.globo (rodar 23:30 14/05)\n";
    echo "  --publish   publica o draft em WP (rodar depois de eu preencher o HTML)\n";
    exit(0);
}

$scrapedPath = __DIR__ . '/../data/pos_jogo_vitfla_scraped.json';

// ══════════════════════════════════════════════════════════════════════════
// MODO --scrape
// ══════════════════════════════════════════════════════════════════════════
if ($modo === 'scrape') {
    // URL canonical do ge.globo pra esse jogo (padrão observado)
    $url = 'https://ge.globo.com/ba/futebol/copa-do-brasil/jogo/14-05-2026/vitoria-flamengo.ghtml';

    echo "Tentando scrape: {$url}\n";
    $html = baixar($url);
    if ($html === '' || stripos($html, '404') !== false && stripos($html, 'not found') !== false) {
        // Fallback: URL alt
        $url = 'https://ge.globo.com/futebol/copa-do-brasil/jogo/14-05-2026/vitoria-flamengo.ghtml';
        echo "Fallback: {$url}\n";
        $html = baixar($url);
    }
    if ($html === '') {
        // Último recurso: usar Serper pra achar a matéria pós-jogo
        require_once __DIR__ . '/../lib/Serper.php';
        $s = new Serper(Env::get('SERPER_API_KEY'));
        $resp = $s->search('Vitória Flamengo Barradão Copa do Brasil 14 maio pós-jogo gols', 8);
        $urls = [];
        foreach (($resp['organic'] ?? []) as $r) {
            $u = (string)($r['link'] ?? '');
            if ($u) $urls[] = $u;
        }
        $facts = ['scraped_at' => date('c'), 'fonte_principal_ge' => $url . ' (HTTP falhou)', 'fontes_alternativas' => $urls];
        file_put_contents($scrapedPath, json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        echo "⚠ Scrape ge.globo direto falhou. Fontes Serper salvas em {$scrapedPath}.\n";
        exit(0);
    }

    // Extrai meta + body
    $facts = ['scraped_at' => date('c'), 'url_ge' => $url];
    if (preg_match('/<title>(.*?)<\/title>/s', $html, $m)) $facts['title'] = trim($m[1]);
    if (preg_match('/<meta[^>]+property=[\'"]og:image[\'"][^>]+content=[\'"]([^\'"]+)/i', $html, $m)) $facts['og_image'] = $m[1];
    if (preg_match('/<meta[^>]+name=[\'"]description[\'"][^>]+content=[\'"]([^\'"]+)/i', $html, $m)) $facts['description'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('/<meta[^>]+property=[\'"]article:published_time[\'"][^>]+content=[\'"]([^\'"]+)/', $html, $m)) $facts['published'] = $m[1];

    // Tenta extrair body de article
    if (preg_match('/<article[^>]*>(.*?)<\/article>/s', $html, $m)) {
        $body = strip_tags($m[1], '<p><h2><h3><strong><em><ul><li>');
        $body = preg_replace('/\s+/', ' ', $body);
        $facts['body_excerpt'] = mb_substr(trim($body), 0, 4000);
    }

    // Extrai placar via patterns comuns (ge.globo embeda em JSON)
    if (preg_match('/"placar":\s*"([^"]+)"/', $html, $m)) $facts['placar_raw'] = $m[1];
    if (preg_match_all('/(Vit[oó]ria|Flamengo)\s+(\d+)\s*x\s*(\d+)\s+(Vit[oó]ria|Flamengo)/i', $html, $ms)) {
        $facts['placar_matches'] = array_slice($ms[0], 0, 3);
    }

    file_put_contents($scrapedPath, json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "✅ Fatos salvos em {$scrapedPath}\n";
    echo "Conteúdo do JSON:\n" . file_get_contents($scrapedPath) . "\n\n";
    echo "Próximo passo: abrir Claude Code, eu (Opus) leio esse JSON e preencho o bloco \$html abaixo. Depois rodar --publish.\n";
    exit(0);
}

// ══════════════════════════════════════════════════════════════════════════
// MODO --publish
// ══════════════════════════════════════════════════════════════════════════

// ─── PREENCHER ABAIXO APÓS O JOGO ───────────────────────────────────────
// Opus vai editar esses valores em sessão Claude Code amanhã 23:30
$placarVitoria   = null;  // ex: 1
$placarFlamengo  = null;  // ex: 1
$classificou    = null;   // true | false
$decidiu_penaltis = null; // true | false (se houve)
$placar_penaltis = null;  // ex: '4 a 3' ou null

if ($placarVitoria === null) {
    echo "❌ Variáveis ainda não preenchidas pelo Opus. Abra o script, ajuste após o jogo, rode de novo.\n";
    exit(1);
}

// Título e slug — Opus ajusta com base no resultado
$titulo = "Vitória x Flamengo: como foi o jogo de volta da Copa do Brasil no Barradão";  // SUBSTITUIR
$slug   = 'vitoria-flamengo-pos-jogo-copa-do-brasil-barradao-14-maio-2026';                // SUBSTITUIR
$metaDesc = "Veja como foi Vitória x Flamengo no Barradão, jogo de volta da Copa do Brasil 2026.";  // SUBSTITUIR

// ─── HTML do pós-jogo (substituir após o jogo) ─────────────────────────
$html = <<<'HTML'
<p><strong>PREENCHER:</strong> P1 com placar final + cenário do agregado + se classificou.</p>

<h2>PREENCHER: H2 da fase do jogo (1º tempo / 2º tempo)</h2>
<p>PREENCHER: gols, expulsões, lances importantes.</p>

<h2>PREENCHER: O que muda na Copa do Brasil pra Vitória</h2>
<p>PREENCHER: próxima fase ou eliminação, próximo jogo no Brasileirão.</p>

<p><em>Atualizado em 14 de maio de 2026.</em></p>
HTML;

// Schema SportsEvent finalizado
$schemaSports = [
    '@context' => 'https://schema.org',
    '@type' => 'SportsEvent',
    'name' => 'Esporte Clube Vitória x Flamengo — Copa do Brasil 2026 (volta da 5ª fase)',
    'startDate' => '2026-05-14T21:30:00-03:00',
    'endDate'   => '2026-05-14T23:40:00-03:00',
    'eventStatus' => 'https://schema.org/EventEnded',
    'location' => [
        '@type' => 'StadiumOrArena',
        'name' => 'Estádio Manoel Barradas (Barradão)',
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Salvador', 'addressRegion' => 'BA', 'addressCountry' => 'BR'],
    ],
    'homeTeam' => ['@type' => 'SportsTeam', 'name' => 'Esporte Clube Vitória'],
    'awayTeam' => ['@type' => 'SportsTeam', 'name' => 'Flamengo'],
    'sport' => 'Football (Soccer)',
];

$contentFinal = $html
    . "\n<script type=\"application/ld+json\" data-sportsevent=\"1\">\n"
    . json_encode($schemaSports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

// Publica
$cfg = require __DIR__ . '/../config.php';
$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, 'leaodabarra');
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

$payload = [
    'title' => $titulo,
    'slug'  => $slug,
    'content' => $contentFinal,
    'status' => 'draft',
    'meta' => [
        'rank_math_description' => $metaDesc,
        'rank_math_focus_keyword' => 'vitoria flamengo pos jogo copa do brasil',
    ],
];
if (!empty($cfg['default_post_author_id'])) $payload['author'] = (int)$cfg['default_post_author_id'];

$r = $wp->criarPost($payload);
$postId = (int)($r['id'] ?? 0);
echo "✅ Post pós-jogo criado como DRAFT: #{$postId}\n";
echo "Link: " . ($r['link'] ?? '?') . "\n";
echo "Admin: " . ($cfg['wp_url'] ?? '?') . "/wp-admin/post.php?post={$postId}&action=edit\n";

// Atualiza calendar JSON
$jsonPath = __DIR__ . '/../data/jogos_vitoria.json';
$dados = json_decode((string)file_get_contents($jsonPath), true);
foreach ($dados['jogos'] as $i => $j) {
    if (($j['id'] ?? '') !== '2026-05-14-vit-fla') continue;
    $dados['jogos'][$i]['status'] = 'finalizado';
    $dados['jogos'][$i]['placar'] = ['vitoria' => $placarVitoria, 'adversario' => $placarFlamengo];
    if ($decidiu_penaltis) {
        $dados['jogos'][$i]['penaltis'] = $placar_penaltis;
    }
    $dados['jogos'][$i]['posts_gerados']['pos_jogo'] = $postId;
    $dados['_meta']['atualizado_em'] = date('c');
    break;
}
file_put_contents($jsonPath, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "✅ Calendar JSON atualizado com placar final + post_id\n";


function baixar(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http === 200 && is_string($body)) ? $body : '';
}
