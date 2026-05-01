<?php
/**
 * SocialBluesky — driver pra Bluesky (atproto).
 *
 * API: https://docs.bsky.app/docs/get-started
 *
 * Autenticação:
 *   1. Cria conta em bsky.app
 *   2. Em Settings → App Passwords, gera uma app password (formato xxxx-xxxx-xxxx-xxxx)
 *   3. Adiciona no .env:
 *      BLUESKY_HANDLE_LEAODABARRA=leaodabarra.bsky.social
 *      BLUESKY_APP_PASSWORD_LEAODABARRA=xxxx-xxxx-xxxx-xxxx
 *   4. Configura em sites.php:
 *      'social' => ['bluesky' => ['enabled' => true, 'handle_env' => 'BLUESKY_HANDLE_LEAODABARRA',
 *                                  'pass_env' => 'BLUESKY_APP_PASSWORD_LEAODABARRA']]
 *
 * Rate limits Bluesky: ~5000 posts/dia (sem custo). Suficiente.
 *
 * Fluxo de post:
 *   POST /xrpc/com.atproto.server.createSession   → access_jwt + did
 *   POST /xrpc/com.atproto.repo.createRecord      → cria o post
 *
 * Sem caching de session (cron curto, JWT dura 2h, refazer login é barato).
 */
class SocialBluesky
{
    private const API_BASE = 'https://bsky.social/xrpc';

    /**
     * @param string $mensagem texto pronto (já adaptado pelo SocialPoster)
     * @param string $url      URL do post (incluso na mensagem, mas usado pra facets/embeds)
     * @param array  $post     metadata do post (titulo, imagem_url, post_id...)
     * @param array  $cfgCanal {handle_env, pass_env, handle?, password?}
     * @return array {ok, url_post?, erro?, http_code?}
     */
    public static function postar(string $mensagem, string $url, array $post, array $cfgCanal): array
    {
        $handle = self::resolverEnv($cfgCanal['handle_env'] ?? null) ?? (string)($cfgCanal['handle'] ?? '');
        $pass   = self::resolverEnv($cfgCanal['pass_env'] ?? null)   ?? (string)($cfgCanal['password'] ?? '');
        if ($handle === '' || $pass === '') {
            return ['ok' => false, 'erro' => 'handle/password ausentes (verifique .env BLUESKY_*)'];
        }

        // 1. Login
        try {
            $session = self::login($handle, $pass);
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'login falhou: ' . $e->getMessage()];
        }
        $jwt = $session['accessJwt'];
        $did = $session['did'];

        // 2. Cria record
        try {
            // Detecta facets (URLs no texto) — Bluesky não auto-linka, precisa marcar
            $facets = self::detectarFacets($mensagem);

            $record = [
                '$type'     => 'app.bsky.feed.post',
                'text'      => $mensagem,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            if (!empty($facets)) $record['facets'] = $facets;

            // External embed (preview do link) — opcional mas dá CTR melhor
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $titulo = trim((string)($post['titulo'] ?? ''));
                $record['embed'] = [
                    '$type'    => 'app.bsky.embed.external',
                    'external' => [
                        'uri'         => $url,
                        'title'       => mb_substr($titulo, 0, 200),
                        'description' => mb_substr($titulo, 0, 280),
                    ],
                ];
            }

            $payload = [
                'repo'       => $did,
                'collection' => 'app.bsky.feed.post',
                'record'     => $record,
            ];
            $resp = self::apiCall('POST', '/com.atproto.repo.createRecord', $payload, $jwt);

            if (!isset($resp['uri'])) {
                return ['ok' => false, 'erro' => 'createRecord sem uri', 'response' => $resp];
            }
            // URI atproto: at://did:plc:xxx/app.bsky.feed.post/yyy → URL pública: https://bsky.app/profile/{handle}/post/{rkey}
            $rkey = '';
            if (preg_match('#/([^/]+)$#', (string)$resp['uri'], $m)) $rkey = $m[1];
            $urlPublica = $rkey !== ''
                ? "https://bsky.app/profile/{$handle}/post/{$rkey}"
                : '';

            return ['ok' => true, 'url_post' => $urlPublica, 'uri' => $resp['uri']];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'createRecord falhou: ' . $e->getMessage()];
        }
    }

    private static function login(string $handle, string $pass): array
    {
        $resp = self::apiCall('POST', '/com.atproto.server.createSession', [
            'identifier' => $handle,
            'password'   => $pass,
        ]);
        if (empty($resp['accessJwt']) || empty($resp['did'])) {
            throw new RuntimeException('createSession sem accessJwt/did');
        }
        return $resp;
    }

    /**
     * Detecta URLs no texto e retorna facets pra Bluesky linkar.
     * Bluesky usa byte offsets (não char offsets) — preciso achar UTF-8 byte positions.
     */
    private static function detectarFacets(string $texto): array
    {
        $facets = [];
        if (preg_match_all('#https?://[^\s]+#', $texto, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                [$urlMatch, $byteStart] = $match;
                $byteEnd = $byteStart + strlen($urlMatch);
                $facets[] = [
                    'index'    => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                    'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $urlMatch]],
                ];
            }
        }
        return $facets;
    }

    /** Wrapper HTTP padrão. */
    private static function apiCall(string $method, string $path, array $body = [], ?string $jwt = null): array
    {
        $url = self::API_BASE . $path;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($jwt !== null) $headers[] = 'Authorization: Bearer ' . $jwt;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException("HTTP cURL error: {$err}");
        $j = json_decode((string)$raw, true);
        if ($code >= 400) {
            $msg = is_array($j) ? ($j['message'] ?? $j['error'] ?? json_encode($j)) : substr((string)$raw, 0, 200);
            throw new RuntimeException("HTTP {$code}: {$msg}");
        }
        return is_array($j) ? $j : [];
    }

    /** Resolve env name (string) → valor. Suporta Env::get fallback. */
    private static function resolverEnv(?string $envName): ?string
    {
        if (!$envName || !is_string($envName) || $envName === '') return null;
        $envPath = __DIR__ . '/Env.php';
        if (is_file($envPath)) {
            require_once $envPath;
            $v = Env::get($envName, '');
            if ($v !== '') return (string)$v;
        }
        $v = getenv($envName);
        return $v !== false ? (string)$v : null;
    }
}
