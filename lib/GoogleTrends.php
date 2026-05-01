<?php
/**
 * Google Trends RSS — puxa trending topics do Brasil.
 * Feed: https://trends.google.com.br/trending/rss?geo=BR
 * Gratuito, sem API key.
 */
class GoogleTrends
{
    private string $userAgent;

    public function __construct(string $userAgent = '')
    {
        $this->userAgent = $userAgent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    }

    /**
     * Busca trending topics do Brasil.
     * @return array [{title, traffic, news_url, news_title, news_source, image, pubDate}]
     */
    /** Categorias pra filtro local (Google RSS não filtra por cat). */
    public static array $categorias = [
        'all'           => 'Todas',
        'tecnologia'    => 'Tecnologia',
        'entretenimento'=> 'Entretenimento',
        'esportes'      => 'Esportes',
        'negocios'      => 'Negócios',
        'saude'         => 'Saúde',
        'politica'      => 'Política',
    ];

    /** Períodos aceitos pela Explore API. */
    public static array $periodos = [
        'now 1-H'   => 'Última 1 hora',
        'now 4-H'   => 'Últimas 4 horas',
        'now 1-d'   => 'Últimas 24 horas',
        'now 7-d'   => 'Últimos 7 dias',
        'today 1-m' => 'Últimos 30 dias',
        'today 3-m' => 'Últimos 90 dias',
        'today 12-m'=> 'Últimos 12 meses',
    ];

    /** Palavras-chave por categoria pra filtro local. */
    private static array $catKeywords = [
        'tecnologia'     => ['celular','smartphone','iphone','samsung','galaxy','xiaomi','motorola','notebook','laptop','fone','headphone','airpod','apple','google','microsoft','ai','inteligência artificial','tech','app','android','ios','software','hardware','processador','gpu','nvidia','amd','intel','monitor','tv','smart','gadget','drone','câmera','tablet','ipad','watch','wearable','robô','chatgpt','openai','meta','tiktok','instagram','whatsapp','5g','wifi','bluetooth','usb','ssd','memória','computador','pc','gamer','setup','console','playstation','xbox','nintendo','steam'],
        'entretenimento' => ['filme','série','netflix','disney','hbo','prime video','cinema','ator','atriz','cantor','cantora','show','música','álbum','bbb','big brother','novela','globo','record','sbt','reality','youtube','streaming','podcast','famoso','celebridade','red carpet','oscar','grammy','coachella','lollapalooza','rock in rio'],
        'esportes'       => ['futebol','gol','campeonato','brasileirão','copa','libertadores','champions','nba','basquete','vôlei','fórmula 1','f1','mma','ufc','boxe','tênis','olimpíada','seleção','flamengo','corinthians','palmeiras','são paulo','santos','grêmio','inter','cruzeiro','atlético','botafogo','vasco','fluminense','transferência','contratação','técnico'],
        'negocios'       => ['bolsa','ação','ibovespa','dólar','câmbio','bitcoin','cripto','investimento','economia','inflação','selic','juros','pib','empresa','startup','ipo','fusão','aquisição','mercado','banco','finança','imposto','taxa'],
        'saude'          => ['saúde','vacina','doença','vírus','covid','dengue','hospital','médico','remédio','tratamento','sintoma','dieta','nutrição','exercício','mental','ansiedade','depressão','câncer','diabetes'],
        'politica'       => ['governo','presidente','lula','bolsonaro','congresso','senado','câmara','deputado','senador','ministro','stf','eleição','votação','partido','pt','pl','psd','mdb','política','reforma','lei','projeto'],
    ];

    /**
     * @param string $geo  País (BR, US, etc)
     * @param string $cat  Categoria local pra filtro
     */
    public function buscar(string $geo = 'BR', string $cat = 'all'): array
    {
        $url = "https://trends.google.com.br/trending/rss?geo={$geo}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/xml, text/xml'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            throw new RuntimeException("Google Trends RSS falhou (HTTP {$code})");
        }

        $items = $this->parseRss($resp);

        // Filtro local por categoria
        if ($cat !== 'all' && $cat !== '' && isset(self::$catKeywords[$cat])) {
            $keywords = self::$catKeywords[$cat];
            $items = array_values(array_filter($items, function($item) use ($keywords) {
                $haystack = mb_strtolower($item['title'] . ' ' . $item['news_title'], 'UTF-8');
                foreach ($keywords as $kw) {
                    if (str_contains($haystack, $kw)) return true;
                }
                return false;
            }));
        }

        return $items;
    }

    private function parseRss(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) throw new RuntimeException('XML inválido do Google Trends');

        // Registra namespaces
        $namespaces = $doc->getNamespaces(true);

        $items = [];
        foreach ($doc->channel->item as $item) {
            $title = trim((string)$item->title);
            $traffic = '';
            $newsUrl = '';
            $newsTitle = '';
            $newsSource = '';
            $image = '';
            $pubDate = (string)($item->pubDate ?? '');

            // Namespace ht: (Google Trends specific)
            if (isset($namespaces['ht'])) {
                $ht = $item->children($namespaces['ht']);
                $traffic = (string)($ht->approx_traffic ?? '');
                if (isset($ht->news_item)) {
                    $newsTitle = (string)($ht->news_item->news_item_title ?? '');
                    $newsUrl = (string)($ht->news_item->news_item_url ?? '');
                    $newsSource = (string)($ht->news_item->news_item_source ?? '');
                    if (isset($ht->news_item->news_item_picture)) {
                        $image = (string)$ht->news_item->news_item_picture;
                    }
                }
                if (!$image && isset($ht->picture)) {
                    $image = (string)$ht->picture;
                }
            }

            // Tráfego numérico
            $trafficNum = (int)preg_replace('/[^0-9]/', '', $traffic);

            $items[] = [
                'title'       => $title,
                'traffic'     => $traffic,
                'traffic_num' => $trafficNum,
                'news_url'    => $newsUrl,
                'news_title'  => $newsTitle,
                'news_source' => $newsSource,
                'image'       => $image,
                'pub_date'    => $pubDate,
            ];
        }

        // Ordena por tráfego (maior primeiro)
        usort($items, fn($a, $b) => $b['traffic_num'] - $a['traffic_num']);

        return $items;
    }

    /**
     * Explora um termo no Google Trends e retorna related queries (top + rising).
     * Usa os endpoints não-oficiais trends/api/explore e trends/api/widgetdata/relatedsearches.
     *
     * @param string $q       Termo (ex: "melhores")
     * @param string $periodo Chave de self::$periodos (ex: "now 1-H")
     * @param string $geo     País (ex: "BR")
     * @return array Lista de [query, value, formattedValue, link, type(top|rising)]
     */
    public function explorarTermo(string $q, string $periodo = 'now 1-H', string $geo = 'BR'): array
    {
        $q = trim($q);
        if ($q === '') throw new RuntimeException('Termo de trends vazio');
        if (!isset(self::$periodos[$periodo])) {
            throw new RuntimeException("Período inválido: $periodo");
        }

        $hl = 'pt-BR';
        $tz = 180;

        $cookieJar = tempnam(sys_get_temp_dir(), 'gt_');
        // Prefetch p/ capturar NID cookie
        $this->httpGet('https://trends.google.com.br/?geo=' . urlencode($geo), $cookieJar);

        // ── Step 1: Explore (obter widgets + token) ──
        $req1 = [
            'comparisonItem' => [[
                'keyword' => $q,
                'geo'     => $geo,
                'time'    => $periodo,
            ]],
            'category' => 0,
            'property' => '',
        ];
        $url1 = 'https://trends.google.com.br/trends/api/explore?' . http_build_query([
            'hl'  => $hl,
            'tz'  => $tz,
            'req' => json_encode($req1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'geo' => $geo,
        ]);
        $resp1 = $this->httpGet($url1, $cookieJar);
        if ($resp1 === '') {
            @unlink($cookieJar);
            throw new RuntimeException('Google Trends explore falhou (resposta vazia, possivelmente rate-limit)');
        }
        $data1 = json_decode($this->stripXssi($resp1), true);
        if (!$data1 || empty($data1['widgets'])) {
            @unlink($cookieJar);
            throw new RuntimeException('Google Trends explore sem widgets');
        }

        // Acha widget RELATED_QUERIES
        $rq = null;
        foreach ($data1['widgets'] as $w) {
            if (($w['id'] ?? '') === 'RELATED_QUERIES') { $rq = $w; break; }
        }
        if (!$rq) {
            @unlink($cookieJar);
            return []; // termo sem related queries no período
        }

        // ── Step 2: Related searches ──
        $url2 = 'https://trends.google.com.br/trends/api/widgetdata/relatedsearches?' . http_build_query([
            'hl'    => $hl,
            'tz'    => $tz,
            'req'   => json_encode($rq['request'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'token' => $rq['token'],
        ]);
        $resp2 = $this->httpGet($url2, $cookieJar);
        @unlink($cookieJar);
        if ($resp2 === '') {
            throw new RuntimeException('Google Trends related searches falhou');
        }
        $data2 = json_decode($this->stripXssi($resp2), true);
        if (!$data2 || empty($data2['default']['rankedList'])) return [];

        $out = [];
        $types = ['top', 'rising'];
        foreach ($data2['default']['rankedList'] as $i => $list) {
            $type = $types[$i] ?? 'top';
            foreach ($list['rankedKeyword'] ?? [] as $item) {
                $query = $item['query'] ?? '';
                if ($query === '') continue;
                $out[] = [
                    'query'          => $query,
                    'value'          => (int)($item['value'] ?? 0),
                    'formattedValue' => (string)($item['formattedValue'] ?? ''),
                    'link'           => 'https://trends.google.com.br' . ($item['link'] ?? ''),
                    'type'           => $type,
                ];
            }
        }
        return $out;
    }

    /** Remove prefixo anti-XSSI ")]}'," das respostas do Trends. */
    private function stripXssi(string $s): string
    {
        return ltrim(preg_replace('/^\)\]\}\',?/', '', ltrim($s)), " \n\r\t");
    }

    private function httpGet(string $url, string $cookieJar): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) return '';
        return (string)$resp;
    }
}
