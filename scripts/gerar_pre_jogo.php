<?php
/**
 * gerar_pre_jogo.php — pipeline pré-jogo do EC Vitória com schema BroadcastEvent.
 *
 * Lê 1 jogo do calendário (--game-id) ou aceita mock (--mock-data) e:
 *   1. Claude/Sonnet gera matéria pré-jogo (escalação provável, onde assistir, retrospecto)
 *   2. Injeta Schema.org BroadcastEvent no content
 *   3. Publica WP leaodabarra
 *   4. Notifica Google Indexing API (BroadcastEvent é tipo OFICIAL — indexa rápido)
 *
 * Uso:
 *   php scripts/gerar_pre_jogo.php --game-id=2026-05-15-vit-vsc
 *   php scripts/gerar_pre_jogo.php --mock-data='{"data":"2026-05-15","hora":"18:30","competicao":"Brasileirão Série A","mando":"casa","adversario":{"nome":"Vasco","sigla":"VAS"},"estadio":"Barradão"}'
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
$asDraft = !empty($args['draft']); // status=draft (pra testes/validação)

if ($gameId === '' && $mockJson === '') {
    fwrite(STDERR, "uso: php gerar_pre_jogo.php --game-id=ID  OU  --mock-data='{...}' [--dry-run]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/BroadcastEventBuilder.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Pré-jogo Pipeline (BroadcastEvent) — site={$siteSlug}\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// 1. Carrega dados do jogo
$jogo = null;
if ($mockJson !== '') {
    $jogo = json_decode($mockJson, true);
    if (!is_array($jogo)) {
        fwrite(STDERR, "✗ mock-data inválido (JSON parse error)\n");
        exit(1);
    }
    $jogo['id'] = $jogo['id'] ?? 'mock-' . date('Ymd-His');
    echo "→ Usando MOCK\n";
} else {
    $cal = json_decode((string)file_get_contents(__DIR__ . '/../data/jogos_vitoria.json'), true);
    foreach ($cal['jogos'] ?? [] as $j) {
        if (($j['id'] ?? '') === $gameId) { $jogo = $j; break; }
    }
    if (!$jogo) {
        fwrite(STDERR, "✗ jogo {$gameId} não encontrado no calendário\n");
        exit(1);
    }
    echo "→ Carregado calendário\n";
}

$adv = $jogo['adversario']['nome'] ?? '?';
$comp = $jogo['competicao'] ?? '?';
$mando = $jogo['mando'] ?? '?';
$dataStr = ($jogo['data'] ?? '?') . ' ' . ($jogo['hora'] ?? '?');
echo "  adversário: {$adv}\n";
echo "  competição: {$comp}\n";
echo "  mando: {$mando}\n";
echo "  data: {$dataStr}\n\n";

// 2. Claude gera conteúdo pré-jogo
echo "→ [2/5] Claude gerando conteúdo pré-jogo (Sonnet)\n";
$claude = new Claude($cfg['anthropic_api_key'], $cfg['anthropic_model']);

$mandoTxt = $mando === 'casa' ? "no Barradão (Salvador)" : "fora de casa, no estádio do {$adv}";
$titulo = "Vitória x {$adv}: onde assistir, escalação provável e horário do jogo do " . ($comp ?: 'Brasileirão');

$systemPrompt = <<<EOT
Você é redator do Leão da Barra, voz especializada no Esporte Clube Vitória — jornalismo investigativo, voz baiana, sem fanatismo cego, sem apostas.

Tom obrigatório: rubro-negro identitário com rigor factual. Cita fontes (CBF, Federação Baiana, ge.globo, Lance!).
Estrutura do post pré-jogo:
1. P1 (45-65 palavras): lead 5W (quem joga, quando, onde, competição, transmissão se conhecida)
2. P2-P3: contexto do jogo (momento do Vitória + retrospecto contra adversário)
3. <h2>Onde assistir</h2>: bullet list canais TV/streaming (se transmissão informada)
4. <h2>Escalação provável do Vitória</h2>: 11 jogadores ou parágrafo com prováveis titulares (Lucas Arcanjo no gol, Camutanga zaga, etc.)
5. <h2>Histórico recente</h2>: últimos 5 jogos do Vitória + últimos 3 confrontos diretos
6. <h2>Arbitragem e detalhes</h2>: árbitro provável, VAR, transmissão oficial CBF
7. Fechamento P1 com CTA "acompanhe a partida"

Saída: HTML limpo (sem markdown). Use <p>, <h2>, <ul>, <li>, <strong>. Acentuação portuguesa completa.
EOT;

$userPrompt = "JOGO:\n"
    . "Adversário: {$adv}\n"
    . "Competição: {$comp}\n"
    . "Mando: " . ($mando === 'casa' ? 'Vitória joga em casa (Barradão, Salvador)' : 'Vitória joga fora de casa') . "\n"
    . "Data/hora: {$dataStr} (horário de Brasília)\n"
    . "Estádio: " . ($jogo['estadio'] ?? '-') . "\n"
    . "Transmissão: " . ($jogo['transmissao'] ?? 'A definir') . "\n\n"
    . "Escreva matéria pré-jogo em estilo Leão da Barra. ~600 palavras. Saída só HTML.";

$resp = $claude->callPublic([['role' => 'user', 'content' => $userPrompt]], $systemPrompt, 2500);
$contentHtml = trim((string)($resp['content'][0]['text'] ?? ''));
// Remove markdown code fence se Claude rebarbou ```html ... ```
$contentHtml = preg_replace('/^\s*```(?:html)?\s*\n?/i', '', $contentHtml);
$contentHtml = preg_replace('/\n?\s*```\s*$/i', '', $contentHtml);
$contentHtml = trim($contentHtml);
if ($contentHtml === '') {
    fwrite(STDERR, "✗ Claude retornou vazio\n");
    exit(1);
}
echo "   ✓ " . str_word_count(strip_tags($contentHtml)) . " palavras geradas\n";

// 3. Monta schema BroadcastEvent
echo "→ [3/5] Montando BroadcastEvent schema\n";
$builder = new BroadcastEventBuilder();
try {
    $schema = $builder->montar($jogo, ['post_url' => '']);
    echo "   ✓ schema startDate={$schema['startDate']} endDate={$schema['endDate']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✗ schema falhou: " . $e->getMessage() . "\n");
    exit(1);
}

// 4. Combina + slug
$contentComSchema = $contentHtml . $builder->renderizarScript($schema);
$slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $titulo))), '-');
$slug = substr($slug, 0, 70);

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
    echo "\n[DRY-RUN] Resumo:\n";
    echo "  Título: {$titulo}\n";
    echo "  Slug:   {$slug}\n";
    echo "  Author: " . ($payload['author'] ?? 'default') . "\n";
    echo "  Schema BroadcastEvent + SportsEvent: OK\n";
    echo "\nFirst 600 chars do content+schema:\n" . substr($contentComSchema, 0, 600) . "...\n";
    exit(0);
}

// 5. Publica + indexing
echo "→ [4/5] Publicando WP\n";
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
try {
    $r = $wp->criarPost($payload);
    $postId = (int)($r['id'] ?? 0);
    $linkPub = (string)($r['link'] ?? '');
    if ($postId === 0) throw new RuntimeException('post não criado');
    echo "   ✓ Post #{$postId} publicado: {$linkPub}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✗ falha publicar WP: " . $e->getMessage() . "\n");
    exit(1);
}

echo "→ [5/5] Google Indexing API (BroadcastEvent é tipo OFICIAL)\n";
try {
    $idx = new GoogleIndexingApi(__DIR__ . '/../data/credentials/google-indexing.json');
    $rIdx = $idx->notifyUrl($linkPub, 'URL_UPDATED');
    if ($rIdx['success']) {
        echo "   ✓ HTTP {$rIdx['http_code']} — Google notificado oficialmente\n";
    } else {
        echo "   ⚠ Indexing API erro: {$rIdx['error']}\n";
    }
} catch (Throwable $e) {
    echo "   ⚠ Indexing API falhou: " . $e->getMessage() . "\n";
}

echo "\n═══ RESUMO ═══\n";
echo "  post_id:    {$postId}\n";
echo "  link:       {$linkPub}\n";
echo "  schema:     BroadcastEvent + SportsEvent\n";
echo "  startDate:  {$schema['startDate']}\n";
echo "  endDate:    {$schema['endDate']}\n";
echo "\nValide schema em https://search.google.com/test/rich-results?url=" . urlencode($linkPub) . "\n";
