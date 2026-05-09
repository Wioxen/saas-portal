<?php
declare(strict_types=1);

/**
 * gerar_preview_tatico.php — preview D-1 (Onda 2 do cluster jogo).
 *
 * Foco diferente do pre_jogo: o leitor já sabe onde assistir + horário.
 * Quer saber:
 *   - Escalação provável (quem joga, quem volta, quem está fora)
 *   - Quem é o árbitro / arbitragem
 *   - Punições / suspensões / contexto extra-campo
 *   - O que esperar do adversário
 *
 * Schema: NewsArticle (BroadcastEvent fica no #1050 pré-jogo).
 * Cluster: registra em posts_gerados.preview_tatico + backfill irmãos.
 *
 * Pipeline (mesma regra estrita V4):
 *   1. Lê jogo do calendário
 *   2. Recebe lista de trend_ids relevantes (--trend-ids=)
 *   3. Coleta URLs do pingo_link/payload de cada trend
 *   4. Scrape conteúdo
 *   5. Sonnet gera com regra estrita: cada fato deve estar nas fontes
 *   6. SourceFidelityValidator
 *   7. Cluster linker injeta cross-link
 *   8. Publica WP + Indexing API
 *
 * Uso:
 *   php scripts/gerar_preview_tatico.php --game-id=2026-05-09-vit-flu --trend-ids=8365,9466,10075,10076,9546
 *   php scripts/gerar_preview_tatico.php ... --draft     (status=draft)
 *   php scripts/gerar_preview_tatico.php ... --dry-run   (sem publicar)
 */

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$gameId = (string)($args['game-id'] ?? '');
$trendIdsRaw = (string)($args['trend-ids'] ?? '');
$dryRun = !empty($args['dry-run']);
$siteSlug = (string)($args['site'] ?? 'leaodabarra');
$asDraft = !empty($args['draft']);

if ($gameId === '' || $trendIdsRaw === '') {
    fwrite(STDERR, "uso: php gerar_preview_tatico.php --game-id=ID --trend-ids=N1,N2,N3 [--draft] [--dry-run]\n");
    exit(2);
}
$trendIds = array_filter(array_map('intval', explode(',', $trendIdsRaw)));
if (empty($trendIds)) { fwrite(STDERR, "✗ trend-ids vazio\n"); exit(2); }

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/SourceTrustScore.php';
require_once __DIR__ . '/../lib/SourceFidelityValidator.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';
require_once __DIR__ . '/../lib/JogosCalendario.php';
require_once __DIR__ . '/../lib/JogoClusterLinker.php';
require_once __DIR__ . '/../lib/DbConnection.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);
$cfgSiteRaw = sitesDisponiveis()[$siteSlug] ?? $cfg;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Preview Tático D-1 (Onda 2) — site={$siteSlug}\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ── 1. Carrega jogo ────────────────────────────────────────────────────────
$cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');
$jogo = null;
foreach ($cal->jogos() as $j) {
    if (($j['id'] ?? '') === $gameId) { $jogo = $j; break; }
}
if (!$jogo) { fwrite(STDERR, "✗ jogo {$gameId} não encontrado\n"); exit(1); }

$adv = $jogo['adversario']['nome'] ?? '?';
$mando = $jogo['mando'] ?? '?';
$dataStr = ($jogo['data'] ?? '?') . ' ' . ($jogo['hora'] ?? '?');
echo "→ [1/6] Jogo: Vitória " . ($mando === 'casa' ? 'x ' . $adv : 'em ' . $adv) . " · {$dataStr}\n\n";

// ── 2. Coleta URLs das trends ──────────────────────────────────────────────
$pdo = DbConnection::pdo();
$qmarks = implode(',', array_fill(0, count($trendIds), '?'));
$st = $pdo->prepare("SELECT id, titulo, pingo_link, payload, score_discover FROM trends WHERE id IN ({$qmarks})");
$st->execute($trendIds);
$trends = $st->fetchAll(PDO::FETCH_ASSOC);
if (empty($trends)) { fwrite(STDERR, "✗ nenhuma trend encontrada\n"); exit(1); }

$fontes = [];
foreach ($trends as $t) {
    $payload = json_decode($t['payload'] ?? '{}', true) ?: [];
    $url = $t['pingo_link'] ?: ($payload['link'] ?? $payload['url'] ?? '');
    if ($url) $fontes[] = ['trend_id' => $t['id'], 'titulo' => $t['titulo'], 'url' => $url, 'score' => $t['score_discover']];
}
echo "→ [2/6] " . count($fontes) . " fontes coletadas das trends\n";
foreach ($fontes as $f) echo "  · #{$f['trend_id']} [{$f['score']}] " . substr($f['titulo'], 0, 70) . "\n";
echo "\n";

// ── 3. Scrape conteúdo ─────────────────────────────────────────────────────
echo "→ [3/6] Scrapando " . count($fontes) . " URLs\n";
$scraper = new Scraper();
$scraped = [];
foreach ($fontes as $f) {
    try {
        $html = $scraper->fetch($f['url']);
        $texto = $scraper->extrairTexto($html);
        if (mb_strlen($texto) < 200) {
            echo "  ⚠ {$f['url']} retornou texto curto (" . mb_strlen($texto) . " chars), skip\n";
            continue;
        }
        $scraped[] = ['url' => $f['url'], 'titulo' => $f['titulo'], 'texto' => mb_substr($texto, 0, 4000)];
        echo "  ✓ " . parse_url($f['url'], PHP_URL_HOST) . " (" . mb_strlen($texto) . " chars)\n";
    } catch (Throwable $e) {
        echo "  ✗ {$f['url']}: " . $e->getMessage() . "\n";
    }
}
if (count($scraped) < 2) { fwrite(STDERR, "✗ menos de 2 fontes scrapeadas com sucesso\n"); exit(3); }
echo "\n";

// ── 4. Sonnet — preview tático D-1 ─────────────────────────────────────────
echo "→ [4/6] Sonnet (regra estrita V4)\n";
$claude = new Claude($cfg['anthropic_api_key']);

$briefingFontes = '';
foreach ($scraped as $i => $s) {
    $briefingFontes .= "FONTE " . ($i + 1) . ": {$s['titulo']}\nURL: {$s['url']}\n\n{$s['texto']}\n\n---\n\n";
}

$systemPrompt = <<<EOT
Você é redator do Leão da Barra. Tom: jornalismo factual de serviço público, frases curtas, sem clichê de torcida.

═══ REGRA ÚNICA E ABSOLUTA ═══
Cada FATO mencionado (jogador, lesão, suspensão, escalação, árbitro, retrospecto, punição) DEVE estar EXPLICITAMENTE escrito nas FONTES SCRAPEDAS abaixo.

Se a fonte trouxe → você pode mencionar (idealmente atribuindo).
Se a fonte NÃO trouxe → NÃO MENCIONE (mesmo se você "sabe" do training data).

═══ FOCO DESTE POST (preview D-1, NÃO pré-jogo) ═══
O leitor já sabe horário e onde assistir (post anterior cobre isso). Aqui ele quer:
1. Quem volta / quem está fora (desfalques + retornos)
2. Provável escalação (só se fonte trouxer; senão "a definir")
3. Arbitragem (árbitro principal + auxiliares se fonte tiver)
4. Contexto extra-campo (punições STJD, sanções, suspensões)
5. O que esperar do adversário

PROIBIDO:
✗ Inventar lista de "11 prováveis" sem fonte explícita
✗ Citar lesões ou suspensões que não aparecem nas fontes
✗ Especular ("o time deve atuar com...", "X pode escalar...")
✗ Repetir info do pré-jogo (horário, onde assistir, transmissão)

PERMITIDO E ESPERADO:
✓ Reescrever fato da fonte em PT-BR jornalístico
✓ Atribuir explicitamente ("Segundo a Arena Rubro-Negra, ...")
✓ Conectar fatos das fontes ("Com Cacá fora e mais um titular ausente, ...")
✓ Conteúdo CURTO (~350-500 palavras) e FIDEDIGNO

ESTRUTURA SUGERIDA:
1. <p> Lead 3-4 frases conectando os principais fatos das fontes
2. <h2>Desfalques e retornos</h2> + lista
3. <h2>Arbitragem</h2> (se fonte tem)
4. <h2>Contexto extra-campo</h2> (STJD/punições, se fonte tem)
5. <h2>Provável escalação</h2> (só se fonte trouxer; senão omita ou "a definir")

Saída: APENAS HTML limpo (sem markdown ```). Tag <h2> usa aspas simples nos atributos.
EOT;

$advCanonico = $jogo['mando'] === 'casa' ? "{$adv} no " . ($jogo['estadio'] ?? 'Barradão') : "{$adv} no " . ($jogo['estadio'] ?? '?');
$userPrompt = "DADOS DO JOGO (verdade absoluta):\n"
    . "  Vitória x {$adv} · {$dataStr} · " . ($jogo['estadio'] ?? '?') . "\n"
    . "  Competição: " . ($jogo['competicao'] ?? '?') . "\n\n"
    . "FONTES SCRAPEDAS (cada parágrafo deve ser atribuível):\n\n"
    . $briefingFontes
    . "Escreva o preview tático D-1 seguindo as regras. Saída só HTML.";

$resposta = $claude->callPublic([
    'model' => 'claude-sonnet-4-5-20251022',
    'max_tokens' => 2000,
    'system' => $systemPrompt,
    'messages' => [['role' => 'user', 'content' => $userPrompt]],
]);
$contentHtml = trim((string)($resposta['content'][0]['text'] ?? ''));
if ($contentHtml === '') { fwrite(STDERR, "✗ Sonnet vazio\n"); exit(4); }
echo "  ✓ " . mb_strlen($contentHtml) . " chars gerados\n\n";

// ── 5. SourceFidelityValidator ─────────────────────────────────────────────
echo "→ [5/6] SourceFidelityValidator\n";
$fontesTexto = array_map(fn($s) => $s['texto'], $scraped);
$validator = new SourceFidelityValidator();
$resultado = $validator->validar($contentHtml, $fontesTexto);
$fidelityWarn = ($resultado['severity'] ?? '') === 'fail';
echo "  severity=" . ($resultado['severity'] ?? '?') . " | nomes_alucinados=" . count($resultado['nomes_alucinados'] ?? []) . "\n\n";

// ── 6. Schema NewsArticle + cluster + publish ──────────────────────────────
$titulo = "Vitória x {$adv}: desfalques, arbitragem e o que esperar do duelo no " . ($jogo['estadio'] ?? '?');
$slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $titulo))), '-');
$slug = substr($slug, 0, 70);

if ($fidelityWarn && !$asDraft) {
    echo "⚠ FIDELITY FAIL — forçando draft pra revisão\n";
    $asDraft = true;
}

$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);

// Cluster linker injeta bloco "Mais sobre Vitória x Adv" + Schema Series
$clusterLinker = new JogoClusterLinker(__DIR__ . '/../data/jogos_vitoria.json');
$contentFinal = $contentHtml;
if (!empty($jogo['posts_gerados']) && !$dryRun) {
    $contentFinal = $clusterLinker->injetarNoPost($jogo, 'preview_tatico', $contentFinal, $wp);
}

$payload = [
    'title' => $titulo,
    'slug' => $slug,
    'content' => $contentFinal,
    'status' => $asDraft ? 'draft' : 'publish',
    'meta' => [
        'rank_math_focus_keyword' => "vitoria x " . mb_strtolower($adv),
        'rank_math_title' => "{$titulo} | Leão da Barra",
        'rank_math_description' => "Preview do jogo: desfalques, arbitragem, retornos e o que esperar de Vitória x {$adv}.",
    ],
];
if (!empty($cfg['default_post_author_id'])) {
    $payload['author'] = (int)$cfg['default_post_author_id'];
}

if ($dryRun) {
    echo "→ [6/6] DRY-RUN\n";
    echo "  Título: {$titulo}\n";
    echo "  Status: {$payload['status']}\n";
    echo "\n--- HTML preview (1000 chars) ---\n" . substr($contentFinal, 0, 1000) . "...\n";
    exit(0);
}

echo "→ [6/6] Publicando WP + Indexing API\n";
try {
    $r = $wp->criarPost($payload);
    $postId = (int)($r['id'] ?? 0);
    $linkPub = (string)($r['link'] ?? '');
    if ($postId === 0) throw new RuntimeException('post não criado');
    echo "   ✓ Post #{$postId} status={$payload['status']} link={$linkPub}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✗ falha publicar WP: " . $e->getMessage() . "\n");
    exit(5);
}

if ($payload['status'] === 'publish') {
    try {
        $idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
        $rIdx = $idx->notifyUrl($linkPub, 'URL_UPDATED');
        echo "   ✓ Indexing API HTTP " . ($rIdx['http_code'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo "   ⚠ Indexing API falhou: " . $e->getMessage() . "\n";
    }
}

// Cluster: registra + backfill
$registrou = $cal->registrarPostGerado($gameId, 'preview_tatico', $postId);
echo "   " . ($registrou ? "✓" : "⚠") . " Calendário: posts_gerados.preview_tatico={$postId}\n";

if (!empty($jogo['posts_gerados'])) {
    $bf = $clusterLinker->backfillIrmaos($jogo, 'preview_tatico', $postId, $wp);
    echo "   ✓ Cluster backfill: {$bf['atualizados']} irmão(s) atualizado(s)\n";
}

echo "\n═══ RESUMO ═══\n";
echo "  post_id: {$postId}\n";
echo "  status:  {$payload['status']}\n";
echo "  link:    {$linkPub}\n";
echo "  fidelity: " . ($fidelityWarn ? 'fail' : 'ok') . "\n";
echo "  fontes:  " . count($scraped) . "\n";
exit(0);
