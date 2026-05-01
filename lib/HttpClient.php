<?php
/**
 * HttpClient — wrapper cURL com timeout duro, retry inteligente e logging consistente.
 *
 * Resolve 3 dores em produção:
 *   1. Timeouts altos (default 120s do Sonnet) podem travar o cron se conexão pendura
 *   2. Erros transitórios (429 rate limit, 502/503/504, network blip) precisam retry
 *   3. Erros permanentes (401, 400 invalid request) NÃO devem retry — só desperdiça
 *
 * Uso típico:
 *   $r = HttpClient::post($url, ['x' => 1], [
 *       'json' => true,                  // body como JSON (vs form-urlencoded)
 *       'headers' => ['Authorization: Bearer ...'],
 *       'timeout' => 30,                 // segundos por tentativa
 *       'tries' => 3,                    // tentativas (0s, 2s, 5s entre)
 *       'retry_on' => [429, 500, 502, 503, 504, 0], // 0 = network/timeout
 *   ]);
 *   if ($r['ok']) { ... usa $r['json'] ou $r['body'] }
 *   else { ... $r['error'], $r['attempts'] }
 *
 * Helpers de conveniência:
 *   HttpClient::getJson($url, $opts)     // GET, parse JSON
 *   HttpClient::postJson($url, $data, $opts)  // POST JSON
 *   HttpClient::postForm($url, $data, $opts)  // POST form
 */
class HttpClient
{
    /** Timeouts por categoria (segundos) — caller pode override via opts.timeout. */
    public const TIMEOUT_PADRAO     = 30;   // GET geral
    public const TIMEOUT_LLM        = 120;  // Sonnet/GPT (resposta longa)
    public const TIMEOUT_BUSCA      = 15;   // Serper, GSC query
    public const TIMEOUT_SOCIAL     = 30;   // Meta API, IG fetch
    public const TIMEOUT_INDEXACAO  = 12;   // IndexNow

    /** Status codes considerados transitórios (re-tentar). 0 = network/timeout. */
    public const RETRY_TRANSITORIOS = [0, 408, 429, 500, 502, 503, 504];

    /**
     * Faz request HTTP. Auto-retry pra erros transitórios.
     *
     * @param string $method GET|POST|PUT|DELETE
     * @param string $url
     * @param array $opts {
     *   body?: string|array,           # raw, ou array → form-urlencoded
     *   json?: bool|array,             # true = body é JSON-encoded; array = body data direto JSON-encoded
     *   headers?: array<string>,       # ['Header: Value', ...]
     *   timeout?: int,                 # segundos por tentativa (default TIMEOUT_PADRAO)
     *   tries?: int,                   # nº tentativas (default 3)
     *   backoff?: array<int>,          # delays em segundos por tentativa (default [0, 2, 5])
     *   retry_on?: array<int>,         # status codes que disparam retry (default RETRY_TRANSITORIOS)
     *   user_agent?: string,
     *   follow?: bool,                 # follow redirects (default true)
     * }
     * @return array {
     *   ok: bool,                      # http 2xx
     *   http_code: int,
     *   body: string,
     *   json: array|null,              # tentativa de parse JSON
     *   headers_response: array,
     *   error: string|null,            # mensagem se !ok
     *   attempts: int,                 # quantas tentativas foram feitas
     *   total_ms: int,
     * }
     */
    public static function request(string $method, string $url, array $opts = []): array
    {
        $timeout = (int)($opts['timeout'] ?? self::TIMEOUT_PADRAO);
        $tries = max(1, (int)($opts['tries'] ?? 3));
        $backoff = $opts['backoff'] ?? [0, 2, 5];
        $retryOn = $opts['retry_on'] ?? self::RETRY_TRANSITORIOS;
        $userAgent = (string)($opts['user_agent'] ?? 'Mozilla/5.0 ClonaisHttp/1.0');
        $follow = !isset($opts['follow']) || !empty($opts['follow']);

        $body = $opts['body'] ?? null;
        $headers = $opts['headers'] ?? [];

        // Se 'json' é array, é o body. Se é true, $body já está e precisa ser JSON-encoded
        if (isset($opts['json'])) {
            if (is_array($opts['json'])) {
                $body = json_encode($opts['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            } elseif ($opts['json'] === true && is_array($body)) {
                $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            }
        } elseif (is_array($body)) {
            // Form-urlencoded por default quando body é array
            $body = http_build_query($body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $tInicio = microtime(true);
        $resposta = ['ok' => false, 'http_code' => 0, 'body' => '', 'json' => null, 'headers_response' => [], 'error' => null, 'attempts' => 0, 'total_ms' => 0];

        for ($i = 0; $i < $tries; $i++) {
            $delay = $backoff[$i] ?? end($backoff);
            if ($delay > 0) sleep($delay);
            $resposta['attempts']++;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => $follow,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER         => false,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            ]);
            if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            // Captura headers de resposta
            $rawResponseHeaders = '';
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $h) use (&$rawResponseHeaders) {
                $rawResponseHeaders .= $h;
                return strlen($h);
            });

            $respBody = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
            curl_close($ch);

            $resposta['http_code'] = $code;
            $resposta['body'] = is_string($respBody) ? $respBody : '';
            $resposta['headers_response'] = self::parseResponseHeaders($rawResponseHeaders);

            // Network error (timeout, DNS, etc) → code = 0
            if ($respBody === false || $errno !== 0) {
                $resposta['error'] = "cURL erro {$errno}: {$errmsg}";
                if (in_array(0, $retryOn, true) && $i < $tries - 1) continue;
                break;
            }

            // 2xx — sucesso
            if ($code >= 200 && $code < 300) {
                $resposta['ok'] = true;
                $resposta['error'] = null;
                $resposta['json'] = self::tentarParseJson($resposta['body']);
                break;
            }

            // 3xx — redirect (mas com follow ativado já foi)
            // 4xx — erro permanente (NÃO retry) exceto 408/429
            // 5xx — erro servidor (transitório)
            $resposta['error'] = "HTTP {$code}: " . substr($resposta['body'], 0, 300);
            $resposta['json'] = self::tentarParseJson($resposta['body']);
            if (in_array($code, $retryOn, true) && $i < $tries - 1) continue;
            break;
        }

        $resposta['total_ms'] = (int)((microtime(true) - $tInicio) * 1000);
        return $resposta;
    }

    /** Atalho: GET. */
    public static function get(string $url, array $opts = []): array
    {
        return self::request('GET', $url, $opts);
    }

    /** Atalho: GET + parse JSON. Returns null em falha. */
    public static function getJson(string $url, array $opts = []): ?array
    {
        $r = self::get($url, $opts);
        return $r['ok'] ? ($r['json'] ?? null) : null;
    }

    /** Atalho: POST com body raw, form-urlencoded ou json (auto-detect). */
    public static function post(string $url, $body = null, array $opts = []): array
    {
        if ($body !== null) $opts['body'] = $body;
        return self::request('POST', $url, $opts);
    }

    /** Atalho: POST JSON body. */
    public static function postJson(string $url, array $data, array $opts = []): array
    {
        $opts['json'] = $data;
        return self::request('POST', $url, $opts);
    }

    /** Atalho: POST form-urlencoded. */
    public static function postForm(string $url, array $data, array $opts = []): array
    {
        $opts['body'] = $data;  // será auto-encoded por http_build_query
        return self::request('POST', $url, $opts);
    }

    /** Atalho: PUT. */
    public static function put(string $url, $body = null, array $opts = []): array
    {
        if ($body !== null) $opts['body'] = $body;
        return self::request('PUT', $url, $opts);
    }

    /** Atalho: DELETE. */
    public static function delete(string $url, array $opts = []): array
    {
        return self::request('DELETE', $url, $opts);
    }

    // ─────────── INTERNOS ───────────

    private static function tentarParseJson(string $body): ?array
    {
        if ($body === '') return null;
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }

    private static function parseResponseHeaders(string $raw): array
    {
        $out = [];
        $linhas = preg_split("/\r\n|\n|\r/", trim($raw));
        foreach ($linhas as $linha) {
            if (strpos($linha, ':') === false) continue;
            [$k, $v] = explode(':', $linha, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
        return $out;
    }
}
