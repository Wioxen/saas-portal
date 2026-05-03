<?php
declare(strict_types=1);
/**
 * scripts/gerar_pos_jogo.php
 *
 * Gerador pós-jogo: recebe URLs de fontes que cobriram o jogo + dados do
 * placar, scrapa, gera artigo via Sonnet com prompt pós-jogo específico,
 * publica como POST draft no WP.
 *
 * Diferente do HubBuilder (que busca fontes via Serper pra hub enciclopédico),
 * este recebe URLs pré-curadas — pra capturar a janela quente do pós-jogo.
 *
 * Uso:
 *   php scripts/gerar_pos_jogo.php \
 *     --site=leaodabarra \
 *     --jogo-id=2026-05-02-vit-cfc \
 *     --urls=https://metro1.com.br/...,https://correio24horas.com.br/... \
 *     [--dry-run] [--publicar]
 *
 *  --publicar: status='publish' direto. Sem ele, status='draft' pra você revisar.
 */

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/SourceTrustScore.php';
require_once __DIR__ . '/../lib/SportsFactExtractor.php';
require_once __DIR__ . '/../lib/AntiAIValidator.php';
require_once __DIR__ . '/../lib/SourceFidelityValidator.php';
require_once __DIR__ . '/../lib/InternalLinkGlossary.php';
require_once __DIR__ . '/../lib/JogosCalendario.php';
require_once __DIR__ . '/../lib/DiscoverPromptBuilder.php';

$opts = getopt('', ['site::', 'jogo-id::', 'urls::', 'dry-run', 'publicar', 'verbose']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$jogoId   = (string)($opts['jogo-id'] ?? '');
$urlsRaw  = (string)($opts['urls'] ?? '');
$dryRun   = isset($opts['dry-run']);
$publicar = isset($opts['publicar']);
$verbose  = isset($opts['verbose']);

if ($jogoId === '' || $urlsRaw === '') {
    fwrite(STDERR, "uso: --site=SLUG --jogo-id=YYYY-MM-DD-vit-XXX --urls=url1,url2,...\n");
    exit(2);
}

// Carrega config do site
$sites = sitesDisponiveis();
aplicarSite($cfg, $sites, $siteSlug);

// Lê dados do jogo do JSON
$cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');
$jogo = null;
foreach ($cal->jogos() as $j) {
    if (($j['id'] ?? '') === $jogoId) { $jogo = $j; break; }
}
if (!$jogo) {
    fwrite(STDERR, "[erro] jogo-id '{$jogoId}' não encontrado em data/jogos_vitoria.json\n");
    exit(3);
}
echo "[jogo] {$jogo['data']} {$jogo['hora']} — Vitória {$jogo['placar']['vitoria']} x {$jogo['placar']['adversario']} {$jogo['adversario']['nome']}\n";
echo "[mando] {$jogo['mando']} no {$jogo['estadio']}\n";
if (!empty($jogo['destaque_editorial'])) echo "[destaque] {$jogo['destaque_editorial']}\n";

// Scrapa fontes
$urls = array_filter(array_map('trim', explode(',', $urlsRaw)));
$scraper = new Scraper($cfg['user_agent'] ?? 'Mozilla/5.0', (int)($cfg['scrape_timeout'] ?? 15));

$fontesOk = [];
foreach ($urls as $url) {
    try {
        $dados = $scraper->fetch($url);
        $paras = $dados['content']['paragraphs'] ?? [];
        $chars = strlen(implode(' ', $paras));
        if ($chars < 500) { echo "  ✗ pulado (curto): {$url} ({$chars} chars)\n"; continue; }
        $tier = SourceTrustScore::tierUrl($url);
        $fontesOk[] = ['url' => $url, 'fonte' => $dados, 'tier' => $tier, 'chars' => $chars];
        echo "  ✓ Tier {$tier}: {$url} ({$chars} chars)\n";
    } catch (Throwable $e) {
        echo "  ✗ falha: {$url} — {$e->getMessage()}\n";
    }
}
if (empty($fontesOk)) { fwrite(STDERR, "[erro] zero fontes aproveitáveis\n"); exit(4); }

// Ordena por tier
usort($fontesOk, fn($a, $b) => SourceTrustScore::scoreUrl($b['url']) <=> SourceTrustScore::scoreUrl($a['url']));

// SportsFactExtractor
$fatos = SportsFactExtractor::extrair($fontesOk);

// Monta prompt pós-jogo
$manifesto = DiscoverPromptBuilder::blocoManifesto();
$blocoFatos = SportsFactExtractor::paraPrompt($fatos);

$mando = $jogo['mando'] === 'casa' ? 'em casa, no Barradão' : 'fora de casa';
$placarStr = "{$jogo['placar']['vitoria']} a {$jogo['placar']['adversario']}";
$advNome = $jogo['adversario']['nome'];
$destaque = $jogo['destaque_editorial'] ?? '';
$competicao = $jogo['competicao'] ?? '';
$rodada = !empty($jogo['rodada']) ? "rodada {$jogo['rodada']} d" : 'd';

$textoFontes = '';
foreach ($fontesOk as $i => $f) {
    $url = $f['url'];
    $tier = $f['tier'];
    $titulo = $f['fonte']['meta']['title'] ?? '';
    $paras = implode("\n", array_slice($f['fonte']['content']['paragraphs'] ?? [], 0, 25));
    $textoFontes .= "\n══ FONTE " . ($i+1) . " · Tier {$tier} · {$url} ══\n{$titulo}\n\n{$paras}\n\n";
}

$system = <<<SYS
{$manifesto}

═══ MISSÃO PÓS-JOGO ═══
Você é redator-chefe escrevendo a COBERTURA PÓS-JOGO do Vitória — janela quente,
máximo 4h depois do apito final. Leitor já sabe o placar: quer saber COMO foi e
O QUE SIGNIFICA agora.

Estrutura obrigatória:
1. LEAD (1 parágrafo): placar + 1 fato definidor (ex: "olé", "expulsão", "salto na tabela")
2. COMO FOI (2-3 parágrafos): cronologia dos gols + momentos-chave (cartões, expulsões)
3. DESTAQUES INDIVIDUAIS: jogadores que brilharam (com base nas fontes)
4. O QUE MUDA NA TABELA: posição, pontos, distância da zona/G6 conforme o caso
5. PRÓXIMO JOGO: data, adversário, mando

REGRAS:
- Cada placar, gol, minuto, cartão, jogador citado: DEVE estar em pelo menos 1 fonte
- Sem inferir o que não está nas fontes (não inventar opinião do técnico se a fonte não traz)
- Tom direto, factual, vibrante mas não ufanista
- 600-900 palavras (pós-jogo é leitura rápida, não enciclopédico)

═══ DADOS DO JOGO (confirmados) ═══
- Data: {$jogo['data']} às {$jogo['hora']}
- Competição: {$competicao} ({$rodada}o jogo)
- Placar: Vitória {$placarStr} {$advNome}
- Mando: Vitória {$mando}
- Destaque: {$destaque}

═══ FATOS EXTRAÍDOS DAS FONTES ═══
{$blocoFatos}

═══ SAÍDA OBRIGATÓRIA ═══
JSON com campos: html, meta_title (50-60c), meta_description (140-160c), focus_keyword
SYS;

$user = <<<USR
═══ FONTES SCRAPEADAS — APENAS USAR INFO DAQUI ═══
{$textoFontes}

═══ SOLICITAÇÃO ═══
Gere a matéria PÓS-JOGO do Vitória {$placarStr} {$advNome} ({$competicao}) seguindo
todas as regras. Comece com lead que captura {$destaque} sem clichê. Use a estrutura
H2 estabelecida.

Responda APENAS o JSON.
USR;

if ($verbose) {
    echo "\n--- PROMPT SYSTEM (head) ---\n" . substr($system, 0, 600) . "...\n";
    echo "--- FONTES ---\n" . substr($textoFontes, 0, 800) . "...\n";
}

if ($dryRun) {
    echo "\n[dry-run] sem chamar Claude. Custo estimado: ~\$0.20\n";
    exit(0);
}

// Chama Claude
echo "\n[claude] gerando...\n";
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model'] ?? 'claude-sonnet-4-6');
$resp = $claude->callPublic([['role' => 'user', 'content' => $user]], $system, 8000);
$texto = $resp['content'][0]['text'] ?? '';
$json = Claude::parseJsonResponse($texto);
if (!$json || empty($json['html'])) {
    $debugPath = __DIR__ . '/../data/debug/posjogo_fail_' . date('Ymd_His') . '.txt';
    @mkdir(dirname($debugPath), 0775, true);
    file_put_contents($debugPath, $texto);
    fwrite(STDERR, "[erro] Claude não retornou JSON válido. Raw em: " . basename($debugPath) . "\n");
    exit(5);
}

$html = (string)$json['html'];
$metaTitle = (string)($json['meta_title'] ?? "Vitória {$placarStr} {$advNome}");
$metaDesc  = (string)($json['meta_description'] ?? '');
$focusKw   = (string)($json['focus_keyword'] ?? "Vitória {$advNome}");

// Validators
echo "[validators]\n";
$ai = (new AntiAIValidator())->validate($html);
if (!empty($ai['violations'])) {
    foreach (array_slice($ai['violations'], 0, 5) as $v) echo "  · anti-ai: {$v['phrase']} x{$v['count']}\n";
}

$textosFontes = array_map(fn($f) => implode("\n", $f['fonte']['content']['paragraphs'] ?? []), $fontesOk);
$fid = SourceFidelityValidator::validar($html, $textosFontes, ['own_domain' => $cfg['wp_url'] ?? '']);
if (!empty($fid['issues'])) {
    foreach (array_slice($fid['issues'], 0, 5) as $i) echo "  · fidelity: [{$i['tipo']}] {$i['valor']}\n";
}

// Glossário internal links
if (!empty($cfg['internal_link_glossary'])) {
    $gloss = InternalLinkGlossary::aplicar($html, [
        'wp_url'    => (string)($cfg['wp_url'] ?? ''),
        'glossario' => $cfg['internal_link_glossary'],
    ]);
    if (!empty($gloss['html'])) $html = $gloss['html'];
    if (!empty($gloss['aplicados'])) {
        echo "  · backlinks aplicados: " . count($gloss['aplicados']) . "\n";
    }
}

// Schema.org — NewsArticle (sempre) + SportsEvent COMPLETO (resolve avisos GSC
// "campo X não foi encontrado em location/event"). Schema é injetado via
// scripts/fix_schema_post.php após criar post (precisa de featured_media + dateModified
// que só existem depois do criarPost). Aqui não injetamos — fix_schema roda no fim.

// Cria post WP
$status = $publicar ? 'publish' : 'draft';
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
try {
    $payload = [
        'title'   => "Vitória {$placarStr} {$advNome}: " . ($destaque ?: $competicao),
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

    // Injeta schemas NewsArticle + SportsEvent completos via fix_schema_post.php
    // (precisa rodar APÓS criar post pra ter dateModified + featured_media — quando
    // existir; aqui sem featured ainda, mas schema fica válido pra Google)
    $jogoId = $hubConfig['jogo_id'] ?? ($jogo['id'] ?? '');
    if ($pid > 0 && $jogoId !== '') {
        echo "  → injetando schemas NewsArticle + SportsEvent...\n";
        $cmd = sprintf(
            'php %s --site=%s --post-id=%d --jogo-id=%s 2>&1',
            escapeshellarg(__DIR__ . '/fix_schema_post.php'),
            escapeshellarg($siteSlug),
            $pid,
            escapeshellarg($jogoId)
        );
        $out = shell_exec($cmd);
        echo "  " . trim((string)$out) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[erro] criar post WP: " . $e->getMessage() . "\n");
    exit(6);
}
