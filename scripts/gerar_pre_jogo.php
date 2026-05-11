<?php
/**
 * gerar_pre_jogo.php — pipeline pré-jogo do EC Vitória com schema BroadcastEvent.
 *
 * Pipeline V2 (factualidade reforçada):
 *   1. Lê 1 jogo do calendário (--game-id) ou aceita mock (--mock-data)
 *   2. Carrega persona do site (elenco real 2026, especialidades editoriais)
 *   3. Busca 3-4 fontes recentes via Serper (matérias atuais sobre o jogo)
 *   4. Scrape conteúdo das fontes via Scraper
 *   5. Sonnet gera matéria com persona + briefing scrape (factual, sem alucinar elenco)
 *   6. SourceFidelityValidator: nomes próprios devem aparecer nas fontes
 *   7. Injeta Schema.org BroadcastEvent no content
 *   8. Publica WP + Google Indexing API
 *
 * Uso:
 *   php scripts/gerar_pre_jogo.php --game-id=2026-05-09-vit-flu
 *   php scripts/gerar_pre_jogo.php --game-id=X --draft
 *   php scripts/gerar_pre_jogo.php --game-id=X --dry-run
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$gameId = (string)($args['game-id'] ?? '');
$mockJson = (string)($args['mock-data'] ?? '');
$dryRun = !empty($args['dry-run']);
$siteSlug = (string)($args['site'] ?? 'leaodabarra');
$asDraft = !empty($args['draft']);
$apiPartidaId = (int)($args['api-partida-id'] ?? 0);

if ($gameId === '' && $mockJson === '') {
    fwrite(STDERR, "uso: php gerar_pre_jogo.php --game-id=ID  OU  --mock-data='{...}' [--draft] [--dry-run]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/SourceTrustScore.php';
require_once __DIR__ . '/../lib/SourceFidelityValidator.php';
require_once __DIR__ . '/../lib/BroadcastEventBuilder.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';
require_once __DIR__ . '/../lib/JogosCalendario.php';
require_once __DIR__ . '/../lib/JogoClusterLinker.php';
require_once __DIR__ . '/../lib/DiscoverImagemFeatured.php';
require_once __DIR__ . '/../lib/InlineImageInjector.php';
require_once __DIR__ . '/../lib/SerperImages.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/EntityPageLinker.php';
require_once __DIR__ . '/../lib/ApiFutebol.php';

$sitesGlobais = sitesDisponiveis();
aplicarSite($cfg, $sitesGlobais, $siteSlug);
$cfgSiteRaw = $sitesGlobais[$siteSlug] ?? $cfg; // tem persona, empresa, etc.

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Pré-jogo Pipeline V2 (BroadcastEvent + persona + fontes) — site={$siteSlug}\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// ── 1. Carrega jogo ────────────────────────────────────────────────────────
$jogo = null;
if ($mockJson !== '') {
    $jogo = json_decode($mockJson, true);
    if (!is_array($jogo)) { fwrite(STDERR, "✗ mock-data inválido\n"); exit(1); }
    $jogo['id'] = $jogo['id'] ?? 'mock-' . date('Ymd-His');
    echo "→ [1/7] Usando MOCK\n";
} else {
    $cal = json_decode((string)file_get_contents(__DIR__ . '/../data/jogos_vitoria.json'), true);
    foreach ($cal['jogos'] ?? [] as $j) {
        if (($j['id'] ?? '') === $gameId) { $jogo = $j; break; }
    }
    if (!$jogo) { fwrite(STDERR, "✗ jogo {$gameId} não encontrado\n"); exit(1); }
    echo "→ [1/7] Carregado do calendário\n";

    // Anti-stale: data/hora podem estar desatualizadas (caso real 2026-05-08:
    // hora #vit-flu estava 21:30 no JSON mas o jogo era 18h → texto+schema
    // saíram errados). Aborta se scraped_at for antigo, a menos que --skip-stale-check.
    $scrapedAt = $jogo['scraped_at'] ?? $jogo['_meta_jogo']['scraped_at'] ?? null;
    if ($scrapedAt && empty($args['skip-stale-check'])) {
        $diasDesdeAtualizacao = (time() - strtotime($scrapedAt)) / 86400;
        if ($diasDesdeAtualizacao > 7) {
            fwrite(STDERR, "✗ ABORT: scraped_at do jogo é " . round($diasDesdeAtualizacao, 1) . " dias atrás.\n"
                . "  data='{$jogo['data']}' hora='{$jogo['hora']}' podem estar desatualizados.\n"
                . "  Confirme via ge.globo / site oficial e atualize jogos_vitoria.json,\n"
                . "  ou rode com --skip-stale-check pra forçar.\n");
            exit(7);
        }
        if ($diasDesdeAtualizacao > 2) {
            echo "  ⚠ AVISO: scraped_at há " . round($diasDesdeAtualizacao, 1) . "d — verifique data/hora antes de seguir\n";
        }
    }
}

$adv = $jogo['adversario']['nome'] ?? '?';
$comp = $jogo['competicao'] ?? '?';
$mando = $jogo['mando'] ?? '?';
$dataStr = ($jogo['data'] ?? '?') . ' ' . ($jogo['hora'] ?? '?');
echo "  adversário: {$adv} · competição: {$comp} · mando: {$mando} · data: {$dataStr}\n\n";

// ── 1b. API Futebol (fonte oficial — escalação, transmissão, árbitros) ────
// Se passou --api-partida-id ou jogo no JSON tem api_partida_id, busca dados
// estruturados oficiais. Esses fatos viram bloco "FONTE OFICIAL" no briefing
// do Sonnet, com prioridade máxima sobre Serper.
$apiBriefing = '';
$apiOk = false;
$apiPartidaIdFinal = $apiPartidaId ?: (int)($jogo['api_partida_id'] ?? 0);
if ($apiPartidaIdFinal > 0) {
    echo "→ [1b/7] Consultando api-futebol (partida_id={$apiPartidaIdFinal})\n";
    try {
        $apiKey = (string)($cfg['api_futebol_key'] ?? getenv('API_FUTEBOL_KEY') ?: '');
        if ($apiKey === '') throw new RuntimeException('api_futebol_key não configurada em config.php');
        $api = new ApiFutebol($apiKey);
        $partida = $api->getPartida($apiPartidaIdFinal);
        $stale = !empty($partida['_stale']);
        $home = $partida['time_mandante']['nome_popular'] ?? '?';
        $away = $partida['time_visitante']['nome_popular'] ?? '?';
        $estad = $partida['estadio']['nome_popular'] ?? '';
        $dataIso = $partida['data_realizacao_iso'] ?? '';
        $rodada = $partida['rodada'] ?? '';
        $compNome = $partida['campeonato']['nome_popular'] ?? $partida['campeonato']['nome'] ?? '';
        // schema real: escalacoes.mandante / escalacoes.visitante (NÃO time_mandante.escalacao)
        $escMandante = $partida['escalacoes']['mandante'] ?? null;
        $escVisitante = $partida['escalacoes']['visitante'] ?? null;
        $transmissao = (array)($partida['transmissao'] ?? []);
        $arbs = (array)($partida['arbitragem'] ?? []);

        $linhasArb = [];
        foreach ($arbs as $a) {
            if (!empty($a['nome_popular']) && !empty($a['funcao'])) {
                $linhasArb[] = "{$a['funcao']}: {$a['nome_popular']}";
            }
        }
        $linhasTrans = [];
        foreach ($transmissao as $t) {
            $nome = is_array($t) ? ($t['nome'] ?? $t['canal'] ?? null) : (string)$t;
            if ($nome) $linhasTrans[] = (string)$nome;
        }

        // Helper pra formatar escalação
        $formatarEscalacao = function ($esc) {
            if (!is_array($esc)) return null;
            $tatico = $esc['esquema_tatico'] ?? '';
            $tecnico = $esc['tecnico']['nome_popular'] ?? '';
            $linhas = [];
            foreach (($esc['titulares'] ?? []) as $j) {
                $nome = $j['atleta']['nome_popular'] ?? '';
                $pos = $j['posicao']['sigla'] ?? '';
                if ($nome) $linhas[] = $pos ? "{$nome} ({$pos})" : $nome;
            }
            $out = [];
            if ($tatico) $out[] = "Esquema: {$tatico}";
            if ($tecnico) $out[] = "Técnico: {$tecnico}";
            if (!empty($linhas)) $out[] = "Titulares: " . implode(', ', $linhas);
            return implode(' | ', $out);
        };

        $apiBriefing = "FONTE OFICIAL (api-futebol — verdade absoluta) [pub=" . substr($dataIso, 0, 10) . "]:\n";
        $apiBriefing .= "  Confronto: {$home} x {$away}\n";
        if ($compNome) $apiBriefing .= "  Competição: {$compNome}" . ($rodada ? " ({$rodada})" : '') . "\n";
        $apiBriefing .= "  Local: " . ($estad ?: '?') . "\n";
        $apiBriefing .= "  Data/hora ISO: {$dataIso}\n";
        if (!empty($linhasTrans)) $apiBriefing .= "  Transmissão: " . implode(', ', $linhasTrans) . "\n";
        if (!empty($linhasArb))   $apiBriefing .= "  Arbitragem: " . implode(' | ', $linhasArb) . "\n";

        $escM = $formatarEscalacao($escMandante);
        if ($escM) $apiBriefing .= "  Escalação {$home}: {$escM}\n";
        $escV = $formatarEscalacao($escVisitante);
        if ($escV) $apiBriefing .= "  Escalação {$away}: {$escV}\n";
        $apiBriefing .= "\n---\n\n";
        $apiOk = true;

        $countEsc = (($escMandante && !empty($escMandante['titulares'])) ? 1 : 0) + (($escVisitante && !empty($escVisitante['titulares'])) ? 1 : 0);
        echo "  ✓ api-futebol OK" . ($stale ? " (stale-cache)" : '') . " — escalações={$countEsc}/2, arbs=" . count($linhasArb) . ", transm=" . count($linhasTrans) . "\n";
    } catch (Throwable $e) {
        echo "  ⚠ api-futebol falhou: " . $e->getMessage() . " — fallback Serper\n";
    }
    echo "\n";
}

// ── 2. Carrega persona do site (elenco real, voz, tom) ──────────────────────
$persona = $cfgSiteRaw['persona'] ?? [];
if (empty($persona)) { fwrite(STDERR, "⚠ persona vazia em sites.php\n"); }
echo "→ [2/7] Persona carregada (autor: " . ($persona['autor'] ?? '?') . ")\n\n";

// ── 3. Busca fontes via Serper (queries focadas em data-alvo) ──────────────
echo "→ [3/7] Buscando fontes Serper (queries focadas)\n";
$diaJogo = (int)substr($jogo['data'] ?? '', 8, 2);
$mesJogo = (int)substr($jogo['data'] ?? '', 5, 2);
$mesesPt = ['','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
$mesNome = $mesesPt[$mesJogo] ?? '';
$diaMes = sprintf('%d/%02d', $diaJogo, $mesJogo);
$diaMesPorExtenso = "{$diaJogo} de {$mesNome}";

// Detecta se é jogo de volta (oitava/quartas/etc) pra adicionar contexto
$ehVolta = mb_stripos((string)($jogo['fase'] ?? ''), 'volta') !== false;
$queries = [
    // Específicas pro JOGO ALVO (data + contexto)
    "Vitória x {$adv} {$diaMes} {$comp}" . ($ehVolta ? ' jogo de volta' : ''),
    "Vitória x {$adv} {$diaMesPorExtenso} escalação provável",
    "Vitória {$adv} desfalques suspensos {$diaMes}",
    "Vitória x {$adv} {$comp} transmissão {$diaMes}",
];
// Domínios prioritários pra cobertura específica do EC Vitória
$dominiosVitoria = [
    'meuvitoria.com.br', 'arenarubronegra.com', 'bahianoticias.com.br',
    'ge.globo.com', 'lance.com.br', 'gazetaesportiva.com', 'placar.com.br',
    'atarde.com.br', 'correio24horas.com.br', 'metro1.com.br',
];
$urlsAll = [];
try {
    $serper = new Serper($cfg['serper_api_key'] ?? '');
    foreach ($queries as $q) {
        $resp = $serper->search($q, 8);
        foreach (($resp['organic'] ?? []) as $r) {
            $u = (string)($r['link'] ?? '');
            if ($u !== '' && !isset($urlsAll[$u])) $urlsAll[$u] = ['url' => $u, 'titulo' => $r['title'] ?? '', 'snippet' => $r['snippet'] ?? '', 'q' => $q];
        }
    }
} catch (Throwable $e) {
    echo "   ⚠ Serper falhou: " . $e->getMessage() . "\n";
}
// Score: prioriza domínios Vitória + Tier S/A
$ranked = [];
foreach ($urlsAll as $u => $d) {
    $host = parse_url($u, PHP_URL_HOST) ?: '';
    $hostClean = preg_replace('/^www\./', '', $host);
    $bonus = 0;
    foreach ($dominiosVitoria as $dom) {
        if (str_ends_with($hostClean, $dom) || str_contains($hostClean, $dom)) { $bonus = 100; break; }
    }
    $tierArr = SourceTrustScore::ordenarPorTier([['url' => $u]]);
    $tier = $tierArr[0]['tier'] ?? 'D';
    $tierScore = ['S' => 50, 'A' => 30, 'B' => 15, 'C' => 5, 'D' => 0][$tier] ?? 0;
    $ranked[] = ['url' => $u, 'score' => $bonus + $tierScore, 'titulo' => $d['titulo'], 'host' => $hostClean];
}
usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
$urlsFontes = array_column(array_slice($ranked, 0, 6), 'url');
echo "   ✓ " . count($urlsFontes) . " URLs (priorizando domínios Vitória):\n";
foreach (array_slice($ranked, 0, 6) as $r) echo "     · [score=" . $r['score'] . "] " . $r['host'] . " — " . substr($r['titulo'], 0, 50) . "\n";
echo "\n";

// ── 3b. Trends recentes do DB (informações REAIS da semana) ────────────────
echo "→ [3b/7] Carregando trends DB recentes 7d\n";
require_once __DIR__ . '/../lib/DbConnection.php';
$pdo = DbConnection::pdo();
$sql = "SELECT id, titulo, pingo_link, status, data_detectada FROM trends WHERE site=:s AND status IN ('publicado','aprovado','novo','fidelity_warn') AND data_detectada > NOW() - INTERVAL 7 DAY ORDER BY score_discover DESC, data_detectada DESC LIMIT 10";
$st = $pdo->prepare($sql);
$st->execute([':s' => $siteSlug]);
$trendsRecentes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$briefingTrends = '';
if (!empty($trendsRecentes)) {
    $briefingTrends .= "TRENDS RECENTES DO CLUBE (esta semana — fatos reais já noticiados):\n";
    foreach ($trendsRecentes as $t) {
        $briefingTrends .= "  · " . $t['titulo'] . "\n";
        $briefingTrends .= "    fonte: " . substr((string)$t['pingo_link'], 0, 100) . "\n";
    }
    $briefingTrends .= "\n";
    echo "   ✓ " . count($trendsRecentes) . " trends recentes carregados\n";
}
echo "\n";

// ── 4. Scrape conteúdo das fontes ──────────────────────────────────────────
echo "→ [4/7] Scrape conteúdo\n";
$scraper = new Scraper($cfg['user_agent'], (int)($cfg['scrape_timeout'] ?? 15));
$briefingFontes = $apiBriefing; // API oficial entra no topo do briefing (fonte primária)
$nomesFontes = []; // pra source-fidelity
$ogImagesCandidatos = []; // pra featured image
$urlsScrapedasOk = []; // só URLs que retornaram texto válido (pra InlineImageInjector)
$maxAgeFontesDias = (int)($args['max-age-fontes-dias'] ?? 14);
$cutoffPublishTs = time() - ($maxAgeFontesDias * 86400);
foreach ($urlsFontes as $idx => $url) {
    try {
        $sc = $scraper->fetch($url);
        $titulo = (string)($sc['meta']['title'] ?? '');
        $publishedRaw = (string)($sc['meta']['published'] ?? '');
        $publishedTs = $publishedRaw ? strtotime($publishedRaw) : 0;
        $publishedSrc = 'meta';

        // Fallback A: extrai data do path /YYYY/MM/DD/ (ge.globo, g1, etc.)
        if ($publishedTs === 0 && preg_match('#/(20\d{2})/(\d{2})/(\d{2})/#', $url, $um)) {
            $publishedTs = strtotime("{$um[1]}-{$um[2]}-{$um[3]}");
            $publishedSrc = 'url-ymd';
        }
        // Fallback B: padrão /DD-MM-YYYY/ (alguns portais — ex: ge.globo jogo)
        if ($publishedTs === 0 && preg_match('#/(\d{2})-(\d{2})-(20\d{2})[/.]#', $url, $um)) {
            $publishedTs = strtotime("{$um[3]}-{$um[2]}-{$um[1]}");
            $publishedSrc = 'url-dmy';
        }
        $publishedHuman = $publishedTs ? date('Y-m-d', $publishedTs) : '?';

        // Filtro 1: rejeita fontes datadas mais velhas que --max-age-fontes-dias
        if ($publishedTs > 0 && $publishedTs < $cutoffPublishTs) {
            $diasAtras = round((time() - $publishedTs) / 86400);
            echo "   · scrape SKIP (obsoleto {$diasAtras}d via {$publishedSrc}): {$url}\n";
            continue;
        }
        // Filtro 2: SEM DATA — só aceita se URL ou título contém marker do jogo-alvo
        if ($publishedTs === 0 && empty($args['allow-no-date'])) {
            $dataAlvoStr = (string)($jogo['data'] ?? '');
            $diaAlvo = $dataAlvoStr ? (int)substr($dataAlvoStr, 8, 2) : 0;
            $mesAlvo = $dataAlvoStr ? (int)substr($dataAlvoStr, 5, 2) : 0;
            $mesesPt = ['', 'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
            $mesNome = $mesesPt[$mesAlvo] ?? '';
            $tituloMeta = mb_strtolower((string)($sc['meta']['title'] ?? '') . ' ' . $url);
            $temMarker = false;
            if ($diaAlvo > 0 && $mesAlvo > 0) {
                $padroes = [
                    sprintf('%02d/%02d', $diaAlvo, $mesAlvo),
                    sprintf('%d/%d', $diaAlvo, $mesAlvo),
                    sprintf('%02d-%02d-20%d', $diaAlvo, $mesAlvo, (int)substr($dataAlvoStr, 2, 2)),
                    sprintf('%d de %s', $diaAlvo, $mesNome),
                ];
                foreach ($padroes as $p) {
                    if (mb_stripos($tituloMeta, $p) !== false) { $temMarker = true; break; }
                }
            }
            if (!$temMarker) {
                echo "   · scrape SKIP (sem data + sem marker {$diaAlvo}/{$mesAlvo}): {$url}\n";
                continue;
            }
            echo "   · scrape OK [sem-data+marker]: {$url}\n";
        }

        $paragraphs = $sc['content']['paragraphs'] ?? [];
        $textoTopo = trim(implode("\n", array_slice($paragraphs, 0, 8)));
        if (mb_strlen($textoTopo) < 100) continue;
        $briefingFontes .= "FONTE " . ($idx + 1) . " [pub={$publishedHuman}]: {$titulo}\nURL: {$url}\n{$textoTopo}\n\n---\n\n";
        if (preg_match_all('/\b[A-ZÁÉÍÓÚÂÊÔÃÕ][a-záéíóúâêôãõç]{2,}(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕ][a-záéíóúâêôãõç]{2,})?/u', $textoTopo, $mm)) {
            foreach ($mm[0] as $n) $nomesFontes[$n] = true;
        }
        // Coleta og:image pra featured + URL pra inline injector
        $ogImg = (string)($sc['meta']['og_image'] ?? '');
        if ($ogImg && filter_var($ogImg, FILTER_VALIDATE_URL)) {
            $ogImagesCandidatos[] = $ogImg;
        }
        $urlsScrapedasOk[] = $url;
        echo "   · scrape OK [{$publishedHuman}]: {$url} (" . mb_strlen($textoTopo) . " chars)\n";
    } catch (Throwable $e) {
        echo "   · scrape falhou: {$url} (" . $e->getMessage() . ")\n";
    }
}
echo "\n";

// Adversário também precisa estar nos nomes-fonte (LLM vai gerar OBVIO)
$nomesFontes[$adv] = true;
$nomesFontes['Vitória'] = true;
$nomesFontes['Esporte Clube Vitória'] = true;

// ── 5. Sonnet gera matéria ─────────────────────────────────────────────────
echo "→ [5/7] Sonnet gerando matéria pré-jogo\n";
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);

// Concordância: "do Copa" é erro — feminino exige "da". Detecta gênero do nome da competição.
$competicaoNome = $comp ?: 'Brasileirão';
$artigoComp = preg_match('/^(Copa|Liga|Série|Final|Sul-Americana|Libertadores)\b/i', $competicaoNome) ? 'da' : 'do';
$faseHint = '';
if (mb_stripos((string)($jogo['fase'] ?? ''), 'volta') !== false) $faseHint = ' de volta';
elseif (mb_stripos((string)($jogo['fase'] ?? ''), 'ida') !== false) $faseHint = ' de ida';
$tituloPadrao = "Vitória x {$adv}: onde assistir, escalação e horário do jogo{$faseHint} {$artigoComp} {$competicaoNome}";

// V4 ESTRITO: persona só pra voz/tom (não fatos), Sonnet só pode usar fontes
$systemPrompt = <<<EOT
Você redator do Leão da Barra. Tom: jornalismo factual de serviço público, frases curtas, sem clichê de torcida, sem dramatização. Acentuação portuguesa completa.

═══ REGRA ÚNICA E ABSOLUTA ═══

Cada FATO mencionado no post (nome de jogador, lesão, suspensão, escalação, árbitro, retrospecto) DEVE estar EXPLICITAMENTE escrito nas FONTES fornecidas abaixo.

PRIORIDADE entre fontes (quando houver conflito):
1. FONTE OFICIAL (api-futebol) — verdade absoluta. Escalação, transmissão, árbitros, data/hora.
2. FONTES SCRAPEDAS — usar pra contexto narrativo, retrospectos, declarações de jogadores/técnico.

Se FONTE OFICIAL diz X e fonte scraped diz Y, use X. Se conflito grave, omita.

Se a fonte trouxe → você pode mencionar.
Se a fonte NÃO trouxe → NÃO MENCIONE (mesmo se você "sabe" do training data).

═══ ATRIBUIÇÃO — VOZ DE AUTORIDADE PRÓPRIA ═══

NÓS somos o Leão da Barra. AS FONTES scrapedas são INSUMOS internos da nossa apuração — NÃO citar veículos por nome no corpo do texto.

PROIBIDO mencionar no texto:
✗ "Segundo o ge.globo / Lance / Terra / bolavip / Arena Rubro-Negra"
✗ "Conforme o [veículo]"
✗ "O [veículo] informa que / explica que / aponta que"
✗ Qualquer nome de portal externo

USAR no lugar (vozes de autoridade própria):
✓ "Apuração da nossa redação aponta que..."
✓ "Levantamento da equipe do Leão da Barra mostra que..."
✓ "A redação confirmou que..."
✓ "Conforme apurado pela redação..."
✓ "Segundo nosso acompanhamento..."

═══ PROIBIDO (factualidade) ═══
✗ Inventar lista de "11 prováveis" sem fonte que confirme
✗ Citar nomes de jogadores que não aparecem nas fontes
✗ Citar lesões/suspensões/contratos que não aparecem nas fontes
✗ Inventar histórico de confrontos sem fonte
✗ Inventar arbitragem sem fonte
✗ Especular ("o time deve atuar com...", "Jair pode escalar...")

═══ PERMITIDO ═══
✓ Reescrever fato da fonte em PT-BR jornalístico
✓ Escrever "escalação será definida em coletiva D-1" se nenhuma fonte trouxe
✓ OMITIR seção inteira se não há fonte
✓ Conteúdo CURTO é melhor que conteúdo INVENTADO

ESTRUTURA SUGERIDA (~350-600 palavras — pra Discover precisa volume + densidade):

1. P1 lead (3-4 frases): adversário, dia, hora, estádio, competição, transmissão (apenas o que está nos DADOS DO JOGO + fontes)
2. <h2>Onde assistir [Time A] x [Time B] ao vivo</h2> (sempre — H2 SEO friendly mesmo se transmissão "a confirmar")
3. <h2>Provável escalação do Vitória contra o [Adversário]</h2> (só se fonte trouxer; senão omitir esse h2)
4. <h2>Desfalques confirmados</h2> (só se fonte explícita)
5. <h2>Como o [Adversário] chega para o jogo</h2> (situação do oponente; só se fonte trouxer)
6. <h2>O que está em jogo</h2> (contexto: classificação, importância da partida — pode ser inferido dos dados oficiais)

═══ H2/H3 — REGRAS SEO ═══
✓ H2 deve conter PALAVRAS-CHAVE de busca real ("escalação provável", "onde assistir", "desfalques", "horário")
✓ H2 NUNCA pode ser pergunta sem resposta abaixo ("O que é isso") ou frase abstrata ("O que muda")
✗ PROIBIDO H2 vazio: "Próximos passos", "O que se sabe", "Sobre o confronto"
✗ PROIBIDO H2 com "Por que está em alta" / "O que muda" / "Entenda" — são gancho artificial sem valor SEO

Saída: APENAS HTML limpo (sem markdown ```). Use <p>, <h2>, <ul>, <li>, <strong>.
EOT;

$dataInfo = $dataStr . " (horário de Brasília)";
$mandoTxt = $mando === 'casa'
    ? "Vitória joga EM CASA (Barradão, Salvador)"
    : "Vitória joga FORA DE CASA, no estádio: " . ($jogo['estadio'] ?? '?');

// Contexto temporal: alerta sobre confusão com jogo de ida/anterior se houver
$contextoTemporal = '';
if (mb_stripos((string)($jogo['fase'] ?? ''), 'volta') !== false) {
    $contextoTemporal = "\n═══ ATENÇÃO TEMPORAL ═══\n"
        . "Este post é sobre o JOGO DE VOLTA, marcado para {$dataStr}.\n"
        . "Existe um JOGO DE IDA passado (já aconteceu, com placar definido).\n"
        . "IGNORE qualquer informação nas fontes que se refira ao jogo de IDA — escalações, desfalques, transmissão e arbitragem mudam de um jogo para o outro.\n"
        . "Se a fonte cita 'amanhã' ou 'nesta data X', verifique se X bate com {$dataStr}. Se NÃO bate, NÃO use o fato.\n\n";
}

$userPrompt = $contextoTemporal . "DADOS DO JOGO (verdade absoluta — pode citar livremente):\n"
    . "  Adversário: {$adv}\n"
    . "  Competição: {$comp}\n"
    . "  Mando: {$mandoTxt}\n"
    . "  Data/hora: {$dataInfo}\n"
    . "  Estádio: " . ($jogo['estadio'] ?? '-') . "\n"
    . "  Transmissão: " . ($jogo['transmissao'] ?? 'A definir') . "\n\n"
    . ($briefingTrends ?: '')
    . "FONTES SCRAPEDAS (cada parágrafo do post DEVE ser atribuído a uma destas fontes ou aos DADOS DO JOGO acima):\n\n"
    . ($briefingFontes ?: "[Sem fontes scraped — escreva APENAS sobre dados do jogo + trends acima, omitindo seções sem informação]\n\n")
    . "═══ COMO PROCEDER ═══\n"
    . "Antes de escrever cada parágrafo, pergunte: 'esse fato está NUMA das fontes ou trends acima?'\n"
    . "  - Se SIM: escreva, idealmente atribuindo a fonte\n"
    . "  - Se NÃO: NÃO ESCREVA — ou pula o tópico, ou cita 'a definir'\n\n"
    . "Se as fontes mencionam DESFALQUES (Matheuzinho, Erick suspenso STJD, etc.) — liste eles.\n"
    . "Se NENHUMA fonte traz escalação — NÃO LISTE jogadores. Diga 'escalação a confirmar'.\n\n"
    . "Conteúdo CURTO e VERDADEIRO > conteúdo longo e inventado. Aceitável omitir <h2> de seções sem fonte.\n\n"
    . "Saída: só HTML.";

$resp = $claude->callPublic([['role' => 'user', 'content' => $userPrompt]], $systemPrompt, 3500);
$contentHtml = trim((string)($resp['content'][0]['text'] ?? ''));
$contentHtml = preg_replace('/^\s*```(?:html)?\s*\n?/i', '', $contentHtml);
$contentHtml = preg_replace('/\n?\s*```\s*$/i', '', $contentHtml);
$contentHtml = trim($contentHtml);
if ($contentHtml === '') { fwrite(STDERR, "✗ Claude retornou vazio\n"); exit(1); }
echo "   ✓ " . str_word_count(strip_tags($contentHtml)) . " palavras geradas\n\n";

// ── 6. Source-Fidelity check ───────────────────────────────────────────────
echo "→ [6/7] SourceFidelityValidator\n";
$fidelityWarn = false;
$fidelityDetail = '';
try {
    $textosFontes = [];
    foreach ($urlsFontes as $url) {
        try { $textosFontes[$url] = $scraper->fetch($url); } catch (Throwable $e) {}
    }
    $val = SourceFidelityValidator::validar($contentHtml, $textosFontes);
    $sev = $val['severity'] ?? 'ok';
    $achados = count($val['nomes_alucinados'] ?? []);
    echo "   severity={$sev} | nomes_alucinados={$achados}\n";
    if ($achados > 0) {
        echo "   ⚠ alucinações: " . implode(', ', array_slice($val['nomes_alucinados'], 0, 5)) . "\n";
        $fidelityWarn = ($sev === 'fail');
        $fidelityDetail = json_encode($val['nomes_alucinados']);
    }
} catch (Throwable $e) {
    echo "   ⚠ validator falhou: " . $e->getMessage() . "\n";
}
echo "\n";

// ── 7. Schema BroadcastEvent + publish + indexing ──────────────────────────
$builder = new BroadcastEventBuilder();
$schema = $builder->montar($jogo, []);
$contentComSchema = $contentHtml . $builder->renderizarScript($schema);

// Cluster cross-link: se já existem outros posts do mesmo jogo (pos_jogo, etc.),
// injeta bloco "Mais sobre Vitória x Adv" + Schema Series no fim
$clusterLinker = new JogoClusterLinker(__DIR__ . '/../data/jogos_vitoria.json');
if (!empty($jogo['posts_gerados']) && !$dryRun && !$mockJson) {
    $wpTmp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    $contentComSchema = $clusterLinker->injetarNoPost($jogo, 'pre_jogo', $contentComSchema, $wpTmp);
}

$titulo = $tituloPadrao;
$slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $titulo))), '-');
$slug = substr($slug, 0, 70);

// fidelity_warn + draft override → não publica live se há alucinação
if ($fidelityWarn && !$asDraft) {
    echo "⚠ FIDELITY FAIL — forçando status=draft pra revisão manual\n";
    $asDraft = true;
}

// Featured image: prioridade og:image > Serper Images (Google) > Pexels via DiscoverImagemFeatured
$featuredMediaId = 0;
$featuredUrl = '';
$featuredCredito = 'divulgação';
if (!$dryRun) {
    $wpUploader = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    $altText = "Vitória x {$adv} pelo " . ($comp ?: 'Brasileirão');

    // Tier 1: og:image da melhor fonte scrapeada (mais autêntico)
    foreach ($ogImagesCandidatos as $ogUrl) {
        if (!filter_var($ogUrl, FILTER_VALIDATE_URL)) continue;
        $mid = (int)$wpUploader->uploadImagemPorUrl($ogUrl, $altText, '');
        if ($mid > 0) {
            $featuredMediaId = $mid;
            $featuredUrl = $ogUrl;
            echo "   ✓ Featured: media_id={$mid} fonte=og\n";
            break;
        }
    }

    // Tier 2: Serper Images (Google) — específica e contextual, evita Pexels stock
    if ($featuredMediaId === 0 && !empty($cfg['serper_api_key'])) {
        try {
            $sx = new SerperImages($cfg['serper_api_key']);
            $queryImg = "Vitória x {$adv} " . ($jogo['estadio'] ?? '');
            $img = $sx->melhor($queryImg, ['min_w' => 800, 'min_h' => 400, 'credito_generico' => false]);
            if ($img) {
                $mid = (int)$wpUploader->uploadImagemPorUrl((string)$img['imageUrl'], $altText, '');
                if ($mid > 0) {
                    $featuredMediaId = $mid;
                    $featuredUrl = (string)$img['imageUrl'];
                    $featuredCredito = (string)($img['credito'] ?? 'divulgação');
                    echo "   ✓ Featured: media_id={$mid} fonte=serper-images credito={$featuredCredito} score={$img['score']}\n";
                }
            }
        } catch (Throwable $e) {
            echo "   ⚠ Serper Images falhou: " . $e->getMessage() . "\n";
        }
    }

    // Tier 3: Pexels (último recurso — stock genérico)
    if ($featuredMediaId === 0) {
        try {
            $imagemFeatured = new DiscoverImagemFeatured($cfg);
            $resultado = $imagemFeatured->escolher([
                'termo' => "Vitória x {$adv}",
                'cluster_key' => 'esportes',
                'briefing_titulo' => $titulo,
                'og_image_fallback' => '',
            ]);
            $imgUrl = (string)($resultado['url'] ?? '');
            if ($imgUrl) {
                $featuredMediaId = (int)$wpUploader->uploadImagemPorUrl($imgUrl, $altText, $resultado['slug_sugerido'] ?? '');
                $featuredUrl = $imgUrl;
                echo "   ✓ Featured: media_id={$featuredMediaId} fonte=" . ($resultado['fonte'] ?? '?') . " (fallback)\n";
            } else {
                echo "   ⚠ Sem featured image disponível\n";
            }
        } catch (Throwable $e) {
            echo "   ⚠ featured image falhou: " . $e->getMessage() . "\n";
        }
    }

    // Caption + description + alt_text na featured (SEO + acessibilidade)
    if ($featuredMediaId > 0) {
        try {
            $captionTxt = "{$titulo} (Foto: {$featuredCredito})";
            $descTxt = "Imagem ilustrativa da matéria '{$titulo}' publicada no portal Leão da Barra. " . mb_substr(strip_tags($contentHtml), 0, 200);
            $wpUploader->atualizarMedia($featuredMediaId, [
                'caption' => $captionTxt,
                'description' => $descTxt,
                'title' => $titulo,
                'alt_text' => $altText,
            ]);
            echo "   ✓ Featured caption + description setados\n";
        } catch (Throwable $e) { echo "   ⚠ atualizarMedia falhou: " . $e->getMessage() . "\n"; }
    }
}

// Categoria: detecta no contexto do jogo + competicao
$categoryIds = [];
if (!$dryRun) {
    $catsPropostas = ['Esporte Clube Vitória'];
    if (mb_stripos($comp, 'Copa do Brasil') !== false) $catsPropostas[] = 'Copa do Brasil';
    if (mb_stripos($comp, 'Copa do Nordeste') !== false || mb_stripos($comp, 'Nordestão') !== false) $catsPropostas[] = 'Copa do Nordeste';
    if (mb_stripos($comp, 'Brasileir') !== false || mb_stripos($comp, 'Série A') !== false) $catsPropostas[] = 'Brasileirão';
    if ($adv) $catsPropostas[] = $adv; // adversario como categoria
    try {
        $cm = new CategoryMatcher($wpUploader, 70.0);
        $resolvido = $cm->resolverComMatch($catsPropostas);
        $categoryIds = array_values(array_filter(array_map('intval', $resolvido)));
        echo "   ✓ Categorias: " . implode(',', $categoryIds) . "\n";
    } catch (Throwable $e) { echo "   ⚠ categoria: " . $e->getMessage() . "\n"; }
}

$payload = [
    'title' => $titulo,
    'slug' => $slug,
    'content' => $contentComSchema,
    'status' => $asDraft ? 'draft' : 'publish',
    'meta' => [
        'rank_math_focus_keyword' => "vitoria x " . mb_strtolower($adv),
        'rank_math_title' => "{$titulo} | Leão da Barra",
        'rank_math_description' => "Pré-jogo do Vitória contra o {$adv}: onde assistir, escalação e detalhes.",
    ],
];
if ($featuredMediaId > 0) {
    $payload['featured_media'] = $featuredMediaId;
}
if (!empty($categoryIds)) {
    $payload['categories'] = $categoryIds;
}
if (!empty($cfg['default_post_author_id'])) {
    $payload['author'] = (int)$cfg['default_post_author_id'];
}

if ($dryRun) {
    echo "→ [7/7] DRY-RUN\n";
    echo "  Título: {$titulo}\n  Status: {$payload['status']}\n  Schema: BroadcastEvent OK\n";
    echo "\n--- HTML preview (primeiros 800 chars) ---\n" . substr($contentComSchema, 0, 800) . "...\n";
    exit(0);
}

echo "→ [7/7] Publicando WP + Indexing API\n";
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
try {
    $r = $wp->criarPost($payload);
    $postId = (int)($r['id'] ?? 0);
    $linkPub = (string)($r['link'] ?? '');
    if ($postId === 0) throw new RuntimeException('post não criado');
    echo "   ✓ Post #{$postId} status={$payload['status']} link={$linkPub}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✗ falha publicar WP: " . $e->getMessage() . "\n");
    exit(1);
}

// Pós-publish: 3 enriquecimentos (entity links + inline ≠ featured + relacionados)
$htmlFinal = $contentComSchema;

// (1) Entity links pra hubs
try {
    $linker = new EntityPageLinker($wp, $siteSlug, ['entidade', 'conceito'], 3, 'publish');
    $resL = $linker->injetar($htmlFinal);
    if (!empty($resL['html']) && $resL['html'] !== $htmlFinal) {
        $htmlFinal = $resL['html'];
        $logL = $linker->getLog();
        echo "   ✓ Entity links: " . ($logL['links_inseridos'] ?? 0) . " inseridos\n";
    }
} catch (Throwable $e) { echo "   ⚠ entity links: " . $e->getMessage() . "\n"; }

// (2) Inline image — exclui URL da featured
$urlsParaInline = array_values(array_filter($urlsScrapedasOk, fn($u) => $u !== $featuredUrl));
try {
    $resInline = InlineImageInjector::injetar($htmlFinal, $urlsParaInline, $wp, 1, $titulo, $cfg);
    if (($resInline['log']['inseridas'] ?? 0) > 0) {
        $htmlFinal = $resInline['html'];
        echo "   ✓ Inline image: " . $resInline['log']['inseridas'] . " inserida (≠ featured)\n";
    } else {
        echo "   ⊘ Inline image: 0 inseridas (candidatas=" . ($resInline['log']['candidatas_encontradas'] ?? 0) . ")\n";
    }
} catch (Throwable $e) { echo "   ⚠ inline image: " . $e->getMessage() . "\n"; }

// (3) Posts relacionados (keyword curta — 1ª palavra significativa)
try {
    $kwBusca = 'Vitória';
    if (preg_match_all('/\b([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç]{3,})\b/u', $titulo, $mm)) {
        $palavras = array_values(array_filter($mm[1], fn($p) => !in_array(mb_strtolower($p), ['vitória', 'leão', 'flamengo', 'fluminense'])));
        if (!empty($palavras)) $kwBusca = (string)$palavras[0];
    }
    $kwBusca = $kwBusca ?: 'Vitória';
    $relacionados = $wp->buscarRelacionados($kwBusca, 6, $postId);
    if (count($relacionados) >= 2) {
        $blocoRel = "\n<aside class='posts-relacionados' aria-label='Posts relacionados'>\n  <h2>Veja também</h2>\n  <ul>\n";
        foreach (array_slice($relacionados, 0, 4) as $rel) {
            $titRel = htmlspecialchars(html_entity_decode((string)$rel['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $linkRel = htmlspecialchars((string)$rel['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $blocoRel .= "    <li><a href='{$linkRel}'>{$titRel}</a></li>\n";
        }
        $blocoRel .= "  </ul>\n</aside>\n";
        if (preg_match('/<script[^>]*data-broadcast-event/', $htmlFinal)) {
            $htmlFinal = preg_replace('/(<script[^>]*data-broadcast-event)/', $blocoRel . "$1", $htmlFinal, 1);
        } else {
            $htmlFinal .= $blocoRel;
        }
        echo "   ✓ Posts relacionados: " . min(4, count($relacionados)) . " links\n";
    }
} catch (Throwable $e) { echo "   ⚠ relacionados: " . $e->getMessage() . "\n"; }

if ($htmlFinal !== $contentComSchema) {
    $wp->atualizarPost($postId, ['content' => $htmlFinal]);
}

if ($payload['status'] === 'publish') {
    try {
        $idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
        $rIdx = $idx->notifyUrl($linkPub, 'URL_UPDATED');
        echo "   ✓ Indexing API HTTP " . ($rIdx['http_code'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo "   ⚠ Indexing API falhou: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ⊘ Indexing API SKIP (post draft)\n";
}

// Cluster: registra post no calendário + backfill links nos irmãos
if (!$mockJson && !empty($gameId)) {
    $cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');
    $registrou = $cal->registrarPostGerado($gameId, 'pre_jogo', $postId);
    echo "   " . ($registrou ? "✓" : "⚠") . " Calendário: posts_gerados.pre_jogo={$postId}\n";

    if (!empty($jogo['posts_gerados'])) {
        $bf = $clusterLinker->backfillIrmaos($jogo, 'pre_jogo', $postId, $wp);
        echo "   ✓ Cluster backfill: {$bf['atualizados']} irmão(s) atualizado(s)";
        if (!empty($bf['erros'])) echo " (erros: " . count($bf['erros']) . ")";
        echo "\n";
    }
}

echo "\n═══ RESUMO ═══\n";
echo "  post_id:      {$postId}\n";
echo "  status:       {$payload['status']}\n";
echo "  link:         {$linkPub}\n";
echo "  fidelity:     " . ($fidelityWarn ? "FAIL ({$fidelityDetail})" : "ok") . "\n";
echo "  fontes_used:  " . count($urlsFontes) . "\n";
