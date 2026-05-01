<?php
/**
 * ClusterKiller — pausa automática de clusters que não performam (B5 Frente Inteligência).
 *
 * Por que existe:
 *   - Cluster que em 30d gera <10 clicks Discover totais E CTR <0.5% = desperdício de
 *     Sonnet ($0.30/post × N posts/mês). Ao pausar, trend daquele cluster vai pra rejeição
 *     no PrePublishLint, economizando geração e dando espaço pra outros clusters.
 *   - Decisão não é DEFINITIVA: re-analisa toda semana. Cluster volta automático se trend
 *     futura (de outra origem) provar que tem performance — basta passar pelo lint.
 *
 * Fonte de verdade:
 *   - PostPerformanceLog JSONL (data/post_performance/{YYYY-MM}.jsonl) — métricas Discover
 *   - DiscoverDb (data/discover_trends.json) — pra mapear post_id → cluster_key
 *
 * Persistência:
 *   - data/cluster_paused.json: {"<site>|<cluster_key>": {"pausado_em": ISO, "razao": "..."}}
 *
 * Decisão de pausa (default tunável):
 *   - Janela: 30 dias
 *   - Mínimo posts no cluster: 5 (proteção estatística — não pausa cluster com 1 post ruim)
 *   - Threshold A: clicks Discover totais < 10
 *   - Threshold B: CTR médio Discover < 0.5%
 *   - Pausa só se A AND B (ambos ruins)
 *
 * Uso (cron semanal):
 *   $k = new ClusterKiller();
 *   $analise = $k->analisar($db);             // não muda nada
 *   $k->aplicar($analise);                    // grava data/cluster_paused.json
 *
 * Consulta no PrePublishLint:
 *   if (ClusterKiller::estaPausado($site, $clusterKey)) reject('cluster_paused');
 */
class ClusterKiller
{
    public const PAUSE_PATH_DEF = '/../data/cluster_paused.json';
    public const JANELA_DIAS = 30;
    public const MIN_POSTS_CLUSTER = 5;
    public const MAX_CLICKS_PARA_PAUSAR = 10;
    public const MAX_CTR_PARA_PAUSAR = 0.005; // 0.5%

    private string $pausePath;

    public function __construct(?string $pausePath = null)
    {
        $this->pausePath = $pausePath ?? __DIR__ . self::PAUSE_PATH_DEF;
    }

    /**
     * Lê PostPerformanceLog + DiscoverDb, agrega por (site × cluster_key) e decide pausa.
     *
     * @param object $db DiscoverDb (precisa ->all([])
     * @param array  $opts {janela_dias, min_posts, max_clicks, max_ctr, log_base_dir}
     * @return array {analise: [{site, cluster_key, posts, clicks, impr, ctr, pausar, razao}], total_pausados}
     */
    public function analisar($db, array $opts = []): array
    {
        require_once __DIR__ . '/PostPerformanceLog.php';

        $janela     = (int)($opts['janela_dias'] ?? self::JANELA_DIAS);
        $minPosts   = (int)($opts['min_posts'] ?? self::MIN_POSTS_CLUSTER);
        $maxClicks  = (int)($opts['max_clicks'] ?? self::MAX_CLICKS_PARA_PAUSAR);
        $maxCtr     = (float)($opts['max_ctr'] ?? self::MAX_CTR_PARA_PAUSAR);
        $baseDir    = $opts['log_base_dir'] ?? null;

        // 1. Mapa post_id → cluster_key + site (de DB)
        $publicados = $db->all(['status' => 'publicado']);
        $mapPost = [];
        foreach ($publicados as $p) {
            $pid = (int)($p['post_id'] ?? 0);
            if ($pid === 0) continue;
            $mapPost[$pid] = [
                'site'        => (string)($p['site'] ?? ''),
                'cluster_key' => (string)($p['cluster_detect']['key'] ?? 'curiosidades_geral'),
            ];
        }

        // 2. Lê JSONL dos meses na janela (mês atual + anterior cobre 30-60d)
        $mesAtual    = date('Y-m');
        $mesAnterior = date('Y-m', strtotime('-1 month'));
        $entries = array_merge(
            PostPerformanceLog::lerLog($mesAtual, [], $baseDir),
            PostPerformanceLog::lerLog($mesAnterior, [], $baseDir)
        );

        // Filtra por janela + surface=discover
        $cutoff = strtotime("-{$janela} days");
        $entriesDiscover = array_filter($entries, function ($e) use ($cutoff) {
            return ($e['surface'] ?? '') === 'discover'
                && strtotime($e['ts'] ?? '') >= $cutoff;
        });

        // 3. Agrega por (site × cluster)
        $agg = [];
        foreach ($entriesDiscover as $e) {
            $pid = (int)($e['post_id'] ?? 0);
            if (!isset($mapPost[$pid])) continue;
            $site    = $mapPost[$pid]['site'];
            $cluster = $mapPost[$pid]['cluster_key'];
            if ($site === '' || $cluster === '') continue;
            $key = $site . '|' . $cluster;
            $agg[$key] ??= [
                'site'        => $site,
                'cluster_key' => $cluster,
                'posts_unicos'=> [],
                'clicks'      => 0,
                'impressions' => 0,
            ];
            $agg[$key]['posts_unicos'][$pid] = true;
            $agg[$key]['clicks']      += (int)($e['clicks'] ?? 0);
            $agg[$key]['impressions'] += (int)($e['impressions'] ?? 0);
        }

        // 4. Decide pausa
        $analise = [];
        $pausados = 0;
        foreach ($agg as $key => $info) {
            $nPosts = count($info['posts_unicos']);
            $clicks = (int)$info['clicks'];
            $impr   = (int)$info['impressions'];
            $ctr    = $impr > 0 ? round($clicks / $impr, 5) : 0;
            $pausar = $nPosts >= $minPosts && $clicks < $maxClicks && $ctr < $maxCtr;
            $razao  = '';
            if ($pausar) {
                $razao = sprintf("clicks=%d/<%d AND CTR=%s%%/<%s%% (%d posts em %dd)",
                    $clicks, $maxClicks,
                    number_format($ctr * 100, 3, '.', ''),
                    number_format($maxCtr * 100, 3, '.', ''),
                    $nPosts, $janela);
                $pausados++;
            }
            $analise[] = [
                'site'        => $info['site'],
                'cluster_key' => $info['cluster_key'],
                'posts'       => $nPosts,
                'clicks'      => $clicks,
                'impressions' => $impr,
                'ctr'         => $ctr,
                'pausar'      => $pausar,
                'razao'       => $razao,
            ];
        }
        // Ordena: pausar=true primeiro, depois por clicks ASC (piores no topo)
        usort($analise, function ($a, $b) {
            if ($a['pausar'] !== $b['pausar']) return $a['pausar'] ? -1 : 1;
            return $a['clicks'] <=> $b['clicks'];
        });

        return [
            'analise'         => $analise,
            'total_clusters'  => count($analise),
            'total_pausados'  => $pausados,
            'janela_dias'     => $janela,
        ];
    }

    /**
     * Aplica análise: grava arquivo de paused. Sobrescreve previous (re-analisa toda execução).
     */
    public function aplicar(array $analise): array
    {
        require_once __DIR__ . '/JsonStore.php';
        $pausados = [];
        foreach ($analise['analise'] ?? [] as $a) {
            if (empty($a['pausar'])) continue;
            $key = $a['site'] . '|' . $a['cluster_key'];
            $pausados[$key] = [
                'pausado_em' => date('c'),
                'razao'      => $a['razao'],
                'clicks'     => $a['clicks'],
                'ctr'        => $a['ctr'],
                'posts'      => $a['posts'],
            ];
        }
        JsonStore::write($this->pausePath, [
            'gerado_em'    => date('c'),
            'janela_dias'  => $analise['janela_dias'] ?? self::JANELA_DIAS,
            'pausados'     => $pausados,
        ], 3, true);
        return ['pausados' => count($pausados), 'arquivo' => $this->pausePath];
    }

    /**
     * Consulta se (site, cluster_key) está atualmente pausado. Lib estática pra
     * PrePublishLint chamar barato. Cache em static var pra evitar re-leituras.
     */
    public static function estaPausado(string $site, string $clusterKey, ?string $pausePath = null): bool
    {
        static $cache = [];
        $path = $pausePath ?? (__DIR__ . self::PAUSE_PATH_DEF);
        if (!array_key_exists($path, $cache)) {
            $cache[$path] = [];
            if (is_file($path)) {
                $raw = @file_get_contents($path);
                $d = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
                $cache[$path] = is_array($d['pausados'] ?? null) ? $d['pausados'] : [];
            }
        }
        $key = $site . '|' . $clusterKey;
        return isset($cache[$path][$key]);
    }
}
