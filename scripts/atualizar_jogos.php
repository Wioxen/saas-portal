<?php
declare(strict_types=1);

/**
 * scripts/atualizar_jogos.php
 *
 * Atualiza data/jogos_vitoria.json scrapando ge.globo (próximo jogo do clube).
 *
 * Pipeline:
 *   1. Baixa https://ge.globo.com/ba/futebol/times/vitoria/ com user-agent real
 *   2. Extrai JSON embedado: "jogos":[{...}] (Globo CMS injeta inline)
 *   3. Normaliza pra formato interno (id estável, mando, adversário, status)
 *   4. Faz UPSERT no JSON local (id = data + sigla mandante + sigla visitante)
 *   5. Marca jogos passados como 'finalizado' se Globo trouxe placar
 *   6. Atualiza _meta.atualizado_em
 *
 * Modos:
 *   php scripts/atualizar_jogos.php             # scrape + merge
 *   php scripts/atualizar_jogos.php --dry-run   # mostra diff sem gravar
 *   php scripts/atualizar_jogos.php --verbose   # detalhes do que mudou
 *
 * Cron sugerido (EasyPanel/Linux):
 *   # Normal: 1x/dia 08:00 BR (11:00 UTC)
 *   0 11 * * * cd /app && php scripts/atualizar_jogos.php >> logs/atualizar_jogos.log 2>&1
 *
 *   # Aceleração D-1 / dia de jogo: a cada 1h (rodar JogosCalendario::horasAteProximoJogo<=24)
 *   0 * * * * cd /app && php scripts/atualizar_jogos.php --so-se-perto >> logs/atualizar_jogos.log 2>&1
 */

require_once __DIR__ . '/../lib/JogosCalendario.php';

$opts = getopt('', ['dry-run', 'verbose', 'so-se-perto']);
$dryRun  = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);
$soSePerto = isset($opts['so-se-perto']);

$jsonPath = __DIR__ . '/../data/jogos_vitoria.json';

// Modo --so-se-perto: só roda se próximo jogo está nas próximas 24h
if ($soSePerto) {
    $cal = new JogosCalendario($jsonPath);
    $h = $cal->horasAteProximoJogo();
    if ($h === null || $h > 24) {
        echo "[skip] próximo jogo a " . ($h !== null ? round($h, 1) . "h" : 'desconhecido') . " — fora da janela 24h\n";
        exit(0);
    }
    echo "[ok] próximo jogo em " . round($h, 1) . "h — atualizando\n";
}

// 1. Scrape
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$url = 'https://ge.globo.com/ba/futebol/times/vitoria/';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT      => $ua,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$html = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $status !== 200) {
    fwrite(STDERR, "[erro] scrape ge.globo falhou — status={$status}\n");
    exit(2);
}

// 2. Extrai JSON dos jogos
//    Padrão observado: "jogos":[{...,"data_realizacao":"YYYY-MM-DD",...}]
$jogosCrus = extrairJogosDoHtml($html);
if (empty($jogosCrus)) {
    fwrite(STDERR, "[erro] nenhum jogo encontrado no HTML — Globo pode ter mudado layout\n");
    exit(3);
}
echo "[scrape] " . count($jogosCrus) . " jogo(s) extraídos do ge.globo\n";

// 3. Filtra SÓ jogos do Vitória (página main lista outros clubes da Bahia/Nordeste)
$jogosCrus = array_values(array_filter($jogosCrus, function($g) {
    $sigMand = strtoupper((string)($g['equipe_mandante']['sigla']  ?? ''));
    $sigVis  = strtoupper((string)($g['equipe_visitante']['sigla'] ?? ''));
    $nomeMand = (string)($g['equipe_mandante']['nome_popular']  ?? '');
    $nomeVis  = (string)($g['equipe_visitante']['nome_popular'] ?? '');
    return $sigMand === 'VIT' || $sigVis === 'VIT'
        || stripos($nomeMand, 'Vitória') === 0 || stripos($nomeVis, 'Vitória') === 0;
}));
echo "[filtro] " . count($jogosCrus) . " jogo(s) envolvem o Vitória\n";

if (empty($jogosCrus)) {
    fwrite(STDERR, "[erro] após filtro Vitória, zero jogos restantes\n");
    exit(4);
}

// 4. Normaliza
$jogosNorm = array_map('normalizarJogo', $jogosCrus);

// 4. Merge com JSON local
$dadosAtuais = file_exists($jsonPath)
    ? (json_decode((string)file_get_contents($jsonPath), true) ?: [])
    : ['_meta' => [], 'jogos' => []];

$jogosLocal = (array)($dadosAtuais['jogos'] ?? []);
$indiceLocal = [];
foreach ($jogosLocal as $i => $j) {
    if (!empty($j['id'])) $indiceLocal[$j['id']] = $i;
}

$novos = 0; $atualizados = 0; $diffs = [];
foreach ($jogosNorm as $jogoNovo) {
    $id = $jogoNovo['id'];
    if (isset($indiceLocal[$id])) {
        $idx = $indiceLocal[$id];
        $antigo = $jogosLocal[$idx];
        $diff = compararJogos($antigo, $jogoNovo);
        if (!empty($diff)) {
            // Preserva campos manuais que cron não tem (ex: rodada, transmissao manual)
            $jogosLocal[$idx] = array_merge($antigo, $jogoNovo, [
                'rodada'      => $antigo['rodada']      ?? $jogoNovo['rodada'] ?? null,
                'transmissao' => $jogoNovo['transmissao'] ?? $antigo['transmissao'] ?? null,
            ]);
            $atualizados++;
            $diffs[$id] = $diff;
        }
    } else {
        $jogosLocal[] = $jogoNovo;
        $novos++;
        $diffs[$id] = ['NOVO' => $jogoNovo];
    }
}

// 5. Atualiza _meta
$dadosAtuais['_meta'] = array_merge($dadosAtuais['_meta'] ?? [], [
    'atualizado_em'        => date('c'),
    'ultimo_scrape_ge'     => date('c'),
    'ultimo_scrape_status' => 'ok',
    'ultimo_scrape_jogos'  => count($jogosNorm),
]);
$dadosAtuais['jogos'] = $jogosLocal;

// Reporting
echo "[merge] novos={$novos} atualizados={$atualizados} total=" . count($jogosLocal) . "\n";
if ($verbose && !empty($diffs)) {
    foreach ($diffs as $id => $d) {
        echo "  · {$id}: " . json_encode($d, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// 6. Grava
if ($dryRun) {
    echo "[dry-run] sem gravar\n";
    exit(0);
}

if ($novos === 0 && $atualizados === 0) {
    echo "[skip] nada mudou — não regrava\n";
    exit(0);
}

file_put_contents(
    $jsonPath,
    json_encode($dadosAtuais, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);
echo "[ok] gravado em {$jsonPath}\n";
exit(0);

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

/** Extrai o array JSON "jogos":[{...}] do HTML do ge.globo. */
function extrairJogosDoHtml(string $html): array
{
    // Estratégia 1: padrão "jogos":[{...}]  (até o ] de fechamento; aceita 1+ jogos)
    if (preg_match('/"jogos":(\[\{.*?\}\])(?=,"|\})/s', $html, $m)) {
        $arr = json_decode($m[1], true);
        if (is_array($arr) && !empty($arr)) return $arr;
    }
    // Estratégia 2: bloco json maior — match simples de "data_realizacao"
    if (preg_match_all('/\{"data_realizacao":"\d{4}-\d{2}-\d{2}".*?\}(?=,\{|\])/s', $html, $m2)) {
        $jogos = [];
        foreach ($m2[0] as $bloco) {
            $obj = json_decode($bloco, true);
            if (is_array($obj) && !empty($obj['data_realizacao'])) $jogos[] = $obj;
        }
        if (!empty($jogos)) return $jogos;
    }
    return [];
}

/** Converte schema do ge.globo pro schema interno do JSON. */
function normalizarJogo(array $g): array
{
    $mandante  = (string)($g['equipe_mandante']['nome_popular']  ?? '');
    $visitante = (string)($g['equipe_visitante']['nome_popular'] ?? '');
    $sigMand   = (string)($g['equipe_mandante']['sigla']  ?? substr($mandante, 0, 3));
    $sigVis    = (string)($g['equipe_visitante']['sigla'] ?? substr($visitante, 0, 3));
    $data      = (string)($g['data_realizacao'] ?? '');
    $hora      = (string)($g['hora_realizacao'] ?? '21:30');

    $isVitoriaMandante = strcasecmp($sigMand, 'VIT') === 0 || stripos($mandante, 'Vitória') !== false;
    $mando = $isVitoriaMandante ? 'casa' : 'fora';
    $advNome = $isVitoriaMandante ? $visitante : $mandante;
    $advSig  = $isVitoriaMandante ? $sigVis : $sigMand;
    $advEscudoUrl = $isVitoriaMandante
        ? ($g['equipe_visitante']['escudos']['30x30'] ?? '')
        : ($g['equipe_mandante']['escudos']['30x30'] ?? '');

    $jaComecou = !empty($g['jogo_ja_comecou']);
    $placarMand = $g['placar_oficial_mandante'] ?? null;
    $placarVis  = $g['placar_oficial_visitante'] ?? null;
    $temPlacar = $placarMand !== null && $placarVis !== null;

    $status = 'agendado';
    if ($temPlacar) $status = 'finalizado';
    elseif ($jaComecou) $status = 'live';

    return [
        'id'         => sprintf('%s-%s-%s', $data, strtolower($sigMand), strtolower($sigVis)),
        'data'       => $data,
        'hora'       => $hora,
        'timezone'   => 'America/Sao_Paulo',
        'competicao' => null,  // ge.globo main page não traz; preencher manual ou via outra fonte
        'rodada'     => null,
        'mando'      => $mando,
        'adversario' => [
            'nome'   => $advNome,
            'sigla'  => $advSig,
            'escudo' => $advEscudoUrl,
        ],
        'estadio'    => (string)($g['sede']['nome_popular'] ?? ''),
        'transmissao' => $g['transmissao'] ?? null,
        'status'     => $status,
        'placar'     => [
            'vitoria'    => $isVitoriaMandante ? $placarMand : $placarVis,
            'adversario' => $isVitoriaMandante ? $placarVis  : $placarMand,
        ],
        'fonte'      => 'ge.globo',
        'fonte_url'  => 'https://ge.globo.com/ba/futebol/times/vitoria/',
        'scraped_at' => date('c'),
    ];
}

/** Diff campo-a-campo entre 2 jogos (mesmo id). Retorna [] se idênticos. */
function compararJogos(array $a, array $b): array
{
    $diff = [];
    $campos = ['data', 'hora', 'estadio', 'status', 'placar'];
    foreach ($campos as $c) {
        $va = $a[$c] ?? null; $vb = $b[$c] ?? null;
        if (json_encode($va) !== json_encode($vb)) {
            $diff[$c] = ['de' => $va, 'para' => $vb];
        }
    }
    return $diff;
}
