<?php
/**
 * Client para o endpoint cc/v1/indexar (plugin cc-instant-indexing-api no WP).
 * Aciona o Rank Math Instant Indexing, com fallback pra IndexNow.
 */
class InstantIndexing
{
    private string $baseUrl;
    private string $auth;

    public function __construct(string $wpUrl, string $user, string $appPassword)
    {
        $this->baseUrl = rtrim($wpUrl, '/') . '/wp-json/cc/v1';
        $this->auth    = base64_encode("{$user}:{$appPassword}");
    }

    /**
     * Solicita indexação imediata de uma URL.
     * @return array ['success'=>bool, 'method'=>string|null, 'error'=>string|null]
     */
    public function indexar(string $url, string $action = 'URL_UPDATED'): array
    {
        $payload = ['url' => $url, 'action' => $action];

        $ch = curl_init($this->baseUrl . '/indexar');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            return ['success' => false, 'method' => null, 'error' => "HTTP {$code}: " . substr((string)$resp, 0, 200)];
        }
        $json = json_decode($resp, true);
        return [
            'success' => (bool)($json['success'] ?? false),
            'method'  => $json['method'] ?? null,
            'error'   => $json['error'] ?? null,
        ];
    }
}
