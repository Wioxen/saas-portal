<?php
/**
 * ClickLog — espelho local de cc-click-logger (plugin WP) em JSONL mensal.
 *
 * Sem isso: clicks ficam SÓ no DB do WP de cada site (6 sites = 6 fontes desconectadas).
 * Com isso: SaaS tem snapshot consolidado, agregável com PostPerformanceLog pra cálculo
 * de funnel: post_view → click → (mais tarde) sale.
 *
 * Estrutura JSONL: data/click_log/{YYYY-MM}.jsonl
 *   Cada linha: {ts, ts_iso, site, slug, post_id, ip_hash, ua_hash, referer_hash, source_id}
 *
 * Sync incremental:
 *   - Estado por site em data/click_log/_state.json: {site: last_synced_id}
 *   - Pull /wp-json/cc/v1/clicks/recent?since={last_id}
 *   - Append entries ao JSONL do mês (UTC, baseado em ts)
 *   - Atualiza last_id no state
 *
 * Uso (cron diário):
 *   $cl = new ClickLog();
 *   $r = $cl->sincronizar('cursosenac', $cfg);  // {ok, novos, last_id}
 *
 * Uso (relatório):
 *   $entries = ClickLog::lerLog('2026-04', ['site' => 'cursosenac']);
 *   $clicksPorPost = ClickLog::clicksPorPost($entries);  // [post_id => count]
 */
class ClickLog
{
    private const PATH_DEF  = '/../data/click_log';
    private const STATE_FILE = '_state.json';

    private string $baseDir;
    private string $statePath;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? __DIR__ . self::PATH_DEF;
        if (!is_dir($this->baseDir)) @mkdir($this->baseDir, 0777, true);
        $this->statePath = $this->baseDir . '/' . self::STATE_FILE;
    }

    /**
     * Sincroniza um site (pull paginado até esvaziar).
     *
     * @param string $siteSlug
     * @param array  $cfg     {wp_url, wp_user, wp_app_password}
     * @param int    $maxBatches limite de páginas (proteção, default 10 = 50000 events)
     * @return array {ok, site, novos, last_id, paginas, erros}
     */
    public function sincronizar(string $siteSlug, array $cfg, int $maxBatches = 10): array
    {
        $wpUrl = rtrim((string)($cfg['wp_url'] ?? ''), '/');
        $user  = (string)($cfg['wp_user'] ?? '');
        $pass  = (string)($cfg['wp_app_password'] ?? '');
        if ($wpUrl === '' || $user === '' || $pass === '') {
            return ['ok' => false, 'erro' => 'cfg incompleta', 'site' => $siteSlug];
        }

        $state = $this->loadState();
        $sinceIdInicial = (int)($state[$siteSlug] ?? 0);
        $sinceId = $sinceIdInicial;

        $endpoint = $wpUrl . '/wp-json/cc/v1/clicks/recent';
        $auth = base64_encode($user . ':' . $pass);

        $totalNovos = 0;
        $paginas = 0;
        $erros = [];
        $sucessoCompleto = false;
        // last_id avança SOMENTE em batches confirmados (events foram appendados E http OK).
        $lastIdConfirmado = $sinceIdInicial;
        $lastIdCorrente   = $sinceIdInicial;

        for ($i = 0; $i < $maxBatches; $i++) {
            $url = $endpoint . '?since=' . $lastIdCorrente . '&limit=500';
            $resp = $this->httpGet($url, $auth);
            if ($resp === null) {
                $erros[] = "falha pull em since={$lastIdCorrente} batch=" . ($paginas + 1);
                break; // NÃO avança lastIdConfirmado — próximo run re-tenta a partir do último confirmado
            }
            $events = $resp['events'] ?? [];
            $paginas++;
            if (empty($events)) {
                // Sem events → sincronia em dia. Confirma o lastId atual.
                $lastIdConfirmado = $lastIdCorrente;
                $sucessoCompleto = true;
                break;
            }

            // Append PRIMEIRO; só depois avança last_id (se append falhar, próximo run retenta)
            try {
                $this->appendJsonl($events, $siteSlug);
            } catch (Throwable $e) {
                $erros[] = "appendJsonl falhou: " . $e->getMessage();
                break;
            }
            $totalNovos += count($events);
            $lastIdCorrente = (int)($resp['next_since'] ?? $lastIdCorrente);
            $lastIdConfirmado = $lastIdCorrente;

            if (empty($resp['has_more'])) {
                $sucessoCompleto = true;
                break;
            }
        }

        // Persiste state SOMENTE com lastIdConfirmado. Se loop quebrou no meio (rede caiu),
        // próximo run retenta a partir do último batch CONFIRMADO — zero perda de events.
        if ($lastIdConfirmado !== $sinceIdInicial) {
            $state[$siteSlug] = $lastIdConfirmado;
            $state['_updated_at'] = date('c');
            $this->saveState($state);
        }

        return [
            'ok'                => empty($erros) && $sucessoCompleto,
            'site'              => $siteSlug,
            'novos'             => $totalNovos,
            'last_id'           => $lastIdConfirmado,
            'last_id_inicial'   => $sinceIdInicial,
            'paginas'           => $paginas,
            'sucesso_completo'  => $sucessoCompleto,
            'erros'             => $erros,
        ];
    }

    /**
     * Lê JSONL de um mês com filtros.
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
            if (isset($filtros['site']) && ($e['site'] ?? '') !== $filtros['site']) continue;
            if (isset($filtros['post_id']) && (int)($e['post_id'] ?? 0) !== (int)$filtros['post_id']) continue;
            if (isset($filtros['slug']) && ($e['slug'] ?? '') !== $filtros['slug']) continue;
            if (isset($filtros['since_ts']) && (int)($e['ts'] ?? 0) < $filtros['since_ts']) continue;
            $entries[] = $e;
        }
        @fclose($fp);
        return $entries;
    }

    /**
     * Agrupa clicks por post_id. Retorna [post_id => count].
     * Conta clicks únicos por (post_id × ip_hash × dia) pra deduplicar bots leves.
     */
    public static function clicksPorPost(array $entries, bool $unicos = true, string $timezone = 'America/Sao_Paulo'): array
    {
        $contador = [];
        $vistos = []; // chave: pid|ip|dia
        // TZ explícita: ts é UTC (time()), mas dia precisa ser o dia BRASILEIRO do clique.
        // Sem isso, click às 23:59 BRT (= 02:59 UTC dia+1) virava dia diferente do click às 22:00 BRT
        // do mesmo usuário → dupla contagem no mesmo dia real.
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('UTC');
        }
        foreach ($entries as $e) {
            $pid = (int)($e['post_id'] ?? 0);
            if ($pid === 0) continue;
            if ($unicos) {
                $ts = (int)($e['ts'] ?? 0);
                $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
                $dia = $dt->format('Y-m-d');
                $chave = $pid . '|' . ($e['ip_hash'] ?? '') . '|' . $dia;
                if (isset($vistos[$chave])) continue;
                $vistos[$chave] = true;
            }
            $contador[$pid] = ($contador[$pid] ?? 0) + 1;
        }
        return $contador;
    }

    /** Top N posts por clicks. */
    public static function topPosts(array $entries, int $n = 10): array
    {
        $cont = self::clicksPorPost($entries, true);
        arsort($cont);
        $out = [];
        $i = 0;
        foreach ($cont as $pid => $clicks) {
            $out[] = ['post_id' => $pid, 'clicks' => $clicks];
            if (++$i >= $n) break;
        }
        return $out;
    }

    // ── helpers ──

    private function appendJsonl(array $events, string $siteSlug): void
    {
        // Agrupa por mês (ts)
        $porMes = [];
        foreach ($events as $e) {
            $ts = (int)($e['ts'] ?? time());
            $mes = date('Y-m', $ts);
            $linha = [
                'ts'           => $ts,
                'ts_iso'       => date('c', $ts),
                'site'         => $siteSlug,
                'slug'         => (string)($e['slug'] ?? ''),
                'post_id'      => isset($e['post_id']) ? (int)$e['post_id'] : null,
                'ip_hash'      => $e['ip_hash'] ?? null,
                'ua_hash'      => $e['ua_hash'] ?? null,
                'referer_hash' => $e['referer_hash'] ?? null,
                'source_id'    => (int)($e['id'] ?? 0),
            ];
            $porMes[$mes][] = json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        foreach ($porMes as $mes => $linhas) {
            $file = $this->baseDir . '/' . $mes . '.jsonl';
            $fp = @fopen($file, 'ab');
            if (!$fp) continue;
            foreach ($linhas as $l) @fwrite($fp, $l . "\n");
            @fclose($fp);
        }
    }

    private function loadState(): array
    {
        if (!is_file($this->statePath)) return [];
        $raw = @file_get_contents($this->statePath);
        $d = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return is_array($d) ? $d : [];
    }

    private function saveState(array $state): void
    {
        require_once __DIR__ . '/JsonStore.php';
        JsonStore::write($this->statePath, $state, 3, true);
    }

    private function httpGet(string $url, string $auth): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) return null;
        $j = json_decode((string)$resp, true);
        return is_array($j) ? $j : null;
    }
}
