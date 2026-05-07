<?php
declare(strict_types=1);

/**
 * GoogleIndexingApi — cliente nativo PHP pra Google Indexing API.
 *
 * Sem dependências externas: gera JWT RS256 com openssl, troca por access
 * token OAuth, chama https://indexing.googleapis.com/v3/urlNotifications:publish
 *
 * AVISO: Google Indexing API oficialmente aceita apenas JobPosting +
 * BroadcastEvent. Pra páginas comuns, retorna 200 OK mas Google ignora
 * a notificação. Mesmo assim alguns relatos sugerem que acelera crawl
 * em sites com schema Article. Use IndexNow (Bing/Yandex) em paralelo.
 *
 * Setup:
 *   1. Service account com Indexing API habilitada no Cloud Console
 *   2. Service account email adicionado como OWNER em cada propriedade GSC
 *      (precisa Domain Property — URL-prefix limitado)
 *   3. JSON da chave em /app/data/credentials/google-indexing.json (chmod 600)
 *
 * Uso:
 *   $idx = new GoogleIndexingApi('/app/data/credentials/google-indexing.json');
 *   $r = $idx->notifyUrl('https://site.com/page/', 'URL_UPDATED');
 */
class GoogleIndexingApi
{
    private const SCOPE = 'https://www.googleapis.com/auth/indexing';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const PUBLISH_ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    private array $credentials;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(string $credentialsPath)
    {
        if (!file_exists($credentialsPath)) {
            throw new RuntimeException("Credentials não encontrado: {$credentialsPath}");
        }
        $j = json_decode((string)file_get_contents($credentialsPath), true);
        if (!is_array($j) || empty($j['private_key']) || empty($j['client_email'])) {
            throw new RuntimeException("Credentials inválido em {$credentialsPath}");
        }
        $this->credentials = $j;
    }

    /**
     * Notifica Google sobre criação/atualização de URL.
     *
     * @param string $url URL canônica (sem fragment)
     * @param string $type 'URL_UPDATED' (default) ou 'URL_DELETED'
     * @return array ['success'=>bool, 'method'=>'google_indexing', 'response'=>array, 'error'=>string|null]
     */
    public function notifyUrl(string $url, string $type = 'URL_UPDATED'): array
    {
        try {
            $token = $this->getAccessToken();
        } catch (Throwable $e) {
            return ['success' => false, 'method' => 'google_indexing', 'response' => null, 'error' => 'oauth: ' . $e->getMessage()];
        }

        $payload = ['url' => $url, 'type' => $type];
        $ch = curl_init(self::PUBLISH_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode((string)$resp, true);
        $success = ($code >= 200 && $code < 300);

        return [
            'success' => $success,
            'method' => 'google_indexing',
            'http_code' => $code,
            'response' => $body,
            'error' => $success ? null : ('HTTP ' . $code . ': ' . substr((string)$resp, 0, 300)),
        ];
    }

    /**
     * Retorna access token OAuth (cacheado em memória até expirar).
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiresAt > time() + 60) {
            return $this->accessToken;
        }

        $jwt = $this->generateJwt();

        $ch = curl_init(self::TOKEN_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("OAuth token HTTP {$code}: " . substr((string)$resp, 0, 300));
        }

        $j = json_decode((string)$resp, true);
        if (empty($j['access_token'])) {
            throw new RuntimeException("OAuth response sem access_token: " . substr((string)$resp, 0, 300));
        }

        $this->accessToken = (string)$j['access_token'];
        $this->tokenExpiresAt = time() + (int)($j['expires_in'] ?? 3600);
        return $this->accessToken;
    }

    /**
     * Gera JWT RS256 assinado com a private key da service account.
     */
    private function generateJwt(): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $b64h = self::base64UrlEncode(json_encode($header));
        $b64c = self::base64UrlEncode(json_encode($claims));
        $signingInput = "{$b64h}.{$b64c}";

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('openssl_sign falhou: ' . openssl_error_string());
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
