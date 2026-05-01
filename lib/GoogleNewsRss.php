<?php
/**
 * Parser de RSS do Google News + resolver de redirects.
 *
 * O Google News retorna links do tipo:
 *   https://news.google.com/rss/articles/CBMi...?oc=5
 * Esses links não fazem redirect HTTP normal — usam meta refresh / JS redirect.
 * Esta classe resolve o link real via múltiplas estratégias.
 */
class GoogleNewsRss
{
    private string $userAgent;
    private int $timeout;
    private ?Serper $serper = null;

    public function __construct(string $userAgent = '', int $timeout = 20, ?Serper $serper = null)
    {
        $this->userAgent = $userAgent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
        $this->timeout   = $timeout;
        $this->serper    = $serper;
    }

    /**
     * Resolve o link real via busca Serper pelo título exato.
     * Funciona bem para títulos únicos — o Google indexa a página original.
     */
    public function resolverViaTitulo(string $titulo, string $source = ''): ?string
    {
        if ($this->serper === null || trim($titulo) === '') return null;
        $query = '"' . trim($titulo) . '"';
        if ($source !== '') $query .= ' ' . $source;
        try {
            $resp = $this->serper->search($query, 5);
        } catch (Throwable $e) { return null; }
        foreach (($resp['organic'] ?? []) as $r) {
            $link = (string)($r['link'] ?? '');
            if ($link === '') continue;
            if (preg_match('#(?:news\.google\.com|google\.com/url)#i', $link)) continue;
            return $link;
        }
        return null;
    }

    /**
     * Lê uma URL de RSS do Google News e retorna lista de items.
     * @return array de {title, link, link_resolvido, description, pubDate, source}
     */
    public function parseRss(string $rssUrl, int $maxItems = 20): array
    {
        $xml = $this->fetch($rssUrl);
        if ($xml === null) throw new RuntimeException("Falha ao baixar RSS: $rssUrl");

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            $err = libxml_get_last_error();
            libxml_clear_errors();
            throw new RuntimeException('XML inválido: ' . ($err->message ?? '?'));
        }

        $items = [];
        $count = 0;
        foreach ($doc->channel->item as $it) {
            if ($count >= $maxItems) break;
            $title = trim((string)$it->title);
            $link  = trim((string)$it->link);
            $desc  = trim((string)$it->description);
            $pub   = trim((string)$it->pubDate);
            $source = '';
            if (isset($it->source)) $source = trim((string)$it->source);

            // Limpa descrição (vem com HTML)
            $descLimpo = trim(strip_tags(html_entity_decode($desc)));
            // No feed do Google, title vem no formato "Título - Fonte"
            $titleLimpo = $title;
            if (strpos($title, ' - ') !== false) {
                $parts = explode(' - ', $title);
                $sourceFromTitle = array_pop($parts);
                $titleLimpo = implode(' - ', $parts);
                if ($source === '') $source = $sourceFromTitle;
            }

            $items[] = [
                'title'          => $titleLimpo,
                'link'           => $link,
                'link_resolvido' => null, // preenchido sob demanda
                'description'    => $descLimpo,
                'pubDate'        => $pub,
                'source'         => $source,
            ];
            $count++;
        }
        return $items;
    }

    /**
     * Resolve o redirect de um link do Google News para a URL real do artigo.
     * Tenta 5 estratégias: base64 protobuf decode (primeiro), HTTP follow, meta refresh, data-n-au, regex JSON.
     */
    public function resolverLink(string $gnewsUrl): ?string
    {
        // Se já não for URL do Google News, retorna como está
        if (!preg_match('#news\.google\.com/(?:rss/)?articles/#i', $gnewsUrl)) {
            return $gnewsUrl;
        }

        // 0. MÉTODO CONFIÁVEL: batchexecute API do Google News (resolve redirect via POST interno)
        // Extrai article ID da URL (string base64-url após /articles/)
        if (preg_match('#/articles/([A-Za-z0-9_\-]+)#', $gnewsUrl, $m)) {
            $articleId = $m[1];
            // 1) Decodifica o ID para extrair o signature interno (formato AU_...)
            $padded = $articleId . str_repeat('=', (4 - strlen($articleId) % 4) % 4);
            $decoded = @base64_decode(strtr($padded, '-_', '+/'), true);
            $signature = null;
            if ($decoded !== false && preg_match('#(AU_[A-Za-z0-9_\-]{20,})#', $decoded, $sigMatch)) {
                $signature = $sigMatch[1];
            }

            if ($signature !== null) {
                // 2) Monta chamada batchexecute — formato Google interno
                $innerPayload = json_encode([null, null, $signature, $articleId]);
                $fReq = json_encode([[['Fbv4je', $innerPayload, null, 'generic']]]);
                $postData = http_build_query(['f.req' => $fReq]);

                $ch = curl_init('https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $postData,
                    CURLOPT_USERAGENT      => $this->userAgent,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept: */*',
                    ],
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);

                if (is_string($resp) && $resp !== '') {
                    // Resposta tem prefixo ")]}'" e depois JSON-ish. Extrai URL diretamente.
                    if (preg_match('#\[\s*"(https?://[^"]+)"#', $resp, $urlMatch)) {
                        $realUrl = stripslashes($urlMatch[1]);
                        if (!preg_match('#^https?://(?:[^/]+\.)?(?:google|gstatic)\.com#i', $realUrl)) {
                            return $realUrl;
                        }
                    }
                    // Fallback: procura qualquer http(s):// válido não-google
                    if (preg_match_all('#https?://[^\s\\\\"<>]+#i', $resp, $allUrls)) {
                        foreach ($allUrls[0] as $u) {
                            $u = stripslashes($u);
                            if (preg_match('#^https?://(?:[^/]+\.)?(?:google|gstatic|googleusercontent)\.com#i', $u)) continue;
                            if (preg_match('#\.(js|css|png|jpg|svg|ico|woff|ttf)$#i', $u)) continue;
                            if (strlen($u) < 25) continue;
                            return $u;
                        }
                    }
                }
            }
        }

        $ch = curl_init($gnewsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            ],
        ]);
        $html = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // 1. Se o follow já levou pra fora do google, usa isso
        if ($finalUrl && !preg_match('#^https?://(?:[^/]+\.)?google\.#i', $finalUrl)) {
            return $finalUrl;
        }

        if ($html === false || $html === '') return null;

        // 2. Meta refresh
        if (preg_match('#<meta\s+http-equiv=["\']refresh["\']\s+content=["\']\d+;\s*url=([^"\']+)["\']#i', $html, $m)) {
            return html_entity_decode($m[1]);
        }

        // 3. data-n-au (atributo do wrapper c-wiz do Google News)
        if (preg_match('#data-n-au=["\'](https?://[^"\']+)["\']#i', $html, $m)) {
            return html_entity_decode($m[1]);
        }

        // 4. Primeiro link externo no HTML que não seja google.com
        if (preg_match_all('#https?://(?!(?:[^/]+\.)?(?:google|gstatic|googleusercontent)\.[^/"\'\s]+)[^"\'\s<>]+#i', $html, $all)) {
            foreach ($all[0] as $u) {
                // Ignora URLs de CDN, fontes, js, css
                if (preg_match('#\.(js|css|png|jpg|svg|ico|woff|ttf)(\?|$)#i', $u)) continue;
                if (strlen($u) < 30) continue;
                return $u;
            }
        }

        return null;
    }

    /** Fetch simples com curl. */
    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($resp !== false && $code < 400) ? (string)$resp : null;
    }
}
