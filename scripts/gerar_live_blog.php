<?php
declare(strict_types=1);

/**
 * gerar_live_blog.php — Live blog AO VIVO de jogos do Vitória com api-futebol.
 *
 * Arquitetura:
 *   1. State persistido em /app/data/live_blog_state/{game-id}.json
 *   2. Polling /partidas/{id} (cache 60s no ApiFutebol)
 *   3. Diff de eventos (gols/cartões/subs) vs snapshot anterior
 *   4. Cada evento novo vira entry no Schema.org LiveBlogPosting
 *   5. Post dedicado "vitoria-x-X-ao-vivo" com cluster cross-link
 *
 * Fases (relativas ao kickoff):
 *   pre-live   T-15min..T       → cria post com lineup oficial (sem entries ainda)
 *   live       T..T+130min      → polling + entries por evento
 *   post-live  T+130min..T+180m → fecha LiveBlog (coverageEndTime)
 *   off        fora dessas janelas → exit silencioso
 *
 * Uso:
 *   php scripts/gerar_live_blog.php --game-id=2026-05-17-vit-bgg --api-partida-id=27799
 *   php scripts/gerar_live_blog.php --game-id=2026-05-09-vit-flu --api-partida-id=27785 --simular-live
 *   php scripts/gerar_live_blog.php --game-id=... --dry-run
 *
 * Cron sugerido (ativar só em D-0 do jogo, manualmente ou via wrapper):
 *   * * * * * php /app/scripts/gerar_live_blog.php --game-id=... --api-partida-id=...
 *
 * --simular-live: usa partida finalizada como se eventos chegassem agora
 *   (1º run pega lineups + 0 eventos; runs seguintes liberam +1 evento por chamada).
 *   Cria/atualiza file de estado simulado: data/live_blog_state/sim-{game-id}.json
 */

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$gameId = (string)($args['game-id'] ?? '');
$apiPartidaId = (int)($args['api-partida-id'] ?? 0);
$dryRun = !empty($args['dry-run']);
$siteSlug = (string)($args['site'] ?? 'leaodabarra');
$simularLive = !empty($args['simular-live']);
$verbose = !empty($args['verbose']);

if ($gameId === '' || $apiPartidaId === 0) {
    fwrite(STDERR, "uso: --game-id=Y-M-D-X --api-partida-id=N [--simular-live] [--dry-run]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/GoogleIndexingApi.php';
require_once __DIR__ . '/../lib/JogosCalendario.php';
require_once __DIR__ . '/../lib/JogoClusterLinker.php';
require_once __DIR__ . '/../lib/CategoryMatcher.php';
require_once __DIR__ . '/../lib/ApiFutebol.php';
require_once __DIR__ . '/../lib/SportsHighlightsExtractor.php';
require_once __DIR__ . '/../lib/Serper.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$apiKey = (string)($cfg['api_futebol_key'] ?? '');
if ($apiKey === '') { fwrite(STDERR, "✗ api_futebol_key não configurada\n"); exit(2); }

$cal = new JogosCalendario(__DIR__ . '/../data/jogos_vitoria.json');
$jogo = null;
foreach ($cal->jogos() as $j) {
    if (($j['id'] ?? '') === $gameId) { $jogo = $j; break; }
}
if (!$jogo) { fwrite(STDERR, "✗ jogo {$gameId} não encontrado\n"); exit(1); }

date_default_timezone_set('America/Sao_Paulo');
$now = new DateTime('now');
$kickoff = new DateTime(($jogo['data'] ?? '') . ' ' . ($jogo['hora'] ?? '21:30') . ':00', new DateTimeZone('America/Sao_Paulo'));
$preLive = (clone $kickoff)->modify('-15 minutes');
$endLive = (clone $kickoff)->modify('+130 minutes');
$endPost = (clone $kickoff)->modify('+180 minutes');

$fase = 'off';
if ($simularLive) $fase = 'live'; // sempre rola em sim
elseif ($now >= $preLive && $now < $kickoff) $fase = 'pre-live';
elseif ($now >= $kickoff && $now < $endLive) $fase = 'live';
elseif ($now >= $endLive && $now < $endPost) $fase = 'post-live';

// Override automático: se API diz "finalizado" e estamos em live/post-live,
// força transição pra encerrado (cobre jogos que acabam antes ou depois do tempo padrão)
$forcaEncerrado = false;

echo "[live-blog] {$gameId} fase={$fase} now=" . $now->format('H:i:s') . " kickoff=" . $kickoff->format('H:i:s') . "\n";

// State file (lê ANTES do exit pra detectar fase=off mas com state preexistente)
$stateDir = __DIR__ . '/../data/live_blog_state';
if (!is_dir($stateDir)) @mkdir($stateDir, 0775, true);
$statePath = $stateDir . '/' . ($simularLive ? 'sim-' : '') . $gameId . '.json';
$state = is_readable($statePath) ? (json_decode((string)file_get_contents($statePath), true) ?: []) : [];

// fase=off só sai se NÃO há post anterior (rodada nova fora de janela)
if ($fase === 'off' && empty($state['post_id'])) { echo "  ⊘ fora de janela live\n"; exit(0); }
if ($fase === 'off' && !empty($state['post_id'])) {
    echo "  [continua] fase=off mas state existe (jogo passado) — força post-live\n";
    $fase = 'post-live';
}

// Busca partida
$api = new ApiFutebol($apiKey);
try {
    $partida = $api->getPartida($apiPartidaId, $simularLive ? 0 : 60);
} catch (Throwable $e) {
    fwrite(STDERR, "✗ api-futebol: {$e->getMessage()}\n"); exit(3);
}

$home = $partida['time_mandante']['nome_popular'] ?? '?';
$away = $partida['time_visitante']['nome_popular'] ?? '?';
$pH = (int)($partida['placar_mandante'] ?? 0);
$pV = (int)($partida['placar_visitante'] ?? 0);
$estadio = $partida['estadio']['nome_popular'] ?? '';
$competicao = $partida['campeonato']['nome_popular'] ?? '';
$rodada = $partida['rodada'] ?? '';
$statusApi = $partida['status'] ?? '';

// Auto-detect ENCERRADO: API diz finalizado → muda fase
// Cobre também caso D+N (jogo já terminou faz dias mas live_blog está sendo gerado)
if (!$simularLive && $statusApi === 'finalizado') {
    // Só re-processa post-live se já temos post (state)
    if (in_array($fase, ['live', 'post-live'], true) || !empty($state['post_id'])) {
        $fase = 'post-live';
        $forcaEncerrado = true;
        echo "  [auto] API status=finalizado → fase=post-live\n";
    }
}

// Extrai TODOS os eventos numa lista flat ordenada cronologicamente
$eventos = extrairEventosFlat($partida, $home, $away);

// Em modo simulação: revela 1 evento por chamada (testa o pipeline)
if ($simularLive) {
    $revealCount = (int)($state['_sim_reveal_count'] ?? 0) + 1;
    $eventos = array_slice($eventos, 0, $revealCount);
    $state['_sim_reveal_count'] = $revealCount;
    echo "  [sim] revelando {$revealCount}/" . count(extrairEventosFlat($partida, $home, $away)) . " eventos\n";
}

// Eventos NOVOS vs state anterior
$eventosVistos = $state['eventos_hashes'] ?? [];
$novos = [];
foreach ($eventos as $ev) {
    $h = md5($ev['tipo'] . '|' . $ev['minuto'] . '|' . ($ev['atleta'] ?? '') . '|' . ($ev['lado'] ?? ''));
    if (!in_array($h, $eventosVistos, true)) {
        $ev['_hash'] = $h;
        $novos[] = $ev;
        $eventosVistos[] = $h;
    }
}
echo "  eventos totais: " . count($eventos) . " | novos nesta passagem: " . count($novos) . "\n";

// Monta HTML do post: bloco AO VIVO no topo, entries cronológico reverso, dados oficiais embaixo
$adv = ($jogo['mando'] === 'casa') ? $away : $home;
$tituloPost = "AO VIVO: " . ($jogo['mando'] === 'casa' ? "Vitória x {$adv}" : "{$adv} x Vitória")
    . " — {$competicao}" . ($rodada ? " ({$rodada})" : '');

if ($verbose) echo "  título: {$tituloPost}\n";

$placarAtual = ($jogo['mando'] === 'casa') ? "Vitória {$pH} x {$pV} {$adv}" : "{$adv} {$pH} x {$pV} Vitória";

// Bloco placar destacado
$statusLabel = match ($fase) {
    'pre-live'  => '⏳ PRÉ-JOGO',
    'live'      => '🔴 AO VIVO',
    'post-live' => '✅ ENCERRADO',
    default     => '',
};
// Override mais explícito quando API confirma finalizado
if ($forcaEncerrado) $statusLabel = '✅ JOGO ENCERRADO';
$blocoPlacar = "<div class='live-blog-placar' style='background:#000;color:#fff;padding:20px;border-radius:8px;text-align:center;margin:20px 0;'>"
    . "<div style='font-size:13px;letter-spacing:2px;'>{$statusLabel}</div>"
    . "<div style='font-size:32px;font-weight:bold;margin-top:8px;'>{$placarAtual}</div>"
    . "<div style='font-size:14px;margin-top:6px;opacity:0.8;'>{$competicao}" . ($rodada ? " · {$rodada}" : '') . " · " . $estadio . "</div>"
    . "</div>\n";

// Bloco de entries (cronológico reverso)
$entriesHtml = '';
$entriesSchema = [];
foreach (array_reverse($eventos) as $ev) {
    $body = formatarEntryBody($ev, $home, $away);
    $iconLabel = formatarEntryHeadline($ev);
    $entriesHtml .= "<div class='live-blog-entry' style='border-left:4px solid #c00;padding:12px 16px;margin:12px 0;background:#fafafa;'>"
        . "<div style='font-weight:bold;color:#c00;font-size:13px;'>{$ev['minuto']} ({$ev['periodo']})</div>"
        . "<div style='margin-top:4px;'>{$iconLabel}</div>"
        . "<div style='color:#444;font-size:14px;margin-top:4px;'>{$body}</div>"
        . "</div>\n";
    $entriesSchema[] = [
        '@type' => 'BlogPosting',
        'headline' => strip_tags($iconLabel),
        'articleBody' => strip_tags($body),
        'datePublished' => $now->format('c'),
    ];
}

if ($fase === 'pre-live' && empty($eventos)) {
    $entriesHtml = "<p>O jogo começa em breve. Esta página será atualizada com gols, cartões e substituições em tempo real.</p>\n";
}

// Bloco "Onde assistir" — essencial pra post AO VIVO
// Fonte 1: JSON ($jogo['transmissao']), Fonte 2: Serper busca, Fallback: "A confirmar"
$transmissaoNomes = [];
$transJson = $jogo['transmissao'] ?? null;
if (is_string($transJson) && $transJson !== '') {
    $transmissaoNomes[] = $transJson;
} elseif (is_array($transJson) && !empty($transJson)) {
    $transmissaoNomes = array_filter(array_map('strval', $transJson));
}
// Se vazio, busca via Serper
if (empty($transmissaoNomes) && !empty($cfg['serper_api_key'])) {
    try {
        $sr = new Serper($cfg['serper_api_key']);
        $q = ($jogo['mando'] === 'casa' ? "Vitória x {$adv}" : "{$adv} x Vitória") . " {$competicao} onde assistir transmissão";
        $resp = $sr->search($q, 6);
        $snippets = '';
        foreach (($resp['organic'] ?? []) as $o) $snippets .= ' ' . (string)($o['snippet'] ?? '');
        // Regex pra canais conhecidos
        $canais = ['Premiere', 'SporTV', 'Globoplay', 'Amazon Prime', 'Amazon Prime Video', 'Cazé TV', 'Cazé', 'YouTube', 'Disney+', 'ESPN', 'Globo', 'Band', 'Record'];
        foreach ($canais as $c) {
            if (mb_stripos($snippets, $c) !== false) $transmissaoNomes[] = $c;
        }
        $transmissaoNomes = array_values(array_unique($transmissaoNomes));
        if (!empty($transmissaoNomes)) echo "  ✓ Onde assistir (Serper): " . implode(', ', $transmissaoNomes) . "\n";
    } catch (Throwable $e) { /* skip */ }
}
$txtTransmissao = !empty($transmissaoNomes)
    ? "O jogo entre " . ($jogo['mando'] === 'casa' ? "Vitória x {$adv}" : "{$adv} x Vitória") . " tem transmissão ao vivo por <strong>" . implode(', ', $transmissaoNomes) . "</strong>."
    : "A transmissão da partida ainda está a confirmar.";

$blocoOndeAssistir = "\n<h2>Onde assistir " . ($jogo['mando'] === 'casa' ? "Vitória x {$adv}" : "{$adv} x Vitória") . " ao vivo</h2>\n"
    . "<p>{$txtTransmissao} Início: " . $kickoff->format('d/m/Y \à\s H:i') . " (horário de Brasília), no {$estadio}.</p>\n";

// MM YouTube quando fase é post-live (jogo terminou): injeta vídeo no topo
$blocoMM = '';
if ($fase === 'post-live') {
    try {
        $hl = SportsHighlightsExtractor::buscar($home, $pH, $away, $pV, $competicao, null, (string)($cfg['serper_api_key'] ?? ''));
        if ($hl && !empty($hl['embed_html'])) {
            $blocoMM = "\n<h2>Assista aos gols e melhores momentos</h2>\n"
                . "<p>Veja o vídeo dos lances do empate entre {$home} e {$away}:</p>\n"
                . $hl['embed_html'] . "\n";
            echo "  ✓ MM video: {$hl['titulo']}\n";
        }
    } catch (Throwable $e) { echo "  ⚠ MM video: {$e->getMessage()}\n"; }
}

// Bloco escalações (se disponível)
$blocoEscalacao = '';
$escM = $partida['escalacoes']['mandante'] ?? null;
$escV = $partida['escalacoes']['visitante'] ?? null;
if ($escM || $escV) {
    $blocoEscalacao .= "<h2>Escalações confirmadas</h2>\n";
    foreach ([[$home, $escM], [$away, $escV]] as [$nome, $esc]) {
        if (!$esc) continue;
        $tatico = $esc['esquema_tatico'] ?? '';
        $tec = $esc['tecnico']['nome_popular'] ?? '';
        $titulares = array_map(fn($j) => $j['atleta']['nome_popular'] ?? '?', $esc['titulares'] ?? []);
        $blocoEscalacao .= "<p><strong>{$nome}</strong>" . ($tatico ? " ({$tatico})" : '') . ": " . implode(', ', $titulares) . ($tec ? " · Técnico: {$tec}" : '') . "</p>\n";
    }
}

// Schema LiveBlogPosting
$schemaLB = [
    '@context' => 'https://schema.org',
    '@type' => 'LiveBlogPosting',
    'headline' => $tituloPost,
    'coverageStartTime' => $kickoff->format('c'),
    'coverageEndTime' => $endLive->format('c'),
    'inLanguage' => 'pt-BR',
    'about' => [
        '@type' => 'SportsEvent',
        'name' => "{$home} x {$away}",
        'startDate' => $kickoff->format('c'),
        'location' => ['@type' => 'StadiumOrArena', 'name' => $estadio],
        'homeTeam' => ['@type' => 'SportsTeam', 'name' => $home],
        'awayTeam' => ['@type' => 'SportsTeam', 'name' => $away],
    ],
];
if (!empty($entriesSchema)) $schemaLB['liveBlogUpdate'] = $entriesSchema;

$schemaScript = "<script type=\"application/ld+json\" data-live-blog=\"1\">\n"
    . json_encode($schemaLB, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    . "\n</script>\n";

// Cluster cross-link com posts irmãos (pré-jogo / pós-jogo) — busca posts_gerados do JSON
$blocoCluster = '';
$irmaos = [];
foreach (['pre_jogo' => 'Pré-jogo', 'pos_jogo' => 'Pós-jogo'] as $tipo => $label) {
    $pid = (int)($jogo['posts_gerados'][$tipo] ?? 0);
    if ($pid > 0) $irmaos[] = ['id' => $pid, 'label' => $label];
}
if (!empty($irmaos)) {
    $blocoCluster = "\n<h2>Mais sobre " . ($jogo['mando'] === 'casa' ? "Vitória x {$adv}" : "{$adv} x Vitória") . "</h2>\n<ul>\n";
    // Resolve URLs dos irmãos (rápido — getPost cada)
    $wpTmp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
    foreach ($irmaos as $i) {
        try {
            $pIrmao = $wpTmp->getPost($i['id']);
            $titIr = htmlspecialchars(html_entity_decode((string)($pIrmao['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $linkIr = htmlspecialchars((string)($pIrmao['link'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $blocoCluster .= "  <li><strong>{$i['label']}:</strong> <a href='{$linkIr}'>{$titIr}</a></li>\n";
        } catch (Throwable $e) {}
    }
    $blocoCluster .= "</ul>\n";
}

$htmlPost = $blocoPlacar
    . $blocoOndeAssistir
    . $blocoMM
    . "<h2>Acompanhe lance a lance</h2>\n"
    . $entriesHtml
    . $blocoEscalacao
    . $blocoCluster
    . $schemaScript;

if ($dryRun) {
    echo "\n--- DRY-RUN preview (1500 chars) ---\n" . substr($htmlPost, 0, 1500) . "...\n";
    exit(0);
}

// Cria ou atualiza post
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$postId = (int)($state['post_id'] ?? 0);
$linkPub = '';

if ($postId === 0) {
    // Primeira passagem: cria post
    $slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $tituloPost))), '-');
    $slug = substr($slug, 0, 70);
    $payload = [
        'title' => $tituloPost,
        'slug' => $slug,
        'content' => $htmlPost,
        'status' => 'publish',
        'meta' => [
            'rank_math_title' => "{$tituloPost} | Leão da Barra",
            'rank_math_description' => "Acompanhe AO VIVO {$home} x {$away} pelo {$competicao} com lance a lance, escalações e melhores momentos.",
            'rank_math_focus_keyword' => mb_strtolower("{$home} x {$away} ao vivo"),
        ],
    ];
    if (!empty($cfg['default_post_author_id'])) $payload['author'] = (int)$cfg['default_post_author_id'];

    // Categorias
    try {
        $cm = new CategoryMatcher($wp, 70.0);
        $catsPropostas = ['Esporte Clube Vitória', 'Ao Vivo'];
        if (mb_stripos($competicao, 'Brasileir') !== false) $catsPropostas[] = 'Brasileirão';
        if (mb_stripos($competicao, 'Copa do Brasil') !== false) $catsPropostas[] = 'Copa do Brasil';
        if (mb_stripos($competicao, 'Copa do Nordeste') !== false) $catsPropostas[] = 'Copa do Nordeste';
        $catsPropostas[] = $adv;
        $res = $cm->resolverComMatch($catsPropostas);
        $catIds = array_values(array_filter(array_map('intval', $res)));
        if (!empty($catIds)) $payload['categories'] = $catIds;
    } catch (Throwable $e) {}

    try {
        $r = $wp->criarPost($payload);
        $postId = (int)($r['id'] ?? 0);
        $linkPub = (string)($r['link'] ?? '');
        echo "  ✓ Post #{$postId} criado: {$linkPub}\n";
        $cal->registrarPostGerado($gameId, 'live_blog', $postId);
    } catch (Throwable $e) {
        fwrite(STDERR, "✗ criar post: {$e->getMessage()}\n"); exit(5);
    }
} else {
    // Update do existente
    try {
        $r = $wp->atualizarPost($postId, ['content' => $htmlPost]);
        $linkPub = (string)($r['link'] ?? '');
        echo "  ✓ Post #{$postId} atualizado ({$competicao})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "✗ update post: {$e->getMessage()}\n"); exit(5);
    }
}

// Indexing API ping só se houver evento novo (ou primeira passagem)
$pingAgora = !empty($novos) || empty($state['post_id']);
if ($pingAgora && $linkPub) {
    try {
        $idx = new GoogleIndexingApi('/app/data/credentials/google-indexing.json');
        $ri = $idx->notifyUrl($linkPub, 'URL_UPDATED');
        echo "  ✓ Indexing API HTTP " . ($ri['http_code'] ?? '?') . "\n";
    } catch (Throwable $e) {}
}

// Persiste state
$state['post_id'] = $postId;
$state['eventos_hashes'] = $eventosVistos;
$state['last_update'] = $now->format('c');
$state['placar'] = "{$pH}x{$pV}";
$state['fase'] = $fase;
file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

echo "[done] fase={$fase} eventos=" . count($eventos) . " novos=" . count($novos) . " post_id={$postId}\n";
exit(0);


// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function extrairEventosFlat(array $partida, string $home, string $away): array
{
    $out = [];
    // Gols
    foreach (['mandante' => $home, 'visitante' => $away] as $key => $time) {
        foreach (($partida['gols'][$key] ?? []) as $g) {
            $out[] = [
                'tipo'    => $g['penalti'] ?? false ? 'gol_penalti' : (($g['gol_contra'] ?? false) ? 'gol_contra' : 'gol'),
                'minuto'  => $g['minuto'] ?? '?',
                'periodo' => $g['periodo'] ?? '',
                'atleta'  => $g['atleta']['nome_popular'] ?? '?',
                'lado'    => $time,
                'minuto_ord' => parseMinuto($g['periodo'] ?? '', $g['minuto'] ?? '0'),
            ];
        }
    }
    // Cartões
    foreach (['amarelo', 'vermelho'] as $tipo) {
        foreach (['mandante' => $home, 'visitante' => $away] as $key => $time) {
            foreach (($partida['cartoes'][$tipo][$key] ?? []) as $c) {
                $out[] = [
                    'tipo'    => "cartao_{$tipo}",
                    'minuto'  => $c['minuto'] ?? '?',
                    'periodo' => $c['periodo'] ?? '',
                    'atleta'  => $c['atleta']['nome_popular'] ?? '?',
                    'lado'    => $time,
                    'minuto_ord' => parseMinuto($c['periodo'] ?? '', $c['minuto'] ?? '0'),
                ];
            }
        }
    }
    // Substituições
    foreach (['mandante' => $home, 'visitante' => $away] as $key => $time) {
        foreach (($partida['substituicoes'][$key] ?? []) as $s) {
            $out[] = [
                'tipo'    => 'substituicao',
                'minuto'  => $s['minuto'] ?? '?',
                'periodo' => $s['periodo'] ?? '',
                'atleta'  => ($s['entrou']['nome_popular'] ?? '?') . ' (saiu ' . ($s['saiu']['nome_popular'] ?? '?') . ')',
                'lado'    => $time,
                'minuto_ord' => parseMinuto($s['periodo'] ?? '', $s['minuto'] ?? '0'),
            ];
        }
    }
    // Ordena cronologicamente
    usort($out, fn($a, $b) => $a['minuto_ord'] <=> $b['minuto_ord']);
    return $out;
}

function parseMinuto(string $periodo, string $minuto): int
{
    $base = (mb_stripos($periodo, '2') !== false) ? 4500 : 0; // 2º tempo começa em 45min
    if (preg_match('/^(\d+):(\d+)/', $minuto, $m)) return $base + ((int)$m[1] * 60) + (int)$m[2];
    return $base + (int)$minuto * 60;
}

function formatarEntryHeadline(array $ev): string
{
    return match ($ev['tipo']) {
        'gol'          => "⚽ <strong>GOOOOL!</strong> de {$ev['atleta']} para o <strong>{$ev['lado']}</strong>",
        'gol_penalti'  => "⚽ <strong>GOL DE PÊNALTI!</strong> {$ev['atleta']} marca para o <strong>{$ev['lado']}</strong>",
        'gol_contra'   => "⚽ <strong>GOL CONTRA</strong> de {$ev['atleta']} — beneficia o <strong>{$ev['lado']}</strong>",
        'cartao_amarelo' => "🟨 Cartão amarelo para {$ev['atleta']} ({$ev['lado']})",
        'cartao_vermelho' => "🟥 <strong>EXPULSO!</strong> {$ev['atleta']} ({$ev['lado']})",
        'substituicao' => "🔄 Substituição no {$ev['lado']}: entra {$ev['atleta']}",
        default        => "Evento: {$ev['atleta']} ({$ev['lado']})",
    };
}

function formatarEntryBody(array $ev, string $home, string $away): string
{
    return '';
}
