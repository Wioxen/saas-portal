<?php
declare(strict_types=1);
/**
 * scripts/gerar_noticia.php
 *
 * Gerador rápido de notícia a partir de URLs pré-curadas. Diferente do pós-jogo:
 * notícia comum (mercado, lesão, escalação, suspensão, declaração) — não exige
 * estrutura cronológica de gols nem placar.
 *
 * Uso:
 *   php scripts/gerar_noticia.php \
 *     --site=leaodabarra \
 *     --urls=https://ge.globo.com/...,https://outra.com/... \
 *     --titulo-hint="Matheuzinho desfalca o Vitória contra o Fluminense" \
 *     [--dry-run] [--publicar]
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/SourceTrustScore.php';
require_once __DIR__ . '/../lib/AntiAIValidator.php';
require_once __DIR__ . '/../lib/SourceFidelityValidator.php';
require_once __DIR__ . '/../lib/InternalLinkGlossary.php';
require_once __DIR__ . '/../lib/DiscoverPromptBuilder.php';
require_once __DIR__ . '/../lib/AutoRevisor.php';

$opts = getopt('', ['site::', 'urls::', 'titulo-hint::', 'dry-run', 'publicar', 'verbose']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$urlsRaw  = (string)($opts['urls'] ?? '');
$tituloHint = (string)($opts['titulo-hint'] ?? '');
$dryRun   = isset($opts['dry-run']);
$publicar = isset($opts['publicar']);

if ($urlsRaw === '') { fwrite(STDERR, "uso: --site=SLUG --urls=u1,u2 [--titulo-hint='...']\n"); exit(2); }

$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

// Scrape (parsing manual de URLs separadas por pipe pra suportar URLs com vírgula)
$sep = str_contains($urlsRaw, '|') ? '|' : ',';
$urls = array_filter(array_map('trim', explode($sep, $urlsRaw)));
$scraper = new Scraper($cfg['user_agent'] ?? 'Mozilla/5.0', (int)($cfg['scrape_timeout'] ?? 15));

$fontesOk = [];
foreach ($urls as $url) {
    try {
        $dados = $scraper->fetch($url);
        $paras = $dados['content']['paragraphs'] ?? [];
        $chars = strlen(implode(' ', $paras));
        if ($chars < 400) { echo "  ✗ pulado (curto): {$url} ({$chars} chars)\n"; continue; }
        $tier = SourceTrustScore::tierUrl($url);
        $fontesOk[] = ['url' => $url, 'fonte' => $dados, 'tier' => $tier, 'chars' => $chars];
        echo "  ✓ Tier {$tier}: {$url} ({$chars} chars)\n";
    } catch (Throwable $e) {
        echo "  ✗ falha: {$url} — {$e->getMessage()}\n";
    }
}
if (empty($fontesOk)) { fwrite(STDERR, "[erro] zero fontes\n"); exit(4); }

usort($fontesOk, fn($a, $b) => SourceTrustScore::scoreUrl($b['url']) <=> SourceTrustScore::scoreUrl($a['url']));

// Prompt
$manifesto = DiscoverPromptBuilder::blocoManifesto();

$textoFontes = '';
foreach ($fontesOk as $i => $f) {
    $url = $f['url']; $tier = $f['tier'];
    $titulo = $f['fonte']['meta']['title'] ?? '';
    $paras = implode("\n", array_slice($f['fonte']['content']['paragraphs'] ?? [], 0, 30));
    $textoFontes .= "\n══ FONTE " . ($i+1) . " · Tier {$tier} · {$url} ══\n{$titulo}\n\n{$paras}\n\n";
}

$hintBloc = $tituloHint !== '' ? "\nPISTA EDITORIAL: {$tituloHint}\n" : '';

// Persona + contexto editorial (vem do sites.php — não hardcode!)
$persona = $cfg['persona'] ?? [];
$siteName = $cfg['site_name'] ?? $cfg['_site_name'] ?? $siteSlug;
$personaAutor = (string)($persona['autor'] ?? "Equipe {$siteName}");
$personaVoz = (string)($persona['voz'] ?? 'jornalística direta');
$personaEspec = (string)($persona['especialidade'] ?? '');
$personaTom = (string)($persona['tom'] ?? 'direto e factual');
$subtipoNicho = (string)($cfg['subtipo_nicho'] ?? '');
$dataHoje = date('d/m/Y');  // PHP TZ deve estar America/Sao_Paulo via config.php
$diaSemana = ['Sun'=>'domingo','Mon'=>'segunda','Tue'=>'terça','Wed'=>'quarta','Thu'=>'quinta','Fri'=>'sexta','Sat'=>'sábado'][date('D')];

$system = <<<SYS
{$manifesto}

═══ VOCÊ É O AUTOR — NÃO A IA ═══
Você é {$personaAutor}, redator(a) deste site. Sua voz é {$personaVoz}. Seu tom
é {$personaTom}. Você está escrevendo COM SUA VOZ HUMANA — o leitor NÃO PODE
suspeitar que tem IA por trás. Cada frase deve soar como sua, não como
template/catálogo.

ANTES DE ESCREVER CADA PARÁGRAFO, PERGUNTE A SI MESMO:
1. "Esse parágrafo tem padrão de IA?" (frase-catálogo, conector óbvio,
   adjetivo vazio, teaser-isolado, lista de exatos 3 itens, self-reference)
   → Se SIM, REFAÇA com fato concreto da fonte.
2. "Esse parágrafo tem AUTORIDADE pro Google?" (traz dado único da fonte,
   nome próprio, número, data concreta, cita entidade oficial?)
   → Se NÃO, REFAÇA trazendo o dado mais forte da fonte ainda não usado.
3. "Parece que {$personaAutor} escreveu, ou parece IA?"
   → Se IA, REFAÇA até soar humano e específico.

═══ CONTEXTO TEMPORAL ═══
HOJE é {$dataHoje} ({$diaSemana}). Toda referência a "hoje", "amanhã", "ontem",
"semana passada", etc. usa essa data como ancora. NUNCA usar datas inferidas do
training data. Datas mencionadas no artigo DEVEM aparecer literalmente em ao
menos 1 fonte — proibido inferir, proibido arredondar.

═══ SITE / NICHO ═══
Site: {$siteName}
Nicho: {$subtipoNicho}
Autor (assinatura): {$personaAutor}
Voz: {$personaVoz}
Especialidade: {$personaEspec}
Tom: {$personaTom}

═══ MISSÃO: NOTÍCIA EDITORIAL ═══
Você é redator(a) deste site escrevendo NOTÍCIA factual a partir das fontes
fornecidas. Você é a REDAÇÃO que apura — NÃO copia, NÃO atribui pro veículo
concorrente. Apresenta os fatos como apuração própria.

Estrutura:
1. LEAD (2-3 linhas): O QUE + QUEM + QUANDO + ONDE + POR QUE/IMPACTO
2. DETALHES (2-3 parágrafos): contexto, declarações se houver, números/datas relevantes
3. O QUE SIGNIFICA: impacto pro leitor (esse site é {$subtipoNicho})
4. PRÓXIMO PASSO: prazo/data/ação esperada se aplicável

REGRAS DURAS:
- Cada nome, número, data, declaração: DEVE estar nas fontes (sem inferir nada)
- Datas: SEMPRE confirmar com a data de hoje ({$dataHoje}) antes de escrever ano
- Tom: {$personaTom}
- 400-700 palavras
- H2 com pergunta-resposta funciona bem pra Discover

═══ FORMATAÇÃO HTML — REGRAS DURAS ═══
- COMECE direto com <p> ou <h2>. NUNCA inclua <h1> no html — WordPress renderiza
  o h1 automaticamente do título do post. Se você escrever <h1>, vai DUPLICAR.
- Usar <h2> pra seções, <h3> pra subseções, <p> pra parágrafos
- Listas <ul><li> ok pra enumerar prazos, requisitos, etc.
- <strong> pra destacar palavras-chave críticas (datas, valores, prazos)
- <a href> apenas pra fontes oficiais (gov.br, sites institucionais) com target='_blank'

═══ ASPAS DENTRO DO HTML — REGRA CRÍTICA ═══
Você está retornando JSON. O valor de "html" é uma string JSON que NÃO PODE
conter aspas duplas (") soltas. Pra QUALQUER aspa dentro do conteúdo HTML:
- Atributos HTML (class, href, style, target): usar APENAS aspas SIMPLES
  ✓ certo: <a href='https://exemplo.com' target='_blank'>link</a>
  ❌ errado: <a href="https://exemplo.com" target="_blank">link</a>
- Aspas em texto (citações, palavras com ênfase): usar ENTITY HTML &quot;
  ✓ certo: <p>resposta diferente de &quot;nenhuma vez&quot; foi marcada</p>
  ❌ errado: <p>resposta diferente de "nenhuma vez" foi marcada</p>
- Apóstrofo em texto: usar &#39; OU palavra natural sem apóstrofo

═══ SAÍDA OBRIGATÓRIA ═══
JSON: { html, meta_title (50-60c), meta_description (140-160c), focus_keyword, titulo_h1 }
{$hintBloc}
SYS;

$user = "═══ FONTES ═══\n{$textoFontes}\n═══ INSTRUÇÃO ═══\nGere a notícia seguindo regras. Apenas JSON.";

if ($dryRun) { echo "\n[dry-run] sem chamar Claude\n"; exit(0); }

echo "\n[claude] gerando (sonnet 4.6, 16k tokens)...\n";
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model'] ?? 'claude-sonnet-4-6');
$resp = $claude->callPublic([['role' => 'user', 'content' => $user]], $system, 16000);
$texto = $resp['content'][0]['text'] ?? '';
$json = Claude::parseJsonResponse($texto);

if (!$json || empty($json['html'])) {
    $debugPath = __DIR__ . '/../data/debug/noticia_fail_' . date('Ymd_His') . '.txt';
    @mkdir(dirname($debugPath), 0775, true);
    file_put_contents($debugPath, $texto);
    fwrite(STDERR, "[erro] JSON inválido. Raw: " . basename($debugPath) . "\n");
    exit(5);
}

$html = (string)$json['html'];
$titulo = (string)($json['titulo_h1'] ?? $tituloHint ?: 'Notícia');
$metaTitle = (string)($json['meta_title'] ?? $titulo);
$metaDesc  = (string)($json['meta_description'] ?? '');
$focusKw   = (string)($json['focus_keyword'] ?? '');

// Guard anti-H1: WordPress renderiza H1 do título do post. Se Sonnet incluiu <h1>
// no html (mesmo após instrução explícita), strip pra evitar duplicação no DOM.
$h1Removidos = preg_match_all('#<h1\b[^>]*>.*?</h1>#is', $html);
if ($h1Removidos > 0) {
    $html = preg_replace('#<h1\b[^>]*>.*?</h1>\s*#is', '', $html) ?? $html;
    echo "  ⚠️ guard: removido(s) {$h1Removidos} H1 do html (Sonnet ignorou instrução)\n";
}

// Validators (1ª passada — só pra log)
$ai = (new AntiAIValidator())->validate($html);
$totalAi = $ai['total_phrase_violations'] + count($ai['structural'] ?? []);
echo "  · anti-ai (1ª passada): severity={$ai['severity']} | violations={$totalAi}\n";
foreach (array_slice($ai['violations'] ?? [], 0, 3) as $v) echo "    [{$v['category']}] '{$v['phrase']}' x{$v['count']}\n";
foreach (array_slice($ai['structural'] ?? [], 0, 3) as $s) echo "    [estrutural] {$s}\n";

// AUTO-REVISÃO via Haiku 4.5 se severity != ok
if ($ai['severity'] !== 'ok') {
    echo "  ⚙️ disparando auto-revisão Haiku (custo extra ~\$0.02)...\n";
    $rev = (new AutoRevisor($cfg['anthropic_api_key']))->revisar($html, [
        'site_name'      => $siteName,
        'persona_autor'  => $personaAutor,
        'persona_voz'    => $personaVoz,
        'persona_tom'    => $personaTom,
        'subtipo_nicho'  => $subtipoNicho,
    ]);
    if ($rev['reescreveu'] && $rev['ok']) {
        $html = $rev['html'];
        echo "  ✓ revisão ok: severity " . $rev['antes']['severity'] . " → " . $rev['depois']['severity'] . "\n";
    } elseif ($rev['reescreveu']) {
        $html = $rev['html'];
        echo "  ⚠️ revisão melhorou mas ainda warn — severity " . $rev['antes']['severity'] . " → " . $rev['depois']['severity'] . "\n";
    } else {
        echo "  ✗ revisão falhou: " . ($rev['erro'] ?? 'desconhecido') . " — mantendo original\n";
    }
}

$textosFontes = array_map(fn($f) => implode("\n", $f['fonte']['content']['paragraphs'] ?? []), $fontesOk);
$fid = SourceFidelityValidator::validar($html, $textosFontes, ['own_domain' => $cfg['wp_url'] ?? '']);
foreach (array_slice($fid['issues'] ?? [], 0, 3) as $i) echo "  · fidelity: [{$i['tipo']}] {$i['valor']}\n";

// Cluster backlinks
if (!empty($cfg['internal_link_glossary'])) {
    $gloss = InternalLinkGlossary::aplicar($html, [
        'wp_url' => (string)($cfg['wp_url'] ?? ''),
        'glossario' => $cfg['internal_link_glossary'],
    ]);
    if (!empty($gloss['html'])) $html = $gloss['html'];
    if (!empty($gloss['aplicados'])) echo "  · backlinks aplicados: " . count($gloss['aplicados']) . "\n";
}

// Cria post WP
$status = $publicar ? 'publish' : 'draft';
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
try {
    $payload = [
        'title'   => $titulo,
        'content' => $html,
        'status'  => $status,
        'meta'    => [
            'rank_math_title'         => $metaTitle,
            'rank_math_description'   => $metaDesc,
            'rank_math_focus_keyword' => $focusKw,
        ],
    ];
    $post = $wp->criarPost($payload);
    $pid = (int)($post['id'] ?? 0);
    echo "\n✓ POST CRIADO id={$pid} status={$status}\n";
    echo "  Edit: {$cfg['wp_url']}/wp-admin/post.php?post={$pid}&action=edit\n";
    if ($status === 'publish') echo "  URL : {$post['link']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[erro] WP: " . $e->getMessage() . "\n");
    exit(6);
}
