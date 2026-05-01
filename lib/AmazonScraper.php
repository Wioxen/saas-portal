<?php
/**
 * AmazonScraper — busca top produtos por categoria na Amazon BR (página de bestsellers).
 *
 * Fluxo:
 *  1. obterBestsellers($categoria) verifica cache 24h
 *  2. cache miss → download da URL pública /gp/bestsellers/{categoria}/?language=pt_BR
 *  3. parse DOM extrai ASIN, nome, imagem, preço, rank
 *  4. salva cache, retorna lista
 *
 * Cache: data/cache/amazon_bestsellers/{categoria}.json (TTL 24h)
 * Bloqueio: se captcha detectado, marca categoria com blocked_until=ts+6h e retorna [].
 *
 * Categorias suportadas (slug Amazon BR em inglês — confirmado nas URLs reais):
 *   electronics, home, toys, beauty, sports, books
 *
 * IMPORTANTE: scraping é frágil por design. A Amazon muda HTML com frequência.
 * O parser usa estratégia múltipla (data-asin → links /dp/ + contexto próximo).
 */
class AmazonScraper
{
    private const TTL_CACHE_SEC      = 86400;       // 24h
    private const BLOCKED_RECOVERY   = 21600;       // 6h após captcha
    private const TIMEOUT_SEC        = 20;
    private const MIN_PRODUTOS       = 5;           // se parser pegar menos, considera falha

    /** Slug Amazon BR → URL completa. Confirmado: Amazon BR usa slugs em inglês mesmo. */
    public const CATEGORIAS = [
        'electronics' => 'https://www.amazon.com.br/gp/bestsellers/electronics/?language=pt_BR',
        'home'        => 'https://www.amazon.com.br/gp/bestsellers/home/?language=pt_BR',
        'toys'        => 'https://www.amazon.com.br/gp/bestsellers/toys/?language=pt_BR',
        'beauty'      => 'https://www.amazon.com.br/gp/bestsellers/beauty/?language=pt_BR',
        'sports'      => 'https://www.amazon.com.br/gp/bestsellers/sports/?language=pt_BR',
        'books'       => 'https://www.amazon.com.br/gp/bestsellers/books/?language=pt_BR',
    ];

    private static ?string $pathCache = null;
    private string $userAgent;

    public function __construct(?string $userAgent = null)
    {
        // UA Chrome real — Amazon serve HTML simplificado pra bots óbvios
        $this->userAgent = $userAgent
            ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
             . '(KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
    }

    public static function configurar(?string $pathCache = null): void
    {
        self::$pathCache = $pathCache ?? __DIR__ . '/../data/cache/amazon_bestsellers';
    }

    private static function pathCache(): string
    {
        if (self::$pathCache === null) self::configurar();
        if (!is_dir(self::$pathCache)) {
            @mkdir(self::$pathCache, 0775, true);
        }
        return self::$pathCache;
    }

    /** Categorias disponíveis (chaves de CATEGORIAS). */
    public static function categoriasDisponiveis(): array
    {
        return array_keys(self::CATEGORIAS);
    }

    /**
     * Retorna top produtos de uma categoria.
     * @param string $categoria slug (electronics|home|toys|beauty|sports|books)
     * @param int    $limite   máximo retornado (default 10)
     * @return array<int,array> cada item: {asin, nome, img, preco_brl, preco_num, url, rank}
     *                          [] em falha total (captcha/network/parse) — caller decide pular ranker
     */
    public function obterBestsellers(string $categoria, int $limite = 10): array
    {
        if (!isset(self::CATEGORIAS[$categoria])) {
            throw new InvalidArgumentException("Categoria desconhecida: '{$categoria}'. Use: " . implode(',', self::categoriasDisponiveis()));
        }

        // 1) Cache fresco?
        $cache = $this->lerCache($categoria);
        if ($cache !== null && !$this->cacheExpirado($cache)) {
            return array_slice($cache['produtos'] ?? [], 0, $limite);
        }

        // 2) Categoria bloqueada (captcha recente)?
        if ($cache !== null && !empty($cache['blocked_until']) && time() < (int)$cache['blocked_until']) {
            return array_slice($cache['produtos'] ?? [], 0, $limite);  // serve cache stale > vazio
        }

        // 3) Download + parse
        $url = self::CATEGORIAS[$categoria];
        $html = $this->download($url);
        if ($html === null || $this->detectarBloqueio($html)) {
            // Marca bloqueio. Se tem cache stale, devolve ele.
            $this->salvarCache($categoria, [
                'produtos'      => $cache['produtos'] ?? [],
                'blocked_until' => time() + self::BLOCKED_RECOVERY,
                'fetched_at'    => $cache['fetched_at'] ?? null,
                'erro'          => $html === null ? 'download_falhou' : 'captcha_detectado',
            ]);
            return array_slice($cache['produtos'] ?? [], 0, $limite);
        }

        $produtos = $this->parse($html);
        if (count($produtos) < self::MIN_PRODUTOS) {
            // parser quebrou (HTML mudou) — preserva cache stale se houver
            $this->salvarCache($categoria, [
                'produtos'      => $cache['produtos'] ?? $produtos,
                'fetched_at'    => $cache['fetched_at'] ?? null,
                'parse_erro'    => 'menos_que_min',
                'parse_count'   => count($produtos),
                'last_attempt'  => time(),
            ]);
            return array_slice($cache['produtos'] ?? $produtos, 0, $limite);
        }

        // 4) Sucesso — salva cache fresco
        $this->salvarCache($categoria, [
            'produtos'   => $produtos,
            'fetched_at' => time(),
            'categoria'  => $categoria,
            'url'        => $url,
        ]);

        return array_slice($produtos, 0, $limite);
    }

    /** Estado do cache de uma categoria (debug/admin). */
    public function statusCache(string $categoria): array
    {
        $cache = $this->lerCache($categoria);
        if ($cache === null) return ['existe' => false];
        return [
            'existe'        => true,
            'fetched_at'    => $cache['fetched_at'] ?? null,
            'idade_seg'     => isset($cache['fetched_at']) ? (time() - (int)$cache['fetched_at']) : null,
            'expirado'      => $this->cacheExpirado($cache),
            'blocked_until' => $cache['blocked_until'] ?? null,
            'count'         => count($cache['produtos'] ?? []),
            'erro'          => $cache['erro'] ?? null,
        ];
    }

    // ─────────── INTERNOS ───────────

    /**
     * Download com retry exponencial. Amazon BR bloqueia (HTTP 503 ou body curto)
     * em rajada de requests pela mesma sessão — backoff resolve.
     */
    private function download(string $url): ?string
    {
        $tentativas = [0, 3, 7];  // segundos entre tentativas (0 = primeira já)
        $cookieFile = sys_get_temp_dir() . '/amazon_scraper_' . md5($url) . '.cookies';

        foreach ($tentativas as $i => $espera) {
            if ($espera > 0) sleep($espera);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING       => '',  // aceita gzip/deflate/br
                CURLOPT_COOKIEFILE     => $cookieFile,
                CURLOPT_COOKIEJAR      => $cookieFile,
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                ],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Sucesso: HTTP 200 + body grande (página real ~100KB+)
            if ($body !== false && $code === 200 && strlen($body) >= 50000) {
                return $body;
            }
            // Falha — vai pra próxima tentativa (ou desiste após a última)
        }
        return null;
    }

    private function detectarBloqueio(string $html): bool
    {
        // Strings típicas de captcha/bloqueio Amazon
        $sinais = [
            'Type the characters you see in this image',
            'Digite os caracteres que você vê',
            'Sorry! Something went wrong',
            'api-services-support@amazon',
            'validateCaptcha',
            'errors/validateCaptcha',
        ];
        foreach ($sinais as $s) {
            if (stripos($html, $s) !== false) return true;
        }
        return false;
    }

    /**
     * Parse DOM da página de bestsellers.
     * Estratégia: encontra todos os links /dp/{ASIN}/, agrupa por ASIN, extrai contexto próximo.
     */
    private function parse(string $html): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        // Força UTF-8 (Amazon serve UTF-8 mas DOMDocument default é ISO-8859-1)
        $htmlSeguro = '<?xml encoding="UTF-8">' . $html;
        @$doc->loadHTML($htmlSeguro, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        // Procura cards: itens da lista têm role="listitem" OU data-asin OU id="gridItemRoot"
        // Cobertura múltipla pra resistir a mudanças de layout.
        $candidatos = $xpath->query("//div[@id='gridItemRoot'] | //div[@role='listitem' and .//a[contains(@href,'/dp/')]]");
        if (!$candidatos || $candidatos->length === 0) {
            // Fallback: lista todos os links /dp/ com pai mais próximo do tipo div
            $candidatos = $xpath->query("//div[.//a[contains(@href,'/dp/')] and .//img[@src]]");
        }

        $produtos = [];
        $vistos = [];
        $rank = 0;

        foreach ($candidatos as $card) {
            /** @var DOMElement $card */
            $asin = $this->extrairAsin($card, $xpath);
            if ($asin === null || isset($vistos[$asin])) continue;

            $nome = $this->extrairNome($card, $xpath);
            $img  = $this->extrairImg($card, $xpath);
            $preco = $this->extrairPreco($card, $xpath);

            // Mínimo viável: ASIN + nome + img. Preço é opcional (alguns bestsellers só mostram em hover).
            if ($nome === '' || $img === '') continue;

            $rank++;
            $vistos[$asin] = true;
            $produtos[] = [
                'asin'      => $asin,
                'nome'      => $nome,
                'img'       => $img,
                'preco_brl' => $preco['exibicao'] ?? '',
                'preco_num' => $preco['valor']    ?? null,
                'url'       => 'https://www.amazon.com.br/dp/' . $asin,
                'rank'      => $rank,
            ];

            if ($rank >= 50) break;  // limite duro — página inteira ~50 produtos
        }

        return $produtos;
    }

    private function extrairAsin(DOMElement $card, DOMXPath $xpath): ?string
    {
        // Tenta data-asin no próprio card
        $asin = trim($card->getAttribute('data-asin'));
        if ($asin !== '' && preg_match('/^[A-Z0-9]{10}$/', $asin)) return $asin;

        // Procura link /dp/ASIN dentro
        $link = $xpath->query(".//a[contains(@href,'/dp/')]", $card)->item(0);
        if ($link instanceof DOMElement) {
            $href = $link->getAttribute('href');
            if (preg_match('#/dp/([A-Z0-9]{10})#', $href, $m)) return $m[1];
        }
        return null;
    }

    private function extrairNome(DOMElement $card, DOMXPath $xpath): string
    {
        // Estratégia em ordem: aria-label do link → alt da img → div com classe que contém "title" → texto do link
        $link = $xpath->query(".//a[contains(@href,'/dp/')]", $card)->item(0);
        if ($link instanceof DOMElement) {
            $aria = trim($link->getAttribute('aria-label'));
            if ($aria !== '' && mb_strlen($aria) > 8) return $this->limparTexto($aria);
        }

        $div = $xpath->query(".//div[contains(@class,'_cDEzb_p13n-sc-css-line-clamp')]
            | .//div[contains(@class,'p13n-sc-truncate')]
            | .//span[contains(@class,'p13n-sc-truncated')]
            | .//div[contains(@class,'a-link-normal') and string-length(text()) > 10]", $card)->item(0);
        if ($div instanceof DOMElement) {
            $t = $this->limparTexto($div->textContent);
            if (mb_strlen($t) > 8) return $t;
        }

        $img = $xpath->query(".//img", $card)->item(0);
        if ($img instanceof DOMElement) {
            $alt = trim($img->getAttribute('alt'));
            if ($alt !== '' && mb_strlen($alt) > 8) return $this->limparTexto($alt);
        }

        if ($link instanceof DOMElement) {
            $t = $this->limparTexto($link->textContent);
            if (mb_strlen($t) > 8) return $t;
        }

        return '';
    }

    private function extrairImg(DOMElement $card, DOMXPath $xpath): string
    {
        $img = $xpath->query(".//img", $card)->item(0);
        if (!($img instanceof DOMElement)) return '';

        // Amazon serve img em src OU data-src OU srcset (lazy load)
        $src = trim($img->getAttribute('src'));
        if ($src === '' || strpos($src, 'data:image') === 0) {
            $src = trim($img->getAttribute('data-src'));
        }
        if ($src === '') {
            $srcset = trim($img->getAttribute('srcset'));
            if ($srcset !== '' && preg_match('#(https?://\S+)#', $srcset, $m)) $src = $m[1];
        }
        // Normaliza pra HTTPS absoluto
        if ($src !== '' && strpos($src, '//') === 0) $src = 'https:' . $src;
        if ($src !== '' && strpos($src, 'http') !== 0) $src = '';
        return $src;
    }

    /** Retorna ['exibicao' => 'R$ 99,90', 'valor' => 99.90] ou ['exibicao'=>'', 'valor'=>null]. */
    private function extrairPreco(DOMElement $card, DOMXPath $xpath): array
    {
        $nodes = $xpath->query(".//span[contains(@class,'p13n-sc-price')]
            | .//span[contains(@class,'a-price')]//span[contains(@class,'a-offscreen')]
            | .//span[contains(@class,'a-color-price')]", $card);

        foreach ($nodes as $n) {
            $t = trim($n->textContent);
            if ($t === '') continue;
            // Match "R$ 99,90" ou "99,90" ou "1.234,56"
            if (preg_match('/R?\$?\s*([\d.]+,\d{2})/u', $t, $m)) {
                $valorStr = str_replace(['.', ','], ['', '.'], $m[1]);
                return [
                    'exibicao' => 'R$ ' . $m[1],
                    'valor'    => (float)$valorStr,
                ];
            }
        }
        return ['exibicao' => '', 'valor' => null];
    }

    private function limparTexto(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);
        // Limita comprimento — nomes Amazon as vezes vêm gigantes
        if (mb_strlen($s) > 180) $s = mb_substr($s, 0, 177) . '...';
        return $s;
    }

    private function pathArquivo(string $categoria): string
    {
        return self::pathCache() . '/' . $categoria . '.json';
    }

    private function lerCache(string $categoria): ?array
    {
        $path = $this->pathArquivo($categoria);
        if (!is_file($path)) return null;
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function salvarCache(string $categoria, array $data): void
    {
        $path = $this->pathArquivo($categoria);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
    }

    private function cacheExpirado(array $cache): bool
    {
        $fetchedAt = (int)($cache['fetched_at'] ?? 0);
        if ($fetchedAt === 0) return true;
        return (time() - $fetchedAt) > self::TTL_CACHE_SEC;
    }
}
