<?php
/**
 * gsc_aprender — feedback loop semanal: lê GSC últimos 7d, identifica oportunidades, AGE.
 *
 * 3 outputs:
 *   1. **Posts em opportunity zone** — top 10 posições (1-10) com CTR < 1%.
 *      → título não está convertendo. Dispara DiscoverReviewer pra reescrever.
 *   2. **Queries não-respondidas** — queries com 50+ impressions na semana SEM post
 *      correspondente no site. → cria fila com termo novo (status='aprovado', score=10).
 *   3. **Padrões vencedores** — top 10 posts por CTR. Extrai padrão de título/H2 e
 *      sugere snippet pra `DiscoverPromptBuilder` injetar como aprendizado.
 *
 * Não é cron `--apply` por default — gera RELATÓRIO. User decide aplicar.
 *
 * Uso:
 *   php scripts/gsc_aprender.php                       # report dry-run
 *   php scripts/gsc_aprender.php --site=cursosenac     # 1 site
 *   php scripts/gsc_aprender.php --apply               # aplica: dispara Reviewer + cria fila
 *   php scripts/gsc_aprender.php --output=/tmp/x.json  # salva relatório
 *
 * Cron sugerido (semanal, segunda 6h):
 *   0 6 * * 1 /usr/bin/php /var/www/clonais/scripts/gsc_aprender.php --quiet >> /var/log/clonais/gsc_aprender.log 2>&1
 */

set_time_limit(0);
ini_set('memory_limit', '512M');
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/DiscoverFila.php';
require_once $ROOT . '/lib/DiscoverSearchConsole.php';
require_once $ROOT . '/lib/DiscoverClusterMatcher.php';
require_once $ROOT . '/lib/DiscoverPainClassifier.php';
require_once $ROOT . '/lib/DiscoverReviewer.php';
require_once $ROOT . '/_site_helper.php';

$cfg = require $ROOT . '/config.php';

// ── parse args ──
$soSite = null; $apply = false; $output = null; $quiet = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--site='))   $soSite = substr($a, 7);
    elseif ($a === '--apply')             $apply = true;
    elseif (str_starts_with($a, '--output=')) $output = substr($a, 9);
    elseif ($a === '--quiet')             $quiet = true;
}

function log_msg(string $m, bool $q): void { if (!$q) echo '[' . date('Y-m-d H:i:s') . "] {$m}\n"; }

$lock = new CronLock('gsc_aprender');
if (!$lock->aquirir()) { log_msg('outra instância rodando — saindo', $quiet); exit(0); }

$gsc = new DiscoverSearchConsole();
$db = new DiscoverDb();
$sites = sitesDisponiveis();
if ($soSite !== null) {
    if (!isset($sites[$soSite])) { fwrite(STDERR, "Site '{$soSite}' não existe\n"); exit(2); }
    $sites = [$soSite => $sites[$soSite]];
}

$dataFim    = (new DateTimeImmutable('-3 days'))->format('Y-m-d');
$dataInicio = (new DateTimeImmutable('-10 days'))->format('Y-m-d');

$relatorio = ['gerado_em' => date('c'), 'periodo' => "{$dataInicio} a {$dataFim}", 'sites' => []];
$totalRevisores = 0; $totalNovasFilas = 0;

foreach ($sites as $slug => $siteCfg) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slug);
    $wpUrl = rtrim((string)($cfgSite['wp_url'] ?? ''), '/');
    if ($wpUrl === '') continue;
    $gscUrl = (string)($cfgSite['gsc_site_url'] ?? ($wpUrl . '/'));

    log_msg("[{$slug}] consultando GSC ({$dataInicio} a {$dataFim})", $quiet);
    $relatSite = ['site' => $slug, 'oportunidades_titulo' => [], 'queries_orfas' => [], 'top_performers' => []];

    try {
        // 1. Página + query — pra detectar oportunidades de título
        $perPagQuery = $gsc->consultarPerformance($gscUrl, $dataInicio, $dataFim, [
            'dimensoes' => ['page', 'query'], 'limite' => 1000, 'tipo' => 'web',
        ]);
        // 2. Só query — pra detectar queries órfãs (volume alto sem post)
        $perQuery = $gsc->consultarPerformance($gscUrl, $dataInicio, $dataFim, [
            'dimensoes' => ['query'], 'limite' => 500, 'tipo' => 'web',
        ]);
        // 3. Só page — pra ranking de top performers
        $perPage = $gsc->consultarPerformance($gscUrl, $dataInicio, $dataFim, [
            'dimensoes' => ['page'], 'limite' => 200, 'tipo' => 'web',
        ]);
    } catch (Throwable $e) {
        log_msg("[{$slug}] ERRO GSC: {$e->getMessage()}", $quiet);
        $relatSite['erro'] = $e->getMessage();
        $relatorio['sites'][] = $relatSite;
        continue;
    }

    // ── 1. OPORTUNIDADES DE TÍTULO ──
    // Pages com posição < 10 E CTR < 1% (com mínimo 50 impressions)
    $pageStats = [];
    foreach ($perPage['rows'] ?? [] as $r) {
        $url = (string)($r['keys'][0] ?? '');
        if ($url === '') continue;
        $impressions = (int)($r['impressions'] ?? 0);
        $clicks = (int)($r['clicks'] ?? 0);
        $position = (float)($r['position'] ?? 99);
        $ctr = $impressions > 0 ? $clicks / $impressions : 0;
        if ($impressions < 50) continue;
        if ($position >= 10) continue;
        if ($ctr >= 0.01) continue;
        $pageStats[$url] = ['impressions' => $impressions, 'clicks' => $clicks, 'position' => round($position, 1), 'ctr' => round($ctr * 100, 2)];
    }
    foreach ($pageStats as $url => $s) {
        // Mapeia URL → trend_id
        $trendId = mapUrlToTrendId($url, $slug, $db);
        $relatSite['oportunidades_titulo'][] = [
            'url' => $url, 'trend_id' => $trendId,
            'impressions' => $s['impressions'], 'clicks' => $s['clicks'],
            'position' => $s['position'], 'ctr_pct' => $s['ctr'],
        ];
    }
    log_msg(sprintf('[%s] %d posts em opportunity zone (top 10 + CTR<1%%)', $slug, count($pageStats)), $quiet);

    // Aplica: hierarquia cheap → expensive
    //   1) Title Swap   (1 chamada WP, só title)
    //   2) P1 Swap      (1 chamada WP, content modificado)
    //   3) Meta Swap    (1 chamada WP, só meta_description — Yoast/RankMath)
    //   4) Reviewer     (Sonnet reescreve tudo)
    if ($apply) {
        require_once $ROOT . '/lib/DiscoverReviewer.php';
        require_once $ROOT . '/lib/DiscoverTitleSwapper.php';
        require_once $ROOT . '/lib/DiscoverP1Swapper.php';
        require_once $ROOT . '/lib/DiscoverMetaSwapper.php';
        require_once $ROOT . '/lib/Wordpress.php';
        $wpSlug = null;
        try {
            $wpSlug = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);
        } catch (Throwable $e) { /* sem WP, swap fica fora — segue só pro Reviewer */ }

        foreach (array_slice($relatSite['oportunidades_titulo'], 0, 5) as $op) { // limita 5/site/execução
            if (!$op['trend_id']) continue;

            $stats = [
                'ctr_pct'     => $op['ctr_pct'] ?? 0,
                'impressions' => $op['impressions'] ?? 0,
                'clicks'      => $op['clicks'] ?? 0,
                'position'    => $op['position'] ?? 99,
            ];

            // 1ª tentativa: Title Swap
            $swapped = false;
            if ($wpSlug !== null) {
                try {
                    $trendOp = $db->get((int)$op['trend_id']);
                    if (is_array($trendOp) && !empty($trendOp['titulo_variantes'])) {
                        $swap = DiscoverTitleSwapper::tentarSwap($trendOp, $stats, $cfgSite, $db, $wpSlug);
                        if (($swap['acao'] ?? '') === 'swap') {
                            $swapped = true;
                            log_msg(sprintf('  → TitleSwap trend=%d "%s" → "%s" (%s)',
                                $op['trend_id'],
                                substr((string)($swap['titulo_de'] ?? ''), 0, 40),
                                substr((string)($swap['titulo_para'] ?? ''), 0, 40),
                                $swap['motivo'] ?? ''
                            ), $quiet);
                        }
                    }
                } catch (Throwable $e) {
                    log_msg("  → TitleSwap erro: {$e->getMessage()}", $quiet);
                }
            }
            if ($swapped) continue;

            // 2ª tentativa: P1 Swap
            if ($wpSlug !== null) {
                try {
                    $trendOp = $db->get((int)$op['trend_id']);
                    if (is_array($trendOp) && !empty($trendOp['p1_variantes'])) {
                        $swapP1 = DiscoverP1Swapper::tentarSwap($trendOp, $stats, $cfgSite, $db, $wpSlug);
                        if (($swapP1['acao'] ?? '') === 'swap') {
                            $swapped = true;
                            log_msg(sprintf('  → P1Swap trend=%d → "%s..." (%s)',
                                $op['trend_id'],
                                substr((string)($swapP1['p1_para'] ?? ''), 0, 60),
                                $swapP1['motivo'] ?? ''
                            ), $quiet);
                        }
                    }
                } catch (Throwable $e) {
                    log_msg("  → P1Swap erro: {$e->getMessage()}", $quiet);
                }
            }
            if ($swapped) continue;

            // 3ª tentativa: Meta Swap (meta_description via Yoast/RankMath)
            if ($wpSlug !== null) {
                try {
                    $trendOp = $db->get((int)$op['trend_id']);
                    $metaVars = $trendOp['meta_tags']['meta_description_variantes'] ?? [];
                    if (is_array($trendOp) && !empty($metaVars)) {
                        $swapMeta = DiscoverMetaSwapper::tentarSwap($trendOp, $stats, $cfgSite, $db, $wpSlug);
                        if (($swapMeta['acao'] ?? '') === 'swap') {
                            $swapped = true;
                            log_msg(sprintf('  → MetaSwap trend=%d → "%s..." (%s)',
                                $op['trend_id'],
                                substr((string)($swapMeta['meta_para'] ?? ''), 0, 60),
                                $swapMeta['motivo'] ?? ''
                            ), $quiet);
                        }
                    }
                } catch (Throwable $e) {
                    log_msg("  → MetaSwap erro: {$e->getMessage()}", $quiet);
                }
            }
            if ($swapped) continue;

            // 4ª tentativa: Reviewer (caro — só se swap não rolou)
            try {
                $rev = new DiscoverReviewer($cfgSite, $db);
                $r = $rev->revisar((int)$op['trend_id']);
                if (!empty($r['ok'])) {
                    $totalRevisores++;
                    log_msg(sprintf('  → Reviewer trend=%d ok (%s)', $op['trend_id'], substr($r['titulo_depois'] ?? '', 0, 50)), $quiet);
                }
            } catch (Throwable $e) {
                log_msg("  → Reviewer falhou: {$e->getMessage()}", $quiet);
            }
        }
    }

    // ── 2. QUERIES ÓRFÃS ──
    // Queries com 50+ impressions que NÃO tem post no site
    $publicadosTermos = array_map(fn($r) => mb_strtolower(trim((string)($r['termo'] ?? '')), 'UTF-8'),
        $db->all(['site' => $slug, 'status' => 'publicado'])
    );
    foreach ($perQuery['rows'] ?? [] as $r) {
        $query = trim((string)($r['keys'][0] ?? ''));
        $impressions = (int)($r['impressions'] ?? 0);
        if ($query === '' || $impressions < 50) continue;
        // Tem post existente já cobrindo?
        $queryLow = mb_strtolower($query, 'UTF-8');
        $cobertoPorPost = false;
        foreach ($publicadosTermos as $tp) {
            if ($tp === '') continue;
            similar_text($queryLow, $tp, $sim);
            if ($sim >= 70) { $cobertoPorPost = true; break; }
        }
        if ($cobertoPorPost) continue;
        // Filtra queries que parecem ser nome de site/marca (não geram posts originais)
        if (preg_match('/\b(' . preg_quote($slug, '/') . '|cursos?\s+gratuitos?|onde\s+comprar)\b/iu', $query)) continue;

        $relatSite['queries_orfas'][] = [
            'query' => $query,
            'impressions' => $impressions,
            'clicks' => (int)($r['clicks'] ?? 0),
            'position' => round((float)($r['position'] ?? 99), 1),
        ];
    }
    log_msg(sprintf('[%s] %d queries órfãs (50+ imp sem post)', $slug, count($relatSite['queries_orfas'])), $quiet);

    // Aplica: cria fila com queries órfãs (top 3/site)
    if ($apply) {
        $fila = new DiscoverFila($slug);
        $criados = [];
        foreach (array_slice($relatSite['queries_orfas'], 0, 3) as $q) {
            $cluster = DiscoverClusterMatcher::detectar(['termo' => $q['query']]);
            $pain = DiscoverPainClassifier::classificar($q['query']);
            $novo = [
                'site' => $slug,
                'termo' => $q['query'],
                'status' => 'aprovado',
                'score_discover' => 9.0,
                'data_detectada' => date('Y-m-d H:i:s'),
                'origem' => 'gsc_aprender:query_orfa',
                'categoria' => 'GSC oportunity',
                'categoria_ids' => [],
                'volume_busca' => $q['impressions'],
                'volume_label' => $q['impressions'] . ' imp/sem',
                'growth_pct' => 0,
                'intencao' => 'curiosidade',
                'noticias_qtd' => 0,
                'relacionados' => [],
                'pain' => $pain,
                'cluster_detect' => ['key' => $cluster['key'] ?? null, 'nome' => $cluster['nome'] ?? null],
                'ativo' => 1,
            ];
            try {
                $id = $db->upsert($novo);
                $criados[] = $id;
                $totalNovasFilas++;
                log_msg(sprintf('  → fila id=%d (%s)', $id, $q['query']), $quiet);
            } catch (Throwable $e) { /* segue */ }
        }
        if ($criados) {
            // Cria fila batch com os novos
            $records = array_filter(array_map(fn($id) => $db->get($id), $criados));
            if ($records) $fila->criar(array_values($records), 'discover');
        }
    }

    // ── 3. TOP PERFORMERS — padrões vencedores ──
    $topPerf = [];
    foreach ($perPage['rows'] ?? [] as $r) {
        $url = (string)($r['keys'][0] ?? '');
        $clicks = (int)($r['clicks'] ?? 0);
        $impressions = (int)($r['impressions'] ?? 0);
        if ($url === '' || $clicks < 5) continue;
        $topPerf[] = [
            'url' => $url, 'clicks' => $clicks, 'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
        ];
    }
    usort($topPerf, fn($a, $b) => $b['clicks'] <=> $a['clicks']);
    $relatSite['top_performers'] = array_slice($topPerf, 0, 10);
    log_msg(sprintf('[%s] %d top performers (clicks ≥ 5)', $slug, count($relatSite['top_performers'])), $quiet);

    $relatorio['sites'][] = $relatSite;
}

$relatorio['totais'] = [
    'oportunidades' => array_sum(array_map(fn($s) => count($s['oportunidades_titulo'] ?? []), $relatorio['sites'])),
    'queries_orfas' => array_sum(array_map(fn($s) => count($s['queries_orfas'] ?? []), $relatorio['sites'])),
    'top_performers' => array_sum(array_map(fn($s) => count($s['top_performers'] ?? []), $relatorio['sites'])),
    'reviewers_disparados' => $totalRevisores,
    'novas_filas_criadas' => $totalNovasFilas,
];

if ($output !== null) {
    @file_put_contents($output, json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    log_msg("Relatório salvo em {$output}", $quiet);
}

log_msg(sprintf('RESUMO: %d oportunidades, %d queries órfãs, %d top performers · Reviewers=%d Filas=%d',
    $relatorio['totais']['oportunidades'],
    $relatorio['totais']['queries_orfas'],
    $relatorio['totais']['top_performers'],
    $totalRevisores, $totalNovasFilas
), $quiet);

if ($apply && ($totalRevisores > 0 || $totalNovasFilas > 0)) {
    require_once $ROOT . '/lib/HealthWebhook.php';
    HealthWebhook::info('gsc_aprender: aplicado', $relatorio['totais']);
}

$lock->liberar();
exit(0);

// ─── Helper ───
function mapUrlToTrendId(string $url, string $site, DiscoverDb $db): ?int
{
    $urlNorm = trim(strtolower($url));
    $urlNorm = rtrim(preg_replace('#^https?://#', '', $urlNorm), '/');
    foreach ($db->all(['site' => $site, 'status' => 'publicado']) as $r) {
        $editUrl = (string)($r['url_post'] ?? '');
        if (!preg_match('/post=(\d+)/', $editUrl, $m)) continue;
        $postId = (int)$m[1];
        // URL pública = wp_url + '/' + slug (não dá pra resolver sem chamar WP)
        // Aproximação: se o URL contém o slug provável (hífen-separado do termo)
        $termo = (string)($r['termo'] ?? '');
        $slugProvavel = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($termo, 'UTF-8'));
        $slugProvavel = trim($slugProvavel, '-');
        if ($slugProvavel !== '' && strpos($urlNorm, $slugProvavel) !== false) {
            return (int)$r['id'];
        }
    }
    return null;
}
