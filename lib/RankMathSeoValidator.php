<?php
declare(strict_types=1);

/**
 * RankMathSeoValidator — replica os 12 checks principais do plugin RankMath
 * que aparecem no painel "SEO Básico / Adicional / Legibilidade do título".
 *
 * Uso:
 *   $r = RankMathSeoValidator::validar($html, [
 *       'titulo'        => 'Isenção Enem 2026: prazo termina dia 30',
 *       'meta_title'    => '...',
 *       'meta_desc'     => '...',
 *       'slug'          => 'isencao-enem-2026',
 *       'focus_keyword' => 'isenção Enem 2026',
 *       'featured_alt'  => '...',
 *   ]);
 *   if ($r['score'] < 80) { ... }
 *
 * Retorna {score (0-100), passes, fails, warnings}.
 */
class RankMathSeoValidator
{
    public static function validar(string $html, array $opts): array
    {
        $titulo  = trim((string)($opts['titulo'] ?? ''));
        $metaT   = trim((string)($opts['meta_title'] ?? $titulo));
        $metaD   = trim((string)($opts['meta_desc'] ?? ''));
        $slug    = trim((string)($opts['slug'] ?? ''));
        $kw      = mb_strtolower(trim((string)($opts['focus_keyword'] ?? '')), 'UTF-8');
        $alt     = mb_strtolower(trim((string)($opts['featured_alt'] ?? '')), 'UTF-8');

        $bodyText = mb_strtolower(strip_tags($html), 'UTF-8');
        $totalPalavras = max(1, str_word_count(strip_tags($html)));
        $kwOcorrencias = $kw !== '' ? mb_substr_count($bodyText, $kw) : 0;
        $densidade = $kw !== '' ? round(($kwOcorrencias / $totalPalavras) * 100, 2) : 0;

        $checks = [];

        // === SEO BÁSICO (5 checks) ===
        $checks[] = self::check('Focus keyword no título SEO', mb_stripos($metaT, $kw) !== false, $kw === '' ? 'sem keyword' : null);
        $checks[] = self::check('Focus keyword na meta description', mb_stripos($metaD, $kw) !== false, $kw === '' ? 'sem keyword' : null);
        $checks[] = self::check('Focus keyword no URL/slug', mb_stripos($slug, self::slugify($kw)) !== false, $slug === '' ? 'sem slug' : null);
        /* "Primeiros 10% do conteúdo" — fórmula oficial do RankMath. Pra artigo de 600
         * palavras = primeiras 60. Mínimo 60 pra evitar janela curta demais em posts curtos. */
        $janela10pct = max(60, (int)floor($totalPalavras * 0.10));
        $checks[] = self::check("Focus keyword nos primeiros 10% do conteúdo (janela={$janela10pct}p)",
            $kw !== '' && mb_stripos(self::primeirasPalavras($bodyText, $janela10pct), $kw) !== false);
        $checks[] = self::check('Focus keyword aparece no conteúdo', $kwOcorrencias > 0);

        // === ADICIONAL (5 checks) ===
        $temKwH = false;
        if (preg_match_all('#<h[2-4][^>]*>(.*?)</h[2-4]>#is', $html, $hs)) {
            foreach ($hs[1] as $h) if (mb_stripos(strip_tags($h), $kw) !== false) { $temKwH = true; break; }
        }
        $checks[] = self::check('Focus keyword em subtítulo (H2/H3/H4)', $temKwH);
        $checks[] = self::check('Imagem com keyword no alt text', $kw !== '' && mb_stripos($alt, $kw) !== false);
        $idealMin = 0.5; $idealMax = 2.5;
        $checks[] = self::check(
            "Densidade da keyword adequada (0.5%-2.5%, atual: {$densidade}%)",
            $densidade >= $idealMin && $densidade <= $idealMax,
            $densidade < $idealMin ? "muito baixa ({$kwOcorrencias} ocorr.)" : "muito alta (keyword stuffing)"
        );
        $checks[] = self::check("URL ≤ 75 caracteres (atual: " . strlen($slug) . ")", strlen($slug) > 0 && strlen($slug) <= 75);
        /* Ao menos 1 link externo dofollow (host diferente do próprio site). RankMath
         * exige isso pra rampa de "Authority" — sem outbound autoritativo, score capa. */
        $ownDomain = (string)($opts['own_domain'] ?? '');
        $ownHost = $ownDomain !== '' ? parse_url($ownDomain, PHP_URL_HOST) : '';
        $temExtDofollow = self::temLinkExternoDofollow($html, (string)$ownHost);
        $checks[] = self::check('Pelo menos 1 link externo com dofollow', $temExtDofollow,
            $temExtDofollow ? null : 'nenhum <a href> com host externo + rel="dofollow" detectado');

        // === LEGIBILIDADE DO TÍTULO (2 checks) ===
        $kwInicio = $kw !== '' && mb_stripos(mb_substr($metaT, 0, mb_strlen($kw) + 5), $kw) !== false;
        $checks[] = self::check('Focus keyword no INÍCIO do título', $kwInicio);
        $checks[] = self::check('Título contém número', preg_match('/\d/', $metaT) === 1);

        // Score
        $totalChecks = count($checks);
        $passes = count(array_filter($checks, fn($c) => $c['ok']));
        $score = $totalChecks > 0 ? (int)round(($passes / $totalChecks) * 100) : 0;

        $fails = array_values(array_filter($checks, fn($c) => !$c['ok']));

        return [
            'score'     => $score,
            'passes'    => $passes,
            'total'     => $totalChecks,
            'densidade' => $densidade,
            'kw_ocorrencias' => $kwOcorrencias,
            'fails'     => $fails,
            'all_checks'=> $checks,
        ];
    }

    private static function check(string $titulo, bool $ok, ?string $detalhe = null): array
    {
        return ['titulo' => $titulo, 'ok' => $ok, 'detalhe' => $detalhe];
    }

    private static function primeirasPalavras(string $texto, int $n): string
    {
        $palavras = preg_split('/\s+/u', $texto) ?: [];
        return implode(' ', array_slice($palavras, 0, $n));
    }

    /**
     * Detecta se há AO MENOS 1 link externo (host ≠ ownHost) com `rel="dofollow"`
     * (ou ausência de `nofollow`/`sponsored`/`ugc`, que tecnicamente também é dofollow).
     * RankMath conta como dofollow qualquer `<a>` que NÃO tem rel restritivo.
     */
    private static function temLinkExternoDofollow(string $html, string $ownHost): bool
    {
        if (!preg_match_all('/<a\s+([^>]+)>/i', $html, $matches)) return false;
        foreach ($matches[1] as $atribs) {
            if (!preg_match('/href\s*=\s*[\'"]([^\'"]+)[\'"]/i', $atribs, $hm)) continue;
            $href = trim($hm[1]);
            /* Ignora âncoras internas, mailto, tel, etc */
            if ($href === '' || $href[0] === '#' || stripos($href, 'mailto:') === 0 || stripos($href, 'tel:') === 0 || stripos($href, 'javascript:') === 0) continue;
            $host = parse_url($href, PHP_URL_HOST);
            if (!$host) continue;
            if ($ownHost !== '' && stripos($host, $ownHost) !== false) continue; /* link interno */
            /* Confere rel — dofollow explícito OU ausência de restritivo */
            $rel = '';
            if (preg_match('/rel\s*=\s*[\'"]([^\'"]+)[\'"]/i', $atribs, $rm)) $rel = mb_strtolower($rm[1]);
            $bloqueia = preg_match('/\b(nofollow|sponsored|ugc)\b/', $rel);
            if (!$bloqueia) return true; /* dofollow implícito ou explícito */
        }
        return false;
    }

    /** Gera slug pra comparar (lowercase, sem acento, hífen). Fallback manual se ext-intl ausente. */
    private static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (function_exists('transliterator_transliterate')) {
            $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s) ?: $s;
        } else {
            /* Fallback manual: tabela PT-BR + remoção de acentos genérica via iconv */
            $s = strtr($s, [
                'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
                'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
                'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
                'ç'=>'c','ñ'=>'n',
            ]);
            if (function_exists('iconv')) {
                $sIconv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
                if ($sIconv !== false) $s = $sIconv;
            }
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /** Helper: deriva focus_keyword do título (2-4 palavras-chave principais sem stop words). */
    public static function derivarKeywordDoTitulo(string $titulo): string
    {
        $stopWords = ['a','o','e','de','do','da','dos','das','para','por','com','sem','em','no','na','nos','nas','um','uma','que','se','ou','mas','já','é','foi','será','ao','aos'];
        $limpo = mb_strtolower(strip_tags($titulo), 'UTF-8');
        /* Remove pontuação aderida (vírgula, dois-pontos, ponto, etc) — preserva letras/números/hífen/espaço */
        $limpo = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $limpo) ?? $limpo;
        $palavras = preg_split('/\s+/u', $limpo) ?: [];
        $palavras = array_values(array_filter($palavras, fn($p) => mb_strlen($p) >= 3 && !in_array($p, $stopWords, true)));
        /* Default 3 palavras (RankMath gosta de focus keywords curtas — núcleo da busca real). */
        return implode(' ', array_slice($palavras, 0, 3));
    }
}
