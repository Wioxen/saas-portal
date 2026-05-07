<?php
declare(strict_types=1);

/**
 * KnowledgeGraphBuilder — monta Schema.org JSON-LD do KG do site (entidades+conceitos+relações).
 * Onda 3 llm-wiki: sinaliza ao Google a estrutura semântica do portal.
 *
 * Saída: array PHP pronto pra json_encode com Schema.org CollectionPage + ItemList.
 * Cada item é uma DefinedTerm (concept) ou Organization (entity), com link, descrição,
 * sameAs (URL oficial pra entity), keywords (aliases), e about (relações detectadas).
 */
class KnowledgeGraphBuilder
{
    private const ALIASES_DIR = __DIR__ . '/../data/entity_pages_cache';

    private Wordpress $wp;
    private string $siteSlug;
    private string $siteUrl;
    private string $siteName;

    public function __construct(Wordpress $wp, string $siteSlug, string $siteUrl, string $siteName)
    {
        $this->wp = $wp;
        $this->siteSlug = $siteSlug;
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->siteName = $siteName;
    }

    /**
     * Monta o JSON-LD completo. Retorna ['jsonld' => array, 'humano' => array].
     *   jsonld: estrutura Schema.org pronta pra json_encode
     *   humano: dados estruturados pra render HTML legível
     */
    public function montar(): array
    {
        $aliases = $this->carregarAliases();
        if (empty($aliases)) {
            return ['jsonld' => null, 'humano' => ['erro' => 'aliases.json vazio']];
        }

        // Pré-carrega URLs canônicas das pages (REST traz status=publish)
        $urlsPorId = $this->mapearUrls(array_keys($aliases));

        $entities = [];
        $concepts = [];
        $todos = [];

        foreach ($aliases as $pageId => $info) {
            $pid = (int)$pageId;
            $url = $urlsPorId[$pid] ?? '';
            if ($url === '') continue; // skip pages não publicadas

            $tipo = (string)($info['tipo'] ?? 'entity');
            $fullname = (string)($info['fullname'] ?? '');
            $nome = (string)($info['nome'] ?? '');
            $aliasesArr = array_values((array)($info['aliases'] ?? []));

            $itemBase = [
                '@type' => $tipo === 'concept' ? 'DefinedTerm' : 'Organization',
                '@id' => $url . '#hub',
                'name' => $tipo === 'concept' ? $fullname : ($nome !== '' ? "{$fullname} ({$nome})" : $fullname),
                'alternateName' => $aliasesArr,
                'url' => $url,
            ];

            // Adiciona description se existir descricao_seed em algum config (não tá no aliases.json hoje)
            // Fallback: nada.

            $todos[] = $itemBase;

            $registro = [
                'page_id' => $pid,
                'tipo' => $tipo,
                'fullname' => $fullname,
                'nome' => $nome,
                'aliases' => $aliasesArr,
                'url' => $url,
                'slug' => (string)($info['slug'] ?? ''),
            ];

            if ($tipo === 'concept') {
                $concepts[] = $registro;
            } else {
                $entities[] = $registro;
            }
        }

        // Relações: entity-concept que aparecem juntas em posts (proxy: ambos têm aliases que casam)
        // V0: relação simples baseada em "concepts conhecidos do nicho" — todas entities relacionam
        // a todas concepts (autoridade temática mútua). Refinamento posterior pode usar EntityExtractor
        // pra detectar quais pares aparecem juntos com mais frequência.
        foreach ($todos as $idx => $item) {
            // Adiciona "isPartOf" pro CollectionPage pai
            $todos[$idx]['isPartOf'] = ['@id' => $this->siteUrl . '/knowledge-graph/#collection'];
        }

        // Wrapper Schema.org
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $this->siteUrl . '/knowledge-graph/#collection',
            'name' => "Mapa de Conhecimento — {$this->siteName}",
            'description' => 'Estrutura semântica do portal: entidades educacionais (institutos, órgãos, sistemas S) e conceitos transversais (modalidades, processos seletivos), com relações editoriais agregadas.',
            'url' => $this->siteUrl . '/knowledge-graph/',
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $this->siteName,
                'url' => $this->siteUrl . '/',
            ],
            'dateModified' => date('c'),
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count($todos),
                'itemListElement' => array_map(
                    fn($i, $item) => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $item],
                    array_keys($todos),
                    $todos
                ),
            ],
        ];

        return [
            'jsonld' => $jsonLd,
            'humano' => [
                'entities' => $entities,
                'concepts' => $concepts,
                'total' => count($todos),
                'site_name' => $this->siteName,
                'site_url' => $this->siteUrl,
                'updated_at' => date('c'),
            ],
        ];
    }

    /**
     * Renderiza HTML legível pra humanos (page WP). Inclui o JSON-LD inline no fim.
     */
    public function renderizarHtml(array $jsonld, array $humano): string
    {
        $html = '';
        $html .= "<p>Visão estruturada das entidades e conceitos cobertos editorialmente por <strong>{$this->siteName}</strong>. Cada item é um hub navegável com sumário, posts agregados e perguntas frequentes.</p>\n";
        $html .= "<p><em>Atualizado em " . date('d/m/Y H:i', strtotime($humano['updated_at'])) . " · " . $humano['total'] . " hubs ativos</em></p>\n\n";

        if (!empty($humano['entities'])) {
            $html .= "<h2>Entidades educacionais (" . count($humano['entities']) . ")</h2>\n";
            $html .= "<p>Institutos, órgãos públicos, sistemas S e autarquias com cobertura editorial dedicada:</p>\n<ul>\n";
            foreach ($humano['entities'] as $e) {
                $rotulo = $e['nome'] !== '' ? "{$e['fullname']} (<strong>{$e['nome']}</strong>)" : "<strong>{$e['fullname']}</strong>";
                $aliasesStr = !empty($e['aliases']) ? ' <em>· também conhecido como: ' . htmlspecialchars(implode(', ', $e['aliases'])) . '</em>' : '';
                $html .= "<li><a href=\"{$e['url']}\" data-internal-link=\"1\">{$rotulo}</a>{$aliasesStr}</li>\n";
            }
            $html .= "</ul>\n\n";
        }

        if (!empty($humano['concepts'])) {
            $html .= "<h2>Conceitos e modalidades (" . count($humano['concepts']) . ")</h2>\n";
            $html .= "<p>Modalidades educacionais, processos seletivos e categorias transversais:</p>\n<ul>\n";
            foreach ($humano['concepts'] as $c) {
                $aliasesStr = !empty($c['aliases']) ? ' <em>· sinônimos: ' . htmlspecialchars(implode(', ', $c['aliases'])) . '</em>' : '';
                $html .= "<li><a href=\"{$c['url']}\" data-internal-link=\"1\"><strong>{$c['fullname']}</strong></a>{$aliasesStr}</li>\n";
            }
            $html .= "</ul>\n\n";
        }

        $html .= "<h2>Sobre este Mapa</h2>\n";
        $html .= "<p>Este Mapa de Conhecimento é regenerado automaticamente sempre que novos hubs são adicionados ao portal. Cada hub agrega cobertura editorial recente, FAQ crescente, sumário enciclopédico verificado e fontes oficiais. A estrutura abaixo (Schema.org) é consumível por sistemas de busca, agregadores e crawlers semânticos.</p>\n\n";

        // JSON-LD inline (Google consome)
        $html .= '<script type="application/ld+json">' . "\n";
        $html .= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $html .= "\n</script>\n";

        return $html;
    }

    /** Mapeia page_id → URL canônica via WP REST (filtra publish). */
    private function mapearUrls(array $pageIds): array
    {
        $out = [];
        foreach ($pageIds as $id) {
            $pid = (int)$id;
            try {
                $p = $this->wp->getPagina($pid);
                $status = (string)($p['status'] ?? '');
                if ($status === 'publish') {
                    $out[$pid] = (string)($p['link'] ?? '');
                }
            } catch (Throwable $e) { /* skip */ }
        }
        return $out;
    }

    private function carregarAliases(): array
    {
        $path = self::ALIASES_DIR . "/{$this->siteSlug}_aliases.json";
        if (!file_exists($path)) return [];
        $j = json_decode((string)file_get_contents($path), true);
        return is_array($j) ? $j : [];
    }
}
