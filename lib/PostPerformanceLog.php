<?php
/**
 * PostPerformanceLog — snapshot diário de cada post publicado em 3 surfaces do GSC.
 *
 * Por que existe:
 *   - Sem isso, mês 1 do deploy é cego: não dá pra otimizar prompt sem feedback de qual
 *     trend viralizou e qual morreu.
 *   - GSC API tem 16 meses de histórico, mas pra ML precisa do dado JÁ. Snapshot diário
 *     desde o dia 1 = base de ML acumulável.
 *
 * Estratégia:
 *   - Pra cada post `publicado` nos últimos N dias, consulta GSC últimas 24h em
 *     **3 surfaces separadas**: web (Search), discover (Discover feed), googleNews (News).
 *   - Append em JSONL mensal (data/post_performance/{YYYY-MM}.jsonl).
 *   - Cada linha: {ts, post_id, trend_id, site, url, published_at, day_offset, surface,
 *                  clicks, impressions, ctr, position}
 *   - JSONL é append-only (nada a re-escrever em corrida). Crash no meio = última linha
 *     parcial só é descartada na leitura (json_decode null).
 *
 * Uso (no cron diário):
 *   $log = new PostPerformanceLog();
 *   $r = $log->snapshot('cursosenac', $cfg, $db, $gsc, ['max_posts' => 100, 'janela_d' => 30]);
 *
 * Uso (relatório):
 *   $entries = PostPerformanceLog::lerLog('2026-04', ['site' => 'cursosenac']);
 *   $resumo = PostPerformanceLog::agregarPorPost($entries);
 */
class PostPerformanceLog
{
    public const SURFACES = ['web', 'discover', 'googleNews'];
    private const PATH_DEF = '/../data/post_performance';

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? __DIR__ . self::PATH_DEF;
        if (!is_dir($this->baseDir)) @mkdir($this->baseDir, 0777, true);
    }

    /**
     * Snapshot diário pra um site.
     *
     * @param string $siteSlug
     * @param array  $cfg     cfg do site (precisa wp_url e gsc_site_url se houver)
     * @param object $db      DiscoverDb pra listar trends publicados
     * @param object $gsc     DiscoverSearchConsole
     * @param array  $opts    {janela_d:30, max_posts:200, dia_alvo:'YYYY-MM-DD'}
     * @return array {ok, posts_processados, surfaces_consultadas, entries_logadas, erros}
     */
    public function snapshot(string $siteSlug, array $cfg, $db, $gsc, array $opts = []): array
    {
        $janelaDias = (int)($opts['janela_d'] ?? 30);
        $maxPosts   = (int)($opts['max_posts'] ?? 200);

        // Dia-alvo: GSC tem ~3 dias de delay. Por default consulta 3 dias atrás.
        $diaAlvo = (string)($opts['dia_alvo'] ?? date('Y-m-d', strtotime('-3 days')));

        $siteGscUrl = (string)($cfg['gsc_site_url'] ?? rtrim($cfg['wp_url'] ?? '', '/') . '/');
        if ($siteGscUrl === '/' || $siteGscUrl === '') {
            return ['ok' => false, 'erro' => 'wp_url/gsc_site_url ausente em cfg'];
        }

        // Lista posts publicados no site nos últimos $janelaDias dias
        $publicados = $db->all(['site' => $siteSlug, 'status' => 'publicado']);
        $cutoff = strtotime("-{$janelaDias} days");
        $candidatos = [];
        foreach ($publicados as $p) {
            $pubAt = strtotime((string)($p['publicado_em'] ?? $p['data_geracao'] ?? ''));
            if ($pubAt === false || $pubAt < $cutoff) continue;
            $url = (string)($p['url_post'] ?? '');
            if ($url === '') continue;
            $candidatos[] = $p;
            if (count($candidatos) >= $maxPosts) break;
        }
        if (empty($candidatos)) {
            return ['ok' => true, 'posts_processados' => 0, 'entries_logadas' => 0, 'nota' => 'sem posts elegíveis'];
        }

        // Indexa rows GSC por URL pra cada surface (1 chamada GSC por surface, não N!)
        $rowsBySurface = [];
        $erros = [];
        foreach (self::SURFACES as $surface) {
            try {
                $resp = $gsc->consultarPerformance($siteGscUrl, $diaAlvo, $diaAlvo, [
                    'dimensoes' => ['page'],
                    'limite'    => 5000,
                    'tipo'      => $surface,
                ]);
                $rowsBySurface[$surface] = self::indexarPorUrl($resp['rows'] ?? []);
            } catch (Throwable $e) {
                $rowsBySurface[$surface] = [];
                $erros[] = "{$surface}: " . mb_substr($e->getMessage(), 0, 150);
            }
        }

        // Append JSONL (idempotência: usa chave ts+post_id+surface; mas como é append, não checa duplicado)
        $entries = 0;
        $logFile = $this->baseDir . '/' . substr($diaAlvo, 0, 7) . '.jsonl';
        $fp = @fopen($logFile, 'ab');
        if ($fp === false) {
            return ['ok' => false, 'erro' => 'falha abrir log: ' . $logFile];
        }
        foreach ($candidatos as $p) {
            $url = (string)$p['url_post'];
            $pubAt = strtotime((string)($p['publicado_em'] ?? $p['data_geracao'] ?? ''));
            $dayOffset = $pubAt ? (int)floor((strtotime($diaAlvo) - $pubAt) / 86400) : null;
            foreach (self::SURFACES as $surface) {
                $row = self::lookupUrl($rowsBySurface[$surface] ?? [], $url);
                // Mesmo se row=null (sem dado pra essa surface), grava com zeros — sinaliza
                // "tinha 0 impressões em discover" vs "snapshot não rodou"
                $entry = [
                    'ts'           => $diaAlvo,
                    'post_id'      => (int)($p['post_id'] ?? 0),
                    'trend_id'     => (int)($p['id'] ?? 0),
                    'site'         => $siteSlug,
                    'url'          => $url,
                    'published_at' => $pubAt ? date('Y-m-d', $pubAt) : null,
                    'day_offset'   => $dayOffset,
                    'surface'      => $surface,
                    'clicks'       => (int)($row['clicks'] ?? 0),
                    'impressions'  => (int)($row['impressions'] ?? 0),
                    'ctr'          => $row ? round((float)($row['ctr'] ?? 0), 4) : 0,
                    'position'     => $row ? round((float)($row['position'] ?? 0), 2) : 0,
                ];
                @fwrite($fp, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $entries++;
            }
        }
        @fclose($fp);

        return [
            'ok'                  => empty($erros),
            'site'                => $siteSlug,
            'dia_alvo'            => $diaAlvo,
            'posts_processados'   => count($candidatos),
            'surfaces_consultadas'=> count(array_filter($rowsBySurface, fn($r) => !empty($r))),
            'entries_logadas'     => $entries,
            'log_file'            => $logFile,
            'erros'               => $erros,
        ];
    }

    /**
     * Lê JSONL de um mês (YYYY-MM) com filtros opcionais.
     *
     * @param string $mes        'YYYY-MM'
     * @param array  $filtros    {site:?, surface:?, post_id:?, trend_id:?, day_offset_min:?, day_offset_max:?}
     * @param ?string $baseDir   override (default lib/../data/post_performance)
     * @return array<int,array>
     */
    public static function lerLog(string $mes, array $filtros = [], ?string $baseDir = null): array
    {
        $dir = $baseDir ?? __DIR__ . self::PATH_DEF;
        $file = $dir . '/' . $mes . '.jsonl';
        if (!is_file($file)) return [];
        $entries = [];
        $fp = @fopen($file, 'rb');
        if (!$fp) return [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $e = json_decode($line, true);
            if (!is_array($e)) continue;
            if (isset($filtros['site'])    && $e['site']    !== $filtros['site'])    continue;
            if (isset($filtros['surface']) && $e['surface'] !== $filtros['surface']) continue;
            if (isset($filtros['post_id'])  && (int)$e['post_id']  !== (int)$filtros['post_id'])  continue;
            if (isset($filtros['trend_id']) && (int)$e['trend_id'] !== (int)$filtros['trend_id']) continue;
            if (isset($filtros['day_offset_min']) && (int)($e['day_offset'] ?? 0) < $filtros['day_offset_min']) continue;
            if (isset($filtros['day_offset_max']) && (int)($e['day_offset'] ?? 0) > $filtros['day_offset_max']) continue;
            $entries[] = $e;
        }
        @fclose($fp);
        return $entries;
    }

    /**
     * Agrega entries por (post_id + surface), retornando última snapshot e métricas
     * d1 / d3 / d7 / d30 quando disponíveis (baseado em day_offset).
     *
     * @return array<string,array> chave = "{post_id}|{surface}"
     */
    public static function agregarPorPost(array $entries): array
    {
        $agg = [];
        foreach ($entries as $e) {
            $key = ($e['post_id'] ?? 0) . '|' . ($e['surface'] ?? '');
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'post_id'   => $e['post_id'] ?? 0,
                    'trend_id'  => $e['trend_id'] ?? 0,
                    'site'      => $e['site'] ?? '',
                    'url'       => $e['url'] ?? '',
                    'surface'   => $e['surface'] ?? '',
                    'clicks_total'      => 0,
                    'impressions_total' => 0,
                    'd1_clicks' => null, 'd3_clicks' => null, 'd7_clicks' => null, 'd30_clicks' => null,
                    'd1_impr'   => null, 'd3_impr'   => null, 'd7_impr'   => null, 'd30_impr'   => null,
                    'ultima_position' => null,
                    'ultima_ctr'      => null,
                    'snapshots'       => 0,
                ];
            }
            $a = &$agg[$key];
            $a['clicks_total']      += (int)($e['clicks'] ?? 0);
            $a['impressions_total'] += (int)($e['impressions'] ?? 0);
            $a['snapshots']++;
            $a['ultima_position'] = $e['position'] ?? $a['ultima_position'];
            $a['ultima_ctr']      = $e['ctr']      ?? $a['ultima_ctr'];

            $offset = (int)($e['day_offset'] ?? -1);
            // d1/d3/d7/d30 = clicks acumulados ATÉ o dia X
            // Aqui pega o snapshot DO dia X. Pra "acumulado", caller pode somar manualmente.
            foreach ([1, 3, 7, 30] as $d) {
                if ($offset === $d) {
                    $a["d{$d}_clicks"] = (int)($e['clicks'] ?? 0);
                    $a["d{$d}_impr"]   = (int)($e['impressions'] ?? 0);
                }
            }
            unset($a);
        }
        return $agg;
    }

    // ── helpers ──

    private static function indexarPorUrl(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $url = (string)($r['keys'][0] ?? '');
            if ($url === '') continue;
            $out[$url] = $r;
        }
        return $out;
    }

    private static function lookupUrl(array $index, string $url): ?array
    {
        // Match exato primeiro; depois tolerância a trailing slash
        if (isset($index[$url])) return $index[$url];
        $alt = rtrim($url, '/');
        if (isset($index[$alt])) return $index[$alt];
        if (isset($index[$alt . '/'])) return $index[$alt . '/'];
        return null;
    }
}
