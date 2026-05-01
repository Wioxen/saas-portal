<?php
/**
 * Cliente Graph API da Meta — Facebook Page + Instagram Business.
 *
 * Requisitos (setar em sites.php por site):
 *  - fb_page_id        : ID numérico da Page do Facebook
 *  - fb_page_token     : Long-lived Page Access Token (permissões: pages_manage_posts, pages_read_engagement,
 *                        instagram_content_publish, instagram_basic)
 *  - ig_user_id        : Instagram Business/Creator User ID (conectado à Page)
 *
 * O Instagram usa o MESMO token da Page — não precisa de 2 tokens.
 */
class Meta
{
    private string $pageId;
    private string $pageToken;
    private string $igUserId;
    private string $igToken;
    private string $apiVersion = 'v20.0';

    /**
     * @param string $pageId    ID da Page (para postar no Facebook)
     * @param string $pageToken Long-lived Page Access Token
     * @param string $igUserId  Instagram Business/Creator User ID
     * @param string $igToken   Token direto do IG (Instagram Login for Business).
     *                          Se vazio, usa $pageToken (fluxo clássico via Page).
     */
    public function __construct(string $pageId, string $pageToken, string $igUserId = '', string $igToken = '')
    {
        $this->pageId    = $pageId;
        $this->pageToken = $pageToken;
        $this->igUserId  = $igUserId;
        $this->igToken   = $igToken;
    }

    public function fbConfigurado(): bool
    {
        return $this->pageId !== '' && $this->pageToken !== '';
    }

    public function igConfigurado(): bool
    {
        return $this->igUserId !== '' && ($this->igToken !== '' || $this->pageToken !== '');
    }

    /** @deprecated use fbConfigurado() */
    public function configurado(): bool { return $this->fbConfigurado(); }

    private function tokenIg(): string
    {
        return $this->igToken !== '' ? $this->igToken : $this->pageToken;
    }

    /**
     * Publica um link na Page do Facebook.
     * O FB crawla o link e pega og:image/og:title/og:description automaticamente.
     * @return array ['success'=>bool, 'id'=>postId|null, 'error'=>string|null]
     */
    public function postarFacebookPage(string $link, string $message = ''): array
    {
        if (!$this->fbConfigurado()) return ['success' => false, 'id' => null, 'error' => 'Facebook não configurado (fb_page_id/fb_page_token)'];

        $payload = [
            'link'         => $link,
            'message'      => $message,
            'access_token' => $this->pageToken,
        ];
        $resp = $this->post("/{$this->pageId}/feed", $payload);
        if (!empty($resp['id'])) return ['success' => true, 'id' => $resp['id'], 'error' => null];
        return ['success' => false, 'id' => null, 'error' => $resp['_error'] ?? 'Falha desconhecida'];
    }

    /**
     * Publica uma imagem no feed do Instagram Business.
     * Fluxo 2-step: cria container → publica.
     * A imagem DEVE estar acessível publicamente (URL HTTPS, JPG/PNG, ≤8MB).
     * @return array ['success'=>bool, 'id'=>mediaId|null, 'error'=>string|null]
     */
    public function postarInstagramFeed(string $imageUrl, string $caption = ''): array
    {
        if (!$this->igConfigurado()) return ['success' => false, 'id' => null, 'error' => 'Instagram não configurado (ig_user_id + ig_access_token ou fb_page_token)'];
        if ($imageUrl === '' || !preg_match('#^https://#', $imageUrl)) return ['success' => false, 'id' => null, 'error' => 'Imagem deve ser HTTPS'];

        $token = $this->tokenIg();
        // Token IGAA → Instagram Login for Business → endpoint graph.instagram.com
        // Token EAA  → Facebook Page flow → endpoint graph.facebook.com
        $baseHost = str_starts_with($token, 'IGAA') ? 'https://graph.instagram.com' : 'https://graph.facebook.com';

        // 1. Cria container
        $criar = $this->postUrl("{$baseHost}/{$this->apiVersion}/{$this->igUserId}/media", [
            'image_url'    => $imageUrl,
            'caption'      => $caption,
            'access_token' => $token,
        ]);
        if (empty($criar['id'])) {
            return ['success' => false, 'id' => null, 'error' => 'Falha ao criar container: ' . ($criar['_error'] ?? '?')];
        }
        $creationId = $criar['id'];

        // 2. Pequena pausa pro IG processar a imagem (JPEG precisa validar)
        sleep(2);

        // 3. Publica
        $publicar = $this->postUrl("{$baseHost}/{$this->apiVersion}/{$this->igUserId}/media_publish", [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ]);
        if (!empty($publicar['id'])) return ['success' => true, 'id' => $publicar['id'], 'error' => null];
        return ['success' => false, 'id' => null, 'error' => 'Falha ao publicar: ' . ($publicar['_error'] ?? '?')];
    }

    /**
     * Publica uma FOTO na Page do Facebook (endpoint /photos).
     * Diferente do /feed, a foto é upada diretamente — não depende do FB crawlar og:image.
     * Ideal quando o post no WP ainda está como draft (não indexado publicamente).
     * @return array ['success'=>bool, 'id'=>postId|null, 'error'=>string|null]
     */
    public function postarFacebookFoto(string $imageUrl, string $caption = '', string $linkNaLegenda = ''): array
    {
        if (!$this->fbConfigurado()) return ['success' => false, 'id' => null, 'error' => 'Facebook não configurado'];
        if ($imageUrl === '' || !preg_match('#^https?://#', $imageUrl)) {
            return ['success' => false, 'id' => null, 'error' => 'URL de imagem inválida'];
        }
        $msg = trim($caption . ($linkNaLegenda !== '' ? "\n\n" . $linkNaLegenda : ''));
        $resp = $this->post("/{$this->pageId}/photos", [
            'url'          => $imageUrl,
            'caption'      => $msg,
            'access_token' => $this->pageToken,
        ]);
        if (!empty($resp['id'])) return ['success' => true, 'id' => $resp['id'], 'error' => null];
        return ['success' => false, 'id' => null, 'error' => $resp['_error'] ?? 'Falha desconhecida'];
    }

    /**
     * Publica um CARROSSEL no feed do Instagram (até 10 imagens).
     * Fluxo 3-step: cria N containers (media_type=IMAGE, is_carousel_item=true)
     *               → cria container-pai (media_type=CAROUSEL com children=[ids])
     *               → publica o container-pai.
     * Todas as imagens devem ser HTTPS, JPG/PNG, ≤8MB.
     * @return array ['success'=>bool, 'id'=>mediaId|null, 'error'=>string|null]
     */
    public function postarInstagramCarrossel(array $imageUrls, string $caption = ''): array
    {
        if (!$this->igConfigurado()) return ['success' => false, 'id' => null, 'error' => 'Instagram não configurado'];
        $imageUrls = array_values(array_filter($imageUrls, fn($u) => preg_match('#^https://#', $u)));
        if (empty($imageUrls)) return ['success' => false, 'id' => null, 'error' => 'Nenhuma URL HTTPS válida'];
        if (count($imageUrls) < 2) return ['success' => false, 'id' => null, 'error' => 'Carrossel precisa de no mínimo 2 imagens'];
        if (count($imageUrls) > 10) $imageUrls = array_slice($imageUrls, 0, 10);

        $token = $this->tokenIg();
        $baseHost = str_starts_with($token, 'IGAA') ? 'https://graph.instagram.com' : 'https://graph.facebook.com';

        // 1. Cria container por imagem
        $childrenIds = [];
        foreach ($imageUrls as $i => $url) {
            $resp = $this->postUrl("{$baseHost}/{$this->apiVersion}/{$this->igUserId}/media", [
                'image_url'        => $url,
                'is_carousel_item' => 'true',
                'access_token'     => $token,
            ]);
            if (empty($resp['id'])) {
                return ['success' => false, 'id' => null, 'error' => 'Falha no child #' . ($i + 1) . ': ' . ($resp['_error'] ?? '?')];
            }
            $childrenIds[] = $resp['id'];
            usleep(500000); // 0.5s entre uploads
        }

        // 2. Cria container-pai do carrossel
        $parent = $this->postUrl("{$baseHost}/{$this->apiVersion}/{$this->igUserId}/media", [
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $childrenIds),
            'caption'      => $caption,
            'access_token' => $token,
        ]);
        if (empty($parent['id'])) {
            return ['success' => false, 'id' => null, 'error' => 'Falha no container-pai: ' . ($parent['_error'] ?? '?')];
        }

        sleep(3); // IG precisa processar o carrossel

        // 3. Publica
        $pub = $this->postUrl("{$baseHost}/{$this->apiVersion}/{$this->igUserId}/media_publish", [
            'creation_id'  => $parent['id'],
            'access_token' => $token,
        ]);
        if (!empty($pub['id'])) return ['success' => true, 'id' => $pub['id'], 'error' => null];
        return ['success' => false, 'id' => null, 'error' => 'Falha ao publicar: ' . ($pub['_error'] ?? '?')];
    }

    /**
     * Atalho: posta o mesmo conteúdo no Facebook Page + Instagram Feed.
     * @param string $link       URL do post no WP
     * @param string $imageUrl   URL pública da featured image (HTTPS)
     * @param string $caption    Texto (título/excerpt)
     * @return array ['facebook'=>[...], 'instagram'=>[...]]
     */
    public function postarTudo(string $link, string $imageUrl, string $caption): array
    {
        // Instagram não aceita URLs no caption como clicáveis, mas inclui mesmo assim
        $captionIg = trim($caption . "\n\nLink no perfil: " . $link);
        return [
            'facebook'  => $this->postarFacebookPage($link, $caption),
            'instagram' => $this->postarInstagramFeed($imageUrl, $captionIg),
        ];
    }

    private function post(string $path, array $payload): array
    {
        return $this->postUrl("https://graph.facebook.com/{$this->apiVersion}" . $path, $payload);
    }

    private function postUrl(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) return ['_error' => 'cURL falhou'];
        $data = json_decode((string)$resp, true);
        if (!is_array($data)) return ['_error' => 'Resposta inválida: ' . substr((string)$resp, 0, 200)];
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? 'HTTP ' . $code;
            return ['_error' => "HTTP {$code}: {$msg}"];
        }
        return $data;
    }
}
