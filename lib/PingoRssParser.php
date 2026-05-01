<?php
/**
 * PingoRssParser — parser tolerante de RSS 2.0 + Atom.
 *
 * Converte XML em lista normalizada de items:
 *   ['link' => ..., 'guid' => ..., 'title' => ..., 'description' => ..., 'pub_ts' => int, 'categorias' => [...]]
 *
 * Design choices:
 *  - Não usa libs externas (SimpleXML do PHP nativo)
 *  - libxml_use_internal_errors(true) — feeds malformados não derrubam a classe
 *  - Normaliza encoding pra UTF-8 agressivamente
 *  - Detecta Atom vs RSS 2.0 automaticamente
 */
class PingoRssParser
{
    /**
     * Parse um XML string e retorna items normalizados.
     * @param string $xml conteúdo raw (já baixado)
     * @param int $maxItems limite de items retornados (ordem do feed)
     * @return array
     */
    public static function parse(string $xml, int $maxItems = 50): array
    {
        if ($xml === '') return [];

        // Normalização de encoding:
        //   - Se o XML declara encoding na tag, deixa SimpleXML converter sozinho
        //     (pré-conversão causaria dupla-decode).
        //   - Se NAO declara E os bytes não são UTF-8 válido, forçamos UTF-8.
        $inicio = substr($xml, 0, 200);
        $temDeclaracaoEncoding = (stripos($inicio, 'encoding=') !== false) && (stripos($inicio, 'xml') !== false);
        if (!$temDeclaracaoEncoding && !mb_check_encoding($xml, 'UTF-8')) {
            $xml = self::forcarUtf8($xml);
        }

        $previousUse = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } catch (Throwable $e) {
            libxml_use_internal_errors($previousUse);
            return [];
        }
        libxml_use_internal_errors($previousUse);

        if ($sx === false || $sx === null) return [];

        // Detecta formato. SimpleXML::getName() retorna só o nome local sem prefixo,
        // então <rdf:RDF> vira "RDF". Já <rss> vira "rss" e <feed> vira "feed".
        $rootName = strtolower($sx->getName());

        if ($rootName === 'feed') {
            return self::parseAtom($sx, $maxItems);
        }

        // RDF (RSS 1.0 / Plone) — items são SIBLINGS do <channel>, não filhos.
        // Detecta ANTES do check de $sx->channel porque RDF TEM channel (com metadata),
        // mas os items estão no nível root. Característica: namespace rdf no root.
        $ns = $sx->getDocNamespaces(true);
        $ehRdf = ($rootName === 'rdf' || $rootName === 'rdf:rdf')
              || isset($ns['rdf'])
              || (isset($ns['']) && $ns[''] === 'http://purl.org/rss/1.0/');
        if ($ehRdf) {
            return self::parseRss2($sx, $maxItems);
        }

        // RSS 2.0 padrão (<rss><channel><item/>...)
        if (isset($sx->channel)) {
            return self::parseRss2($sx->channel, $maxItems);
        }
        // Fallback
        if (isset($sx->item)) {
            return self::parseRss2($sx, $maxItems);
        }

        return [];
    }

    /** Parse Atom (<feed><entry/>...) */
    private static function parseAtom(SimpleXMLElement $feed, int $maxItems): array
    {
        $items = [];
        $entries = $feed->entry ?? [];
        foreach ($entries as $e) {
            if (count($items) >= $maxItems) break;
            $link = '';
            // Atom: <link href="..." rel="alternate"/>  (pode ter vários)
            if (isset($e->link)) {
                foreach ($e->link as $l) {
                    $attrs = $l->attributes();
                    $rel = (string)($attrs['rel'] ?? 'alternate');
                    if ($rel === 'alternate' || $rel === '') {
                        $link = (string)($attrs['href'] ?? '');
                        break;
                    }
                }
                if ($link === '' && isset($e->link[0])) {
                    $link = (string)($e->link[0]->attributes()['href'] ?? '');
                }
            }
            $title = self::clean((string)($e->title ?? ''));
            $desc  = self::clean((string)($e->summary ?? $e->content ?? ''));
            $guid  = (string)($e->id ?? $link);
            $pubTs = self::parseDate((string)($e->updated ?? $e->published ?? ''));

            $categorias = [];
            if (isset($e->category)) {
                foreach ($e->category as $c) {
                    $term = (string)($c->attributes()['term'] ?? (string)$c);
                    if ($term !== '') $categorias[] = $term;
                }
            }

            if ($title === '' && $link === '') continue;
            $items[] = [
                'link'        => $link,
                'guid'        => $guid,
                'title'       => $title,
                'description' => $desc,
                'pub_ts'      => $pubTs,
                'categorias'  => $categorias,
            ];
        }
        return $items;
    }

    /** Parse RSS 2.0 ou RDF. $channel pode ser <channel> ou o root do RDF. */
    private static function parseRss2(SimpleXMLElement $channel, int $maxItems): array
    {
        $items = [];
        $list = $channel->item ?? [];
        foreach ($list as $it) {
            if (count($items) >= $maxItems) break;
            $link  = self::extrairLink($it);
            $title = self::clean((string)($it->title ?? ''));
            $desc  = self::clean((string)($it->description ?? ''));
            $guid  = (string)($it->guid ?? $link);
            // Data: pubDate (RSS 2.0) ou dc:date (RDF/Atom-híbrido)
            $dataRaw = (string)($it->pubDate ?? '');
            if ($dataRaw === '') {
                $dc = $it->children('dc', true);
                if ($dc && isset($dc->date)) $dataRaw = (string)$dc->date;
            }
            $pubTs = self::parseDate($dataRaw);

            $categorias = [];
            if (isset($it->category)) {
                foreach ($it->category as $c) {
                    $term = self::clean((string)$c);
                    if ($term !== '') $categorias[] = $term;
                }
            }

            if ($title === '' && $link === '') continue;
            $items[] = [
                'link'        => $link,
                'guid'        => $guid,
                'title'       => $title,
                'description' => $desc,
                'pub_ts'      => $pubTs,
                'categorias'  => $categorias,
            ];
        }
        return $items;
    }

    /** Google News RSS embute o link real em uma query — extrai se detectado. */
    private static function extrairLink(SimpleXMLElement $it): string
    {
        $link = trim((string)($it->link ?? ''));
        // Alguns feeds usam <link> como wrapper de children
        if ($link === '') {
            foreach ($it->children() as $child) {
                if (strtolower($child->getName()) === 'link') {
                    $href = (string)$child->attributes()['href'];
                    if ($href !== '') { $link = $href; break; }
                }
            }
        }
        return $link;
    }

    /** Converte data em timestamp Unix. Aceita RFC2822, ISO8601, formato comum. */
    private static function parseDate(string $data): int
    {
        if ($data === '') return 0;
        $ts = strtotime($data);
        return $ts === false ? 0 : $ts;
    }

    /** Força conteúdo a UTF-8 válido. */
    private static function forcarUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) return $s;
        $conv = @mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
        return $conv !== false ? $conv : $s;
    }

    /** Remove tags HTML + entidades + trim + colapsa espaços. */
    private static function clean(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}
