<?php
/**
 * Google Trends — scraping direto do endpoint interno (batchexecute).
 *
 * Alvo: https://trends.google.com.br/trending?geo=BR&hours=168&sort=search-volume
 * A página é SPA React; o frontend chama o RPC `i0OFE` em:
 *   POST /_/TrendsUi/data/batchexecute?rpcids=i0OFE
 *
 * Esta classe reproduz essa chamada sem dependência de JS headless.
 * Retorna: termo, volume aproximado, categorias, notícias relacionadas, timestamps.
 */
require_once __DIR__ . '/TrendsTaxonomia.php';

class TrendsScraperWeb
{
    private string $userAgent;
    private string $cookieJar;
    private bool $sessionPrimed = false;
    public ?string $lastRawResponse = null;
    public ?string $lastRpcPayload  = null;
    public ?string $lastEndpoint    = null;
    public int $lastHttpCode = 0;

    public function __construct(string $userAgent = '')
    {
        $this->userAgent = $userAgent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->cookieJar = sys_get_temp_dir() . '/trends_cookies_' . substr(md5($this->userAgent), 0, 8) . '.txt';
    }

    /**
     * Aquece a sessão visitando trends.google.com para receber cookies (NID, etc.)
     * Sem isso, o endpoint /trends/api/explore devolve HTTP 429 quase imediatamente.
     */
    private function primeSession(): void
    {
        if ($this->sessionPrimed) return;
        $ch = curl_init('https://trends.google.com/trends/explore?geo=BR&hl=pt-BR');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
        $this->sessionPrimed = true;
    }

    /**
     * Busca trends.
     * @param int    $hours 4 ou 168
     * @param string $sort  'search-volume' ou 'recency'
     * @param string $geo   BR, US...
     * @return array lista de trends normalizados
     */
    public function buscar(int $hours = 168, string $sort = 'search-volume', string $geo = 'BR'): array
    {
        $sortCode = $sort === 'recency' ? 1 : 0; // 0 = search-volume (default da UI)
        $rpcArgs  = [null, null, $geo, $sortCode, null, $hours];
        $rpcArgsJson = json_encode($rpcArgs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $fReq = json_encode([[
            ["i0OFE", $rpcArgsJson, null, "generic"]
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->lastRpcPayload = $fReq;

        $url = 'https://trends.google.com.br/_/TrendsUi/data/batchexecute'
             . '?rpcids=i0OFE'
             . '&source-path=%2Ftrending'
             . '&f.sid=-1'
             . '&bl=boq_trends-frontend-ui_20241021.06_p0'
             . '&hl=pt-BR'
             . '&gl=' . urlencode($geo)
             . '&soc-app=162'
             . '&soc-platform=1'
             . '&soc-device=1'
             . '&_reqid=' . rand(100000, 999999)
             . '&rt=c';

        $this->lastEndpoint = $url;

        $body = 'f.req=' . urlencode($fReq);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
                'Accept: */*',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                'Origin: https://trends.google.com.br',
                'Referer: https://trends.google.com.br/trending?geo=' . $geo . '&hours=' . $hours . '&sort=' . $sort,
                'X-Same-Domain: 1',
            ],
        ]);
        $resp = curl_exec($ch);
        $this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $this->lastRawResponse = is_string($resp) ? $resp : null;

        if ($resp === false) {
            throw new RuntimeException("cURL falhou: {$err}");
        }
        if ($this->lastHttpCode >= 400) {
            throw new RuntimeException("HTTP {$this->lastHttpCode} no batchexecute");
        }

        return $this->parseBatchExecute($resp);
    }

    /**
     * Resposta do batchexecute tem o formato:
     *   )]}'\n
     *   <len1>\n
     *   <bloco JSON de <len1> bytes>
     *   <len2>\n
     *   <bloco JSON de <len2> bytes>
     *   ...
     * Cada bloco é um array; o bloco que nos interessa começa com [["wrb.fr","i0OFE",...]].
     * O terceiro elemento é uma string JSON que contém a lista real de trends.
     */
    private function parseBatchExecute(string $raw): array
    {
        // Remove prefixo de segurança do Google
        $body = preg_replace('/^\)\]\}\'\s*/', '', $raw);

        $innerJson = $this->extractRpcResultFromChunks($body, 'i0OFE');

        if ($innerJson === null) {
            throw new RuntimeException('Não encontrei resultado do RPC i0OFE na resposta.');
        }

        $decoded = json_decode($innerJson, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON interno do i0OFE inválido.');
        }

        return $this->normalizeTrends($decoded);
    }

    /**
     * Lê os chunks ( <len>\n<blob> ) e localiza o array wrb.fr do rpcId alvo.
     * O size anunciado pelo Google é em CODE POINTS (não bytes) e às vezes vem
     * levemente off; por isso truncamos no último "]]" válido do chunk.
     */
    private function extractRpcResultFromChunks(string $body, string $rpcId): ?string
    {
        // Posição em BYTES (para substr/strpos) — tamanho declarado é em CHARS.
        $pos = 0;
        $len = strlen($body);

        while ($pos < $len) {
            // Pula whitespace
            while ($pos < $len && ctype_space($body[$pos])) $pos++;
            if ($pos >= $len) break;

            // Lê o tamanho (dígitos até \n)
            $nlPos = strpos($body, "\n", $pos);
            if ($nlPos === false) break;
            $sizeStr = trim(substr($body, $pos, $nlPos - $pos));
            if (!ctype_digit($sizeStr)) break;
            $chunkCharSize = (int)$sizeStr;
            $chunkStartBytes = $nlPos + 1;

            // Posição em chars até o início do chunk
            $startChars = mb_strlen(substr($body, 0, $chunkStartBytes), 'UTF-8');
            $chunk = mb_substr($body, $startChars, $chunkCharSize, 'UTF-8');

            // Corrige off-by: truncar no último "]]" para obter JSON válido.
            $lastClose = mb_strrpos($chunk, ']]', 0, 'UTF-8');
            if ($lastClose !== false) {
                $chunk = mb_substr($chunk, 0, $lastClose + 2, 'UTF-8');
            }

            $arr = json_decode($chunk, true);

            // Avança pos em BYTES (strlen do chunk truncado + header + possível newline)
            $pos = $chunkStartBytes + strlen(mb_substr($body, $startChars, $chunkCharSize, 'UTF-8'));

            if (!is_array($arr)) continue;

            // Procura ["wrb.fr", "<rpcId>", "<json-string>", ...]
            foreach ($arr as $entry) {
                if (is_array($entry)
                    && ($entry[0] ?? '') === 'wrb.fr'
                    && ($entry[1] ?? '') === $rpcId
                    && is_string($entry[2] ?? null)) {
                    return $entry[2];
                }
            }
        }
        return null;
    }

    /**
     * Normaliza o shape bruto do i0OFE.
     *
     * Shape observado:
     *   [null, [ trend1, trend2, ... ]]
     *
     * Cada trend é um array posicional:
     *   [0]  string termo
     *   [1]  null
     *   [2]  string país (BR)
     *   [3]  [int timestamp_inicio]
     *   [4]  [int timestamp_fim] | null
     *   [5]  null
     *   [6]  int volume aproximado
     *   [7]  null
     *   [8]  int growth% / multiplicador
     *   [9]  [string...] consultas relacionadas (1ª é o próprio termo)
     *   [10] [int...] IDs de categoria
     *   [11] [[int article_id, string lang, string country], ...] refs de artigos
     *   [12] string termo (repetido)
     */
    private function normalizeTrends(array $data): array
    {
        $trendsRaw = $data[1] ?? null;
        if (!is_array($trendsRaw)) return [];

        $list = [];
        foreach ($trendsRaw as $t) {
            if (!is_array($t) || !isset($t[0]) || !is_string($t[0])) continue;

            $termo       = $t[0];
            $startedTs   = is_array($t[3] ?? null) ? (int)($t[3][0] ?? 0) : 0;
            $endedTs     = is_array($t[4] ?? null) ? (int)($t[4][0] ?? 0) : 0;
            $volumeNum   = is_int($t[6] ?? null) ? (int)$t[6] : 0;
            $growth      = is_int($t[8] ?? null) ? (int)$t[8] : 0;
            $relacionados= is_array($t[9] ?? null) ? array_values(array_filter($t[9], 'is_string')) : [];
            // tira o próprio termo da lista de relacionados
            $relacionados= array_values(array_filter($relacionados, fn($r) => mb_strtolower($r) !== mb_strtolower($termo)));
            $catIds      = is_array($t[10] ?? null) ? array_values(array_filter($t[10], 'is_int')) : [];
            $artigos     = is_array($t[11] ?? null) ? count($t[11]) : 0;
            $artigosRefs = is_array($t[11] ?? null) ? array_slice($t[11], 0, 5) : [];

            // Status/duração (espelha Google Trends UI):
            // - ativa: sem endedTs (ou endedTs no futuro)
            // - durou N min/h: tem endedTs passado → mostra duração
            $agoraTs = time();
            $ativa   = ($endedTs === 0 || $endedTs > $agoraTs);
            $duracaoMin = ($endedTs > $startedTs) ? max(1, (int)round(($endedTs - $startedTs) / 60)) : 0;

            $list[] = [
                'termo'         => $termo,
                'volume_num'    => $volumeNum,
                'volume_label'  => $volumeNum ? self::formatVolume($volumeNum) : '',
                'growth_pct'    => $growth,
                'iniciado_ts'   => $startedTs,
                'iniciado_em'   => $startedTs ? date('Y-m-d H:i', $startedTs) : '',
                'iniciado_rel'  => $startedTs ? self::tempoRelativo($startedTs) : '',
                'terminado_ts'  => $endedTs,
                'terminado_em'  => $endedTs   ? date('Y-m-d H:i', $endedTs)   : '',
                'ativa'         => $ativa,
                'duracao_min'   => $duracaoMin,
                'duracao_label' => $ativa ? 'Ativa' : ('Durou ' . self::formatDuracao($duracaoMin)),
                'categorias'    => array_map([self::class, 'mapCategoria'], $catIds),
                'categoria_ids' => $catIds,
                'relacionados'  => $relacionados,
                'noticias_qtd'  => $artigos,
                'noticias_ids'  => $artigosRefs,
            ];
        }

        return $list;
    }

    /**
     * Compatibilidade: mantemos a propriedade estática pública apontando para
     * TrendsTaxonomia::CATEGORIAS_GOOGLE, que é a fonte única de verdade.
     * Código legado que lê TrendsScraperWeb::$categoriasMap continua funcionando.
     */
    public static array $categoriasMap;

    private static function mapCategoria(int $id): string
    {
        return TrendsTaxonomia::labelCategoriaGoogle($id);
    }

    /**
     * "há 20 minutos", "há 1 hora", "há 2 dias" — espelha o Google Trends UI.
     */
    public static function tempoRelativo(int $ts): string
    {
        if ($ts <= 0) return '';
        $diff = max(0, time() - $ts);
        if ($diff < 60)        return 'agora';
        if ($diff < 3600)      return 'há ' . (int)($diff / 60) . ' min';
        if ($diff < 86400)     return 'há ' . (int)($diff / 3600) . ' h';
        if ($diff < 86400 * 7) return 'há ' . (int)($diff / 86400) . ' d';
        return date('d/m', $ts);
    }

    /** "50 min", "2 h", "1 dia" — duração de trend encerrado. */
    public static function formatDuracao(int $minutos): string
    {
        if ($minutos < 60)   return $minutos . ' min';
        if ($minutos < 1440) return round($minutos / 60, 1) . ' h';
        return round($minutos / 1440, 1) . ' dia(s)';
    }

    public static function formatVolume(int $n): string
    {
        if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M+';
        if ($n >= 1_000)     return round($n / 1_000) . 'K+';
        return (string)$n;
    }

    /**
     * Busca "Consultas mais frequentes" (TOP) e "Consultas em alta" (RISING)
     * para um termo específico, via endpoint /trends/api/explore + /relatedsearches.
     *
     * @param string $termo
     * @param int    $hours  4 ou 168 — traduzido para 'now 4-H' ou 'now 7-d'
     * @param string $geo
     * @return array ['top' => [{query,value,formatted}], 'rising' => [...]]
     */
    public function consultasRelacionadas(string $termo, int $hours = 168, string $geo = 'BR'): array
    {
        $timeRange = $hours === 4 ? 'now 4-H' : 'now 7-d';
        return $this->relatedQueriesByTime($termo, $timeRange, $geo);
    }

    /**
     * Consultas relacionadas (TOP + RISING) de um termo num período HISTÓRICO.
     * Aceita intervalo de datas no formato YYYY-MM-DD.
     *
     * Use case: "quais subconsultas explodiram em torno de 'black friday' em nov/2024?"
     * → conteúdo pronto antes do próximo ciclo sazonal.
     */
    public function consultasHistoricas(string $termo, string $dataInicio, string $dataFim, string $geo = 'BR'): array
    {
        $dataInicio = $this->normalizaData($dataInicio);
        $dataFim    = $this->normalizaData($dataFim);
        if (!$dataInicio || !$dataFim) {
            throw new RuntimeException('Datas inválidas (formato esperado YYYY-MM-DD).');
        }
        if ($dataInicio > $dataFim) {
            throw new RuntimeException('Data início não pode ser maior que data fim.');
        }
        $timeRange = $dataInicio . ' ' . $dataFim;
        return $this->relatedQueriesByTime($termo, $timeRange, $geo);
    }

    private function relatedQueriesByTime(string $termo, string $timeRange, string $geo): array
    {
        $this->primeSession();

        $exploreReq = [
            'comparisonItem' => [[
                'keyword' => $termo,
                'geo'     => $geo,
                'time'    => $timeRange,
            ]],
            'category' => 0,
            'property' => '',
        ];
        $exploreUrl = 'https://trends.google.com/trends/api/explore'
            . '?hl=pt-BR&tz=180'
            . '&req=' . rawurlencode(json_encode($exploreReq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . '&tz=180';

        $exploreRaw = $this->httpGet($exploreUrl);
        $explore = json_decode($this->stripXssiPrefix($exploreRaw), true);
        if (!is_array($explore) || empty($explore['widgets'])) {
            throw new RuntimeException('Explore sem widgets para "' . $termo . '" em ' . $timeRange);
        }

        $widget = null;
        foreach ($explore['widgets'] as $w) {
            if (($w['id'] ?? '') === 'RELATED_QUERIES') { $widget = $w; break; }
        }
        if (!$widget || empty($widget['token']) || empty($widget['request'])) {
            throw new RuntimeException('Widget RELATED_QUERIES ausente — o termo pode ter buscas insuficientes nesse período.');
        }

        $rsUrl = 'https://trends.google.com/trends/api/widgetdata/relatedsearches'
            . '?hl=pt-BR&tz=180'
            . '&req=' . rawurlencode(json_encode($widget['request'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . '&token=' . rawurlencode($widget['token']);

        $rs = json_decode($this->stripXssiPrefix($this->httpGet($rsUrl)), true);
        if (!is_array($rs)) {
            throw new RuntimeException('Resposta relatedsearches inválida.');
        }

        $ranked = $rs['default']['rankedList'] ?? [];
        return [
            'top'        => $this->parseRanked($ranked[0]['rankedKeyword'] ?? []),
            'rising'     => $this->parseRanked($ranked[1]['rankedKeyword'] ?? []),
            'time_range' => $timeRange,
        ];
    }

    private function normalizaData(string $d): ?string
    {
        $d = trim($d);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $ts = strtotime($d);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    private function parseRanked(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!isset($it['query'])) continue;
            $out[] = [
                'query'     => (string)$it['query'],
                'value'     => (int)($it['value'] ?? 0),
                'formatted' => (string)($it['formattedValue'] ?? ''),
                'link'      => isset($it['link']) ? 'https://trends.google.com' . $it['link'] : '',
            ];
        }
        return $out;
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                'Referer: https://trends.google.com/trends/explore',
                'X-Client-Data: CIW2yQEIorbJAQipncoBCKijygE=',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException("cURL: {$err}");
        if ($code === 429) {
            throw new RuntimeException('HTTP 429 — Google Trends rate-limit. Aguarde 2-5 min e tente de novo (o limite é por IP + endpoint).');
        }
        if ($code === 404) {
            throw new RuntimeException('HTTP 404 — o termo "' . '" pode não ter volume suficiente no período escolhido, ou o período é muito antigo.');
        }
        if ($code >= 400)    throw new RuntimeException("HTTP {$code} no endpoint Trends");
        return (string)$resp;
    }

    /** Remove prefixo anti-XSSI `)]}',` que o Trends prepende nas respostas JSON. */
    private function stripXssiPrefix(string $body): string
    {
        return (string)preg_replace('/^\)\]\}\'\s*,?\s*/', '', $body);
    }
}

// Inicialização lazy da compat $categoriasMap a partir da taxonomia.
TrendsScraperWeb::$categoriasMap = TrendsTaxonomia::CATEGORIAS_GOOGLE;
