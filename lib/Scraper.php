<?php
/**
 * Scraper de páginas web — extrai conteúdo limpo pra alimentar a IA.
 *
 * Estratégia (sem dependências externas):
 *  1. Baixa HTML com User-Agent de browser
 *  2. Parseia com DOMDocument (libxml warnings suprimidos)
 *  3. Remove ruído: script, style, nav, header, footer, aside, .ads, etc.
 *  4. Extrai metadata: title, og:image, og:title, meta description, JSON-LD
 *  5. Identifica conteúdo principal por heurística (article > main > maior bloco)
 *  6. Coleta H1-H6, parágrafos, listas, imagens com alt
 */
class Scraper
{
    private string $userAgent;
    private int $timeout;

    public function __construct(string $userAgent, int $timeout = 15)
    {
        $this->userAgent = $userAgent;
        $this->timeout   = $timeout;
    }

    public function fetch(string $url): array
    {
        $html = $this->download($url);
        if ($html === null) {
            throw new RuntimeException("Falha ao baixar: $url");
        }
        return $this->parse($html, $url);
    }

    private function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400 || strlen($body) < 500) return null;
        return $body;
    }

    private function parse(string $html, string $url): array
    {
        // Garante UTF-8
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', mb_detect_encoding($html, 'UTF-8, ISO-8859-1', true) ?: 'UTF-8');

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xp = new DOMXPath($dom);

        $meta = [
            'url'          => $url,
            'title'        => $this->getMeta($xp, 'og:title') ?: $this->getTitleTag($dom),
            'description'  => $this->getMeta($xp, 'og:description') ?: $this->getMetaName($xp, 'description'),
            'og_image'     => $this->getMeta($xp, 'og:image'),
            'site_name'    => $this->getMeta($xp, 'og:site_name') ?: parse_url($url, PHP_URL_HOST),
            'author'       => $this->getMetaName($xp, 'author'),
            'published'    => $this->getMeta($xp, 'article:published_time'),
            'jsonld'       => $this->getJsonLd($xp),
        ];

        // Remove ruído
        $this->removeNodes($xp, '//script | //style | //noscript | //iframe | //nav | //header | //footer | //aside | //form');
        $this->removeNodes($xp, '//*[contains(@class,"ads") or contains(@class,"advertis") or contains(@class,"comment") or contains(@class,"share") or contains(@class,"related") or contains(@class,"sidebar") or contains(@class,"newsletter")]');

        // Acha conteúdo principal
        $main = $this->findMain($xp);

        $content = [
            'headings'   => [],
            'paragraphs' => [],
            'lists'      => [],
            'images'     => [],
        ];

        if ($main) {
            // Headings
            foreach ($xp->query('.//h1 | .//h2 | .//h3 | .//h4', $main) as $h) {
                $txt = $this->cleanText($h->textContent);
                if ($txt !== '' && mb_strlen($txt) < 200) {
                    $content['headings'][] = ['tag' => $h->nodeName, 'text' => $txt];
                }
            }

            // Parágrafos (filtra linhas que são só datas desatualizadas)
            $anoAtual = (int)date('Y');
            foreach ($xp->query('.//p', $main) as $p) {
                $txt = $this->cleanText($p->textContent);
                if (mb_strlen($txt) < 40) continue;

                // Remove parágrafos que são só data/atualização de anos anteriores
                if (preg_match('/^(atualizado|publicado|revisado)\s+(em\s+)?\w+\s+de\s+\d{4}/iu', $txt)) {
                    if (!preg_match('/\b' . $anoAtual . '\b/', $txt)) {
                        continue; // data antiga, pula
                    }
                }

                $content['paragraphs'][] = $txt;
            }

            // Fallback: se nenhum <p> foi encontrado, tenta <div> com texto longo
            // (sites com markup não-padrão usam div em vez de p)
            if (empty($content['paragraphs'])) {
                foreach ($xp->query('.//div[not(descendant::div)]', $main) as $div) {
                    $txt = $this->cleanText($div->textContent);
                    if (mb_strlen($txt) >= 60 && mb_strlen($txt) < 2000) {
                        $content['paragraphs'][] = $txt;
                    }
                }
            }

            // Listas
            foreach ($xp->query('.//ul | .//ol', $main) as $list) {
                $items = [];
                foreach ($xp->query('.//li', $list) as $li) {
                    $t = $this->cleanText($li->textContent);
                    if ($t !== '') $items[] = $t;
                }
                if (count($items) >= 2) $content['lists'][] = $items;
            }

            // Imagens com alt
            foreach ($xp->query('.//img', $main) as $img) {
                $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
                $alt = $img->getAttribute('alt');
                if ($src) {
                    $content['images'][] = ['src' => $this->absUrl($src, $url), 'alt' => $alt];
                }
            }
        }

        return ['meta' => $meta, 'content' => $content];
    }

    private function findMain(DOMXPath $xp): ?DOMNode
    {
        // Tenta tags semânticas primeiro
        foreach (['//article', '//main', '//*[@role="main"]'] as $q) {
            $nodes = $xp->query($q);
            if ($nodes->length > 0) {
                // Retorna o maior por quantidade de texto
                $best = null; $bestLen = 0;
                foreach ($nodes as $n) {
                    $len = strlen($n->textContent);
                    if ($len > $bestLen) { $best = $n; $bestLen = $len; }
                }
                if ($bestLen > 500) return $best;
            }
        }
        // Fallback: <body>
        $body = $xp->query('//body');
        return $body->length ? $body->item(0) : null;
    }

    private function removeNodes(DOMXPath $xp, string $query): void
    {
        $nodes = $xp->query($query);
        $toRemove = [];
        foreach ($nodes as $n) $toRemove[] = $n;
        foreach ($toRemove as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
    }

    private function getMeta(DOMXPath $xp, string $property): ?string
    {
        $n = $xp->query("//meta[@property='$property']/@content");
        return $n->length ? trim($n->item(0)->nodeValue) : null;
    }

    private function getMetaName(DOMXPath $xp, string $name): ?string
    {
        $n = $xp->query("//meta[@name='$name']/@content");
        return $n->length ? trim($n->item(0)->nodeValue) : null;
    }

    private function getTitleTag(DOMDocument $dom): ?string
    {
        $t = $dom->getElementsByTagName('title');
        return $t->length ? trim($t->item(0)->textContent) : null;
    }

    private function getJsonLd(DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query("//script[@type='application/ld+json']") as $s) {
            $j = json_decode(trim($s->textContent), true);
            if ($j) $out[] = $j;
        }
        return $out;
    }

    private function cleanText(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function absUrl(string $src, string $base): string
    {
        if (preg_match('#^https?://#', $src)) return $src;
        if (str_starts_with($src, '//')) return 'https:' . $src;
        $p = parse_url($base);
        if (str_starts_with($src, '/')) return $p['scheme'] . '://' . $p['host'] . $src;
        return $p['scheme'] . '://' . $p['host'] . '/' . ltrim($src, '/');
    }
}
