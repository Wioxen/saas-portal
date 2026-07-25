<?php
/**
 * Cliente para Serper.dev — wrapper simples sobre os endpoints /search e /shopping.
 * Docs: https://serper.dev/playground
 */
class Serper
{
    private string $apiKey;
    private string $base = 'https://google.serper.dev';

    /** @var string|null Chave secundária usada automaticamente se a primária retornar 401/402/403/429. */
    private ?string $fallbackKey = null;
    public function __construct(string $apiKey, ?string $fallbackKey = null)
    {
        $this->apiKey = $apiKey;
        // BUG ATÉ 2026-07-22: o parâmetro era aceito e descartado, então o fallback só
        // funcionava quando a chave secundária estava em SERPER_API_KEY_FALLBACK (ver o
        // getenv() no post()). Chamador que passasse a chave por parâmetro caía sem
        // fallback, justo no cenário "chave primária sem crédito".
        $fallbackKey = $fallbackKey !== null ? trim($fallbackKey) : null;
        $this->fallbackKey = ($fallbackKey === '' ) ? null : $fallbackKey;
    }

    /** Busca orgânica do Google (BR/PT). */
    public function search(string $query, int $num = 10): array
    {
        return $this->post('/search', [
            'q'  => $query,
            'gl' => 'br',
            'hl' => 'pt-br',
            'num' => $num,
        ]);
    }

    /**
     * Uma chamada /search com os blocos RICOS do SERP normalizados (pra planejamento de
     * hub/cluster): organic, People Also Ask, relatedSearches, knowledgeGraph, answerBox
     * (featured snippet) e topStories. Tudo num único request (cacheado).
     */
    public function searchRich(string $query, int $num = 10): array
    {
        $r = $this->search($query, $num);
        return [
            'organic'         => $r['organic'] ?? [],
            'peopleAlsoAsk'   => $r['peopleAlsoAsk'] ?? [],
            'relatedSearches' => $r['relatedSearches'] ?? [],
            'knowledgeGraph'  => $r['knowledgeGraph'] ?? null,
            'answerBox'       => $r['answerBox'] ?? null,
            'topStories'      => $r['topStories'] ?? [],
        ];
    }

    /**
     * Verifica se uma URL específica está indexada no Google (query site:URL).
     * Retorna true se aparece em qualquer resultado orgânico.
     */
    public function checarIndexacao(string $url): bool
    {
        $limpa = rtrim($url, '/');
        try {
            $resp = $this->search('site:' . $limpa, 5);
        } catch (Throwable $e) {
            return false;
        }
        $organicos = $resp['organic'] ?? [];
        foreach ($organicos as $o) {
            $link = rtrim((string)($o['link'] ?? ''), '/');
            if ($link === '') continue;
            if (strcasecmp($link, $limpa) === 0) return true;
            if (stripos($link, $limpa) !== false) return true;
        }
        return false;
    }

    /** Google Shopping — retorna produtos com preço, imagem, fonte e link. */
    public function shopping(string $query, int $num = 20): array
    {
        return $this->post('/shopping', [
            'q'  => $query,
            'gl' => 'br',
            'hl' => 'pt-br',
            'num' => $num,
        ]);
    }

    /** Autocomplete — sugestões de busca do Google. */
    public function autocomplete(string $query): array
    {
        return $this->post('/autocomplete', [
            'q'  => $query,
            'gl' => 'br',
            'hl' => 'pt-br',
        ]);
    }

    /**
     * Related searches + People Also Ask — filtrado por período.
     * @param string $tbs  Filtro de tempo: qdr:h (1h), qdr:h4 (4h), qdr:d (24h), qdr:w (7d), qdr:m (30d), qdr:y (1 ano)
     */
    public function relatedSearches(string $query, string $tbs = ''): array
    {
        $payload = [
            'q'  => $query,
            'gl' => 'br',
            'hl' => 'pt-br',
            'num' => 10,
        ];
        if ($tbs !== '') $payload['tbs'] = $tbs;
        $result = $this->post('/search', $payload);
        return [
            'related' => $result['relatedSearches'] ?? [],
            'paa'     => $result['peopleAlsoAsk'] ?? [],
            'organic' => array_slice($result['organic'] ?? [], 0, 5),
        ];
    }

    /** Períodos disponíveis pra filtro de tempo. */
    public static array $periodos = [
        ''       => 'Qualquer período',
        'qdr:h'  => 'Última hora',
        'qdr:h4' => 'Últimas 4 horas',
        'qdr:d'  => 'Últimas 24 horas',
        'qdr:w'  => 'Última semana',
        'qdr:m'  => 'Último mês',
        'qdr:y'  => 'Último ano',
    ];

    /** Notícias recentes — útil pro Google News. */
    public function news(string $query, int $num = 10): array
    {
        return $this->post('/news', [
            'q'  => $query,
            'gl' => 'br',
            'hl' => 'pt-br',
            'num' => $num,
        ]);
    }

    /**
     * Scrape de uma página (endpoint scrape.serper.dev) — retorna texto/markdown + metadata.
     * Útil p/ grounding factual direto da fonte oficial (ex.: lista de cursos na página da FGV).
     * @return array{text?:string,markdown?:string,metadata?:array}
     */
    public function webpage(string $url, bool $markdown = true): array
    {
        $payload = ['url' => $url];
        if ($markdown) $payload['includeMarkdown'] = true;
        return $this->post('/', $payload, 'https://scrape.serper.dev');
    }

    /** TTL do cache em segundos (default 24h). Override via env SERPER_CACHE_TTL. */
    private const CACHE_TTL_DEFAULT = 86400;

    /** Endpoints que NÃO devem ser cacheados (real-time relevante). */
    private const NO_CACHE_PATHS = ['/news']; // notícias precisam ser fresh

    private function post(string $path, array $payload, ?string $baseOverride = null): array
    {
        $base = $baseOverride ?? $this->base;
        // Cache hit-or-miss
        $useCache = !in_array($path, self::NO_CACHE_PATHS, true);
        $cacheKey = $useCache ? self::cacheKey($base . $path, $payload) : null;
        $cacheFile = $useCache ? self::cacheFilePath($cacheKey) : null;
        $ttl = (int)(getenv('SERPER_CACHE_TTL') ?: self::CACHE_TTL_DEFAULT);

        if ($useCache && $cacheFile !== null && is_file($cacheFile)) {
            $age = time() - (int)@filemtime($cacheFile);
            if ($age < $ttl) {
                $raw = @file_get_contents($cacheFile);
                $cached = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($cached)) {
                    self::logCacheEvent('hit', $path, strlen($raw));
                    return $cached;
                }
            }
        }

        // Helper p/ tentar com uma chave específica (permite fallback transparente)
        $tryWithKey = function (string $key) use ($base, $path, $payload) {
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $key, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return ['resp'=>$resp, 'code'=>$code, 'err'=>$err];
        };

        $r = $tryWithKey($this->apiKey);
        // Fallback automático se a chave primária esgotou/foi rejeitada.
        // Serper devolve HTTP 400 {"message":"Not enough credits"} quando o crédito acaba — por isso checamos 400+"credit" além de 401/402/403/429.
        $fb = $this->fallbackKey ?: ((string)(getenv('SERPER_API_KEY_FALLBACK') ?: ''));
        $semCredito = ($r['code'] === 400 && stripos((string)$r['resp'], 'credit') !== false);
        if ($fb !== '' && $fb !== $this->apiKey && (in_array($r['code'], [401, 402, 403, 429], true) || $semCredito)) {
            error_log("Serper: chave primária HTTP {$r['code']} (".($semCredito?'sem crédito':'rejeitada').") — usando fallback");
            $r = $tryWithKey($fb);
        }
        $resp = $r['resp']; $code = $r['code']; $err = $r['err'];

        if ($resp === false) {
            throw new RuntimeException("Serper cURL erro: $err");
        }
        if ($code !== 200) {
            throw new RuntimeException("Serper HTTP $code: $resp");
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('Serper retornou JSON inválido');
        }

        // Persist cache (somente endpoints cacheáveis E response com dados úteis)
        if ($useCache && $cacheFile !== null && !empty($data) && empty($data['error'])) {
            $dir = dirname($cacheFile);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            @file_put_contents($cacheFile, $resp, LOCK_EX);
            self::logCacheEvent('miss', $path, strlen($resp));
        }

        return $data;
    }

    private static function cacheKey(string $path, array $payload): string
    {
        // Normaliza payload pra cache (remove keys voláteis)
        ksort($payload);
        $hash = sha1($path . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $hash;
    }

    private static function cacheFilePath(?string $key): ?string
    {
        if ($key === null) return null;
        // Subdiretório de 2 chars pra evitar 1 dir gigante
        $sub = substr($key, 0, 2);
        return __DIR__ . '/../data/cache/serper/' . $sub . '/' . $key . '.json';
    }

    private static function logCacheEvent(string $tipo, string $path, int $bytes): void
    {
        $dir = __DIR__ . '/../data/cost_tracker';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $line = json_encode([
            'ts'    => date('c'),
            'tipo'  => $tipo,        // hit | miss
            'api'   => 'serper',
            'path'  => $path,
            'bytes' => $bytes,
        ]);
        @file_put_contents($dir . '/serper_cache.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
