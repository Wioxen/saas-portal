<?php
/**
 * TrendsArticles — resolve artigos reais (URL + snippet) para um trend.
 *
 * Fluxo:
 *   1. Consulta Google News RSS pelo termo
 *   2. Para cada item, resolve a URL real via Serper (resolverViaTitulo)
 *   3. Opcionalmente faz scrape do conteúdo via lib/Scraper
 *   4. Cacheia em data/articles_cache/<hash>.json (TTL padrão 1h)
 *
 * Uso:
 *   $ta = new TrendsArticles($serper, $scraper, $userAgent);
 *   $artigos = $ta->listar('flamengo x indep. medellín', 5);
 *   $comTexto = $ta->enriquecer($artigos);  // faz scrape real
 */
class TrendsArticles
{
    private Serper $serper;
    private ?Scraper $scraper;
    private GoogleNewsRss $rss;
    private string $cacheDir;
    private int $ttl;

    public function __construct(Serper $serper, ?Scraper $scraper = null, string $userAgent = '', int $ttl = 3600)
    {
        $this->serper   = $serper;
        $this->scraper  = $scraper;
        $this->rss      = new GoogleNewsRss($userAgent, 15, $serper);
        $this->cacheDir = __DIR__ . '/../data/articles_cache';
        $this->ttl      = $ttl;
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0777, true);
    }

    /**
     * Lista artigos de um termo com URL resolvida.
     * @return array [{title, source, url_real, url_gnews, pubDate, description}]
     */
    public function listar(string $termo, int $max = 5): array
    {
        $termo = trim($termo);
        if ($termo === '') return [];

        $cacheKey  = md5('list_' . mb_strtolower($termo, 'UTF-8') . '_' . $max);
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';

        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $this->ttl)) {
            $c = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($c)) return $c;
        }

        $rssUrl = 'https://news.google.com/rss/search?q=' . urlencode($termo)
                . '&hl=pt-BR&gl=BR&ceid=BR:pt-419';

        try {
            $items = $this->rss->parseRss($rssUrl, $max);
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($items as $it) {
            $real = $this->rss->resolverViaTitulo($it['title'], $it['source']);
            $out[] = [
                'title'       => $it['title'],
                'source'      => $it['source'],
                'url_real'    => $real,
                'url_gnews'   => $it['link'],
                'pubDate'     => $it['pubDate'],
                'description' => $it['description'],
            ];
        }

        file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE));
        return $out;
    }

    /**
     * Enriquece artigos com conteúdo scrapeado (title, texto limpo, imagem).
     * Faz scrape só dos que têm url_real.
     * @return array com campo extra `scrape` em cada item
     */
    public function enriquecer(array $artigos, int $maxScrape = 3): array
    {
        if ($this->scraper === null) return $artigos;

        $feitos = 0;
        foreach ($artigos as &$a) {
            if ($feitos >= $maxScrape) break;
            if (empty($a['url_real'])) continue;
            try {
                $a['scrape'] = $this->scraper->fetch($a['url_real']);
                $feitos++;
            } catch (Throwable $e) {
                $a['scrape'] = ['erro' => $e->getMessage()];
            }
        }
        unset($a);
        return $artigos;
    }

    public function limparCache(): int
    {
        $n = 0;
        foreach (glob($this->cacheDir . '/*.json') ?: [] as $f) {
            if (@unlink($f)) $n++;
        }
        return $n;
    }
}
