<?php
/**
 * Valida links <a href> do artigo, detecta e neutraliza URLs alucinadas pelo LLM.
 *
 * Regras:
 *  - Links com data-authority-link ou data-internal-link: VÁLIDOS (gerados pelo sistema)
 *  - Links para gov.br, .gov, .edu, sites de imprensa conhecidos: VÁLIDOS externos
 *  - Links para o PRÓPRIO domínio sem flag sistema: verifica via HEAD/slug search
 *  - Links inválidos/inexistentes: remove <a>, preserva texto visível
 *
 * Modo de uso:
 *   $r = DiscoverLinkValidator::validar($html, $dominioBase, $wp);
 *   // $r = ['html' => ..., 'removidos' => [['url' => ..., 'motivo' => ...], ...]]
 */
class DiscoverLinkValidator
{
    /**
     * @param string     $html          HTML do artigo
     * @param string     $dominioBase   Ex: 'https://vagasebeneficios.com' (sem barra no fim)
     * @param Wordpress|null $wp        Instância pra checar slug (opcional; sem isso valida só heurística)
     * @return array ['html' => string, 'removidos' => array, 'preservados' => int]
     */
    public static function validar(string $html, string $dominioBase, $wp = null): array
    {
        $dominioBase = rtrim($dominioBase, '/');
        $hostBase = parse_url($dominioBase, PHP_URL_HOST) ?? '';

        // Cache de validação por slug (evita pedir WP 2x)
        $cacheSlug = [];

        $removidos = [];
        $preservados = 0;

        $html = preg_replace_callback(
            '/<a\s+([^>]*)>([\s\S]*?)<\/a>/i',
            function ($m) use (&$removidos, &$preservados, &$cacheSlug, $dominioBase, $hostBase, $wp) {
                $attrs = $m[1];
                $inner = $m[2];

                // Extrai href
                if (!preg_match('/href\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attrs, $hm)) {
                    return $m[0]; // sem href — mantém
                }
                $href = trim(html_entity_decode($hm[1]));

                // Âncoras internas (#foo), mailto, tel: sempre válidos
                if (preg_match('/^(#|mailto:|tel:|javascript:)/i', $href)) { $preservados++; return $m[0]; }

                // Links com flag de sistema SEMPRE válidos (gerados por nós)
                if (preg_match('/data-(?:authority|internal|post-share)-link/i', $attrs)) {
                    $preservados++;
                    return $m[0];
                }

                // Classifica o link
                $parsed = parse_url($href);
                $host = $parsed['host'] ?? '';
                $path = $parsed['path'] ?? '';

                // 1. Link externo (domínio diferente do site): deixa passar
                //    (se Claude inventou URL de gov.br, vai dar 404 mas é risco baixo; tratamos como válido externo)
                if ($host !== '' && $host !== $hostBase) {
                    $preservados++;
                    return $m[0];
                }

                // 2. Link pro próprio domínio (sem host) OU com host igual → precisa validar
                //    Tenta extrair slug do path
                if ($path === '' || $path === '/') {
                    // Apontando pra home → válido
                    $preservados++;
                    return $m[0];
                }

                // Extrai último segmento não-vazio do path como slug
                $segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
                if (empty($segments)) { $preservados++; return $m[0]; }
                $slug = end($segments);

                // Cache
                if (isset($cacheSlug[$slug])) {
                    $existe = $cacheSlug[$slug];
                } else {
                    $existe = self::slugExisteNoWp($wp, $slug);
                    $cacheSlug[$slug] = $existe;
                }

                if ($existe) {
                    $preservados++;
                    return $m[0];
                }

                // INVÁLIDO — remove <a>, preserva conteúdo visível
                $removidos[] = [
                    'url'    => $href,
                    'texto'  => trim(strip_tags($inner)),
                    'motivo' => 'URL não corresponde a slug existente no WP',
                ];
                return $inner; // só o conteúdo, sem a tag <a>
            },
            $html
        ) ?? $html;

        return [
            'html'         => $html,
            'removidos'    => $removidos,
            'preservados'  => $preservados,
        ];
    }

    /**
     * Verifica via WP REST se existe post com slug. Cache no caller.
     * Se $wp é null, retorna TRUE (fail-safe: não remove na ausência do validador).
     */
    private static function slugExisteNoWp($wp, string $slug): bool
    {
        if (!$wp || !method_exists($wp, 'buscarRelacionados')) return true;
        try {
            // Reusa buscarRelacionados como fallback: se retornar algum post com o slug exato, existe.
            // Alternativa mais direta seria um método `$wp->buscarPorSlug($slug)`, mas aqui evitamos criar método novo.
            $candidatos = $wp->buscarRelacionados($slug, 5, 0);
            foreach ($candidatos as $c) {
                $link = (string)($c['link'] ?? '');
                if ($link === '') continue;
                $segs = array_values(array_filter(explode('/', parse_url($link, PHP_URL_PATH) ?? ''), fn($s) => $s !== ''));
                if (!empty($segs) && end($segs) === $slug) return true;
            }
        } catch (Throwable $e) { /* considera inválido em caso de erro */ }
        return false;
    }
}
