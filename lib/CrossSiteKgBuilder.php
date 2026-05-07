<?php
declare(strict_types=1);

/**
 * CrossSiteKgBuilder — monta JSON-LD adicional declarando rede editorial entre
 * sites irmãos (mesma editora `empresa.nome`).
 *
 * Coexiste com Rank Math: gera <script type="application/ld+json"> separado,
 * NÃO substitui Article/Org/Breadcrumb que Rank Math já emite.
 *
 * Schema usado:
 *   - Organization (a editora — Sistema 2 Conteúdo Educacional, Sistema 3 Mídia Digital)
 *   - owns: WebSite[] dos sites irmãos
 *
 * Saída: array PHP pronto pra json_encode, ou null se site não tem irmãos.
 */
class CrossSiteKgBuilder
{
    private string $siteSlug;
    private array $cfgSiteAtual;
    private array $sitesGlobais;

    /**
     * @param array $sitesGlobais Mapa retornado por sitesDisponiveis(): ['slug' => cfg, ...]
     */
    public function __construct(string $siteSlug, array $cfgSiteAtual, array $sitesGlobais)
    {
        $this->siteSlug = $siteSlug;
        $this->cfgSiteAtual = $cfgSiteAtual;
        $this->sitesGlobais = $sitesGlobais;
    }

    /**
     * Monta JSON-LD da editora + sites irmãos. Retorna null se não há irmãos.
     */
    public function montar(): ?array
    {
        $empresaNome = (string)($this->cfgSiteAtual['empresa']['nome'] ?? '');
        $empresaDesc = (string)($this->cfgSiteAtual['empresa']['descricao'] ?? '');
        if ($empresaNome === '') return null;

        $irmaos = $this->encontrarIrmaos($empresaNome);
        if (count($irmaos) < 2) return null; // sozinho não tem rede

        $owns = [];
        foreach ($irmaos as $slug => $cfg) {
            $url = rtrim((string)($cfg['wp_url'] ?? ''), '/') . '/';
            $name = (string)($cfg['site_name'] ?? $cfg['name'] ?? $slug);
            if ($url === '/' || $name === '') continue;

            // sameAs: aponta pros outros irmãos (não inclui a si mesmo)
            $sameAs = [];
            foreach ($irmaos as $otherSlug => $otherCfg) {
                if ($otherSlug === $slug) continue;
                $otherUrl = rtrim((string)($otherCfg['wp_url'] ?? ''), '/') . '/';
                if ($otherUrl !== '/') $sameAs[] = $otherUrl;
            }

            $owns[] = [
                '@type' => 'WebSite',
                '@id' => $url . '#website',
                'name' => $name,
                'url' => $url,
                'description' => (string)($cfg['subtipo_nicho'] ?? ''),
                'sameAs' => $sameAs,
            ];
        }

        $urlAtual = rtrim((string)($this->cfgSiteAtual['wp_url'] ?? ''), '/') . '/';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $urlAtual . '#publisher',
            'name' => $empresaNome,
            'description' => $empresaDesc,
            'owns' => $owns,
        ];
    }

    /**
     * Encontra sites com mesma empresa.nome (irmãos) E com entity_pages_enabled=true.
     * Filtra LPs de afiliado/arbitragem (que tecnicamente são da mesma editora mas não são portais editoriais).
     */
    private function encontrarIrmaos(string $empresaNome): array
    {
        $irmaos = [];
        foreach ($this->sitesGlobais as $slug => $cfg) {
            $nomeOutro = (string)($cfg['empresa']['nome'] ?? '');
            if ($nomeOutro === '' || $nomeOutro !== $empresaNome) continue;
            if (empty($cfg['entity_pages_enabled'])) continue;
            $irmaos[$slug] = $cfg;
        }
        return $irmaos;
    }

    /**
     * Renderiza apenas o <script> JSON-LD pra injetar no HTML.
     */
    public function renderizarScript(?array $jsonld = null): string
    {
        $jsonld = $jsonld ?? $this->montar();
        if (empty($jsonld)) return '';

        return "\n<script type=\"application/ld+json\" data-cross-site-kg=\"1\">\n"
             . json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
             . "\n</script>\n";
    }
}
