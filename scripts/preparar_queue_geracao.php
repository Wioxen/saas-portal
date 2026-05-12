<?php
declare(strict_types=1);

/**
 * preparar_queue_geracao.php — prepara fila de geração SEM gastar LLM.
 *
 * Substitui tick_filas_v2_* quando billing LLM está zerado. Processo:
 *   1. Para cada site, pega top N trends novo/aprovado score≥threshold 24h
 *   2. Filtro de nicho (keywords por site) + dedup Jaccard com publicados
 *   3. Serper /search + Scraper pra trazer conteúdo das fontes
 *   4. Salva 1 arquivo JSON por trend em data/queue_gerar/{slug}/{trend_id}.json
 *      contendo: titulo, fontes scraped (texto pronto), og_image, contexto
 *   5. Quando user abrir Claude Code, executa /gerar-pendentes
 *
 * Custo: Serper (~R$0,003/query) + bandwidth scrape. Zero LLM.
 *
 * Cron sugerido: a cada 2 horas (queue cresce ao longo do dia).
 *
 * Flags:
 *   --site=SLUG (default: todos os 5 configurados)
 *   --max-por-site=N (default 4)
 *   --dry-run
 *   --verbose
 */

date_default_timezone_set('America/Sao_Paulo');

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteFiltro = (string)($args['site'] ?? '');
$maxPorSite = (int)($args['max-por-site'] ?? 4);
$dryRun = !empty($args['dry-run']);
$verbose = !empty($args['verbose']);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/DbConnection.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/Serper.php';
require_once __DIR__ . '/../lib/Scraper.php';

$cfgGlobal = require __DIR__ . '/../config.php';
$pdo = DbConnection::pdo();

// Configuração por site (origens + threshold + filtro nicho)
$SITES = [
    'leaodabarra' => [
        'origens' => ['pingo:48','pingo:51','pingo:55','pingo:56','pingo:57','pingo:58','pingo:59','pingo:60'],
        'score_min' => 5.5,
        'filtro_nicho' => null, // sem filtro pra leaodabarra (já é segmentado por fonte)
    ],
    'cursosenac' => [
        'origens' => ['pingo:6','pingo:13','pingo:19','pingo:34','pingo:42'],
        'score_min' => 5.0,
        'filtro_nicho' => '/\b(mec|capes|cnpq|enem|fies|prouni|mestrado|doutorado|pos-?gradua|vestibular|bolsa|gratui|inscri|professor|docente|curso|universidade|federal|instituto|ifb|ifac|ifsp|unifesp|ufrj|ufpb|cne|ufsc|ufmg|usp|unicamp|inep|ead|forma[cç][aã]o|edital|sele[cç][aã]o)/iu',
    ],
    'comocomprar' => [
        'origens' => ['pingo:22','pingo:29','pingo:35','pingo:38','pingo:61','pingo:62','pingo:63'],
        'score_min' => 5.5,
        'filtro_nicho' => '/\b(comprar|oferta|desconto|barato|melhor|pre[çc]o|amazon|magazine|americanas|mercadolivre|shopee|aliexpress|review|comparativo|frete|kit|combo|lan[çc]amento|liquida|black\s+friday|cupom|promo|wi-?fi|smartphone|notebook|tv|fog[aã]o|geladeira|c[aâ]mera|fone|smart)/iu',
    ],
    'ondecompraragora' => [
        'origens' => ['pingo:30','pingo:41','pingo:64'],
        'score_min' => 5.5,
        'filtro_nicho' => '/\b(onde\s+comprar|loja|pre[çc]o|amazon|magazine|americanas|mercadolivre|shopee|aliexpress|oferta|desconto|cupom|frete|liquida|melhor\s+pre[çc]o|comparativo|review|recomenda|economizar|barato|pacote|viagem|passagem)/iu',
    ],
    'vagasebeneficios' => [
        'origens' => ['pingo:14','pingo:15','pingo:17','pingo:20','pingo:21','pingo:25','pingo:27','pingo:31','pingo:40','pingo:43','pingo:49','pingo:50','pingo:52','pingo:53'],
        'score_min' => 5.5,
        'filtro_nicho' => '/\b(vaga|emprego|sal[aá]rio|inss|bolsa\s+fam[ií]lia|aux[ií]lio|benef[ií]cio|concurso|trabalho|carteira\s+assinada|aposentadoria|pis|fgts|seguro\s+desemprego|13\s*[ªo]?\s*sal)/iu',
    ],
];

if ($siteFiltro) {
    if (!isset($SITES[$siteFiltro])) { fwrite(STDERR, "✗ site '{$siteFiltro}' não configurado\n"); exit(2); }
    $SITES = [$siteFiltro => $SITES[$siteFiltro]];
}

$queueDir = __DIR__ . '/../data/queue_gerar';
if (!is_dir($queueDir)) @mkdir($queueDir, 0775, true);

echo "[queue-prep] " . date('H:i:s d/m') . " — sites: " . implode(',', array_keys($SITES)) . "\n\n";

function tokenizar(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\w\sáéíóúâêôãõç]/u', ' ', $s) ?? $s;
    $tokens = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = ['o','a','os','as','de','do','da','dos','das','e','em','para','por','com','sobre','que','na','no','nos','nas','um','uma','ao','aos','é'];
    return array_values(array_diff($tokens, $stop));
}
function jaccard(array $a, array $b): float {
    if (empty($a) || empty($b)) return 0.0;
    $i = count(array_intersect(array_unique($a), array_unique($b)));
    $u = count(array_unique(array_merge($a, $b)));
    return $u > 0 ? $i / $u : 0.0;
}

$totalPrep = 0;
foreach ($SITES as $slug => $cfg) {
    echo "═══ {$slug} ═══\n";
    $siteDir = $queueDir . '/' . $slug;
    if (!is_dir($siteDir)) @mkdir($siteDir, 0775, true);

    // Já em queue (skip)
    $jaFila = [];
    foreach (glob($siteDir . '/*.json') ?: [] as $f) {
        $jaFila[] = (int)basename($f, '.json');
    }
    echo "  ja na fila: " . count($jaFila) . "\n";

    $cfgSite = $cfgGlobal;
    aplicarSite($cfgSite, sitesDisponiveis(), $slug);
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);

    // Trends candidatas
    $origens = $cfg['origens'];
    $qm = implode(',', array_fill(0, count($origens), '?'));
    $sql = "SELECT id, titulo, pingo_link, score_discover, data_detectada
            FROM trends
            WHERE origem IN ({$qm})
              AND status IN ('aprovado', 'novo')
              AND score_discover >= ?
              AND data_detectada >= NOW() - INTERVAL 24 HOUR
            ORDER BY score_discover DESC, data_detectada DESC
            LIMIT 25";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($origens, [$cfg['score_min']]));
    $candidatas = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "  candidatas 24h: " . count($candidatas) . "\n";

    // Posts publicados 3d pra dedup
    $publicados = [];
    try {
        $ps = $wp->listarPosts(1, 30);
        foreach ($ps as $p) {
            $dataP = strtotime($p['date'] ?? '') ?: 0;
            if ($dataP >= time() - 3 * 86400) {
                $publicados[] = html_entity_decode((string)($p['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    } catch (Throwable $e) {}

    $sr = new Serper((string)($cfgSite['serper_api_key'] ?? ''));
    $scraper = new Scraper($cfgSite['user_agent'] ?? 'Mozilla/5.0', 15);

    $prepThisSite = 0;
    foreach ($candidatas as $cand) {
        if ($prepThisSite >= $maxPorSite) break;
        $tid = (int)$cand['id'];
        if (in_array($tid, $jaFila, true)) continue;

        // Filtro nicho
        if ($cfg['filtro_nicho'] && !preg_match($cfg['filtro_nicho'], $cand['titulo'])) {
            if ($verbose) echo "  ⊘ #{$tid} off-topic: " . mb_substr($cand['titulo'], 0, 60) . "\n";
            $pdo->prepare("UPDATE trends SET status='descartado_off_topic' WHERE id=?")->execute([$tid]);
            continue;
        }

        // Dedup Jaccard
        $tok = tokenizar($cand['titulo']);
        $maxJ = 0.0;
        foreach ($publicados as $tp) {
            $j = jaccard($tok, tokenizar($tp));
            if ($j > $maxJ) $maxJ = $j;
        }
        if ($maxJ >= 0.7) {
            if ($verbose) echo "  ⊘ #{$tid} dedup " . number_format($maxJ, 2) . "\n";
            $pdo->prepare("UPDATE trends SET status='descartado_canibal' WHERE id=?")->execute([$tid]);
            continue;
        }

        // Scrape: URL primária + 3 do Serper
        $urls = [$cand['pingo_link']];
        try {
            $q = preg_replace('/:?\s*o que saber agora.*$/iu', '', $cand['titulo']);
            $resp = $sr->search($q, 4);
            foreach (($resp['organic'] ?? []) as $o) $urls[] = $o['link'] ?? '';
        } catch (Throwable $e) {}
        $urls = array_values(array_unique(array_filter($urls)));

        $fontes = [];
        foreach (array_slice($urls, 0, 5) as $u) {
            try {
                $sc = $scraper->fetch($u);
                $paras = $sc['content']['paragraphs'] ?? [];
                $texto = trim(implode("\n", array_slice($paras, 0, 12)));
                if (mb_strlen($texto) < 250) continue;
                $fontes[] = [
                    'url' => $u,
                    'titulo' => $sc['meta']['title'] ?? '',
                    'pub' => $sc['meta']['published'] ?? '',
                    'og' => $sc['meta']['og_image'] ?? '',
                    'texto' => mb_substr($texto, 0, 3000),
                ];
                if (count($fontes) >= 3) break;
            } catch (Throwable $e) {}
        }

        if (count($fontes) < 2) {
            if ($verbose) echo "  ⊘ #{$tid} poucas fontes (" . count($fontes) . ")\n";
            continue;
        }

        $data = [
            'trend_id' => $tid,
            'site_slug' => $slug,
            'titulo' => $cand['titulo'],
            'score' => (float)$cand['score_discover'],
            'data_detectada' => $cand['data_detectada'],
            'pingo_link' => $cand['pingo_link'],
            'fontes' => $fontes,
            'preparado_em' => date('c'),
        ];
        $arq = $siteDir . '/' . $tid . '.json';
        if (!$dryRun) {
            file_put_contents($arq, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // Marca trend como "em_fila_geracao" pra não pegar de novo
            $pdo->prepare("UPDATE trends SET status='em_fila_geracao' WHERE id=?")->execute([$tid]);
        }
        echo "  ✓ #{$tid} [" . number_format((float)$cand['score_discover'], 2) . "] " . mb_substr($cand['titulo'], 0, 60) . " (" . count($fontes) . " fontes)\n";
        $prepThisSite++;
        $totalPrep++;
    }

    echo "  preparados: {$prepThisSite}\n\n";
}

echo "═══ TOTAL ═══\n";
echo "  preparados nesta run: {$totalPrep}\n";
$totalFila = 0;
foreach (glob($queueDir . '/*/*.json') ?: [] as $f) $totalFila++;
echo "  total na fila (todos sites): {$totalFila}\n";
echo "\nPróximo passo: abra Claude Code e digite /gerar-pendentes\n";
