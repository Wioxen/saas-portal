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
}

$adv = $jogo['adversario']['nome'] ?? '?';
$comp = $jogo['competicao'] ?? '?';
$mando = $jogo['mando'] ?? '?';
$dataStr = ($jogo['data'] ?? '?') . ' ' . ($jogo['hora'] ?? '?');
echo "  adversário: {$adv} · competição: {$comp} · mando: {$mando} · data: {$dataStr}\n\n";

// ── 2. Carrega persona do site (elenco real, voz, tom) ──────────────────────
$persona = $cfgSiteRaw['persona'] ?? [];
if (empty($persona)) { fwrite(STDERR, "⚠ persona vazia em sites.php\n"); }
echo "→ [2/7] Persona carregada (autor: " . ($persona['autor'] ?? '?') . ")\n\n";

// ── 3. Busca fontes via Serper (3 queries focadas) ─────────────────────────
echo "→ [3/7] Buscando fontes Serper (3 queries focadas)\n";
$queries = [
    "Vitória escalação treino " . $adv . " " . ($jogo['data'] ?? ''),
    "Vitória desfalques suspensos " . $adv,
    "Vitória x " . $adv . " " . $comp . " transmissão",
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
$briefingFontes = '';
$nomesFontes = []; // pra source-fidelity
foreach ($urlsFontes as $idx => $url) {
    try {
        $sc = $scraper->fetch($url);
        $titulo = (string)($sc['meta']['title'] ?? '');
        $paragraphs = $sc['content']['paragraphs'] ?? [];
        $textoTopo = trim(implode("\n", array_slice($paragraphs, 0, 8)));
        if (mb_strlen($textoTopo) < 100) continue;
        $briefingFontes .= "FONTE " . ($idx + 1) . ": {$titulo}\nURL: {$url}\n{$textoTopo}\n\n---\n\n";
        // Nomes próprios pra fidelity
        if (preg_match_all('/\b[A-ZÁÉÍÓÚÂÊÔÃÕ][a-záéíóúâêôãõç]{2,}(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕ][a-záéíóúâêôãõç]{2,})?/u', $textoTopo, $mm)) {
            foreach ($mm[0] as $n) $nomesFontes[$n] = true;
        }
        echo "   · scrape OK: {$url} (" . mb_strlen($textoTopo) . " chars)\n";
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

$tituloPadrao = "Vitória x {$adv}: onde assistir, escalação provável e horário do jogo do " . ($comp ?: 'Brasileirão');

// V4 ESTRITO: persona só pra voz/tom (não fatos), Sonnet só pode usar fontes
$systemPrompt = <<<EOT
Você redator do Leão da Barra. Tom: jornalismo factual de serviço público, frases curtas, sem clichê de torcida, sem dramatização. Acentuação portuguesa completa.

═══ REGRA ÚNICA E ABSOLUTA ═══

Cada FATO mencionado no post (nome de jogador, lesão, suspensão, escalação, árbitro, retrospecto) DEVE estar EXPLICITAMENTE escrito nas FONTES SCRAPEDAS fornecidas.

Se a fonte trouxe → você pode mencionar.
Se a fonte NÃO trouxe → NÃO MENCIONE (mesmo se você "sabe" do training data).

PROIBIDO:
✗ Inventar lista de "11 prováveis" sem fonte que confirme
✗ Citar nomes de jogadores que não aparecem nas fontes
✗ Citar lesões/suspensões/contratos que não aparecem nas fontes
✗ Inventar histórico de confrontos sem fonte
✗ Inventar arbitragem sem fonte
✗ Especular ("o time deve atuar com...", "Jair pode escalar...")

PERMITIDO:
✓ Reescrever fato da fonte em PT-BR jornalístico
✓ Atribuir explicitamente ("Segundo o ge.globo, ...")
✓ Escrever "escalação será definida em coletiva D-1" se nenhuma fonte trouxe
✓ OMITIR seção inteira se não há fonte
✓ Conteúdo CURTO é melhor que conteúdo INVENTADO

ESTRUTURA SUGERIDA (~250-400 palavras é o ideal — não infle):

1. P1 lead (3-4 frases): adversário, dia, hora, estádio, competição, transmissão (apenas o que está nos DADOS DO JOGO + fontes)
2. <h2>Desfalques confirmados</h2> (só se há fonte com lesão/suspensão. Senão omitir h2)
3. <h2>Onde assistir</h2> (só se transmissão informada nos dados)
4. <h2>O que se sabe até agora</h2> (1-2 parágrafos resumindo TRENDS + FONTES sem inventar)

Saída: APENAS HTML limpo (sem markdown ```). Use <p>, <h2>, <ul>, <li>, <strong>.
EOT;

$dataInfo = $dataStr . " (horário de Brasília)";
$mandoTxt = $mando === 'casa'
    ? "Vitória joga EM CASA (Barradão, Salvador)"
    : "Vitória joga FORA DE CASA, no estádio: " . ($jogo['estadio'] ?? '?');

$userPrompt = "DADOS DO JOGO (verdade absoluta — pode citar livremente):\n"
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

$titulo = $tituloPadrao;
$slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $titulo))), '-');
$slug = substr($slug, 0, 70);

// fidelity_warn + draft override → não publica live se há alucinação
if ($fidelityWarn && !$asDraft) {
    echo "⚠ FIDELITY FAIL — forçando status=draft pra revisão manual\n";
    $asDraft = true;
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

echo "\n═══ RESUMO ═══\n";
echo "  post_id:      {$postId}\n";
echo "  status:       {$payload['status']}\n";
echo "  link:         {$linkPub}\n";
echo "  fidelity:     " . ($fidelityWarn ? "FAIL ({$fidelityDetail})" : "ok") . "\n";
echo "  fontes_used:  " . count($urlsFontes) . "\n";
