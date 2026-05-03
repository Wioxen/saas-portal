<?php
declare(strict_types=1);

/**
 * InternalLinkGlossary — sistema de backlinks fixos termo → URL canônica do site.
 *
 * Diferente de DiscoverInternalLinks (que BUSCA posts publicados pela keyword),
 * este é um GLOSSÁRIO ESTÁTICO definido em sites.php: cada termo recorrente
 * aponta sempre pra MESMA URL canônica. Isso constrói cluster topical authority
 * — Google entende que o site tem páginas-hub específicas pra cada entidade.
 *
 * Caso de uso (leaodabarra, pivot 2026-05-02):
 *   - 'Esporte Clube Vitória' → /historia-do-esporte-clube-vitoria/
 *   - 'Copa do Nordeste'      → /category/copa-do-nordeste/
 *   - 'Barradão'              → /barradao/
 *   - 'Jair Ventura'          → /tecnico/jair-ventura/
 *
 * Regras de injeção:
 *   - Linkar APENAS 1ª ocorrência de cada termo no body (evita spam)
 *   - NÃO linkar dentro de tags estruturais: <h1>, <h2>, <h3>, <h4>, <table>,
 *     <thead>, <tbody>, <tr>, <td>, <th>, <details>, <summary>, <a>, <script>
 *   - Termos MAIS LONGOS têm prioridade (linkar 'Esporte Clube Vitória' antes
 *     de 'Vitória' sozinho)
 *   - Match case-insensitive com word boundary (\b)
 *   - Preserve case original do texto
 *   - URL pode ser absoluta ou relativa (relativa = usa $wpUrl como base)
 *
 * Uso:
 *   $resultado = InternalLinkGlossary::aplicar($html, [
 *       'wp_url' => 'https://leaodabarra.com.br',
 *       'glossario' => [
 *           'Esporte Clube Vitória' => '/historia-do-esporte-clube-vitoria/',
 *           'Copa do Nordeste' => '/category/copa-do-nordeste/',
 *       ],
 *   ]);
 *   $htmlComLinks = $resultado['html'];
 *   $aplicados = $resultado['aplicados']; // {termo: count, ...}
 */
class InternalLinkGlossary
{
    /** Tags onde NÃO linkar (estrutura ou já-link). */
    private const TAGS_PROIBIDAS = [
        'h1','h2','h3','h4','h5','h6',
        'a','script','style','code','pre',
        'table','thead','tbody','tfoot','tr','td','th','caption',
        'details','summary',
    ];

    public static function aplicar(string $html, array $opts = []): array
    {
        if ($html === '') return ['html' => $html, 'aplicados' => []];

        $glossario = (array)($opts['glossario'] ?? []);
        if (empty($glossario)) return ['html' => $html, 'aplicados' => []];

        $wpUrl = rtrim((string)($opts['wp_url'] ?? ''), '/');
        $maxLinksTotal = (int)($opts['max_links_total'] ?? 8);
        $maxLinksPorTermo = (int)($opts['max_links_por_termo'] ?? 1);
        // Self-link guard: pula termos cujo destino bate com current_url.
        // Evita que página-hub /barradao/ linke pra ela mesma com anchor "Barradão".
        $currentUrl = trim((string)($opts['current_url'] ?? ''));
        $currentPath = '';
        if ($currentUrl !== '') {
            $currentPath = parse_url($currentUrl, PHP_URL_PATH) ?: $currentUrl;
            $currentPath = '/' . trim($currentPath, '/') . '/';
        }
        if ($currentPath !== '' && !empty($glossario)) {
            foreach ($glossario as $termo => $url) {
                $destPath = '/' . trim((string)$url, '/') . '/';
                if ($destPath === $currentPath) unset($glossario[$termo]);
            }
        }

        // Ordena termos por tamanho desc — pra match 'Esporte Clube Vitória' antes de 'Vitória'
        $termosOrdenados = array_keys($glossario);
        usort($termosOrdenados, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $aplicados = [];
        $totalAplicados = 0;
        $htmlMutavel = $html;

        // Pre-protege regiões que NÃO devem ser tocadas (tags proibidas + dentro de <a>)
        $protegidos = [];
        $idx = 0;
        $padroes = [
            // Headings + tabelas + details + scripts/styles
            '/<(h[1-6]|table|details|summary|script|style|code|pre)\b[^>]*>[\s\S]*?<\/\1>/iu',
            // Anchors existentes
            '/<a\b[^>]*>[\s\S]*?<\/a>/iu',
            // Inline tags com class de no-link (já estilizado)
            '/<\w+\b[^>]*class=[\'"][^\'"]*(?:no-link|no-internal-link)[^\'"]*[\'"][^>]*>[\s\S]*?<\/\w+>/iu',
        ];
        foreach ($padroes as $p) {
            $htmlMutavel = preg_replace_callback($p, function($m) use (&$protegidos, &$idx) {
                $token = "\x00ILGPROT_{$idx}\x00";
                $protegidos[$token] = $m[0];
                $idx++;
                return $token;
            }, $htmlMutavel) ?? $htmlMutavel;
        }

        // Aplica glossário — termos longos primeiro
        foreach ($termosOrdenados as $termo) {
            if ($totalAplicados >= $maxLinksTotal) break;
            $url = trim((string)$glossario[$termo]);
            if ($url === '') continue;

            // Resolve URL relativa
            $hrefFinal = $url;
            if (str_starts_with($url, '/') && $wpUrl !== '') {
                $hrefFinal = $wpUrl . $url;
            }

            // Word boundary, case-insensitive, unicode
            $padraoTermo = '/(?<![\w\-])(' . preg_quote($termo, '/') . ')(?![\w\-])/iu';
            $count = 0;

            $htmlMutavel = preg_replace_callback($padraoTermo, function($m) use (&$count, $maxLinksPorTermo, $hrefFinal, $termo) {
                if ($count >= $maxLinksPorTermo) return $m[0];
                $count++;
                $textoOriginal = $m[1]; // preserva casing
                $hrefEscaped = htmlspecialchars($hrefFinal, ENT_QUOTES, 'UTF-8');
                $titleEscaped = htmlspecialchars($termo, ENT_QUOTES, 'UTF-8');
                return "<a href='{$hrefEscaped}' title='{$titleEscaped}' data-internal-glossary='1'>{$textoOriginal}</a>";
            }, $htmlMutavel) ?? $htmlMutavel;

            if ($count > 0) {
                $aplicados[$termo] = $count;
                $totalAplicados += $count;
            }
        }

        // Restaura regiões protegidas
        if (!empty($protegidos)) {
            $htmlMutavel = strtr($htmlMutavel, $protegidos);
        }

        return [
            'html'             => $htmlMutavel,
            'aplicados'        => $aplicados,
            'total_aplicados'  => $totalAplicados,
        ];
    }
}
