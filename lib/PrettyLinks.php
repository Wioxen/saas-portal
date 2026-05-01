<?php
/**
 * Pretty Links — via endpoint REST custom (plugin cc-prettylinks-api).
 *
 * Endpoint: POST /wp-json/cc/v1/pretty-link
 * Auth: Application Password (mesmo do WP REST)
 *
 * O plugin cc-prettylinks-api.php chama prli_create_pretty_link()
 * internamente no WordPress — funciona com Pretty Links free.
 */
class PrettyLinks
{
    private string $baseUrl;
    private string $auth;

    public function __construct(string $wpUrl, string $user, string $appPassword)
    {
        $this->baseUrl = rtrim($wpUrl, '/') . '/wp-json/cc/v1';
        $this->auth = base64_encode("{$user}:{$appPassword}");
    }

    /**
     * Cria um Pretty Link via REST.
     * @return string|null URL do pretty link (ex: https://site.com/go/produto)
     */
    public function criar(string $targetUrl, string $slug, string $name = '', bool $nofollow = true, string $redirectType = '301'): ?string
    {
        $payload = [
            'target_url'    => $targetUrl,
            'slug'          => $slug,
            'name'          => $name ?: $slug,
            'nofollow'      => $nofollow ? 1 : 0,
            'redirect_type' => $redirectType,
        ];

        $resp = $this->post('/pretty-link', $payload);

        if ($resp && ($resp['success'] ?? false)) {
            return $resp['url'] ?? null;
        }
        return null;
    }

    /**
     * Busca Pretty Link por slug.
     * @return array|null Dados do link ou null se não existe
     */
    public function buscarPorSlug(string $slug): ?array
    {
        try {
            return $this->get('/pretty-link/' . urlencode($slug));
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Cria ou retorna existente (evita duplicata).
     * @return string|null URL do pretty link
     */
    public function criarOuBuscar(string $targetUrl, string $slug, string $name = '', bool $nofollow = true, string $redirectType = '301'): ?string
    {
        // O endpoint já verifica se existe — retorna o existente se tiver
        return $this->criar($targetUrl, $slug, $name, $nofollow, $redirectType);
    }

    /** Gera slug a partir do nome do produto. */
    public static function slugify(string $name, string $prefix = 'go'): string
    {
        $s = mb_strtolower($name, 'UTF-8');
        $s = preg_replace('/[áàãâä]/u', 'a', $s);
        $s = preg_replace('/[éèêë]/u', 'e', $s);
        $s = preg_replace('/[íìîï]/u', 'i', $s);
        $s = preg_replace('/[óòõôö]/u', 'o', $s);
        $s = preg_replace('/[úùûü]/u', 'u', $s);
        $s = preg_replace('/[ç]/u', 'c', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (mb_strlen($s) > 50) $s = mb_substr($s, 0, 50);
        $s = rtrim($s, '-');
        return $prefix . '/' . $s;
    }

    private function post(string $path, array $payload): ?array
    {
        $ch = curl_init($this->baseUrl . $path);
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

        if ($resp === false || $code >= 400) return null;
        return json_decode($resp, true) ?: null;
    }

    private function get(string $path): ?array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) return null;
        return json_decode($resp, true) ?: null;
    }
}
