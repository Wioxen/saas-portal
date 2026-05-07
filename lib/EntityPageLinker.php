<?php
declare(strict_types=1);

/**
 * EntityPageLinker — injeta links pra entity pages (hubs /entidade/X/) ANTES do
 * DiscoverInternalLinks contextual rodar. Concentra PageRank nos hubs (Onda 2 llm-wiki).
 *
 * Pipeline:
 *   1. Carrega lista de pages filhas de /entidade/ (cache 24h em data/entity_pages_cache/{site}.json)
 *   2. Pra cada page: extrai SIGLA (regex do título) + FULLNAME (parte antes do "(")
 *   3. Procura 1ª ocorrência de cada termo no HTML (fora de <a>, dentro de <p>)
 *   4. Injeta <a href="entity_link" data-entity-link="1">termo</a>
 *   5. Devolve URLs/IDs linkados pra excluir no DiscoverInternalLinks
 */
class EntityPageLinker
{
    private const CACHE_DIR = __DIR__ . '/../data/entity_pages_cache';
    private const CACHE_TTL = 86400;

    private Wordpress $wp;
    private string $siteSlug;
    /** @var string[] */
    private array $parentSlugs;
    private int $maxLinks;
    private string $statusList;
    private array $log = [];

    /**
     * @param string|string[] $parentSlugs Slug único ou lista (ex: ['entidade','conceito']).
     * @param string          $statusList  CSV de status WP (default 'publish'). Smoke usa 'publish,draft'.
     */
    public function __construct(Wordpress $wp, string $siteSlug, string|array $parentSlugs = 'entidade', int $maxLinks = 2, string $statusList = 'publish')
    {
        $this->wp = $wp;
        $this->siteSlug = $siteSlug;
        $this->parentSlugs = is_array($parentSlugs) ? array_values(array_filter($parentSlugs)) : [$parentSlugs];
        $this->maxLinks = $maxLinks;
        $this->statusList = $statusList;
        if (!is_dir(self::CACHE_DIR)) @mkdir(self::CACHE_DIR, 0775, true);
    }

    /**
     * Injeta até maxLinks pra entity pages no HTML.
     * Retorna ['html' => string, 'aplicados' => int, 'destinos' => [...], 'urls' => [...], 'ids' => [...]]
     */
    public function injetar(string $html): array
    {
        $pages = $this->carregarEntityPages();
        if (empty($pages)) return ['html' => $html, 'aplicados' => 0, 'destinos' => [], 'urls' => [], 'ids' => []];

        $aliasesMap = $this->carregarAliases();

        // URLs já presentes no HTML (evita duplicar)
        $urlsExistentes = [];
        if (preg_match_all('/<a\s[^>]*href=[\'"]([^\'"]+)[\'"]/i', $html, $hm)) {
            foreach ($hm[1] as $u) $urlsExistentes[$u] = true;
        }

        $aplicados = 0;
        $destinos = [];
        $urlsLinkadas = [];
        $idsLinkados = [];

        foreach ($pages as $page) {
            if ($aplicados >= $this->maxLinks) break;
            $url = (string)($page['link'] ?? '');
            $pid = (int)($page['id'] ?? 0);
            if ($url === '' || $pid === 0 || isset($urlsExistentes[$url])) continue;

            $termos = $this->extrairTermosDaPage($page, $aliasesMap[(string)$pid] ?? null);
            if (empty($termos)) continue;

            // Tenta cada termo até conseguir injetar
            foreach ($termos as $termo) {
                $novo = $this->injetarLinkInline($html, $termo, $url);
                if ($novo === null) continue;
                $html = $novo;
                $urlsExistentes[$url] = true;
                $urlsLinkadas[] = $url;
                $idsLinkados[] = $pid;
                $destinos[] = ['page_id' => $pid, 'termo' => $termo, 'link' => $url, 'titulo' => $page['title'] ?? ''];
                $aplicados++;
                break;
            }
        }

        return ['html' => $html, 'aplicados' => $aplicados, 'destinos' => $destinos, 'urls' => $urlsLinkadas, 'ids' => $idsLinkados];
    }

    /**
     * Carrega entity/concept pages dos parents configurados (cache TTL 24h).
     * Cache em data/entity_pages_cache/{site}_{parents}.json.
     */
    public function carregarEntityPages(): array
    {
        $parentsKey = implode('-', $this->parentSlugs);
        $path = self::CACHE_DIR . "/{$this->siteSlug}_{$parentsKey}.json";
        if (file_exists($path) && (time() - filemtime($path)) < self::CACHE_TTL) {
            $j = json_decode((string)file_get_contents($path), true);
            if (is_array($j) && !empty($j['pages'])) return $j['pages'];
        }
        $todas = [];
        foreach ($this->parentSlugs as $parent) {
            try {
                $r = $this->wp->listarEntityPages($parent, 100, $this->statusList);
                foreach ($r as $p) $todas[] = $p;
            } catch (Throwable $e) {
                $this->log[] = "erro_load[{$parent}]: " . $e->getMessage();
            }
        }
        @file_put_contents($path, json_encode([
            'site' => $this->siteSlug,
            'parent_slugs' => $this->parentSlugs,
            'updated_at' => date('c'),
            'pages' => $todas,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $todas;
    }

    /**
     * Carrega mapping local de aliases (mantido pelo EntityHubBuilder).
     * Estrutura: {page_id: {tipo, nome, fullname, slug, aliases: [...]}}
     */
    private function carregarAliases(): array
    {
        $path = self::CACHE_DIR . "/{$this->siteSlug}_aliases.json";
        if (!file_exists($path)) return [];
        $j = json_decode((string)file_get_contents($path), true);
        return is_array($j) ? $j : [];
    }

    /**
     * Extrai termos linkáveis. Se mapping local de aliases existe, usa ele (preciso).
     * Senão, extrai sigla+fullname do título da page (heurística).
     *
     * Ordem: termos mais específicos primeiro (sigla > fullname > aliases curtos).
     */
    private function extrairTermosDaPage(array $page, ?array $aliasesData = null): array
    {
        $termos = [];

        // Caminho preferido: mapping local mantido pelo EntityHubBuilder
        if ($aliasesData) {
            $tipo = (string)($aliasesData['tipo'] ?? 'entity');
            if ($tipo === 'entity') {
                if (!empty($aliasesData['nome'])) $termos[] = (string)$aliasesData['nome'];
                if (!empty($aliasesData['fullname'])) $termos[] = (string)$aliasesData['fullname'];
            } else { // concept
                if (!empty($aliasesData['fullname'])) $termos[] = (string)$aliasesData['fullname'];
            }
            foreach (($aliasesData['aliases'] ?? []) as $a) {
                $a = trim((string)$a);
                if ($a !== '') $termos[] = $a;
            }
        } else {
            // Fallback: extrai do título
            $titulo = (string)($page['title'] ?? '');
            if (preg_match('/\(([A-Z][A-Za-z0-9\-\.]{1,7})\)/', $titulo, $m)) {
                $termos[] = trim($m[1]);
            }
            if (preg_match('/^(.+?)\s*\(/', $titulo, $m)) {
                $full = trim($m[1]);
                if (mb_strlen($full) >= 10) $termos[] = $full;
            }
            // Concept page: título sem parênteses → "{fullname} — guia..."
            if (empty($termos) && preg_match('/^(.+?)\s+—/u', $titulo, $m)) {
                $termos[] = trim($m[1]);
            }
        }

        // Ordena: específicos primeiro (sigla 2-8 maiúsculas), depois por comprimento desc
        usort($termos, function ($a, $b) {
            $aSigla = preg_match('/^[A-Z][A-Za-z0-9\-\.]{1,7}$/', $a) ? 1 : 0;
            $bSigla = preg_match('/^[A-Z][A-Za-z0-9\-\.]{1,7}$/', $b) ? 1 : 0;
            if ($aSigla !== $bSigla) return $bSigla - $aSigla;
            return mb_strlen($b) - mb_strlen($a);
        });

        return array_values(array_unique($termos));
    }

    /**
     * Injeta link inline na 1ª ocorrência do termo dentro de <p>.
     * 2 estágios:
     *   1) Isola blocos protegidos (h1-h6, classes especiais de <p>, details/script/style)
     *   2) Dentro de <p>...</p> elegível, divide o INTERIOR por <a>...</a> e injeta só nos pedaços livres
     *
     * Cobre <strong>termo</strong> também (transforma em <a><strong>).
     */
    private function injetarLinkInline(string $html, string $termo, string $url): ?string
    {
        $termoEscaped = preg_quote($termo, '#');

        // Estágio 1: blocos NÃO-elegíveis (h1-h6, <p class=especial>, details/script/style)
        // <a> NÃO entra aqui — vai ser tratado dentro do <p> no estágio 2 (preserva <p>...</p>)
        $padraoProtegido = '#(<h[1-6][^>]*>.*?</h[1-6]>|<p\b[^>]*class\s*=\s*[\'"][^\'"]*(?:resposta-direta|snippet-resumo|leia-mais|leia-tambem|alerta-critico|fonte-rodape)[^\'"]*[\'"][^>]*>.*?</p>|<details\b.*?</details>|<script\b.*?</script>|<style\b.*?</style>)#is';

        $partes = preg_split($padraoProtegido, $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($partes) || empty($partes)) return null;

        foreach ($partes as $i => $seg) {
            if (preg_match($padraoProtegido, $seg)) continue;

            // Estágio 2: encontra <p>...</p> e processa interior dividindo por <a>
            $alterado = false;
            $novo = preg_replace_callback(
                '#<p\b([^>]*)>(.*?)</p>#is',
                function ($m) use ($termoEscaped, $termo, $url, &$alterado) {
                    if ($alterado) return $m[0];
                    $attrs = $m[1];
                    $body = $m[2];

                    // Divide o interior do <p> em pedaços: texto vs <a>...</a>
                    $bodyParts = preg_split('#(<a\b[^>]*>.*?</a>)#is', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                    if (!is_array($bodyParts)) return $m[0];

                    $modificado = false;
                    foreach ($bodyParts as $j => $bp) {
                        // Pula se é um <a> existente
                        if (preg_match('/^<a\b/i', $bp)) continue;

                        // <strong>TERMO</strong> dentro deste pedaço de texto livre
                        $padStrong = '#(<strong[^>]*>)\s*(' . $termoEscaped . ')\s*(</strong>)#u';
                        if (!$modificado && preg_match($padStrong, $bp)) {
                            $bodyParts[$j] = preg_replace_callback($padStrong, function ($mm) use ($url, &$modificado) {
                                if ($modificado) return $mm[0];
                                $modificado = true;
                                return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" data-entity-link="1">' . $mm[1] . $mm[2] . $mm[3] . '</a>';
                            }, $bp, 1);
                            if ($modificado) break;
                        }

                        // TERMO solto (boundary de palavra), em texto puro
                        $padSolto = '#(?<![\w<\-/])' . $termoEscaped . '(?![\w\-])#u';
                        if (!$modificado && preg_match($padSolto, $bp)) {
                            $bodyParts[$j] = preg_replace_callback($padSolto, function ($mm) use ($termo, $url, &$modificado) {
                                if ($modificado) return $mm[0];
                                $modificado = true;
                                return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" data-entity-link="1">' . $termo . '</a>';
                            }, $bp, 1);
                            if ($modificado) break;
                        }
                    }

                    if ($modificado) {
                        $alterado = true;
                        return '<p' . $attrs . '>' . implode('', $bodyParts) . '</p>';
                    }
                    return $m[0];
                },
                $seg
            );

            if ($alterado) {
                $partes[$i] = $novo;
                return implode('', $partes);
            }
        }

        return null;
    }

    public function getLog(): array { return $this->log; }
}
