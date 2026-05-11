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
require_once __DIR__ . '/../lib/JogoClusterLinker.php';
require_once __DIR__ . '/../lib/DiscoverPromptBuilder.php';
require_once __DIR__ . '/../lib/AutoRevisor.php';
require_once __DIR__ . '/../lib/SportsHighlightsExtractor.php';
require_once __DIR__ . '/../lib/ApiFutebol.php';

$opts = getopt('', ['site::', 'jogo-id::', 'urls::', 'api-partida-id::', 'dry-run', 'publicar', 'verbose']);
$siteSlug = (string)($opts['site'] ?? 'leaodabarra');
$jogoId   = (string)($opts['jogo-id'] ?? '');
$urlsRaw  = (string)($opts['urls'] ?? '');
$apiPartidaId = (int)($opts['api-partida-id'] ?? 0);
$dryRun   = isset($opts['dry-run']);
$publicar = isset($opts['publicar']);
$verbose  = isset($opts['verbose']);

if ($jogoId === '' || ($urlsRaw === '' && $apiPartidaId === 0)) {
    fwrite(STDERR, "uso: --site=SLUG --jogo-id=YYYY-MM-DD-vit-XXX (--urls=u1,u2,... OU --api-partida-id=N)\n");
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

// ── API Futebol: ficha técnica oficial (gols, cartões, substituições) ──────
$apiPartidaIdFinal = $apiPartidaId ?: (int)($jogo['api_partida_id'] ?? 0);
$apiFicha = '';
$apiPartida = null;
if ($apiPartidaIdFinal > 0) {
    echo "[api-futebol] consultando partida_id={$apiPartidaIdFinal}\n";
    try {
        $apiKey = (string)($cfg['api_futebol_key'] ?? '');
        if ($apiKey === '') throw new RuntimeException('api_futebol_key não configurada');
        $api = new ApiFutebol($apiKey);
        $apiPartida = $api->getPartida($apiPartidaIdFinal);
        $statusApi = $apiPartida['status'] ?? '?';
        $home = $apiPartida['time_mandante']['nome_popular'] ?? '?';
        $away = $apiPartida['time_visitante']['nome_popular'] ?? '?';
        $pH = (int)($apiPartida['placar_mandante'] ?? 0);
        $pV = (int)($apiPartida['placar_visitante'] ?? 0);
        $estadio = $apiPartida['estadio']['nome_popular'] ?? '';
        echo "  ✓ {$statusApi} — {$home} {$pH}x{$pV} {$away} em {$estadio}\n";

        // Monta ficha técnica
        $linhas = [];
        $linhas[] = "FICHA TÉCNICA OFICIAL (api-futebol — verdade absoluta):";
        $linhas[] = "  Confronto: {$home} {$pH}x{$pV} {$away}";
        $linhas[] = "  Status: {$statusApi}";
        if ($estadio) $linhas[] = "  Local: {$estadio}";
        if (!empty($apiPartida['data_realizacao_iso'])) $linhas[] = "  Data: " . $apiPartida['data_realizacao_iso'];
        $rd = $apiPartida['rodada'] ?? '';
        $cnome = $apiPartida['campeonato']['nome_popular'] ?? '';
        if ($cnome) $linhas[] = "  Competição: {$cnome}" . ($rd ? " ({$rd})" : '');

        // Gols (schema: {mandante: [...], visitante: [...]})
        $golsM = $apiPartida['gols']['mandante'] ?? [];
        $golsV = $apiPartida['gols']['visitante'] ?? [];
        if (!empty($golsM)) {
            $linhas[] = "  Gols {$home}:";
            foreach ($golsM as $g) {
                $atl = $g['atleta']['nome_popular'] ?? '?';
                $tag = !empty($g['penalti']) ? ' (pênalti)' : (!empty($g['gol_contra']) ? ' (gol contra)' : '');
                $linhas[] = "    - {$atl} aos {$g['minuto']} ({$g['periodo']}){$tag}";
            }
        }
        if (!empty($golsV)) {
            $linhas[] = "  Gols {$away}:";
            foreach ($golsV as $g) {
                $atl = $g['atleta']['nome_popular'] ?? '?';
                $tag = !empty($g['penalti']) ? ' (pênalti)' : (!empty($g['gol_contra']) ? ' (gol contra)' : '');
                $linhas[] = "    - {$atl} aos {$g['minuto']} ({$g['periodo']}){$tag}";
            }
        }

        // Cartões — schema: cartoes.{amarelo|vermelho}.{mandante|visitante}[]
        $cartoes = $apiPartida['cartoes'] ?? [];
        $cartFlat = [];
        foreach (['amarelo', 'vermelho'] as $tipo) {
            foreach ([$home => 'mandante', $away => 'visitante'] as $lado => $key) {
                foreach (($cartoes[$tipo][$key] ?? []) as $c) {
                    $cartFlat[] = ['atleta' => $c['atleta']['nome_popular'] ?? '?', 'minuto' => $c['minuto'] ?? '?', 'periodo' => $c['periodo'] ?? '', 'tipo' => $tipo, 'lado' => $lado];
                }
            }
        }
        if (!empty($cartFlat)) {
            $linhas[] = "  Cartões:";
            foreach ($cartFlat as $c) {
                $linhas[] = "    - {$c['atleta']} ({$c['lado']}) {$c['tipo']} aos {$c['minuto']} ({$c['periodo']})";
            }
        }

        // Substituições (mandante/visitante)
        $subs = $apiPartida['substituicoes'] ?? [];
        $subM = $subs['mandante'] ?? [];
        $subV = $subs['visitante'] ?? [];
        if (!empty($subM)) {
            $linhas[] = "  Substituições {$home}:";
            foreach ($subM as $s) {
                $linhas[] = "    - SAIU " . ($s['saiu']['nome_popular'] ?? '?') . " / ENTROU " . ($s['entrou']['nome_popular'] ?? '?') . " aos {$s['minuto']} ({$s['periodo']})";
            }
        }
        if (!empty($subV)) {
            $linhas[] = "  Substituições {$away}:";
            foreach ($subV as $s) {
                $linhas[] = "    - SAIU " . ($s['saiu']['nome_popular'] ?? '?') . " / ENTROU " . ($s['entrou']['nome_popular'] ?? '?') . " aos {$s['minuto']} ({$s['periodo']})";
            }
        }

        // Estatísticas (schema real: posse_de_bola, escanteios, impedimentos, faltas (scalars)
        // + passes/finalizacao/defensivo (objects))
        $estM = $apiPartida['estatisticas']['mandante'] ?? [];
        $estV = $apiPartida['estatisticas']['visitante'] ?? [];
        if (!empty($estM) || !empty($estV)) {
            $linhas[] = "  Estatísticas ({$home} x {$away}):";
            $stats = [
                'Posse de bola'      => fn($e) => $e['posse_de_bola'] ?? null,
                'Finalizações'       => fn($e) => $e['finalizacao']['total'] ?? null,
                'Chutes no gol'      => fn($e) => $e['finalizacao']['no_gol'] ?? null,
                'Precisão finalização' => fn($e) => $e['finalizacao']['precisao'] ?? null,
                'Escanteios'         => fn($e) => $e['escanteios'] ?? null,
                'Faltas'             => fn($e) => $e['faltas'] ?? null,
                'Impedimentos'       => fn($e) => $e['impedimentos'] ?? null,
                'Passes'             => fn($e) => $e['passes']['total'] ?? null,
                'Precisão passes'    => fn($e) => $e['passes']['precisao'] ?? null,
                'Desarmes'           => fn($e) => $e['desarmes'] ?? null,
                'Defesas'            => fn($e) => $e['defensivo']['defesas'] ?? null,
            ];
            foreach ($stats as $label => $extractor) {
                $vm = $extractor($estM);
                $vv = $extractor($estV);
                if ($vm === null && $vv === null) continue;
                $linhas[] = "    - {$label}: " . ($vm ?? '?') . " x " . ($vv ?? '?');
            }
        }

        $apiFicha = implode("\n", $linhas) . "\n";
    } catch (Throwable $e) {
        echo "  ⚠ api-futebol falhou: {$e->getMessage()} — fallback só com fontes scraped\n";
    }
}

// Atualiza placar/destaque do JSON com dados oficiais da API se disponível
if ($apiPartida && ($apiPartida['status'] ?? '') === 'finalizado') {
    $pH = (int)$apiPartida['placar_mandante'];
    $pV = (int)$apiPartida['placar_visitante'];
    $isVitMandante = ($jogo['mando'] === 'casa');
    $jogo['placar'] = [
        'vitoria' => $isVitMandante ? $pH : $pV,
        'adversario' => $isVitMandante ? $pV : $pH,
    ];
    echo "[api] placar do JSON sincronizado com API: Vitória {$jogo['placar']['vitoria']}x{$jogo['placar']['adversario']} {$jogo['adversario']['nome']}\n";
}

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
if (empty($fontesOk) && empty($apiFicha)) { fwrite(STDERR, "[erro] zero fontes aproveitáveis e sem ficha API\n"); exit(4); }
if (empty($fontesOk) && !empty($apiFicha)) { echo "[info] sem fontes scraped, gerando só com ficha técnica oficial da API\n"; }

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

═══ PRIORIDADE ENTRE FONTES (HIERARQUIA) ═══
1. FICHA TÉCNICA OFICIAL (api-futebol) — VERDADE ABSOLUTA pra:
   placar, gols (autor/minuto/pênalti), cartões, substituições, estatísticas.
2. FONTES SCRAPEDAS — usar pra NARRATIVA: declarações pós-jogo, cronologia,
   contexto, citações de jogadores/técnico.
Se conflito sobre gol/minuto/jogador → FICHA OFICIAL VENCE. Sempre.

═══ ATRIBUIÇÃO — VOZ DE AUTORIDADE PRÓPRIA ═══
NÓS somos o Leão da Barra. Fontes scraped são INSUMOS internos.
PROIBIDO: "Segundo o ge.globo / Lance / Terra / [qualquer veículo]"
USAR: "Apuração da nossa redação aponta que..." / "Levantamento do Leão da Barra
mostra..." / "A redação confirmou que..."

Estrutura obrigatória:
1. LEAD (1 parágrafo): placar + 1 fato definidor (ex: "olé", "expulsão", "salto na tabela")
2. COMO FOI (2-3 parágrafos): cronologia dos gols + momentos-chave (cartões, expulsões)
3. DESTAQUES INDIVIDUAIS: jogadores que brilharam
4. O QUE MUDA NA TABELA: posição, pontos, distância da zona/G6 conforme o caso
5. PRÓXIMO JOGO: data, adversário, mando

REGRAS:
- Placar, gol, minuto, autor, pênalti, cartão → SEMPRE da FICHA OFICIAL
- Declarações/cronologia/análise → das FONTES SCRAPEDAS (ou omitir)
- NÃO inventar minutos, autores ou eventos
- Tom direto, factual, vibrante mas não ufanista
- 600-900 palavras

═══ DADOS DO JOGO (confirmados pelo JSON do calendário) ═══
- Data: {$jogo['data']} às {$jogo['hora']}
- Competição: {$competicao} ({$rodada}o jogo)
- Placar: Vitória {$placarStr} {$advNome}
- Mando: Vitória {$mando}
- Destaque editorial: {$destaque}

{$apiFicha}
═══ FATOS EXTRAÍDOS DAS FONTES SCRAPEDAS ═══
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
$resp = $claude->callPublic([['role' => 'user', 'content' => $user]], $system, 16000);
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

// Guard anti-H1: WP renderiza h1 do título do post — duplicação no DOM se Sonnet incluir
$h1Removidos = preg_match_all('#<h1\b[^>]*>.*?</h1>#is', $html);
if ($h1Removidos > 0) {
    $html = preg_replace('#<h1\b[^>]*>.*?</h1>\s*#is', '', $html) ?? $html;
    echo "  ⚠️ guard: removido(s) {$h1Removidos} H1 do html\n";
}

// Validators (1ª passada)
echo "[validators]\n";
$ai = (new AntiAIValidator())->validate($html);
echo "  · anti-ai (1ª passada): severity={$ai['severity']}\n";
foreach (array_slice($ai['violations'] ?? [], 0, 3) as $v) echo "    [{$v['category']}] '{$v['phrase']}' x{$v['count']}\n";
foreach (array_slice($ai['structural'] ?? [], 0, 3) as $s) echo "    [estrutural] {$s}\n";

// AUTO-REVISÃO Haiku se severity != ok
if ($ai['severity'] !== 'ok') {
    $persona = $cfg['persona'] ?? [];
    echo "  ⚙️ disparando auto-revisão Haiku (~\$0.02)...\n";
    $rev = (new AutoRevisor($cfg['anthropic_api_key']))->revisar($html, [
        'site_name'      => $cfg['site_name'] ?? 'Leão da Barra',
        'persona_autor'  => $persona['autor'] ?? 'Equipe Leão da Barra',
        'persona_voz'    => $persona['voz'] ?? 'apurada e direta',
        'persona_tom'    => $persona['tom'] ?? 'esportivo factual',
        'subtipo_nicho'  => 'Esporte Clube Vitória — futebol profissional',
    ]);
    if ($rev['reescreveu'] && $rev['ok']) {
        $html = $rev['html'];
        echo "  ✓ revisão ok: " . $rev['antes']['severity'] . " → " . $rev['depois']['severity'] . "\n";
    } elseif ($rev['reescreveu']) {
        $html = $rev['html'];
        echo "  ⚠️ revisão melhorou parcial: " . $rev['antes']['severity'] . " → " . $rev['depois']['severity'] . "\n";
    } else {
        echo "  ✗ revisão falhou — mantendo original\n";
    }
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

    // SPORTS HIGHLIGHTS — busca melhores momentos / gols via Serper + injeta iframe YouTube
    $vitTime  = $jogo['mando'] === 'casa' ? 'Vitória' : $advNome;
    $advTime  = $jogo['mando'] === 'casa' ? $advNome : 'Vitória';
    $placarMa = $jogo['mando'] === 'casa' ? (int)$jogo['placar']['vitoria']    : (int)$jogo['placar']['adversario'];
    $placarVi = $jogo['mando'] === 'casa' ? (int)$jogo['placar']['adversario'] : (int)$jogo['placar']['vitoria'];
    try {
        $hl = SportsHighlightsExtractor::buscar(
            $vitTime, $placarMa, $advTime, $placarVi,
            $jogo['competicao'] ?? '', null,
            (string)($cfg['serper_api_key'] ?? '')
        );
        if ($hl && !empty($hl['embed_html'])) {
            echo "  ▶ highlights: {$hl['fonte']} score={$hl['score']} · " . substr((string)$hl['titulo'], 0, 60) . "\n";
            // Injeta antes do bloco "Próximo jogo" OU no fim do conteúdo
            $marker = '<h2>Próximo jogo';
            $blocoHl = "\n<h2>Assista aos melhores momentos</h2>\n" . $hl['embed_html'] . "\n";
            if (stripos($html, $marker) !== false) {
                $html = str_ireplace($marker, $blocoHl . $marker, $html);
            } else {
                $html .= $blocoHl;
            }
            $payload['content'] = $html;
        } else {
            echo "  ▶ highlights: nenhum vídeo encontrado via Serper\n";
        }
    } catch (Throwable $e) {
        echo "  ▶ highlights: erro — " . $e->getMessage() . "\n";
    }

    // Cluster cross-link: se já existem outros posts do jogo (pre_jogo, etc.),
    // injeta bloco "Mais sobre Vitória x Adv" antes de criar o post
    $clusterLinker = new JogoClusterLinker(__DIR__ . '/../data/jogos_vitoria.json');
    if (!empty($jogo['posts_gerados'])) {
        $payload['content'] = $clusterLinker->injetarNoPost($jogo, 'pos_jogo', $payload['content'], $wp);
    }

    $post = $wp->criarPost($payload);
    $pid = (int)($post['id'] ?? 0);
    echo "\n✓ POST CRIADO id={$pid} status={$status}\n";
    echo "  Edit: {$cfg['wp_url']}/wp-admin/post.php?post={$pid}&action=edit\n";
    if ($status === 'publish') echo "  URL : {$post['link']}\n";

    // Cluster: registra post + backfill irmãos
    if ($pid > 0) {
        $registrou = $cal->registrarPostGerado($jogoId, 'pos_jogo', $pid);
        echo "  ✓ Calendário: posts_gerados.pos_jogo={$pid}\n";
        if (!empty($jogo['posts_gerados'])) {
            $bf = $clusterLinker->backfillIrmaos($jogo, 'pos_jogo', $pid, $wp);
            echo "  ✓ Cluster backfill: {$bf['atualizados']} irmão(s) atualizado(s)\n";
        }
    }

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
