<?php
/**
 * Cliente para Serper.dev — wrapper simples sobre os endpoints /search e /shopping.
 * Docs: https://serper.dev/playground
 */
class Serper
{
    private string $apiKey;
    private string $base = 'https://google.serper.dev';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
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

    /** TTL do cache em segundos (default 24h). Override via env SERPER_CACHE_TTL. */
    private const CACHE_TTL_DEFAULT = 86400;

    /** Endpoints que NÃO devem ser cacheados (real-time relevante). */
    private const NO_CACHE_PATHS = ['/news']; // notícias precisam ser fresh

    private function post(string $path, array $payload): array
    {
        // Cache hit-or-miss
        $useCache = !in_array($path, self::NO_CACHE_PATHS, true);
        $cacheKey = $useCache ? self::cacheKey($path, $payload) : null;
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

        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false, // XAMPP Windows
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

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
