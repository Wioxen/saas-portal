<?php
/**
 * SocialThreads — driver pra Threads (Meta).
 *
 * API oficial Threads (graph.threads.net) — separada da Instagram Graph API,
 * mas mesmo desenvolvedor Meta. Liberada em Junho/2024.
 *
 * Documentação: https://developers.facebook.com/docs/threads
 *
 * Autenticação:
 *   1. App Meta Developer Portal habilitado pra Threads API
 *   2. Permissão `threads_basic` + `threads_content_publish`
 *   3. Long-lived access token por conta Threads
 *   4. .env: THREADS_TOKEN_LEAODABARRA + THREADS_USER_ID_LEAODABARRA
 *
 * Fluxo de post (2-step):
 *   1. POST /me/threads     → cria container (creation_id)
 *   2. POST /me/threads_publish?creation_id=X → publica
 *
 * Limite: 250 posts/24h por user. Sobra muito pra 6 sites × 5/dia.
 */
class SocialThreads
{
    private const API_BASE = 'https://graph.threads.net/v1.0';

    /**
     * @return array {ok, url_post?, erro?}
     */
    public static function postar(string $mensagem, string $url, array $post, array $cfgCanal): array
    {
        $token  = self::resolverEnv($cfgCanal['token_env'] ?? null) ?? (string)($cfgCanal['token'] ?? '');
        $userId = self::resolverEnv($cfgCanal['user_id_env'] ?? null) ?? (string)($cfgCanal['user_id'] ?? '');
        if ($token === '' || $userId === '') {
            return ['ok' => false, 'erro' => 'token/user_id ausentes (verifique .env THREADS_*)'];
        }

        $imgUrl = trim((string)($post['imagem_url'] ?? ''));

        // 1. Cria container
        try {
            $payload = [
                'media_type'   => $imgUrl !== '' ? 'IMAGE' : 'TEXT',
                'text'         => mb_substr($mensagem, 0, 500), // Threads limita 500 chars
                'access_token' => $token,
            ];
            if ($imgUrl !== '') $payload['image_url'] = $imgUrl;

            $createResp = self::apiCall('POST', "/{$userId}/threads", $payload);
            if (empty($createResp['id'])) {
                return ['ok' => false, 'erro' => 'create_container sem id', 'response' => $createResp];
            }
            $creationId = (string)$createResp['id'];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'create_container: ' . $e->getMessage()];
        }

        // Threads precisa de espera de ~1-3s entre create e publish (processamento de mídia)
        if ($imgUrl !== '') sleep(2);

        // 2. Publica
        try {
            $resp = self::apiCall('POST', "/{$userId}/threads_publish", [
                'creation_id'  => $creationId,
                'access_token' => $token,
            ]);
            if (empty($resp['id'])) {
                return ['ok' => false, 'erro' => 'publish sem id', 'response' => $resp];
            }
            $threadId = (string)$resp['id'];

            // Tenta resolver permalink (não obrigatório — alguns endpoints não retornam)
            $permalink = '';
            try {
                $detalhe = self::apiCall('GET', "/{$threadId}?fields=permalink&access_token=" . urlencode($token));
                $permalink = (string)($detalhe['permalink'] ?? '');
            } catch (Throwable $e) { /* permalink é opcional */ }

            return [
                'ok'        => true,
                'thread_id' => $threadId,
                'url_post'  => $permalink,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'publish: ' . $e->getMessage()];
        }
    }

    private static function apiCall(string $method, string $path, array $params = []): array
    {
        $url = self::API_BASE . $path;
        if ($method === 'GET' && !empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("cURL error: {$err}");
        $j = json_decode((string)$raw, true);
        if ($code >= 400) {
            $msg = is_array($j) ? ($j['error']['message'] ?? json_encode($j['error'] ?? $j)) : substr((string)$raw, 0, 200);
            throw new RuntimeException("HTTP {$code}: {$msg}");
        }
        return is_array($j) ? $j : [];
    }

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
