<?php
/**
 * update_detector.php — cron diário que detecta mudança de fato em fontes
 * de posts publicados (7-30 dias) e dispara refresh cirúrgico.
 *
 * Uso:
 *   php scripts/update_detector.php --site=cursosenac                          # dry-run (lista)
 *   php scripts/update_detector.php --site=cursosenac --confirm                # executa updates
 *   php scripts/update_detector.php --site=cursosenac --confirm --max=20       # cap explícito
 *   php scripts/update_detector.php --site=cursosenac --confirm --quiet        # cron mode
 *
 * Cap padrão: 20 posts/dia/site (~R$5-8/dia).
 *
 * 1ª execução só cria baselines em data/update_detector/{site}_{trend_id}.json.
 * 2ª+ executa diff e dispara refresh quando datas/valores/números/status mudam.
 */

declare(strict_types=1);

$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$siteSlug = (string)($args['site'] ?? '');
$dryRun   = empty($args['confirm']);
$maxRun   = (int)($args['max'] ?? 20);
$quiet    = !empty($args['quiet']);

if ($siteSlug === '') {
    fwrite(STDERR, "uso: php update_detector.php --site=SLUG [--confirm] [--max=20] [--quiet]\n");
    exit(2);
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../_site_helper.php';
require_once __DIR__ . '/../lib/Scraper.php';
require_once __DIR__ . '/../lib/Claude.php';
require_once __DIR__ . '/../lib/Wordpress.php';
require_once __DIR__ . '/../lib/InstantIndexing.php';
require_once __DIR__ . '/../lib/EntityExtractor.php';
require_once __DIR__ . '/../lib/UpdateDetector.php';
require_once __DIR__ . '/../lib/PostHtmlSanitizers.php';
require_once __DIR__ . '/../lib/DiscoverDbMysql.php';

aplicarSite($cfg, sitesDisponiveis(), $siteSlug);

$logPath = __DIR__ . '/../data/update_detector/_runs/' . date('Y-m-d_His') . "_{$siteSlug}.json";
@mkdir(dirname($logPath), 0775, true);

$say = function (string $msg) use ($quiet) {
    if (!$quiet) echo $msg . "\n";
};

$say("═══ Update Detector — site={$siteSlug} | " . ($dryRun ? 'DRY-RUN' : 'EXECUTAR') . " | cap={$maxRun} ═══");

$db = new DiscoverDbMysql();
$scraper = new Scraper($cfg['user_agent'], (int)($cfg['scrape_timeout'] ?? 15));
$wp = new Wordpress($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']);
$claude = new Claude((string)$cfg['anthropic_api_key'], (string)$cfg['anthropic_model']);
$idxApi = !empty($cfg['gsc_service_account_json']) ? new InstantIndexing($cfg['wp_url'], $cfg['wp_user'], $cfg['wp_app_password']) : null;

$detector = new UpdateDetector($db, $scraper, $siteSlug);
$candidatos = $detector->listarCandidatos(50, 7, 30);
$say('Candidatos encontrados: ' . count($candidatos));

$resultados = [
    'inicio' => date('c'),
    'site' => $siteSlug,
    'modo' => $dryRun ? 'dry-run' : 'execute',
    'cap' => $maxRun,
    'candidatos' => count($candidatos),
    'baselines_criados' => 0,
    'sem_mudanca' => 0,
    'mudancas_detectadas' => 0,
    'refresh_executados' => 0,
    'refresh_falhos' => 0,
    'erros_scrape' => 0,
    'updates' => [],
];

$executados = 0;

foreach ($candidatos as $c) {
    $trendId = (int)$c['id'];
    $postId = (int)$c['post_id'];
    $tit = (string)($c['titulo'] ?? $c['termo'] ?? '');
    $say("→ trend #{$trendId} post #{$postId} — " . mb_substr($tit, 0, 60));

    $r = $detector->detectarMudancas($c);

    if (!empty($r['erro'])) {
        $resultados['erros_scrape']++;
        $say("   ✗ {$r['erro']}");
        $resultados['updates'][] = ['trend_id' => $trendId, 'post_id' => $postId, 'titulo' => $tit, 'status' => 'erro_scrape', 'msg' => $r['erro']];
        continue;
    }

    if (!$r['baseline_existia']) {
        $resultados['baselines_criados']++;
        $say('   · baseline criado (1ª passada — sem refresh)');
        $resultados['updates'][] = ['trend_id' => $trendId, 'post_id' => $postId, 'titulo' => $tit, 'status' => 'baseline_criado'];
        continue;
    }

    if (!$r['mudou']) {
        $resultados['sem_mudanca']++;
        $say('   · sem mudança crítica');
        continue;
    }

    $resultados['mudancas_detectadas']++;
    $motivos = $r['motivos'] ?? [];
    $say('   ⚠ MUDANÇA: ' . implode(' | ', array_slice($motivos, 0, 3)));

    if ($dryRun) {
        $resultados['updates'][] = ['trend_id' => $trendId, 'post_id' => $postId, 'titulo' => $tit, 'status' => 'mudanca_detectada_dry', 'motivos' => $motivos, 'diff' => $r['diff']];
        continue;
    }

    if ($executados >= $maxRun) {
        $say('   ⏸ cap atingido — pulando');
        $resultados['updates'][] = ['trend_id' => $trendId, 'post_id' => $postId, 'titulo' => $tit, 'status' => 'pulado_cap', 'motivos' => $motivos];
        continue;
    }

    // Executa refresh: getPost antigo + Claude->atualizarPost + sanitiza + atualizarPost WP + indexa URL_UPDATED
    try {
        $postAntigo = $wp->getPost($postId);
        $htmlAntigo = (string)($postAntigo['content']['raw'] ?? $postAntigo['content']['rendered'] ?? '');
        $titAntigo = (string)($postAntigo['title']['raw'] ?? $postAntigo['title']['rendered'] ?? '');
        $linkAntigo = (string)($postAntigo['link'] ?? '');
        if ($htmlAntigo === '') throw new RuntimeException('content raw vazio no WP');

        $atualizado = $claude->atualizarPost($titAntigo, $htmlAntigo, [$r['scrape']], 'discover');
        $novoHtml = (string)($atualizado['content_html'] ?? '');
        $novoTitulo = (string)($atualizado['title'] ?? $titAntigo);
        if ($novoHtml === '') throw new RuntimeException('Claude retornou HTML vazio');

        $novoHtml = quebrarParagrafosLongos($novoHtml, 999);
        $novoHtml = sanitizarTravessoes($novoHtml);
        $novoHtml = autoFixIntroInflada($novoHtml);
        $novoHtml = autoFixRdParaFechamento($novoHtml);
        $novoHtml = autoFixReticenciasExcessivas($novoHtml);

        $cfgPurge = !empty($cfg['cloudflare_zone_id'])
            ? ['cloudflare_zone_id' => $cfg['cloudflare_zone_id'], 'urls' => array_filter([$linkAntigo])]
            : [];
        $wp->atualizarPost($postId, ['title' => $novoTitulo, 'content' => $novoHtml], $cfgPurge);

        if ($idxApi && $linkAntigo !== '') {
            try { $idxApi->indexar($linkAntigo, 'URL_UPDATED'); } catch (Throwable $eI) {}
        }

        $detector->marcarRefreshExecutado($trendId, ['titulo_novo' => $novoTitulo, 'words' => str_word_count(strip_tags($novoHtml))]);
        $executados++;
        $resultados['refresh_executados']++;
        $say("   ✓ REFRESH OK — words=" . str_word_count(strip_tags($novoHtml)));
        $resultados['updates'][] = [
            'trend_id' => $trendId,
            'post_id' => $postId,
            'titulo' => $novoTitulo,
            'status' => 'refresh_ok',
            'motivos' => $motivos,
            'link' => $linkAntigo,
        ];
    } catch (Throwable $e) {
        $resultados['refresh_falhos']++;
        $say('   ✗ REFRESH FAIL: ' . $e->getMessage());
        $resultados['updates'][] = [
            'trend_id' => $trendId,
            'post_id' => $postId,
            'titulo' => $tit,
            'status' => 'refresh_falhou',
            'motivos' => $motivos,
            'erro' => $e->getMessage(),
        ];
    }
}

$resultados['fim'] = date('c');
file_put_contents($logPath, json_encode($resultados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (!$quiet) {
    echo "\n═══ RESUMO ═══\n";
    echo "  candidatos:           " . $resultados['candidatos'] . "\n";
    echo "  baselines_criados:    " . $resultados['baselines_criados'] . "\n";
    echo "  sem_mudanca:          " . $resultados['sem_mudanca'] . "\n";
    echo "  mudancas_detectadas:  " . $resultados['mudancas_detectadas'] . "\n";
    echo "  refresh_executados:   " . $resultados['refresh_executados'] . "\n";
    echo "  refresh_falhos:       " . $resultados['refresh_falhos'] . "\n";
    echo "  erros_scrape:         " . $resultados['erros_scrape'] . "\n";
    echo "  log: $logPath\n";
}
