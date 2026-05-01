<?php
/**
 * pruning_posts_antigos — limpa posts antigos sem tráfego pra preservar autoridade do site.
 *
 * Discover/Google penaliza sites com muita "deadweight" — páginas indexadas mas sem cliques.
 * Este script identifica posts publicados há >MIN_DIAS dias que receberam <MIN_CLIQUES no
 * GSC nos últimos 90 dias e:
 *   - Modo `noindex` (default): adiciona meta robots noindex,nofollow no post
 *   - Modo `draft`: muda status WP pra draft (esconde do público mas preserva URL)
 *   - Modo `delete`: deleta o post (irreversível — usar com cuidado)
 *
 * Idempotente — posts já processados ficam em data/pruning_state.json e não são re-tocados.
 *
 * Uso:
 *   php scripts/pruning_posts_antigos.php                            # dry-run em todos os sites
 *   php scripts/pruning_posts_antigos.php --apply                    # aplica em todos
 *   php scripts/pruning_posts_antigos.php --site=comocomprar --apply # 1 site só
 *   php scripts/pruning_posts_antigos.php --modo=draft --apply       # move pra draft em vez de noindex
 *   php scripts/pruning_posts_antigos.php --min-dias=180 --min-cliques=2 --apply  # critérios custom
 *   php scripts/pruning_posts_antigos.php --historico                # lista posts já processados
 *
 * Cron sugerido (mensal, dia 1, 5h):
 *   0 5 1 * * /usr/bin/php /var/www/clonais/scripts/pruning_posts_antigos.php --apply --quiet >> /var/log/clonais/pruning.log 2>&1
 */

set_time_limit(0);
ini_set('memory_limit', '512M');
$ROOT = dirname(__DIR__);

require_once $ROOT . '/lib/CronLock.php';
require_once $ROOT . '/lib/DiscoverDb.php';
require_once $ROOT . '/lib/Wordpress.php';
require_once $ROOT . '/lib/DiscoverSearchConsole.php';
require_once $ROOT . '/_site_helper.php';

$cfg = require $ROOT . '/config.php';

// ── parse args ──
$soSite = null; $apply = false; $modo = 'noindex'; $quiet = false; $historico = false;
$minDias = 90; $minCliques = 5;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--site='))         $soSite = substr($arg, 7);
    elseif ($arg === '--apply')                   $apply = true;
    elseif (str_starts_with($arg, '--modo='))     $modo = substr($arg, 7);
    elseif ($arg === '--quiet')                   $quiet = true;
    elseif ($arg === '--historico')               $historico = true;
    elseif (str_starts_with($arg, '--min-dias='))    $minDias = max(30, (int)substr($arg, 11));
    elseif (str_starts_with($arg, '--min-cliques=')) $minCliques = max(0, (int)substr($arg, 14));
}

if (!in_array($modo, ['noindex', 'draft', 'delete'], true)) {
    fwrite(STDERR, "Modo inválido: '{$modo}'. Use: noindex|draft|delete\n");
    exit(1);
}

function log_msg(string $m, bool $q): void { if (!$q) echo '[' . date('Y-m-d H:i:s') . "] {$m}\n"; }

$STATE_PATH = $ROOT . '/data/pruning_state.json';

// ── modo histórico (só leitura) ──
if ($historico) {
    if (!is_file($STATE_PATH)) { echo "(sem histórico)\n"; exit(0); }
    $state = json_decode((string)file_get_contents($STATE_PATH), true) ?: ['events' => []];
    foreach (array_slice($state['events'] ?? [], -50) as $e) {
        printf("%s | %s | post=%-5d | %s | %s\n",
            substr($e['ts'] ?? '', 0, 10),
            $e['site'] ?? '?',
            $e['post_id'] ?? 0,
            $e['acao'] ?? '?',
            substr($e['titulo'] ?? '', 0, 60)
        );
    }
    exit(0);
}

$lock = new CronLock('pruning_posts_antigos');
if (!$lock->aquirir()) { log_msg('outra instância rodando — saindo', $quiet); exit(0); }

// Carrega state
$state = is_file($STATE_PATH)
    ? (json_decode((string)file_get_contents($STATE_PATH), true) ?: ['events' => [], 'processados' => []])
    : ['events' => [], 'processados' => []];
if (!isset($state['processados'])) $state['processados'] = [];

$gsc = new DiscoverSearchConsole();
$sites = sitesDisponiveis();
if ($soSite !== null) {
    if (!isset($sites[$soSite])) { fwrite(STDERR, "Site '{$soSite}' não existe\n"); exit(2); }
    $sites = [$soSite => $sites[$soSite]];
}

$totalProcessados = 0; $totalAplicados = 0; $totalSkippedJa = 0; $totalErros = 0;

// Janela GSC: últimos 90d
$dataFimGsc    = (new DateTimeImmutable('-3 days'))->format('Y-m-d');
$dataInicioGsc = (new DateTimeImmutable('-93 days'))->format('Y-m-d');

foreach ($sites as $slug => $siteCfg) {
    $cfgSite = $cfg;
    aplicarSite($cfgSite, $sites, $slug);
    $wpUrl = rtrim((string)($cfgSite['wp_url'] ?? ''), '/');
    if ($wpUrl === '') { log_msg("[{$slug}] sem wp_url — pulando", $quiet); continue; }
    $gscUrl = (string)($cfgSite['gsc_site_url'] ?? ($wpUrl . '/'));

    log_msg("[{$slug}] iniciando análise GSC ({$dataInicioGsc} a {$dataFimGsc})", $quiet);

    // Busca clicks por página dos últimos 90d via GSC
    try {
        $perf = $gsc->consultarPerformance($gscUrl, $dataInicioGsc, $dataFimGsc, [
            'dimensoes' => ['page'], 'limite' => 5000, 'tipo' => 'web',
        ]);
    } catch (Throwable $e) {
        log_msg("[{$slug}] ERRO GSC: {$e->getMessage()}", $quiet);
        $totalErros++;
        continue;
    }

    $clicksPorPage = [];
    foreach (($perf['rows'] ?? []) as $r) {
        $url = (string)($r['keys'][0] ?? '');
        if ($url === '') continue;
        $clicksPorPage[$url] = ($clicksPorPage[$url] ?? 0) + (int)($r['clicks'] ?? 0);
    }

    // Lista posts publicados via WP REST (paginado)
    $wp = new Wordpress($cfgSite['wp_url'], $cfgSite['wp_user'], $cfgSite['wp_app_password']);
    $cutoffData = (new DateTimeImmutable("-{$minDias} days"))->format('Y-m-d');
    $page = 1; $candidatos = []; $fetched = 0;
    while (true) {
        try {
            $batch = $wp->listarPosts(['status' => 'publish', 'before' => $cutoffData . 'T23:59:59', 'per_page' => 100, 'page' => $page]);
        } catch (Throwable $e) {
            // Wordpress::listarPosts pode não existir — fallback a request direto
            $batch = wpListPosts($cfgSite, $cutoffData, $page);
        }
        if (empty($batch)) break;
        foreach ($batch as $p) {
            $pid = (int)($p['id'] ?? 0);
            $link = (string)($p['link'] ?? '');
            if ($pid <= 0 || $link === '') continue;
            // Já processado antes?
            $key = "{$slug}:{$pid}";
            if (isset($state['processados'][$key])) { $totalSkippedJa++; continue; }
            // Clicks no GSC
            $clicks = (int)($clicksPorPage[$link] ?? 0);
            $clicksAlt = (int)($clicksPorPage[$link . '/'] ?? 0); // trailing slash variante
            $clicks = max($clicks, $clicksAlt);
            if ($clicks >= $minCliques) continue; // tem tráfego — preserva
            $candidatos[] = [
                'site' => $slug, 'post_id' => $pid, 'link' => $link,
                'titulo' => $p['title']['rendered'] ?? '?',
                'data' => $p['date'] ?? '?',
                'clicks_90d' => $clicks,
            ];
        }
        $fetched += count($batch);
        if (count($batch) < 100) break;
        $page++;
        if ($page > 50) break; // hard cap 5000 posts
    }
    $totalProcessados += $fetched;
    log_msg(sprintf('[%s] %d posts antigos analisados, %d candidatos a pruning (clicks_90d < %d)',
        $slug, $fetched, count($candidatos), $minCliques), $quiet);

    foreach ($candidatos as $c) {
        $titulo = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string)$c['titulo'])));
        $msg = sprintf('  [%s] post=%d (%d clicks 90d) "%s"', $apply ? $modo : 'DRY', $c['post_id'], $c['clicks_90d'], substr($titulo, 0, 60));
        log_msg($msg, $quiet);
        if (!$apply) continue;

        try {
            switch ($modo) {
                case 'draft':
                    $wp->atualizarPost($c['post_id'], ['status' => 'draft']);
                    break;
                case 'delete':
                    // Hard delete — usa REST DELETE com force=true
                    wpDeletarPost($cfgSite, $c['post_id']);
                    break;
                case 'noindex':
                default:
                    // Adiciona meta robots noindex via Rank Math meta
                    $wp->atualizarPost($c['post_id'], [
                        'meta' => ['rank_math_robots' => ['noindex', 'nofollow']],
                    ]);
                    break;
            }
            $state['processados'][$slug . ':' . $c['post_id']] = date('c');
            $state['events'][] = [
                'ts' => date('c'), 'site' => $slug, 'post_id' => $c['post_id'],
                'acao' => $modo, 'clicks_90d' => $c['clicks_90d'], 'titulo' => $titulo,
            ];
            $totalAplicados++;
        } catch (Throwable $e) {
            log_msg("    ERRO: {$e->getMessage()}", $quiet);
            $totalErros++;
        }
    }
}

// Persiste state (rotativo: max 5000 events)
if (count($state['events']) > 5000) {
    $state['events'] = array_slice($state['events'], -5000);
}
@file_put_contents($STATE_PATH, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

log_msg(sprintf('RESUMO: %d posts analisados | %d aplicados (%s) | %d já-processados | %d erros',
    $totalProcessados, $totalAplicados, $modo, $totalSkippedJa, $totalErros), $quiet);

if ($totalErros > 0) {
    require_once $ROOT . '/lib/HealthWebhook.php';
    HealthWebhook::aviso('pruning_posts_antigos: erros', ['erros' => $totalErros, 'aplicados' => $totalAplicados]);
}

$lock->liberar();
exit(0);

// ─── Helpers fallback ───

function wpListPosts(array $cfg, string $beforeDate, int $page): array
{
    $auth = base64_encode($cfg['wp_user'] . ':' . $cfg['wp_app_password']);
    $url = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2/posts?status=publish'
         . '&before=' . urlencode($beforeDate . 'T23:59:59')
         . '&per_page=100&page=' . $page
         . '&_fields=id,link,date,title';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
        CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return is_array($data) ? $data : [];
}

function wpDeletarPost(array $cfg, int $postId): bool
{
    $auth = base64_encode($cfg['wp_user'] . ':' . $cfg['wp_app_password']);
    $url = rtrim($cfg['wp_url'], '/') . '/wp-json/wp/v2/posts/' . $postId . '?force=true';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    @curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
