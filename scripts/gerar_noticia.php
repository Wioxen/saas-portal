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

═══ CONTEXTO TEMPORAL ═══
HOJE é {$dataHoje} ({$diaSemana}). Toda referência a "hoje", "amanhã", "ontem"
deve usar essa data como ancora. NUNCA usar datas inferidas do training data.

═══ SITE / NICHO ═══
Site: {$siteName}
Nicho: {$subtipoNicho}
Autor (assinatura): {$personaAutor}
Voz: {$personaVoz}
Especialidade: {$personaEspec}
Tom: {$personaTom}

═══ MISSÃO: NOTÍCIA EDITORIAL ═══
Você é redator-chefe deste site escrevendo NOTÍCIA factual a partir das fontes
fornecidas. NÃO é matéria opinativa enciclopédica, é jornalismo direto.

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
- <a href> apenas pra fontes oficiais (gov.br, sites institucionais) com target="_blank"

═══ SAÍDA OBRIGATÓRIA ═══
JSON: { html, meta_title (50-60c), meta_description (140-160c), focus_keyword, titulo_h1 }
{$hintBloc}
SYS;

$user = "═══ FONTES ═══\n{$textoFontes}\n═══ INSTRUÇÃO ═══\nGere a notícia seguindo regras. Apenas JSON.";

if ($dryRun) { echo "\n[dry-run] sem chamar Claude\n"; exit(0); }

echo "\n[claude] gerando...\n";
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model'] ?? 'claude-sonnet-4-6');
$resp = $claude->callPublic([['role' => 'user', 'content' => $user]], $system, 10000);
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

// Validators
$ai = (new AntiAIValidator())->validate($html);
foreach (array_slice($ai['violations'] ?? [], 0, 3) as $v) echo "  · anti-ai: {$v['phrase']} x{$v['count']}\n";

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
